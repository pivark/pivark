<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin;

use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\registry\PluginApiVersionRegistry;
use app\common\contract\DocumentAddonSearchContributorInterface;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\service\plugin\package\PluginCoreVersionRequirementService;
use app\common\service\plugin\package\PluginPeerVersionRequirementService;
use app\common\service\plugin\manifest\PluginRevenueShareValidationService;

class PluginManifestService
{
    public function __construct(
        private readonly PluginDistributionService $distribution,
        private readonly PluginApiVersionRegistry $pluginApiVersionRegistry,
        private readonly PluginCoreVersionRequirementService $pluginCoreVersionRequirement,
        private readonly PluginPeerVersionRequirementService $pluginPeerVersionRequirement,
        private readonly PluginRevenueShareValidationService $pluginRevenueShareValidation,
    ) {
    }

    public const TYPE_OFFICIAL   = 'official';
    public const TYPE_PERSONAL   = 'personal';
    public const TYPE_ENTERPRISE = 'enterprise';
    /** 本站 weapp 目录内自建，不上市场；便于与 Core 升级解耦 */
    public const TYPE_LOCAL      = 'local';

    public const KIND_DOCUMENT_ADDON = 'document-addon';
    public const KIND_PLATFORM       = 'platform';
    public const KIND_APPLICATION    = 'application';

