<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\ServiceResult;

use app\common\support\SiteUrl;

/**
 * 知识搜索（轻量 RAG）：关键词召回站内资源 + LLM 总结
 * 向量检索二期接入；当前不依赖独立知识库服务部署
 */
class KnowledgeSearchService
{

    public function __construct(
        private readonly KnowledgeSearchAiDeps $ai,
        private readonly KnowledgeSearchRecallDeps $recall,
    ) {
    }

    /**
     * @return ServiceResult
     */
    public function smartSearch(string $keyword, ?int $docLimit = null): ServiceResult
    {
        $keyword = $this->recall->smartSearchOrchestrator->prepareKeyword($keyword);
        if ($keyword === '') {
            return ServiceResult::fail('请输入搜索内容');
        }

        $productPack = class_exists(\app\common\service\product\ProductSmartSearchService::class)
            && \app\common\service\product\ProductSmartSearchService::isAvailable()
            ? \app\common\service\product\ProductSmartSearchService::search($keyword, 8, true)
            : ['list' => [], 'parsed' => [], 'filter_chips' => [], 'catalog_url' => ''];

        if (!$this->ai->aiConfig->activeProviderConfigured()) {
            if ($this->ai->smartSearchConfig->fallbackEnabled()) {
                return $this->wrapProductMeta(
                    $this->recall->smartSearchOrchestrator->groupSources(
                        \app\common\service\product\ProductSmartSearchService::toKnowledgeSources(
                            is_array($productPack['list'] ?? null) ? $productPack['list'] : [],
                        ),
                    ),
                    $productPack,
                    $this->ruleAnswerFromProducts($keyword, $productPack),
                    'rule_fallback',
                );
            }

            return ServiceResult::fail('AI 未启用或未配置当前服务商 API Key');
        }

        $docLimit = $docLimit ?? $this->recall->searchConfig->aiDocLimit();

        $matchedRule   = $this->ai->intent->matchRule($keyword);
        $docs          = $this->recallDocuments($keyword, $docLimit, $matchedRule);
        $products      = is_array($productPack['list'] ?? null) ? $productPack['list'] : [];
        $siteMeta      = [];
        $pluginCatalog = [];
        $promptHint    = '';

        if ($matchedRule !== null) {
            $promptHint = trim((string) ($matchedRule['prompt_hint'] ?? ''));
            $guided     = $this->applyGuidedRecall($matchedRule, $docs, $docLimit);
            $docs          = $guided['docs'];
            $siteMeta      = $guided['site_meta'];
            $pluginCatalog = $guided['plugins'];
        }

        if ($docs === [] && $siteMeta === [] && $pluginCatalog === [] && $products === []) {
            return ServiceResult::ok(['answer' => '未在站内资源中找到与「' . $keyword . '」足够相关的内容，请换关键词或浏览栏目。', 'mode' => 'empty', 'sources' => []], 'ok');
        }

        $mode = $matchedRule !== null
            ? (string) ($matchedRule['id'] ?? 'guided') . '_llm'
            : 'keyword_llm';
        if ($products !== [] && $docs === []) {
            $mode = 'product_llm';
        } elseif ($products !== []) {
            $mode = $mode . '+product';
        }

        $answer = $this->answerFromContext($keyword, $docs, $siteMeta, $pluginCatalog, $products, $mode, $promptHint);
        $payload = $answer->dataArray();
        if ($answer->isOk() && trim((string) ($payload['answer'] ?? '')) === ''
            && $this->ai->smartSearchConfig->fallbackEnabled()) {
            $payload['answer']   = $this->ruleAnswerFromProducts($keyword, $productPack);
            $payload['mode']     = 'llm_empty_fallback';
            $payload['fallback'] = 1;
        }
        $payload['parsed']       = $productPack['parsed'] ?? [];
        $payload['filter_chips'] = $productPack['filter_chips'] ?? [];
        $payload['catalog_url']  = (string) ($productPack['catalog_url'] ?? '');
        $payload['chunk_citations'] = $this->chunkCitations($keyword, min(5, $docLimit));
        $payload['sources_grouped'] = $this->recall->smartSearchOrchestrator->groupSources(
            is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
        );

        return ServiceResult::ok($payload, $answer->message(), $answer->meta());
    }

