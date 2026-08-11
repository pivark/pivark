<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\model\Item;
use app\common\service\front\FrontUrlRuleService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\tag\TagService;
use app\common\support\SiteUrl;
use think\facade\Db;

/** 品项前台 URL（无 ItemService 搜索子图，供索引构建等 leaf 使用） */
final class ItemPublicUrlService
{

    public function productItemPage(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $mode = app(SiteUrlModeService::class);
        if ($mode->articleRule() === SiteUrlModeService::ARTICLE_TAG_DIR && $mode->usesPrettyUrl()) {
            $channelPath = $this->primaryTagPathForSlug($slug);
            if ($channelPath !== '') {
                $nested = '/' . $channelPath . '/' . rawurlencode($slug);

                return app(FrontUrlRuleService::class)->withSuffix($nested);
            }
        }

        return SiteUrl::productItem($slug);
    }

    private function primaryTagPathForSlug(string $slug): string
    {
        $itemId = (int) (Item::where('slug', $slug)->where('status', ItemService::STATUS_ACTIVE)->value('id') ?? 0);
        if ($itemId < 1) {
            return '';
        }
        $tagId = (int) (Db::name('item_tags')->where('item_id', $itemId)->order('id', 'asc')->value('tag_id') ?? 0);
        if ($tagId < 1) {
            return '';
        }
        $tag = app(TagService::class)->findRowById($tagId);
        if ($tag === null) {
            return '';
        }

        return app(TagService::class)->publicPath($tag);
    }
}
