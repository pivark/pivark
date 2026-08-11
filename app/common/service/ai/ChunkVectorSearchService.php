<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */

declare(strict_types=1);



namespace app\common\service\ai;
use app\common\service\ai\EmbeddingService;
use app\common\service\ai\ChunkService;
use app\common\support\QueryLimit;



use app\common\model\AiChunk;
use app\common\service\search\SmartSearchConfigService;



/** 分块混合召回：关键词 + API/本地向量，按配置权重合并（P4） */

final class ChunkVectorSearchService
{

    public function __construct(
        private readonly ChunkService $chunkService,
        private readonly SmartSearchConfigService $smartSearchConfigService,
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>

     */

    public function search(string $keyword, int $limit = QueryLimit::FRONT_LIST): array
    {
        $keyword = trim($keyword);
        if ($keyword === '' || $limit < 1) {
            return [];
        }

        $keywordHits = $this->chunkService->searchChunks($keyword, $limit * 2);
        if (!$this->smartSearchConfigService->vectorEnabled()) {
            return array_slice($keywordHits, 0, $limit);
        }

        return $this->mergeHybridHits(
            $keywordHits,
            $this->searchBySimilarity($keyword, $limit * 3),
            $limit,
            $this->smartSearchConfigService->scoreWeights()
        );
    }

    /**
     * @param list<array<string, mixed>> $keywordHits
     * @param list<array<string, mixed>> $vectorHits
     * @param array{vector:int,keyword:int} $weights
     * @return list<array<string, mixed>>
     */
    private function mergeHybridHits(array $keywordHits, array $vectorHits, int $limit, array $weights): array
    {
        $vecScale = max(1, $weights['vector']) / 100.0;
        $kwScale  = max(1, $weights['keyword']) / 100.0;

        /** @var array<string, array{row:array<string,mixed>,score:float}> $merged */
        $merged = [];
        foreach ($vectorHits as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sig = $this->sig($row);
            if ($sig === '') {
                continue;
            }
            $merged[$sig] = [
                'row'   => $row,
                'score' => (float) ($row['_score'] ?? 0.5) * $vecScale + 0.02,
            ];
        }

        $kwTotal = max(1, count($keywordHits));
        foreach ($keywordHits as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $sig = $this->sig($row);
            if ($sig === '') {
                continue;
            }
            $bonus = $kwScale * (1.0 - ($i / $kwTotal) * 0.35);
            if (isset($merged[$sig])) {
                $merged[$sig]['score'] += $bonus;
            } else {
                $merged[$sig] = ['row' => $row, 'score' => $bonus];
            }
        }

        $list = array_values($merged);
        usort($list, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $out = [];
        foreach (array_slice($list, 0, $limit) as $item) {
            $out[] = $item['row'];
        }

        return $out;
    }



    /**

     * @param array<string, mixed> $row

     */

    private function sig(array $row): string

    {

        $docId = (int) ($row['document_id'] ?? 0);

        $idx   = (int) ($row['chunk_index'] ?? 0);



        return $docId > 0 ? $docId . ':' . $idx : '';

    }



    /**

     * @return list<array<string, mixed>>

     */

    private function searchBySimilarity(string $keyword, int $limit): array

    {

        $scanLimit = min(800, max(120, $limit * 40));

        $query     = AiChunk::alias('c')

            ->join('documents d', 'd.id = c.document_id')

            ->whereNull('d.deleted_at')

            ->where('d.status', 1);

        if ($this->embeddingService->isOperational()) {

            $query->whereRaw("(c.embedding_json IS NOT NULL AND c.embedding_json != '' AND c.embedding_json != '[]')");

        }

        $rows = $query
            ->field('c.document_id,c.chunk_index,c.content,c.embedding_json,d.title,d.summary')
            ->order('c.updated_at', 'desc')
            ->limit($scanLimit)
            ->select()
            ->toArray();

        if ($rows === []) {
            return [];
        }

        return $this->scoreAndSliceChunks($rows, $keyword, $limit);

    }



    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function scoreAndSliceChunks(array $rows, string $keyword, int $limit): array
    {
        $queryVec = $this->queryVector($keyword);
        $scored   = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $content = (string) ($row['content'] ?? '');
            $stored  = $this->decodeEmbedding($row['embedding_json'] ?? null);
            $score   = $stored !== null && $queryVec !== null
                ? $this->cosine($queryVec, $stored)
                : $this->tokenOverlapScore($keyword, $content);
            if ($score < 0.05) {
                continue;
            }
            $row['_score'] = $score;
            $scored[]      = $row;
        }
        usort($scored, static fn (array $a, array $b): int => ($b['_score'] <=> $a['_score']));

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<float>|null
     */
    private function queryVector(string $keyword): ?array

    {

        if ($this->embeddingService->isOperational()) {

            $api = $this->embeddingService->embedText($keyword);

            if ($api !== null) {

                return $api;

            }

        }



        return $this->hashEmbed($keyword);

    }



    /**

     * @return list<float>

     */

    private function hashEmbed(string $text): array

    {

        $text = mb_strtolower(trim($text));

        $tokens = preg_split('/[\s,，、；;。.!?]+/u', $text) ?: [];

        $freq   = [];

        foreach ($tokens as $tok) {

            $tok = trim((string) $tok);

            if ($tok === '' || mb_strlen($tok) < 2) {

                continue;

            }

            $freq[$tok] = ($freq[$tok] ?? 0) + 1;

        }

        if ($freq === []) {

            $freq[$text] = 1;

        }

        $vec = [];

        foreach ($freq as $tok => $w) {

            $h = crc32($tok) % 256;

            $vec[$h] = ($vec[$h] ?? 0.0) + (float) $w;

        }

        $norm = 0.0;

        foreach ($vec as $v) {

            $norm += $v * $v;

        }

        $norm = sqrt($norm) ?: 1.0;

        $out = array_fill(0, 256, 0.0);

        foreach ($vec as $i => $v) {

            $out[$i] = $v / $norm;

        }



        return $out;

    }



    /** @return list<float>|null */

    private function decodeEmbedding(mixed $raw): ?array

    {

        if ($raw === null || $raw === '') {

            return null;

        }

        if (is_string($raw)) {

            $decoded = json_decode($raw, true);

        } else {

            $decoded = $raw;

        }

        if (!is_array($decoded)) {

            return null;

        }

        $out = [];

        foreach ($decoded as $v) {

            $out[] = (float) $v;

        }



        return $out !== [] ? $out : null;

    }



    /**

     * @param list<float> $a

     * @param list<float> $b

     */

    private function cosine(array $a, array $b): float

    {

        $len = min(count($a), count($b));

        if ($len < 1) {

            return 0.0;

        }

        $dot = 0.0;

        $na  = 0.0;

        $nb  = 0.0;

        for ($i = 0; $i < $len; $i++) {

            $dot += $a[$i] * $b[$i];

            $na  += $a[$i] * $a[$i];

            $nb  += $b[$i] * $b[$i];

        }

        if ($na <= 0 || $nb <= 0) {

            return 0.0;

        }



        return $dot / (sqrt($na) * sqrt($nb));

    }



    private function tokenOverlapScore(string $keyword, string $content): float

    {

        $kw = mb_strtolower(trim($keyword));

        $ct = mb_strtolower(trim($content));

        if ($kw === '' || $ct === '') {

            return 0.0;

        }

        if (mb_strpos($ct, $kw) !== false) {

            return 1.0;

        }

        $parts = preg_split('/[\s,，、；;]+/u', $kw) ?: [];

        $hit   = 0;

        $total = 0;

        foreach ($parts as $p) {

            $p = trim((string) $p);

            if ($p === '' || mb_strlen($p) < 2) {

                continue;

            }

            $total++;

            if (mb_strpos($ct, $p) !== false) {

                $hit++;

            }

        }



        return $total > 0 ? $hit / $total : 0.0;

    }

}

