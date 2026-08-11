<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\release;

use app\common\service\audit\AuditLogService;
use app\common\support\CoreUpdatePathRules;
use app\common\support\ProjectPaths;
use app\common\support\ReleaseEditionConfig;
use app\common\support\ServiceResult;

/**
 * 发行文件指纹：打包产出清单；客户站比对官方（多/少/改）。
 * 官方真源：官方货源 /static/release/fingerprints/{version}.json（非 Gitee 运行态）。
 */
final class ReleaseFilesFingerprintService
{
    /** schema 2：仅系统面（app / bootstrap / admin dist / vendor / config 白名单），不含模板·主题·uploads */
    private const SCHEMA = 2;

    public function __construct(
        private readonly CoreUpdateRemoteService $coreUpdateRemote,
        private readonly CoreUpdatePackageService $coreUpdatePackage,
        private readonly AuditLogService $auditLog,
    ) {
    }

    /**
     * 从升级 zip 生成官方指纹清单（打包机 / CLI）。
     *
     * @return array{
     *   schema:int,product:string,version:string,generated_at:string,
     *   algorithm:string,file_count:int,tree_sha256:string,files:array<string,string>
     * }|null
     */
    public function buildFromZip(string $zipPath, string $version, string $product = 'community'): ?array
    {
        if (!is_readable($zipPath)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $rel = ltrim($name, '/');
            // zip 内偶有顶层发行目录前缀（pivark-*/）：仅当首段不是已知根时剥离
            if (preg_match('#^([^/]+)/(app|bootstrap|public|template|vendor|config|weapp|install|index\.php|RELEASE\.json)(/|$)#', $rel, $m) === 1) {
                $top = (string) $m[1];
                $knownRoots = ['app', 'bootstrap', 'public', 'template', 'vendor', 'config', 'weapp', 'install'];
                if (!in_array($top, $knownRoots, true)) {
                    $rel = preg_replace('#^[^/]+/#', '', $rel) ?? $rel;
                }
            }
            if ($this->shouldSkipInventoryPath($rel)) {
                continue;
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $ctx = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }
            fclose($stream);
            $files[$rel] = hash_final($ctx);
        }
        $zip->close();

        ksort($files, SORT_STRING);

        return $this->packageManifest($product, $version, $files);
    }

