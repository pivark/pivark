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

use app\common\model\FloatContactItem;

use app\common\model\SiteLink;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\infra\MetaSqlCacheService;
use app\common\support\AdminBatchSupport;
use app\common\support\AdminListParams;

/** 友情链接 */
class SiteLinkService
{

    public function __construct(
        private readonly MetaSqlCacheService $metaSqlCache,
        private readonly SiteModeService $siteMode,
        private readonly FrontCacheInvalidator $cacheInvalidator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublic(int $limit = QueryLimit::PUBLIC_CATALOG_LIST): array
    {
        $limit = max(1, min(100, $limit));

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->metaSqlCache->remember(
            'site_links_l' . $limit,
            static function () use ($limit): array {
                return SiteLink::where('status', 1)
                    ->where('url', '<>', '')
                    ->order('sort', 'asc')
                    ->order('id', 'asc')
                    ->limit($limit)
                    ->select()
                    ->toArray();
            }
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->formatPublicRow($row);
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
        $query = SiteLink::order('sort', 'asc')->order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'title|url');
        $total = (int) $query->count();
        $rows  = $query->page($p['page'], $p['limit'])->select()->toArray();
        $out   = [];
        foreach ($rows as $row) {
            $out[] = $this->formatAdminRow($row);
        }

        return ['list' => $out, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id         = (int) ($data['id'] ?? 0);
        $title      = trim((string) ($data['title'] ?? ''));
        $url        = trim((string) ($data['url'] ?? ''));
        $logoUrl    = trim((string) ($data['logo_url'] ?? ''));
        $sort       = (int) ($data['sort'] ?? 0);
        $status     = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $openNewTab = !empty($data['open_new_tab']) ? 1 : 0;
        $now        = AppTime::now();

        if ($title === '') {
            return ServiceResult::fail('链接名称不能为空');
        }
        if (mb_strlen($title) > 100) {
            return ServiceResult::fail('链接名称过长');
        }
        if ($url === '') {
            return ServiceResult::fail('请填写链接地址');
        }
        if (!$this->isValidUrl($url)) {
            return ServiceResult::fail('链接需以 http://、https:// 或 / 开头');
        }

        $payload = [
            'title'        => $title,
            'url'          => mb_substr($url, 0, 500),
            'logo_url'     => mb_substr($logoUrl, 0, 500),
            'sort'         => $sort,
            'status'       => $status,
            'open_new_tab' => $openNewTab,
            'updated_at'   => $now,
        ];

        if ($id > 0) {
            if (!SiteLink::where('id', $id)->find()) {
                return ServiceResult::fail('友链不存在');
            }
            SiteLink::where('id', $id)->update($payload);
            $this->afterChange();

            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) SiteLink::insertGetId($payload);
        $this->afterChange();

        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /**
     * @return ServiceResult
     */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1 || !SiteLink::where('id', $id)->find()) {
            return ServiceResult::fail('友链不存在');
        }
        SiteLink::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        $this->afterChange();

        return ServiceResult::ok(null, '已更新');
    }

    /**
     * @return ServiceResult
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1 || !SiteLink::where('id', $id)->find()) {
            return ServiceResult::fail('友链不存在');
        }
        $status = $status === 1 ? 1 : 0;
        SiteLink::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        $this->afterChange();

        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !SiteLink::where('id', $id)->find()) {
            return ServiceResult::fail('友链不存在');
        }
        SiteLink::where('id', $id)->delete();
        $this->afterChange();

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param list<int|string> $ids
     * @return ServiceResult
     */
    public function batchDeleteAdmin(array $ids): array
    {
        return AdminBatchSupport::deleteByIds(
            SiteLink::class,
            $ids,
            fn () => $this->afterChange(),
            '请选择要删除的友链',
        );
    }

    private function isValidUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        return (bool) preg_match('#^https?://#i', $url);
    }

    private function afterChange(): void
    {
        $this->siteMode->clearPageCache();
        $this->cacheInvalidator->invalidateMeta();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPublicRow(array $row): array
    {
        $openNewTab = (int) ($row['open_new_tab'] ?? 1) === 1;

        return [
            'id'           => (int) ($row['id'] ?? 0),
            'title'        => (string) ($row['title'] ?? ''),
            'url'          => (string) ($row['url'] ?? ''),
            'logo_url'     => (string) ($row['logo_url'] ?? ''),
            'link_target'  => $openNewTab ? '_blank' : '_self',
            'open_new_tab' => $openNewTab ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row): array
    {
        return [
            'id'             => (int) ($row['id'] ?? 0),
            'title'          => (string) ($row['title'] ?? ''),
            'url'            => (string) ($row['url'] ?? ''),
            'logo_url'       => (string) ($row['logo_url'] ?? ''),
            'sort'           => (int) ($row['sort'] ?? 0),
            'status'         => (int) ($row['status'] ?? 1),
            'status_text'    => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'open_new_tab'   => (int) ($row['open_new_tab'] ?? 1),
            'created_at'     => (string) ($row['created_at'] ?? ''),
            'updated_at'     => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
