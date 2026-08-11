<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\weapp\WeappPluginDocNavService;

/** 官方 weapp 后台 Tab（插件 boot 注册 · 替代 admin WEAPP_ADMIN_TABS 静态表） */
final class WeappAdminNavRegistry
{

    /** @var array<string, list<array{key:string,title:string,segment:string}>> */
    private array $tabsByPlugin = [];

    public function reset(): void
    {
        $this->tabsByPlugin = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return;
        }
        unset($this->tabsByPlugin[$identifier]);
    }

    /**
     * @param list<array{key:string,title:string,segment:string}> $tabs
     */
    public function register(string $identifier, array $tabs): void
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return;
        }
        $this->tabsByPlugin[$identifier] = $tabs;
    }

    /**
     * @return list<array{key:string,title:string,to:string}>
     */
    public function tabsForAdmin(string $identifier): array
    {
        $identifier = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($identifier))) ?? '';
        if ($identifier === '') {
            return [];
        }

        $manifest = app(PluginService::class)->readManifest($identifier) ?? [];
        $docFlags = app(WeappPluginDocNavService::class)->tabsForAdmin($identifier, $manifest);

        $out = [];
        foreach ($this->tabsByPlugin[$identifier] ?? [] as $tab) {
            $key = (string) ($tab['key'] ?? '');
            $segment = (string) ($tab['segment'] ?? '');
            if ($key === '' || $segment === '') {
                continue;
            }
            $out[] = [
                'key'   => $key,
                'title' => (string) ($tab['title'] ?? $key),
                'to'    => self::tabTo($identifier, $segment),
            ];
        }

        if ($docFlags['guide'] ?? false) {
            $out[] = self::docTab($identifier, 'guide', '功能介绍', 'guide');
        }
        if ($docFlags['usage'] ?? false) {
            $out[] = self::docTab($identifier, 'usage', '前台调用说明', 'usage');
        }
        if ($docFlags['changelog'] ?? false) {
            $out[] = self::docTab($identifier, 'changelog', '升级日志', 'changelog');
        }

        return $out;
    }

    /**
     * @return array{key:string,title:string,segment:string}
     */
    public static function tab(string $key, string $title, string $segment): array
    {
        return ['key' => $key, 'title' => $title, 'segment' => $segment];
    }

    /**
     * @return array{key:string,title:string,to:string}
     */
    private static function docTab(string $plugin, string $key, string $title, string $segment): array
    {
        return [
            'key'   => $key,
            'title' => $title,
            'to'    => self::tabTo($plugin, $segment),
        ];
    }

    private static function tabTo(string $identifier, string $segment): string
    {
        $segment = trim($segment, '/');

        return WeappAdminSpaHostRoutes::hostPath($identifier, $segment !== '' ? $segment : 'settings');
    }
}