    /**
     * 扫描本站「系统面」文件（指纹专用；不含模板/主题/uploads）。
     *
     * @return array<string, string> path => sha256
     */
    public function scanLocalInventory(?string $root = null): array
    {
        $root = $root !== null && $root !== ''
            ? rtrim(str_replace('\\', '/', $root), '/')
            : rtrim(str_replace('\\', '/', ProjectPaths::root()), '/');

        $files = [];
        foreach ($this->fingerprintInventoryPrefixes() as $prefix) {
            $prefix = trim(str_replace('\\', '/', $prefix), '/');
            if ($prefix === 'index.php') {
                $abs = $root . '/index.php';
                if (is_file($abs) && !$this->shouldSkipInventoryPath('index.php')) {
                    $files['index.php'] = $this->sha256File($abs);
                }
                continue;
            }
            $absDir = $root . '/' . $prefix;
            if (!is_dir($absDir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $abs = str_replace('\\', '/', $fileInfo->getPathname());
                $rel = ltrim(substr($abs, strlen($root)), '/');
                if ($this->shouldSkipInventoryPath($rel)) {
                    continue;
                }
                $hash = $this->sha256File($abs);
                if ($hash !== '') {
                    $files[$rel] = $hash;
                }
            }
        }

        // config 白名单（不在前缀整树扫）
        foreach (CoreUpdatePathRules::coreUpdateConfigFiles() as $cfg) {
            $abs = $root . '/' . $cfg;
            if (is_file($abs) && !$this->shouldSkipInventoryPath($cfg)) {
                $hash = $this->sha256File($abs);
                if ($hash !== '') {
                    $files[$cfg] = $hash;
                }
            }
        }

        $releaseJson = $root . '/RELEASE.json';
        if (is_file($releaseJson) && !$this->shouldSkipInventoryPath('RELEASE.json')) {
            $hash = $this->sha256File($releaseJson);
            if ($hash !== '') {
                $files['RELEASE.json'] = $hash;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * 指纹比对范围（≠ 升级覆盖面）：只系统内核，不含客户皮。
     *
     * include SSOT：发行升级包「系统面」；exclude 见 shouldSkipInventoryPath / compareToOfficial.scope
     *
     * @return list<string>
     */
    public function fingerprintInventoryPrefixes(): array
    {
        return [
            'app/',
            'bootstrap/', // HTTP 真入口（根 index.php 薄壳 require）
            'public/static/admin/dist/',
            'vendor/',
            'index.php',
        ];
    }

    /**
     * 比对本站 vs 官方指纹清单。
     *
     * @return ServiceResult data: match/summary/lists/official_meta
     */
    public function compareToOfficial(bool $refreshRemote = false): ServiceResult
    {
        $version = $this->coreUpdateRemote->currentVersion();
        $check = $this->coreUpdateRemote->check($refreshRemote);
        $official = $this->fetchOfficialManifest($version, $check);
        if ($official === null) {
            return ServiceResult::fail(
                '未找到官方文件指纹。请确认官方货源已发布 fingerprints/' . $version . '.json，且 updates.json 含 fingerprint_url。',
            );
        }

        $official['_source_url'] = (string) ($official['_source_url'] ?? '');

        return $this->compareRootToManifest(
            rtrim(str_replace('\\', '/', ProjectPaths::root()), '/'),
            $official,
            $version,
            $this->isCustomerRepairableWorkspace(),
        );
    }

    /**
     * 任意站点根 vs 官方指纹清单（升级 zip 生成的 fingerprints/{ver}.json）。
     * 比对范围=系统面；uploads/模板/主题/客户 weapp/data 一律不比。
     *
     * @param array<string, mixed> $official packageManifest + 可选 _source_url
     */
    public function compareRootToManifest(
        string $root,
        array $official,
        string $versionHint = '',
        ?bool $repairable = null,
    ): ServiceResult {
        $officialFiles = is_array($official['files'] ?? null) ? $official['files'] : [];
        if ($officialFiles === []) {
            return ServiceResult::fail('官方指纹清单为空');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || !is_dir($root)) {
            return ServiceResult::fail('站点根无效: ' . $root);
        }

        $version = trim($versionHint) !== ''
            ? trim($versionHint)
            : trim((string) ($official['version'] ?? ''));

        $localForOfficial = [];
        $missing = [];
        $modified = [];
        $officialComparable = 0;
        foreach ($officialFiles as $path => $sha) {
            $path = str_replace('\\', '/', (string) $path);
            $sha = strtolower(trim((string) $sha));
            if ($path === '' || $sha === '' || $this->shouldSkipInventoryPath($path)) {
                // 发行指纹里若仍含 migrations 等跳过面，不参与本站比对（避免假缺失）
                continue;
            }
            ++$officialComparable;
            $abs = $root . '/' . $path;
            if (!is_file($abs)) {
                $missing[] = $path;
                continue;
            }
            $hash = $this->sha256File($abs);
            $localForOfficial[$path] = $hash;
            if ($hash === '' || $hash !== $sha) {
                $modified[] = $path;
            }
        }

        // 多余：本站系统面有、官方可比清单无（相对当前版本发行包）
        $localScan = $this->scanLocalInventory($root);
        $extra = [];
        foreach ($localScan as $path => $_) {
            if (!isset($officialFiles[$path])) {
                $extra[] = $path;
            }
        }

        sort($missing);
        sort($modified);
        sort($extra);

        $match = $missing === [] && $modified === [] && $extra === [];
        $localTree = $this->treeSha256($localForOfficial + $localScan);
        $officialTree = strtolower(trim((string) ($official['tree_sha256'] ?? '')));
        $repairable ??= false;
        $distMissing = count(array_filter($missing, static fn ($p) => str_starts_with((string) $p, 'public/static/admin/dist/')));
        $distExtra = count(array_filter($extra, static fn ($p) => str_starts_with((string) $p, 'public/static/admin/dist/')));
        $distModified = count(array_filter($modified, static fn ($p) => str_starts_with((string) $p, 'public/static/admin/dist/')));

        $hint = $match
            ? '本站系统文件与当前版本官方发行指纹一致（不含模板/主题/uploads/setup/migrations）。'
            : '相对当前版本 v' . $version . ' 发行指纹的系统面差异：缺失=发行包有本站无；改动=同路径哈希不同；多余=本站多出且会进发行面的路径。';
        if (!$repairable) {
            $hint .= ' 当前为开发仓/诊断模式：只诊断，禁止删多余/补缺失/替换已改——发行包与 dig 本就不同构。';
        } elseif ($distMissing + $distExtra + $distModified > 0) {
            $hint .= ' 其中 admin/dist 哈希资源差异 ' . ($distMissing + $distExtra + $distModified)
                . ' 条，优先整包升级或同步 dist，单文件补齐可能对不齐入口。';
        }

        return ServiceResult::ok([
            'match'          => $match,
            'version'        => $version,
            'root'           => $root,
            'repairable'     => $repairable,
            'repair_block'   => $repairable ? '' : '仅诊断，不可用删/补/换对齐发行包',
            'official'       => [
                'version'      => (string) ($official['version'] ?? $version),
                'tree_sha256'  => $officialTree,
                'file_count'   => (int) ($official['file_count'] ?? count($officialFiles)),
                'comparable'   => $officialComparable,
                'generated_at' => (string) ($official['generated_at'] ?? ''),
                'source_url'   => (string) ($official['_source_url'] ?? ''),
            ],
            'local'          => [
                'tree_sha256' => $localTree,
                'file_count'  => count($localScan),
            ],
            'summary'        => [
                'missing'  => count($missing),
                'modified' => count($modified),
                'extra'    => count($extra),
            ],
            'dist_summary'   => [
                'missing'  => $distMissing,
                'modified' => $distModified,
                'extra'    => $distExtra,
            ],
            'missing'        => array_slice($missing, 0, 200),
            'modified'       => array_slice($modified, 0, 200),
            'extra'          => array_slice($extra, 0, 200),
            'truncated'     => [
                'missing'  => max(0, count($missing) - 200),
                'modified' => max(0, count($modified) - 200),
                'extra'    => max(0, count($extra) - 200),
            ],
            'hint'           => $hint,
            'scope'          => [
                'include' => $this->fingerprintInventoryPrefixes(),
                'exclude' => [
                    'template/',
                    'public/static/theme/',
                    'public/uploads/',
                    'public/static/thumb/',
                    'weapp/',
                    'data/',
                    'admin/',
                    'app/database/migrations/',
                    'app/database/setup/',
                ],
            ],
        ], $match ? '与官方一致' : '与官方不一致');
    }

    /**
     * 升前所见即所得：本站系统面 vs 目标版官方指纹 → 将新增/覆盖/删除。
     * 不下完整升级包；范围同指纹 schema 2。
     *
     * @return ServiceResult
     */
    public function previewUpgradeImpact(string $targetVersion = '', bool $refreshRemote = false): ServiceResult
    {
        $check = $this->coreUpdateRemote->check($refreshRemote);
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '') {
            $targetVersion = trim((string) ($check['latest'] ?? ''));
        }
        if ($targetVersion === '') {
            return ServiceResult::fail('无法确定目标版本');
        }

        $current = $this->coreUpdateRemote->currentVersion();
        $official = $this->fetchOfficialManifest($targetVersion, $check);
        if ($official === null) {
            return ServiceResult::fail(
                '未找到目标版官方指纹 fingerprints/' . $targetVersion . '.json，无法预览将变更文件。',
            );
        }
        $targetFiles = is_array($official['files'] ?? null) ? $official['files'] : [];
        if ($targetFiles === []) {
            return ServiceResult::fail('目标版指纹清单为空');
        }

        $root = rtrim(str_replace('\\', '/', ProjectPaths::root()), '/');
        $localScan = $this->scanLocalInventory($root);

        $willAdd = [];
        $willUpdate = [];
        foreach ($targetFiles as $path => $sha) {
            $path = str_replace('\\', '/', (string) $path);
            $sha = strtolower(trim((string) $sha));
            if ($path === '' || $sha === '' || $this->shouldSkipInventoryPath($path)) {
                continue;
            }
            if (!isset($localScan[$path])) {
                $willAdd[] = $path;
                continue;
            }
            if (strtolower((string) $localScan[$path]) !== $sha) {
                $willUpdate[] = $path;
            }
        }

        $willRemove = [];
        foreach (CoreUpdatePathRules::coreUpdateDeletePaths() as $rel) {
            $rel = str_replace('\\', '/', trim($rel, '/'));
            if ($rel === '') {
                continue;
            }
            $abs = $root . '/' . $rel;
            if (is_file($abs) || is_dir($abs)) {
                $willRemove[] = $rel;
            }
        }
        foreach (CoreUpdatePathRules::coreUpdateReplaceTreePrefixes() as $prefix) {
            $prefix = rtrim(str_replace('\\', '/', $prefix), '/') . '/';
            foreach ($localScan as $path => $_) {
                if (!str_starts_with($path, $prefix)) {
                    continue;
                }
                if (!isset($targetFiles[$path])) {
                    $willRemove[] = $path;
                }
            }
        }
        $willRemove = array_values(array_unique($willRemove));

        sort($willAdd);
        sort($willUpdate);
        sort($willRemove);

        $listCap = 100;
        $addCount = count($willAdd);
        $updCount = count($willUpdate);
        $rmCount = count($willRemove);

        return ServiceResult::ok([
            'current'        => $current,
            'target'         => $targetVersion,
            'official'       => [
                'version'      => (string) ($official['version'] ?? $targetVersion),
                'tree_sha256'  => strtolower(trim((string) ($official['tree_sha256'] ?? ''))),
                'file_count'   => (int) ($official['file_count'] ?? count($targetFiles)),
                'source_url'   => (string) ($official['_source_url'] ?? ''),
            ],
            'local'          => [
                'file_count' => count($localScan),
            ],
            'summary'        => [
                'will_add'    => $addCount,
                'will_update' => $updCount,
                'will_remove' => $rmCount,
                'total'       => $addCount + $updCount + $rmCount,
            ],
            'will_add'       => array_slice($willAdd, 0, $listCap),
            'will_update'    => array_slice($willUpdate, 0, $listCap),
            'will_remove'    => array_slice($willRemove, 0, $listCap),
            'truncated'     => [
                'will_add'    => max(0, $addCount - $listCap),
                'will_update' => max(0, $updCount - $listCap),
                'will_remove' => max(0, $rmCount - $listCap),
            ],
            'hint'           => '以下为升到 v' . $targetVersion
                . ' 后系统面预计变更（与官方指纹比对，升前无需下载完整包）。不含模板/主题/uploads/客户插件。',
            'scope'          => [
                'include' => $this->fingerprintInventoryPrefixes(),
                'exclude' => ['template/', 'public/static/theme/', 'public/uploads/', 'public/static/thumb/', 'weapp/', 'data/', 'admin/'],
            ],
        ], '升级影响预览');
    }

    public const APPLY_DELETE_EXTRA = 'delete_extra';

    public const APPLY_RESTORE_MISSING = 'restore_missing';

    public const APPLY_REPLACE_MODIFIED = 'replace_modified';

    /**
     * 比对结果勾选修复：删多余 / 补缺失 / 替换已改。
     *
     * @param list<string>|array<int, mixed> $paths
     */
    public function applyCompareActions(string $op, array $paths): ServiceResult
    {
        if (!$this->isCustomerRepairableWorkspace()) {
            return ServiceResult::fail('开发仓（dev/digtools）禁止用删/补/换对齐发行包；比对仅供诊断');
        }

        $op = strtolower(trim($op));
        if (!in_array($op, [self::APPLY_DELETE_EXTRA, self::APPLY_RESTORE_MISSING, self::APPLY_REPLACE_MODIFIED], true)) {
            return ServiceResult::fail('未知操作');
        }

        $normalized = [];
        foreach ($paths as $p) {
            $rel = str_replace('\\', '/', trim((string) $p, '/'));
            if ($rel === '' || str_contains($rel, '..') || $this->shouldSkipInventoryPath($rel)) {
                continue;
            }
            $normalized[$rel] = true;
        }
        $normalized = array_keys($normalized);
        if ($normalized === []) {
            return ServiceResult::fail('未选择有效系统面路径');
        }
        if (count($normalized) > 100) {
            return ServiceResult::fail('单次最多处理 100 个文件，请分批勾选');
        }

        $check = $this->coreUpdateRemote->check(false);
        $version = $this->coreUpdateRemote->currentVersion();
        if ($version === '') {
            return ServiceResult::fail('无法读取本站版本号');
        }
        $official = $this->fetchOfficialManifest($version, $check);
        if ($official === null) {
            return ServiceResult::fail('未找到官方指纹，无法安全修复');
        }
        $officialFiles = is_array($official['files'] ?? null) ? $official['files'] : [];
        if ($officialFiles === []) {
            return ServiceResult::fail('官方指纹清单为空');
        }

        $root = rtrim(str_replace('\\', '/', ProjectPaths::root()), '/');
        $localScan = $this->scanLocalInventory($root);

        $ok = [];
        $fail = [];

        if ($op === self::APPLY_DELETE_EXTRA) {
            foreach ($normalized as $rel) {
                if (isset($officialFiles[$rel])) {
                    $fail[] = ['path' => $rel, 'reason' => '官方清单含此路径，不能当「多余」删除'];
                    continue;
                }
                if (!isset($localScan[$rel])) {
                    $fail[] = ['path' => $rel, 'reason' => '本站系统面清单中无此文件'];
                    continue;
                }
                $abs = $root . '/' . $rel;
                if (!is_file($abs)) {
                    $fail[] = ['path' => $rel, 'reason' => '文件不存在或不是普通文件'];
                    continue;
                }
                if (!@unlink($abs)) {
                    $fail[] = ['path' => $rel, 'reason' => '删除失败（权限？）'];
                    continue;
                }
                $ok[] = $rel;
            }
        } else {
            $toExtract = [];
            foreach ($normalized as $rel) {
                $officialSha = strtolower(trim((string) ($officialFiles[$rel] ?? '')));
                if ($officialSha === '') {
                    $fail[] = ['path' => $rel, 'reason' => '官方指纹无此路径'];
                    continue;
                }
                $abs = $root . '/' . $rel;
                $exists = is_file($abs);
                if ($op === self::APPLY_RESTORE_MISSING) {
                    if ($exists) {
                        $fail[] = ['path' => $rel, 'reason' => '文件已存在，请用「替换已改」'];
                        continue;
                    }
                } else {
                    if (!$exists) {
                        $fail[] = ['path' => $rel, 'reason' => '文件不存在，请用「补齐缺失」'];
                        continue;
                    }
                    $localHash = $this->sha256File($abs);
                    $localHash = is_string($localHash) ? strtolower($localHash) : '';
                    if ($localHash === $officialSha) {
                        $fail[] = ['path' => $rel, 'reason' => '哈希已与官方一致，无需替换'];
                        continue;
                    }
                }
                $toExtract[] = $rel;
            }

            if ($toExtract !== []) {
                $downloadUrl = '';
                $releases = is_array($check['releases'] ?? null) ? $check['releases'] : [];
                foreach ($releases as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (trim((string) ($row['version'] ?? '')) !== $version) {
                        continue;
                    }
                    $downloadUrl = trim((string) ($row['download_url'] ?? ''));
                    break;
                }
                if ($downloadUrl === '') {
                    $downloadUrl = trim((string) ($check['download_url'] ?? ''));
                }
                $pkg = $this->coreUpdatePackage->ensureCachedPackage(
                    $this->coreUpdateRemote->resolveDownloadUrl($downloadUrl),
                );
                if (!$pkg->isOk()) {
                    return ServiceResult::fail((string) ($pkg->message() ?: '无法获取官方升级包'));
                }
                $package = (string) (($pkg->dataArray())['package'] ?? '');
                if ($package === '' || !is_readable($package)) {
                    return ServiceResult::fail('官方升级包路径无效');
                }

                $extracted = $this->coreUpdatePackage->extractRelativePathsToRoot($package, $toExtract);
                foreach ($extracted['ok'] as $rel) {
                    $abs = $root . '/' . $rel;
                    $want = strtolower(trim((string) ($officialFiles[$rel] ?? '')));
                    $got = is_file($abs) ? $this->sha256File($abs) : '';
                    if ($want !== '' && $got !== '' && $got !== $want) {
                        $fail[] = ['path' => $rel, 'reason' => '写入后哈希与官方指纹不一致'];
                        @unlink($abs);
                        continue;
                    }
                    $ok[] = $rel;
                }
                foreach ($extracted['fail'] as $row) {
                    if (is_array($row)) {
                        $fail[] = $row;
                    }
                }
            }
        }

        $this->auditLog->operate(
            'files_compare_apply',
            'upgrade',
            [
                'op'      => $op,
                'version' => $version,
                'ok'      => count($ok),
                'fail'    => count($fail),
                'paths'   => array_slice($ok, 0, 40),
            ],
            $fail === [],
        );

        $msg = match ($op) {
            self::APPLY_DELETE_EXTRA => '已删除多余文件',
            self::APPLY_RESTORE_MISSING => '已补齐缺失文件',
            default => '已替换已改文件',
        };

        return ServiceResult::ok([
            'op'      => $op,
            'version' => $version,
            'ok'      => $ok,
            'fail'    => $fail,
            'summary' => [
                'ok'   => count($ok),
                'fail' => count($fail),
            ],
            'dist_hint' => '若涉及 public/static/admin/dist 哈希文件名差异，单文件补齐可能仍对不齐入口，优先整包升级或同步 dist。',
        ], $msg . '（成功 ' . count($ok) . ' · 失败 ' . count($fail) . '）');
    }

    /**
     * @param array<string, mixed> $check
     * @return array<string, mixed>|null
     */
    public function fetchOfficialManifest(string $version, array $check): ?array
    {
        $version = trim($version);
        $url = '';
        $releases = is_array($check['releases'] ?? null) ? $check['releases'] : [];
        foreach ($releases as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['version'] ?? '')) !== $version) {
                continue;
            }
            $url = trim((string) ($row['fingerprint_url'] ?? ''));
            break;
        }
        if ($url === '') {
            $url = '/static/release/fingerprints/' . rawurlencode($version) . '.json';
        }
        $resolved = $this->coreUpdateRemote->resolveDownloadUrl($url);
        if ($resolved === '') {
            return null;
        }

        // 本机优先：相对路径直接读 public（发版机 / dig 自检）
        if (str_starts_with($url, '/static/release/fingerprints/')) {
            $local = ProjectPaths::root() . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $url);
            if (is_readable($local)) {
                $parsed = json_decode((string) file_get_contents($local), true);
                if (is_array($parsed)) {
                    $parsed['_source_url'] = $url;

                    return $parsed;
                }
            }
        }

