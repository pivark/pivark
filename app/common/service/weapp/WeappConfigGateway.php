<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappConfigGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\config\ConfigService;
use app\common\service\plugin\WeappContext;
use app\common\service\plugin\security\PluginConfigAccessGuard;

final class WeappConfigGateway
{

    public function __construct(
        private readonly WeappContext $weappContext,
        private readonly ConfigService $config,
    ) {
    }

    public function weappDb(string $identifier, string $logical): \think\db\BaseQuery
    {
        return $this->weappContext->db($identifier, $logical);
    }

    public function weappTable(string $identifier, string $logical): string
    {
        return $this->weappContext->table($identifier, $logical);
    }

    public function configRefreshAllCacheFromDatabase(): void
    {
        $this->config->refreshAllCacheFromDatabase();
    }

    public function configGet(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }

    public function configGetDirect(string $key, mixed $default = null): mixed
    {
        return $this->config->getDirect($key, $default);
    }

    public function configSet(string $key, mixed $value): void
    {
        PluginConfigAccessGuard::assertCallerMaySet($key);
        $this->config->set($key, (string) $value);
    }

    /**
     * 按精确键名删除配置（退役旧键用；非清空值为空串）。
     *
     * @param list<string> $keys
     * @return int 删除行数
     */
    public function configDeleteKeys(array $keys): int
    {
        foreach ($keys as $key) {
            PluginConfigAccessGuard::assertCallerMaySet((string) $key);
        }

        return $this->config->deleteKeys($keys);
    }

    /** @param array{slug:string,name:string,tpl:string,nav_sort:int,description:string,url_path:string,litpic?:string} $row */
    public function installDemoUpsertTag(array $row): int
    {
        return WeappInstallDemoSupport::upsertTag($row);
    }

    public function installDemoUpsertDocument(
        string $htmlName,
        string $title,
        string $summary,
        string $content,
        string $litpic = '',
        string $attrFlags = 'has_image',
        int $click = 0,
        int $daysAgo = 3,
        string $tplName = '',
    ): int {
        return WeappInstallDemoSupport::upsertDocument(
            $htmlName,
            $title,
            $summary,
            $content,
            $litpic,
            $attrFlags,
            $click,
            $daysAgo,
            $tplName,
        );
    }

    /** @param list<string> $tagSlugs @param array<string, int> $tagIds */
    public function installDemoLinkDocumentTags(int $documentId, array $tagSlugs, array $tagIds): void
    {
        WeappInstallDemoSupport::linkDocumentTags($documentId, $tagSlugs, $tagIds);
    }

    public function installDemoAppendContentCategoryNav(string $urlPath, string $title, int $sort): void
    {
        WeappInstallDemoSupport::appendContentCategoryNav($urlPath, $title, $sort);
    }

    public function installDemoRefreshTagUseCounts(): void
    {
        WeappInstallDemoSupport::refreshTagUseCounts();
    }

    public function installDemoDocumentIdByHtml(string $htmlName): int
    {
        return WeappInstallDemoSupport::documentIdByHtml($htmlName);
    }

    /** @return list<int> */
    public function installDemoDocumentIdsByTagSlug(string $slug): array
    {
        return WeappInstallDemoSupport::documentIdsByTagSlug($slug);
    }

    /** @return list<int> */
    public function installDemoDocumentIdsByHtmlPrefix(string $prefix): array
    {
        return WeappInstallDemoSupport::documentIdsByHtmlPrefix($prefix);
    }

    public function installDemoDocumentField(int $documentId, string $field): string
    {
        return WeappInstallDemoSupport::documentField($documentId, $field);
    }

    public function installDemoPreviewImage(): string
    {
        return WeappInstallDemoSupport::PREVIEW_IMAGE;
    }
}
