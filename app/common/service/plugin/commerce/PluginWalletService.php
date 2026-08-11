<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\support\ServiceResult;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\support\AppTime;

use app\common\model\SitePluginWallet;
use app\common\model\SitePluginWalletLedger;
use think\facade\Db;

final class PluginWalletService
{
    public function __construct(
        private readonly PluginMeteringService $pluginMeteringService,
    ) {
    }

    public const MODE_QUOTA     = 'quota';
    public const MODE_UNLIMITED = 'unlimited';

    /**
     * @return array<string, mixed>|null
     */
    public function getWallet(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        $row = SitePluginWallet::where('plugin_identifier', $identifier)
            ->where('status', 'active')
            ->find();

        return $this->walletRow($row);
    }

    public function isUnlimited(string $identifier): bool
    {
        $wallet = $this->getWallet($identifier);

        return is_array($wallet) && (string) ($wallet['wallet_mode'] ?? '') === self::MODE_UNLIMITED;
    }

    public function remaining(string $identifier): ?int
    {
        if ($this->isUnlimited($identifier)) {
            return null;
        }
        $wallet = $this->getWallet($identifier);
        if (!is_array($wallet)) {
            return 0;
        }

        return max(0, (int) ($wallet['quota_remaining'] ?? 0));
    }

    /**
     * @return ServiceResult
     */
    public function credit(
        string $identifier,
        int $amount,
        string $reason,
        string $ref = '',
        ?string $skuId = null
    ): ServiceResult {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $amount <= 0) {
            return ServiceResult::fail('充值参数无效');
        }

