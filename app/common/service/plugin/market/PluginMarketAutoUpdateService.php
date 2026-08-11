<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\market\PluginMarketShelfDirectory;
use app\common\model\Plugin;
use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;

/** Cron / 后台：检查远程 catalog 更新并按策略自动升级已装插件 */
final class PluginMarketAutoUpdateService
{
    public function __construct(
        private readonly PluginMarketShelfDirectory $remoteCatalog,
        private readonly PluginService $pluginService,
        private readonly EntitlementService $entitlement,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @return array{checked:int,updates:int,applied:int,failed:list<string>,skipped:list<string>}
     */
    public function run(bool $apply = false): array
    {
        $apply = $apply || (bool) config('plugin.market.auto_update_apply', false);
        $stats = [
            'checked' => 0,
            'updates' => 0,
            'applied' => 0,
            'failed'  => [],
            'skipped' => [],
        ];

        $pending = $this->remoteCatalog->checkUpdates();
        $stats['checked'] = count($this->pluginService->discover());
        $stats['updates'] = count($pending);

        foreach ($pending as $row) {
            $id = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($id === '') {
                continue;
            }

            if (!$this->entitlement->can($id)) {
                $stats['skipped'][] = $id . ':无有效授权';
                continue;
            }

            $installed = Plugin::where('identifier', $id)->where('installed', 1)->find();
            if ($installed === null) {
                $stats['skipped'][] = $id . ':未安装';
                continue;
            }

            if (!$apply) {
                continue;
            }

            // 升级真源在 Ladder::apply（内含 package_url / 阶梯）；禁预算未用变量（J85）
            $ladder = app(PluginUpgradeLadderService::class);
            if (($ladder->plan($id)['path'] ?? []) === []
                && trim((string) ($row['package_url'] ?? '')) === '') {
                $stats['failed'][] = $id . ':无升级路径';
                continue;
            }

            $result = $ladder->apply($id);
            if (!$result->isOk()) {
                $stats['failed'][] = $id . ':' . $result->message();
                continue;
            }

            ++$stats['applied'];
            $this->auditLogService->operate('市场自动更新插件', 'admin.plugin', [
                'identifier'     => $id,
                'remote_version' => (string) ($row['remote_version'] ?? ''),
                'ladder'         => $result->dataArray()['applied'] ?? [],
            ]);
        }

        return $stats;
    }

    /**
     * @return list<array{identifier:string,local_version:string,remote_version:string,package_url:string}>
     */
    public function listPending(): array
    {
        return $this->remoteCatalog->checkUpdates();
    }

    /**
     * @return ServiceResult
     */
    public function applyAll(): ServiceResult
    {
        $stats = $this->run(true);
        if ($stats['failed'] !== []) {
            return ServiceResult::fail(
                '部分插件自动更新失败：' . implode('; ', $stats['failed']),
                data: $stats
            );
        }

        return ServiceResult::ok($stats, sprintf(
            '已检查 %d 个插件，%d 个待更新，已应用 %d 个',
            $stats['checked'],
            $stats['updates'],
            $stats['applied']
        ));
    }
}
