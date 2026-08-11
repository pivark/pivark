<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 后台文档保存/静态化等内部辅助（不对外门面）
 */
declare(strict_types=1);


namespace app\common\service\document;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\model\Document;
use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
use app\common\service\content\ContentEditorService;
use app\common\service\content\EditorContentService;
use app\common\service\document\satellite\DocumentAssetSyncService;
use app\common\service\document\satellite\DocumentPresetService;
use app\common\service\item\ItemService;
use app\common\service\product\ProductTabPersistRegistry;
use app\common\service\infra\UrlPathService;
use app\common\service\member\MemberLevelService;
use app\common\service\media\MediaUrlService;
use app\common\service\search\SearchIndexService;
use app\common\service\search\SearchTextExtractor;
use app\common\service\seo\SeoExcerpt;
use app\common\service\seo\SitemapService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteNavService;
use app\common\service\static\StaticHtmlDispatch;
use app\common\service\tag\TagCore;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\support\AppTime;
use app\common\support\HtmlSanitizer;
use app\common\support\OpsLog;
use app\common\support\ServiceResult;
use think\facade\Db;

final class DocumentAdminInternals
{

    /**
     * 校验 + 清洗 POST，组装待落库字段（不含 create 专属 author/created_at）。
     *
     * @return ServiceResult data: { id:int, tagStr:string, saveData:array, extraNavIds:list<int> }
     */
    public function prepareSaveAdminPayload(array $data): ServiceResult
    {
        if (empty(trim((string) ($data['title'] ?? '')))) {
            return ServiceResult::fail('标题不能为空');
        }
        $id = (int) ($data['id'] ?? 0);
        // 品项详情文档：去掉误拼货号后缀，避免编辑保存把「名（code）」写回文档/品项
        $itemCodeHint = trim((string) ($data['product_item_code'] ?? ''));
        $itemIdHint = (int) ($data['product_item_id'] ?? 0);
        if ($itemCodeHint === '' && $itemIdHint > 0) {
            $itemCodeHint = trim((string) (\app\common\model\Item::where('id', $itemIdHint)->value('code') ?: ''));
        }
        if ($itemCodeHint === '' && $id > 0) {
            $primary = app(ItemService::class)->primaryItemRowForDocument($id);
            if (is_array($primary)) {
                $itemCodeHint = trim((string) ($primary['code'] ?? ''));
            }
        }
        if ($itemCodeHint !== '') {
            $data['title'] = ItemService::stripTrailingItemCodeFromTitle(
                (string) ($data['title'] ?? ''),
                $itemCodeHint,
            );
        }
        if (mb_strlen((string) $data['title']) > 200) {
            return ServiceResult::fail('标题不能超过200字符');
        }

        $navId = max(0, (int) ($data['nav_id'] ?? 0));
        if ($navId < 1 && $id > 0 && !array_key_exists('nav_id', $data)) {
            $navId = (int) (Document::where('id', $id)->whereNull('deleted_at')->value('nav_id') ?? 0);
        }
        $status = (int) ($data['status'] ?? 1);
        if ($status === 1 && $navId < 1) {
            return ServiceResult::fail('请选择栏目（文档必须挂在栏目上）');
        }
        if ($navId > 0 && !app(SiteNavService::class)->isContentCategoryId($navId)) {
            return ServiceResult::fail('所选分类不可挂载文档（请选文章/产品分类）');
        }
        if ($navId > 0) {
            $deny = app(\app\common\service\admin\AdminTagScopeService::class)->assertCurrentCanManageNav($navId);
            if ($deny !== null) {
                return $deny;
            }
        }

        $extraNavIds = [];
        if (array_key_exists('extra_nav_ids', $data)) {
            $extraNavIds = app(SiteNavService::class)->normalizeExtraNavIds($data['extra_nav_ids'] ?? [], $navId);
            foreach ($extraNavIds as $extraNavId) {
                $deny = app(\app\common\service\admin\AdminTagScopeService::class)->assertCurrentCanManageNav($extraNavId);
                if ($deny !== null) {
                    return $deny;
                }
            }
        } elseif ($id > 0) {
            $extraNavIds = app(SiteNavService::class)->listDocumentExtraNavIds($id);
            $extraNavIds = app(SiteNavService::class)->normalizeExtraNavIds($extraNavIds, $navId);
        }

        // Tag 仅聚合：空保持空；禁止因 nav_id 代挂 Tag（列表归属只认 nav_id）
        $tagStr = isset($data['tags'])
            ? app(TagService::class)->normalizeTagsCsv((string) $data['tags'])
            : '';

        $editorMode = app(ContentEditorService::class)->current();
        $rawContent = (string) ($data['content'] ?? '');
        $rawMobile  = (string) ($data['content_mobile'] ?? '');
        app(EditorContentService::class)->resetProcessReport();
        $rawContent = app(EditorContentService::class)->processForSave($rawContent, $editorMode);
        $rawMobile  = app(EditorContentService::class)->processForSave($rawMobile, $editorMode);

        $htmlName = $this->sanitizeHtmlName((string) ($data['html_name'] ?? ''));
        if ($htmlName !== '') {
            $dupQuery = Document::where('html_name', $htmlName)->whereNull('deleted_at');
            if ($id > 0) {
                $dupQuery->where('id', '<>', $id);
            }
            if ($dupQuery->find()) {
                return ServiceResult::fail('自定义文件名已被占用，请更换');
            }
        }

        $urlPath = app(UrlPathService::class)->normalize((string) ($data['url_path'] ?? ''));
        if ($urlPath !== '') {
            $pathErr = app(UrlPathService::class)->validateAvailable($urlPath, 'document', $id);
            if ($pathErr !== '') {
                return ServiceResult::fail($pathErr);
            }
        }

        $publishedAt = trim((string) ($data['published_at'] ?? ''));
        if ($publishedAt !== '' && strtotime($publishedAt) !== false) {
            $publishedAt = AppTime::format('Y-m-d H:i:s', (int) strtotime($publishedAt));
        } else {
            $publishedAt = null;
        }

        $schedulePublish = $this->optionalDatetimeFromPost($data['schedule_publish_at'] ?? '');
        $scheduleOffline = $this->optionalDatetimeFromPost($data['schedule_offline_at'] ?? '');

        $contentClean = app(MediaUrlService::class)->rewriteText(HtmlSanitizer::cleanArticle($rawContent));
        $mobileClean  = app(MediaUrlService::class)->rewriteText(HtmlSanitizer::cleanArticle($rawMobile));
        // 封面只认提交的 litpic / 产品图；禁保存时静默抽正文（后台有「正文首图」按钮）
        $litpic = app(MediaUrlService::class)->formatForStorage(
            HtmlSanitizer::cleanUrl((string) ($data['litpic'] ?? ''))
        );
        $attrFlags = app(DocumentAttrHelper::class)->mergeAttrHasImage(app(DocumentAttrHelper::class)->attrFlagsFromPost($data), $litpic);
        $extUrl    = HtmlSanitizer::cleanUrl((string) ($data['external_url'] ?? ''));
        if (app(DocumentAttrHelper::class)->hasAttrFlag($attrFlags, 'external') && $extUrl === '') {
            return ServiceResult::fail('勾选外链时请填写有效的外链地址（http/https）');
        }
        $extNewTab = !empty($data['external_open_new_tab']) ? 1 : 0;
        if (!app(DocumentAttrHelper::class)->hasAttrFlag($attrFlags, 'external')) {
            $extUrl    = '';
            $extNewTab = 0;
        }

        $readAccess = app(MemberLevelService::class)->readAccessFromPost($data);

        $specialChars = app(\app\common\service\content\EditorSpecialCharsService::class);
        $saveData = [
            'title'                 => $specialChars->filterForSave(trim((string) $data['title'])),
            'subtitle'              => $specialChars->filterForSave(HtmlSanitizer::cleanPlainText((string) ($data['subtitle'] ?? ''), 200)),
            'content'               => $contentClean,
            'content_mobile'        => $mobileClean,
            'summary'               => $specialChars->filterForSave(HtmlSanitizer::cleanPlainText((string) ($data['summary'] ?? ''), 500)),
            'source'                => HtmlSanitizer::cleanPlainText((string) ($data['source'] ?? ''), 100),
            'extra_json'            => app(TagCore::class)->encodeExtraFields($data['extra_fields'] ?? $data['extra_json'] ?? null),
            'attr_flags'            => $attrFlags,
            'external_url'          => $extUrl,
            'external_open_new_tab' => $extNewTab,
            'read_perm'             => $readAccess['read_perm'],
            'read_level_id'         => $readAccess['read_level_id'],
            'tpl_name'              => $this->sanitizeTplName((string) ($data['tpl_name'] ?? '')),
            'html_name'             => $htmlName,
            'url_path'              => $urlPath,
            'seo_title'             => $specialChars->filterForSave(HtmlSanitizer::cleanPlainText((string) ($data['seo_title'] ?? ''), 200)),
            'seo_keywords'          => $specialChars->filterForSave(HtmlSanitizer::cleanPlainText((string) ($data['seo_keywords'] ?? ''), 500)),
            'seo_description'       => $specialChars->filterForSave(HtmlSanitizer::cleanPlainText((string) ($data['seo_description'] ?? ''), 500)),
            'litpic'                => $litpic,
            'click'                 => max(0, (int) ($data['click'] ?? 0)),
            'author_name'           => HtmlSanitizer::cleanPlainText(
                app(DocumentPresetService::class)->normalizeAuthorName((string) ($data['author_name'] ?? '')),
                50
            ),
            'nav_id'                => $navId,
            'status'                => $status,
            'schedule_publish_at'   => $schedulePublish,
            'schedule_offline_at'   => $scheduleOffline,
            'updated_at'            => AppTime::now(),
        ];
        if ($publishedAt !== null) {
            $saveData['published_at'] = $publishedAt;
        }

        $derived = app(SeoExcerpt::class)->deriveForDocument([
            'title'    => $saveData['title'],
            'subtitle' => $saveData['subtitle'],
            'content'  => $saveData['content'],
            'tags'     => $tagStr !== '' ? explode(',', $tagStr) : [],
        ]);
        if ($saveData['summary'] === '' && $derived['summary'] !== '') {
            $saveData['summary'] = HtmlSanitizer::cleanPlainText($derived['summary'], 500);
        }
        if ($saveData['seo_description'] === '' && $derived['seo_description'] !== '') {
            $saveData['seo_description'] = HtmlSanitizer::cleanPlainText($derived['seo_description'], 500);
        }
        if ($saveData['seo_keywords'] === '' && $derived['seo_keywords'] !== '') {
            $saveData['seo_keywords'] = HtmlSanitizer::cleanPlainText($derived['seo_keywords'], 500);
        }
        $saveData['search_text'] = app(SearchTextExtractor::class)->forDocumentSave($saveData);

        return ServiceResult::ok([
            'id'          => $id,
            'tagStr'      => $tagStr,
            'saveData'    => $saveData,
            'extraNavIds' => $extraNavIds,
        ]);
    }

