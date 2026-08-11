<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\contract\DocumentAddonSearchContributorInterface;
use Closure;

/** 通过 listForDocument 类桥接拉取插件 payload，再用通用采集器抽文本 */
final class BridgePayloadDocumentAddonSearchContributor implements DocumentAddonSearchContributorInterface
{
    private readonly Closure $fetcher;

    /** @param callable(int): mixed $fetcher */
    public function __construct(
        private readonly string $label,
        callable $fetcher,
        private readonly bool $collectAttachments = false,
    ) {
        $this->fetcher = $fetcher instanceof Closure ? $fetcher : Closure::fromCallable($fetcher);
    }

    public function searchSections(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        $payload = ($this->fetcher)($documentId);
        $lines   = app(DocumentAddonPlainTextCollector::class)->linesFromPayload($payload);
        if ($lines === []) {
            return [];
        }

        return [['label' => $this->label, 'text' => implode("\n", $lines)]];
    }

    public function searchAttachments(int $documentId): array
    {
        if ($documentId < 1 || !$this->collectAttachments) {
            return [];
        }

        return app(DocumentAddonAttachmentRegistry::class)->filesFromPayload(
            ($this->fetcher)($documentId),
            $documentId,
        );
    }
}
