<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\contract\DocumentAddonBridgeHandlerInterface;
use app\common\contract\ImportIntentDelegateInterface;
use app\common\contract\PluginHostRuntimeHandlerInterface;
use app\common\service\plugin\boot\PluginRuntimeFaultGuard;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\extension\ExtensionTraceService;
use app\common\service\plugin\PluginService;
use app\common\support\ServiceResult;

/**
 * 插件扩展点统一注册表
 *
 * 内核只 dispatch(point_id, ctx)；插件在 boot/manifest 注册 handler。
 */
final class PluginExtensionRegistry
{
    public const POINT_DOCUMENT_ADDON_SAVE = 'document_addon.save';

    public const POINT_DOCUMENT_ADDON_BRIDGE = 'document_addon.bridge';

    public const POINT_PRODUCT_TAB_AFTER_PERSIST = 'product_tab.after_persist';

    public const POINT_ADMIN_SPA_META = 'admin.spa_meta';

    public const POINT_IMPORT_INTENT_DELEGATE = 'import_intent.delegate';

    public const POINT_HOST_RUNTIME = 'host_runtime.handler';

    public const POINT_OFFICIAL_PRODUCT = 'official_product';

    public const POINT_PLUGIN_PREFLIGHT = 'plugin.preflight';

    /** @var array<string, list<array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>}>> */
    private static array $handlersByPoint = [];

    public function reset(): void
    {
        self::$handlersByPoint = [];
    }

    public function resetPoint(string $pointId): void
    {
        $pointId = trim($pointId);
        if ($pointId !== '') {
            unset(self::$handlersByPoint[$pointId]);
        }
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        foreach (array_keys(self::$handlersByPoint) as $pointId) {
            self::$handlersByPoint[$pointId] = array_values(array_filter(
                self::$handlersByPoint[$pointId],
                static fn (array $entry): bool => strtolower(trim((string) ($entry['identifier'] ?? ''))) !== $identifier,
            ));
            if (self::$handlersByPoint[$pointId] === []) {
                unset(self::$handlersByPoint[$pointId]);
            }
        }
    }

