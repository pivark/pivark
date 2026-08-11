<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use app\common\service\plugin\PluginService;
use app\common\service\weapp\WeappInstallDemoService;
use app\common\service\weapp\WeappSchemaRunner;
use install\support\InstallSeedContext;
use install\service\InstallEnhancementPackService;
use install\service\InstallDatabaseService;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;

/** 装站一次性：演示种子；装完可随 install/ 删除 */
final class InstallSeedService
{
    public function __construct(
        private readonly InstallDatabaseService $installDatabaseService,
        private readonly PluginService $pluginService,
        private readonly InstallEnhancementPackService $installEnhancementPack,
        private readonly WeappSchemaRunner $weappSchemaRunner,
    ) {
    }

    /**
     * 演示种子含商城 SKU 时尝试装 shop；Community 发行包通常不含 shop → 跳过 extras（不阻断向导）。
     */
    private function ensureShopPluginForDemoProductExtras(): bool
    {
        if (!in_array('shop', $this->pluginService->listInstalledIdentifiers(), true)) {
            $result = $this->installEnhancementPack->installIdentifier('shop');
            if (!$result->isOk()) {
                return false;
            }
            $this->pluginService->enable('shop');
        }
        PluginService::registerAutoloadPublic('shop');
        $schema = $this->weappSchemaRunner->apply('shop');

        return $schema->isOk();
    }

    /**
     * @param list<string> $selectedPlugins
     */
    public function runAll(array $selectedPlugins, bool $importDemo): void
    {
        if (!$importDemo) {
            return;
        }
        $guard = 0;
        while ($guard++ < 20) {
            $step = $this->runNextDemoBatch($selectedPlugins, true);
            if (!$step->isOk()) {
                throw new \RuntimeException((string) ($step->message() ?: '演示数据导入失败'));
            }
            if (!empty($step->dataArray()['done'])) {
                return;
            }
        }
        throw new \RuntimeException('演示数据导入轮次过多');
    }

    /**
     * 安装向导分批导入演示数据（进度字段与迁移/建表对齐）
     *
     * @param list<string> $selectedPlugins
     */
    public function runNextDemoBatch(array $selectedPlugins, bool $importDemo): ServiceResult
    {
        if (!$importDemo) {
            $this->clearDemoProgressJob();

            return ServiceResult::ok([
                'done'              => true,
                'import_demo'       => false,
                'pending'           => 0,
                'migration_total'   => 1,
                'migration_applied' => 1,
                'table_count'       => $this->installDatabaseService->countPrefixedTablesFromEnv(),
                'name'              => 'skip_demo',
            ], '已跳过全部演示数据（系统演示与插件样例均不灌）');
        }

        $phases = [
            ['key' => 'site', 'label' => '站点演示基础数据'],
            ['key' => 'items', 'label' => '产品样例品项'],
            ['key' => 'relations', 'label' => '品项关联'],
            ['key' => 'extras', 'label' => '产品扩展演示'],
        ];
        $total = \count($phases);
        $job = $this->readDemoProgressJob() ?? ['phase' => 0];
        $phase = (int) ($job['phase'] ?? 0);
        if ($phase >= $total) {
            $this->clearDemoProgressJob();
            $tables = $this->installDatabaseService->countPrefixedTablesFromEnv();

            return ServiceResult::ok([
                'done'              => true,
                'import_demo'       => true,
                'pending'           => 0,
                'migration_total'   => $total,
                'migration_applied' => $total,
                'table_count'       => $tables,
                'name'              => 'demo_done',
                'plugins'           => $selectedPlugins,
            ], '华仪智控系统演示数据已导入');
        }

        $current = $phases[$phase];
        match ($current['key']) {
            'site' => $this->runCommunityDemoSeed($selectedPlugins),
            'items' => $this->runCommunityDemoItems($selectedPlugins),
            'relations' => $this->runCommunityDemoItemRelations($selectedPlugins),
            'extras' => $this->runCommunityDemoProductExtras($selectedPlugins),
            default => null,
        };

        $phase++;
        $job['phase'] = $phase;
        $pending = $total - $phase;
        $tables = $this->installDatabaseService->countPrefixedTablesFromEnv();
        $done = $phase >= $total;
        if ($done) {
            $this->clearDemoProgressJob();
        } else {
            $this->writeDemoProgressJob($job);
        }

        return ServiceResult::ok([
            'done'              => $done,
            'import_demo'       => true,
            'pending'           => $pending,
            'migration_total'   => $total,
            'migration_applied' => $phase,
            'table_count'       => $tables,
            'name'              => (string) $current['label'],
            'plugins'           => $selectedPlugins,
        ], '演示 ' . $phase . '/' . $total . ' · 剩余 ' . $pending . ' · ' . (string) $current['label']);
    }

