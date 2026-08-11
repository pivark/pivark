<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from UploadService — 场景登记与元数据
 */
declare(strict_types=1);

namespace app\common\service\upload;

use app\common\service\config\ConfigService;
use think\facade\Config;

class UploadSceneService
{

    /** @var array<string, array<string, mixed>> */
    private static array $runtimeScenes = [];

    /**
     * 插件安装时登记场景（示例：plugin.shop）
     *
     * @param array<string, mixed> $meta 需含 type
     */
    public function registerScene(string $scene, array $meta): void
    {
        $scene = self::normalizeScene($scene);
        if (!preg_match((string) Config::get('upload.plugin_scene_pattern', '/^plugin\./'), $scene)) {
            throw new \InvalidArgumentException('插件场景须匹配 plugin.{identifier}');
        }
        self::$runtimeScenes[$scene] = $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function sceneMeta(string $scene): array
    {
        $scene = self::normalizeScene($scene);
        $cfg   = self::sceneConfig($scene);
        if ($cfg === []) {
            return [];
        }
        $type = (string) ($cfg['type'] ?? 'image');
        $file = new UploadFileService($type, $scene);

        return array_merge($cfg, [
            'scene'              => $scene,
            'type'               => $type,
            'type_label'         => Config::get('upload.types.' . $type . '.label', $type),
            'allowed_extensions' => $file->allowedExtensions(),
            'max_size_mb'        => $file->maxSizeMb(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allScenes(): array
    {
        $builtin = Config::get('upload.scenes', []);
        $all     = is_array($builtin) ? $builtin : [];
        foreach (self::$runtimeScenes as $k => $v) {
            $all[$k] = array_merge(is_array($v) ? $v : [], ['scene' => $k]);
        }

        return $all;
    }

    public function uploadRouteForScene(string $scene): string
    {
        $meta = $this->sceneMeta($scene);

        return (string) ($meta['upload_route'] ?? '/admin/upload/image');
    }

    /**
     * @return array<string, mixed>
     */
    public static function sceneConfig(string $scene): array
    {
        $builtin = Config::get('upload.scenes.' . $scene, []);
        $runtime = self::$runtimeScenes[$scene] ?? [];
        if (!is_array($builtin)) {
            $builtin = [];
        }
        if ($builtin === [] && $runtime === []) {
            return [];
        }

        return array_merge($builtin, is_array($runtime) ? $runtime : []);
    }

    public static function assertScene(string $scene): void
    {
        if (self::sceneConfig($scene) !== []) {
            return;
        }
        $pattern = Config::get('upload.plugin_scene_pattern');
        if (is_string($pattern) && preg_match($pattern, $scene) === 1 && isset(self::$runtimeScenes[$scene])) {
            return;
        }
        throw new \InvalidArgumentException("未登记的上传场景: {$scene}");
    }

    public static function normalizeScene(string $scene): string
    {
        $scene = strtolower(trim($scene));
        if ($scene === '') {
            return 'general';
        }
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $scene)) {
            throw new \InvalidArgumentException('无效的上传场景标识');
        }

        return $scene;
    }
}
