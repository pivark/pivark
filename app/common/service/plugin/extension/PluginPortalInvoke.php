<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\service\plugin\boot\PluginBootService;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\support\ProjectPaths;

/**
 * 受限发行插件 portal 服务软调用。
 * 仅允许 weapp/{id}/service/portal/{ShortClass}.php；禁止内核拼任意 FQCN。
 */
final class PluginPortalInvoke
{
    public static function hostIdentifier(): ?string
    {
        $ids = PluginDistributionPolicy::identifiers();

        return $ids[0] ?? null;
    }

    public static function ensureHostAutoload(): void
    {
        $id = self::hostIdentifier();
        if ($id === null || $id === '') {
            return;
        }
        PluginBootService::registerAutoloadPublic($id);
    }

    public static function portalFqcn(string $shortClass): ?string
    {
        $shortClass = ltrim($shortClass, '\\');
        if ($shortClass === '') {
            return null;
        }
        if (str_starts_with($shortClass, 'weapp\\')) {
            return null;
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $shortClass)) {
            return null;
        }
        $id = self::hostIdentifier();
        if ($id === null) {
            return null;
        }
        $pluginDir = str_replace('-', '_', $id);
        $portalFile = ProjectPaths::root() . 'weapp/' . $pluginDir . '/service/portal/' . $shortClass . '.php';
        if (!is_file($portalFile)) {
            return null;
        }
        $fqcn = 'weapp\\' . $pluginDir . '\\service\\portal\\' . $shortClass;
        self::ensureHostAutoload();

        return class_exists($fqcn) ? $fqcn : null;
    }

    public static function portalClassExists(string $shortClass): bool
    {
        return self::portalFqcn($shortClass) !== null;
    }

    public static function portalFileExists(string $shortClass): bool
    {
        $id = self::hostIdentifier();
        if ($id === null || $shortClass === '') {
            return false;
        }

        return is_file(
            ProjectPaths::root() . 'weapp/' . $id . '/service/portal/' . ltrim($shortClass, '\\') . '.php'
        );
    }

    public static function portalDi(string $shortClass): ?object
    {
        $fqcn = self::portalFqcn($shortClass);
        if ($fqcn === null || !class_exists($fqcn)) {
            return null;
        }

        return app($fqcn);
    }

    /**
     * @param list<mixed> $args
     */
    public static function portalInvoke(string $shortClass, string $method, array $args = []): mixed
    {
        $instance = self::portalDi($shortClass);
        if ($instance === null || !method_exists($instance, $method)) {
            return null;
        }

        return $instance->{$method}(...$args);
    }

    /** @return class-string|null */
    public static function portalConstClass(string $shortClass, string $const): mixed
    {
        $fqcn = self::portalFqcn($shortClass);
        if ($fqcn === null || !defined($fqcn . '::' . $const)) {
            return null;
        }

        return constant($fqcn . '::' . $const);
    }
}
