<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 列表/搜索/导出默认条数 SSOT（冗余审计 §5） */
final class QueryLimit
{
    /** 前台列表、站内搜索、智能搜索、分块检索默认每页 */
    public const FRONT_LIST = 12;

    /** 后台插件中心 DB 行上限（D-2.1） */
    public const PLUGIN_REGISTRY = 200;

    /** 活跃插件授权 map（EntitlementService::can · D-2.2） */
    public const PLUGIN_ENTITLEMENT_ACTIVE = 100;

    /** 后台授权/报表拉取上限（D-2.3） */
    public const PLUGIN_ENTITLEMENT_ADMIN = 200;

    /** 启用中的定时任务（D-2.5） */
    public const CRON_JOBS_ACTIVE = 200;

    /** 品项参数组元数据（D-2.7） */
    public const PRODUCT_PARAM_GROUPS = 100;

    /** 站点导航行（D-2.6） */
    public const SITE_NAV_ROWS = 200;

    /** 标签树/索引拉取（D-2.8） */
    public const TAG_TREE_ROWS = 5000;

    /** 后台「拉全表」listAdmin 上限（导出/下拉） */
    public const ADMIN_UNBOUNDED = 5000;

    /** 操作日志 CSV 导出分页拉取 */
    public const LOG_EXPORT_PAGE = 500;

    /** 向量 embedding 回填批大小 */
    public const EMBEDDING_BACKFILL = 40;

    /** 站内搜索辅助结果（标签/单页）条数 */
    public const SEARCH_AUX_HITS = 8;

    /** 小程序装修组件默认列表条数上限 */
    public const MINIPROGRAM_WIDGET_MAX = 30;

    /** sitemap / 企业资产列表单批拉取 */
    public const SITEMAP_BATCH = 200;

    /** 访问统计 Top N（小/中/大） */
    public const STATS_TOP_SMALL = 10;
    public const STATS_TOP_MEDIUM = 15;

    /** 搜索点击日志 Top */
    public const CLICK_LOG_TOP = 50;

    /** 表单导出最大行数 */
    public const FORM_EXPORT_MAX = 10000;

    /** SPA meta 最近文档下拉 */
    public const SPA_META_RECENT = 300;

    /** 迁移记录最近条数 */
    public const MIGRATION_RECENT = 200;

    /** Meilisearch 品项重索引批大小 */
    public const MEILI_REINDEX_BATCH = 500;

    /** 品项参数键 distinct 上限 */
    public const ITEM_PARAM_KEYS = 48;

    /** 后台列表默认每页 */
    public const ADMIN_PAGE_DEFAULT = 20;

    /** 后台紧凑列表（用户/角色/会员作者） */
    public const ADMIN_PAGE_COMPACT = 15;

    /** 导航标签/文档侧栏 */
    public const NAV_TAGS = 20;

    /** 公开标签/友链列表 */
    public const PUBLIC_CATALOG_LIST = 50;

    /** 相关内容/召回条数 */
    public const RELATED_ITEMS = 8;

    /** 相关文档条数 */
    public const RELATED_DOCS = 5;

    /** Meilisearch 关键词搜索 ID 上限 */
    public const MEILI_SEARCH_IDS = 24;

    /** 搜索索引队列 drain 批 */
    public const QUEUE_DRAIN_INDEX = 300;

    /** 领域事件队列 drain 批 */
    public const QUEUE_DRAIN_EVENT = 200;

    /** 事件日志 prune 单批 */
    public const EVENT_LOG_PRUNE_BATCH = 20000;

    /** 死链扫描 / 支付对账批 */
    public const RECONCILE_BATCH = 50;

    /** 批量替换预览页大小 */
    public const BULK_PREVIEW_PAGE_DEFAULT = 50;
    public const BULK_PREVIEW_PAGE_MAX = 200;
    public const BULK_PREVIEW_PAGE_MIN = 10;
}
