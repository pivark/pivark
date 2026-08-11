<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from UploadService — 校验、落盘与上传处理
 */
declare(strict_types=1);

namespace app\common\service\upload;


use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\exception\UploadException;
use app\common\service\config\ConfigService;
use app\common\service\front\FrontAuthService;
use app\common\service\media\MediaAssetRefService;
use app\common\service\media\MediaAssetService;
use app\common\service\watermark\WatermarkService;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\SvgSanitizer;
use think\facade\Config;
use think\file\UploadedFile;

class UploadFileService
{
    private readonly string $type;
    private readonly string $scene;

    public function __construct(string $type, string $scene)
    {
        $this->type  = $type;
        $this->scene = UploadSceneService::normalizeScene($scene);
        UploadSceneService::assertScene($this->scene);
    }

    public function getScene(): string
    {
        return $this->scene;
    }

    /**
     * @param array{content_hash?:string,file_size?:int,force_upload?:bool,force_security_upload?:bool,admin_confirm_password?:string} $meta
     * @return ServiceResult
     */
    public function handle(?UploadedFile $file, array $meta = []): ServiceResult
    {
        try {
            if ($file === null) {
                throw new UploadException('请选择上传文件', UploadException::EMPTY);
            }
            if (!$file->isValid()) {
                throw new UploadException('上传无效或已中断', UploadException::EMPTY);
            }

            $hash        = app(MediaAssetService::class)->normalizeHash((string) ($meta['content_hash'] ?? ''));
            $size        = (int) ($meta['file_size'] ?? 0);
            if ($size <= 0) {
                $size = self::uploadedFileSize($file);
            }
            $forceUpload = !empty($meta['force_upload']);
            $forceSecurity = !empty($meta['force_security_upload']);
            $adminConfirmPassword = (string) ($meta['admin_confirm_password'] ?? '');

            if ($hash === '') {
                $hash = app(MediaAssetService::class)->computeSha256($file->getPathname());
            }

            if (!$forceUpload && $hash !== '') {
                $dup = app(MediaAssetService::class)->findByHash($hash, $size > 0 ? $size : null);
                if ($dup !== null) {
                    app(MediaAssetService::class)->touchReuse((int) $dup['id']);
                    $origName = (string) $file->getOriginalName();
                    if ($origName !== '' && app(MediaAssetRefService::class)->isEnabled()) {
                        app(MediaAssetRefService::class)->addAlias(
                            (int) $dup['id'],
                            $origName,
                            $this->currentUploaderId(),
                        );
                    }

                    return ServiceResult::duplicate(array_merge(
                            app(MediaAssetService::class)->formatForClient($dup),
                            ['duplicate' => true, 'reused' => true]
                        ), '检测到相同文件已存在，已复用');
                }
            }

            $data = $this->store($file, $hash, $forceSecurity, $adminConfirmPassword);
            app(UploadQueueService::class)->enqueue($this->scene, [
                'sha256'   => $hash,
                'path'     => (string) ($data['url'] ?? $data['path'] ?? ''),
                'media_id' => (int) ($data['media_id'] ?? 0),
            ]);

            return ServiceResult::ok($data, '上传成功');
        } catch (UploadException $e) {
            return $e->toJson();
        } catch (\Throwable $e) {
            if ((bool) env('APP_DEBUG', false)) {
                return ServiceResult::fail('上传失败：' . $e->getMessage());
            }

            return ServiceResult::fail('上传失败，请稍后重试或联系管理员');
        }
    }

