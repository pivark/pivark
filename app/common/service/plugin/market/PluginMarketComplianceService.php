<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\gateway\PluginGatewayAuditService;
use app\common\service\plugin\package\PluginPackageAuditService;
use app\common\service\plugin\PluginService;
use app\common\support\AppTime;
use app\common\support\ProjectPaths;

/** 官方市场 catalog 包合规扫描（授权平台运营 / 发版校验） */
final class PluginMarketComplianceService
{
    public function __construct(
        private readonly PluginPackageAuditService $pluginPackageAuditService,
        private readonly PluginGatewayAuditService $pluginGatewayAuditService,
        private readonly PluginService $pluginService,
    ) {
    }

    /**
     * @return array{
     *   catalog_path:string,
     *   scanned_at:string,
     *   summary:array{total:int,pass:int,warn:int,block:int,gateway_fail:int},
     *   plugins:list<array<string,mixed>>,
     *   installed_gateway_issues:list<array<string,mixed>>
     * }
     */
    public function catalogReport(): array
    {
        $catalogPath = $this->catalogPath();
        $plugins     = [];
        $summary     = [
            'total'         => 0,
            'pass'          => 0,
            'warn'          => 0,
            'block'         => 0,
            'gateway_fail'  => 0,
        ];

        if (is_file($catalogPath)) {
            $decoded = json_decode((string) file_get_contents($catalogPath), true);
            $rows    = is_array($decoded['plugins'] ?? null) ? $decoded['plugins'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $entry = $this->scanCatalogEntry($row);
                if ($entry === null) {
                    continue;
                }
                $plugins[] = $entry;
                $summary['total']++;
                $level = (string) ($entry['audit_level'] ?? 'pass');
                if ($level === 'block') {
                    $summary['block']++;
                } elseif ($level === 'warn') {
                    $summary['warn']++;
                } else {
                    $summary['pass']++;
                }
                if (($entry['gateway_ok'] ?? true) !== true) {
                    $summary['gateway_fail']++;
                }
            }
        }

        return [
            'catalog_path'          => $catalogPath,
            'scanned_at'            => AppTime::now(),
            'summary'               => $summary,
            'plugins'               => $plugins,
            'installed_gateway_issues' => $this->installedGatewaySummary(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function scanCatalogEntry(array $row): ?array
    {
        $identifier = strtolower(trim((string) ($row['identifier'] ?? '')));
        if ($identifier === '') {
            return null;
        }

        $packageUrl = trim((string) ($row['package_url'] ?? ''));
        $zipPath    = $this->resolvePackagePath($packageUrl);
        if ($zipPath === null || !is_file($zipPath)) {
            return [
                'identifier'          => $identifier,
                'name'                => (string) ($row['name'] ?? $identifier),
                'version'             => (string) ($row['version'] ?? ''),
                'package_url'         => $packageUrl,
                'package_missing'     => true,
                'audit_level'         => 'block',
                'audit_blocks'        => ['市场包文件不存在或未同步到本站'],
                'audit_warns'         => [],
                'gateway_ok'          => false,
                'gateway_total'       => 0,
                'gateway_violations'  => [],
            ];
        }

        $audit = $this->pluginPackageAuditService->auditZipFile(
            $zipPath,
            PluginPackageAuditService::AUDIT_CONTEXT_MARKET_CATALOG
        );
        $audit = $this->pluginPackageAuditService->applyCatalogSha256(
            $audit,
            strtolower(trim((string) ($row['package_sha256'] ?? '')))
        );

        $gateway = ['ok' => true, 'total' => 0, 'violations' => []];
        $zip     = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            try {
                $gateway = $this->pluginGatewayAuditService->auditZipArchive($zip, $identifier);
            } finally {
                $zip->close();
            }
        }

        return [
            'identifier'         => $identifier,
            'name'               => (string) ($row['name'] ?? $identifier),
            'version'            => (string) ($row['version'] ?? ''),
            'package_url'        => $packageUrl,
            'package_missing'    => false,
            'audit_level'        => (string) $audit['level'],
            'audit_blocks'       => $audit['blocks'],
            'audit_warns'        => $audit['warns'],
            'gateway_ok'         => (bool) $gateway['ok'],
            'gateway_total'      => (int) $gateway['total'],
            'gateway_violations' => $gateway['violations'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function installedGatewaySummary(): array
    {
        $out    = [];
        $scopes = $this->pluginGatewayAuditService->normalizeScopes('service,api,admin,boot');
        foreach ($this->pluginService->listInstalledIdentifiers() as $id) {
            $total      = 0;
            $violations = [];
            foreach ($scopes as $scope) {
                $report = $this->pluginGatewayAuditService->auditPluginDirectory($id, $scope);
                $total += $report['total'];
                foreach ($report['violations'] as $row) {
                    $violations[] = $row;
                }
            }
            if ($total < 1) {
                continue;
            }
            $out[] = [
                'identifier' => $id,
                'total'      => $total,
                'violations' => $violations,
            ];
        }

        return $out;
    }

    private function catalogPath(): string
    {
        return rtrim(ProjectPaths::root(), '/\\') . '/public/static/market/catalog.json';
    }

    private function resolvePackagePath(string $packageUrl): ?string
    {
        $packageUrl = trim($packageUrl);
        if ($packageUrl === '') {
            return null;
        }
        $root = rtrim(ProjectPaths::root(), '/\\');
        if (str_starts_with($packageUrl, '/static/')) {
            $path = $root . '/public' . $packageUrl;

            return is_file($path) ? $path : null;
        }
        if (preg_match('#^https?://[^/]+(/static/market/.+\.zip)(?:\?.*)?$#i', $packageUrl, $m)) {
            $path = $root . '/public' . $m[1];

            return is_file($path) ? $path : null;
        }

        return null;
    }
}
