<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\export;

use app\common\service\audit\AuditLogService;
use app\common\support\AdminApiResponse;
use think\facade\Request;
use think\Response;

/** 后台 CSV/JSON 导入管道：读上传 + 审计 + profile 行数上限 */
final class AdminDataImportSupport
{

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** 读取 multipart 上传正文；失败返回 JSON Response */
    public function readUpload(string $field = 'file'): string|Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $file = Request::file($field);
        if (!$file) {
            return AdminApiResponse::fail('请上传文件');
        }

        return (string) file_get_contents($file->getPathname());
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function auditImport(
        string $auditModule,
        array $extra = [],
        string $action = '导入',
    ): void {
        $this->auditLogService->operate($action, $auditModule, $extra);
    }

    public function assertImportAllowed(string $profile, int $rowCount): ?Response
    {
        $meta = app(DataExportRegistry::class)->find($profile);
        if ($meta === null) {
            return null;
        }
        $max = (int) ($meta['max_rows'] ?? 0);
        if ($max > 0 && $rowCount > $max) {
            return AdminApiResponse::fail('导入行数超过上限（' . $max . ' 条）');
        }

        return null;
    }

    /** CSV 正文估算数据行数（不含表头） */
    public function estimateCsvRowCount(string $text): int
    {
        $trim = trim($text);
        if ($trim === '') {
            return 0;
        }
        $lines = preg_split('/\r\n|\r|\n/', $trim);
        if (!is_array($lines)) {
            return 0;
        }

        return max(0, count($lines) - 1);
    }
}
