<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\service\site\SiteDomainEntitlementService;

use app\common\model\FloatContactItem;

use think\facade\Request;

use app\common\model\SiteDomain;
use app\common\model\Tag;
use app\common\model\TagGroup;
use app\common\service\tag\TagPublicService;
use app\common\support\AdminListParams;
use app\common\support\ModelRelationLoad;

/** 同站多域 → Tag 分组（AD-019） */
class SiteDomainService
{

    public function __construct(
        private readonly TagPublicService $tagPublicService,
        private readonly SiteDomainEntitlementService $siteDomainEntitlementService,
    ) {
    }

    public function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if (str_contains($host, ':')) {
            $host = (string) parse_url('http://' . $host, PHP_URL_HOST);
        }
        $host = rtrim($host, '.');

        return $host;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveCurrentHost(?string $host = null): ?array
    {
        $host = $this->normalizeHost($host ?? (string) Request::host());
        if ($host === '') {
            return null;
        }

        $row = $this->siteDomainRow(SiteDomain::where('host', $host)->where('status', 1)->find());
        if ($row === null) {
            return null;
        }

        return $this->formatResolvedRow($row);
    }

    /**
     * 开发预览：未解析域名时可用 `?_pv_site_host=` 模拟 Host（仅 APP_DEBUG）。
     *
     * @return array<string, mixed>|null
     */
    public function resolveDebugPreviewHost(): ?array
    {
        if (!filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $preview = $this->normalizeHost((string) Request::get('_pv_site_host', ''));
        if ($preview === '') {
            return null;
        }

        return $this->resolveCurrentHost($preview);
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
        $query = SiteDomain::alias('d')->with(['tagGroup' => static function ($groupQuery): void {
            $groupQuery->field('id,name');
        }])->order('d.sort', 'asc')->order('d.id', 'asc');
        if ($p['keyword'] !== '') {
            $like = '%' . addcslashes($p['keyword'], '%_\\') . '%';
            $query->where(function ($sub) use ($like): void {
                $sub->whereLike('d.host', $like)
                    ->whereOr(function ($or) use ($like): void {
                        $or->whereHas('tagGroup', static function ($groupQuery) use ($like): void {
                            $groupQuery->whereLike('name', $like);
                        });
                    });
            });
        }
        $total = (int) $query->count();
        $rows  = ModelRelationLoad::mapBelongsTo(
            $query->page($p['page'], $p['limit'])->select(),
            'tagGroup',
            ['name' => 'tag_group_name'],
        );
        $out   = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row);
        }