        $raw = $this->httpGet($resolved);
        if ($raw === null || $raw === '') {
            return null;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return null;
        }
        $parsed['_source_url'] = $resolved;

        return $parsed;
    }

    /**
     * @param array<string, string> $files
     * @return array{
     *   schema:int,product:string,version:string,generated_at:string,
     *   algorithm:string,file_count:int,tree_sha256:string,files:array<string,string>
     * }
     */
    public function packageManifest(string $product, string $version, array $files): array
    {
        ksort($files, SORT_STRING);

        return [
            'schema'       => self::SCHEMA,
            'product'      => $product,
            'version'      => $version,
            'generated_at' => gmdate('c'),
            'algorithm'    => 'sha256',
            'file_count'   => count($files),
            'tree_sha256'  => $this->treeSha256($files),
            'files'        => $files,
        ];
    }

    /** @param array<string, string> $files */
    public function treeSha256(array $files): string
    {
        ksort($files, SORT_STRING);
        $buf = '';
        foreach ($files as $path => $sha) {
            $buf .= $path . "\t" . strtolower($sha) . "\n";
        }

        return hash('sha256', $buf);
    }

    /** 可读则 sha256；锁/权限失败返回空串（不当 Fatal） */
    private function sha256File(string $abs): string
    {
        if ($abs === '' || !is_file($abs)) {
            return '';
        }
        set_error_handler(static fn (): bool => true);
        try {
            $hash = hash_file('sha256', $abs);
        } finally {
            restore_error_handler();
        }

        return is_string($hash) && $hash !== '' ? strtolower($hash) : '';
    }

    public function shouldSkipInventoryPath(string $rel): bool
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return true;
        }
        // 客户皮 / 运行态：指纹不比
        if (
            str_starts_with($rel, 'template/')
            || str_starts_with($rel, 'public/static/theme/')
            || str_starts_with($rel, 'public/uploads/')
            || str_starts_with($rel, 'public/static/thumb/')
            || str_starts_with($rel, 'weapp/')
            || str_starts_with($rel, 'data/')
            || str_starts_with($rel, 'admin/')
        ) {
            return true;
        }
        // 发行包不携带（与 CommunityPackRules database noise 同构；禁内核直引 digtools）
        if ($rel === 'app/database/setup' || str_starts_with($rel, 'app/database/setup/')) {
            return true;
        }
        // 说明/文档噪音（升级说明、README*）
        $base = basename($rel);
        if (preg_match('/^(readme.*|升级说明\\.txt|changelog.*)$/i', $base) === 1) {
            return true;
        }
        if (CoreUpdatePathRules::shouldSkipCoreUpdatePath($rel)) {
            return true;
        }
        // 只保留系统面前缀 + config 白名单 + RELEASE.json
        if ($rel === 'index.php' || $rel === 'RELEASE.json') {
            return false;
        }
        if (in_array($rel, CoreUpdatePathRules::coreUpdateConfigFiles(), true)) {
            return false;
        }
        foreach ($this->fingerprintInventoryPrefixes() as $prefix) {
            $prefix = trim(str_replace('\\', '/', $prefix), '/');
            if ($prefix === 'index.php') {
                continue;
            }
            // 必须带目录分隔，避免 app 误匹配 application/
            if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 客户/发行装机可勾选修复；dig/dev 仓与 Community 发行包不同构，只允许诊断。
     */
    public function isCustomerRepairableWorkspace(): bool
    {
        if (ReleaseEditionConfig::edition() === 'dev') {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', ProjectPaths::root()), '/');
        if (is_dir($root . '/digtools')) {
            return false;
        }

        return true;
    }

    private function httpGet(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'PivArk-ReleaseFingerprint/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && is_string($body)) {
                return $body;
            }

            return null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: PivArk-ReleaseFingerprint/1.0\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);

        return is_string($body) ? $body : null;
    }
}
