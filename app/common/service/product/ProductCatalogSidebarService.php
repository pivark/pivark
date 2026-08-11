<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\model\SiteNav;
use app\common\model\Tag;
use app\common\service\config\ConfigService;
use app\common\service\item\ItemPublicViewCatalogDeps;
use app\common\service\site\SiteNavService;
use app\common\support\DbRead;
use app\common\support\SiteUrl;

/** 产品目录侧栏 / 首页子导航（展示面 · 非品项主数据） */
final class ProductCatalogSidebarService
{
    /** @var list<array{slug:string,name:string,url:string}>|null */
    private static ?array $productCatalogTagsCache = null;

    /** @var list<array<string, mixed>>|null */
    private static ?array $productCatalogTreeCache = null;

    public function __construct(
        private readonly ItemPublicViewCatalogDeps $catalog,
    ) {
    }

    public function catalogListUrl(): string
    {
        // 1) 首页产品栏目（栏目优先；不用 tpl 名伪造 /list-page-products）
        $navId = (int) app(ConfigService::class)->get('home_product_nav_id', 0);
        if ($navId > 0) {
            $row = SiteNav::where('id', $navId)->where('status', 1)->find()?->toArray();
            if (is_array($row) && $row !== []) {
                $url = trim(app(SiteNavService::class)->resolveUrl($row));
                if ($url !== '' && $url !== '#') {
                    return $url;
                }
            }
        }

        // 2) 库里真实存在的 list_page_products 单页
        $page = $this->catalog->sitePageService->findByTpl('list_page_products');
        if (is_array($page) && $page !== []) {
            $url = trim((string) ($page['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        // 3) demo hub Tag
        $hub = DbRead::model(Tag::class)->where('status', 1)->where('slug', 'pv-demo-product')->find()?->toArray();
        if (is_array($hub) && $hub !== []) {
            return SiteUrl::tagFromRow($hub);
        }

        return '/chanpin';
    }

    /**
     * @return array{
     *   url_product_catalog:string,
     *   product_catalog_tags:list<array{slug:string,name:string,url:string}>,
     *   product_catalog_tag_tree:list<array<string,mixed>>,
     *   product_catalog_nav_html:string,
     *   product_catalog_tags_empty:int,
     *   tag_slug:string
     * }
     */
    public function sidebarVars(string $activeTagSlug = ''): array
    {
        $active = trim($activeTagSlug);
        $tree   = $this->catalogTagTree();
        // 首页/页脚横滑：只展示产品根下「一级」栏目，禁止把孙级叶子全铺开
        $topLevel = $this->topLevelCatalogTags($tree);

        return [
            'url_product_catalog'         => $this->catalogListUrl(),
            'product_catalog_tags'        => $topLevel,
            'product_catalog_tag_tree'    => $tree,
            'product_catalog_nav_html'    => $this->renderCatalogNavHtml($tree, $active),
            'product_catalog_tags_empty'  => $tree === [] ? 1 : 0,
            'tag_slug'                    => $active,
        ];
    }

    /**
     * 首页/页脚/横滑：产品根栏目的直接子级（SiteNav；非 Tag 全树叶子）。
     *
     * @return list<array{slug:string,name:string,url:string}>
     */
    public function catalogTags(): array
    {
        if (self::$productCatalogTagsCache !== null) {
            return self::$productCatalogTagsCache;
        }

        return self::$productCatalogTagsCache = $this->topLevelCatalogTags($this->catalogTagTree());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogTagTree(): array
    {
        if (self::$productCatalogTreeCache !== null) {
            return self::$productCatalogTreeCache;
        }

        $fromNav = $this->loadProductNavTree();
        if ($fromNav !== []) {
            return self::$productCatalogTreeCache = $fromNav;
        }

        $rows = $this->loadProductTagRows();
        if ($rows === []) {
            return self::$productCatalogTreeCache = [];
        }

        return self::$productCatalogTreeCache = $this->buildTree($rows);
    }

    /**
     * 优先 home_product_nav_id 下 SiteNav 子树；无则空（再回落 Tag）。
     *
     * @return list<array<string, mixed>>
     */
    private function loadProductNavTree(): array
    {
        $navId = (int) app(ConfigService::class)->get('home_product_nav_id', 0);
        if ($navId < 1) {
            return [];
        }
        $children = app(SiteNavService::class)->listPublicChildrenOf($navId);
        if ($children === []) {
            return [];
        }

        return $this->mapNavNodesToCatalogTree($children);
    }

    /**
     * @param list<array<string, mixed>> $nodes SiteNav 公开树节点
     * @return list<array<string, mixed>>
     */
    private function mapNavNodesToCatalogTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id    = (int) ($node['id'] ?? 0);
            $title = trim((string) ($node['title'] ?? ''));
            $url   = trim((string) ($node['url'] ?? ''));
            if ($title === '' || $url === '' || $url === '#') {
                continue;
            }
            $slug = trim((string) ($node['target'] ?? ''), '/');
            if ($slug === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $slug = is_string($path) ? trim($path, '/') : '';
            }
            if ($slug === '') {
                $slug = $id > 0 ? ('nav-' . $id) : '';
            }
            if ($slug === '') {
                continue;
            }
            $children = $this->mapNavNodesToCatalogTree((array) ($node['children'] ?? []));
            $out[]    = [
                'id'           => $id,
                'parent_id'    => (int) ($node['parent_id'] ?? 0),
                'slug'         => $slug,
                'name'         => $title,
                'url'          => $url,
                'children'     => $children,
                'has_children' => $children !== [] ? 1 : 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array{slug:string,name:string,url:string}>
     */
    private function topLevelCatalogTags(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $slug = trim((string) ($node['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'slug' => $slug,
                'name' => (string) ($node['name'] ?? $slug),
                'url'  => (string) ($node['url'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProductTagRows(): array
    {
        $hub = $this->catalog->tagSlugIndexService->rowBySlug('pv-demo-product');
        if ($hub !== null) {
            $hubId = (int) ($hub['id'] ?? 0);
            $rows  = $hubId > 0 ? $this->catalog->tagSlugIndexService->rowsByParentId($hubId) : [];
            if ($rows !== []) {
                // hub 子级：若子级还有孙级，一并拉入以便建树
                $all = [];
                foreach ($rows as $row) {
                    $all[(int) ($row['id'] ?? 0)] = $row;
                }
                foreach ($rows as $row) {
                    $pid = (int) ($row['id'] ?? 0);
                    if ($pid < 1) {
                        continue;
                    }
                    foreach ($this->catalog->tagSlugIndexService->rowsByParentId($pid) as $child) {
                        $cid = (int) ($child['id'] ?? 0);
                        if ($cid > 0) {
                            $all[$cid] = $child;
                        }
                    }
                }

                return array_values($all);
            }
        }

        $query = DbRead::model(Tag::class)->where('status', 1)
            ->where(function ($q): void {
                $q->where('tpl_name', 'like', '%list_document_product%')
                    ->whereOr('tpl_name', 'like', '%list_page_products%')
                    ->whereOr('slug', 'like', 'pv-demo-cat-%')
                    ->whereOr('slug', 'chanpin');
            })
            ->where('slug', '<>', 'pv-demo-product')
            ->order('nav_sort', 'asc')
            ->order('id', 'asc');

        return $query->select()->toArray();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $byId[$id] = [
                'id'           => $id,
                'parent_id'    => (int) ($row['parent_id'] ?? 0),
                'slug'         => $slug,
                'name'         => (string) ($row['name'] ?? $slug),
                'url'          => SiteUrl::tagFromRow($row),
                'children'     => [],
                'has_children' => 0,
            ];
        }
        if ($byId === []) {
            return [];
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $pid = (int) ($node['parent_id'] ?? 0);
            if ($pid > 0 && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
                $byId[$pid]['has_children'] = 1;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return $roots;
    }

    /**
     * @param list<array<string, mixed>> $tree
     */
    public function renderCatalogNavHtml(array $tree, string $activeSlug): string
    {
        if ($tree === []) {
            return '';
        }
        $activeSlug = trim($activeSlug);
        $openIds    = $this->ancestorIdsForActive($tree, $activeSlug);
        $html       = '';
        foreach ($tree as $node) {
            $html .= $this->renderCatalogNode($node, $activeSlug, $openIds, 0);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, true>     $openIds
     */
    private function renderCatalogNode(array $node, string $activeSlug, array $openIds, int $depth): string
    {
        $slug     = trim((string) ($node['slug'] ?? ''));
        $name     = htmlspecialchars((string) ($node['name'] ?? $slug), ENT_QUOTES, 'UTF-8');
        $url      = htmlspecialchars((string) ($node['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $children = (array) ($node['children'] ?? []);
        $id       = (int) ($node['id'] ?? 0);
        $active   = $slug !== '' && $slug === $activeSlug;
        $pad      = max(0, $depth) * 12;
        $html     = '';

        if ($children === []) {
            $cls = 'list-group-item list-group-item-action channel-link' . ($active ? ' active' : '');
            $html .= '<a class="' . $cls . '" href="' . $url . '" data-pv-catalog-tag="'
                . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" style="padding-left:' . (16 + $pad) . 'px">'
                . $name . '</a>';

            return $html;
        }

        $open    = $active || isset($openIds[$id]);
        $cid     = 'pvCatFold' . $id;
        $btnCls  = 'list-group-item list-group-item-action channel-link pv-catalog-fold__toggle'
            . ($active ? ' active' : '') . ($open ? ' is-open' : '');
        $html   .= '<div class="pv-catalog-fold">';
        $html   .= '<div class="' . $btnCls . '" style="padding-left:' . (16 + $pad) . 'px">';
        $html   .= '<a class="pv-catalog-fold__link" href="' . $url . '" data-pv-catalog-tag="'
            . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $name . '</a>';
        $html   .= '<button type="button" class="pv-catalog-fold__btn" data-bs-toggle="collapse" data-bs-target="#'
            . $cid . '" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . $cid
            . '" aria-label="展开或收起"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>';
        $html   .= '</div>';
        $html   .= '<div id="' . $cid . '" class="collapse' . ($open ? ' show' : '') . '">';
        foreach ($children as $child) {
            if (is_array($child)) {
                $html .= $this->renderCatalogNode($child, $activeSlug, $openIds, $depth + 1);
            }
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return array<int, true>
     */
    private function ancestorIdsForActive(array $tree, string $activeSlug): array
    {
        if ($activeSlug === '') {
            return [];
        }
        $path = [];
        $found = false;
        $walk = static function (array $nodes, array $trail) use (&$walk, &$path, &$found, $activeSlug): void {
            foreach ($nodes as $node) {
                if (!is_array($node) || $found) {
                    continue;
                }
                $id = (int) ($node['id'] ?? 0);
                $next = $trail;
                if ($id > 0) {
                    $next[] = $id;
                }
                if (trim((string) ($node['slug'] ?? '')) === $activeSlug) {
                    $path = $next;
                    $found = true;

                    return;
                }
                $walk((array) ($node['children'] ?? []), $next);
            }
        };
        $walk($tree, []);
        $out = [];
        foreach ($path as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }
}
