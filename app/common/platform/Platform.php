<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 第三方插件 Platform Facade（只读 v1）
 *
 * 插件应通过本类访问 Core 能力，避免直 Db:: 读 Core 表。
 */
declare(strict_types=1);

namespace app\common\platform;



use app\common\model\Item;
use app\common\platform\EnterpriseResourcePort;
use app\common\service\config\ConfigService;
use app\common\service\item\ItemPublicGateway;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginService;
use app\common\service\product\ProductCenterGateService;

final class Platform
{
    public static function entitled(string $identifier): bool
    {
        return app(EntitlementService::class)->can($identifier);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pluginManifest(string $identifier): ?array
    {
        return app(PluginService::class)->readManifest($identifier);
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return app(ConfigService::class)->get($key, $default);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public static function itemsPublic(array $params = []): array
    {
        return app(ItemPublicGateway::class)->listPublic($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function itemBySlug(string $slug): ?array
    {
        return app(ItemPublicGateway::class)->findPublicBySlug($slug);
    }

    /**
     * @return array<string, mixed>|null 后台行或公开行均可由调用方区分；此处返回原始 items 行数组
     */
    public static function itemById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = Item::where('id', $id)->find();

        return $row ? (array) $row : null;
    }

    /**
     * 产品展示参数定义（需产品中心档位授权且开启）
     *
     * @return list<array<string, mixed>>
     */
    public static function productParamDefs(): array
    {
        if (!app(ProductCenterGateService::class)->allowsParams()) {
            return [];
        }

        return app(\app\common\service\product\DocumentProductFacade::class)->listParamDefs();
    }

    /**
     * document-addon 文档资源列表（按 bridge meta 发现 provider，调用方传 bridge meta）
     *
     * @return list<array<string, mixed>>
     */
    public static function documentAddonBundlesForDocument(
        int $documentId,
        bool $forAdmin = false,
        string $bridgeMeta,
    ): array {
        if ($documentId < 1) {
            return [];
        }
        $identifier = app(\app\common\service\plugin\registry\PluginExtensionRegistry::class)
            ->documentAddonBridgeIdentifierForMeta($bridgeMeta);
        if ($identifier === null || !self::entitled($identifier) || !DocumentAddonBridgeAccess::isEnabled($identifier)) {
            return [];
        }
        $rows = DocumentAddonBridgeAccess::invokeOr([], $identifier, 'listForDocument', [$documentId, $forAdmin]);

        return is_array($rows) ? $rows : [];
    }

    public static function enterpriseResource(): EnterpriseResourcePort
    {
        return app(EnterpriseResourcePort::class);
    }
}
