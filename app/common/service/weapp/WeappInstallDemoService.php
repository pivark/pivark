<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\config\ConfigService;
use app\common\support\OpsLog;
use app\common\service\weapp\WeappSupportGateway;
use think\facade\Db;

/**
 * 安装向导首次装插件时写入插件自带默认演示数据（后台再装插件不触发）。
 * 仅当向导勾选「导入演示数据」（PIVARK_INSTALL_IMPORT_DEMO=1）时写入演示数据；未勾选 = 空站只装能力。
 *
 * 约定：weapp/{id}/service/{Studly}InstallDemoService::seed() · 表检测/时间见 WeappInstallDemoService::tableReady/now
 */
final class WeappInstallDemoService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    public function seedDefaultDataOnWizardInstall(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !self::isInstallWizardActive() || !self::isImportDemoRequested()) {
            return;
        }
        if ($this->hasSeeded($identifier)) {
            return;
        }
        // 确保 weapp\{id}\service\* 可 class_exists（zip 刚解压 / 未 boot 时）
        \app\common\service\plugin\PluginService::registerAutoloadPublic($identifier);
        $class = $this->resolveSeederClass($identifier);
        if ($class === null) {
            return;
        }
        try {
            $class::seed();
            $this->runVolumeEnrichmentAfterInstallDemo($identifier);
            $this->markSeeded($identifier);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('weapp_install_demo_seed_failed', [
                'identifier' => $identifier,
                'error'      => $e->getMessage(),
                'exception'  => $e::class,
            ]);
        }
    }

    /**
     * 装机社区种子后再灌：发现 weapp/{id}/service/*DemoVolumeSeedService::seed()（禁止写死插件 id）。
     * InstallSeedService 在 seed_community_demo 之后调用，避免「有栏目无挂表」。
     */
    public function runVolumeEnrichmentForPlugin(string $identifier): void
    {
        $this->runVolumeEnrichmentAfterInstallDemo($identifier);
    }

    /**
     * 安装演示后加灌：发现 weapp/{id}/service/*DemoVolumeSeedService::seed()（禁止写死插件 id）。
     * 须直调类方法：装机试用 entitlement 尚未稳定时 PluginWeappAccess 会静默 no-op，导致有栏目无挂表。
     */
    private function runVolumeEnrichmentAfterInstallDemo(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !preg_match('/^[a-z0-9_-]+$/', $identifier)) {
            return;
        }
        $dir = \app\common\support\ProjectPaths::root() . 'weapp/' . $identifier . '/service';
        if (!is_dir($dir)) {
            return;
        }
        \app\common\service\plugin\PluginService::registerAutoloadPublic($identifier);
        foreach (glob($dir . '/*DemoVolumeSeedService.php') ?: [] as $file) {
            $short = basename((string) $file, '.php');
            if ($short === '' || $short === 'DemoVolumeSeedService') {
                continue;
            }
            $class = 'weapp\\' . $identifier . '\\service\\' . $short;
            if (!class_exists($class) || !method_exists($class, 'seed')) {
                OpsLog::businessWarning('weapp_install_demo_volume_missing', [
                    'identifier' => $identifier,
                    'class'      => $class,
                ]);
                continue;
            }
            try {
                $class::seed();
            } catch (\Throwable $e) {
                OpsLog::businessWarning('weapp_install_demo_volume_failed', [
                    'identifier' => $identifier,
                    'class'      => $class,
                    'error'      => $e->getMessage(),
                    'exception'  => $e::class,
                ]);
            }
        }
    }

    public function configKey(string $identifier): string
    {
        return 'plugin_install_demo_' . strtolower(trim($identifier));
    }

    public function hasSeeded(string $identifier): bool
    {
        try {
            return (string) $this->config->getDirect($this->configKey($identifier), '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public function markSeeded(string $identifier): void
    {
        $this->config->set($this->configKey($identifier), '1');
    }

    private function resolveSeederClass(string $identifier): ?string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $identifier)));
        $candidates = [
            'weapp\\' . $identifier . '\\service\\' . $studly . 'InstallDemoService',
        ];
        // 历史类名（插件 id 与业务名不一致：doc_bundle→Download 等）
        $legacy = [
            'doc_bundle'  => 'DownloadInstallDemoService',
            'doc_vod'     => 'VideoInstallDemoService',
            'doc_comment' => 'CommentInstallDemoService',
            'doc_thumb'   => 'ThumbInstallDemoService',
            'doc_gallery' => 'GalleryInstallDemoService',
            'doc_ask'     => 'AskInstallDemoService',
        ];
        if (isset($legacy[$identifier])) {
            $candidates[] = 'weapp\\' . $identifier . '\\service\\' . $legacy[$identifier];
        }
        foreach ($candidates as $class) {
            if (!class_exists($class)) {
                continue;
            }
            if (!method_exists($class, 'seed')) {
                continue;
            }

            return $class;
        }

        return null;
    }

    /** 插件 InstallDemoService 共用：表是否可写 */
    public static function tableReady(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 插件 InstallDemoService 共用：当前站点时间字符串 */
    public static function now(): string
    {
        return app(WeappSupportGateway::class)->appTimeNow();
    }

    /** 仅安装向导进程置位；装完后恒为 false（不依赖 install/ 类） */
    private static function isInstallWizardActive(): bool
    {
        return self::envFlagTruthy('PIVARK_INSTALL_WIZARD');
    }

    /**
     * 向导「导入演示数据」勾选态。未置位时按 false（空站优先，对齐矩阵 plugins_no_demo）。
     */
    private static function isImportDemoRequested(): bool
    {
        return self::envFlagTruthy('PIVARK_INSTALL_IMPORT_DEMO');
    }

    private static function envFlagTruthy(string $key): bool
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1;
        }
        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
