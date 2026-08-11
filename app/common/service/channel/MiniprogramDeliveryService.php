<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\channel;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\channel\MiniprogramChannelRegistry;
use app\common\service\channel\MiniprogramChannelService;
use app\common\service\channel\MiniprogramConfigService;

use app\common\service\config\ConfigService;
use app\common\support\LocalFile;

/** 按注册表 platform/edition 打包 miniprogram/apps 工程 zip */
final class MiniprogramDeliveryService
{

    public function __construct(
        private readonly MiniprogramConfigService $miniprogramConfigService,
        private readonly MiniprogramChannelService $miniprogramChannelService,
        private readonly ConfigService $configService,
        private readonly MiniprogramChannelRegistry $miniprogramChannelRegistry,
    ) {
    }

    private const SKIP_NAMES = [
        '.git',
        'node_modules',
        'project.private.config.json',
    ];

    /**
     * @return ServiceResult
     */
    public function buildSdkZip(?string $platform = null, ?string $edition = null): ServiceResult
    {
        $platform = strtolower(trim((string) ($platform ?? 'wechat'))) ?: 'wechat';
        $edition  = strtolower(trim((string) ($edition ?? $this->miniprogramConfigService->edition())))
            ?: MiniprogramConfigService::EDITION_CONTENT;

        $deny = $this->miniprogramChannelService->assertLicensed($edition);
        if ($deny !== null) {
            return $deny;
        }

        $skuRow = $this->resolveSkuByPlatformEdition($platform, $edition);
        if ($skuRow === null) {
            return ServiceResult::fail('未找到对应小程序渠道配置');
        }

        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('服务器未启用 ZipArchive，无法打包');
        }

        $appDir = $skuRow['app_dir'];
        $source = rtrim((string) (defined('ROOT_PATH') ? ROOT_PATH : ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'miniprogram' . DIRECTORY_SEPARATOR . 'apps'
            . DIRECTORY_SEPARATOR . $appDir;
        if (!is_dir($source)) {
            return ServiceResult::fail('小程序模板目录不存在：miniprogram/apps/' . $appDir);
        }

        $appIdKey = $skuRow['app_id_config'];
        $appId    = trim((string) $this->configService->get($appIdKey, ''));
        $apiBase  = $this->miniprogramConfigService->apiBase();
        if ($appId === '') {
            return ServiceResult::fail('请先在交付向导中填写微信小程序 AppID');
        }

        $runtime = rtrim((string) runtime_path(), DIRECTORY_SEPARATOR);
        if (!is_dir($runtime)) {
            LocalFile::mkdirIfMissing($runtime);
        }
        $zipPath = $runtime . DIRECTORY_SEPARATOR . 'mp-sdk-' . $platform . '-' . $edition . '-'
            . AppTime::format('YmdHis') . '.zip';
        if (is_file($zipPath)) {
            LocalFile::unlinkIfExists($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ServiceResult::fail('无法创建 zip 文件');
        }

        $zipFolder = $appDir . '/';
        $this->addDirToZip($zip, $source, $zipFolder, $appId, $apiBase, $edition, true);
        $coreSource = rtrim((string) (defined('ROOT_PATH') ? ROOT_PATH : ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'miniprogram' . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'mp-core';
        if (is_dir($coreSource)) {
            $this->addDirToZip($zip, $coreSource, 'packages/mp-core/', $appId, $apiBase, $edition, false);
        }
        $zip->close();

        $host     = preg_replace('/[^a-z0-9.-]+/i', '', (string) parse_url($apiBase, PHP_URL_HOST));
        $filename = 'pivark-' . $platform . '-' . $edition . ($host !== '' ? '-' . $host : '') . '.zip';

        return ServiceResult::ok(['path' => $zipPath, 'filename' => $filename], 'ok');
    }

    /**
     * @return array{sku:string,hub:string,platform:string,edition:string,app_dir:string,app_id_config:string,virtual:bool}|null
     */
    private function resolveSkuByPlatformEdition(string $platform, string $edition): ?array
    {
        foreach ($this->miniprogramChannelRegistry->marketSkus() as $sku => $row) {
            if (!is_array($row)) {
                continue;
            }
            $resolved = $this->miniprogramChannelRegistry->resolveMarketSku((string) $sku);
            if ($resolved === null) {
                continue;
            }
            if ($resolved['platform'] === $platform && $resolved['edition'] === $edition) {
                return $resolved;
            }
        }

        return $this->miniprogramChannelRegistry->resolveMarketSku($this->miniprogramChannelService->hubPlugin());
    }

    private function addDirToZip(
        \ZipArchive $zip,
        string $dir,
        string $zipPrefix,
        string $appId,
        string $apiBase,
        string $edition,
        bool $patchAppFiles = true
    ): void {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (in_array($name, self::SKIP_NAMES, true)) {
                continue;
            }
            $path  = $dir . DIRECTORY_SEPARATOR . $name;
            $entry = $zipPrefix . str_replace('\\', '/', $name);
            if (is_dir($path)) {
                $zip->addEmptyDir(rtrim($entry, '/') . '/');
                $this->addDirToZip($zip, $path, $entry . '/', $appId, $apiBase, $edition, $patchAppFiles);

                continue;
            }
            $content = (string) file_get_contents($path);
            if ($patchAppFiles && $name === 'project.config.json') {
                $content = $this->patchProjectConfig($content, $appId);
            } elseif ($patchAppFiles && $name === 'env.js' && str_contains($entry, 'config/')) {
                $content = $this->patchEnvJs($content, $apiBase, $edition);
            }
            $zip->addFromString(rtrim($entry, '/'), $content);
        }
    }

    private function patchProjectConfig(string $json, string $appId): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $data['appid'] = $appId;
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $encoded !== false ? $encoded . "\n" : $json;
    }

    private function patchEnvJs(string $js, string $apiBase, string $edition): string
    {
        $safeBase = addslashes($apiBase);
        $safeEd   = addslashes($edition);

        return <<<JS
/**
 * 由 PivArk 后台导出
 */
module.exports = {
  apiBase: '{$safeBase}',
  edition: '{$safeEd}',
};

JS;
    }
}
