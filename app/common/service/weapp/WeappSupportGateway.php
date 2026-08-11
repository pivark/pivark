<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 插件可调用的内核工具门面（WeappSupportGateway）
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\service\export\DataExportExtensionRegistry;
use app\common\support\AdminApiResponse;
use app\common\support\AdminBatchSupport;
use app\common\support\ApiResponse;
use app\common\service\config\ConfigService;
use app\common\service\plugin\gateway\PluginGatewayCallerContext;
use app\common\service\plugin\security\PluginDataAccessGuard;
use app\common\service\plugin\security\PluginFileAccessGuard;
use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\FrontVendorAsset;
use app\common\support\HtmlSanitizer;
use app\common\support\LocalFile;
use app\common\support\ModelRelationLoad;
use app\common\support\OpsLog;
use app\common\support\ParseIds;
use app\common\support\ServiceResult;
use app\common\support\SiteUrl;
use app\common\support\WeappPublicAsset;
use app\common\support\MoneyMath;
use app\common\support\PvPublicAsset;
use app\common\support\ProjectPaths;
use app\common\support\SeedExecutionGuard;
use think\Response;

final class WeappSupportGateway
{

    public function __construct(
        private readonly ConfigService $config,
        private readonly DataExportExtensionRegistry $dataExportExtension,
    ) {
    }

    /** @param array<string, mixed> $def */
    public function dataExportExtensionRegister(array $def): void
    {
        $this->dataExportExtension->register($def);
    }

    /**
     * @param array{label?:string, settings_route?:string} $entry
     */
    public function socialAuthCapabilityRegister(string $identifier, array $entry): void
    {
        app(\app\common\service\auth\SocialAuthCapabilityRegistry::class)->register($identifier, $entry);
    }

    public function appTimeNow(): string
    {
        return AppTime::now();
    }

    /** @return list<int> */
    public function parseIdsFromMixed(mixed $raw): array
    {
        return ParseIds::fromMixed($raw);
    }

    public function dbModelExists(string $modelClass): bool
    {
        return DbTable::modelExists($modelClass);
    }

    public function documentKernelModelExists(): bool
    {
        return DbTable::modelExists(\app\common\model\Document::class);
    }

    /** @param array<string, mixed> $context */
    public function kernelOpsLog(string $key, array $context = []): void
    {
        OpsLog::businessWarning($key, $context);
    }

    public function htmlSanitizePlainText(string $text, int $max = 0): string
    {
        return HtmlSanitizer::cleanPlainText($text, $max);
    }

    public function htmlSanitizeArticle(string $html): string
    {
        return HtmlSanitizer::cleanArticle($html);
    }

    public function weappPublicAssetUrl(string $identifier, string $relativePath): string
    {
        return WeappPublicAsset::url($identifier, $relativePath);
    }

    public function weappPublicAssetAbsoluteUrl(string $path): string
    {
        return WeappPublicAsset::absoluteAssetUrl($path);
    }

    public function weappPublicAssetNormalizeDocHtml(string $catalogHtml, string $identifier): string
    {
        return WeappPublicAsset::normalizeDocHtml($catalogHtml, $identifier);
    }

    public function frontVendorBootstrapIconsCss(?string $theme = null): string
    {
        return FrontVendorAsset::bootstrapIconsCss($theme);
    }

    public function frontVendorFallbackScriptIfRemote(string $primaryUrl, string $elementId, string $localUrl): string
    {
        return FrontVendorAsset::fallbackScriptIfRemote($primaryUrl, $elementId, $localUrl);
    }

    public function localFileUnlinkIfExists(string $path): bool
    {
        PluginFileAccessGuard::assertCallerPathAlways($path);

        return LocalFile::unlinkIfExists($path);
    }

    public function localFileUnlinkQuiet(string $path, string $context = ''): bool
    {
        PluginFileAccessGuard::assertCallerPathAlways($path);

        return LocalFile::unlinkQuiet($path, $context);
    }

    public function localFileRenameQuiet(string $src, string $dst, string $context = ''): bool
    {
        PluginFileAccessGuard::assertCallerPathAlways($src);
        PluginFileAccessGuard::assertCallerPathAlways($dst);

        return LocalFile::renameQuiet($src, $dst, $context);
    }

    public function localFileRemoveDirRecursive(string $dir, string $context = ''): void
    {
        PluginFileAccessGuard::assertCallerPathAlways($dir);
        LocalFile::removeDirRecursive($dir, $context);
    }

    public function localFileGetContents(string $path, bool $useIncludePath = false, $context = null): ?string
    {
        PluginFileAccessGuard::assertCallerPathAlways($path, true);
        $raw = LocalFile::getContents($path, $useIncludePath, $context);

        return is_string($raw) ? $raw : null;
    }

    public function siteUrlDocument(int $documentId, string $htmlName = ''): string
    {
        return SiteUrl::document($documentId, $htmlName);
    }

    public function siteUrlPageByTpl(string $tpl): string
    {
        return SiteUrl::pageByTpl($tpl);
    }

    public function siteUrlTag(string $slug): string
    {
        return SiteUrl::tag($slug);
    }

