<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\theme;

use app\common\support\ExtendBootstrap;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;

/**
 * 主题 data/*.php 与 extend::diy_* 供数（theme.json provider SSOT）
 */
final class ThemeDataProviderService
{

    public function __construct(
        private readonly ThemeService $themeService,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function resolve(string $provider, array $context = [], ?string $themeId = null): array
    {
        $provider = trim($provider);
        if ($provider === '') {
            return [];
        }

        if (str_starts_with($provider, 'extend::')) {
            $function = trim(substr($provider, 8));

            return $function !== '' && str_starts_with($function, 'diy_')
                ? ExtendBootstrap::invokeDiyTemplateVars($function, $context)
                : [];
        }

        return $this->loadThemeDataFile($provider, $context, $themeId);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function loadThemeDataFile(string $relative, array $context = [], ?string $themeId = null): array
    {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return [];
        }
        if (!preg_match('#^data/[a-z0-9][a-z0-9_/.-]*\\.php$#i', $relative)) {
            return [];
        }

        $themeId = $themeId ?? $this->themeService->getCurrentTheme();
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $themeId)) {
            return [];
        }

        $themeRoot = realpath(ProjectPaths::root() . 'template/' . $themeId);
        if ($themeRoot === false || !is_dir($themeRoot)) {
            return [];
        }

        $file = $themeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($file);
        $dataRoot = realpath($themeRoot . DIRECTORY_SEPARATOR . 'data');
        if ($real === false || $dataRoot === false || !is_file($real) || !str_starts_with($real, $dataRoot . DIRECTORY_SEPARATOR)) {
            return [];
        }

        try {
            /** @var mixed $result */
            $result = (static function (string $path, array $ctx): mixed {
                $context = $ctx;

                return include $path;
            })($real, $context);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('theme_data_provider_load_failed', [
                'file' => $relative,
                'theme' => $themeId,
                'msg'   => $e->getMessage(),
            ]);

            return [];
        }

        return is_array($result) ? $result : [];
    }
}