    public function clearDemoProgressJob(): void
    {
        $path = $this->demoProgressJobPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{phase:int}|null */
    private function readDemoProgressJob(): ?array
    {
        $path = $this->demoProgressJobPath();
        if (!is_readable($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);

        return \is_array($raw) ? $raw : null;
    }

    /** @param array{phase:int} $job */
    private function writeDemoProgressJob(array $job): void
    {
        $path = $this->demoProgressJobPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法写入演示导入进度');
        }
        file_put_contents($path, json_encode($job, JSON_UNESCAPED_UNICODE));
    }

    private function demoProgressJobPath(): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR . 'install_demo_progress.json';
    }

    /**
     * @param list<string> $selectedPlugins
     */
    private function runCommunityDemoSeed(array $selectedPlugins): void
    {
        InstallSeedContext::begin($selectedPlugins, true);
        $script = ProjectPaths::resolveInstallSeedScript('seed_community_demo.php');
        if ($script === null) {
            InstallSeedContext::reset();

            return;
        }
        try {
            $code = $this->installDatabaseService->runEmbeddedSetupScript($script);
            if ($code !== 0) {
                throw new \RuntimeException('seed_community_demo 执行失败，exit ' . $code);
            }
        } finally {
            InstallSeedContext::reset();
        }
        // 社区文落库后再跑插件 Volume，避免「有栏目无挂表」
        $this->runSelectedPluginVolumeSeeds($selectedPlugins);
    }

    /**
     * 对已选增强包发现并执行 *DemoVolumeSeedService（约定同 WeappInstallDemoService）。
     *
     * @param list<string> $selectedPlugins
     */
    private function runSelectedPluginVolumeSeeds(array $selectedPlugins): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => strtolower(trim((string) $id)),
            $selectedPlugins
        ))));
        if ($ids === []) {
            return;
        }
        $demo = app(WeappInstallDemoService::class);
        foreach ($ids as $id) {
            try {
                PluginService::registerAutoloadPublic($id);
                $demo->runVolumeEnrichmentForPlugin($id);
            } catch (\Throwable $e) {
                \app\common\support\OpsLog::businessWarning('install_demo_volume_plugin_failed', [
                    'identifier' => $id,
                    'error'      => $e->getMessage(),
                    'exception'  => $e::class,
                ]);
            }
        }
    }

    /**
     * @param list<string> $selectedPlugins
     */
    private function runCommunityDemoItems(array $selectedPlugins): void
    {
        $script = ProjectPaths::resolveInstallSeedScript('seed_community_demo_items.php');
        if ($script === null) {
            return;
        }
        InstallSeedContext::begin($selectedPlugins, true);
        \ob_start();
        try {
            if (!\defined('PIVARK_INSTALL_SEED_INCLUDE')) {
                \define('PIVARK_INSTALL_SEED_INCLUDE', true);
            }
            include $script;
        } finally {
            \ob_end_clean();
            InstallSeedContext::reset();
        }
    }

    /**
     * @param list<string> $selectedPlugins
     */
    private function runCommunityDemoItemRelations(array $selectedPlugins): void
    {
        $script = ProjectPaths::resolveInstallSeedScript('seed_community_demo_item_relations.php');
        if ($script === null) {
            return;
        }
        InstallSeedContext::begin($selectedPlugins, true);
        $savedArgv = $GLOBALS['argv'] ?? null;
        // include 在方法作用域：须设局部 $argv，仅改 $GLOBALS 时脚本读不到 --force
        $argv = ['seed_community_demo_item_relations.php', '--force'];
        $GLOBALS['argv'] = $argv;
        if (!\defined('PIVARK_INSTALL_SEED_INCLUDE')) {
            \define('PIVARK_INSTALL_SEED_INCLUDE', true);
        }
        \ob_start();
        try {
            include $script;
        } finally {
            \ob_end_clean();
            if ($savedArgv !== null) {
                $GLOBALS['argv'] = $savedArgv;
            } else {
                unset($GLOBALS['argv']);
            }
            InstallSeedContext::reset();
        }
    }

    /**
     * @param list<string> $selectedPlugins
     */
    private function runCommunityDemoProductExtras(array $selectedPlugins): void
    {
        $script = ProjectPaths::resolveInstallSeedScript('seed_community_demo_product_extras.php');
        if ($script === null) {
            return;
        }
        // 尽量装 shop 以便 SKU；失败不阻断内核段（参数组 / 可售标记 / variant）
        $this->ensureShopPluginForDemoProductExtras();
        InstallSeedContext::begin($selectedPlugins, true);
        \ob_start();
        try {
            if (!\defined('PIVARK_INSTALL_SEED_INCLUDE')) {
                \define('PIVARK_INSTALL_SEED_INCLUDE', true);
            }
            include $script;
        } finally {
            \ob_end_clean();
            InstallSeedContext::reset();
        }
    }
}
