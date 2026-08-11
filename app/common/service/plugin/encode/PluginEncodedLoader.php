<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\encode;

use app\common\service\plugin\PluginService;

use app\common\service\plugin\entitlement\EntitlementService;
use think\facade\Log;

/** 商业插件 .pve 运行时加载（本地化，仅校验 Entitlement，不联网） */
final class PluginEncodedLoader
{
    public const MAGIC = 'PIVARKENC1';

    public const MAGIC2 = 'PIVARKENC2';

    /** @var array<string, bool> */
    private static array $loaded = [];

    public function registerAutoload(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        $root = app(PluginService::class)->weappRoot() . $identifier . DIRECTORY_SEPARATOR;
        $prefix = 'weapp\\' . str_replace('-', '_', $identifier) . '\\';

        spl_autoload_register(function (string $class) use ($root, $prefix, $identifier): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $plain = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel) . '.php';
            if (is_file($plain)) {
                if ($this->isStubOnly($plain)) {
                    $pveAlt = preg_replace('/\.php$/i', '.php.pve', $plain);
                    if (is_string($pveAlt) && is_file($pveAlt)) {
                        $this->requirePve($pveAlt, $identifier);
                    }

                    return;
                }
                require_once $plain;

                return;
            }
            $pve = $plain . '.pve';
            if (is_file($pve)) {
                $this->requirePve($pve, $identifier);
            }
        }, true, true);
    }

    public function requirePve(string $pvePath, string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        $key        = $identifier . '|' . realpath($pvePath);
        if (isset(self::$loaded[$key])) {
            return;
        }
        if (!app(EntitlementService::class)->can($identifier)) {
            Log::warning('plugin_encoded_skip_no_entitlement', ['identifier' => $identifier, 'pve' => $pvePath]);

            return;
        }
        $code = $this->decodeFile($pvePath);
        if ($code === '') {
            throw new \RuntimeException('无法解码插件文件：' . $pvePath);
        }
        self::$loaded[$key] = true;
        eval($this->prepareEvalSource($code));
    }

    /** eval() 不接受 <?php 开标签，解码后的插件源码需先剥离 */
    private function prepareEvalSource(string $code): string
    {
        $code = ltrim($code, "\xEF\xBB\xBF");
        $code = trim($code);
        if (str_starts_with($code, '<?php')) {
            $code = substr($code, 5);
        } elseif (str_starts_with($code, '<?')) {
            $code = substr($code, 2);
        }
        $code = ltrim($code, "\r\n");
        $trimmed = rtrim($code);
        if (str_ends_with($trimmed, '?>')) {
            $code = rtrim(substr($trimmed, 0, -2));
        }

        return $code;
    }

    public function decodeFile(string $pvePath): string
    {
        $this->verifySidecarSha256($pvePath);
        $raw = (string) file_get_contents($pvePath);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, self::MAGIC2)) {
            return $this->decodePayload(substr($raw, strlen(self::MAGIC2)), true);
        }
        if (str_starts_with($raw, self::MAGIC)) {
            return $this->decodePayload(substr($raw, strlen(self::MAGIC)), false);
        }

        return '';
    }

    public function encodePayload(string $phpSource): string
    {
        $bin = gzencode($phpSource, 6);
        if ($bin === false) {
            return '';
        }
        $key = $this->encodeKey();
        if ($key !== '') {
            $bin = $this->xorBytes($bin, $key);
        }
        $hash = hash('sha256', $phpSource);

        return self::MAGIC2 . $hash . base64_encode($bin);
    }

    public function encodeKey(): string
    {
        return trim((string) config('plugin.commercial.encode_key', ''));
    }

    public function isStubOnly(string $phpPath): bool
    {
        if (!is_file($phpPath)) {
            return false;
        }

        return $this->isStubBody((string) file_get_contents($phpPath));
    }

    public function isStubBody(string $body): bool
    {
        if (!str_contains($body, 'PivArk encoded stub')) {
            return false;
        }

        return !preg_match('/\b(namespace|class|function)\s+/i', $body);
    }

    public function stubPhp(string $pveRelative): string
    {
        $pveRelative = str_replace('\\', '/', $pveRelative);

        return '<?php' . "\n"
            . '/** PivArk encoded stub — 业务逻辑在 .pve，授权本地化 */' . "\n"
            . 'if (!app(\\app\\common\\service\\plugin\\encode\\PluginEncodedLoader::class)->loadStub(__FILE__, '
            . var_export($pveRelative, true) . ')) {' . "\n"
            . "    return;\n"
            . "}\n";
    }

    public function loadStub(string $stubFile, string $pveRelative): bool
    {
        $identifier = $this->identifierFromPath($stubFile);
        if ($identifier === '') {
            return false;
        }
        $dir = dirname($stubFile);
        $pve = $dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pveRelative);
        if (!is_file($pve) && str_ends_with($pveRelative, '.php')) {
            $pveAlt = preg_replace('/\.php$/i', '.php.pve', $stubFile);
            if (is_string($pveAlt)) {
                $pve = $pveAlt;
            }
        }
        if (!is_file($pve)) {
            return false;
        }
        if (!app(EntitlementService::class)->can($identifier)) {
            Log::warning('plugin_encoded_stub_skip_no_entitlement', ['identifier' => $identifier]);

            return false;
        }
        $this->requirePve($pve, $identifier);

        return true;
    }

    public function identifierFromPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#/weapp/([a-z][a-z0-9_-]{1,31})/#', $path, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    /** @return list<string> */
    public function runtimeErrors(string $encryption = 'pivark'): array
    {
        $encryption = strtolower(trim($encryption));
        if ($encryption === 'ioncube') {
            if (extension_loaded('ionCube Loader') || extension_loaded('ioncube loader')) {
                return [];
            }

            return ['PHP 未加载 ionCube Loader，请在宝塔 → PHP → 安装 ionCube Loader 后重载'];
        }

        if (!function_exists('gzdecode')) {
            return ['PHP 未启用 zlib（gzdecode），无法加载 PivArk 加密插件'];
        }

        return [];
    }

    private function decodePayload(string $payload, bool $verifyPlainHash): string
    {
        $expectedHash = '';
        if ($verifyPlainHash) {
            if (strlen($payload) < 64) {
                return '';
            }
            $expectedHash = strtolower(substr($payload, 0, 64));
            $payload      = substr($payload, 64);
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                return '';
            }
        }
        $bin = base64_decode($payload, true);
        if ($bin === false || $bin === '') {
            return '';
        }
        $key = $this->encodeKey();
        if ($key !== '') {
            $bin = $this->xorBytes($bin, $key);
        }
        $plain = @gzdecode($bin);
        if (!is_string($plain) || $plain === '') {
            return '';
        }
        if ($verifyPlainHash && !hash_equals($expectedHash, hash('sha256', $plain))) {
            return '';
        }

        return $plain;
    }

    private function verifySidecarSha256(string $pvePath): void
    {
        $sidecar = $pvePath . '.sha256';
        if (!is_file($sidecar)) {
            Log::warning('[plugin_encoded] sidecar_missing pve=' . $pvePath);

            return;
        }
        $expected = strtolower(trim((string) file_get_contents($sidecar)));
        if ($expected === '' || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new \RuntimeException('插件校验文件无效：' . $sidecar);
        }
        $actual = hash_file('sha256', $pvePath);
        if ($actual === false || !hash_equals($expected, strtolower($actual))) {
            throw new \RuntimeException('插件文件 SHA256 校验失败：' . $pvePath);
        }
    }

    private function xorBytes(string $data, string $key): string
    {
        if ($key === '') {
            return $data;
        }
        $out    = '';
        $len    = strlen($data);
        $keyLen = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            $out .= $data[$i] ^ $key[$i % $keyLen];
        }

        return $out;
    }
}
