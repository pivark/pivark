<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappFrontGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\model\Document;
use app\common\model\User;
use app\common\service\front\FrontCsrfService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\front\FrontScriptUrlRegistry;
use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontAssetRegistry;
use app\common\service\front\FrontPluginPathRegistry;
use app\common\service\front\FrontEarlyResponseRegistry;
use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\service\front\PluginFrontAssetRegistry;
use app\common\service\front\FrontUrlRuleService;
use app\common\service\watermark\WatermarkService;

final class WeappFrontGateway
{

    public function __construct(
        private readonly FrontAuthService $frontAuth,
        private readonly FrontUrlRuleService $frontUrlRule,
        private readonly WatermarkService $watermark,
        private readonly FrontCsrfService $frontCsrf,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly PluginFrontAssetRegistry $pluginFrontAsset,
        private readonly FrontAssetRegistry $frontAsset,
        private readonly FrontPluginPathRegistry $frontPluginPath,
        private readonly FrontEarlyResponseRegistry $frontEarlyResponse,
    ) {
    }

    public function frontIsLoggedIn(): bool
    {
        return $this->frontAuth->isLoggedIn();
    }

    /** @return array<string, mixed>|null */
    public function frontCurrent(): ?array
    {
        return $this->frontAuth->current();
    }

    public function frontMemberId(): int
    {
        $member = $this->frontAuth->current();

        return $member !== null ? (int) ($member['id'] ?? 0) : 0;
    }

    public function frontLoginRedirectUrl(string $returnUrl = ''): string
    {
        return $this->frontAuth->loginRedirectUrl($returnUrl);
    }

    /** @param array<string, mixed>|\app\common\model\Document $document */
    public function frontCanReadDocument(array|\app\common\model\Document $document): bool
    {
        return $this->frontAuth->canReadDocument($document);
    }

    /** @param array<string, mixed> $memberRow */
    public function frontEstablishSessionFromMemberRow(array $memberRow): void
    {
        $userId = (int) ($memberRow['id'] ?? 0);
        if ($userId < 1) {
            return;
        }
        $user = User::find($userId);
        if ($user !== null) {
            $this->frontAuth->establishSession($user);
        }
    }

    /** @param array<string, mixed> $doc */
    public function frontBuildDocumentUrl(array $doc): string
    {
        return $this->frontUrlRule->buildDocument($doc);
    }

    public function watermarkMaybeApply(string $filePath, string $format = 'jpg'): void
    {
        $this->watermark->maybeApply($filePath, $format);
    }

    public function frontCsrfFieldName(): string
    {
        return $this->frontCsrf->fieldName();
    }

    public function frontCsrfToken(): string
    {
        return $this->frontCsrf->token();
    }

    public function frontCsrfValidateRequest(string $tokenFromBody, ?string $tokenFromHeader): bool
    {
        return $this->frontCsrf->validateRequest($tokenFromBody, $tokenFromHeader);
    }

    public function frontCsrfRotateAfterSuccess(): void
    {
        $this->frontCsrf->rotateAfterSuccess();
    }

    public function frontCacheInvalidateDocuments(): void
    {
        $this->frontCacheInvalidator->invalidateDocuments();
    }

    public function frontCacheInvalidateAll(bool $purgeThinkCacheFiles = false): void
    {
        $this->frontCacheInvalidator->invalidateAll($purgeThinkCacheFiles);
    }

    public function frontAssetHandlerRegister(string $identifier, string $action, callable $handler): void
    {
        $this->pluginFrontAsset->register($identifier, $action, $handler);
    }

    /** @param array<string, string> $urls */
    public function frontScriptUrlRegisterModule(string $identifier, array $urls): void
    {
        app(FrontScriptUrlRegistry::class)->registerModule($identifier, $urls);
    }

    public function frontScriptUrlRegisterCore(string $key, string $url): void
    {
        app(FrontScriptUrlRegistry::class)->registerCore($key, $url);
    }

    public function frontAssetRegisterComment(): void
    {
        $this->pluginFrontAsset->dispatchForAction('widget');
    }

    /** @param list<array<string, mixed>> $bundles */
    public function frontAssetRegisterDocBundles(array $bundles, ?string $identifier = null): void
    {
        if ($identifier === null || $identifier === '') {
            return;
        }
        if (!DocumentAddonBridgeAccess::isEnabled($identifier)) {
            return;
        }
        $this->pluginFrontAsset->dispatch($identifier, 'bundles', $bundles);
    }

    public function frontAssetDispatchPlugin(string $pluginId, string $action, mixed ...$args): void
    {
        $this->pluginFrontAsset->dispatch($pluginId, $action, ...$args);
    }

    public function frontAssetRegisterCommerceModule(string $pluginId): void
    {
        $this->frontAsset->registerWeappCommerceModule($pluginId);
    }

    public function frontAssetRegisterCommerceScript(string $pluginId, string $handle, string $relativePath): void
    {
        $this->frontAsset->registerWeappCommerceScript($pluginId, $handle, $relativePath);
    }

    public function frontAssetRegisterWeappStylesheet(string $pluginId, string $handle, string $relativePath): void
    {
        $this->frontAsset->registerWeappStylesheet($pluginId, $handle, $relativePath);
    }

    public function frontAssetRegisterWeappScript(string $pluginId, string $handle, string $relativePath): void
    {
        $this->frontAsset->registerWeappScript($pluginId, $handle, $relativePath);
    }

    /** 前台 URL 模块标记（插件不得直引 FrontAssetRegistry） */
    public function frontAssetRegisterUrlModule(string $module): void
    {
        $this->frontAsset->registerUrlModule($module);
    }

    public function frontAssetRegisterVideoVodStyles(): void
    {
        $this->pluginFrontAsset->dispatchForAction('vod_styles');
    }

    public function frontAssetRegisterVideoVod(): void
    {
        $this->pluginFrontAsset->dispatchForAction('vod');
    }

    public function frontAssetRegisterTalentCards(): void
    {
        $this->pluginFrontAsset->dispatchForAction('cards');
    }

    /** @param callable(string): (\think\Response|null) $handler */
    public function frontPluginPathRegister(string $pathPrefix, callable $handler): void
    {
        $this->frontPluginPath->register($pathPrefix, $handler);
    }

    /** @param callable(): (\think\Response|null) $handler */
    public function frontEarlyResponderRegister(callable $handler, ?string $identifier = null): void
    {
        $this->frontEarlyResponse->register($handler, $identifier);
    }

    public function frontTryEarlyResponse(): ?\think\Response
    {
        return $this->frontEarlyResponse->tryResponse();
    }
}
