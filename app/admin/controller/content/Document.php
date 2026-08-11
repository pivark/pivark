<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);


namespace app\admin\controller\content;


use app\common\service\auth\CsrfService;
use app\common\service\export\AdminDataExportSupport;
use app\common\service\export\AdminDataImportSupport;
use app\common\service\export\ExportImportService;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\support\AdminApiResponse;
use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\document\satellite\DocumentBlockService;
use app\common\service\document\satellite\DocumentAssetSyncService;
use app\common\service\document\satellite\DocumentPresetService;
use app\common\service\ContentBulkReplaceService;
use app\common\service\document\satellite\DocumentQrService;
use app\common\service\document\DocumentAdminInternals;
use app\common\service\document\DocumentAdminService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberLevelService;
use app\common\service\plugin\extension\PluginDocumentEditorService;
use app\common\service\plugin\extension\PluginDocumentSaveService;
use app\common\service\plugin\extension\PluginEditorSurfaceService;
use app\common\service\search\DocumentSearchTextBackfillService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\config\ConfigService;
use app\common\service\content\ContentEditorService;
use app\common\service\seo\SeoExcerpt;
use app\common\service\tag\TagService;
use app\common\service\upload\UploadService;
use app\common\support\SiteUrl;
use app\common\support\AdminBatchSupport;
use app\common\support\ParseIds;
use app\common\support\UploadGate;
use app\common\support\UploadGateException;
use think\facade\Request;
use think\facade\Session;
use think\Response;

