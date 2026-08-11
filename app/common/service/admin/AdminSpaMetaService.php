<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;


use app\common\support\MoneyMath;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\model\Document;
use app\common\model\Role;
use app\common\model\User;
use app\common\service\admin\login_notice\AdminLoginNoticeConfigService;
use app\common\service\ai\AiConfigAdminService;
use app\common\service\auth\CaptchaConfigService;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\channel\MiniprogramPageConfigService;
use app\common\service\config\ConfigService;
use app\common\service\content\ClipboardUrlInsightService;
use app\common\service\content\ImportIntentResolveService;
use app\common\service\content\ContentEditorService;
use app\common\service\document\DocumentAdminInternals;
use app\common\service\document\DocumentAdminService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\document\satellite\DocumentPresetService;
use app\common\service\cron\CronService;
use app\common\service\event\DomainEventDispatchAdminService;
use app\common\service\license\LicenseActivateService;
use app\common\service\license\LicenseHeartbeatService;
use app\common\service\license\LicenseSyncService;
use app\common\service\infra\CacheConfigService;
use app\common\service\item\ItemService;
use app\common\service\mail\MailConfigService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberCancelService;
use app\common\service\member\MemberFieldService;
use app\common\service\member\MemberLevelService;
use app\common\service\member\MemberRechargeService;
use app\common\service\member\MemberService;
use app\common\service\member\MemberUxService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\PaymentOrderService;
use app\common\service\plugin\extension\PluginDocumentEditorService;
use app\common\service\plugin\extension\PluginEditorSurfaceService;
use app\common\service\plugin\package\PluginPackageService;
use app\common\service\plugin\scaffold\PluginReservedIdentifierService;
use app\common\service\plugin\scaffold\PluginScaffoldService;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\search\SearchConfigService;
use app\common\service\sms\SmsConfigService;
use app\common\service\search\SearchIndexService;
use app\common\service\search\SmartSearchAdminService;
use app\common\service\seo\RobotsService;
use app\common\service\seo\SeoConfigService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\seo\SitemapService;
use app\common\service\site\FloatContactConfigService;
use app\common\service\site\FloatContactService;
use app\common\service\site\SiteBrandService;
use app\common\service\site\SiteKeyService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteNavService;
use app\common\service\site\SitePageService;
use app\common\service\site\SiteUrlRewriteGuideService;
use app\common\service\static\StaticHtmlBatchService;
use app\common\service\static\StaticHtmlService;
use app\common\service\static\StaticRemotePublishConfigService;
use app\common\service\tag\TagGroupService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\user\RoleService;
use app\common\service\user\UserService;
use app\common\service\audit\LogService;
use app\common\support\OpsLog;
use app\common\support\ServiceResult;

/** Vue 后台 SPA *Meta 端点 payload（从 Spa 控制器分批下沉） */
class AdminSpaMetaService
{

    public function __construct(
        private readonly PluginReservedIdentifierService $pluginReservedIdentifierService,
        private readonly SmsConfigService $smsConfigService,
    ) {
    }

