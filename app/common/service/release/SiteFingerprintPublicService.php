<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\support\InstallGate;

/** 公开站点指纹（API 只经本 PublicService，禁控制器直引 Core/Edition） */
final class SiteFingerprintPublicService
{
    public function __construct(
        private readonly CoreUpdateRemoteService $coreUpdateRemoteService,
        private readonly PivarkEditionService $pivarkEditionService,
    ) {
    }

    /**
     * @return array{product:string,core_version:string,edition:string,release_version:string}
     */
    public function snapshot(): array
    {
        $lock = InstallGate::readLockData();

        return [
            'product'         => 'pivark',
            'core_version'    => $this->coreUpdateRemoteService->currentVersion(),
            'edition'         => $this->pivarkEditionService->edition(),
            'release_version' => trim((string) ($lock['release_version'] ?? '')),
        ];
    }
}