class Document extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly DocumentAdminService $document,
        private readonly TagService $tag,
        private readonly DocumentSearchTextBackfillService $documentSearchTextBackfill,
        private readonly PluginEditorSurfaceService $pluginEditorSurface,
        private readonly PluginDocumentSaveService $pluginDocumentSave,
        private readonly SeoExcerpt $seoExcerpt,
        private readonly ExportImportService $exportImport,
        private readonly AdminDataExportSupport $adminDataExport,
        private readonly AdminDataImportSupport $adminDataImport,
        private readonly DocumentQrService $documentQr,
        private readonly ContentBulkReplaceService $contentBulkReplace,
        private readonly DocumentBlockService $documentBlock,
        private readonly ContentEditorService $contentEditor,
        private readonly ThemeTemplateCatalogService $themeTemplateCatalog,
        private readonly ConfigService $config,
        private readonly DocumentPresetService $documentPreset,
        private readonly MemberLevelService $memberLevel,
        private readonly MemberConfigService $memberConfig,
        private readonly PluginExtensionRegistry $pluginExtensionRegistry,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $result = $this->document->listAdmin(Request::get());
            $nextCursorId = (int) ($result['next_cursor_id'] ?? 0);
            $hasMore = (int) ($result['has_more'] ?? ($nextCursorId > 0 ? 1 : 0));

            return AdminApiResponse::list(['total'          => $result['total'],
                'list'           => $result['list'],
                'next_cursor_id' => $nextCursorId,
                'has_more'       => $hasMore]);
        }

        $tagMap = $this->tagOptions();
        return $this->renderView('document/index', ['tags' => array_values($tagMap)]);
    }

    public function create()
    {
        return $this->renderView('document/form', $this->documentFormVars());
    }

    public function edit()
    {
        $id = (int) Request::get('id');
        if ($id < 1) {
            return $this->legacyDocumentEditFail('参数错误');
        }
        $article = $this->document->findForAdmin($id);
        if (!$article) {
            return $this->legacyDocumentEditFail('文档不存在');
        }
        $article['tags']     = $this->tag->tagNamesCsvForDocument($id);
        $article['tag_list'] = $article['tags'] !== '' ? explode(',', $article['tags']) : [];

        return $this->renderView('document/form', $this->documentFormVars($article));
    }

    public function searchTextPreview()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->documentSearchTextBackfill->previewForAdmin((int) Request::get('id', 0)));
    }

    public function saveEditorSurfaceOrder()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $slot = (string) Request::post('slot', '');
        $raw  = Request::post('order', '');
        $list = [];
        if (is_array($raw)) {
            $list = $this->pluginEditorSurface->normalizeOrderList($raw);
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $list      = is_array($decoded) ? $this->pluginEditorSurface->normalizeOrderList($decoded) : [];
        }

        return AdminApiResponse::admin($this->pluginEditorSurface->saveSiteOrderList($slot, $list));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $adminUser = Session::get('admin_user') ?? [];
        $data      = Request::post();
        $data['id'] = (int) ($data['id'] ?? 0);
        /** @var ServiceResult $res */
        $res = $this->document->saveAdmin($data, (int) ($adminUser['id'] ?? 0));
        if ($res->isOk()) {
            $savedId = (int) ($res->dataArray()['id'] ?? $data['id'] ?? 0);
            if ($savedId > 0) {
                $pluginSync = $this->pluginDocumentSave->syncAfterSave($savedId, false, $data);
                if (!$pluginSync->isOk()) {
                    return AdminApiResponse::admin(ServiceResult::fail((string) $pluginSync->message()));
                }
            }
            $payload = $res->dataArray();
            $payload['url'] = '/admin/document/index';

            return AdminApiResponse::admin(ServiceResult::ok($payload, $res->message()));
        }

        return AdminApiResponse::admin($res);
    }

    public function deriveSeo()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $tagsCsv = $this->tag->normalizeTagsCsv((string) Request::post('tags', ''));
        $derived = $this->seoExcerpt->deriveForDocument([
            'title'    => (string) Request::post('title', ''),
            'subtitle' => (string) Request::post('subtitle', ''),
            'content'  => (string) Request::post('content', ''),
            'tags'     => $tagsCsv !== '' ? explode(',', $tagsCsv) : [],
        ]);

        return AdminApiResponse::admin(ServiceResult::ok($derived));
    }

    public function status()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->updateStatusAdmin(
            (int) Request::post('id', 0),
            (int) Request::post('status', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $ids = ParseIds::fromMixed(Request::post('id', Request::post('ids', '')));
        if (count($ids) > 1) {
            return AdminApiResponse::admin($this->document->batchDeleteAdmin($ids));
        }
        return AdminApiResponse::admin($this->document->deleteAdmin($ids[0] ?? 0));
    }

    public function batchDelete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        return AdminApiResponse::admin($this->document->batchDeleteAdmin(AdminBatchSupport::parsePostIds()));
    }

    public function batchStatus()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $status = (int) Request::post('status', 1) === 1 ? 1 : 0;
        return AdminApiResponse::admin($this->document->batchSetStatusAdmin(AdminBatchSupport::parsePostIds(), $status));
    }

    public function batchTags()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->batchSetTagsAdmin(
            AdminBatchSupport::parsePostIds(),
            (string) Request::post('tags', ''),
            (string) Request::post('mode', 'replace')
        ));
    }

    public function batchSeo()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->batchSetSeoAdmin(
            AdminBatchSupport::parsePostIds(),
            (string) Request::post('seo_title', ''),
            (string) Request::post('seo_keywords', ''),
            (string) Request::post('seo_description', ''),
            (string) Request::post('title_mode', 'replace'),
        ));
    }

    public function batchAttr()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $flags = Request::post('flags/a', []);
        if (!is_array($flags) || $flags === []) {
            $single = trim((string) Request::post('flag', ''));
            $flags  = $single !== '' ? [$single] : [];
        }

        return AdminApiResponse::admin($this->document->batchSetAttrAdmin(
            AdminBatchSupport::parsePostIds(),
            $flags,
            (string) Request::post('mode', 'add')
        ));
    }

    public function restore()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->batchRestoreAdmin(AdminBatchSupport::parsePostIds()));
    }

    public function purgeRecycle()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->purgeRecycleAdmin(AdminBatchSupport::parsePostIds()));
    }

    public function emptyRecycle()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->document->emptyRecycleBinAdmin());
    }

    public function export()
    {
        $pack = $this->exportImport->exportArticlesCsv();

        return $this->adminDataExport->respondPack(
            $pack,
            'text/csv; charset=UTF-8',
            'admin.document.export',
            ['export_scope' => 'all'],
            '导出 CSV',
            'document.list',
        );
    }

    public function exportJson()
    {
        $pack = $this->exportImport->exportDocumentsJson();

        return $this->adminDataExport->respondPack(
            $pack,
            'application/json; charset=UTF-8',
            'admin.document.export',
            ['export_scope' => 'all', 'format' => 'json'],
            '导出 JSON',
            'document.list_json',
        );
    }

    public function import()
    {
        $support = $this->adminDataImport;
        $text = $support->readUpload('file');
        if ($text instanceof Response) {
            return $text;
        }
        $blocked = $support->assertImportAllowed('document.import', $support->estimateCsvRowCount($text));
        if ($blocked !== null) {
            return $blocked;
        }
        $adminUser = Session::get('admin_user') ?? [];
        $result = $this->exportImport->importArticlesCsv(
            $text,
            (int) ($adminUser['id'] ?? 0)
        );
        if ($result->isOk()) {
            $support->auditImport('admin.document.import', ['profile' => 'document.import'], '导入 CSV');
        }

        return AdminApiResponse::admin($result);
    }

    public function importJson()
    {
        $support = $this->adminDataImport;
        $text = $support->readUpload('file');
        if ($text instanceof Response) {
            return $text;
        }
        $decoded = json_decode($text, true);
        $rowCount = is_array($decoded) ? count($decoded) : 0;
        $blocked = $support->assertImportAllowed('document.import_json', $rowCount);
        if ($blocked !== null) {
            return $blocked;
        }
        $adminUser = Session::get('admin_user') ?? [];
        $result = $this->exportImport->importDocumentsJson(
            $text,
            (int) ($adminUser['id'] ?? 0)
        );
        if ($result->isOk()) {
            $support->auditImport('admin.document.import', ['profile' => 'document.import_json'], '导入 JSON');
        }

        return AdminApiResponse::admin($result);
    }

    public function getTags()
    {
        $keyword = Request::get('keyword', '');
        $tags    = $this->tag->listAllActive();
        if ($keyword !== '') {
            $tags = array_values(array_filter($tags, static function ($tag) use ($keyword) {
                return stripos((string) $tag['name'], $keyword) !== false;
            }));
        }
        return AdminApiResponse::fromResult(ServiceResult::ok($tags));
    }

    public function upload()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        try {
            UploadGate::assertUpload('document');
        } catch (UploadGateException $e) {
            return ($e->getHttpCode() === 401 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }

        $meta = [
            'content_hash' => (string) Request::post('content_hash', ''),
            'file_size'    => (int) Request::post('file_size', 0),
            'force_upload' => in_array(Request::post('force_upload'), ['1', 'true', true], true),
        ];

        return AdminApiResponse::admin(UploadService::scene('document')->handle(Request::file('file'), $meta));
    }

    private function tagOptions(): array
    {
        $result = [];
        foreach ($this->tag->listAllActive() as $tag) {
            $result[$tag['name']] = $tag;
        }
        return $result;
    }

    public function qrcode(): Response
    {
        $id = (int) Request::get('id', 0);

        return AdminApiResponse::admin($this->documentQr->infoForAdmin($id));
    }

    public function bulkReplacePreview(): Response
    {
        $opts = $this->contentBulkReplace->optionsFromRequest(Request::post());
        $previewOpts = array_merge($opts['options'], [
            'preview_page'      => (int) Request::post('preview_page', 1),
            'preview_page_size' => (int) Request::post('preview_page_size', 50),
        ]);

        return AdminApiResponse::admin($this->contentBulkReplace->preview(
            $opts['filter'],
            $opts['rules'],
            $opts['fields'],
            $previewOpts
        ));
    }

    public function bulkReplace(): Response
    {
        $opts = $this->contentBulkReplace->optionsFromRequest(Request::post());

        return AdminApiResponse::admin($this->contentBulkReplace->execute(
            $opts['filter'],
            $opts['rules'],
            $opts['fields'],
            $opts['options'],
            $opts['batch_page']
        ));
    }

    public function blockMeta(): Response
    {
        return AdminApiResponse::fromResult(
            ServiceResult::ok($this->documentBlock->metaForAdmin())
        );
    }

    public function blockPreview(): Response
    {
        $params = Request::post();
        if (!is_array($params)) {
            $params = [];
        }

        return AdminApiResponse::fromResult(
            $this->documentBlock->previewForAdmin($params)
        );
    }

    private function documentFormVars(?array $article = null): array
    {
        $admin     = Session::get('admin_user') ?? [];
        $templates = $this->themeTemplateCatalog->listFiles(ThemeTemplateCatalogService::SCOPE_DOCUMENT);
        $tplDefault = (string) ($article['tpl_name'] ?? $templates[0] ?? 'view_document.php');
        if (!in_array($tplDefault, $templates, true)) {
            $tplDefault = $templates[0] ?? 'view_document.php';
        }

        $articleId = (int) ($article['id'] ?? 0);

        return [
            'tags'                    => $this->tagOptions(),
            'document'                 => $article,
            'isEdit'                  => $article !== null,
            'contentEditor'           => $this->contentEditor->current(),
            'articleTemplates'        => $templates,
            'articleTemplateOptions'  => $this->themeTemplateCatalog->listOptions(ThemeTemplateCatalogService::SCOPE_DOCUMENT),
            'documentAddonPrefill'    => $this->documentAddonPrefillForLegacyForm($articleId),
            'articleId'               => $articleId,
            'editorRemoteLocal'   => (string) $this->config->get('editor_remote_local', '1'),
            'editorClearExternal' => (string) $this->config->get('editor_clear_external', '1'),
            'editorSpecialChars'  => (string) $this->config->get('editor_special_chars', '0'),
            'authorPresets'       => $this->documentPreset->authorPresets($admin),
            'sourcePresets'       => $this->documentPreset->sourcePresets(),
            'formDefaults'        => [
                'author_name'  => (string) ($article['author_name'] ?? $this->documentPreset->defaultAuthorName($admin)),
                'source'       => (string) ($article['source'] ?? $this->documentPreset->defaultSource()),
                'click'        => (int) ($article['click'] ?? DocumentAdminInternals::defaultClickFromConfig()),
                'published_at' => (string) ($article['published_at'] ?? AppTime::now()),
                'tpl_name'     => $tplDefault,
                'read_access'  => $this->memberLevel->encodeReadAccess(
                    (int) ($article['read_perm'] ?? 0),
                    (int) ($article['read_level_id'] ?? 0)
                ),
            ],
            'memberLevels'          => $this->memberLevel->listActive(),
            'memberPointsEnabled'   => $this->memberConfig->isPointsEnabled(),
        ];
    }

    /** @return array<string, array{enabled:bool, editorSlot:string, prefill:list<array<string,mixed>>}> */
    private function documentAddonPrefillForLegacyForm(int $articleId): array
    {
        $out = [];
        foreach ($this->pluginExtensionRegistry->documentAddonBridgeIdentifiers() as $identifier) {
            if (!DocumentAddonBridgeAccess::isEnabled($identifier)) {
                continue;
            }
            if (!$this->pluginEditorSurface->isEnabledInEditor($identifier)) {
                continue;
            }
            $prefill = [];
            if ($articleId > 0) {
                $rows = DocumentAddonBridgeAccess::invokeOr([], $identifier, 'listForDocument', [$articleId, true]);
                $prefill = is_array($rows) ? $rows : [];
            }
            $out[$identifier] = [
                'enabled'    => true,
                'editorSlot' => $this->pluginEditorSurface->resolveSlot($identifier),
                'prefill'    => $prefill,
            ];
        }

        return $out;
    }

    /** 旧版 document/form 直链失败时统一重定向 SPA，仅 Ajax 保留 JSON */
    private function legacyDocumentEditFail(string $msg)
    {
        if (Request::isAjax()) {
            return AdminApiResponse::fail($msg);
        }

        return redirect(SiteUrl::adminSpa('/content/document'));
    }

    /** GET — 剪贴板链接洞察（REST · 原 Spa::clipboardUrlInsight） */
    public function clipboardUrlInsight()
    {
        /** @var \app\common\service\admin\AdminSpaMetaService $spaMeta */
        $spaMeta = \app\common\support\AppService::make(\app\common\service\admin\AdminSpaMetaService::class);

        return AdminApiResponse::admin($spaMeta->spaClipboardUrlInsight(
            trim((string) Request::get('url', '')),
            trim((string) Request::get('raw', ''))
        ));
    }
}
