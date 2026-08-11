<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\service\config\ConfigService;

/**
 * 素材库 v1 API 可注入门面（Phase 2 DI）。
 */
final class MediaPublicGateway
{

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array>,folders:list<string>,page:int,limit:int,total:int}
     */
    public function listImages(array $params): array
    {
        return app(MediaLibraryService::class)->listImages($params);
    }

    public function maxSizeMb(): string
    {
        return (string) app(ConfigService::class)->get('upload_max_size', '2');
    }
}
