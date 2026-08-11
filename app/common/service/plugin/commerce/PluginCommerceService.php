<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\entitlement\EntitlementQueryService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

use app\common\support\AppTime;
use app\common\model\PaymentOrder;
use app\common\service\audit\AuditLogService;
use app\common\service\payment\PaymentOrderFactory;
use app\common\service\payment\PaymentOrderService;
use app\common\service\release\PivarkEditionService;
use think\facade\Db;

final class PluginCommerceService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly PaymentOrderFactory $paymentOrderFactory,
        private readonly PaymentOrderService $paymentOrderService,
        private readonly PivarkEditionService $pivarkEditionService,
        private readonly PluginSkuCatalogService $pluginSkuCatalogService,
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
        private readonly EntitlementService $entitlementService,
        private readonly EntitlementQueryService $entitlementQuery,
        private readonly PluginSkuFulfillmentService $pluginSkuFulfillmentService,
        private readonly PluginWalletService $pluginWalletService,
    ) {
    }


    /**
     * @return array{
     *   identifier:string,
     *   sku_id:string,
     *   sku_name:string,
     *   name:string,
     *   price:float,
     *   period_days:?int,
     *   model:string,
     *   billing_type:string,
     *   package:string,
     *   quota_total:?int,
     *   tier:string
     * }|null
     */
    public function sku(string $identifier, string $skuId = ''): ?array
    {
        $identifier = strtolower(trim($identifier));
        $skuId      = trim($skuId);
        if ($identifier === '') {
            return null;
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return null;
        }

        $catalogRow = $this->pluginMarketRemoteCatalog->indexByIdentifier()[$identifier] ?? null;
        if ($skuId !== '') {
            foreach ($this->pluginSkuCatalogService->skusFor($identifier, $manifest, is_array($catalogRow) ? $catalogRow : null) as $row) {
                if (($row['sku_id'] ?? '') !== $skuId) {
                    continue;
                }
                $commercial = $this->pluginSkuCatalogService->skuToCommercial($row);

                return [
                    'identifier'    => $identifier,
                    'sku_id'        => $skuId,
                    'sku_name'      => (string) ($row['name'] ?? ''),
                    'name'          => (string) ($manifest['name'] ?? $identifier),
                    'price'         => (float) $commercial['price'],
                    'period_days'   => $commercial['period_days'],
                    'model'         => (string) $commercial['model'],
                    'billing_type'  => (string) ($row['billing_type'] ?? ''),
                    'package'       => (string) ($manifest['package'] ?? ''),
                    'quota_total'   => isset($row['quota_total']) ? (int) $row['quota_total'] : null,
                    'tier'          => (string) ($row['tier'] ?? ''),
                ];
            }

            return null;
        }

        $commercial = $this->pluginSkuCatalogService->resolveCommercial($identifier, $manifest);
        $activeRow  = $this->pluginSkuCatalogService->resolveActiveSku(
            $identifier,
            $manifest,
            is_array($catalogRow) ? $catalogRow : null
        );

        return [
            'identifier'    => $identifier,
            'sku_id'        => (string) $commercial['sku_id'],
            'sku_name'      => (string) $commercial['sku_name'],
            'name'          => (string) ($manifest['name'] ?? $identifier),
            'price'         => (float) $commercial['price'],
            'period_days'   => $commercial['period_days'],
            'model'         => (string) $commercial['model'],
            'billing_type'  => (string) $commercial['billing_type'],
            'package'       => (string) ($manifest['package'] ?? ''),
            'quota_total'   => isset($commercial['quota_total']) ? (int) $commercial['quota_total'] : null,
            'tier'          => is_array($activeRow) ? (string) ($activeRow['tier'] ?? '') : '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSkus(): array
    {
        return $this->pluginSkuCatalogService->listPurchasableSkus();
    }

    /**
     * @return ServiceResult
     */
    public function createEntitlementOrder(
        string $identifier,
        int $userId,
        string $channel = 'balance',
        ?float $overrideAmount = null,
        string $skuId = '',
        array $payloadExtras = []
    ): ServiceResult {
        if ($this->pivarkEditionService->isCommunity() && !$this->pivarkEditionService->allowsInSitePluginPurchase()) {
            return ServiceResult::fail('当前版本请使用授权码激活商业插件');
        }

        $sku = $this->sku($identifier, $skuId);
        if ($sku === null) {
            return ServiceResult::fail('插件不存在或 SKU 无效');
        }

        $manifest = app(PluginService::class)->readManifest($identifier);
        if (is_array($manifest) && !empty($manifest['_manifest_valid'])) {
            $gateCommercial = $sku !== null
                ? [
                    'model'        => (string) ($sku['model'] ?? ''),
                    'price'        => (float) ($sku['price'] ?? 0),
                    'period_days'  => $sku['period_days'] ?? null,
                    'sku_id'       => (string) ($sku['sku_id'] ?? ''),
                    'billing_type' => (string) ($sku['billing_type'] ?? ''),
                    'tier'         => (string) ($sku['tier'] ?? ''),
                ]
                : $this->pluginSkuCatalogService->resolveCommercial($identifier, $manifest);
            $tierMsg    = app(PluginDomainPurchaseGateService::class)->assertForPurchase($identifier, $manifest, $gateCommercial);
            if ($tierMsg !== null) {
                return ServiceResult::fail($tierMsg);
            }
        }

        if ($sku['price'] <= 0 && ($overrideAmount === null || $overrideAmount <= 0)) {
            return ServiceResult::fail('免费/试用 SKU 无需下单，请直接安装或 grant');
        }

        $amount = $overrideAmount !== null ? round($overrideAmount, 2) : $sku['price'];
        if ($amount <= 0) {
            return ServiceResult::fail('未配置价格');
        }

        $title = '插件授权：' . $sku['name'];
        if ($sku['sku_name'] !== '') {
            $title .= ' · ' . $sku['sku_name'];
        }

        $result = $this->paymentOrderFactory->create(
            max(1, $userId),
            PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT,
            0,
            $amount,
            $channel,
            array_merge([
                'title'             => $title,
                'plugin_identifier' => $identifier,
                'sku_id'            => $sku['sku_id'],
                'tier'              => $sku['tier'],
                'billing_type'      => $sku['billing_type'],
                'quota_total'       => $sku['quota_total'],
                'period_days'       => $sku['period_days'],
                'license_type'      => $sku['model'] === 'subscription' ? 'subscription' : 'paid',
                'package'           => $sku['package'],
            ], $payloadExtras)
        );

        if ($result->isOk()) {
            $created = $result->dataArray();
            $this->auditLogService->operate('创建插件授权订单', 'admin.plugin', [
                'identifier' => $identifier,
                'sku_id'     => $sku['sku_id'],
                'order_no'   => (string) ($created['order_no'] ?? ''),
                'amount'     => $amount,
                'channel'    => $channel,
                'user_id'    => $userId,
            ]);
        }

        return $result;
    }

    /**
     * 后台轮询插件授权订单状态
     *
     * @return ServiceResult
     */
    public function orderStatusForAdmin(string $orderNo): ServiceResult
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return ServiceResult::fail('订单号无效');
        }

        $order = $this->paymentOrderService->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        if ((string) ($order['scene'] ?? '') !== PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT) {
            return ServiceResult::fail('非插件授权订单');
        }

        $this->paymentOrderService->trySyncPaidFromGateway($orderNo, (int) ($order['user_id'] ?? 0));
        $order = $this->paymentOrderService->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }

        $payload    = json_decode((string) ($order['payload_json'] ?? ''), true);
        $payload    = is_array($payload) ? $payload : [];
        $identifier = strtolower(trim((string) ($payload['plugin_identifier'] ?? '')));
        $paid       = (string) ($order['status'] ?? '') === PaymentOrderService::STATUS_PAID;

        return ServiceResult::ok(['paid' => $paid, 'status' => (string) ($order['status'] ?? ''), 'identifier' => $identifier, 'entitled' => $identifier !== '' && $this->entitlementService->can($identifier)], $paid ? '已支付' : '待支付');
    }

    /**
     * 插件市场 / 商业化下单后的统一分支（演示入账、线下待确认、在线待支付）
     *
     * @param ServiceResult $order createEntitlementOrder 返回值
     * @param list<string>  $steps
     * @return ServiceResult
     */
    public function interpretEntitlementOrderAfterCreate(ServiceResult $order, array &$steps): ServiceResult
    {
        if (!$order->isOk()) {
            return ServiceResult::fail($order->message());
        }

        $payload = $order->dataArray();
        $orderNo = (string) ($payload['order_no'] ?? '');
        if ($orderNo !== '') {
            $steps[] = '订单 ' . $orderNo . ' 已创建';
        }

        if (!empty($payload['demo'])) {
            $steps[] = '演示模式已自动支付';

            return ServiceResult::ok(['paid' => true, 'order_no' => $orderNo, 'type' => 'demo'], '支付已完成');
        }

        if (($payload['type'] ?? '') === 'manual') {
            return ServiceResult::ok(['paid' => false, 'pending_payment' => true, 'order_no' => $orderNo, 'type' => 'manual', 'steps' => $steps], '订单已创建，请在「插件商业化」确认入账后继续');
        }

        $hasOnlinePay = trim((string) ($payload['form_html'] ?? '')) !== ''
            || trim((string) ($payload['code_url'] ?? '')) !== ''
            || trim((string) ($payload['redirect'] ?? '')) !== '';
        if ($hasOnlinePay) {
            return ServiceResult::ok(['paid' => false, 'pending_payment' => true, 'order_no' => $orderNo, 'type' => (string) ($payload['type'] ?? ''), 'form_html' => (string) ($payload['form_html'] ?? ''), 'code_url' => (string) ($payload['code_url'] ?? ''), 'redirect' => (string) ($payload['redirect'] ?? ''), 'steps' => $steps], '请完成支付，支付成功后将自动开通授权');
        }

        return ServiceResult::ok(['paid' => true, 'order_no' => $orderNo], 'ok');
    }

    /**
     * @param array<string, mixed> $order
     * @return ServiceResult
     */
    public function fulfillEntitlement(array $order): ServiceResult
    {
        $payload    = json_decode((string) ($order['payload_json'] ?? ''), true);
        $payload    = is_array($payload) ? $payload : [];
        $identifier = trim((string) ($payload['plugin_identifier'] ?? ''));
        if ($identifier === '') {
            return ServiceResult::fail('订单缺少 plugin_identifier');
        }

        $orderNo      = (string) ($order['order_no'] ?? '');
        $days         = isset($payload['period_days']) ? (int) $payload['period_days'] : 0;
        $expire       = $this->resolveEntitlementExpireAt($identifier, $days);
        $license      = trim((string) ($payload['license_type'] ?? 'paid'));
        $billingType  = strtolower(trim((string) ($payload['billing_type'] ?? '')));
        $quotaTotal   = isset($payload['quota_total']) ? (int) $payload['quota_total'] : null;

        if ($license === '') {
            $license = 'paid';
        }

        $grantRef = $orderNo !== '' ? $orderNo : 'manual';
        if ($orderNo === '' || !$this->pivarkEditionService->allowsPluginEntitlementOrderGrant($grantRef, $identifier)) {
            return ServiceResult::fail('订单未支付或无权履约该插件授权');
        }

        if (!$this->entitlementService->grantWithSkuApply(
            $identifier,
            $expire,
            'order:' . $grantRef,
            $license,
            trim((string) ($payload['sku_id'] ?? ''))
        )) {
            return ServiceResult::fail('授权履约被拒绝，请检查站点版本或许可策略');
        }
        // grant() 的 cache flush 挂在 DbAfterCommit；支付 markPaid 事务未提交前 can() 会读到旧缓存。
        $this->entitlementQuery->flushEntitlementCache();

        $skuId = trim((string) ($payload['sku_id'] ?? ''));
        if ($skuId !== '') {
            $sku = $this->pluginSkuFulfillmentService->findSku($identifier, $skuId);
            if (is_array($sku)) {
                $this->pluginWalletService->applySkuPurchase($identifier, $sku, $orderNo);
            }
        }

        if (!$this->entitlementService->can($identifier)) {
            return ServiceResult::fail('授权履约失败，请检查版本策略或联系支持');
        }

        $this->auditLogService->operate('插件授权订单履约', 'admin.plugin', [
            'identifier'    => $identifier,
            'order_no'      => $orderNo,
            'license'       => $license,
            'sku_id'        => (string) ($payload['sku_id'] ?? ''),
            'billing_type'  => $billingType,
        ]);

        return ServiceResult::ok(null, '插件授权已开通');
    }

    /**
     * @return ServiceResult
     */
    public function confirmOrderPaid(string $orderNo): ServiceResult
    {
        $orderNo = trim($orderNo);
        $result  = $this->paymentOrderService->markPaid($orderNo, 'manual:' . $orderNo, ['manual' => true]);
        if ($result->isOk()) {
            $this->auditLogService->operate('手动确认插件订单入账', 'admin.plugin', ['order_no' => $orderNo]);
        }

        return $result;
    }

    /**
     * 误确认入账回滚：撤销履约并将订单标为 closed（不走支付网关退款）。
     *
     * @return ServiceResult
     */
    public function rollbackConfirmedOrder(string $orderNo, string $reason = '', int $adminId = 0): ServiceResult
    {
        $orderNo = trim($orderNo);
        $order   = $this->findPluginEntitlementOrder($orderNo, PaymentOrderService::STATUS_PAID);
        if (!$order->isOk()) {
            return $order;
        }
        $orderRow = $order->dataArray()['order'] ?? [];
        if (!is_array($orderRow)) {
            return ServiceResult::fail('订单数据无效');
        }

        $fulfill = $this->revokeFulfillmentForOrder($orderRow, $orderNo);
        if (!$fulfill->isOk()) {
            return $fulfill;
        }

        $fulfillData = $fulfill->dataArray();
        $now     = AppTime::now();
        $payload = is_array($fulfillData['payload'] ?? null) ? $fulfillData['payload'] : [];
        $payload['_rollback'] = [
            'reason'             => mb_substr(trim($reason) !== '' ? trim($reason) : '误确认入账', 0, 500),
            'admin_id'           => max(0, $adminId),
            'at'                 => $now,
            'revoked'            => (bool) ($fulfillData['revoked'] ?? false),
            'wallet_rolled_back' => (int) ($fulfillData['wallet_rolled_back'] ?? 0),
        ];

        Db::transaction(static function () use ($orderNo, $payload, $now): void {
            PaymentOrder::where('order_no', $orderNo)
                ->where('status', PaymentOrderService::STATUS_PAID)
                ->update([
                    'status'       => PaymentOrderService::STATUS_CLOSED,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'updated_at'   => $now,
                ]);
        });

        return $this->finishOrderReversalAudit(
            '回滚插件授权订单入账',
            $orderNo,
            (string) ($fulfillData['identifier'] ?? ''),
            (bool) ($fulfillData['revoked'] ?? false),
            (int) ($fulfillData['wallet_rolled_back'] ?? 0),
            (string) $payload['_rollback']['reason'],
            '已回滚入账，订单已关闭'
        );
    }

    /**
     * 退款并撤销授权：先 markRefunded（可选走网关），再撤销本单履约。
     *
     * @return ServiceResult
     */
    public function refundEntitlementOrder(
        string $orderNo,
        string $reason = '',
        int $adminId = 0,
        bool $viaGateway = false
    ): ServiceResult {
        $orderNo = trim($orderNo);
        $order   = $this->findPluginEntitlementOrder($orderNo, PaymentOrderService::STATUS_PAID);
        if (!$order->isOk()) {
            return $order;
        }
        $orderRow = $order->dataArray()['order'] ?? [];
        if (!is_array($orderRow)) {
            return ServiceResult::fail('订单数据无效');
        }

        $reason = trim($reason) !== '' ? trim($reason) : '插件授权退款';
        $refund = $this->paymentOrderService->markRefunded($orderNo, $reason, $adminId, $viaGateway);
        if (!$refund->isOk()) {
            return ServiceResult::fail($refund->message());
        }

        $orderRow = $this->paymentOrderService->findByOrderNo($orderNo);
        if (!is_array($orderRow)) {
            return ServiceResult::fail('退款后订单不存在');
        }

        $fulfill = $this->revokeFulfillmentForOrder($orderRow, $orderNo);
        if (!$fulfill->isOk()) {
            return $fulfill;
        }

        $fulfillData = $fulfill->dataArray();
        $now     = AppTime::now();
        $payload = is_array($fulfillData['payload'] ?? null) ? $fulfillData['payload'] : [];
        $payload['_entitlement_revoke'] = [
            'at'                 => $now,
            'admin_id'           => max(0, $adminId),
            'revoked'            => (bool) ($fulfillData['revoked'] ?? false),
            'wallet_rolled_back' => (int) ($fulfillData['wallet_rolled_back'] ?? 0),
        ];
        PaymentOrder::where('order_no', $orderNo)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at'   => $now,
        ]);

        $audit = $this->finishOrderReversalAudit(
            '插件授权订单退款并撤销',
            $orderNo,
            (string) ($fulfillData['identifier'] ?? ''),
            (bool) ($fulfillData['revoked'] ?? false),
            (int) ($fulfillData['wallet_rolled_back'] ?? 0),
            $reason,
            (string) $refund->message()
        );
        $refundData = $refund->dataArray();

        return ServiceResult::ok(
            array_merge($audit->dataArray(), ['gateway' => (int) ($refundData['gateway'] ?? 0)]),
            $audit->message()
        );
    }

    /**
     * @return ServiceResult
     */
    private function findPluginEntitlementOrder(string $orderNo, string $requiredStatus): ServiceResult
    {
        if ($orderNo === '') {
            return ServiceResult::fail('订单号无效');
        }

        $order = $this->paymentOrderService->findByOrderNo($orderNo);
        if ($order === null) {
            return ServiceResult::fail('订单不存在');
        }
        if ((string) ($order['scene'] ?? '') !== PaymentOrderService::SCENE_PLUGIN_ENTITLEMENT) {
            return ServiceResult::fail('仅插件授权订单可操作');
        }
        if ((string) ($order['status'] ?? '') !== $requiredStatus) {
            return ServiceResult::fail('订单状态不允许此操作');
        }

        return ServiceResult::ok(['order' => $order], 'ok');
    }

    /**
     * @param array<string, mixed> $order
     * @return ServiceResult
     */
    private function revokeFulfillmentForOrder(array $order, string $orderNo): ServiceResult
    {
        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $identifier = $this->entitlementService->resolveSlugIdentifier(
            trim((string) ($payload['plugin_identifier'] ?? ''))
        );
        if ($identifier === '') {
            return ServiceResult::fail('订单缺少 plugin_identifier');
        }

        $grantRef = 'order:' . $orderNo;
        $summary  = $this->entitlementService->summary($identifier);
        $revoked  = false;
        if ($summary['granted_by'] === $grantRef) {
            $this->entitlementService->revoke($identifier);
            $revoked = true;
        }

        $walletRb = $this->pluginWalletService->rollbackCreditsByRef($identifier, $orderNo);
        if ($revoked) {
            $this->pluginWalletService->syncOnEntitlementLost($identifier);
        }

        return ServiceResult::ok(['identifier' => $identifier, 'revoked' => $revoked, 'wallet_rolled_back' => $walletRb, 'payload' => $payload], 'ok');
    }

    /**
     * @return ServiceResult
     */
    private function finishOrderReversalAudit(
        string $action,
        string $orderNo,
        string $identifier,
        bool $revoked,
        int $walletRb,
        string $reason,
        string $prefix
    ): ServiceResult {
        $this->auditLogService->operate($action, 'admin.plugin', [
            'order_no'           => $orderNo,
            'identifier'         => $identifier,
            'revoked'            => $revoked,
            'wallet_rolled_back' => $walletRb,
            'reason'             => $reason,
        ]);

        $msg = $prefix;
        if ($revoked) {
            $msg .= '，授权已撤销';
        }
        if ($walletRb > 0) {
            $msg .= '，钱包已回退 ' . $walletRb . ' 次';
        }
        if (!$revoked && $walletRb <= 0) {
            $msg .= '（本订单未检测到可撤销的授权或钱包变更）';
        }

        return ServiceResult::ok(['revoked' => $revoked, 'wallet_rolled_back' => $walletRb], $msg);
    }

    /** 续费在尚未到期日上叠加；已过期则从当前时间起算。 */
    private function resolveEntitlementExpireAt(string $identifier, int $periodDays): ?string
    {
        if ($periodDays <= 0) {
            return null;
        }

        $identifier = $this->entitlementService->resolveSlugIdentifier($identifier);
        $baseTs     = time();
        $row        = $this->entitlementQuery->rowByPluginIdentifier($identifier);
        if ($row !== null && (string) ($row['status'] ?? '') === 'active') {
            $prev = trim((string) ($row['expire_at'] ?? ''));
            if ($prev !== '') {
                $prevTs = strtotime($prev);
                if ($prevTs !== false && $prevTs > $baseTs) {
                    $baseTs = $prevTs;
                }
            }
        }

        return AppTime::format('Y-m-d H:i:s', $baseTs + $periodDays * 86400);
    }
}
