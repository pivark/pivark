<?php
/**
 * 构造 DI 阶段 · 永久 lazy / 空 ctor 白名单（SSOT）。
 *
 * @return array<class-string, string> class => 原因
 */
return [
    \app\common\service\config\ConfigService::class => 'hub：全站配置聚合，禁止 ctor 拉满子图',
    \app\common\service\plugin\PluginService::class => 'hub：插件注册/manifest，边沿 lazy',
    \app\common\service\front\FrontRenderService::class => 'hub：前台渲染入口，空 ctor + 边沿 lazy',
    \app\common\service\catalog\CatalogListCacheService::class => '空 ctor：与 CatalogQueryService 互依，lazy 破环',
    \app\common\service\item\ItemPublicUrlService::class => 'leaf：零依赖 URL 工具，无 ctor 参数',
    \app\common\service\member\MemberRoleCheckService::class => 'leaf：零依赖角色校验，无 ctor 参数',
    \app\common\service\enterprise\EnterpriseAssetService::class => 'leaf：资料门面读 Registry/Model，暂空 ctor',
    \app\common\service\license\LicenseHmacService::class => 'leaf：读 config HMAC secret，零依赖',
    \app\common\service\release\CoreUpdateSignatureService::class => 'leaf：读 config 公钥验签，零依赖',
    \app\common\service\plugin\package\PluginCoreVersionRequirementService::class => 'leaf：纯约束解析，零依赖',
    \app\common\service\upload\UploadDirProtectService::class => 'leaf：上传目录防护，零依赖',
    \install\service\InstallCompletionLinksService::class => 'leaf：安装完成页外链，零依赖',
];
