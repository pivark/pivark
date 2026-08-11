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
use think\facade\Request;
use think\facade\Session;

/** 后台数据导入/导出访问审计（无权限尝试 → 操作日志，供管理员查阅） */
final class AdminDataAccessAuditService
{

    private const DEDUP_SECONDS = 60;

    /** @var array<string, int> */
    private static array $recentDenied = [];

    /** @var list<string> */
    private const DATA_ACCESS_ACTIONS = [
        'export',
        'import',
        'exportjson',
        'importjson',
        'exportorderscsv',
        'exportasyncstart',
        'exportasyncstep',
        'exportasyncdownload',
        'exportlist',
        'exportfile',
        'exportredownload',
    ];

    public function isDataAccessAction(string $action): bool
    {
        $action = strtolower(trim($action));
        if ($action === '') {
            return false;
        }
        if (in_array($action, self::DATA_ACCESS_ACTIONS, true)) {
            return true;
        }

        return str_starts_with($action, 'export') || str_starts_with($action, 'import');
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function logDeniedAccess(
        string $requiredPermission,
        string $controller,
        string $action,
        array $extra = [],
    ): void {
        if ($this->shouldSkipDuplicate($requiredPermission, 'middleware:' . $action)) {
            return;
        }

        $kind = str_contains(strtolower($action), 'import') ? '导入' : '导出';
        app(AuditLogService::class)->operate(
            '无权限尝试' . $kind,
            $requiredPermission !== '' ? $requiredPermission : 'admin.data_access',
            array_merge([
                'controller'          => $controller,
                'action'              => $action,
                'required_permission' => $requiredPermission,
                'source'              => 'middleware',
                'page_label'          => $this->pageLabel($requiredPermission),
            ], $this->sanitizeParams($extra)),
            false,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function logClientDenied(
        string $permission,
        string $kind,
        string $path,
        array $extra = [],
    ): void {
        if ($this->shouldSkipDuplicate($permission, 'client:' . strtolower(trim($kind)))) {
            return;
        }

        $kindNorm = strtolower(trim($kind));
        $label    = $kindNorm === 'import' ? '导入' : '导出';
        $module   = trim($permission) !== '' ? trim($permission) : 'admin.data_access';

        app(AuditLogService::class)->operate(
            '无权限尝试' . $label,
            $module,
            array_merge([
                'path'                => trim($path),
                'required_permission' => $module,
                'source'              => 'client',
                'page_label'          => $this->pageLabel($module),
            ], $this->sanitizeParams($extra)),
            false,
        );
    }

    private function pageLabel(string $permission): string
    {
        $label = app(DataExportExtensionRegistry::class)->labelForPermission($permission);

        return $label !== '' ? $label : $permission;
    }

    private function shouldSkipDuplicate(string $permission, string $suffix): bool
    {
        $admin = Session::get('admin_user');
        $uid   = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        $key   = $uid . ':' . trim($permission) . ':' . $suffix;
        $now   = time();
        if (isset(self::$recentDenied[$key]) && ($now - self::$recentDenied[$key]) < self::DEDUP_SECONDS) {
            return true;
        }
        self::$recentDenied[$key] = $now;

        return false;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, ['password', 'token', 'secret', 'file'], true)) {
                continue;
            }
            if ($k === 'ids' || $k === 'query') {
                if (is_array($value)) {
                    $ids = array_values(array_filter(array_map('intval', (array) ($value['ids'] ?? $value))));
                    if ($ids !== []) {
                        $out['ids_count'] = count($ids);
                    }
                } elseif (is_string($value) && $value !== '') {
                    $parts = array_filter(explode(',', $value));
                    if ($parts !== []) {
                        $out['ids_count'] = count($parts);
                    }
                }
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        $idsRaw = Request::get('ids', '');
        if (!isset($out['ids_count']) && is_string($idsRaw) && $idsRaw !== '') {
            $out['ids_count'] = count(array_filter(explode(',', $idsRaw)));
        }

        return $out;
    }
}