    /**
     * @return ServiceResult
     */
    public function checkDuplicate(string $hash, int $fileSize = 0): ServiceResult
    {
        if (!app(MediaAssetService::class)->isEnabled()) {
            return ServiceResult::ok(['duplicate' => false], 'ok');
        }

        $hash = app(MediaAssetService::class)->normalizeHash($hash);
        if ($hash === '') {
            return ServiceResult::fail('无效的文件哈希');
        }

        $dup = app(MediaAssetService::class)->findByHash($hash, $fileSize > 0 ? $fileSize : null);
        if ($dup === null) {
            return ServiceResult::ok(['duplicate' => false], 'ok');
        }

        return ServiceResult::duplicate(array_merge(
                app(MediaAssetService::class)->formatForClient($dup),
                ['duplicate' => true]
            ), '检测到相同文件已存在');
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     * @throws UploadException
     */
    public function store(
        UploadedFile $file,
        string $contentHash = '',
        bool $forceSecurity = false,
        string $adminConfirmPassword = '',
    ): array {
        $this->assertSecurityScan(
            $file->getPathname(),
            (string) $file->getOriginalName(),
            $forceSecurity,
            $adminConfirmPassword,
        );
        $ext = $this->resolveExtension($file);
        $this->validateExtension($ext);
        $fileSize = self::uploadedFileSize($file);
        $this->validateSizeBytes($fileSize);
        $this->validateMime($file, $ext);

        $dir      = $this->absoluteTargetDir();
        $filename = $this->buildFilename($file, $ext);
        $target   = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($ext === 'svg') {
            $this->saveSanitizedSvg($file, $target);
        } else {
            $saved = $file->move($dir, $filename);
            if ($saved === false) {
                throw new UploadException('文件保存失败', UploadException::SAVE_FAILED);
            }
            if ($this->type === 'image') {
                app(WatermarkService::class)->maybeApply($target, $ext);
            }
        }

        if ($fileSize <= 0 && is_file($target)) {
            $fileSize = (int) filesize($target);
        }

        return $this->finalizeStoredFile($target, $filename, $ext, $file->getOriginalName(), $contentHash, $fileSize);
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     * @throws UploadException
     */
    public function storeFromLocalFile(
        string $sourcePath,
        string $originalName,
        string $contentHash = '',
        bool $forceSecurity = false,
        string $adminConfirmPassword = '',
    ): array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new UploadException('无法读取合并文件', UploadException::SAVE_FAILED);
        }

        $this->assertSecurityScan($sourcePath, $originalName, $forceSecurity, $adminConfirmPassword);

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if ($ext === '') {
            $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        }

        $this->validateExtension($ext);
        $size = (int) filesize($sourcePath);
        $this->validateSizeBytes($size);
        $this->validateMimeOnPath($sourcePath, $ext);

        $hash = app(MediaAssetService::class)->normalizeHash($contentHash);
        if ($hash === '') {
            $hash = app(MediaAssetService::class)->computeSha256($sourcePath);
        }

        $dir      = $this->absoluteTargetDir();
        $filename = $this->buildFilenameFromName($originalName, $ext);
        $target   = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($ext === 'svg') {
            $clean = SvgSanitizer::clean((string) file_get_contents($sourcePath));
            if ($clean === null) {
                throw new UploadException('SVG 含有不安全内容或格式无效', UploadException::INVALID_MIME);
            }
            if (file_put_contents($target, $clean, LOCK_EX) === false) {
                throw new UploadException('文件保存失败', UploadException::SAVE_FAILED);
            }
        } elseif (!rename($sourcePath, $target)) {
            if (!copy($sourcePath, $target)) {
                throw new UploadException('文件保存失败', UploadException::SAVE_FAILED);
            }
            LocalFile::unlinkIfExists($sourcePath);
        }

        if ($this->type === 'image' && $ext !== 'svg') {
            app(WatermarkService::class)->maybeApply($target, $ext);
        }

        return $this->finalizeStoredFile($target, $filename, $ext, $originalName, $hash, $size);
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $formats = app(ConfigService::class)->get(
            $this->typeConfig('formats_key'),
            $this->typeConfig('default_formats')
        );
        $list = $this->parseFormats((string) $formats);
        if (!$this->svgUploadAllowed()) {
            $list = array_values(array_filter($list, static fn (string $e): bool => $e !== 'svg'));
        }

        return $list;
    }

    public function svgUploadAllowed(): bool
    {
        return (bool) Config::get('upload.allow_svg_upload', false);
    }

