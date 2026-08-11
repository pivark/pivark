<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);
namespace app\common\service\config;
use app\common\model\Config;
final class ConfigGroupService {
    private const PREFIX = 'config_group_';
    public function groupMap(): array {
        $map = config('pivark.config_groups');
        if (!is_array($map)) return [];
        $out = [];
        foreach ($map as $group => $keys) {
            if (!is_string($group) || !is_array($keys)) continue;
            $out[$group] = array_values(array_filter($keys, fn($k) => is_string($k) && $k !== ''));
        }
        return $out;
    }
    public function storageKey(string $group): string {
        return self::PREFIX . preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim($group)));
    }
    public function buildGroupFromFlat(string $group): array {
        $keys = $this->groupMap()[$group] ?? [];
        if ($keys === []) return [];
        $flat = Config::getAll(); $out = [];
        foreach ($keys as $key) if (array_key_exists($key, $flat)) $out[$key] = $flat[$key];
        return $out;
    }
    public function rebuildAll(): int {
        $n = 0; foreach (array_keys($this->groupMap()) as $g) if ($this->rebuildGroup($g)) $n++; return $n;
    }
    public function rebuildGroup(string $group): bool {
        $payload = $this->buildGroupFromFlat($group);
        if ($payload === []) return false;
        Config::setValue($this->storageKey($group), json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return true;
    }
    public function syncGroupsForFlatPatch(array $flatPatch): void {
        if ($flatPatch === []) return;
        foreach ($this->groupMap() as $group => $keys) {
            foreach ($keys as $key) if (array_key_exists($key, $flatPatch)) { $this->rebuildGroup($group); break; }
        }
    }
}