    /** @return array<string, mixed> */
    public function configMeta(): array
    {
        app(SiteKeyService::class)->ensure();
        $config = app(ConfigService::class)->getAll();
        $config = $this->sanitizeConfigMediaUrls($config);

        app(LicenseHeartbeatService::class)->maybeSendDeferred();
        try {
            app(LicenseSyncService::class)->maybeSync();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('admin_spa_config_meta_license_sync_failed', [
                'msg' => $e->getMessage(),
            ]);
        }

        return [
            'config'               => $config,
            'customVars'           => app(ConfigService::class)->getCustomVars(),
            'themes'               => app(ThemeService::class)->listThemes(),
            'themeScanWarnings'    => app(ThemeService::class)->listThemeScanWarnings(),
            'memberThemes'         => app(ThemeService::class)->listMemberThemes(),
            'siteMode'             => app(SiteModeService::class)->normalize((string) ($config['site_mode'] ?? SiteModeService::DEV)),
            'contentEditorMode'    => app(ContentEditorService::class)->normalize((string) ($config['content_editor'] ?? ContentEditorService::TIPTAP)),
            'pageCacheTtl'         => (int) config('pivark.page_cache_ttl', 300),
            'cacheDriver'          => app(CacheConfigService::class)->configuredDriver(),
            'cacheDriverEffective' => app(CacheConfigService::class)->effectiveDriver(),
            'redisExtensionLoaded' => app(CacheConfigService::class)->extensionLoaded(),
            'cacheRedisFallback'   => app(CacheConfigService::class)->redisFallbackUsed(),
            'coreUpdate'           => app(CoreUpdateRemoteService::class)->check(),
            'licenseStatus'        => app(LicenseActivateService::class)->status(),
            'siteBrand'            => app(SiteBrandService::class)->adminPayload(),
            'productCenterAllowed' => app(\app\common\service\product\ProductCenterGateService::class)->allowsAdmin(),
        ];
    }

    /** @return array<string, mixed> */
    public function cronMeta(): array
    {
        return [
            'handlers'    => app(CronService::class)->handlers(),
            'logs'        => app(CronService::class)->listLogsAdmin(),
            'webhook'     => app(CronService::class)->webhookMeta(),
            'event_queue' => app(DomainEventDispatchAdminService::class)->panel(),
        ];
    }

    /**
     * @param array<string, mixed>|null $admin
     * @return array<string, mixed>
     */
    public function userFormMeta(int $userId, ?array $admin): array
    {
        $isSelfEdit = is_array($admin) && (int) ($admin['id'] ?? 0) === $userId;

        return [
            'roles'       => app(RoleService::class)->getAllForUserForm(),
            'tagPicker'   => app(AdminTagScopeService::class)->listTagsForAdminPicker(),
            'navPicker'   => app(AdminTagScopeService::class)->listNavsForAdminPicker(),
            'userRoleIds' => $userId > 0 ? app(UserService::class)->getRoleIdsForUser($userId) : [],
            'tagScopeIds' => $userId > 0 ? app(UserService::class)->getTagScopeIdsForUser($userId) : [],
            'navScopeIds' => $userId > 0 ? app(UserService::class)->getNavScopeIdsForUser($userId) : [],
            'isSelfEdit'  => $isSelfEdit,
        ];
    }

