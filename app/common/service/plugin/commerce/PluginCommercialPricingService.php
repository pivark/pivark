<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

use app\common\support\AppTime;
use app\common\model\Plugin;
use app\common\support\LocalFile;

final class PluginCommercialPricingService
{
    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
        private readonly PluginSkuCatalogService $pluginSkuCatalogService,
        private readonly PluginCommercialPackageService $commercialPackage,
        private readonly PluginService $pluginService,
    ) {
    }

    /**
     * @return array{
     *   modes:list<array{value:string,label:string,desc:string}>,
     *   defaults:array<string, mixed>
     * }
     */
    public function pricingMeta(): array
    {
        return [
            'modes' => [
                ['value' => 'free', 'label' => '永久免费', 'desc' => '独占档：勾选后不可同时上架其它任何档'],
                ['value' => 'limited_free', 'label' => '限时免费（试用）', 'desc' => '试用 N 天；可与买断/订阅/按次同架；与永久免费互斥；与按次同属试用类至多一档'],
                ['value' => 'paid_once', 'label' => '一次性买断', 'desc' => '单站点终身授权；可与试用/订阅/按次同架；仅与永久免费互斥'],
                ['value' => 'subscription', 'label' => '按期订阅', 'desc' => '按天/年付费续期，可多期；可与试用/买断/按次同架；仅与永久免费互斥'],
                ['value' => 'metered', 'label' => '按次计费', 'desc' => '试用次数 + 按次/按包；可与买断/订阅同架；与永久免费互斥；与限时试用同属试用类至多一档'],
            ],
            /**
             * 互斥 SSOT（成对；末勾选优先）
             * 独占：仅 free ↔ 任意其它档
             * 可同架：paid_once + subscription + metered（任意子集）+ limited_free（试用类另见 trial_modes）
             * 试用类至多一档：trial_modes = limited_free | metered（时长试用 vs 次数试用，勿并排成双试用）
             */
            'mutex_groups' => [
                ['free', 'limited_free'],
                ['free', 'paid_once'],
                ['free', 'subscription'],
                ['free', 'metered'],
            ],
            'trial_modes' => ['limited_free', 'metered'],
            'defaults' => [
                'pricing_mode'        => 'limited_free',
                'enabled_modes'       => ['limited_free', 'paid_once'],
                'price'               => 0,
                'paid_once_price'     => 0,
                'trial_days'          => PluginSkuCatalogService::defaultTrialDays(),
                'period_days'         => 365,
                'subscription_plans'  => [
                    ['period_days' => 365, 'price' => 0.0],
                ],
                'trial_quota'         => 3,
                'quota_per_pack'      => 1,
            ],
        ];
    }

    public function canEditPricing(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }
        if ($this->commercialPackage->isSourceOpen($identifier)
            || $this->pluginService->blocksOfficialPackageUpload($identifier)) {
            return false;
        }
        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null) {
            return false;
        }
        $type = strtolower(trim((string) ($manifest['publisher_type'] ?? '')));

        return in_array($type, [
            PluginManifestService::TYPE_PERSONAL,
            PluginManifestService::TYPE_ENTERPRISE,
            PluginManifestService::TYPE_LOCAL,
        ], true);
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>
     */
    public function formFromManifest(?array $manifest): array
    {
        $defaults = $this->pricingMeta()['defaults'];
        if ($manifest === null) {
            return $defaults;
        }

        $commercial = isset($manifest['commercial']) && is_array($manifest['commercial']) ? $manifest['commercial'] : [];
        $skus       = $this->pluginSkuCatalogService->manifestSkus($manifest);
        if ($skus === []) {
            $model = strtolower(trim((string) ($commercial['model'] ?? 'free')));
            $price = round((float) ($commercial['price'] ?? 0), 2);
            if ($model === 'free' || ($model !== 'paid' && $price <= 0)) {
                return array_merge($defaults, [
                    'pricing_mode'  => 'free',
                    'enabled_modes' => ['free'],
                    'price'         => 0,
                ]);
            }
            if ($model === 'subscription') {
                $period = max(1, (int) ($commercial['period_days'] ?? 365));

                return array_merge($defaults, [
                    'pricing_mode'       => 'subscription',
                    'enabled_modes'      => ['subscription'],
                    'price'              => $price,
                    'period_days'        => $period,
                    'subscription_plans' => [['period_days' => $period, 'price' => $price]],
                ]);
            }

            return array_merge($defaults, [
                'pricing_mode'    => 'paid_once',
                'enabled_modes'   => ['paid_once'],
                'price'           => $price,
                'paid_once_price' => $price,
            ]);
        }

        $enabled = [];
        $price = 0.0;
        $paidOncePrice = 0.0;
        $trialDays = (int) ($defaults['trial_days'] ?? 90);
        $periodDays = (int) ($defaults['period_days'] ?? 365);
        $trialQuota = (int) ($defaults['trial_quota'] ?? 3);
        $quotaPerPack = (int) ($defaults['quota_per_pack'] ?? 1);
        $subscriptionPlans = [];
        foreach ($skus as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $type = strtolower(trim((string) ($sku['billing_type'] ?? '')));
            $p = round((float) ($sku['price'] ?? 0), 2);
            if ($p > $price) {
                $price = $p;
            }
            if ($type === PluginSkuCatalogService::BILLING_FREE && !in_array('free', $enabled, true)) {
                $enabled[] = 'free';
            } elseif ($type === PluginSkuCatalogService::BILLING_LIMITED_FREE && !in_array('limited_free', $enabled, true)) {
                $enabled[] = 'limited_free';
                $trialDays = max(1, (int) ($sku['duration_days'] ?? $trialDays));
            } elseif ($type === PluginSkuCatalogService::BILLING_LIFETIME) {
                if (!in_array('paid_once', $enabled, true)) {
                    $enabled[] = 'paid_once';
                }
                $paidOncePrice = $p;
            } elseif ($type === PluginSkuCatalogService::BILLING_SUBSCRIPTION_TIME) {
                if (!in_array('subscription', $enabled, true)) {
                    $enabled[] = 'subscription';
                }
                $days = max(1, (int) ($sku['duration_days'] ?? $periodDays));
                $periodDays = $days;
                $subscriptionPlans[] = [
                    'period_days' => $days,
                    'price'       => $p,
                ];
            } elseif (in_array($type, [PluginSkuCatalogService::BILLING_TRIAL_QUOTA, PluginSkuCatalogService::BILLING_PREPAID_PACK], true)
                && !in_array('metered', $enabled, true)
            ) {
                $enabled[] = 'metered';
                if ($type === PluginSkuCatalogService::BILLING_TRIAL_QUOTA) {
                    $trialQuota = max(1, (int) ($sku['quota_total'] ?? $trialQuota));
                }
                if ($type === PluginSkuCatalogService::BILLING_PREPAID_PACK) {
                    $quotaPerPack = max(1, (int) ($sku['quota_total'] ?? $quotaPerPack));
                    $price = $p;
                }
            }
        }
        $enabled = $this->applyPricingMutex($enabled, $this->pricingMeta());
        if ($enabled === []) {
            return $defaults;
        }
        if ($subscriptionPlans === []) {
            $subscriptionPlans = [
                ['period_days' => $periodDays, 'price' => in_array('subscription', $enabled, true) ? $price : 0.0],
            ];
        }

        return array_merge($defaults, [
            'pricing_mode'       => $enabled[0],
            'enabled_modes'      => $enabled,
            'price'              => $price,
            'paid_once_price'    => $paidOncePrice > 0 ? $paidOncePrice : (in_array('paid_once', $enabled, true) ? $price : 0.0),
            'trial_days'         => $trialDays,
            'period_days'        => $periodDays,
            'subscription_plans' => $subscriptionPlans,
            'trial_quota'        => $trialQuota,
            'quota_per_pack'     => $quotaPerPack,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalizeFormInput(array $input): array
    {
        $meta     = $this->pricingMeta();
        $defaults = $meta['defaults'];
        $allowed  = array_column($meta['modes'], 'value');
        $mode     = strtolower(trim((string) ($input['pricing_mode'] ?? $defaults['pricing_mode'])));
        if (!in_array($mode, $allowed, true)) {
            $mode = (string) $defaults['pricing_mode'];
        }

        $enabled = [];
        $rawEnabled = $input['enabled_modes'] ?? null;
        if (is_string($rawEnabled) && trim($rawEnabled) !== '') {
            $decoded = json_decode($rawEnabled, true);
            $rawEnabled = is_array($decoded) ? $decoded : preg_split('/[,，]+/u', $rawEnabled);
        }
        if (is_array($rawEnabled)) {
            foreach ($rawEnabled as $m) {
                $m = strtolower(trim((string) $m));
                if ($m !== '' && in_array($m, $allowed, true) && !in_array($m, $enabled, true)) {
                    $enabled[] = $m;
                }
            }
        }
        if ($enabled === []) {
            $enabled = [$mode];
        }
        $enabled = $this->applyPricingMutex($enabled, $meta);
        if ($enabled === []) {
            $enabled = [(string) $defaults['pricing_mode']];
        }
        if (!in_array($mode, $enabled, true)) {
            $mode = $enabled[0];
        }

        $price = max(0, round((float) ($input['price'] ?? 0), 2));
        $paidOncePrice = array_key_exists('paid_once_price', $input)
            ? max(0, round((float) $input['paid_once_price'], 2))
            : $price;

        $plans = $this->normalizeSubscriptionPlans($input, $price);
        $periodDays = (int) ($plans[0]['period_days'] ?? $defaults['period_days']);

        return [
            'pricing_mode'       => $mode,
            'enabled_modes'      => $enabled,
            'price'              => $price,
            'paid_once_price'    => $paidOncePrice,
            'trial_days'         => max(1, (int) ($input['trial_days'] ?? $defaults['trial_days'])),
            'period_days'        => max(1, $periodDays),
            'subscription_plans' => $plans,
            'trial_quota'        => max(1, (int) ($input['trial_quota'] ?? $defaults['trial_quota'])),
            'quota_per_pack'     => max(1, (int) ($input['quota_per_pack'] ?? $defaults['quota_per_pack'])),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{period_days:int,price:float}>
     */
    private function normalizeSubscriptionPlans(array $input, float $fallbackPrice): array
    {
        $raw = $input['subscription_plans'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        $plans = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $days = max(1, (int) ($row['period_days'] ?? 0));
                if ($days < 1) {
                    continue;
                }
                $plans[] = [
                    'period_days' => $days,
                    'price'       => max(0, round((float) ($row['price'] ?? 0), 2)),
                ];
            }
        }
        if ($plans === []) {
            $plans[] = [
                'period_days' => max(1, (int) ($input['period_days'] ?? 365)),
                'price'       => max(0, round((float) ($input['period_price'] ?? $fallbackPrice), 2)),
            ];
        }

        // 同天数去重，保留后写
        $byDays = [];
        foreach ($plans as $plan) {
            $byDays[(int) $plan['period_days']] = $plan;
        }
        ksort($byDays);

        return array_values($byDays);
    }

    /**
     * 档位互斥（公开：脚手架 / host_only 后台 / 商家发布三处 UI 共用同一规则）。
     *
     * @param list<string> $enabled
     * @param array<string, mixed>|null $meta null 则读 pricingMeta()
     * @return list<string>
     */
    public function applyPricingMutex(array $enabled, ?array $meta = null): array
    {
        $meta = is_array($meta) ? $meta : $this->pricingMeta();
        $groups = is_array($meta['mutex_groups'] ?? null) ? $meta['mutex_groups'] : [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $hit = [];
            foreach ($group as $g) {
                $g = strtolower(trim((string) $g));
                if ($g !== '' && in_array($g, $enabled, true)) {
                    $hit[] = $g;
                }
            }
            if (count($hit) <= 1) {
                continue;
            }
            // 保留用户勾选顺序中的最后一档（enabled 末项优先，非 mutex_groups 定义顺序）
            $keep = $hit[0];
            foreach ($enabled as $m) {
                if (in_array($m, $hit, true)) {
                    $keep = $m;
                }
            }
            $enabled = array_values(array_filter(
                $enabled,
                static fn (string $m): bool => $m === $keep || !in_array($m, $hit, true),
            ));
        }
        $trialModes = is_array($meta['trial_modes'] ?? null) ? $meta['trial_modes'] : [];
        $trialHit = array_values(array_filter(
            $enabled,
            static fn (string $m): bool => in_array($m, $trialModes, true),
        ));
        if (count($trialHit) > 1) {
            $keep = $trialHit[0];
            foreach ($enabled as $m) {
                if (in_array($m, $trialHit, true)) {
                    $keep = $m;
                }
            }
            $enabled = array_values(array_filter(
                $enabled,
                static fn (string $m): bool => $m === $keep || !in_array($m, $trialHit, true),
            ));
        }

        return array_values($enabled);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function buildCommercialBlock(array $form): array
    {
        $form = $this->normalizeFormInput($form);
        /** @var list<string> $modes */
        $modes = is_array($form['enabled_modes'] ?? null) ? $form['enabled_modes'] : [$form['pricing_mode']];
        $skus = [];
        $seen = [];
        foreach ($modes as $mode) {
            foreach ($this->skusForMode((string) $mode, $form) as $sku) {
                $id = (string) ($sku['sku_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $skus[] = $sku;
            }
        }
        if ($skus === []) {
            $skus = $this->skusForMode('limited_free', $form);
        }
        $active = (string) ($skus[0]['sku_id'] ?? 'limited_free');
        $primaryMode = (string) ($modes[0] ?? $form['pricing_mode']);
        $model = match ($primaryMode) {
            'free' => 'free',
            'subscription', 'limited_free' => 'subscription',
            'metered' => 'paid',
            default => 'paid',
        };
        $price = 0.0;
        foreach ($skus as $sku) {
            $p = round((float) ($sku['price'] ?? 0), 2);
            if ($p > $price) {
                $price = $p;
            }
        }
        $period = null;
        if (in_array('limited_free', $modes, true)) {
            $period = (int) $form['trial_days'];
        } elseif (in_array('subscription', $modes, true)) {
            $period = (int) $form['period_days'];
        }

        return [
            'model'              => $model,
            'price'              => $price,
            'period_days'        => $period,
            'commercial_profile' => in_array('metered', $modes, true) ? 'metered_ai' : 'policy_flexible',
            'policy'             => [
                'active_sku'          => $active,
                'display_default_sku' => $active,
            ],
            'skus'               => $skus,
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @return list<array<string, mixed>>
     */
    private function skusForMode(string $mode, array $form): array
    {
        return match ($mode) {
            'free' => [[
                'sku_id'       => 'free',
                'name'         => '永久免费',
                'billing_type' => PluginSkuCatalogService::BILLING_FREE,
                'price'        => 0,
            ]],
            // 禁再塞永久免费双档（与 free 互斥；历史 limited_free+free 已退役）
            'limited_free' => [[
                'sku_id'        => 'limited_free',
                'name'          => '限时免费',
                'billing_type'  => PluginSkuCatalogService::BILLING_LIMITED_FREE,
                'price'         => 0,
                'duration_days' => (int) $form['trial_days'],
                'exclusive'     => true,
            ]],
            'paid_once' => [[
                'sku_id'       => 'buyout',
                'name'         => '站点买断',
                'billing_type' => PluginSkuCatalogService::BILLING_LIFETIME,
                'price'        => (float) ($form['paid_once_price'] ?? $form['price'] ?? 0),
                'scope'        => 'site',
            ]],
            'subscription' => $this->subscriptionSkusFromForm($form),
            'metered' => [
                [
                    'sku_id'       => 'trial_quota',
                    'name'         => '试用·' . (int) $form['trial_quota'] . '次',
                    'billing_type' => PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
                    'price'        => 0,
                    'quota_total'  => (int) $form['trial_quota'],
                    'exclusive'    => true,
                ],
                [
                    'sku_id'       => 'pay_per_use',
                    'name'         => ((int) $form['quota_per_pack'] > 1)
                        ? ((int) $form['quota_per_pack'] . '次包')
                        : '按次',
                    'billing_type' => PluginSkuCatalogService::BILLING_PREPAID_PACK,
                    'price'        => (float) $form['price'],
                    'quota_total'  => (int) $form['quota_per_pack'],
                ],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $form
     * @return list<array<string, mixed>>
     */
    private function subscriptionSkusFromForm(array $form): array
    {
        $plans = is_array($form['subscription_plans'] ?? null) ? $form['subscription_plans'] : [];
        if ($plans === []) {
            $plans = [[
                'period_days' => max(1, (int) ($form['period_days'] ?? 365)),
                'price'       => (float) ($form['price'] ?? 0),
            ]];
        }
        $out = [];
        foreach ($plans as $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $days = max(1, (int) ($plan['period_days'] ?? 0));
            $price = max(0, round((float) ($plan['price'] ?? 0), 2));
            $out[] = [
                'sku_id'        => 'subscription_' . $days,
                'name'          => '订阅·' . $days . '天',
                'billing_type'  => PluginSkuCatalogService::BILLING_SUBSCRIPTION_TIME,
                'price'         => $price,
                'duration_days' => $days,
            ];
        }

        return $out !== [] ? $out : [[
            'sku_id'        => 'subscription_365',
            'name'          => '订阅·365天',
            'billing_type'  => PluginSkuCatalogService::BILLING_SUBSCRIPTION_TIME,
            'price'         => (float) ($form['price'] ?? 0),
            'duration_days' => 365,
        ]];
    }

    /**
     * 本机自用插件（personal/enterprise/local）写 plugin.json commercial。
     * 市场货架价真源是官方货架品项——官方/在架货禁止走此口（canEditPricing=false）。
     *
     * @param array<string, mixed> $form
     * @return ServiceResult
     */
    public function applyToWeapp(string $identifier, array $form): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('插件标识无效');
        }
        if (!$this->canEditPricing($identifier)) {
            return ServiceResult::fail('官方内置插件不可通过表单改价，请升级插件包或联系平台');
        }

        $path = $this->pluginService->weappRoot() . $identifier . '/plugin.json';
        if (!is_readable($path)) {
            return ServiceResult::fail('plugin.json 不存在');
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest)) {
            return ServiceResult::fail('plugin.json 无效');
        }

        $built      = $this->buildCommercialBlock($form);
        $existing   = isset($manifest['commercial']) && is_array($manifest['commercial']) ? $manifest['commercial'] : [];
        $manifest['commercial'] = array_merge($existing, $built);
        $manifest   = $this->pluginManifestService->applyValidation($manifest);
        if (empty($manifest['_manifest_valid'])) {
            $errs = is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : ['清单无效'];

            return ServiceResult::fail('定价写入后清单校验失败：' . implode('；', $errs));
        }

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ServiceResult::fail('plugin.json 编码失败');
        }
        if (!LocalFile::putContents($path, $json . "\n")) {
            return ServiceResult::fail('无法写入 plugin.json');
        }

        $this->syncPluginRowCommercial($identifier, $manifest);
        app(PluginCapabilityService::class)->refreshEntitlementSnapshot($identifier);

        return ServiceResult::ok(null, '定价已保存');
    }

    /** @param array<string, mixed> $manifest */
    private function syncPluginRowCommercial(string $identifier, array $manifest): void
    {
        if (Plugin::where('identifier', $identifier)->where('installed', 1)->count() === 0) {
            return;
        }
        $resolved = $this->pluginSkuCatalogService->resolveCommercial($identifier, $manifest);
        Plugin::where('identifier', $identifier)->update([
            'commercial_model' => (string) $resolved['model'],
            'price'            => (float) $resolved['price'],
            'period_days'      => $resolved['period_days'],
            'updated_at'       => AppTime::now(),
        ]);
    }
}
