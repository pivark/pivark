<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\entitlement;

use app\common\model\Plugin;
use app\common\model\SitePluginEntitlement;
use app\common\service\admin\AdminPortalService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\service\plugin\WeappContext;
use app\common\service\plugin\commerce\PluginSkuCatalogService;
use app\common\support\AppTime;
use app\common\support\QueryLimit;

/** 插件授权只读查询（can / summary / 后台列表） */
final class EntitlementQueryService
{
    public function __construct(
        private readonly MetaSqlCacheService $metaCache,
    ) {
    }

    /** @var array<string, true>|null */
    private static ?array $activeEntitlementMap = null;

    private static int $activeEntitlementMapGeneration = 0;

    /** @var array<string, bool> */
    private static array $canSingleCache = [];

    private static int $canSingleCacheGeneration = 0;

    /** @var array<string, string> */
    public const LICENSE_LABELS = [
        'free'     => '永久免费',
        'trial'    => '限时免费',
        'paid'     => '付费',
        'bundled'  => '捆绑',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'active'   => '已授权',
        'expired'  => '已过期',
        'disabled' => '已撤销',
    ];

    public function flushEntitlementCache(): void
    {
        self::$activeEntitlementMap            = null;
        self::$activeEntitlementMapGeneration = 0;
        self::$canSingleCache                 = [];
        self::$canSingleCacheGeneration       = 0;
        $this->metaCache->clearFrontMeta();
        app(FrontCacheInvalidator::class)->bumpGeneration();
    }

    public function can(string $identifier): bool
    {
        $slug = $this->resolveSlugIdentifier($identifier);
        if ($slug === '') {
            return false;
        }
        if (isset($this->activeEntitlementMap()[$slug])) {
            return true;
        }

        return $this->canSingle($slug);
    }

    public function resolveSlugIdentifier(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return '';
        }
        if (str_contains($identifier, '/')) {
            $slug = substr($identifier, strrpos($identifier, '/') + 1);

            return $slug !== '' ? $slug : $identifier;
        }

        return $identifier;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function commercialModel(array $manifest): string
    {
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];

