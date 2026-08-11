<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;
use app\common\support\AdminOpsPanelHints;

use app\common\service\config\ConfigService;

/** 静态页远程发布（OSS / CDN）可选配置 — 默认本地，用户自购云后自行填写 */
final class StaticRemotePublishConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const OSS_LOCAL   = 'local';
    public const OSS_MIRROR  = 'mirror';
    public const OSS_WEBHOOK = 'webhook';
    public const OSS_S3      = 's3';

    public const CDN_NONE    = 'none';
    public const CDN_WEBHOOK = 'webhook';

    /** @return list<string> */
    public function configKeys(): array
    {
        return [
            'static_oss_enabled',
            'static_oss_driver',
            'static_oss_endpoint',
            'static_oss_bucket',
            'static_oss_access_key',
            'static_oss_secret_key',
            'static_oss_prefix',
            'static_oss_mirror_dir',
            'static_oss_webhook_url',
            'static_oss_webhook_secret',
            'static_cdn_enabled',
            'static_cdn_driver',
            'static_cdn_public_base',
            'static_cdn_webhook_url',
            'static_cdn_webhook_secret',
            'static_cdn_warmup_enabled',
            'static_cdn_warmup_webhook_url',
            'static_cdn_warmup_webhook_secret',
            'static_cdn_warmup_timeout',
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
            'static_oss_enabled'        => '0',
            'static_oss_driver'         => self::OSS_LOCAL,
            'static_oss_prefix'         => '',
            'static_oss_mirror_dir'     => 'data/static_mirror',
            'static_cdn_enabled'        => '0',
            'static_cdn_driver'         => self::CDN_NONE,
            'static_cdn_warmup_enabled' => '0',
            'static_cdn_warmup_timeout' => '15',
            default                     => '',
        };
    }

    public function cdnWarmupEnabled(): bool
    {
        return $this->cdnEnabled()
            && (string) $this->configService->get('static_cdn_warmup_enabled', '0') === '1';
    }

    public function ossEnabled(): bool
    {
        return (string) $this->configService->get('static_oss_enabled', '0') === '1'
            && $this->ossDriver() !== self::OSS_LOCAL;
    }

    public function cdnEnabled(): bool
    {
        return (string) $this->configService->get('static_cdn_enabled', '0') === '1'
            && $this->cdnDriver() === self::CDN_WEBHOOK
            && trim((string) $this->configService->get('static_cdn_webhook_url', '')) !== '';
    }

    public function ossDriver(): string
    {
        $v = strtolower(trim((string) $this->configService->get('static_oss_driver', self::OSS_LOCAL)));
        $allowed = [self::OSS_LOCAL, self::OSS_MIRROR, self::OSS_WEBHOOK, self::OSS_S3];

        return in_array($v, $allowed, true) ? $v : self::OSS_LOCAL;
    }

    public function cdnDriver(): string
    {
        $v = strtolower(trim((string) $this->configService->get('static_cdn_driver', self::CDN_NONE)));

        return in_array($v, [self::CDN_NONE, self::CDN_WEBHOOK], true) ? $v : self::CDN_NONE;
    }

    /**
     * 后台展示（密钥打码）
     *
     * @return array<string, mixed>
     */
    public function panelForAdmin(): array
    {
        $cfg = $this->all();

        return [
            'oss_enabled'     => $this->ossEnabled(),
            'oss_driver'      => $this->ossDriver(),
            'cdn_enabled'     => $this->cdnEnabled(),
            'cdn_driver'      => $this->cdnDriver(),
            'cdn_warmup_enabled' => $this->cdnWarmupEnabled(),
            'cdn_public_base' => trim((string) ($cfg['static_cdn_public_base'] ?? '')),
            'has_oss_secret'  => trim((string) ($cfg['static_oss_secret_key'] ?? '')) !== '',
            'has_cdn_secret'  => trim((string) ($cfg['static_cdn_webhook_secret'] ?? '')) !== '',
            'has_warmup_secret' => trim((string) ($cfg['static_cdn_warmup_webhook_secret'] ?? '')) !== '',
            'warmup_ops'        => $this->warmupOpsForAdmin(),
            'hint'            => '不启用时 HTML 仅保存在本站 public/；启用后由您自购的 OSS/CDN 服务商处理（本系统只调 API/Webhook）。',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function warmupOpsForAdmin(): array
    {
        $hook = trim((string) $this->configService->get('static_cdn_warmup_webhook_url', ''));
        if ($hook === '') {
            $hook = trim((string) $this->configService->get('static_cdn_webhook_url', ''));
        }

        $scan = AdminOpsPanelHints::scanOps(
            'static_cdn_warmup_cli.php',
            '--scan --limit=200',
            '--scan --dry-run --limit=50',
        );

        return [
            'enabled'       => $this->cdnWarmupEnabled(),
            'webhook_ready' => $hook !== '',
            'timeout'       => max(5, (int) $this->configService->get('static_cdn_warmup_timeout', 15)),
            'scan_cli'      => $scan['scan_cli'],
            'dry_run_cli'   => $scan['dry_run_cli'],
            'doc'           => $scan['doc'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function mergeIntoSavePayload(array &$data): void
    {
        if (array_key_exists('static_oss_enabled', $data)) {
            $data['static_oss_enabled'] = (int) $data['static_oss_enabled'] === 1 ? '1' : '0';
        }
        if (array_key_exists('static_cdn_enabled', $data)) {
            $data['static_cdn_enabled'] = (int) $data['static_cdn_enabled'] === 1 ? '1' : '0';
        }
        if (array_key_exists('static_cdn_warmup_enabled', $data)) {
            $data['static_cdn_warmup_enabled'] = (int) $data['static_cdn_warmup_enabled'] === 1 ? '1' : '0';
        }
        if (array_key_exists('static_oss_driver', $data)) {
            $drv = strtolower(trim((string) $data['static_oss_driver']));
            if (!in_array($drv, [self::OSS_LOCAL, self::OSS_MIRROR, self::OSS_WEBHOOK, self::OSS_S3], true)) {
                $drv = self::OSS_LOCAL;
            }
            $data['static_oss_driver'] = $drv;
            if ($drv === self::OSS_LOCAL) {
                $data['static_oss_enabled'] = '0';
            }
        }
        if (array_key_exists('static_cdn_driver', $data)) {
            $drv = strtolower(trim((string) $data['static_cdn_driver']));
            $data['static_cdn_driver'] = in_array($drv, [self::CDN_NONE, self::CDN_WEBHOOK], true)
                ? $drv
                : self::CDN_NONE;
            if ($drv === self::CDN_NONE) {
                $data['static_cdn_enabled'] = '0';
            }
        }
        foreach (['static_oss_prefix', 'static_oss_mirror_dir', 'static_oss_endpoint', 'static_oss_bucket'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = trim(str_replace('\\', '/', (string) $data[$k]), '/');
            }
        }
        if (array_key_exists('static_cdn_public_base', $data)) {
            $data['static_cdn_public_base'] = rtrim(trim((string) $data['static_cdn_public_base']), '/');
        }
        // 留空 secret 表示不修改
        foreach (['static_oss_secret_key', 'static_cdn_webhook_secret', 'static_oss_webhook_secret'] as $secretKey) {
            if (array_key_exists($secretKey, $data) && trim((string) $data[$secretKey]) === '') {
                unset($data[$secretKey]);
            }
        }
        if (array_key_exists('static_cdn_warmup_webhook_secret', $data) && trim((string) $data['static_cdn_warmup_webhook_secret']) === '') {
            unset($data['static_cdn_warmup_webhook_secret']);
        }
        if (array_key_exists('static_cdn_warmup_timeout', $data)) {
            $data['static_cdn_warmup_timeout'] = (string) max(5, (int) $data['static_cdn_warmup_timeout']);
        }
    }

    /**
     * @return array<string, string>
     */
    public function allForAdminForm(): array
    {
        $cfg = $this->all();
        foreach (['static_oss_secret_key', 'static_cdn_webhook_secret', 'static_oss_webhook_secret'] as $k) {
            if (trim((string) ($cfg[$k] ?? '')) !== '') {
                $cfg[$k] = '';
            }
        }
        if (trim((string) ($cfg['static_cdn_warmup_webhook_secret'] ?? '')) !== '') {
            $cfg['static_cdn_warmup_webhook_secret'] = '';
        }

        return $cfg;
    }
}