    public function siteUrlMemberLogin(string $redirect = ''): string
    {
        return SiteUrl::memberLogin($redirect);
    }

    public function siteUrlMemberRecharge(): string
    {
        return SiteUrl::memberRecharge();
    }

    public function siteUrlHome(): string
    {
        return SiteUrl::home();
    }

    public function siteUrlAdminSpa(string $spaPath = '/dashboard/welcome'): string
    {
        return SiteUrl::adminSpa($spaPath);
    }

    public function siteUrlMemberOAuthCallback(string $provider): string
    {
        return SiteUrl::memberOAuthCallback($provider);
    }

    public function moneyMathToFloat(string $amount): float
    {
        return MoneyMath::toFloat($amount);
    }

    public function moneyMathWithinTolerance(float $paid, float $expected): bool
    {
        return MoneyMath::withinTolerance($paid, $expected);
    }

    public function moneyMathFormatPlain(float|string|int $value): string
    {
        return MoneyMath::formatPlain($value);
    }

    public function seedExecutionShouldSkip(string $key): bool
    {
        return SeedExecutionGuard::shouldSkip($key);
    }

    public function pvPublicAssetJs(string $relativePath): string
    {
        return PvPublicAsset::js($relativePath);
    }

    public function dbTableExists(string $table): bool
    {
        return DbTable::tableExists($table);
    }

    public function dbTableQuery(string $table): \think\db\Query
    {
        $caller = PluginGatewayCallerContext::currentIdentifier();
        if ($caller !== null && $caller !== '') {
            // 第三方始终校验表归属（此入口原先完全绕过 DataAccessGuard）
            PluginDataAccessGuard::assertWritableTable($caller, $table);
        }

        return DbTable::query($table);
    }

    /**
     * @param list<string>|array<string, string> $fields
     * @return array<string, mixed>
     */
    public function modelRelationMergeBelongsTo(mixed $model, string $relation, array $fields): array
    {
        return ModelRelationLoad::mergeBelongsTo($model, $relation, $fields);
    }

    public function resultOk(mixed $data = null, string $message = '', ?array $meta = null): ServiceResult
    {
        return ServiceResult::ok($data, $message, $meta);
    }

    public function resultFail(
        string $message,
        mixed $data = null,
        ?array $meta = null,
    ): ServiceResult {
        return ServiceResult::fail($message, \app\common\enum\ApiErrorCode::VALIDATION, $data, $meta);
    }

    public function resultFailCode(
        string $message,
        \app\common\enum\ApiErrorCode $code,
        mixed $data = null,
        ?array $meta = null,
    ): ServiceResult {
        return ServiceResult::fail($message, $code, $data, $meta);
    }

    public function clientErrorMessageFromThrowable(\Throwable $e): string
    {
        return \app\common\support\ClientErrorMessage::fromThrowable($e);
    }

    /** @param list<mixed> $list */
    public function resultList(array $list, int $total, array $meta = []): ServiceResult
    {
        return ServiceResult::list($list, $total, $meta);
    }

    /** @param array<string, mixed> $extra */
    public function resultForbidden(string $message, array $extra = []): ServiceResult
    {
        return ServiceResult::forbidden($message, $extra);
    }

    public function resultNotFound(string $message, mixed $data = null): ServiceResult
    {
        return ServiceResult::notFound($message, $data);
    }

    public function resultDuplicate(mixed $data, string $message = '文件已存在'): ServiceResult
    {
        return ServiceResult::duplicate($data, $message);
    }

    public function resultRateLimited(string $message = '请求过于频繁，请稍后再试'): ServiceResult
    {
        return ServiceResult::rateLimited($message);
    }

    /** @param array<string, mixed> $extra */
    public function resultAuthRequired(string $message, string $redirect, array $extra = []): ServiceResult
    {
        return ServiceResult::authRequired($message, $redirect, $extra);
    }

    public function apiFromServiceResult(ServiceResult $result, int $httpStatus = 200): Response
    {
        return ApiResponse::fromServiceResult($result, $httpStatus);
    }

    public function apiSuccess(mixed $data = null, ?array $meta = null): \think\response\Json
    {
        return ApiResponse::success($data, $meta);
    }

    public function apiMethodNotAllowed(string $message = '请使用 POST'): Response
    {
        return ApiResponse::methodNotAllowed($message);
    }

    public function apiCsrfExpired(string $message = '表单已过期，请刷新页面后重试'): Response
    {
        return ApiResponse::csrfExpired($message);
    }

    public function apiFailValidation(string $message): Response
    {
        return ApiResponse::failCode(\app\common\enum\ApiErrorCode::VALIDATION, $message);
    }

    public function portalViewEsc(mixed $value): string
    {
        return \app\common\service\plugin\extension\HostPortalAccountView::esc($value);
    }

    public function siteUrlCommerceMall(
        int $page = 1,
        string $keyword = '',
        int $merchantId = 0,
        string $tag = '',
        string $itemType = '',
        string $sort = '',
    ): string {
        return SiteUrl::commerceMall($page, $keyword, $merchantId, $tag, $itemType, $sort);
    }