        return strtolower(trim((string) ($commercial['model'] ?? 'free'))) ?: 'free';
    }

    /**
     * @return array{
     *   status:string,
     *   status_label:string,
     *   license_type:string,
     *   license_label:string,
     *   expire_at:?string,
     *   granted_by:string,
     *   entitled:bool
     * }
     */
    public function summary(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return $this->emptySummary();
        }

        $row = $this->entitlementRow(
            SitePluginEntitlement::where('plugin_identifier', $identifier)->find()
        );
        if ($row === null) {
            return $this->emptySummary();
        }

        $status = (string) ($row['status'] ?? '');
        $expire = !empty($row['expire_at']) ? (string) $row['expire_at'] : null;
        if ($status === 'active' && $this->expireAtIsPast($expire)) {
            $status = 'expired';
            app(EntitlementService::class)->refreshExpiredStatuses();
        }

        $license = (string) ($row['license_type'] ?? '');

        return [
            'status'        => $status,
            'status_label'  => self::STATUS_LABELS[$status] ?? $status,
            'license_type'  => $license,
            'license_label' => self::LICENSE_LABELS[$license] ?? $license,
            'expire_at'     => $expire,
            'granted_by'    => (string) ($row['granted_by'] ?? ''),
            'entitled'      => $this->can($identifier),
        ];
    }

    /**
     * 原始 entitlement 行（Gateway / 种子；不含 summary 标签）
     *
     * @return array<string, mixed>|null
     */
    public function rowByPluginIdentifier(string $identifier): ?array
    {
        $identifier = $this->resolveSlugIdentifier($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->entitlementRow(
            SitePluginEntitlement::where('plugin_identifier', $identifier)->find()
        );
    }

    /** @return array<string, mixed>|null */
    public function readCapabilitySnapshot(string $identifier): ?array
    {
        $row = $this->rowByPluginIdentifier($identifier);
        if ($row === null) {
            return null;
        }
        $raw = $row['capability_snapshot_json'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntitledAdmin(): array
    {
        $rows = SitePluginEntitlement::order('id', 'desc')
            ->limit(QueryLimit::PLUGIN_ENTITLEMENT_ADMIN)
            ->select()
            ->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $identifier = (string) ($row['plugin_identifier'] ?? '');
            if ($identifier === '' || str_contains($identifier, '/')) {
                continue;
            }
            if (!is_dir(app(PluginService::class)->weappRoot() . $identifier)) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($identifier);
            if ($manifest === null || !app(AdminPortalService::class)->pluginAdminVisible($manifest)) {
                continue;
            }
            $pluginRow = $this->pluginRow(Plugin::where('identifier', $identifier)->find());
            $summary   = $this->summary($identifier);
            if ($summary['status'] === 'none') {
                continue;
            }
            $admin = is_array($manifest['admin'] ?? null) ? $manifest['admin'] : [];
            $resolved        = app(PluginSkuCatalogService::class)->resolveCommercial($identifier, $manifest);
            $commercialModel = (string) $resolved['model'];
            $billingLabel    = trim((string) ($resolved['sku_name'] !== '' ? $resolved['sku_name'] : $resolved['price_label']));
            $licenseType     = (string) $summary['license_type'];
            $out[] = [
                'identifier'       => $identifier,
                'name'             => (string) ($manifest['name'] ?? $identifier),
                'version'          => (string) ($manifest['version'] ?? '1.0.0'),
                'installed'        => (int) ($pluginRow['installed'] ?? 0),
                'enabled'          => (int) ($pluginRow['enabled'] ?? 0),
                'entitlement'      => $summary,
                'package'          => (string) ($manifest['package'] ?? ''),
                'billing_label'    => $billingLabel,
                'commercial_model' => $commercialModel,
                'is_paid'          => in_array($licenseType, ['paid', 'trial'], true)
                    || in_array($commercialModel, ['paid', 'subscription'], true),
                'admin_route'      => app(WeappAdminUiService::class)->adminRouteFromManifest($manifest, $identifier),
                'admin_spa_path'   => app(WeappAdminUiService::class)->spaPath($identifier, $manifest),
                'admin_ui_mode'    => app(WeappAdminUiService::class)->mode($manifest, $identifier),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entitlementRow(mixed $result): ?array
    {
        if ($result instanceof SitePluginEntitlement) {
            return $result->toArray();
        }

        return null;
    }

    /**
     * @return array{
     *   status:string,
     *   status_label:string,
     *   license_type:string,
     *   license_label:string,
     *   expire_at:?string,
     *   granted_by:string,
     *   entitled:bool
     * }
     */
    private function emptySummary(): array
    {
        return [
            'status'        => 'none',
            'status_label'  => '未授权',
            'license_type'  => '',
            'license_label' => '',
            'expire_at'     => null,
            'granted_by'    => '',
            'entitled'      => false,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function activeEntitlementMap(): array
    {
        $generation = app(FrontCacheInvalidator::class)->generation();
        if (self::$activeEntitlementMap !== null && self::$activeEntitlementMapGeneration === $generation) {
            return self::$activeEntitlementMap;
        }

        /** @var array<string, true> $map */
        $map = $this->metaCache->remember('entitlements_active_map', function (): array {
            $out = [];
            foreach (SitePluginEntitlement::where('status', 'active')
                ->order('id', 'asc')
                ->limit(QueryLimit::PLUGIN_ENTITLEMENT_ACTIVE)
                ->select()
                ->toArray() as $row) {
                $id = trim((string) ($row['plugin_identifier'] ?? ''));
                if ($id === '') {
                    continue;
                }
                if ($this->expireAtIsPast(!empty($row['expire_at']) ? (string) $row['expire_at'] : null)) {
                    continue;
                }
                $out[$id] = true;
            }

            return $out;
        });
        self::$activeEntitlementMap            = $map;
        self::$activeEntitlementMapGeneration = $generation;

        return self::$activeEntitlementMap;
    }

    private function canSingle(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        $generation = app(FrontCacheInvalidator::class)->generation();
        if (self::$canSingleCacheGeneration !== $generation) {
            self::$canSingleCache            = [];
            self::$canSingleCacheGeneration = $generation;
        }
        if (array_key_exists($identifier, self::$canSingleCache)) {
            return self::$canSingleCache[$identifier];
        }

        $row = $this->entitlementRow(
            SitePluginEntitlement::where('plugin_identifier', $identifier)
                ->where('status', 'active')
                ->find()
        );
        if ($row === null) {
            return self::$canSingleCache[$identifier] = false;
        }
        $expire = !empty($row['expire_at']) ? (string) $row['expire_at'] : null;
        if ($this->expireAtIsPast($expire)) {
            return self::$canSingleCache[$identifier] = false;
        }

        return self::$canSingleCache[$identifier] = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pluginRow(mixed $result): ?array
    {
        if ($result instanceof Plugin) {
            return $result->toArray();
        }

        return null;
    }

    private function expireAtIsPast(?string $expire): bool
    {
        if ($expire === null || trim($expire) === '') {
            return false;
        }
        $ts = strtotime($expire);
        if ($ts === false) {
            return false;
        }

        return $ts < time();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRowsOrdered(int $limit = QueryLimit::PLUGIN_ENTITLEMENT_ADMIN): array
    {
        return SitePluginEntitlement::order('id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveExpiringBetween(string $from, string $to): array
    {
        return SitePluginEntitlement::where('status', 'active')
            ->whereNotNull('expire_at')
            ->whereBetween('expire_at', [$from, $to])
            ->select()
            ->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listExpiredUpdatedSince(string $since): array
    {
        return SitePluginEntitlement::where('status', 'expired')
            ->where('updated_at', '>=', $since)
            ->select()
            ->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveExpiringBefore(string $deadline, string $after = ''): array
    {
        $query = SitePluginEntitlement::where('status', 'active')
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', $deadline);
        if ($after !== '') {
            $query->where('expire_at', '>', $after);
        }

        return $query->select()->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveExpiringInWindow(string $afterExclusive, string $deadlineInclusive): array
    {
        return SitePluginEntitlement::where('status', 'active')
            ->whereNotNull('expire_at')
            ->where('expire_at', '>', $afterExclusive)
            ->where('expire_at', '<=', $deadlineInclusive)
            ->select()
            ->toArray();
    }

    public function listRowsOrderedByExpireDesc(int $limit): array
    {
        return SitePluginEntitlement::order('expire_at', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
    }
}
