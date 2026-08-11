<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 上传后处理队列（Session 暂存，Cron/下次上传时 drain）
 */
declare(strict_types=1);

namespace app\common\service\upload;

use think\facade\Session;

class UploadQueueService
{

    private const SESSION_KEY = 'pv_upload_post_queue';
    private const MAX_ITEMS   = 200;

    /**
     * @param array<string, mixed> $payload 如 scene、path、sha256、media_id
     */
    public function enqueue(string $scene, array $payload): void
    {
        $scene = trim($scene);
        if ($scene === '') {
            return;
        }
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[] = [
            'scene'      => $scene,
            'payload'    => $payload,
            'created_at' => time(),
        ];
        if (count($all) > self::MAX_ITEMS) {
            $all = array_slice($all, -self::MAX_ITEMS);
        }
        Session::set(self::SESSION_KEY, $all);
    }

    /** @return int 处理条数 */
    public function drain(int $max = 10): int
    {
        $all = Session::get(self::SESSION_KEY, []);
        if (!is_array($all) || $all === []) {
            return 0;
        }
        $done  = 0;
        $rest  = [];
        foreach ($all as $item) {
            if ($done >= $max || !is_array($item)) {
                $rest[] = $item;
                continue;
            }
            $scene   = (string) ($item['scene'] ?? '');
            $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            if ($scene !== '' && $this->processItem($scene, $payload)) {
                $done++;
            } else {
                $rest[] = $item;
            }
        }
        Session::set(self::SESSION_KEY, $rest);

        return $done;
    }

    /** @param array<string, mixed> $payload */
    private function processItem(string $scene, array $payload): bool
    {
        return $scene !== '' && $payload !== [];
    }
}
