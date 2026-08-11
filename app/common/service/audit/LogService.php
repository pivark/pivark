<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\audit;

use app\common\service\user\PermissionLabelService;
use app\common\support\AppTime;
use app\common\support\QueryLimit;


use app\common\service\export\ExportImportService;
use app\common\model\AuditLog;
use app\common\service\content\ContentSearchService;
use app\common\support\CursorPaginator;
use app\common\support\ParseIds;

/** 操作日志查询（pv_logs） */
class LogService
{

    public function __construct(
        private readonly ExportImportService $exportImport,
        private readonly ContentSearchService $contentSearch,
    ) {
    }

    /**
     * @param array<string, mixed> $params page, limit, keyword, module, type, result, date_from, date_to
     * @return array{list:list<array>,total:int,page:int,limit:int}
     */
    public function listAdmin(array $params = []): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(max((int) ($params['limit'] ?? 20), 1), 100);

        $query = AuditLog::order('id', 'desc');
        $this->applyAdminFilters($query, $params);

        $cursorId = (int) ($params['cursor_id'] ?? 0);
        $keyword  = trim((string) ($params['keyword'] ?? ''));
        $module   = trim((string) ($params['module'] ?? ''));
        $modules  = $params['modules'] ?? null;
        $username = trim((string) ($params['username'] ?? ''));
        $type     = trim((string) ($params['type'] ?? ''));
        $allowCursor = $keyword === '' && $module === '' && $type === ''
            && (!is_array($modules) || $modules === [])
            && $username === ''
            && ($params['result'] ?? '') === '' && ($params['date_from'] ?? '') === ''
            && ($params['date_to'] ?? '') === '';

        $pageResult = CursorPaginator::paginateById($query, $limit, $page, $cursorId, 'id', $allowCursor);
        $rows  = $pageResult['rows'];
        $total = $pageResult['total'];
        $nextCursor = $pageResult['next_cursor_id'];
        $list  = [];
        foreach ($rows as $row) {
            $list[] = $this->formatRow($row);
        }

        return [
            'list'             => $list,
            'total'            => $total,
            'page'             => $page,
            'limit'            => $limit,
            'next_cursor_id'   => $nextCursor,
        ];
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $id
     */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = AuditLog::where('id', $id)->find()?->toArray();

