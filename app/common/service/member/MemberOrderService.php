<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 会员中心 — 统一支付订单（payment_orders，不依赖报价插件）
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\model\User;
use app\common\model\PaymentNotifyLog;
use app\common\model\PaymentOrder;
use app\common\service\payment\PaymentFulfillmentRegistry;
use app\common\service\payment\PaymentOrderService;
use app\common\service\payment\PaymentChannelRegistry;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\support\DbTable;
use app\common\support\MoneyMath;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\support\ModelRelationLoad;
use think\facade\Db;

class MemberOrderService
{

    public function __construct(
        private readonly PaymentFulfillmentRegistry $paymentFulfillmentRegistry,
        private readonly PaymentOrderService $paymentOrderService,
        private readonly PaymentChannelRegistry $paymentChannelRegistry,
        private readonly PluginOfferBridgeRegistry $pluginOfferBridgeRegistry,
    ) {
    }

    /** @var array<string, string> */
    public const SCENE_LABELS = [
        PaymentOrderService::SCENE_RECHARGE         => '会员充值',
        PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT => '插件授权',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        PaymentOrderService::STATUS_PENDING => '待支付',
        PaymentOrderService::STATUS_PAID    => '已支付',
        PaymentOrderService::STATUS_FAILED  => '失败',
        PaymentOrderService::STATUS_CLOSED  => '已关闭',
        PaymentOrderService::STATUS_REFUNDED => '已退款',
    ];

    /** 后台「支付订单」场景筛选项（value => label） */
    private const ADMIN_SCENE_FILTER_LABELS = [
        PaymentOrderService::SCENE_RECHARGE           => '会员充值',
        PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT => '插件授权',
    ];

    /** @return list<string> */
    private function platformOnlySceneKeys(): array
    {
        return $this->paymentFulfillmentRegistry->hostOnlySceneKeys();
    }

