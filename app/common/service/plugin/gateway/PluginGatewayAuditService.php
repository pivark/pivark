<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\gateway;

use app\common\service\plugin\PluginService;
use app\common\service\plugin\encode\PluginEncodedLoader;
use app\common\service\plugin\manifest\PluginDistributionPolicy;

final class PluginGatewayAuditService
{
    public function __construct(
        private readonly PluginService $plugins,
    ) {
    }

    /** host_only 插件：Gateway 审包改走 direct core import 校验 */
    private function isGatewayAuditExempt(string $identifier): bool
    {
        return PluginDistributionPolicy::isHostOnly($identifier);
    }

    /** @var list<string> service/api/admin 层白名单 */
    private const ALLOWED_FQCN = [
        'app\\common\\service\\plugin\\weapp\\WeappPluginSaveSupport',
        'app\\common\\service\\plugin\\registry\\PluginRouteService',
        'app\\common\\service\\plugin\\encode\\PluginEncodedLoader',
        'app\\common\\service\\template\\TemplateTagParser',
    ];

    /** @var list<string> 第三方 Plugin.php boot 允许直引（官方应走 Gateway） */
    private const BOOT_ALLOWED_FQCN = [
        'app\\common\\service\\plugin\\entitlement\\EntitlementService',
        'app\\common\\service\\plugin\\registry\\PluginApiRegistry',
        'app\\common\\service\\plugin\\registry\\PluginRouteService',
        'app\\common\\service\\template\\TemplateEngine',
        'app\\common\\service\\hook\\HookService',
        'app\\common\\service\\kernel\\KernelModuleRegistry',
        'app\\common\\service\\channel\\MiniprogramChannelService',
    ];

    /** @var list<string> 已删除 mega facade，禁止插件再引用 */
    private const FORBIDDEN_FQCN = [
        'app\\common\\service\\weapp\\WeappCoreGateway',
    ];

    /** @return list<string> */
    public function allowedFqcn(): array
    {
        $base = array_merge(self::ALLOWED_FQCN, self::weappSubGatewayFqcn());
        if (!function_exists('config')) {
            return $base;
        }

        $extra = config('plugin.security.gateway_allowed_imports');
        if (!is_array($extra)) {
            return $base;
        }

        return array_values(array_unique(array_merge(
            $base,
            array_map(static fn ($v): string => trim((string) $v), $extra)
        )));
    }

    /** @return list<string> */
    private static function weappSubGatewayFqcn(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $root = dirname(__DIR__, 3);
        $out  = [];
        foreach (glob($root . '/service/weapp/Weapp*Gateway.php') ?: [] as $path) {
            $short = basename($path, '.php');
            if ($short === 'WeappCoreGateway') {
                continue;
            }
            $out[] = 'app\\common\\service\\weapp\\' . $short;
        }
        sort($out);

        return $cache = $out;
    }

    /** @return list<string> */
    public function bootAllowedFqcn(): array
    {
        if (!function_exists('config')) {
            return self::BOOT_ALLOWED_FQCN;
        }

        $extra = config('plugin.security.gateway_boot_allowed_imports');
        if (!is_array($extra)) {
            return self::BOOT_ALLOWED_FQCN;
        }

        return array_values(array_unique(array_merge(
            self::BOOT_ALLOWED_FQCN,
            array_map(static fn ($v): string => trim((string) $v), $extra)
        )));
    }

    public function isBootRelativePath(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);

