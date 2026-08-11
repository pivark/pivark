<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
     * @param mixed $title
     * @param mixed $url
     * @param mixed $active
 */
declare(strict_types=1);

namespace app\common\service\infra;



use app\common\service\site\SiteNavService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeService;
use app\common\service\product\ProductCatalogSidebarService;
use app\common\model\SiteNav;
use app\common\service\member\MemberCenterPageRegistry;
use app\common\support\SiteUrl;


/** 前台面包屑（展示层，非栏目树） */
class BreadcrumbService
{

    public function __construct(
        private readonly SiteNavService $siteNavService,
        private readonly TagService $tagService,
        private readonly MemberCenterPageRegistry $memberCenterPageRegistry,
        private readonly ThemeService $themeService,
    ) {
    }

    private function itemPublicViewService(): ItemPublicViewService
    {
        return app(ItemPublicViewService::class);
    }

    /**
     * @return array{title:string,url:string,active:int}
     */
    public function crumb(string $title, string $url = '', bool $active = false): array
    {
        $url = trim($url);
        if ($active) {
            $url = '';
        } elseif ($url === '' || $url === '#') {
            $url = '';
        }

        return [
            'title'  => $title,
            'url'    => $url,
            'active' => $active ? 1 : 0,
        ];
    }

    /**
     * 将末项从「当前页」改为可点击链接（追加下级面包屑前调用）
     *
     * @param list<array{title:string,url:string,active:int}> $trail
     */
    public function demoteLastActive(array &$trail, string $url): void
    {
        if ($trail === [] || $url === '' || $url === '#') {
            return;
        }
        $last = count($trail) - 1;
        if (($trail[$last]['active'] ?? 0) !== 1) {
            return;
        }
        $trail[$last] = $this->crumb((string) ($trail[$last]['title'] ?? ''), $url, false);
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     */
    public function homeTrail(): array
    {
        return [$this->crumb('首页', SiteUrl::home())];
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     * @param mixed $title
     * @param mixed $path
     */
    public function forPage(string $title, string $path): array
    {
        $path  = $this->siteNavService->normalizeInternalPath($path);
        $trail = $this->trailFromSiteNav($path);
        $last  = $trail !== [] ? $trail[count($trail) - 1] : null;
        if ($last !== null && ($last['active'] ?? 0) === 1) {
            return $trail;
        }
        $trail[] = $this->crumb($title, '', true);
        return $trail;
    }

    /**
     * @param array<string, mixed> $tagRow
     * @return list<array{title:string,url:string,active:int}>
     */
    public function forTag(array $tagRow): array
    {
        $slug  = (string) ($tagRow['slug'] ?? '');
        $name  = (string) ($tagRow['name'] ?? $slug);
        $url   = $slug !== '' ? SiteUrl::tag($slug) : SiteUrl::tags();
        $trail = $this->trailFromSiteNav($url);
        $last  = $trail !== [] ? $trail[count($trail) - 1] : null;
        if ($last !== null && ($last['active'] ?? 0) === 1 && ($last['title'] ?? '') === $name) {
            return $trail;
        }
        $this->demoteLastActive($trail, $url);
        $trail[] = $this->crumb($name, '', true);

        return $trail;
    }

    /**
     * @param array<string, mixed>      $detail   文章详情（含 tags）
     * @param list<array<string,mixed>> $tags     可选，默认取 detail.tags
     * @return list<array{title:string,url:string,active:int}>
     */
    public function forDocument(array $detail, array $tags = []): array
    {
        $title   = (string) ($detail['title'] ?? '');
        $primary = $this->tagService->primaryTagFromDocument(
            $tags !== [] ? ['tags' => $tags] : $detail
        );

        if ($primary !== null) {
            $slug = (string) ($primary['slug'] ?? '');
            $name = (string) ($primary['name'] ?? $slug);
            if ($slug !== '') {
                $tagUrl = SiteUrl::tag($slug);
                $trail  = $this->trailFromSiteNav($tagUrl);
                if ($trail !== $this->homeTrail()) {
                    $last = count($trail) - 1;
                    if (($trail[$last]['active'] ?? 0) === 1) {
                        $this->demoteLastActive($trail, $tagUrl);
                    } elseif (($trail[$last]['url'] ?? '') === '') {
                        $trail[$last] = $this->crumb((string) $trail[$last]['title'], $tagUrl, false);
                    }
                } else {
                    $trail[] = $this->crumb($name, $tagUrl);
                }
                $trail[] = $this->crumb($title, '', true);
                return $trail;
            }
        }

        $trail = $this->trailFromSiteNav(SiteUrl::documents());
        if ($trail === $this->homeTrail()) {
            $trail[] = $this->crumb('资讯', SiteUrl::documents());
        }
        $trail[] = $this->crumb($title, '', true);
        return $trail;
    }

    /**
     * @param array<string, mixed> $item 品项公开行（含 name）
     * @return list<array{title:string,url:string,active:int}>
     */
    public function forItem(array $item, string $listUrl = '', string $listTitle = ''): array
    {
        $title = trim((string) ($item['name'] ?? ''));
        if ($title === '') {
            $title = '产品详情';
        }
        $listUrl = $listUrl !== ''
            ? $this->siteNavService->normalizeInternalPath($listUrl)
            : $this->productCatalogSidebar()->catalogListUrl();
        $trail = $this->trailFromSiteNav($listUrl);
        if ($trail !== $this->homeTrail()) {
            $last = count($trail) - 1;
            if (($trail[$last]['active'] ?? 0) === 1) {
                $this->demoteLastActive($trail, $listUrl);
            } elseif (($trail[$last]['url'] ?? '') === '') {
                $trail[$last] = $this->crumb((string) $trail[$last]['title'], $listUrl, false);
            }
        } elseif ($listUrl !== '') {
            $listTitle = trim($listTitle);
            if ($listTitle === '') {
                $listTitle = '产品展示';
            }
            $trail[] = $this->crumb($listTitle, $listUrl);
        }
        $trail[] = $this->crumb($title, '', true);

        return $trail;
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     * @param mixed $pageTitle
     * @param mixed $listUrl
     */
    public function forDocumentList(string $pageTitle, string $listUrl = ''): array
    {
        $listUrl = $listUrl !== '' ? $this->siteNavService->normalizeInternalPath($listUrl) : SiteUrl::documents();
        $trail   = $this->trailFromSiteNav($listUrl);
        if ($trail !== $this->homeTrail()) {
            $last = count($trail) - 1;
            if (($trail[$last]['active'] ?? 0) === 1) {
                $this->demoteLastActive($trail, $listUrl);
            }
            if (($trail[$last]['title'] ?? '') === $pageTitle) {
                $trail[$last] = $this->crumb($pageTitle, '', true);

                return $trail;
            }
        }
        $trail[] = $this->crumb($pageTitle, '', true);
        return $trail;
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     */
    public function forTagsCloud(): array
    {
        $tagsUrl = SiteUrl::tags();
        $trail   = $this->trailFromSiteNav($tagsUrl);
        $last    = $trail !== [] ? $trail[count($trail) - 1] : null;
        if ($last !== null && ($last['active'] ?? 0) === 1 && ($last['title'] ?? '') === '标签云') {
            return $trail;
        }
        $this->demoteLastActive($trail, $tagsUrl);
        if ($trail === $this->homeTrail()) {
            $trail[] = $this->crumb('标签云', '', true);
        } elseif (($last['title'] ?? '') !== '标签云') {
            $trail[] = $this->crumb('标签云', '', true);
        }

        return $trail;
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     * @param mixed $keyword
     */
    public function forSearch(string $keyword): array
    {
        $trail = $this->homeTrail();
        if ($keyword === '') {
            $trail[] = $this->crumb('搜索', '', true);

            return $trail;
        }
        $trail[] = $this->crumb('搜索', SiteUrl::search());
        $trail[] = $this->crumb($keyword, '', true);

        return $trail;
    }

    /**
     * @return list<array{title:string,url:string,active:int}>
     */
    public function forMember(string $scene, string $pageTitle = ''): array
    {
        $trail = $this->homeTrail();
        $label = trim($pageTitle) !== '' ? trim($pageTitle) : $this->memberSceneTitle($scene);

        if (in_array($scene, ['login', 'register', 'forgot', 'reset'], true)) {
            $trail[] = $this->crumb($label, '', true);

            return $trail;
        }

        if ($scene === 'center') {
            $trail[] = $this->crumb($label !== '' ? $label : '会员中心', '', true);

            return $trail;
        }

        $trail[] = $this->crumb('会员中心', SiteUrl::memberCenter());
        $trail[] = $this->crumb($label, '', true);

        return $trail;
    }

    private function memberSceneTitle(string $scene): string
    {
        $fromRegistry = $this->memberCenterPageRegistry->pageTitleForMemberScene($scene);
        if ($fromRegistry !== null && $fromRegistry !== '') {
            return $fromRegistry;
        }

        return match ($scene) {
            'login'           => '登录',
            'register'        => '注册',
            'forgot'          => '找回密码',
            'reset'           => '重置密码',
            'purchases'       => '我的购买',
            'viewing'         => '我的观影',
            'merchant'        => '商家管理',
            'points'          => '我的积分',
            'consumption'     => '消费记录',
            'balance'         => '余额流水',
            'recharge'        => '充值中心',
            'security'        => '账号安全',
            'profile'         => '基本资料',
            'documents'       => '我的文章',
            'document_create' => '发布文章',
            'document_edit'   => '编辑文章',
            default           => '会员中心',
        };
    }

    /**
     *
     * @return list<array{title:string,url:string,active:int}>
     * @param mixed $currentUrl
     */
    public function trailFromSiteNav(string $currentUrl): array
    {
        $currentUrl = $this->normalizeUrl($currentUrl);
        $rows       = SiteNav::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->buildTrailFromRows($rows, $currentUrl);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{title:string,url:string,active:int}>
     */
    public function buildTrailFromRows(array $rows, string $currentUrl): array
    {
        $currentUrl = $this->normalizeUrl($currentUrl);
        if ($rows === []) {
            return $this->homeTrail();
        }

        $byId   = [];
        $matchId = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = $row;
            if ($matchId < 1 && $this->normalizeUrl($this->siteNavService->resolveUrl($row)) === $currentUrl) {
                $matchId = $id;
            }
        }

        if ($matchId < 1) {
            return $this->homeTrail();
        }

        $chain = [];
        $id    = $matchId;
        while ($id > 0 && isset($byId[$id])) {
            $row = $byId[$id];
            array_unshift($chain, $row);
            $id = (int) ($row['parent_id'] ?? 0);
        }

        $trail = $this->homeTrail();
        $last  = count($chain) - 1;
        foreach ($chain as $i => $row) {
            $title = (string) ($row['title'] ?? '');
            $url   = $this->siteNavService->resolveUrl($row);
            $isLast = $i === $last;
            if ($isLast) {
                $trail[] = $this->crumb($title, $url, true);
            } else {
                $trail[] = $this->crumb($title, $url, false);
            }
        }

        return $trail;
    }

    /**
     * @return mixed
     * @param mixed $url
     */
    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return '#';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $url = $path;
        }
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }
        return $url;
    }

    /**
     * 面包屑 HTML（条件在 PHP 内处理，模板一行输出）
     *
     * @param list<array{title:string,url:string,active:int}> $trail
     */
    public function renderHtml(array $trail): string
    {
        if ($trail === []) {
            return '';
        }

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = [];
        foreach ($trail as $bc) {
            if (!is_array($bc)) {
                continue;
            }
            $title = trim((string) ($bc['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $url    = trim((string) ($bc['url'] ?? ''));
            $active = (int) ($bc['active'] ?? 0) === 1;
            if ($active) {
                $items[] = '<li class="breadcrumb-item active" aria-current="page">' . $h($title) . '</li>';
            } elseif ($url !== '') {
                $items[] = '<li class="breadcrumb-item"><a href="' . $h($url) . '">' . $h($title) . '</a></li>';
            } else {
                $items[] = '<li class="breadcrumb-item">' . $h($title) . '</li>';
            }
        }

        if ($items === []) {
            return '';
        }

        $ol = implode('', $items);

        $skinPrefix = $this->themeService->themeClassPrefix();
        if ($skinPrefix !== '') {
            return '<div class="' . $skinPrefix . 'breadcrumb-bar"><div class="container">'
                . '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">'
                . $ol
                . '</ol></nav></div></div>';
        }

        return '<nav aria-label="breadcrumb" class="breadcrumb-nav">'
            . '<div class="pv-portal-container"><ol class="breadcrumb">'
            . $ol
            . '</ol></div></nav>';
    }

    /**
     * 会员中心面包屑（无门户 pv-portal-container，与主栏对齐）
     *
     * @param list<array{title:string,url:string,active:int}> $trail
     */
    public function renderMemberHtml(array $trail): string
    {
        if ($trail === []) {
            return '';
        }

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = [];
        foreach ($trail as $bc) {
            if (!is_array($bc)) {
                continue;
            }
            $title = trim((string) ($bc['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $url    = trim((string) ($bc['url'] ?? ''));
            $active = (int) ($bc['active'] ?? 0) === 1;
            if ($active) {
                $items[] = '<li class="breadcrumb-item active" aria-current="page">' . $h($title) . '</li>';
            } elseif ($url !== '') {
                $items[] = '<li class="breadcrumb-item"><a href="' . $h($url) . '">' . $h($title) . '</a></li>';
            } else {
                $items[] = '<li class="breadcrumb-item">' . $h($title) . '</li>';
            }
        }

        if ($items === []) {
            return '';
        }

        return '<nav aria-label="breadcrumb" class="breadcrumb-nav pv-member-breadcrumb-nav">'
            . '<ol class="breadcrumb mb-0">'
            . implode('', $items)
            . '</ol></nav>';
    }
}
