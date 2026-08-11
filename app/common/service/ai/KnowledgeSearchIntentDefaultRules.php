<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

/** 知识搜索引导问法内置规则（冗余审计 §3 外置） */
final class KnowledgeSearchIntentDefaultRules
{
    /**
     * 全站通用默认（配置为空时回落）。
     * 「插件目录」属门户场景，见 pluginCatalog()，由 www 种子写入，不进通用默认。
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::siteOverview(),
        ];
    }

    /**
     * 门户引导问法包：站点介绍 + 插件目录。
     *
     * @return list<array<string, mixed>>
     */
    public static function officialWww(): array
    {
        return [
            self::siteOverview(),
            self::pluginCatalog(),
        ];
    }

    /** @return array<string, mixed> */
    public static function siteOverview(): array
    {
        return [
            'id'                     => 'site_overview',
            'label'                  => '站点介绍',
            'enabled'                => true,
            'builtin'                => true,
            'patterns'               => [
                '/这(个)?站(点)?/u',
                '/本站/u',
                '/贵站/u',
                '/网站(是)?(干|做)(啥|什么)/u',
                '/(主要|到底|究竟)(是)?(干|做)(啥|什么)/u',
                '/做什么的/u',
                '/干啥的/u',
                '/干什么的/u',
                '/站点介绍/u',
                '/网站介绍/u',
                '/公司简介/u',
                '/了解.{0,8}(站|网站)/u',
                '/介绍一下.{0,6}(站|网站|公司)/u',
            ],
            'recall'                 => 'site_overview',
            'alt_keywords'           => [],
            'tag_slugs'              => ['about', 'company', 'intro', 'product-community', 'site'],
            'doc_attrs'              => ['recommend', 'headline'],
            'include_site_meta'      => true,
            'include_plugin_catalog' => false,
            'use_site_name_alt'      => true,
            'fill_latest_docs'       => true,
            'prompt_hint'            => '用户可能在问「本站是做什么的」：请结合站点配置与代表性文章，用 2～4 段概括网站主题、产品或业务方向。',
        ];
    }

    /** @return array<string, mixed> */
    public static function pluginCatalog(): array
    {
        return [
            'id'                     => 'plugin_catalog',
            'label'                  => '插件目录',
            'enabled'                => true,
            'builtin'                => true,
            'patterns'               => [
                '/有哪些插件/u',
                '/有什么插件/u',
                '/都有哪些插件/u',
                '/支持哪些插件/u',
                '/插件有哪些/u',
                '/插件列表/u',
                '/装了哪些插件/u',
                '/安装了(什么|哪些)插件/u',
                '/(这个|本|该)系统.*插件/u',
                '/系统.*(有哪些|有什么|带哪些)插件/u',
                '/平台.*(有哪些|有什么)插件/u',
                '/插件.*(介绍|说明|概览|大全)/u',
                '/自带(哪些|什么)插件/u',
                '/内置(哪些|什么)插件/u',
            ],
            'recall'                 => 'plugin_catalog',
            'alt_keywords'           => ['插件', 'weapp'],
            'tag_slugs'              => ['plugin', 'weapp', 'extension'],
            'doc_attrs'              => [],
            'include_site_meta'      => false,
            'include_plugin_catalog' => true,
            'use_site_name_alt'      => false,
            'fill_latest_docs'       => false,
            'prompt_hint'            => '用户可能在问「系统有哪些插件」：请根据【已安装插件】列表，按功能分类简要介绍各插件用途；区分已启用与未启用；不要编造列表中不存在的插件名称。',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function indexedById(): array
    {
        $out = [];
        foreach (self::all() as $rule) {
            $out[(string) $rule['id']] = $rule;
        }

        return $out;
    }
}
