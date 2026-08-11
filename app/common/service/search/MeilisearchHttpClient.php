<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\OpsLog;

/** Meilisearch REST v1 薄封装 */
final class MeilisearchHttpClient
{
    /** 后台 meta 探活上限（Meili 未启动时勿阻塞 SPA 默认 10s axios） */
    public const ADMIN_PROBE_TIMEOUT = 2;

    public function __construct(
        private readonly string $host,
        private readonly string $apiKey = '',
        private readonly int $timeoutSec = 15,
    ) {
    }

    /** 后台探活用短超时；业务检索仍走实例 timeoutSec */
    public static function probeHealth(string $host, string $apiKey = '', int $timeoutSec = self::ADMIN_PROBE_TIMEOUT): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        return (new self($host, $apiKey, max(1, $timeoutSec)))->health();
    }

    public function health(): bool
    {
        try {
            $this->requestOnce('GET', '/health', null);

            return true;
        } catch (\Throwable $e) {
            OpsLog::businessWarning('meilisearch_health_failed', ['msg' => $e->getMessage()]);

            return false;
        }
    }

    public function ensureIndex(string $uid, string $primaryKey = 'id'): void
    {
        $created = false;
        try {
            $this->request('GET', '/indexes/' . rawurlencode($uid));
        } catch (\Throwable $e) {
            if (!$this->isIndexNotFound($e)) {
                OpsLog::businessWarning('meilisearch_ensure_index_failed', [
                    'uid' => $uid,
                    'msg' => $e->getMessage(),
                ]);
                throw $e;
            }
            $this->request('POST', '/indexes', [
                'uid' => $uid,
                'primaryKey' => $primaryKey,
            ]);
            $created = true;
            OpsLog::businessInfo('meilisearch_index_created', ['uid' => $uid]);
        }
        if ($created) {
            $this->patchIndexSettings($uid);
        }
    }

    private function isIndexNotFound(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'HTTP 404');
    }

    public function patchIndexSettings(string $uid): void
    {
        $this->request('PATCH', '/indexes/' . rawurlencode($uid) . '/settings', [
            'searchableAttributes' => [
                'title',
                'subtitle',
                'summary',
                'search_text',
            ],
            'filterableAttributes' => [
                'status',
                'read_perm',
                'read_level_id',
                'tag_ids',
                'nav_id',
            ],
            'sortableAttributes' => ['published_at'],
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function patchCustomSettings(string $uid, array $settings): void
    {
        $this->request('PATCH', '/indexes/' . rawurlencode($uid) . '/settings', $settings);
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function addDocuments(string $uid, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents', $documents);
    }

    /**
     * @param list<int|string> $ids
     */
    public function deleteDocuments(string $uid, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents/delete-batch', $ids);
    }

    public function deleteAllDocuments(string $uid): void
    {
        $this->request('DELETE', '/indexes/' . rawurlencode($uid) . '/documents');
    }

    /**
     * @param list<string> $attributesToSearchOn
     * @return array{hits:list<array<string,mixed>>,estimatedTotal:int}
     */
    public function search(
        string $uid,
        string $q,
        int $offset,
        int $limit,
        ?string $filter = null,
        array $attributesToSearchOn = [],
    ): array {
        $body = [
            'q' => $q,
            'offset' => max(0, $offset),
            'limit' => min(max($limit, 1), 100),
        ];
        if ($filter !== null && $filter !== '') {
            $body['filter'] = $filter;
        }
        if ($attributesToSearchOn !== []) {
            $body['attributesToSearchOn'] = $attributesToSearchOn;
        }
        $res = $this->request('POST', '/indexes/' . rawurlencode($uid) . '/search', $body);

        $hits = is_array($res['hits'] ?? null) ? $res['hits'] : [];
        /** @var list<array<string, mixed>> $typedHits */
        $typedHits = [];
        foreach ($hits as $hit) {
            if (is_array($hit)) {
                $typedHits[] = $hit;
            }
        }

        return [
            'hits' => $typedHits,
            'estimatedTotal' => (int) ($res['estimatedTotalHits'] ?? $res['totalHits'] ?? 0),
        ];
    }

    /**
     * @param array<mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $jsonBody = null, bool $recoverOnSuccess = true): array
    {
        $attempts = max(1, (int) config('pivark.search_meili_request_retries', 3));
        $last     = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $decoded = $this->requestOnce($method, $path, $jsonBody);
                if ($recoverOnSuccess) {
                    app(SearchDegradedGuard::class)->markExternalRecovered();
                }

                return $decoded;
            } catch (\Throwable $e) {
                $last = $e;
                if ($i >= $attempts - 1 || !$this->shouldRetryRequest($e)) {
                    break;
                }
                usleep((int) (100000 * (2 ** $i)));
            }
        }
        app(SearchDegradedGuard::class)->markExternalUnavailable();
        /** @var \Throwable $last */
        throw $last;
    }

    private function shouldRetryRequest(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'curl ')) {
            return true;
        }

        return (bool) preg_match('/HTTP 5\d\d:/', $msg);
    }

    /**
     * @param array<mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function requestOnce(string $method, string $path, ?array $jsonBody = null): array
    {
        $base = rtrim($this->host, '/');
        $url = $base . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(2, max(1, $this->timeoutSec)),
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : '{}');
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new \RuntimeException('Meilisearch 请求失败: curl ' . $errno);
        }
        if (!is_string($raw) || $raw === '') {
            if ($httpCode >= 200 && $httpCode < 300) {
                return [];
            }
            throw new \RuntimeException('Meilisearch 空响应 HTTP ' . $httpCode);
        }
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $raw) : $raw;
            throw new \RuntimeException('Meilisearch HTTP ' . $httpCode . ': ' . $msg);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