        $remaining = null;
        Db::transaction(function () use ($identifier, $amount, $reason, $ref, $skuId, &$remaining): void {
            $row = $this->walletRow(SitePluginWallet::where('plugin_identifier', $identifier)->lock(true)->find());
            $now = AppTime::now();
            if ($row === null) {
                SitePluginWallet::insert([
                    'plugin_identifier' => $identifier,
                    'wallet_mode'       => self::MODE_QUOTA,
                    'quota_remaining'   => $amount,
                    'quota_total'       => $amount,
                    'active_sku_id'     => $skuId,
                    'status'            => 'active',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $remaining = $amount;
            } elseif ((string) ($row['wallet_mode'] ?? '') === self::MODE_UNLIMITED) {
                $remaining = null;
            } else {
                $newBalance = max(0, (int) ($row['quota_remaining'] ?? 0)) + $amount;
                SitePluginWallet::where('id', (int) $row['id'])->update([
                    'quota_remaining' => $newBalance,
                    'quota_total'     => max((int) ($row['quota_total'] ?? 0), $newBalance),
                    'active_sku_id'   => $skuId ?: ($row['active_sku_id'] ?? null),
                    'updated_at'      => $now,
                ]);
                $remaining = $newBalance;
            }

            SitePluginWalletLedger::insert([
                'plugin_identifier' => $identifier,
                'delta'               => $amount,
                'balance_after'       => $remaining,
                'reason'              => mb_substr($reason, 0, 40),
                'ref'                 => $ref !== '' ? mb_substr($ref, 0, 120) : null,
                'detail'              => $skuId !== null && $skuId !== '' ? ('sku=' . $skuId) : null,
                'created_at'          => $now,
            ]);
        });

        return ServiceResult::ok(['remaining' => $remaining], 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function debit(string $identifier, int $amount, string $reason, string $ref = ''): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $amount <= 0) {
            return ServiceResult::fail('扣减参数无效');
        }
        if ($this->isUnlimited($identifier)) {
            $this->writeLedger($identifier, -$amount, null, $reason, $ref, 'unlimited');

            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        $remaining  = 0;
        $ok         = false;
        $failReason = '';
        Db::transaction(function () use ($identifier, $amount, $reason, $ref, &$remaining, &$ok, &$failReason): void {
            $row = $this->walletRow(SitePluginWallet::where('plugin_identifier', $identifier)->lock(true)->find());
            if ($row === null) {
                $failReason = 'wallet_missing';

                return;
            }
            if ((string) ($row['wallet_mode'] ?? '') === self::MODE_UNLIMITED) {
                return;
            }
            $balance = max(0, (int) ($row['quota_remaining'] ?? 0));
            if ($balance < $amount) {
                $failReason = 'insufficient';

                return;
            }
            $newBalance = $balance - $amount;
            $now        = AppTime::now();
            SitePluginWallet::where('id', (int) $row['id'])->update([
                'quota_remaining' => $newBalance,
                'updated_at'      => $now,
            ]);
            $remaining = $newBalance;
            $ok        = true;
            SitePluginWalletLedger::insert([
                'plugin_identifier' => $identifier,
                'delta'               => -$amount,
                'balance_after'       => $newBalance,
                'reason'              => mb_substr($reason, 0, 40),
                'ref'                 => $ref !== '' ? mb_substr($ref, 0, 120) : null,
                'created_at'          => $now,
            ]);
        });

        if (!$ok) {
            if ($failReason === 'wallet_missing') {
                return ServiceResult::fail('钱包未初始化，请先购买套餐或开通额度');
            }

            return ServiceResult::fail('次数不足，请购买套餐或联系续费');
        }

        return ServiceResult::ok(['remaining' => $remaining], 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function setUnlimited(string $identifier, string $reason, string $ref = '', ?string $skuId = null): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ServiceResult::fail('参数无效');
        }
        $now = AppTime::now();
        Db::transaction(function () use ($identifier, $reason, $ref, $skuId, $now): void {
            $row = $this->walletRow(SitePluginWallet::where('plugin_identifier', $identifier)->lock(true)->find());
            if ($row !== null) {
                SitePluginWallet::where('id', (int) $row['id'])->update([
                    'wallet_mode'     => self::MODE_UNLIMITED,
                    'quota_remaining' => null,
                    'quota_total'     => null,
                    'period_start'    => null,
                    'period_end'      => null,
                    'active_sku_id'   => $skuId,
                    'updated_at'      => $now,
                ]);
            } else {
                SitePluginWallet::insert([
                    'plugin_identifier' => $identifier,
                    'wallet_mode'       => self::MODE_UNLIMITED,
                    'quota_remaining'   => null,
                    'quota_total'       => null,
                    'active_sku_id'     => $skuId,
                    'status'            => 'active',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }
            $this->writeLedger($identifier, 0, null, $reason, $ref, 'unlimited sku=' . (string) $skuId);
        });

        return ServiceResult::ok(null, 'ok');
    }

    /**
     * 订单 / 试用履约：按 SKU billing_type 充值或设为不限次
     *
     * @param array<string, mixed> $sku
     * @return ServiceResult
     */
    public function applySkuPurchase(string $identifier, array $sku, string $ref = ''): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $type       = strtolower(trim((string) ($sku['billing_type'] ?? '')));
        $skuId      = (string) ($sku['sku_id'] ?? '');
        $quota      = isset($sku['quota_total']) ? (int) $sku['quota_total'] : 0;

        $quotaTotal = $sku['quota_total'] ?? null;
        if ($type === PluginSkuCatalogService::BILLING_LIFETIME && ($quota <= 0 || $quotaTotal === null)) {
            return $this->setUnlimited($identifier, 'order', $ref, $skuId);
        }
        if (in_array($type, [
            PluginSkuCatalogService::BILLING_PREPAID_PACK,
            PluginSkuCatalogService::BILLING_TRIAL_QUOTA,
        ], true) && $quota > 0) {
            return $this->credit(
                $identifier,
                $quota,
                $type === PluginSkuCatalogService::BILLING_TRIAL_QUOTA ? 'grant_trial' : 'order',
                $ref,
                $skuId
            );
        }
        if ($type === PluginSkuCatalogService::BILLING_SUBSCRIPTION_QUOTA && $quota > 0) {
            return $this->startSubscriptionQuota($identifier, $sku, $ref);
        }

        return ServiceResult::ok(null, '无需钱包变更');
    }

    /**
     * 安装 / 限免领取：按指定 SKU 行发放试用（非 catalog active 推断）
     *
     * @param array<string, mixed> $sku
     * @return ServiceResult
     */
    public function applySkuGrantFromRow(string $identifier, array $sku): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !$this->isMeteredPlugin($identifier)) {
            return ServiceResult::ok(null, 'skip');
        }

        $type  = strtolower(trim((string) ($sku['billing_type'] ?? '')));
        $skuId = (string) ($sku['sku_id'] ?? '');

        if ($type === PluginSkuCatalogService::BILLING_TRIAL_QUOTA) {
            if ($this->hasExclusiveTrialGranted($identifier)) {
                return ServiceResult::ok(null, 'trial already granted');
            }
            $quota = max(1, (int) ($sku['quota_total'] ?? 1));

            return $this->credit($identifier, $quota, 'grant_trial', 'install', $skuId);
        }
        if ($type === PluginSkuCatalogService::BILLING_TRIAL_TIME) {
            if ($this->hasExclusiveTrialGranted($identifier)) {
                return ServiceResult::ok(null, 'trial already granted');
            }

            return $this->setUnlimited($identifier, 'grant_trial_time', 'install', $skuId);
        }

        return ServiceResult::ok(null, 'skip');
    }

    /**
     * 安装 / 限免领取：按 catalog 当前 active_sku 发放试用
     *
     * @return ServiceResult
     */
    public function applyActiveSkuGrant(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !$this->isMeteredPlugin($identifier)) {
            return ServiceResult::ok(null, 'skip');
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        $active   = app(PluginSkuCatalogService::class)->resolveActiveSku($identifier, $manifest);
        if (!is_array($active)) {
            return ServiceResult::ok(null, 'no sku');
        }

        return $this->applySkuGrantFromRow($identifier, $active);
    }

    /**
     * 授权失效后收回试用不限次等权益（cron / 过期检查调用）
     */
    public function syncOnEntitlementLost(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || app(EntitlementService::class)->can($identifier)) {
            return;
        }
        if (!$this->isMeteredPlugin($identifier)) {
            return;
        }

        Db::transaction(function () use ($identifier): void {
            $row = $this->walletRow(SitePluginWallet::where('plugin_identifier', $identifier)->lock(true)->find());
            if ($row === null) {
                return;
            }
            if ((string) ($row['wallet_mode'] ?? '') !== self::MODE_UNLIMITED) {
                return;
            }

            $now = AppTime::now();
            $affected = (int) SitePluginWallet::where('id', (int) $row['id'])
                ->where('wallet_mode', self::MODE_UNLIMITED)
                ->update([
                    'wallet_mode'     => self::MODE_QUOTA,
                    'quota_remaining' => 0,
                    'quota_total'     => 0,
                    'period_start'    => null,
                    'period_end'      => null,
                    'updated_at'      => $now,
                ]);
            if ($affected > 0) {
                $this->writeLedger($identifier, 0, 0, 'entitlement_expired', '', 'unlimited revoked');
            }
        });
    }

