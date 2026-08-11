<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\site\SiteModeService;
use app\common\service\site\SitePageService;

use app\common\model\FloatContactItem;

use app\common\service\infra\BreadcrumbService;
use app\common\service\theme\ThemeService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\infra\MetaSqlCacheService;
use app\common\support\OpsLog;
use app\common\support\QueryLimit;
use app\common\service\infra\UrlPathService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\enum\ApiErrorCode;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\tag\TagCore;
use app\common\service\tag\TagService;
use app\common\model\DocumentNav;
use app\common\model\ItemNav;
use app\common\model\SiteNav;
use app\common\service\front\FrontUrlRuleService;
use app\common\support\SiteUrl;
use think\facade\Cache;
use think\facade\Db;

/** 前台站点导航 */
class SiteNavService
{
    /** 后台侧栏/列表扁平树；与 FrontCacheInvalidator::invalidateMeta 同 key */
    public const ADMIN_FLAT_CACHE_KEY = 'admin:site_nav:list_flat:v1';

    private const ADMIN_FLAT_CACHE_TTL = 120;

    /** @var array<string, array<string, mixed>>|null path => site_nav row（请求内） */
    private ?array $contentCategoryByPathMap = null;

    public function __construct(
        private readonly TagService $tagService,
        private readonly MetaSqlCacheService $metaSqlCacheService,
        private readonly UrlPathService $urlPathService,
        private readonly SiteModeService $siteModeService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly StaticHtmlDispatch $staticHtmlDispatch,
        private readonly NavChannelKind $navChannelKind,
        private readonly NavChannelResolver $navChannelResolver,
    ) {
    }

    private function breadcrumbService(): BreadcrumbService
    {
        return app(BreadcrumbService::class);
    }

    public const TYPE_ROUTE    = 'route';
    public const TYPE_PAGE     = 'page';
    public const TYPE_TAG      = 'tag';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_NONE     = 'none';

    public const KIND_DOCUMENT = 'document';
    public const KIND_PRODUCT  = 'product';
    public const KIND_PAGE     = 'page';
    public const KIND_HOME     = 'home';
    public const KIND_EXTERNAL = 'external';
    /** @deprecated 列表/表单禁止露出「其他」；未知一律归文档 */
    public const KIND_OTHER    = 'other';

    /**
     * 用户可见类型：文章栏目 / 产品栏目 / 单页 / 首页 / 外链
     *
     * @return array<string, string>
     */
    public function contentKindLabels(): array
    {
        return [
            self::KIND_DOCUMENT => '文章栏目',
            self::KIND_PRODUCT  => '产品栏目',
            self::KIND_PAGE     => '单页',
            self::KIND_HOME     => '首页',
            self::KIND_EXTERNAL => '外链',
        ];
    }

    /**
     * @deprecated 转 NavChannelKind::normalizeTargetKey（保留薄封装供内部调用）
     */
    private function normalizeNavTargetKey(string $target): string
    {
        return $this->navChannelKind->normalizeTargetKey($target);
    }

    /**
     * @deprecated 转 NavChannelKind::isShowcaseTarget
     */
    private function isProductShowcasePageTarget(string $target): bool
    {
        return $this->navChannelKind->isShowcaseTarget($target);
    }

    /** @deprecated 转 NavChannelResolver::resolveCatalogLine */
    private function resolveCatalogLineForAdmin(string $contentKind, string $target, string $title): string
    {
        return $this->navChannelResolver->resolveCatalogLine($contentKind, $target, $title);
    }

    /**
     * 后台表单：各导航类型的可选目标（单页路径/模板、标签 Slug）
     *
     * @return array{route:list<array{value:string,label:string}>,page:list<array{value:string,label:string}>,tag:list<array{value:string,label:string}>}
     */
    public function targetSuggestions(): array
    {
        $route = [
            ['value' => '/documents', 'label' => '文档列表 · /documents'],
            ['value' => '/tags', 'label' => '标签索引 · /tags'],
        ];
        $page  = [];
        $tag   = [];
        $seen  = [];
        $pageService = app(SitePageService::class);

        foreach ($pageService->listAdmin() as $row) {
            // 绑定建议只列启用单页；禁用/退役页不得进「绑定单页」下拉
            if ((int) ($row['status'] ?? 1) !== 1) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $title = '未命名单页';
            }
            $path = trim((string) ($row['path'] ?? ''));
            $tpl  = $pageService->normalizeTpl((string) ($row['tpl_name'] ?? ''));
            $target = $pageService->navTargetFromPageRow($row);
            if ($target === '') {
                continue;
            }
            $key = 'page:' . $target;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parts = [$title];
            if ($path !== '') {
                $parts[] = '/' . ltrim($path, '/');
            }
            if ($tpl !== '') {
                $parts[] = str_ends_with($tpl, '.php') ? $tpl : ($tpl . '.php');
            }
            $page[] = ['value' => $target, 'label' => implode(' · ', $parts)];
        }