    public function maxSizeMb(): float
    {
        $key = (string) Config::get('upload.config_keys.max_size', 'upload_max_size');

        return max(0.1, (float) app(ConfigService::class)->get($key, '2'));
    }

    /**
     * @return array{wap_adapt:bool,add_title:bool,add_alt:bool,alt_replace:bool}
     */
    public function editorContentOptions(): array
    {
        $keys = Config::get('upload.editor_content_keys', []);
        if (!is_array($keys)) {
            $keys = [];
        }
        $read = static fn (string $cfgKey): bool => app(ConfigService::class)->get($cfgKey, '1') === '1';

        return [
            'wap_adapt'   => isset($keys['wap_adapt']) ? $read((string) $keys['wap_adapt']) : true,
            'add_title'   => isset($keys['add_title']) ? $read((string) $keys['add_title']) : true,
            'add_alt'     => isset($keys['add_alt']) ? $read((string) $keys['add_alt']) : true,
            'alt_replace' => isset($keys['alt_replace']) ? $read((string) $keys['alt_replace']) : true,
        ];
    }

    /** @return array<string, mixed> */
    public function clientUploadConfig(): array
    {
        $security = app(UploadSecurityScanService::class)->engineStatus();

        return [
            'dedup_enabled'   => app(MediaAssetService::class)->isEnabled(),
            'chunk_enabled'   => app(UploadChunkService::class)->isEnabled(),
            'chunk_threshold' => app(UploadChunkService::class)->thresholdBytes(),
            'chunk_part_size' => app(UploadChunkService::class)->defaultPartSizeBytes(),
            'security_scan'   => $security,
        ];
    }

    /**
     * 普通文件上传安全闸：仅当「每文件扫病毒」开关打开时重扫。
     * block 不可绕；warn 仅后台运营可二次密码强制。
     *
     * @throws UploadException
     */
    private function assertSecurityScan(
        string $absPath,
        string $displayName,
        bool $forceSecurity,
        string $adminConfirmPassword,
    ): void {
        $scanner = app(UploadSecurityScanService::class);
        if (!$scanner->isEveryFileScanEnabled()) {
            return;
        }

        $report = $scanner->scanPath($absPath, $displayName, UploadSecurityScanService::CONTEXT_FILE);
        $level = (string) ($report['level'] ?? 'pass');
        if ($level === 'pass') {
            return;
        }

        /** @var list<array{code?:string,severity?:string,path?:string,line?:?int,message?:string}> $findings */
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $summary = $this->formatSecuritySummary($findings);

        if ($level === 'block') {
            throw new UploadException(
                '安全扫描未通过（硬拒）：' . $summary,
                UploadException::SECURITY_BLOCK,
                0,
                null,
                $findings,
            );
        }

        if ($forceSecurity && $this->adminMayForceSecurityUpload($adminConfirmPassword)) {
            OpsLog::businessWarning('upload_force_security', [
                'path'     => $displayName,
                'findings' => count($findings),
            ]);

            return;
        }

        throw new UploadException(
            '安全扫描发现风险：' . $summary . '。后台运营可二次确认密码后强制上传；前台不可强制。',
            UploadException::SECURITY_WARN,
            0,
            null,
            $findings,
        );
    }

    /**
     * @param list<array{code?:string,severity?:string,path?:string,line?:?int,message?:string}> $findings
     */
    private function formatSecuritySummary(array $findings): string
    {
        $parts = [];
        foreach (array_slice($findings, 0, 5) as $f) {
            $path = (string) ($f['path'] ?? '');
            $line = $f['line'] ?? null;
            $msg  = (string) ($f['message'] ?? '');
            $bit  = $path !== '' ? $path : '未知文件';
            if (is_int($line) && $line > 0) {
                $bit .= ':' . $line;
            }
            if ($msg !== '') {
                $bit .= ' — ' . $msg;
            }
            $parts[] = $bit;
        }

        return $parts !== [] ? implode('；', $parts) : '存在风险';
    }

