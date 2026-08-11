<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappPaymentGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\support\ServiceResult;
use app\common\support\DbTable;
use app\common\support\ModelRelationLoad;
use app\common\model\PaymentOrder;
use app\common\service\document\satellite\DocumentPaymentService;
use app\common\service\payment\PaymentChannelRegistry;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentFulfillmentRegistry;
use app\common\service\payment\PaymentOrderService;

final class WeappPaymentGateway
{

    public function __construct(
        private readonly PaymentConfigService $paymentConfig,
        private readonly DocumentPaymentService $documentPayment,
        private readonly PaymentOrderService $paymentOrder,
        private readonly PaymentFulfillmentRegistry $paymentFulfillment,
        private readonly PaymentChannelRegistry $paymentChannel,
    ) {
    }

    public function paymentIsDemoMode(): bool
    {
        return $this->paymentConfig->isDemoMode();
    }

    public function paymentIsProductionEnvironment(): bool
    {
        return $this->paymentConfig->isProductionEnvironment();
    }

    public function paymentDocumentEnabled(): bool
    {
        return $this->documentPayment->enabled();
    }

    /** @return list<string> */
    public function paymentFrontOnlineChannels(): array
    {
        return $this->paymentConfig->frontOnlineChannels();
    }

    public function paymentNormalizeChannel(string $channel): string
    {
        return $this->paymentConfig->normalizeChannel($channel);
    }

    public function paymentIsFrontChannelAllowed(string $channel): bool
    {
        return $this->paymentConfig->isFrontChannelAllowed($channel);
    }

    public function paymentChannelUnavailableMessage(string $channel): string
    {
        return $this->paymentConfig->channelUnavailableMessage($channel);
    }

    /** @return array<string, mixed> */
    public function paymentFrontFlags(): array
    {
        return $this->paymentConfig->frontPaymentFlags();
    }

    /** @param array<string, mixed> $payload */
    public function paymentCreateDocumentOrder(
        int $userId,
        string $scene,
        int $sceneId,
        string $channel,
        array $payload = [],
    ): ServiceResult {
        return $this->documentPayment->createOrder($userId, $scene, $sceneId, $channel, $payload);
    }

    /** @return array<string, mixed>|null */
    public function paymentFindByOrderNo(string $orderNo): ?array
    {
        return $this->paymentOrder->findByOrderNo($orderNo);
    }

    public function paymentPluginDocumentPaidScene(string $identifier): string
    {
        return PaymentOrderService::pluginDocumentPaidScene($identifier);
    }

    /** @param callable(array<string,mixed>): array{code:int,msg:string} $handler */
    public function paymentFulfillmentRegister(string $scene, callable $handler, string $label = '', bool $hostOnly = false): void
    {
        $this->paymentFulfillment->register($scene, $handler, $label, $hostOnly);
    }

    /**
     * 注册扩展支付通道（官方 wechat/alipay/balance 不可覆盖）
     *
     * @param array<string, mixed> $definition 见 PaymentChannelRegistry
     */
    public function paymentChannelRegister(array $definition): void
    {
        $this->paymentChannel->register($definition);
    }

    public function payOrderModelExists(): bool
    {
        return class_exists(PaymentOrder::class) && DbTable::modelExists(PaymentOrder::class);
    }

    public function payOrderTableName(): string
    {
        return (new PaymentOrder())->getTable();
    }

