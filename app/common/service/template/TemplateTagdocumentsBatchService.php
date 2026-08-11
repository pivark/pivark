<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;
use app\common\service\template\TemplateTagParser;
use app\common\service\template\TemplateBlockRenderer;

use app\common\model\Document;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\DocumentPublicService;
use app\common\service\search\SearchMemberContext;
use app\common\service\tag\TagDocumentLinkIndexService;
use app\common\service\tag\TagService;
use app\common\service\tag\TagSlugIndexService;

final class TemplateTagdocumentsBatchService
{

    public function __construct(
        private readonly TagDocumentLinkIndexService $tagDocumentLinkIndexService,
        private readonly TemplateBlockRenderer $templateBlockRenderer,
        private readonly TemplateTagParser $templateTagParser,
        private readonly TagSlugIndexService $tagSlugIndexService,
        private readonly TagService $tagService,
        private readonly SearchMemberContext $searchMemberContext,
        private readonly DocumentPublicService $documentPublicService,
        private readonly DocumentFormatService $documentFormatService,
        private readonly DocumentPublicService $documentService,
    ) {
    }

    /** @var list<array{params: array<string, mixed>, offset: int, limit: int}> */
    private static array $blocks = [];

    public function reset(): void
    {
        self::$blocks = [];
        $this->tagDocumentLinkIndexService->reset();
    }

    public function registerFromAttrs(array $attrs): void
    {
        $params = $this->templateBlockRenderer->listPublicParamsFromTagAttrs($attrs);
        if (!$this->canMerge($params)) {
            return;
        }
        self::$blocks[] = [
            'params' => $params,
            'offset' => (int) ($params['offset'] ?? 0),
            'limit'  => (int) ($params['limit'] ?? 15),
        ];
    }

    public function warmFromHtml(string $html): void
    {
        $this->reset();
        $this->appendFromHtml($html);
    }

