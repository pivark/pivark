<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\MoneyMath;
use app\common\service\member\MemberPluginNavService;
use app\common\service\member\MemberUxService;
use app\common\service\member\MemberSidebarNavService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberBalanceService;
use app\common\service\member\MemberPointService;

use app\common\service\config\ConfigService;
use app\common\support\SiteUrl;

final class MemberPublishLayoutService
{

    public function __construct(
        private readonly MemberPointService $memberPointService,
        private readonly MemberBalanceService $memberBalanceService,
        private readonly MemberConfigService $memberConfigService,
        private readonly ConfigService $configService,
        private readonly MemberSidebarNavService $memberSidebarNavService,
        private readonly MemberUxService $memberUxService,
        private readonly MemberPluginNavService $memberPluginNavService,
    ) {
    }

    /**
     * @param array<string, mixed> $member FrontAuth 当前会员行
     * @return array<string, mixed>
     */
    public function shellForMember(array $member, string $navActive = 'document_create'): array
    {
        $userId      = (int) ($member['id'] ?? 0);
        $points      = $this->memberPointService->balance($userId);
        $balance     = $this->memberBalanceService->balance($userId);
        $nickname    = trim((string) ($member['nickname'] ?? ''));
        $displayName = $nickname !== '' ? $nickname : (string) ($member['username'] ?? '会员');
        $pluginNav   = $this->pluginNavFor($navActive);
        $navCtx      = [
            'document_publish_open' => $this->memberConfigService->isDocumentPublishOpen() ? 1 : 0,
            'points_enabled'        => $this->memberConfigService->isPointsEnabled() ? 1 : 0,
            'points_label'          => $this->memberConfigService->pointsLabel(),
            'document_create_url'   => SiteUrl::memberDocumentCreate(),
            'documents_url'         => SiteUrl::memberDocuments(),
            'points_url'            => SiteUrl::memberPoints(),
            'balance_url'           => SiteUrl::memberBalance(),
            'recharge_url'          => SiteUrl::memberRecharge(),
            'purchases_url'         => SiteUrl::memberPurchases(),
            'consumption_url'       => SiteUrl::memberConsumption(),
            'security_url'          => SiteUrl::memberSecurity(),
        ];
        $siteCfg     = $this->configService->getAll();

        return [
            'site_name'                  => (string) ($siteCfg['site_name'] ?? ''),
            'home_url'                   => SiteUrl::home(),
            'member_nav_active'          => $navActive,
            'member_nav_class_center'    => $navActive === 'center' ? ' active' : '',
            'member_nav_groups'          => $this->memberSidebarNavService->groups($navActive, $navCtx, $pluginNav),
            'member_display_name'        => $displayName,
            'member_username_text'       => (string) ($member['username'] ?? ''),
            'member_center_url'        => SiteUrl::memberCenter(),
            'member_documents_url'     => SiteUrl::memberDocuments(),
            'member_document_create_url' => SiteUrl::memberDocumentCreate(),
            'member_logout_url'        => SiteUrl::memberLogout(),
            'member_avatar_letter'     => mb_strtoupper(mb_substr($displayName, 0, 1)),
            'member_recharge_status_line' => $this->memberUxService->rechargeMemberStatus($userId)['member_recharge_status_line'],
            'member_recharge_url'      => SiteUrl::memberRecharge(),
            'member_points_enabled'    => $this->memberConfigService->isPointsEnabled() ? 1 : 0,
            'member_points_name'       => $this->memberConfigService->pointsLabel(),
            'member_balance_text'      => MoneyMath::formatPlain($balance),
            'member_points_text'       => (string) $points,
        ];
    }

    /**
     * @return list<array{identifier:string,label:string,url:string,route:string,icon:string,is_active:int}>
     */
    private function pluginNavFor(string $navActive): array
    {
        $list = $this->memberPluginNavService->listForMemberCenter();
        foreach ($list as &$row) {
            $route            = trim((string) ($row['route'] ?? ''));
            $row['is_active'] = ($navActive !== '' && $navActive === $route) ? 1 : 0;
        }
        unset($row);

        return $list;
    }
}
