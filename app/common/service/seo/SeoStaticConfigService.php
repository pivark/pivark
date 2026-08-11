<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\service\config\ConfigService;
use app\common\service\site\SiteUrlModeService;
use app\common\support\OpsLog;
use app\common\support\TrustedShellRunner;

/** 静态 HTML 模式专用配置 */
class SeoStaticConfigService
{
    private static bool $subdirWebAliasEnsured = false;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly SiteUrlModeService $siteUrlModeService,
    ) {
    }

    /** @return list<string> */
    public function configKeys(): array
    {
        return [
            'seo_static_subdir',
            'seo_static_publish_home',
            'seo_static_publish_channel',
            'seo_static_publish_adjacent',
            'seo_static_edit_home',
            'seo_static_edit_channel',
            'seo_static_edit_adjacent',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->configKeys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'seo_static_subdir' => '',
            'seo_static_publish_home', 'seo_static_edit_home', 'seo_static_edit_channel' => '0',
            'seo_static_publish_channel', 'seo_static_publish_adjacent', 'seo_static_edit_adjacent' => '1',
            default => '',
        };
    }

    /** 静态文件子目录（相对 public/），如 html；留空表示 public 根目录 */
    public function subdir(): string
    {
        if (!$this->siteUrlModeService->isStatic()) {
            return '';
        }
        $sub = trim((string) $this->configService->get('seo_static_subdir', ''), '/');
        if ($sub === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,30}$/i', $sub)) {
            return '';
        }

        return $sub;
    }

    /**
     * 不落盘为静态 HTML 的前台应用路径：禁止加 seo_static_subdir。
     * （member/search/api 等仍走 PHP；误加前缀会导致 /html/member 404，且污染 tpl parse 缓存）
     *
     * @var list<string>
     */
    private const NON_STATIC_APP_PREFIXES = [
        '/member',
        '/search',
        '/api',
        '/admin',
        '/static',
        '/install',
        '/index.php',
        '/captcha',
    ];

    /** 静态模式下为前台内容 URL 加存储前缀，使链接与 HTML 文件路径一致 */
    public function applyStaticStoragePrefix(string $url): string
    {
        $sub = $this->subdir();
        if ($sub === '' || !$this->siteUrlModeService->isStatic()) {
            return $url;
        }

        if ($url === '' || $url === '/') {
            return '/' . $sub . '/';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $path  = (string) ($parts['path'] ?? $url);
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        if ($this->isNonStaticAppPath($path)) {
            return $path . $query;
        }
        $prefix = '/' . $sub;
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return $path . $query;
        }

        return $prefix . ($path[0] === '/' ? $path : '/' . $path) . $query;
    }

    /** 会员中心 / API / 静态资源等：永不挂 seo_static_subdir */
    public function isNonStaticAppPath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::NON_STATIC_APP_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public function normalizeSubdir(string $raw): string
    {
        $sub = trim(str_replace('\\', '/', $raw), '/');
        if ($sub === '') {
            return '';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,30}$/i', $sub)) {
            return '';
        }

        return $sub;
    }

    /** @return array{publish_home:bool,publish_channel:bool,publish_adjacent:bool,edit_home:bool,edit_channel:bool,edit_adjacent:bool} */
    public function syncFlags(): array
    {
        return [
            'publish_home'     => $this->flag('seo_static_publish_home'),
            'publish_channel'  => $this->flag('seo_static_publish_channel'),
            'publish_adjacent' => $this->flag('seo_static_publish_adjacent'),
            'edit_home'        => $this->flag('seo_static_edit_home'),
            'edit_channel'     => $this->flag('seo_static_edit_channel'),
            'edit_adjacent'    => $this->flag('seo_static_edit_adjacent'),
        ];
    }

    public function storageHint(): string
    {
        $sub = $this->subdir();
        if ($sub === '') {
            return '当前 HTML 生成在 public/ 根目录，访问路径与伪静态 URL 一致。';
        }

        return '当前 HTML 生成在 public/' . $sub . '/，前台链接加 /' . $sub . '/；站点根会自愈软链 /' . $sub . '→public/' . $sub . '（不必粘伪静态也能打开）。';
    }

    /**
     * 文档根=发行包根时：/{subdir} 须映到 public/{subdir}，否则 Nginx 空站 404。
     * 与装机 /uploads、/static 软链同思路；静态模式不依赖粘宝塔伪静态。
     */
    public function ensureSubdirWebAlias(): void
    {
        if (self::$subdirWebAliasEnsured) {
            return;
        }
        self::$subdirWebAliasEnsured = true;

        $sub = $this->subdir();
        if ($sub === '') {
            return;
        }
        $root = defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', (string) ROOT_PATH), '/') : '';
        if ($root === '') {
            return;
        }
        $target = $root . '/public/' . $sub;
        $link   = $root . '/' . $sub;
        if (!is_dir($target)) {
            return;
        }
        if (is_link($link)) {
            if (!\function_exists('readlink')) {
                return;
            }
            $current = @readlink($link);
            $currentNorm = $current !== false ? str_replace('\\', '/', $current) : '';
            $targetNorm  = str_replace('\\', '/', $target);
            if ($currentNorm === $targetNorm || str_ends_with($currentNorm, '/' . $sub)) {
                return;
            }
            OpsLog::businessWarning('seo_static_subdir_alias_exists_other', [
                'link'    => $link,
                'current' => $currentNorm,
                'expect'  => $targetNorm,
            ]);

            return;
        }
        if (file_exists($link) || is_dir($link)) {
            return;
        }
        if (\function_exists('symlink')) {
            try {
                if (@\symlink($target, $link)) {
                    return;
                }
            } catch (\Throwable $e) {
                OpsLog::businessWarning('seo_static_subdir_alias_failed', [
                    'link'  => $link,
                    'target'=> $target,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if (\PHP_OS_FAMILY !== 'Windows') {
            $cmd = 'ln -sfn ' . \escapeshellarg($target) . ' ' . \escapeshellarg($link);
            $result = TrustedShellRunner::execCaptured($cmd);
            if ((int) ($result['exit_code'] ?? 1) === 0 && (is_link($link) || is_dir($link))) {
                return;
            }
        }
        OpsLog::businessWarning('seo_static_subdir_alias_failed', [
            'link'   => $link,
            'target' => $target,
            'hint'   => 'symlink_disabled_use_baota_html_alias',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{code:int,msg:string}
     */
    public function mergeIntoSavePayload(array &$data): void
    {
        foreach ($this->configKeys() as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'seo_static_subdir') {
                $data[$key] = $this->normalizeSubdir((string) $data[$key]);
            } else {
                $data[$key] = (int) $data[$key] === 1 ? '1' : '0';
            }
        }
    }

    private function flag(string $key): bool
    {
        return (string) $this->configService->get($key, $this->defaultFor($key)) === '1';
    }
}