    /** @var list<string> */
    public const DOCUMENT_EDITOR_SLOTS = ['tab', 'inline', 'sidebar', 'modal', 'hidden'];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        self::TYPE_OFFICIAL   => '官方插件',
        self::TYPE_PERSONAL   => '个人插件',
        self::TYPE_ENTERPRISE => '第三方企业',
        self::TYPE_LOCAL      => '本站自用',
    ];

    /** @var list<string> */
    private const VALID_KINDS = [
        self::KIND_DOCUMENT_ADDON,
        self::KIND_PLATFORM,
        self::KIND_APPLICATION,
    ];

    /**
     * @param array<string, mixed> $manifest
     * @return array{ok:bool,errors:list<string>,publisher_type:string,label:string}
     */
    public function validate(array $manifest): array
    {
        $errors = [];
        $type   = $this->resolvePublisherType($manifest);

        if ($type === '') {
            $errors[] = '缺少 publisher_type（official / personal / enterprise / local）';
        } elseif (!isset(self::TYPE_LABELS[$type])) {
            $errors[] = 'publisher_type 无效，仅允许 official、personal、enterprise、local';
            $type = '';
        }

        $vendor = $this->packageVendor($manifest);
        $officialVendors = $this->officialVendors();

        if ($type === self::TYPE_OFFICIAL) {
            if ($vendor === '') {
                $errors[] = '官方插件须声明 package（vendor/slug）';
            } elseif (!in_array($vendor, $officialVendors, true)) {
                $errors[] = '仅授权厂商可使用 publisher_type=official（当前 vendor：' . $vendor . '）';
            }
        } elseif ($type !== '') {
            if ($vendor !== '' && in_array($vendor, $officialVendors, true)) {
                $errors[] = '个人/企业插件不得使用官方包名空间 vendor（' . $vendor . '）';
            }
            foreach ($this->forbiddenOfficialTexts($manifest) as $hit) {
                $errors[] = '非官方插件不得使用「' . $hit . '」等官方标识（请检查 name/author/description）';
            }
        }

        $label = $type !== '' ? self::TYPE_LABELS[$type] : '';

        $surfaceResult = $this->validateSurfaces($manifest);
        $errors        = array_merge($errors, $surfaceResult['errors']);
        $errors        = array_merge($errors, $this->validateCommercialSkus($manifest));
        $errors        = array_merge($errors, $this->validateCoreApiVersion($manifest));
        $errors        = array_merge($errors, $this->pluginApiVersionRegistry->validateManifest($manifest));
        $errors        = array_merge($errors, $this->pluginCoreVersionRequirement->syntaxErrors($manifest));
        $errors        = array_merge($errors, $this->pluginPeerVersionRequirement->syntaxErrors($manifest));
        $errors        = array_merge($errors, $this->pluginRevenueShareValidation->syntaxErrors($manifest));

        return [
            'ok'             => $errors === [],
            'errors'         => $errors,
            'publisher_type' => $type,
            'label'          => $label,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateCoreApiVersion(array $manifest): array
    {
        $core = trim((string) config('pivark.plugin_core_api_version'));
        if ($core === '') {
            return [];
        }

        $manifestApi = trim((string) ($manifest['api_version'] ?? '1.0'));
        if ($manifestApi === '') {
            $manifestApi = '1.0';
        }
        if ($manifestApi !== $core) {
            return [
                'api_version 须与核心一致（核心 ' . $core . '，manifest ' . $manifestApi . '）',
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function validateCommercialSkus(array $manifest): array
    {
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        $skus       = $commercial['skus'] ?? null;
        $distribution = is_array($manifest['distribution'] ?? null) ? $manifest['distribution'] : [];
        $distMode   = strtolower(trim((string) ($distribution['mode'] ?? '')));
        if ($distMode === 'source_open') {
            return [];
        }
        if (!is_array($skus) || $skus === []) {
            return ['commercial.skus 不能为空（v1 commercial.model/price 已废弃，须在 plugin.json 声明 skus[]）'];
        }

        $errors   = [];
        $validTypes = [
            PluginSkuCatalogService::BILLING_FREE,
            PluginSkuCatalogService::BILLING_LIMITED_FREE,
            PluginSkuCatalogService::BILLING_TRIAL_TIME,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
            PluginSkuCatalogService::BILLING_PREPAID_PACK,
            PluginSkuCatalogService::BILLING_SUBSCRIPTION_TIME,
            PluginSkuCatalogService::BILLING_SUBSCRIPTION_QUOTA,
            PluginSkuCatalogService::BILLING_LIFETIME,
        ];
        $seenIds = [];
        foreach ($skus as $idx => $sku) {
            if (!is_array($sku)) {
                $errors[] = 'commercial.skus[' . $idx . '] 须为对象';
                continue;
            }
            $skuId = trim((string) ($sku['sku_id'] ?? ''));
            if ($skuId === '') {
                $errors[] = 'commercial.skus[' . $idx . '] 缺少 sku_id';
                continue;
            }
            if (isset($seenIds[$skuId])) {
                $errors[] = 'commercial.skus sku_id 重复：' . $skuId;
            }
            $seenIds[$skuId] = true;
            $billingType = strtolower(trim((string) ($sku['billing_type'] ?? '')));
            if ($billingType === '' || !in_array($billingType, $validTypes, true)) {
                $errors[] = 'commercial.skus[' . $skuId . '] billing_type 无效';
            }
            if (!is_numeric($sku['price'] ?? null)) {
                $errors[] = 'commercial.skus[' . $skuId . '] price 须为数字';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{ok:bool,errors:list<string>}
     */
    public function validateSurfaces(array $manifest): array
    {
        $errors = [];
        $kind   = $this->resolveKind($manifest);
        if ($kind !== '' && !in_array($kind, self::VALID_KINDS, true)) {
            $errors[] = 'kind 无效，仅允许 document-addon、platform、application';
        }

        if (array_key_exists('document_search', $manifest)) {
            $errors = array_merge($errors, $this->validateDocumentSearch($manifest, $kind));
        }

        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : null;
        if ($surfaces === null) {
            return ['ok' => $errors === [], 'errors' => $errors];
        }

        $editor = is_array($surfaces['document_editor'] ?? null) ? $surfaces['document_editor'] : null;
        if ($editor !== null) {
            $enabled = !empty($editor['enabled']);
            $slot    = strtolower(trim((string) ($editor['slot'] ?? 'tab')));
            if ($slot === '') {
                $slot = 'tab';
            }
            if (!in_array($slot, self::DOCUMENT_EDITOR_SLOTS, true)) {
                $errors[] = 'surfaces.document_editor.slot 无效，仅允许 '
                    . implode('、', self::DOCUMENT_EDITOR_SLOTS);
            }
            if ($enabled && $kind === self::KIND_PLATFORM) {
                $errors[] = 'platform 插件不得启用 document_editor（请设 enabled: false）';
            }
            if ($enabled && $kind === self::KIND_APPLICATION && in_array($slot, ['tab', 'inline', 'sidebar'], true)) {
                $errors[] = 'application 插件不得占用 document_editor 的 tab/inline/sidebar';
            }
        }

        $frontend = is_array($surfaces['frontend'] ?? null) ? $surfaces['frontend'] : null;
        if ($frontend !== null) {
            $tags = $frontend['template_tags'] ?? null;
            if ($tags !== null && !is_array($tags)) {
                $errors[] = 'surfaces.frontend.template_tags 须为数组';
            }
            $hooks = $frontend['render_hooks'] ?? null;
            if ($hooks !== null && !is_array($hooks)) {
                $errors[] = 'surfaces.frontend.render_hooks 须为数组';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string> 空数组表示通过
     */
    public function validateDocumentSearch(array $manifest, string $kind = ''): array
    {
        if (!array_key_exists('document_search', $manifest)) {
            return [];
        }

        $kind = $kind !== '' ? $kind : $this->resolveKind($manifest);
        if ($kind === '') {
            $kind = self::KIND_DOCUMENT_ADDON;
        }

        $block = $manifest['document_search'];
        if (!is_array($block)) {
            return ['document_search 须为对象'];
        }

        if ($kind !== self::KIND_DOCUMENT_ADDON) {
            return ['仅 kind=document-addon 可声明 document_search'];
        }

        $bridge = is_array($block['bridge'] ?? null) ? $block['bridge'] : null;
        if ($bridge !== null) {
            return $this->validateDocumentSearchBridge($bridge);
        }

        $contributor = trim((string) ($block['contributor'] ?? ''));
        if ($contributor === '') {
            return ['document_search 须声明 contributor 或 bridge'];
        }

        $identifier = strtolower(trim((string) ($manifest['identifier'] ?? '')));
        if ($identifier !== '') {
            PluginService::registerAutoloadPublic($identifier);
        }

        if (!class_exists($contributor)) {
            return ['document_search.contributor 类不存在：' . $contributor];
        }

        $instance = new $contributor();
        if (!$instance instanceof DocumentAddonSearchContributorInterface) {
            return ['document_search.contributor 须实现 DocumentAddonSearchContributorInterface'];
        }

        return [];
    }

    /**
     * manifest document_search.bridge：Core 桥接 listForDocument 等（官方/自带桥接类）。
     *
     * @param array<string, mixed> $bridge
     * @return list<string>
     */
    private function validateDocumentSearchBridge(array $bridge): array
    {
        $errors = [];
        $fetch = trim((string) ($bridge['fetch'] ?? 'listForDocument'));
        if ($fetch === '') {
            $fetch = 'listForDocument';
        }
        $allowedFetch = ['listForDocument', 'doc_vod_merge'];
        if (!in_array($fetch, $allowedFetch, true)) {
            $errors[] = 'document_search.bridge.fetch 无效，仅允许 '
                . implode('、', $allowedFetch);
        }
        if (array_key_exists('attachments', $bridge) && !is_bool($bridge['attachments'])) {
            $errors[] = 'document_search.bridge.attachments 须为布尔值';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function normalizeSurfaces(array $manifest): array
    {
        $kind = $this->resolveKind($manifest);
        if ($kind === '') {
            $kind = self::KIND_DOCUMENT_ADDON;
            $manifest['kind'] = $kind;
        }

        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
        if ($kind === self::KIND_PLATFORM && !isset($surfaces['document_editor'])) {
            $surfaces['document_editor'] = ['enabled' => false];
        }
        if ($surfaces !== []) {
            $manifest['surfaces'] = $surfaces;
        }

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function resolveKind(array $manifest): string
    {
        $kind = strtolower(trim((string) ($manifest['kind'] ?? '')));

        return preg_replace('/[^a-z_-]/', '', $kind) ?: '';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function applyValidation(array $manifest): array
    {
        $manifest = $this->distribution->normalize($this->normalizeSurfaces($manifest));
        $result   = $this->validate($manifest);
        $distErrs = $this->distribution->validateManifest($manifest);
        $errors   = array_merge($result['errors'], $distErrs);
        $manifest['_manifest_valid']   = $errors === [];
        $manifest['_manifest_errors']  = $errors;
        $manifest['publisher_type']      = $result['publisher_type'];
        $manifest['publisher_label']    = $result['label'];

        return $manifest;
    }

    public function labelForType(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? '';
    }

    public function labelForKind(string $kind): string
    {
        $labels = $this->kindOptions();

        return $labels[$kind] ?? $kind;
    }

    /**
     * 插件三级分类（与官方货架品项 official_catalog.plugin_kinds / 市场「分类」筛同词）
     *
     * @return array<string, string> kind => label
     */
    public function kindOptions(): array
    {
        return [
            self::KIND_DOCUMENT_ADDON => '文档扩展',
            self::KIND_PLATFORM       => '平台能力',
            self::KIND_APPLICATION    => '独立应用',
        ];
    }

    /** @return list<string> */
    public function officialVendors(): array
    {
        $cfg = \think\facade\Config::get('pivark.plugin_official_vendors');
        if (!is_array($cfg) || $cfg === []) {
            return ['pivark'];
        }

        return array_values(array_filter(array_map('strval', $cfg)));
    }

    /** @param array<string, mixed> $manifest */
    public function resolvePublisherType(array $manifest): string
    {
        $publisher = is_array($manifest['publisher'] ?? null) ? $manifest['publisher'] : [];
        $type = strtolower(trim((string) ($manifest['publisher_type'] ?? ($publisher['type'] ?? ''))));

        return preg_replace('/[^a-z_]/', '', $type) ?: '';
    }

    /** @param array<string, mixed> $manifest */
    public function packageVendor(array $manifest): string
    {
        $package = trim((string) ($manifest['package'] ?? ''));
        if ($package !== '' && str_contains($package, '/')) {
            return strtolower(trim(explode('/', $package, 2)[0]));
        }

        $vendor = strtolower(trim((string) ($manifest['vendor'] ?? '')));

        return preg_replace('/[^a-z0-9_-]/', '', $vendor) ?: '';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function forbiddenOfficialTexts(array $manifest): array
    {
        $patterns = [
            '官方'           => '/官方/u',
            'PivArk官方'     => '/pivark\s*官方/ui',
            '元舟官方'       => '/元舟[\s\-]*pivark?\s*官方/ui',
            'Official Plugin'=> '/official\s+plugin/ui',
        ];
        $fields = [
            (string) ($manifest['name'] ?? ''),
            (string) ($manifest['author'] ?? ''),
            (string) ($manifest['description'] ?? ''),
        ];
        $blob = implode("\n", $fields);
        $hits = [];
        foreach ($patterns as $label => $regex) {
            if (preg_match($regex, $blob)) {
                $hits[] = $label;
            }
        }

        return $hits;
    }
}
