<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\support\MoneyMath;
use app\common\support\ServiceResult;
use app\common\service\payment\PaymentFulfillmentService;
use app\common\service\payment\PaymentOrderValidator;

use app\common\model\PaymentNotifyLog;
use app\common\model\PaymentOrder;
use app\common\service\content\ContentSearchService;
use app\common\service\event\EventBusService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\gateway\PaymentGatewayInterface;
use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\support\AppTime;
use app\common\support\CursorPaginator;
use app\common\support\DbTable;
use app\common\support\OpsLog;
use app\common\support\QueryLimit;
use think\facade\Db;

class PaymentOrderService
{

    public function __construct(
        private readonly PaymentOrderValidator $paymentOrderValidator,
        private readonly PaymentConfigService $paymentConfigService,
        private readonly PaymentFulfillmentService $paymentFulfillmentService,
        private readonly EventBusService $eventBusService,
    ) {
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_CLOSED  = 'closed';
    public const STATUS_REFUNDED = 'refunded';

    public const SCENE_RECHARGE          = 'recharge';
    public const SCENE_PLUGIN_ENTITLEMENT  = 'plugin_entitlement';

    /** 付费下载 scene：`{plugin_id}.document_paid`（禁止空 id / 旧字面量 plugin.document_paid） */
    public static function pluginDocumentPaidScene(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $identifier === 'plugin') {
            return '';
        }

        return $identifier . '.document_paid';
    }

    public static function parsePluginDocumentPaidSceneIdentifier(string $scene): ?string
    {
        $scene = trim($scene);
        // 旧统一 scene 已退役，不再解析为合法 document_paid
        if ($scene === 'plugin.document_paid' || !str_ends_with($scene, '.document_paid')) {
            return null;
        }
        $id = strtolower(trim(substr($scene, 0, -strlen('.document_paid'))));
        if ($id === '' || $id === 'plugin') {
            return null;
        }

        return $id;
    }

    public function isPluginDocumentPaidScene(string $scene): bool
    {
        return self::parsePluginDocumentPaidSceneIdentifier(trim($scene)) !== null;
    }

    public static function pluginDocumentPaidSceneLabel(string $scene): string
    {
        $pluginId = self::parsePluginDocumentPaidSceneIdentifier($scene);

        return $pluginId !== null ? ('付费下载·' . $pluginId) : '付费下载';
    }

    /**
     * 后台订单「场景」中文名（账房 / 会员中心共用；禁前端按插件 id 硬编码）
     */
    public function sceneLabelForAdmin(string $scene): string
    {
        $scene = trim($scene);
        if ($scene === '') {
            return '';
        }
        $fromRegistry = app(PaymentFulfillmentRegistry::class)->sceneLabel($scene);
        if ($fromRegistry !== null && $fromRegistry !== '') {
            return $fromRegistry;
        }
        if ($this->isPluginDocumentPaidScene($scene)) {
            return self::pluginDocumentPaidSceneLabel($scene);
        }
        $bridge = app(PluginOfferBridgeRegistry::class);
        if ($bridge->matchesPaymentScene($scene)) {
            return $bridge->adminOrderSceneLabel();
        }

        return $scene;
    }

    /** 可售/报价插件支付 scene（= PluginOfferBridgeRegistry identifier） */
    public function offerBridgePaymentScene(): string
    {
        $id = app(PluginOfferBridgeRegistry::class)->identifier();

        return ($id !== null && $id !== '') ? $id : '';
    }

