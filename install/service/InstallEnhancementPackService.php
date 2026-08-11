<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\package\PluginBundledPackageLocator;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;

/**
 * 装站一次性：增强包勾选 UI；装完可随 install/ 删除。
 * zip 路径/发现 → {@see PluginBundledPackageLocator}（常驻，插件域）。
 */
final class InstallEnhancementPackService
{
    public function __construct(
        private readonly PluginService $pluginService,
        private readonly PluginSkuCatalogService $pluginSkuCatalog,
        private readonly PluginBundledPackageLocator $bundledPackages,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogForWizard(): array
    {
        $rows = config('pivark.plugin_install_enhancement_pack');
        if (!is_array($rows) || $rows === []) {
            $rows = PluginManifestPolicyDiscovery::enhancementPackCatalogRows();
        }

        $trialDays = max(1, (int) config('plugin.commercial.default_trial_days', 90));
        $out       = [];
        $seen      = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = strtolower(trim((string) ($row['id'] ?? '')));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $entry = $this->buildWizardCatalogEntry($id, $row, $trialDays);
            if ($entry === null) {
                continue;
            }
            $seen[$id] = true;
            $out[]     = $entry;
        }

        // Community 发行包：增强包多在 install/assets/packages 与 market/plugins zip，
        // weapp/ 仅明文预装 doc_comment；须把 zip 内插件补进勾选列表。
        foreach ($this->bundledPackages->discoverPackagedEnhancementIdentifiers() as $id) {
            if (isset($seen[$id])) {
                continue;
            }
            $entry = $this->buildWizardCatalogEntry($id, ['id' => $id, 'default' => true], $trialDays);
            if ($entry === null) {
                continue;
            }
            $seen[$id] = true;
            $out[]     = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function buildWizardCatalogEntry(string $id, array $row, int $trialDays): ?array
    {
        $manifest = $this->pluginService->readManifest($id);
        if ($manifest === null) {
            $manifest = $this->bundledPackages->readManifestFromBundledZip($id);
        }
        if ($manifest === null && $this->bundledPackages->resolveBundledPackagePath($id) === null) {
            return null;
        }
        $manifest = is_array($manifest) ? $manifest : [];
        $commercial = $this->pluginSkuCatalog->resolveCommercial($id, $manifest !== [] ? $manifest : null);
        $priceLabel = trim((string) ($row['price_label'] ?? ''));
        if ($priceLabel === '') {
            $priceLabel = trim((string) ($commercial['price_label'] ?? ''));
        }
        if ($priceLabel === '') {
            $priceLabel = $trialDays . '天免费试用';
        }
        $tableCount = (int) ($row['table_count'] ?? $this->countInstallTables($id));
        if ($tableCount <= 0 && is_array($manifest) && $manifest !== []) {
            $tableCount = $this->countInstallTablesFromZip($id);
        }
        $default = array_key_exists('default', $row)
            ? !empty($row['default'])
            : $this->defaultFlagFromManifest($manifest);

        return [
            'id'          => $id,
            'name'        => trim((string) ($row['name'] ?? $manifest['name'] ?? $id)),
            'hint'        => trim((string) ($row['hint'] ?? $this->hintFromManifest($manifest))),
            'default'     => $default,
            'price_label' => $priceLabel,
            'table_count' => $tableCount,
            'table_label' => $tableCount > 0
                ? $tableCount . ' 张业务表'
                : '无独立业务表',
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function defaultFlagFromManifest(array $manifest): bool
    {
        $policy = is_array($manifest['pivark_policy'] ?? null) ? $manifest['pivark_policy'] : [];
        if (!empty($policy['install_default'])) {
            return true;
        }
        $pack = is_array($policy['enhancement_pack'] ?? null) ? $policy['enhancement_pack'] : [];
        if (array_key_exists('default', $pack)) {
            return !empty($pack['default']);
        }

        return true;
    }

    /** @param array<string, mixed> $manifest */
    private function hintFromManifest(array $manifest): string
    {
        $policy = is_array($manifest['pivark_policy'] ?? null) ? $manifest['pivark_policy'] : [];
        $pack   = is_array($policy['enhancement_pack'] ?? null) ? $policy['enhancement_pack'] : [];
        $hint   = trim((string) ($pack['hint'] ?? ''));
        if ($hint !== '') {
            return $hint;
        }

        return trim((string) ($manifest['description'] ?? ''));
    }

    private function countInstallTablesFromZip(string $identifier): int
    {
        $path = $this->bundledPackages->resolveBundledPackagePath($identifier);
        if ($path === null || !class_exists(\ZipArchive::class)) {
            return 0;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return 0;
        }
        try {
            $body = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (preg_match('#(^|/)database/install\.sql$#', $name) !== 1) {
                    continue;
                }
                $body = $zip->getFromIndex($i);
                break;
            }
            if (!is_string($body) || $body === '') {
                return 0;
            }
            if (!preg_match_all('/CREATE\s+TABLE/i', $body, $m)) {
                return 0;
            }

            return count($m[0]);
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    public function defaultIdentifiers(): array
    {
        return PluginManifestPolicyDiscovery::mergeIdentifierLists(
            config('pivark.plugin_install_defaults'),
            PluginManifestPolicyDiscovery::installDefaultIdentifiers(),
        );
    }

    /** @deprecated 常驻能力在 PluginBundledPackageLocator；向导兼容保留 */
    public function resolveBundledPackagePath(string $identifier): ?string
    {
        return $this->bundledPackages->resolveBundledPackagePath($identifier);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function normalizeIdentifiersFromPayload(array $payload): array
    {
        // 表单始终带 plugins_csv（可空）：空串 = 用户明确不装增强包，禁止回落到 defaultIdentifiers
        if (array_key_exists('plugins_csv', $payload)) {
            $csv = trim((string) $payload['plugins_csv']);

            return $csv === '' ? [] : $this->sanitizeIdentifierList(explode(',', $csv));
        }
        $plugins = $payload['plugins'] ?? null;
        if (is_array($plugins)) {
            return $plugins === [] ? [] : $this->sanitizeIdentifierList($plugins);
        }

        return $this->defaultIdentifiers();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shouldImportDemo(array $payload): bool
    {
        $raw = $payload['import_demo'] ?? false;

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            || (string) $raw === '1'
            || (int) $raw === 1;
    }

    public function installIdentifier(string $identifier): ServiceResult
    {
        return $this->pluginService->installForWizard($identifier);
    }

    /**
     * @param list<string>|list<mixed> $ids
     * @return list<string>
     */
    private function sanitizeIdentifierList(array $ids): array
    {
        $allowed = [];
        foreach ($this->catalogForWizard() as $row) {
            $allowed[(string) $row['id']] = true;
        }

        $out = [];
        $aliases = [
            'download' => 'doc_bundle',
            'bundle'   => 'doc_bundle',
            'gallery'  => 'doc_gallery',
            'video'    => 'doc_vod',
            'comment'  => 'doc_comment',
            'ask'      => 'doc_ask',
            'thumb'    => 'doc_thumb',
        ];
        foreach ($ids as $id) {
            $id = strtolower(trim((string) $id));
            if (isset($aliases[$id])) {
                $id = $aliases[$id];
            }
            if ($id === '' || !isset($allowed[$id])) {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    private function countInstallTables(string $identifier): int
    {
        $sqlFile = ProjectPaths::root() . 'weapp/' . $identifier . '/database/install.sql';
        if (!is_readable($sqlFile)) {
            return 0;
        }
        $body = (string) file_get_contents($sqlFile);
        if (!preg_match_all('/CREATE\s+TABLE/i', $body, $m)) {
            return 0;
        }

        return count($m[0]);
    }
}
