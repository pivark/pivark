<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\model\PaymentOrder;
use app\common\service\payment\PaymentOrderService;
use app\common\support\AppTime;
use app\common\support\QueryLimit;

final class PluginCommerceReportService
{
    private const RENEWAL_WINDOW_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 90): array
    {
        $days  = max(7, min(365, $days));
        $sinceTs = strtotime('-' . $days . ' days');
        $since = AppTime::format('Y-m-d H:i:s', $sinceTs !== false ? $sinceTs : null);
        $scene = PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT;

        $paidRows = PaymentOrder::where('scene', $scene)
            ->where('status', PaymentOrderService::STATUS_PAID)
            ->where('paid_at', '>=', $since)
            ->select()
            ->toArray();

        $revenue      = 0.0;
        $skuBreakdown = [];
        foreach ($paidRows as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            $revenue += $amount;
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $skuKey  = trim((string) ($payload['sku_id'] ?? ''));
            if ($skuKey === '') {
                $skuKey = 'default';
            }
            $identifier = app(EntitlementService::class)->resolveSlugIdentifier(
                trim((string) ($payload['plugin_identifier'] ?? ''))
            );
            $bucket = $identifier . '::' . $skuKey;
            if (!isset($skuBreakdown[$bucket])) {
                $manifest = $identifier !== '' ? app(PluginService::class)->readManifest($identifier) : null;
                $skuBreakdown[$bucket] = [
                    'identifier'   => $identifier,
                    'sku_id'       => $skuKey,
                    'name'         => (string) ($manifest['name'] ?? $identifier),
                    'order_count'  => 0,
                    'revenue'      => 0.0,
                ];
            }
            $skuBreakdown[$bucket]['order_count']++;
            $skuBreakdown[$bucket]['revenue'] = round(
                $skuBreakdown[$bucket]['revenue'] + $amount,
                2
            );
        }

        $expiredSoon = 0;
        $expired     = 0;
        $activePaid  = 0;
        $now         = time();
        $query = app(EntitlementQueryService::class);
        foreach ($query->listRowsOrdered() as $row) {
            $rawId = (string) ($row['plugin_identifier'] ?? '');
            if ($rawId === '' || str_contains($rawId, '/')) {
                continue;
            }
            $identifier = app(EntitlementService::class)->resolveSlugIdentifier($rawId);
            if ($identifier === '') {
                continue;
            }
            $status  = (string) ($row['status'] ?? '');
            $expire  = trim((string) ($row['expire_at'] ?? ''));
            $license = (string) ($row['license_type'] ?? '');
            if ($status === 'active' && in_array($license, ['paid', 'subscription'], true)
                && ($expire === '' || strtotime($expire) > $now)) {
                $activePaid++;
            }
            if ($expire === '') {
                continue;
            }
            $ts = strtotime($expire);
            if ($ts === false) {
                continue;
            }
            if ($ts < $now) {
                $expired++;
            } elseif ($ts <= strtotime('+7 days')) {
                $expiredSoon++;
            }
        }

        $renewal = $this->renewalStats($since);

        $skuList = array_values($skuBreakdown);
        usort($skuList, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'period_days'          => $days,
            'order_count'          => count($paidRows),
            'revenue'              => round($revenue, 2),
            'active_paid'          => $activePaid,
            'expiring_7d'          => $expiredSoon,
            'expired_total'        => $expired,
            'renewal_rate_pct'     => $renewal['rate_pct'],
            'renewal_repurchased'  => $renewal['repurchased'],
            'renewal_eligible'     => $renewal['eligible'],
            'sku_breakdown'        => $skuList,
            'recent_orders'        => $this->recentOrders(10),
            'expired_plugins'      => $this->expiredPluginList(20),
        ];
    }

    /**
     * 统计周期内到期且 30 天内再次付费的占比。
     *
     * @return array{eligible:int,repurchased:int,rate_pct:float}
     */
    private function renewalStats(string $since): array
    {
        $eligible    = 0;
        $repurchased = 0;
        $now         = time();
        $windowEnd   = self::RENEWAL_WINDOW_DAYS * 86400;

        $paidByIdentifier = [];
        foreach (PaymentOrder::where('scene', PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT)
            ->where('status', PaymentOrderService::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->select()
            ->toArray() as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $id      = app(EntitlementService::class)->resolveSlugIdentifier(
                trim((string) ($payload['plugin_identifier'] ?? ''))
            );
            if ($id === '') {
                continue;
            }
            $paidAt = strtotime((string) ($row['paid_at'] ?? ''));
            if ($paidAt === false) {
                continue;
            }
            $paidByIdentifier[$id][] = $paidAt;
        }

        $query = app(EntitlementQueryService::class);
        foreach ($query->listRowsOrdered() as $row) {
            $rawId = (string) ($row['plugin_identifier'] ?? '');
            if ($rawId === '' || str_contains($rawId, '/')) {
                continue;
            }
            $identifier = app(EntitlementService::class)->resolveSlugIdentifier($rawId);
            $expire     = trim((string) ($row['expire_at'] ?? ''));
            if ($identifier === '' || $expire === '') {
                continue;
            }
            $expireTs = strtotime($expire);
            if ($expireTs === false || $expireTs >= $now || $expire < $since) {
                continue;
            }
            $eligible++;
            $paidTimes = $paidByIdentifier[$identifier] ?? [];
            foreach ($paidTimes as $paidTs) {
                if ($paidTs > $expireTs && $paidTs <= $expireTs + $windowEnd) {
                    $repurchased++;
                    break;
                }
            }
        }

        $rate = $eligible > 0 ? round($repurchased / $eligible * 100, 1) : 0.0;

        return [
            'eligible'    => $eligible,
            'repurchased' => $repurchased,
            'rate_pct'    => $rate,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(int $limit): array
    {
        $out = [];
        foreach (PaymentOrder::where('scene', PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray() as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $id      = app(EntitlementService::class)->resolveSlugIdentifier(
                trim((string) ($payload['plugin_identifier'] ?? ''))
            );
            $out[]   = [
                'order_no'   => (string) ($row['order_no'] ?? ''),
                'status'     => (string) ($row['status'] ?? ''),
                'amount'     => (float) ($row['amount'] ?? 0),
                'paid_at'    => (string) ($row['paid_at'] ?? ''),
                'identifier' => $id,
                'sku_id'     => (string) ($payload['sku_id'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expiredPluginList(int $limit): array
    {
        $out = [];
        $now = time();
        foreach (app(EntitlementQueryService::class)->listRowsOrderedByExpireDesc(max($limit * 3, QueryLimit::PLUGIN_ENTITLEMENT_ADMIN)) as $row) {
            $expire = trim((string) ($row['expire_at'] ?? ''));
            if ($expire === '' || strtotime($expire) >= $now) {
                continue;
            }
            $id = app(EntitlementService::class)->resolveSlugIdentifier((string) ($row['plugin_identifier'] ?? ''));
            if ($id === '' || str_contains((string) ($row['plugin_identifier'] ?? ''), '/')) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($id);
            $out[]    = [
                'identifier' => $id,
                'name'       => (string) ($manifest['name'] ?? $id),
                'expire_at'  => $expire,
                'status'     => (string) ($row['status'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