    private function adminMayForceSecurityUpload(string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $admin = \think\facade\Session::get('admin_user');
        if (!is_array($admin)) {
            return false;
        }
        $uid = (int) ($admin['id'] ?? 0);
        if ($uid < 1) {
            return false;
        }

        return app(\app\common\service\admin\AdminSensitiveConfirmService::class)
            ->verifyPassword($uid, $password);
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $ext = strtolower($file->extension() ?: pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        return $ext;
    }

    /**
     * 直传 issue/callback 与本地上传共用扩展名策略。
     *
     * @throws UploadException
     */
    public function assertExtension(string $ext): void
    {
        $this->validateExtension($ext);
    }

    /**
     * @throws UploadException
     */
    public function assertFilenameExtension(string $filename): void
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $this->validateExtension($ext);
    }

    private function validateExtension(string $ext): void
    {
        if ($ext === '') {
            throw new UploadException('无法识别文件类型', UploadException::INVALID_EXT);
        }

        if (str_contains($ext, '.') || preg_match('/^(php|phtml|php3|php4|php5|php7|php8|phps|pht|phar|shtml|inc|jsp|asp|aspx|cgi|sh|htaccess)$/i', $ext)) {
            throw new UploadException('不允许上传该类型文件', UploadException::INVALID_EXT);
        }

        $dangerous = Config::get('upload.dangerous_extensions', []);
        if (is_array($dangerous) && in_array($ext, $dangerous, true)) {
            throw new UploadException('不允许上传该类型文件', UploadException::INVALID_EXT);
        }

        if (!in_array($ext, $this->allowedExtensions(), true)) {
            $display = strtoupper(implode('/', $this->allowedExtensions()));
            throw new UploadException("仅支持 {$display} 格式", UploadException::INVALID_EXT);
        }
    }

    private static function uploadedFileSize(UploadedFile $file): int
    {
        $path = $file->getPathname();
        if ($path !== '' && is_file($path)) {
            $bytes = @filesize($path);
            if ($bytes !== false) {
                return (int) $bytes;
            }
        }

        try {
            return max(0, (int) $file->getSize());
        } catch (\Throwable $e) {
            OpsLog::businessWarning('upload_file_size_probe_failed', ['msg' => $e->getMessage()]);

            return 0;
        }
    }

    private function validateSizeBytes(int $size): void
    {
        $maxMb    = $this->maxSizeMb();
        $maxBytes = (int) ($maxMb * 1024 * 1024);
        if ($size > $maxBytes) {
            throw new UploadException(
                '文件大小不能超过' . rtrim(rtrim((string) $maxMb, '0'), '.') . 'MB',
                UploadException::TOO_LARGE
            );
        }
    }

    private function validateMimeOnPath(string $path, string $ext): void
    {
        $mimesMap = $this->typeConfig('mimes');
        if (!is_array($mimesMap)) {
            if ($this->type === 'image' && $ext !== 'svg') {
                $this->validateImageBinaryOnPath($path);
            }

            return;
        }

        $allowedMimes = $mimesMap[$ext] ?? null;
        $detected     = $this->detectMimeOnPath($path);
        if (!is_array($allowedMimes) || $allowedMimes === []) {
            if ($this->type === 'image' && $ext !== 'svg') {
                $this->validateImageBinaryOnPath($path);
            }

            return;
        }

        if ($detected === '') {
            if ($this->type === 'image' && $ext !== 'svg') {
                $this->validateImageBinaryOnPath($path);
            }

            return;
        }

        $ok = false;
        foreach ($allowedMimes as $mime) {
            if (strcasecmp($detected, (string) $mime) === 0) {
                $ok = true;
                break;
            }
        }

        if (!$ok && $this->type === 'image' && $ext !== 'svg') {
            $this->validateImageBinaryOnPath($path);

            return;
        }

        if (!$ok) {
            throw new UploadException('文件内容与扩展名不匹配', UploadException::INVALID_MIME);
        }
    }

