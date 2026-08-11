<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\support\ServiceResult;
use app\common\support\SimpleHttpClient;


use app\common\service\config\ConfigService;
use app\common\model\Document;

/** 文档正文外链死链检测（阶段 3 O-02） */
class DeadLinkCheckService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    private const CACHE_KEY = 'access_stats_dead_scan';

    private const MAX_SCAN_ALL = 500;

    /**
     * @return ServiceResult
     */
    public function scan(int $limit = QueryLimit::RECONCILE_BATCH, string $mode = 'recent'): ServiceResult
    {
        $mode  = in_array($mode, ['recent', 'with_links', 'all'], true) ? $mode : 'recent';
        $query = Document::where('status', 1)->whereNull('deleted_at')
            ->field('id,title,content,content_mobile,updated_at');

        if ($mode === 'with_links') {
            $query->where(function ($q): void {
                $q->whereLike('content', '%http%')
                    ->whereOr('content_mobile', 'like', '%http%');
            });
        }

        $query->order('updated_at', 'desc');

        if ($mode === 'all') {
            $limit = self::MAX_SCAN_ALL;
        } else {
            $limit = max(1, min(200, $limit));
            $query->limit($limit);
        }

        $rows = $query->select()->toArray();

        $probeCache = [];
        $dead       = [];
        $byDoc      = [];

        foreach ($rows as $row) {
            $docId = (int) ($row['id'] ?? 0);
            $html  = (string) ($row['content'] ?? '') . "\n" . (string) ($row['content_mobile'] ?? '');
            $urls  = $this->extractExternalUrls($html);
            if ($urls === []) {
                continue;
            }

            $docDead = [];
            foreach ($urls as $url) {
                if (!isset($probeCache[$url])) {
                    $probeCache[$url] = $this->probeUrl($url);
                }
                $code = $probeCache[$url];
                if ($code >= 400 || $code === 0) {
                    $item = [
                        'document_id' => $docId,
                        'title'       => (string) ($row['title'] ?? ''),
                        'url'         => $url,
                        'http_code'   => $code,
                        'status_text' => $this->httpStatusLabel($code),
                    ];
                    $dead[]    = $item;
                    $docDead[] = $item;
                }
            }

            if ($docDead !== []) {
                $byDoc[] = [
                    'document_id' => $docId,
                    'title'       => (string) ($row['title'] ?? ''),
                    'updated_at'  => (string) ($row['updated_at'] ?? ''),
                    'dead_count'  => count($docDead),
                    'links'       => $docDead,
                ];
            }
        }

        $message = $this->buildScanMessage(count($rows), count($dead), count($byDoc));
        $payload = [
            'scanned'       => count($rows),
            'checked_urls'  => count($probeCache),
            'dead_count'    => count($dead),
            'dead'          => $dead,
            'documents'     => $byDoc,
            'scanned_at'    => AppTime::now(),
            'mode'          => $mode,
            'limit'         => $mode === 'all' ? self::MAX_SCAN_ALL : $limit,
            'msg'           => $message,
        ];

        $this->saveLastScan($payload);

        return ServiceResult::ok($payload, $message);
    }

    /**
     * @return ServiceResult
     */
    public function lastScan(): ServiceResult
    {
        $raw = $this->config->get(self::CACHE_KEY, '');
        if ($raw === '') {
            return ServiceResult::ok(['dead' => [], 'documents' => []], '');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ServiceResult::ok(['dead' => [], 'documents' => []], '');
        }

        return ServiceResult::ok($data, (string) ($data['msg'] ?? ''));
    }

    /**
     * @return list<string>
     */
    public function extractExternalUrls(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $patterns = [
            '#\bhref\s*=\s*["\'](https?://[^"\'\s<>]+)#i',
            '#\bsrc\s*=\s*["\'](https?://[^"\'\s<>]+)#i',
            '#\bdata-href\s*=\s*["\'](https?://[^"\'\s<>]+)#i',
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $m)) {
                foreach ($m[1] as $url) {
                    $url = $this->normalizeUrl((string) $url);
                    if ($url !== '') {
                        $found[$url] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    public function probeUrl(string $url): int
    {
        $res = SimpleHttpClient::request($url, [
            'method'           => 'HEAD',
            'no_body'          => true,
            'follow_location'  => true,
            'max_redirects'    => 5,
            'timeout'          => 10,
            'connect_timeout'  => 5,
            'user_agent'       => 'PivArkDeadLinkCheck/1.1',
        ]);

        return $res['http_code'];
    }

    public function httpStatusLabel(int $code): string
    {
        if ($code === 0) {
            return '无法连接';
        }
        if ($code >= 500) {
            return '服务端异常';
        }
        if ($code >= 400) {
            return '客户端错误';
        }

        return '异常';
    }

    private function normalizeUrl(string $url): string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = rtrim($url, '.,;)\'"');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function buildScanMessage(int $scanned, int $deadLinks, int $docCount): string
    {
        if ($deadLinks === 0) {
            return "已扫描 {$scanned} 篇文档，未发现死链";
        }

        return "已扫描 {$scanned} 篇，{$docCount} 篇文档共 {$deadLinks} 条死链";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveLastScan(array $payload): void
    {
        $store = [
            'scanned'      => (int) ($payload['scanned'] ?? 0),
            'checked_urls' => (int) ($payload['checked_urls'] ?? 0),
            'dead_count'   => (int) ($payload['dead_count'] ?? 0),
            'dead'         => $payload['dead'] ?? [],
            'documents'    => $payload['documents'] ?? [],
            'scanned_at'   => (string) ($payload['scanned_at'] ?? ''),
            'mode'         => (string) ($payload['mode'] ?? ''),
            'limit'        => (int) ($payload['limit'] ?? 0),
            'msg'          => (string) ($payload['msg'] ?? ''),
        ];
        $this->config->set(self::CACHE_KEY, json_encode($store, JSON_UNESCAPED_UNICODE));
    }
}
