<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\service\infra\RateLimitConfigService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use think\facade\Request;

/** 运维工具 — 限流策略（滑动窗口 / 冷却） */
class RateLimit extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly RateLimitConfigService $rateLimitConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        return AdminApiResponse::fromResult(
            ServiceResult::ok($this->rateLimitConfig->metaForAdmin())
        );
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $input = [];
        $json = trim((string) Request::post('policies_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return AdminApiResponse::fail('限流策略数据无效');
            }
            $input = $decoded;
        } else {
            // 兼容扁平 POST（不含 csrf / policies_json）
            foreach (Request::post() as $key => $value) {
                if (!is_string($key) || $key === '' || $key === 'policies_json') {
                    continue;
                }
                if (str_starts_with($key, '_')) {
                    continue;
                }
                if (is_array($value)) {
                    $input[$key] = $value;
                }
            }
        }

        return AdminApiResponse::admin(
            $this->rateLimitConfig->saveAdmin($input)
        );
    }

    public function bust()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->rateLimitConfig->bustAll());
    }
}
