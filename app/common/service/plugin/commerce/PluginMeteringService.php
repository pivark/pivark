<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\service\plugin\registry\PluginCapabilityService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\support\ServiceResult;

final class PluginMeteringService
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
        private readonly PluginCapabilityService $pluginCapabilityService,
        private readonly PluginMarketShelfDirectory $pluginMarketRemoteCatalog,
    ) {
    }

    public function canConsume(string $identifier, string $action): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $action     = strtolower(trim($action));
        if ($identifier === '' || $action === '') {
            return ServiceResult::fail('参数无效');
        }

        if (!$this->entitlementService->can($identifier)) {
            return ServiceResult::fail('插件未授权或已过期');
        }

        if (!$this->pluginCapabilityService->canFeature($identifier, $action)) {
            return ServiceResult::fail('当前授权不包含该能力：' . $action);
        }

        $billable = $this->billableActions($identifier);
        if ($billable === [] || !in_array($action, $billable, true)) {
            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        if ($this->pluginWallet()->isUnlimited($identifier)) {
            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        $remaining = $this->pluginWallet()->remaining($identifier);
        if ($remaining === null || $remaining > 0) {
            return ServiceResult::ok(['remaining' => $remaining], 'ok');
        }

        return ServiceResult::fail('生成次数已用尽，请购买套餐或续费', data: ['remaining' => 0]);
    }

    public function consume(string $identifier, string $action, int $units = 1, string $ref = ''): ServiceResult
    {
        $check = $this->canConsume($identifier, $action);
        if (!$check->isOk()) {
            return $check;
        }

        $billable = $this->billableActions($identifier);
        if ($billable === [] || !in_array(strtolower(trim($action)), $billable, true)) {
            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        if ($this->pluginWallet()->isUnlimited($identifier)) {
            $this->pluginWallet()->debit($identifier, $units, 'consume', $ref);

            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        $result = $this->pluginWallet()->debit($identifier, $units, 'consume', $ref);
        if (!$result->isOk()) {
            $remaining = $result->dataArray()['remaining'] ?? 0;

            return ServiceResult::fail((string) ($result->message() ?: '扣次失败'), data: ['remaining' => $remaining]);
        }

        return ServiceResult::ok(
            ['remaining' => $result->dataArray()['remaining'] ?? null],
            'ok',
        );
    }

    /**
     * 生成失败/超时补偿：退回已扣份数（与 consume 对称，记 generation_refund）
     */
    public function refund(string $identifier, string $action, int $units = 1, string $ref = ''): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $units <= 0) {
            return ServiceResult::fail('参数无效');
        }

        $billable = $this->billableActions($identifier);
        if ($billable === []) {
            return ServiceResult::ok(['remaining' => null], 'ok');
        }

        $result = $this->pluginWallet()->credit(
            $identifier,
            $units,
            'generation_refund',
            $ref !== '' ? $ref : $action,
        );
        if (!$result->isOk()) {
            return ServiceResult::fail(
                (string) ($result->message() ?: '退回失败'),
                data: ['remaining' => $result->dataArray()['remaining'] ?? null],
            );
        }

        return ServiceResult::ok(
            ['remaining' => $result->dataArray()['remaining'] ?? null],
            'ok',
        );
    }

    /**
     * @return array{remaining:?int,unlimited:bool,metered:bool}
     */
    public function summary(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->billableActions($identifier) === []) {
            return ['remaining' => null, 'unlimited' => false, 'metered' => false];
        }

        return [
            'remaining' => $this->pluginWallet()->remaining($identifier),
            'unlimited' => $this->pluginWallet()->isUnlimited($identifier),
            'metered'   => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function billableActions(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $row        = $this->pluginMarketRemoteCatalog->indexByIdentifier()[$identifier] ?? null;
        if (is_array($row) && is_array($row['meter']['billable_actions'] ?? null)) {
            return array_values(array_map('strval', $row['meter']['billable_actions']));
        }
        $manifest = $this->pluginService()->readManifest($identifier);
        $commercial = is_array($manifest['commercial'] ?? null) ? $manifest['commercial'] : [];
        if (is_array($commercial['meter']['billable_actions'] ?? null)) {
            return array_values(array_map('strval', $commercial['meter']['billable_actions']));
        }

        return [];
    }

    private function pluginWallet(): PluginWalletService
    {
        return app(PluginWalletService::class);
    }

    private function pluginService(): PluginService
    {
        return app(PluginService::class);
    }
}
