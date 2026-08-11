<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappAdminGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\export\AdminDataExportSupport;
use app\common\service\admin\AdminTagScopeService;
use app\common\service\product\ProductCenterGateService;
use app\common\service\product\ProductL1Access;
use app\common\service\plugin\extension\PluginOfferBridgeRegistry;
use app\common\support\ServiceResult;
use think\Response;
use app\common\service\admin\WeappAdminSpaHostRoutes;
use app\common\service\admin\AdminPermissionExtensionRegistry;
use app\common\service\admin\AdminPluginRouteRegistry;
use app\common\service\admin\AdminRetiredApiPathRegistry;
use app\common\service\admin\AdminSensitiveConfirmRouteRegistry;
use app\common\service\admin\AdminSpaExplicitRouteRegistry;
use app\common\service\admin\AdminSpaMetaRegistry;
use app\common\service\admin\AdminSpaPluginListRegistry;
use app\common\service\admin\WeappAdminNavRegistry;
use app\common\model\AuditLog;
use app\common\service\audit\AuditLogService;
use app\common\service\commerce\CommerceProductModeService;
use app\common\service\menu\MenuService;
use app\common\service\site\SiteSlideService;
use app\common\service\menu\AdminMenuRegistry;
use app\common\service\infra\AdminAsyncExportService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\plugin\WeappContext;
use app\common\support\PivarkVueRoute;
use app\common\support\SiteUrl;
use think\facade\Session;

final class WeappAdminGateway
{

    public function __construct(
        private readonly AdminTagScopeService $adminTagScope,
        private readonly AdminAsyncExportService $adminAsyncExport,
        private readonly AdminSpaMetaRegistry $adminSpaMeta,
        private readonly AdminSpaPluginListRegistry $adminSpaPluginList,
        private readonly AdminMenuRegistry $adminMenu,
        private readonly AdminPluginRouteRegistry $adminPluginRoute,
        private readonly AdminSensitiveConfirmRouteRegistry $adminSensitiveConfirmRoute,
        private readonly AdminRetiredApiPathRegistry $adminRetiredApiPath,
        private readonly AdminSpaExplicitRouteRegistry $adminSpaExplicitRoute,
        private readonly WeappAdminNavRegistry $weappAdminNav,
        private readonly AdminPermissionExtensionRegistry $adminPermissionExtension,
        private readonly PluginOfferBridgeRegistry $pluginOfferBridge,
        private readonly PluginService $pluginService,
        private readonly ProductCenterGateService $productCenterGate,
        private readonly EntitlementService $entitlement,
        private readonly WeappContext $weappContext,
        private readonly AdminDataExportSupport $adminDataExport,
        private readonly AuditLogService $auditLog,
        private readonly MenuService $menu,
        private readonly SiteSlideService $siteSlide,
    ) {
    }

    /**
     * @param list<int> $tagIds
     * @return list<int>
     */
    public function adminTagScopeExpandWithDescendants(array $tagIds): array
    {
        return $this->adminTagScope->expandWithDescendants($tagIds);
    }

    /** @param list<string> $headers @param callable(int,int):list<list<string>> $fetchChunk */
    public function adminAsyncExportStart(string $kind, string $basename, array $headers, callable $fetchChunk): ServiceResult
    {
        return $this->adminAsyncExport->start($kind, $basename, $headers, $fetchChunk);
    }

    /** @param callable(int,int):list<list<string>> $fetchChunk */
    public function adminAsyncExportStep(string $jobId, callable $fetchChunk): ServiceResult
    {
        return $this->adminAsyncExport->step($jobId, $fetchChunk);
    }

    /** @return array<string, mixed>|null */
    public function adminAsyncExportConsumeDownload(string $jobId): ?array
    {
        return $this->adminAsyncExport->consumeDownload($jobId);
    }

    /**
     * 后台 SPA meta 扩展（admin.spa_meta）
     *
     * @param callable(array<string,mixed>): array<string,mixed> $handler
     */
    public function adminSpaMetaRegister(
        string $identifier,
        string $bucket,
        callable $handler,
        int $priority = 100,
    ): void {
        $this->adminSpaMeta->register($identifier, $bucket, $handler, $priority);
    }

    /**
     * 后台 SPA 插件列表扩展（admin.spa_plugin_list）
     *
     * @param callable(array<string,mixed>): array<string,mixed> $handler
     */
    public function adminSpaPluginListRegister(string $identifier, string $action, callable $handler): void
    {
        $this->adminSpaPluginList->register($identifier, $action, $handler);
    }