    /**
     * @param callable|object $handler
     * @param array<string, mixed> $meta post_keys · bucket · asset_sync · …
     */
    public function register(
        string $pointId,
        string $identifier,
        callable|object $handler,
        int $priority = 100,
        array $meta = [],
    ): void {
        $pointId    = trim($pointId);
        $identifier = trim($identifier);
        if ($pointId === '' || $identifier === '') {
            return;
        }
        self::$handlersByPoint[$pointId] ??= [];
        self::$handlersByPoint[$pointId][] = [
            'identifier' => $identifier,
            'handler'    => $handler,
            'priority'   => $priority,
            'meta'       => $meta,
        ];
        usort(self::$handlersByPoint[$pointId], static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['identifier'], $b['identifier']);
        });
    }

    /** @return list<string> */
    public function registeredPointIds(): array
    {
        $ids = array_keys(self::$handlersByPoint);
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    public function registeredIdentifiers(string $pointId): array
    {
        $pointId = trim($pointId);
        if ($pointId === '' || !isset(self::$handlersByPoint[$pointId])) {
            return [];
        }
        $ids = [];
        foreach (self::$handlersByPoint[$pointId] as $entry) {
            $ids[] = $entry['identifier'];
        }

        return $ids;
    }

    /**
     * document-addon.save：文档 Tab POST 同步
     *
     * @param callable(string): bool $enabledInEditor
     * @param callable(string): bool $enabledForMember
     */
    public function dispatchDocumentAddonSave(
        int $documentId,
        array $post,
        bool $forMember,
        callable $enabledInEditor,
        callable $enabledForMember,
    ): ServiceResult {
        if ($documentId < 1) {
            return ServiceResult::ok(null, 'ok');
        }
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_SAVE) as $entry) {
            $identifier = $entry['identifier'];
            if (!$enabledInEditor($identifier)) {
                continue;
            }
            if ($forMember && !$enabledForMember($identifier)) {
                continue;
            }
            $field = $this->firstMatchingPostKey($entry, $post);
            if ($field === null) {
                continue;
            }
            if (!$this->pluginActive($identifier)) {
                continue;
            }
            $decoded = $this->decodeJsonList($post[$field] ?? '[]');
            app(ExtensionTraceService::class)->log(self::POINT_DOCUMENT_ADDON_SAVE, $identifier, [
                'document_id' => $documentId,
                'field'       => $field,
            ]);
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            try {
                $sync = $handler($documentId, $decoded);
            } catch (\Throwable $e) {
                PluginRuntimeFaultGuard::log('plugin_document_addon_save_failed', [
                    'identifier'  => $identifier,
                    'document_id' => $documentId,
                    'field'       => $field,
                ], $e);
                $sync = ServiceResult::fail($e->getMessage());
            }
            if (!$sync instanceof ServiceResult || !$sync->isOk()) {
                $msg = $sync instanceof ServiceResult ? $sync->message() : 'sync failed';
                $manifest = app(PluginService::class)->readManifest($identifier);
                $label    = trim((string) ($manifest['name'] ?? $identifier));
                if ($label === '') {
                    $label = $identifier;
                }

                return ServiceResult::fail('文档已保存，但' . $label . '同步失败：' . $msg);
            }
            $assetSync = $entry['meta']['asset_sync'] ?? null;
            if (is_callable($assetSync)) {
                PluginRuntimeFaultGuard::invoke(
                    static fn (): mixed => $assetSync($documentId),
                    'plugin_document_addon_asset_sync_failed',
                    [
                        'identifier'  => $identifier,
                        'document_id' => $documentId,
                    ],
                );
            }
        }

        return ServiceResult::ok(null, 'ok');
    }

    /** @return list<string> */
    public function documentAddonSavePostKeys(): array
    {
        $keys = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_SAVE) as $entry) {
            foreach ($this->postKeys($entry) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<array{field:string, identifier:string}>
     */
    public function documentAddonSaveFieldMap(): array
    {
        $out = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_SAVE) as $entry) {
            foreach ($this->postKeys($entry) as $key) {
                $out[] = [
                    'field'      => $key,
                    'identifier' => $entry['identifier'],
                ];
            }
        }

        return $out;
    }

    public function hasDocumentAddonBridge(string $identifier): bool
    {
        return $this->getDocumentAddonBridge($identifier) !== null;
    }

    public function getDocumentAddonBridge(string $identifier): ?DocumentAddonBridgeHandlerInterface
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            if ($entry['identifier'] !== $identifier) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface) {
                continue;
            }
            if (!$this->bridgeHandlerActive($handler)) {
                return null;
            }

            return $handler;
        }

        return null;
    }

    /** @return list<DocumentAddonBridgeHandlerInterface> */
    public function enabledDocumentAddonBridges(): array
    {
        $out = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface) {
                continue;
            }
            if (!$this->bridgeHandlerActive($handler)) {
                continue;
            }
            $out[] = $handler;
        }

        return $out;
    }

    /** @return list<string> 已启用 document-addon bridge 的站点唯一 identifier（非全局类型名） */
    public function documentAddonBridgeIdentifiers(): array
    {
        $ids = [];
        foreach ($this->enabledDocumentAddonBridges() as $handler) {
            $id = strtolower(trim($handler->identifier()));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    /** 按 boot meta 发现 document-addon bridge（如 doc_gallery_pack → 打包下载能力提供者） */
    /** @return list<string> 已启用且声明 metaKey 的全部 bridge identifier（同台竞品 · 非单槽） */
    public function documentAddonBridgeIdentifiersForMeta(string $metaKey): array
    {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return [];
        }
        $ids = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            if (empty($entry['meta'][$metaKey])) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface || !$this->bridgeHandlerActive($handler)) {
                continue;
            }
            $id = strtolower(trim($handler->identifier()));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function documentAddonBridgeIdentifierForMeta(string $metaKey): ?string
    {
        $ids = $this->documentAddonBridgeIdentifiersForMeta($metaKey);

        return $ids === [] ? null : $ids[0];
    }


    /** meta 键值匹配发现 bridge（如 document_availability => video） */
    public function documentAddonBridgeIdentifierForMetaValue(string $metaKey, mixed $expected): ?string
    {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return null;
        }
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            if (!array_key_exists($metaKey, $entry['meta'])) {
                continue;
            }
            if ($entry['meta'][$metaKey] != $expected) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface || !$this->bridgeHandlerActive($handler)) {
                continue;
            }
            $id = strtolower(trim($handler->identifier()));

            return $id !== '' ? $id : null;
        }

        return null;
    }

    /** @return list<string> boot meta document_availability 槽位（模板 flag: document_has_{slot}） */
    public function documentAvailabilitySlots(): array
    {
        $slots = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            $slot = trim((string) ($entry['meta']['document_availability'] ?? ''));
            if ($slot === '') {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface || !$this->bridgeHandlerActive($handler)) {
                continue;
            }
            $slots[] = $slot;
        }
        sort($slots);

        return array_values(array_unique($slots));
    }
    /**
     * document-addon bridge boot meta · dashboard_overview → 后台概览 KPI（内核不写死 plugin id）
     *
     * @return list<array{identifier:string,key:string,logical_table:string,title:string,unit:string,defaultVisible:bool}>
     */
    public function documentAddonDashboardOverviewStats(): array
    {
        $out = [];
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            $raw = $entry['meta']['dashboard_overview'] ?? null;
            if (!is_array($raw)) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof DocumentAddonBridgeHandlerInterface || !$this->bridgeHandlerActive($handler)) {
                continue;
            }
            $identifier   = strtolower(trim($handler->identifier()));
            $key          = trim((string) ($raw['key'] ?? $identifier));
            $logicalTable = trim((string) ($raw['logical_table'] ?? ''));
            if ($identifier === '' || $key === '' || $logicalTable === '') {
                continue;
            }
            $out[] = [
                'identifier'       => $identifier,
                'key'              => $key,
                'logical_table'    => $logicalTable,
                'title'            => trim((string) ($raw['title'] ?? $key)),
                'unit'             => trim((string) ($raw['unit'] ?? '条')) ?: '条',
                'defaultVisible'   => (bool) ($raw['defaultVisible'] ?? false),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public function documentAddonBridgeListMethodsFor(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return ['listForDocument'];
        }
        foreach ($this->entries(self::POINT_DOCUMENT_ADDON_BRIDGE) as $entry) {
            if (strtolower(trim($entry['identifier'])) !== $identifier) {
                continue;
            }
            $methods = $entry['meta']['document_list_methods'] ?? null;
            if (!is_array($methods) || $methods === []) {
                return ['listForDocument'];
            }

            return array_values(array_filter(
                array_map(static fn (mixed $m): string => trim((string) $m), $methods),
                static fn (string $m): bool => $m !== '',
            ));
        }

        return ['listForDocument'];
    }

    /**
     * @param list<mixed> $args
     */
    public function invokeDocumentAddonBridge(string $identifier, string $method, array $args = []): mixed
    {
        $handler = $this->getDocumentAddonBridge($identifier);
        if ($handler === null || !method_exists($handler, $method)) {
            return null;
        }
        app(ExtensionTraceService::class)->log(self::POINT_DOCUMENT_ADDON_BRIDGE, $identifier, [
            'method' => $method,
        ]);

        return PluginRuntimeFaultGuard::invoke(
            static fn () => $handler->{$method}(...$args),
            'plugin_document_addon_bridge_invoke_failed',
            ['identifier' => $identifier, 'method' => $method],
            null,
        );
    }

    public function getHostRuntimeHandler(string $identifier): ?PluginHostRuntimeHandlerInterface
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !app(EntitlementService::class)->can($identifier)) {
            return null;
        }
        foreach ($this->entries(self::POINT_HOST_RUNTIME) as $entry) {
            if ($entry['identifier'] !== $identifier) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof PluginHostRuntimeHandlerInterface) {
                continue;
            }

            return $handler;
        }

        return null;
    }

    /** @return list<string> */
    public function hostRuntimeIdentifiers(): array
    {
        $ids = [];
        foreach ($this->entries(self::POINT_HOST_RUNTIME) as $entry) {
            $id = strtolower(trim((string) ($entry['identifier'] ?? '')));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<mixed> $args
     */
    public function invokeHostRuntimeHandler(string $identifier, string $method, array $args = []): mixed
    {
        $handler = $this->getHostRuntimeHandler($identifier);
        if ($handler === null || !method_exists($handler, $method)) {
            return null;
        }
        app(ExtensionTraceService::class)->log(self::POINT_HOST_RUNTIME, $identifier, [
            'method' => $method,
        ]);

        return PluginRuntimeFaultGuard::invoke(
            static fn () => $handler->{$method}(...$args),
            'plugin_host_runtime_invoke_failed',
            ['identifier' => $identifier, 'method' => $method],
            null,
        );
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function dispatchOfficialProduct(string $operation, array $ctx, mixed $default): mixed
    {
        $operation = trim($operation);
        if ($operation === '') {
            return $default;
        }
        $ctx['operation'] = $operation;
        foreach ($this->entries(self::POINT_OFFICIAL_PRODUCT) as $entry) {
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            app(ExtensionTraceService::class)->log(self::POINT_OFFICIAL_PRODUCT, $entry['identifier'], [
                'operation' => $operation,
            ]);
            $result = PluginRuntimeFaultGuard::invoke(
                static fn (): mixed => $handler($ctx),
                'plugin_official_product_failed',
                ['identifier' => $entry['identifier'], 'operation' => $operation],
                null,
            );
            if ($result !== null) {
                return $result;
            }
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function collectPluginPreflightFlags(): array
    {
        $merged = [];
        foreach ($this->entries(self::POINT_PLUGIN_PREFLIGHT) as $entry) {
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            $chunk = PluginRuntimeFaultGuard::invoke(
                static fn (): mixed => $handler([]),
                'plugin_preflight_flags_failed',
                ['identifier' => $entry['identifier']],
                [],
            );
            if (is_array($chunk) && $chunk !== []) {
                $merged = array_merge($merged, $chunk);
            }
        }

        return $merged;
    }

    /**
     * product_tab.after_persist
     *
     * @param array<string, mixed> $ctx
     */
    public function dispatchProductTabAfterPersist(array $ctx): ?ServiceResult
    {
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $ran  = false;
        $last = null;
        foreach ($this->entries(self::POINT_PRODUCT_TAB_AFTER_PERSIST) as $entry) {
            if (!$this->handlerMatchesPost($entry, $post)) {
                continue;
            }
            $ran  = true;
            app(ExtensionTraceService::class)->log(self::POINT_PRODUCT_TAB_AFTER_PERSIST, $entry['identifier'], [
                'document_id' => (int) ($ctx['document_id'] ?? 0),
                'item_id'     => (int) ($ctx['item_id'] ?? 0),
            ]);
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            $last = PluginRuntimeFaultGuard::invoke(
                static fn () => $handler($ctx),
                'plugin_product_tab_after_persist_failed',
                [
                    'identifier'  => (string) ($entry['identifier'] ?? ''),
                    'document_id' => (int) ($ctx['document_id'] ?? 0),
                ],
                null,
            );
            if ($last instanceof ServiceResult && !$last->isOk()) {
                return $last;
            }
        }

        return $ran ? ($last instanceof ServiceResult ? $last : ServiceResult::ok(null, 'ok')) : null;
    }

    /** @param array<string, mixed> $post */
    public function productTabPostDataHasRegisteredKeys(array $post): bool
    {
        if ($post === []) {
            return false;
        }
        foreach ($this->entries(self::POINT_PRODUCT_TAB_AFTER_PERSIST) as $entry) {
            if ($this->handlerMatchesPost($entry, $post)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function productTabRegisteredPostKeys(): array
    {
        $keys = [];
        foreach ($this->entries(self::POINT_PRODUCT_TAB_AFTER_PERSIST) as $entry) {
            foreach ($this->postKeys($entry) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * admin.spa_meta：按 bucket 合并
     *
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function collectAdminSpaMetaMerged(string $bucket, array $ctx = []): array
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            return [];
        }
        $merged = [];
        foreach ($this->entries(self::POINT_ADMIN_SPA_META) as $entry) {
            if ($this->metaString($entry, 'bucket') !== $bucket) {
                continue;
            }
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            $chunk = $handler($ctx);
            if (!is_array($chunk) || $chunk === []) {
                continue;
            }
            $merged = array_merge($merged, $chunk);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>|null
     */
    public function collectAdminSpaMetaFirst(string $bucket, array $ctx = []): ?array
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            return null;
        }
        foreach ($this->entries(self::POINT_ADMIN_SPA_META) as $entry) {
            if ($this->metaString($entry, 'bucket') !== $bucket) {
                continue;
            }
            $handler = $entry['handler'];
            if (!is_callable($handler)) {
                continue;
            }
            $chunk = $handler($ctx);
            if (is_array($chunk) && $chunk !== []) {
                return $chunk;
            }
        }

        return null;
    }

    public function registerImportIntentDelegate(ImportIntentDelegateInterface $delegate, int $priority = 100): void
    {
        $this->register(
            self::POINT_IMPORT_INTENT_DELEGATE,
            $delegate->identifier(),
            $delegate,
            $priority !== 100 ? $priority : $delegate->priority(),
        );
    }

    public function getImportIntentDelegate(string $identifier): ?ImportIntentDelegateInterface
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }
        foreach ($this->entries(self::POINT_IMPORT_INTENT_DELEGATE) as $entry) {
            if ($entry['identifier'] !== $identifier) {
                continue;
            }
            $handler = $entry['handler'];
            if (!$handler instanceof ImportIntentDelegateInterface) {
                continue;
            }
            if (!$handler->isEnabled()) {
                return null;
            }

            return $handler;
        }

        return null;
    }

    /**
     * @return list<array{
     *   identifier:string,
     *   label:string,
     *   enabled:bool,
     *   intent_kinds:list<string>,
     *   channels:list<string>,
     *   priority:int
     * }>
     */
    public function importIntentDelegateCatalog(): array
    {
        $out = [];
        foreach ($this->entries(self::POINT_IMPORT_INTENT_DELEGATE) as $entry) {
            $handler = $entry['handler'];
            if (!$handler instanceof ImportIntentDelegateInterface) {
                continue;
            }
            $out[] = [
                'identifier'   => $handler->identifier(),
                'label'        => $handler->label(),
                'enabled'      => $handler->isEnabled(),
                'intent_kinds' => $handler->supportedIntentKinds(),
                'channels'     => $handler->supportedChannels(),
                'priority'     => (int) ($entry['priority'] ?? $handler->priority()),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function importIntentSuggestPlugins(string $intentKind, string $channel = 'paste'): array
    {
        $intentKind = strtolower(trim($intentKind));
        $channel    = strtolower(trim($channel));
        $ids        = [];
        foreach ($this->entries(self::POINT_IMPORT_INTENT_DELEGATE) as $entry) {
            $handler = $entry['handler'];
            if (!$handler instanceof ImportIntentDelegateInterface || !$handler->isEnabled()) {
                continue;
            }
            $kinds = array_map('strtolower', $handler->supportedIntentKinds());
            if (!in_array($intentKind, $kinds, true)) {
                continue;
            }
            $channels = array_map('strtolower', $handler->supportedChannels());
            if ($channels !== [] && !in_array($channel, $channels, true)) {
                continue;
            }
            $ids[] = $handler->identifier();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>}>
     */
    private function entries(string $pointId): array
    {
        return self::$handlersByPoint[$pointId] ?? [];
    }

    /** @param array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>} $entry */
    private function postKeys(array $entry): array
    {
        $keys = [];
        foreach ((array) ($entry['meta']['post_keys'] ?? []) as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** @param array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>} $entry */
    private function metaString(array $entry, string $key): string
    {
        return trim((string) ($entry['meta'][$key] ?? ''));
    }

    /** @param array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>} $entry */
    private function firstMatchingPostKey(array $entry, array $post): ?string
    {
        foreach ($this->postKeys($entry) as $key) {
            if (array_key_exists($key, $post)) {
                return $key;
            }
        }

        return null;
    }

    /** @param array{identifier:string, handler:callable|object, priority:int, meta:array<string,mixed>} $entry */
    private function handlerMatchesPost(array $entry, array $post): bool
    {
        $keys = $this->postKeys($entry);
        if ($keys === []) {
            return true;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $post)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function decodeJsonList(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [];
        }
        $out = [];
        foreach ($parsed as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function pluginActive(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        return app(EntitlementService::class)->can($identifier)
            && is_dir(ROOT_PATH . 'weapp/' . $identifier);
    }

    private function bridgeHandlerActive(DocumentAddonBridgeHandlerInterface $handler): bool
    {
        if (!$handler->isEnabled()) {
            return false;
        }

        return $this->pluginActive($handler->identifier());
    }
}