        foreach ($this->tagService->listAllActive() as $row) {
            $name    = trim((string) ($row['name'] ?? ''));
            $slug    = trim((string) ($row['slug'] ?? ''));
            $urlPath = trim((string) ($row['url_path'] ?? ''));
            if ($name === '') {
                $name = $slug !== '' ? $slug : '标签';
            }
            if ($slug !== '') {
                $key = 'tag:' . $slug;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $tag[] = ['value' => $slug, 'label' => $name . ' · Slug ' . $slug];
                }
            }
            if ($urlPath !== '' && $urlPath !== $slug) {
                $key = 'tag_path:' . $urlPath;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $tag[] = ['value' => $urlPath, 'label' => $name . ' · 路径 ' . $urlPath];
                }
            }
        }

        return ['route' => $route, 'page' => $page, 'tag' => $tag];
    }

    /**
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        // 禁再暴露 TYPE_TAG「内容栏目」；遗留 tag 行由 normalizeType 归一为 route
        return [
            self::TYPE_ROUTE    => '站内路径',
            self::TYPE_PAGE     => '单页绑定',
            self::TYPE_EXTERNAL => '外部链接',
            self::TYPE_NONE     => '分组菜单',
        ];
    }

    /**
     * @return mixed
     * @param mixed $type
     */
    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        // 假分类遗留：库内 nav_type=tag 一律当站内路径门牌
        if ($type === self::TYPE_TAG) {
            return self::TYPE_ROUTE;
        }

        return isset($this->typeLabels()[$type]) ? $type : self::TYPE_ROUTE;
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $publicTreeCache = null;

    private static ?int $publicTreeCacheGen = null;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $publicTreeActiveCache = [];

    public function forgetRequestCache(): void
    {
        self::$publicTreeCache       = null;
        self::$publicTreeCacheGen    = null;
        self::$publicTreeActiveCache = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublicTree(): array
    {
        $gen = $this->frontCacheInvalidator->generation();
        if (self::$publicTreeCache !== null && self::$publicTreeCacheGen === $gen) {
            return self::$publicTreeCache;
        }
        $rows = $this->metaSqlCacheService->remember('site_nav_rows', function (): array {
            return $this->listAllRowsOrdered(activeOnly: true);
        });
        self::$publicTreeCache    = $this->buildPublicTree($rows);
        self::$publicTreeCacheGen = $gen;

        return self::$publicTreeCache;
    }

    /**
     * 由种子/配置定义构建导航树（与 listPublicTree 节点字段一致，供页脚等多列菜单 foreach）
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function buildTreeFromDefinitions(array $items): array
    {
        $tree = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $children  = (array) ($item['children'] ?? []);
            $childTree = $children !== [] ? $this->buildTreeFromDefinitions($children) : [];
            $openNewTab = (int) ($item['open_new_tab'] ?? 0);
            $tree[]     = [
                'title'        => (string) ($item['title'] ?? ''),
                'nav_type'     => $this->normalizeType((string) ($item['nav_type'] ?? self::TYPE_ROUTE)),
                'target'       => (string) ($item['target'] ?? ''),
                'url'          => $this->resolveUrl($item),
                'open_new_tab' => $openNewTab,
                'link_target'  => $openNewTab === 1 ? '_blank' : '_self',
                'has_children' => $childTree !== [],
                'children'     => $childTree,
            ];
        }

        return $tree;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function buildPublicTree(array $rows, int $parentId = 0): array
    {
        $productSurfaceOpen = ProductCenterGateService::publicSurfaceOpen();
        $tree = [];
        foreach ($rows as $row) {
            if ((int) ($row['parent_id'] ?? 0) !== $parentId) {
                continue;
            }
            $kind = $this->navChannelKind->resolveForRow(
                $row,
                $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE)),
                (string) ($row['target'] ?? '')
            );
            // 无 Pro：前台导航不露产品栏目（种子行仍在库）
            if (!$productSurfaceOpen && $kind === NavChannelKind::PRODUCT) {
                continue;
            }
            $id       = (int) ($row['id'] ?? 0);
            $children = $this->buildPublicTree($rows, $id);
            $item     = [
                'id'           => $id,
                'title'        => (string) ($row['title'] ?? ''),
                'nav_type'     => $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE)),
                'target'       => (string) ($row['target'] ?? ''),
                'content_kind' => (string) ($row['content_kind'] ?? ''),
                'url'          => $this->resolveUrl($row),
                'open_new_tab' => (int) ($row['open_new_tab'] ?? 0),
                'link_target'  => (int) ($row['open_new_tab'] ?? 0) === 1 ? '_blank' : '_self',
                'extra_json'   => $row['extra_json'] ?? null,
                'sort'         => (int) ($row['sort'] ?? 0),
                'has_children' => $children !== [],
                'children'     => $children,
            ];
            $tree[] = $item;
        }
        return $tree;
    }

    /**
     * 为导航树注入当前页高亮标记（currentclass）
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function enrichTreeWithActive(array $tree, ?string $currentPath = null): array
    {
        $currentPath = $currentPath ?? $this->currentPath();
        $out         = [];
        foreach ($tree as $item) {
            if (!is_array($item)) {
                continue;
            }
            $children           = (array) ($item['children'] ?? []);
            $children           = $children !== [] ? $this->enrichTreeWithActive($children, $currentPath) : [];
            $item['children']   = $children;
            $active             = $this->isNavItemActive($item, $currentPath);
            $childBranchActive  = false;
            foreach ($children as $child) {
                if ((int) ($child['is_active'] ?? 0) === 1 || (int) ($child['is_active_branch'] ?? 0) === 1) {
                    $childBranchActive = true;
                    break;
                }
            }
            $item['is_active']         = $active ? 1 : 0;
            $item['is_active_branch']  = ($active || $childBranchActive) ? 1 : 0;
            $item['nav_class']         = $active ? 'active' : '';
            $out[]                     = $item;
        }

        return $out;
    }

    /**
     * 前台导航树（含 is_active / nav_class，供 {pv:foreach name="site_nav"} 手写导航）
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicTreeWithActive(?string $currentPath = null): array
    {
        $currentPath = $currentPath ?? $this->currentPath();
        $cacheKey    = $currentPath !== '' ? $currentPath : '/';
        if (isset(self::$publicTreeActiveCache[$cacheKey])) {
            return self::$publicTreeActiveCache[$cacheKey];
        }

        return self::$publicTreeActiveCache[$cacheKey] = $this->enrichTreeWithActive(
            $this->listPublicTree(),
            $currentPath
        );
    }

    /**
     *
     * @param list<array<string, mixed>>|null $tree
     * @param string|null                     $currentPath 当前页面路径，用于高亮
     */
    public function renderNavbarHtml(?array $tree = null, ?string $currentPath = null): string
    {
        $currentPath = $currentPath ?? $this->currentPath();

        return $this->renderNavbarLevel($tree ?? $this->listPublicTree(), true, $currentPath);
    }

    /** 当前请求路径（用于导航高亮） */
    public function currentPath(): string
    {
        try {
            $path = (string) request()->pathinfo();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('site_nav_current_path_failed', ['msg' => $e->getMessage()]);

            return '/';
        }
        if ($path === '') {
            return '/';
        }

        return $this->breadcrumbService()->normalizeUrl('/' . ltrim($path, '/'));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderNavbarLevel(array $items, bool $root, string $currentPath): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title      = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $url        = htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
            $target     = htmlspecialchars((string) ($item['link_target'] ?? '_self'), ENT_QUOTES, 'UTF-8');
            $children   = (array) ($item['children'] ?? []);
            $hasChild   = $children !== [];
            $childHtml  = $hasChild ? $this->renderNavbarLevel($children, false, $currentPath) : '';
            $active     = $this->isNavItemActive($item, $currentPath);
            $activeCls  = $active ? ' active' : '';
            $ariaCurrent = $active && !$hasChild ? ' aria-current="page"' : '';

            if ($root) {
                if ($hasChild) {
                    $linkUrl    = $url !== '#' ? $url : htmlspecialchars($this->firstNavigableChildUrl($children), ENT_QUOTES, 'UTF-8');
                    $skin       = $this->navSkinPrefix();
                    $toggleAttr = $this->navToggleDataAttr();
                    $html      .= '<li class="nav-item' . ($active ? ' active' : '') . '">';
                    if ($skin !== '') {
                        $menuCls = 'dropdown-menu';
                        // 有三级子菜单时保持纵向列表 + 右侧飞出，勿用多列 mega
                        if (count($children) >= 4 && !$this->navChildrenHaveNested($children)) {
                            $menuCls .= ' st-nav-dropdown-menu--cols';
                        }
                        $html .= '<div class="dropdown ' . $skin . 'nav-dropdown' . ($active ? ' active' : '') . '">';
                        $html .= '<div class="' . $skin . 'nav-dropdown-split">';
                        if ($linkUrl !== '#') {
                            $html .= '<a class="nav-link ' . $skin . 'nav-dropdown-split__link' . $activeCls . '" href="' . $linkUrl . '" target="' . $target . '"' . $ariaCurrent . '>' . $title . '</a>';
                            // 独立展开钮：手机端可分辨「有下级」；标题本身仍跳转栏目
                            $html .= '<button type="button" class="' . $skin . 'nav-dropdown-split__caret" ' . $toggleAttr . ' aria-expanded="false" aria-label="展开' . $title . '子菜单"></button>';
                        } else {
                            $html .= '<a class="nav-link ' . $skin . 'nav-dropdown-split__link' . $activeCls . '" href="#" role="button" ' . $toggleAttr . ' aria-expanded="false"' . $ariaCurrent . '>' . $title . '</a>';
                        }
                        $html .= '</div><ul class="' . $menuCls . '">' . $childHtml . '</ul></div>';
                    } else {
                        $html .= '<div class="dropdown' . ($active ? ' active' : '') . '">';
                        $html .= '<a class="nav-link dropdown-toggle' . $activeCls . '" href="' . ($linkUrl !== '#' ? $linkUrl : '#') . '" role="button" data-bs-toggle="dropdown" aria-expanded="false"' . $ariaCurrent . '>' . $title . '</a>';
                        $html .= '<ul class="dropdown-menu">' . $childHtml . '</ul></div>';
                    }
                    $html .= '</li>';
                } else {
                    $html .= '<li class="nav-item' . ($active ? ' active' : '') . '"><a class="nav-link' . $activeCls . '" href="' . $url . '" target="' . $target . '"' . $ariaCurrent . '>' . $title . '</a></li>';
                }
                continue;
            }

            if ($hasChild) {
                $html .= '<li class="dropend' . ($active ? ' active' : '') . '">';
                $html .= '<a class="dropdown-item dropdown-toggle' . $activeCls . '" href="' . $url . '"' . $ariaCurrent . '>' . $title . '</a>';
                $html .= '<ul class="dropdown-menu">' . $childHtml . '</ul>';
                $html .= '</li>';
            } else {
                $html .= '<li><a class="dropdown-item' . $activeCls . '" href="' . $url . '" target="' . $target . '"' . $ariaCurrent . '>' . $title . '</a></li>';
            }
        }
        return $html;
    }

    /**
     * 子项是否仍有下级（用于判断是否走三级飞出，而非多列 mega）
     *
     * @param list<array<string, mixed>> $children
     */
    private function navChildrenHaveNested(array $children): bool
    {
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $nested = (array) ($child['children'] ?? []);
            if ($nested !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isNavItemActive(array $item, string $currentPath): bool
    {
        $url = $this->breadcrumbService()->normalizeUrl((string) ($item['url'] ?? '#'));
        if ($url !== '#' && $this->pathMatches($url, $currentPath)) {
            return true;
        }
        foreach ((array) ($item['children'] ?? []) as $child) {
            if (is_array($child) && $this->isNavItemActive($child, $currentPath)) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $navUrl, string $currentPath): bool
    {
        $navUrl      = $this->pathForMatch($navUrl);
        $currentPath = $this->pathForMatch($currentPath);

        if ($navUrl === $currentPath) {
            return true;
        }
        if ($navUrl === '/' && $currentPath === '/') {
            return true;
        }
        if ($navUrl !== '/' && $navUrl !== '#' && str_starts_with($currentPath, rtrim($navUrl, '/') . '/')) {
            return true;
        }

        return false;
    }

    /** 导航高亮比较用：去掉尾斜杠与 /index.html，避免 /pochai/ 与 /pochai/index.html 对不上 */
    private function pathForMatch(string $path): string
    {
        $path = $this->breadcrumbService()->normalizeUrl($path);
        if ($path === '#' || $path === '') {
            return $path;
        }
        $stripped = preg_replace('#/index\.(html?|php)$#i', '', $path);
        if (is_string($stripped) && $stripped !== '') {
            $path = $stripped;
        } elseif (is_string($stripped) && $stripped === '') {
            $path = '/';
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveUrl(array $row): string
    {
        $type   = $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE));
        $target = trim((string) ($row['target'] ?? ''));
        // 真分类门牌只认 site_nav.url_path（与 nav_type/Tag 脱钩）
        $kind = $this->navChannelKind->normalize((string) ($row['content_kind'] ?? ''));
        if (in_array($kind, [self::KIND_DOCUMENT, self::KIND_PRODUCT], true)) {
            return $this->resolveContentCategoryDoorUrl($row, $target);
        }

        return match ($type) {
            self::TYPE_PAGE     => $target !== '' ? $this->resolvePageNavTargetUrl($target) : '#',
            self::TYPE_EXTERNAL => $target,
            self::TYPE_NONE     => $this->resolveNoneOrCategoryDoorUrl($row, $target),
            default             => $this->resolveRouteTarget($target),
        };
    }

    /**
     * 栏目门牌优先 site_nav.url_path，不再依赖绑定 Tag 路径。
     *
     * @param array<string, mixed> $row
     */
    private function resolveContentCategoryDoorUrl(array $row, string $target): string
    {
        $path = trim((string) ($row['url_path'] ?? ''), '/');
        if ($path !== '') {
            return $this->outlinkChannelHome($path);
        }

        // 真分类无门牌 → 不可点（禁回落 Tag）
        return '#';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveNoneOrCategoryDoorUrl(array $row, string $target): string
    {
        $path = trim((string) ($row['url_path'] ?? ''), '/');
        if ($path !== '' && $this->isContentCategoryId((int) ($row['id'] ?? 0))) {
            return $this->outlinkChannelHome($path);
        }

        return $target !== '' ? $this->resolveNoneGroupTargetUrl($target) : '#';
    }

    /** 栏目门牌出站：走 FrontUrlRuleService（动态含 /index.php） */
    private function outlinkChannelHome(string $path): string
    {
        return SiteUrl::public(app(FrontUrlRuleService::class)->buildChannelHome($path, 1));
    }

    /** 导航单页：www 主题下「下载」直链 Gitee，不再走站内下载页 */
    public function resolvePageNavTargetUrl(string $target): string
    {
        $external = SiteUrl::resolveMarketingNavTarget($target);
        if ($external !== null) {
            return $external;
        }

        return app(SitePageService::class)->urlFromNavTarget($target);
    }

    /**
     * 站内路径：解析单页/标签；若填的是模板名（如 about）则跟单页管理联动
     * @return mixed
     * @param mixed $target
     */
    public function resolveRouteTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || $target === '#') {
            return '#';
        }

        $path = ltrim($target, '/');
        $norm = $this->urlPathService->normalize($path);
        if ($norm === '') {
            return $this->normalizeInternalPath($target);
        }

        if (in_array($norm, ['', 'documents', 'tags'], true)) {
            return $this->normalizeInternalPath($target);
        }

        $page = app(SitePageService::class)->findByPath($norm);
        if ($page !== null) {
            return (string) $page['url'];
        }

        $marketingUrl = SiteUrl::resolveMarketingNavTarget($norm);
        if ($marketingUrl !== null) {
            return $marketingUrl;
        }

        $pageByTpl = app(SitePageService::class)->findByTpl($norm);
        if ($pageByTpl !== null) {
            return (string) $pageByTpl['url'];
        }

        $tagRow = $this->tagService->findRowByUrlPath($norm) ?? $this->tagService->findRowBySlug($norm);
        if ($tagRow !== null) {
            return SiteUrl::tagFromRow($tagRow);
        }

        return $this->normalizeInternalPath($target);
    }

    /**
     * @return mixed
     * @param mixed $path
     */
    public function normalizeInternalPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '#') {
            return '#';
        }
        if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '//')) {
            return $path;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        // 静态资源 / 后台 / 上传目录勿加 PATHINFO 入口或静态存储前缀
        if (preg_match('#^/(static|uploads|admin|install)(/|$)#i', $path) === 1) {
            return $path;
        }

        return SiteUrl::public(app(FrontUrlRuleService::class)->applyDynamicEntry($path));
    }

    private function resolveTagTargetUrl(string $target): string
    {
        $target = trim($target);
        $row    = $this->tagService->findRowBySlug($target) ?? $this->tagService->findRowByUrlPath($target);
        if ($row !== null) {
            return SiteUrl::tagFromRow($row);
        }

        return SiteUrl::tag($target);
    }

    /** 分组菜单一级链接：优先单页，其次主题标签 */
    private function resolveNoneGroupTargetUrl(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '#';
        }

        $pageUrl = app(SitePageService::class)->urlFromNavTarget($target);
        if ($pageUrl !== '' && $pageUrl !== '#') {
            return $pageUrl;
        }

        return $this->resolveTagTargetUrl($target);
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    private function firstNavigableChildUrl(array $children): string
    {
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $url = trim((string) ($child['url'] ?? '#'));
            if ($url !== '' && $url !== '#') {
                return $url;
            }
            $nested = $this->firstNavigableChildUrl((array) ($child['children'] ?? []));
            if ($nested !== '#') {
                return $nested;
            }
        }

        return '#';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdminFlat(): array
    {
        /** @var list<array<string, mixed>> $cached */
        $cached = Cache::remember(self::ADMIN_FLAT_CACHE_KEY, function (): array {
            return $this->computeListAdminFlat();
        }, self::ADMIN_FLAT_CACHE_TTL);

        return is_array($cached) ? $cached : [];
    }

    public function bustAdminFlatCache(): void
    {
        Cache::delete(self::ADMIN_FLAT_CACHE_KEY);
        // 同请求内新建栏目后，须失效 path→真分类映射，否则 findContentCategoryByPublicPath 仍读空缓存
        $this->contentCategoryByPathMap = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function computeListAdminFlat(): array
    {
        $rows = $this->listAllRowsOrdered();
        $allowProduct = ProductCenterGateService::entitled();
        if (!$allowProduct) {
            $rows = array_values(array_filter(
                $rows,
                function (array $row): bool {
                    $kind = $this->navChannelKind->resolveForRow(
                        $row,
                        $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE)),
                        (string) ($row['target'] ?? '')
                    );

                    return $kind !== NavChannelKind::PRODUCT;
                }
            ));
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $out  = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row, $byId);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $id
     */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = SiteNav::where('id', $id)->find()?->toArray();
        return $row ? $this->formatAdminRow($row, $this->indexRowsById($this->listAllRowsOrdered())) : null;
    }

    /**
     * 文档/品项可挂靠的真分类（文章/产品栏目；首页外链等不可挂）。
     */
    public function isContentCategoryId(int $id): bool
    {
        if ($id < 1) {
            return false;
        }
        $row = SiteNav::where('id', $id)->find()?->toArray();
        if ($row === null || $row === []) {
            return false;
        }
        $navType = $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE));
        $kind    = $this->navChannelKind->resolveForRow($row, $navType, (string) ($row['target'] ?? ''));

        return in_array($kind, [NavChannelKind::DOCUMENT, NavChannelKind::PRODUCT], true);
    }

    /**
     * 列表筛分类：自身 + 全部子孙 nav id。
     *
     * @return list<int>
     */
    public function contentCategorySelfAndDescendantIds(int $navId): array
    {
        if ($navId < 1) {
            return [];
        }
        $ids = [$navId];
        $frontier = [$navId];
        while ($frontier !== []) {
            $children = array_map('intval', SiteNav::whereIn('parent_id', $frontier)->column('id') ?: []);
            $frontier = [];
            foreach ($children as $cid) {
                if ($cid > 0 && !in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $frontier[] = $cid;
                }
            }
        }

        return $ids;
    }

    public const UNCATEGORIZED_TITLE = '未分类';

    /**
     * 发布表单：可选栏目（document/product），带缩进 label。
     * 受限管理员只列出授权栏目（含子栏目）。
     *
     * @return list<array{id:int,title:string,content_kind:string,label:string}>
     */
    public function listContentCategoryOptionsForPublish(): array
    {
        $scope = app(\app\common\service\admin\AdminTagScopeService::class);
        $userId = $scope->currentUserId();
        $allowed = null;
        if ($scope->isNavRestricted($userId)) {
            $allowed = array_fill_keys($scope->allowedNavIds($userId), true);
        }

        $out = [];
        foreach ($this->listAdminFlat() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || (int) ($row['status'] ?? 0) !== 1) {
                continue;
            }
            if (!$this->isContentCategoryId($id)) {
                continue;
            }
            if ($allowed !== null && !isset($allowed[$id])) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $depth = max(0, (int) ($row['depth'] ?? 0));
            $out[] = [
                'id'           => $id,
                'title'        => $title,
                'content_kind' => (string) ($row['content_kind'] ?? ''),
                'label'        => str_repeat('— ', $depth) . $title,
            ];
        }

        return $out;
    }

    /**
     * 真分类公开门牌 path（无首尾斜杠；# / 空 = 无公开列表）。
     *
     * @param array<string, mixed> $navRow
     */
    public function publicPathForContentCategory(array $navRow): string
    {
        $direct = $this->urlPathService->normalize(trim((string) ($navRow['url_path'] ?? ''), '/'));
        if ($direct !== '' && $direct !== '/') {
            return $direct;
        }
        $url = trim($this->resolveUrl($navRow));
        if ($url === '' || $url === '#') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
        }
        $norm = $this->urlPathService->normalize(ltrim($this->pathForMatch($path), '/'));

        return $norm === '/' ? '' : $norm;
    }

    /**
     * 入站 path → 真分类栏目（同址门牌；不以 Tag 冒充）。
     *
     * @return array<string, mixed>|null
     */
    public function findContentCategoryByPublicPath(string $path): ?array
    {
        $path = $this->urlPathService->normalize(trim($path, '/'));
        if ($path === '' || $path === '/') {
            return null;
        }
        if ($this->contentCategoryByPathMap === null) {
            $this->contentCategoryByPathMap = [];
            $rows = SiteNav::where('status', 1)
                ->field('id,parent_id,title,nav_type,target,content_kind,status,open_new_tab,extra_json,sort,url_path,tpl_name,view_tpl_name,seo_keywords,seo_description,litpic,read_perm,read_level_id')
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1 || !$this->isContentCategoryId($id)) {
                    continue;
                }
                $pub = $this->publicPathForContentCategory($row);
                if ($pub === '' || isset($this->contentCategoryByPathMap[$pub])) {
                    continue;
                }
                $this->contentCategoryByPathMap[$pub] = $row;
            }
        }

        $hit = $this->contentCategoryByPathMap[$path] ?? null;
        if ($hit === null) {
            return null;
        }
        $kind = $this->navChannelKind->resolveForRow(
            $hit,
            $this->normalizeType((string) ($hit['nav_type'] ?? self::TYPE_ROUTE)),
            (string) ($hit['target'] ?? '')
        );
        // 无 Pro：直链 /chanpin 等不解析为产品栏目
        if ($kind === NavChannelKind::PRODUCT && !ProductCenterGateService::publicSurfaceOpen()) {
            return null;
        }

        return $hit;
    }

        /**
     * 确保「未分类」网站栏目节点（文档/产品各一）。
     */
    public function ensureUncategorizedCategoryId(string $contentKind): int
    {
        $contentKind = $this->navChannelKind->normalize($contentKind);
        if (!in_array($contentKind, [NavChannelKind::DOCUMENT, NavChannelKind::PRODUCT], true)) {
            return 0;
        }
        $existingId = (int) (SiteNav::where('title', self::UNCATEGORIZED_TITLE)
            ->where('content_kind', $contentKind)
            ->order('id', 'asc')
            ->value('id') ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $parentId = 0;
        if ($contentKind === NavChannelKind::PRODUCT) {
            $parentId = (int) (SiteNav::where('content_kind', NavChannelKind::PRODUCT)
                ->where('parent_id', 0)
                ->order('id', 'asc')
                ->value('id') ?: 0);
        }

        $now = AppTime::now();
        $sort = 9990;
        $payload = [
            'parent_id'     => $parentId,
            'title'         => self::UNCATEGORIZED_TITLE,
            'nav_type'      => self::TYPE_NONE,
            'target'        => '',
            'content_kind'  => $contentKind,
            'status'        => 1,
            'sort'          => $sort,
            'open_new_tab'  => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        return (int) SiteNav::insertGetId($payload);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        try {
            return Db::transaction(function () use ($data): ServiceResult {
                $result = $this->saveAdminInTransaction($data);
                if (!$result->isOk()) {
                    throw new \RuntimeException(
                        $result->message() !== '' ? $result->message() : '保存失败'
                    );
                }

                return $result;
            });
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage() !== '' ? $e->getMessage() : '保存失败');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveAdminInTransaction(array $data): ServiceResult
    {
        $id       = (int) ($data['id'] ?? 0);
        $title    = trim((string) ($data['title'] ?? ''));
        $parentId = max(0, (int) ($data['parent_id'] ?? 0));
        // 写入不接受 nav_type=tag（读库遗留行仍由 normalizeType→route）
        $rawNavType = strtolower(trim((string) ($data['nav_type'] ?? self::TYPE_ROUTE)));
        if ($rawNavType === self::TYPE_TAG) {
            return ServiceResult::fail('内容栏目请直接选文章/产品栏目，不再支持按 Tag 绑定');
        }
        $navType  = $this->normalizeType($rawNavType);
        $target   = trim((string) ($data['target'] ?? ''));
        $sort     = (int) ($data['sort'] ?? 0);
        $status   = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $newTab   = (int) ($data['open_new_tab'] ?? 0) === 1 ? 1 : 0;
        $now      = AppTime::now();

        if ($title === '') {
            return ServiceResult::fail('栏目名称不能为空');
        }
        if (mb_strlen($title) > 100) {
            return ServiceResult::fail('栏目名称过长');
        }
        if ($id > 0 && $parentId === $id) {
            return ServiceResult::fail('上级栏目不能是自己');
        }
        if ($id > 0 && $parentId > 0 && $this->isDescendant($parentId, $id)) {
            return ServiceResult::fail('上级栏目不能是自己的子级');
        }
        if ($parentId > 0 && !SiteNav::where('id', $parentId)->find()) {
            return ServiceResult::fail('上级栏目不存在');
        }

        $contentKind = $this->navChannelKind->normalize((string) ($data['content_kind'] ?? 'auto'));
        if ($contentKind === 'auto') {
            $contentKind = $parentId > 0
                ? $this->inferContentKindForParentNav($parentId)
                : $this->navChannelKind->inferFromNavTypeTarget($navType, $target);
        }

        if ($contentKind === self::KIND_PRODUCT && !ProductCenterGateService::entitled()) {
            return ServiceResult::fail(
                app(SiteCoreLicenseService::class)->proRequiredMessage(),
                ApiErrorCode::CORE_LICENSE_PRO_REQUIRED
            );
        }

        if ($contentKind === self::KIND_PAGE) {
            $navType = self::TYPE_PAGE;
        } elseif ($contentKind === self::KIND_HOME) {
            $navType = self::TYPE_ROUTE;
            if ($target === '') {
                $target = '/';
            }
        } elseif (in_array($contentKind, [self::KIND_PRODUCT, self::KIND_DOCUMENT], true)) {
            // 真分类：自有 url_path，禁止 TYPE_TAG / 自动建 Tag
            $navType = self::TYPE_ROUTE;
            $target  = '';
        }

        if ($navType === self::TYPE_PAGE && $target === '') {
            $target = $this->resolveOrCreatePageTarget($title);
            if ($target === '') {
                return ServiceResult::fail('无法按栏目名自动创建单页，请选择已有单页或先到「单页管理」创建');
            }
        }

        $isContentCategory = in_array($contentKind, [self::KIND_DOCUMENT, self::KIND_PRODUCT], true);
        if (!$isContentCategory) {
            $err = $this->validateTarget($navType, $target);
            if ($err !== '') {
                return ServiceResult::fail($err);
            }
        }

        if ($navType === self::TYPE_NONE) {
            $target = $this->normalizeNoneTarget($target);
        } elseif ($navType === self::TYPE_PAGE) {
            $pageService = app(SitePageService::class);
            $pageRow = $pageService->findRowByNavTarget($target);
            if ($pageRow === null) {
                return ServiceResult::fail('单页不存在，请填模板名或访问路径');
            }
            $pageId = (int) ($pageRow['id'] ?? 0);
            if ($pageId < 1) {
                return ServiceResult::fail('单页不存在，请填模板名或访问路径');
            }
            // 写穿 site_pages（禁双写 site_nav.channel_*）：访问地址/关键词摘要；SEO 标题=名称
            // 版式 tpl_name 真源=单页管理，栏目表单禁平行改模板
            $hasPageField = static function (string $k) use ($data): bool {
                return array_key_exists($k, $data);
            };
            if (
                $hasPageField('channel_url_path')
                || $hasPageField('channel_seo_keywords')
                || $hasPageField('channel_seo_description')
            ) {
                $pagePath = trim((string) ($pageRow['path'] ?? ''), '/');
                if ($hasPageField('channel_url_path')) {
                    $incomingPath = trim((string) $data['channel_url_path'], '/');
                    if ($incomingPath !== '') {
                        $pagePath = $incomingPath;
                    }
                }
                $pageTpl = (string) ($pageRow['tpl_name'] ?? '');
                $pageTitle = $title !== '' ? $title : (string) ($pageRow['title'] ?? '');
                $pageSave = $pageService->saveAdmin([
                    'id'              => $pageId,
                    'title'           => $pageTitle,
                    'path'            => $pagePath,
                    'tpl_name'        => $pageTpl,
                    'content'         => (string) ($pageRow['content'] ?? ''),
                    // 退役独立搜索标题：单页 seo_title 跟名称
                    'seo_title'       => $pageTitle,
                    'seo_keywords'    => $hasPageField('channel_seo_keywords')
                        ? trim((string) $data['channel_seo_keywords'])
                        : (string) ($pageRow['seo_keywords'] ?? ''),
                    'seo_description' => $hasPageField('channel_seo_description')
                        ? trim((string) $data['channel_seo_description'])
                        : (string) ($pageRow['seo_description'] ?? ''),
                    'status'          => (int) ($pageRow['status'] ?? 1) === 1 ? 1 : 0,
                ]);
                if (!$pageSave->isOk()) {
                    return ServiceResult::fail($pageSave->message() !== '' ? $pageSave->message() : '单页保存失败');
                }
                $fresh = \app\common\model\SitePage::where('id', $pageId)->find()?->toArray();
                if (is_array($fresh) && $fresh !== []) {
                    $pageRow = $fresh;
                }
            }
            $target = $pageService->navTargetFromPageRow($pageRow);
            if ($target === '') {
                return ServiceResult::fail('单页不存在，请填模板名或访问路径');
            }
        } elseif ($navType === self::TYPE_ROUTE && !$isContentCategory) {
            $target = $this->normalizeInternalPath($target);
        } elseif ($isContentCategory) {
            $target = '';
        }

        $payload = [
            'parent_id'     => $parentId,
            'title'         => $title,
            'nav_type'      => $navType,
            'content_kind'  => $contentKind,
            'target'        => mb_substr($target, 0, 255),
            'sort'          => $sort,
            'status'        => $status,
            'open_new_tab'  => $newTab,
            'extra_json'    => app(TagCore::class)->encodeExtraFields($data['extra_fields'] ?? $data['extra_json'] ?? null),
            'updated_at'    => $now,
        ];
        $payload = array_merge($payload, $this->listFieldsPayloadFromAdminChannelData($data, $contentKind));
        $navPath = trim((string) ($payload['url_path'] ?? ''), '/');
        if ($navPath === '' && $isContentCategory && $id > 0) {
            $navPath = trim((string) (SiteNav::where('id', $id)->value('url_path') ?? ''), '/');
        }
        if ($navPath === '' && $isContentCategory) {
            $navPath = trim(app(TagCore::class)->makeSlug($title, 0), '/');
            if ($navPath !== '') {
                $payload['url_path'] = $navPath;
            }
        }
        if ($isContentCategory && $navPath === '') {
            return ServiceResult::fail('请填写访问地址（栏目门牌路径）');
        }
        if ($navPath !== '') {
            $pathErr = $this->urlPathService->validateAvailable($navPath, 'nav', $id);
            if ($pathErr !== '') {
                return ServiceResult::fail($pathErr);
            }
            $payload['url_path'] = $navPath;
        }

        if ($id > 0) {
            SiteNav::where('id', $id)->update($payload);
            $savedId = $id;
        } else {
            $payload['created_at'] = $now;
            $savedId = (int) SiteNav::insertGetId($payload);
        }

        $this->siteModeService->clearPageCache();
        $this->frontCacheInvalidator->invalidateMeta();
        $this->bustAdminFlatCache();
        $this->staticHtmlDispatch->afterNavOrSlideChange();

        return ServiceResult::ok(['id' => $savedId], '保存成功');
    }

    /**
     * 栏目列表字段写入 site_nav（不写 Tag）。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function listFieldsPayloadFromAdminChannelData(array $data, string $contentKind): array
    {
        if (!in_array($contentKind, [self::KIND_DOCUMENT, self::KIND_PRODUCT], true)) {
            return [];
        }
        $has = static function (string $k) use ($data): bool {
            return array_key_exists($k, $data);
        };
        if (
            !$has('channel_url_path')
            && !$has('channel_seo_keywords')
            && !$has('channel_seo_description')
            && !$has('channel_tpl_name')
            && !$has('channel_view_tpl_name')
            && !$has('channel_litpic')
            && !$has('channel_read_access')
            && !$has('url_path')
            && !$has('tpl_name')
        ) {
            return [];
        }
        $out = [];
        if ($has('channel_url_path') || $has('url_path')) {
            $out['url_path'] = $this->urlPathService->normalize(
                (string) ($data['channel_url_path'] ?? $data['url_path'] ?? '')
            );
        }
        if ($has('channel_tpl_name') || $has('tpl_name')) {
            $out['tpl_name'] = trim((string) ($data['channel_tpl_name'] ?? $data['tpl_name'] ?? ''));
        }
        if ($has('channel_view_tpl_name') || $has('view_tpl_name')) {
            $out['view_tpl_name'] = trim((string) ($data['channel_view_tpl_name'] ?? $data['view_tpl_name'] ?? ''));
        }
        if ($has('channel_seo_keywords') || $has('seo_keywords')) {
            $out['seo_keywords'] = trim((string) ($data['channel_seo_keywords'] ?? $data['seo_keywords'] ?? ''));
        }
        if ($has('channel_seo_description') || $has('seo_description')) {
            $out['seo_description'] = trim((string) ($data['channel_seo_description'] ?? $data['seo_description'] ?? ''));
        }
        if ($has('channel_litpic') || $has('litpic')) {
            $out['litpic'] = trim((string) ($data['channel_litpic'] ?? $data['litpic'] ?? ''));
        }
        if ($has('channel_read_access') || $has('read_access') || $has('read_perm')) {
            if ($has('read_perm') && !$has('channel_read_access') && !$has('read_access')) {
                $out['read_perm'] = (int) $data['read_perm'];
                $out['read_level_id'] = (int) ($data['read_level_id'] ?? 0);
            } else {
                $access = app(\app\common\service\member\MemberLevelService::class)
                    ->parseReadAccess((string) ($data['channel_read_access'] ?? $data['read_access'] ?? ''));
                $out['read_perm'] = (int) ($access['read_perm'] ?? 0);
                $out['read_level_id'] = (int) ($access['read_level_id'] ?? 0);
            }
        }

        return $out;
    }

        /**
     * 栏目列表页模板变量（TDK / 封面 / nav_extra），真源 site_nav。
     *
     * @param array<string, mixed> $navRow
     * @return array<string, mixed>
     */
    public function buildPublicCategoryListViewVars(array $navRow): array
    {
        $title = trim((string) ($navRow['title'] ?? ''));
        $seoKeywords = trim((string) ($navRow['seo_keywords'] ?? ''));
        $seoDesc = trim((string) ($navRow['seo_description'] ?? ''));
        $litpic = trim((string) ($navRow['litpic'] ?? ''));
        $extra = app(TagCore::class)->normalizeExtraFieldsForScopes(
            $navRow['extra_json'] ?? null,
            ['list', 'both'],
        );
        $pageTitle = $title !== '' ? $title : '栏目';
        $vars = [
            'nav_id'               => (int) ($navRow['id'] ?? 0),
            'page_title'           => $pageTitle,
            // 退役独立 seo_title：公开页只用栏目名称（SeoTitleService 再拼站名）
            'seo_title'            => $pageTitle,
            'seo_keywords'         => $seoKeywords,
            'seo_description'      => $seoDesc,
            'channel_banner_image' => $litpic,
            'nav_extra'            => $extra,
        ];
        foreach ($extra as $key => $value) {
            $vars['nav_extra_' . $key] = $value;
        }

        return $vars;
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $sort
     */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!SiteNav::where('id', $id)->find()) {
            return ServiceResult::fail('栏目不存在');
        }
        SiteNav::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        $this->siteModeService->clearPageCache();
        $this->frontCacheInvalidator->invalidateMeta();
        $this->bustAdminFlatCache();
        $this->staticHtmlDispatch->afterNavOrSlideChange();
        return ServiceResult::ok(null, '已更新');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $status
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!SiteNav::where('id', $id)->find()) {
            return ServiceResult::fail('栏目不存在');
        }
        $status = $status === 1 ? 1 : 0;
        SiteNav::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        $this->siteModeService->clearPageCache();
        $this->frontCacheInvalidator->invalidateMeta();
        $this->bustAdminFlatCache();
        $this->staticHtmlDispatch->afterNavOrSlideChange();
        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $this->deleteWithChildren($id);
        $this->siteModeService->clearPageCache();
        $this->frontCacheInvalidator->invalidateMeta();
        $this->bustAdminFlatCache();
        $this->staticHtmlDispatch->afterNavOrSlideChange();
        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @return list<array{id:int,name:string,depth:int}>
     * @param mixed $excludeId
     */
    public function listParentOptions(int $excludeId = 0): array
    {
        $rows       = $this->listAllRowsOrdered();
        $tree       = $this->buildPublicTree($rows);
        $excludeIds = $excludeId > 0 ? array_merge([$excludeId], $this->collectDescendantIds($excludeId)) : [];
        $out        = [['id' => 0, 'name' => '顶级栏目', 'depth' => 0]];
        $walk       = static function (array $nodes, int $depth) use (&$walk, &$out, $excludeIds): void {
            foreach ($nodes as $node) {
                $id = (int) ($node['id'] ?? 0);
                if (in_array($id, $excludeIds, true)) {
                    continue;
                }
                $prefix = str_repeat('　', $depth);
                $out[]  = [
                    'id'    => $id,
                    'name'  => $prefix . (string) ($node['title'] ?? ''),
                    'depth' => $depth,
                ];
                if (!empty($node['children'])) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };
        $walk($tree, 0);
        return $out;
    }

    /**
     * @return list<int>
     */
    private function collectDescendantIds(int $id): array
    {
        $ids      = [];
        $childIds = SiteNav::where('parent_id', $id)->column('id');
        foreach ($childIds as $childId) {
            $cid = (int) $childId;
            $ids[] = $cid;
            $ids   = array_merge($ids, $this->collectDescendantIds($cid));
        }
        return $ids;
    }

    private function validateTarget(string $navType, string $target): string
    {
        if ($navType === self::TYPE_NONE) {
            // 分组菜单：目标可空；若填则须是单页或站内路径，禁再验 Tag slug
            if ($target === '') {
                return '';
            }
            if (app(SitePageService::class)->findRowByNavTarget($target) !== null) {
                return '';
            }

            return $this->validateRouteTarget($target);
        }
        if ($target === '') {
            return '请填写链接目标';
        }
        if ($navType === self::TYPE_EXTERNAL) {
            if (!preg_match('#^https?://#i', $target)) {
                return '外部链接需以 http:// 或 https:// 开头';
            }
            return '';
        }
        if ($navType === self::TYPE_PAGE) {
            if (app(SitePageService::class)->findRowByNavTarget($target) === null) {
                return '单页不存在，请填模板名（about）或「单页管理」中的访问路径（如 about1、guanyu）';
            }
            return '';
        }
        if ($navType === self::TYPE_ROUTE) {
            return $this->validateRouteTarget($target);
        }
        return '';
    }

    private function validateRouteTarget(string $target): string
    {
        $path = ltrim(trim($target), '/');
        if ($path === '' || $path === '/') {
            return '';
        }

        $norm = $this->urlPathService->normalize($path);
        if (in_array($norm, ['documents', 'tags'], true)) {
            return '';
        }

        if (app(SitePageService::class)->findRowByNavTarget($target) !== null) {
            return '';
        }
        if ($this->tagService->findRowByUrlPath($norm) !== null || $this->tagService->findRowBySlug($norm) !== null) {
            return '';
        }

        return '站内路径不存在：请从下拉选择已有单页/标签，或先到「单页管理」创建访问路径「'
            . $norm
            . '」后再保存（也可改用「单页绑定」类型）';
    }

    /** 分组菜单：可选绑定单页或主题标签作为一级落地页 */
    private function normalizeNoneTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }
        $pageRow = app(SitePageService::class)->findRowByNavTarget($target);
        if ($pageRow !== null) {
            $normalized = app(SitePageService::class)->navTargetFromPageRow($pageRow);

            return $normalized !== '' ? $normalized : mb_substr($target, 0, 255);
        }
        $row = $this->tagService->findRowBySlug($target) ?? $this->tagService->findRowByUrlPath($target);
        if ($row !== null) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '') {
                return $slug;
            }
        }

        return mb_substr($target, 0, 255);
    }

            /**
     * 单页栏目未选手动绑定时：按标题复用或自动创建 site_pages，返回导航 target。
     * 与 saveAdmin 同事务调用（外层 Db::transaction）。
     */
    private function resolveOrCreatePageTarget(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        $pageService = app(SitePageService::class);
        $byTitle = \app\common\model\SitePage::where('title', $title)->find()?->toArray();
        if (is_array($byTitle)) {
            $target = $pageService->navTargetFromPageRow($byTitle);

            return $target !== '' ? $target : '';
        }

        $base = \app\common\support\SlugHelper::asciiFromText($title, 'page');
        $path = \app\common\support\SlugHelper::ensureUnique(
            $base,
            function (string $candidate) use ($pageService): bool {
                $norm = $pageService->normalizePath($candidate);
                if ($norm === '' || $pageService->isReservedPath($norm)) {
                    return true;
                }
                if (\app\common\model\SitePage::where('path', $norm)->count() > 0) {
                    return true;
                }

                return $this->urlPathService->validateAvailable($norm, 'page', 0) !== '';
            },
        );

        $result = $pageService->saveAdmin([
            'id'       => 0,
            'title'    => $title,
            'path'     => $path,
            'tpl_name' => \app\common\service\theme\ThemeTemplateCatalogService::TPL_LIST_PAGE,
            'content'  => '',
            'status'   => 1,
        ]);
        if (!$result->isOk()) {
            return '';
        }
        $newId = is_array($result->data()) ? (int) ($result->data()['id'] ?? 0) : 0;
        if ($newId < 1) {
            return '';
        }
        $row = \app\common\model\SitePage::where('id', $newId)->find()?->toArray();
        if (!is_array($row)) {
            return '';
        }
        $target = $pageService->navTargetFromPageRow($row);

        return $target !== '' ? $target : '';
    }

    private function normalizeContentKind(string $kind): string
    {
        return $this->navChannelKind->normalize($kind);
    }

    private function inferContentKindForParentNav(int $parentNavId): string
    {
        if ($parentNavId < 1) {
            return self::KIND_DOCUMENT;
        }
        $row = SiteNav::where('id', $parentNavId)->find()?->toArray();
        if (!$row) {
            return self::KIND_DOCUMENT;
        }
        $kind = $this->navChannelKind->resolveForRow(
            $row,
            (string) ($row['nav_type'] ?? ''),
            (string) ($row['target'] ?? '')
        );

        return $kind === self::KIND_PRODUCT ? self::KIND_PRODUCT : self::KIND_DOCUMENT;
    }

        private function resolveContentKind(string $navType, string $target): string
    {
        return $this->navChannelKind->inferFromNavTypeTarget($navType, $target);
    }

                private function isDescendant(int $candidateParentId, int $nodeId): bool
    {
        $current = $candidateParentId;
        while ($current > 0) {
            if ($current === $nodeId) {
                return true;
            }
            $row = SiteNav::where('id', $current)->find()?->toArray();
            if (!$row) {
                break;
            }
            $current = (int) ($row['parent_id'] ?? 0);
        }
        return false;
    }

    private function deleteWithChildren(int $id): void
    {
        $childIds = SiteNav::where('parent_id', $id)->column('id');
        foreach ($childIds as $childId) {
            $this->deleteWithChildren((int) $childId);
        }
        SiteNav::where('id', $id)->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAllRowsOrdered(bool $activeOnly = false): array
    {
        $query = SiteNav::order('sort', 'asc')->order('id', 'asc');
        if ($activeOnly) {
            $query->where('status', 1);
        }

        return $query->limit(QueryLimit::SITE_NAV_ROWS)->select()->toArray();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRowsById(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }
        return $byId;
    }

    private function computeDepth(int $id, array $byId): int
    {
        $depth = 0;
        $cur   = $id;
        while ($cur > 0 && isset($byId[$cur])) {
            $cur = (int) ($byId[$cur]['parent_id'] ?? 0);
            if ($cur > 0) {
                $depth++;
            }
        }
        return $depth;
    }

    /**
     * @param array<string, mixed>              $row
     * @param array<int, array<string, mixed>> $byId
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row, array $byId = []): array
    {
        $labels = $this->typeLabels();
        $type   = $this->normalizeType((string) ($row['nav_type'] ?? self::TYPE_ROUTE));
        $target = trim((string) ($row['target'] ?? ''));
        $typeText = $labels[$type] ?? $type;
        $parent = (int) ($row['parent_id'] ?? 0);
        $parentTitle = '';
        if ($parent > 0) {
            if (isset($byId[$parent])) {
                $parentTitle = (string) ($byId[$parent]['title'] ?? '');
            } else {
                $parentTitle = (string) (SiteNav::where('id', $parent)->value('title') ?? '');
            }
        }

        // 入口类型 / catalog_line：NavChannelResolver 单一入口（禁 channel_tag_*）
        $resolved     = $this->navChannelResolver->resolve($row);
        $contentKind  = $resolved['content_kind'];
        $kindLabels   = $this->contentKindLabels();
        // 列表 TDK/模板真源 = site_nav 一等列（不读绑定 Tag）
        $channelUrlPath = trim((string) ($row['url_path'] ?? ''), '/');
        $channelSeoKeywords = (string) ($row['seo_keywords'] ?? '');
        $channelSeoDesc = (string) ($row['seo_description'] ?? '');
        $channelTplName = (string) ($row['tpl_name'] ?? '');
        $channelViewTpl = (string) ($row['view_tpl_name'] ?? '');
        $channelLitpic = (string) ($row['litpic'] ?? '');
        $channelReadAccess = app(\app\common\service\member\MemberLevelService::class)
            ->encodeReadAccess((int) ($row['read_perm'] ?? 0), (int) ($row['read_level_id'] ?? 0));
        $boundPageId    = 0;
        $boundPageTitle = '';
        $boundPagePath  = '';
        if ($type === self::TYPE_PAGE && $target !== '') {
            $pageRow = app(SitePageService::class)->findRowByNavTarget($target);
            if ($pageRow !== null) {
                $boundPageId    = (int) ($pageRow['id'] ?? 0);
                $boundPageTitle = trim((string) ($pageRow['title'] ?? ''));
                $boundPagePath  = trim((string) ($pageRow['path'] ?? ''));
                // 固定页：抽屉表单复用 channel_* 字段展示，真源仍是 site_pages（禁写回 site_nav）
                $channelUrlPath = $boundPagePath;
                $channelSeoKeywords = (string) ($pageRow['seo_keywords'] ?? '');
                $channelSeoDesc = (string) ($pageRow['seo_description'] ?? '');
                $channelTplName = app(SitePageService::class)->normalizeTpl((string) ($pageRow['tpl_name'] ?? ''));
                if ($channelTplName !== '' && !str_ends_with($channelTplName, '.php')) {
                    $channelTplName .= '.php';
                }
            }
        }

        // 绑定方式跟 nav_type（与 content_kind 解耦，禁止互相覆盖）
        $bindMode = 'none';
        $bindModeText = '—';
        $bindLabel = '';
        $rowTitle = trim((string) ($row['title'] ?? ''));
        if ($type === self::TYPE_ROUTE && ($target === '' || $target === '/')) {
            $bindMode = 'home';
            $bindModeText = '首页';
            $bindLabel = '/';
        } elseif ($type === self::TYPE_EXTERNAL || $contentKind === self::KIND_EXTERNAL) {
            $bindMode = 'external';
            $bindModeText = '外链';
            $bindLabel = $target !== '' ? $target : '—';
        } elseif ($type === self::TYPE_PAGE) {
            $bindMode = 'page';
            $bindModeText = '单页';
            $bindLabel = $boundPageTitle !== '' ? $boundPageTitle : ($target !== '' ? $target : '—');
        } elseif ($type === self::TYPE_NONE) {
            $bindMode = 'none';
            $bindModeText = '分组';
            $bindLabel = $target !== '' ? $target : ($rowTitle !== '' ? $rowTitle : '—');
        } elseif ($type === self::TYPE_ROUTE) {
            $bindMode = 'route';
            $bindModeText = '站内路径';
            $bindLabel = $target !== '' ? $target : ($rowTitle !== '' ? $rowTitle : '—');
        }

        return [
            'id'                 => (int) $row['id'],
            'parent_id'          => $parent,
            'parent_title'       => $parentTitle,
            'depth'              => $byId !== [] ? $this->computeDepth((int) $row['id'], $byId) : 0,
            'title'              => (string) ($row['title'] ?? ''),
            'nav_type'           => $type,
            'nav_type_text'      => $typeText,
            'content_kind'       => $contentKind,
            'content_kind_text'  => $kindLabels[$contentKind] ?? $contentKind,
            'target'             => $target,
            'url'                => $this->resolveUrl($row),
            'channel_url_path'   => $channelUrlPath,
            'channel_seo_keywords' => $channelSeoKeywords,
            'channel_seo_description' => $channelSeoDesc,
            'channel_tpl_name'   => $channelTplName,
            'channel_view_tpl_name' => $channelViewTpl,
            'channel_litpic'     => $channelLitpic,
            'channel_read_access' => $channelReadAccess,
            'bound_page_id'      => $boundPageId,
            'bound_page_title'   => $boundPageTitle,
            'bound_page_path'    => $boundPagePath,
            'bind_mode'          => $bindMode,
            'bind_mode_text'     => $bindModeText,
            'bind_label'         => $bindLabel,
            'catalog_line'       => $resolved['catalog_line'],
            'can_enter_docs'     => $contentKind === self::KIND_DOCUMENT ? 1 : 0,
            'can_manage_product' => $contentKind === self::KIND_PRODUCT ? 1 : 0,
            'sort'             => (int) ($row['sort'] ?? 0),
            'status'           => (int) ($row['status'] ?? 1),
            'status_text'      => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'open_new_tab'     => (int) ($row['open_new_tab'] ?? 0),
            'extra_fields'     => app(TagCore::class)->normalizeExtraFieldDefs($row['extra_json'] ?? null),
            'created_at'       => (string) ($row['created_at'] ?? ''),
            'updated_at'       => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 文档可接收的栏目扩展字段（scope=document|both）；值由文档自填，此处带栏目 schema。
     *
     * @return array<string, array{type: string, value: string, scope: string, options: list<string>}>
     */
    public function listDocumentReceivableExtraFieldDefs(int $navId): array
    {
        if ($navId < 1) {
            return [];
        }
        $row = SiteNav::where('id', $navId)->find()?->toArray();
        if ($row === null) {
            return [];
        }
        $defs = app(TagCore::class)->normalizeExtraFieldDefs($row['extra_json'] ?? null);

        return app(TagCore::class)->documentReceivableExtraFieldDefs($defs);
    }

    /**
     * 指定导航节点的直接子级（启用项，含高亮）
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicChildrenOf(int $parentId): array
    {
        if ($parentId <= 0) {
            return $this->listPublicTreeWithActive();
        }
        foreach ($this->listPublicTreeWithActive() as $node) {
            if (!is_array($node) || (int) ($node['id'] ?? 0) !== $parentId) {
                continue;
            }
            $children = (array) ($node['children'] ?? []);

            return $children !== [] ? $children : [];
        }

        return $this->findChildrenInTree($this->listPublicTreeWithActive(), $parentId);
    }

    /**
     * 按导航标题取直接子级（模板 parent_title，如「产品」）
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicChildrenByTitle(string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }
        $id = $this->findNavIdByTitle($this->listPublicTreeWithActive(), $title);
        if ($id < 1) {
            return [];
        }

        return $this->listPublicChildrenOf($id);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function findNavIdByTitle(array $nodes, string $title): int
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (trim((string) ($node['title'] ?? '')) === $title) {
                return (int) ($node['id'] ?? 0);
            }
            $childId = $this->findNavIdByTitle((array) ($node['children'] ?? []), $title);
            if ($childId > 0) {
                return $childId;
            }
        }

        return 0;
    }

    /**
     * 模板 `{pv:navigation}` 节点字段（EyouCMS 风格别名；栏目信息走 content_kind / catalog_line）
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function mapPublicNodeForTemplate(array $item, string $currentClass = 'active'): array
    {
        $type   = $this->normalizeType((string) ($item['nav_type'] ?? self::TYPE_ROUTE));
        $active = (int) ($item['is_active'] ?? 0) === 1;

        $navClass = $active ? $currentClass : '';
        $item['is_active']    = $active ? 1 : 0;
        $resolved = $this->navChannelResolver->resolve($item);
        $item['nav_class']    = $navClass;
        $item['currentclass'] = $navClass;
        $item['name']         = (string) ($item['title'] ?? '');
        $item['typename']     = (string) ($item['title'] ?? '');
        $item['typeurl']      = (string) ($item['url'] ?? '#');
        if (!isset($item['is_active_branch'])) {
            $item['is_active_branch'] = $active ? 1 : 0;
        }
        $item['nav_type']     = $type;
        $item['content_kind'] = $resolved['content_kind'];
        $item['catalog_line'] = $resolved['catalog_line'];
        $item['extends']      = $this->buildNavLinkExtends($item);

        $extra = app(TagCore::class)->normalizeExtraFieldsForScopes(
            $item['extra_json'] ?? null,
            ['list', 'both'],
        );
        $item['nav_extra'] = $extra;
        foreach ($extra as $extraKey => $extraValue) {
            $item['nav_extra_' . $extraKey] = $extraValue;
        }
        unset($item['extra_json']);

        $children = (array) ($item['children'] ?? []);
        if ($children !== []) {
            $mapped = [];
            foreach ($children as $child) {
                $mapped[] = is_array($child)
                    ? $this->mapPublicNodeForTemplate($child, $currentClass)
                    : $child;
            }
            $item['children'] = $mapped;
        }

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    private function findChildrenInTree(array $tree, int $parentId): array
    {
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            if ((int) ($node['id'] ?? 0) === $parentId) {
                return (array) ($node['children'] ?? []);
            }
            $children = (array) ($node['children'] ?? []);
            if ($children !== []) {
                $found = $this->findChildrenInTree($children, $parentId);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildNavLinkExtends(array $item): string
    {
        $parts = [];
        if ((string) ($item['link_target'] ?? '_self') === '_blank' || (int) ($item['open_new_tab'] ?? 0) === 1) {
            $parts[] = 'target="_blank"';
            $parts[] = 'rel="noopener noreferrer"';
        }

        $url = $this->breadcrumbService()->normalizeUrl((string) ($item['url'] ?? '#'));
        if ($url !== '#' && $this->pathMatches($url, $this->currentPath())) {
            $parts[] = 'aria-current="page"';
        }

        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }

    private function navSkinPrefix(): string
    {
        return app(ThemeService::class)->themeClassPrefix();
    }

    private function navToggleDataAttr(): string
    {
        $skin = rtrim($this->navSkinPrefix(), '-');

        return 'data-' . ($skin !== '' ? $skin : 'st') . '-nav-toggle';
    }

    /**
     * 列表筛选：主栏目树 OR 附加栏目命中。
     *
     * @param \think\db\Query|\think\Model $query
     * @param list<int>                    $navIds
     */
    public function applyPrimaryOrExtraNavFilter($query, array $navIds, string $entity = 'document'): void
    {
        $navIds = array_values(array_unique(array_filter(array_map('intval', $navIds))));
        if ($navIds === []) {
            $query->where('nav_id', 0);

            return;
        }
        $extraTable = $entity === 'item' ? 'item_navs' : 'document_navs';
        $fk         = $entity === 'item' ? 'item_id' : 'document_id';
        $query->where(static function ($q) use ($navIds, $extraTable, $fk): void {
            $q->whereIn('nav_id', $navIds)->whereOr('id', 'in', static function ($sub) use ($navIds, $extraTable, $fk): void {
                $sub->name($extraTable)->whereIn('nav_id', $navIds)->field($fk);
            });
        });
    }

    /**
     * 附加栏目 ID：去重、剔主栏目、仅保留可挂载内容的栏目。
     *
     * @param list<mixed>|mixed $raw
     * @return list<int>
     */
    public function normalizeExtraNavIds(mixed $raw, int $primaryNavId): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $v) {
            $nid = (int) $v;
            if ($nid < 1 || $nid === $primaryNavId || isset($seen[$nid])) {
                continue;
            }
            if (!$this->isContentCategoryId($nid)) {
                continue;
            }
            $seen[$nid] = true;
            $out[] = $nid;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function listDocumentExtraNavIds(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }

        return array_values(array_map(
            'intval',
            DocumentNav::where('document_id', $documentId)->order('id', 'asc')->column('nav_id') ?: []
        ));
    }

    /**
     * @return list<int>
     */
    public function listItemExtraNavIds(int $itemId): array
    {
        if ($itemId < 1) {
            return [];
        }

        return array_values(array_map(
            'intval',
            ItemNav::where('item_id', $itemId)->order('id', 'asc')->column('nav_id') ?: []
        ));
    }

    /**
     * @param list<int> $navIds 已 normalize
     */
    public function replaceDocumentExtraNavs(int $documentId, array $navIds): void
    {
        if ($documentId < 1) {
            return;
        }
        DocumentNav::where('document_id', $documentId)->delete();
        $now = AppTime::now();
        foreach ($navIds as $nid) {
            $nid = (int) $nid;
            if ($nid < 1) {
                continue;
            }
            DocumentNav::insert([
                'document_id' => $documentId,
                'nav_id'      => $nid,
                'created_at'  => $now,
            ]);
        }
    }

    /**
     * @param list<int> $navIds 已 normalize
     */
    public function replaceItemExtraNavs(int $itemId, array $navIds): void
    {
        if ($itemId < 1) {
            return;
        }
        ItemNav::where('item_id', $itemId)->delete();
        $now = AppTime::now();
        foreach ($navIds as $nid) {
            $nid = (int) $nid;
            if ($nid < 1) {
                continue;
            }
            ItemNav::insert([
                'item_id'    => $itemId,
                'nav_id'     => $nid,
                'created_at' => $now,
            ]);
        }
    }
}
