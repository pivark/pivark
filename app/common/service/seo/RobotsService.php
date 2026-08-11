<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\support\ServiceResult;
use app\common\support\ProjectPaths;

use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
/** robots.txt 管理 */
class RobotsService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly SitemapService $sitemapService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** @return list<string> */
    public function configKeys(): array
    {
        return ['seo_robots_txt', 'seo_robots_preset'];
    }

    /** @return array<string, string> */
    public function presetLabels(): array
    {
        return [
            'close_all'   => '全部关闭',
            'open_all'    => '全部开放',
            'foreign_only'=> '仅开放国外',
            'ai_crawler'  => 'AI爬虫专属',
            'custom'      => '自定义',
        ];
    }

    public function normalizePreset(string $preset): string
    {
        return array_key_exists($preset, $this->presetLabels()) ? $preset : 'custom';
    }

    public function currentPreset(): string
    {
        $preset = (string) $this->configService->get('seo_robots_preset', '');

        return $this->normalizePreset($preset !== '' ? $preset : 'open_all');
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        return [
            'seo_robots_txt'    => $this->rulesBody(),
            'seo_robots_preset' => $this->currentPreset(),
        ];
    }

    /** 前台 /robots.txt：规则 + 运行时 Sitemap（跟 site_url/canonical） */
    public function content(): string
    {
        return $this->composePublic($this->rulesBody());
    }

    /** 仅规则正文（不含 Sitemap）；Sitemap 一律由 SitemapService 生成 */
    public function rulesBody(): string
    {
        $raw = trim((string) $this->configService->get('seo_robots_txt', ''));
        if ($raw === '') {
            return $this->presetRules($this->currentPreset());
        }

        return $this->stripSitemapLines($raw);
    }

    public function defaultContent(): string
    {
        return $this->composePublic($this->presetRules('open_all'));
    }

    public function presetContent(string $preset): string
    {
        $preset = $this->normalizePreset($preset);
        if ($preset === 'custom') {
            return $this->content();
        }

        return $this->composePublic($this->presetRules($preset));
    }

    /** @return string 预设规则正文（无 Sitemap） */
    private function presetRules(string $preset): string
    {
        $preset = $this->normalizePreset($preset);
        $lines = match ($preset) {
            'close_all' => [
                'User-agent: *',
                'Disallow: /',
            ],
            'open_all' => [
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin/',
                'Disallow: /data/',
            ],
            'foreign_only' => [
                'User-agent: Baiduspider',
                'Disallow: /',
                '',
                'User-agent: Sogou web spider',
                'Disallow: /',
                '',
                'User-agent: 360Spider',
                'Disallow: /',
                '',
                'User-agent: YisouSpider',
                'Disallow: /',
                '',
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin/',
                'Disallow: /data/',
            ],
            'ai_crawler' => [
                'User-agent: GPTBot',
                'Disallow: /',
                '',
                'User-agent: ChatGPT-User',
                'Disallow: /',
                '',
                'User-agent: Claude-Web',
                'Disallow: /',
                '',
                'User-agent: anthropic-ai',
                'Disallow: /',
                '',
                'User-agent: Google-Extended',
                'Disallow: /',
                '',
                'User-agent: Bytespider',
                'Disallow: /',
                '',
                'User-agent: CCBot',
                'Disallow: /',
                '',
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin/',
                'Disallow: /data/',
            ],
            default => [
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin/',
                'Disallow: /data/',
            ],
        };

        return implode("\n", $lines);
    }

    private function composePublic(string $rules): string
    {
        $rules = rtrim($this->stripSitemapLines($rules));
        $sitemap = $this->sitemapLines();
        if ($sitemap === []) {
            return $rules . "\n";
        }

        return $rules . "\n\n" . implode("\n\n", $sitemap) . "\n";
    }

    private function stripSitemapLines(string $content): string
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*Sitemap\s*:/i', $line) === 1) {
                continue;
            }
            $kept[] = $line;
        }
        while ($kept !== [] && trim((string) end($kept)) === '') {
            array_pop($kept);
        }

        return implode("\n", $kept);
    }

    /** @return list<string> */
    private function sitemapLines(): array
    {
        if (!$this->sitemapService->isEnabled()) {
            return [];
        }

        $lines = [];
        foreach (['xml', 'txt', 'html'] as $type) {
            if ($this->sitemapService->isTypeEnabled($type)) {
                $lines[] = 'Sitemap: ' . $this->sitemapService->publicUrl($type);
            }
        }

        return $lines;
    }

    /**
     * @return ServiceResult
     */
    public function saveAdmin(string $content, string $preset = 'custom'): ServiceResult
    {
        $preset  = $this->normalizePreset($preset);
        $content = trim($content);
        if ($content === '') {
            return ServiceResult::fail('Robots 内容不能为空');
        }
        if (strlen($content) > 20000) {
            return ServiceResult::fail('内容过长');
        }

        // 落盘只存规则；Sitemap 由 site_url/canonical 运行时拼，避免域名变更后陈旧绝对 URL
        $rules = $this->stripSitemapLines($content);
        $public = $this->composePublic($rules);

        $this->configService->save([
            'seo_robots_txt'    => $rules,
            'seo_robots_preset' => $preset,
        ]);
        $this->writePublicFile($public);
        $this->auditLogService->operate('保存 robots.txt', 'admin.seo.robots', ['preset' => $preset]);

        return ServiceResult::ok(null, 'robots.txt 已保存');
    }

    public function writePublicFile(string $content): void
    {
        $path = ProjectPaths::publicDir() . DIRECTORY_SEPARATOR . 'robots.txt';
        file_put_contents($path, $content);
    }

    /** @return ServiceResult */
    public function resetDefault(): array
    {
        return $this->saveAdmin($this->presetContent('open_all'), 'open_all');
    }
}