    public function matchesOfferBridgePaymentScene(string $scene): bool
    {
        return app(PluginOfferBridgeRegistry::class)->matchesPaymentScene($scene);
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function createOrder(
        int $userId,
        string $scene,
        int $sceneId,
        float|string|int $amount,
        string $channel,
        array $payload = []
    ): ServiceResult {
        $check = $this->paymentOrderValidator->createParams($userId, $scene, $amount, $channel);
        if (!$check->isOk()) {
            return ServiceResult::fail($check->message());
        }
        $checkData = $check->dataArray();
        $userId  = (int) ($checkData['user_id'] ?? 0);
        $scene   = (string) ($checkData['scene'] ?? '');
        $amount  = MoneyMath::formatPlain($checkData['amount'] ?? '0');
        $channel = (string) ($checkData['channel'] ?? '');

        $orderNo = $this->generateOrderNo();
        $subject = trim((string) ($payload['title'] ?? $payload['subject'] ?? '在线支付'));
        $now     = AppTime::now();

        PaymentOrder::insert([
            'order_no'     => $orderNo,
            'user_id'      => $userId,
            'scene'        => $scene,
            'scene_id'     => max(0, $sceneId),
            'amount'       => $amount,
            'channel'      => $channel,
            'status'       => self::STATUS_PENDING,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        if ($channel === PaymentConfigService::CHANNEL_BALANCE) {
            if (!$this->paymentConfigService->isBalanceOpen()) {
                return ServiceResult::fail('余额/线下通道未开启');
            }

            return ServiceResult::ok(['order_no' => $orderNo, 'type' => 'manual'], '订单已创建，待手动确认入账');
        }

        if ($this->paymentConfigService->shouldUseDemo($channel)) {
            $this->paymentConfigService->warnIfDemoInProduction();
            $paid = $this->markPaid($orderNo, 'demo:' . $orderNo, ['demo' => true]);
            if (!$paid->isOk()) {
                return ServiceResult::fail($paid->message());
            }

            return ServiceResult::ok(['order_no' => $orderNo, 'type' => 'demo', 'demo' => 1, 'redirect' => '/member/pay/return?order_no=' . rawurlencode($orderNo) . '&demo=1'], '演示模式：订单已自动完成');
        }

        if (!$this->paymentConfigService->channelReady($channel)) {
            return ServiceResult::fail($this->paymentConfigService->channelUnavailableMessage($channel));
        }

        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单创建失败');
        }
        $order['subject'] = $subject;
        $gateway          = $this->gateway($channel);
        $pay              = $gateway->createPayment($order, $this->paymentConfigService->gatewayConfig($channel));
        if (!$pay->isOk()) {
            PaymentOrder::where('order_no', $orderNo)->update([
                'status'     => self::STATUS_FAILED,
                'updated_at' => AppTime::now(),
            ]);

            return ServiceResult::fail($pay->message());
        }

        $payData = $pay->dataArray();
        unset($payData['code'], $payData['msg']);

        return ServiceResult::ok(array_merge(['order_no' => $orderNo], $payData), 'ok');
    }

    /**
     * @param array<string, mixed> $raw
     * @return ServiceResult
     */
    public function markPaid(string $orderNo, string $txnId, array $raw = []): ServiceResult
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ServiceResult::fail('订单号无效');
        }

        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        if ((string) ($order['status'] ?? '') === self::STATUS_PAID) {
            return ServiceResult::ok(null, '已支付');
        }

        $lock = app(\app\common\service\infra\DistributedLockService::class)->using(
            'pay:order:' . $orderNo,
            30,
            function () use ($orderNo, $txnId, $raw): ServiceResult {
                return $this->markPaidLocked($orderNo, $txnId, $raw);
            }
        );
        if ($lock === null) {
            return ServiceResult::fail('订单处理中，请稍后重试');
        }

        return $lock;
    }