    public function appendFromHtml(string $html): void
    {
        $html = $this->templateTagParser->normalizeTagAliases($html);
        if (!preg_match_all('/\{pv:arclist\b((?:[^{}]|\{\$[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*\})*)\}/i', $html, $matches, PREG_SET_ORDER)) {
            return;
        }
        $known = [];
        foreach (self::$blocks as $block) {
            $known[$this->blockKey($block)] = true;
        }
        $added = false;
        foreach ($matches as $m) {
            $params = $this->templateBlockRenderer->listPublicParamsFromTagAttrs(
                $this->templateTagParser->parseAttrs($m[1])
            );
            if (!$this->canMerge($params)) {
                continue;
            }
            $block = [
                'params' => $params,
                'offset' => (int) ($params['offset'] ?? 0),
                'limit'  => (int) ($params['limit'] ?? 15),
            ];
            $key = $this->blockKey($block);
            if (isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            self::$blocks[] = $block;
            $added          = true;
        }
        if (!$added) {
            return;
        }
        $this->pagePrefetchLinks();
        $this->execute();
    }

    /**
     * @param array{params: array<string, mixed>, offset: int, limit: int} $block
     */
    private function blockKey(array $block): string
    {
        return $this->mergeGroupKey($block['params']) . '|' . $block['offset'] . '|' . $block['limit'];
    }

    public function execute(): void
    {
        if (self::$blocks === []) {
            return;
        }

        /** @var array<string, list<array{params: array<string, mixed>, offset: int, limit: int}>> $bySort */
        $bySort = [];
        foreach (self::$blocks as $block) {
            $bySort[$this->sortGroupKey($block['params'])][] = $block;
        }

        foreach ($bySort as $specs) {
            if ($this->canSuperBatch($specs)) {
                $this->executeSuperBatch($specs);
            } else {
                $this->executePerTagGroup($specs);
            }
        }
    }

    private function pagePrefetchLinks(): void
    {
        if (self::$blocks === []) {
            return;
        }

        $slugMap = $this->tagSlugIndexService->slugIdMap();
        $tagIds  = [];
        foreach (self::$blocks as $block) {
            foreach (array_filter(array_map('trim', explode(',', (string) ($block['params']['tags'] ?? '')))) as $slug) {
                if (isset($slugMap[$slug])) {
                    $tagIds[] = $slugMap[$slug];
                }
            }
        }

        $this->tagDocumentLinkIndexService->preloadForTagIds($tagIds);
        $docIds = array_keys($this->tagDocumentLinkIndexService->docTagSets());
        if ($docIds !== []) {
            $this->tagService->getTagsForDocuments($docIds);
        }
    }

    /**
     * @param list<array{params: array<string, mixed>, offset: int, limit: int}> $specs
     */
    private function canSuperBatch(array $specs): bool
    {
        foreach ($specs as $spec) {
            if (trim((string) ($spec['params']['attr'] ?? '')) !== '') {
                return false;
            }
        }

        return $specs !== [];
    }

    /**
     * @param list<array{params: array<string, mixed>, offset: int, limit: int}> $specs
     */
    private function executeSuperBatch(array $specs): void
    {
        $sort       = (string) ($specs[0]['params']['sort'] ?? 'id_desc');
        $unionSlugs = [];
        $maxNeed    = 0;
        foreach ($specs as $spec) {
            $maxNeed = max($maxNeed, $spec['offset'] + $spec['limit']);
            foreach (array_filter(array_map('trim', explode(',', (string) ($spec['params']['tags'] ?? '')))) as $slug) {
                $unionSlugs[$slug] = true;
            }
        }
        $maxNeed    = min(100, max(1, $maxNeed));
        $unionSlugs = array_keys($unionSlugs);
        if ($unionSlugs === []) {
            return;
        }

        if ($this->tagSlugIndexService->idsForSlugs($unionSlugs) === []) {
            return;
        }

        $docTagSets = $this->tagDocumentLinkIndexService->docTagSets();
        $candidateIds = array_keys($docTagSets);
        if ($candidateIds === []) {
            return;
        }

        $query = Document::whereIn('id', $candidateIds)->where('status', 1)->whereNull('deleted_at');
        $this->searchMemberContext->applyReadPermToQuery($query, $this->searchMemberContext->forPublicSearch());
        $this->documentPublicService->applySortToQuery($query, $sort);
        $rows = $query->limit($maxNeed * 2)->select()->toArray();
        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->tagDocumentLinkIndexService->preloadForDocumentIds($ids);
        $tagsMap = $this->tagService->getTagsForDocuments($ids);
        $ordered = [];
        foreach ($rows as $row) {
            $ordered[] = $this->documentFormatService->formatForApi($row, false, $tagsMap[(int) $row['id']] ?? null);
        }
        $ordered = $this->documentFormatService->applyListMediaFieldsBatch($ordered);

        foreach ($specs as $spec) {
            $requiredIds = $this->tagSlugIndexService->idsForSlugs(
                array_filter(array_map('trim', explode(',', (string) ($spec['params']['tags'] ?? ''))))
            );
            if ($requiredIds === []) {
                continue;
            }
            $requiredSet = array_fill_keys($requiredIds, true);
            $filtered    = [];
            foreach ($ordered as $item) {
                $docId   = (int) ($item['id'] ?? 0);
                $hasTags = $docTagSets[$docId] ?? [];
                $ok      = true;
                foreach ($requiredSet as $tid => $_) {
                    if (!isset($hasTags[$tid])) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $filtered[] = $item;
                }
            }
            $list = array_slice($filtered, $spec['offset'], $spec['limit']);
            $this->documentPublicService->seedListPublicRequestCache($spec['params'], [
                'list'  => $list,
                'total' => count($filtered),
                'page'  => 1,
                'limit' => $spec['limit'],
            ]);
        }
    }

    /**
     * @param list<array{params: array<string, mixed>, offset: int, limit: int}> $specs
     */
    private function executePerTagGroup(array $specs): void
    {
        /** @var array<string, list<array{params: array<string, mixed>, offset: int, limit: int}>> $groups */
        $groups = [];
        foreach ($specs as $block) {
            $groups[$this->mergeGroupKey($block['params'])][] = $block;
        }

        foreach ($groups as $groupSpecs) {
            $need = 0;
            foreach ($groupSpecs as $spec) {
                $need = max($need, $spec['offset'] + $spec['limit']);
            }
            $need = min(100, max(1, $need));

            $fetchParams               = $groupSpecs[0]['params'];
            $fetchParams['offset']     = 0;
            $fetchParams['limit']      = $need;
            $fetchParams['page']       = 1;
            $fetchParams['with_total'] = false;

            $full = $this->documentService->listPublic($fetchParams);

            foreach ($groupSpecs as $spec) {
                $list = array_slice($full['list'], $spec['offset'], $spec['limit']);
                $this->documentPublicService->seedListPublicRequestCache($spec['params'], [
                    'list'  => $list,
                    'total' => (int) ($full['total'] ?? 0),
                    'page'  => max(1, (int) ($spec['params']['page'] ?? 1)),
                    'limit' => $spec['limit'],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function canMerge(array $params): bool
    {
        if ((int) ($params['id'] ?? 0) > 0) {
            return false;
        }
        if (trim((string) ($params['ids'] ?? '')) !== '') {
            return false;
        }
        if (trim((string) ($params['keyword'] ?? '')) !== '') {
            return false;
        }
        if (max(1, (int) ($params['page'] ?? 1)) > 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sortGroupKey(array $params): string
    {
        $base = $params;
        unset($base['tags'], $base['offset'], $base['limit'], $base['with_total'], $base['page']);
        ksort($base);

        return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function mergeGroupKey(array $params): string
    {
        $base = $params;
        unset($base['offset'], $base['limit'], $base['with_total'], $base['page']);
        ksort($base);

        return hash('sha256', json_encode($base, JSON_UNESCAPED_UNICODE));
    }
}
