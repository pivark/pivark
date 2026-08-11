<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\enum\ApiErrorCode;
use app\common\service\release\PivarkEditionService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\support\ServiceResult;

/**
 * 品项管理写路径本机许可闸（ADR：本地加强，禁止线上拉码/phone-home）。
 *
 * 后续可将本类关键判断迁入 encode 构建产物；运行时仍只读本机许可。
 */
final class ItemCapabilityGate
{
    public function __construct(
        private readonly SiteCoreLicenseService $siteCoreLicense,
        private readonly PivarkEditionService $edition,
    ) {
    }

    /** 本机是否允许品项管理写操作（创建/改/删） */
    public function allowsAdminWrite(): bool
    {
        // 开发/平台运营站：本机全开，不挡研发
        if ($this->edition->isDev() || $this->edition->isPlatform()) {
            return true;
        }

        // 装机勾选「导入演示」：允许落库品项/参数组（开源仍藏 UI；升专业版+打开即有样例）
        if (self::isInstallDemoSeedContext()) {
            return true;
        }

        // Community 发行：须本机站点许可为专业版+（激活时写入，不依赖授权平台实时在线）
        if ($this->edition->isCommunity()) {
            return $this->siteCoreLicense->isProPlusTier();
        }

        return true;
    }

    /**
     * 仅装机/种子 include 上下文（禁止日常开源后台绕过）。
     * 不引用 install\ 命名空间，避免装完删 install/ 后内核耦合。
     */
    public static function isInstallDemoSeedContext(): bool
    {
        if (\defined('PIVARK_INSTALL_SEED_INCLUDE') && \constant('PIVARK_INSTALL_SEED_INCLUDE')) {
            return true;
        }
        $truthy = static fn (string $raw): bool => in_array(
            strtolower(trim($raw)),
            ['1', 'true', 'yes', 'on'],
            true
        );
        $wizard = (string) (getenv('PIVARK_INSTALL_WIZARD') ?: '');
        $import = (string) (getenv('PIVARK_INSTALL_IMPORT_DEMO') ?: '');

        return $truthy($wizard) && $truthy($import);
    }

    public function guardAdminWrite(): ?ServiceResult
    {
        if ($this->allowsAdminWrite()) {
            return null;
        }

        return ServiceResult::fail(
            $this->siteCoreLicense->proRequiredMessage(),
            ApiErrorCode::CORE_LICENSE_PRO_REQUIRED
        );
    }
}
