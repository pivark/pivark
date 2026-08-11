<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 文档保存后 — 各 document-addon 插件数据同步（Registry 发现，禁止内核硬编码 field→id 表）
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\service\plugin\registry\PluginExtensionRegistry;
use app\common\service\event\EventBusService;
use app\common\service\member\MemberConfigService;
use app\common\support\ServiceResult;

class PluginDocumentSaveService
{
    public function __construct(
        private readonly PluginEditorSurfaceService $pluginEditorSurfaceService,
    ) {
    }

    /**
     * @return list<array{field:string,identifier:string}>
     */
    public function postFields(): array
    {
        return app(PluginExtensionRegistry::class)->documentAddonSaveFieldMap();
    }

    /**
     * @param array<string, mixed> $post 控制器传入的 POST 体（禁止 Service 内读 Request）
     */
    public function syncAfterSave(int $documentId, bool $forMember = false, array $post = []): ServiceResult
    {
        if ($documentId < 1) {
            return ServiceResult::ok(null, 'ok');
        }

        $sync = app(PluginExtensionRegistry::class)->dispatchDocumentAddonSave(
            $documentId,
            $post,
            $forMember,
            fn (string $identifier): bool => $this->pluginEditorSurfaceService->isEnabledInEditor($identifier),
            fn (string $identifier): bool => app(MemberConfigService::class)->isDocumentPluginOpenForMember($identifier),
        );
        if (!$sync->isOk()) {
            return $sync;
        }

        app(EventBusService::class)->dispatch('document.after_save', [
            'document_id' => $documentId,
            'user_id'     => (int) ($post['user_id'] ?? 0),
        ]);

        return ServiceResult::ok(null, 'ok');
    }
}
