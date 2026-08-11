<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\service\plugin\extension\DocumentAddonBridgeAccess;
use app\common\contract\DocumentAddonSearchContributorInterface;
use app\common\service\hook\HookService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\service\product\ProductL1Access;

/**
 * 文档扩展型插件 → 超级搜索 SSOT
 * - 已安装且授权的 document-addon：经 Core 桥接 listForDocument + 通用文本采集
 * - product 等：专用 Contributor
 * - plugin.json document_search.contributor：自定义类
 * - Hook document.search_supplement：追加 section
 */
final class DocumentAddonSearchRegistry
{

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PluginService $plugins,
        private readonly HookService $hooks,
    ) {
    }

    private const HOOK = 'document.search_supplement';

    private const MAX_LEN = 120_000;

    /** @var list<DocumentAddonSearchContributorInterface>|null */
    private static ?array $contributors = null;

    public function plainText(int $documentId): string
    {
        $sections = $this->sectionsForDocument($documentId);
        if ($sections === []) {
            return '';
        }
        $parts = [];
        foreach ($sections as $sec) {
            $label = trim($sec['label']);
            $text  = trim($sec['text']);
            if ($text === '') {
                continue;
            }
            $parts[] = ($label !== '' ? '【' . $label . "】\n" : '') . $text;
        }
        $out = trim(implode("\n\n", $parts));
        if ($out !== '' && mb_strlen($out) > self::MAX_LEN) {
            $out = mb_substr($out, 0, self::MAX_LEN);
        }

        return $out;
    }

    /**
     * @return list<array{label:string,text:string}>
     */
    public function sectionsForDocument(int $documentId): array
    {
        if ($documentId < 1) {
            return [];
        }
        $sections = [];
        foreach ($this->contributors() as $contributor) {
            foreach ($contributor->searchSections($documentId) as $sec) {
                $text = trim($sec['text']);
                if ($text === '') {
                    continue;
                }
                $sections[] = [
                    'label' => trim($sec['label']),
                    'text'  => $text,
                ];
            }
        }

        $payload = ['document_id' => $documentId, 'sections' => &$sections];
        $this->hooks->fire(self::HOOK, $payload);

        return $sections;
    }

    /** @return list<DocumentAddonSearchContributorInterface> */
    public function attachmentContributors(): array
    {
        return $this->contributors();
    }

    /** @return list<DocumentAddonSearchContributorInterface> */
    private function contributors(): array
    {
        if (self::$contributors !== null) {
            return self::$contributors;
        }
        $list = [];
        $list[] = app(ProductDocumentAddonSearchContributor::class);
        foreach ($this->manifestBridgeContributors() as $c) {
            $list[] = $c;
        }
        foreach ($this->manifestContributors() as $c) {
            $list[] = $c;
        }
        self::$contributors = $list;

        return self::$contributors;
    }

    /** @return list<DocumentAddonSearchContributorInterface> */
    private function manifestBridgeContributors(): array
    {
        $out = [];
        foreach ($this->plugins->listInstalledIdentifiers() as $identifier) {
            $identifier = strtolower(trim($identifier));
            if ($identifier === '' || ProductL1Access::isKernel($identifier)) {
                continue;
            }
            $manifest = $this->plugins->readManifest($identifier);
            $kind     = strtolower(trim((string) ($manifest['kind'] ?? PluginManifestService::KIND_DOCUMENT_ADDON)));
            if ($kind !== PluginManifestService::KIND_DOCUMENT_ADDON) {
                continue;
            }
            if (!$this->entitlements->can($identifier)) {
                continue;
            }
            $docSearch = is_array($manifest['document_search'] ?? null) ? $manifest['document_search'] : [];
            $bridge    = is_array($docSearch['bridge'] ?? null) ? $docSearch['bridge'] : null;
            if ($bridge === null) {
                continue;
            }
            $fetchKind = trim((string) ($bridge['fetch'] ?? 'listForDocument'));
            $label     = $this->pluginLabel($identifier);
            $attach    = (bool) ($bridge['attachments'] ?? false);
            $out[]     = new BridgePayloadDocumentAddonSearchContributor(
                $label,
                $this->buildManifestBridgeFetch($identifier, $fetchKind),
                $attach,
            );
        }

        return $out;
    }

    private function buildManifestBridgeFetch(string $identifier, string $fetchKind): callable
    {
        if ($fetchKind === 'doc_vod_merge') {
            return static function (int $id) use ($identifier): array {
                $embed = DocumentAddonBridgeAccess::invoke($identifier, 'listForDocument', [$id]);
                $vod   = DocumentAddonBridgeAccess::invoke($identifier, 'listVodForDocument', [$id]);

                return array_merge(is_array($embed) ? $embed : [], is_array($vod) ? $vod : []);
            };
        }

        return static fn (int $id): array => DocumentAddonBridgeAccess::invoke($identifier, 'listForDocument', [$id]);
    }

    /** @return list<DocumentAddonSearchContributorInterface> */
    private function manifestContributors(): array
    {
        $out = [];
        foreach ($this->plugins->listInstalledIdentifiers() as $identifier) {
            $identifier = strtolower(trim($identifier));
            if ($identifier === '' || ProductL1Access::isKernel($identifier)) {
                continue;
            }
            $manifest = $this->plugins->readManifest($identifier);
            $kind     = strtolower(trim((string) ($manifest['kind'] ?? PluginManifestService::KIND_DOCUMENT_ADDON)));
            if ($kind !== PluginManifestService::KIND_DOCUMENT_ADDON) {
                continue;
            }
            if (!$this->entitlements->can($identifier)) {
                continue;
            }
            $docSearch = is_array($manifest['document_search'] ?? null) ? $manifest['document_search'] : [];
            if (is_array($docSearch['bridge'] ?? null)) {
                continue;
            }
            $class = trim((string) ($docSearch['contributor'] ?? ''));
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            $obj = new $class();
            if ($obj instanceof DocumentAddonSearchContributorInterface) {
                $out[] = $obj;
            }
        }

        return $out;
    }

    private function pluginLabel(string $identifier): string
    {
        $manifest = $this->plugins->readManifest($identifier);

        return trim((string) ($manifest['name'] ?? $identifier));
    }

    /** 测试重置 */
    public function resetForTests(): void
    {
        self::$contributors = null;
    }
}
