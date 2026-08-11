<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;


use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\support\AppTime;


use app\common\service\tag\TagCore;
use app\common\service\tag\TagService;
use app\common\support\HtmlSanitizer;
use app\common\support\SiteUrl;

final class DocumentFormatService
{

    public function __construct(
        private readonly DocumentAttrHelper $attrs,
        private readonly TagService $tags,
    ) {
    }

    /**
     * 后台列表/预览/前台 detail：跟 media_url_mode（经 SiteUrl::public）。
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>>|null $prefetchedTags
     */
    public function buildPublicDocumentUrl(array $row, ?array $prefetchedTags = null, ?string $publicHome = null): string
    {
        unset($publicHome); // 出站一律 SiteUrl::public，禁止再拼 configuredPublicHome
        $path = trim(SiteUrl::documentFromRow($row, $prefetchedTags));
        if ($path === '' || $path === '#') {
            return '';
        }

        return $path;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    public function enrichListForView(array $list): array
    {
        foreach ($list as &$item) {
            $tagRows = is_array($item['tags'] ?? null) ? $item['tags'] : null;
            $item['url']         = SiteUrl::documentFromRow($item, $tagRows);
            $item['create_date'] = !empty($item['created_at'])
                ? AppTime::format('Y-m-d', strtotime((string) $item['created_at'])) : '';
            $item['excerpt'] = (string) ($item['summary'] ?? '');
            $item['excerpt_short'] = mb_strlen($item['excerpt']) > 120
                ? mb_substr($item['excerpt'], 0, 120) : $item['excerpt'];
            $item = $this->attrs->decorateAttrFlagsForView($item);
            $item = $this->applyExtraFieldsToRow($item);
            DocumentAddonBridgeAccess::invokeMetaOr(null, 'list_item_apply', 'applyToItem', [&$item]);
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function applyExtraFieldsToRow(array $row): array
    {
        $flat = app(TagCore::class)->normalizeExtraFields($row['extra_json'] ?? $row['extra_fields'] ?? null);
        foreach ($flat as $key => $value) {
            $row['field_extra_' . $key] = $value;
        }
        if (!isset($row['extra_fields']) || !is_array($row['extra_fields'])) {
            $row['extra_fields'] = app(TagCore::class)->normalizeExtraFieldDefs($row['extra_json'] ?? null);
        }
        unset($row['extra_json']);

        return $row;
    }

    /**
     * @param array<string, mixed>              $row
     * @param list<array<string, mixed>>|null $prefetchedTags
     * @return array<string, mixed>
     */
    public function formatForApi(array $row, bool $detail = false, ?array $prefetchedTags = null): array
    {
        $tags = $prefetchedTags ?? $this->tags->getTagsForDocument((int) $row['id']);

        $item = [
            'id'           => (int) $row['id'],
            'title'        => $row['title'],
            'summary'      => $row['summary'] ?? '',
            'litpic'       => $row['litpic'] ?? '',
            'status'       => (int) $row['status'],
            'click'        => (int) ($row['click'] ?? 0),
            'tags'         => $tags,
            'published_at' => $row['published_at'] ?? null,
            'created_at'   => $row['created_at'] ?? '',
            'url'          => SiteUrl::documentFromRow($row, $tags),
            'html_name'    => (string) ($row['html_name'] ?? ''),
            'url_path'     => trim((string) ($row['url_path'] ?? '')),
            'attr_flags'   => (string) ($row['attr_flags'] ?? ''),
            'read_perm'    => (int) ($row['read_perm'] ?? 0),
            'read_level_id'=> (int) ($row['read_level_id'] ?? 0),
            'extra_json'   => $row['extra_json'] ?? null,
        ];
        $item = $this->applyExtraFieldsToRow($item);

        if ($detail) {
            $loginRequired             = !empty($row['login_required']);
            $item['login_required']    = $loginRequired;
            $item['auth_required']     = $loginRequired;
            $item['read_perm']         = $loginRequired ? 1 : 0;
            $item['read_level_name']   = (string) ($row['read_level_name'] ?? '');
            $item['content']           = $loginRequired ? '' : HtmlSanitizer::cleanArticle((string) ($row['content'] ?? ''));
            $item['seo_title']         = $row['seo_title'] ?? '';
            $item['seo_keywords']      = $row['seo_keywords'] ?? '';
            $item['seo_description']   = $row['seo_description'] ?? '';
        }

        DocumentAddonBridgeAccess::invokeMetaOr(null, 'list_item_apply', 'applyToItem', [&$item]);

        return $item;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function applyListMediaFields(array &$item): void
    {
        $item = $this->attrs->decorateAttrFlagsForView($item);
        DocumentAddonBridgeAccess::invokeMetaOr(null, 'list_item_apply', 'applyToItem', [&$item]);
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    public function applyListMediaFieldsBatch(array $list): array
    {
        foreach ($list as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $item = $this->attrs->decorateAttrFlagsForView($item);
            DocumentAddonBridgeAccess::invokeMetaOr(null, 'list_item_apply', 'applyToItem', [&$item]);
        }
        unset($item);

        return $list;
    }
}
