<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\support\AppTime;
use app\common\model\FormSubmission;
use app\common\model\SitePage;
use app\common\model\MediaAsset;
use app\common\model\Tag;
use app\common\model\User;
use app\common\model\Document;

use app\common\service\enterprise\EnterpriseResourceService;
use app\common\service\weapp\WeappPluginGateway;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\plugin\WeappContext;


use app\common\service\license\LicenseHeartbeatService;
use app\common\service\member\MemberService;
use app\common\model\Role;
use app\common\support\PivarkVueRoute;
use app\common\support\DbTable;
use think\facade\Cache;

/** 后台首页控制台数据 */
class AdminDashboardService
{
    /** 概况 COUNT / 趋势等重块；与 FrontCacheInvalidator::invalidateDocuments 同 key */
    public const CACHE_OVERVIEW = 'admin:dashboard:overview:v1';

    public const CACHE_DOC_TREND_PREFIX = 'admin:dashboard:doc_trend:v1:';

    public const CACHE_INQUIRY_TREND_PREFIX = 'admin:dashboard:inquiry_trend:v1:';

    public const CACHE_MEMBER_TREND_PREFIX = 'admin:dashboard:member_trend:v1:';

    public const CACHE_ACTIVITY_PREFIX = 'admin:dashboard:activity:v1:';

    private const AGGREGATE_CACHE_TTL = 120;

    public function __construct(
        private readonly AdminDashboardOpsDeps $ops,
        private readonly AdminDashboardPlatformDeps $platform,
    ) {
    }

    /** 清概况聚合缓存（文档变更 / refresh=1） */
    public function bustDashboardAggregateCaches(): void
    {
        Cache::delete(self::CACHE_OVERVIEW);
        Cache::delete(self::CACHE_DOC_TREND_PREFIX . '15');
        Cache::delete(self::CACHE_INQUIRY_TREND_PREFIX . '15');
        Cache::delete(self::CACHE_MEMBER_TREND_PREFIX . '15');
        Cache::delete(self::CACHE_ACTIVITY_PREFIX . '16');
    }

    /** SPA GET /dashboard — 控制台概览 payload */
    public function spaDashboard(int $userId, bool $refresh = false): array
    {
        if ($refresh) {
            $this->bustDashboardAggregateCaches();
        }
        $prefs = $this->platform->adminDashboardPreferenceService->get($userId);
        app(LicenseHeartbeatService::class)->maybeSendDeferred();

        return [
            'overview'        => $this->overviewStats($prefs['overviewKeys']),
            'overviewCatalog' => $this->overviewStatDefinitions(),
            'prefs'           => $prefs,
            'homeTemplateCatalog' => $this->homeTemplateCatalog(),
            'shortcutCatalog' => [
                'common' => $this->shortcutCatalogCommon(),
                'apps'   => $this->shortcutCatalogApps(),
            ],
            'shortcuts'       => [
                'common' => $this->resolveShortcuts($prefs['shortcutsCommon'], 'common'),
                'apps'   => $this->resolveShortcuts($prefs['shortcutsApps'], 'apps'),
            ],
            'quickLinks'      => $this->quickLinks(),
            'todos'           => $this->todoItems(),
            'docTrend'        => $this->documentTrend(15),
            'inquiryTrend'    => $this->inquiryTrend(15),
            'memberTrend'     => $this->memberTrend(15),
            'contentMix'      => $this->contentMixStats(),
            'siteActivity'    => $this->siteActivityFeed(16),
            'versionInfo'     => $this->versionInfo(),
            'systemInfo'      => $this->systemInfo(),
            'refreshedAt'     => AppTime::now(),
        ];
    }

