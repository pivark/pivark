<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\service\config\ConfigService;

/**
 * 限流策略注册表：config/rate_limit.php + 站点 overrides + 遗留配置键兼容
 */
final class RateLimitRegistry
{
    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    public function isGloballyEnabled(): bool
    {
        return (bool) config('rate_limit.enabled', true);
    }

    /** @return list<string> */
    public function policyIds(): array
    {
        $policies = config('rate_limit.policies', []);

        return is_array($policies) ? array_keys($policies) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $policyId): ?array
    {
        $base = $this->policyBase($policyId);
        if (!is_array($base)) {
            return null;
        }

        $policy = $base;
        $policy['id'] = $policyId;

        // 先套 env / 文件默认，再套站点 overrides，保证后台「限流策略」能压过默认 env
        $this->applyEnvKeys($policy);
        $this->applyConfigPath($policy);

        $overrides = $this->loadOverrides();
        if (isset($overrides[$policyId]) && is_array($overrides[$policyId])) {
            $policy = array_merge($policy, $overrides[$policyId]);
        }

        $policy['enabled'] = $this->normalizeEnabled($policy);
        if (!empty($policy['bypass'])) {
            $policy['enabled'] = false;
        }

        $policy['max_requests']   = max(1, (int) ($policy['max_requests'] ?? 60));
        $policy['window_seconds'] = max(1, (int) ($policy['window_seconds'] ?? config('rate_limit.window_seconds', 60)));
        if (isset($policy['min_requests'])) {
            $policy['max_requests'] = max((int) $policy['min_requests'], $policy['max_requests']);
        }
        if (isset($policy['lock_seconds'])) {
            $policy['lock_seconds'] = max(1, (int) $policy['lock_seconds']);
        }
        if (($policy['mode'] ?? 'window') === 'cooldown') {
            $policy['cooldown_seconds'] = max(1, (int) ($policy['cooldown_seconds'] ?? 60));
        }

        return $policy;
    }

    /**
     * @return array{
     *   groups: list<array{key:string,title:string}>,
     *   items: list<array<string,mixed>>,
     *   cluster_hint: string
     * }
     */
    public function metaForAdmin(): array
    {
        $groups = [];
        $items  = [];
        foreach ($this->policyIds() as $policyId) {
            $policy = $this->resolve($policyId);
            if ($policy === null || empty($policy['admin_editable'])) {
                continue;
            }
            $group = (string) ($policy['group'] ?? '其他');
            if (!isset($groups[$group])) {
                $groups[$group] = ['key' => md5($group), 'title' => $group];
            }
            $baseCfg = $this->policyBase($policyId);
            $items[] = [
                'id'               => $policyId,
                'label'            => (string) ($policy['label'] ?? $policyId),
                'hint'             => (string) ($policy['hint'] ?? ''),
                'group'            => $group,
                'enabled'          => $this->normalizeEnabled($policy),
                'mode'             => (string) ($policy['mode'] ?? 'window'),
                'max_requests'     => (int) ($policy['max_requests'] ?? 60),
                'window_seconds'   => (int) ($policy['window_seconds'] ?? 60),
                'cooldown_seconds' => (int) ($policy['cooldown_seconds'] ?? 60),
                'lock_seconds'     => (int) ($policy['lock_seconds'] ?? 0),
                'default_max'      => (int) (is_array($baseCfg) ? ($baseCfg['max_requests'] ?? 60) : 60),
                'min_requests'     => (int) ($policy['min_requests'] ?? 1),
                'max_cap'          => (int) ($policy['max_cap'] ?? 600),
            ];
        }

        return [
            'groups'       => array_values($groups),
            'items'        => $items,
            'cluster_hint' => '多机部署时各节点独立计数，实际限额约为配置值×机器数。',
        ];
    }

