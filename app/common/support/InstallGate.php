<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 安装锁 data/install.lock */
final class InstallGate
{
    public static function lockPath(): string
    {
        $root = defined('ROOT_PATH')
            ? ROOT_PATH
            : (function_exists('root_path') ? root_path() : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);

        return rtrim((string) $root, '/\\') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'install.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockPath());
    }

    /**
     * @return array<string, mixed>
     */
    public static function readLockData(): array
    {
        if (!self::isInstalled()) {
            return [];
        }
        $raw = trim((string) file_get_contents(self::lockPath()));
        if ($raw === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        return [
            'installed_at' => $raw,
            'edition'      => null,
        ];
    }

    public static function lockedEdition(): ?string
    {
        $edition = strtolower(trim((string) (self::readLockData()['edition'] ?? '')));
        if ($edition === '' || !in_array($edition, ['community', 'platform', 'dev'], true)) {
            return null;
        }

        if ($edition === 'community' && !self::verifyCommunitySeal(self::readLockData())) {
            return 'community';
        }

        return $edition;
    }

    public static function createLock(?string $edition = null): void
    {
        $dir = dirname(self::lockPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建 data 目录');
        }

        $edition = $edition ?? self::detectPackagedEdition();
        $payload = [
            'installed_at'    => AppTime::format('c'),
            'edition'         => $edition,
            'release_version' => self::readReleaseVersion(),
        ];
        if ($edition === 'community') {
            $payload['seal'] = self::communitySeal($payload);
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents(self::lockPath(), $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('无法写入 install.lock');
        }
    }

    public static function isInstallUri(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;

        return str_starts_with($path, '/install');
    }

    public static function shouldBypass(string $uri): bool
    {
        if (self::isInstallUri($uri)) {
            return true;
        }
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        // 未装机时仍放行静态资源（安装页 CSS/JS）；
        if (str_starts_with($path, '/static/')) {
            return true;
        }
        if (preg_match('#^/captcha/#', $path)) {
            return true;
        }

        return false;
    }

    private static function detectPackagedEdition(): string
    {
        $releaseEdition = self::readReleaseEdition();
        if ($releaseEdition !== null) {
            return $releaseEdition;
        }

        $env = strtolower(trim((string) env('PIVARK_EDITION', 'community')));

        return in_array($env, ['community', 'platform', 'dev'], true) ? $env : 'community';
    }

    private static function readReleaseEdition(): ?string
    {
        $path = ROOT_PATH . 'RELEASE.json';
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $edition = strtolower(trim((string) ($data['edition'] ?? '')));

        return in_array($edition, ['community', 'platform', 'dev'], true) ? $edition : null;
    }

    private static function readReleaseVersion(): string
    {
        if (defined('PIVARK_VERSION')) {
            return trim((string) PIVARK_VERSION);
        }
        $path = ROOT_PATH . 'RELEASE.json';
        if (!is_readable($path)) {
            return '';
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return '';
        }

        return trim((string) ($data['version'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function communitySeal(array $payload): string
    {
        $material = 'community|'
            . (string) ($payload['release_version'] ?? '')
            . '|'
            . (string) ($payload['installed_at'] ?? '');

        return hash_hmac('sha256', $material, self::sealSecret());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function verifyCommunitySeal(array $payload): bool
    {
        $seal = trim((string) ($payload['seal'] ?? ''));
        if ($seal === '') {
            return true;
        }

        return hash_equals($seal, self::communitySeal($payload));
    }

    private static function sealSecret(): string
    {
        $release = defined('PIVARK_RELEASE') ? (string) PIVARK_RELEASE : '20260521';

        return hash('sha256', 'pivark-community-install-seal-v1|' . $release);
    }
}
