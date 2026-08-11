<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentOrderService;
use app\common\support\AppTime;
use app\common\support\ProjectPaths;
use app\common\support\RuntimeJsonFile;
use app\common\support\ServiceResult;

/** 插件授权退款申请队列（本地 JSON · 运营审批后走 refundEntitlementOrder） */
final class PluginRefundRequestService
{
    private const FILE = 'plugin_refund_requests.json';

    public function request(string $orderNo, int $userId, string $reason): ServiceResult
    {
        $orderNo = trim($orderNo);
        $reason  = mb_substr(trim($reason), 0, 500);
        if ($orderNo === '' || $reason === '') {
            return ServiceResult::fail('请填写订单号与退款原因');
        }

        $order = app(PaymentOrderService::class)->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        if ((string) ($order['scene'] ?? '') !== PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT) {
            return ServiceResult::fail('仅插件授权订单可申请退款');
        }
        if ($userId > 0 && (int) ($order['user_id'] ?? 0) !== $userId) {
            return ServiceResult::fail('无权操作此订单');
        }
        if ((string) ($order['status'] ?? '') !== PaymentOrderService::STATUS_PAID) {
            return ServiceResult::fail('仅已支付订单可申请退款');
        }

        $id    = 'rfq_' . substr(hash('sha256', $orderNo . microtime(true)), 0, 12);
        $saved = $this->mutate(function (array $data) use ($id, $orderNo, $order, $reason): array {
            foreach ($data['requests'] as $row) {
                if (is_array($row) && ($row['order_no'] ?? '') === $orderNo && ($row['status'] ?? '') === 'pending') {
                    return $data;
                }
            }
            $data['requests'][] = [
                'id'         => $id,
                'order_no'   => $orderNo,
                'user_id'    => (int) ($order['user_id'] ?? 0),
                'reason'     => $reason,
                'status'     => 'pending',
                'created_at' => AppTime::format('c'),
            ];

            return $data;
        });
        if ($saved === null) {
            return ServiceResult::fail('无法保存退款申请');
        }
        foreach ($saved['requests'] as $row) {
            if (is_array($row) && ($row['order_no'] ?? '') === $orderNo && ($row['status'] ?? '') === 'pending'
                && ($row['id'] ?? '') !== $id) {
                return ServiceResult::ok(null, '退款申请已存在，请等待运营处理');
            }
        }

        app(AuditLogService::class)->operate('插件授权退款申请', 'admin.plugin', [
            'order_no' => $orderNo,
            'user_id'  => (int) ($order['user_id'] ?? 0),
        ]);

        return ServiceResult::ok(['request_id' => $id], '退款申请已提交，运营审核后将原路退回');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, int $limit = 30): array
    {
        if ($userId < 1) {
            return [];
        }

        $out = [];
        foreach ($this->load()['requests'] as $row) {
            if (!is_array($row) || (int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $out[] = [
                'id'            => (string) ($row['id'] ?? ''),
                'order_no'      => (string) ($row['order_no'] ?? ''),
                'reason'        => (string) ($row['reason'] ?? ''),
                'status'        => (string) ($row['status'] ?? ''),
                'created_at'    => (string) ($row['created_at'] ?? ''),
                'processed_at'  => (string) ($row['processed_at'] ?? ''),
                'reject_reason' => (string) ($row['reject_reason'] ?? ''),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($out, 0, max(1, min(100, $limit)));
    }

    public function statusForOrder(string $orderNo, int $userId): ?array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '' || $userId < 1) {
            return null;
        }

        foreach ($this->load()['requests'] as $row) {
            if (!is_array($row) || ($row['order_no'] ?? '') !== $orderNo || (int) ($row['user_id'] ?? 0) !== $userId) {
                continue;
            }

            return [
                'id'            => (string) ($row['id'] ?? ''),
                'order_no'      => $orderNo,
                'status'        => (string) ($row['status'] ?? ''),
                'created_at'    => (string) ($row['created_at'] ?? ''),
                'processed_at'  => (string) ($row['processed_at'] ?? ''),
                'reject_reason' => (string) ($row['reject_reason'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listPending(int $limit = 50): array
    {
        $out = [];
        foreach ($this->load()['requests'] as $row) {
            if (!is_array($row) || ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $out[] = $row;
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($out, 0, max(1, min(200, $limit)));
    }

    public function approve(string $requestId, int $adminId, bool $viaGateway = false): ServiceResult
    {
        $requestId = trim($requestId);
        $snapshot  = null;
        $this->mutate(function (array $data) use ($requestId, $adminId, &$snapshot): array {
            foreach ($data['requests'] as $idx => $row) {
                if (!is_array($row) || ($row['id'] ?? '') !== $requestId) {
                    continue;
                }
                if (($row['status'] ?? '') !== 'pending') {
                    return $data;
                }
                $snapshot                           = $row;
                $data['requests'][$idx]['status']       = 'approving';
                $data['requests'][$idx]['processed_at'] = AppTime::format('c');
                $data['requests'][$idx]['admin_id']     = $adminId;
                break;
            }

            return $data;
        });
        if ($snapshot === null) {
            return ServiceResult::fail('退款申请不存在或非待审状态');
        }

        $orderNo = (string) ($snapshot['order_no'] ?? '');
        $reason  = (string) ($snapshot['reason'] ?? '');
        $refund  = app(PluginCommerceService::class)->refundEntitlementOrder($orderNo, $reason, $adminId, $viaGateway);
        if (!$refund->isOk()) {
            $this->mutate(function (array $data) use ($requestId): array {
                foreach ($data['requests'] as $idx => $row) {
                    if (!is_array($row) || ($row['id'] ?? '') !== $requestId) {
                        continue;
                    }
                    if (($row['status'] ?? '') === 'approving') {
                        $data['requests'][$idx]['status'] = 'pending';
                        unset($data['requests'][$idx]['processed_at'], $data['requests'][$idx]['admin_id']);
                    }
                    break;
                }

                return $data;
            });

            return $refund;
        }

        $this->mutate(function (array $data) use ($requestId, $adminId): array {
            foreach ($data['requests'] as $idx => $row) {
                if (!is_array($row) || ($row['id'] ?? '') !== $requestId) {
                    continue;
                }
                $data['requests'][$idx]['status']       = 'approved';
                $data['requests'][$idx]['processed_at'] = AppTime::format('c');
                $data['requests'][$idx]['admin_id']     = $adminId;
                break;
            }

            return $data;
        });

        return ServiceResult::ok(null, (string) ($refund->message() ?? '退款已完成'));
    }

    public function reject(string $requestId, int $adminId, string $reason = ''): ServiceResult
    {
        $requestId = trim($requestId);
        $reason    = mb_substr(trim($reason), 0, 500);
        $orderNo   = '';
        $ok        = false;
        $this->mutate(function (array $data) use ($requestId, $adminId, $reason, &$orderNo, &$ok): array {
            foreach ($data['requests'] as $idx => $row) {
                if (!is_array($row) || ($row['id'] ?? '') !== $requestId) {
                    continue;
                }
                if (($row['status'] ?? '') !== 'pending') {
                    return $data;
                }
                $orderNo = (string) ($row['order_no'] ?? '');
                $data['requests'][$idx]['status']       = 'rejected';
                $data['requests'][$idx]['processed_at'] = AppTime::format('c');
                $data['requests'][$idx]['admin_id']     = $adminId;
                if ($reason !== '') {
                    $data['requests'][$idx]['reject_reason'] = $reason;
                }
                $ok = true;
                break;
            }

            return $data;
        });
        if (!$ok) {
            return ServiceResult::fail('退款申请不存在或仅待审申请可驳回');
        }

        app(AuditLogService::class)->operate('驳回插件授权退款申请', 'admin.plugin', [
            'request_id' => $requestId,
            'order_no'   => $orderNo,
            'admin_id'   => $adminId,
        ]);

        return ServiceResult::ok(null, '已驳回退款申请');
    }

    /**
     * @return array{requests:list<array<string,mixed>>}
     */
    private function load(): array
    {
        return RuntimeJsonFile::read($this->path(), ['requests' => []]);
    }

    /**
     * @param callable(array{requests:list<array<string,mixed>>}): array{requests:list<array<string,mixed>>} $mutator
     * @return array{requests:list<array<string,mixed>>}|null
     */
    private function mutate(callable $mutator): ?array
    {
        try {
            return RuntimeJsonFile::update($this->path(), static function (array $data) use ($mutator): array {
                if (!isset($data['requests']) || !is_array($data['requests'])) {
                    $data['requests'] = [];
                }
                $next = $mutator($data);

                return is_array($next) ? $next : $data;
            }, ['requests' => []]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function path(): string
    {
        return rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . self::FILE;
    }
}