    /**
     * @param array<string, mixed> $input policyId => {enabled,max_requests,window_seconds,...}
     * @return array{overrides: array<string, array<string, mixed>>, applied: int}
     */
    public function buildOverridesFromAdmin(array $input): array
    {
        $overrides = $this->loadOverrides();
        $applied = 0;
        foreach ($input as $policyId => $row) {
            if (!is_string($policyId) || !is_array($row)) {
                continue;
            }
            $base = $this->policyBase($policyId);
            if (!is_array($base) || empty($base['admin_editable'])) {
                continue;
            }
            $patch = [];
            if (array_key_exists('enabled', $row)) {
                $patch['enabled'] = filter_var($row['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($patch['enabled'] === null) {
                    $patch['enabled'] = (bool) $row['enabled'];
                }
            }
            if (array_key_exists('max_requests', $row)) {
                $min = (int) ($base['min_requests'] ?? 1);
                $max = (int) ($base['max_cap'] ?? 600);
                $patch['max_requests'] = max($min, min($max, (int) $row['max_requests']));
            }
            if (array_key_exists('window_seconds', $row)) {
                $patch['window_seconds'] = max(1, min(3600, (int) $row['window_seconds']));
            }
            if (array_key_exists('cooldown_seconds', $row) && ($base['mode'] ?? '') === 'cooldown') {
                $patch['cooldown_seconds'] = max(1, min(3600, (int) $row['cooldown_seconds']));
            }
            if ($patch !== []) {
                $overrides[$policyId] = array_merge($overrides[$policyId] ?? [], $patch);
                $applied++;
            }
        }

        return ['overrides' => $overrides, 'applied' => $applied];
    }

    public function saveOverrides(array $overrides): void
    {
        $key = (string) config('rate_limit.overrides_key', 'rate_limit_policy_overrides');
        $json = json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->config->save([
            $key => $json,
        ]);
        $stored = (string) $this->config->get($key, '');
        if ($stored === '') {
            throw new \RuntimeException('限流策略未能写入配置（请确认 rate_limit_policy_overrides 已放行保存）');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function loadOverrides(): array
    {
        $key = (string) config('rate_limit.overrides_key', 'rate_limit_policy_overrides');
        $raw = $this->config->get($key, '');
        if ($raw === '' || $raw === null) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $policy */
    private function applyEnvKeys(array &$policy): void
    {
        $envKeys = $policy['env_keys'] ?? null;
        if (!is_array($envKeys)) {
            return;
        }
        if (isset($envKeys['enabled'])) {
            $policy['enabled'] = (bool) env((string) $envKeys['enabled'], true);
        }
        if (isset($envKeys['max'])) {
            $policy['max_requests'] = max(1, (int) env((string) $envKeys['max'], $policy['max_requests'] ?? 60));
        }
        if (isset($envKeys['window'])) {
            $policy['window_seconds'] = max(1, (int) env((string) $envKeys['window'], $policy['window_seconds'] ?? 60));
        }
    }

    /** @param array<string, mixed> $policy */
    private function applyConfigPath(array &$policy): void
    {
        $path = $policy['config_path'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }
        $cfg = config($path);
        if (!is_array($cfg)) {
            return;
        }
        if (array_key_exists('enabled', $cfg)) {
            $policy['enabled'] = (bool) $cfg['enabled'];
        }
        if (isset($cfg['max'])) {
            $policy['max_requests'] = max(1, (int) $cfg['max']);
        }
        if (isset($cfg['max_requests'])) {
            $policy['max_requests'] = max(1, (int) $cfg['max_requests']);
        }
        if (isset($cfg['window_seconds'])) {
            $policy['window_seconds'] = max(1, (int) $cfg['window_seconds']);
        }
    }

    /** @param array<string, mixed> $policy */
    private function normalizeEnabled(array $policy): bool
    {
        if (!empty($policy['bypass'])) {
            return false;
        }
        if (!$this->isGloballyEnabled()) {
            return false;
        }

        return (bool) ($policy['enabled'] ?? true);
    }

    /** @return array<string, mixed>|null */
    private function policyBase(string $policyId): ?array
    {
        $cfg = config('rate_limit');
        if (!is_array($cfg)) {
            return null;
        }
        $policies = $cfg['policies'] ?? null;
        if (!is_array($policies) || !array_key_exists($policyId, $policies)) {
            return null;
        }
        $base = $policies[$policyId];

        return is_array($base) ? $base : null;
    }
}