    /**
     * @param array<string, mixed> $raw
     * @return ServiceResult
     */
    private function markPaidLocked(string $orderNo, string $txnId, array $raw = []): ServiceResult
    {
        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        if ((string) ($order['status'] ?? '') === self::STATUS_PAID) {
            return ServiceResult::ok(null, '已支付');
        }

        $now = AppTime::now();
        Db::startTrans();
        try {
            $updated = PaymentOrder::where('order_no', $orderNo)
                ->where('status', self::STATUS_PENDING)
                ->update([
                    'status'         => self::STATUS_PAID,
                    'channel_txn_id' => mb_substr($txnId, 0, 64),
                    'paid_at'        => $now,
                    'updated_at'     => $now,
                ]);
            if ($updated < 1) {
                Db::rollback();

                return ServiceResult::ok(null, '已处理');
            }

            $fulfill = $this->paymentFulfillmentService->fulfill($order);
            if (!$fulfill->isOk()) {
                Db::rollback();
                // 履约可能已 flush 过授权缓存；回滚后需再刷，避免 can() 短暂读到未提交态。
                try {
                    app(EntitlementQueryService::class)->flushEntitlementCache();
                } catch (\Throwable $e) {
                    OpsLog::businessWarning('payment_fulfill_rollback_entitlement_flush_failed', [
                        'order_no' => $orderNo,
                        'msg'      => $e->getMessage(),
                    ]);
                }

                return ServiceResult::fail($fulfill->message());
            }

            Db::commit();
            $this->recordFulfillPayload($orderNo, $fulfill);
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('payment_mark_paid_failed', [
                'order_no' => $orderNo,
                'msg'      => $e->getMessage(),
            ]);

            return ServiceResult::fail('入账失败');
        }

        $fresh = $this->findByOrderNo($orderNo);
        if (is_array($fresh)) {
            $this->eventBusService->dispatch('payment.fulfilled', [
                'order_no' => $orderNo,
                'user_id'  => (int) ($fresh['user_id'] ?? 0),
                'scene'    => (string) ($fresh['scene'] ?? ''),
                'scene_id' => (int) ($fresh['scene_id'] ?? 0),
                'amount'   => (float) ($fresh['amount'] ?? 0),
            ]);
        }

        return ServiceResult::ok(['raw' => $raw], '支付成功');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $notifyHeaders
     * @return ServiceResult
     */
    public function handleNotify(
        string $channel,
        array $input,
        string $rawBody = '',
        array $notifyHeaders = []
    ): ServiceResult {
        $channel = strtolower(trim($channel));
        if (!app(PaymentChannelRegistry::class)->isOnline($channel)) {
            return ServiceResult::fail('未知支付通道');
        }
        $gateway = $this->gateway($channel);
        $config  = $this->paymentConfigService->gatewayConfig($channel);

        $logId = (int) PaymentNotifyLog::insertGetId([
            'channel'      => $channel,
            'order_no'     => (string) ($input['out_trade_no'] ?? $input['order_no'] ?? ''),
            'verified'     => 0,
            'payload_json' => $rawBody !== '' ? $rawBody : json_encode($input, JSON_UNESCAPED_UNICODE),
            'created_at'   => AppTime::now(),
        ]);

        $parsed = $gateway->parseNotify($input, $rawBody, $config, $notifyHeaders);
        if (!$parsed['ok']) {
            return ServiceResult::fail(isset($parsed['msg']) ? (string) $parsed['msg'] : '验签失败');
        }

        if ($logId > 0) {
            PaymentNotifyLog::where('id', $logId)->update([
                'verified' => 1,
                'order_no' => (string) ($parsed['order_no'] ?? ''),
            ]);
        }

        $orderNo = trim((string) ($parsed['order_no'] ?? ''));
        $txnId   = trim((string) ($parsed['txn_id'] ?? ''));
        if ($orderNo !== '') {
            $existing = $this->findByOrderNo($orderNo);
            if (is_array($existing) && (string) ($existing['status'] ?? '') === self::STATUS_PAID) {
                $prevTxn = trim((string) ($existing['channel_txn_id'] ?? ''));
                if ($txnId !== '' && $prevTxn !== '' && $prevTxn === $txnId) {
                    OpsLog::businessInfo('pay_notify_duplicate', [
                        'order_no' => $orderNo,
                        'txn_id'   => $txnId,
                        'channel'  => $channel,
                    ]);
                } elseif ($txnId !== '' && $prevTxn !== '' && $prevTxn !== $txnId) {
                    // 已支付订单收到不同 channel_txn_id：才记 replay（避免 verified count 误报）
                    OpsLog::businessInfo('pay_notify_replay', [
                        'order_no' => $orderNo,
                        'prev_txn' => $prevTxn,
                        'txn_id'   => $txnId,
                        'channel'  => $channel,
                    ]);
                }
            }
        }

        $paid = $this->markPaid((string) ($parsed['order_no'] ?? ''), (string) ($parsed['txn_id'] ?? ''), $parsed['raw'] ?? []);
        if (!$paid->isOk()) {
            return ServiceResult::fail($paid->message());
        }

        return ServiceResult::ok([
            'response' => app(PaymentChannelRegistry::class)->notifySuccessBody($channel),
        ], 'success');
    }

    /** @return array<string, mixed>|null */
    public function findByOrderNo(string $orderNo): ?array
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return null;
        }
        $row = PaymentOrder::where('order_no', $orderNo)->find();
        if (!$row instanceof PaymentOrder) {
            return null;
        }