    /**
     * SPA POST — 保存控制台展示偏好
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function spaSavePrefs(int $userId, array $body): array
    {
        $prefs = $this->platform->adminDashboardPreferenceService->normalize([
            'homeTemplate'       => $body['homeTemplate'] ?? null,
            'showWelcomeToolbar' => $body['showWelcomeToolbar'] ?? null,
            'overviewKeys'       => $body['overviewKeys'] ?? null,
            'shortcutsCommon' => $body['shortcutsCommon'] ?? null,
            'shortcutsApps'   => $body['shortcutsApps'] ?? null,
        ]);
        $this->platform->adminDashboardPreferenceService->save($userId, $prefs);

        return [
            'prefs'     => $prefs,
            'overview'  => $this->overviewStats($prefs['overviewKeys']),
            'shortcuts' => [
                'common' => $this->resolveShortcuts($prefs['shortcutsCommon'], 'common'),
                'apps'   => $this->resolveShortcuts($prefs['shortcutsApps'], 'apps'),
            ],
        ];
    }

    /**
     * @return list<array{key:string,title:string,unit:string,defaultVisible:bool}>
     */
    public function overviewStatDefinitions(): array
    {
        $core = [
            ['key' => 'documents', 'title' => '文档', 'unit' => '篇', 'defaultVisible' => true],
            ['key' => 'tags', 'title' => '栏目', 'unit' => '个', 'defaultVisible' => true],
            ['key' => 'members', 'title' => '会员', 'unit' => '人', 'defaultVisible' => true],
            ['key' => 'pages', 'title' => '单页', 'unit' => '页', 'defaultVisible' => true],
            ['key' => 'media', 'title' => '素材', 'unit' => '个', 'defaultVisible' => true],
            ['key' => 'inquiries', 'title' => '待处理咨询', 'unit' => '条', 'defaultVisible' => true],
            ['key' => 'today_docs', 'title' => '今日新增文档', 'unit' => '篇', 'defaultVisible' => true],
        ];
        foreach (app(PluginExtensionRegistry::class)->documentAddonDashboardOverviewStats() as $stat) {
            $core[] = [
                'key'            => (string) $stat['key'],
                'title'          => (string) $stat['title'],
                'unit'           => (string) $stat['unit'],
                'defaultVisible' => (bool) $stat['defaultVisible'],
            ];
        }

        return $core;
    }

    /**
     * @return array<string, true>
     */
    public function overviewKeySet(): array
    {
        $set = [];
        foreach ($this->overviewStatDefinitions() as $def) {
            $set[(string) $def['key']] = true;
        }

        return $set;
    }