        return ['list' => $out, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /**
     * 某标签分组下可选的「首页直达」标签（启用态）
     *
     * @return list<array{id:int,name:string,slug:string,url_path:string}>
     */
    public function listTagsForGroup(int $groupId): array
    {
        if ($groupId < 1) {
            return [];
        }
        if (!TagGroup::where('id', $groupId)->where('status', 1)->find()) {
            return [];
        }

        $rows = Tag::where('group_id', $groupId)
            ->where('status', 1)
            ->order('nav_sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $out[] = [
                'id'       => (int) ($row['id'] ?? 0),
                'name'     => (string) ($row['name'] ?? ''),
                'slug'     => $slug,
                'url_path' => $this->tagPublicService->publicPath($row),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id              = (int) ($data['id'] ?? 0);
        $host            = $this->normalizeHost((string) ($data['host'] ?? ''));
        $tagGroupId      = (int) ($data['tag_group_id'] ?? 0);
        $defaultTagSlug  = trim((string) ($data['default_tag_slug'] ?? ''));
        $sort            = (int) ($data['sort'] ?? 0);
        $status          = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $isPrimary       = !empty($data['is_primary']) ? 1 : 0;
        $now             = AppTime::now();

        if ($host === '') {
            return ServiceResult::fail('请填写域名');
        }
        if (mb_strlen($host) > 255) {
            return ServiceResult::fail('域名过长');
        }
        if ($tagGroupId < 1) {
            return ServiceResult::fail('请选择标签分组');
        }
        if (!TagGroup::where('id', $tagGroupId)->where('status', 1)->find()) {
            return ServiceResult::fail('标签分组不存在或已禁用');
        }
        if ($status === 1) {
            $entitled = $this->siteDomainEntitlementService->assertTagGroupEntitled($tagGroupId);
            if (!$entitled->isOk()) {
                return $entitled;
            }
        }
        if (mb_strlen($defaultTagSlug) > 120) {
            return ServiceResult::fail('默认标签 slug 过长');
        }

        $dup = SiteDomain::where('host', $host);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->count() > 0) {
            return ServiceResult::fail('该域名已登记');
        }

        $payload = [
            'host'             => $host,
            'tag_group_id'     => $tagGroupId,
            'default_tag_slug' => mb_substr($defaultTagSlug, 0, 120),
            'sort'             => $sort,
            'status'           => $status,
            'is_primary'       => $isPrimary,
            'updated_at'       => $now,
        ];

        if ($isPrimary === 1) {
            SiteDomain::where('is_primary', 1)->update(['is_primary' => 0, 'updated_at' => $now]);
        }

        if ($id > 0) {
            if (!SiteDomain::where('id', $id)->find()) {
                return ServiceResult::fail('记录不存在');
            }
            SiteDomain::where('id', $id)->update($payload);

            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) SiteDomain::insertGetId($payload);

        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /**
     * @return ServiceResult
     */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1 || !SiteDomain::where('id', $id)->find()) {
            return ServiceResult::fail('记录不存在');
        }
        SiteDomain::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '排序已更新');
    }

    /**
     * @return ServiceResult
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1 || !SiteDomain::where('id', $id)->find()) {
            return ServiceResult::fail('记录不存在');
        }
        if ($status === 1) {
            $row = $this->siteDomainRow(SiteDomain::where('id', $id)->find());
            if ($row === null) {
                return ServiceResult::fail('记录不存在');
            }
            $tagGroupId = (int) ($row['tag_group_id'] ?? 0);
            $entitled   = $this->siteDomainEntitlementService->assertTagGroupEntitled($tagGroupId);
            if (!$entitled->isOk()) {
                return $entitled;
            }
        }
        SiteDomain::where('id', $id)->update([
            'status'     => $status === 1 ? 1 : 0,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, $status === 1 ? '已启用' : '已禁用');
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !SiteDomain::where('id', $id)->find()) {
            return ServiceResult::fail('记录不存在');
        }
        SiteDomain::where('id', $id)->delete();

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatResolvedRow(array $row): array
    {
        return [
            'id'               => (int) ($row['id'] ?? 0),
            'host'             => (string) ($row['host'] ?? ''),
            'tag_group_id'     => (int) ($row['tag_group_id'] ?? 0),
            'default_tag_slug' => trim((string) ($row['default_tag_slug'] ?? '')),
            'is_primary'       => (int) ($row['is_primary'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row): array
    {
        $defaultSlug = trim((string) ($row['default_tag_slug'] ?? ''));

        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'host'                 => (string) ($row['host'] ?? ''),
            'tag_group_id'         => (int) ($row['tag_group_id'] ?? 0),
            'tag_group_name'       => (string) ($row['tag_group_name'] ?? ''),
            'default_tag_slug'     => $defaultSlug,
            'default_tag_url_path' => $this->defaultTagUrlPath($defaultSlug),
            'is_primary'           => (int) ($row['is_primary'] ?? 0),
            'sort'                 => (int) ($row['sort'] ?? 0),
            'status'               => (int) ($row['status'] ?? 0),
            'created_at'           => (string) ($row['created_at'] ?? ''),
            'updated_at'           => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function defaultTagUrlPath(string $slug): string
    {
        if ($slug === '') {
            return '';
        }
        $tagRow = Tag::where('slug', $slug)->where('status', 1)->find();
        if ($tagRow === null) {
            return '';
        }

        return $this->tagPublicService->publicPath($tagRow->toArray());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function siteDomainRow(mixed $found): ?array
    {
        if ($found instanceof SiteDomain) {
            return $found->toArray();
        }

        return is_array($found) ? $found : null;
    }
}
