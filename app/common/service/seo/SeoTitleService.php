<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;
use app\common\service\seo\SeoConfigService;

use app\common\service\config\ConfigService;
use app\common\service\site\SiteBrandService;
/** 按 SEO 配置拼装页面 &lt;title&gt; */
class SeoTitleService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly SeoConfigService $seoConfigService,
    ) {
    }

    public function separator(): string
    {
        $sep = trim((string) $this->configService->get('seo_title_separator', ' - '));

        return $sep !== '' ? $sep : ' - ';
    }

    public function siteName(): string
    {
        return app(SiteBrandService::class)->resolveSiteNameForTemplate(
            (string) $this->configService->get('site_name', ''),
            (string) $this->configService->get('site_title', '')
        );
    }

    public function tagTitle(string $tagName, int $page = 1): string
    {
        $rule = $this->seoConfigService->normalizeRule(
            'seo_tag_title_rule',
            (string) $this->configService->get('seo_tag_title_rule', 'name_page_site')
        );
        $sep = $this->separator();
        $site = $this->siteName();
        $pagePart = $page > 1 ? '第' . $page . '页' : '';

        return match ($rule) {
            'name_site' => $this->join([$tagName, $site], $sep),
            'name_page' => $this->join([$tagName, $pagePart], $sep),
            'name_page_site' => $this->join(array_filter([$tagName, $pagePart, $site], fn ($p) => $p !== ''), $sep),
            default => $tagName,
        };
    }

    public function documentTitle(string $title, string $tagName = ''): string
    {
        $rule = $this->seoConfigService->normalizeRule(
            'seo_document_title_rule',
            (string) $this->configService->get('seo_document_title_rule', 'title_site')
        );
        $sep = $this->separator();
        $site = $this->siteName();

        return match ($rule) {
            'title' => $title,
            'title_tag_site' => $tagName !== ''
                ? $this->join([$title, $tagName, $site], $sep)
                : $this->join([$title, $site], $sep),
            default => $this->join([$title, $site], $sep),
        };
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function finalizeFromPageVars(array $pageVars): string
    {
        $raw = trim((string) ($pageVars['seo_title'] ?? $pageVars['page_title'] ?? ''));
        if ($raw === '') {
            return $this->siteName();
        }

        $context = (string) ($pageVars['seo_title_context'] ?? '');
        if ($context === 'document') {
            $tagName = '';
            $tags = $pageVars['document_tags'] ?? [];
            if (is_array($tags) && isset($tags[0]['name'])) {
                $tagName = (string) $tags[0]['name'];
            }

            return $this->documentTitle($raw, $tagName);
        }
        if ($context === 'tag') {
            $tagName = trim((string) ($pageVars['tag_name'] ?? $raw));

            return $this->tagTitle($tagName, max(1, (int) ($pageVars['page'] ?? 1)));
        }

        $site = $this->siteName();

        return $site !== '' && !str_contains($raw, $site)
            ? $this->join([$raw, $site], $this->separator())
            : $raw;
    }

    /** @param list<string> $parts */
    private function join(array $parts, string $sep): string
    {
        $parts = array_values(array_filter($parts, static fn ($p) => trim($p) !== ''));

        return implode($sep, $parts);
    }
}