    /**
     * 落库后的审计、缓存、静态化、SEO、搜索与品项同步。
     *
     * @param array<string, mixed> $saveData
     * @param array<string, mixed> $postData 原始 POST（含 item_ids）
     * @param array<string, mixed>|null $before 更新前快照；null 表示新建
     */
    public function afterSaveAdminPersist(
        int $documentId,
        array $saveData,
        array $postData,
        ?array $before,
        string $auditLabel
    ): string {
        $extensionWarn = '';
        $hadProductVariantsJson = array_key_exists('product_variants_json', $postData);
        $postData               = $this->normalizeProductPostData($postData);
        app(AuditLogService::class)->operate($auditLabel, 'admin.document', [
            'document_id' => $documentId,
            'title'       => (string) ($saveData['title'] ?? ''),
        ]);
        app(SiteModeService::class)->clearPageCache();
        $scene = $before === null ? 'publish' : $this->staticSyncScene($before, $saveData);
        app(StaticHtmlDispatch::class)->afterArticleChange($documentId, $scene);
        app(SitemapService::class)->syncAfterContentChange($documentId, $scene);
        app(DocumentAssetSyncService::class)->syncAfterDocumentSave($documentId, $saveData);
        app(SearchIndexService::class)->syncDocumentById($documentId);
        // 产品多图真源；列表封面只读 documents.litpic
        if (array_key_exists('product_images', $postData) || array_key_exists('product_images_json', $postData)) {
            $rawImages = $postData['product_images'] ?? null;
            if (!is_array($rawImages) && isset($postData['product_images_json'])) {
                $decoded = json_decode((string) $postData['product_images_json'], true);
                $rawImages = is_array($decoded) ? $decoded : [];
            }
            if (is_array($rawImages)) {
                app(DocumentProductImageService::class)->replaceForDocument(
                    $documentId,
                    $rawImages,
                    (string) ($saveData['litpic'] ?? '')
                );
                $freshLitpic = trim((string) (Document::where('id', $documentId)->value('litpic') ?: ''));
                if ($freshLitpic !== '') {
                    $saveData['litpic'] = $freshLitpic;
                }
            }
        } elseif (trim((string) ($saveData['litpic'] ?? '')) !== '') {
            // 仅改 litpic：保证产品多图至少有封面行
            $imgSvc = app(DocumentProductImageService::class);
            if ($imgSvc->listForDocument($documentId) === []) {
                $imgSvc->replaceForDocument($documentId, [], (string) $saveData['litpic']);
            }
        }
        $navIdForItems = max(0, (int) ($saveData['nav_id'] ?? 0));
        if ($navIdForItems > 0) {
            app(ItemService::class)->syncPrimaryItemNavFromDocument($documentId, $navIdForItems);
        }
        $productTabSave = array_key_exists('product_item_type', $postData)
            || array_key_exists('product_item_attrs', $postData)
            || array_key_exists('product_item_attrs_json', $postData)
            || array_key_exists('product_layout_mode', $postData)
            || array_key_exists('product_variants', $postData)
            || array_key_exists('product_variants_json', $postData)
            || array_key_exists('product_models', $postData)
            || array_key_exists('product_models_json', $postData)
            || array_key_exists('product_accessory_item_ids', $postData)
            || array_key_exists('product_related_document_ids', $postData)
            || array_key_exists('product_accessory_section_label', $postData)
            || app(ProductTabPersistRegistry::class)->postDataHasRegisteredKeys($postData)
            || (int) ($postData['product_item_id'] ?? 0) > 0;
        if (!$productTabSave && array_key_exists('item_ids', $postData)) {
            $itemIds = is_array($postData['item_ids']) ? $postData['item_ids'] : [];
            app(ItemService::class)->syncDocumentRefs($documentId, $itemIds);
        }
        if (array_key_exists('param_group_id', $postData) || array_key_exists('param_group_ids', $postData)) {
            $groupIds = [];
            if (array_key_exists('param_group_id', $postData)) {
                $gid = (int) ($postData['param_group_id'] ?? 0);
                if ($gid > 0) {
                    $groupIds = [$gid];
                }
            } else {
                $raw = is_array($postData['param_group_ids']) ? $postData['param_group_ids'] : [];
                $first = (int) ($raw[0] ?? 0);
                if ($first > 0) {
                    $groupIds = [$first];
                }
            }
            $productBridge = app(\app\common\service\product\DocumentProductFacade::class);
            if ($productBridge->enabled()) {
                $productBridge->syncDocumentParamGroupRefs($documentId, $groupIds);
            }
        }
        if (array_key_exists('product_layout_mode', $postData)) {
            $productBridge = app(\app\common\service\product\DocumentProductFacade::class);
            if ($productBridge->enabled()) {
                $layoutMode = trim((string) ($postData['product_layout_mode'] ?? 'single'));
                $accessoryLabel = (string) ($postData['product_accessory_section_label'] ?? '零配件');
                $productBridge->syncDocumentProductSettings($documentId, $layoutMode, $accessoryLabel);
            }
        }
        if ($productTabSave) {
            $layoutMode = trim((string) ($postData['product_layout_mode'] ?? 'single'));
            $itemType   = (string) ($postData['product_item_type'] ?? ItemService::TYPE_PHYSICAL);
            if (!in_array($itemType, [ItemService::TYPE_PHYSICAL, ItemService::TYPE_DIGITAL], true)) {
                $itemType = ItemService::TYPE_PHYSICAL;
            }
            $docTitle   = (string) ($saveData['title'] ?? '');
            // multi_model 已退役：旧请求降级为单型号保存
            if ($layoutMode === 'multi_model') {
                $layoutMode = 'single';
            }
            if ($layoutMode === 'multi_spec') {
                if ($hadProductVariantsJson) {
                    $productResult = app(ItemService::class)->persistProductMultiSpecForDocument(
                        $documentId,
                        (int) ($postData['product_item_id'] ?? 0),
                        $itemType,
                        is_array($postData['product_variants'] ?? null) ? $postData['product_variants'] : [],
                        $docTitle,
                        $postData,
                    );
                    if (!$productResult->isOk()) {
                        $extensionWarn = '文档已保存，但产品扩展同步失败：' . $productResult->message();
                    }
                }
            } else {
                $productResult = app(ItemService::class)->persistProductTabForDocument(
                    $documentId,
                    (int) ($postData['product_item_id'] ?? 0),
                    $itemType,
                    is_array($postData['product_item_attrs'] ?? null) ? $postData['product_item_attrs'] : [],
                    $docTitle,
                    (string) ($postData['product_item_name'] ?? ''),
                    (string) ($postData['product_item_code'] ?? ''),
                    $postData,
                );
                if (!$productResult->isOk()) {
                    $extensionWarn = '文档已保存，但产品扩展同步失败：' . $productResult->message();
                }
            }

            $primaryRow = app(ItemService::class)->primaryItemRowForDocument($documentId);
            $parentItemId = is_array($primaryRow) ? (int) ($primaryRow['id'] ?? 0) : 0;
            if ($parentItemId > 0
                && array_key_exists('product_accessory_item_ids', $postData)
                && class_exists(\app\common\service\product\ProductItemRelationService::class)) {
                $accessoryIds = is_array($postData['product_accessory_item_ids'])
                    ? $postData['product_accessory_item_ids']
                    : [];
                $rows = [];
                foreach ($accessoryIds as $rawId) {
                    $childId = (int) $rawId;
                    if ($childId > 0 && $childId !== $parentItemId) {
                        $rows[] = ['child_item_id' => $childId];
                    }
                }
                \app\common\service\product\ProductItemRelationService::syncForParent($parentItemId, $rows);
            }

            if (array_key_exists('product_related_document_ids', $postData)
                && class_exists(\app\common\service\product\DocumentRelatedRefService::class)) {
                $relatedIds = is_array($postData['product_related_document_ids'])
                    ? $postData['product_related_document_ids']
                    : [];
                \app\common\service\product\DocumentRelatedRefService::syncForDocument(
                    $documentId,
                    array_map('intval', $relatedIds),
                );
            }
        }

        return $extensionWarn;
    }