    /**
     * @return array{url:string,path:string,filename:string,content_hash?:string}
     */
    private function finalizeStoredFile(
        string $targetPath,
        string $filename,
        string $ext,
        string $originalName,
        string $contentHash,
        int $fileSize
    ): array {
        $relative = $this->relativePublicPath($filename);
        $hash     = app(MediaAssetService::class)->normalizeHash($contentHash);
        if ($hash === '' && is_file($targetPath)) {
            $hash = app(MediaAssetService::class)->computeSha256($targetPath);
        }

        $mime   = $this->detectMimeOnPath($targetPath);
        $publicUrl = '/' . str_replace('\\', '/', $relative);
        $result    = [
            'url'          => app(\app\common\service\media\MediaUrlService::class)->formatForStorage($publicUrl),
            'path'         => $relative,
            'filename'     => $filename,
            'content_hash' => $hash,
        ];

        if (app(MediaAssetService::class)->isEnabled() && $hash !== '') {
            app(MediaAssetService::class)->registerWithAlias([
                'content_hash'  => $hash,
                'file_size'     => $fileSize > 0 ? $fileSize : (int) (@filesize($targetPath) ?: 0),
                'mime'          => $mime,
                'ext'           => $ext,
                'scene'         => $this->scene,
                'path'          => $relative,
                'url'           => $result['url'],
                'original_name' => $originalName,
                'admin_id'      => $this->currentUploaderId(),
            ]);
        }

        app(UploadRemoteMirrorService::class)->afterLocalStored($targetPath, $relative, $result['url']);

        return $result;
    }

    private function validateMime(UploadedFile $file, string $ext): void
    {
        $mimesMap = $this->typeConfig('mimes');
        if (!is_array($mimesMap)) {
            return;
        }

        $allowedMimes = is_array($mimesMap) ? ($mimesMap[$ext] ?? null) : null;
        if (!is_array($allowedMimes) || $allowedMimes === []) {
            if ($this->type === 'image' && $ext !== 'svg') {
                $this->validateImageBinary($file);
            }

            return;
        }

        $detected = $this->detectMime($file);
        if ($detected === '') {
            if ($this->type === 'image' && $ext !== 'svg') {
                $this->validateImageBinary($file);
            }

            return;
        }

        $ok = false;
        foreach ($allowedMimes as $mime) {
            if (strcasecmp($detected, (string) $mime) === 0) {
                $ok = true;
                break;
            }
        }

        if (!$ok && $this->type === 'image' && $ext !== 'svg') {
            $this->validateImageBinary($file);

            return;
        }

        if (!$ok) {
            throw new UploadException('文件内容与扩展名不匹配', UploadException::INVALID_MIME);
        }
    }

    private function detectMime(UploadedFile $file): string
    {
        return $this->detectMimeOnPath($file->getPathname());
    }

