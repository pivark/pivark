<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\manifest\PluginTaxonomyService;
use app\common\service\plugin\weapp\WeappAdminUiService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\product\DocumentProductFacade;
use app\common\service\member\MemberConfigService;
use app\common\service\product\ProductL1Access;
use app\common\model\Plugin;
use app\common\support\InstallGate;
use app\common\support\OpsLog;
use think\facade\Session;

class PluginDocumentEditorService
{
    public function __construct(
        private readonly MemberConfigService $memberConfigService,
        private readonly EntitlementService $entitlementService,
        private readonly PluginEditorSurfaceService $pluginEditorSurfaceService,
        private readonly PluginService $pluginService,
        private readonly PluginTaxonomyService $pluginTaxonomyService,
    ) {
    }

    /** 文档发布 UI：iframe 固定 component=iframe；core_vue 用站点唯一 identifier（非全局类型名） */
    public function spaComponentKey(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return '';
        }

        $manifest = $this->pluginService->readManifest($identifier) ?? [];
        if ($this->resolveDocumentEditorUiMode($identifier, $manifest) === WeappAdminUiService::MODE_IFRAME) {
            return WeappAdminUiService::MODE_IFRAME;
        }

        return $identifier;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTabs(): array
    {
        return $this->listSpaBySlot(0, PluginEditorSurfaceService::SLOT_TAB);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInlines(): array
    {
        return $this->listSpaBySlot(0, PluginEditorSurfaceService::SLOT_INLINE);
    }

    /**
     * @return array{tabs:list<array<string,mixed>>,inlines:list<array<string,mixed>>}
     */
    public function listSpaSurfaces(int $documentId = 0): array
    {
        return [
            'tabs'    => $this->listSpaBySlot($documentId, PluginEditorSurfaceService::SLOT_TAB),
            'inlines' => $this->listSpaBySlot($documentId, PluginEditorSurfaceService::SLOT_INLINE),
        ];
    }

    /**
     * @return array{tabs:list<array<string,mixed>>,inlines:list<array<string,mixed>>}
     */
    public function listMemberSpaSurfaces(int $documentId = 0): array
    {
        $all = $this->listSpaSurfaces($documentId);
        $filter = function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $id = (string) ($row['identifier'] ?? '');
                if ($id !== '' && $this->memberConfigService->isDocumentPluginOpenForMember($id)) {
                    $out[] = $row;
                }
            }

            return $out;
        };