    /**
     * 更新已有文档：事务 + 落库后同步。
     *
     * @param array<string, mixed> $saveData
     * @param array<string, mixed> $postData
     */
    /**
     * @param list<int> $extraNavIds
     */
    public function executeSaveAdminUpdate(
        int $id,
        array $saveData,
        string $tagStr,
        string $attrFlags,
        array $postData,
        array $extraNavIds = []
    ): ServiceResult {
        $cur = Document::where('id', $id)->whereNull('deleted_at')->find()?->toArray();
        if (!$cur) {
            return ServiceResult::fail('文档不存在或已删除');
        }
        if ((int) $saveData['status'] === 1 && empty($cur['published_at']) && empty($saveData['published_at'])) {
            $saveData['published_at'] = AppTime::now();
        }

        try {
            Db::transaction(function () use ($id, $saveData, $tagStr, $attrFlags, $extraNavIds) {
                Document::where('id', $id)->whereNull('deleted_at')->update($saveData);
                app(TagService::class)->syncDocumentTags($id, $tagStr);
                app(DocumentAttrFlagIndexService::class)->sync($id, $attrFlags);
                app(SiteNavService::class)->replaceDocumentExtraNavs($id, $extraNavIds);
            });
        } catch (\Throwable $e) {
            OpsLog::businessWarning('document_save_admin_update_failed', [
                'document_id' => $id,
                'msg'         => $e->getMessage(),
            ]);

            return ServiceResult::fail('更新失败');
        }

        $extensionWarn = $this->afterSaveAdminPersist($id, $saveData, $postData, $cur, '更新文档');
        $successMsg    = '更新成功';
        if ($extensionWarn !== '') {
            $successMsg = $extensionWarn;
        }

        return ServiceResult::ok(['id' => $id], $this->appendEditorProcessHint($successMsg));
    }

