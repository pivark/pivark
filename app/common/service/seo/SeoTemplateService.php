<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\service\theme\ThemeService;
use app\common\support\SiteUrl;
use think\facade\Request;

/**
 * 前台 SEO：组装 TDK/OG/Twitter/JSON-LD 变量 + 渲染 `{pv:seo}` meta HTML。
 */
class SeoTemplateService
{
    public function __construct(
        private readonly ThemeService $theme,
    ) {
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function buildMetaVars(array $vars): array
    {
        $title = trim((string) ($vars['seo_title'] ?? $vars['page_title'] ?? ''));
        if ($title === '') {
            $title = app(SeoTitleService::class)->siteName();
        }

        $description = trim((string) ($vars['seo_description'] ?? ''));
        if ($description === '') {
            $description = trim((string) ($vars['site_description'] ?? ''));
        }

        $keywords = trim((string) ($vars['seo_keywords'] ?? ''));
        if ($keywords === '') {
            $keywords = trim((string) ($vars['site_keywords'] ?? ''));
        }

        $ogType = trim((string) ($vars['seo_og_type'] ?? ''));
        if ($ogType === '') {
            $ogType = (($vars['seo_title_context'] ?? '') === 'document') ? 'article' : 'website';
        }

        // 静态生成 / CLI 无 REQUEST 时 currentPath 常为 /；优先页面传入的 canonical
        $pageUrl = $this->resolveCanonicalPageUrl($vars);
        $ogImage = trim((string) ($vars['seo_og_image'] ?? ''));
        if ($ogImage === '') {
            $ogImage = $this->resolveOgImage($vars);
        } else {
            $ogImage = $this->absoluteUrl($ogImage);
        }

        $jsonLd = trim((string) ($vars['seo_json_ld'] ?? ''));
        if ($jsonLd === '') {
            $jsonLd = $this->buildDefaultJsonLd($vars, $pageUrl, $ogImage, $title, $description);
        }

        $robots = trim((string) ($vars['seo_robots'] ?? ''));
        if ($robots === '' && (($vars['seo_title_context'] ?? '') === 'error')) {
            $robots = 'noindex, nofollow';
        }

        return [
            'seo_description'         => $description,
            'seo_keywords'            => $keywords,
            'seo_og_type'             => $ogType,
            'seo_og_title'            => $title,
            'seo_og_description'      => $description,
            'seo_og_url'              => $pageUrl,
            'seo_og_image'            => $ogImage,
            'seo_twitter_card'        => trim((string) ($vars['seo_twitter_card'] ?? 'summary_large_image')),
            'seo_twitter_title'       => $title,
            'seo_twitter_description' => $description,
            'seo_twitter_image'       => $ogImage,
            'seo_json_ld'             => $jsonLd,
            'seo_robots'              => $robots,
            'page_h1'                 => $this->resolvePageH1($vars),
        ];
    }

    /**
     * `{pv:seo /}` 与空块体默认输出；T/D 与 `<title>` 同源（seo_title / seo_description）。
     *
     * @param array<string, mixed> $vars 已合并 buildMetaVars 的页面变量
     */
    public function renderMetaHtml(array $vars, bool $withFavicon = false): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [];

        $title = trim((string) ($vars['seo_title'] ?? $vars['seo_og_title'] ?? ''));
        $description = trim((string) ($vars['seo_description'] ?? $vars['seo_og_description'] ?? ''));

        $lines[] = '<link rel="canonical" href="' . $h((string) ($vars['seo_og_url'] ?? '')) . '">';
        $lines[] = '<meta property="og:type" content="' . $h((string) ($vars['seo_og_type'] ?? 'website')) . '">';
        $lines[] = '<meta property="og:title" content="' . $h($title) . '">';
        $lines[] = '<meta property="og:description" content="' . $h($description) . '">';
        $lines[] = '<meta property="og:url" content="' . $h((string) ($vars['seo_og_url'] ?? '')) . '">';

        $ogImage = trim((string) ($vars['seo_og_image'] ?? ''));
        if ($ogImage !== '') {
            $lines[] = '<meta property="og:image" content="' . $h($ogImage) . '">';
        }

        $lines[] = '<meta name="twitter:card" content="' . $h((string) ($vars['seo_twitter_card'] ?? 'summary_large_image')) . '">';
        $lines[] = '<meta name="twitter:title" content="' . $h($title) . '">';
        $lines[] = '<meta name="twitter:description" content="' . $h($description) . '">';

        $twitterImage = trim((string) ($vars['seo_twitter_image'] ?? ''));
        if ($twitterImage !== '') {
            $lines[] = '<meta name="twitter:image" content="' . $h($twitterImage) . '">';
        }

        $robots = trim((string) ($vars['seo_robots'] ?? ''));
        if ($robots !== '') {
            $lines[] = '<meta name="robots" content="' . $h($robots) . '">';
        }

        $jsonLd = trim((string) ($vars['seo_json_ld'] ?? ''));
        if ($jsonLd !== '') {
            $lines[] = '<script type="application/ld+json">' . $jsonLd . '</script>';
        }

        if ($withFavicon) {
            $themeAsset = trim((string) ($vars['theme_asset'] ?? ''));
            if ($themeAsset === '') {
                $themeAsset = '/static/theme/' . $this->theme->getCurrentTheme();
            }
            $lines[] = '<link rel="icon" href="' . $h(rtrim($themeAsset, '/')) . '/favicon.ico" sizes="any">';
        }

        return implode("\n        ", $lines);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function resolvePageH1(array $vars): string
    {
        $context = (string) ($vars['seo_title_context'] ?? '');

        if ($context === 'home') {
            $siteName = trim((string) ($vars['site_name'] ?? ''));
            if ($siteName !== '') {
                return $siteName;
            }
        }

        if ($context === 'document') {
            $docTitle = trim((string) ($vars['document_title'] ?? ''));
            if ($docTitle !== '') {
                return $docTitle;
            }
        }

        if ($context === 'item') {
            $itemTitle = trim((string) ($vars['page_title'] ?? $vars['field']['name'] ?? ''));
            if ($itemTitle !== '') {
                return $itemTitle;
            }
        }

        if ($context === 'error') {
            $errMsg = trim((string) ($vars['error_msg'] ?? ''));
            if ($errMsg !== '') {
                return $errMsg;
            }
        }

        if (is_array($vars['www_plugin'] ?? null)) {
            $pluginTitle = trim((string) ($vars['www_plugin']['title'] ?? ''));
            if ($pluginTitle !== '') {
                return $pluginTitle;
            }
        }

        if (is_array($vars['www_theme'] ?? null)) {
            $themeTitle = trim((string) ($vars['www_theme']['title'] ?? ''));
            if ($themeTitle !== '') {
                return $themeTitle;
            }
        }

        $tagName = trim((string) ($vars['tag_name'] ?? ''));
        if ($tagName !== '') {
            return $tagName;
        }

        return trim((string) ($vars['page_title'] ?? ''));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function resolveOgImage(array $vars): string
    {
        $candidates = [
            trim((string) ($vars['document_litpic'] ?? '')),
            trim((string) ($vars['field']['litpic'] ?? '')),
            trim((string) ($vars['site_logo'] ?? '')),
        ];
        foreach ($candidates as $src) {
            if ($src !== '') {
                return $this->absoluteUrl($src);
            }
        }

        return '';
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $path = $url[0] === '/' ? $url : '/' . $url;
        $configured = trim((string) app(\app\common\service\config\ConfigService::class)->get('site_url', ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/') . $path;
        }

        $scheme = (Request::isSsl()) ? 'https' : 'http';
        $host   = trim((string) Request::host());
        if ($host === '') {
            return $path;
        }

        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function buildDefaultJsonLd(
        array $vars,
        string $pageUrl,
        string $ogImage,
        string $title,
        string $description
    ): string {
        $context = (string) ($vars['seo_title_context'] ?? '');
        $siteName = app(SeoTitleService::class)->siteName();

        if ($context === 'document') {
            $payload = array_filter([
                '@context'      => 'https://schema.org',
                '@type'         => 'Article',
                'headline'      => trim((string) ($vars['document_title'] ?? $title)),
                'datePublished' => trim((string) ($vars['document_date'] ?? '')),
                'description'   => $description,
                'url'           => $pageUrl,
                'image'         => $ogImage !== '' ? [$ogImage] : null,
            ], static fn ($v) => $v !== null && $v !== '');

            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if ($context === 'item') {
            $productBrand = trim((string) ($vars['field']['product_brand']
                ?? $vars['field']['field_extra_brand']
                ?? ''));
            $orgName = trim((string) ($vars['site_name'] ?? $siteName));
            $brandName = $productBrand !== '' ? $productBrand : $orgName;
            $model = trim((string) ($vars['field']['model'] ?? ''));
            $payload = array_filter([
                '@context'    => 'https://schema.org',
                '@type'       => 'Product',
                'name'        => trim((string) ($vars['page_title'] ?? $title)),
                'description' => $description !== '' ? $description : null,
                'url'         => $pageUrl,
                'image'       => $ogImage !== '' ? [$ogImage] : null,
                'sku'         => $model !== '' ? $model : null,
                'brand'       => $brandName !== '' ? [
                    '@type' => 'Brand',
                    'name'  => $brandName,
                ] : null,
                'manufacturer' => $orgName !== '' ? [
                    '@type' => 'Organization',
                    'name'  => $orgName,
                ] : null,
            ], static fn ($v) => $v !== null && $v !== '');

            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if ($context === 'home') {
            $org = array_filter([
                '@context' => 'https://schema.org',
                '@type'    => 'Organization',
                'name'     => $siteName,
                'url'      => $this->absoluteUrl(SiteUrl::home()),
                'logo'     => $ogImage !== '' ? $ogImage : null,
            ], static fn ($v) => $v !== null && $v !== '');

            $website = array_filter([
                '@context'        => 'https://schema.org',
                '@type'           => 'WebSite',
                'name'            => $siteName,
                'url'             => $this->absoluteUrl(SiteUrl::home()),
                'description'     => $description,
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => $this->absoluteUrl(SiteUrl::search()) . '?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ], static fn ($v) => $v !== '');

            return json_encode([$org, $website], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if ($context === 'error') {
            return '';
        }

        $webPage = array_filter([
            '@context'    => 'https://schema.org',
            '@type'       => 'WebPage',
            'name'        => $title,
            'description' => $description !== '' ? $description : null,
            'url'         => $pageUrl,
            'image'       => $ogImage !== '' ? $ogImage : null,
            'isPartOf'    => [
                '@type' => 'WebSite',
                'name'  => $siteName,
                'url'   => $this->absoluteUrl(SiteUrl::home()),
            ],
        ], static fn ($v) => $v !== null && $v !== '');

        return json_encode($webPage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function resolveCanonicalPageUrl(array $vars): string
    {
        foreach (['seo_og_url', 'document_share_url', 'page_url'] as $key) {
            $candidate = trim((string) ($vars[$key] ?? ''));
            if ($candidate === '' || $candidate === '#') {
                continue;
            }

            return $this->absoluteUrl($candidate);
        }

        return $this->currentPageUrl();
    }

    private function currentPageUrl(): string
    {
        $path = app(\app\common\service\site\SiteNavService::class)->currentPath();
        $configured = trim((string) app(\app\common\service\config\ConfigService::class)->get('site_url', ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/') . ($path === '/' ? '/' : $path);
        }

        $scheme = (Request::isSsl()) ? 'https' : 'http';
        $host   = trim((string) Request::host());
        if ($host === '') {
            return $path === '/' ? '/' : $path;
        }

        return $scheme . '://' . $host . ($path === '/' ? '/' : $path);
    }
}
