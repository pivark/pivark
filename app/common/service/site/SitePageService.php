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

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\service\site\SiteModeService;

use app\common\model\FloatContactItem;

use app\common\service\front\FrontUrlBuilder;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\infra\UrlPathService;
use app\common\service\static\StaticHtmlService;
use app\common\service\theme\ThemeService;
use app\common\model\SitePage;
use app\common\support\AdminListParams;
use app\common\model\SiteNav;
use app\common\support\HtmlSanitizer;

/** 前台单页（用户自定义 URL 路径） */
class SitePageService
{

    public function __construct(
        private readonly MetaSqlCacheService $metaSqlCacheService,
        private readonly UrlPathService $urlPathService,
        private readonly ThemeService $themeService,
        private readonly FrontUrlBuilder $frontUrlBuilder,
        private readonly ThemeTemplateCatalogService $themeTemplateCatalogService,
        private readonly SiteModeService $siteModeService,
    ) {
    }

    /** @var array<string, string>|null */
    private static ?array $urlByTplCache = null;

    /**
     * @return array{
     *   by_tpl: array<string, array<string, mixed>>,
     *   by_tpl_list: array<string, list<array<string, mixed>>>,
     *   by_path: array<string, array<string, mixed>>,
     *   url_by_tpl: array<string, string>
     * }
     */
    private function publicCatalog(): array
    {
        /** @var array{by_tpl: array<string, array<string, mixed>>, by_tpl_list: array<string, list<array<string, mixed>>>, by_path: array<string, array<string, mixed>>, url_by_tpl: array<string, string>} */
        return $this->metaSqlCacheService->remember('site_pages_pub_v2', function (): array {
            $byTpl     = [];
            $byTplList = [];
            $byPath    = [];
            $urlByTpl  = [];
            foreach (SitePage::where('status', 1)->order('id', 'asc')->select()->toArray() as $row) {
                $formatted = $this->formatPublicRow($row);
                $tpl       = $this->normalizeTpl((string) ($row['tpl_name'] ?? ''));
                if ($tpl !== '') {
                    $byTpl[$tpl]    = $formatted;
                    $urlByTpl[$tpl] = (string) ($formatted['url'] ?? '');
                    $byTplList[$tpl][] = [
                        'id'       => (int) ($formatted['id'] ?? 0),
                        'title'    => (string) ($formatted['title'] ?? ''),
                        'path'     => (string) ($formatted['path'] ?? ''),
                        'url'      => (string) ($formatted['url'] ?? ''),
                        'tpl_name' => $tpl,
                    ];
                }
                $path = $this->normalizePath((string) ($row['path'] ?? ''));
                if ($path !== '') {
                    $byPath[$path] = $formatted;
                }
            }

            return [
                'by_tpl'      => $byTpl,
                'by_tpl_list' => $byTplList,
                'by_path'     => $byPath,
                'url_by_tpl'  => $urlByTpl,
            ];
        });
    }

    /**
     * 同模板单页列表（侧栏「单页分类」用；共用 list_page 时按 path 区分）
     *
     * @return list<array{id:int,title:string,path:string,url:string,tpl_name:string}>
     */
    public function listPublicByTpl(string $tplName): array
    {
        $tpl = $this->normalizeTpl($tplName);
        if ($tpl === '') {
            return [];
        }
        $list = $this->publicCatalog()['by_tpl_list'][$tpl] ?? [];
        if (!is_array($list) || $list === []) {
            return [];
        }

        // 同标题去重（如 falv / falvshenming），优先更短 path
        $byTitle = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            if (!isset($byTitle[$title])) {
                $byTitle[$title] = $item;
                continue;
            }
            $prevPath = (string) ($byTitle[$title]['path'] ?? '');
            if (strlen($path) < strlen($prevPath) || (strlen($path) === strlen($prevPath) && (int) ($item['id'] ?? 0) < (int) ($byTitle[$title]['id'] ?? 0))) {
                $byTitle[$title] = $item;
            }
        }

