<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;

use app\common\model\EnterpriseAsset;
use app\common\service\enterprise\EnterpriseAssetBackendRegistry;
use app\common\support\DbTable;
use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

/** 企业经营资料门面 · 内核 backend 优先 */
class EnterpriseAssetService
{

    /** @var list<string> */
    public const ASSET_TYPES = ['cert', 'financial', 'performance', 'personnel', 'product', 'template', 'other'];

    /** @var list<string> */
    public const SCOPES = ['internal', 'product', 'web', 'tender'];

    /** @var array<string, string> */
    public const SCOPE_LABELS = [
        'internal' => '内部经营',
        'tender'   => '投标资料',
        'product'  => '产品对外',
        'web'      => '网站公开',
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        'cert'        => '证照资质',
        'financial'   => '财务信用',
        'performance' => '业绩案例',
        'personnel'   => '人员证书',
        'product'     => '产品资料',
        'template'    => 'Word 母版',
        'other'       => '其它',
    ];

    public function tableExists(): bool
    {
        return DbTable::modelExists(EnterpriseAsset::class);
    }

    /** @return array{total:int,list:list<array<string,mixed>>} */
    public function listAdmin(
        int $page = 1,
        int $limit = 20,
        string $keyword = '',
        int $entityId = 0,
        string $assetType = '',
        string $scope = '',
        bool $expiringOnly = false,
    ): array {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ['total' => 0, 'list' => []];
        }

        return $svc::listAdmin($page, $limit, $keyword, $entityId, $assetType, $scope, $expiringOnly);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): ServiceResult
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }

        return $svc::save($data);
    }

    public function archive(int $id): ServiceResult
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }

        return $svc::archive($id);
    }

    public function purge(int $id): ServiceResult
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }

        return $svc::purge($id);
    }

    /**
     * @param list<int> $ids
     */
    public function archiveBatch(array $ids): ServiceResult
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }

        return $svc::archiveBatch($ids);
    }

    /**
     * @param list<int> $ids
     */
    public function purgeBatch(array $ids): ServiceResult
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }

        return $svc::purgeBatch($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $entityId = 0, int $limit = QueryLimit::RELATED_ITEMS): array
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return [];
        }

        return $svc::search($query, $entityId, $limit);
    }

    /** @return array{total:int,active:int,expiring:int} */
    public function stats(string $scope = ''): array
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return ['total' => 0, 'active' => 0, 'expiring' => 0];
        }

        return $svc::stats($scope);
    }

    public function seedDemo(int $entityId): void
    {
        $svc = $this->backendSvcClass();
        if ($svc === null) {
            return;
        }
        $svc::seedDemo($entityId);
    }

    /** @return class-string|null */
    private function backendSvcClass(): ?string
    {
        $cls = app(EnterpriseAssetBackendRegistry::class)->backendClass();
        if ($cls !== null && class_exists($cls)) {
            return $cls;
        }
        if (class_exists(EnterpriseAssetKernelService::class)) {
            return EnterpriseAssetKernelService::class;
        }

        return null;
    }
}
