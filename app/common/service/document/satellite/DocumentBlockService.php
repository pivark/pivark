<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\ServiceResult;

use app\common\service\document\DocumentPublicService;
use app\common\service\favorite\FavoriteConfigService;
use app\common\service\tag\TagService;

/** 前台 {pv:tagdocuments} 文档块：后台预览与片段生成 */
class DocumentBlockService
{

    public function __construct(
        private readonly TagService $tagService,
        private readonly DocumentFavoriteService $documentFavoriteService,
        private readonly FavoriteConfigService $favoriteConfigService,
        private readonly DocumentPublicService $documentPublic,
    ) {
    }

    /** @return list<array{value:string,label:string,sort?:string,need_favorite?:bool}> */
    public function orderbyOptions(): array
    {
        return [
            ['value' => 'new', 'label' => '最新发布', 'sort' => 'published_at_desc'],
            ['value' => 'hot', 'label' => '点击量', 'sort' => 'click_desc'],
            ['value' => 'like', 'label' => '点赞数', 'sort' => 'favorite_desc', 'need_favorite' => true],
            ['value' => 'collect', 'label' => '收藏数', 'sort' => 'collect_desc', 'need_favorite' => true],
            ['value' => 'aid', 'label' => '文档 ID', 'sort' => 'id_desc'],
        ];
    }

    /** @return list<array{value:string,label:string}> */
    public function attrOptions(): array
    {
        return [
            ['value' => '', 'label' => '不限'],
            ['value' => 'headline', 'label' => '头条'],
            ['value' => 'recommend', 'label' => '推荐'],
            ['value' => 'push', 'label' => '推送'],
            ['value' => 'has_image', 'label' => '有图'],
            ['value' => 'external', 'label' => '外链'],
        ];
    }

    /** @return array<string, mixed> */
    public function metaForAdmin(): array
    {
        $tags = [];
        foreach ($this->tagService->listAllActive() as $row) {
            $tags[] = [
                'slug' => (string) ($row['slug'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return [
            'tags'             => $tags,
            'favorite_enabled' => $this->documentFavoriteService->enabled(),
            'favorite_cfg'     => $this->favoriteConfigService->adminCfg(),
            'orderby_options'  => $this->orderbyOptions(),
            'attr_options'     => $this->attrOptions(),
            'default_inner_tpl' => <<<'HTML'
<div class="pv-doc-block-item">
  <a href="{$field.url}"><h3>{$field.title}</h3></a>
  <p>{$field.summary}</p>
</div>
HTML,
        ];
    }

    /**
     * @param array<string, mixed> $params tags, row, orderby, attr, inner_tpl
     * @return ServiceResult
     */
    public function previewForAdmin(array $params): ServiceResult
    {
        $row     = min(100, max(1, (int) ($params['row'] ?? 10)));
        $tags    = trim((string) ($params['tags'] ?? ''));
        $orderby = strtolower(trim((string) ($params['orderby'] ?? 'new')));
        $attr    = trim((string) ($params['attr'] ?? ''));
        $sort    = $this->mapOrderbyToSort($orderby);

        $result = $this->documentPublic->listPublic([
            'page'  => 1,
            'limit' => $row,
            'tags'  => $tags,
            'sort'  => $sort,
            'attr'  => $attr,
        ]);

        $inner = trim((string) ($params['inner_tpl'] ?? ''));
        if ($inner === '') {
            $inner = (string) ($this->metaForAdmin()['default_inner_tpl'] ?? '');
        }

        return ServiceResult::ok([
            'total'   => (int) ($result['total'] ?? 0),
            'list'    => is_array($result['list'] ?? null) ? $result['list'] : [],
            'sort'    => $sort,
            'snippet' => $this->buildSnippet([
                'tags'    => $tags,
                'row'     => $row,
                'orderby' => $orderby,
                'attr'    => $attr,
                'inner'   => $inner,
            ]),
        ], '');
    }

    /**
     * @param array<string, mixed> $p
     */
    public function buildSnippet(array $p): string
    {
        $attrs = ['row="' . (int) ($p['row'] ?? 10) . '"'];
        $tags = trim((string) ($p['tags'] ?? ''));
        if ($tags !== '') {
            $attrs[] = 'tags="' . htmlspecialchars($tags, ENT_QUOTES, 'UTF-8') . '"';
        }
        $orderby = trim((string) ($p['orderby'] ?? ''));
        if ($orderby !== '' && $orderby !== 'new') {
            $attrs[] = 'orderby="' . htmlspecialchars($orderby, ENT_QUOTES, 'UTF-8') . '"';
        }
        $attr = trim((string) ($p['attr'] ?? ''));
        if ($attr !== '') {
            $attrs[] = 'attr="' . htmlspecialchars($attr, ENT_QUOTES, 'UTF-8') . '"';
        }
        $inner = (string) ($p['inner'] ?? '');
        $open  = '{pv:arclist ' . implode(' ', $attrs) . '}';

        return $open . "\n" . $inner . "\n{/pv:arclist}";
    }

    public function mapOrderbyToSort(string $orderby): string
    {
        return match (strtolower(trim($orderby))) {
            'add_time', 'new', 'update_time' => 'published_at_desc',
            'click', 'hot'                   => 'click_desc',
            'like', 'favorite'               => 'favorite_desc',
            'collect', 'fav'                 => 'collect_desc',
            'aid'                            => 'id_desc',
            default                          => 'id_desc',
        };
    }
}
