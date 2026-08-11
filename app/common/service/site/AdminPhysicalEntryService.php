<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\LocalFile;
use app\common\support\OpsLog;

/**
 * 后台物理入口自愈：客户站落盘 admin/index.php（镜像 install/），
 * Nginx 无伪静态粘贴也可 /admin/ → DirectoryIndex → PATH_INFO。
 *
 * dig Vue 仓（存在 admin/package.json）不落盘，避免污染 SPA 源码树。
 */
final class AdminPhysicalEntryService
{
    public function templateDir(): string
    {
        return rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/bootstrap/admin_entry';
    }

    public function targetDir(): string
    {
        return rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/admin';
    }

    /** dig 开发仓 Vue monorepo：禁止往 admin/ 写运行态入口 */
    public function shouldMaterialize(): bool
    {
        return !is_file($this->targetDir() . '/package.json');
    }

    /**
     * 确保站点根存在 admin/index.php + .htaccess。
     *
     * @return array{ok:bool,skipped:bool,reason:string,path:string}
     */
    public function ensure(): array
    {
        $targetDir = $this->targetDir();
        $indexPath = $targetDir . '/index.php';
        if (!$this->shouldMaterialize()) {
            return [
                'ok'      => true,
                'skipped' => true,
                'reason'  => 'dig_vue_monorepo',
                'path'    => $indexPath,
            ];
        }

        $tplDir = $this->templateDir();
        $tplIndex = $tplDir . '/index.php.template';
        $tplHt = $tplDir . '/.htaccess.template';
        if (!is_readable($tplIndex)) {
            return [
                'ok'      => false,
                'skipped' => false,
                'reason'  => 'missing_template',
                'path'    => $tplIndex,
            ];
        }

        if (!is_dir($targetDir) && !LocalFile::mkdirIfMissing($targetDir)) {
            return [
                'ok'      => false,
                'skipped' => false,
                'reason'  => 'mkdir_failed',
                'path'    => $targetDir,
            ];
        }

        $body = (string) file_get_contents($tplIndex);
        if (@file_put_contents($indexPath, $body) === false) {
            OpsLog::businessWarning('admin_physical_entry_write_failed', ['path' => $indexPath]);

            return [
                'ok'      => false,
                'skipped' => false,
                'reason'  => 'write_index_failed',
                'path'    => $indexPath,
            ];
        }

        if (is_readable($tplHt)) {
            @file_put_contents($targetDir . '/.htaccess', (string) file_get_contents($tplHt));
        }

        return [
            'ok'      => true,
            'skipped' => false,
            'reason'  => 'written',
            'path'    => $indexPath,
        ];
    }
}
