<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Request;

/** 解析 application/json POST 请求体 */
class JsonBody
{
    /**
     * @return array<string, mixed>|null 非 JSON POST 或解析失败为 null
     */
    public static function decode(): ?array
    {
        $req = Request::instance();
        if (!$req->isPost()) {
            return null;
        }

        $contentType = strtolower((string) $req->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            return null;
        }

        $raw = (string) $req->getContent();
        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