    /**
     * @param callable(): list<array<string,mixed>> $handler
     */
    public function adminMenuRegister(string $identifier, callable $handler, int $priority = 100): void
    {
        $this->adminMenu->register($identifier, $handler, $priority);
    }

    /** @param callable|class-string|array{0:class-string,1:string} $handler */
    public function adminPluginRouteRegister(
        string $method,
        string $path,
        callable|string|array $handler,
        ?string $identifier = null,
    ): void {
        $this->adminPluginRoute->register($method, $path, $handler, $identifier);
    }

    public function adminSensitiveConfirmPathRegister(string $adminUrlPath): void
    {
        $this->adminSensitiveConfirmRoute->registerPath($adminUrlPath);
    }

    public function adminRetiredApiPathRegister(string $adminRelativePrefix, string $message): void
    {
        $this->adminRetiredApiPath->registerPrefix($adminRelativePrefix, $message);
    }

    /** @param array<string, mixed> $def */
    public function adminSpaExplicitRouteRegister(string $adminHref, array $def): void
    {
        $this->adminSpaExplicitRoute->register($adminHref, $def);
    }

    public function adminSpaHostPath(string $pluginId, string $page): string
    {
        return WeappAdminSpaHostRoutes::hostPath($pluginId, $page);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function adminSpaHostRegisterPluginPage(
        string $pluginId,
        string $page,
        string $routeName,
        array $meta,
        ?string $spaPath = null,
        ?string $component = null,
    ): void {
        WeappAdminSpaHostRoutes::registerPluginPage(
            $this,
            $pluginId,
            $page,
            $routeName,
            $meta,
            $spaPath,
            $component,
        );
    }

    public function adminSpaRouteIconRegister(string $adminHref, string $lucideIcon): void
    {
        $this->adminSpaExplicitRoute->registerRouteIcon($adminHref, $lucideIcon);
    }

    /**
     * @param list<array{key:string,title:string,segment:string}> $tabs
     */
    public function adminWeappNavTabsRegister(string $identifier, array $tabs): void
    {
        $this->weappAdminNav->register($identifier, $tabs);
    }

    /** @return array{key:string,title:string,segment:string} */
    public function adminWeappNavTab(string $key, string $title, string $segment): array
    {
        return WeappAdminNavRegistry::tab($key, $title, $segment);
    }

    public function adminMaintenanceHiddenRouteRegister(string $adminHref): void
    {
        $this->adminSpaExplicitRoute->registerMaintenanceHiddenRoute($adminHref);
    }

    public function adminPermissionExtensionRegister(string $controller, string $action, string $permissionCode): void
    {
        $this->adminPermissionExtension->register($controller, $action, $permissionCode);
    }

    /** @param array<string, string> $actions */
    public function adminPermissionExtensionRegisterMap(string $controller, array $actions): void
    {
        $this->adminPermissionExtension->registerMap($controller, $actions);
    }
/** 报价/可售插件支付 scene（= PluginOfferBridgeRegistry identifier） */
    public function paymentOfferScene(): string
    {
        $id = $this->pluginOfferBridge->identifier();

        return $id !== null && $id !== '' ? $id : '';
    }

    public function pluginAdminEntitled(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::allowsAdminApi();
        }

        return $this->entitlement->can($identifier);
    }

