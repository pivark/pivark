<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\service\config\ConfigService;
use app\common\service\release\PivarkEditionService;
use app\common\support\SiteUrl;

final class SiteCoreLicenseService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly PivarkEditionService $pivarkEditionService,
    ) {
    }

    public const FEATURE_REMOVE_BRAND  = 'remove_brand';
    public const FEATURE_CORE_UPDATE   = 'core_update';

    private const CONFIG_TIER     = 'site_core_license_tier';
    private const CONFIG_FEATURES = 'site_core_license_features_json';
    private const CONFIG_CODE     = 'site_core_license_code';
    private const CONFIG_EXPIRE   = 'site_core_license_expire_at';
    /** 曾开通专业版+（到期/降档后不清，用于侧栏空态入口） */
    private const CONFIG_EVER_PRO = 'site_core_license_ever_pro';

    /** @var list<string> */
    private const KNOWN_FEATURES = [
        self::FEATURE_REMOVE_BRAND,
        self::FEATURE_CORE_UPDATE,
    ];

    public function tier(): string
    {
        return strtolower(trim((string) $this->configService->get(self::CONFIG_TIER, '')));
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        $raw = (string) $this->configService->get(self::CONFIG_FEATURES, '');
        if ($raw === '') {
            return [];
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [];
        }

        return $this->normalizeFeatureList($parsed);
    }

    public function hasFeature(string $feature): bool
    {
        $feature = strtolower(trim($feature));

        return in_array($feature, $this->features(), true);
    }

    /**
     * 升级包下载 / 在线一键升级：开源版不可；基础版起即可（与 /pricing「后台一键升级」一致）。
     * 专业版差异在产品中心等，见 {@see isProPlusTier()}，不在此门禁。
     */
    public function allowsUpdateDownload(): bool
    {
        if (!$this->pivarkEditionService->isCommunity()) {
            return true;
        }

        if ($this->hasFeature(self::FEATURE_CORE_UPDATE)) {
            return true;
        }

        // 兼容旧 Grant：features_json 为空但已落 basic/pro 档位
        return in_array($this->tier(), ['basic', 'pro', 'professional', 'enterprise'], true);
    }

    /** 专业版及以上本机站点许可（品项管理等；不依赖授权平台实时在线） */
    public function isProPlusTier(): bool
    {
        return in_array($this->tier(), ['pro', 'professional', 'enterprise'], true);
    }

    /** 本站是否曾开通专业版+（激活时落库，到期/降档保留） */
    public function everProPlus(): bool
    {
        $flag = strtolower(trim((string) $this->configService->get(self::CONFIG_EVER_PRO, '')));
        if (in_array($flag, ['1', 'true', 'yes'], true)) {
            return true;
        }

        // 兼容：未写旗标但当前仍是 pro+ / 或有到期痕迹且曾是付费档
        if ($this->isProPlusTier()) {
            return true;
        }

        return false;
    }

    /**
     * 侧栏：未授权专业版+时，仅「曾开通过」才保留入口进空态续费提示。
     * 从未买过专业版+ → 不显示产品中心。
     */
    public function showProductCenterDegradedEntry(): bool
    {
        return $this->everProPlus() && !$this->isProPlusTier();
    }

    /** 专业版能力被拒时的人话（空态 / API 共用） */
    public function proRequiredMessage(): string
    {
        if ($this->everProPlus() || trim((string) $this->configService->get(self::CONFIG_EXPIRE, '')) !== '') {
            return '专业版授权已到期或已取消，请续费开通专业版及以上后再使用此功能。可在「系统设置 → 授权激活」处理。';
        }

        return '此功能需专业版及以上授权，请开通后再使用。可在「系统设置 → 授权激活」处理。';
    }

    /** 后台一键在线升级：与下载授权同门（基础版起） */
    public function allowsOnlineCoreApply(): bool
    {
        return $this->allowsUpdateDownload();
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function applyFromActivatePayload(array $entry, string $licenseCode): void
    {
        $tier = strtolower(trim((string) ($entry['core_tier'] ?? '')));
        $features = $this->normalizeFeatureList($entry['core_features'] ?? []);
        $expire = isset($entry['core_expire_at']) && $entry['core_expire_at'] !== ''
            ? (string) $entry['core_expire_at']
            : (isset($entry['expire_at']) && $entry['expire_at'] !== '' ? (string) $entry['expire_at'] : '');

        if ($tier === '' && $features === []) {
            return;
        }

        if ($tier !== '') {
            $this->configService->set(self::CONFIG_TIER, $tier);
            if (in_array($tier, ['pro', 'professional', 'enterprise'], true)) {
                $this->configService->set(self::CONFIG_EVER_PRO, '1');
            }
        }
        $this->configService->set(self::CONFIG_FEATURES, json_encode($features, JSON_UNESCAPED_UNICODE));
        $this->configService->set(self::CONFIG_CODE, strtoupper(trim($licenseCode)));
        $this->configService->set(self::CONFIG_EXPIRE, $expire);
    }

    /** @return self::TIER_*|string none|basic|pro */
    public function marketDomainTier(): string
    {
        if (!$this->pivarkEditionService->isCommunity()) {
            return self::TIER_PRO;
        }

        if (!$this->allowsUpdateDownload()) {
            return 'none';
        }

        $tier = $this->tier();
        if (in_array($tier, ['pro', 'professional', 'enterprise'], true)) {
            return self::TIER_PRO;
        }
        if ($tier === 'basic') {
            return self::TIER_BASIC;
        }

        return 'none';
    }

    private const TIER_PRO = 'pro';
    private const TIER_BASIC = 'basic';

    /**
     * 插件市场 preflight：域名授权与升级便利（V3）
     *
     * @return array<string, mixed>
     */
    public function marketPreflight(): array
    {
        $tier = $this->marketDomainTier();
        $repo = trim((string) config('pivark.opensource_repo_url', ''));
        if ($repo === '') {
            $repo = 'https://gitee.com/pivark/pivark/releases';
        }
        $siteUrl  = SiteUrl::configuredPublicHome();
        $siteHost = parse_url($siteUrl, PHP_URL_HOST);
        if (!\is_string($siteHost) || $siteHost === '') {
            $siteHost = trim((string) $this->configService->get('site_url', ''));
            if ($siteHost !== '' && preg_match('#^https?://#i', $siteHost)) {
                $parsed = parse_url($siteHost, PHP_URL_HOST);
                $siteHost = \is_string($parsed) ? $parsed : $siteHost;
            }
        }
        $coreExpire = trim((string) $this->configService->get(self::CONFIG_EXPIRE, ''));

        return [
            'domain_tier'              => $tier,
            'domain_tier_label'        => $this->domainTierShortLabel($tier),
            'allows_online_core_upgrade' => $this->allowsOnlineCoreApply(),
            'opensource_repo_url'        => $repo,
            'core_expire_at'             => $coreExpire,
            'site_url'                   => $siteUrl,
            'site_domain'                => $siteHost,
            'core_license_expire_label'  => $coreExpire !== ''
                ? ('核心授权至 ' . substr($coreExpire, 0, 10))
                : ($tier === self::TIER_PRO ? '核心授权永久有效' : '未绑定付费域名授权码'),
        ];
    }

    /**
     * 域名授权档位对外短名（不含价目；插件市场 / 系统升级页共用）
     */
    public function domainTierShortLabel(string $marketTier = ''): string
    {
        $marketTier = strtolower(trim($marketTier !== '' ? $marketTier : $this->marketDomainTier()));

        return match ($marketTier) {
            self::TIER_PRO   => '专业版',
            self::TIER_BASIC => '基础版',
            default          => '开源版 · 可商用',
        };
    }

    /**
     * Community 站点：授权版本展示名（开源版 / 基础版 / 专业版 / 企业版）
     */
    public function domainEditionDisplayName(): string
    {
        if (!$this->pivarkEditionService->isCommunity()) {
            return $this->pivarkEditionService->editionDisplayName();
        }

        $rawTier = strtolower(trim($this->tier()));
        if ($rawTier === 'enterprise') {
            return '企业版';
        }
        if (in_array($rawTier, ['pro', 'professional'], true)) {
            return '专业版';
        }
        if ($rawTier === 'basic') {
            return '基础版';
        }

        return match ($this->marketDomainTier()) {
            self::TIER_PRO   => '专业版',
            self::TIER_BASIC => '基础版',
            default          => '开源版',
        };
    }

    /**
     * 系统升级页：当前站点授权摘要（发行版 + 域名授权档位）
     *
     * @return array{
     *   edition:string,
     *   edition_display_name:string,
     *   license_tier:string,
     *   license_tier_label:string,
     *   license_expire_label:string,
     *   allows_online_core_upgrade:bool,
     *   site_domain:string
     * }
     */
    public function upgradeLicenseSummary(): array
    {
        $editionSvc  = app(PivarkEditionService::class);
        $edition     = $editionSvc->edition();
        $preflight   = $this->marketPreflight();
        $marketTier  = (string) ($preflight['domain_tier'] ?? 'none');
        $displayName = $this->domainEditionDisplayName();

        if (!$editionSvc->isCommunity()) {
            $tierLabel   = $displayName;
            $expireLabel = match ($edition) {
                PivarkEditionService::PLATFORM => '平台宿主内置授权',
                PivarkEditionService::DEV       => '开发环境（无商业授权约束）',
                default                         => '—',
            };
        } else {
            $tierLabel   = $marketTier === 'none'
                ? '开源版 · 可商用'
                : $displayName;
            $expireLabel = trim((string) ($preflight['core_license_expire_label'] ?? ''));
        }

        return [
            'edition'                    => $edition,
            'edition_display_name'       => $displayName,
            'license_tier'               => $marketTier,
            'license_tier_label'         => $tierLabel,
            'license_expire_label'       => $expireLabel,
            'allows_online_core_upgrade' => !empty($preflight['allows_online_core_upgrade']),
            'site_domain'                => trim((string) ($preflight['site_domain'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'tier'                  => $this->tier(),
            'features'              => $this->features(),
            'license_code'          => (string) $this->configService->get(self::CONFIG_CODE, ''),
            'expire_at'             => (string) $this->configService->get(self::CONFIG_EXPIRE, ''),
            'remove_brand'          => $this->hasFeature(self::FEATURE_REMOVE_BRAND),
            'core_update_allowed'   => $this->allowsUpdateDownload(),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public function normalizeFeatureList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            $f = strtolower(trim((string) $item));
            if ($f === '' || !in_array($f, self::KNOWN_FEATURES, true)) {
                continue;
            }
            $out[$f] = $f;
        }

        return array_values($out);
    }
}