    /**
     * @param array<string, mixed> $productPack
     */
    private function ruleAnswerFromProducts(string $keyword, array $productPack): string
    {
        $products = is_array($productPack['list'] ?? null) ? $productPack['list'] : [];
        if ($products === []) {
            return '未在站内品项中找到与「' . $keyword . '」匹配的在售型号，可尝试调整参数词或浏览产品中心。';
        }
        $lines = ['已根据自定义参数匹配到 ' . count($products) . ' 条品项：'];
        foreach (array_slice($products, 0, 6) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = ($i + 1) . '. ' . ($row['name'] ?? '');
            $spec = trim((string) ($row['attrs_summary_text'] ?? ''));
            if ($spec !== '') {
                $line .= '（' . $spec . '）';
            }
            $reason = trim((string) ($row['match_reason_text'] ?? ''));
            if ($reason !== '') {
                $line .= ' — ' . $reason;
            }
            $lines[] = $line;
        }
        $catalog = (string) ($productPack['catalog_url'] ?? '');
        if ($catalog !== '') {
            $lines[] = '筛选链接：' . $catalog;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $productPack
     * @param list<array<string, mixed>> $grouped
     * @return array<string, mixed>
     */
    private function wrapProductMeta(array $grouped, array $productPack, string $answer, string $mode): ServiceResult
    {
        $products = is_array($productPack['list'] ?? null) ? $productPack['list'] : [];

        return ServiceResult::ok(['answer' => $answer, 'mode' => $mode, 'fallback' => 1, 'sources' => class_exists(\app\common\service\product\ProductSmartSearchService::class)
                ? \app\common\service\product\ProductSmartSearchService::toKnowledgeSources($products)
                : [], 'sources_grouped' => $grouped, 'parsed' => $productPack['parsed'] ?? [], 'filter_chips' => $productPack['filter_chips'] ?? [], 'catalog_url' => (string) ($productPack['catalog_url'] ?? ''), 'chunk_citations' => []], 'ok');
    }

    /** @return list<array{document_id:int,chunk_index:int,snippet:string,title:string}> */
    private function chunkCitations(string $keyword, int $limit): array
    {
        if (!class_exists(ChunkVectorSearchService::class)) {
            return [];
        }
        $out = [];
        foreach ($this->ai->chunkVector->search($keyword, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $snippet = mb_substr(trim((string) ($row['content'] ?? '')), 0, 200);
            $out[]   = [
                'document_id' => (int) ($row['document_id'] ?? 0),
                'chunk_index' => (int) ($row['chunk_index'] ?? 0),
                'snippet'     => $snippet,
                'title'       => (string) ($row['title'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return null|string 匹配到的规则 id */
    public function resolveGuidedIntent(string $keyword): ?string
    {
        $rule = $this->ai->intent->matchRule($keyword);

        return $rule !== null ? (string) ($rule['id'] ?? '') : null;
    }

    public function isSiteOverviewQuery(string $keyword): bool
    {
        return $this->ai->intent->isSiteOverviewQuery($keyword);
    }

    public function isPluginCatalogQuery(string $keyword): bool
    {
        return $this->ai->intent->isPluginCatalogQuery($keyword);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recallProducts(string $keyword, int $limit): array
    {
        if (!class_exists(\app\common\service\product\ProductSmartSearchService::class)) {
            return [];
        }
        if (!\app\common\service\product\ProductSmartSearchService::isAvailable()) {
            return [];
        }

        return \app\common\service\product\ProductSmartSearchService::recall($keyword, $limit)['list'] ?? [];
    }

    /**
     * @param array<string, mixed>|null $matchedRule
     * @return list<array<string, mixed>>
     */
    private function recallDocuments(string $keyword, int $docLimit, ?array $matchedRule): array
    {
        $hit  = $this->recall->contentSearch->searchPublic($keyword, 1, $docLimit);
        $docs = $hit['documents']['list'] ?? [];
        $docs = $this->mergeChunkHits($docs, $keyword, $docLimit);

        if ($docs !== [] || $matchedRule === null) {
            return $docs;
        }

        $alternates = $this->alternateKeywordsForRule($matchedRule, $keyword);
        foreach (array_unique($alternates) as $alt) {
            $hit  = $this->recall->contentSearch->searchPublic($alt, 1, $docLimit);
            $docs = $hit['documents']['list'] ?? [];
            $docs = $this->mergeChunkHits($docs, $alt, $docLimit);
            if ($docs !== []) {
                return $docs;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<array<string, mixed>> $docs
     * @return array{
     *   docs:list<array<string,mixed>>,
     *   site_meta:array{name:string,description:string,keywords:string},
     *   plugins:list<array{identifier:string,name:string,description:string,kind:string,version:string,enabled:bool}>
     * }
     */
    private function applyGuidedRecall(array $rule, array $docs, int $limit): array
    {
        $siteMeta      = [];
        $pluginCatalog = [];
        $recall        = (string) ($rule['recall'] ?? 'custom');

        if (!empty($rule['include_site_meta']) || $recall === 'site_overview') {
            $pack     = $this->recallSiteOverview($limit, $rule);
            $siteMeta = $pack['site_meta'];
            if ($docs === [] && $recall === 'site_overview') {
                $docs = $pack['docs'];
            }
        }

        if (!empty($rule['include_plugin_catalog']) || $recall === 'plugin_catalog') {
            $pack          = $this->recallPluginCatalog($limit, $rule);
            $pluginCatalog = $pack['plugins'];
            if ($docs === [] && $recall === 'plugin_catalog') {
                $docs = $pack['docs'];
            }
        }

        if ($docs === [] && $recall === 'custom') {
            $docs = $this->recallCustomDocs($limit, $rule);
        }

        return [
            'docs'       => $docs,
            'site_meta'  => $siteMeta,
            'plugins'    => $pluginCatalog,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<string>
     */
    private function alternateKeywordsForRule(array $rule, string $keyword): array
    {
        $alternates = [];
        foreach ($rule['alt_keywords'] ?? [] as $alt) {
            $alt = trim((string) $alt);
            if ($alt !== '') {
                $alternates[] = $alt;
            }
        }

        if (!empty($rule['use_site_name_alt'])) {
            $siteName = trim((string) $this->recall->config->get('site_name', ''));
            if ($siteName !== '' && $siteName !== $keyword && mb_strpos($keyword, $siteName) === false) {
                $alternates[] = $siteName;
            }
        }

        return $alternates;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{docs:list<array<string,mixed>>,site_meta:array{name:string,description:string,keywords:string}}
     */
    private function recallSiteOverview(int $limit, array $rule): array
    {
        $cfg = $this->recall->config->getAll();
        $siteMeta = [
            'name'        => trim((string) ($cfg['site_name'] ?? '')),
            'description' => trim((string) ($cfg['site_description'] ?? '')),
            'keywords'    => trim((string) ($cfg['site_keywords'] ?? '')),
        ];

        $docAttrs = $this->stringList($rule['doc_attrs'] ?? [], ['recommend', 'headline']);
        $tagSlugs = $this->stringList($rule['tag_slugs'] ?? [], ['about', 'company', 'intro', 'product-community', 'site']);
        $fillLatest = !array_key_exists('fill_latest_docs', $rule) || !empty($rule['fill_latest_docs']);

        $docs = $this->recallDocsByAttrsAndTags($limit, $docAttrs, $tagSlugs, $fillLatest);

        return ['docs' => $docs, 'site_meta' => $siteMeta];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{
     *   docs:list<array<string,mixed>>,
     *   plugins:list<array{identifier:string,name:string,description:string,kind:string,version:string,enabled:bool}>
     * }
     */
    private function recallPluginCatalog(int $limit, array $rule): array
    {
        $plugins = [];
        foreach ($this->recall->plugins->listAdmin() as $row) {
            if ((int) ($row['installed'] ?? 0) !== 1) {
                continue;
            }
            $identifier = trim((string) ($row['identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }
            $plugins[] = [
                'identifier'  => $identifier,
                'name'        => trim((string) ($row['name'] ?? $identifier)),
                'description' => trim((string) ($row['description'] ?? '')),
                'kind'        => trim((string) ($row['kind'] ?? 'document-addon')),
                'version'     => trim((string) ($row['version'] ?? '')),
                'enabled'     => (int) ($row['enabled'] ?? 0) === 1,
            ];
        }

        usort($plugins, static function (array $a, array $b): int {
            $ae = $a['enabled'] ? 0 : 1;
            $be = $b['enabled'] ? 0 : 1;
            if ($ae !== $be) {
                return $ae <=> $be;
            }

            return strcmp($a['name'], $b['name']);
        });

        $altKeywords = $this->stringList($rule['alt_keywords'] ?? [], ['插件', 'weapp']);
        $tagSlugs    = $this->stringList($rule['tag_slugs'] ?? [], ['plugin', 'weapp', 'extension']);
        $docs        = $this->recallDocsByKeywordsAndTags($limit, $altKeywords, $tagSlugs, false);

        return ['docs' => $docs, 'plugins' => $plugins];
    }

    /**
     * @param array<string, mixed> $rule
     * @return list<array<string, mixed>>
     */
    private function recallCustomDocs(int $limit, array $rule): array
    {
        $docAttrs = $this->stringList($rule['doc_attrs'] ?? [], []);
        $tagSlugs = $this->stringList($rule['tag_slugs'] ?? [], []);
        $fillLatest = !array_key_exists('fill_latest_docs', $rule) || !empty($rule['fill_latest_docs']);

        $docs = $this->recallDocsByAttrsAndTags($limit, $docAttrs, $tagSlugs, $fillLatest);
        if ($docs !== []) {
            return $docs;
        }

        $altKeywords = $this->stringList($rule['alt_keywords'] ?? [], []);

        return $this->recallDocsByKeywordsAndTags($limit, $altKeywords, [], $fillLatest);
    }

    /**
     * @param list<string> $docAttrs
     * @param list<string> $tagSlugs
     * @return list<array<string, mixed>>
     */
    private function recallDocsByAttrsAndTags(
        int $limit,
        array $docAttrs,
        array $tagSlugs,
        bool $fillLatest
    ): array {
        $docs = [];
        $seen = [];

        $pushList = static function (array $list) use (&$docs, &$seen, $limit): void {
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $docs[]    = $row;
                $seen[$id] = true;
                if (count($docs) >= $limit) {
                    break;
                }
            }
        };

        foreach ($docAttrs as $attr) {
            $hit = $this->recall->documents->listPublic([
                'attr'  => $attr,
                'limit' => $limit,
                'sort'  => 'id_desc',
            ]);
            $pushList($hit['list'] ?? []);
            if (count($docs) >= $limit) {
                return array_slice($docs, 0, $limit);
            }
        }

        foreach ($tagSlugs as $slug) {
            $hit = $this->recall->documents->listPublic([
                'tags'  => $slug,
                'limit' => $limit,
                'sort'  => 'id_desc',
            ]);
            $pushList($hit['list'] ?? []);
            if (count($docs) >= $limit) {
                return array_slice($docs, 0, $limit);
            }
        }

        if ($fillLatest && count($docs) < $limit) {
            $hit = $this->recall->documents->listPublic([
                'limit' => $limit,
                'sort'  => 'id_desc',
            ]);
            $pushList($hit['list'] ?? []);
        }

        return array_slice($docs, 0, $limit);
    }

    /**
     * @param list<string> $keywords
     * @param list<string> $tagSlugs
     * @return list<array<string, mixed>>
     */
    private function recallDocsByKeywordsAndTags(
        int $limit,
        array $keywords,
        array $tagSlugs,
        bool $fillLatest
    ): array {
        $docs = [];
        $seen = [];

        $pushList = static function (array $list) use (&$docs, &$seen, $limit): void {
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1 || isset($seen[$id])) {
                    continue;
                }
                $docs[]    = $row;
                $seen[$id] = true;
                if (count($docs) >= $limit) {
                    break;
                }
            }
        };

        foreach ($keywords as $alt) {
            $hit = $this->recall->contentSearch->searchPublic($alt, 1, $limit);
            $pushList($hit['documents']['list'] ?? []);
            if (count($docs) >= $limit) {
                return array_slice($docs, 0, $limit);
            }
        }

        foreach ($tagSlugs as $slug) {
            $hit = $this->recall->documents->listPublic([
                'tags'  => $slug,
                'limit' => $limit,
                'sort'  => 'id_desc',
            ]);
            $pushList($hit['list'] ?? []);
            if (count($docs) >= $limit) {
                return array_slice($docs, 0, $limit);
            }
        }

        if ($fillLatest && count($docs) < $limit) {
            $hit = $this->recall->documents->listPublic([
                'limit' => $limit,
                'sort'  => 'id_desc',
            ]);
            $pushList($hit['list'] ?? []);
        }

        return array_slice($docs, 0, $limit);
    }

    /**
     * @param list<mixed> $items
     * @param list<string> $fallback
     * @return list<string>
     */
    private function stringList(array $items, array $fallback): array
    {
        $out = [];
        foreach ($items as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out !== [] ? $out : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @param array{name:string,description:string,keywords:string} $siteMeta
     * @param list<array{identifier:string,name:string,description:string,kind:string,version:string,enabled:bool}> $pluginCatalog
     * @param list<array<string,mixed>> $products
     * @return ServiceResult
     */
    private function answerFromContext(
        string $keyword,
        array $docs,
        array $siteMeta,
        array $pluginCatalog,
        array $products,
        string $mode,
        string $promptHint = ''
    ): ServiceResult {
        $siteMeta = array_merge(
            ['name' => '', 'description' => '', 'keywords' => ''],
            $siteMeta
        );

        $sources      = [];
        $contextParts = [];

        if ($siteMeta['name'] !== '' || $siteMeta['description'] !== '' || $siteMeta['keywords'] !== '') {
            $line = '【站点配置】';
            if ($siteMeta['name'] !== '') {
                $line .= '名称：' . $siteMeta['name'];
            }
            if ($siteMeta['description'] !== '') {
                $line .= ($line !== '【站点配置】' ? '；' : '') . '简介：' . mb_substr($siteMeta['description'], 0, 400);
            }
            if ($siteMeta['keywords'] !== '') {
                $line .= '；关键词：' . mb_substr($siteMeta['keywords'], 0, 120);
            }
            $contextParts[] = $line;
            $sources[]      = [
                'id'      => 0,
                'title'   => '站点配置（' . ($siteMeta['name'] !== '' ? $siteMeta['name'] : '本站') . '）',
                'summary' => $siteMeta['description'],
                'url'     => SiteUrl::home(),
            ];
        }

        if ($pluginCatalog !== []) {
            $enabledCount = count(array_filter($pluginCatalog, static fn (array $p): bool => $p['enabled']));
            $contextParts[] = '【已安装插件】共 ' . count($pluginCatalog) . ' 个，其中已启用 ' . $enabledCount . ' 个';
            foreach ($pluginCatalog as $plugin) {
                $line = '- ' . $plugin['name'] . '（' . $plugin['identifier'] . '）';
                if ($plugin['kind'] !== '') {
                    $line .= ' [' . $plugin['kind'] . ']';
                }
                if ($plugin['version'] !== '') {
                    $line .= ' v' . $plugin['version'];
                }
                $line .= $plugin['enabled'] ? '，已启用' : '，未启用';
                if ($plugin['description'] !== '') {
                    $line .= '：' . mb_substr($plugin['description'], 0, 200);
                }
                $contextParts[] = $line;
                $sources[]        = [
                    'id'      => 0,
                    'title'   => $plugin['name'] . '（' . $plugin['identifier'] . '）',
                    'summary' => $plugin['description'],
                    'url'     => SiteUrl::search($plugin['identifier']),
                ];
            }
        }

        if ($products !== [] && class_exists(\app\common\service\product\ProductSmartSearchService::class)) {
            $productBlock = \app\common\service\product\ProductSmartSearchService::buildContextLines($products);
            if ($productBlock !== '') {
                $contextParts[] = $productBlock;
            }
            foreach ($products as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $docId = (int) ($row['primary_document_id'] ?? 0);
                if ($docId > 0) {
                    $docRow = $this->recall->documents->getPublicDetail($docId);
                    if (is_array($docRow)) {
                        $contextParts[] = '关联文档：' . ($docRow['title'] ?? '')
                            . ' — ' . mb_substr(trim((string) ($docRow['summary'] ?? '')), 0, 160);
                    }
                }
            }
            foreach (\app\common\service\product\ProductSmartSearchService::toKnowledgeSources($products) as $src) {
                $sources[] = $src;
            }
        }

        foreach ($docs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title   = trim((string) ($row['title'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? $row['excerpt'] ?? ''));
            $url     = (string) ($row['url'] ?? SiteUrl::document((int) ($row['id'] ?? 0)));
            $sources[] = [
                'id'      => (int) ($row['id'] ?? 0),
                'title'   => $title,
                'summary' => $summary,
                'url'     => $url,
            ];
            $contextParts[] = (count($contextParts) + 1) . '. ' . $title
                . ($summary !== '' ? "\n   " . mb_substr($summary, 0, 280) : '');
        }

        $context = implode("\n", $contextParts);
        $system  = '你是站点知识库助手。仅根据提供的站内资源摘要回答用户问题。'
            . '若提供【在售品项】段落，请结合名称与参数（颜色、码数、规格等）直接推荐匹配型号，并说明匹配依据。'
            . '若资料不足请明确说明。回答简洁有条理，使用中文。'
            . '不要编造不存在的链接或文章标题。可在末尾建议用户点击下列相关资源。';
        if ($promptHint !== '') {
            $system .= $promptHint;
        }

        $chat = $this->ai->llmChat->chat([
            ['role' => 'system', 'content' => $system],
            [
                'role'    => 'user',
                'content' => "用户问题：{$keyword}\n\n站内相关资源摘要：\n{$context}",
            ],
        ], null, 0.4);

        if (!$chat->isOk()) {
            return ServiceResult::ok(['answer' => '', 'mode' => 'keyword_only', 'sources' => $sources], 'ok');
        }

        return ServiceResult::ok(['answer' => trim((string) ($chat['content'] ?? '')), 'mode' => $mode, 'sources' => $sources], 'ok');
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @return list<array<string, mixed>>
     */
    private function mergeChunkHits(array $docs, string $keyword, int $limit): array
    {
        $chunks = $this->ai->chunkVector->search($keyword, $limit * 2);
        if ($chunks === []) {
            return array_slice($docs, 0, $limit);
        }

        $seen   = [];
        $merged = [];
        foreach ($chunks as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (int) ($c['document_id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $snippet = mb_substr(trim((string) ($c['content'] ?? '')), 0, 280);
            $merged[] = [
                'id'      => $id,
                'title'   => (string) ($c['title'] ?? ''),
                'summary' => $snippet !== '' ? $snippet : (string) ($c['summary'] ?? ''),
                'url'     => SiteUrl::document($id),
                '_from'   => 'ai_chunk',
            ];
            $seen[$id] = true;
        }
        foreach ($docs as $d) {
            if (!is_array($d)) {
                continue;
            }
            $id = (int) ($d['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $merged[] = $d;
            $seen[$id] = true;
        }

        return array_slice($merged, 0, $limit);
    }
}