    /**
     * 新建文档：补 create 字段 + 事务 + 落库后同步。
     *
     * @param array<string, mixed> $saveData
     * @param array<string, mixed> $postData
     */
    /**
     * @param list<int> $extraNavIds
     */
    public function executeSaveAdminCreate(
        array $saveData,
        string $tagStr,
        string $attrFlags,
        int $authorId,
        array $postData,
        array $extraNavIds = []
    ): ServiceResult {
        $saveData['created_at'] = AppTime::now();
        $saveData['author_id']  = $authorId;
        if (empty($saveData['published_at'])) {
            $saveData['published_at'] = (int) $saveData['status'] === 1 ? $saveData['created_at'] : null;
        }
        if ((int) ($saveData['click'] ?? 0) < 1) {
            $saveData['click'] = self::defaultClickFromConfig();
        }

        try {
            $newId = 0;
            Db::transaction(function () use ($saveData, $tagStr, $attrFlags, $extraNavIds, &$newId) {
                $newId = (int) Document::insertGetId($saveData);
                if ($newId > 0) {
                    app(TagService::class)->syncDocumentTags($newId, $tagStr);
                    app(DocumentAttrFlagIndexService::class)->sync($newId, $attrFlags);
                    app(SiteNavService::class)->replaceDocumentExtraNavs($newId, $extraNavIds);
                }
            });
        } catch (\Throwable $e) {
            OpsLog::businessWarning('document_save_admin_create_failed', [
                'msg'   => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
                'class' => $e::class,
            ]);

            return ServiceResult::fail('发布失败：' . $e->getMessage());
        }

        if ($newId < 1) {
            return ServiceResult::fail('发布失败');
        }

        $extensionWarn = $this->afterSaveAdminPersist($newId, $saveData, $postData, null, '发布文档');
        $successMsg    = '发布成功';
        if ($extensionWarn !== '') {
            $successMsg = $extensionWarn;
        }

        return ServiceResult::ok(['id' => $newId], $this->appendEditorProcessHint($successMsg));
    }