    /** host_only 发行形态才暴露站点授权、开发者保证金 */
    public function adminIncludesPlatformScenes(): bool
    {
        return PluginDistributionPolicy::isHostBundleActive();
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function adminSceneFilterOptions(): array
    {
        $options = [['value' => '', 'label' => '全部场景']];
        foreach (self::ADMIN_SCENE_FILTER_LABELS as $value => $label) {
            if (!$this->adminIncludesPlatformScenes()
                && in_array($value, $this->platformOnlySceneKeys(), true)) {
                continue;
            }
            $options[] = ['value' => $value, 'label' => $label];
        }
        foreach ($this->paymentFulfillmentRegistry->pluginSceneFilterOptions() as $opt) {
            $options[] = $opt;
        }
        foreach ($this->offerBridgeSceneFilterOptions() as $opt) {
            $options[] = $opt;
        }
        if ($this->adminIncludesPlatformScenes()) {
            foreach ($this->paymentFulfillmentRegistry->hostOnlySceneFilterOptions() as $opt) {
                $options[] = $opt;
            }
        }

        return $options;
    }

    /** @return list<array{value:string,label:string}> */
    private function offerBridgeSceneFilterOptions(): array
    {
        $registry = $this->pluginOfferBridgeRegistry;
        $values   = $registry->paymentSceneFilterValues();
        if ($values === []) {
            return [];
        }
        $label = $registry->adminOrderSceneLabel();
        $out   = [];
        foreach ($values as $value) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    private function sceneLabel(string $scene): string
    {
        return $this->paymentOrderService->sceneLabelForAdmin($scene);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function normalizeAdminFilters(array $filters): array
    {
        $scene = trim((string) ($filters['scene'] ?? ''));
        if ($scene !== ''
            && !$this->adminIncludesPlatformScenes()
            && in_array($scene, $this->platformOnlySceneKeys(), true)) {
            $filters['scene'] = '';
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   list:list<array<string,mixed>>,
     *   total:int,
     *   page:int,
     *   limit:int,
     *   stats:array{total:int,pending:int,paid:int}
     * }
     */
    public function listAdmin(array $filters, int $page = 1, int $limit = 20): array
    {
        if (!$this->tableExists()) {
            return [
                'list'          => [],
                'total'         => 0,
                'page'          => max(1, $page),
                'limit'         => max(1, min(100, $limit)),
                'stats'         => ['total' => 0, 'pending' => 0, 'paid' => 0],
                'scene_options' => $this->adminSceneFilterOptions(),
            ];
        }

        $filters = $this->normalizeAdminFilters($filters);
        $result  = $this->paymentOrderService->listAdmin($filters, $page, $limit);
        $userIds = [];
        foreach ($result['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }
        $users = ModelRelationLoad::indexUsersBasicByIds(array_values($userIds));

        $list = [];
        foreach ($result['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapAdminRow($row);
            $uid    = (int) ($row['user_id'] ?? 0);
            if ($uid > 0 && isset($users[$uid])) {
                $mapped['username'] = (string) ($users[$uid]['username'] ?? '');
                $mapped['nickname'] = (string) ($users[$uid]['nickname'] ?? '');
            }
            $list[] = $mapped;
        }

        return [
            'list'          => $list,
            'total'         => (int) ($result['total'] ?? 0),
            'page'          => (int) ($result['page'] ?? $page),
            'limit'         => (int) ($result['limit'] ?? $limit),
            'stats'         => $this->paymentOrderService->statsAdmin(),
            'scene_options' => $this->adminSceneFilterOptions(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function detailAdmin(string $orderNo): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return null;
        }

        $row = $this->paymentOrderService->findByOrderNo($orderNo);
        if ($row === null) {
            return null;
        }

        $mapped  = $this->mapAdminRow($row);
        $userId  = (int) ($row['user_id'] ?? 0);
        $member  = null;
        if ($userId > 0) {
            $user = User::where('id', $userId)->field('id,username,nickname,mobile,email')->find();
            if ($user instanceof User) {
                $member = $user->toArray();
            }
        }

        $payload = $this->decodePayloadJson((string) ($row['payload_json'] ?? ''));
        $notifyLogs = $this->notifyLogsForAdmin($orderNo);
        $status     = (string) ($row['status'] ?? '');

        return array_merge($mapped, [
            'id'             => (int) ($row['id'] ?? 0),
            'user_id'        => $userId,
            'username'       => (string) ($member['username'] ?? ''),
            'nickname'       => (string) ($member['nickname'] ?? ''),
            'member'         => $member,
            'channel_txn_id' => (string) ($row['channel_txn_id'] ?? ''),
            'updated_at'     => (string) ($row['updated_at'] ?? ''),
            'amount_text'    => '¥' . (string) ($mapped['amount'] ?? '0'),
            'business_lines' => $this->buildBusinessLines($payload, $mapped),
            'timeline'       => $this->buildAdminTimeline($row, $payload, $notifyLogs),
            'notify_logs'    => $notifyLogs,
            'can_close'      => in_array($status, [
                PaymentOrderService::STATUS_PENDING,
                PaymentOrderService::STATUS_FAILED,
            ], true),
            'can_refund'     => $status === PaymentOrderService::STATUS_PAID,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function notifyLogsForAdmin(string $orderNo): array
    {
        if (!DbTable::modelExists(PaymentNotifyLog::class)) {
            return [];
        }

        $logs = [];
        foreach (
            PaymentNotifyLog::where('order_no', $orderNo)
                ->order('id', 'desc')
                ->limit(30)
                ->select()
                ->toArray() as $log
        ) {
            if (!is_array($log)) {
                continue;
            }
            $channel = strtolower(trim((string) ($log['channel'] ?? '')));
            $verified = (int) ($log['verified'] ?? 0) === 1;
            $logs[] = [
                'id'             => (int) ($log['id'] ?? 0),
                'channel'        => $channel,
                'channel_label'  => $this->paymentChannelRegistry->buttonLabel($channel, true),
                'verified'       => $verified ? 1 : 0,
                'verified_label' => $verified ? '验签通过' : '验签失败',
                'created_at'     => (string) ($log['created_at'] ?? ''),
            ];
        }

        return $logs;
    }

    /** @return array<string, mixed> */
    private function decodePayloadJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $mapped
     * @return list<array{label:string,value:string}>
     */
    private function buildBusinessLines(array $payload, array $mapped): array
    {
        $lines = [];
        $map   = [
            'plugin_identifier' => '插件标识',
            'sku_id'            => 'SKU',
            'sku_key'           => 'SKU Key',
            'package_id'        => '充值套餐 ID',
            'level_id'          => '会员级别 ID',
            'document_id'       => '文档 ID',
            'item_id'           => '品项 ID',
            'site_key'          => '站点 Key',
            'domain'            => '绑定域名',
            'license_edition'   => '授权版本',
            'developer_type'    => '开发者类型',
        ];
        foreach ($map as $key => $label) {
            $value = $payload[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = ['label' => $label, 'value' => (string) $value];
        }
        $title = trim((string) ($mapped['title'] ?? ''));
        if ($title !== '') {
            array_unshift($lines, ['label' => '业务说明', 'value' => $title]);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $notifyLogs
     * @return list<array{at:string,kind:string,title:string,detail:string}>
     */
    private function buildAdminTimeline(array $row, array $payload, array $notifyLogs): array
    {
        $events = [];
        $created = trim((string) ($row['created_at'] ?? ''));
        if ($created !== '') {
            $amount = MoneyMath::formatPlain((float) ($row['amount'] ?? 0));
            $events[] = [
                'at'     => $created,
                'kind'   => 'created',
                'title'  => '创建订单',
                'detail' => '金额 ¥' . $amount,
            ];
        }

        foreach ($notifyLogs as $log) {
            $at = trim((string) ($log['created_at'] ?? ''));
            if ($at === '') {
                continue;
            }
            $events[] = [
                'at'     => $at,
                'kind'   => 'notify',
                'title'  => '支付渠道回调',
                'detail' => (string) ($log['verified_label'] ?? '') . ' · '
                    . (string) ($log['channel_label'] ?? ''),
            ];
        }

        $paidAt = trim((string) ($row['paid_at'] ?? ''));
        if ($paidAt !== '') {
            $txn = trim((string) ($row['channel_txn_id'] ?? ''));
            $events[] = [
                'at'     => $paidAt,
                'kind'   => 'paid',
                'title'  => '支付成功',
                'detail' => $txn !== '' ? '渠道单号 ' . $txn : '已入账',
            ];
        }

        $this->appendPayloadTimelineEvent($events, $payload, '_fulfill', '业务履约');
        $this->appendPayloadTimelineEvent($events, $payload, '_refund', '订单退款');
        $this->appendPayloadTimelineEvent($events, $payload, '_close', '关闭订单');
        $this->appendPayloadTimelineEvent($events, $payload, '_rollback', '回滚入账');

        $updated = trim((string) ($row['updated_at'] ?? ''));
        $status  = (string) ($row['status'] ?? '');
        if ($status === PaymentOrderService::STATUS_FAILED && $updated !== '') {
            $events[] = [
                'at'     => $updated,
                'kind'   => 'failed',
                'title'  => '支付失败',
                'detail' => '下单或拉起支付失败',
            ];
        }

        usort($events, static fn(array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    /**
     * @param list<array{at:string,kind:string,title:string,detail:string}> $events
     * @param array<string, mixed> $payload
     */
    private function appendPayloadTimelineEvent(
        array &$events,
        array $payload,
        string $key,
        string $title
    ): void {
        $block = $payload[$key] ?? null;
        if (!is_array($block)) {
            return;
        }
        $at = trim((string) ($block['at'] ?? ''));
        if ($at === '') {
            return;
        }
        $detail = trim((string) ($block['reason'] ?? $block['msg'] ?? $block['status'] ?? ''));
        if ($detail === '' && $key === '_fulfill') {
            $detail = (string) ($block['status'] ?? 'done');
        }
        $events[] = [
            'at'     => $at,
            'kind'   => ltrim($key, '_'),
            'title'  => $title,
            'detail' => $detail !== '' ? $detail : '—',
        ];
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listForUser(int $userId, int $page = 1, int $limit = 20, string $status = ''): array
    {
        if ($userId < 1 || !$this->tableExists()) {
            return ['list' => [], 'total' => 0];
        }
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 50);
        $query = PaymentOrder::where('user_id', $userId)->order('id', 'desc');
        $status = trim($status);
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $query->where('status', $status);
        }
        $total = (int) $query->count();
        $rows  = $query->page($page, $limit)->select()->toArray();
        $list  = [];
        foreach ($rows as $row) {
            $list[] = $this->mapRow(is_array($row) ? $row : []);
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAdminRow(array $row): array
    {
        $mapped = $this->mapRow($row);

        return array_merge($row, $mapped);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $scene  = (string) ($row['scene'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $payload = [];
        $raw = (string) ($row['payload_json'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $title = trim((string) ($payload['title'] ?? $payload['subject'] ?? ''));
        if ($title === '') {
            $title = $this->sceneLabel($scene);
        }
        $amount = MoneyMath::formatPlain((float) ($row['amount'] ?? 0));
        $channel = strtolower(trim((string) ($row['channel'] ?? '')));

        return [
            'order_no'      => (string) ($row['order_no'] ?? ''),
            'scene'         => $scene,
            'scene_label'   => $this->sceneLabel($scene),
            'scene_id'      => (int) ($row['scene_id'] ?? 0),
            'amount'        => $amount,
            'amount_text'   => '¥' . $amount,
            'channel'       => $channel,
            'channel_label' => $this->paymentChannelRegistry->buttonLabel($channel, true),
            'status'        => $status,
            'status_label'  => self::STATUS_LABELS[$status] ?? $status,
            'title'         => $title,
            'paid_at'       => (string) ($row['paid_at'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'is_paid'       => $status === PaymentOrderService::STATUS_PAID ? 1 : 0,
            'is_pending'    => $status === PaymentOrderService::STATUS_PENDING ? 1 : 0,
        ];
    }

    private function tableExists(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $ok = DbTable::modelExists(PaymentOrder::class);

        return $ok;
    }
}
