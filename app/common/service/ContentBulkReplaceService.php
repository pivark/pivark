<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service;
use app\common\support\ServiceResult;

use app\common\service\bulk\ContentBulkReplaceQuery;
use app\common\service\bulk\ContentBulkReplaceRules;
use app\common\service\bulk\ContentBulkReplaceRunner;
use app\common\model\Document;
use app\common\model\DocumentTag;
use app\common\model\SitePage;
use think\db\Query;
use app\common\support\HtmlSanitizer;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

/** 批量查找替换：文档 + 可选单页，预览/分批执行/快照/审计 */

/** 批量查找替换门面 */
class ContentBulkReplaceService
{

    public function __construct(
        private readonly ContentBulkReplaceRules $contentBulkReplaceRules,
        private readonly ContentBulkReplaceQuery $contentBulkReplaceQuery,
        private readonly ContentBulkReplaceRunner $contentBulkReplaceRunner,
    ) {
    }


    public const MAX_SCAN           = 50000;
    public const BATCH_SIZE         = 100;
    public const PREVIEW_SNIPPETS   = 50;
    public const MAX_FIND_LEN       = 500;
    public const MAX_RULES          = 20;
    public const RATE_LIMIT_SECONDS = 60;

    /** @var array<string, string> */
    public const DOCUMENT_FIELDS = [
        'content'          => 'PC 正文',
        'content_mobile'   => '手机正文',
        'summary'          => '摘要',
        'subtitle'         => '副标题',
        'seo_title'        => 'SEO 标题',
        'seo_keywords'     => 'SEO 关键词',
        'seo_description'  => 'SEO 描述',
        'litpic'           => '缩略图 URL',
        'external_url'     => '外链 URL',
    ];

    /** @var list<string> */
    private const HTML_FIELDS = ['content', 'content_mobile'];

    /** @var list<string> */
    public const DEFAULT_FIELDS = ['content', 'content_mobile'];

    /**
     * @param array{find?:string,replace?:string,rules?:list<array{find?:string,replace?:string}>} $input
     * @return list<array{find:string,replace:string}>
     */

    public function normalizeRules(array $input): array
    {
        return $this->contentBulkReplaceRules->normalizeRules($input);
    }

    public function optionsFromRequest(array $post): array
    {
        return $this->contentBulkReplaceQuery->optionsFromRequest($post);
    }

    public function preview(array $filter, array $rules, array $fields, array $options = []): ServiceResult
    {
        return $this->contentBulkReplaceRunner->preview($filter, $rules, $fields, $options);
    }

    public function execute(
        array $filter,
        array $rules,
        array $fields,
        array $options = [],
        int $batchPage = 1
    ): ServiceResult {
        return $this->contentBulkReplaceRunner->execute($filter, $rules, $fields, $options, $batchPage);
    }

    public function normalizeTagNames(mixed $raw): array
    {
        return $this->contentBulkReplaceRules->normalizeTagNames($raw);
    }

    public function normalizeAttrKeys(mixed $raw): array
    {
        return $this->contentBulkReplaceRules->normalizeAttrKeys($raw);
    }

    public function applyBulkDocumentFilters(Query $query, array $filter): void
    {
        $this->contentBulkReplaceQuery->applyBulkDocumentFilters($query, $filter);
    }
}
