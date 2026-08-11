<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\service\ai\EmbeddingService;

use app\common\model\AiChunk;
use app\common\service\config\AiConfigService;
use app\common\service\content\ContentSearchService;
use think\facade\Db;

/** 纯文本分块写入 ai_chunks */
class ChunkService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly EmbeddingService $embeddingService,
        private readonly ContentSearchService $contentSearchService,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function rebuildForDocument(int $documentId, string $plainText): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::fail('参数错误');
        }
        $plainText = trim($plainText);
        if ($plainText === '') {
            AiChunk::where('document_id', $documentId)->delete();
            return ServiceResult::ok(['count' => 0], '正文为空，已清空分块');
        }

        $max    = max(400, $this->aiConfigService->chunkMaxChars());
        $overlap = max(0, min(400, $this->aiConfigService->chunkOverlap()));
        $pieces = $this->splitText($plainText, $max, $overlap);
        $now    = time();

        Db::startTrans();
        try {
            AiChunk::where('document_id', $documentId)->delete();
            $index = 0;
            foreach ($pieces as $content) {
                $content = trim($content);
                if ($content === '') {
                    continue;
                }
                AiChunk::insert([
                    'document_id'    => $documentId,
                    'chunk_index'    => $index,
                    'content'        => $content,
                    'content_hash'   => hash('sha256', $content),
                    'token_estimate' => (int) ceil(mb_strlen($content) / 2),
                    'embedding_json' => null,
                    'meta_json'      => json_encode(['source' => 'document_body'], JSON_UNESCAPED_UNICODE),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $index++;
            }
            Db::commit();

            if (class_exists(EmbeddingService::class) && $this->embeddingService->isEnabled()) {
                $this->embeddingService->embedDocumentChunks($documentId);
            }

            return ServiceResult::ok(['count' => $index], 'ok');
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail(\app\common\support\ClientErrorMessage::fromThrowable($e));
        }
    }

    /**
     * @return list<string>
     */
    public function splitText(string $text, int $maxChars, int $overlap): array
    {
        $paragraphs = preg_split('/\n{2,}/u', str_replace(["\r\n", "\r"], "\n", $text)) ?: [$text];
        $chunks     = [];
        $buffer     = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer   = '';
                }
                foreach ($this->splitLongParagraph($para, $maxChars, $overlap) as $sub) {
                    $chunks[] = $sub;
                }
                continue;
            }
            $candidate = $buffer === '' ? $para : $buffer . "\n\n" . $para;
            if (mb_strlen($candidate) <= $maxChars) {
                $buffer = $candidate;
                continue;
            }
            $chunks[] = $buffer;
            $buffer   = $para;
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        if ($overlap > 0 && count($chunks) > 1) {
            $merged = [];
            foreach ($chunks as $i => $chunk) {
                if ($i === 0) {
                    $merged[] = $chunk;
                    continue;
                }
                $prev = $merged[$i - 1];
                $tail = mb_substr($prev, max(0, mb_strlen($prev) - $overlap));
                $merged[] = $tail . $chunk;
            }
            $chunks = $merged;
        }

        return array_values(array_filter($chunks, static fn (string $s): bool => trim($s) !== ''));
    }

    /**
     * @return list<string>
     */
    private function splitLongParagraph(string $para, int $maxChars, int $overlap): array
    {
        $sentences = preg_split('/(?<=[。！？.!?])\s*/u', $para) ?: [$para];
        $out       = [];
        $buf       = '';
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            if (mb_strlen($s) > $maxChars) {
                if ($buf !== '') {
                    $out[] = $buf;
                    $buf   = '';
                }
                for ($o = 0; $o < mb_strlen($s); $o += max(1, $maxChars - $overlap)) {
                    $out[] = mb_substr($s, $o, $maxChars);
                }
                continue;
            }
            $cand = $buf === '' ? $s : $buf . ' ' . $s;
            if (mb_strlen($cand) <= $maxChars) {
                $buf = $cand;
            } else {
                $out[] = $buf;
                $buf   = $s;
            }
        }
        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchChunks(string $keyword, int $limit = QueryLimit::FRONT_LIST): array
    {
        $keyword = trim($keyword);
        if ($keyword === '' || $limit < 1) {
            return [];
        }
        $like = $this->contentSearchService->likePattern($keyword);
        if ($like === '') {
            return [];
        }

        return AiChunk::alias('c')
            ->join('documents d', 'd.id = c.document_id')
            ->whereNull('d.deleted_at')
            ->where('d.status', 1)
            ->whereLike('c.content', $like)
            ->field('c.document_id,c.chunk_index,c.content,d.title,d.summary')
            ->order('c.updated_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }
}