<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use think\facade\Cache;
use think\facade\Db;
use think\response\Json;

/** GET /api/v1/health — 运维探活（DB / 缓存 / 磁盘） */
class Health
{
    public function index(): Json
    {
        $checks = [
            'app'      => 'ok',
            'database' => 'fail',
            'cache'    => 'skip',
            'disk'     => 'fail',
        ];

        try {
            // 禁探无前缀 users（Community 表为 pv_*）；SELECT 1 即可证连通
            Db::query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'fail';
        }

        try {
            $probe = 'health_' . bin2hex(random_bytes(4));
            Cache::set($probe, 1, 5);
            // 文件缓存常把标量落成字符串；禁用 === 1 误判 fail
            $checks['cache'] = (int) Cache::get($probe) === 1 ? 'ok' : 'fail';
            Cache::delete($probe);
        } catch (\Throwable) {
            // 文件缓存不可写等：跳过，不把 Community 默认态打成 503
            $checks['cache'] = 'skip';
        }

        $runtimeDir = ROOT_PATH . 'data' . DIRECTORY_SEPARATOR . 'runtime';
        $checks['disk'] = is_dir($runtimeDir) && is_writable($runtimeDir) ? 'ok' : 'fail';

        // cache=skip（无 Redis / 缓存驱动异常）不否决；仅 database+disk 为硬条件
        $healthy = $checks['database'] === 'ok' && $checks['disk'] === 'ok'
            && $checks['cache'] !== 'fail';

        $status = $healthy ? 'ok' : 'degraded';
        $httpCode = $healthy ? 200 : 503;

        return json(['data' => [
            'status' => $status,
            'checks' => $checks,
        ]], $httpCode);
    }
}
