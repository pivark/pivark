<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document;

use app\common\model\Document;
use app\common\service\media\MediaUrlService;
use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\HtmlSanitizer;
use think\facade\Db;

/**
 * 文档产品多图真源（≠ doc_gallery 图集）。封面指针仍为 documents.litpic。
 */
final class DocumentProductImageService
{
    /**
     * @return list<array{url:string,sort:int,is_cover:int}>
     */
    public function listForDocument(int $documentId): array
    {
        $documentId = max(0, $documentId);
        if ($documentId < 1 || !DbTable::exists('document_product_images')) {
            return [];
        }
        $rows = Db::name('document_product_images')
            ->where('document_id', $documentId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('url,sort,is_cover')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'url'      => $url,
                'sort'     => (int) ($row['sort'] ?? 0),
                'is_cover' => (int) ($row['is_cover'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        return $out;
    }

    /**
     * 整表替换产品多图；同步 documents.litpic = 封面。
     *
     * @param list<array{url?:string,sort?:int,is_cover?:int|bool}|string> $images
     */
    public function replaceForDocument(int $documentId, array $images, ?string $fallbackLitpic = null): void
    {
        $documentId = max(0, $documentId);
        if ($documentId < 1 || !DbTable::exists('document_product_images')) {
            return;
        }
        $normalized = $this->normalizeIncoming($images);
        if ($normalized === [] && $fallbackLitpic !== null) {
            $fb = app(MediaUrlService::class)->formatForStorage(HtmlSanitizer::cleanUrl($fallbackLitpic));
            if ($fb !== '') {
                $normalized[] = ['url' => $fb, 'sort' => 0, 'is_cover' => 1];
            }
        }
        $coverUrl = '';
        foreach ($normalized as $row) {
            if ($row['is_cover'] === 1) {
                $coverUrl = $row['url'];
                break;
            }
        }
        if ($coverUrl === '' && $normalized !== []) {
            $normalized[0]['is_cover'] = 1;
            $coverUrl = $normalized[0]['url'];
        }

        $now = AppTime::now();
        Db::transaction(function () use ($documentId, $normalized, $coverUrl, $now): void {
            Db::name('document_product_images')->where('document_id', $documentId)->delete();
            $sort = 0;
            foreach ($normalized as $row) {
                Db::name('document_product_images')->insert([
                    'document_id' => $documentId,
                    'url'         => $row['url'],
                    'sort'        => $sort,
                    'is_cover'    => $row['is_cover'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $sort++;
            }
            if ($coverUrl !== '') {
                Document::where('id', $documentId)->update([
                    'litpic'     => mb_substr($coverUrl, 0, 512),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * 仅有 litpic、尚无产品图行时，保证至少一行封面（读路径兜底不写库也可）。
     *
     * @return list<array{url:string,sort:int,is_cover:int}>
     */
    public function listOrSynthesizeFromLitpic(int $documentId, string $litpic): array
    {
        $list = $this->listForDocument($documentId);
        if ($list !== []) {
            return $list;
        }
        $litpic = trim($litpic);
        if ($litpic === '') {
            return [];
        }

        return [['url' => $litpic, 'sort' => 0, 'is_cover' => 1]];
    }

    /**
     * @param list<array{url?:string,sort?:int,is_cover?:int|bool}|string> $images
     * @return list<array{url:string,sort:int,is_cover:int}>
     */
    private function normalizeIncoming(array $images): array
    {
        $media = app(MediaUrlService::class);
        $out = [];
        $seen = [];
        $i = 0;
        foreach ($images as $raw) {
            if (is_string($raw)) {
                $url = $media->formatForStorage(HtmlSanitizer::cleanUrl($raw));
                $isCover = 0;
            } elseif (is_array($raw)) {
                $url = $media->formatForStorage(HtmlSanitizer::cleanUrl((string) ($raw['url'] ?? '')));
                $isCover = !empty($raw['is_cover']) ? 1 : 0;
            } else {
                continue;
            }
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [
                'url'      => mb_substr($url, 0, 512),
                'sort'     => $i,
                'is_cover' => $isCover,
            ];
            $i++;
        }
        $coverCount = 0;
        foreach ($out as $row) {
            if ($row['is_cover'] === 1) {
                $coverCount++;
            }
        }
        if ($coverCount > 1) {
            $first = true;
            foreach ($out as $idx => $row) {
                if ($row['is_cover'] !== 1) {
                    continue;
                }
                if ($first) {
                    $first = false;
                    continue;
                }
                $out[$idx]['is_cover'] = 0;
            }
        }

        return $out;
    }
}
