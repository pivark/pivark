<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\tag;

use app\common\support\AppTime;

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

use app\common\service\site\SiteDomainEntitlementService;
use app\common\service\plugin\PluginService;
use app\common\model\Plugin;
use app\common\model\Tag;
use app\common\model\TagGroup as TagGroupModel;
use app\common\support\AdminListParams;

/** 标签分组 */
class TagGroupService
{

    public function __construct(
        private readonly PluginService $pluginService,
        private readonly SiteDomainEntitlementService $siteDomainEntitlementService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        return TagGroupModel::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** 后台新建标签时的默认分组（首个启用分组） */
    public function defaultGroupId(): int
    {
        $row = TagGroupModel::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('id')
            ->find();

        return $row !== null ? (int) $row['id'] : 0;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listForSelect(): array
    {
        $out = [['id' => 0, 'name' => '未分组']];
        foreach ($this->listActive() as $row) {
            $out[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{identifier:string,name:string}>
     */
    public function listPluginOptionsForEntitlementSlots(): array
    {
        $rows = Plugin::where('installed', 1)->order('identifier', 'asc')->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $identifier = strtolower(trim((string) ($row['identifier'] ?? '')));
            if ($identifier === '') {
                continue;
            }
            $manifest = $this->pluginService->readManifest($identifier);
            $out[]    = [
                'identifier' => $identifier,
                'name'       => (string) ($manifest['name'] ?? $identifier),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        return $this->listAdminPaged(['limit' => QueryLimit::ADMIN_UNBOUNDED])['list'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $p     = AdminListParams::parse($params);
        $query = TagGroupModel::order('sort', 'asc')->order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'name|slug|description');
        $total = (int) $query->count();
        $rows  = $query->page($p['page'], $p['limit'])->select()->toArray();
        $out   = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row);
        }

        return ['list' => $out, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $id
     */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = TagGroupModel::where('id', $id)->find()?->toArray();
        return $row ? $this->formatAdminRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id     = (int) ($data['id'] ?? 0);
        $name   = trim((string) ($data['name'] ?? ''));
        $sort   = (int) ($data['sort'] ?? 0);
        $status = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $requires = $this->siteDomainEntitlementService->normalizeIdentifiers(
            $data['requires_entitlement'] ?? $data['requires_entitlement[]'] ?? null
        );
        $now    = AppTime::now();

        if ($name === '') {
            return ServiceResult::fail('分组名称不能为空');
        }
        if (mb_strlen($name) > 100) {
            return ServiceResult::fail('分组名称过长');
        }

        $dup = TagGroupModel::where('name', $name);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->count() > 0) {
            return ServiceResult::fail('分组名称已存在');
        }

        $payload = [
            'name'                  => $name,
            'sort'                  => $sort,
            'status'                => $status,
            'requires_entitlement'  => $requires === [] ? null : json_encode($requires, JSON_UNESCAPED_UNICODE),
            'updated_at'            => $now,
        ];

        if ($id > 0) {
            TagGroupModel::where('id', $id)->update($payload);
            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) TagGroupModel::insertGetId($payload);
        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $sort
     */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!TagGroupModel::where('id', $id)->find()) {
            return ServiceResult::fail('分组不存在');
        }
        TagGroupModel::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        return ServiceResult::ok(null, '已更新');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $status
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!TagGroupModel::where('id', $id)->find()) {
            return ServiceResult::fail('分组不存在');
        }
        $status = $status === 1 ? 1 : 0;
        TagGroupModel::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }

        $used = (int) Tag::where('group_id', $id)->count();
        if ($used > 0) {
            return ServiceResult::fail("该分组下仍有 {$used} 个标签，请先移出或改分组后再删除");
        }

        TagGroupModel::where('id', $id)->delete();
        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row): array
    {
        $id = (int) $row['id'];
        $requires = $this->siteDomainEntitlementService->normalizeIdentifiers($row['requires_entitlement'] ?? null);
        $labels   = [];
        foreach ($requires as $identifier) {
            $manifest = $this->pluginService->readManifest($identifier);
            $labels[] = (string) ($manifest['name'] ?? $identifier);
        }

        return [
            'id'                      => $id,
            'name'                    => (string) $row['name'],
            'sort'                    => (int) ($row['sort'] ?? 0),
            'status'                  => (int) ($row['status'] ?? 1),
            'status_text'             => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'requires_entitlement'    => $requires,
            'requires_entitlement_text' => $labels === [] ? '' : implode('、', $labels),
            'tag_count'               => (int) Tag::where('group_id', $id)->count(),
            'created_at'              => (string) ($row['created_at'] ?? ''),
            'updated_at'              => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
