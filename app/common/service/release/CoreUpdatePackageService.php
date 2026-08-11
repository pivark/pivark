<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中台
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 核心在线升级 — 安装包下载、校验、解压与覆盖
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
use app\common\service\infra\CurlTlsService;
use app\common\support\CoreUpdatePathRules;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

final class CoreUpdatePackageService
{

    public function __construct(
        private readonly CurlTlsService $curlTlsService,
        private readonly ConfigService $configService,
        private readonly CoreUpdateSignatureService $coreUpdateSignatureService,
    ) {
    }

    public const WORK_DIR = 'data/runtime/core_update_work';
    private const DEFAULT_PACKAGE_BYTES = 83886080;
    public const DOWNLOAD_CHUNK_BYTES = 2097152;

    public function resolveLocalPackagePath(string $downloadUrl): string
    {
        $downloadUrl = trim($downloadUrl);
        if ($downloadUrl === '') {
            return '';
        }

        $path = (string) (parse_url($downloadUrl, PHP_URL_PATH) ?: '');
        if ($path === '' && str_starts_with($downloadUrl, '/')) {
            $path = $downloadUrl;
        }
        if ($path === '') {
            return '';
        }

        $candidates = [];
        if (str_starts_with($path, '/static/')) {
            $rel = ltrim($path, '/');
            $candidates[] = ProjectPaths::root() . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
        if (str_starts_with($path, '/public/')) {
            $candidates[] = ProjectPaths::root() . substr($path, 1);
        }

        foreach ($candidates as $candidate) {
            $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public function estimatePackageBytes(string $downloadUrl): int
    {
        $local = $this->resolveLocalPackagePath($downloadUrl);
        if ($local !== '' && is_file($local)) {
            return max(1, (int) filesize($local));
        }

        $length = $this->fetchRemoteContentLength($downloadUrl);
        if ($length > 0) {
            return $length;
        }

        return self::DEFAULT_PACKAGE_BYTES;
    }

    public function extractPackage(string $package, string $dest): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            return false;
        }
        if (!$this->validateZipEntries($zip)) {
            $zip->close();

            return false;
        }
        LocalFile::mkdirIfMissing($dest);
        $ok = $zip->extractTo($dest);
        $zip->close();

        return $ok;
    }

    /**
     * 指纹修复：优先本机 /static/release 包，否则缓存下载到 work 目录。
     */
    public function ensureCachedPackage(string $downloadUrl): ServiceResult
    {
        $downloadUrl = trim($downloadUrl);
        if ($downloadUrl === '') {
            return ServiceResult::fail('官方升级包地址为空，无法补齐/替换文件');
        }

        $local = $this->resolveLocalPackagePath($downloadUrl);
        if ($local !== '' && is_file($local) && filesize($local) > 0) {
            return ServiceResult::ok(['package' => $local, 'cached' => false], '使用本机官方包');
        }

        $resolved = $this->resolveDownloadUrl($downloadUrl);
        if (!preg_match('#^https?://#i', $resolved)) {
            return ServiceResult::fail('无法解析官方升级包下载地址');
        }

        $cacheName = 'fingerprint-repair-' . substr(hash('sha256', $resolved), 0, 16) . '.zip';
        $dest = $this->workDirAbs($cacheName);
        if (is_file($dest) && filesize($dest) > 1024) {
            return ServiceResult::ok(['package' => $dest, 'cached' => true], '使用已缓存官方包');
        }

        LocalFile::mkdirIfMissing(dirname($dest));
        if (!$this->downloadToFile($resolved, $dest)) {
            return ServiceResult::fail('下载官方升级包失败，请检查网络或先执行一次核心升级下载');
        }

        return ServiceResult::ok(['package' => $dest, 'cached' => true], '已下载官方包');
    }

    /**
     * 从升级 zip 抽出指定相对路径到站点根（支持 zip 顶层目录前缀）。
     *
     * @param list<string> $relPaths
     * @return array{ok:list<string>, fail:list<array{path:string,reason:string}>}
     */
    public function extractRelativePathsToRoot(string $package, array $relPaths): array
    {
        $ok = [];
        $fail = [];
        if (!class_exists(ZipArchive::class) || !is_readable($package)) {
            foreach ($relPaths as $p) {
                $fail[] = ['path' => $p, 'reason' => '升级包不可读或缺少 ZipArchive'];
            }

            return ['ok' => $ok, 'fail' => $fail];
        }

        $zip = new ZipArchive();
        if ($zip->open($package) !== true) {
            foreach ($relPaths as $p) {
                $fail[] = ['path' => $p, 'reason' => '无法打开升级包'];
            }

            return ['ok' => $ok, 'fail' => $fail];
        }
        if (!$this->validateZipEntries($zip)) {
            $zip->close();
            foreach ($relPaths as $p) {
                $fail[] = ['path' => $p, 'reason' => '升级包路径校验失败'];
            }

            return ['ok' => $ok, 'fail' => $fail];
        }

        $indexByRel = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $rel = ltrim($name, '/');
            if (preg_match('#^[^/]+/(app|public|template|vendor|config|index\.php|RELEASE\.json|weapp)/#', $rel) === 1) {
                $rel = preg_replace('#^[^/]+/#', '', $rel) ?? $rel;
            }
            $indexByRel[$rel] = $name;
        }

        $root = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR;
        foreach ($relPaths as $rel) {
            $rel = str_replace('\\', '/', trim($rel, '/'));
            if ($rel === '' || str_contains($rel, '..')) {
                $fail[] = ['path' => $rel, 'reason' => '路径非法'];
                continue;
            }
            if (!isset($indexByRel[$rel])) {
                $fail[] = ['path' => $rel, 'reason' => '官方包中无此文件'];
                continue;
            }
            $entry = $indexByRel[$rel];
            $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            LocalFile::mkdirIfMissing(dirname($abs));
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                $fail[] = ['path' => $rel, 'reason' => '无法读取包内文件'];
                continue;
            }
            $fp = fopen($abs, 'wb');
            if ($fp === false) {
                fclose($stream);
                $fail[] = ['path' => $rel, 'reason' => '无法写入本站文件'];
                continue;
            }
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                fwrite($fp, $chunk);
            }
            fclose($stream);
            fclose($fp);
            $ok[] = $rel;
        }
        $zip->close();

        return ['ok' => $ok, 'fail' => $fail];
    }

    public function applyExtractedTree(string $workRoot): int
    {
        $root = rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR;
        $count = 0;

        foreach (CoreUpdatePathRules::coreUpdateApplyPrefixes() as $prefix) {
            $src = $workRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefix, '/'));
            if (!file_exists($src)) {
                continue;
            }
            if (is_file($src)) {
                $dst = $root . str_replace('/', DIRECTORY_SEPARATOR, $prefix);
                LocalFile::mkdirIfMissing(dirname($dst));
                if (LocalFile::copyQuiet($src, $dst, 'core_update_apply_file')) {
                    ++$count;
                }
                continue;
            }
            $count += $this->mirrorAllowed($src, $root, $prefix);
        }

        foreach (CoreUpdatePathRules::coreUpdateConfigFiles() as $rel) {
            $src = $workRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($src)) {
                continue;
            }
            $dst = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            LocalFile::mkdirIfMissing(dirname($dst));
            if (LocalFile::copyQuiet($src, $dst, 'core_update_config')) {
                ++$count;
            }
        }

        $count += $this->applyBundledWeapp($workRoot, $root);
        $count += $this->pruneReplaceTrees($workRoot, $root);
        $count += $this->applyExplicitDeletes($root);

        return $count;
    }

    /**
     * 升级 finalize：按「当前已落地」的 PathRules 再 apply 一次包树。
     * apply 步跑的是升级前旧代码白名单，新增前缀（如 public/static/theme/）必须在 finalize 补齐；
     * 同时仍含 replace-tree prune（admin dist 哈希垃圾）。
     */
    public function pruneAfterUpgrade(string $packagePath): int
    {
        $packagePath = trim($packagePath);
        if ($packagePath === '' || !is_file($packagePath) || !class_exists(ZipArchive::class)) {
            return $this->applyExplicitDeletes(rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR);
        }
        $work = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pivark_core_prune_' . bin2hex(random_bytes(4));
        LocalFile::mkdirIfMissing($work);
        try {
            if (!$this->extractPackage($packagePath, $work)) {
                return $this->applyExplicitDeletes(rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR);
            }

            return $this->applyExtractedTree($work);
        } finally {
            LocalFile::removeDirRecursive($work, 'core_update_prune_work');
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return ServiceResult
     */
    public function finalizeDownloadedPackage(array $job, string $jobId): ServiceResult
    {
        $package = (string) ($job['package_path'] ?? '');
        $sha256 = (string) ($job['sha256'] ?? '');
        if ($sha256 !== '' && strtolower((string) hash_file('sha256', $package)) !== strtolower($sha256)) {
            LocalFile::unlinkQuiet($package, 'core_update_package');

            return ServiceResult::fail('安装包 SHA256 校验失败，已中止');
        }

        $signature = trim((string) ($job['signature'] ?? ''));
        $signSvc   = $this->coreUpdateSignatureService;
        if ($signSvc->isRequireSignature() && $signature === '') {
            LocalFile::unlinkQuiet($package, 'core_update_package');

            return ServiceResult::fail('升级包缺少 RSA 签名（已启用强制验签）');
        }
        if ($signature !== '' && $sha256 !== '') {
            if (!$signSvc->hasPublicKey()) {
                LocalFile::unlinkQuiet($package, 'core_update_package');

                return ServiceResult::fail('收到升级包签名但未配置验签公钥');
            }
            $signed = $signSvc->verifySha256Hex($sha256, $signature);
            if (!$signed->isOk()) {
                LocalFile::unlinkQuiet($package, 'core_update_package');

                return ServiceResult::fail((string) ($signed->message() ?? 'RSA 签名校验失败'));
            }
        }

        return ServiceResult::ok([
                'job_id'         => $jobId,
                'step'           => 'downloaded',
                'done'           => true,
                'size'           => (int) filesize($package),
                'download_bytes' => (int) filesize($package),
                'download_total' => (int) filesize($package),
                'download_pct'   => 100,
            ], '安装包下载并校验成功');
    }

    /**
     * @return array{ok:bool,msg?:string,complete?:bool,total?:int}
     */
    public function downloadChunk(string $url, string $dest, int $offset, int $length): array
    {
        $url = $this->resolveDownloadUrl($url);
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'msg' => '下载地址无效'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'msg' => '需要 curl 扩展以分块下载'];
        }

        LocalFile::mkdirIfMissing(dirname($dest));
        $mode = $offset > 0 ? 'ab' : 'wb';
        $fp = fopen($dest, $mode);
        if ($fp === false) {
            return ['ok' => false, 'msg' => '无法写入下载临时文件'];
        }

        $end = $offset + max(1, $length) - 1;
        $contentRange = '';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_RANGE, $offset . '-' . $end);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: PivArk-CoreUpdatePackage/1.0']);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$contentRange): int {
            if (stripos($header, 'Content-Range:') === 0) {
                $contentRange = trim($header);
            }

            return strlen($header);
        });
        $this->curlTlsService->applyToCurl($ch);
        $ok = curl_exec($ch) !== false && (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok && !in_array($httpCode, [200, 206, 416], true)) {
            if ($offset === 0) {
                LocalFile::unlinkQuiet($dest, 'core_update_package_part');
                if ($this->downloadToFile($url, $dest)) {
                    return ['ok' => true, 'complete' => true, 'total' => (int) filesize($dest)];
                }
            }

            return ['ok' => false, 'msg' => '下载分块失败（HTTP ' . $httpCode . '）'];
        }

        $newSize = is_file($dest) ? (int) filesize($dest) : 0;
        $bytesWritten = max(0, $newSize - $offset);
        // Content-Range: bytes 0-2097151/77022551 → 远程全长；禁止用当前已下字节冒充 total（否则 2MB 一片就误判完成）
        $remoteTotal = 0;
        if ($contentRange !== '' && preg_match('/\/(\d+)\s*$/', $contentRange, $m)) {
            $remoteTotal = (int) $m[1];
        }
        if ($httpCode === 200) {
            // 服务端忽略 Range，整包一次返回
            return ['ok' => true, 'complete' => true, 'total' => $newSize];
        }
        $complete = ($remoteTotal > 0 && $newSize >= $remoteTotal)
            || ($bytesWritten > 0 && $bytesWritten < $length);

        return [
            'ok'       => true,
            'complete' => $complete,
            'total'    => $remoteTotal > 0 ? $remoteTotal : 0,
        ];
    }

    public function workDirAbs(string $suffix = ''): string
    {
        $dir = $this->absPath(self::WORK_DIR);
        if ($suffix !== '') {
            $dir .= DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $suffix), DIRECTORY_SEPARATOR);
        }

        return $dir;
    }

    private function resolveDownloadUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $site = trim((string) $this->configService->get('site_url', ''));
            if ($site !== '' && str_starts_with($url, '/')) {
                $url = rtrim($site, '/') . $url;
            }
        }

        return $url;
    }

    private function downloadToFile(string $url, string $dest): bool
    {
        $url = $this->resolveDownloadUrl($url);
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        if (function_exists('curl_init')) {
            $fp = fopen($dest, 'wb');
            if ($fp === false) {
                return false;
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            $this->curlTlsService->applyToCurl($ch);
            $ok = curl_exec($ch) !== false && (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
            curl_close($ch);
            fclose($fp);
            if (!$ok) {
                LocalFile::unlinkQuiet($dest, 'core_update_download');
            }

            return $ok && is_file($dest) && filesize($dest) > 0;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 600,
                'ignore_errors' => true,
                'header'        => "User-Agent: PivArk-CoreUpdatePackage/1.0\r\n",
            ],
        ]);
        $raw = LocalFile::getContents($url, false, $ctx);
        if ($raw === null || $raw === '') {
            return false;
        }

        return LocalFile::putContents($dest, $raw) && is_file($dest);
    }

    private function applyBundledWeapp(string $workRoot, string $root): int
    {
        $count = 0;
        $weappRoot = $workRoot . DIRECTORY_SEPARATOR . 'weapp';
        if (!is_dir($weappRoot)) {
            return 0;
        }
        // Community 安装/升级包明文随包：doc_comment（旧名 comment 已退役）
        $keep = ['doc_comment', 'README.md', 'GATEWAY.md', '.gitkeep'];
        foreach ($keep as $name) {
            $src = $weappRoot . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($src)) {
                continue;
            }
            $dst = $root . 'weapp' . DIRECTORY_SEPARATOR . $name;
            if (is_file($src)) {
                LocalFile::mkdirIfMissing(dirname($dst));
                if (LocalFile::copyQuiet($src, $dst, 'core_update_weapp')) {
                    ++$count;
                }
                continue;
            }
            $count += $this->mirrorAllowed($src, $root, 'weapp/' . $name . '/');
        }

        return $count;
    }

    private function mirrorAllowed(string $srcDir, string $root, string $relPrefix): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $rel = $relPrefix . substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $srcDir)) + 1);
            $rel = str_replace('\\', '/', $rel);
            if (CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
                continue;
            }
            $dst = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                LocalFile::mkdirIfMissing($dst);
                continue;
            }
            LocalFile::mkdirIfMissing(dirname($dst));
            if (LocalFile::copyQuiet($item->getPathname(), $dst, 'core_update_mirror')) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * 替换树：包内有清单的前缀，删除目标站多出的文件（admin dist 旧哈希等）。
     */
    private function pruneReplaceTrees(string $workRoot, string $root): int
    {
        $removed = 0;
        foreach (CoreUpdatePathRules::coreUpdateReplaceTreePrefixes() as $prefix) {
            $prefix = trim(str_replace('\\', '/', $prefix), '/') . '/';
            $srcDir = $workRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefix, '/'));
            $dstDir = $root . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefix, '/'));
            if (!is_dir($srcDir) || !is_dir($dstDir)) {
                continue;
            }
            $keep = $this->collectRelativeFiles($srcDir, $prefix);
            $existing = $this->collectRelativeFiles($dstDir, $prefix);
            foreach (array_keys($existing) as $rel) {
                if (isset($keep[$rel])) {
                    continue;
                }
                if (CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
                    continue;
                }
                $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_file($abs) && LocalFile::unlinkQuiet($abs, 'core_update_prune_tree')) {
                    ++$removed;
                }
            }
            $this->pruneEmptyDirsUnder($dstDir);
        }

        return $removed;
    }

    /** 显式删除清单（文件或目录）。 */
    private function applyExplicitDeletes(string $root): int
    {
        $removed = 0;
        foreach (CoreUpdatePathRules::coreUpdateDeletePaths() as $rel) {
            $rel = trim(str_replace('\\', '/', (string) $rel), '/');
            if ($rel === '' || CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
                continue;
            }
            if (str_starts_with($rel, 'data/') || str_starts_with($rel, 'public/uploads/') || $rel === '.env') {
                continue;
            }
            $abs = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($abs)) {
                if (LocalFile::unlinkQuiet($abs, 'core_update_delete_path')) {
                    ++$removed;
                }
                continue;
            }
            if (is_dir($abs)) {
                LocalFile::removeDirRecursive($abs, 'core_update_delete_path');
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return array<string, true> rel => true
     */
    private function collectRelativeFiles(string $absDir, string $relPrefix): array
    {
        $out = [];
        $absDir = rtrim(str_replace('\\', '/', $absDir), '/') . '/';
        $relPrefix = rtrim(str_replace('\\', '/', $relPrefix), '/') . '/';
        if (!is_dir($absDir)) {
            return $out;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $pathname = str_replace('\\', '/', $item->getPathname());
            $suffix = substr($pathname, strlen($absDir));
            $out[$relPrefix . $suffix] = true;
        }

        return $out;
    }

    private function pruneEmptyDirsUnder(string $absDir): void
    {
        if (!is_dir($absDir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isDir()) {
                continue;
            }
            $path = $item->getPathname();
            $entries = scandir($path);
            if ($entries === ['.', '..']) {
                rmdir($path);
            }
        }
    }

    private function fetchRemoteContentLength(string $url): int
    {
        $url = $this->resolveDownloadUrl($url);
        if (!preg_match('#^https?://#i', $url)) {
            return 0;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            $this->curlTlsService->applyToCurl($ch);
            curl_exec($ch);
            $length = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            if ($length > 0) {
                return $length;
            }
        }

        $headers = @get_headers($url, true);
        if (is_array($headers)) {
            $value = $headers['Content-Length'] ?? $headers['content-length'] ?? 0;
            if (is_array($value)) {
                $value = end($value);
            }
            $length = (int) $value;
            if ($length > 0) {
                return $length;
            }
        }

        return 0;
    }

    private function validateZipEntries(ZipArchive $zip): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return false;
            }
            $name = (string) ($stat['name'] ?? '');
            if (!$this->isSafeZipEntryName($name)) {
                return false;
            }
            if ($this->isZipSymlinkEntry($zip, $i)) {
                return false;
            }
        }

        return true;
    }

    private function isSafeZipEntryName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return false;
        }

        return true;
    }

    private function isZipSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }
        $opsys = 0;
        $attrs = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attrs)) {
            return false;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attrs >> 16) & 0170000) === 0120000;
    }

    private function absPath(string $rel): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }
}
