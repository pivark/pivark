<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * 后台运维面板 ops 提示：发行环境不向 API 返回内部脚本命令。
 */
final class AdminOpsPanelHints
{
    /**
     * @return array{drain_cli:string,dry_run_cli:string,doc:string}
     */
    public static function drainOps(string $scriptRel, string $extraArgs = ''): array
    {
        $drain = self::cliIfWorkspaceTools($scriptRel, $extraArgs);

        return [
            'drain_cli'   => $drain,
            'dry_run_cli' => $drain !== ''
                ? self::cliIfWorkspaceTools($scriptRel, trim($extraArgs . ' --dry-run'))
                : '',
            'doc'         => '',
        ];
    }

    /**
     * @return array{scan_cli:string,dry_run_cli:string,doc:string}
     */
    public static function scanOps(string $scriptRel, string $scanArgs, string $dryRunArgs): array
    {
        return [
            'scan_cli'    => self::cliIfWorkspaceTools($scriptRel, $scanArgs),
            'dry_run_cli' => self::cliIfWorkspaceTools($scriptRel, $dryRunArgs),
            'doc'         => '',
        ];
    }

    public static function cliIfWorkspaceTools(string $scriptRel, string $args = ''): string
    {
        $abs = ProjectPaths::optionalWorkspaceToolsFile([
            'daily',
            'scripts',
            ltrim(str_replace('\\', '/', $scriptRel), '/'),
        ]);
        if ($abs === '' || !is_readable($abs)) {
            return '';
        }

        // 仅完整开发仓返回命令；发行包无工具树时恒为空
        $root = rtrim(ProjectPaths::root(), '/\\');
        $rel  = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        $cmd  = 'php ' . $rel;
        if ($args !== '') {
            $cmd .= ' ' . $args;
        }

        return $cmd;
    }

    /** @deprecated 使用 cliIfWorkspaceTools */
    public static function cliIfDevtools(string $scriptRel, string $args = ''): string
    {
        return self::cliIfWorkspaceTools($scriptRel, $args);
    }
}
