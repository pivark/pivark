<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\encode;

use app\common\support\ServiceResult;

/** ionCube 构建（需构建机安装编码器） */
final class PluginIonCubeBuildService
{
    /**
     * @return ServiceResult
     */
    public function buildToDirectory(string $identifier, ?string $destDir = null): ServiceResult
    {
        $encoder = trim((string) config('plugin.commercial.ioncube_encoder', ''));
        if ($encoder === '' || !is_file($encoder)) {
            return ServiceResult::fail('未配置 PIVARK_IONCUBE_ENCODER 或编码器路径无效；可改用 PIVARK_ENCODE_DRIVER=pivark');
        }

        return ServiceResult::fail('ionCube 批量构建请使用编码器 GUI/CLI 处理 weapp/' . $identifier
                . ' 后，将 distribution.mode 设为 encoded_commercial 再打包 zip');
    }
}
