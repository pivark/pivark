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
use app\common\support\ServiceResult;
use app\common\service\audit\AuditLogService;
use app\common\service\site\NavChannelKind;
use app\common\service\site\SiteNavService;
use app\common\model\SiteNav;
use app\common\model\Tag;
use app\common\support\SiteUrl;

/**
 * 后台「从 Tag 导入网站栏目」主路径已关闭。
 * 仅保留 WeappTagGateway / 易优迁移一次性建 site_nav 真分类（写入 content_kind）。
 */
class TagNavImportService
{
    public const RETIRED_MESSAGE = '已废止（AD-031）：分类真源是「网站栏目」site_nav，禁止从 Tag 导入导航。请在网站栏目维护真分类；Tag 只做聚合。';

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TagService $tags,
    ) {
    }

    /**
     * 迁移插件专用：由 Tag 树一次性生成 site_nav（真分类），须带 migrate=1。
     * 后台 UI/API 已关闭，无 migrate 参数时拒绝。
     *
     * @param array<string, mixed> $options
     */
    public function execute(array $options = []): ServiceResult
    {
        if ((int) ($options['migrate'] ?? 0) !== 1) {
            return ServiceResult::fail(self::RETIRED_MESSAGE);
        }

        $items = $this->buildPlan($options);
        if ($items === []) {
            return ServiceResult::fail('没有可导入的标签');
        }

        $parentNavId = max(0, (int) ($options['parent_nav_id'] ?? 0));
        $mode        = (string) ($options['mode'] ?? 'append');
        if ($mode === 'replace_tag_navs') {
            SiteNav::where('nav_type', SiteNavService::TYPE_TAG)->delete();
        } elseif ($mode === 'replace_children' && $parentNavId > 0) {
            SiteNav::where('parent_id', $parentNavId)->delete();
        }

        $navIdByTag = [];
        $created    = 0;
        $now        = AppTime::now();
        $sortBase   = (int) SiteNav::where('parent_id', $parentNavId)->max('sort');

        foreach ($items as $item) {
            $tagId    = (int) ($item['tag_id'] ?? 0);
            $tagPid   = (int) ($item['tag_parent_id'] ?? 0);
            $navPid   = $tagPid > 0 && isset($navIdByTag[$tagPid]) ? $navIdByTag[$tagPid] : $parentNavId;
            $sortBase++;
            $contentKind = (string) ($item['content_kind'] ?? NavChannelKind::DOCUMENT);
            // 真分类：TYPE_ROUTE + url_path；禁再写 TYPE_TAG 骨架
            $urlPath = trim((string) ($item['target'] ?? ''), '/');
            $navId = (int) SiteNav::insertGetId([
                'parent_id'    => $navPid,
                'title'        => (string) ($item['title'] ?? ''),
                'nav_type'     => SiteNavService::TYPE_ROUTE,
                'target'       => '',
                'url_path'     => $urlPath,
                'content_kind' => $contentKind,
                'sort'         => $sortBase,
                'status'       => 1,
                'open_new_tab' => 0,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $navIdByTag[$tagId] = $navId;
            ++$created;
        }

        $this->auditLog->operate('迁移：Tag→site_nav 真分类', 'admin.tag', [
            'created' => $created,
            'parent_nav_id' => $parentNavId,
        ]);

        app(\app\common\service\infra\MetaSqlCacheService::class)->forget('site_nav_rows');

        return ServiceResult::ok(['created' => $created], "已写入 {$created} 条网站栏目");
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    private function buildPlan(array $options): array
    {
        $groupId  = max(0, (int) ($options['group_id'] ?? 0));
        $tagIds   = [];
        if (isset($options['tag_ids']) && is_array($options['tag_ids'])) {
            foreach ($options['tag_ids'] as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) {
                    $tagIds[$tid] = $tid;
                }
            }
            $tagIds = array_values($tagIds);
        }
        // show_in_nav 列已删除；options.only_show_in_nav 忽略
        $query = Tag::where('status', 1)->order('nav_sort', 'asc')->order('id', 'asc');
        if ($groupId > 0) {
            $query->where('group_id', $groupId);
        }
        if ($tagIds !== []) {
            $query->whereIn('id', $tagIds);
        }
        $rows = $query->select()->toArray();
        if ($rows === []) {
            return [];
        }

        $forest = $this->tags->buildTagForest($rows);
        $flat   = [];
        $this->flattenForestPlan($forest, $flat);

        return $flat;
    }

    /**
     * @param list<array<string, mixed>> $forest
     * @param list<array<string, mixed>> $out
     */
    private function flattenForestPlan(array $forest, array &$out): void
    {
        foreach ($forest as $node) {
            $tagId = (int) ($node['id'] ?? 0);
            if ($tagId < 1) {
                continue;
            }
            $slug = (string) ($node['slug'] ?? '');
            $urlPath = trim((string) ($node['url_path'] ?? ''));
            $target  = $urlPath !== '' ? $urlPath : $slug;
            $kindRaw = (string) ($node['kind'] ?? '');
            $contentKind = $kindRaw === 'product' || $kindRaw === 'item'
                ? NavChannelKind::PRODUCT
                : NavChannelKind::DOCUMENT;
            $out[] = [
                'tag_id'         => $tagId,
                'tag_parent_id'  => (int) ($node['parent_id'] ?? 0),
                'title'          => (string) ($node['name'] ?? ''),
                'target'         => $target,
                'content_kind'   => $contentKind,
                'url'            => (string) ($node['url'] ?? SiteUrl::tag($target !== '' ? $target : $slug)),
            ];
            if (!empty($node['children']) && is_array($node['children'])) {
                $this->flattenForestPlan($node['children'], $out);
            }
        }
    }
}