        return array_values($byTitle);
    }

    /** @var list<string> @deprecated 使用 UrlPathService::RESERVED */
    public const RESERVED_PATHS = UrlPathService::RESERVED;

    /**
     * @return mixed
     * @param mixed $path
     */
    public function normalizePath(string $path): string
    {
        return $this->urlPathService->normalize($path);
    }

    /**
     * @return mixed
     * @param mixed $path
     */
    public function isReservedPath(string $path): bool
    {
        return $this->urlPathService->isReserved($path);
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $path
     */
    public function findByPath(string $path): ?array
    {
        $path = $this->normalizePath($path);
        if ($path === '' || $this->isReservedPath($path)) {
            return null;
        }

        return $this->publicCatalog()['by_path'][$path] ?? null;
    }

    /**
     * 专属路由（如 Route::get('portal')）可绕过保留字拦截，仍读公开目录。
     *
     * @return array<string, mixed>|null
     */
    public function findByPathAllowReserved(string $path): ?array
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return null;
        }

        return $this->publicCatalog()['by_path'][$path] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $tplName
     */
    public function findByTpl(string $tplName): ?array
    {
        $byTpl = $this->publicCatalog()['by_tpl'];
        foreach ($this->tplLookupCandidates($tplName) as $candidate) {
            $key = $this->normalizeTpl($candidate);
            if ($key !== '' && isset($byTpl[$key])) {
                return $byTpl[$key];
            }
        }

        $path = $this->normalizePath($tplName);
        if ($path !== '' && !$this->isReservedPath($path)) {
            return $this->findByPath($path);
        }

        return null;
    }

    /**
     * 后台「单页版式」下拉：list_page（通用）→ list_page_contact 等特殊版式 → 主题内其它 list_page_* 定制文件。
     *
     * @return list<string>
     */
    public function listAdminLayoutTemplates(?string $theme = null): array
    {
        $theme = $theme ?? $this->themeService->getCurrentTheme();
        $dir   = $this->themeService->siteTemplateScanDir($theme);
        $out   = [];

        foreach ([
            ThemeTemplateCatalogService::TPL_LIST_PAGE,
            ThemeTemplateCatalogService::TPL_LIST_PAGE . '_contact',
        ] as $base) {
            if ($this->themeService->siteTemplateExists($theme, $base . '.php')
                || $this->themeService->siteTemplateExists('default', $base . '.php')) {
                $out[] = $base;
            }
        }

        foreach (glob($dir . '/list_page_*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (!in_array($base, $out, true)) {
                $out[] = $base;
            }
        }

        foreach ($this->listThemeTemplates($theme) as $base) {
            if (!in_array($base, $out, true)) {
                $out[] = $base;
            }
        }

        return $out;
    }

    /**
     * 导航绑定：按模板名或访问路径查找单页（含禁用，供后台校验）
     *
     * @return array<string, mixed>|null
     * @param mixed $target
     */
    public function findRowByNavTarget(string $target): ?array
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }

        $tpl = $this->normalizeTpl($target);
        if ($tpl !== '') {
            $candidates = $this->tplLookupCandidates($tpl);
            if ($candidates !== []) {
                $rows = SitePage::whereIn('tpl_name', $candidates)->select()->toArray();
                $byTpl = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $byTpl[(string) ($row['tpl_name'] ?? '')] = $row;
                }
                foreach ($candidates as $candidate) {
                    if (isset($byTpl[$candidate])) {
                        return $byTpl[$candidate];
                    }
                }
            }
        }

        $path = $this->normalizePath(ltrim($target, '/'));
        if ($path !== '' && !$this->isReservedPath($path)) {
            $row = SitePage::where('path', $path)->find()?->toArray();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * 将导航目标规范为模板名（page 类型统一存 tpl，路径变更后链接自动跟随）
     * @return mixed
     * @param mixed $target
     */
    public function tplFromNavTarget(string $target): string
    {
        $row = $this->findRowByNavTarget($target);
        return $row ? $this->normalizeTpl((string) ($row['tpl_name'] ?? '')) : '';
    }

    /**
     * 导航「单页绑定」解析为前台 URL（按 target 唯一定位单页，避免共用 list_page 时全部指向同一页）
     */
    public function urlFromNavTarget(string $target): string
    {
        $row = $this->findRowByNavTarget($target);
        if ($row === null) {
            return '#';
        }

        return $this->frontUrlBuilder->pageFromRow($row);
    }

    /**
     * 单页导航持久化 target：独占模板存 tpl，共用 list_page 等存 path
     *
     * @param array<string, mixed> $row site_pages 行
     */
    public function navTargetFromPageRow(array $row): string
    {
        $tpl  = $this->normalizeTpl((string) ($row['tpl_name'] ?? ''));
        $path = $this->normalizePath((string) ($row['path'] ?? ''));
        if ($path !== '' && $this->isSharedSitePageTpl($tpl)) {
            return $path;
        }

        return $tpl !== '' ? $tpl : $path;
    }

    /**
     * @return mixed
     * @param mixed $tplName
     * @param mixed $fallback
     */
    public function urlByTpl(string $tplName, string $fallback = ''): string
    {
        // 真源：库里有单页才出链。禁止用 tpl 名 / fallback 伪造 /contact、/list-page-products 等 404 路径。
        $page = $this->findByTpl($tplName);
        if ($page !== null) {
            $url = trim((string) ($page['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        if ($fallback !== '') {
            $path = $this->normalizePath($fallback);
            if ($path !== '') {
                $byPath = $this->isReservedPath($path)
                    ? $this->findByPathAllowReserved($path)
                    : $this->findByPath($path);
                if ($byPath !== null) {
                    $url = trim((string) ($byPath['url'] ?? ''));
                    if ($url !== '') {
                        return $url;
                    }
                }
            }
        }

        return '';
    }

    /**
     * 企业门户前台 URL：优先 site_pages（portal / list_page_portal）；
     * Tag SSOT 退役单页后回落到「整站程序」product-site 标签公开路径。
     */
    public function portalFrontUrl(): string
    {
        $page = $this->findByPathAllowReserved('portal') ?? $this->findByTpl('list_page_portal');
        if ($page !== null) {
            $tpl = $this->normalizeTpl((string) ($page['tpl_name'] ?? 'list_page_portal'));

            return $this->urlByTpl($tpl !== '' ? $tpl : 'list_page_portal', 'portal');
        }
        $tag = app(\app\common\service\tag\TagService::class)->findRowBySlug('product-site');
        if (is_array($tag) && (int) ($tag['status'] ?? 0) === 1) {
            return \app\common\support\SiteUrl::tagFromRow($tag);
        }

        return $this->frontUrlBuilder->pageFromRow(['path' => 'portal']);
    }

    /**
     * @return array<string, string> tpl_name => url
     */
    public function urlMapByTpl(): array
    {
        return $this->publicCatalog()['url_by_tpl'];
    }

    /**
     * @return list<string>
     * @param mixed $theme
     */
    public function listThemeTemplates(?string $theme = null): array
    {
        $theme = $theme ?? $this->themeService->getCurrentTheme();
        $dir   = $this->themeService->siteTemplateScanDir($theme);
        $out   = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (!$this->themeTemplateCatalogService->isSitePageCandidate($base)) {
                continue;
            }
            if ($this->themeTemplateCatalogService->classifyBasename($base) === 'legacy_page') {
                $canonicalPage = ThemeTemplateCatalogService::SITE_PAGE_PREFIX . '_' . $base;
                if (is_file($dir . '/' . $canonicalPage . '.php')) {
                    continue;
                }
            }
            if (str_starts_with($base, 'page_')) {
                $canonicalPage = $this->themeTemplateCatalogService->toCanonicalBasename($base);
                if ($canonicalPage !== $base && is_file($dir . '/' . $canonicalPage . '.php')) {
                    continue;
                }
            }
            $out[] = $base;
        }
        sort($out);

        return $out;
    }

    /**
     * 解析主题目录中实际存在的单页模板文件（page_contact ↔ contact 双向兼容）。
     */
    public function resolveThemeTemplateFile(string $tplName, ?string $theme = null): string
    {
        return $this->themeTemplateCatalogService->resolveSitePageTpl($tplName, $theme);
    }

    /** 是否联系表单单页（按 path 或 tpl 判定） */
    public function isContactPage(array $page, string $tplName = ''): bool
    {
        $path = $this->normalizePath((string) ($page['path'] ?? ''));
        if ($path === 'contact') {
            return true;
        }
        $tpl = $tplName !== '' ? $this->normalizeTpl($tplName) : $this->normalizeTpl((string) ($page['tpl_name'] ?? ''));

        return in_array($tpl, ['contact', 'page_contact', 'list_page_contact'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        return $this->listAdminPaged(['limit' => QueryLimit::ADMIN_UNBOUNDED])['list'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $p     = AdminListParams::parse($params);
        $query = SitePage::order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'title|path|tpl_name|seo_title');
        $total = (int) $query->count();
        $rows  = $query->page($p['page'], $p['limit'])->select()->toArray();
        $out   = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row);
        }

        return ['list' => $out, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
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
        $row = SitePage::where('id', $id)->find()?->toArray();
        return $row ? $this->formatAdminRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id     = (int) ($data['id'] ?? 0);
        $title  = trim((string) ($data['title'] ?? ''));
        $path   = $this->normalizePath((string) ($data['path'] ?? ''));
        $tpl    = $this->normalizeTpl((string) ($data['tpl_name'] ?? ''));
        if ($tpl === '') {
            $tpl = ThemeTemplateCatalogService::TPL_LIST_PAGE;
        }
        $status = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $now    = AppTime::now();

        if ($title === '') {
            return ServiceResult::fail('页面标题不能为空');
        }
        if ($path === '' || $this->isReservedPath($path)) {
            return ServiceResult::fail('访问路径无效或与系统保留路径冲突');
        }
        if ($tpl === '') {
            return ServiceResult::fail('请选择模板');
        }

        $pathErr = $this->urlPathService->validateAvailable($path, 'page', $id);
        if ($pathErr !== '') {
            return ServiceResult::fail($pathErr);
        }

        $payload = [
            'title'           => $title,
            'path'            => $path,
            'tpl_name'        => $tpl,
            'content'         => $this->sanitizeContent((string) ($data['content'] ?? '')),
            'seo_title'       => mb_substr(trim((string) ($data['seo_title'] ?? '')), 0, 200),
            'seo_keywords'    => mb_substr(trim((string) ($data['seo_keywords'] ?? '')), 0, 255),
            'seo_description' => mb_substr(trim((string) ($data['seo_description'] ?? '')), 0, 500),
            'status'          => $status,
            'updated_at'      => $now,
        ];
        if ($payload['seo_title'] === '') {
            $payload['seo_title'] = $title;
        }

        if ($id > 0) {
            $old = SitePage::where('id', $id)->find()?->toArray();
            $oldPath = is_array($old) ? (string) ($old['path'] ?? '') : '';
            SitePage::where('id', $id)->update($payload);
            if ($oldPath !== '' && $oldPath !== $path) {
                $this->syncNavAfterPathChange($oldPath, $path, $tpl);
            }
            $this->siteModeService->clearPageCache();
            $this->metaSqlCacheService->forget('site_pages_pub_v2');
            app(StaticHtmlService::class)->syncAfterSitePageChange($id, $oldPath !== '' ? $oldPath : null);
            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) SitePage::insertGetId($payload);
        $this->siteModeService->clearPageCache();
        $this->metaSqlCacheService->forget('site_pages_pub_v2');
        app(StaticHtmlService::class)->syncAfterSitePageChange($newId);
        return ServiceResult::ok(['id' => $newId], '保存成功');
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
        if (!SitePage::where('id', $id)->find()) {
            return ServiceResult::fail('单页不存在');
        }
        $status = $status === 1 ? 1 : 0;
        SitePage::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        $this->siteModeService->clearPageCache();
        $this->metaSqlCacheService->forget('site_pages_pub_v2');
        app(StaticHtmlService::class)->syncAfterSitePageChange($id);
        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

    private function syncNavAfterPathChange(string $oldPath, string $newPath, string $tpl): void
    {
        $oldPath = $this->normalizePath($oldPath);
        $newPath = $this->normalizePath($newPath);
        $now     = AppTime::now();
        foreach (['/' . $oldPath, $oldPath] as $oldTarget) {
            SiteNav::where('nav_type', SiteNavService::TYPE_ROUTE)
                ->where('target', $oldTarget)
                ->update(['target' => '/' . $newPath, 'updated_at' => $now]);
        }
        SiteNav::where('nav_type', SiteNavService::TYPE_ROUTE)
            ->where('target', $tpl)
            ->update(['target' => '/' . $newPath, 'updated_at' => $now]);
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
        $row = SitePage::where('id', $id)->find()?->toArray();
        SitePage::where('id', $id)->delete();
        $this->siteModeService->clearPageCache();
        $this->metaSqlCacheService->forget('site_pages_pub_v2');
        app(StaticHtmlService::class)->syncAfterSitePageDelete(is_array($row) ? $row : null);
        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @return mixed
     * @param mixed $tpl
     */
    public function normalizeTpl(string $tpl): string
    {
        $tpl = strtolower(trim($tpl));
        $tpl = preg_replace('/\.php$/', '', $tpl) ?? '';
        $tpl = preg_replace('/[^a-z0-9\-_]/', '', $tpl) ?? '';

        return $tpl;
    }

    /**
     * @return list<string>
     */
    private function isSharedSitePageTpl(string $tpl): bool
    {
        if ($tpl === ThemeTemplateCatalogService::TPL_LIST_PAGE) {
            return true;
        }
        if ($tpl === '') {
            return false;
        }

        return SitePage::where('tpl_name', $tpl)->count() > 1;
    }

    /**
     * @return list<string>
     */
    private function tplLookupCandidates(string $tplName): array
    {
        $tplName = $this->normalizeTpl($tplName);
        if ($tplName === '') {
            return [];
        }
        $canonical  = $this->themeTemplateCatalogService->toCanonicalBasename($tplName);
        $candidates = array_values(array_unique(array_merge(
            [$canonical, $tplName],
            $this->themeTemplateCatalogService->legacyBasenames($canonical)
        )));
        if ($tplName !== '' && !str_contains($tplName, '_')) {
            $candidates[] = ThemeTemplateCatalogService::SITE_PAGE_PREFIX . '_' . $tplName;
            $candidates[] = 'page_' . $tplName;
        }

        return $candidates;
    }

    /**
     * @return mixed
     * @param mixed $html
     */
    public function sanitizeContent(string $html): string
    {
        return HtmlSanitizer::cleanArticle($html);
    }

    /**
     * @param array<string, mixed> $row site_pages 行或 formatPublicRow 结果
     * @return array<string, mixed> 前台单页模板变量
     */
    public function buildPublicViewVars(array $row): array
    {
        $pub     = isset($row['url']) ? $row : $this->formatPublicRow($row);
        $content = trim((string) ($pub['content'] ?? $row['content'] ?? ''));
        $tpl     = $this->normalizeTpl((string) ($pub['tpl_name'] ?? ''));
        $curPath = $this->normalizePath((string) ($pub['path'] ?? ''));
        $nav     = [];
        foreach ($this->listPublicByTpl($tpl !== '' ? $tpl : ThemeTemplateCatalogService::TPL_LIST_PAGE) as $item) {
            $itemPath = $this->normalizePath((string) ($item['path'] ?? ''));
            $nav[]    = [
                'id'           => (int) ($item['id'] ?? 0),
                'title'        => (string) ($item['title'] ?? ''),
                'path'         => $itemPath,
                'url'          => (string) ($item['url'] ?? ''),
                'currentclass' => ($itemPath !== '' && $itemPath === $curPath) ? 'active' : '',
            ];
        }

        return [
            'page_slug'          => $tpl,
            'page_title'         => (string) ($pub['title'] ?? ''),
            'page_path'          => $curPath,
            'page_content'       => $content,
            'page_content_empty' => $content === '' ? 1 : 0,
            'seo_title'          => (string) ($pub['seo_title'] ?? $pub['title'] ?? ''),
            'seo_keywords'       => (string) ($pub['seo_keywords'] ?? ''),
            'seo_description'    => (string) ($pub['seo_description'] ?? ''),
            'site_page_nav'      => $nav,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPublicRow(array $row): array
    {
        $path = $this->normalizePath((string) ($row['path'] ?? ''));
        $title = (string) ($row['title'] ?? '');
        return [
            'id'              => (int) ($row['id'] ?? 0),
            'title'           => $title,
            'path'            => $path,
            'url'             => $this->frontUrlBuilder->pageFromRow($row),
            'tpl_name'        => $this->normalizeTpl((string) ($row['tpl_name'] ?? '')),
            'content'         => (string) ($row['content'] ?? ''),
            'seo_title'       => (string) ($row['seo_title'] ?? '') ?: $title,
            'seo_keywords'    => (string) ($row['seo_keywords'] ?? ''),
            'seo_description' => (string) ($row['seo_description'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row): array
    {
        $pub = $this->formatPublicRow($row);
        return array_merge($pub, [
            'content'     => (string) ($row['content'] ?? ''),
            'status'      => (int) ($row['status'] ?? 1),
            'status_text' => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'created_at'  => (string) ($row['created_at'] ?? ''),
            'updated_at'  => (string) ($row['updated_at'] ?? ''),
        ]);
    }
}
