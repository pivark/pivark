<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\ServiceResult;

/** 限流策略后台读写 + 清计数 */
final class RateLimitConfigService
{
    public function __construct(
        private readonly RateLimitRegistry $registry,
        private readonly RateLimitGateway $gateway,
    ) {
    }

    /** @return array<string, mixed> */
    public function metaForAdmin(): array
    {
        return $this->registry->metaForAdmin();
    }

    /**
     * @param array<string, mixed> $input policyId => row
     * @return ServiceResult<null>
     */
    public function saveAdmin(array $input): ServiceResult
    {
        if ($input === []) {
            return ServiceResult::fail('未提交任何限流策略');
        }
        $built = $this->registry->buildOverridesFromAdmin($input);
        $overrides = $built['overrides'];
        $applied = $built['applied'];
        if ($applied < 1) {
            return ServiceResult::fail('没有可保存的策略项');
        }
        $this->registry->saveOverrides($overrides);

        return ServiceResult::ok(['applied' => $applied], '限流策略已保存');
    }

    public function bustAll(): ServiceResult
    {
        $this->gateway->clearAll();

        return ServiceResult::ok(null, '限流计数已清空');
    }
}
