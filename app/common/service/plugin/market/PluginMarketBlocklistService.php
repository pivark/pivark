<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\plugin\market\PluginMarketCatalogSecurityService;
use app\common\service\plugin\market\PluginMarketCatalogSignatureService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;

final class PluginMarketBlocklistService
{
    public const SEVERITY_WARN      = 'warn';
    public const SEVERITY_DISABLE   = 'disable';
    public const SEVERITY_UNINSTALL = 'uninstall';

    public function __construct(
        private readonly PluginMarketCatalogSecurityService $pluginMarketCatalogSecurityService,
        private readonly PluginPackageService $pluginPackageService,
        private readonly PluginMarketCatalogSignatureService $pluginMarketCatalogSignature,
    ) {
    }

    private const FILE = 'plugin_market_blocklist.json';

    public function isBlocked(string $identifier): bool
    {
        return $this->entry($identifier) !== null;
    }

    /**
     * @return list<string>
     */
    public function blockedIdentifiers(): array
    {
        $ids = array_keys($this->localEntries());
        foreach (array_keys($this->pluginMarketCatalogSecurityService->remoteBlocklistEntries()) as $id) {
            $ids[] = $id;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => strtolower(trim((string) $v)),
            $ids
        ))));
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function localEntries(): array
    {
        return $this->loadLocal()['identifiers'];
    }

    /**
     * @return array{reason:string,blocked_at:string,listing_id?:int,operator?:string}|null
     */
    public function entry(string $identifier): ?array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        $local = $this->localEntries()[$identifier] ?? null;
        if (is_array($local)) {
            return $this->normalizeBlocklistEntry($local);
        }

        return $this->pluginMarketCatalogSecurityService->remoteBlocklistEntry($identifier);
    }

    public function blockReason(string $identifier): string
    {
        $entry = $this->entry($identifier);

        return trim((string) ($entry['reason'] ?? ''));
    }

    /**
     * @return ServiceResult
     */
    public function block(
        string $identifier,
        string $reason = '',
        int $listingId = 0,
        string $operator = 'system',
        string $severity = self::SEVERITY_DISABLE,
        ?string $graceUntil = null
    ): ServiceResult {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || $this->pluginPackageService->safeIdentifier($identifier) === null) {
            return ServiceResult::fail('插件标识无效');
        }

        $data = $this->loadLocal();
        $data['identifiers'][$identifier] = [
            'reason'      => trim($reason) !== '' ? trim($reason) : '运营紧急下架',
            'blocked_at'  => AppTime::format('c'),
            'listing_id'  => max(0, $listingId),
            'operator'    => $operator,
            'source'      => 'local',
            'severity'    => $this->normalizeSeverity($severity),
            'grace_until' => $this->normalizeGraceUntil($graceUntil),
        ];
        if (!$this->saveLocal($data)) {
            return ServiceResult::fail('无法写入 blocklist');
        }

        $this->pluginMarketCatalogSecurityService->syncFromLocalBlocklist();

        return ServiceResult::ok(null, '已加入下架名单');
    }

    /**
     * @return ServiceResult
     */
    public function unblock(string $identifier): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $data       = $this->loadLocal();
        if (!isset($data['identifiers'][$identifier])) {
            return ServiceResult::ok(null, '本地 blocklist 无此条目');
        }
        unset($data['identifiers'][$identifier]);
        if (!$this->saveLocal($data)) {
            return ServiceResult::fail('无法写入 blocklist');
        }

        $this->pluginMarketCatalogSecurityService->syncFromLocalBlocklist();

        return ServiceResult::ok(null, '已移出本地下架名单');
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        return $this->blockedIdentifiers();
    }

    public function guardInstall(string $identifier): ?string
    {
        if (!$this->isBlocked($identifier)) {
            return null;
        }
        $entry  = $this->entry($identifier);
        $reason = trim((string) ($entry['reason'] ?? ''));
        $action = $this->effectiveSeverity(is_array($entry) ? $entry : []);
        // 警告期（含 grace_until 未到期）：允许装，与 effectiveSeverity 语义对齐（J96）
        if ($action === self::SEVERITY_WARN) {
            OpsLog::businessWarning('plugin_market_blocklist_warn_install_allowed', [
                'identifier' => strtolower(trim($identifier)),
                'reason'     => $reason,
            ]);

            return null;
        }
        $suffix = match ($action) {
            self::SEVERITY_UNINSTALL => '（将强制移除）',
            default                  => '',
        };

        return '插件「' . $identifier . '」已被运营下架' . $suffix
            . ($reason !== '' ? '：' . $reason : '');
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function effectiveSeverity(array $entry): string
    {
        $grace = trim((string) ($entry['grace_until'] ?? ''));
        if ($grace !== '') {
            $ts = strtotime($grace);
            if ($ts !== false && $ts > time()) {
                return self::SEVERITY_WARN;
            }
        }

        return $this->normalizeSeverity((string) ($entry['severity'] ?? self::SEVERITY_DISABLE));
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, [self::SEVERITY_WARN, self::SEVERITY_DISABLE, self::SEVERITY_UNINSTALL], true)
            ? $severity
            : self::SEVERITY_DISABLE;
    }

    private function normalizeGraceUntil(?string $graceUntil): string
    {
        $graceUntil = trim((string) $graceUntil);
        if ($graceUntil === '') {
            return '';
        }
        $ts = strtotime($graceUntil);

        return $ts !== false ? AppTime::format('c', $ts) : '';
    }

    /**
     * @return array{identifiers:array<string,array<string,mixed>>}
     */
    private function loadLocal(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return ['identifiers' => []];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return ['identifiers' => []];
        }
        $err = $this->pluginMarketCatalogSignature->verifyBlocklist($json);
        if ($err !== null) {
            OpsLog::businessWarning('plugin_market_blocklist_signature_invalid', [
                'path'  => $path,
                'error' => $err,
            ]);

            return ['identifiers' => []];
        }
        $ids = is_array($json['identifiers'] ?? null) ? $json['identifiers'] : [];

        return ['identifiers' => $ids];
    }

    /**
     * @param array{identifiers:array<string,array<string,mixed>>} $data
     */
    private function saveLocal(array $data): bool
    {
        $path = $this->path();
        $dir  = dirname($path);
        if (!is_dir($dir) && !LocalFile::mkdirIfMissing($dir)) {
            return false;
        }
        $payload = json_encode(
            $this->pluginMarketCatalogSignature->attachBlocklistSignature($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($payload)) {
            return false;
        }

        return LocalFile::putContents($path, $payload . "\n");
    }

    private function path(): string
    {
        return rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . self::FILE;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{reason:string,blocked_at:string,listing_id?:int,operator?:string,severity?:string,grace_until?:string}
     */
    private function normalizeBlocklistEntry(array $entry): array
    {
        $out = [
            'reason'      => (string) ($entry['reason'] ?? ''),
            'blocked_at'  => (string) ($entry['blocked_at'] ?? ''),
            'severity'    => $this->normalizeSeverity((string) ($entry['severity'] ?? self::SEVERITY_DISABLE)),
            'grace_until' => trim((string) ($entry['grace_until'] ?? '')),
        ];
        if (isset($entry['listing_id'])) {
            $out['listing_id'] = (int) $entry['listing_id'];
        }
        if (isset($entry['operator']) && trim((string) $entry['operator']) !== '') {
            $out['operator'] = (string) $entry['operator'];
        }

        return $out;
    }
}
