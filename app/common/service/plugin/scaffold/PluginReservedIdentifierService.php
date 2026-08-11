<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\scaffold;

use app\common\service\plugin\PluginService;

/** 插件 identifier 黑名单校验（SSOT config/plugin/reserved_identifiers.php） */
final class PluginReservedIdentifierService
{
    public function __construct(
        private readonly PluginService $plugin,
    ) {
    }

    /** identifier：2–32 位，小写开头，字母数字下划线连字符 */
    public const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_-]{1,31}$/';

    /** package：vendor/slug 各 2–32 位 */
    public const PACKAGE_PATTERN = '/^[a-z][a-z0-9_-]{1,31}\/[a-z][a-z0-9_-]{1,31}$/';

    /** @var array<string, string>|null id => tier */
    private ?array $indexCache = null;

    /** @var list<string>|null */
    private ?array $officialWeappCache = null;

    /**
     * 是否命中黑名单（normalize 后）
     */
    public function isReserved(string $identifier): bool
    {
        return $this->conflict($identifier) !== null;
    }

    /**
     * @return array{tier:string,label:string,message:string,suggest:string}|null
     */
    public function conflict(string $identifier): ?array
    {
        $id = strtolower(trim($identifier));
        if ($id === '') {
            return null;
        }

        $tier = $this->lookupTier($id);
        if ($tier === null) {
            return null;
        }

        $labels = $this->tierLabels();

        return [
            'tier'    => $tier,
            'label'   => (string) ($labels[$tier] ?? $tier),
            'message' => $this->messageFor($id, $tier),
            'suggest' => $this->suggestPrefix($id),
        ];
    }

    /** 供安装/脚手架/上传：非 null 即为拒绝原因（单行中文） */
    public function validateForPlugin(string $identifier): ?string
    {
        if ($this->normalizeIdentifier($identifier) === null) {
            return $this->identifierFormatMessage();
        }

        $conflict = $this->conflict($identifier);

        return $conflict !== null ? (string) $conflict['message'] : null;
    }

    public function identifierFormatMessage(): string
    {
        return '插件标识无效（2–32 位小写字母、数字、下划线或连字符，且以字母开头）';
    }

    public function packageFormatMessage(): string
    {
        return '包名格式须为 vendor/slug（各 2–32 位小写字母、数字、下划线或连字符，且以字母开头）';
    }

    public function normalizeIdentifier(string $identifier): ?string
    {
        $id = strtolower(trim($identifier));
        if ($id === '' || !preg_match(self::IDENTIFIER_PATTERN, $id)) {
            return null;
        }

        return $id;
    }

    /** 包名 vendor/slug 段校验（空包名跳过；pivark vendor 禁止第三方使用） */
    public function validatePackageForPlugin(string $package): ?string
    {
        $package = strtolower(trim($package));
        if ($package === '') {
            return null;
        }
        if (!preg_match(self::PACKAGE_PATTERN, $package)) {
            return $this->packageFormatMessage();
        }
        $parts = explode('/', $package, 2);
        $vendor = $parts[0] ?? '';
        $slug = $parts[1] ?? '';
        if ($vendor === 'pivark') {
            return '不可使用 pivark 官方包名空间';
        }
        foreach ([$vendor, $slug] as $segment) {
            if ($this->isReserved($segment)) {
                $conflict = $this->conflict($segment);

                return '包名段「' . $segment . '」为系统保留名'
                    . ($conflict !== null ? '：' . (string) $conflict['message'] : '，请换用带厂商前缀的名称');
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,identifier:string,tier?:string,label?:string,message?:string,suggest?:string}
     */
    public function checkPayload(string $identifier): array
    {
        $id = $this->normalizeIdentifier($identifier);
        if ($id === null) {
            return [
                'ok'         => false,
                'identifier' => strtolower(trim($identifier)),
                'message'    => $this->identifierFormatMessage(),
            ];
        }

        $conflict = $this->conflict($id);
        if ($conflict === null) {
            return ['ok' => true, 'identifier' => $id];
        }

        return array_merge(['ok' => false, 'identifier' => $id], $conflict);
    }

    /**
     * 脚手架 meta：规模 + 分层说明（不下发完整 80+ 列表）
     *
     * @return array{count:int,tier_labels:array<string,string>,hint:string,doc_section:string}
     */
    public function scaffoldMeta(): array
    {
        return $this->namingPolicyMeta();
    }

    /**
     * 命名策略 meta：规模 + 分层说明 + 各层示例（不下发完整黑名单）
     *
     * @return array{
     *   count:int,
     *   tier_labels:array<string,string>,
     *   tier_examples:array<string,list<string>>,
     *   hint:string,
     *   doc_section:string,
     *   lookup_hint:string
     * }
     */
    public function namingPolicyMeta(): array
    {
        return [
            'count'         => count($this->index()),
            'tier_labels'   => $this->tierLabels(),
            'tier_examples' => $this->tierExamples(),
            'hint'          => '标识请用小写+下划线/连字符，并加厂商前缀（如 acme_my_tool），勿用 download、member、plugin 等系统保留名',
            'doc_section'   => 'docs/06-插件/插件市场与命名规范.md#33-identifier-黑名单',
            'lookup_hint'   => '输入单个词（identifier 或 package 的 vendor/slug 段）可查询是否在黑名单',
        ];
    }

    /**
     * @return array{ok:bool,package:string,message?:string}
     */
    public function checkPackagePayload(string $package): array
    {
        $package = strtolower(trim($package));
        if ($package === '') {
            return ['ok' => false, 'package' => '', 'message' => '包名不能为空'];
        }
        $msg = $this->validatePackageForPlugin($package);
        if ($msg !== null) {
            return ['ok' => false, 'package' => $package, 'message' => $msg];
        }

        return ['ok' => true, 'package' => $package];
    }

    /**
     * 单词查询：identifier 或 package vendor/slug 段是否在黑名单
     *
     * @return array{
     *   ok:bool,
     *   term:string,
     *   reserved?:bool,
     *   tier?:string,
     *   label?:string,
     *   message?:string,
     *   suggest?:string
     * }
     */
    public function lookupSegment(string $term): array
    {
        $term = strtolower(trim($term));
        if ($term === '') {
            return ['ok' => false, 'term' => '', 'message' => '请输入要查询的词'];
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $term)) {
            return [
                'ok'      => false,
                'term'    => $term,
                'message' => '仅支持小写字母开头的单词（2–32 位，字母数字下划线连字符）',
            ];
        }
        $conflict = $this->conflict($term);
        if ($conflict === null) {
            return ['ok' => true, 'term' => $term, 'reserved' => false];
        }

        return array_merge(
            ['ok' => true, 'term' => $term, 'reserved' => true],
            $conflict,
        );
    }

    /** @return array<string, string> */
    public function index(): array
    {
        if ($this->indexCache !== null) {
            return $this->indexCache;
        }

        $cfg = config('plugin.reserved_identifiers');
        if (!is_array($cfg)) {
            return $this->indexCache = [];
        }

        $out = [];
        foreach ($this->kernelIdentifiers() as $id) {
            $this->registerTierId($out, $id, 'kernel');
        }
        foreach ($this->shippedOfficialWeappIdentifiers() as $id) {
            $this->registerTierId($out, $id, 'official_weapp');
        }
        foreach (['platform_module', 'route', 'legacy', 'meta'] as $key) {
            $list = $cfg[$key] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $raw) {
                $this->registerTierId($out, (string) $raw, $key === 'platform_module' ? 'platform_module' : (string) $key);
            }
        }

        // route 与 UrlPathService 对齐（单页/标签路径）
        foreach (\app\common\service\infra\UrlPathService::RESERVED as $raw) {
            $this->registerTierId($out, (string) $raw, 'route');
        }

        // 后台 SPA 一级段（菜单 hash）
        foreach ([
            'content', 'dashboard', 'member', 'payment', 'platform', 'plugin', 'portal',
            'product', 'profile', 'seo', 'site', 'system', 'weapp',
        ] as $raw) {
            $this->registerTierId($out, (string) $raw, 'route');
        }

        ksort($out);

        return $this->indexCache = $out;
    }

    public function minCount(): int
    {
        $cfg = config('plugin.reserved_identifiers.min_count');

        return is_int($cfg) ? max(1, $cfg) : 80;
    }

    /** @return list<string> */
    private function kernelIdentifiers(): array
    {
        $ids = PluginService::KERNEL_BUILTIN_IDENTIFIERS;
        $merged = config('pivark.core_merged_identifiers');
        if (is_array($merged)) {
            $ids = array_merge($ids, $merged);
        }
        $l1 = config('kernel.l1_modules');
        if (is_array($l1)) {
            $ids = array_merge($ids, $l1);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $ids
        ))));
    }

    private function lookupTier(string $identifier): ?string
    {
        $index = $this->index();
        if (isset($index[$identifier])) {
            return $index[$identifier];
        }
        $underscore = str_replace('-', '_', $identifier);
        if ($underscore !== $identifier && isset($index[$underscore])) {
            return $index[$underscore];
        }
        $hyphen = str_replace('_', '-', $identifier);
        if ($hyphen !== $identifier && isset($index[$hyphen])) {
            return $index[$hyphen];
        }

        return null;
    }

    /** @param array<string, string> $out */
    private function registerTierId(array &$out, string $raw, string $tier): void
    {
        $id = strtolower(trim($raw));
        if ($id === '') {
            return;
        }
        $out[$id] = $tier;
        $underscore = str_replace('-', '_', $id);
        if ($underscore !== $id) {
            $out[$underscore] = $tier;
        }
        $hyphen = str_replace('_', '-', $id);
        if ($hyphen !== $id) {
            $out[$hyphen] = $tier;
        }
    }

    /** @return array<string, string> */
    private function tierLabels(): array
    {
        $cfg = config('plugin.reserved_identifiers.tier_labels');

        return is_array($cfg) ? array_map(strval(...), $cfg) : [];
    }

    private function messageFor(string $id, string $tier): string
    {
        $label = (string) ($this->tierLabels()[$tier] ?? $tier);

        return match ($tier) {
            'kernel'          => '标识「' . $id . '」为' . $label . '，不可作为 weapp 插件目录名',
            'platform_module' => '标识「' . $id . '」与系统平台模块（needs）同名，不可作为插件 identifier',
            'legacy'          => '标识「' . $id . '」为历史废弃名（官方已改名或并入内核），请换用带前缀的新 identifier',
            'meta'            => '标识「' . $id . '」与插件框架/路由元词语冲突，请换用带厂商前缀的名称',
            'official_weapp'  => '标识「' . $id . '」为官方发行插件占用名，第三方请使用带厂商前缀的新 identifier',
            default           => '标识「' . $id . '」与' . $label . '冲突，不可作为插件 identifier',
        };
    }

    private function suggestPrefix(string $id): string
    {
        if (str_starts_with($id, 'doc_') || in_array($id, ['download', 'gallery', 'video', 'comment', 'thumb'], true)) {
            return '建议：acme_doc_xxx 或 yourvendor/doc-slug 包名 + identifier acme_doc_xxx';
        }
        if (strlen($id) <= 12) {
            return '建议：acme_' . $id . ' 或 myco_' . $id;
        }

        return '建议：在标识前加厂商缩写前缀，如 acme_' . substr($id, 0, 8);
    }

    /** 单测/门禁：清静态缓存 */
    public function resetCache(): void
    {
        $this->indexCache = null;
        $this->officialWeappCache = null;
    }

    /** @return list<string> */
    private function shippedOfficialWeappIdentifiers(): array
    {
        if ($this->officialWeappCache !== null) {
            return $this->officialWeappCache;
        }

        $weappRoot = $this->plugin->weappRoot();
        if (!is_dir($weappRoot)) {
            return $this->officialWeappCache = [];
        }

        $ids = [];
        foreach (glob($weappRoot . '*/plugin.json') ?: [] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $publisher = strtolower(trim((string) ($data['publisher_type'] ?? '')));
            if ($publisher !== 'official') {
                continue;
            }
            $id = strtolower(trim((string) ($data['identifier'] ?? '')));
            if ($id !== '' && preg_match(self::IDENTIFIER_PATTERN, $id)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $this->officialWeappCache = array_values(array_unique($ids));
    }

    /** @return array<string, list<string>> */
    private function tierExamples(): array
    {
        $cfg = config('plugin.reserved_identifiers');
        $out = [];
        foreach (['platform_module', 'route', 'legacy', 'meta'] as $key) {
            $list = is_array($cfg[$key] ?? null) ? $cfg[$key] : [];
            $out[$key] = array_slice(array_values(array_map(strval(...), $list)), 0, 6);
        }
        $out['kernel'] = array_slice($this->kernelIdentifiers(), 0, 6);
        $out['official_weapp'] = array_slice($this->shippedOfficialWeappIdentifiers(), 0, 8);

        return $out;
    }
}
