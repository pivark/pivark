<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\front;

use app\common\service\member\MemberCenterPageRegistry;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\service\site\SitePageService;
use app\common\service\infra\UrlPathService;
use app\common\service\tag\TagService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\content\ContentSearchService;
use app\common\support\SiteUrl;
/** 前台出站 URL 统一生成（经 FrontUrlRuleService 规则引擎） */
class FrontUrlBuilder
{

    /**
     * @return mixed
     */
    public function home(): string
    {
        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix('/');
    }

    /**
     * @return mixed
     * @param mixed $page
     * @param mixed $tagSlug
     */
    public function documents(int $page = 1, string $tagSlug = ''): string
    {
        if ($tagSlug !== '') {
            return $this->tag($tagSlug, $page);
        }

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix(
            app(FrontUrlRuleService::class)->buildSystemList('documents', $page)
        );
    }

    /**
     * @return mixed
     * @param mixed $page
     */
    public function tags(int $page = 1): string
    {
        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix(
            app(FrontUrlRuleService::class)->buildSystemList('tags', $page)
        );
    }

    /**
     * @return mixed
     * @param mixed $slug
     * @param mixed $page
     */
    public function tag(string $slug, int $page = 1): string
    {
        $row = app(TagService::class)->findRowBySlug($slug);
        if ($row !== null) {
            return $this->tagFromRow($row, $page);
        }

        $path = app(UrlPathService::class)->normalize($slug);
        $url = $page > 1
            ? app(FrontUrlRuleService::class)->buildTagListPage($path, $page)
            : app(FrontUrlRuleService::class)->buildChannelHome($path, 1);

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix($url);
    }

    /**
     * @param array<string, mixed> $row
     * @return mixed
     */
    public function tagFromRow(array $row, int $page = 1): string
    {
        $path = app(TagService::class)->publicPath($row);
        $url = $page > 1
            ? app(FrontUrlRuleService::class)->buildTagListPage($path, $page)
            : app(FrontUrlRuleService::class)->buildChannelHome($path, 1);

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix($url);
    }

    /**
     * @return mixed
     * @param mixed $id
     * @param mixed $htmlName
     */
    public function document(int $id, string $htmlName = ''): string
    {
        // 有 id 时走 documentFromRow，才能带上主频道 /downloads/... 而非裸 /documents/
        if ($id > 0) {
            return $this->documentFromRow([
                'id'        => $id,
                'html_name' => $htmlName,
                'url_path'  => '',
            ]);
        }

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix(
            app(FrontUrlRuleService::class)->buildDocument([
                'id'        => $id,
                'html_name' => $htmlName,
                'url_path'  => '',
            ])
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return mixed
     */
    public function documentFromRow(array $row, ?array $prefetchedTags = null): string
    {
        $tagRow = $this->primaryTopicTagRowForDocument((int) ($row['id'] ?? 0), $prefetchedTags);

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix(
            app(FrontUrlRuleService::class)->buildDocument($row, $tagRow)
        );
    }

    /**
     * @return mixed
     * @param mixed $tplName
     */
    public function pageByTpl(string $tplName): string
    {
        return app(SitePageService::class)->urlByTpl($tplName);
    }

    /**
     * @return mixed
     * @param mixed $keyword
     * @param mixed $page
     */
    public function search(string $keyword = '', int $page = 1, int $productPage = 1): string
    {
        $keyword = app(ContentSearchService::class)->normalizeKeyword($keyword);
        $params  = [];
        if ($keyword !== '') {
            $params['q'] = $keyword;
        }
        if ($page > 1) {
            $params['page'] = $page;
        }
        if ($productPage > 1) {
            $params['pp'] = $productPage;
        }

        return $this->dyn($params === [] ? '/search' : '/search?' . http_build_query($params));
    }

    public function commerceMall(
        int $page = 1,
        string $keyword = '',
        int $merchantId = 0,
        string $tag = '',
        string $itemType = '',
        string $sort = ''
    ): string {
        $base = $this->commerceBasePath();
        if ($base === '') {
            return '/';
        }
        $params = [];
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $params['q'] = $keyword;
        }
        if ($merchantId > 0) {
            $params['merchant'] = $merchantId;
        }
        $tag = trim($tag);
        if ($tag !== '') {
            $params['tag'] = $tag;
        }
        $itemType = trim($itemType);
        if ($itemType !== '') {
            $params['type'] = $itemType;
        }
        $sort = trim($sort);
        if ($sort !== '' && $sort !== 'default') {
            $params['sort'] = $sort;
        }
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->dyn($params === [] ? $base : $base . '?' . http_build_query($params));
    }

    public function commerceCart(): string
    {
        $base = $this->commerceBasePath();

        return $base === '' ? '/' : $this->dyn($base . '/cart');
    }

    public function commerceCheckout(): string
    {
        $base = $this->commerceBasePath();

        return $base === '' ? '/' : $this->dyn($base . '/checkout');
    }

    public function commerceMarket(): string
    {
        $base = $this->commerceBasePath();

        return $base === '' ? '/' : $this->dyn($base . '/market');
    }

    private function commerceBasePath(): string
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();

        return $id !== null && $id !== '' ? '/' . $id : '';
    }

