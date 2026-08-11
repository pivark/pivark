<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\OpsLog;
use think\facade\Db;

/** 注册 DB 慢查询监听（仅一次） */
class DbOpsLogService
{

    private static bool $listening = false;

    public function boot(): void
    {
        if (self::$listening) {
            return;
        }
        self::$listening = true;

        $thresholdMs = max(100, (int) env('DB_SLOW_QUERY_MS', '1000'));
        Db::listen(static function (string $sql, float|string $time, ?string $master) use ($thresholdMs): void {
            $ms = is_numeric($time) ? (float) $time * 1000 : 0.0;
            if ($ms < $thresholdMs) {
                return;
            }
            $sqlTrim = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
            if (strlen($sqlTrim) > 500) {
                $sqlTrim = substr($sqlTrim, 0, 500) . '…';
            }
            OpsLog::slowQuery('slow_sql', [
                'ms'     => round($ms, 2),
                'master' => $master ?? '',
                'sql'    => $sqlTrim,
            ]);
        });
    }
}