        return $row->toArray();
    }

    /**
     * 轮询查单入口：notify 丢失时向渠道查单并补入账（live 渠道）。
     */
    public function trySyncPaidFromGateway(string $orderNo, int $userId = 0): void
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return;
        }
        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return;
        }
        if ($userId > 0 && (int) ($order['user_id'] ?? 0) !== $userId) {
            return;
        }
        if ((string) ($order['status'] ?? '') === self::STATUS_PAID) {
            return;
        }

        $this->syncPaidFromGatewayOrder($order);
    }

    /**
     * 订阅续费：对已发起的网关订单短轮询查单（notify 丢失时补入账）。
     */
    public function attemptGatewayRenewSettlement(string $orderNo, int $userId = 0, int $maxPoll = 3): bool
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return false;
        }

        $maxPoll = max(1, min(10, $maxPoll));
        for ($i = 0; $i < $maxPoll; $i++) {
            $this->trySyncPaidFromGateway($orderNo, $userId);
            $order = $this->findByOrderNo($orderNo);
            if (is_array($order) && (string) ($order['status'] ?? '') === self::STATUS_PAID) {
                return true;
            }
            if ($i < $maxPoll - 1) {
                sleep(2);
            }
        }

        return false;
    }

    /**
     * Cron：扫描超时 pending 在线支付订单并向渠道查单补入账。
     *
     * @return array{scanned:int,synced:int,skipped:int}
     */
    public function reconcilePendingFromGateway(int $limit = QueryLimit::RECONCILE_BATCH, int $minAgeSeconds = 120): array
    {
        $limit = max(1, min(200, $limit));
        $minAgeSeconds = max(60, $minAgeSeconds);
        $cutoff = AppTime::format('Y-m-d H:i:s', time() - $minAgeSeconds);

        $rows = PaymentOrder::where('status', self::STATUS_PENDING)
            ->whereIn('channel', app(PaymentChannelRegistry::class)->ids('online'))
            ->where('created_at', '<=', $cutoff)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $synced  = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $channel = strtolower(trim((string) ($row['channel'] ?? '')));
            if (!app(PaymentChannelRegistry::class)->isOnline($channel)) {
                $skipped++;
                continue;
            }
            if ($this->syncPaidFromGatewayOrder($row)) {
                $synced++;
            }
        }

        return ['scanned' => count($rows), 'synced' => $synced, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function syncPaidFromGatewayOrder(array $order): bool
    {
        if ((string) ($order['status'] ?? '') !== self::STATUS_PENDING) {
            return false;
        }

        $orderNo = trim((string) ($order['order_no'] ?? ''));
        if ($orderNo === '') {
            return false;
        }

        $channel = strtolower(trim((string) ($order['channel'] ?? '')));
        if (!app(PaymentChannelRegistry::class)->isOnline($channel)) {
            return false;
        }
        if ($this->paymentConfigService->shouldUseDemo($channel)
            || !$this->paymentConfigService->channelReady($channel)) {
            return false;
        }

        $query = $this->gateway($channel)->queryPayment(
            $orderNo,
            $this->paymentConfigService->gatewayConfig($channel)
        );
        if (!$query['ok']) {
            return false;
        }
        if (!$query['paid']) {
            return false;
        }

        $paid = $this->markPaid(
            (string) ($query['order_no'] ?? $orderNo),
            (string) ($query['txn_id'] ?? ''),
            is_array($query['raw'] ?? null) ? $query['raw'] : []
        );

        return $paid->isOk();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdmin(array $filters, int $page = 1, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));
        $query = PaymentOrder::withoutField('payload_json')->order('id', 'desc');

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $query->where('channel', $this->paymentConfigService->normalizeChannel($channel));
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('order_no|channel_txn_id', app(ContentSearchService::class)->likePattern($keyword));
        }
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $scene = trim((string) ($filters['scene'] ?? ''));
        if ($scene !== '') {
            $query->where('scene', $scene);
        }

        $cursorId = (int) ($filters['cursor_id'] ?? 0);
        $allowCursor = $status === '' && $channel === '' && $keyword === '' && $userId < 1 && $scene === '';
        $pageResult = CursorPaginator::paginateById($query, $limit, $page, $cursorId, 'id', $allowCursor);
        $list  = $pageResult['rows'];
        $total = $pageResult['total'];

        foreach ($list as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $list[$i]['scene_label'] = $this->sceneLabelForAdmin((string) ($row['scene'] ?? ''));
        }

        return [
            'list'           => $list,
            'total'          => $total,
            'page'           => $page,
            'limit'          => $limit,
            'next_cursor_id' => $pageResult['next_cursor_id'],
        ];
    }

    /** @return array{total:int,pending:int,paid:int} */
    public function statsAdmin(): array
    {
        if (!DbTable::modelExists(PaymentOrder::class)) {
            return ['total' => 0, 'pending' => 0, 'paid' => 0];
        }
        $table = DbTable::name('payment_orders');

        return [
            'total'   => (int) Db::table($table)->count(),
            'pending' => (int) Db::table($table)->where('status', 'pending')->count(),
            'paid'    => (int) Db::table($table)->where('status', 'paid')->count(),
        ];
    }

    /**
     * 分批清理过期支付回调日志（保留近 N 天审计）。
     */
    public function pruneNotifyLogsOlderThanDays(int $days, int $batchLimit = 5000): int
    {
        if (!DbTable::modelExists(PaymentNotifyLog::class)) {
            return 0;
        }
        $days       = max(30, min(365, $days));
        $batchLimit = max(100, min(20000, $batchLimit));
        $cutoff     = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);
        $ids        = PaymentNotifyLog::where('created_at', '<', $cutoff)
            ->order('id', 'asc')
            ->limit($batchLimit)
            ->column('id');
        $ids = array_values(array_map('intval', $ids ?: []));
        if ($ids === []) {
            return 0;
        }

        return (int) PaymentNotifyLog::whereIn('id', $ids)->delete();
    }

    public function generateOrderNo(): string
    {
        return 'PAY' . AppTime::format('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    }

    private function gateway(string $channel): PaymentGatewayInterface
    {
        $channel = strtolower(trim($channel));
        $gateway = app(PaymentChannelRegistry::class)->gateway($channel);
        if ($gateway === null) {
            throw new \RuntimeException('支付通道 Gateway 未注册：' . $channel);
        }

        return $gateway;
    }

    /**
     * 后台退款：live 模式走网关原路退，demo 仅本地标记。
     *
     * @return ServiceResult
     */
    public function markRefunded(string $orderNo, string $reason, int $adminId, bool $viaGateway = true): ServiceResult
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ServiceResult::fail('订单号无效');
        }

        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        $status = (string) ($order['status'] ?? '');
        if ($status === self::STATUS_REFUNDED) {
            return ServiceResult::ok(null, '已退款');
        }
        if ($status !== self::STATUS_PAID) {
            return ServiceResult::fail('仅已支付订单可退款');
        }

        $channel = $this->paymentConfigService->normalizeChannel((string) ($order['channel'] ?? ''));
        $gatewayUsed = 0;
        if ($viaGateway && $channel !== PaymentConfigService::CHANNEL_BALANCE) {
            $gateway = $this->gateway($channel);
            $refund  = $gateway->refundPayment($order, $this->paymentConfigService->gatewayConfig($channel), $reason);
            if (!$refund->isOk()) {
                return ServiceResult::fail($refund->message());
            }
            $gatewayUsed = (int) ($refund->dataArray()['gateway'] ?? 0);
        }

        $now     = AppTime::now();
        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['_refund'] = [
            'reason'   => mb_substr(trim($reason), 0, 500),
            'admin_id' => max(0, $adminId),
            'at'       => $now,
            'gateway'  => $gatewayUsed,
        ];

        PaymentOrder::where('order_no', $orderNo)->update([
            'status'       => self::STATUS_REFUNDED,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at'   => $now,
        ]);

        $fresh = $this->findByOrderNo($orderNo);
        if (is_array($fresh)) {
            $this->eventBusService->dispatch('payment.refunded', [
                'order_no' => $orderNo,
                'user_id'  => (int) ($fresh['user_id'] ?? 0),
                'scene'    => (string) ($fresh['scene'] ?? ''),
                'scene_id' => (int) ($fresh['scene_id'] ?? 0),
                'amount'   => (float) ($fresh['amount'] ?? 0),
                'reason'   => mb_substr(trim($reason), 0, 500),
                'admin_id' => max(0, $adminId),
            ]);
        }

        return ServiceResult::ok(['gateway' => $gatewayUsed], $gatewayUsed ? '网关退款成功' : '已标记退款');
    }

    /**
     * 后台关闭无效订单（待支付 / 失败）；关闭前对在线 pending 尝试查单补入账。
     *
     * @return ServiceResult
     */
    public function closeAdmin(string $orderNo, int $adminId, string $reason = ''): ServiceResult
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ServiceResult::fail('订单号无效');
        }

        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }

        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return ServiceResult::fail('仅待支付或失败订单可关闭');
        }

        if ($status === self::STATUS_PENDING) {
            try {
                if ($this->syncPaidFromGatewayOrder($order)) {
                    return ServiceResult::fail('订单已支付，无法关闭');
                }
            } catch (\Throwable $e) {
                OpsLog::businessWarning('payment_close_sync_failed', [
                    'order_no' => $orderNo,
                    'message'  => $e->getMessage(),
                ]);
            }
        }

        return $this->applyClosedStatus($orderNo, $adminId, $reason, 'manual');
    }

    /**
     * 批量关闭超时仍待支付 / 失败的无效订单（不删除，保留审计）。
     *
     * @return ServiceResult
     */
    public function closeStaleInvalidAdmin(int $olderThanDays, int $adminId, int $limit = 500): ServiceResult
    {
        $olderThanDays = max(1, min(365, $olderThanDays));
        $limit         = max(1, min(2000, $limit));
        $cutoff        = AppTime::format('Y-m-d H:i:s', time() - $olderThanDays * 86400);

        $rows = PaymentOrder::whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where('created_at', '<', $cutoff)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $closed      = 0;
        $skippedPaid = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderNo = trim((string) ($row['order_no'] ?? ''));
            if ($orderNo === '') {
                continue;
            }
            if ((string) ($row['status'] ?? '') === self::STATUS_PENDING) {
                try {
                    if ($this->syncPaidFromGatewayOrder($row)) {
                        $skippedPaid++;
                        continue;
                    }
                } catch (\Throwable $e) {
                    OpsLog::businessWarning('payment_close_sync_failed', [
                        'order_no' => $orderNo,
                        'message'  => $e->getMessage(),
                    ]);
                }
            }
            $res = $this->applyClosedStatus(
                $orderNo,
                $adminId,
                '超时无效订单批量关闭',
                'stale_batch',
                $olderThanDays
            );
            if ($res->isOk()) {
                $closed++;
            }
        }

        OpsLog::businessInfo('payment_orders_close_stale', [
            'admin_id'     => max(0, $adminId),
            'older_days'   => $olderThanDays,
            'scanned'      => count($rows),
            'closed'       => $closed,
            'skipped_paid' => $skippedPaid,
        ]);

        $msg = sprintf('已关闭 %d 笔无效订单', $closed);
        if ($skippedPaid > 0) {
            $msg .= sprintf('（跳过 %d 笔已补入账）', $skippedPaid);
        }

        return ServiceResult::ok([
            'closed'       => $closed,
            'skipped_paid' => $skippedPaid,
            'scanned'      => count($rows),
        ], $msg);
    }

    private function applyClosedStatus(
        string $orderNo,
        int $adminId,
        string $reason,
        string $source,
        int $olderThanDays = 0
    ): ServiceResult {
        $order = $this->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return ServiceResult::fail('订单状态已变更，无法关闭');
        }

        $now     = AppTime::now();
        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['_close'] = [
            'reason'      => mb_substr(trim($reason) !== '' ? trim($reason) : '管理员关闭', 0, 500),
            'admin_id'    => max(0, $adminId),
            'at'          => $now,
            'source'      => $source,
            'from_status' => $status,
        ];
        if ($olderThanDays > 0) {
            $payload['_close']['older_days'] = $olderThanDays;
        }

        PaymentOrder::where('order_no', $orderNo)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->update([
                'status'       => self::STATUS_CLOSED,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'updated_at'   => $now,
            ]);

        OpsLog::businessInfo('payment_order_closed', [
            'order_no' => $orderNo,
            'admin_id' => max(0, $adminId),
            'source'   => $source,
        ]);

        return ServiceResult::ok(null, '订单已关闭');
    }

    /**
     * 重试 payload._fulfill.status=failed 的已支付订单履约。
     *
     * @return array{retried:int,succeeded:int,failed:int}
     */
    public function retryFailedFulfillments(int $limit = QueryLimit::STATS_TOP_SMALL): array
    {
        $limit   = max(1, min(50, $limit));
        $retried = $succeeded = $failed = 0;

        $rows = PaymentOrder::where('status', self::STATUS_PAID)
            ->order('id', 'desc')
            ->limit($limit * 20)
            ->select()
            ->toArray();

        foreach ($rows as $order) {
            if ($retried >= $limit) {
                break;
            }
            $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $fulfill = $payload['_fulfill'] ?? null;
            if (!is_array($fulfill) || (string) ($fulfill['status'] ?? '') !== 'failed') {
                continue;
            }

            $retried++;
            $res = $this->paymentFulfillmentService->fulfill($order);
            $this->recordFulfillPayload((string) ($order['order_no'] ?? ''), $res, $order);
            if ($res->isOk()) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return ['retried' => $retried, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed>|null $order
     */
    private function recordFulfillPayload(string $orderNo, ServiceResult $fulfill, ?array $order = null): void
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return;
        }
        if ($order === null) {
            $order = $this->findByOrderNo($orderNo);
        }
        if ($order === null) {
            return;
        }
        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['_fulfill'] = [
            'status' => $fulfill->isOk() ? 'done' : 'failed',
            'msg'    => $fulfill->message(),
            'at'     => AppTime::now(),
        ];
        PaymentOrder::where('order_no', $orderNo)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at'   => AppTime::now(),
        ]);
    }
}
