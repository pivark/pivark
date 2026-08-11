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
use app\common\support\AdminBatchSupport;
use app\common\support\ParseIds;
use think\facade\Request;
use think\Response;

/** 后台 CSV 导出管道：packCsv + 审计 + 标准响应头（业务仍负责查行） */
final class AdminDataExportSupport
{

    public function __construct(
        private readonly ExportImportService $exportImportService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * 从 GET 解析导出 ids（ids=1,2,3 或 ids[]）
     *
     * @return list<int>
     */
    public function parseExportIdsFromRequest(): array
    {
        $raw = Request::get('ids/a', Request::get('ids', ''));

        return AdminBatchSupport::normalizeIds(ParseIds::fromMixed($raw));
    }

    public function resolveExportScopeFromRequest(): string
    {
        $scope = trim((string) Request::get('export_scope', ''));
        if ($scope !== '') {
            return $scope;
        }
        if ($this->parseExportIdsFromRequest() !== []) {
            return 'selected';
        }

        return 'all';
    }

    public function assertExportAllowed(string $profile, string $scope, int $rowCount): ?Response
    {
        $meta = app(DataExportRegistry::class)->find($profile);
        if ($meta === null) {
            return null;
        }
        $modes = $meta['modes'] ?? [];
        if (is_array($modes) && $modes !== [] && !in_array($scope, $modes, true)) {
            return AdminApiResponse::fail('当前导出方式不可用');
        }
        $max = (int) ($meta['max_rows'] ?? 0);
        if ($max > 0 && $rowCount > $max) {
            return AdminApiResponse::fail('导出行数超过上限（' . $max . ' 条）');
        }

        return null;
    }

    /**
     * @param list<string>       $headers
     * @param list<list<mixed>>  $rows
     */
    public function respondCsv(
        string $basename,
        array $headers,
        array $rows,
        string $auditModule,
        array $auditExtra = [],
        ?string $profile = null,
    ): Response {
        $auditExtra = array_merge(['row_count' => count($rows)], $auditExtra);
        if ($profile !== null) {
            $scope = (string) ($auditExtra['export_scope'] ?? 'all');
            $blocked = $this->assertExportAllowed($profile, $scope, count($rows));
            if ($blocked !== null) {
                return $blocked;
            }
        }
        $pack = $this->exportImportService->packCsv($basename, $headers, $rows);

        return $this->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            $auditModule,
            $auditExtra,
            '导出 CSV',
            $profile,
        );
    }

    /**
     * @param array{filename:string,content:string} $pack
     * @param array<string, mixed>                  $auditExtra
     */
    public function respondPack(
        array $pack,
        string $contentType,
        string $auditModule,
        array $auditExtra = [],
        string $auditAction = '导出 CSV',
        ?string $profile = null,
    ): Response {
        if ($profile !== null) {
            $scope = (string) ($auditExtra['export_scope'] ?? $this->resolveExportScopeFromRequest());
            $rowCount = (int) ($auditExtra['row_count'] ?? $this->estimatePackRowCount($pack['content'] ?? '', $contentType));
            $auditExtra['row_count'] = $rowCount;
            $blocked = $this->assertExportAllowed($profile, $scope, $rowCount);
            if ($blocked !== null) {
                return $blocked;
            }
        }

        $this->auditLogService->operate($auditAction, $auditModule, $auditExtra);

        // ThinkPHP: create($data, $type, $code) — 第 2 参是 type 字符串，不是 HTTP status
        return Response::create($pack['content'], 'html', 200)->header([
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . ($pack['filename'] ?? 'export.dat') . '"',
        ]);
    }

    private function estimatePackRowCount(string $content, string $contentType): int
    {
        $trim = trim($content);
        if ($trim === '') {
            return 0;
        }
        if (str_contains($contentType, 'json')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return count($decoded);
            }

            return 0;
        }
        $lines = preg_split('/\r\n|\r|\n/', $trim);
        if (!is_array($lines)) {
            return 0;
        }

        return max(0, count($lines) - 1);
    }
}