    public function optionalDatetimeFromPost(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '' || strtotime($s) === false) {
            return null;
        }

        return AppTime::format('Y-m-d H:i:s', (int) strtotime($s));
    }

    public function sanitizeTplName(string $name): string
    {
        $name = basename(str_replace(['\\', "\0"], '', trim($name)));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $name)) {
            return ThemeTemplateCatalogService::TPL_VIEW_DOCUMENT . '.php';
        }

        return app(ThemeTemplateCatalogService::class)->toCanonicalFilename($name) ?: ThemeTemplateCatalogService::TPL_VIEW_DOCUMENT . '.php';
    }

    public function sanitizeHtmlName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '';

        return substr($name, 0, 120);
    }

    /** @param array<string, mixed> $after */
    public function staticSyncScene(?array $before, array $after): string
    {
        if ($before === null) {
            return 'publish';
        }
        $wasPublished = (int) ($before['status'] ?? 0) === 1;
        $nowPublished = (int) ($after['status'] ?? $before['status'] ?? 0) === 1;
        if (!$wasPublished && $nowPublished) {
            return 'publish';
        }

        return 'edit';
    }

    public function appendEditorProcessHint(string $msg): string
    {
        if (!app(EditorContentService::class)->remoteLocalEnabled()) {
            return $msg;
        }
        $r = app(EditorContentService::class)->lastProcessReport();
        if ($r['images_attempted'] < 1) {
            return $msg;
        }
        $hint = '（远程图：' . $r['images_localized'] . ' 张已保存到本站';
        if ($r['images_kept_remote'] > 0) {
            $hint .= '，' . $r['images_kept_remote'] . ' 张仍为外链（对方站点可能禁止抓取）';
        }

        return $msg . $hint . '）';
    }

    /**
     * 文档保存 POST：product_variants 等须走 *_json（前端 URLSearchParams 无法传对象数组）。
     *
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private function normalizeProductPostData(array $postData): array
    {
        $jsonAliases = [
            'product_variants'   => 'product_variants_json',
            'product_models'     => 'product_models_json',
            'product_item_attrs' => 'product_item_attrs_json',
        ];
        $hostAliases = \app\common\service\plugin\extension\PluginOfficialProduct::dispatch(
            'document_product_post_json_aliases',
            [],
            [],
        );
        if (is_array($hostAliases)) {
            foreach ($hostAliases as $key => $jsonKey) {
                $key = trim((string) $key);
                $jsonKey = trim((string) $jsonKey);
                if ($key !== '' && $jsonKey !== '') {
                    $jsonAliases[$key] = $jsonKey;
                }
            }
        }
        foreach ($jsonAliases as $key => $jsonKey) {
            if (!array_key_exists($jsonKey, $postData)) {
                continue;
            }
            $raw = $postData[$jsonKey];
            unset($postData[$jsonKey]);
            if (is_string($raw) && trim($raw) !== '') {
                $parsed = json_decode($raw, true);
                $postData[$key] = is_array($parsed) ? $parsed : [];
            } elseif (!array_key_exists($key, $postData)) {
                $postData[$key] = [];
            }
        }

        return $postData;
    }

    public static function defaultClickFromConfig(): int
    {
        $raw   = (string) app(ConfigService::class)->get('doc_default_hits', '500|1000');
        $parts = array_map('intval', array_filter(explode('|', $raw), static fn ($v) => $v !== ''));
        if (count($parts) >= 2) {
            $min = min($parts[0], $parts[1]);
            $max = max($parts[0], $parts[1]);
            if ($min > 0 && $max >= $min) {
                return random_int($min, $max);
            }
        }
        $one = (int) ($parts[0] ?? 0);

        return $one > 0 ? $one : 500;
    }
}
