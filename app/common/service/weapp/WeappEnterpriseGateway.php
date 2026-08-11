<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappEnterpriseGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\model\User;
use app\common\service\enterprise\EnterpriseAssetBackendRegistry;
use app\common\service\enterprise\EnterpriseAssetService;
use app\common\service\enterprise\EnterpriseEntityService;
use app\common\service\user\PermissionService;
use think\facade\Db;

final class WeappEnterpriseGateway
{

    public function __construct(
        private readonly EnterpriseAssetService $enterpriseAsset,
        private readonly EnterpriseAssetBackendRegistry $enterpriseAssetBackend,
        private readonly EnterpriseEntityService $enterpriseEntity,
        private readonly PermissionService $permission,
    ) {
    }

    public function enterpriseAssetTableExists(): bool
    {
        return $this->enterpriseAsset->tableExists();
    }

    /** @return array{total:int,list:list<array<string,mixed>>} */
    public function enterpriseAssetListAdmin(
        int $page = 1,
        int $limit = 20,
        string $keyword = '',
        int $entityId = 0,
        string $assetType = '',
        string $scope = ''
    ): array {
        return $this->enterpriseAsset->listAdmin($page, $limit, $keyword, $entityId, $assetType, $scope);
    }

    /** @param array<string, mixed> $data @return array{code:int,msg:string,id?:int} */
    public function enterpriseAssetSave(array $data): array
    {
        return $this->enterpriseAsset->save($data);
    }

    /** @return array{code:int,msg:string} */
    public function enterpriseAssetArchive(int $id): array
    {
        return $this->enterpriseAsset->archive($id);
    }

    /** @return list<array<string, mixed>> */
    public function enterpriseAssetSearch(string $query, int $entityId = 0, int $limit = 8): array
    {
        return $this->enterpriseAsset->search($query, $entityId, $limit);
    }

    /** @return array<string, mixed> */
    public function enterpriseAssetStats(): array
    {
        return $this->enterpriseAsset->stats();
    }

    public function enterpriseAssetSeedDemo(int $entityId): void
    {
        $this->enterpriseAsset->seedDemo($entityId);
    }

    /** @return array<string, string> */
    public function enterpriseAssetTypeLabels(): array
    {
        return EnterpriseAssetService::TYPE_LABELS;
    }

    public function enterpriseAssetBackendRegister(string $identifier, string $serviceClass): void
    {
        $this->enterpriseAssetBackend->register($identifier, $serviceClass);
    }

    public function enterpriseAssetBackendRegisterKernel(string $identifier): void
    {
        $this->enterpriseAssetBackendRegister(
            $identifier,
            \app\common\service\enterprise\EnterpriseAssetKernelService::class,
        );
    }

    /** @return list<array<string, mixed>> */
    public function enterpriseEntityListActive(): array
    {
        return $this->enterpriseEntity->listActive();
    }

    /** @return array<string, mixed>|null */
    public function enterpriseEntityDefault(): ?array
    {
        return $this->enterpriseEntity->defaultEntity();
    }

    /** @param array<string, mixed> $data */
    public function enterpriseEntitySave(array $data)
    {
        return $this->enterpriseEntity->save($data);
    }

    public function enterpriseEntityArchive(int $id)
    {
        return $this->enterpriseEntity->archive($id);
    }

    /**
     * @param \think\db\Query|\think\Model $query
     * @param callable|null $poolHandler optional public-pool handler
     */
    public function dataScopeApply(
        mixed $query,
        string $ownerColumn,
        string $deptColumn,
        string $permissionCode,
        int $adminUserId,
        ?callable $poolHandler = null,
    ): void {
        if ($adminUserId < 1) {
            $query->where($ownerColumn, 0);

            return;
        }
        if ($this->permission->isSuperAdmin($adminUserId)) {
            return;
        }
        if ($poolHandler !== null) {
            $poolHandler($query);
        }

        $scope = $this->resolveDataScope($adminUserId, $permissionCode);
        if (!$this->pluginGateway()->pluginIsEnabled('oa') && $scope !== 'self') {
            $scope = 'self';
        }

        match ($scope) {
            'all'       => null,
            'dept'      => $this->applyDeptScope($query, $deptColumn, $adminUserId, false),
            'dept_tree' => $this->applyDeptScope($query, $deptColumn, $adminUserId, true),
            default     => $query->where($ownerColumn, $adminUserId),
        };
    }

    private function resolveDataScope(int $adminUserId, string $permissionCode): string
    {
        $roleIds = $this->permission->getRoleIds($adminUserId);
        if ($roleIds === []) {
            return 'self';
        }
        $permId = Db::name('permissions')
            ->where('code', $permissionCode)
            ->where('status', 1)
            ->value('id');
        if ($permId === null) {
            return 'self';
        }
        try {
            $scopes = Db::name('role_permissions')
                ->whereIn('role_id', $roleIds)
                ->where('permission_id', (int) $permId)
                ->column('data_scope');
        } catch (\Throwable) {
            return 'self';
        }
        if ($scopes === []) {
            return 'self';
        }
        $rank = ['self' => 1, 'dept' => 2, 'dept_tree' => 3, 'all' => 4];
        $best = 'self';
        foreach ($scopes as $scope) {
            $scope = (string) $scope;
            if (($rank[$scope] ?? 0) > ($rank[$best] ?? 0)) {
                $best = $scope;
            }
        }

        return $best;
    }

    /**
     * @param \think\db\Query|\think\Model $query
     */
    private function applyDeptScope(mixed $query, string $deptColumn, int $adminUserId, bool $tree): void
    {
        $deptId = $this->adminDepartmentId($adminUserId);
        if ($deptId < 1) {
            $query->where($deptColumn, 0);

            return;
        }
        if (!$tree) {
            $query->where($deptColumn, $deptId);

            return;
        }
        $deptIds = $this->departmentTreeIds($deptId);
        $query->whereIn($deptColumn, $deptIds !== [] ? $deptIds : [0]);
    }

    private function adminDepartmentId(int $adminUserId): int
    {
        try {
            $row = User::where('id', $adminUserId)->field('department_id')->find();
        } catch (\Throwable) {
            return 0;
        }
        if ($row === null) {
            return 0;
        }
        $data = $row->toArray();

        return (int) ($data['department_id'] ?? 0);
    }

    /** @return list<int> */
    private function departmentTreeIds(int $rootDeptId): array
    {
        $pluginGateway = $this->pluginGateway();
        if ($rootDeptId < 1 || !$pluginGateway->pluginIsEnabled('oa')) {
            return [$rootDeptId];
        }
        /** @var list<int>|null $children */
        $children = $pluginGateway->pluginApiInvoke('oa', 'OaPublic', 'getDepartmentChildren', [$rootDeptId]);
        if (!is_array($children)) {
            return [$rootDeptId];
        }
        $ids = array_values(array_unique(array_merge([$rootDeptId], array_map('intval', $children))));

        return $ids !== [] ? $ids : [$rootDeptId];
    }

    private function pluginGateway(): WeappPluginGateway
    {
        return app(WeappPluginGateway::class);
    }
}
