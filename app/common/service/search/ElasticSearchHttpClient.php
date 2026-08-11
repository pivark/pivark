<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

/** Elasticsearch HTTP 客户端（Phase C：cluster ping + 配置读取） */
final class ElasticSearchHttpClient
{

    public function __construct(
        private readonly SearchConfigService $searchConfig,
    ) {
    }

    private const PING_TIMEOUT_SEC = 5;

    /** @return array{hosts:string,index:string,user:string,pass:string} */
    public function config(): array
    {
        return $this->searchConfig->elasticConfig();
    }

    public function isConfigured(): bool
    {
        $cfg = $this->config();

        return trim($cfg['hosts']) !== '';
    }

    public function primaryHost(): string
    {
        $raw = trim($this->config()['hosts']);
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];

        return rtrim(trim($parts[0]), '/');
    }

    public function ping(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $base = $this->primaryHost();
        if ($base === '') {
            return false;
        }

        return $this->httpOk($base) || $this->httpOk($base . '/_cluster/health');
    }

    public function isAvailable(): bool
    {
        return $this->isConfigured() && $this->ping();
    }

    public function itemIndexName(): string
    {
        $base = trim($this->config()['index']);

        return $base !== '' ? $base . '_items' : 'pivark_items';
    }

    public function documentIndexName(): string
    {
        $base = trim($this->config()['index']);

        return $base !== '' ? $base : 'pivark_documents';
    }

    /** @return array<string, array<string, mixed>> */
    public function itemIndexMappingProperties(): array
    {
        return [
            'id'          => ['type' => 'integer'],
            'name'        => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'code'        => ['type' => 'keyword'],
            'slug'        => ['type' => 'keyword'],
            'search_text' => ['type' => 'text'],
            'status'      => ['type' => 'keyword'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function documentIndexMappingProperties(): array
    {
        return [
            'id'            => ['type' => 'integer'],
            'title'         => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]],
            'subtitle'      => ['type' => 'text'],
            'summary'       => ['type' => 'text'],
            'search_text'   => ['type' => 'text'],
            'tag_ids'       => ['type' => 'integer'],
            'nav_id'        => ['type' => 'integer'],
            'status'        => ['type' => 'integer'],
            'read_perm'     => ['type' => 'integer'],
            'read_level_id' => ['type' => 'integer'],
            'published_at'  => ['type' => 'long'],
            'url'           => ['type' => 'keyword'],
        ];
    }

    public function indexExists(string $index): bool
    {
        if (!$this->isConfigured() || $index === '') {
            return false;
        }
        $base = $this->primaryHost();

        return $base !== '' && $this->httpOk($base . '/' . rawurlencode($index));
    }

    /** @param array<string, array<string, mixed>> $properties */
    public function ensureIndex(string $index, array $properties): bool
    {
        if (!$this->isConfigured() || $index === '') {
            return false;
        }
        if ($this->indexExists($index)) {
            return true;
        }
        $base = $this->primaryHost();
        if ($base === '') {
            return false;
        }
        $url  = $base . '/' . rawurlencode($index);
        $body = ['mappings' => ['properties' => $properties]];

        return $this->requestJson('PUT', $url, $body) !== null;
    }

    /**
     * @return array{ids:list<int>,total:int}
     */
    public function searchDocumentIds(string $keyword, int $offset = 0, int $limit = 24): array
    {
        if (!$this->isConfigured()) {
            return ['ids' => [], 'total' => 0];
        }
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['ids' => [], 'total' => 0];
        }
        $limit = max(1, min(100, $limit));
        $body  = [
            'from'             => max(0, $offset),
            'size'             => $limit,
            '_source'          => false,
            'track_total_hits' => true,
            'query'            => [
                'multi_match' => [
                    'query'  => $keyword,
                    'fields' => ['title', 'subtitle', 'summary', 'search_text'],
                    'type'   => 'best_fields',
                ],
            ],
        ];
        $res = $this->search($this->documentIndexName(), $body);
        if ($res === null) {
            return ['ids' => [], 'total' => 0];
        }
        $ids = [];
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $id = (int) ($hit['_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $totalRaw = $res['hits']['total'] ?? count($ids);
        $total    = is_array($totalRaw) ? (int) ($totalRaw['value'] ?? count($ids)) : (int) $totalRaw;

        return ['ids' => $ids, 'total' => max($total, count($ids))];
    }

    /**
     * @return list<int>
     */
    public function searchItemIds(string $keyword, int $limit = 24): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $body  = [
            'size'    => $limit,
            '_source' => false,
            'query'   => [
                'multi_match' => [
                    'query'  => $keyword,
                    'fields' => ['name', 'code', 'search_text'],
                    'type'   => 'best_fields',
                ],
            ],
        ];
        $res = $this->search($this->itemIndexName(), $body);
        if ($res === null) {
            return [];
        }
        $ids = [];
        foreach ($res['hits']['hits'] ?? [] as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $id = (int) ($hit['_id'] ?? 0);
            if ($id < 1 && isset($hit['_source']) && is_array($hit['_source'])) {
                $id = (int) ($hit['_source']['id'] ?? 0);
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    public function search(string $index, array $body): ?array
    {
        $base = $this->primaryHost();
        if ($base === '' || $index === '') {
            return null;
        }
        $url = $base . '/' . rawurlencode($index) . '/_search';

        return $this->requestJson('POST', $url, $body);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function upsertDocument(string $index, int|string $id, array $document): bool
    {
        if (!$this->isConfigured() || $index === '' || (string) $id === '' || (int) $id < 1) {
            return false;
        }
        $base = $this->primaryHost();
        if ($base === '') {
            return false;
        }
        $url = $base . '/' . rawurlencode($index) . '/_doc/' . rawurlencode((string) $id);

        return $this->requestJson('PUT', $url, $document) !== null;
    }

    public function deleteDocument(string $index, int|string $id): bool
    {
        if (!$this->isConfigured() || $index === '' || (int) $id < 1) {
            return false;
        }
        $base = $this->primaryHost();
        if ($base === '') {
            return false;
        }
        $url = $base . '/' . rawurlencode($index) . '/_doc/' . rawurlencode((string) $id);

        return $this->requestJson('DELETE', $url) !== null;
    }

    private function httpOk(string $url): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        $cfg = $this->config();
        $headers = ['Accept: application/json'];
        $user = trim($cfg['user']);
        $pass = $cfg['pass'];
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::PING_TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        return $errno === 0 && $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function requestJson(string $method, string $url, ?array $body = null): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $cfg     = $this->config();
        $headers = ['Accept: application/json'];
        $user    = trim($cfg['user']);
        $pass    = $cfg['pass'];
        if ($user !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::PING_TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS]   = $this->encodeJsonBody($body);
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_HTTPHEADER][]  = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS]    = $this->encodeJsonBody($body);
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }
        curl_setopt_array($ch, $opts);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }
        if ($method === 'DELETE') {
            return ['deleted' => true];
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function encodeJsonBody(?array $body): string
    {
        $encoded = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[]';
    }
}
