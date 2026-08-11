<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

/** 从插件 listForDocument 等嵌套结构抽取可检索纯文本（通用，不绑单一插件字段名） */
final class DocumentAddonPlainTextCollector
{

    public function __construct(
        private readonly SearchTextSanitizer $sanitizer,
    ) {
    }

    /** @var list<string> */
    private const PREFERRED_KEYS = [
        'title', 'name', 'label', 'question', 'answer', 'summary', 'description', 'bio',
        'caption', 'alt', 'subtitle', 'code', 'extract_code', 'content', 'text', 'body',
        'remark', 'note', 'job_title', 'platform_label', 'original_name', 'bundle_title',
        'item_label', 'play_url', 'remote_url', 'url', 'file_path', 'litpic', 'cover_litpic',
        'path_snapshot', 'attrs_summary',
    ];

    /** @var list<string> */
    private const SKIP_KEYS = [
        'id', 'document_id', 'bundle_id', 'item_id', 'episode_id', 'series_id', 'tag_id',
        'status', 'sort', 'created_at', 'updated_at', 'deleted_at', 'password', 'token',
        'embedding_json', 'meta_json', 'options_json', 'config_json', 'flags', 'attrs',
    ];

    /**
     * @param mixed $payload
     * @return list<string>
     */
    public function linesFromPayload(mixed $payload, int $maxDepth = 8, bool $omitSensitiveFields = true): array
    {
        $lines = [];
        $this->walk($payload, $lines, 0, $maxDepth, $omitSensitiveFields);

        return $this->uniqueLines($lines);
    }

    /**
     * @param list<string> $lines
     */
    private function walk(mixed $node, array &$lines, int $depth, int $maxDepth, bool $omitSensitiveFields): void
    {
        if ($depth > $maxDepth || $node === null) {
            return;
        }
        if (is_string($node) || is_numeric($node)) {
            $this->pushScalar((string) $node, $lines, $omitSensitiveFields);

            return;
        }
        if (!is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $child) {
                $this->walk($child, $lines, $depth + 1, $maxDepth, $omitSensitiveFields);
            }

            return;
        }
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }
            if (is_string($value) || is_numeric($value)) {
                $this->pushField($key, (string) $value, $lines, $omitSensitiveFields);
                continue;
            }
            $this->walk($value, $lines, $depth + 1, $maxDepth, $omitSensitiveFields);
        }
    }

    /** @param list<string> $lines */
    private function pushField(string $key, string $value, array &$lines, bool $omitSensitiveFields): void
    {
        if ($omitSensitiveFields && $this->sanitizer->shouldOmitFieldKey($key)) {
            $lines[] = $this->sanitizer->omitPlaceholder();

            return;
        }
        $value = trim($value);
        if ($value === '' || (!$this->isPreferredKey($key) && mb_strlen($value) > 4000)) {
            return;
        }
        if (in_array($key, ['file_path', 'litpic', 'cover_litpic', 'path_snapshot'], true)) {
            $base = basename(str_replace('\\', '/', $value));
            if ($base !== '') {
                $lines[] = $base;
            }

            return;
        }
        if (in_array($key, ['remote_url', 'play_url', 'url'], true)) {
            $tok = $this->urlSearchTokens($value);
            if ($tok !== '') {
                $lines[] = $tok;
            }

            return;
        }
        if (preg_match('/<[^>]+>/', $value)) {
            $value = trim(strip_tags($value));
        }
        if ($value !== '' && ($this->isPreferredKey($key) || mb_strlen($value) <= 2000)) {
            $lines[] = $this->sanitizer->maskLine($value);
        }
    }

    private function isPreferredKey(string $key): bool
    {
        return in_array($key, self::PREFERRED_KEYS, true);
    }

    /** @param list<string> $lines */
    private function pushScalar(string $value, array &$lines, bool $omitSensitiveFields): void
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2000 || preg_match('/^\d+$/', $value)) {
            return;
        }
        if (preg_match('/<[^>]+>/', $value)) {
            $value = trim(strip_tags($value));
        }
        if ($value !== '') {
            $lines[] = $omitSensitiveFields ? $this->sanitizer->maskLine($value) : $value;
        }
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function uniqueLines(array $lines): array
    {
        $out  = [];
        $seen = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || mb_strlen($line) < 2 || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $out[]       = $line;
        }

        return $out;
    }

    public function urlSearchTokens(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $base = $path !== '' ? basename($path) : '';
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        return trim($host . ' ' . str_replace(['-', '_', '.'], ' ', $base));
    }
}
