<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from StaticHtmlService — 生成与增量同步
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\service\plugin\extension\PluginPortalInvoke;

use app\common\service\infra\PaginationService;
use app\common\support\ServiceResult;

use app\common\support\DbRead;
use app\common\model\Tag;
use app\common\model\SitePage;
use app\common\model\Document;
use app\common\support\SiteUrl;
use app\common\support\ProjectPaths;

class StaticHtmlGeneratorService
{

    private const CURSOR_BATCH = 200;

    /** help 单页模板（Community 包不含 DocsSectionPagesService 时跳过） */
    private const DOCS_SECTION_TPL = 'list_page_docs_section';

    /** 每处理 N 篇/页触发一次 GC，缓解大 HTML 字符串碎片 */
    private const GC_EVERY = 25;

    public function __construct(
        private readonly StaticHtmlGeneratorConfigDeps $config,
        private readonly StaticHtmlGeneratorContentDeps $content,
    ) {
    }

    private function isEnabled(): bool
    {
        return $this->config->siteUrlModeService->isStatic();
    }

    /**
     * @return array{written:int,skipped:int,deleted:int,errors:list<string>}
     */
    /**
     * @param bool $purgeFirst 是否先清空 manifest（大规模站点请用 StaticHtmlBatchService 分批）
     */
    public function rebuildAll(bool $purgeFirst = true): array
    {
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        if ($purgeFirst) {
            $this->config->staticHtmlManifestService->purgeAll($stats);
        }

        try {
            $this->generateHome($stats);
            $this->generateAllSitePages($stats);
            $this->generateAllTags($stats);
            $this->generateSystemLists($stats);
            $this->generateAllArticles($stats);
        } catch (\Throwable $e) {
            $stats['errors'][] = $this->humanizeGenerateError($e->getMessage());
        }

        return $stats;
    }

    /**
     * URL/主题变更后同步 manifest：禁止在 urlSave HTTP 内整站 rebuild（易超 10s）。
     * 整站生成请走后台「HTML 生成」分批任务（StaticHtmlBatchService）。
     */
    public function syncAfterConfigChange(): void
    {
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        if ($this->isEnabled()) {
            $this->config->staticHtmlManifestService->purgeAll($stats);
            return;
        }
        $this->config->staticHtmlManifestService->purgeAll($stats);
    }

    /**
     * 全站公共嵌入（浮动联系等）变更：静态页须全量重写，不能依赖文档 updated_at 跳过。
     */
    public function syncAfterGlobalEmbedChange(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->rebuildAll(true);
    }

