<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

/** {pv:cache name} 与业务事件的 SSOT 映射 */
final class TemplateFragmentInvalidationMap
{

    /** @return list<string> */
    public function namesForDocumentSave(): array
    {
        return ['home_latest', 'home_sidebar', 'tagcloud', 'hot_tags'];
    }

    /** @return list<string> */
    public function namesForItemCatalog(): array
    {
        return ['item_catalog', 'home_products'];
    }

    /** @return list<string> */
    public function namesForTagStructure(): array
    {
        return ['tagcloud', 'hot_tags'];
    }

    /** @return list<string> */
    public function namesForMetaSave(): array
    {
        return ['site_nav', 'friendlinks', 'site_header', 'site_footer', 'site_branding'];
    }

    /** @return list<string> */
    public function namesForHomeWidgets(): array
    {
        return ['home_slides', 'home_ads'];
    }

    /** @return list<string> 已登记 fragment name */
    public function allKnownNames(): array
    {
        $set = [];
        foreach ([
            $this->namesForDocumentSave(),
            $this->namesForItemCatalog(),
            $this->namesForTagStructure(),
            $this->namesForMetaSave(),
            $this->namesForHomeWidgets(),
        ] as $group) {
            foreach ($group as $name) {
                $set[$name] = true;
            }
        }
        $names = array_keys($set);
        sort($names);

        return $names;
    }

    /**
     * @return list<array{name:string,hook:string,trigger:string}>
     */
    public function hookCatalog(): array
    {
        $rows = [];
        foreach ($this->namesForDocumentSave() as $name) {
            $rows[] = [
                'name'    => $name,
                'hook'    => 'document.after_save · document.deleted',
                'trigger' => 'TemplateFragmentHookService → EventBus',
            ];
        }
        foreach ($this->namesForItemCatalog() as $name) {
            $rows[] = [
                'name'    => $name,
                'hook'    => 'item catalog save',
                'trigger' => 'app(FrontCacheInvalidator::class)->invalidateItemCatalog()',
            ];
        }
        foreach ($this->namesForTagStructure() as $name) {
            $rows[] = [
                'name'    => $name,
                'hook'    => 'tag structure',
                'trigger' => 'app(FrontCacheInvalidator::class)->invalidateTags()',
            ];
        }
        foreach ($this->namesForMetaSave() as $name) {
            $rows[] = [
                'name'    => $name,
                'hook'    => 'meta / nav / links / branding',
                'trigger' => 'app(FrontCacheInvalidator::class)->invalidateMeta()',
            ];
        }
        foreach ($this->namesForHomeWidgets() as $name) {
            $rows[] = [
                'name'    => $name,
                'hook'    => 'home slides / ad slots',
                'trigger' => 'SiteSlideService · SiteAdSlotService afterChange()',
            ];
        }

        return $rows;
    }
}
