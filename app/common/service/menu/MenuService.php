<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\service\menu;

use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\admin\AdminPluginSidebarRegistry;
use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use app\common\support\ServiceResult;

use app\common\service\admin\AdminNavPersonaService;
use app\common\service\admin\AdminNavProfileService;
use app\common\service\admin\AdminPortalService;
use app\common\service\admin\AdminSpaMenuRouteCacheService;
use app\common\service\channel\MiniprogramChannelService;
use app\common\service\member\MemberConfigService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\site\SiteCoreLicenseService;
use app\common\service\user\PermissionService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\model\Menu;
use app\common\support\SiteUrl;
use think\facade\Session;

/** 后台菜单 */
class MenuService
{

    /** @var array<string, string|null> */
    private static array $weappDirSlugMemo = [];

    public function __construct(
        private readonly AdminPortalService $adminPortalService,
        private readonly PermissionService $permissionService,
        private readonly PluginService $pluginService,
        private readonly MiniprogramChannelService $miniprogramChannelService,
    ) {
    }

    /** 不在侧栏展示、禁止后台 UI 改动的维护项（SSOT：migrate_*.php） */
    private const MAINTENANCE_HIDDEN_ROUTES = [
        '/admin/menu/index',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function getAll()
    {
        return Menu::getAllSorted();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTree(): array
    {
        $menus = array_merge(Menu::getAllSorted(), $this->dynamicMenus());
        $list  = [];
        $admin = Session::get('admin_user', []);
        $userId = (int) ($admin['id'] ?? 0);
        $isSuperAdmin = $userId > 0 && (
            !empty($admin['is_super'])
            || in_array('super_admin', is_array($admin['role_codes'] ?? null) ? $admin['role_codes'] : [], true)
        );
        foreach ($menus as $m) {
            if ((int) ($m['status'] ?? 1) !== 1) {
                continue;
            }
            if (!$this->adminPortalService->menuVisible($m)) {
                continue;
            }
            if (empty($m['kernel_shortcut']) && $this->isHiddenPluginAdminMenu((string) ($m['route'] ?? ''))) {
                continue;
            }
            if ($this->isHiddenMaintenanceMenu((string) ($m['route'] ?? ''))) {
                continue;
            }
            if ($this->isHiddenMiniprogramMenu($m)) {
                continue;
            }
            if ($this->isHiddenUnentitledPluginMenu($m)) {
                continue;
            }
            if ($this->isHiddenProductCenterMenu($m)) {
                continue;
            }
            if ($this->isHiddenMemberCenterMenu($m)) {
                continue;
            }
            if (empty($m['icon'])) {
                $m['icon'] = 'fa fa-circle-o';
            }
            $code = $m['permission_code'] ?? '';
            if (
                !$isSuperAdmin
                && $code !== ''
                && $userId > 0
                && !$this->permissionService->can($userId, (string) $code)
            ) {
                continue;
            }
            $list[] = $m;
        }
        $list = app(AdminNavPersonaService::class)->filterFlatMenus($list, $userId);
        $tree = $this->buildTree($list, 0);
        $tree = app(AdminNavProfileService::class)->applyToMenuTree($tree);

        return app(AdminNavPersonaService::class)->filterMenuTree($tree, $userId);
    }

    /**
     * 侧栏默认首页（禁用工作台后取第一个可用菜单）
     *
     * @return array{title:string,href:string}
     */
    public function defaultHomeInfo(): array
    {
        return [
            'title' => '管理首页',
            'href'  => SiteUrl::adminSpa(),
        ];
    }

    /**
     * L1/L2 点亮后在 Core 侧栏展示的插件快捷入口（非插件首页）
     *
     * @return list<array<string, mixed>>
     */
    public function dynamicMenus(): array
    {
        $out = [];

        foreach (app(AdminMenuRegistry::class)->collect() as $pluginMenu) {
            $out[] = $pluginMenu;
        }

        foreach (app(AdminNavProfileService::class)->enterpriseDynamicMenus() as $enterpriseMenu) {
            $out[] = $enterpriseMenu;
        }

        foreach (app(AdminNavProfileService::class)->marketCommerceDynamicMenus() as $marketMenu) {
            $out[] = $marketMenu;
        }

        return $out;
    }

    /**
     * 插件子功能快捷入口：插件启用时可在「内容与发布」等 Core 分组展示
     */
    public function isPluginSidebarShortcut(string $route): bool
    {
        return app(AdminPluginSidebarRegistry::class)->isShortcutVisible(trim($route));
    }

    /**
     * 后台 admin 路由 slug（如 social_auth）→ weapp 目录名（如 social-auth）
     */
    public function resolveWeappDirForAdminSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        if (array_key_exists($slug, self::$weappDirSlugMemo)) {
            return self::$weappDirSlugMemo[$slug];
        }
        foreach (array_unique([$slug, str_replace('_', '-', $slug), str_replace('-', '_', $slug)]) as $candidate) {
            if ($candidate !== '' && is_dir(ROOT_PATH . 'weapp/' . $candidate)) {
                self::$weappDirSlugMemo[$slug] = $candidate;

                return $candidate;
            }
        }
        self::$weappDirSlugMemo[$slug] = null;

        return null;
    }

    /**
     * weapp 插件后台入口统一从「插件中心 → 我的插件」进入，不在 Core 侧栏重复展示
     */
    public function isHiddenPluginAdminMenu(string $route): bool
    {
        $route = trim($route);
        if ($route === '') {
            return false;
        }
        if ($this->isPluginSidebarShortcut($route)) {
            return false;
        }

        if (preg_match('#^/weapp/host/([a-z0-9_-]+)(/|$)#', $route, $m)) {
            return $this->resolveWeappDirForAdminSlug($m[1]) !== null;
        }

        if (preg_match('#^/admin/weapp/([a-z0-9_-]+)(/|$)#', $route, $m)) {
            return $this->resolveWeappDirForAdminSlug($m[1]) !== null;
        }

        if (preg_match('#^/admin/([a-z0-9_-]+)/#', $route, $m)) {
            $id = $m[1];
            if (PluginDistributionPolicy::isHostOnly($id)) {
                return false;
            }
            if ($this->resolveWeappDirForAdminSlug($id) === null) {
                return false;
            }
            // 插件应用列表本身保留
            if ($id === 'plugin') {
                return false;
            }
            if ($this->pluginService->isKernelBuiltin($id)) {
                return false;
            }
            if ($this->pluginService->isCoreMerged($id)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /** 菜单维护页等：仅迁移脚本维护，不对运营侧栏暴露 */
    public function isHiddenMaintenanceMenu(string $route): bool
    {
        $route = strtolower(trim($route));
        if ($route === '') {
            return false;
        }
        if (in_array($route, self::MAINTENANCE_HIDDEN_ROUTES, true)) {
            return true;
        }
        return in_array($route, app(AdminSpaExplicitRouteRegistry::class)->maintenanceHiddenRoutes(), true);
    }

    /**
     * 小程序一级分组：未购买、未授权或未安装启用 mp-wechat 时不展示
     *
     * @param array<string, mixed> $menu
     */
    public function isHiddenMiniprogramMenu(array $menu): bool
    {
        if ($this->shouldShowMiniprogramAdminMenus()) {
            return false;
        }

        $route = strtolower(trim((string) ($menu['route'] ?? '')));
        $title = trim((string) ($menu['title'] ?? ''));
        if ((int) ($menu['parent_id'] ?? 0) === 0 && $title === '小程序') {
            return true;
        }

        return $this->isMiniprogramAdminRoute($route);
    }

    /**
     * 未授权插件：侧栏不展示 plugin.{id}.* 菜单（CC-3）
     *
     * @param array<string, mixed> $menu
     */
    public function isHiddenUnentitledPluginMenu(array $menu): bool
    {
        $slug = $this->resolveEntitlementSlugForMenu($menu);
        if ($slug === null) {
            return false;
        }
        if ($this->pluginService->isKernelBuiltin($slug)) {
            return false;
        }
        if ($this->pluginService->isCoreMerged($slug)) {
            return false;
        }

        return !app(EntitlementService::class)->can($slug);
    }

    /**
     * 产品中心侧栏：专业版+ 显示；从未开通过则隐藏；
     * 曾开通后到期/降档保留入口，由 ProductCenterShell / API 给续费空态。
     *
     * @param array<string, mixed> $menu
     */
    public function isHiddenProductCenterMenu(array $menu): bool
    {
        if (app(ProductCenterGateService::class)->allowsAdmin()) {
            return false;
        }
        if (app(SiteCoreLicenseService::class)->showProductCenterDegradedEntry()) {
            return false;
        }

        $route = strtolower(trim((string) ($menu['route'] ?? '')));
        $title = trim((string) ($menu['title'] ?? ''));

        if ($title === '产品中心' || $title === '品项列表') {
            return true;
        }

        if (!empty($menu['product_center'])) {
            return true;
        }

        foreach ([
            '/product/item',
            '/product/params',
            '/product/settings',
            '/admin/product/index',
            '/admin/product/params',
            '/admin/item/index',
        ] as $exact) {
            if ($route === strtolower($exact)) {
                return true;
            }
        }

        foreach (['/product/shop', '/admin/product/shop'] as $prefix) {
            if ($prefix !== '' && str_starts_with($route, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 会员中心侧栏：站点关闭会员功能后整组隐藏（重开入口在系统设置 → 模块开关）。
     *
     * @param array<string, mixed> $menu
     */
    public function isHiddenMemberCenterMenu(array $menu): bool
    {
        if (app(MemberConfigService::class)->isCenterOpen()) {
            return false;
        }

        $route = strtolower(trim((string) ($menu['route'] ?? '')));
        $title = trim((string) ($menu['title'] ?? ''));

        if ($title === '会员中心' || $title === '会员列表' || $title === '会员级别') {
            return true;
        }

        if (!empty($menu['member_center'])) {
            return true;
        }

        if ($route !== '' && (str_starts_with($route, '/member/') || $route === '/member')) {
            return true;
        }

        foreach ([
            '/admin/member/index',
            '/admin/member/level',
            '/admin/member/center',
        ] as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function resolveEntitlementSlugForMenu(array $menu): ?string
    {
        $code = trim((string) ($menu['permission_code'] ?? ''));
        if ($code !== '' && preg_match('/^plugin\.([a-z0-9_-]+)\.(use|manage)$/', $code, $m)) {
            return $this->normalizePluginSlug($m[1]);
        }

        $route = trim((string) ($menu['route'] ?? ''));
        if ($route === '') {
            return null;
        }
        if (preg_match('#^/weapp/host/([a-z0-9_-]+)(/|$)#', $route, $m)) {
            $dir = $this->resolveWeappDirForAdminSlug($m[1]);

            return $dir ?? $this->normalizePluginSlug($m[1]);
        }
        if (preg_match('#^/admin/weapp/([a-z0-9_-]+)(/|$)#', $route, $m)) {
            $dir = $this->resolveWeappDirForAdminSlug($m[1]);

            return $dir ?? $this->normalizePluginSlug($m[1]);
        }
        if (preg_match('#^/admin/([a-z0-9_-]+)/#', $route, $m)) {
            if (PluginDistributionPolicy::isHostOnly($m[1])) {
                return null;
            }
            $dir = $this->resolveWeappDirForAdminSlug($m[1]);
            if ($dir === null) {
                return null;
            }
            if ($m[1] === 'plugin') {
                return null;
            }

            return $dir;
        }

        return null;
    }

    private function normalizePluginSlug(string $slug): string
    {
        $slug = strtolower(trim(str_replace('_', '-', $slug)));

        return $slug;
    }

    public function shouldShowMiniprogramAdminMenus(): bool
    {
        return $this->miniprogramChannelService->isLicensed()
            && $this->miniprogramChannelService->hubInstalledAndEnabled();
    }

    public function isMiniprogramAdminRoute(string $route): bool
    {
        $route = strtolower(trim($route));
        if ($route === '') {
            return false;
        }

        if (str_starts_with($route, '/admin/miniprogramconfig/')) {
            return true;
        }

        $hub = app(\app\common\service\channel\MiniprogramChannelRegistry::class)->hubPlugin();

        return str_starts_with($route, '/admin/weapp/' . $hub . '/')
            || str_starts_with($route, '/admin/weapp/' . str_replace('-', '_', $hub) . '/');
    }

    /**
     * 后台菜单 CRUD 已关闭：改库易致侧栏缺失，统一走迁移脚本。
     *
     * @return ServiceResult
     */
    private function menuMutationBlocked(): ServiceResult
    {
        return ServiceResult::fail('系统菜单由数据库迁移脚本维护，已禁止在后台新增/修改/删除。如需调整请运行 migrations/ 下 migrate 脚本或联系运维。');
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function create(array $data): ServiceResult
    {
        $blocked = $this->menuMutationBlocked();
        if (!$blocked->isOk()) {
            return $blocked;
        }
        $id = Menu::createMenu($data);
        app(AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(['id' => $id], '创建成功');
    }

    /**
     * @param int                $id
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function update(int $id, array $data): ServiceResult
    {
        $blocked = $this->menuMutationBlocked();
        if (!$blocked->isOk()) {
            return $blocked;
        }
        Menu::updateMenu($id, $data);
        app(AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(null, '更新成功');
    }

    /**
     * @param int $id
     * @return ServiceResult
     */
    public function delete(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $blocked = $this->menuMutationBlocked();
        if (!$blocked->isOk()) {
            return $blocked;
        }
        Menu::deleteWithChildren($id);
        app(AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $sort
     */
    public function updateSort(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $blocked = $this->menuMutationBlocked();
        if (!$blocked->isOk()) {
            return $blocked;
        }
        if (!Menu::find($id)) {
            return ServiceResult::fail('菜单不存在');
        }
        Menu::where('id', $id)->update(['sort' => $sort]);
        app(AdminSpaMenuRouteCacheService::class)->bustAll();

        return ServiceResult::ok(null, '已更新');
    }

    /**
     * @param int|string $selected
     * @param int        $parentId
     * @param string     $prefix
     * @return string HTML option 片段
     */
    public function getOptions($selected = 0, $parentId = 0, $prefix = '')
    {
        $html = '';
        $items = Menu::getByParent($parentId);
        foreach ($items as $item) {
            $sel = (int)$item['id'] === (int)$selected ? 'selected' : '';
            $html .= "<option value=\"{$item['id']}\" $sel>{$prefix}{$item['title']}</option>";
            $html .= $this->getOptions($selected, (int)$item['id'], $prefix . '&nbsp;&nbsp;');
        }
        return $html;
    }

    public function findForForm(int $id): ?Menu
    {
        if ($id < 1) {
            return null;
        }
        $menu = Menu::find($id);

        return $menu instanceof Menu ? $menu : null;
    }

    private function buildTree(array $items, int $parentId): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $children = $this->buildTree($items, (int)$item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $tree[] = $item;
            }
        }
        usort($tree, static function (array $a, array $b): int {
            $sort = ((int) ($a['sort'] ?? 0)) <=> ((int) ($b['sort'] ?? 0));
            if ($sort !== 0) {
                return $sort;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $tree;
    }

    /**
     * 动态菜单锚点（插件侧栏挂接产品中心等）
     *
     * @return array{parent_id:int,sort:int}
     */
    public function anchorByRoute(string $route): array
    {
        return $this->menuAnchorByRoute($route);
    }

    public function menuNodeExists(int $menuId): bool
    {
        if ($menuId < 1) {
            return false;
        }

        foreach (Menu::getAllSorted() as $row) {
            if ((int) ($row['id'] ?? 0) === $menuId && (int) ($row['status'] ?? 1) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{parent_id:int,sort:int}
     */
    private function menuAnchorByRoute(string $route): array
    {
        foreach (Menu::getAllSorted() as $row) {
            if ((string) ($row['route'] ?? '') === $route) {
                return [
                    'parent_id' => (int) ($row['parent_id'] ?? 0),
                    'sort'      => (int) ($row['sort'] ?? 0),
                ];
            }
        }

        return ['parent_id' => 0, 'sort' => 0];
    }
}
