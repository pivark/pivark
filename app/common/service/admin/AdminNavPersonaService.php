<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\user\PermissionService;

/**
 * 后台侧栏 Nav Persona（内核 interface + Registry 按需合并 pack/插件）
 */
class AdminNavPersonaService
{

    public const PERSONA_SYSTEM_ADMIN    = 'system_admin';
    public const PERSONA_EXECUTIVE       = 'executive';
    public const PERSONA_CONTENT_OPS     = 'content_ops';
    public const PERSONA_FACTORY_INTERNAL = 'factory_internal';

    public function resolve(int $userId): string
    {
        if ($userId < 1) {
            return self::PERSONA_CONTENT_OPS;
        }

        foreach ($this->resolvePriority() as $personaId) {
            if ($this->matchesPersona($userId, $personaId)) {
                return $personaId;
            }
        }

        return self::PERSONA_CONTENT_OPS;
    }

    /** @return array<string, mixed> */
    public function payload(int $userId): array
    {
        $personaId = $this->resolve($userId);
        $meta      = $this->personaMeta($personaId);

        return [
            'persona'     => $personaId,
            'label'       => (string) ($meta['label'] ?? $personaId),
            'description' => (string) ($meta['description'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $menus
     * @return list<array<string, mixed>>
     */
    public function filterFlatMenus(array $menus, int $userId): array
    {
        $persona = $this->resolve($userId);
        if ($persona === self::PERSONA_SYSTEM_ADMIN) {
            return $menus;
        }

        $rules = $this->rulesFor($persona);
        if ($rules === []) {
            return $menus;
        }

        $hiddenIds = [];
        $filtered  = [];

        foreach ($menus as $menu) {
            if ($this->shouldHideMenu($menu, $rules, $hiddenIds)) {
                $hiddenIds[(int) ($menu['id'] ?? 0)] = true;
                continue;
            }
            $filtered[] = $menu;
        }

        return $this->dropOrphanMenus($filtered, $hiddenIds);
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function filterMenuTree(array $tree, int $userId): array
    {
        $persona = $this->resolve($userId);
        if ($persona === self::PERSONA_SYSTEM_ADMIN) {
            return $tree;
        }

        return $this->pruneEmptyBranches($tree);
    }

    /** @return array<string, mixed> */
    public function documentEditorProfile(int $userId): array
    {
        $persona = $this->resolve($userId);
        $rules   = $this->documentEditorRules($persona);

        return array_merge([
            'persona' => $persona,
        ], $rules);
    }

    /** @return array<string, mixed> */
    public function itemAdminProfile(int $userId): array
    {
        $persona = $this->resolve($userId);
        $rules   = $this->itemAdminRules($persona);

        return array_merge([
            'persona' => $persona,
        ], $rules);
    }

    public function allowsExternalDocumentSurfaces(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        if ($this->resolve($userId) === self::PERSONA_SYSTEM_ADMIN) {
            return true;
        }

        return !empty($this->documentEditorRules($this->resolve($userId))['show_external_surfaces']);
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, array<string, mixed>>
     */
    public function applyItemUiModules(array $modules, int $userId): array
    {
        $profile = $this->itemAdminProfile($userId);
        $keep    = $profile['ui_modules_keep'] ?? null;
        if (!is_array($keep)) {
            return $modules;
        }

        foreach ($modules as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!in_array((string) $key, $keep, true)) {
                $modules[$key]['visible'] = false;
            }
        }

        return $modules;
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, array<string, mixed>>
     */
    public function filterItemCapabilityModules(array $modules, int $userId): array
    {
        $allowed = $this->itemAdminProfile($userId)['capability_flags'] ?? null;
        if (!is_array($allowed)) {
            return $modules;
        }

        foreach ($modules as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!in_array((string) $key, $allowed, true)) {
                $modules[$key]['visible'] = false;
            }
        }

        return $modules;
    }

    /**
     * @param array<string, array<string, mixed>> $baseHints
     * @return array<string, array<string, mixed>>
     */
    public function mergeItemFormFieldHints(array $baseHints, int $userId): array
    {
        $style = (string) ($this->itemAdminProfile($userId)['field_hints_style'] ?? 'default');
        if ($style === '' || $style === 'default') {
            return $baseHints;
        }

        $overrides = app(AdminNavPersonaRegistry::class)->get(['item_field_hint_styles', $style], []);
        if (!is_array($overrides) || $overrides === []) {
            return $baseHints;
        }

        foreach ($overrides as $field => $patch) {
            if (!is_array($patch)) {
                continue;
            }
            $base = is_array($baseHints[$field] ?? null) ? $baseHints[$field] : [];
            $baseHints[$field] = array_merge($base, $patch);
        }

        return $baseHints;
    }

    public function itemFormReadOnly(int $userId): bool
    {
        return !empty($this->itemAdminProfile($userId)['read_only_form']);
    }

    public function itemListColumnVisible(int $userId, string $column): bool
    {
        $columns = $this->itemAdminProfile($userId)['list_columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return true;
        }

        return in_array($column, $columns, true);
    }

    public function itemFormFieldVisible(int $userId, string $field): bool
    {
        $fields = $this->itemAdminProfile($userId)['form_fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            return true;
        }

        return in_array($field, $fields, true);
    }

    /** @return array<string, bool> */
    private function documentEditorRules(string $persona): array
    {
        $defaults = [
            'show_seo_tab'           => true,
            'show_more_tab'          => true,
            'show_external_surfaces' => true,
            'show_item_picker'       => true,
        ];
        $cfg = app(AdminNavPersonaRegistry::class)->get(['document_editor', $persona], []);
        if (!is_array($cfg)) {
            $cfg = [];
        }

        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = array_key_exists($key, $cfg) ? (bool) $cfg[$key] : $default;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function itemAdminRules(string $persona): array
    {
        $fallback = app(AdminNavPersonaRegistry::class)->get(['item_admin', 'system_admin'], []);
        $cfg      = app(AdminNavPersonaRegistry::class)->get(['item_admin', $persona], []);
        if (!is_array($fallback)) {
            $fallback = [];
        }
        if (!is_array($cfg)) {
            $cfg = [];
        }

        return array_merge($fallback, $cfg);
    }

    /** @return list<string> */
    private function resolvePriority(): array
    {
        $list = app(AdminNavPersonaRegistry::class)->get(['resolve_priority'], []);
        if (!is_array($list) || $list === []) {
            return [
                self::PERSONA_SYSTEM_ADMIN,
                self::PERSONA_EXECUTIVE,
                self::PERSONA_CONTENT_OPS,
                self::PERSONA_FACTORY_INTERNAL,
            ];
        }

        return array_values(array_map(static fn ($v): string => trim((string) $v), $list));
    }

    /** @return array<string, mixed> */
    private function personaMeta(string $personaId): array
    {
        $all = app(AdminNavPersonaRegistry::class)->get(['personas'], []);
        if (!is_array($all)) {
            return [];
        }
        $row = $all[$personaId] ?? [];

        return is_array($row) ? $row : [];
    }

    /** @return array<string, mixed> */
    private function rulesFor(string $personaId): array
    {
        $all = app(AdminNavPersonaRegistry::class)->get(['rules'], []);
        if (!is_array($all)) {
            return [];
        }
        $rules = $all[$personaId] ?? [];

        return is_array($rules) ? $rules : [];
    }

    /** @return array<string, int> */
    private function dynamicGroupRoots(): array
    {
        $roots = app(AdminNavPersonaRegistry::class)->get(['dynamic_group_roots'], []);
        if (!is_array($roots)) {
            return [
                'enterprise'      => -96000,
                'market_commerce' => -96600,
            ];
        }

        $out = [];
        foreach ($roots as $key => $id) {
            $out[(string) $key] = (int) $id;
        }

        return $out;
    }

    private function matchesPersona(int $userId, string $personaId): bool
    {
        $signals = app(AdminNavPersonaRegistry::class)->get(['resolve', $personaId], []);
        if (!is_array($signals) || $signals === []) {
            return false;
        }

        if (!empty($signals['super_admin']) && $this->permissionService->isSuperAdmin($userId)) {
            return true;
        }

        $roleCodes = $this->permissionService->getRoleCodes($userId);
        foreach ($signals['role_codes'] ?? [] as $code) {
            if ($code !== '' && in_array((string) $code, $roleCodes, true)) {
                return true;
            }
        }

        $permCodes = $this->permissionService->getPermissionCodes($userId);
        if (in_array('*', $permCodes, true)) {
            return $personaId === self::PERSONA_SYSTEM_ADMIN;
        }

        foreach ($signals['none_permissions'] ?? [] as $denyCode) {
            if ($denyCode !== '' && $this->permissionService->can($userId, (string) $denyCode)) {
                return false;
            }
        }

        foreach ($signals['any_permissions'] ?? [] as $permCode) {
            if ($permCode !== '' && $this->permissionService->can($userId, (string) $permCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $menu
     * @param array<string, mixed> $rules
     * @param array<int, bool>     $hiddenIds
     */
    private function shouldHideMenu(array $menu, array $rules, array $hiddenIds): bool
    {
        $id       = (int) ($menu['id'] ?? 0);
        $parentId = (int) ($menu['parent_id'] ?? 0);
        $route    = (string) ($menu['route'] ?? '');

        if ($parentId !== 0 && isset($hiddenIds[$parentId])) {
            return true;
        }

        if ($this->isHiddenDynamicMenu($id, $parentId, $rules)) {
            return true;
        }

        $hideIds = $rules['hide_menu_ids'] ?? [];
        if (is_array($hideIds) && in_array($id, $hideIds, true)) {
            return true;
        }

        $hidePluginChildren = $rules['hide_plugin_center_child_ids'] ?? [];
        if (is_array($hidePluginChildren) && $parentId === 16 && in_array($id, $hidePluginChildren, true)) {
            return true;
        }

        if ($parentId === 10) {
            $allowed = $rules['digital_asset_menu_ids'] ?? null;
            if (is_array($allowed) && $allowed !== [] && !in_array($id, $allowed, true)) {
                return true;
            }
        }

        foreach ($rules['hide_route_prefixes'] ?? [] as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && str_starts_with($route, $prefix)) {
                return true;
            }
        }

        $hideShortcuts = $rules['hide_kernel_shortcut_routes'] ?? [];
        if (is_array($hideShortcuts) && !empty($menu['kernel_shortcut'])) {
            foreach ($hideShortcuts as $shortcutRoute) {
                if ($shortcutRoute !== '' && $route === (string) $shortcutRoute) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $rules */
    private function isHiddenDynamicMenu(int $id, int $parentId, array $rules): bool
    {
        $hideGroups = $rules['hide_dynamic_groups'] ?? [];
        if (!is_array($hideGroups) || $hideGroups === []) {
            return false;
        }

        $roots = $this->dynamicGroupRoots();
        foreach ($hideGroups as $groupKey) {
            $rootId = (int) ($roots[(string) $groupKey] ?? 0);
            if ($rootId === 0) {
                continue;
            }
            if ($id === $rootId || $parentId === $rootId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $menus
     * @param array<int, bool>           $hiddenIds
     * @return list<array<string, mixed>>
     */
    private function dropOrphanMenus(array $menus, array $hiddenIds): array
    {
        $present = [];
        foreach ($menus as $menu) {
            $present[(int) ($menu['id'] ?? 0)] = true;
        }

        $out = [];
        foreach ($menus as $menu) {
            $parentId = (int) ($menu['parent_id'] ?? 0);
            if ($parentId > 0 && !isset($present[$parentId])) {
                continue;
            }
            $out[] = $menu;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function pruneEmptyBranches(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $children = [];
            if (!empty($node['children']) && is_array($node['children'])) {
                $children = $this->pruneEmptyBranches($node['children']);
            }
            $route = (string) ($node['route'] ?? '');
            if ($route === '' && $children === []) {
                continue;
            }
            $node['children'] = $children;
            $out[]            = $node;
        }

        return $out;
    }

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {
    }
}
