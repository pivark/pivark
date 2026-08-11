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
use app\common\model\EnterpriseEntity;
use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\ServiceResult;
use think\facade\Db;

/** 公司经营主体 CRUD（内核 SSOT · 不依赖标书插件） */
final class EnterpriseEntityService
{

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return $this->listByStatus(1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByStatus(?int $status = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $query = EnterpriseEntity::order('is_default', 'desc')->order('id', 'asc');
        if ($status === 1 || $status === 0) {
            $query->where('status', $status);
        }

        $rows = $query->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $row['asset_count'] = $this->countActiveAssets($id);
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<array{id:int,name:string}> */
    public function listOptions(): array
    {
        $out = [];
        foreach ($this->listActive() as $row) {
            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function defaultEntity(): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = EnterpriseEntity::where('status', 1)
            ->order('is_default', 'desc')
            ->order('id', 'asc')
            ->find();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): ServiceResult
    {
        if (!$this->tableExists()) {
            return ServiceResult::fail('主体表未初始化，请执行内核迁移');
        }

        $id   = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ServiceResult::fail('请填写主体名称');
        }

        $payload = [
            'name'          => $name,
            'credit_code'   => trim((string) ($data['credit_code'] ?? '')),
            'legal_person'  => trim((string) ($data['legal_person'] ?? '')),
            'address'       => trim((string) ($data['address'] ?? '')),
            'contact_phone' => trim((string) ($data['contact_phone'] ?? '')),
            'is_default'    => !empty($data['is_default']) ? 1 : 0,
            'status'        => 1,
            'updated_at'    => AppTime::now(),
        ];

        if (!empty($data['is_default'])) {
            EnterpriseEntity::where('id', '>', 0)->update(['is_default' => 0]);
        }

        if ($id > 0) {
            EnterpriseEntity::where('id', $id)->update($payload);

            return ServiceResult::ok(['id' => $id], '已更新');
        }

        $payload['created_at'] = $payload['updated_at'];
        $newId = (int) EnterpriseEntity::insertGetId($payload);

        return ServiceResult::ok(['id' => $newId], '已创建');
    }

    public function archive(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('无效主体');
        }
        if (!$this->tableExists()) {
            return ServiceResult::fail('主体表未初始化');
        }

        $row = EnterpriseEntity::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('主体不存在');
        }

        $activeProjects = $this->activeTenderProjectCount($id);
        if ($activeProjects > 0) {
            return ServiceResult::fail("该主体仍关联 {$activeProjects} 个进行中标书项目，请先归档或更换主体后再停用");
        }

        EnterpriseEntity::where('id', $id)->update([
            'status'     => 0,
            'is_default' => 0,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '主体已冻结，名下资料不再参与插件与 AI 召回');
    }

    public function restore(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('无效主体');
        }
        if (!$this->tableExists()) {
            return ServiceResult::fail('主体表未初始化');
        }

        $row = EnterpriseEntity::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('主体不存在');
        }
        if ((int) ($row['status'] ?? 0) === 1) {
            return ServiceResult::ok(null, '主体已在用');
        }

        EnterpriseEntity::where('id', $id)->update([
            'status'     => 1,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '主体已恢复');
    }