        return $row ? $this->formatRow($row, true) : null;
    }

    /**
     * @return list<string>
     */
    public function listModules(): array
    {
        $modules = AuditLog::distinct(true)->column('module');
        sort($modules);

        return array_values(array_filter($modules, static fn ($m) => $m !== '' && $m !== null));
    }

    /**
     * @param list<int> $ids
     */
    public function deleteByIds(array $ids): int
    {
        $ids = ParseIds::fromMixed($ids);
        if ($ids === []) {
            return 0;
        }

        return (int) AuditLog::whereIn('id', $ids)->delete();
    }

    public function deleteOlderThanDays(int $days): int
    {
        $days  = max(7, min(3650, $days));
        $since = AppTime::format('Y-m-d H:i:s', time() - $days * 86400);

        return (int) AuditLog::where('created_at', '<', $since)->delete();
    }

    public function deleteAll(): int
    {
        return (int) AuditLog::where('id', '>', 0)->delete();
    }

    public function countAll(): int
    {
        return (int) AuditLog::count();
    }

    /**
     * 按当前筛选条件导出 CSV（上限 50000 行）
     *
     * @param array<string, mixed> $params 与 listAdmin 相同
     * @return array{filename:string,content:string}
     */
    public function exportAdminCsv(array $params = []): array
    {
        $headers = ['ID', '用户', '行为', '模块', '类型', '结果', 'IP', '时间'];
        $rows    = [];
        $cursorId = 0;
        $limit    = QueryLimit::LOG_EXPORT_PAGE;
        $maxRows  = 50000;

        while (count($rows) < $maxRows) {
            $batch          = $params;
            $batch['page']  = 1;
            $batch['limit'] = min($limit, $maxRows - count($rows));
            if ($cursorId > 0) {
                $batch['cursor_id'] = $cursorId;
            }
            $result = $this->listAdmin($batch);
            foreach ($result['list'] as $row) {
                $rows[] = [
                    $row['id'] ?? '',
                    $row['username'] ?? '',
                    $row['action'] ?? '',
                    $row['module_text'] ?? $row['module'] ?? '',
                    $row['type_text'] ?? $row['type'] ?? '',
                    $row['result_text'] ?? '',
                    $row['ip'] ?? '',
                    $row['created_at'] ?? '',
                ];
            }
            $next = (int) ($result['next_cursor_id'] ?? 0);
            if ($next < 1 || ($result['list'] ?? []) === []) {
                break;
            }
            $cursorId = $next;
        }

        return $this->exportImport->packCsv('admin_logs', $headers, $rows);
    }

    /**
     * @param \think\db\BaseQuery|\think\db\Query $query
     */
    private function applyAdminFilters($query, array $params): void
    {
        $keyword  = trim((string) ($params['keyword'] ?? ''));
        $module   = trim((string) ($params['module'] ?? ''));
        $modules  = $params['modules'] ?? null;
        $username = trim((string) ($params['username'] ?? ''));
        $type     = trim((string) ($params['type'] ?? ''));
        $result   = ($params['result'] ?? '') !== '' ? (int) $params['result'] : null;
        $dateFrom = trim((string) ($params['date_from'] ?? ''));
        $dateTo   = trim((string) ($params['date_to'] ?? ''));

        if ($keyword !== '') {
            $query->whereLike('username|action|module|ip', $this->contentSearch->likePattern($keyword));
        }
        if (is_array($modules) && $modules !== []) {
            $query->whereIn('module', array_values(array_filter(array_map('strval', $modules))));
        } elseif ($module !== '') {
            $query->where('module', $module);
        }
        if ($username !== '') {
            $query->where('username', $username);
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($result !== null) {
            $query->where('result', $result);
        }
        if ($dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }
    }

    /** @return list<array{value:string,label:string}> */
    public function typeOptions(): array
    {
        $out = [];
        foreach (['operate', 'login', 'security'] as $type) {
            $out[] = ['value' => $type, 'label' => $this->typeLabel($type)];
        }

        return $out;
    }

    /** @return list<array{value:string,label:string}> */
    public function modulesForAdmin(): array
    {
        $out = [];
        foreach ($this->listModules() as $module) {
            $out[] = ['value' => $module, 'label' => $this->moduleLabel($module)];
        }

        return $out;
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'operate'  => '操作',
            'login'    => '登录',
            'security' => '安全',
            default    => $type !== '' ? $type : '—',
        };
    }

    public function moduleLabel(string $module): string
    {
        static $map = [
            'admin.login'          => '后台登录',
            'admin.dashboard'      => '控制台',
            'admin.user'           => '用户管理',
            'admin.role'           => '角色管理',
            'admin.config'         => '系统配置',
            'admin.menu'           => '菜单管理',
            'admin.document'       => '文档管理',
            'admin.tag'            => '标签管理',
            'admin.log'            => '操作日志',
            'admin.media'          => '素材库',
            'admin.plugin'         => '插件管理',
            'admin.backup'         => '数据备份',
            'admin.sql_console'    => 'SQL 控制台',
            'admin.cron'           => '定时任务',
            'admin.member'         => '会员管理',
            'admin.member.config'  => '会员功能配置',
            'admin.member.points'  => '积分管理',
            'admin.member.level'   => '会员等级',
            'admin.seo.url'        => 'SEO 链接',
            'admin.seo.robots'     => 'Robots',
            'admin.seo.sitemap'    => 'Sitemap',
            'member.password_reset'=> '会员找回密码',
            'member.username_remind'=> '会员忘记登录名',
            'license.platform'     => '授权平台',
            'license.platform.portal' => '授权平台 · 门户',
        ];

        if (isset($map[$module])) {
            return $map[$module];
        }
        if (str_contains($module, '.') && str_starts_with($module, 'admin.')) {
            $label = app(PermissionLabelService::class)->labelForCode($module);
            if ($label !== '' && $label !== $module) {
                return $label;
            }
        }
        if (str_starts_with($module, 'admin.seo.')) {
            return 'SEO · ' . substr($module, 9);
        }
        if (str_starts_with($module, 'admin.member.')) {
            return '会员 · ' . substr($module, 13);
        }
        if (str_starts_with($module, 'plugin.')) {
            return '插件 · ' . substr($module, 7);
        }
        if (str_contains($module, '.')) {
            $parts = explode('.', $module);
            $last  = end($parts) ?: $module;

            return '后台 · ' . $last;
        }

        return $module !== '' ? $module : '—';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row, bool $full = false): array
    {
        $params = null;
        if (!empty($row['request_params'])) {
            $decoded = json_decode((string) $row['request_params'], true);
            $params  = is_array($decoded) ? $decoded : (string) $row['request_params'];
        }

        $module = (string) ($row['module'] ?? '');
        $type   = (string) ($row['type'] ?? '');

        $item = [
            'id'             => (int) $row['id'],
            'user_id'        => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'username'       => (string) ($row['username'] ?? ''),
            'type'           => $type,
            'type_text'      => $this->typeLabel($type),
            'action'         => (string) ($row['action'] ?? ''),
            'module'         => $module,
            'module_text'    => $this->moduleLabel($module),
            'request_method' => (string) ($row['request_method'] ?? ''),
            'request_url'    => (string) ($row['request_url'] ?? ''),
            'ip'             => (string) ($row['ip'] ?? ''),
            'result'         => (int) ($row['result'] ?? 1),
            'result_text'    => (int) ($row['result'] ?? 1) === 1 ? '成功' : '失败',
            'created_at'     => (string) ($row['created_at'] ?? ''),
        ];

        if ($full) {
            $item['request_params'] = $params;
            $item['user_agent']     = (string) ($row['user_agent'] ?? '');
            $item['duration']       = isset($row['duration']) ? (int) $row['duration'] : null;
        }

        return $item;
    }
}
