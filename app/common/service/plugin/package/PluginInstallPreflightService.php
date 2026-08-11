<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 插件安装 / 上传前预检（不依赖授权平台与市场）
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\scaffold\PluginReservedIdentifierService;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\manifest\PluginManifestPolicyDiscovery;
use app\common\service\plugin\package\PluginBundledPackageLocator;
use app\common\service\plugin\PluginService;
use app\common\model\Plugin;
use app\common\support\LocalFile;

class PluginInstallPreflightService
{
    public function __construct(
        private readonly PluginService $pluginService,
        private readonly PluginManifestService $pluginManifestService,
        private readonly PluginReservedIdentifierService $pluginReservedIdentifierService,
        private readonly PluginCoreVersionRequirementService $pluginCoreVersionRequirement,
        private readonly PluginPeerVersionRequirementService $pluginPeerVersionRequirement,
    ) {
    }

    /**
     * 已装插件升级前：校验当前内核 / PHP / 同伴插件约束。
     *
     * @return array{ok:bool,errors:list<string>}
     */
    public function forUpgrade(string $identifier): array
    {
        $errors = [];
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return $this->preflightResult(['插件标识无效']);
        }
        $row = Plugin::where('identifier', $identifier)->where('installed', 1)->find();
        if ($row === null) {
            return $this->preflightResult(['请先安装插件']);
        }
        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            $errs = is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : ['清单无效'];
            $errors[] = 'plugin.json：' . implode('；', $errs);