        return [
            'tabs'    => $filter($all['tabs']),
            'inlines' => $filter($all['inlines']),
        ];
    }

    public function hasSpaComponent(string $identifier): bool
    {
        if (!$this->isSpaSurfaceActive($identifier)) {
            return false;
        }

        $manifest = $this->pluginService->readManifest($identifier) ?? [];
        if ($this->resolveDocumentEditorUiMode($identifier, $manifest) === WeappAdminUiService::MODE_IFRAME) {
            $editor = $this->pluginEditorSurfaceService->manifestEditor($identifier);

            return $editor !== [] && !empty($editor['enabled']);
        }

        return $this->spaComponentKey($identifier) !== '';
    }

    public function hasTab(string $identifier): bool
    {
        foreach ($this->listSpaBySlot(0, PluginEditorSurfaceService::SLOT_TAB) as $tab) {
            if (($tab['identifier'] ?? '') === $identifier) {
                return true;
            }
        }

        return false;
    }

    public function hasInline(string $identifier): bool
    {
        foreach ($this->listSpaBySlot(0, PluginEditorSurfaceService::SLOT_INLINE) as $row) {
            if (($row['identifier'] ?? '') === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSpaBySlot(int $documentId, string $slot): array
    {
        if (!InstallGate::isInstalled()) {
            return [];
        }

        $items = [];
        $rows  = Plugin::where('installed', 1)->where('enabled', 1)->order('id', 'asc')->select()->toArray();
        foreach ($rows as $row) {
            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            // L1 / 已并入内核：不得走 plugins 表扫描；文档 Tab 由 appendL1ProductSpaBySlot 等专用路径注入
            if ($this->pluginService->isPermanentKernelSurface($identifier)) {
                continue;
            }
            if (!$this->entitlementService->can($identifier)) {
                continue;
            }
            if (!$this->pluginTaxonomyService->visibleInDocumentEditor($identifier, $this->currentUserId())) {
                continue;
            }
            $item = $this->spaEditorItem($identifier, $slot, $documentId);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $this->appendL1ProductSpaBySlot($items, $documentId, $slot);

        usort($items, function (array $a, array $b) use ($slot): int {
            $orderA = $this->pluginEditorSurfaceService->resolveOrder($a['identifier'], $slot);
            $orderB = $this->pluginEditorSurfaceService->resolveOrder($b['identifier'], $slot);
            $cmp    = $orderA <=> $orderB;
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['identifier'], $b['identifier']);
        });

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function appendL1ProductSpaBySlot(array &$items, int $documentId, string $slot): void
    {
        foreach ($items as $item) {
            if (ProductL1Access::isKernel((string) ($item['identifier'] ?? ''))) {
                return;
            }
        }
        if (!ProductL1Access::allowsAdmin()) {
            return;
        }
        if (!$this->pluginTaxonomyService->visibleInDocumentEditor(ProductL1Access::IDENTIFIER, $this->currentUserId())) {
            return;
        }
        $item = $this->spaEditorItem(ProductL1Access::IDENTIFIER, $slot, $documentId);
        if ($item !== null) {
            $items[] = $item;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function spaEditorItem(string $identifier, string $expectedSlot, int $documentId): ?array
    {
        if (!$this->isSpaSurfaceActive($identifier)) {
            return null;
        }

        $editor = $this->pluginEditorSurfaceService->manifestEditor($identifier);
        if ($editor === [] || empty($editor['enabled'])) {
            return null;
        }

        if ($this->pluginEditorSurfaceService->resolveSlot($identifier) !== $expectedSlot) {
            return null;
        }

        $manifest  = $this->pluginService->readManifest($identifier) ?? [];
        $uiMode    = $this->resolveDocumentEditorUiMode($identifier, $manifest);
        $component = $uiMode === WeappAdminUiService::MODE_IFRAME
            ? 'iframe'
            : $this->spaComponentKey($identifier);
        if ($component === '') {
            return null;
        }

        $label = trim((string) ($editor['tab_label'] ?? ($manifest['name'] ?? $identifier)));
        if ($label === '') {
            $label = $identifier;
        }

        $payload = $this->spaPayload($identifier, $documentId);
        if ($uiMode === WeappAdminUiService::MODE_IFRAME) {
            $payload['iframe_src'] = app(WeappAdminUiService::class)->documentEditorIframeSrc(
                $identifier,
                $manifest,
                $documentId,
                $expectedSlot,
            );
        }

        return [
            'identifier' => $identifier,
            'label'      => $label,
            'slot'       => $expectedSlot,
            'component'  => $component,
            'ui_mode'    => $uiMode,
            'tab_order'  => $this->pluginEditorSurfaceService->resolveOrder($identifier, $expectedSlot),
            'tab_name'   => $identifier,
            'payload'    => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function resolveDocumentEditorUiMode(string $identifier, array $manifest): string
    {
        if (ProductL1Access::isKernel($identifier)) {
            return WeappAdminUiService::MODE_CORE_VUE;
        }

        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
        $editor   = is_array($surfaces['document_editor'] ?? null) ? $surfaces['document_editor'] : [];
        $forced   = strtolower(trim((string) ($editor['ui_mode'] ?? '')));
        if ($forced === WeappAdminUiService::MODE_IFRAME || $forced === WeappAdminUiService::MODE_CORE_VUE) {
            return $forced;
        }

        return app(WeappAdminUiService::class)->mode($manifest, $identifier);
    }

    private function isSpaSurfaceActive(string $identifier): bool
    {
        if (!$this->pluginEditorSurfaceService->isEnabledInEditor($identifier)) {
            return false;
        }

        if (ProductL1Access::isKernel($identifier)) {
            return app(DocumentProductFacade::class)->enabled();
        }

        return DocumentAddonBridgeAccess::isEnabled($identifier);
    }

    /**
     * @return array<string, mixed>
     */
    private function spaPayload(string $identifier, int $documentId): array
    {
        try {
            if (ProductL1Access::isKernel($identifier)) {
                return app(DocumentProductFacade::class)->documentEditorSpaPayload($documentId);
            }

            return DocumentAddonBridgeAccess::documentEditorSpaPayload($identifier, $documentId);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('plugin_document_editor_spa_payload_failed', [
                'identifier'  => $identifier,
                'document_id' => $documentId,
                'msg'         => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array{identifier:string,label:string,slot:string}>
     */
    public function listDraggableSurfaces(): array
    {
        $out = [];
        foreach ($this->listInlines() as $row) {
            $out[] = [
                'identifier' => (string) ($row['identifier'] ?? ''),
                'label'      => (string) ($row['label'] ?? ''),
                'slot'       => PluginEditorSurfaceService::SLOT_INLINE,
            ];
        }
        foreach ($this->listTabs() as $row) {
            $out[] = [
                'identifier' => (string) ($row['identifier'] ?? ''),
                'label'      => (string) ($row['label'] ?? ''),
                'slot'       => PluginEditorSurfaceService::SLOT_TAB,
            ];
        }

        return $out;
    }

    private function currentUserId(): int
    {
        $admin = Session::get('admin_user', []);

        return (int) ($admin['id'] ?? 0);
    }
}
