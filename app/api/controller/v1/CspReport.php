<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\support\OpsLog;
use app\common\support\ApiResponse;
use think\response\Json;

/** POST /api/v1/system/csp-report — CSP Report-Only 违规采集 */
class CspReport
{
    public function store(): Json
    {
        $raw = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = ['raw' => mb_substr($raw, 0, 2000)];
        }

        OpsLog::businessWarning('csp_report_only_violation', [
            'document_uri' => (string) ($payload['document-uri'] ?? ($payload['documentURI'] ?? '')),
            'violated'     => (string) ($payload['violated-directive'] ?? ($payload['effectiveDirective'] ?? '')),
            'blocked'      => (string) ($payload['blocked-uri'] ?? ($payload['blockedURL'] ?? '')),
            'sample'       => mb_substr($raw, 0, 4000),
        ]);

        return ApiResponse::success(['accepted' => true]);
    }
}
