<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\model\SitePage;
use app\common\model\Tag;
use app\common\model\Document;

use app\common\service\audit\AuditLogService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\config\ConfigService;
use app\common\service\front\FrontUrlBuilder;
use app\common\service\front\FrontUrlRuleService;
use app\common\service\infra\UrlPathService;
use app\common\service\product\ProductSitemapService;
use app\common\model\Config;
use app\common\support\ProjectPaths;
use app\common\support\SiteUrl;
use app\common\support\QueryLimit;
use app\common\service\site\SitePageService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\theme\ThemeService;

/** 站点 Sitemap 生成与配置 */
class SitemapService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly AuditLogService $auditLogService,
        private readonly DocumentAttrHelper $documentService,
        private readonly FrontUrlBuilder $frontUrlBuilder,
        private readonly FrontUrlRuleService $frontUrlRuleService,
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly UrlPathService $urlPathService,
        private readonly SitePageService $sitePageService,
        private readonly ThemeService $themeService,
    ) {
    }

    /** @var list<array{loc:string,changefreq:string,priority:string,lastmod:string,title:string,kind:string}>|null */
    private ?array $collectUrlsCache = null;

    /** @var array{url_count:int,skipped_documents:int,document_included:int,pretty_url_mode:bool}|null */
    private ?array $qualityStatsCache = null;

    /** @return list<string> */
    public function configKeys(): array
    {
        return [
            'seo_sitemap_enabled',
            'seo_sitemap_type_xml',
            'seo_sitemap_type_txt',
            'seo_sitemap_type_html',
            'seo_sitemap_type_ai',
            'seo_sitemap_type_llms',
            'seo_sitemap_auto_update',
            'seo_sitemap_filter_hidden_tag',
            'seo_sitemap_filter_external',
            'seo_sitemap_freq_home',
            'seo_sitemap_freq_list',
            'seo_sitemap_freq_content',
            'seo_sitemap_priority_home',
            'seo_sitemap_priority_list',
            'seo_sitemap_priority_content',
            'seo_sitemap_limit_document',
            'seo_sitemap_limit_tag',
            'seo_baidu_push_token',
        ];
    }

    public function isEnabled(): bool
    {
        return (string) $this->configService->get('seo_sitemap_enabled', '1') === '1';
    }

    public function isTypeEnabled(string $type): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $key = 'seo_sitemap_type_' . strtolower(trim($type));

        return (string) $this->configService->get($key, $this->defaultFor($key)) === '1';
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        $out = [];
        foreach ($this->configKeys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'seo_sitemap_enabled', 'seo_sitemap_type_xml', 'seo_sitemap_auto_update' => '1',
            'seo_sitemap_filter_hidden_tag', 'seo_sitemap_filter_external' => '1',
            'seo_sitemap_freq_home' => 'daily',
            'seo_sitemap_freq_list' => 'hourly',
            'seo_sitemap_freq_content' => 'daily',
            'seo_sitemap_priority_home' => '1.0',
            'seo_sitemap_priority_list' => '0.8',
            'seo_sitemap_priority_content' => '0.5',
            'seo_sitemap_limit_document', 'seo_sitemap_limit_tag' => '100',
            'seo_baidu_push_token' => '',
            default => '0',
        };
    }

    /** @return array<string, string> */
    public function changefreqLabels(): array
    {
        return [
            'always'  => '经常',
            'hourly'  => '每小时',
            'daily'   => '每天',
            'weekly'  => '每周',
            'monthly' => '每月',
            'yearly'  => '每年',
            'never'   => '从不',
        ];
    }

    /** @return array<string, string> */
    public function priorityLabels(): array
    {
        return [
            '1.0' => '1.0',
            '0.9' => '0.9',
            '0.8' => '0.8',
            '0.7' => '0.7',
            '0.6' => '0.6',
            '0.5' => '0.5',
            '0.4' => '0.4',
            '0.3' => '0.3',
            '0.2' => '0.2',
            '0.1' => '0.1',
        ];
    }

    public function normalizeChangefreq(string $value): string
    {
        $allowed = array_keys($this->changefreqLabels());

        return in_array($value, $allowed, true) ? $value : 'daily';
    }

    public function normalizePriority(string $value): string
    {
        $value = number_format((float) $value, 1, '.', '');
        if (!isset($this->priorityLabels()[$value])) {
            return '0.5';
        }

        return $value;
    }

    public function normalizeLimit(int $value, int $max = 5000): int
    {
        return max(1, min($max, $value > 0 ? $value : 100));
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $payload = [];
        foreach ($this->configKeys() as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            // Token：空串=不改（防缓存读空后整表保存把已配置 Token 盖掉）
            if ($key === 'seo_baidu_push_token') {
                $token = mb_substr(trim((string) $data[$key]), 0, 128);
                if ($token === '') {
                    continue;
                }
                $payload[$key] = $token;
                continue;
            }
            $payload[$key] = $this->normalizeConfigValue($key, $data[$key]);
        }
        if ($payload === []) {
            return ServiceResult::fail('无有效配置项');
        }

        if (!isset($payload['seo_sitemap_enabled'])) {
            $payload['seo_sitemap_enabled'] = '0';
        }

        $docLimit = $this->normalizeLimit((int) ($payload['seo_sitemap_limit_document'] ?? $this->defaultFor('seo_sitemap_limit_document')));
        $tagLimit = $this->normalizeLimit((int) ($payload['seo_sitemap_limit_tag'] ?? $this->defaultFor('seo_sitemap_limit_tag')));
        if ($docLimit + $tagLimit > 5000) {
            return ServiceResult::fail('文档与 TAG 生成数量合计不能超过 5000');
        }
        $payload['seo_sitemap_limit_document'] = (string) $docLimit;
        $payload['seo_sitemap_limit_tag']     = (string) $tagLimit;

        $this->configService->save($payload);
        $this->auditLogService->operate('保存 Sitemap 配置', 'admin.seo.sitemap', []);

        if ($this->isEnabled()) {
            try {
                $stats = $this->rebuildPublicFiles();
            } catch (\Throwable $e) {
                return ServiceResult::ok(null, '配置已保存，但地图文件生成失败：' . $e->getMessage());
            }

            return ServiceResult::ok(
                $stats,
                '配置已保存，' . lcfirst($this->rebuildSuccessMessage($stats)),
            );
        }

        return ServiceResult::ok(null, '配置已保存');
    }

    /**
     * @return ServiceResult
     */
    public function rebuildAdmin(): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('请先启用 Sitemap');
        }

        try {
            $stats = $this->rebuildPublicFiles();
        } catch (\Throwable $e) {
            return ServiceResult::fail('生成失败：' . $e->getMessage());
        }

        return ServiceResult::ok($stats, $this->rebuildSuccessMessage($stats));
    }

    public function syncAfterContentChange(int $documentId = 0, string $scene = 'publish'): void
    {
        if (!$this->isEnabled() || (string) $this->configService->get('seo_sitemap_auto_update', '1') !== '1') {
            return;
        }

        try {
            $this->rebuildPublicFiles();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('sitemap_auto_rebuild_failed', [
                'document_id' => $documentId,
                'scene'       => $scene,
                'msg'         => $e->getMessage(),
            ]);
        }

        if ($documentId > 0 && $scene === 'publish') {
            $this->pushDocumentToBaidu($documentId);
        }
    }

    /**
     * @return ServiceResult
     */
    public function pushDocumentAdmin(int $documentId): ServiceResult
    {
        $token = trim((string) $this->configService->get('seo_baidu_push_token', ''));
        if ($token === '') {
            return ServiceResult::fail('未配置百度推送 Token');
        }
        if ($documentId < 1) {
            return ServiceResult::fail('文档 ID 无效');
        }
        $this->pushDocumentToBaidu($documentId);

        return ServiceResult::ok(null, '已提交推送请求');
    }

    /**
     * @return ServiceResult
     */
    public function pushBatchAdmin(int $limit = QueryLimit::MINIPROGRAM_WIDGET_MAX): ServiceResult
    {
        $token = trim((string) $this->configService->get('seo_baidu_push_token', ''));
        if ($token === '') {
            return ServiceResult::fail('未配置百度推送 Token');
        }
        $limit = max(1, min(100, $limit));
        $ids   = Document::where('status', 1)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->limit($limit)
            ->column('id');
        $n = 0;
        foreach ($ids as $id) {
            $this->pushDocumentToBaidu((int) $id);
            $n++;
        }

        return ServiceResult::ok(['pushed' => $n], "已向百度提交 {$n} 条 URL");
    }

    public function publicUrl(string $type = 'xml'): string
    {
        $path = match ($type) {
            'txt'  => '/sitemap.txt',
            'html' => '/sitemap.html',
            'ai'   => '/ai-sitemap.txt',
            'llms' => '/llms.txt',
            default => '/sitemap.xml',
        };
        $base = rtrim($this->canonicalBase(), '/');

        return ($base !== '' ? $base : '') . $path;
    }

    public function generateXml(?array $urls = null): string
    {
        $urls = $urls ?? $this->collectUrls();
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $entry) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars((string) $entry['loc'], ENT_XML1) . "</loc>\n";
            if (($entry['lastmod'] ?? '') !== '') {
                $xml .= '    <lastmod>' . htmlspecialchars((string) $entry['lastmod'], ENT_XML1) . "</lastmod>\n";
            }
            $xml .= '    <changefreq>' . htmlspecialchars((string) $entry['changefreq'], ENT_XML1) . "</changefreq>\n";
            $xml .= '    <priority>' . htmlspecialchars((string) $entry['priority'], ENT_XML1) . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return $xml;
    }

    public function generateTxt(?array $urls = null): string
    {
        $lines = [];
        foreach ($urls ?? $this->collectUrls() as $entry) {
            $lines[] = (string) ($entry['loc'] ?? '');
        }

        return implode("\n", array_filter($lines)) . "\n";
    }

    public function generateHtml(?array $urls = null): string
    {
        $siteName = (string) $this->configService->get('site_name', 'Sitemap');
        $urls     = $urls ?? $this->collectUrls();
        $html     = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
        $html    .= '<meta name="robots" content="noindex,follow">';
        $html    .= '<title>' . htmlspecialchars($siteName . ' - 网站地图', ENT_QUOTES) . '</title>';
        $html    .= '<style>body{font-family:sans-serif;max-width:960px;margin:24px auto;padding:0 16px;}';
        $html    .= 'ul{line-height:1.9;padding-left:20px;}a{color:#1e3c72;word-break:break-all;}</style></head><body>';
        $html    .= '<h1>' . htmlspecialchars($siteName . ' 网站地图', ENT_QUOTES) . '</h1><ul>';
        foreach ($urls as $entry) {
            $loc   = htmlspecialchars((string) ($entry['loc'] ?? ''), ENT_QUOTES);
            $title = htmlspecialchars((string) ($entry['title'] ?? $loc), ENT_QUOTES);
            $html .= '<li><a href="' . $loc . '">' . $title . '</a></li>';
        }
        $html .= '</ul></body></html>';

        return $html;
    }

    public function generateLlmsTxt(?array $urls = null): string
    {
        $siteName = (string) $this->configService->get('site_name', 'Site');
        $base     = $this->canonicalBase();
        $homeLoc  = $base . SiteUrl::home();
        $lines    = [
            '# ' . $siteName,
            '',
            '## 首页',
            $homeLoc,
            '',
            '## 主要页面',
        ];
        foreach ($urls ?? $this->collectUrls() as $entry) {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || $loc === $homeLoc) {
                continue;
            }
            $lines[] = '- ' . ((string) ($entry['title'] ?? '') !== '' ? ($entry['title'] . ': ') : '') . $loc;
        }

        return implode("\n", $lines) . "\n";
    }

    public function generateAiIndex(?array $urls = null): string
    {
        $siteName = (string) $this->configService->get('site_name', 'Site');
        $desc     = trim(strip_tags((string) $this->configService->get('site_description', '')));
        $lines    = [
            '# ' . $siteName,
            $desc !== '' ? $desc : 'Website content index',
            '',
            '## URLs',
        ];
        foreach ($urls ?? $this->collectUrls() as $entry) {
            $lines[] = (string) ($entry['loc'] ?? '');
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')) . "\n";
    }

    /** @return array{files:int,urls:int,public_mirrored:int} */
    public function rebuildPublicFiles(): array
    {
        $this->invalidateCollectCache();
        $urls           = $this->collectUrls();
        $files          = 0;
        $publicMirrored = 0;

        $map = [
            'xml'  => ['file' => 'sitemap.xml', 'content' => $this->generateXml($urls)],
            'txt'  => ['file' => 'sitemap.txt', 'content' => $this->generateTxt($urls)],
            'html' => ['file' => 'sitemap.html', 'content' => $this->generateHtml($urls)],
            'ai'   => ['file' => 'ai-sitemap.txt', 'content' => $this->generateAiIndex($urls)],
            'llms' => ['file' => 'llms.txt', 'content' => $this->generateLlmsTxt($urls)],
        ];

        foreach ($map as $type => $meta) {
            $fileName = (string) $meta['file'];
            if (!$this->isTypeEnabled($type)) {
                $this->removeSitemapFile($fileName);
                continue;
            }
            $this->writeSitemapFile($fileName, (string) $meta['content']);
            $files++;
            if ($this->mirrorSitemapToPublic($fileName, (string) $meta['content'])) {
                $publicMirrored++;
            }
        }

        return ['files' => $files, 'urls' => count($urls), 'public_mirrored' => $publicMirrored];
    }

    /** @return array{url_count:int,skipped_documents:int,document_included:int,pretty_url_mode:bool} */
    public function qualityStats(): array
    {
        $this->collectUrls();

        return $this->qualityStatsCache ?? [
            'url_count'           => 0,
            'skipped_documents'   => 0,
            'document_included'   => 0,
            'pretty_url_mode'     => $this->siteUrlModeService->usesPrettyUrl(),
        ];
    }

    private function invalidateCollectCache(): void
    {
        $this->collectUrlsCache  = null;
        $this->qualityStatsCache = null;
    }

    private function sitemapStorageDir(): string
    {
        $dir = ProjectPaths::runtimeDir() . 'seo' . DIRECTORY_SEPARATOR;
        if (!LocalFile::mkdirIfMissing($dir)) {
            throw new \RuntimeException('无法创建 Sitemap 运行态目录：' . $dir);
        }

        return $dir;
    }

    private function writeSitemapFile(string $fileName, string $content): void
    {
        $abs = $this->sitemapStorageDir() . $fileName;
        if (!LocalFile::putContents($abs, $content)) {
            throw new \RuntimeException('无法写入 Sitemap 文件：' . $abs);
        }
    }

    private function mirrorSitemapToPublic(string $fileName, string $content): bool
    {
        $abs = ProjectPaths::publicDir() . '/' . $fileName;

        return LocalFile::putContents($abs, $content);
    }

    private function removeSitemapFile(string $fileName): void
    {
        LocalFile::unlinkIfExists($this->sitemapStorageDir() . $fileName);
        LocalFile::unlinkIfExists(ProjectPaths::publicDir() . '/' . $fileName);
    }

    /** @param array{files?:int,urls?:int,public_mirrored?:int} $stats */
    private function rebuildSuccessMessage(array $stats): string
    {
        $files    = (int) ($stats['files'] ?? 0);
        $urls     = (int) ($stats['urls'] ?? 0);
        $mirrored = (int) ($stats['public_mirrored'] ?? 0);
        $msg      = sprintf('已更新 %d 个地图文件，共 %d 条 URL', $files, $urls);
        if ($mirrored < $files) {
            return $msg . '。public/ 无写入权限，已写入 data/runtime/seo/；前台 /sitemap.xml 等路由仍可动态访问';
        }

        return $msg . '。已同步至 public/ 与 data/runtime/seo/';
    }

    /**
     * @return list<array{loc:string,changefreq:string,priority:string,lastmod:string,title:string,kind:string}>
     */
    public function collectUrls(): array
    {
        if ($this->collectUrlsCache !== null) {
            return $this->collectUrlsCache;
        }

        $base = $this->canonicalBase();
        if ($base === '') {
            $this->qualityStatsCache = [
                'url_count'         => 0,
                'skipped_documents' => 0,
                'document_included' => 0,
                'pretty_url_mode'   => $this->siteUrlModeService->usesPrettyUrl(),
            ];
            $this->collectUrlsCache = [];

            return $this->collectUrlsCache;
        }

        $freqHome    = $this->normalizeChangefreq((string) $this->configService->get('seo_sitemap_freq_home', 'daily'));
        $freqList    = $this->normalizeChangefreq((string) $this->configService->get('seo_sitemap_freq_list', 'hourly'));
        $freqContent = $this->normalizeChangefreq((string) $this->configService->get('seo_sitemap_freq_content', 'daily'));
        $priHome     = $this->normalizePriority((string) $this->configService->get('seo_sitemap_priority_home', '1.0'));
        $priList     = $this->normalizePriority((string) $this->configService->get('seo_sitemap_priority_list', '0.8'));
        $priContent  = $this->normalizePriority((string) $this->configService->get('seo_sitemap_priority_content', '0.5'));
        $docLimit    = $this->normalizeLimit((int) $this->configService->get('seo_sitemap_limit_document', '100'));
        $tagLimit    = $this->normalizeLimit((int) $this->configService->get('seo_sitemap_limit_tag', '100'));
        // seo_sitemap_filter_hidden_tag 已忽略（曾绑 show_in_nav，现不再使用）
        $filterExternal  = (string) $this->configService->get('seo_sitemap_filter_external', '1') === '1';

        $urls   = [];
        $urls[] = $this->urlEntry($base . SiteUrl::home(), $freqHome, $priHome, '', '首页', 'home');
        $urls[] = $this->urlEntry($base . SiteUrl::documents(), $freqList, $priList, '', '文档列表', 'list');
        $urls[] = $this->urlEntry($base . SiteUrl::tags(), $freqList, $priList, '', '标签云', 'list');

        $docQuery = Document::where('status', 1)
            ->whereNull('deleted_at')
            ->field('id,title,html_name,url_path,updated_at,published_at,attr_flags,external_url')
            ->order('id', 'desc')
            ->limit(min($docLimit * 5, QueryLimit::SITEMAP_BATCH));
        $docAdded          = 0;
        $skippedDocuments  = 0;
        foreach ($docQuery->select()->toArray() as $row) {
            if (!is_array($row) || $docAdded >= $docLimit) {
                continue;
            }
            if ($filterExternal && $this->documentService->resolveExternalRedirectUrl($row) !== null) {
                continue;
            }
            if (!$this->shouldIncludeDocument($row)) {
                if ($this->siteUrlModeService->usesPrettyUrl()) {
                    $skippedDocuments++;
                }
                continue;
            }
            $loc = $base . SiteUrl::documentFromRow($row);
            $lastmod = (string) ($row['updated_at'] ?? $row['published_at'] ?? '');
            $urls[] = $this->urlEntry($loc, $freqContent, $priContent, $lastmod, (string) ($row['title'] ?? ''), 'document');
            $docAdded++;
        }

        // 栏目门牌出链归 site_nav；聚合 Tag 仅出未绑栏目的独立 path（避免同址双发）
        $categoryPaths = [];
        $navSvc = app(\app\common\service\site\SiteNavService::class);
        $navRows = \app\common\model\SiteNav::where('status', 1)
            ->field('id,title,nav_type,target,content_kind,status,updated_at')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->limit(QueryLimit::SITEMAP_BATCH)
            ->select()
            ->toArray();
        foreach ($navRows as $navRow) {
            if (!is_array($navRow)) {
                continue;
            }
            $navId = (int) ($navRow['id'] ?? 0);
            if ($navId < 1 || !$navSvc->isContentCategoryId($navId)) {
                continue;
            }
            $path = $navSvc->publicPathForContentCategory($navRow);
            if ($path === '' || isset($categoryPaths[$path])) {
                continue;
            }
            $categoryPaths[$path] = true;
            $loc = $base . $navSvc->resolveUrl($navRow);
            $urls[] = $this->urlEntry(
                $loc,
                $freqList,
                $priList,
                (string) ($navRow['updated_at'] ?? ''),
                (string) ($navRow['title'] ?? ''),
                'category'
            );
        }

        $tagQuery = Tag::where('status', 1)->field('id,name,slug,url_path,updated_at');
        foreach ($tagQuery->order('id', 'desc')->limit($tagLimit)->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '' || !$this->shouldIncludeTagSlug($slug)) {
                continue;
            }
            // 与栏目门牌 path 互斥：栏目 URL 已由 site_nav 收录，Tag 不再重复
            $tagPath = trim((string) ($row['url_path'] ?? ''), '/');
            if ($tagPath === '') {
                $tagPath = $slug;
            }
            if ($navSvc->findContentCategoryByPublicPath($tagPath) !== null) {
                continue;
            }
            $loc = $base . SiteUrl::tagFromRow($row);
            $urls[] = $this->urlEntry($loc, $freqList, $priList, (string) ($row['updated_at'] ?? ''), (string) ($row['name'] ?? ''), 'tag');
        }

        foreach (SitePage::where('status', 1)->field('title,path,tpl_name,updated_at')->limit(QueryLimit::SITEMAP_BATCH)->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim((string) ($row['path'] ?? ''), '/');
            if ($path === '' || !$this->shouldIncludeSitePagePath($path)) {
                continue;
            }
            $tplName = $this->sitePageService->normalizeTpl((string) ($row['tpl_name'] ?? ''));
            $tplFile = $this->sitePageService->resolveThemeTemplateFile($tplName) . '.php';
            if (!$this->themeService->siteTemplateOwnedByTheme($tplFile)) {
                continue;
            }
            $urls[] = $this->urlEntry(
                $base . $this->frontUrlBuilder->pageFromRow($row),
                $freqContent,
                $priContent,
                (string) ($row['updated_at'] ?? ''),
                (string) ($row['title'] ?? ''),
                'page'
            );
        }

        $urls = ProductSitemapService::appendUrls(
            $urls,
            $base,
            $freqList,
            $freqContent,
            $priList,
            $priContent,
        );

        $finalized = $this->finalizeUrls($urls);
        $this->qualityStatsCache = [
            'url_count'         => count($finalized),
            'skipped_documents' => $skippedDocuments,
            'document_included' => $docAdded,
            'pretty_url_mode'   => $this->siteUrlModeService->usesPrettyUrl(),
        ];
        $this->collectUrlsCache = $finalized;

        return $this->collectUrlsCache;
    }

    public function urlCount(): int
    {
        return (int) ($this->qualityStats()['url_count'] ?? 0);
    }

    /** @return array{loc:string,changefreq:string,priority:string,lastmod:string,title:string,kind:string} */
    private function urlEntry(
        string $loc,
        string $changefreq,
        string $priority,
        string $lastmod = '',
        string $title = '',
        string $kind = ''
    ): array {
        $lastmod = trim($lastmod);
        if ($lastmod !== '') {
            $ts = strtotime($lastmod);
            $lastmod = $ts !== false ? AppTime::format('Y-m-d', $ts) : '';
        }

        return [
            'loc'        => $loc,
            'changefreq' => $changefreq,
            'priority'   => $priority,
            'lastmod'    => $lastmod,
            'title'      => $title,
            'kind'       => $kind,
        ];
    }

    private function normalizeConfigValue(string $key, mixed $value): string
    {
        if (in_array($key, [
            'seo_sitemap_enabled', 'seo_sitemap_type_xml', 'seo_sitemap_type_txt', 'seo_sitemap_type_html',
            'seo_sitemap_type_ai', 'seo_sitemap_type_llms', 'seo_sitemap_auto_update',
            'seo_sitemap_filter_hidden_tag', 'seo_sitemap_filter_external',
        ], true)) {
            return (int) $value === 1 ? '1' : '0';
        }
        if (str_starts_with($key, 'seo_sitemap_freq_')) {
            return $this->normalizeChangefreq((string) $value);
        }
        if (str_starts_with($key, 'seo_sitemap_priority_')) {
            return $this->normalizePriority((string) $value);
        }
        if ($key === 'seo_sitemap_limit_document' || $key === 'seo_sitemap_limit_tag') {
            return (string) $this->normalizeLimit((int) $value);
        }
        if ($key === 'seo_baidu_push_token') {
            return mb_substr(trim((string) $value), 0, 128);
        }

        return trim((string) $value);
    }

    private function siteBase(): string
    {
        $cfg = trim((string) $this->configService->get('site_url', ''));

        return $cfg !== '' ? rtrim($cfg, '/') : '';
    }

    /** 与前台 canonical 一致：site_url + 可选强制 HTTPS */
    private function canonicalBase(): string
    {
        $base = $this->siteBase();

        return $base !== '' ? $this->normalizeLoc($base) : '';
    }

    /** sitemap.org：loc 须为绝对 canonical URL（无 fragment / 小写 host / HTTPS 对齐） */
    private function normalizeLoc(string $loc): string
    {
        $loc = trim($loc);
        if ($loc === '') {
            return '';
        }
        if ((string) $this->configService->get('site_force_https', '0') === '1') {
            $loc = preg_replace('#^http://#i', 'https://', $loc) ?? $loc;
        }
        $parts = parse_url($loc);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $loc;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host   = strtolower((string) $parts['host']);
        $path   = (string) ($parts['path'] ?? '');
        $path   = preg_replace('#/+#', '/', $path) ?: '';
        $query  = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $port   = isset($parts['port']) ? (int) $parts['port'] : null;
        $portStr = '';
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $portStr = ':' . $port;
        }

        return $scheme . '://' . $host . $portStr . $path . $query;
    }

    /**
     * 伪静态/静态：仅收录有友好路径的文档（禁止 /documents/123.html 数字 ID 入图）
     *
     * @param array<string, mixed> $row
     */
    private function shouldIncludeDocument(array $row): bool
    {
        if (!$this->siteUrlModeService->usesPrettyUrl()) {
            return true;
        }

        return $this->frontUrlRuleService->documentHasFriendlyPublicKey($row);
    }

    private function shouldIncludeTagSlug(string $slug): bool
    {
        $normalized = $this->urlPathService->normalize($slug);
        if ($normalized === '' || $this->urlPathService->isReserved($normalized)) {
            return false;
        }

        return !$this->isExcludedTagSlug($normalized);
    }

    private function shouldIncludeSitePagePath(string $path): bool
    {
        $normalized = $this->urlPathService->normalize($path);

        return $normalized !== '' && !$this->urlPathService->isReserved($normalized);
    }

    /** 排除 QA/E2E/测试 slug，避免污染生产 sitemap */
    private function isExcludedTagSlug(string $slug): bool
    {
        return preg_match('/^(e2e(-tag)?-|qa-|test-|tmp-|demo-)/i', $slug) === 1;
    }

    /**
     * 去重 + 规范化 loc（SEO：同一 URL 只出现一次）
     *
     * @param list<array{loc:string,changefreq:string,priority:string,lastmod:string,title:string,kind:string}> $urls
     * @return list<array{loc:string,changefreq:string,priority:string,lastmod:string,title:string,kind:string}>
     */
    private function finalizeUrls(array $urls): array
    {
        $seen = [];
        $out  = [];
        foreach ($urls as $entry) {
            $loc = $this->normalizeLoc((string) ($entry['loc'] ?? ''));
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc]   = true;
            $entry['loc'] = $loc;
            $out[]        = $entry;
        }

        return $out;
    }

    private function pushDocumentToBaidu(int $documentId): void
    {
        $token = trim((string) $this->configService->get('seo_baidu_push_token', ''));
        if ($token === '' || $documentId < 1) {
            return;
        }

        $row = Document::where('id', $documentId)->where('status', 1)->whereNull('deleted_at')->find();
        if (!is_array($row)) {
            return;
        }
        if ((string) $this->configService->get('seo_sitemap_filter_external', '1') === '1'
            && $this->documentService->resolveExternalRedirectUrl($row) !== null) {
            return;
        }

        $base = $this->siteBase();
        if ($base === '') {
            return;
        }

        $url  = $base . SiteUrl::documentFromRow($row);
        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }

        $endpoint = 'http://data.zz.baidu.com/urls?site=' . rawurlencode($host) . '&token=' . rawurlencode($token);
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: text/plain\r\n",
                'content' => $url,
                'timeout' => 5,
            ],
        ]);
        LocalFile::getContents($endpoint, false, $ctx);
    }
}
