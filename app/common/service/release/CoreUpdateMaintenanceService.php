<?php
/**
 * 元舟 PivArk — 核心在线升级维护模式（前台 503，后台/API 可访问）
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\AppTime;
use app\common\support\ServiceResult;

use app\common\support\LocalFile;
use app\common\support\ProjectPaths;

final class CoreUpdateMaintenanceService
{

    private const LOCK_FILE = 'maintenance.lock';

    public function isActive(): bool
    {
        return is_file($this->lockPath());
    }

    /** @return ServiceResult */
    public function engage(string $reason = 'core_update'): ServiceResult
    {
        $path = $this->lockPath();
        if (!LocalFile::mkdirIfMissing(dirname($path))) {
            return ServiceResult::fail('无法创建维护锁目录');
        }
        $payload = json_encode([
            'reason'     => $reason,
            'started_at' => AppTime::format('c'),
            'pid'        => getmypid(),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false || !LocalFile::putContents($path, $payload . "\n")) {
            return ServiceResult::fail('无法写入维护锁');
        }

        return ServiceResult::ok(null, '已进入维护模式');
    }

    /** @return ServiceResult */
    public function disengage(): ServiceResult
    {
        LocalFile::unlinkQuiet($this->lockPath(), 'core_update_maintenance');

        return ServiceResult::ok(null, '已退出维护模式');
    }

    private function lockPath(): string
    {
        return ProjectPaths::runtimeDir() . DIRECTORY_SEPARATOR . self::LOCK_FILE;
    }
}