    private function detectMimeOnPath(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime)) {
                    return $mime;
                }
            }
        }

        return '';
    }

    /**
     * @throws UploadException
     */
    private function saveSanitizedSvg(UploadedFile $file, string $targetPath): void
    {
        if (!$this->svgUploadAllowed()) {
            throw new UploadException('不允许上传 SVG，请在 config/upload.php 开启 allow_svg_upload', UploadException::INVALID_EXT);
        }
        $path = $file->getPathname();
        if (!is_readable($path)) {
            throw new UploadException('无法读取上传文件', UploadException::INVALID_MIME);
        }
        $clean = SvgSanitizer::clean((string) file_get_contents($path));
        if ($clean === null) {
            throw new UploadException('SVG 含有不安全内容或格式无效', UploadException::INVALID_MIME);
        }
        if (file_put_contents($targetPath, $clean, LOCK_EX) === false) {
            throw new UploadException('文件保存失败', UploadException::SAVE_FAILED);
        }
    }

    private function validateImageBinary(UploadedFile $file): void
    {
        $this->validateImageBinaryOnPath($file->getPathname());
    }

    private function validateImageBinaryOnPath(string $path): void
    {
        if (!is_file($path)) {
            throw new UploadException('无法读取上传文件', UploadException::INVALID_MIME);
        }
        if (@getimagesize($path) === false) {
            throw new UploadException('不是有效的图片文件', UploadException::INVALID_MIME);
        }
    }

    private function buildFilename(UploadedFile $file, string $ext): string
    {
        return $this->buildFilenameFromName($file->getOriginalName(), $ext);
    }

    private function buildFilenameFromName(string $originalName, string $ext): string
    {
        $ruleKey = (string) Config::get('upload.config_keys.name_rule', 'upload_name_rule');
        $rule    = app(ConfigService::class)->get($ruleKey, 'random');
        $safeExt = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
        if ($safeExt === '') {
            throw new UploadException('无法识别文件类型', UploadException::INVALID_EXT);
        }

        if ($rule === 'original') {
            $name = pathinfo($originalName, PATHINFO_FILENAME);
            $safe = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fff}]/u', '_', (string) $name) ?: 'file';
            $safe = substr($safe, 0, 80);
            if (str_contains($safe, '..') || str_contains($safe, '/') || str_contains($safe, '\\')) {
                $safe = 'file';
            }

            return $safe . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
        }

        return bin2hex(random_bytes(16)) . '.' . $safeExt;
    }

    /** 上传者 id：后台管理员或前台会员（写入 media_asset_aliases.created_by） */
    private function currentUploaderId(): int
    {
        $admin = \think\facade\Session::get('admin_user') ?? [];
        if (is_array($admin) && !empty($admin['id'])) {
            return (int) $admin['id'];
        }

        $member = app(FrontAuthService::class)->current();
        if ($member !== null && !empty($member['id'])) {
            return (int) $member['id'];
        }

        return 0;
    }

    private function absoluteTargetDir(): string
    {
        $dir = $this->publicRootAbsolute() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->relativeDirSegment());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException('无法创建上传目录', UploadException::NOT_WRITABLE);
        }
        if (!is_writable($dir)) {
            throw new UploadException('上传目录不可写: ' . $dir, UploadException::NOT_WRITABLE);
        }
        app(UploadDirProtectService::class)->ensureDirectory($dir);

        return $dir;
    }

    private function relativeDirSegment(): string
    {
        $parts    = [];
        $sceneCfg = UploadSceneService::sceneConfig($this->scene);
        $subdir   = trim((string) ($sceneCfg['subdir'] ?? ''), '/');
        if ($subdir !== '') {
            $parts[] = $subdir;
        }
        $useDirRule = $sceneCfg['use_dir_rule'] ?? true;
        if ($useDirRule) {
            $parts[] = $this->dateDirSegment();
        }

        return implode('/', $parts);
    }

    private function dateDirSegment(): string
    {
        $key  = (string) Config::get('upload.config_keys.dir_rule', 'upload_dir_rule');
        $rule = app(ConfigService::class)->get($key, 'ymd');

        return $rule === 'ym' ? AppTime::format('Ym') : AppTime::format('Ymd');
    }

    private function relativePublicPath(string $filename): string
    {
        $base = trim((string) Config::get('upload.public_dir', 'uploads'), '/');
        $dir  = $this->relativeDirSegment();

        return $dir === '' ? "{$base}/{$filename}" : "{$base}/{$dir}/{$filename}";
    }

    private function publicRootAbsolute(): string
    {
        $base = trim((string) Config::get('upload.public_dir', 'uploads'), '/');

        return ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $base);
    }

    /** @return list<string> */
    private function parseFormats(string $formats): array
    {
        $list = [];
        foreach (explode('|', $formats) as $item) {
            $ext = strtolower(trim($item));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if ($ext !== '' && !in_array($ext, $list, true)) {
                $list[] = $ext;
            }
        }

        return $list;
    }

    private function typeConfig(string $key): mixed
    {
        return Config::get('upload.types.' . $this->type . '.' . $key);
    }
}
