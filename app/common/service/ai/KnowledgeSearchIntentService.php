<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\ServiceResult;
use app\common\support\SlugHelper;

use app\common\service\config\ConfigService;
use app\common\service\content\ContentSearchService;

/**
 * 知识搜索「引导问法」：正则意图 + 召回策略，后台可配（configs.ai_search_guided_*）
 */
class KnowledgeSearchIntentService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly ContentSearchService $contentSearchService,
    ) {
    }

    public const CONFIG_ENABLED = 'ai_search_guided_enabled';
    public const CONFIG_RULES   = 'ai_search_guided_rules';

    /** @var list<array<string, mixed>>|null */
    private static ?array $rulesCache = null;

    public function isEnabled(): bool
    {
        $v = (string) $this->configService->get(self::CONFIG_ENABLED, '1');

        return $v === '' || $v === '1';
    }

    /** @return list<array<string, mixed>> */
    public function rulesForAdmin(): array
    {
        return $this->rules();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rules(): array
    {
        if (self::$rulesCache !== null) {
            return self::$rulesCache;
        }

        $raw = trim((string) $this->configService->get(self::CONFIG_RULES, ''));
        if ($raw === '') {
            self::$rulesCache = $this->defaultRules();

            return self::$rulesCache;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$rulesCache = $this->defaultRules();

            return self::$rulesCache;
        }

        self::$rulesCache = $this->normalizeRules($decoded);

        return self::$rulesCache;
    }

    public function forgetCache(): void
    {
        self::$rulesCache = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchRule(string $keyword): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $keyword = $this->contentSearchService->normalizeKeyword($keyword);
        if ($keyword === '') {
            return null;
        }

        foreach ($this->rules() as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if ($this->ruleMatches($rule, $keyword)) {
                return $rule;
            }
        }

        return null;
    }

    public function isSiteOverviewQuery(string $keyword): bool
    {
        $rule = $this->matchRule($keyword);

        return $rule !== null && (string) ($rule['id'] ?? '') === 'site_overview';
    }

    public function isPluginCatalogQuery(string $keyword): bool
    {
        $rule = $this->matchRule($keyword);

        return $rule !== null && (string) ($rule['id'] ?? '') === 'plugin_catalog';
    }

    /**
     * @param array<string, mixed> $rule
     */
    public function ruleMatches(array $rule, string $keyword): bool
    {
        $keyword = $this->contentSearchService->normalizeKeyword($keyword);
        if ($keyword === '') {
            return false;
        }

        foreach ($rule['patterns'] ?? [] as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $err = null;
            set_error_handler(static function (int $severity, string $message) use (&$err): bool {
                $err = $message;

                return true;
            });
            $ok = @preg_match($pattern, $keyword) === 1;
            restore_error_handler();
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $enabled = !empty($data['ai_search_guided_enabled']) ? '1' : '0';
        $rulesIn = $data['ai_search_guided_rules'] ?? $data['ai_search_guided_rules_json'] ?? null;
        $hasRules = $rulesIn !== null;

        if ($hasRules) {
            if (is_string($rulesIn)) {
                $rulesIn = json_decode($rulesIn, true);
            }
            if (!is_array($rulesIn)) {
                return ServiceResult::fail('引导问法规则格式无效');
            }

            $rules = $this->normalizeRules($rulesIn);
            if ($rules === []) {
                return ServiceResult::fail('至少保留一条有效引导规则');
            }

            foreach ($rules as $rule) {
                foreach ($rule['patterns'] ?? [] as $pattern) {
                    $pattern = trim((string) $pattern);
                    if ($pattern === '') {
                        continue;
                    }
                    if (@preg_match($pattern, '') === false) {
                        $label = (string) ($rule['label'] ?? $rule['id'] ?? '规则');

                        return ServiceResult::fail('「' . $label . '」含无效正则：' . $pattern);
                    }
                }
            }

            $this->configService->set(self::CONFIG_RULES, json_encode($rules, JSON_UNESCAPED_UNICODE));
        }

        $this->configService->set(self::CONFIG_ENABLED, $enabled);
        $this->configService->forgetRequestCache();
        $this->forgetCache();

        return ServiceResult::ok(null, '引导问法已保存');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultRules(): array
    {
        return KnowledgeSearchIntentDefaultRules::all();
    }

    /**
     * @param list<mixed> $input
     * @return list<array<string, mixed>>
     */
    public function normalizeRules(array $input): array
    {
        $defaults = KnowledgeSearchIntentDefaultRules::indexedById();

        $out   = [];
        $seen  = [];
        $count = 0;

        foreach ($input as $row) {
            if (!is_array($row) || $count >= 20) {
                continue;
            }
            $rule = $this->normalizeRuleRow($row, $defaults, $seen);
            if ($rule === null) {
                continue;
            }
            $out[]       = $rule;
            $seen[$rule['id']] = true;
            $count++;
        }

        if ($out === []) {
            return $this->defaultRules();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $defaults
     * @param array<string, true> $seen
     * @return array<string, mixed>|null
     */
    private function normalizeRuleRow(array $row, array $defaults, array $seen): ?array
    {
        $id = strtolower(trim((string) ($row['id'] ?? '')));
        if ($id === '' || !preg_match('/^[a-z][a-z0-9_]{0,47}$/', $id)) {
            return null;
        }
        if (isset($seen[$id])) {
            return null;
        }

        $base = $defaults[$id] ?? [
            'id'                     => $id,
            'label'                  => trim((string) ($row['label'] ?? $id)),
            'enabled'                => true,
            'builtin'                => false,
            'patterns'               => [],
            'recall'                 => 'custom',
            'alt_keywords'           => [],
            'tag_slugs'              => [],
            'doc_attrs'              => [],
            'include_site_meta'      => false,
            'include_plugin_catalog' => false,
            'use_site_name_alt'      => false,
            'fill_latest_docs'       => true,
            'prompt_hint'            => '',
        ];

        $recall = strtolower(trim((string) ($row['recall'] ?? $base['recall'] ?? 'custom')));
        if (!in_array($recall, ['site_overview', 'plugin_catalog', 'custom'], true)) {
            $recall = (string) ($base['recall'] ?? 'custom');
        }

        $rule = [
            'id'                     => $id,
            'label'                  => $this->clip(trim((string) ($row['label'] ?? $base['label'] ?? $id)), 64),
            'enabled'                => !array_key_exists('enabled', $row) || !empty($row['enabled']),
            'builtin'                => !empty($base['builtin']),
            'patterns'               => $this->normalizeStringList($row['patterns'] ?? $base['patterns'] ?? [], 30, 240),
            'recall'                 => $recall,
            'alt_keywords'           => $this->normalizeStringList($row['alt_keywords'] ?? $base['alt_keywords'] ?? [], 20, 40),
            'tag_slugs'              => $this->normalizeSlugList($row['tag_slugs'] ?? $base['tag_slugs'] ?? []),
            'doc_attrs'              => $this->normalizeAttrList($row['doc_attrs'] ?? $base['doc_attrs'] ?? []),
            'include_site_meta'      => !empty($row['include_site_meta'] ?? $base['include_site_meta'] ?? false),
            'include_plugin_catalog' => !empty($row['include_plugin_catalog'] ?? $base['include_plugin_catalog'] ?? false),
            'use_site_name_alt'      => !empty($row['use_site_name_alt'] ?? $base['use_site_name_alt'] ?? false),
            'fill_latest_docs'       => !array_key_exists('fill_latest_docs', $row)
                ? !empty($base['fill_latest_docs'])
                : !empty($row['fill_latest_docs']),
            'prompt_hint'            => $this->clip(trim((string) ($row['prompt_hint'] ?? $base['prompt_hint'] ?? '')), 800),
        ];

        return $rule['patterns'] === [] ? null : $rule;
    }

    /**
     * @param list<mixed>|string $items
     * @return list<string>
     */
    private function normalizeStringList(mixed $items, int $max, int $maxLen): array
    {
        if (is_string($items)) {
            $items = preg_split('/\r\n|\r|\n/', $items) ?: [];
        }
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $s = trim((string) $item);
            if ($s === '') {
                continue;
            }
            $out[] = $this->clip($s, $maxLen);
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<mixed>|string $items
     * @return list<string>
     */
    private function normalizeSlugList(mixed $items): array
    {
        return SlugHelper::filterValidTokens($items, 20, 64);
    }

    /**
     * @param list<mixed>|string $items
     * @return list<string>
     */
    private function normalizeAttrList(mixed $items): array
    {
        $allowed = ['recommend', 'headline', 'image', 'bold'];
        $list    = $this->normalizeStringList($items, 8, 32);
        $out     = [];
        foreach ($list as $attr) {
            if (in_array($attr, $allowed, true)) {
                $out[] = $attr;
            }
        }

        return $out;
    }

    private function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