    /** @return array<string, mixed> */
    public function roleFormMeta(int $roleId): array
    {
        return [
            'permissionGroups' => app(RoleService::class)->permissionsGroupedForForm(),
            'permissionIds'    => $roleId > 0 ? app(RoleService::class)->getPermissionIds($roleId) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function memberFormMeta(): array
    {
        return app(AdminSpaMemberFormMetaCacheService::class)->remember(static function (): array {
            return [
                'memberLevels'        => app(MemberLevelService::class)->listActive(),
                'memberPointsEnabled' => app(MemberConfigService::class)->isPointsEnabled(),
                'customFields'        => app(MemberFieldService::class)->listActiveAll(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function aiDocumentMeta(): array
    {
        return [
            'cfg'           => app(AiConfigAdminService::class)->allForAdmin(),
            'env_overrides' => app(AiConfigAdminService::class)->envOverrideKeys(),
            'plugin'        => [
                'identifier'  => 'ai_config',
                'name'        => 'AI 配置',
                'description' => 'LLM / Embedding / 分块 / OCR 基础设施',
                'kernel_l1'   => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function siteNavMeta(): array
    {
        $tagTemplateOptions = app(\app\common\service\theme\ThemeTemplateCatalogService::class)->listOptions(
            \app\common\service\theme\ThemeTemplateCatalogService::SCOPE_TAG
        );
        $contentViewTemplateOptions = app(\app\common\service\theme\ThemeTemplateCatalogService::class)->listContentViewOptions();

        return [
            'typeLabels'        => app(SiteNavService::class)->typeLabels(),
            'contentKindLabels' => app(SiteNavService::class)->contentKindLabels(),
            'product_content_kind_allowed' => app(\app\common\service\product\ProductCenterGateService::class)->allowsAdmin(),
            'targetSuggestions' => app(SiteNavService::class)->targetSuggestions(),
            'tagTemplateOptions' => $tagTemplateOptions,
            'pageTemplateOptions' => app(\app\common\service\theme\ThemeTemplateCatalogService::class)->listOptions(
                \app\common\service\theme\ThemeTemplateCatalogService::SCOPE_PAGE
            ),
            'contentViewTemplateOptions' => $contentViewTemplateOptions,
            'memberLevels'      => app(MemberLevelService::class)->listActive(),
        ];
    }

    /** @return array<string, mixed> */
    public function memberCenterConfigMeta(): array
    {
        return [
            'cfg'              => app(MemberConfigService::class)->all(),
            'document_plugins' => app(MemberConfigService::class)->documentPluginOptionsForAdmin(),
            'capabilities'     => app(MemberUxService::class)->adminCapabilityHints(),
        ];
    }

    /** @return array<string, mixed> */
    public function paymentConfigMeta(): array
    {
        return [
            'cfg'            => app(PaymentConfigService::class)->allForAdmin(),
            'channels'       => app(PaymentConfigService::class)->channelsForAdmin(),
            'secret_status'  => app(PaymentConfigService::class)->secretStatusForAdmin(),
            'channel_setup'  => app(PaymentConfigService::class)->channelSetupForAdmin(),
            'notify_urls' => app(PaymentConfigService::class)->notifyUrlsForAdmin(),
            'mail_cfg'    => [
                'mail_smtp_host'    => (string) app(ConfigService::class)->get('mail_smtp_host', ''),
                'mail_from_address' => (string) app(ConfigService::class)->get('mail_from_address', ''),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function miniprogramWechatMeta(): array
    {
        return app(MiniprogramConfigService::class)->adminMeta();
    }

    /** @return array<string, mixed> */
    public function miniprogramPageMeta(): array
    {
        return app(MiniprogramPageConfigService::class)->adminMeta();
    }

    /** @return array<string, mixed> */
    public function searchConfigMeta(): array
    {
        $result = app(TagService::class)->listAdmin(['page' => 1, 'limit' => 200, 'status' => 1]);
        $smart  = app(SmartSearchAdminService::class)->metaForAdmin();

        return [
            'cfg'             => array_merge(app(SearchConfigService::class)->all(), $smart['cfg'] ?? []),
            'tags'            => $result['list'] ?? [],
            'engine'          => app(SearchIndexService::class)->engineStatusForAdmin(),
            'ai'              => $smart['ai'] ?? [],
            'guided_enabled'  => $smart['guided_enabled'] ?? '1',
            'guided_rules'    => $smart['guided_rules'] ?? [],
            'example_queries' => $smart['example_queries'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function captchaConfigMeta(): array
    {
        return [
            'cfg'          => app(CaptchaConfigService::class)->all(),
            'scenes'       => app(CaptchaConfigService::class)->scenes(true),
            'scene_groups' => app(CaptchaConfigService::class)->sceneGroupsForAdmin(),
        ];
    }

    /** @return array<string, mixed> */
    public function loginNoticeSettingsMeta(): array
    {
        return app(AdminLoginNoticeConfigService::class)->metaForAdmin();
    }

    /** @return array<string, mixed> */
    public function mailConfigMeta(): array
    {
        return app(MailConfigService::class)->metaForAdmin();
    }

    /** @return array<string, mixed> */
    public function smsConfigMeta(): array
    {
        return $this->smsConfigService->metaForAdmin();
    }

    /** @return array<string, mixed> */
    public function memberPointsConfigMeta(): array
    {
        return [
            'cfg'          => app(MemberConfigService::class)->pointsAll(),
            'capabilities' => app(MemberUxService::class)->adminCapabilityHints(),
        ];
    }

    /** @return array<string, mixed> */
    public function seoStaticMeta(): array
    {
        return [
            'enabled'             => app(StaticHtmlService::class)->enabled(),
            'homeInfo'            => app(StaticHtmlBatchService::class)->homeInfo(),
            'tags'                => app(TagService::class)->listAllActive(),
            'documents'           => Document::where('status', 1)->whereNull('deleted_at')
                ->order('id', 'desc')->limit(QueryLimit::SPA_META_RECENT)->field('id,title')->select()->toArray(),
            'siteUrlMode'         => (string) app(ConfigService::class)->get('site_url_mode', 'dynamic'),
            'siteBase'            => rtrim((string) app(ConfigService::class)->get('site_url', ''), '/'),
            'staticSubdir'        => (string) app(ConfigService::class)->get('seo_static_subdir', ''),
            'staticFlags'         => app(SeoStaticConfigService::class)->syncFlags(),
            'staticHint'          => app(SeoStaticConfigService::class)->storageHint(),
            'remotePublish'       => app(StaticRemotePublishConfigService::class)->panelForAdmin(),
            'remotePublishConfig' => app(StaticRemotePublishConfigService::class)->allForAdminForm(),
        ];
    }

    /** @return array<string, mixed> */
    public function floatContactMeta(): array
    {
        return [
            'cfg'    => app(FloatContactConfigService::class)->all(),
            'list'   => app(FloatContactService::class)->listAdmin(),
            'types'  => app(FloatContactService::class)->typeLabels(),
            'styles' => app(FloatContactConfigService::class)->styleOptions(),
        ];
    }

    /** @return array<string, mixed> */
    public function pluginScaffoldMeta(): array
    {
        return [
            'kindCards'      => app(PluginScaffoldService::class)->scaffoldKindCards(),
            'previewPackage' => app(PluginPackageService::class)->allocateUniquePackage(),
            'identifierPolicy' => $this->pluginReservedIdentifierService->scaffoldMeta(),
        ];
    }

    /** @return array<string, mixed> */
    public function seoSitemapMeta(): array
    {
        return [
            'settings'       => app(SitemapService::class)->settings(),
            'mapUrls'        => [
                'xml'  => app(SitemapService::class)->publicUrl('xml') ?: '/sitemap.xml',
                'txt'  => app(SitemapService::class)->publicUrl('txt') ?: '/sitemap.txt',
                'html' => app(SitemapService::class)->publicUrl('html') ?: '/sitemap.html',
                'ai'   => app(SitemapService::class)->publicUrl('ai') ?: '/ai-sitemap.txt',
                'llms' => app(SitemapService::class)->publicUrl('llms') ?: '/llms.txt',
            ],
            'freqLabels'     => app(SitemapService::class)->changefreqLabels(),
            'priorityLabels' => app(SitemapService::class)->priorityLabels(),
            'urlCount'       => app(SitemapService::class)->urlCount(),
            'qualityStats'   => app(SitemapService::class)->qualityStats(),
        ];
    }

    /** @return array<string, mixed> */
    public function seoRobotsMeta(): array
    {
        $presetLabels  = app(RobotsService::class)->presetLabels();
        $presetContent = [];
        foreach (array_keys($presetLabels) as $code) {
            $presetContent[$code] = app(RobotsService::class)->presetContent($code);
        }

        return [
            'content'       => app(RobotsService::class)->content(),
            'preset'        => app(RobotsService::class)->currentPreset(),
            'presetLabels'  => $presetLabels,
            'presetContent' => $presetContent,
        ];
    }

    /** @return array<string, mixed> */
    public function tagMeta(): array
    {
        return [
            'groups' => app(TagGroupService::class)->listForSelect(),
        ];
    }

    /** @return array<string, mixed> */
    public function documentMeta(): array
    {
        $tags = [];
        foreach (app(TagService::class)->listAllActive() as $tag) {
            $tags[] = [
                'id'   => (int) ($tag['id'] ?? 0),
                'name' => (string) ($tag['name'] ?? ''),
            ];
        }

        return [
            'attrLabels'         => DocumentAttrHelper::ATTR_FLAG_LABELS,
            'draftCount'         => app(DocumentAdminService::class)->countDraftDocuments(),
            'memberPendingCount' => app(DocumentAdminService::class)->countMemberPendingReview(),
            'tags'               => $tags,
        ];
    }

    /** @return array<string, mixed> */
    public function logMeta(): array
    {
        return [
            'modules' => app(LogService::class)->modulesForAdmin(),
            'types'   => app(LogService::class)->typeOptions(),
            'total'   => app(LogService::class)->countAll(),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>|null null = 文档不存在
     */
    public function documentFormMeta(int $documentId, array $admin): ?array
    {
        $article = $documentId > 0 ? app(DocumentAdminService::class)->findForAdmin($documentId) : null;
        if ($documentId > 0 && !$article) {
            return null;
        }
        if (is_array($article)) {
            $article['tags'] = app(TagService::class)->tagNamesCsvForDocument($documentId);
        }
        $templates  = app(ThemeTemplateCatalogService::class)->listFiles(ThemeTemplateCatalogService::SCOPE_DOCUMENT);
        $tplDefault = (string) ($article['tpl_name'] ?? $templates[0] ?? 'view_document.php');
        if (!in_array($tplDefault, $templates, true)) {
            $tplDefault = $templates[0] ?? 'view_document.php';
        }
        $articleId      = (int) ($article['id'] ?? 0);
        $userId         = (int) ($admin['id'] ?? 0);
        $contentProfile = app(AdminNavPersonaService::class)->documentEditorProfile($userId);
        $editorSurfaces = app(PluginDocumentEditorService::class)->listSpaSurfaces($articleId);
        if (empty($contentProfile['show_external_surfaces'])) {
            $editorSurfaces = ['tabs' => [], 'inlines' => []];
        }
        $authorName     = (string) ($article['author_name'] ?? app(DocumentPresetService::class)->defaultAuthorName($admin));
        $sourceVal      = (string) ($article['source'] ?? app(DocumentPresetService::class)->defaultSource());

        return [
            'document'           => $article,
            'isEdit'             => $article !== null,
            'articleTemplates'   => $templates,
            'templateOptions'    => app(ThemeTemplateCatalogService::class)->listOptions(ThemeTemplateCatalogService::SCOPE_DOCUMENT),
            'contentEditor'      => app(ContentEditorService::class)->current(),
            'memberLevels'       => app(MemberLevelService::class)->listActive(),
            'memberPointsEnabled'=> app(MemberConfigService::class)->isPointsEnabled(),
            'itemOptions'        => $this->documentFormItemOptions($contentProfile, $editorSurfaces),
            'authorPresets'      => app(DocumentPresetService::class)->authorPresets($admin),
            'sourcePresets'      => app(DocumentPresetService::class)->sourcePresets(),
            'authorForm'         => app(DocumentPresetService::class)->authorFormState($authorName, $admin),
            'sourceOrphan'       => app(DocumentPresetService::class)->sourceOrphanOption($sourceVal),
            'formDefaults'       => [
                'author_name'  => $authorName,
                'source'       => $sourceVal,
                'click'        => (int) ($article['click'] ?? DocumentAdminInternals::defaultClickFromConfig()),
                'published_at' => (string) ($article['published_at'] ?? AppTime::now()),
                'tpl_name'     => $tplDefault,
                'read_access'  => app(MemberLevelService::class)->encodeReadAccess(
                    (int) ($article['read_perm'] ?? 0),
                    (int) ($article['read_level_id'] ?? 0)
                ),
            ],
            'editorSurfaces'     => $editorSurfaces,
            'contentProfile'     => $contentProfile,
            'categoryOptions'    => app(SiteNavService::class)->listContentCategoryOptionsForPublish(),
        ];
    }

    /**
     * @return array<string, mixed>|null null = 单页不存在
     */
    public function sitePageFormMeta(int $pageId): ?array
    {
        $page = $pageId > 0 ? app(SitePageService::class)->findAdmin($pageId) : null;
        if ($pageId > 0 && $page === null) {
            return null;
        }

        $theme = app(ThemeService::class)->getCurrentTheme();

        return [
            'page'              => $page,
            'templates'         => app(SitePageService::class)->listAdminLayoutTemplates($theme),
            'templateOptions'   => app(ThemeTemplateCatalogService::class)->listOptions(
                ThemeTemplateCatalogService::SCOPE_PAGE,
                $theme,
            ),
            'contentEditor'     => app(ContentEditorService::class)->current(),
        ];
    }

    /** @return array<string, mixed>|null null = 字段不存在 */
    public function memberCenterFieldMeta(int $fieldId): ?array
    {
        $info = $fieldId > 0 ? app(MemberFieldService::class)->findAdmin($fieldId) : null;
        if ($fieldId > 0 && !$info) {
            return null;
        }

        return [
            'info'         => $info,
            'types'        => MemberFieldService::TYPES,
            'typeOptions'  => app(MemberFieldService::class)->typeOptionsForAdmin(),
            'systemFields' => [
                ['key' => 'username', 'label' => '用户名', 'note' => '系统内置，不可在此配置'],
                ['key' => 'nickname', 'label' => '昵称', 'note' => '系统内置'],
                ['key' => 'mobile', 'label' => '手机', 'note' => '系统内置'],
                ['key' => 'email', 'label' => '邮箱', 'note' => '系统内置'],
            ],
        ];
    }

    /** @return array<string, mixed>|null null = 套餐不存在 */
    public function memberCenterRechargeMeta(int $packageId): ?array
    {
        $info = $packageId > 0 ? app(MemberRechargeService::class)->findAdmin($packageId) : null;
        if ($packageId > 0 && !$info) {
            return null;
        }
        if (is_array($info)) {
            $info['package_type'] = app(MemberRechargeService::class)->normalizePackageType(
                (string) ($info['package_type'] ?? app(MemberRechargeService::class)->inferPackageType($info))
            );
        }

        return [
            'info'         => $info,
            'levels'       => app(MemberLevelService::class)->listActive(),
            'packageTypes' => [
                ['value' => MemberRechargeService::TYPE_MEMBERSHIP, 'label' => '会员套餐'],
                ['value' => MemberRechargeService::TYPE_POINTS, 'label' => '充积分'],
                ['value' => MemberRechargeService::TYPE_BALANCE, 'label' => '充余额'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function seoUrlMeta(): array
    {
        $sampleTag = 'pv-demo-news';
        foreach (app(TagService::class)->listAllActive() as $tagRow) {
            if (trim((string) ($tagRow['slug'] ?? '')) === $sampleTag) {
                $sampleTag = app(TagService::class)->publicPath($tagRow);
                break;
            }
        }

        return [
            'config'              => app(SeoConfigService::class)->all(),
            'siteBase'            => rtrim((string) app(ConfigService::class)->get('site_url', ''), '/'),
            'rewriteServerGuide'  => app(SiteUrlRewriteGuideService::class)->build($sampleTag),
            'staticFlags'         => app(SeoStaticConfigService::class)->syncFlags(),
            'staticHint'          => app(SeoStaticConfigService::class)->storageHint(),
            'remotePublish'       => app(StaticRemotePublishConfigService::class)->panelForAdmin(),
            'remotePublishConfig' => app(StaticRemotePublishConfigService::class)->allForAdminForm(),
            'tagTitleRules'       => app(SeoConfigService::class)->tagTitleRuleLabels(),
            'documentTitleRules'  => app(SeoConfigService::class)->documentTitleRuleLabels(),
            'tags'                => app(TagService::class)->listAllActive(),
            'documents'           => Document::where('status', 1)->whereNull('deleted_at')
                ->order('id', 'desc')->limit(QueryLimit::SPA_META_RECENT)->field('id,title')->select()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function tagFormMeta(int $tagId, int $groupId): array
    {
        $tagTemplateOptions = app(ThemeTemplateCatalogService::class)->listOptions(
            ThemeTemplateCatalogService::SCOPE_TAG
        );
        if ($tagTemplateOptions === []) {
            foreach (app(TagService::class)->listTagTemplates() as $tpl) {
                $tagTemplateOptions[] = [
                    'file'  => $tpl,
                    'label' => $tpl,
                    'hint'  => '',
                    'title' => $tpl,
                ];
            }
        }

        $contentViewTemplateOptions = app(ThemeTemplateCatalogService::class)->listContentViewOptions();

        $defaultGroupId = app(TagGroupService::class)->defaultGroupId();
        $effectiveGroupId = $groupId > 0 ? $groupId : $defaultGroupId;

        return [
            'default_group_id'   => $defaultGroupId,
            'groups'             => app(TagGroupService::class)->listForSelect(),
            'memberLevels'       => app(MemberLevelService::class)->listActive(),
            'parentOptions'      => app(TagService::class)->listParentOptions($tagId, $effectiveGroupId),
            'tagTemplateOptions' => $tagTemplateOptions,
            'contentViewTemplateOptions' => $contentViewTemplateOptions,
        ];
    }

    /** @return array<string, mixed>|null */
    public function userDetail(int $userId): ?array
    {
        $info = User::find($userId);

        return $info !== null ? $info->toArray() : null;
    }

    /** @return array<string, mixed>|null */
    public function roleDetail(int $roleId): ?array
    {
        $info = Role::find($roleId);

        return $info !== null ? $info->toArray() : null;
    }

    /** @return array<string, mixed>|null */
    public function memberLevelDetail(int $levelId): ?array
    {
        return app(MemberLevelService::class)->findAdmin($levelId);
    }

    /** @return array<string, mixed>|null */
    public function memberDetail(int $memberId): ?array
    {
        return app(MemberService::class)->detailForAdmin($memberId);
    }

    /** @return array<string, mixed>|null */
    public function tagDetail(int $tagId): ?array
    {
        return app(TagService::class)->findAdmin($tagId);
    }

    /**
     * @return array{list:list<array<string, mixed>>, total:int, page:int, limit:int}
     */
    public function documentTagPicker(string $keyword, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 50);
        $result = app(TagService::class)->listAdmin([
            'keyword' => $keyword,
            'page'    => $page,
            'limit'   => $limit,
            'status'  => 1,
        ]);
        $list = [];
        foreach ($result['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $list[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'name'       => (string) ($row['name'] ?? ''),
                'slug'       => (string) ($row['slug'] ?? ''),
                'group_name' => (string) ($row['group_name'] ?? ''),
                'use_count'  => (int) ($row['use_count'] ?? 0),
            ];
        }

        // 标签弹窗只列 Tag 聚合；栏目归属走 site_nav / nav_id
        return [
            'list'  => $list,
            'total' => (int) ($result['total'] ?? 0),
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function paymentOrdersList(array $query): array
    {
        $page  = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $filters = [
            'status'  => trim((string) ($query['status'] ?? '')),
            'channel' => trim((string) ($query['channel'] ?? '')),
            'keyword' => trim((string) ($query['keyword'] ?? '')),
            'scene'   => trim((string) ($query['scene'] ?? '')),
            'user_id' => (int) ($query['user_id'] ?? 0),
        ];
        $result         = app(PaymentOrderService::class)->listAdmin($filters, $page, $limit);
        $result['stats'] = app(PaymentOrderService::class)->statsAdmin();

        return $result;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{total:int, list:list<array<string, mixed>>, page:int, limit:int}
     */
    public function memberCenterFieldsList(array $query): array
    {
        return app(MemberFieldService::class)->listAdminPaged($query);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{total:int, list:list<array<string, mixed>>, page:int, limit:int}
     */
    public function memberCenterRechargeList(array $query): array
    {
        $paged = app(MemberRechargeService::class)->listAdminPaged($query);
        $list  = $paged['list'];
        $levelMap = [];
        foreach (app(MemberLevelService::class)->listActive() as $lv) {
            $levelMap[(int) ($lv['id'] ?? 0)] = (string) ($lv['name'] ?? '');
        }
        foreach ($list as $i => $row) {
            $lid = (int) ($row['level_id'] ?? 0);
            $list[$i]['level_name'] = $lid > 0 ? ($levelMap[$lid] ?? ('#' . $lid)) : '—';
            $type = app(MemberRechargeService::class)->normalizePackageType(
                (string) ($row['package_type'] ?? app(MemberRechargeService::class)->inferPackageType($row))
            );
            $list[$i]['package_type']       = $type;
            $list[$i]['package_type_label'] = app(MemberRechargeService::class)->packageTypeLabel($type);
            $grant = (float) ($row['grant_balance'] ?? 0);
            $list[$i]['grant_balance_text'] = $grant > 0
                ? MoneyMath::formatPlain($grant)
                : MoneyMath::formatPlain((float) ($row['price'] ?? 0));
        }

        return [
            'total' => $paged['total'],
            'list'  => $list,
            'page'  => $paged['page'],
            'limit' => $paged['limit'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{total:int, list:list<array<string, mixed>>, page:int, limit:int}
     */
    public function memberCenterCancelList(array $query): array
    {
        $status = $query['status'] ?? '';
        $statusFilter = $status === '' ? -1 : (int) $status;
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
        $keyword = trim((string) ($query['keyword'] ?? $query['q'] ?? ''));
        $result  = app(MemberCancelService::class)->listAdmin($page, $limit, $statusFilter, $keyword);

        return [
            'total' => (int) ($result['total'] ?? 0),
            'list'  => $result['list'] ?? [],
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /** @param array<string, mixed> $contentProfile @param array{tabs:list<array<string,mixed>>,inlines:list<array<string,mixed>>} $editorSurfaces @return list<array<string,mixed>> */
    private function documentFormItemOptions(array $contentProfile, array $editorSurfaces): array
    {
        if (!empty($contentProfile['show_item_picker']) || $this->editorSurfacesHasIdentifier($editorSurfaces, 'product')) {
            return app(ItemService::class)->optionsForDocumentForm();
        }

        return [];
    }

    /** @param array{tabs:list<array<string,mixed>>,inlines:list<array<string,mixed>>} $editorSurfaces */
    private function editorSurfacesHasIdentifier(array $editorSurfaces, string $identifier): bool
    {
        foreach ([...($editorSurfaces['tabs'] ?? []), ...($editorSurfaces['inlines'] ?? [])] as $row) {
            if (($row['identifier'] ?? '') === $identifier) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    private function sanitizeConfigMediaUrls(array $config): array
    {
        foreach (['site_logo', 'site_logo_hero', 'watermark_image', 'watermark_img'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $config[$key] = app(UserService::class)->publicMediaUrlIfExists($config[$key]) ?? '';
        }

        return $config;
    }

    public function spaClipboardUrlInsight(string $url, string $raw): ServiceResult
    {
        if (trim($url) === '') {
            return ServiceResult::fail('请提供 url 参数');
        }

        return app(ClipboardUrlInsightService::class)->analyze($url, $raw);
    }

    /** @return ServiceResult<array{delegates:list<array<string,mixed>>}> */
    public function spaImportIntentCatalog(): ServiceResult
    {
        return ServiceResult::ok([
            'delegates' => app(ImportIntentResolveService::class)->catalog(),
        ]);
    }
}
