<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\manifest;

use app\common\service\admin\AdminNavPersonaService;
use app\common\service\admin\AdminNavProfileService;
use app\common\service\user\PermissionService;
use think\facade\Config;
use think\facade\Session;

/** 产品分层三轴：commercial_tier · business_domain · editor_audience（SSOT config/plugin/taxonomy.php） */
final class PluginTaxonomyService
{
    /**
     * @param array<string, mixed>|null $manifest
     * @return array{
     *   commercial_tier:string,
     *   commercial_tier_label:string,
     *   business_domain:?string,
     *   business_domain_label:string,
     *   editor_audience:string,
     *   nav_bucket:string,
     *   layout_trigger:bool
     * }
     */
    public function resolve(string $identifier, ?array $manifest = null): array
    {
        $identifier = strtolower(trim($identifier));
        $registry   = $this->registryEntry($identifier);
        $kind       = strtolower(trim((string) ($manifest['kind'] ?? '')));
        if ($kind === '') {
            $kind = 'document-addon';
        }

        $commercialTier = strtolower(trim((string) (
            $manifest['commercial']['tier']
            ?? $registry['commercial_tier']
            ?? ($this->kindDefaults()[$kind]['commercial_tier'] ?? 'B2')
        )));

        $businessDomain = $manifest['commercial']['domain']
            ?? $registry['business_domain']
            ?? ($this->kindDefaults()[$kind]['business_domain'] ?? null);
        if ($businessDomain !== null) {
            $businessDomain = strtoupper(trim((string) $businessDomain));
            if ($businessDomain === '') {
                $businessDomain = null;
            }
        }

        $editorAudience = strtolower(trim((string) (
            $manifest['commercial']['editor_audience']
            ?? $registry['editor_audience']
            ?? ($this->kindDefaults()[$kind]['editor_audience'] ?? 'none')
        ))) ?: 'none';

        $navBucket = strtolower(trim((string) (
            $registry['nav_bucket']
            ?? ($this->kindDefaults()[$kind]['nav_bucket'] ?? 'none')
        ))) ?: 'none';

        $layoutTrigger = (bool) (
            $registry['layout_trigger']
            ?? false
        );

        return [
            'commercial_tier'        => $commercialTier,
            'commercial_tier_label'  => $this->tierLabel($commercialTier),
            'business_domain'        => $businessDomain,
            'business_domain_label'  => $this->domainLabel($businessDomain),
            'editor_audience'        => $editorAudience,
            'nav_bucket'             => $navBucket,
            'layout_trigger'         => $layoutTrigger,
        ];
    }

    /** @return list<string> 触发 NavProfile=enterprise 的 identifier */
    public function layoutTriggerIdentifiers(): array
    {
        $out = [];
        foreach ($this->pluginRegistry() as $id => $row) {
            if (!empty($row['layout_trigger'])) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    /** @return array<string, string> domain => label */
    public function domainOptions(): array
    {
        $out = [];
        foreach ($this->domainCatalog() as $code => $row) {
            $out[(string) $code] = (string) ($row['label'] ?? $code);
        }

        return $out;
    }

    /** @return array<string, string> tier => label */
    public function tierOptions(): array
    {
        $out = [];
        foreach ($this->tierCatalog() as $code => $row) {
            $out[(string) $code] = (string) ($row['label'] ?? $code);
        }

        return $out;
    }

    public function visibleInDocumentEditor(string $identifier, int $userId = 0): bool
    {
        $meta = $this->resolve($identifier);
        if ($meta['editor_audience'] === 'external') {
            if ($userId <= 0) {
                $admin  = Session::get('admin_user', []);
                $userId = (int) ($admin['id'] ?? 0);
            }
            if ($userId > 0 && !app(AdminNavPersonaService::class)->allowsExternalDocumentSurfaces($userId)) {
                return false;
            }
            if (app(AdminNavProfileService::class)->isEnterprise()) {
                if ($userId <= 0) {
                    $admin  = Session::get('admin_user', []);
                    $userId = (int) ($admin['id'] ?? 0);
                }
                if ($userId <= 0) {
                    return false;
                }

                return app(PermissionService::class)->can($userId, 'admin.document.edit')
                    || app(PermissionService::class)->can($userId, 'admin.document.create');
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $manifest
     * @return array<string, mixed>
     */
    public function enrichRow(array $row, ?array $manifest = null): array
    {
        $id   = (string) ($row['identifier'] ?? '');
        $meta = $this->resolve($id, $manifest);

        return array_merge($row, $meta);
    }

    /**
     * 安装/升级时写入 plugins 表的分层字段
     *
     * @param array<string, mixed>|null $manifest
     * @return array{commercial_tier: string, business_domain: string|null}
     */
    public function dbColumnsForInstall(string $identifier, ?array $manifest = null): array
    {
        $meta = $this->resolve($identifier, $manifest);

        return [
            'commercial_tier' => $meta['commercial_tier'],
            'business_domain' => $this->normalizeDomainForDb($meta['business_domain']),
        ];
    }

    private function normalizeDomainForDb(mixed $domain): ?string
    {
        if ($domain === null) {
            return null;
        }
        $domain = strtoupper(trim((string) $domain));
        if ($domain === '' || $domain === '-') {
            return null;
        }

        return $domain;
    }

    private function tierLabel(string $tier): string
    {
        $row = $this->tierCatalog()[$tier] ?? null;

        return is_array($row) ? (string) ($row['label'] ?? $tier) : $tier;
    }

    private function domainLabel(?string $domain): string
    {
        if ($domain === null || $domain === '') {
            return '';
        }
        $row = $this->domainCatalog()[$domain] ?? null;

        return is_array($row) ? (string) ($row['label'] ?? $domain) : $domain;
    }

    /** @return array<string, array<string, mixed>> */
    private function pluginRegistry(): array
    {
        $cfg = Config::get('plugin_taxonomy.plugins', []);

        return is_array($cfg) ? $cfg : [];
    }

    /** @return array<string, mixed> */
    private function registryEntry(string $identifier): array
    {
        $manifestOverride = PluginManifestPolicyDiscovery::taxonomyOverride($identifier);
        if ($manifestOverride !== []) {
            return $manifestOverride;
        }
        $row = $this->pluginRegistry()[$identifier] ?? null;

        return is_array($row) ? $row : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function kindDefaults(): array
    {
        $cfg = Config::get('plugin_taxonomy.kind_defaults', []);

        return is_array($cfg) ? $cfg : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function tierCatalog(): array
    {
        $cfg = Config::get('plugin_taxonomy.commercial_tiers', []);

        return is_array($cfg) ? $cfg : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function domainCatalog(): array
    {
        $cfg = Config::get('plugin_taxonomy.business_domains', []);

        return is_array($cfg) ? $cfg : [];
    }
}