    public function isMeteredPlugin(string $identifier): bool
    {
        return $this->pluginMeteringService->billableActions($identifier) !== [];
    }

    /**
     * 订阅周期重置（cron 调用）
     */
    public function refreshSubscriptionPeriods(): int
    {
        $now  = AppTime::now();
        $rows = SitePluginWallet::where('wallet_mode', self::MODE_QUOTA)
            ->whereNotNull('period_end')
            ->where('period_end', '<', $now)
            ->select()
            ->toArray();
        $count = 0;
        foreach ($rows as $row) {
            $identifier = (string) ($row['plugin_identifier'] ?? '');
            $skuId      = (string) ($row['active_sku_id'] ?? '');
            $sku        = $skuId !== '' ? app(PluginSkuFulfillmentService::class)->findSku($identifier, $skuId) : null;
            if (!is_array($sku)) {
                continue;
            }
            $this->startSubscriptionQuota($identifier, $sku, 'period_reset');
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $sku
     * @return ServiceResult
     */
    private function startSubscriptionQuota(string $identifier, array $sku, string $ref): ServiceResult
    {
        $quota  = max(1, (int) ($sku['quota_total'] ?? 1));
        $period = strtolower(trim((string) ($sku['period'] ?? 'month')));
        $days   = $period === 'year' ? 365 : 30;
        $start  = AppTime::now();
        $end    = AppTime::format('Y-m-d H:i:s', time() + $days * 86400);
        $skuId  = (string) ($sku['sku_id'] ?? '');
        $now    = $start;

        Db::transaction(function () use ($identifier, $quota, $start, $end, $skuId, $ref, $period, $now): void {
            $row = $this->walletRow(SitePluginWallet::where('plugin_identifier', $identifier)->lock(true)->find());
            if ($row !== null) {
                SitePluginWallet::where('id', (int) $row['id'])->update([
                    'wallet_mode'     => self::MODE_QUOTA,
                    'quota_remaining' => $quota,
                    'quota_total'     => $quota,
                    'period_start'    => $start,
                    'period_end'      => $end,
                    'active_sku_id'   => $skuId,
                    'updated_at'      => $now,
                ]);
            } else {
                SitePluginWallet::insert([
                    'plugin_identifier' => $identifier,
                    'wallet_mode'       => self::MODE_QUOTA,
                    'quota_remaining'   => $quota,
                    'quota_total'       => $quota,
                    'period_start'      => $start,
                    'period_end'        => $end,
                    'active_sku_id'     => $skuId,
                    'status'            => 'active',
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }
            $this->writeLedger($identifier, $quota, $quota, 'subscription_quota', $ref, $period . ' reset');
        });

        return ServiceResult::ok(null, 'ok');
    }

    /**
     * 站点是否已领取互斥试用（trial_quota / trial_time 仅一次）
     */
    public function hasExclusiveTrialGranted(string $identifier): bool
    {
        return $this->grantedExclusiveTrialSkuId($identifier) !== null;
    }

    /**
     * 已领取的互斥试用 SKU，未领取返回 null
     */
    public function grantedExclusiveTrialSkuId(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $rows = SitePluginWalletLedger::where('plugin_identifier', $identifier)
            ->whereIn('reason', ['grant_trial', 'grant_trial_time'])
            ->order('id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $skuId = $this->parseSkuIdFromLedgerDetail((string) ($row['detail'] ?? ''));
            if ($skuId !== '') {
                return $skuId;
            }
        }

        if ($rows !== []) {
            $walletSku = trim((string) (SitePluginWallet::where('plugin_identifier', $identifier)->value('active_sku_id') ?? ''));

            return $walletSku !== '' ? $walletSku : 'trial';
        }

        return null;
    }

    /**
     * 后台手动充值（计量插件）
     *
     * @return ServiceResult
     */
    public function adminCredit(string $identifier, int $amount, string $note = ''): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !$this->isMeteredPlugin($identifier)) {
            return ServiceResult::fail('非计量插件或标识无效');
        }
        if ($amount <= 0) {
            return ServiceResult::fail('充值次数须大于 0');
        }
        if ($this->isUnlimited($identifier)) {
            return ServiceResult::fail('当前为不限次模式，无需充值');
        }

        return $this->credit(
            $identifier,
            $amount,
            'admin',
            $note !== '' ? mb_substr($note, 0, 120) : 'manual',
            null
        );
    }

    /**
     * 回滚指定订单号关联的钱包充值（计量包 / 不限次套餐）。
     */
    public function rollbackCreditsByRef(string $identifier, string $ref): int
    {
        $identifier = strtolower(trim($identifier));
        $ref        = trim($ref);
        if ($identifier === '' || $ref === '') {
            return 0;
        }

        $rolled = 0;
        foreach (SitePluginWalletLedger::where('plugin_identifier', $identifier)
            ->where('ref', $ref)
            ->where('delta', '>', 0)
            ->select()
            ->toArray() as $row) {
            $delta = (int) ($row['delta'] ?? 0);
            if ($delta <= 0) {
                continue;
            }
            $rb = $this->debit($identifier, $delta, 'order_rollback', $ref . ':rollback');
            if ($rb->isOk()) {
                $rolled += $delta;
            }
        }

        $unlimited = SitePluginWalletLedger::where('plugin_identifier', $identifier)
            ->where('ref', $ref)
            ->whereLike('detail', 'unlimited%')
            ->count();
        if ($unlimited > 0 && $this->isUnlimited($identifier)) {
            $now = AppTime::now();
            SitePluginWallet::where('plugin_identifier', $identifier)->update([
                'wallet_mode'     => self::MODE_QUOTA,
                'quota_remaining' => 0,
                'quota_total'     => 0,
                'period_start'    => null,
                'period_end'      => null,
                'updated_at'      => $now,
            ]);
            $this->writeLedger($identifier, 0, 0, 'order_rollback', $ref . ':rollback', 'unlimited revoked');
            $rolled++;
        }

        return $rolled;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLedger(string $identifier, int $limit = 30): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $limit = min(max($limit, 1), 100);

        return array_values(SitePluginWalletLedger::where('plugin_identifier', $identifier)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray());
    }

    private function parseSkuIdFromLedgerDetail(string $detail): string
    {
        if ($detail === '') {
            return '';
        }
        if (preg_match('/sku=([a-z0-9_.-]+)/i', $detail, $m)) {
            return strtolower(trim($m[1]));
        }

        return '';
    }

    private function writeLedger(
        string $identifier,
        int $delta,
        ?int $balanceAfter,
        string $reason,
        string $ref,
        ?string $detail = null
    ): void {
        SitePluginWalletLedger::insert([
            'plugin_identifier' => $identifier,
            'delta'               => $delta,
            'balance_after'       => $balanceAfter,
            'reason'              => mb_substr($reason, 0, 40),
            'ref'                 => $ref !== '' ? mb_substr($ref, 0, 120) : null,
            'detail'              => $detail !== null && $detail !== '' ? mb_substr($detail, 0, 255) : null,
            'created_at'          => AppTime::now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function walletRow(mixed $result): ?array
    {
        return $result instanceof SitePluginWallet ? $result->toArray() : null;
    }
}
