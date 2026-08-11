<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\service\release\SiteFingerprintPublicService;
use think\response\Json;

/** GET /api/v1/site/fingerprint — 公开只读指纹（无敏感字段；供官网运营探活） */
class SiteFingerprint
{
    public function index(): Json
    {
        return json([
            'data' => app(SiteFingerprintPublicService::class)->snapshot(),
        ]);
    }
}