    public function pluginAdminHref(string $identifier, string $view): string
    {
        $view = trim($view, '/');
        if ($view === '' || $view === 'index') {
            return '/admin/' . $identifier . '/index';
        }

        return '/admin/' . $identifier . '/' . $view;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $vars
     */
    public function pluginAdminRenderPluginView(
        string $identifier,
        string $view,
        array $query = [],
        array $vars = [],
    ): Response {
        $href    = $this->pluginAdminHref($identifier, $view);
        $spaPath = PivarkVueRoute::spaPathFromLegacy($href, $query, $vars);

        return redirect(
            $spaPath !== null ? SiteUrl::adminSpa($spaPath) : SiteUrl::adminSpa(),
        );
    }

    /** @return array<string, mixed> */
    public function pluginAdminContext(string $identifier, array $manifest, string $navKey = 'settings'): array
    {
        $iconRaw = trim((string) ($manifest['icon'] ?? ''));
        $color   = trim((string) ($manifest['color'] ?? '#5fb878'));
        $image   = ($iconRaw !== '' && (str_starts_with($iconRaw, '/') || str_starts_with($iconRaw, 'http')))
            ? $iconRaw
            : '';

        $ctx = [
            'plugin' => [
                'name'        => (string) ($manifest['name'] ?? $identifier),
                'version'     => (string) ($manifest['version'] ?? '1.0.0'),
                'description' => (string) ($manifest['description'] ?? ''),
                'author'      => (string) ($manifest['author'] ?? ''),
                'icon_image'  => $image,
                'icon_color'  => $color,
                'icon'        => '',
                'package'     => (string) ($manifest['package'] ?? $this->weappContext->packageForIdentifier($identifier)),
                'instance_id' => $this->weappContext->instanceId($identifier),
            ],
            'weapp_admin_base' => '/admin/weapp/' . $identifier,
        ];
        if ($navKey !== '') {
            $ctx['navKey'] = $navKey;
        }

        return $ctx;
    }

    public function pluginAdminAssignSession(): void
    {
        Session::get('admin_user', []);
    }

    /**
     * @param array{filename:string,content:string} $pack
     * @param array<string, mixed>                  $auditExtra
     */
    public function adminDataExportRespondPack(
        array $pack,
        string $contentType,
        string $auditModule,
        array $auditExtra = [],
        string $auditAction = '导出 CSV',
        ?string $profile = null,
    ): Response {
        return $this->adminDataExport->respondPack(
            $pack,
            $contentType,
            $auditModule,
            $auditExtra,
            $auditAction,
            $profile,
        );
    }

    /** @param array<string, mixed> $context */
    public function auditLogOperate(string $action, string $module, array $context = []): void
    {
        $this->auditLog->operate($action, $module, $context);
    }

    public function commerceProductCenterNavAllowed(): bool
    {
        return CommerceProductModeService::shopProductCenterNavAllowed();
    }

    /** @return array<string, mixed> */
    public function menuAnchorByRoute(string $route): array
    {
        $row = $this->menu->anchorByRoute($route);

        return is_array($row) ? $row : [];
    }

    public function siteSlideSlotHomeCarousel(): string
    {
        return SiteSlideService::SLOT_HOME_CAROUSEL;
    }

    public function siteSlideTypeCarousel(): string
    {
        return SiteSlideService::TYPE_CAROUSEL;
    }

    /** @param array<string, mixed> $data */
    public function siteSlideSaveAdmin(array $data): ServiceResult
    {
        return $this->siteSlide->saveAdmin($data);
    }

    /** @return list<array<string, mixed>> */
    public function siteSlideListPublic(string $slot): array
    {
        return $this->siteSlide->listPublic($slot);
    }

    /** @param list<string> $modules */
    public function auditLogCountByModules(array $modules): int
    {
        if ($modules === []) {
            return 0;
        }

        return (int) AuditLog::whereIn('module', $modules)->count();
    }

    /** @param list<string> $modules @return list<string> */
    public function auditLogDistinctModules(array $modules, int $limit = 50): array
    {
        if ($modules === []) {
            return [];
        }
        $names = AuditLog::whereIn('module', $modules)
            ->distinct(true)
            ->limit(max(1, $limit))
            ->column('module');

        return is_array($names) ? array_values(array_filter(array_map('strval', $names))) : [];
    }

    /** @param list<int> $ids */
    public function auditLogDeleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return (int) AuditLog::whereIn('id', $ids)->delete();
    }

    /** @param list<string> $modules @return list<string> */
    public function auditLogDistinctUsernamesByModules(array $modules, int $limit = 200): array
    {
        if ($modules === []) {
            return [];
        }
        $names = AuditLog::whereIn('module', $modules)
            ->where('username', '<>', '')
            ->distinct(true)
            ->order('username', 'asc')
            ->limit(max(1, $limit))
            ->column('username');

        return is_array($names) ? array_values(array_filter(array_map('strval', $names))) : [];
    }

    /** @param list<int> $ids @param list<string> $modules */
    public function auditLogDeleteByIdsForModules(array $ids, array $modules): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || $modules === []) {
            return 0;
        }

        return (int) AuditLog::whereIn('id', $ids)->whereIn('module', $modules)->delete();
    }

    public function productCenterAllowsAdmin(): bool
    {
        return $this->productCenterGate->allowsAdmin();
    }
}