    /**
     * 永久删除主体；须指定名下经营资料处理方式。
     *
     * @param 'archive_assets'|'keep_shared' $assetMode
     */
    public function delete(int $id, string $assetMode = 'keep_shared'): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('无效主体');
        }
        if (!$this->tableExists()) {
            return ServiceResult::fail('主体表未初始化');
        }

        $row = EnterpriseEntity::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('主体不存在');
        }

        $activeProjects = $this->activeTenderProjectCount($id);
        if ($activeProjects > 0) {
            return ServiceResult::fail("该主体仍关联 {$activeProjects} 个进行中标书项目，请先归档或更换主体后再删除");
        }

        $assetMode = strtolower(trim($assetMode));
        if (!in_array($assetMode, ['keep_shared', 'archive_assets'], true)) {
            return ServiceResult::fail('请选择名下经营资料的处理方式');
        }

        $assetCount = $this->countActiveAssets($id);
        $affected   = 0;
        if ($assetCount > 0) {
            $affected = $assetMode === 'archive_assets'
                ? EnterpriseAssetKernelService::archiveEntityAssets($id)
                : EnterpriseAssetKernelService::releaseEntityAssets($id);
        }

        if ((int) ($row['is_default'] ?? 0) === 1) {
            EnterpriseEntity::where('id', $id)->update(['is_default' => 0]);
        }

        EnterpriseEntity::where('id', $id)->delete();

        return ServiceResult::ok(
            ['assets_affected' => $affected, 'asset_mode' => $assetMode],
            '主体已永久删除',
        );
    }

    private function countActiveAssets(int $entityId): int
    {
        if ($entityId < 1 || !DbTable::modelExists(EnterpriseAsset::class)) {
            return 0;
        }

        return (int) EnterpriseAsset::where('entity_id', $entityId)
            ->where('status', '<>', 'archived')
            ->count();
    }

    public function tableExists(): bool
    {
        return DbTable::modelExists(EnterpriseEntity::class);
    }

    private function activeTenderProjectCount(int $entityId): int
    {
        $table = DbTable::name('weapp_tender_projects');
        if (!DbTable::exists($table)) {
            return 0;
        }

        return (int) Db::table($table)
            ->where('entity_id', $entityId)
            ->whereNotIn('status', ['archived', 'void'])
            ->count();
    }

    /**
     * 同名在用主体分组（名称 trim + 小写归一）。
     *
     * @return list<array{name:string,count:int,entities:list<array<string,mixed>>}>
     */
    public function duplicateNameGroups(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $grouped = [];
        foreach (EnterpriseEntity::where('status', 1)->order('id', 'asc')->select()->toArray() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $row['asset_count'] = $this->countActiveAssets((int) ($row['id'] ?? 0));
            $grouped[$key][] = $row;
        }

        $out = [];
        foreach ($grouped as $items) {
            if (count($items) < 2) {
                continue;
            }
            usort($items, static function (array $a, array $b): int {
                $defaultA = (int) ($a['is_default'] ?? 0);
                $defaultB = (int) ($b['is_default'] ?? 0);
                if ($defaultA !== $defaultB) {
                    return $defaultB <=> $defaultA;
                }
                $assetsA = (int) ($a['asset_count'] ?? 0);
                $assetsB = (int) ($b['asset_count'] ?? 0);
                if ($assetsA !== $assetsB) {
                    return $assetsB <=> $assetsA;
                }

                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });
            $out[] = [
                'name'     => (string) ($items[0]['name'] ?? ''),
                'count'    => count($items),
                'entities' => $items,
            ];
        }

        return $out;
    }

    /** @return array{duplicate_groups:int,duplicate_entities:int} */
    public function duplicateStats(): array
    {
        $groups = $this->duplicateNameGroups();
        $duplicateEntities = 0;
        foreach ($groups as $group) {
            $duplicateEntities += max(0, (int) ($group['count'] ?? 0) - 1);
        }

        return [
            'duplicate_groups'    => count($groups),
            'duplicate_entities'  => $duplicateEntities,
        ];
    }

    public function consolidateDuplicateNames(): ServiceResult
    {
        if (!$this->tableExists()) {
            return ServiceResult::fail('主体表未初始化');
        }

        $groups = $this->duplicateNameGroups();
        if ($groups === []) {
            return ServiceResult::ok(
                ['groups' => 0, 'removed' => 0, 'assets_moved' => 0, 'skipped' => 0],
                '未发现重复主体',
            );
        }

        $removed = 0;
        $assetsMoved = 0;
        $skipped = 0;
        foreach ($groups as $group) {
            $entities = $group['entities'] ?? [];
            if (!is_array($entities) || count($entities) < 2) {
                continue;
            }
            $keepId = (int) ($entities[0]['id'] ?? 0);
            if ($keepId < 1) {
                continue;
            }
            for ($i = 1, $n = count($entities); $i < $n; $i++) {
                $dupId = (int) ($entities[$i]['id'] ?? 0);
                if ($dupId < 1 || $dupId === $keepId) {
                    continue;
                }
                if ($this->activeTenderProjectCount($dupId) > 0) {
                    $skipped++;
                    continue;
                }
                $assetsMoved += EnterpriseAssetKernelService::reassignEntityAssets($dupId, $keepId);
                EnterpriseEntity::where('id', $dupId)->delete();
                $removed++;
            }
        }

        if ($removed < 1 && $skipped < 1) {
            return ServiceResult::ok(
                ['groups' => count($groups), 'removed' => 0, 'assets_moved' => 0, 'skipped' => 0],
                '未发现可自动合并的重复主体',
            );
        }

        $message = "已合并 {$removed} 个重复主体";
        if ($assetsMoved > 0) {
            $message .= "，迁移 {$assetsMoved} 条资料";
        }
        if ($skipped > 0) {
            $message .= "；{$skipped} 个仍关联标书项目已跳过";
        }

        return ServiceResult::ok(
            [
                'groups'       => count($groups),
                'removed'      => $removed,
                'assets_moved' => $assetsMoved,
                'skipped'      => $skipped,
            ],
            $message,
        );
    }
}
