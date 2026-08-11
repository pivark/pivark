<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * Constructor DI wave 清单（SSOT）。
 * 按 wave 分批注册 Service。
 */
declare(strict_types=1);

return [
    'wave1' => [
        \app\common\service\payment\PaymentOrderFactory::class,
        \app\common\service\payment\PaymentPublicGateway::class,
    ],
    'wave2' => [
        \app\common\service\plugin\commerce\PluginSkuFulfillmentService::class,
    ],
    'wave3' => [
        \app\common\service\plugin\market\PluginMarketAcquireService::class,
        \app\common\service\plugin\commerce\PluginCommerceService::class,
    ],
    'wave4' => [
        \app\common\service\plugin\entitlement\EntitlementService::class,
        \app\common\service\plugin\commerce\PluginSkuCatalogService::class,
    ],
    'wave5' => [
        \app\common\service\static\StaticBuildQueueService::class,
        \app\common\service\member\MemberProfileService::class,
    ],
    'wave6' => [
        \app\common\service\document\satellite\DocumentFavoriteService::class,
        \app\common\service\site\SiteFormRenderService::class,
    ],
    'wave7' => [
        \app\common\service\site\SiteFormCrudService::class,
        \app\common\service\media\MediaLibraryService::class,
    ],
    'wave8' => [
        \app\common\service\search\SearchDegradedGuard::class,
        \app\common\service\search\SearchMemberContext::class,
    ],
    'wave9' => [
        \app\common\service\content\ContentSearchService::class,
        \app\common\service\search\SearchFulltextSupport::class,
    ],
    'wave10' => [
        \app\common\service\search\SearchDriverFactory::class,
        \app\common\service\document\DocumentPublicService::class,
    ],
    'wave11' => [
        \app\common\service\document\DocumentFormatService::class,
        \app\common\service\search\SqlSearchDriver::class,
    ],
    'wave12' => [
        \app\common\service\document\DocumentAttrFlagIndexService::class,
        \app\common\service\search\MeilisearchSearchDriver::class,
    ],
    'wave13' => [
        \app\common\service\search\ElasticSearchDriver::class,
    ],
    'wave14' => [
        \app\common\service\search\ElasticDocumentIndex::class,
        \app\common\service\search\SearchModeHelper::class,
    ],
    'wave15' => [
        \app\common\service\search\SmartSearchConfigService::class,
        \app\common\service\search\SearchItemRecordBuilder::class,
    ],
    'wave16' => [
        \app\common\service\search\MeilisearchItemIndex::class,
        \app\common\service\search\SearchDocumentRecordBuilder::class,
    ],
    'wave17' => [
        \app\common\service\search\SmartSearchCacheService::class,
        \app\common\service\search\SmartSearchSuggestService::class,
    ],
    'wave18' => [
        \app\common\service\search\SearchTextExtractor::class,
    ],
    'wave19' => [
        \app\common\service\search\SearchIndexQueueService::class,
        \app\common\service\search\SearchQueryLogService::class,
    ],
    'wave20' => [
        \app\common\service\search\SmartSearchSessionService::class,
        \app\common\service\search\DocumentAddonPlainTextCollector::class,
    ],
    'wave21' => [
        \app\common\service\search\ItemSearchIndexService::class,
        \app\common\service\search\SmartSearchSynonymService::class,
    ],
    'wave22' => [
        \app\common\service\search\SearchFulltextSupport::class,
    ],
    'wave23' => [
        \app\common\service\search\SearchIndexQueueAdminService::class,
    ],
    'wave24' => [
        \app\common\service\search\SearchIndexService::class,
        \app\common\service\search\SearchOpsAdminService::class,
    ],
    'wave25' => [
        \app\common\service\search\DocumentSearchEnrichService::class,
        \app\common\service\search\DocumentSearchTextBackfillService::class,
    ],
    'wave26' => [
        \app\common\service\search\ItemListSearchService::class,
        \app\common\service\search\SearchConfigService::class,
    ],
    'wave27' => [
        \app\common\service\search\SmartSearchAdminService::class,
        \app\common\service\auth\SocialAuthService::class,
    ],
    'wave28' => [
        \app\common\service\search\ElasticSearchHttpClient::class,
        \app\common\service\search\ElasticItemIndex::class,
    ],
    'wave29' => [
        \app\common\service\search\DocumentAddonAttachmentRegistry::class,
    ],
    'wave30' => [
        \app\common\service\search\DocumentAddonSearchRegistry::class,
        \app\common\service\search\ItemCatalogSearchDriverFactory::class,
    ],
    'wave31' => [
        \app\common\service\search\SearchTextSanitizer::class,
    ],
    'wave32' => [
        \app\common\service\search\SmartSearchOrchestratorService::class,
        \app\common\service\search\SmartSearchAnalyticsService::class,
    ],
    'wave33' => [
        \app\common\service\search\SmartSearchClickService::class,
        \app\common\service\search\SmartSearchAnswerFormatter::class,
    ],
    'wave34' => [
        \app\common\service\search\SearchPublicGateway::class,
        \app\common\service\search\SearchIndexAdminGateway::class,
    ],
    'wave35' => [
        \app\common\service\search\ProductDocumentAddonSearchContributor::class,
        \app\common\service\search\DocumentAddonPlainTextCollector::class,
    ],
    'wave36' => [
        \app\common\service\member\MemberPointService::class,
        \app\common\service\member\MemberBalanceService::class,
    ],
    'wave37' => [
        \app\common\service\user\PermissionService::class,
        \app\common\service\user\UserPasswordService::class,
    ],
    'wave38' => [
        \app\common\service\infra\SchemaMigrationService::class,
        \app\common\service\infra\HotCacheService::class,
    ],
    'wave39' => [
        \app\common\service\infra\SentryReportService::class,
        \app\common\service\infra\AdminAsyncExportService::class,
    ],
    'wave40' => [
        \app\common\service\item\ItemTagScopeService::class,
        \app\common\service\template\TemplateMetaService::class,
    ],
    'wave41' => [
        \app\common\service\plugin\lifecycle\PluginDistributionService::class,
        \app\common\service\site\AdminEntryAliasService::class,
    ],
    'wave42' => [
        \app\common\service\ai\DocumentExtractService::class,
        \app\common\service\catalog\CatalogFacetStatsService::class,
    ],
    'wave43' => [
        \app\common\service\document\DocumentListMaterializedService::class,
        \app\common\service\content\ContentEditorService::class,
    ],
    'wave44' => [
        \app\common\service\payment\WechatPayCertImportService::class,
    ],
    'wave45' => [
        \app\common\service\document\satellite\DocumentArticleMetaService::class,
        \app\common\service\item\ItemCatalogPublicService::class,
    ],
    'wave46' => [
        \app\common\service\payment\PaymentFulfillmentService::class,
        \app\common\service\seo\SeoTemplateService::class,
    ],
    'wave47' => [
        \app\common\service\theme\ThemeService::class,
        \app\common\service\tag\TagNavImportService::class,
    ],
    'wave48' => [
        \app\common\service\media\MediaLibraryPathService::class,
        \app\common\service\static\StaticHtmlPathService::class,
    ],
    'wave49' => [
        \app\common\service\member\MemberCancelService::class,
        \app\common\service\member\MemberLevelService::class,
    ],
    'wave50' => [
        \app\common\service\document\satellite\DocumentQrService::class,
        \app\common\service\infra\MetaSqlCacheService::class,
    ],
    'wave51' => [
        \app\common\service\infra\DeadLinkCheckService::class,
        \app\common\service\audit\LogService::class,
    ],
    'wave52' => [
        \app\common\service\member\MemberApiTokenService::class,
        \app\common\service\member\MemberPluginNavService::class,
    ],
    'wave53' => [
        \app\common\service\plugin\PluginManifestService::class,
        \app\common\service\ai\OcrService::class,
    ],
    'wave54' => [
        \app\common\service\ai\DocumentAttachmentSourceService::class,
        \app\common\service\event\DomainEventDispatchQueueService::class,
    ],
    'wave55' => [
        \app\common\service\enterprise\EnterpriseAssetService::class,
        \app\common\service\site\SiteDomainEntitlementService::class,
    ],
    'wave56' => [
        \app\common\service\catalog\CatalogListCacheService::class,
        \app\common\service\document\satellite\DocumentPreviewService::class,
    ],
    'wave57' => [
        \app\common\service\template\TemplateFragmentCacheService::class,
        \app\common\service\site\SiteStatusService::class,
    ],
    'wave58' => [
        \app\common\service\document\satellite\DocumentEnterpriseResourceService::class,
    ],
    'wave59' => [
        \app\common\service\plugin\gateway\PluginGatewayAuditService::class,
        \app\common\service\site\SiteOverlayAdService::class,
    ],
    'wave60' => [
        \app\common\service\member\MemberConsumptionService::class,
        \app\common\service\infra\PageCacheWarmupService::class,
    ],
    'wave61' => [
        \app\common\service\admin\AdminTagScopeService::class,
        \app\common\service\site\SiteLinkService::class,
    ],
    'wave62' => [
        \app\common\service\admin\AdminTotpService::class,
        \app\common\service\admin\AdminSensitiveConfirmService::class,
    ],
    'wave63' => [
        \app\common\service\infra\IdempotencyService::class,
        \app\common\service\plugin\security\PluginSecurityPolicyService::class,
    ],
    'wave64' => [
        \app\common\service\site\SiteDomainService::class,
        \app\common\service\channel\MiniprogramPageDefaultsService::class,
    ],
    'wave65' => [
        \app\common\service\release\PivarkEditionService::class,
        \app\common\service\site\SiteKeyService::class,
    ],
    'wave66' => [
        \app\common\service\document\satellite\DocumentPaymentService::class,
    ],
    'wave67' => [
        \app\common\service\watermark\WatermarkService::class,
    ],
    'wave68' => [
        \app\common\service\template\TemplateParseCacheService::class,
    ],
    'wave69' => [
        \app\common\service\admin\AdminCacheService::class,
        \app\common\service\channel\MiniprogramFeatureService::class,
    ],
    'wave70' => [
        \app\common\service\site\FloatContactService::class,
        \app\common\service\document\satellite\DocumentPresetService::class,
    ],
    'wave71' => [
        \app\common\service\media\MediaOrphanQueueService::class,
        \app\common\service\upload\UploadRemoteMirrorService::class,
    ],
    'wave72' => [
        \app\common\service\plugin\package\PluginPackageStorageService::class,
        \app\common\service\plugin\commerce\PluginSkuCatalogStorageService::class,
    ],
    'wave73' => [
        \app\common\service\tag\TagGroupService::class,
        \app\common\service\license\LicenseHmacService::class,
        \app\common\service\license\LicenseRemoteClientService::class,
    ],
    'wave74' => [
        \app\common\service\watermark\WatermarkConfigService::class,
        \app\common\service\catalog\CatalogQueryService::class,
    ],
    'wave75' => [
        \app\common\service\document\satellite\DocumentBlockService::class,
        \app\common\service\event\EventBusService::class,
    ],

    'wave76' => [
        \app\common\service\release\CoreUpdateSignatureService::class,
        \app\common\service\release\CoreUpdatePackageService::class,
        \app\common\service\admin\AdminCockpitService::class,
    ],
    'wave77' => [
        \app\common\service\template\TemplateFragmentHookService::class,
        \app\common\service\plugin\commerce\PluginWalletService::class,
    ],
    'wave78' => [
        \app\common\service\seo\SeoStaticConfigService::class,
        \app\common\service\member\MemberViewAsService::class,
    ],
    'wave79' => [
        \app\common\service\infra\BackupService::class,
        \app\common\service\infra\AdminSqlConsoleService::class,
        \app\common\service\config\ConfigSecretService::class,
    ],
    'wave80' => [
        \app\common\service\member\MemberOpsService::class,
        \app\common\service\plugin\market\PluginMarketBlocklistService::class,
    ],
    'wave81' => [
        \app\common\service\plugin\commerce\PluginCommercialPackageService::class,
        \app\common\service\plugin\package\S3PresignService::class,
    ],
    'wave82' => [
        \app\common\service\plugin\market\PluginMarketCatalogSecurityService::class,
        \app\common\service\ai\ChunkService::class,
    ],
    'wave83' => [
        \app\common\service\release\CoreUpdateRemoteService::class,
    ],
    'wave84' => [
        \app\common\service\static\StaticHtmlManifestService::class,
    ],
    'wave85' => [
        \app\common\service\theme\ThemeTemplateCatalogService::class,
        \app\common\service\site\SiteUrlRewriteGuideService::class,
    ],
    'wave86' => [
        \app\common\service\site\SiteUrlModeService::class,
        \app\common\service\site\SiteBrandService::class,
    ],
    'wave87' => [
        \app\common\service\ai\AIMetadataService::class,
        \app\common\service\tag\TagSlugIndexService::class,
    ],
    'wave88' => [
        \app\common\service\document\satellite\DocumentScheduleService::class,
        \app\common\service\menu\MenuService::class,
    ],
    'wave89' => [
        \app\common\service\media\MediaPurgeBatchService::class,
        \app\common\service\plugin\manifest\PluginLicenseFileService::class,
    ],
    'wave90' => [
        \app\common\service\plugin\package\PluginInstallPreflightService::class,
        \app\common\service\plugin\package\PluginCoreVersionRequirementService::class,
        \app\common\service\ai\AiConfigHookService::class,
    ],
    'wave91' => [
        \app\common\service\ai\DocumentIngestService::class,
        \app\common\service\ai\LlmChatService::class,
    ],
    'wave92' => [
        \app\common\service\ai\ChunkVectorSearchService::class,
        \app\common\service\template\TemplateBlockCacheService::class,
    ],
    'wave93' => [
        \app\common\service\favorite\FavoriteConfigService::class,
    ],
    'wave94' => [
        \app\common\service\infra\BreadcrumbService::class,
        \app\common\service\site\SiteAdSlotService::class,
    ],
    'wave95' => [
        \app\common\service\favorite\FavoriteService::class,
        \app\common\service\ContentBulkReplaceService::class,
    ],
    'wave96' => [
        \app\common\service\upload\UploadService::class,
        \app\common\service\upload\UploadChunkService::class,
        \app\common\service\upload\UploadDirProtectService::class,
    ],
    'wave97' => [
        \app\common\service\plugin\package\PluginInstallBackupService::class,
        \app\common\service\ai\KnowledgeSearchIntentService::class,
    ],

    'wave98' => [
        \app\common\service\ai\AiConfigAdminService::class,
        \app\common\service\seo\SeoTitleService::class,
    ],
    'wave99' => [
        \app\common\service\license\LicensePortalService::class,
        \app\common\service\user\PasswordResetService::class,
    ],
    'wave100' => [
        \app\common\service\admin\AdminDashboardPreferenceService::class,
        \app\common\service\seo\RobotsService::class,
    ],
    'wave101' => [
        \app\common\service\channel\MiniprogramDeliveryService::class,
        \app\common\service\user\RoleService::class,
    ],
    'wave102' => [
        \app\common\service\member\MemberMpWechatAuthService::class,
        \app\common\service\media\MediaAssetRefService::class,
    ],
    'wave103' => [
        \app\common\service\media\MediaAssetService::class,
        \app\common\service\plugin\package\PluginPackageAuditService::class,
    ],
    'wave104' => [
        \app\common\service\site\SiteMapService::class,
        \app\common\service\seo\SeoConfigService::class,
    ],
    'wave105' => [
        \app\common\service\infra\CacheConfigService::class,
        \app\common\service\upload\UploadDirectSignService::class,
    ],
    'wave106' => [
        \app\common\service\plugin\encode\PluginEncodeBuildService::class,
        \app\common\service\export\ExportImportService::class,
    ],
    'wave107' => [
        \install\service\InstallService::class,
        \app\common\service\site\SiteCoreLicenseService::class,
    ],
    'wave108' => [
        \app\common\service\member\MemberRegisterVerifyService::class,
        \app\common\service\member\MemberPointGiftService::class,
    ],
    'wave109' => [
        \app\common\service\site\SiteModeService::class,
        \app\common\service\template\TemplateCompileCacheService::class,
    ],
    'wave110' => [
        \app\common\service\channel\MiniprogramBootstrapService::class,
        \app\common\service\plugin\extension\PluginEditorSurfaceService::class,
    ],
    'wave111' => [
        \app\common\service\plugin\commerce\PluginMeteringService::class,
        \app\common\service\ai\AiConfigProcessService::class,
    ],
    'wave112' => [
        \app\common\service\static\StaticRemotePublishConfigService::class,
        \app\common\service\enterprise\EnterpriseResourceService::class,
    ],
    'wave113' => [
        \app\common\service\license\LicenseSyncService::class,
        \app\common\service\member\MemberPublishLayoutService::class,
    ],
    'wave114' => [
        \app\common\service\user\UserService::class,
        \app\common\service\license\LicenseHeartbeatService::class,
    ],
    'wave115' => [
        \app\common\service\mail\MailService::class,
        \app\common\service\kernel\KernelBootstrapService::class,
    ],

    'wave116' => [
        \app\common\service\site\FloatContactTemplateTagService::class,
    ],
    'wave117' => [
        \app\common\service\payment\PaymentConfigService::class,
        \app\common\service\front\FrontAuthService::class,
    ],
    'wave118' => [
        \app\common\service\content\EditorContentService::class,
        \app\common\service\access\AccessStatsService::class,
    ],
    'wave119' => [
        \app\common\service\member\MemberUxService::class,
        \app\common\service\auth\CaptchaConfigService::class,
    ],
    'wave120' => [
        \app\common\service\site\FloatContactConfigService::class,
        \app\common\service\member\MemberAdminCrudService::class,
    ],
    'wave121' => [
        \app\common\service\plugin\boot\PluginBootService::class,
    ],
    'wave122' => [
        \app\common\service\site\SiteSlideService::class,
        \app\common\service\payment\PaymentOrderService::class,
    ],
    'wave123' => [
        \app\common\service\document\DocumentRecycleService::class,
        \app\common\service\static\StaticHtmlService::class,
    ],
    'wave124' => [
        \app\common\service\item\ItemService::class,
        \app\common\service\item\ItemTemplateTagService::class,
    ],
    'wave125' => [
        \app\common\service\tag\TagPublicService::class,
        \app\common\service\ai\EmbeddingService::class,
    ],
    'wave126' => [
        \app\common\service\item\ItemFilterFacetService::class,
        \app\common\service\admin\AdminDashboardService::class,
    ],
    'wave127' => [
        \app\common\service\item\ItemPublicViewService::class,
        \app\common\service\channel\MiniprogramChannelService::class,
    ],
    'wave128' => [
        \app\common\service\plugin\market\PluginMarketSecuritySyncService::class,
        \app\common\service\event\DomainEventDispatchAdminService::class,
    ],
    'wave129' => [
        \app\common\service\document\satellite\DocumentAssetSyncService::class,
        \app\common\service\site\FloatContactWidgetRenderService::class,
    ],
    'wave130' => [
        \app\common\service\upload\UploadDirectCallbackService::class,
        \app\common\service\member\MemberService::class,
    ],
    'wave131' => [
        \app\common\service\member\MemberRegisterService::class,
    ],
    'wave132' => [
        \app\common\service\config\AiConfigService::class,
        \app\common\service\front\FrontUrlRuleService::class,
    ],
    'wave133' => [
        \app\common\service\seo\SitemapService::class,
        \app\common\service\plugin\extension\PluginDocumentEditorService::class,
    ],
    'wave134' => [
        \app\common\service\template\TemplateTagdocumentsBatchService::class,
        \app\common\service\static\StaticHtmlGeneratorService::class,
    ],
    'wave135' => [
        \app\common\service\channel\MiniprogramPageConfigService::class,
        \app\common\service\tag\TagAdminService::class,
    ],
    'wave136' => [
        \app\common\service\channel\MiniprogramPageCoreService::class,
        \app\common\service\ai\KnowledgeSearchService::class,
    ],
    'wave137' => [
        \app\common\service\static\StaticHtmlBatchService::class,
        \app\common\service\site\SiteFormService::class,
    ],
    'wave138' => [
        \app\common\service\site\SiteNavService::class,
        \app\common\service\license\LicenseActivateService::class,
    ],
    'wave139' => [
        \app\common\service\release\CoreUpdateApplyService::class,
        \app\common\service\member\MemberRechargeService::class,
    ],
    'wave140' => [
        \app\common\service\plugin\registry\PluginCapabilityService::class,
        \app\common\service\site\SitePageService::class,
    ],
    'wave141' => [
        \app\common\service\tag\TagService::class,
        \app\common\service\static\StaticRemotePublishService::class,
    ],
    'wave142' => [
        \app\common\service\channel\MiniprogramConfigService::class,
        \app\common\service\media\MediaLibraryOpsService::class,
    ],
    'wave143' => [
        \app\common\service\plugin\scaffold\PluginScaffoldService::class,
        \app\common\service\plugin\package\PluginPackageService::class,
    ],
    'wave144' => [
        \app\common\service\member\MemberConfigService::class,
        \app\common\service\document\DocumentAdminBatchService::class,
    ],
    'wave145' => [
        \app\common\service\plugin\extension\PluginDocumentSaveService::class,
        \app\common\service\front\FrontRenderService::class,
    ],
    'wave146' => [
        \app\common\service\document\DocumentAdminService::class,
        \app\common\service\cron\CronService::class,
    ],
    'wave147' => [
        \app\common\service\config\ConfigService::class,
        \app\common\service\infra\DataRetentionConfigService::class,
        \app\common\service\plugin\PluginService::class,
    ],
    'wave148' => [
        \app\common\service\document\satellite\DocumentPluginAvailabilityService::class,
        \app\common\service\plugin\commerce\PluginCommerceService::class,
    ],
    'wave149' => [
        \app\common\service\plugin\commerce\PluginSkuFulfillmentService::class,
    ],
    'wave151' => [
        \app\common\service\user\PermissionService::class,
    ],
    'wave152' => [
        \app\common\service\template\TemplateMetaService::class,
    ],
    'wave153' => [
        \app\common\service\search\SearchItemRecordBuilder::class,
        \app\common\service\member\MemberPointService::class,
        \app\common\service\member\MemberBalanceService::class,
        \app\common\service\release\PivarkEditionService::class,
    ],
    'wave154' => [
        \app\common\service\media\MediaOrphanQueueService::class,
        \app\common\service\media\MediaAssetRefService::class,
        \app\common\service\media\MediaAssetService::class,
        \app\common\service\media\MediaPurgeBatchService::class,
    ],
    'wave155' => [
        \app\common\service\plugin\scaffold\PluginDeveloperWorkbenchService::class,
    ],
    'wave156' => [
        \install\service\InstallDatabaseService::class,
    ],
    'wave157' => [
        \install\service\InstallCompletionLinksService::class,
        \install\service\InstallSeedService::class,
        \install\service\InstallStepService::class,
        \app\common\service\weapp\WeappInstallDemoService::class,
    ],
    'wave158' => [
        \app\common\service\plugin\entitlement\EntitlementQueryService::class,
    ],
    'wave159' => [
        \app\common\service\plugin\entitlement\EntitlementCommandService::class,
    ],
    'wave160' => [
        \app\common\service\theme\ThemeDataProviderService::class,
    ],
    'wave161' => [
        \app\common\service\plugin\scaffold\PluginReservedIdentifierService::class,
    ],
];
