<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\contract\DocumentAddonBridgeHandlerInterface;
use app\common\service\plugin\boot\PluginRuntimeFaultGuard;
use app\common\service\plugin\registry\PluginExtensionRegistry;

/** 内核调用 document-addon bridge 的唯一入口（无 per-plugin 内核文件） */
final class DocumentAddonBridgeAccess
{
    public static function isEnabled(string $identifier): bool
    {
        $bridge = app(PluginExtensionRegistry::class)->getDocumentAddonBridge($identifier);

        return $bridge !== null && $bridge->isEnabled();
    }

    public static function bridge(string $identifier): ?DocumentAddonBridgeHandlerInterface
    {
        return app(PluginExtensionRegistry::class)->getDocumentAddonBridge($identifier);
    }

    /**
     * @param list<mixed> $args
     */
    public static function invoke(string $identifier, string $method, array $args = []): mixed
    {
        return PluginRuntimeFaultGuard::invoke(
            static fn (): mixed => app(PluginExtensionRegistry::class)->invokeDocumentAddonBridge($identifier, $method, $args),
            'plugin_addon_bridge_invoke_failed',
            [
                'identifier' => strtolower(trim($identifier)),
                'method'     => trim($method),
            ],
        );
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeOr(mixed $default, string $identifier, string $method, array $args = []): mixed
    {
        if (!self::isEnabled($identifier)) {
            return $default;
        }
        $result = self::invoke($identifier, $method, $args);

        return $result ?? $default;
    }

    /** @return array<string, mixed> */
    public static function documentEditorSpaPayload(string $identifier, int $documentId): array
    {
        if (!self::isEnabled($identifier)) {
            return [];
        }
        $result = self::invoke($identifier, 'documentEditorSpaPayload', [$documentId]);

        return is_array($result) ? $result : [];
    }
    /** @return list<string> */
    public static function enabledIdentifiersForMeta(string $metaKey): array
    {
        $metaKey = strtolower(trim($metaKey));
        if ($metaKey === '') {
            return [];
        }
        $out = [];
        foreach (app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifiersForMeta($metaKey) as $identifier) {
            if (self::isEnabled($identifier)) {
                $out[] = $identifier;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $args
     * @return list<mixed>
     */
    public static function invokeAllForMeta(string $metaKey, string $method, array $args = []): array
    {
        $merged = [];
        foreach (self::enabledIdentifiersForMeta($metaKey) as $identifier) {
            $part = self::invokeOr([], $identifier, $method, $args);
            if (is_array($part) && $part !== []) {
                foreach ($part as $row) {
                    $merged[] = $row;
                }
            }
        }

        return $merged;
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeFirstForMetaOr(
        mixed $default,
        string $metaKey,
        string $method,
        array $args = [],
        ?string $identifier = null,
    ): mixed {
        if ($identifier !== null && $identifier !== '') {
            return self::isEnabled($identifier)
                ? self::invokeOr($default, $identifier, $method, $args)
                : $default;
        }
        $listMethods = ['listForDocument', 'listSearchAttachmentPaths'];
        if (in_array($method, $listMethods, true)) {
            $merged = self::invokeAllForMeta($metaKey, $method, $args);

            return $merged === [] ? $default : $merged;
        }
        foreach (self::enabledIdentifiersForMeta($metaKey) as $id) {
            $result = self::invokeOr(null, $id, $method, $args);
            if ($result !== null) {
                return $result;
            }
        }

        return $default;
    }

    public static function hasAnyListForMeta(int $documentId, string $metaKey, string $method = 'listForDocument'): bool
    {
        if ($documentId < 1) {
            return false;
        }
        foreach (self::enabledIdentifiersForMeta($metaKey) as $identifier) {
            if (self::hasDocumentBridgeList($documentId, $identifier, $method)) {
                return true;
            }
        }

        return false;
    }


    /** gallery 等跨插件打包下载：发现注册了 doc_gallery_pack meta 的 bridge */
    public static function docGalleryPackBridgeIdentifier(): ?string
    {
        return app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifierForMeta('doc_gallery_pack');
    }


    /** @param list<string>|null $methods */
    public static function hasDocumentBridgeList(int $documentId, string $identifier, string $method = 'listForDocument'): bool
    {
        if ($documentId < 1 || $identifier === '' || !self::isEnabled($identifier)) {
            return false;
        }
        $list = self::invokeOr([], $identifier, $method, [$documentId]);

        return is_array($list) && $list !== [];
    }

    /** @param list<string>|null $methods */
    public static function hasDocumentBridgeContent(int $documentId, string $identifier, ?array $methods = null): bool
    {
        if ($documentId < 1 || $identifier === '' || !self::isEnabled($identifier)) {
            return false;
        }
        $methods ??= app(PluginExtensionRegistry::class)->documentAddonBridgeListMethodsFor($identifier);
        foreach ($methods as $method) {
            if ($method !== '' && self::hasDocumentBridgeList($documentId, $identifier, $method)) {
                return true;
            }
        }

        return false;
    }

    /** document-addon bridge 声明 document_groups meta → 分组型图集等 */
    public static function documentGroupsBridgeIdentifier(): ?string
    {
        $id = app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifierForMeta('document_groups');
        if ($id === null || !self::isEnabled($id)) {
            return null;
        }

        return $id;
    }

    public static function hasDocumentGroups(int $documentId): bool
    {
        $identifier = self::documentGroupsBridgeIdentifier();
        if ($identifier === null || $documentId < 1) {
            return false;
        }
        $payload = self::invokeOr(['groups' => []], $identifier, 'payloadForDocument', [$documentId, true]);
        $groups  = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];

        return $groups !== [];
    }

    /** document-addon bridge 声明 document_renderable meta → 品项封面兜底等 */
    public static function documentRenderableBridgeIdentifier(): ?string
    {
        $id = app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifierForMeta('document_renderable');
        if ($id === null || !self::isEnabled($id)) {
            return null;
        }

        return $id;
    }

    public static function hasDocumentRenderableContent(int $documentId): bool
    {
        $identifier = self::documentRenderableBridgeIdentifier();
        if ($identifier === null || $documentId < 1) {
            return false;
        }

        return (bool) self::invokeOr(false, $identifier, 'hasRenderableForDocument', [$documentId]);
    }
    /** boot meta 发现 bridge（enabled 校验） */
    public static function bridgeIdentifierForMeta(string $metaKey): ?string
    {
        $id = app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifierForMeta($metaKey);
        if ($id === null || !self::isEnabled($id)) {
            return null;
        }

        return $id;
    }

    /** boot meta 发现 bridge（meta 值须匹配，如 document_availability => video） */
    public static function bridgeIdentifierForMetaValue(string $metaKey, mixed $expected): ?string
    {
        $id = app(PluginExtensionRegistry::class)->documentAddonBridgeIdentifierForMetaValue($metaKey, $expected);
        if ($id === null || !self::isEnabled($id)) {
            return null;
        }

        return $id;
    }

    /**
     * @param list<mixed> $args
     */
    public static function invokeMetaOr(mixed $default, string $metaKey, string $method, array $args = []): mixed
    {
        $identifier = self::bridgeIdentifierForMeta($metaKey);
        if ($identifier === null) {
            return $default;
        }

        return self::invokeOr($default, $identifier, $method, $args);
    }

    public static function hasDocumentAvailability(int $documentId, string $slot): bool
    {
        $identifier = self::bridgeIdentifierForMetaValue('document_availability', $slot);
        if ($identifier === null) {
            return false;
        }
        $handler = self::bridge($identifier);
        if ($handler !== null && method_exists($handler, 'hasAvailabilityForDocument')) {
            return (bool) $handler->hasAvailabilityForDocument($documentId);
        }
        if ($documentId < 1) {
            return false;
        }

        return self::hasDocumentBridgeContent($documentId, $identifier);
    }

    /**
     * 将模板 has/nohas 属性解析为 document_availability 槽位名（认已注册槽 + gallery→doc_gallery 等后缀）。
     */
    public static function resolveAvailabilitySlot(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }
        $slots = app(PluginExtensionRegistry::class)->documentAvailabilitySlots();
        if (in_array($raw, $slots, true)) {
            return $raw;
        }
        $prefixed = str_starts_with($raw, 'doc_') ? $raw : ('doc_' . $raw);
        if (in_array($prefixed, $slots, true)) {
            return $prefixed;
        }
        foreach ($slots as $slot) {
            if (str_ends_with($slot, '_' . $raw)) {
                return $slot;
            }
        }
        if (in_array($raw, ['download', 'bundle'], true)) {
            foreach ($slots as $slot) {
                if (str_contains($slot, 'bundle') || str_contains($slot, 'download')) {
                    return $slot;
                }
            }
            // 插件尚未 boot（CLI）仍吐规范槽，供 meta 查找
            return 'doc_bundle';
        }
        // 槽表空或未命中：口语 gallery → doc_gallery
        if (!str_starts_with($raw, 'doc_')) {
            return $prefixed;
        }

        return $raw;
    }

    /**
     * 有指定可用性槽数据的文档 ID（供 arclist has/nohas 列表过滤；插件可实现 documentIdsWithAvailability）。
     *
     * @return list<int>
     */
    public static function documentIdsWithAvailability(string $slot): array
    {
        $filter = self::availabilityListFilter($slot);

        return $filter['mode'] === 'ids' ? $filter['ids'] : [];
    }

    /**
     * arclist has/nohas 列表过滤契约。
     * - all：全站槽（如评论开关开）→ 不过滤 ID
     * - none：无数据 / 槽未启用 → has 时空列表
     * - ids：按文档挂载
     *
     * @return array{mode:'all'|'none'|'ids', ids:list<int>}
     */
    public static function availabilityListFilter(string $slot): array
    {
        $slot = self::resolveAvailabilitySlot($slot);
        if ($slot === '') {
            return ['mode' => 'none', 'ids' => []];
        }
        $identifier = self::bridgeIdentifierForMetaValue('document_availability', $slot);
        if ($identifier === null) {
            return ['mode' => 'none', 'ids' => []];
        }
        $handler = self::bridge($identifier);
        if ($handler !== null && method_exists($handler, 'availabilityIsSiteWide') && $handler->availabilityIsSiteWide()) {
            $on = method_exists($handler, 'hasAvailabilityForDocument')
                ? (bool) $handler->hasAvailabilityForDocument(0)
                : self::hasDocumentAvailability(0, $slot);

            return ['mode' => $on ? 'all' : 'none', 'ids' => []];
        }
        if ($handler !== null && method_exists($handler, 'documentIdsWithAvailability')) {
            $ids = $handler->documentIdsWithAvailability();
            if (!is_array($ids)) {
                return ['mode' => 'none', 'ids' => []];
            }
            $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

            return ['mode' => $clean === [] ? 'none' : 'ids', 'ids' => $clean];
        }

        return ['mode' => 'none', 'ids' => []];
    }

    public static function isGlobalBridgeActive(string $metaKey): bool
    {
        $identifier = self::bridgeIdentifierForMeta($metaKey);
        if ($identifier === null) {
            return false;
        }

        return (bool) self::invokeOr(false, $identifier, 'isActive', []);
    }

    /** @param array<string, mixed>|null $order */
    public static function watchUrlForPaidOrder(?array $order): string
    {
        if (!is_array($order) || $order === []) {
            return '';
        }

        return (string) self::invokeMetaOr('', 'payment_watch_url', 'watchUrlForOrder', [$order]);
    }

    public static function frontPlazaPath(): string
    {
        $path = self::invokeMetaOr('', 'frontend_plaza', 'frontPlazaPath', []);

        return is_string($path) ? trim($path) : '';
    }
}
