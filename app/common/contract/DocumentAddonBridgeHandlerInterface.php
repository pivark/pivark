<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/**
 * document-addon 插件在 weapp/{id}/ 实现的桥接契约。
 *
 * 内核只通过 PluginExtensionRegistry dispatch，不出现 per-plugin Service 文件。
 */
interface DocumentAddonBridgeHandlerInterface
{
    public function identifier(): string;

    public function isEnabled(): bool;

    /**
     * 文档发布页 SPA/iframe 预填数据（由插件在 weapp 实现；内核不写 per-id match）。
     *
     * @return array<string, mixed>
     */
    public function documentEditorSpaPayload(int $documentId): array;
}