            return $this->preflightResult($errors);
        }
        $errors = array_merge($errors, $this->pluginCoreVersionRequirement->runtimeErrors($manifest));
        $errors = array_merge($errors, $this->pluginPeerVersionRequirement->runtimeErrors($manifest));

        return $this->preflightResult($errors);
    }

    /**
     * 我的插件 / 市场升级联检报告（含阶梯摘要）。
     *
     * @return array<string, mixed>
     */
    public function forUpgradeReport(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        $base = $this->forUpgrade($identifier);
        $manifest = $this->pluginService->readManifest($identifier);
        $coreConstraint = is_array($manifest)
            ? $this->pluginCoreVersionRequirement->pivarkCoreConstraint($manifest)
            : '';
        $ladder = app(\app\common\service\plugin\market\PluginUpgradeLadderService::class)->plan($identifier);

        $rowModel = Plugin::where('identifier', $identifier)->where('installed', 1)->find();
        $row = $rowModel instanceof Plugin ? $rowModel->toArray() : null;
        $diskNeeds = is_array($manifest) && $row !== null
            ? $this->pluginService->needsUpgrade($identifier, $row, $manifest)
            : false;

        return [
            'ok'               => !empty($base['ok']),
            'errors'           => $base['errors'] ?? [],
            'identifier'       => $identifier,
            'name'             => is_array($manifest) ? trim((string) ($manifest['name'] ?? $identifier)) : $identifier,
            'local_version'    => is_array($manifest) ? trim((string) ($manifest['version'] ?? '')) : '',
            'pivark_core'      => $coreConstraint,
            'current_core'     => $this->pluginCoreVersionRequirement->currentCoreVersion(),
            'ladder'           => $ladder,
            'summary'          => !empty($base['ok'])
                ? (string) ($ladder['summary'] ?? '预检通过')
                : ('升级预检未通过：' . implode('；', $base['errors'] ?? [])),
            'can_start_upgrade'=> !empty($base['ok']) && (
                !empty($ladder['can_ladder']) || $diskNeeds
            ),
        ];
    }

    /**
     * @return array{ok:bool,errors:list<string>}
     */
    public function forInstall(string $identifier): array
    {
        $errors = [];
        $identifier = strtolower(trim($identifier));
        $errors = array_merge($errors, $this->reservedIdentifierErrors($identifier));
        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null) {
            $errors[] = '未找到 weapp/' . $identifier . '/plugin.json';
        } elseif (empty($manifest['_manifest_valid'])) {
            $errs = is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : ['清单无效'];
            $errors[] = 'plugin.json：' . implode('；', $errs);
        }
        $errors = array_merge($errors, app(PluginDistributionPolicy::class)->installErrors($identifier));
        if ($manifest !== null && !empty($manifest['_manifest_valid'])) {
            $errors = array_merge($errors, $this->pluginCoreVersionRequirement->runtimeErrors($manifest));
            $errors = array_merge($errors, $this->pluginPeerVersionRequirement->runtimeErrors($manifest));
        }
        $errors = array_merge($errors, $this->environmentErrors(requireZip: false));

        return $this->preflightResult($errors);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{ok:bool,errors:list<string>}
     */
    public function forMarketInstall(array $manifest, string $identifier, bool $replaceExisting): array
    {
        $errors = [];
        $identifier = strtolower(trim($identifier));
        $errors = array_merge($errors, $this->reservedIdentifierErrors($identifier));

        $validation = $this->pluginManifestService->validate($manifest);
        if (!$validation['ok']) {
            foreach ((array) ($validation['errors'] ?? []) as $err) {
                $err = (string) $err;
                // zip 安装解压前 contributor 尚未可加载
                if (str_contains($err, '类不存在')) {
                    continue;
                }
                $errors[] = $err;
            }
        }

        $package = strtolower(trim((string) ($manifest['package'] ?? '')));
        $errors = array_merge($errors, $this->packageReservedErrors($package, $manifest));
        if ($package !== '' && $this->isPackageTaken($package, $identifier)) {
            $errors[] = '包名 ' . $package . ' 已被其他插件占用';
        }

        $dest = $this->pluginService->weappRoot() . $identifier;
        if (is_dir($dest) && !$replaceExisting) {
            $errors[] = '目录 weapp/' . $identifier . ' 已存在，需勾选覆盖或先卸载';
        }

        if (!$this->pluginService->marketCatalogVisible($identifier)) {
            $hostMarketBlock = app(PluginDistributionPolicy::class)->marketInstallBlockedMessage($identifier);
            if ($hostMarketBlock !== null) {
                $errors[] = $hostMarketBlock;
            } elseif ($this->pluginService->isPermanentKernelSurface($identifier)) {
                $errors[] = '该能力已并入系统内核（产品中心等），不可从插件市场安装';
            }
        }

        $errors = array_merge($errors, $this->pluginCoreVersionRequirement->runtimeErrors($manifest));
        $errors = array_merge($errors, $this->pluginPeerVersionRequirement->runtimeErrors($manifest));
        $errors = array_merge($errors, $this->environmentErrors(requireZip: true));
        $errors = array_merge($errors, $this->downgradeErrors($manifest, $identifier, $replaceExisting));

        return $this->preflightResult($errors);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{ok:bool,errors:list<string>}
     */
    public function forUpload(array $manifest, string $identifier, bool $replaceExisting): array
    {
        $errors = [];
        $identifier = strtolower(trim($identifier));
        $errors = array_merge($errors, $this->reservedIdentifierErrors($identifier));
        if ($this->pluginService->blocksOfficialPackageUpload($identifier)) {
            $errors[] = '不可通过上传覆盖已内置的官方插件目录';
        }

        $validation = $this->pluginManifestService->validate($manifest);
        if (!$validation['ok']) {
            $errors = array_merge($errors, $validation['errors']);
        }

        $package = strtolower(trim((string) ($manifest['package'] ?? '')));
        $errors = array_merge($errors, $this->packageReservedErrors($package, $manifest));
        if ($package !== '' && $this->isPackageTaken($package, $identifier)) {
            $errors[] = '包名 ' . $package . ' 已被其他插件占用';
        }

        $dest = $this->pluginService->weappRoot() . $identifier;
        if (is_dir($dest) && !$replaceExisting) {
            $errors[] = '目录 weapp/' . $identifier . ' 已存在，需勾选覆盖或先卸载';
        }

        $errors = array_merge($errors, $this->pluginCoreVersionRequirement->runtimeErrors($manifest));
        $errors = array_merge($errors, $this->pluginPeerVersionRequirement->runtimeErrors($manifest));
        $errors = array_merge($errors, $this->environmentErrors(requireZip: true));
        $errors = array_merge($errors, $this->downgradeErrors($manifest, $identifier, $replaceExisting));

        return $this->preflightResult($errors);
    }

    /**
     * 后台展示用环境预检（安装 / 上传前）
     *
     * @return array{ok:bool,errors:list<string>,php_version:string,php_ok:bool,zip:bool}
     */
    public function environmentPublic(bool $requireZip = true): array
    {
        $errors = $this->environmentErrors($requireZip);

        return [
            'ok'          => $errors === [],
            'errors'      => $errors,
            'php_version' => PHP_VERSION,
            'php_ok'      => version_compare(PHP_VERSION, '8.1.0', '>='),
            'zip'         => class_exists(\ZipArchive::class),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function downgradeErrors(array $manifest, string $identifier, bool $replaceExisting): array
    {
        if (!$replaceExisting) {
            return [];
        }
        $rowModel = Plugin::where('identifier', $identifier)->where('installed', 1)->find();
        if (!($rowModel instanceof Plugin)) {
            return [];
        }
        $row = $rowModel->toArray();
        $diskVersion = (string) ($manifest['version'] ?? '1.0.0');
        $dbVersion   = (string) ($row['version'] ?? '0.0.0');
        if (version_compare($diskVersion, $dbVersion, '<')) {
            return [
                '磁盘版本 (' . $diskVersion . ') 低于已安装版本 (' . $dbVersion . ')，请使用升级或先卸载',
            ];
        }

        return [];
    }

    /** @return list<string> */
    private function environmentErrors(bool $requireZip = false): array
    {
        $errors = [];
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $errors[] = 'PHP 版本需 >= 8.1（当前 ' . PHP_VERSION . '）';
        }
        $root = $this->pluginService->weappRoot();
        if (!is_dir($root)) {
            if (!LocalFile::mkdirIfMissing($root)) {
                $errors[] = 'weapp 目录不存在且无法创建：' . $root;
            }
        } elseif (!is_writable($root)) {
            $errors[] = 'weapp 目录不可写，请检查权限';
        }
        if ($requireZip && !class_exists(\ZipArchive::class)) {
            $errors[] = '服务器未启用 ZipArchive（上传安装需要）';
        }

        return $errors;
    }

    private function isPackageTaken(string $package, string $identifier): bool
    {
        $package = strtolower(trim($package));
        if ($package === '') {
            return false;
        }
        $rows = Plugin::where('installed', 1)->select()->toArray();
        foreach ($rows as $row) {
            $id = (string) ($row['identifier'] ?? '');
            if ($id === '' || $id === $identifier) {
                continue;
            }
            $manifest = $this->pluginService->readManifest($id);
            if ($manifest === null) {
                continue;
            }
            $other = strtolower(trim((string) ($manifest['package'] ?? '')));
            if ($other !== '' && $other === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $errors
     * @return array{ok:bool,errors:list<string>}
     */
    private function preflightResult(array $errors): array
    {
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /** @return list<string> */
    private function reservedIdentifierErrors(string $identifier): array
    {
        if ($this->allowOfficialBundledDuringInstallWizard($identifier)) {
            return [];
        }
        // 官方发行 weapp 已在本机目录：安装自身 ≠ 第三方抢占官方名
        if ($this->allowLocalOfficialWeappInstall($identifier)) {
            return [];
        }
        $check = $this->pluginReservedIdentifierService->checkPayload($identifier);
        if (($check['ok'] ?? false) === true) {
            return [];
        }
        $msg = trim((string) ($check['message'] ?? ''));
        if ($msg === '') {
            $msg = $this->pluginReservedIdentifierService->identifierFormatMessage();
        }

        return [$msg];
    }

    /** 磁盘上官方示范/发行插件（communityBundled 或 reserved=official_weapp）允许 installFromWeapp */
    private function allowLocalOfficialWeappInstall(string $identifier): bool
    {
        $manifestPath = $this->pluginService->weappRoot() . $identifier . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!\is_file($manifestPath)) {
            return false;
        }
        $bundled = PluginManifestPolicyDiscovery::communityBundledPlainWeapp();
        if (\in_array($identifier, $bundled, true)) {
            return true;
        }
        $tier = $this->pluginReservedIdentifierService->index()[$identifier] ?? '';

        return $tier === 'official_weapp';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function packageReservedErrors(string $package, array $manifest = []): array
    {
        $publisher = strtolower(trim((string) ($manifest['publisher_type'] ?? '')));
        // 官方发行包允许 pivark/*；第三方上传已在审计中禁止伪声明 official
        if ($publisher === PluginManifestService::TYPE_OFFICIAL) {
            $package = strtolower(trim($package));
            if ($package === '') {
                return [];
            }
            if (!preg_match('/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/', $package)) {
                $fmt = $this->pluginReservedIdentifierService->packageFormatMessage();

                return [$fmt];
            }

            return [];
        }

        $msg = $this->pluginReservedIdentifierService->validatePackageForPlugin($package);

        return $msg !== null ? [$msg] : [];
    }

    private function allowOfficialBundledDuringInstallWizard(string $identifier): bool
    {
        $wizard = strtolower(trim((string) \getenv('PIVARK_INSTALL_WIZARD')));
        if (!\in_array($wizard, ['1', 'true', 'yes'], true)) {
            return false;
        }
        $locator = app(PluginBundledPackageLocator::class);
        $allowed = array_values(array_unique(array_merge(
            PluginManifestPolicyDiscovery::communityBundledPlainWeapp(),
            $locator->listEnhancementPackIdentifiers(),
        )));
        if (!\in_array($identifier, $allowed, true)) {
            return false;
        }
        $manifestPath = $this->pluginService->weappRoot() . $identifier . DIRECTORY_SEPARATOR . 'plugin.json';
        if (\is_file($manifestPath)) {
            return true;
        }

        // zip 先装：weapp 尚未落盘，但发行包内已有增强包
        return $locator->resolveBundledPackagePath($identifier) !== null;
    }
}
