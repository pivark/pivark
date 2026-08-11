<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;

use app\common\service\release\PivarkEditionService;
use app\common\service\user\PermissionService;

/**
 * 登录弹窗可见性 · host_only vs 企业站( community )
 *
 * - host_only 运营后台：非超管须角色勾选 admin.notice.* 才弹对应窗
 * - 企业客户后台：broadcast=全员（有控制台权限）；personal=独有提醒须单独授权
 */
class AdminLoginNoticeAccessService
{

    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly PivarkEditionService $editionService,
    ) {
    }

    /** platform | community */
    public function editionMode(): string
    {
        return $this->editionService->isPlatform() ? 'platform' : 'community';
    }

    /**
     * @param array<string, mixed> $meta config/admin/login_notices.php notices[*]
     */
    public function canReceiveNotice(int $userId, string $noticeId, array $meta): bool
    {
        if ($userId < 1 || $noticeId === '') {
            return false;
        }
        if ($this->permissionService->isSuperAdmin($userId)) {
            return true;
        }

        $audience = (string) ($meta['audience'] ?? 'personal');
        $noticePerm = trim((string) ($meta['permission'] ?? ''));
        $dataPerm = trim((string) ($meta['data_permission'] ?? ''));

        if ($this->editionService->isPlatform()) {
            if ($noticePerm === '' || !$this->permissionService->can($userId, $noticePerm)) {
                return false;
            }
            if ($dataPerm !== '' && !$this->permissionService->can($userId, $dataPerm)) {
                return false;
            }

            return true;
        }

        // 企业站 community / dev
        if ($audience === 'broadcast') {
            return $this->permissionService->can($userId, 'admin.dashboard');
        }

        if ($noticePerm === '' || !$this->permissionService->can($userId, $noticePerm)) {
            return false;
        }
        if ($dataPerm !== '' && !$this->permissionService->can($userId, $dataPerm)) {
            return false;
        }

        return true;
    }

    /**
     * 前端拉取的 client_only 提醒（如 core.update）允许列表
     *
     * @return list<string>
     */
    public function clientAllowedNoticeIds(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $registry = app(PluginAdminLoginNoticeRegistry::class)->mergedWithKernel();
        if (!is_array($registry)) {
            return [];
        }
        $allowed = [];
        foreach ($registry as $noticeId => $meta) {
            if (!is_array($meta) || empty($meta['client_only'])) {
                continue;
            }
            if (!app(AdminLoginNoticeConfigService::class)->isChannelEnabled((string) $noticeId, 'popup')) {
                continue;
            }
            if ($this->canReceiveNotice($userId, (string) $noticeId, $meta)) {
                $allowed[] = (string) $noticeId;
            }
        }

        return $allowed;
    }

    /**
     * @return list<array{id:string,label:string,audience:string,permission:string}>
     */
    public function catalogForRoleForm(): array
    {
        $registry = app(PluginAdminLoginNoticeRegistry::class)->mergedWithKernel();
        if (!is_array($registry)) {
            return [];
        }
        $out = [];
        foreach ($registry as $noticeId => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $out[] = [
                'id'         => (string) $noticeId,
                'label'      => (string) ($meta['label'] ?? $noticeId),
                'audience'   => (string) ($meta['audience'] ?? 'personal'),
                'permission' => (string) ($meta['permission'] ?? ''),
            ];
        }

        return $out;
    }
}
