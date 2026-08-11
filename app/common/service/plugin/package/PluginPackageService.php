<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;
use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\plugin\WeappContext;
use app\common\service\plugin\package\PluginInstallBackupService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\package\PluginPackageSignatureService;
use app\common\service\plugin\lifecycle\PluginDistributionService;
use app\common\service\plugin\package\PluginInstallPreflightService;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\commerce\PluginCommercialPricingService;
use app\common\service\plugin\scaffold\PluginReservedIdentifierService;
use app\common\service\plugin\scaffold\PluginScaffoldService;
use app\common\service\plugin\market\PluginMarketBlocklistService;
use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\security\PluginSecurityPolicyService;
use app\common\service\audit\AuditLogService;
use app\common\model\Plugin;
use app\common\support\LocalFile;
class PluginPackageService
{
    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
        private readonly AuditLogService $auditLogService,
        private readonly PluginReservedIdentifierService $pluginReservedIdentifierService,
    ) {
    }


    private function maxPackageBytes(): int
    {
        return max(1048576, (int) config('plugin.market.max_package_bytes', 20971520));
    }

    public function maxPackageBytesLimit(): int
    {
        return $this->maxPackageBytes();
    }

    /**
     * @param array<string, mixed>|null $pricingForm
     * @return ServiceResult
     */
    public function installUpload(
        string $zipPath,
        string $originalName = '',
        bool $replaceExisting = false,
        int $adminUserId = 0,
        ?array $pricingForm = null,
        string $auditContext = PluginPackageAuditService::AUDIT_CONTEXT_UPLOAD
    ): ServiceResult {
        if ($auditContext !== PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG) {
            $uploadBlock = app(PluginSecurityPolicyService::class)->localUploadGuard($adminUserId);
            if ($uploadBlock !== null) {
                return ServiceResult::fail($uploadBlock);
            }
        }

        if (!is_readable($zipPath)) {
            return ServiceResult::fail('无法读取上传文件');
        }
        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('服务器未启用 ZipArchive 扩展');
        }
        $size = filesize($zipPath);
        if ($size === false || $size > $this->maxPackageBytes()) {
            return ServiceResult::fail('插件包不能超过 20MB');
        }
        if ($originalName !== '' && !preg_match('/\.zip$/i', $originalName)) {
            return ServiceResult::fail('仅支持 .zip 格式插件包');
        }

        $audit = app(PluginPackageAuditService::class)->auditZipFile($zipPath, $auditContext);
        $blockedId = trim($audit['identifier']);
        if ($blockedId !== '') {
            $blockMsg = app(PluginMarketBlocklistService::class)->guardInstall($blockedId);
            if ($blockMsg !== null) {
                return ServiceResult::fail($blockMsg);
            }
        }
        if (app(PluginPackageAuditService::class)->shouldBlockInstall($audit)) {
            $verbose = app(PluginSecurityPolicyService::class)->canViewTechnicalAudit($adminUserId);
            $publicAudit = app(PluginSecurityPolicyService::class)->sanitizeAuditReport($audit, $verbose);

            return ServiceResult::fail(app(PluginSecurityPolicyService::class)->installBlockedMessage($audit, $verbose));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ServiceResult::fail('无法打开 zip 文件');
        }

        try {
            $layout = $this->detectLayout(
                $zip,
                $auditContext === PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
            );
            if (!$layout->isOk()) {
                return $layout;
            }

            /** @var array<string,mixed> $layoutData */
            $layoutData = $layout->dataArray();
            $manifest   = is_array($layoutData['manifest'] ?? null) ? $layoutData['manifest'] : [];
            $identifier = (string) ($layoutData['identifier'] ?? '');
            $prefix     = (string) ($layoutData['prefix'] ?? '');

            $validation = $this->pluginManifestService->validate($manifest);
            if (!$validation['ok']) {
                $valErrors = array_values(array_filter(
                    (array) ($validation['errors'] ?? []),
                    static function ($err) use ($auditContext): bool {
                        $err = (string) $err;
                        // 解压前 weapp 未落盘，contributor class_exists 必然失败
                        if (
                            $auditContext === PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
                            && str_contains($err, '类不存在')
                        ) {
                            return false;
                        }

                        return $err !== '';
                    }
                ));
                if ($valErrors !== []) {
                    return ServiceResult::fail('插件清单校验失败：' . implode('；', $valErrors));
                }
            }

            // 在架市场货：无 Entitlement 禁止上传安装（自用非在架可 A→B）
            if ($auditContext !== PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG) {
                $marketBlock = $this->marketListedUploadGuard($identifier, $manifest);
                if ($marketBlock !== null) {
                    return ServiceResult::fail($marketBlock);
                }
            }

            $preflight = $auditContext === PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
                ? app(PluginInstallPreflightService::class)->forMarketInstall($manifest, $identifier, $replaceExisting)
                : app(PluginInstallPreflightService::class)->forUpload($manifest, $identifier, $replaceExisting);
            if (!$preflight['ok']) {
                return ServiceResult::fail('上传预检未通过：' . implode('；', $preflight['errors']));
            }

            if ($auditContext !== PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG) {
                $zipErrors = app(PluginDistributionService::class)->validateZipContents($zip, $manifest);
                if ($zipErrors !== []) {
                    return ServiceResult::fail(implode('；', $zipErrors));
                }

                $sigErrors = app(PluginPackageSignatureService::class)->verifyUploaded($zipPath, $identifier, $manifest);
                if ($sigErrors !== []) {
                    return ServiceResult::fail(implode('；', $sigErrors));
                }

                if (app(PluginService::class)->blocksOfficialPackageUpload($identifier)) {
                    return ServiceResult::fail('不可通过上传覆盖已内置的官方插件目录，请使用系统更新或关闭 PIVARK_BLOCK_OFFICIAL_UPLOAD');
                }
            }

            $uploadPackage = $this->normalizePackage((string) ($manifest['package'] ?? ''));
            if ($uploadPackage !== '' && $this->isPackageTaken($uploadPackage, $identifier)) {
                return ServiceResult::fail('包名 ' . $uploadPackage . ' 已被其他插件占用');
            }

            $dest      = app(PluginService::class)->weappRoot() . $identifier;
            $installed = Plugin::where('identifier', $identifier)->where('installed', 1)->find() !== null;
            $backup    = null;
            if (is_dir($dest)) {
                if (!$replaceExisting) {
                    return ServiceResult::fail('插件目录 weapp/' . $identifier . ' 已存在。若需覆盖升级，请勾选「覆盖已安装版本」');
                }
                if (!$installed) {
                    return ServiceResult::fail('目录已存在但插件未安装，请先在列表安装或删除 weapp/' . $identifier);
                }
                app(PluginInstallBackupService::class)->archiveWeappDirectory($identifier, $dest);
                $backup = $dest . '_bak_' . AppTime::format('YmdHis');
                if (!rename($dest, $backup)) {
                    return ServiceResult::fail('无法备份旧目录，请检查目录权限');
                }
            }

            $restoreBackup = function () use (&$backup, $dest): void {
                if ($backup === null || !is_dir($backup)) {
                    return;
                }
                if (is_dir($dest)) {
                    $this->removeDir($dest);
                }
                rename($backup, $dest);
                $backup = null;
            };

            if (!$this->extractToWeapp($zip, $prefix, $identifier)) {
                $restoreBackup();

                return ServiceResult::fail('解压失败，请检查压缩包结构');
            }

            $onDisk = app(PluginService::class)->readManifest($identifier);
            if ($onDisk === null || empty($onDisk['_manifest_valid'])) {
                $this->removeDir($dest);
                $restoreBackup();
                $errs = is_array($onDisk['_manifest_errors'] ?? null) ? $onDisk['_manifest_errors'] : ['清单无效'];

                return ServiceResult::fail('解压后校验失败：' . implode('；', $errs));
            }

            if (is_array($pricingForm) && $pricingForm !== []) {
                $pricing = app(PluginCommercialPricingService::class)->applyToWeapp($identifier, $pricingForm);
                if (!$pricing->isOk()) {
                    $this->removeDir($dest);
                    $restoreBackup();

                    return ServiceResult::fail($pricing->message());
                }
                $onDisk = app(PluginService::class)->readManifest($identifier);
            }

            if ($backup !== null && is_dir($backup)) {
                $this->removeDir($backup);
                $backup = null;
            }

            if ($replaceExisting && $installed) {
                $upgrade = app(PluginService::class)->upgrade($identifier);
                if (!$upgrade->isOk()) {
                    return $upgrade;
                }
                $this->auditLogService->operate('上传并升级插件包', 'admin.plugin', [
                    'identifier'     => $identifier,
                    'original_name'  => $originalName,
                ]);

                return ServiceResult::ok(['identifier' => $identifier, 'publisher_label' => (string) ($onDisk['publisher_label'] ?? ''), 'upgraded' => true], $upgrade->message());
            }

            $this->auditLogService->operate('上传插件包', 'admin.plugin', [
                'identifier'      => $identifier,
                'publisher_type'  => $onDisk['publisher_type'] ?? '',
                'original_name'   => $originalName,
            ]);

            $msg = '插件包已上传，请在「我的插件」中安装';
            $responseData = [
                'identifier'      => $identifier,
                'publisher_label' => (string) ($onDisk['publisher_label'] ?? ''),
            ];
            if ($audit['level'] === 'warn') {
                $verbose = app(PluginSecurityPolicyService::class)->canViewTechnicalAudit($adminUserId);
                $responseData['audit'] = app(PluginSecurityPolicyService::class)->sanitizeAuditReport($audit, $verbose);
                $msg .= $verbose
                    ? '（审计有警告项，请查看详情）'
                    : '（包已通过安装，但存在需留意的审计提示）';
            }

            return ServiceResult::ok($responseData, $msg);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param bool $allowOfficialReserved 市场/向导官方包：允许官方占用名（upload 仍拦截）
     * @return ServiceResult
     */
    public function detectLayout(\ZipArchive $zip, bool $allowOfficialReserved = false): ServiceResult
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $this->normalizeZipEntry((string) $zip->getNameIndex($i));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (!str_ends_with(strtolower($name), 'plugin.json')) {
                continue;
            }
            $dir = dirname($name);
            if ($dir === '.') {
                $dir = '';
            }
            $candidates[] = ['path' => $name, 'prefix' => $dir === '' ? '' : ($dir . '/')];
        }

        if ($candidates === []) {
            return ServiceResult::fail('压缩包内未找到 plugin.json');
        }
        if (count($candidates) > 1) {
            return ServiceResult::fail('压缩包内存在多个 plugin.json，请只保留一个插件');
        }

        $entry  = $candidates[0];
        $raw    = $zip->getFromName($entry['path']);
        if ($raw === false || trim($raw) === '') {
            return ServiceResult::fail('plugin.json 无法读取');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ServiceResult::fail('plugin.json 不是合法 JSON');
        }

        $dirId = $entry['prefix'] !== '' ? rtrim($entry['prefix'], '/') : '';
        if (str_contains($dirId, '/')) {
            return ServiceResult::fail('请使用「插件标识/plugin.json」单层目录结构打包');
        }

        $identifier = $this->safeIdentifier((string) ($json['identifier'] ?? $dirId));
        if ($identifier === null) {
            return ServiceResult::fail('插件 identifier 无效（仅允许小写字母、数字、下划线、连字符）');
        }
        $reserved = $this->pluginReservedIdentifierService->validateForPlugin($identifier);
        if ($reserved !== null) {
            $publisher = strtolower(trim((string) ($json['publisher_type'] ?? '')));
            $officialOk = $allowOfficialReserved
                && $publisher === PluginManifestService::TYPE_OFFICIAL;
            if (!$officialOk) {
                return ServiceResult::fail($reserved);
            }
        }
        if ($dirId !== '' && $dirId !== $identifier) {
            return ServiceResult::fail('目录名与 plugin.json 中 identifier 不一致');
        }

        $manifest = $this->pluginManifestService->applyValidation(
            app(WeappContext::class)->normalizeManifest($json, $identifier)
        );

        return ServiceResult::ok(['manifest' => $manifest, 'identifier' => $identifier, 'prefix' => $entry['prefix']], 'ok');
    }

    public function safeIdentifier(string $identifier): ?string
    {
        return $this->pluginReservedIdentifierService->normalizeIdentifier($identifier);
    }

    public function normalizePackage(string $package): string
    {
        return strtolower(trim($package));
    }

    /**
     * 包名是否已被占用（plugins 表 + 各 weapp 目录内 plugin.json，可排除当前 identifier）
     */
    public function isPackageTaken(string $package, ?string $exceptIdentifier = null): bool
    {
        $package = $this->normalizePackage($package);
        if ($package === '' || !preg_match(PluginReservedIdentifierService::PACKAGE_PATTERN, $package)) {
            return true;
        }

        $exceptIdentifier = $exceptIdentifier !== null ? strtolower(trim($exceptIdentifier)) : '';

        $query = Plugin::where('package', $package);
        if ($exceptIdentifier !== '') {
            $query->where('identifier', '<>', $exceptIdentifier);
        }
        if ($query->find() !== null) {
            return true;
        }

        $root = app(PluginService::class)->weappRoot();
        if (!is_dir($root)) {
            return false;
        }

        foreach (scandir($root) ?: [] as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($root . $dir)) {
                continue;
            }
            if ($exceptIdentifier !== '' && $dir === $exceptIdentifier) {
                continue;
            }
            $manifest = app(PluginService::class)->readManifest($dir);
            if ($manifest === null) {
                continue;
            }
            if ($this->normalizePackage((string) ($manifest['package'] ?? '')) === $package) {
                return true;
            }
        }

        return false;
    }

    /**
     * 分配未占用的 dev/pk_* 包名（比对库与 weapp 清单后重试）
     */
    public function allocateUniquePackage(string $vendor = PluginScaffoldService::DEFAULT_PACKAGE_VENDOR): string
    {
        $vendor = strtolower(trim($vendor));
        if ($vendor === '' || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $vendor)) {
            $vendor = PluginScaffoldService::DEFAULT_PACKAGE_VENDOR;
        }

        for ($attempt = 0; $attempt < 48; ++$attempt) {
            $bytes  = $attempt < 32 ? 6 : 8;
            $slug   = 'pk_' . bin2hex(random_bytes($bytes));
            $package = $vendor . '/' . $slug;
            if (!$this->isPackageTaken($package)) {
                return $package;
            }
        }

        return $vendor . '/pk_' . bin2hex(random_bytes(8));
    }

    private function extractToWeapp(\ZipArchive $zip, string $prefix, string $identifier): bool
    {
        $destRoot = app(PluginService::class)->weappRoot() . $identifier;
        if (!is_dir($destRoot) && !mkdir($destRoot, 0755, true) && !is_dir($destRoot)) {
            return false;
        }
        $destRootReal = realpath($destRoot);
        if ($destRootReal === false) {
            return false;
        }

        $prefix = $this->normalizeZipEntry($prefix);
        $plen   = strlen($prefix);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $this->normalizeZipEntry((string) $zip->getNameIndex($i));
            if ($entry === '' || !$this->isSafeZipEntry($entry)) {
                $this->removeDir($destRoot);

                return false;
            }
            if ($prefix !== '' && !str_starts_with($entry, $prefix)) {
                continue;
            }
            $rel = $prefix !== '' ? substr($entry, $plen) : $entry;
            if ($rel === '') {
                continue;
            }

            $target = $destRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (str_ends_with($entry, '/')) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    $this->removeDir($destRoot);

                    return false;
                }
                if (is_link($target) || !$this->isPathInsideRoot($target, $destRootReal)) {
                    $this->removeDir($destRoot);

                    return false;
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                $this->removeDir($destRoot);

                return false;
            }
            if (is_link($parent) || !$this->isPathInsideRoot($parent, $destRootReal)) {
                $this->removeDir($destRoot);

                return false;
            }

            $zipEntry = $zip->getNameIndex($i);
            if (!is_string($zipEntry) || $zipEntry === '') {
                $this->removeDir($destRoot);

                return false;
            }
            $stream = $zip->getStream($zipEntry);
            if ($stream === false) {
                $this->removeDir($destRoot);

                return false;
            }
            if ((file_exists($target) && is_link($target)) || (is_file($target) && !$this->isPathInsideRoot($target, $destRootReal))) {
                fclose($stream);
                $this->removeDir($destRoot);

                return false;
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                $this->removeDir($destRoot);

                return false;
            }
            $copied = stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            if ($copied === false) {
                $this->removeDir($destRoot);

                return false;
            }
            if (is_link($target) || !$this->isPathInsideRoot($target, $destRootReal)) {
                $this->removeDir($destRoot);

                return false;
            }
        }

        return is_file($destRoot . DIRECTORY_SEPARATOR . 'plugin.json');
    }

    /**
     * 确认已存在路径经 realpath 解析后仍落在解压根目录内。
     */
    private function isPathInsideRoot(string $path, string $rootReal): bool
    {
        $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
        $resolved = realpath($path);
        if ($resolved === false) {
            return false;
        }
        if ($resolved === $rootReal) {
            return true;
        }

        return str_starts_with($resolved, $rootReal . DIRECTORY_SEPARATOR);
    }

    private function normalizeZipEntry(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        return $path;
    }

    public function isSafeZipEntry(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return false;
        }

        return true;
    }

    /**
     * 打包前扫描：文件数 / 字节数 / 样例路径（供后台打包进度 UI）。
     *
     * @return ServiceResult{file_count:int,byte_count:int,samples:list<string>,identifier:string,version:string}
     */
    public function packStats(string $identifier): ServiceResult
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $this->safeIdentifier($identifier) === null) {
            return ServiceResult::fail('插件标识无效');
        }
        $src = app(PluginService::class)->weappRoot() . $identifier;
        if (!is_dir($src) || !is_file($src . DIRECTORY_SEPARATOR . 'plugin.json')) {
            return ServiceResult::fail('插件目录不存在');
        }
        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return ServiceResult::fail('plugin.json 无效');
        }

        $fileCount = 0;
        $byteCount = 0;
        $samples   = [];
        $it        = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $fileCount++;
            $byteCount += (int) $file->getSize();
            if (count($samples) < 12) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
                if ($rel !== '') {
                    $samples[] = $rel;
                }
            }
        }

        return ServiceResult::ok([
            'identifier' => $identifier,
            'version'    => (string) ($manifest['version'] ?? '1.0.0'),
            'file_count' => $fileCount,
            'byte_count' => $byteCount,
            'samples'    => $samples,
        ]);
    }

    /**
     * @return ServiceResult
     */
    public function exportZip(string $identifier): ServiceResult
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $this->safeIdentifier($identifier) === null) {
            return ServiceResult::fail('插件标识无效');
        }
        $src = app(PluginService::class)->weappRoot() . $identifier;
        if (!is_dir($src) || !is_file($src . DIRECTORY_SEPARATOR . 'plugin.json')) {
            return ServiceResult::fail('插件目录不存在');
        }
        $manifest = app(PluginService::class)->readManifest($identifier);
        if ($manifest === null) {
            return ServiceResult::fail('plugin.json 无效');
        }
        if (empty($manifest['_manifest_valid'])) {
            $errs = is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : ['清单无效'];

            return ServiceResult::fail('无法打包：' . implode('；', $errs));
        }
        if (!class_exists(\ZipArchive::class)) {
            return ServiceResult::fail('服务器未启用 ZipArchive 扩展');
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pivark_weapp_' . $identifier . '_' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ServiceResult::fail('无法创建 zip 文件');
        }

        $prefix = $identifier . '/';
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $rel  = $prefix . str_replace('\\', '/', substr($path, strlen($src) + 1));
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($rel, '/'));
            } else {
                $zip->addFile($path, $rel);
            }
        }
        $zip->close();

        if (!is_file($tmp)) {
            return ServiceResult::fail('打包失败');
        }

        app(PluginPackageSignatureService::class)->writeSidecar($tmp, $identifier, $manifest);

        $version = (string) ($manifest['version'] ?? '1.0.0');
        $filename = $identifier . '-' . preg_replace('/[^a-z0-9._-]+/i', '-', $version) . '.zip';

        $this->auditLogService->operate('导出插件包', 'admin.plugin', ['identifier' => $identifier]);

        return ServiceResult::ok(['file' => $tmp, 'filename' => $filename], 'ok');
    }

    /**
     * 在架市场货禁止无授权上传（ADR 应用市场货架与分发授权）。
     * 自用 local/personal/enterprise 且不在 Feed/目录 → 放行。
     *
     * @param array<string, mixed> $manifest
     */
    private function marketListedUploadGuard(string $identifier, array $manifest): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $remote = app(\app\common\service\plugin\market\PluginMarketShelfDirectory::class)
            ->indexByIdentifier()[$identifier] ?? null;
        $onShelf = is_array($remote);
        $ssotItem = $onShelf && (
            (string) ($remote['ssot'] ?? '') === 'item'
            || strtolower(trim((string) ($remote['publisher_type'] ?? ''))) === 'official'
        );

        // 不在架：按自用类型放行（含未声明时允许互导，仍过审计）
        if (!$ssotItem) {
            return null;
        }

        if (app(\app\common\service\plugin\entitlement\EntitlementService::class)->can($identifier)) {
            return null;
        }

        return '「' . $identifier . '」为应用市场上架插件，请先在应用市场购买/领取授权后再安装，禁止直接上传绕过';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                LocalFile::unlinkIfExists($path);
            }
        }
        LocalFile::rmdirIfExists($dir);
    }
}
