<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\config;

use app\common\support\AppTime;
use app\common\service\config\ConfigSecretValidator;
use app\common\service\config\ConfigService;

use app\common\model\Config;
use app\common\model\ConfigSecret;
use app\common\support\AppCipher;
use app\common\support\ConfigSensitiveKeys;
use app\common\support\InstallGate;

/** configs 凭据分离：读写 config_secrets 表（AppCipher 加密） */
final class ConfigSecretService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly ConfigSecretValidator $configSecretValidator,
    ) {
    }

    public const PLACEHOLDER = '[secret:migrated]';

    /**
     * @param mixed $default
     * @return mixed
     */
    public function resolve(string $key, $default = '')
    {
        if (!InstallGate::isInstalled()) {
            return $default;
        }
        if (!ConfigSensitiveKeys::isCredentialKey($key)) {
            return Config::getAll()[$key] ?? $default;
        }

        $row = ConfigSecret::findByKey($key);
        if ($row !== null) {
            $plain = AppCipher::decrypt((string) ($row->value ?? ''));

            return $plain !== '' ? $plain : $default;
        }

        $legacy = $this->legacyConfigValue($key);
        if ($legacy === null || $legacy === '' || $legacy === self::PLACEHOLDER) {
            return $default;
        }

        $plain = AppCipher::decrypt($legacy);

        return $plain !== '' ? $plain : $legacy;
    }

    public function store(string $key, string $value): void
    {
        if (!ConfigSensitiveKeys::isCredentialKey($key)) {
            Config::setValue($key, $value);

            return;
        }

        $stored = $value === '' ? '' : AppCipher::encrypt($value);
        $now    = AppTime::now();
        $row    = ConfigSecret::findByKey($key);
        if ($row !== null) {
            $row->save(['value' => $stored, 'updated_at' => $now]);
        } else {
            ConfigSecret::create([
                'key'        => $key,
                'value'      => $stored,
                'updated_at' => $now,
            ]);
        }
        Config::setValue($key, self::PLACEHOLDER);
        $this->configService->forgetRequestCache();
    }

    public function hasSecretRow(string $key): bool
    {
        return ConfigSecret::findByKey($key) !== null;
    }

    /** 从 configs 迁一行到 config_secrets（幂等） */
    public function migrateKeyFromConfigs(string $key): bool
    {
        if (!ConfigSensitiveKeys::isCredentialKey($key)) {
            return false;
        }
        if ($this->hasSecretRow($key)) {
            return false;
        }
        $legacy = $this->legacyConfigValue($key);
        if ($legacy === null || $legacy === '' || $legacy === self::PLACEHOLDER) {
            return false;
        }
        $this->store($key, $legacy);

        return true;
    }

    /** 回滚：凭据写回 configs 明文并删 secret 行 */
    public function rollbackKeyToConfigs(string $key): bool
    {
        if (!ConfigSensitiveKeys::isCredentialKey($key)) {
            return false;
        }
        $row = ConfigSecret::findByKey($key);
        if ($row === null) {
            return false;
        }
        $plain = AppCipher::decrypt((string) ($row->value ?? ''));
        Config::setValue($key, $plain);
        $row->delete();
        $this->configService->forgetRequestCache();

        return true;
    }

    public function legacyConfigValue(string $key): ?string
    {
        $all = Config::getAll();

        return array_key_exists($key, $all) ? (string) $all[$key] : null;
    }

    public function migrateAllPlaintextCredentials(): array
    {
        $migrated = [];
        foreach ($this->listPlaintextLeaks() as $key) {
            if ($this->migrateKeyFromConfigs($key)) {
                $migrated[] = $key;
            }
        }

        return $migrated;
    }

    /** @return list<string> configs 中仍含明文凭据的键 */
    public function listPlaintextLeaks(): array
    {
        $leaks = [];
        foreach (ConfigSensitiveKeys::credentialKeys() as $key) {
            if ($this->hasSecretRow($key)) {
                continue;
            }
            $legacy = $this->legacyConfigValue($key);
            if ($legacy === null || !$this->configSecretValidator->isLegacyPlaintextValue($legacy, self::PLACEHOLDER)) {
                continue;
            }
            if ($this->configSecretValidator->looksLikeSecret($key, $legacy)) {
                $leaks[] = $key;
            }
        }

        return $leaks;
    }

    public function looksLikeSecret(string $key, string $value): bool
    {
        return $this->configSecretValidator->looksLikeSecret($key, $value);
    }
}