    /**
     * @return mixed
     * @param mixed $id
     * @param string $scene publish|edit
     */
    public function syncAfterArticleChange(int $id, string $scene = 'publish'): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $flags = $this->config->seoStaticConfigService->syncFlags();
        $usePublish = $scene !== 'edit';
        $home     = $usePublish ? $flags['publish_home'] : $flags['edit_home'];
        $channel  = $usePublish ? $flags['publish_channel'] : $flags['edit_channel'];
        $adjacent = $usePublish ? $flags['publish_adjacent'] : $flags['edit_adjacent'];

        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        if ($id > 0) {
            $this->generateArticle($id, $stats);
        }
        if ($home) {
            $this->generateHome($stats);
        }
        if ($channel) {
            foreach ($this->content->tagService->getTagsForDocument($id) as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                if ($tagId > 0) {
                    $this->generateTagById($tagId, $stats);
                }
            }
        }
        if ($adjacent && $id > 0) {
            $adj = $this->content->documentService->getAdjacentPublic($id);
            foreach (['prev', 'next'] as $key) {
                $row = $adj[$key] ?? null;
                if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                    $this->generateArticle((int) $row['id'], $stats);
                }
            }
        }
    }

    /**
     * @return ServiceResult
     */
    public function generateAdmin(string $scope, int $id = 0): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('请先将 URL 模式设为「静态页面」');
        }

        $scope = strtolower(trim($scope));
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];

        try {
            if ($scope === 'all') {
                $stats = $this->rebuildAll();
            } elseif ($scope === 'home') {
                $this->generateHome($stats);
            } elseif ($scope === 'tag') {
                if ($id > 0) {
                    $this->generateTagById($id, $stats);
                } else {
                    $this->generateAllTags($stats);
                }
            } elseif ($scope === 'document') {
                if ($id > 0) {
                    $this->generateArticle($id, $stats);
                } else {
                    $this->generateAllArticles($stats);
                }
            } else {
                return ServiceResult::fail('无效的生成范围');
            }
        } catch (\Throwable $e) {
            return ServiceResult::fail('生成失败：' . $this->humanizeGenerateError($e->getMessage()));
        }

        $stats['output_dir'] = $this->staticOutputRelativeDir();

        return ServiceResult::ok($stats, $this->buildGenerateAdminMessage($stats));
    }

    /**
     * @return mixed
     * @param mixed $id
     * @param mixed $row
     */
    public function syncAfterArticleDelete(int $id, ?array $row = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        if ($row === null && $id > 0) {
            $row = $this->readDocumentRow($id);
        }
        if (is_array($row)) {
            $this->deleteForUrl(SiteUrl::documentFromRow($row), $stats);
        }
        $this->generateSystemLists($stats);
    }

    /**
     * @return mixed
     * @param mixed $id
     * @param mixed $oldPath
     */
    public function syncAfterSitePageChange(int $id, ?string $oldPath = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        if ($oldPath !== null && $oldPath !== '') {
            $this->deleteForUrl($this->content->frontUrlBuilder->pageFromRow(['path' => $oldPath]), $stats);
        }
        if ($id > 0) {
            $row = $this->readSitePageRow($id);
            if (is_array($row) && (int) ($row['status'] ?? 0) === 1) {
                $this->generateSitePage($id, $stats, $row);
            } elseif (is_array($row)) {
                $this->deleteForUrl($this->content->frontUrlBuilder->pageFromRow($row), $stats);
            }
        }
        $this->generateHome($stats);
    }

    /**
     * @return mixed
     * @param mixed $row
     */
    public function syncAfterSitePageDelete(?array $row): void
    {
        if (!$this->isEnabled() || !is_array($row)) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $this->deleteForUrl($this->content->frontUrlBuilder->pageFromRow($row), $stats);
        $this->generateHome($stats);
    }

    /**
     * @return mixed
     * @param mixed $id
     */
    public function syncAfterTagChange(int $id): void
    {
        if (!$this->isEnabled() || $id < 1) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $row = $this->readTagRow($id);
        if (is_array($row) && (int) ($row['status'] ?? 0) === 1) {
            $this->generateTagById($id, $stats, $row);
        } elseif (is_array($row)) {
            $this->deleteForUrl(SiteUrl::tagFromRow($row, 1), $stats);
        }
        $this->generateHome($stats);
    }

    /**
     * @return mixed
     * @param mixed $row
     */
    public function syncAfterTagDelete(?array $row): void
    {
        if (!$this->isEnabled() || !is_array($row)) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $path = $this->content->tagService->publicPath($row);
        if ($path !== '') {
            $this->deleteForUrl($this->content->frontUrlBuilder->tagFromRow($row, 1), $stats);
        }
        $this->generateHome($stats);
    }

    /**
     * @return mixed
     */
    public function syncAfterNavChange(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        // HTTP 请求内禁止 regenerate 全部单页+Tag（栏目保存会超时）；全量由队列 seedFramework / 手动生成承担
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $this->generateHome($stats);
    }

    /**
     * @return mixed
     */
    public function syncAfterSlideChange(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $stats = ['written' => 0, 'skipped' => 0, 'deleted' => 0, 'errors' => []];
        $this->generateHome($stats);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     * @return mixed
     */
    private function generateHome(array &$stats): void
    {
        $this->writeForUrl(SiteUrl::home(), $this->content->frontRenderService->htmlHome(), $stats);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateAllSitePages(array &$stats): void
    {
        $cursor = 0;
        $n      = 0;
        while (true) {
            $rows = DbRead::model(SitePage::class)
                ->where('status', 1)
                ->where('id', '>', $cursor)
                ->order('id', 'asc')
                ->limit(self::CURSOR_BATCH)
                ->select()
                ->toArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $this->generateSitePage($id, $stats, $row);
                $cursor = $id;
                if (++$n % self::GC_EVERY === 0) {
                    gc_collect_cycles();
                }
            }
            unset($rows);
        }
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateSitePage(int $id, array &$stats, ?array $row = null): void
    {
        if ($id < 1) {
            return;
        }
        if ($row === null) {
            $row = $this->readActiveSitePageRow($id);
        }
        if (!$row) {
            return;
        }
        $public = $this->content->sitePageService->findByPath((string) ($row['path'] ?? ''));
        if ($public === null) {
            $public = [
                'title'           => (string) ($row['title'] ?? ''),
                'path'            => (string) ($row['path'] ?? ''),
                'url'             => $this->content->frontUrlBuilder->pageFromRow($row),
                'tpl_name'        => (string) ($row['tpl_name'] ?? ''),
                'seo_title'       => (string) ($row['seo_title'] ?? ''),
                'seo_keywords'    => (string) ($row['seo_keywords'] ?? ''),
                'seo_description' => (string) ($row['seo_description'] ?? ''),
            ];
        }
        if (
            (string) ($public['tpl_name'] ?? '') === self::DOCS_SECTION_TPL
            && !PluginPortalInvoke::portalClassExists('DocsSectionPagesService')
        ) {
            $stats['skipped']++;

            return;
        }
        try {
            $this->writeForUrl((string) $public['url'], $this->content->frontRenderService->htmlSitePage($public), $stats);
        } catch (\Throwable $e) {
            $stats['errors'][] = $this->humanizeGenerateError($e->getMessage(), (string) ($public['path'] ?? ''));
        }
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateAllTags(array &$stats): void
    {
        $cursor = 0;
        $n      = 0;
        while (true) {
            $rows = DbRead::model(Tag::class)
                ->where('status', 1)
                ->where('id', '>', $cursor)
                ->order('id', 'asc')
                ->limit(self::CURSOR_BATCH)
                ->select()
                ->toArray();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $this->generateTagById($id, $stats, $row);
                $cursor = $id;
                if (++$n % self::GC_EVERY === 0) {
                    gc_collect_cycles();
                }
            }
            unset($rows);
        }
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateTagById(int $id, array &$stats, ?array $row = null): void
    {
        if ($id < 1) {
            return;
        }
        if ($row === null) {
            $row = $this->readActiveTagRow($id);
        }
        if (!$row || (string) ($row['slug'] ?? '') === '') {
            return;
        }

        foreach ($this->tagPageNumbers($row) as $page) {
            $this->generateTagPage($id, $page, $stats, $row);
        }
    }

    /** @param array<string,mixed> $row @return list<int> */
    public function tagPageNumbers(array $row): array
    {
        $slug = (string) ($row['slug'] ?? '');
        if ($slug === '') {
            return [];
        }

        $tpl    = app(\app\common\service\theme\ThemeTemplateCatalogService::class)
            ->resolveTagListTpl((string) ($row['tpl_name'] ?? ''));
        $limit  = app(PaginationService::class)->frontListPageSize($tpl);
        $result = $this->content->documentService->listPublic([
            'page'  => 1,
            'limit' => $limit,
            'tags'  => $slug,
            'sort'  => 'id_desc',
        ]);
        $totalPages = max(1, (int) ceil(max(0, $result['total']) / max(1, $result['limit'])));
        $pages = [];
        for ($page = 1; $page <= $totalPages; $page++) {
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param array{t:string,id?:int,page?:int,k?:string} $item
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    public function runWorkItem(array $item, array &$stats): void
    {
        $type = (string) ($item['t'] ?? '');
        match ($type) {
            'home' => $this->generateHome($stats),
            'page' => $this->generateSitePage((int) ($item['id'] ?? 0), $stats),
            'sys'  => $this->generateSystemListKey((string) ($item['k'] ?? ''), $stats),
            'tag'  => $this->generateTagPage((int) ($item['id'] ?? 0), max(1, (int) ($item['page'] ?? 1)), $stats),
            'doc'  => $this->generateArticle((int) ($item['id'] ?? 0), $stats),
            default => null,
        };
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateSystemListKey(string $key, array &$stats): void
    {
        if ($key === 'documents') {
            $this->writeForUrl(SiteUrl::documents(), $this->content->frontRenderService->htmlDocumentList(), $stats);
            return;
        }
        if ($key === 'tags') {
            $this->writeForUrl(SiteUrl::tags(), $this->content->frontRenderService->htmlTagsCloud(), $stats);
        }
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateTagPage(int $id, int $page, array &$stats, ?array $row = null): void
    {
        if ($id < 1) {
            return;
        }
        if ($row === null) {
            $row = $this->readActiveTagRow($id);
        }
        if (!$row || (string) ($row['slug'] ?? '') === '') {
            return;
        }

        $this->writeForUrl(
            SiteUrl::tagFromRow($row, $page),
            $this->content->frontRenderService->htmlTag($row, $page),
            $stats
        );
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateAllArticles(array &$stats): void
    {
        $cursor = 0;
        $n      = 0;
        while (true) {
            $ids = $this->content->staticHtmlDocumentQuery->idsAfterCursor($cursor, self::CURSOR_BATCH, []);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $this->generateArticle($id, $stats);
                $cursor = $id;
                if (++$n % self::GC_EVERY === 0) {
                    gc_collect_cycles();
                }
            }
            unset($ids);
        }
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateArticle(int $id, array &$stats): void
    {
        if ($id < 1) {
            return;
        }
        $row = $this->content->staticHtmlDocumentQuery->publishedQuery()
            ->where('id', $id)
            ->find()?->toArray();
        if (!$row) {
            $old = $this->readDocumentRow($id);
            if (is_array($old)) {
                $this->deleteForUrl(SiteUrl::documentFromRow($old), $stats);
            }
            return;
        }

        // 受限阅读文档不生成静态页，避免缓存登录前/登录后不一致
        if ((int) ($row['read_perm'] ?? 0) === 1) {
            $this->deleteForUrl(SiteUrl::documentFromRow($row), $stats);
            $stats['skipped']++;

            return;
        }

        $url = SiteUrl::documentFromRow($row);
        $rel = $this->config->staticHtmlPathService->relativePathFromUrl($url);
        $updatedRaw = (string) ($row['updated_at'] ?? $row['published_at'] ?? $row['created_at'] ?? '');
        if ($rel !== null && $this->config->staticHtmlSkipSupport->isFileFreshForUpdatedAt($rel, $updatedRaw)) {
            $stats['skipped']++;

            return;
        }

        $html = $this->content->frontRenderService->htmlDocument($id);
        if ($html === null) {
            $this->deleteForUrl(SiteUrl::documentFromRow($row), $stats);
            $stats['skipped']++;
            return;
        }

        $this->writeForUrl(SiteUrl::documentFromRow($row), $html, $stats);
        unset($html, $row);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function generateSystemLists(array &$stats): void
    {
        $this->writeForUrl(SiteUrl::documents(), $this->content->frontRenderService->htmlDocumentList(), $stats);
        $this->writeForUrl(SiteUrl::tags(), $this->content->frontRenderService->htmlTagsCloud(), $stats);
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function writeForUrl(string $url, string $html, array &$stats): void
    {
        $rel = $this->config->staticHtmlPathService->relativePathFromUrl($url);
        if ($rel === null) {
            $stats['skipped']++;
            return;
        }
        try {
            $this->config->staticHtmlManifestService->writeRelative($rel, $html);
            $stats['written']++;
        } catch (\Throwable $e) {
            $stats['errors'][] = $this->formatWriteErrorLabel($url, $rel)
                . ': ' . $this->humanizeGenerateError($e->getMessage());
        }
    }

    /**
     * @param array{written:int,skipped:int,deleted:int,errors:list<string>} $stats
     */
    private function deleteForUrl(string $url, array &$stats): void
    {
        $rel = $this->config->staticHtmlPathService->relativePathFromUrl($url);
        if ($rel === null) {
            return;
        }
        $this->config->staticHtmlManifestService->deleteRelative($rel, $stats);
    }

    /** @return array<string, mixed>|null */
    private function readDocumentRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return DbRead::model(Document::class)->where('id', $id)->find()?->toArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function readTagRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return DbRead::model(Tag::class)->where('id', $id)->find()?->toArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function readActiveTagRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return DbRead::model(Tag::class)->where('id', $id)->where('status', 1)->find()?->toArray() ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function activeTagRows(): array
    {
        return DbRead::model(Tag::class)->where('status', 1)->select()->toArray();
    }

    /** @return array<string, mixed>|null */
    private function readSitePageRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return DbRead::model(SitePage::class)->where('id', $id)->find()?->toArray() ?: null;
    }

    /** @return array<string, mixed>|null */
    private function readActiveSitePageRow(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return DbRead::model(SitePage::class)->where('id', $id)->where('status', 1)->find()?->toArray() ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function activeSitePageRows(): array
    {
        return DbRead::model(SitePage::class)->where('status', 1)->select()->toArray();
    }

    /** @param array{written?:int,skipped?:int,deleted?:int,errors?:list<string>,output_dir?:string} $stats */
    private function buildGenerateAdminMessage(array $stats): string
    {
        $written = (int) ($stats['written'] ?? 0);
        $skipped = (int) ($stats['skipped'] ?? 0);
        $deleted = (int) ($stats['deleted'] ?? 0);
        $errors  = $stats['errors'] ?? [];

        $head = $errors !== [] && $written < 1
            ? '生成未完成'
            : ($errors !== [] ? '生成完成（部分页面失败）' : '生成完成');

        $parts = ["{$head}：写入 {$written}"];
        if ($skipped > 0) {
            $parts[] = "跳过 {$skipped}";
        }
        if ($deleted > 0) {
            $parts[] = "删除旧文件 {$deleted}";
        }

        $msg = implode('，', $parts);
        $err = $this->summarizeGenerateErrors($errors);
        if ($err !== '') {
            $msg .= '。' . $err;
        }
        $msg .= '。输出目录：' . (string) ($stats['output_dir'] ?? $this->staticOutputRelativeDir());

        return $msg;
    }

    private function staticOutputRelativeDir(): string
    {
        $sub = trim($this->config->seoStaticConfigService->subdir());
        if ($sub !== '') {
            return 'public/' . trim($sub, '/') . '/';
        }

        return 'public/';
    }

    /**
     * @param list<string> $errors
     */
    private function summarizeGenerateErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }
        $lines = [];
        foreach ($errors as $raw) {
            $line = $this->humanizeGenerateError((string) $raw);
            if ($line !== '' && !in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
        if ($lines === []) {
            return '';
        }
        if (count($lines) === 1) {
            return $lines[0];
        }

        return $lines[0] . ' 等 ' . count($lines) . ' 项';
    }

    private function formatWriteErrorLabel(string $url, ?string $relativePath): string
    {
        $rel = trim(str_replace('\\', '/', (string) $relativePath), '/');
        if ($rel === 'index.html' || str_ends_with($rel, '/index.html')) {
            return '首页 index.html';
        }

        return trim($url) !== '' ? trim($url) : ($rel !== '' ? $rel : '页面');
    }

    private function humanizeGenerateError(string $raw, string $path = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, 'DocsSectionPagesService') || str_contains($raw, 'list_page_docs_section')) {
            return 'www 主题帮助单页（Community 版不含）已跳过';
        }
        if (
            str_contains($raw, 'Permission denied')
            || str_contains($raw, '拒绝访问')
            || preg_match('/\bcode:\s*5\b/i', $raw)
        ) {
            return '部分页面写入失败，请检查 public/ 目录是否可写';
        }
        if (str_contains($raw, 'Failed to open stream') || str_contains($raw, 'No such file or directory')) {
            return ($path !== '' ? "页面「{$path}」" : '部分页面') . '生成失败';
        }
        if (str_contains($raw, ':')) {
            [$prefix, $tail] = explode(':', $raw, 2);
            if (str_starts_with($prefix, 'http') || str_starts_with($prefix, '/')) {
                return $this->humanizeGenerateError($tail, $path);
            }
        }
        $plain = (string) preg_replace('#(?:[A-Za-z]:)?[\\\\/][\\w\\\\/.:-]+#u', '', $raw);
        $plain = trim((string) preg_replace('/\s+/', ' ', $plain));
        if ($plain === '') {
            return ($path !== '' ? "页面「{$path}」" : '部分页面') . '生成失败';
        }
        if (mb_strlen($plain) > 72) {
            $plain = mb_substr($plain, 0, 69) . '...';
        }

        return $plain;
    }
}