    public function adminApiFromResult(ServiceResult $result, int $httpStatus = 0): \think\response\Json
    {
        return AdminApiResponse::fromResult($result, $httpStatus);
    }

    /** @param array<string, mixed> $page */
    public function adminApiList(array $page, int $httpStatus = 200): \think\response\Json
    {
        return AdminApiResponse::list($page, $httpStatus);
    }

    public function adminApiAdmin(ServiceResult $payload, int $httpStatus = 200): \think\response\Json
    {
        return AdminApiResponse::admin($payload, $httpStatus);
    }

    public function adminApiFail(string $msg, int $httpStatus = 422): \think\response\Json
    {
        return AdminApiResponse::fail($msg, $httpStatus);
    }

    /** @param array<string, mixed> $extra */
    public function adminApiAuthExpired(string $msg, string $redirect, int $httpStatus = 401, array $extra = []): \think\response\Json
    {
        return AdminApiResponse::authExpired($msg, $redirect, $httpStatus, $extra);
    }

    /** @return list<int> */
    public function adminBatchParsePostIds(): array
    {
        return AdminBatchSupport::parsePostIds();
    }

    public function projectPathsRoot(): string
    {
        return ProjectPaths::root();
    }

    public function projectPathsRuntimeDir(): string
    {
        return ProjectPaths::runtimeDir();
    }

    /** @return array<string, mixed>|null */
    public function memberOauthBindingFindByProviderUid(string $provider, string $providerUid): ?array
    {
        $row = \app\common\model\UserOauthBinding::where('provider', $provider)
            ->where('provider_uid', $providerUid)
            ->find();

        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function memberOauthBindingsForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $rows = \app\common\model\UserOauthBinding::where('user_id', $userId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function memberOauthBindingUpsert(int $userId, string $provider, string $providerUid, array $profile = []): \app\common\support\ServiceResult
    {
        $userId = max(0, $userId);
        $provider = strtolower(trim($provider));
        $providerUid = trim($providerUid);
        if ($userId < 1 || $provider === '' || $providerUid === '') {
            return $this->resultFail('绑定参数无效');
        }
        $taken = \app\common\model\UserOauthBinding::where('provider', $provider)
            ->where('provider_uid', $providerUid)
            ->find();
        if ($taken !== null && (int) ($taken['user_id'] ?? 0) !== $userId) {
            return $this->resultFail('该第三方账号已绑定其他会员');
        }
        $existing = \app\common\model\UserOauthBinding::where('user_id', $userId)
            ->where('provider', $provider)
            ->find();
        $now = \app\common\support\AppTime::now();
        $nick = mb_substr(trim((string) ($profile['nickname'] ?? '')), 0, 100);
        $avatar = mb_substr(trim((string) ($profile['avatar'] ?? '')), 0, 255);
        $payload = [
            'provider_uid' => $providerUid,
            'nickname'     => $nick !== '' ? $nick : null,
            'avatar'       => $avatar !== '' ? $avatar : null,
            'extra_json'   => json_encode($profile['extra'] ?? [], JSON_UNESCAPED_UNICODE),
            'updated_at'   => $now,
        ];
        if ($existing !== null) {
            \app\common\model\UserOauthBinding::where('id', (int) $existing['id'])->update($payload);

            return $this->resultOk(null, '已更新绑定');
        }
        \app\common\model\UserOauthBinding::insert(array_merge($payload, [
            'user_id'    => $userId,
            'provider'   => $provider,
            'created_at' => $now,
        ]));

        return $this->resultOk(null, '绑定成功');
    }

    public function memberOauthBindingDelete(int $userId, string $provider): \app\common\support\ServiceResult
    {
        $userId = max(0, $userId);
        $provider = strtolower(trim($provider));
        if ($userId < 1 || $provider === '') {
            return $this->resultFail('解绑参数无效');
        }
        $n = \app\common\model\UserOauthBinding::where('user_id', $userId)
            ->where('provider', $provider)
            ->delete();
        if ($n < 1) {
            return $this->resultFail('未找到该绑定');
        }

        return $this->resultOk(null, '已解绑');
    }

    public function configForgetRequestCache(): void
    {
        $this->config->forgetRequestCache();
    }

    /**
     * @param iterable<mixed> $models
     * @param list<string>|array<string, string> $fieldMap
     * @return list<array<string, mixed>>
     */
    public function modelRelationMapBelongsTo(iterable $models, string $relation, array $fieldMap): array
    {
        return ModelRelationLoad::mapBelongsTo($models, $relation, $fieldMap);
    }

    /**
     * @param list<int>|array<int, int> $userIds
     * @return array<int, array{id:int, username:string, nickname:string}>
     */
    public function modelRelationIndexUsersBasicByIds(array $userIds): array
    {
        return ModelRelationLoad::indexUsersBasicByIds($userIds);
    }

    /**
     * 短信发送（插件经 Gateway；通道未开 / 未配置时返回 fail，调用方勿阻断主流程）
     *
     * @param array<string, mixed> $context
     */
    public function smsSend(string $mobile, string $content, array $context = []): ServiceResult
    {
        return app(\app\common\service\sms\SmsService::class)->send($mobile, $content, $context);
    }
}