        return str_ends_with($path, '/Plugin.php') || $path === 'Plugin.php';
    }

    /**
     * @return list<string> 当前路径生效的白名单 FQCN
     */
    public function effectiveAllowedFqcn(string $relativePath): array
    {
        if ($this->isBootRelativePath($relativePath)) {
            return array_values(array_unique(array_merge($this->allowedFqcn(), $this->bootAllowedFqcn())));
        }

        return $this->allowedFqcn();
    }

    /**
     * @return list<string> 违规说明
     */
    public function scanPhpContent(string $relativePath, string $body): array
    {
        if (app(PluginEncodedLoader::class)->isStubBody($body)) {
            return [];
        }

        $violations = [];
        $allowed    = array_flip($this->effectiveAllowedFqcn($relativePath));

        if (preg_match_all('/^use\s+([^;]+);/m', $body, $matches)) {
            foreach ($matches[1] as $raw) {
                $fqcn = trim((string) $raw);
                if ($this->isForbiddenFqcn($fqcn)) {
                    $violations[] = sprintf(
                        '禁止 use %s（请改用官方插件接口访问系统能力）@ %s',
                        $fqcn,
                        $relativePath
                    );
                    continue;
                }
                $msg  = $this->checkFqcn($fqcn, $relativePath, 'use');
                if ($msg !== null && !isset($allowed[$fqcn])) {
                    $violations[] = $msg;
                }
            }
        }

        if (preg_match_all('/\b(app\\\\common\\\\service\\\\[A-Za-z0-9_\\\\]+)/', $body, $inline)) {
            foreach ($inline[1] as $fqcn) {
                $fqcn = trim((string) $fqcn);
                if ($this->isForbiddenFqcn($fqcn)) {
                    $violations[] = sprintf(
                        '禁止 inline %s（请改用官方插件接口访问系统能力）@ %s',
                        $fqcn,
                        $relativePath
                    );
                    continue;
                }
                if (isset($allowed[$fqcn])) {
                    continue;
                }
                if (!str_starts_with($fqcn, 'app\\common\\service\\')) {
                    continue;
                }
                $msg = $this->checkFqcn($fqcn, $relativePath, 'inline');
                if ($msg !== null) {
                    $violations[] = $msg;
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * zip 审包：扫描包内 PHP 的 Gateway 直引违规
     *
     * @return array{
     *   ok:bool,
     *   identifier:string,
     *   violations:list<array{file:string,messages:list<string>}>,
     *   total:int
     * }
     */
    public function auditZipArchive(\ZipArchive $zip, string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->isGatewayAuditExempt($identifier)) {
            return [
                'ok'         => true,
                'identifier' => $identifier,
                'violations' => [],
                'total'      => 0,
            ];
        }

        $prefix     = $identifier . '/';
        $violations = [];
        $total      = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($entry === '' || str_ends_with($entry, '/') || !preg_match('/\.php$/i', $entry)) {
                continue;
            }
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }

            $body = $zip->getFromIndex($i);
            if (!is_string($body)) {
                continue;
            }

            $relative = substr($entry, strlen($prefix));
            $messages = $this->scanPhpContent($relative, $body);
            if ($messages === []) {
                continue;
            }

            $total += count($messages);
            $violations[] = [
                'file'     => $entry,
                'messages' => $messages,
            ];
        }

        return [
            'ok'         => $total === 0,
            'identifier' => $identifier,
            'violations' => $violations,
            'total'      => $total,
        ];
    }

    /**
     * @param string|bool $scope service|api|admin|boot|all；bool 兼容旧版 true=service false=all
     * @return array{
     *   ok:bool,
     *   identifier:string,
     *   scope:string,
     *   violations:list<array{file:string,messages:list<string>}>,
     *   total:int
     * }
     */
    public function auditPluginDirectory(string $identifier, string|bool $scope = 'service'): array
    {
        $identifier = strtolower(trim($identifier));
        $scope      = $this->normalizeScope($scope);
        if ($identifier === '' || $this->isGatewayAuditExempt($identifier)) {
            return [
                'ok'         => true,
                'identifier' => $identifier,
                'scope'      => $scope,
                'violations' => [],
                'total'      => 0,
            ];
        }

        $root       = $this->plugins->weappRoot() . $identifier;
        if (!is_dir($root)) {
            return [
                'ok'         => true,
                'identifier' => $identifier,
                'scope'      => $scope,
                'violations' => [],
                'total'      => 0,
            ];
        }

        if ($scope === 'boot') {
            return $this->auditPluginBootFile($identifier, $root);
        }

        $scanRoot = $this->resolveScanRoot($root, $scope);
        if ($scanRoot === null || !is_dir($scanRoot)) {
            return [
                'ok'         => true,
                'identifier' => $identifier,
                'scope'      => $scope,
                'violations' => [],
                'total'      => 0,
            ];
        }

        $violations = [];
        $total      = 0;
        $rootNorm   = rtrim(str_replace('\\', '/', $root), '/');
        foreach ($this->iterPhpFiles($scanRoot) as $file) {
            $fileNorm = str_replace('\\', '/', $file);
            $rel      = $identifier . '/' . ltrim(substr($fileNorm, strlen($rootNorm) + 1), '/');
            $body     = (string) file_get_contents($file);
            $msgs     = $this->scanPhpContent($rel, $body);
            if ($msgs === []) {
                continue;
            }
            $total += count($msgs);
            $violations[] = ['file' => $rel, 'messages' => $msgs];
        }

        return [
            'ok'         => $total === 0,
            'identifier' => $identifier,
            'scope'      => $scope,
            'violations' => $violations,
            'total'      => $total,
        ];
    }

    /**
     * @param list<string>|string|bool $scope
     * @return list<array{identifier:string,ok:bool,scope:string,total:int,violations:list<array{file:string,messages:list<string>}>}>
     */
    public function auditInstalledPlugins(array|string|bool $scope = 'service'): array
    {
        $scopes = $this->normalizeScopes($scope);
        $out    = [];
        foreach ($this->plugins->listInstalledIdentifiers() as $id) {
            foreach ($scopes as $one) {
                $report = $this->auditPluginDirectory($id, $one);
                if ($report['total'] === 0 && count($scopes) > 1) {
                    continue;
                }
                $out[] = [
                    'identifier' => $id,
                    'ok'         => $report['ok'],
                    'scope'      => $report['scope'],
                    'total'      => $report['total'],
                    'violations' => $report['violations'],
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    /**
     * @param array<mixed>|string|bool $scope
     * @return list<string>
     */
    public function normalizeScopes(array|string|bool $scope): array
    {
        if (is_array($scope)) {
            $out = [];
            foreach ($scope as $item) {
                foreach ($this->normalizeScopes($item) as $one) {
                    $out[] = $one;
                }
            }

            return array_values(array_unique($out));
        }

        if (is_bool($scope)) {
            return [$scope ? 'service' : 'all'];
        }

        $scope = strtolower(trim($scope));
        if ($scope === 'all') {
            return ['all'];
        }

        $parts = array_values(array_filter(array_map(
            fn (string $v): string => $this->normalizeScope($v),
            preg_split('/\s*,\s*/', $scope) ?: []
        )));

        return $parts !== [] ? $parts : ['service'];
    }

    private function normalizeScope(string|bool $scope): string
    {
        if (is_bool($scope)) {
            return $scope ? 'service' : 'all';
        }

        $scope = strtolower(trim($scope));

        return match ($scope) {
            'service', 'api', 'admin', 'boot', 'all' => $scope,
            default => 'service',
        };
    }

    /**
     * @return array{
     *   ok:bool,
     *   identifier:string,
     *   scope:string,
     *   violations:list<array{file:string,messages:list<string>}>,
     *   total:int
     * }
     */
    private function auditPluginBootFile(string $identifier, string $root): array
    {
        $file = $root . DIRECTORY_SEPARATOR . 'Plugin.php';
        if (!is_file($file)) {
            return [
                'ok'         => true,
                'identifier' => $identifier,
                'scope'      => 'boot',
                'violations' => [],
                'total'      => 0,
            ];
        }

        $rel  = $identifier . '/Plugin.php';
        $msgs = $this->scanPhpContent($rel, (string) file_get_contents($file));

        return [
            'ok'         => $msgs === [],
            'identifier' => $identifier,
            'scope'      => 'boot',
            'violations' => $msgs === [] ? [] : [['file' => $rel, 'messages' => $msgs]],
            'total'      => count($msgs),
        ];
    }

    private function resolveScanRoot(string $pluginRoot, string $scope): ?string
    {
        if ($scope === 'all') {
            return $pluginRoot;
        }

        $sub = match ($scope) {
            'service' => 'service',
            'api'     => 'api',
            'admin'   => 'admin',
            default   => '',
        };
        if ($sub === '') {
            return null;
        }

        $path = $pluginRoot . DIRECTORY_SEPARATOR . $sub;

        return is_dir($path) ? $path : null;
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterPhpFiles(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!str_ends_with(strtolower($file->getFilename()), '.php')) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.pve')) {
                continue;
            }
            yield $file->getPathname();
        }
    }

    private function checkFqcn(string $fqcn, string $relativePath, string $kind): ?string
    {
        if (!str_starts_with($fqcn, 'app\\common\\service\\')) {
            return null;
        }
        if (str_starts_with($fqcn, 'app\\common\\service\\weapp\\')) {
            return null;
        }

        return sprintf(
            '禁止%s引用内核内部服务 %s（请改用官方插件接口）@ %s',
            $kind === 'use' ? ' use' : '',
            $fqcn,
            $relativePath
        );
    }

    private function isForbiddenFqcn(string $fqcn): bool
    {
        return in_array($fqcn, self::FORBIDDEN_FQCN, true);
    }
}
