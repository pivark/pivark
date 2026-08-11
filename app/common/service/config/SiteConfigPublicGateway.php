<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;


/**
 * 站点公开配置 v1 API 可注入门面（Phase 2 DI）。
 */
final class SiteConfigPublicGateway
{

    /** @var list<string> */
    private const PUBLIC_KEYS = [
        'site_name',
        'site_title',
        'site_url',
        'site_logo',
        'site_copyright',
        'site_icp',
        'site_theme',
        'site_keywords',
        'site_description',
    ];

    /** @return array<string, mixed> */
    public function sitePublic(): array
    {
        $all  = app(ConfigService::class)->getAll();
        $data = [];
        foreach (self::PUBLIC_KEYS as $key) {
            if (array_key_exists($key, $all)) {
                $data[$key] = $all[$key];
            }
        }

        return $data;
    }
}
