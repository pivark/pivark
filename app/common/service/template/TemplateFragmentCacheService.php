<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;
use app\common\support\OpsLog;

use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\theme\ThemeService;
use think\facade\Cache;

/** {pv:cache} 片段缓存（按 name + 主题 + 前台缓存代际） */
final class TemplateFragmentCacheService
{

    public function __construct(
        private readonly ThemeService $theme,
        private readonly FrontCacheInvalidator $cacheInvalidator,
    ) {
    }

    private const PREFIX     = 'pv_tpl_frag_';
    private const INDEX_KEY  = 'pv_tpl_frag_index';

    public function defaultTtl(): int
    {
        return max(0, (int) config('pivark.tpl_fragment_cache_ttl', 3600));
    }

    /**
     * @param callable(): string $loader
     */
    public function remember(string $name, string $theme, int $ttl, callable $loader): string
    {
        $name  = $this->normalizeName($name);
        $theme = trim($theme) !== '' ? trim($theme) : 'default';
        $ttl   = $ttl > 0 ? $ttl : $this->defaultTtl();
        if ($name === '' || $ttl <= 0) {
            return $loader();
        }

        $key = $this->key($name, $theme);
        $hit = Cache::get($key);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $html = $loader();
        if ($html !== '') {
            Cache::set($key, $html, $ttl);
            $this->trackName($name, $theme);
        }

        return $html;
    }

    public function forget(string $name, ?string $theme = null): void
    {
        $this->forgetNames([$name], $theme !== null ? [$theme] : null);
    }

    /**
     * @param list<string> $names
     * @param list<string>|null $themes null 时按索引或 default/current 主题
     */
    public function forgetNames(array $names, ?array $themes = null): void
    {
        $index = $this->readIndex();
        foreach ($names as $raw) {
            $name = $this->normalizeName($raw);
            if ($name === '') {
                continue;
            }
            $targetThemes = $themes;
            if ($targetThemes === null || $targetThemes === []) {
                $targetThemes = isset($index[$name]) && is_array($index[$name])
                    ? array_keys($index[$name])
                    : $this->fallbackThemes();
            }
            foreach ($targetThemes as $theme) {
                Cache::delete($this->key($name, (string) $theme));
            }
            unset($index[$name]);
        }
        $this->writeIndex($index);
    }

    /** @return list<string> */
    private function fallbackThemes(): array
    {
        $themes = ['default'];
        try {
            $current = $this->theme->getCurrentTheme();
            if ($current !== '' && !in_array($current, $themes, true)) {
                $themes[] = $current;
            }
        } catch (\Throwable $e) {
            OpsLog::businessWarning('tpl_fragment_cache_fallback_themes_failed', [
                'msg' => $e->getMessage(),
            ]);
        }

        return $themes;
    }

    private function trackName(string $name, string $theme): void
    {
        $index = $this->readIndex();
        if (!isset($index[$name]) || !is_array($index[$name])) {
            $index[$name] = [];
        }
        $index[$name][$theme] = true;
        $this->writeIndex($index);
    }

    /** @return array<string, array<string, bool>> */
    private function readIndex(): array
    {
        $index = Cache::get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /** @param array<string, array<string, bool>> $index */
    private function writeIndex(array $index): void
    {
        Cache::set(self::INDEX_KEY, $index, 86400 * 30);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return preg_replace('/[^a-z0-9_\-]/i', '_', $name) ?? $name;
    }

    private function key(string $name, string $theme): string
    {
        return self::PREFIX . 'g' . $this->cacheInvalidator->generation() . '_' . $theme . '_' . $name;
    }
}
