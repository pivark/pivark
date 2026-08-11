<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\model\Plugin;
use think\facade\Config;

/**
 * Nav Persona 运行时注册表：内核 interface_only + 可选 pack / 已装插件 config 合并。
 */
final class AdminNavPersonaRegistry
{

    /** @var array<string, mixed>|null */
    private ?array $merged = null;

    public function bustCache(): void
    {
        $this->merged = null;
    }

    /**
     * @param list<string> $path 点分路径，如 ['item_admin','content_ops']
     * @return mixed
     */
    public function get(array $path, mixed $default = null): mixed
    {
        $node = $this->mergedConfig();
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    public function mergedConfig(): array
    {
        if (is_array($this->merged)) {
            return $this->merged;
        }

        $base = Config::get('admin.nav_persona', []);
        if (!is_array($base) || $base === []) {
            // 兼容旧 key（若有遗留自定义加载）
            $legacy = Config::get('admin_nav_persona', []);
            $base = is_array($legacy) ? $legacy : [];
        }

        $overlays = [];
        $root     = dirname(__DIR__, 4);
        $packPath = $root . '/config/enterprise/nav_persona_pack.php';
        if (is_readable($packPath)) {
            /** @var array<string, mixed> $pack */
            $pack = require $packPath;
            if (is_array($pack)) {
                $overlays[] = $pack;
            }
        }

        foreach ($this->installedPluginIdentifiers() as $identifier) {
            $pluginPath = $root . '/weapp/' . $identifier . '/config/nav_persona_pack.php';
            if (!is_readable($pluginPath)) {
                continue;
            }
            /** @var array<string, mixed> $chunk */
            $chunk = require $pluginPath;
            if (is_array($chunk)) {
                $overlays[] = $chunk;
            }
        }

        $merged = $base;
        foreach ($overlays as $overlay) {
            $merged = $this->mergeOverlay($merged, $overlay);
        }

        $this->merged = $merged;

        return $merged;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function mergeOverlay(array $base, array $overlay): array
    {
        if (isset($overlay['resolve_priority']) && is_array($overlay['resolve_priority'])) {
            $base['resolve_priority'] = array_values(array_map(
                static fn ($v): string => trim((string) $v),
                $overlay['resolve_priority']
            ));
            unset($overlay['resolve_priority']);
        } elseif (isset($overlay['resolve_priority_append']) && is_array($overlay['resolve_priority_append'])) {
            $current = is_array($base['resolve_priority'] ?? null) ? $base['resolve_priority'] : [];
            foreach ($overlay['resolve_priority_append'] as $personaId) {
                $personaId = trim((string) $personaId);
                if ($personaId !== '' && !in_array($personaId, $current, true)) {
                    $current[] = $personaId;
                }
            }
            $base['resolve_priority'] = $current;
            unset($overlay['resolve_priority_append']);
        }

        foreach (['personas', 'resolve', 'rules', 'document_editor', 'item_admin'] as $section) {
            if (!isset($overlay[$section]) || !is_array($overlay[$section])) {
                continue;
            }
            $baseSection = is_array($base[$section] ?? null) ? $base[$section] : [];
            foreach ($overlay[$section] as $key => $value) {
                if (!is_array($value)) {
                    $baseSection[$key] = $value;
                    continue;
                }
                $existing = is_array($baseSection[$key] ?? null) ? $baseSection[$key] : [];
                $baseSection[$key] = $this->mergePersonaSection($existing, $value);
            }
            $base[$section] = $baseSection;
            unset($overlay[$section]);
        }

        if (isset($overlay['item_field_hint_styles']) && is_array($overlay['item_field_hint_styles'])) {
            $styles = is_array($base['item_field_hint_styles'] ?? null) ? $base['item_field_hint_styles'] : [];
            foreach ($overlay['item_field_hint_styles'] as $style => $fields) {
                if (!is_array($fields)) {
                    continue;
                }
                $styleBase = is_array($styles[$style] ?? null) ? $styles[$style] : [];
                foreach ($fields as $field => $patch) {
                    if (!is_array($patch)) {
                        continue;
                    }
                    $fieldBase = is_array($styleBase[$field] ?? null) ? $styleBase[$field] : [];
                    $styleBase[$field] = array_merge($fieldBase, $patch);
                }
                $styles[$style] = $styleBase;
            }
            $base['item_field_hint_styles'] = $styles;
            unset($overlay['item_field_hint_styles']);
        }

        if (isset($overlay['dynamic_group_roots']) && is_array($overlay['dynamic_group_roots'])) {
            $roots = is_array($base['dynamic_group_roots'] ?? null) ? $base['dynamic_group_roots'] : [];
            $base['dynamic_group_roots'] = array_merge($roots, $overlay['dynamic_group_roots']);
            unset($overlay['dynamic_group_roots']);
        }

        return array_merge($base, $overlay);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function mergePersonaSection(array $base, array $patch): array
    {
        $listKeys = [
            'hide_menu_ids',
            'hide_plugin_center_child_ids',
            'hide_route_prefixes',
            'hide_kernel_shortcut_routes',
            'hide_dynamic_groups',
            'digital_asset_menu_ids',
            'list_columns',
            'form_fields',
            'capability_flags',
            'ui_modules_keep',
            'role_codes',
            'any_permissions',
            'none_permissions',
        ];

        foreach ($listKeys as $key) {
            if (!isset($patch[$key]) || !is_array($patch[$key])) {
                continue;
            }
            $existing = is_array($base[$key] ?? null) ? $base[$key] : [];
            $base[$key] = array_values(array_unique(array_merge($existing, $patch[$key])));
            unset($patch[$key]);
        }

        return array_merge($base, $patch);
    }

    /** @return list<string> */
    private function installedPluginIdentifiers(): array
    {
        $ids = Plugin::where('installed', 1)->column('identifier');
        $out = [];
        foreach ($ids as $raw) {
            $id = strtolower(trim((string) $raw));
            if ($id !== '') {
                $out[] = $id;
            }
        }
        sort($out);

        return $out;
    }
}
