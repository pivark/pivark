<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;

use app\common\support\ServiceResult;
use app\common\service\enterprise\EnterpriseAssetService;
use app\common\service\kernel\KernelModuleRegistry;
use app\common\service\plugin\PluginService;

class EnterpriseResourceService
{

    public function __construct(
        private readonly KernelModuleRegistry $kernelModuleRegistry,
        private readonly EnterpriseAssetService $enterpriseAssetService,
        private readonly EnterpriseEntityService $enterpriseEntityService,
        private readonly PluginService $pluginService,
    ) {
    }

    public function isActive(): bool
    {
        return $this->kernelModuleRegistry->isActive('enterprise_resource');
    }

    /** @return array<string, mixed> */
    public function meta(string $scope = ''): array
    {
        if (!$this->isActive()) {
            return [
                'active'      => false,
                'asset_types' => [],
                'scopes'      => [],
                'entities'    => [],
                'stats'       => ['total' => 0, 'active' => 0, 'expiring' => 0],
                'entity_health' => ['duplicate_groups' => 0, 'duplicate_entities' => 0],
            ];
        }

        $entities = $this->enterpriseEntityService->listActive();

        return [
            'active'      => true,
            'asset_types' => EnterpriseAssetService::TYPE_LABELS,
            'scopes'      => $this->scopeOptions(),
            'entities'    => $entities,
            'stats'       => $this->stats($scope),
            'entity_health' => $this->enterpriseEntityService->duplicateStats(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveEntity(array $data): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseEntityService->save($data);
    }

    public function archiveEntity(int $id): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseEntityService->archive($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntities(string $view = 'active'): array
    {
        if (!$this->isActive()) {
            return [];
        }

        $view = strtolower(trim($view));
        $status = match ($view) {
            'archived' => 0,
            'all'      => null,
            default    => 1,
        };

        return $this->enterpriseEntityService->listByStatus($status);
    }

    public function restoreEntity(int $id): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseEntityService->restore($id);
    }

    public function deleteEntity(int $id, string $assetMode): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseEntityService->delete($id, $assetMode);
    }

    public function consolidateDuplicateEntities(): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseEntityService->consolidateDuplicateNames();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function attach(array $payload): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        $filePath = trim((string) ($payload['file_path'] ?? $payload['path'] ?? $payload['url'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' && $filePath !== '') {
            $title = basename(str_replace('\\', '/', $filePath));
        }
        if ($title === '') {
            return ServiceResult::fail('请提供资料标题或文件路径');
        }

        $keywords = trim((string) ($payload['keywords'] ?? ''));
        $consumer = trim((string) ($payload['consumer'] ?? ''));
        if ($consumer !== '') {
            $tag = 'consumer:' . $consumer;
            $keywords = $keywords === '' ? $tag : $keywords . ' ' . $tag;
        }

        return $this->save([
            'title'       => $title,
            'asset_type'  => $payload['asset_type'] ?? 'other',
            'scope'       => $payload['scope'] ?? 'internal',
            'entity_id'   => (int) ($payload['entity_id'] ?? 0),
            'file_path'   => $filePath,
            'document_id' => (int) ($payload['document_id'] ?? 0),
            'summary'     => trim((string) ($payload['summary'] ?? '')),
            'keywords'    => $keywords,
            'valid_until' => $payload['valid_until'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{filename:string,content:string}
     */
    public function exportAdminCsv(array $params = []): array
    {
        if (!$this->isActive()) {
            return ['filename' => 'enterprise_resources.csv', 'content' => ''];
        }

        return EnterpriseAssetKernelService::exportAdminCsv($params);
    }

    /** @return list<array{id:string,label:string}> */
    public function scopeOptions(): array
    {
        $out = [];
        foreach (EnterpriseAssetService::SCOPE_LABELS as $id => $label) {
            if (in_array($id, ['internal', 'tender', 'product'], true)) {
                $out[] = ['id' => $id, 'label' => $label];
            }
        }

        return $out;
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
        if (!$this->isActive()) {
            return ['total' => 0, 'list' => []];
        }

        return $this->enterpriseAssetService->listAdmin(
            $page,
            $limit,
            $keyword,
            $entityId,
            $assetType,
            $scope,
            $expiringOnly,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function save(array $data): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseAssetService->save($data);
    }

    /** @return ServiceResult */
    public function archive(int $id): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseAssetService->archive($id);
    }

    /**
     * @param list<int> $ids
     */
    public function archiveBatch(array $ids): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseAssetService->archiveBatch($ids);
    }

    public function purge(int $id): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseAssetService->purge($id);
    }

    /**
     * @param list<int> $ids
     */
    public function purgeBatch(array $ids): ServiceResult
    {
        if (!$this->isActive()) {
            return ServiceResult::fail('企业经营资料模块未启用');
        }

        return $this->enterpriseAssetService->purgeBatch($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recall(string $query, int $entityId = 0, int $limit = 8, string $scope = ''): array
    {
        if (!$this->isActive()) {
            return [];
        }

        $hits = $this->enterpriseAssetService->search($query, $entityId, max($limit, 1) * 3);
        if ($scope === '') {
            return array_slice($hits, 0, $limit);
        }

        $filtered = array_values(array_filter(
            $hits,
            static fn (array $row) => (string) ($row['scope'] ?? '') === $scope
                || ($scope === 'internal' && (string) ($row['scope'] ?? '') === 'tender')
        ));

        return array_slice($filtered, 0, $limit);
    }

    /** @return array{total:int,active:int,expiring:int} */
    public function stats(string $scope = ''): array
    {
        if (!$this->isActive()) {
            return ['total' => 0, 'active' => 0, 'expiring' => 0];
        }

        return $this->enterpriseAssetService->stats($scope);
    }
}
