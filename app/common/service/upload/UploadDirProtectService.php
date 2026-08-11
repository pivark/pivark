<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\support\LocalFile;
use think\facade\Config;

/**
 * 上传目录 Web 执行防护（对标 EyouCMS FileProtect：Apache .htaccess + Nginx 片段 SSOT）。
 *
 * 应用层 UploadFileService 白名单之外，禁止 public/uploads 下脚本被 Web 执行。
 */
final class UploadDirProtectService
{

    /** @var list<string> */
    public const BLOCKED_WEB_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar',
        'shtml', 'inc', 'jsp', 'asp', 'aspx', 'cgi', 'sh', 'htaccess',
    ];

    public function publicUploadRootAbsolute(): string
    {
        $base = trim((string) Config::get('upload.public_dir', 'uploads'), '/');

        return rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $base);
    }

    /** 安装完成 / 首次上传前：写入 public/uploads/.htaccess 与 index.html */
    public function ensurePublicUploadRoot(): void
    {
        $this->ensureDirectory($this->publicUploadRootAbsolute());
    }

    /** 新建日期/场景子目录后：继承根目录防护文件（Apache 子目录可单独生效） */
    public function ensureDirectory(string $absoluteDir): void
    {
        $absoluteDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absoluteDir), DIRECTORY_SEPARATOR);
        if ($absoluteDir === '') {
            return;
        }
        if (!is_dir($absoluteDir)) {
            LocalFile::mkdirIfMissing($absoluteDir);
        }
        if (!is_dir($absoluteDir)) {
            return;
        }
        if (!is_writable($absoluteDir)) {
            return;
        }

        $this->writeIfChanged($absoluteDir . DIRECTORY_SEPARATOR . '.htaccess', $this->apacheHtaccessBody());
        $this->writeIfChanged($absoluteDir . DIRECTORY_SEPARATOR . 'index.html', "<!DOCTYPE html><title>403</title>\n");
    }

    public function apacheHtaccessBody(): string
    {
        $ext = implode('|', self::BLOCKED_WEB_EXTENSIONS);

        return <<<HTACCESS
# PivArk — uploads 目录禁止 Web 执行脚本（UploadDirProtectService SSOT）
<IfModule mod_authz_core.c>
    <FilesMatch "\\.({$ext})$">
        Require all denied
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    <FilesMatch "\\.({$ext})$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</IfModule>
HTACCESS;
    }

    /** 安装向导 Nginx 片段（写入 server {} 内、PHP location 之前） */
    public function nginxLocationSnippet(): string
    {
        $ext = implode('|', self::BLOCKED_WEB_EXTENSIONS);
        $publicDir = trim((string) Config::get('upload.public_dir', 'uploads'), '/');

        return <<<NGINX
    # 上传目录禁止脚本（PivArk UploadDirProtectService）
    location ~* ^/{$publicDir}/.*\\.({$ext})$ {
        deny all;
    }
NGINX;
    }

    /** @return list<string> URL path 后缀（小写、无点），供 router.php 等复用 */
    public static function blockedUrlExtensions(): array
    {
        return self::BLOCKED_WEB_EXTENSIONS;
    }

    public static function isBlockedUrlPath(string $uriPath): bool
    {
        $path = strtolower(parse_url($uriPath, PHP_URL_PATH) ?: $uriPath);
        $publicDir = trim((string) Config::get('upload.public_dir', 'uploads'), '/');
        if (!preg_match('#^/' . preg_quote($publicDir, '#') . '/#', $path)) {
            return false;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $ext !== '' && in_array(strtolower($ext), self::BLOCKED_WEB_EXTENSIONS, true);
    }

    private function writeIfChanged(string $path, string $content): void
    {
        if (is_file($path) && hash('sha256', (string) file_get_contents($path)) === hash('sha256', $content)) {
            return;
        }
        @file_put_contents($path, $content);
    }
}
