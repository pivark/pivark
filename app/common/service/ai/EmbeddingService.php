<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;
use app\common\service\ai\AiProviderCatalog;
use app\common\support\QueryLimit;
use app\common\support\SimpleHttpClient;

use app\common\model\AiChunk;
use app\common\service\config\AiConfigService;
use app\common\service\config\ConfigService;
use app\common\service\search\SmartSearchConfigService;

/** OpenAI 兼容 Embedding API → 写入 ai_chunks.embedding_json */
final class EmbeddingService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly SmartSearchConfigService $smartSearchConfigService,
        private readonly AiProviderCatalog $aiProviderCatalog,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @return list<float>|null
     */
    public function embedText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || !$this->isOperational()) {
            return null;
        }
        $provider = $this->aiConfigService->provider();
        $apiKey   = $this->aiConfigService->providerApiKey($provider);
        if ($apiKey === '') {
            return null;
        }
        $model = $this->embeddingModel($provider);
        $base  = rtrim($this->aiConfigService->providerBaseUrl($provider), '/');
        $url   = $base . '/embeddings';
        $payload = [
            'model' => $model,
            'input' => mb_substr($text, 0, 8000),
        ];
        $raw = $this->postJson($url, $payload, $apiKey);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $vec = $decoded['data'][0]['embedding'] ?? null;
        if (!is_array($vec)) {
            return null;
        }
        $out = [];
        foreach ($vec as $v) {
            $out[] = (float) $v;
        }

        return $out !== [] ? $out : null;
    }

    public function embedDocumentChunks(int $documentId, int $maxChunks = 80): int
    {
        if ($documentId < 1 || !$this->isEnabled()) {
            return 0;
        }
        $rows = AiChunk::where('document_id', $documentId)
            ->order('chunk_index', 'asc')
            ->limit(max(1, min(200, $maxChunks)))
            ->field('id,content,embedding_json')
            ->select()
            ->toArray();
        $n = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['embedding_json'])) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $vec = $this->embedText($content);
            if ($vec === null) {
                continue;
            }
            AiChunk::where('id', (int) ($row['id'] ?? 0))->update([
                'embedding_json' => json_encode($vec, JSON_UNESCAPED_UNICODE),
                'updated_at'     => time(),
            ]);
            $n++;
        }

        return $n;
    }

    public function isEnabled(): bool
    {
        return $this->isOperational();
    }

    /** 配置开启 + AI 就绪 + 当前服务商支持 /embeddings */
    public function isOperational(): bool
    {
        if (!$this->smartSearchConfigService->embeddingEnabled()
            || !$this->aiConfigService->isEnabled()
            || !$this->aiConfigService->activeProviderConfigured()) {
            return false;
        }

        return $this->aiProviderCatalog->supportsEmbeddings($this->aiConfigService->provider());
    }

    /**
     * @return array{
     *   total_chunks:int,
     *   embedded_chunks:int,
     *   pending_chunks:int,
     *   coverage_pct:float,
     *   status:array{ok:bool,reason:string,provider:string,model:string}
     * }
     */
    public function coverageForAdmin(): array
    {
        $total = (int) AiChunk::count();
        $embedded = (int) AiChunk::whereRaw("(embedding_json IS NOT NULL AND embedding_json != '' AND embedding_json != '[]')")->count();
        $pending  = max(0, $total - $embedded);

        return [
            'total_chunks'    => $total,
            'embedded_chunks' => $embedded,
            'pending_chunks'  => $pending,
            'coverage_pct'    => $total > 0 ? round($embedded / $total * 100, 1) : 0.0,
            'status'          => $this->statusForAdmin(),
        ];
    }

    /**
     * @return array{ok:bool,reason:string,provider:string,model:string}
     */
    public function statusForAdmin(): array
    {
        $provider = $this->aiConfigService->provider();
        $catalog  = $this->aiProviderCatalog->get($provider);
        if (!$this->smartSearchConfigService->embeddingEnabled()) {
            return ['ok' => false, 'reason' => '分块 Embedding 开关已关闭', 'provider' => $provider, 'model' => ''];
        }
        if (!$this->aiConfigService->isEnabled() || !$this->aiConfigService->activeProviderConfigured()) {
            return ['ok' => false, 'reason' => 'AI 未启用或未配置 Key', 'provider' => $provider, 'model' => ''];
        }
        if (!$this->aiProviderCatalog->supportsEmbeddings($provider)) {
            return [
                'ok'       => false,
                'reason'   => (string) ($catalog['name'] ?? $provider) . ' 不支持 Embedding，请换 OpenAI/通义/智谱等或关闭向量开关',
                'provider' => $provider,
                'model'    => '',
            ];
        }

        return [
            'ok'       => true,
            'reason'   => '可用',
            'provider' => $provider,
            'model'    => $this->embeddingModel($provider),
        ];
    }

    /**
     * 定时任务：补写缺失 embedding_json
     *
     * @return array{embedded:int,skipped:bool,msg:string}
     */
    public function backfillBatch(int $limit = QueryLimit::EMBEDDING_BACKFILL): array
    {
        $limit = max(1, min(200, $limit));
        if (!$this->isOperational()) {
            return ['embedded' => 0, 'skipped' => true, 'msg' => $this->statusForAdmin()['reason']];
        }
        $rows = AiChunk::whereRaw("(embedding_json IS NULL OR embedding_json = '' OR embedding_json = '[]')")
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id,document_id,content')
            ->select()
            ->toArray();
        $n = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $vec = $this->embedText($content);
            if ($vec === null) {
                break;
            }
            AiChunk::where('id', (int) ($row['id'] ?? 0))->update([
                'embedding_json' => json_encode($vec, JSON_UNESCAPED_UNICODE),
                'updated_at'     => time(),
            ]);
            $n++;
        }

        return ['embedded' => $n, 'skipped' => false, 'msg' => "已写入 {$n} 条分块向量"];
    }

    private function embeddingModel(string $provider): string
    {
        $cfg = trim((string) $this->configService->get('ai_embedding_model', ''));
        if ($cfg !== '') {
            return $cfg;
        }
        $catalog = $this->aiProviderCatalog->get($provider);

        return (string) ($catalog['embedding_model'] ?? 'text-embedding-3-small');
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload, string $apiKey): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }
        $insecure = filter_var(env('AI_CURL_SSL_INSECURE', false), FILTER_VALIDATE_BOOLEAN);
        $res = SimpleHttpClient::request($url, [
            'method'     => 'POST',
            'body'       => $body,
            'timeout'    => 60,
            'headers'    => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            'verify_ssl' => !$insecure,
        ]);
        if ($res['errno'] !== 0 || $res['http_code'] < 200 || $res['http_code'] >= 300) {
            return null;
        }

        return $res['body'];
    }
}