    /**
     * @param array<string, mixed> $row site_pages 行
     * @return mixed
     */
    public function pageFromRow(array $row): string
    {
        $path = app(UrlPathService::class)->normalize((string) ($row['path'] ?? ''));
        $url = $path !== '' ? app(FrontUrlRuleService::class)->buildChannelHome($path, 1) : '/';

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix($url);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function primaryTopicTagRowForDocument(int $articleId, ?array $prefetchedTags = null): ?array
    {
        if ($articleId < 1) {
            return null;
        }
        $tags = $prefetchedTags ?? app(TagService::class)->getTagsForDocument($articleId);
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $kind = app(TagService::class)->normalizeKind((string) ($tag['kind'] ?? ''));
            if ($kind !== TagService::KIND_TOPIC) {
                continue;
            }
            $slug = trim((string) ($tag['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            // 始终取完整 tags 行（含 url_path），勿直接用 arclist 薄摘要否则会退回 /documents/
            $row = app(TagService::class)->findRowBySlug($slug);
            if ($row !== null) {
                return $row;
            }
            if (trim((string) ($tag['url_path'] ?? '')) !== '') {
                return $tag;
            }
        }

        return null;
    }

    public function memberLogin(string $redirect = ''): string
    {
        $url = '/member/login';
        $redirect = $this->normalizeMemberAuthRedirect($redirect);
        if ($redirect !== '') {
            $url .= '?redirect=' . $this->encodeMemberAuthRedirectQuery($redirect);
        }

        return $this->dyn($url);
    }

    public function memberRegister(string $redirect = ''): string
    {
        $url = '/member/register';
        $redirect = $this->normalizeMemberAuthRedirect($redirect);
        if ($redirect !== '') {
            $url .= '?redirect=' . $this->encodeMemberAuthRedirectQuery($redirect);
        }

        return $this->dyn($url);
    }

    public function memberCenter(): string
    {
        $home = app(MemberCenterPageRegistry::class)->hostHomePath();
        if ($home !== '') {
            return $this->dyn('/member/' . $home);
        }

        return $this->dyn('/member/center');
    }

    public function memberOAuthRedirect(string $provider, string $redirect = ''): string
    {
        $provider = rawurlencode(str_replace('-', '_', strtolower(trim($provider))));
        $url      = '/member/oauth/' . $provider . '/redirect';
        $redirect = $this->normalizeMemberAuthRedirect($redirect);
        if ($redirect !== '') {
            $url .= '?redirect=' . $this->encodeMemberAuthRedirectQuery($redirect);
        }

        return $this->dyn($url);
    }

    /**
     * 登录/注册回跳：同站绝对 URL 收成 path+query；非法/跨站丢弃。
     */
    private function normalizeMemberAuthRedirect(string $redirect): string
    {
        $redirect = trim($redirect);
        if ($redirect === '' || str_starts_with($redirect, '//')) {
            return '';
        }
        if ($redirect[0] === '/') {
            return str_starts_with(strtolower($redirect), '/admin') ? '' : $redirect;
        }
        if (!preg_match('#^https?://#i', $redirect)) {
            return '';
        }

        $parts = parse_url($redirect);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string) $parts['host']);
        $allowed = false;
        try {
            $reqHost = strtolower(trim((string) \think\facade\Request::host()));
            if ($reqHost !== '' && ($host === $reqHost || $host === 'www.' . $reqHost || $reqHost === 'www.' . $host)) {
                $allowed = true;
            }
        } catch (\Throwable) {
            // CLI / 无请求
        }
        if (!$allowed) {
            $siteUrl = trim((string) app(\app\common\service\config\ConfigService::class)->get('site_url', ''));
            $siteHost = is_string(parse_url($siteUrl, PHP_URL_HOST) ?? null)
                ? strtolower((string) parse_url($siteUrl, PHP_URL_HOST))
                : '';
            if ($siteHost !== '' && ($host === $siteHost || $host === 'www.' . $siteHost || $siteHost === 'www.' . $host)) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (str_starts_with(strtolower($path), '/admin')) {
            return '';
        }
        $out = $path;
        if (!empty($parts['query'])) {
            $out .= '?' . $parts['query'];
        }
        if (!empty($parts['fragment'])) {
            $out .= '#' . $parts['fragment'];
        }

        return $out;
    }

    /**
     * 宝塔/ModSecurity 常拦 query 内 %3F（嵌套 ?）；字面保留 ?，其余仍 rawurlencode。
     */
    private function encodeMemberAuthRedirectQuery(string $redirect): string
    {
        return str_replace('%3F', '?', rawurlencode($redirect));
    }

    public function memberOAuthCallback(string $provider): string
    {
        $provider = rawurlencode(str_replace('-', '_', strtolower(trim($provider))));

        return $this->dyn('/member/oauth/' . $provider . '/callback');
    }

    public function memberPurchases(): string
    {
        return $this->dyn('/member/purchases');
    }

    public function memberViewing(): string
    {
        return $this->dyn('/member/viewing');
    }

    public function memberDownloads(): string
    {
        return $this->dyn('/member/downloads');
    }

    public function memberPoints(): string
    {
        return $this->dyn('/member/points');
    }

    public function memberConsumption(): string
    {
        return $this->dyn('/member/consumption');
    }

    public function memberBalance(): string
    {
        return $this->dyn('/member/balance');
    }

    public function memberRecharge(): string
    {
        return $this->dyn('/member/recharge');
    }

    public function memberSecurity(): string
    {
        return $this->dyn('/member/security');
    }

    public function memberProfilePage(): string
    {
        return $this->dyn('/member/profile');
    }

    public function memberDocuments(): string
    {
        return $this->dyn('/member/documents');
    }

    public function memberDocumentCreate(): string
    {
        return SiteUrl::adminSpa('/member-publish/document/create');
    }

    public function memberDocumentEdit(int $id): string
    {
        return SiteUrl::adminSpa('/member-publish/document/edit/' . max(0, $id));
    }

    public function memberEnterAs(string $token): string
    {
        return $this->dyn('/member/enter-as?token=' . rawurlencode($token));
    }

    public function memberLogout(): string
    {
        return $this->dyn('/member/logout');
    }

    public function productItem(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return app(SeoStaticConfigService::class)->applyStaticStoragePrefix(
            $this->dyn('/items/' . rawurlencode($slug)),
        );
    }

    public function memberForgotPassword(): string
    {
        return $this->dyn('/member/forgot-password');
    }

    public function memberForgotUsername(): string
    {
        return $this->dyn('/member/forgot-username');
    }

    public function memberPasswordReset(string $token): string
    {
        return $this->dyn('/member/reset-password?token=' . rawurlencode($token));
    }

    /** 动态模式加 /index.php；伪静态/静态原样 */
    private function dyn(string $path): string
    {
        return app(FrontUrlRuleService::class)->applyDynamicEntry($path);
    }
}
