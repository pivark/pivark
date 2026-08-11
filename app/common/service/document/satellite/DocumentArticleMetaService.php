<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\service\config\ConfigService;
use app\common\support\SiteUrl;

/** 文档详情 · 文末版权与来源信息 */
class DocumentArticleMetaService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    /**
     * @param array<string, mixed> $detail DocumentService::getPublicViewData 行
     * @return array{
     *   document_meta_publisher:string,
     *   document_meta_source_phrase:string,
     *   document_meta_address:string,
     *   document_meta_address_url:string
     * }
     */
    public function templateVars(array $detail): array
    {
        $siteName = trim((string) $this->config->get('site_name', ''));
        if ($siteName === '') {
            $siteName = '本站';
        }

        $title  = trim((string) ($detail['title'] ?? ''));
        $author = trim((string) ($detail['author_name'] ?? ''));
        $source = trim((string) ($detail['source'] ?? ''));

        $publisher = $author !== '' ? $author : $siteName;
        $path      = SiteUrl::documentFromRow($detail);
        // 文末「本文地址」跟站内地址模式；需要分享完整链时模板侧可再 absolute
        $fullUrl   = $path;

        return [
            'document_meta_publisher'     => $publisher,
            'document_meta_source_phrase' => $this->resolveSourcePhrase($source),
            'document_meta_address'       => ($title !== '' ? $title : '本文') . ' | ' . $siteName,
            'document_meta_address_url'   => $fullUrl,
        ];
    }

    public function resolveSourcePhrase(string $source): string
    {
        $source = trim($source);
        if ($source === '网络转载' || str_contains($source, '转载')) {
            return '转载于互联网';
        }
        if ($source === '合作供稿') {
            return '合作供稿';
        }
        if ($source === '本站原创' || $source === '') {
            return '发布于本站';
        }

        return '发布（来源：' . $source . '）';
    }
}
