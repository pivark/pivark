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
use app\common\service\infra\DataRetentionConfigService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use think\facade\Request;

/** 运维工具 — 数据保留策略（cron 清理/归档天数） */
class DataRetention extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly DataRetentionConfigService $dataRetentionConfig,
    ) {
        parent::__construct($csrf);
    }

    /** GET /admin/data_retention/index — 保留策略元数据（SPA） */
    public function index()
    {
        return AdminApiResponse::fromResult(
            ServiceResult::ok($this->dataRetentionConfig->metaForAdmin())
        );
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin(
            $this->dataRetentionConfig->saveAdmin(Request::post())
        );
    }
}