    /** @param list<string> $scenes */
    public function payOrderCountPaidByScenes(array $scenes): int
    {
        if (!$this->payOrderModelExists() || $scenes === []) {
            return 0;
        }
        try {
            return (int) PaymentOrder::whereIn('scene', $scenes)->where('status', 'paid')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<string> $scenes
     * @return array<string, int> date Y-m-d => count
     */
    public function payOrderDailyPaidCountsByScenes(array $scenes, string $sinceDateTime): array
    {
        $map = [];
        if (!$this->payOrderModelExists() || $scenes === []) {
            return $map;
        }
        try {
            $rows = PaymentOrder::whereIn('scene', $scenes)
                ->where('status', 'paid')
                ->where('paid_at', '>=', $sinceDateTime)
                ->field('DATE(paid_at) as d, count(*) as c')
                ->group('d')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $map[(string) ($row['d'] ?? '')] = (int) ($row['c'] ?? 0);
            }
        } catch (\Throwable) {
            return $map;
        }

        return $map;
    }

    /**
     * @param list<string> $scenes
     */
    public function payOrderUnionSqlPart(array $scenes, string $bizType, int $branchLimit): string
    {
        if (!$this->payOrderModelExists() || $scenes === []) {
            return '';
        }
        $table  = $this->payOrderTableName();
        $quoted = array_map(static fn (string $s): string => addslashes($s), $scenes);
        $in     = "'" . implode("','", $quoted) . "'";
        $limit  = max(1, min(5000, $branchLimit));

        return "(SELECT CONCAT('pay-', `id`) AS row_key, '" . addslashes($bizType) . "' AS biz_type, `id` AS ref_id, `user_id`,"
            . " `amount`, 'yuan' AS unit, '在线支付' AS title, CONCAT('scene:', `scene`) AS extra,"
            . " COALESCE(`paid_at`, `created_at`) AS created_at"
            . " FROM `{$table}` WHERE `status` = 'paid' AND `user_id` > 0"
            . " AND `scene` IN ({$in})"
            . " ORDER BY COALESCE(`paid_at`, `created_at`) DESC, `id` DESC LIMIT {$limit})";
    }

    /** @param list<string> $scenes */
    public function payOrderCountForMember(int $userId, string $keyword, array $scenes, callable $applyUserScope, callable $applyKeyword): int
    {
        if (!$this->payOrderModelExists() || $scenes === []) {
            return 0;
        }
        $q = PaymentOrder::where('status', 'paid')->where('user_id', '>', 0)->whereIn('scene', $scenes);
        $applyUserScope($q, $userId, 'user_id');
        $applyKeyword($q, $keyword, 'user_id');

        return (int) $q->count();
    }

    /**
     * @param list<string> $scenes
     * @return list<array<string, mixed>>
     */
    public function payOrderRowsForMember(int $userId, string $keyword, array $scenes, string $bizType, callable $applyUserScope, callable $applyKeyword): array
    {
        if (!$this->payOrderModelExists() || $scenes === []) {
            return [];
        }
        $q = PaymentOrder::with(['user' => static function ($userQuery): void {
            $userQuery->field('id,username,nickname');
        }])
            ->where('status', 'paid')
            ->where('user_id', '>', 0)
            ->whereIn('scene', $scenes)
            ->order('id', 'desc');
        $applyUserScope($q, $userId, 'user_id');
        $applyKeyword($q, $keyword, 'user_id');

        $out = [];
        foreach ($q->field('id,user_id,amount,scene,payload_json,paid_at,created_at')->select() as $item) {
            $row     = ModelRelationLoad::mergeBelongsTo($item, 'user', ['username', 'nickname']);
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $title   = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';
            if ($title === '') {
                $title = '在线支付';
            }
            $created = (string) ($row['paid_at'] ?? '');
            if ($created === '') {
                $created = (string) ($row['created_at'] ?? '');
            }
            $out[] = [
                'row_key'    => 'pay-' . $row['id'],
                'biz_type'   => $bizType,
                'ref_id'     => (int) $row['id'],
                'user_id'    => (int) ($row['user_id'] ?? 0),
                'amount'     => (float) ($row['amount'] ?? 0),
                'unit'       => 'yuan',
                'title'      => $title,
                'extra'      => 'scene:' . (string) ($row['scene'] ?? ''),
                'created_at' => $created,
                'username'   => (string) ($row['username'] ?? ''),
                'nickname'   => (string) ($row['nickname'] ?? ''),
            ];
        }

        return $out;
    }

    public function paymentChannelBalance(): string
    {
        return PaymentConfigService::CHANNEL_BALANCE;
    }

    public function paymentMarkRefunded(
        string $payOrderNo,
        string $reason,
        int $operatorId = 0,
        bool $strict = true,
    ): ServiceResult {
        return $this->paymentOrder->markRefunded($payOrderNo, $reason, $operatorId, $strict);
    }

    /** @return list<array<string, mixed>> */
    public function payOrderPaidRowsForMemberScene(int $memberId, string $scene, int $limit = 100): array
    {
        if ($memberId < 1 || !$this->payOrderModelExists() || trim($scene) === '') {
            return [];
        }
        $rows = PaymentOrder::where('member_id', $memberId)
            ->where('scene', $scene)
            ->where('status', PaymentOrderService::STATUS_PAID)
            ->order('id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function payOrderRowByOrderNoMemberScene(string $orderNo, int $memberId, string $scene): ?array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '' || $memberId < 1 || !$this->payOrderModelExists()) {
            return null;
        }
        $row = PaymentOrder::where('order_no', $orderNo)
            ->where('member_id', $memberId)
            ->where('scene', $scene)
            ->find()?->toArray();

        return is_array($row) ? $row : null;
    }
}