    /**
     * @param list<string> $keys
     * @return list<array{key:string,title:string,value:int,unit:string}>
     */
    public function overviewStats(array $keys = []): array
    {
        $values = $this->overviewValueMap();
        $allowed = $keys !== [] ? array_flip($keys) : null;
        $out = [];

        foreach ($this->overviewStatDefinitions() as $def) {
            $key = (string) $def['key'];
            if ($allowed !== null && !isset($allowed[$key])) {
                continue;
            }
            $out[] = [
                'key'   => $key,
                'title' => (string) $def['title'],
                'value' => (int) ($values[$key] ?? 0),
                'unit'  => (string) $def['unit'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function overviewValueMap(): array
    {
        /** @var array<string, int> $values */
        $values = Cache::remember(self::CACHE_OVERVIEW, function (): array {
            $today = AppTime::today();
            $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);

            $memberCount = 0;
            if ($memberRoleId > 0) {
                $memberCount = (int) User::alias('u')
                    ->join('user_roles ur', 'ur.user_id = u.id')
                    ->where('ur.role_id', $memberRoleId)
                    ->count('DISTINCT u.id');
            }

            $map = [
                'documents'  => $this->countDocuments(),
                'tags'       => $this->countTags(),
                'members'    => $memberCount,
                'pages'      => $this->countSitePages(),
                'media'      => $this->countMedia(),
                'inquiries'  => $this->countPendingInquiries(),
                'today_docs' => $this->countDocumentsToday($today),
            ];
            foreach (app(PluginExtensionRegistry::class)->documentAddonDashboardOverviewStats() as $stat) {
                $map[(string) $stat['key']] = $this->countWeappPluginTable(
                    (string) $stat['identifier'],
                    (string) $stat['logical_table'],
                );
            }

            return $map;
        }, self::AGGREGATE_CACHE_TTL);

        return is_array($values) ? $values : [];
    }

    /**
     * @return list<array{id:string,title:string,route:string,vuePath:string,icon:string,color:string,group:string}>
     */
    public function shortcutCatalogCommon(): array
    {
        $links = [
            ['id' => 'doc_create', 'title' => '发布文档', 'route' => '/content/document/create', 'icon' => 'lucide:pen-line', 'color' => '#ff7a45'],
            ['id' => 'doc_index', 'title' => '文档管理', 'route' => '/content/document', 'icon' => 'lucide:files', 'color' => '#1e9fff'],
            ['id' => 'tag_index', 'title' => '栏目管理', 'route' => '/content/tag', 'icon' => 'lucide:folder-tree', 'color' => '#ffb800'],
            ['id' => 'member_index', 'title' => '会员管理', 'route' => '/member/list', 'icon' => 'lucide:users', 'color' => '#16baaa'],
            ['id' => 'seo_url', 'title' => 'SEO 配置', 'route' => '/seo/url', 'icon' => 'lucide:search', 'color' => '#a233c6'],
            ['id' => 'seo_static', 'title' => '静态生成', 'route' => '/seo/static', 'icon' => 'lucide:globe', 'color' => '#64748b'],
            ['id' => 'config_index', 'title' => '站点配置', 'route' => '/system/config', 'icon' => 'lucide:settings', 'color' => '#01aaed'],
            ['id' => 'media_index', 'title' => '素材库', 'route' => '/system/media', 'icon' => 'lucide:image', 'color' => '#5fb878'],
            ['id' => 'inquiry_index', 'title' => '咨询留言', 'route' => '/site/form/submissions/contact', 'icon' => 'lucide:message-square', 'color' => '#f97316'],
            ['id' => 'page_index', 'title' => '单页管理', 'route' => '/site/page', 'icon' => 'lucide:layout-template', 'color' => '#8b5cf6'],
            ['id' => 'nav_index', 'title' => '网站栏目', 'route' => '/site/nav', 'icon' => 'lucide:menu', 'color' => '#0ea5e9'],
            ['id' => 'plugin_mine', 'title' => '我的插件', 'route' => '/plugin/mine', 'icon' => 'lucide:puzzle', 'color' => '#22c55e'],
        ];

        return $this->decorateShortcutCatalog($links, 'common');
    }

    /**
     * @return list<array{id:string,title:string,route:string,vuePath:string,icon:string,color:string,group:string}>
     */
    public function shortcutCatalogApps(): array
    {
        $links = [];
        foreach ($this->platform->pluginService->listAdmin(true) as $row) {
            if (empty($row['installed']) || empty($row['enabled'])) {
                continue;
            }
            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            $adminRoute = trim((string) ($row['admin_route'] ?? ''));
            if ($adminRoute === '') {
                $adminRoute = '/weapp/host/' . $identifier . '/settings';
            }
            if (!str_starts_with($adminRoute, '/')) {
                $adminRoute = '/' . $adminRoute;
            }
            $title = (string) ($row['admin_title'] ?? $row['name'] ?? $identifier);
            $links[] = [
                'id'    => 'weapp_' . $identifier,
                'title' => $title,
                'route' => $adminRoute,
                'icon'  => $this->resolveShortcutIcon($row),
                'color' => (string) ($row['icon_color'] ?? '#5fb878'),
            ];
        }

        usort($links, static fn(array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $this->decorateShortcutCatalog($links, 'apps');
    }

    /**
     * @param array<string, mixed> $row listAdmin 行
     */
    private function resolveShortcutIcon(array $row): string
    {
        $icon = trim((string) ($row['icon'] ?? ''));
        if ($icon !== '' && str_starts_with($icon, 'lucide:')) {
            return $icon;
        }

        return 'lucide:box';
    }

    /**
     * @return array<string, true>
     */
    public function shortcutIdSet(string $group): array
    {
        $catalog = $group === 'apps'
            ? $this->shortcutCatalogApps()
            : $this->shortcutCatalogCommon();
        $set = [];
        foreach ($catalog as $item) {
            $set[(string) $item['id']] = true;
        }

        return $set;
    }

    /**
     * @param list<string> $ids
     * @param 'common'|'apps' $group
     * @return list<array{id:string,title:string,route:string,vuePath:string,icon:string,color:string,group:string}>
     */
    public function resolveShortcuts(array $ids, string $group): array
    {
        $catalog = $group === 'apps'
            ? $this->shortcutCatalogApps()
            : $this->shortcutCatalogCommon();
        $map = [];
        foreach ($catalog as $item) {
            $map[(string) $item['id']] = $item;
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $out[] = $map[$id];
            }
        }

        return $out;
    }

    /**
     * @return list<array{title:string,route:string,vuePath:string,icon:string,color:string}>
     */
    public function quickLinks(): array
    {
        $prefs = $this->platform->adminDashboardPreferenceService->defaults();
        $shortcuts = $this->resolveShortcuts($prefs['shortcutsCommon'], 'common');
        $legacy = [];
        foreach ($shortcuts as $item) {
            $legacy[] = [
                'title'   => $item['title'],
                'route'   => $item['route'],
                'vuePath' => $item['vuePath'],
                'icon'    => $item['icon'],
                'color'   => $item['color'],
            ];
        }

        return $legacy;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return list<array{id:string,title:string,route:string,vuePath:string,icon:string,color:string,group:string}>
     */
    private function decorateShortcutCatalog(array $links, string $group): array
    {
        $out = [];
        foreach ($links as $link) {
            $route = (string) ($link['route'] ?? '');
            $out[] = [
                'id'      => (string) ($link['id'] ?? ''),
                'title'   => (string) ($link['title'] ?? ''),
                'route'   => $route,
                'vuePath' => $this->dashboardVuePath($route),
                'icon'    => (string) ($link['icon'] ?? 'lucide:link'),
                'color'   => (string) ($link['color'] ?? '#1e9fff'),
                'group'   => $group,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{title:string,count:int,route:string,vuePath:string,level:string}>
     */
    public function todoItems(): array
    {
        $items         = [];
        $memberPending = $this->ops->documentService->countMemberPendingReview();

        foreach (
            [
                [$this->countPendingInquiries(), '咨询留言待处理', '/site/form/submissions/contact?status=0', 'warning'],
                [$memberPending, '会员投稿待审核', '/content/document?pending=1&member=1', 'warning'],
                [$this->countMembersToday(AppTime::today()), '今日新注册会员', '/member/list', 'success'],
                [$this->ops->memberOpsService->countInactiveMembers(), '未激活会员待处理', '/member/list?filter=inactive', 'warning'],
                [$this->ops->memberOpsService->countLevelExpiringSoon(7), '等级即将到期（7天内）', '/member/list?filter=level_expiring', 'info'],
                [$this->ops->memberOpsService->countPendingCancel(), '会员注销待审核', '/member/center/cancel', 'warning'],
            ] as [$count, $title, $route, $level]
        ) {
            $row = $this->todoItemIfPositive((int) $count, (string) $title, (string) $route, (string) $level);
            if ($row !== null) {
                $items[] = $row;
            }
        }

        $draftDocs = $this->ops->documentService->countDraftDocuments();
        if ($draftDocs > $memberPending) {
            $row = $this->todoItemIfPositive(
                $draftDocs,
                '草稿文档待发布',
                '/content/document?pending=1',
                'info'
            );
            if ($row !== null) {
                $items[] = $row;
            }
        }

        $pluginAlerts = $this->ops->pluginEntitlementReminderService->dashboardAlerts();
        if (($pluginAlerts['expired'] ?? 0) > 0) {
            $items[] = $this->todoItemRow(
                (int) $pluginAlerts['expired'],
                '插件授权已过期',
                '/plugin/cloud',
                'error'
            );
        } elseif (($pluginAlerts['expiring'] ?? 0) > 0) {
            $items[] = $this->todoItemRow(
                (int) $pluginAlerts['expiring'],
                '插件授权即将到期',
                '/plugin/cloud',
                'warning'
            );
        }

        if (app(EnterpriseResourceService::class)->isActive()) {
            $resourceStats = app(EnterpriseResourceService::class)->stats('');
            $expiringAssets = (int) ($resourceStats['expiring'] ?? 0);
            if ($expiringAssets > 0) {
                $items[] = $this->todoItemRow(
                    $expiringAssets,
                    '经营资料 30 天内到期',
                    '/system/enterprise-resource?filter=expiring',
                    'warning',
                );
            }

            $entityHealth = app(EnterpriseResourceService::class)->meta()['entity_health'] ?? [];
            $duplicateEntities = (int) ($entityHealth['duplicate_entities'] ?? 0);
            if ($duplicateEntities > 0) {
                $items[] = $this->todoItemRow(
                    $duplicateEntities,
                    '重复公司主体待清理',
                    '/system/enterprise-resource?open=entities',
                    'info',
                );
            }
        }

        return $items;
    }

    /**
     * @return array{title:string,count:int,route:string,vuePath:string,level:string}|null
     */
    private function todoItemIfPositive(int $count, string $title, string $route, string $level): ?array
    {
        if ($count <= 0) {
            return null;
        }

        return $this->todoItemRow($count, $title, $route, $level);
    }

    /**
     * @return array{title:string,count:int,route:string,vuePath:string,level:string}
     */
    private function todoItemRow(int $count, string $title, string $route, string $level): array
    {
        return [
            'title'   => $title,
            'count'   => $count,
            'route'   => $route,
            'vuePath' => $this->vuePath($route),
            'level'   => $level,
        ];
    }

    private function vuePath(string $href): string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        if (isset($query['status']) && (string) $query['status'] === '0') {
            unset($query['status']);
            $query['pending'] = '1';
        }
        $base = is_string($path) && $path !== '' ? $path : $href;
        $vuePath = $this->dashboardVuePath($base);
        if ($query !== []) {
            $vuePath .= '?' . http_build_query($query);
        }

        return $vuePath;
    }

    private function dashboardVuePath(string $route): string
    {
        $pathOnly = explode('?', $route, 2)[0];
        $resolved = app(AdminSpaRouteResolveService::class)->vuePathForAdminHref($pathOnly);
        if ($resolved !== '') {
            return str_starts_with($resolved, '/') ? $resolved : '/' . $resolved;
        }
        $legacy = PivarkVueRoute::spaPathFromLegacy($pathOnly);

        return $legacy !== null ? '/' . ltrim($legacy, '/') : $route;
    }

    /**
     * @return list<array{id:string,title:string,desc:string,tag:string}>
     */
    public function homeTemplateCatalog(): array
    {
        return [
            [
                'id'    => 'classic',
                'title' => '经典控制台',
                'desc'  => '原有布局：概况卡片、快捷入口、双折线图与待办侧栏',
                'tag'   => '默认',
            ],
            [
                'id'    => 'insight',
                'title' => '数据洞察',
                'desc'  => '柱/饼图组合、内容占比与网站动态时间线',
                'tag'   => '图表',
            ],
            [
                'id'    => 'aurora',
                'title' => '极光仪表盘',
                'desc'  => '单屏投屏大屏：顶栏KPI+左中右密铺，可全屏；框内跟系统主题，全屏为暗色科技风',
                'tag'   => '大屏',
            ],
        ];
    }

    /**
     * 内容结构占比（饼图）
     *
     * @return list<array{name:string,value:int}>
     */
    public function contentMixStats(): array
    {
        $map = $this->overviewValueMap();
        $out = [];
        foreach ($this->overviewStatDefinitions() as $def) {
            $key = (string) $def['key'];
            $value = (int) ($map[$key] ?? 0);
            if ($value > 0) {
                $out[] = ['name' => (string) $def['title'], 'value' => $value];
            }
        }

        return $out;
    }

    /**
     * 最近网站动态（文档 / 咨询 / 会员）
     *
     * @return list<array{type:string,title:string,time:string,vuePath:string,badge:string}>
     */
    public function siteActivityFeed(int $limit = 16): array
    {
        $limit = max(4, min(30, $limit));
        $key = self::CACHE_ACTIVITY_PREFIX . $limit;
        /** @var list<array{type:string,title:string,time:string,vuePath:string,badge:string}> $cached */
        $cached = Cache::remember($key, function () use ($limit): array {
            return $this->computeSiteActivityFeed($limit);
        }, self::AGGREGATE_CACHE_TTL);

        return is_array($cached) ? $cached : [];
    }

    /**
     * @return list<array{type:string,title:string,time:string,vuePath:string,badge:string}>
     */
    private function computeSiteActivityFeed(int $limit): array
    {
        $items = [];

        $docs = Document::whereNull('deleted_at')
            ->order('created_at', 'desc')
            ->limit($limit)
            ->field('id,title,created_at')
            ->select()
            ->toArray();
        foreach ($docs as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $items[] = [
                'type'    => 'document',
                'title'   => $this->sanitizeDashboardText((string) ($row['title'] ?? ''), 64),
                'time'    => (string) ($row['created_at'] ?? ''),
                'vuePath' => '/content/document/edit/' . $id,
                'badge'   => '文档',
            ];
        }

        $formId = $this->ops->siteFormService->findIdBySlug('contact');
        if ($formId > 0 && DbTable::modelExists(FormSubmission::class)) {
            $inquiries = FormSubmission::where('form_id', $formId)
                ->order('created_at', 'desc')
                ->limit($limit)
                ->field('id,payload_json,created_at')
                ->select()
                ->toArray();
            foreach ($inquiries as $row) {
                $id = (int) ($row['id'] ?? 0);
                $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                $name = trim((string) ($payload['name'] ?? ''));
                $company = trim((string) ($payload['company'] ?? ''));
                $message = trim((string) ($payload['message'] ?? ''));
                if ($name !== '' && $company !== '') {
                    $title = $name . ' · ' . $company;
                } elseif ($name !== '') {
                    $title = $name;
                } elseif ($company !== '') {
                    $title = $company;
                } elseif ($message !== '') {
                    $title = mb_strlen($message) > 40
                        ? (mb_substr($message, 0, 40) . '…')
                        : $message;
                } else {
                    $title = '新咨询 #' . $id;
                }
                $items[] = [
                    'type'    => 'inquiry',
                    'title'   => $this->sanitizeDashboardText($title, 64),
                    'time'    => (string) ($row['created_at'] ?? ''),
                    'vuePath' => '/site/form/submissions/contact?pending=1',
                    'badge'   => '咨询',
                ];
            }
        }

        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId > 0) {
            $members = User::alias('u')
                ->join('user_roles ur', 'ur.user_id = u.id')
                ->where('ur.role_id', $memberRoleId)
                ->order('u.created_at', 'desc')
                ->limit($limit)
                ->field('u.id,u.username,u.nickname,u.created_at')
                ->select()
                ->toArray();
            foreach ($members as $row) {
                $name = trim((string) ($row['nickname'] ?? ''));
                if ($name === '') {
                    $name = (string) ($row['username'] ?? '新会员');
                }
                $items[] = [
                    'type'    => 'member',
                    'title'   => $this->sanitizeDashboardText($name, 64),
                    'time'    => (string) ($row['created_at'] ?? ''),
                    'vuePath' => '/member/list',
                    'badge'   => '会员',
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $b['time'], (string) $a['time']);
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * 会员注册趋势（柱状图）
     *
     * @return array{labels:list<string>,values:list<int>}
     */
    public function memberTrend(int $days = 15): array
    {
        $days = max(1, min(60, $days));
        $key = self::CACHE_MEMBER_TREND_PREFIX . $days;
        /** @var array{labels:list<string>,values:list<int>} $cached */
        $cached = Cache::remember($key, function () use ($days): array {
            return $this->computeMemberTrend($days);
        }, self::AGGREGATE_CACHE_TTL);

        return is_array($cached) ? $cached : ['labels' => [], 'values' => []];
    }

    /**
     * @return array{labels:list<string>,values:list<int>}
     */
    private function computeMemberTrend(int $days): array
    {
        $series = $this->emptyDaySeries($days);
        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return [
                'labels' => $series['labels'],
                'values' => array_values($series['map']),
            ];
        }

        $start = $series['keys'][0] . ' 00:00:00';
        $rows = User::alias('u')
            ->join('user_roles ur', 'ur.user_id = u.id')
            ->where('ur.role_id', $memberRoleId)
            ->where('u.created_at', '>=', $start)
            ->field('DATE(u.created_at) AS day_key, COUNT(DISTINCT u.id) AS total')
            ->group('day_key')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $key = (string) ($row['day_key'] ?? '');
            if ($key !== '' && array_key_exists($key, $series['map'])) {
                $series['map'][$key] = (int) ($row['total'] ?? 0);
            }
        }

        return [
            'labels' => $series['labels'],
            'values' => array_values($series['map']),
        ];
    }

    /**
     * @return array{labels:list<string>,values:list<int>}
     */
    public function documentTrend(int $days = 15): array
    {
        $days = max(1, min(60, $days));
        $key = self::CACHE_DOC_TREND_PREFIX . $days;
        /** @var array{labels:list<string>,values:list<int>} $cached */
        $cached = Cache::remember($key, function () use ($days): array {
            return $this->computeDocumentTrend($days);
        }, self::AGGREGATE_CACHE_TTL);

        return is_array($cached) ? $cached : ['labels' => [], 'values' => []];
    }

    /**
     * @return array{labels:list<string>,values:list<int>}
     */
    private function computeDocumentTrend(int $days): array
    {
        $series = $this->emptyDaySeries($days);
        $start = $series['keys'][0] . ' 00:00:00';

        $rows = Document::whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->field('DATE(created_at) AS day_key, COUNT(*) AS total')
            ->group('day_key')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $dayKey = (string) ($row['day_key'] ?? '');
            if ($dayKey !== '' && array_key_exists($dayKey, $series['map'])) {
                $series['map'][$dayKey] = (int) ($row['total'] ?? 0);
            }
        }

        return [
            'labels' => $series['labels'],
            'values' => array_values($series['map']),
        ];
    }

    /**
     * 前台咨询提交量（暂无独立访客统计时的替代指标）
     *
     * @return array{labels:list<string>,values:list<int>}
     */
    public function inquiryTrend(int $days = 15): array
    {
        $days = max(1, min(60, $days));
        $key = self::CACHE_INQUIRY_TREND_PREFIX . $days;
        /** @var array{labels:list<string>,values:list<int>} $cached */
        $cached = Cache::remember($key, function () use ($days): array {
            return $this->computeInquiryTrend($days);
        }, self::AGGREGATE_CACHE_TTL);

        return is_array($cached) ? $cached : ['labels' => [], 'values' => []];
    }

    /**
     * @return array{labels:list<string>,values:list<int>}
     */
    private function computeInquiryTrend(int $days): array
    {
        $series = $this->emptyDaySeries($days);
        $start = $series['keys'][0] . ' 00:00:00';

        $formId = $this->ops->siteFormService->findIdBySlug('contact');
        if ($formId > 0 && DbTable::modelExists(FormSubmission::class)) {
            $rows = FormSubmission::where('form_id', $formId)
                ->where('created_at', '>=', $start)
                ->field('DATE(created_at) AS day_key, COUNT(*) AS total')
                ->group('day_key')
                ->select()
                ->toArray();
        } else {
            return ['labels' => $series['labels'], 'values' => array_values($series['map'])];
        }

        foreach ($rows as $row) {
            $dayKey = (string) ($row['day_key'] ?? '');
            if ($dayKey !== '' && array_key_exists($dayKey, $series['map'])) {
                $series['map'][$dayKey] = (int) ($row['total'] ?? 0);
            }
        }

        return [
            'labels' => $series['labels'],
            'values' => array_values($series['map']),
        ];
    }

    /**
     * @return array{
     *   version:string,
     *   site_name:string,
     *   copyright:string,
     *   update_status:string,
     *   php_version:string,
     *   has_core_update:bool,
     *   latest_version:string,
     *   changelog_url:string,
     *   download_url:string
     * }
     */
    public function versionInfo(): array
    {
        $version = $this->platform->coreUpdateRemoteService->currentVersion();

        $core = $this->platform->coreUpdateRemoteService->check();

        if ($this->platform->pivarkEditionService->isDev()) {
            $core['has_update'] = false;
        }

        if ($core['has_update']) {
            $updateStatus = '发现新版本 ' . ($core['latest'] ?? '') . '（当前 ' . $version . '）';
        } elseif ($core['ok']) {
            $updateStatus = '已是最新版';
        } else {
            $updateStatus = '未配置远程版本清单';
        }

        $brand = app(\app\common\service\site\SiteBrandService::class);

        return [
            'version'                   => 'v' . $version,
            'site_name'                 => $this->sanitizeDashboardSiteName(
                (string) $this->platform->configService->get('site_name', '元舟 PivArk'),
            ),
            /** 产品方版权（非 configs.site_copyright 客户页脚） */
            'copyright'                 => $brand->productCopyrightText(),
            'product_license'           => $brand->productLicenseLabel(),
            'official_site_url'         => $brand->officialSiteUrl(),
            'gitee_repo_url'            => $brand->giteeRepoUrl(),
            'github_repo_url'           => $brand->githubRepoUrl(),
            'update_status'             => $updateStatus,
            'php_version'               => PHP_VERSION,
            'has_core_update'           => !empty($core['has_update']),
            'can_apply_core_update'     => !empty($core['can_apply']),
            'notify_only_core_update'   => !empty($core['notify_only']),
            'upgrade_license_required'  => !empty($core['upgrade_license_required']),
            'latest_version'            => (string) ($core['latest'] ?? $version),
            'changelog_url'             => (string) ($core['changelog_url'] ?? ''),
            'download_url'              => (string) ($core['download_url'] ?? ''),
            'opensource_repo_url'       => (string) ($core['opensource_repo_url'] ?? $brand->giteeRepoUrl()),
            'upgrade_notice'            => (string) ($core['upgrade_notice'] ?? ''),
        ];
    }

    /**
     * 管理首页「系统信息」（可靠字段；无安装时间戳则不做假运行天数）
     *
     * @return array{
     *   version:string,
     *   php_version:string,
     *   server_software:string,
     *   database:string,
     *   login_ip:string
     * }
     */
    public function systemInfo(): array
    {
        $version = $this->platform->coreUpdateRemoteService->currentVersion();

        return [
            'version'          => 'v' . $version,
            'php_version'      => PHP_VERSION,
            'server_software'  => $this->sanitizeDashboardText(
                (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
                80,
            ) ?: '—',
            'database'         => $this->detectDatabaseLabel(),
            'login_ip'         => $this->sanitizeDashboardText(
                (string) (\think\facade\Request::ip() ?: ''),
                64,
            ) ?: '—',
        ];
    }

    private function detectDatabaseLabel(): string
    {
        try {
            $rows = \think\facade\Db::query('SELECT VERSION() AS v');
            $ver  = is_array($rows) && isset($rows[0]['v']) ? trim((string) $rows[0]['v']) : '';
            if ($ver !== '') {
                return 'MySQL ' . $this->sanitizeDashboardText($ver, 40);
            }
        } catch (\Throwable) {
            // ignore — 表未就绪/无权限时仍给驱动标签
        }

        return 'MySQL';
    }

    /**
     * @return array{labels:list<string>,keys:list<string>,map:array<string,int>}
     */
    public function emptyDaySeries(int $days): array
    {
        $days = max(1, min(60, $days));
        $labels = [];
        $keys = [];
        $map = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $key = AppTime::format('Y-m-d', strtotime('-' . $i . ' days'));
            $keys[] = $key;
            $labels[] = AppTime::format('m-d', strtotime($key));
            $map[$key] = 0;
        }

        return ['labels' => $labels, 'keys' => $keys, 'map' => $map];
    }

    private function sanitizeDashboardSiteName(string $raw): string
    {
        return app(\app\common\service\site\SiteBrandService::class)->normalizeStoredSiteName($raw);
    }

    private function sanitizeDashboardText(string $raw, int $maxLen = 80): string
    {
        $text = trim(strip_tags($raw));
        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > $maxLen ? (mb_substr($text, 0, $maxLen) . '…') : $text;
    }

    private function countDocuments(): int
    {
        return (int) Document::whereNull('deleted_at')->where('status', 1)->count();
    }

    private function countDraftDocuments(): int
    {
        return $this->ops->documentService->countDraftDocuments();
    }

    private function countDocumentsToday(string $day): int
    {
        return (int) Document::whereNull('deleted_at')
            ->where('created_at', '>=', $day . ' 00:00:00')
            ->where('created_at', '<=', $day . ' 23:59:59')
            ->count();
    }

    private function countTags(): int
    {
        return (int) Tag::where('status', 1)->count();
    }

    private function countWeappPluginTable(string $pluginId, string $logicalTable): int
    {
        if (!app(EntitlementService::class)->can($pluginId)) {
            return 0;
        }
        app(WeappPluginGateway::class)->pluginRegisterAutoloadPublic($pluginId);
        try {
            return (int) app(WeappContext::class)->db($pluginId, $logicalTable)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countSitePages(): int
    {
        return (int) SitePage::where('status', 1)->count();
    }

    private function countMedia(): int
    {
        return (int) MediaAsset::count();
    }

    private function countPendingInquiries(): int
    {
        return $this->ops->siteFormService->countPendingForSlug('contact');
    }

    private function countMembersToday(string $day): int
    {
        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return 0;
        }

        return (int) User::alias('u')
            ->join('user_roles ur', 'ur.user_id = u.id')
            ->where('ur.role_id', $memberRoleId)
            ->where('u.created_at', '>=', $day . ' 00:00:00')
            ->where('u.created_at', '<=', $day . ' 23:59:59')
            ->count('DISTINCT u.id');
    }
}
