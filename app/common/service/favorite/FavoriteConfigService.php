<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\favorite;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
/** 点赞收藏配置 */
class FavoriteConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public function isOpen(): bool
    {
        return (string) $this->configService->get('favorite_open', '1') === '1';
    }

    public function guestAllowed(): bool
    {
        return (string) $this->configService->get('favorite_guest', '1') === '1';
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $this->configService->set('favorite_open', !empty($data['favorite_open']) ? '1' : '0');
        $this->configService->set('favorite_guest', !empty($data['favorite_guest']) ? '1' : '0');

        return ServiceResult::ok(null, '保存成功');
    }

    /** @return array{favorite_open:string,favorite_guest:string} */
    public function adminCfg(): array
    {
        return [
            'favorite_open'  => (string) $this->configService->get('favorite_open', '1'),
            'favorite_guest' => (string) $this->configService->get('favorite_guest', '1'),
        ];
    }
}
