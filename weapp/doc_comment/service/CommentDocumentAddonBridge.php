<?php
/**
 * 元舟 PivArk — document-addon bridge handler（weapp SSOT）
 */
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappSupportGateway;
use app\common\service\weapp\WeappTemplateGateway;
use app\common\contract\DocumentAddonBridgeHandlerInterface;
use app\common\contract\WeappPluginBridgeTrait;
use think\facade\Db;

final class CommentDocumentAddonBridge implements DocumentAddonBridgeHandlerInterface
{
    use WeappPluginBridgeTrait;

    
    

    public function isEnabled(): bool
    {
        return $this->bridgeEnabled('doc_comment');
    }

    public function identifier(): string
    {
        return 'doc_comment';
    }
    /** @return array<string, mixed> */
    public function documentEditorSpaPayload(int $documentId): array
    {
        return [];
    }

    /** 插件 bootstrap 会 reset 标签表；评论标签须每请求重注册（同 favorite L1） */
    public function bootTemplateTag(): void
    {
        app(WeappTemplateGateway::class)->templateRegisterExtensionTag(
            'doc_comment',
            fn (array $attrs, array $pageVars, string $tpl) => $this->renderTag($attrs, $pageVars, $tpl)
        );
    }

    public function isActive(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $this->ensureLoaded();

        return \weapp\doc_comment\service\CommentService::isActive();
    }

    /** 模板 flag document_has_doc_comment（全站评论开关，与文档 id 无关） */
    public function hasAvailabilityForDocument(int $documentId): bool
    {
        unset($documentId);

        return $this->isActive();
    }

    /** 评论为全站开关：arclist has=doc_comment 开则不过滤文档 ID */
    public function availabilityIsSiteWide(): bool
    {
        return true;
    }

    /** @return array{cfg:array<string,mixed>,levels:list<array<string,mixed>>,stats:array<string,mixed>} */
    public function adminMeta(): array
    {
        $this->ensureLoaded();

        return [
            'cfg'    => \weapp\doc_comment\service\CommentConfigService::all(),
            'levels' => \weapp\doc_comment\service\CommentService::levelsForAdmin(),
            'stats'  => \weapp\doc_comment\service\CommentService::statsAdmin(),
        ];
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $context
     */
    public function renderTag(array $attrs, array $context, string $content): string
    {
        if (!$this->isActive()) {
            return '';
        }
        $this->ensureLoaded();

        return \weapp\doc_comment\service\CommentService::renderTag($attrs, $context, $content);
    }

    public function approvedStatus(): int
    {
        $this->ensureLoaded();

        return \weapp\doc_comment\service\CommentService::STATUS_APPROVED;
    }

    /** @return array{list:list<array<string,mixed>>,total:int} */
    public function listCommunityThreads(int $page = 1, int $limit = 30, string $sort = 'latest'): array
    {
        if (!$this->isActive()) {
            return ['list' => [], 'total' => 0];
        }
        $this->ensureLoaded();

        return \weapp\doc_comment\service\CommentHubService::listThreads($page, $limit, $sort);
    }

    /**
     * @param list<int> $documentIds
     * @return array{approved:int,total:int,active:bool}
     */
    public function purgeSeedForDocument(int $documentId): void
    {
        if ($documentId < 1 || !$this->isActive()) {
            return;
        }
        $this->ensureLoaded();
        CommentWwwSeedService::purgeForDocument($documentId);
    }

    /**
     * @param list<array<string,mixed>> $comments
     */
    public function seedCommentsForDocument(int $documentId, array $comments): void
    {
        if ($documentId < 1 || $comments === [] || !$this->isActive()) {
            return;
        }
        $this->ensureLoaded();
        CommentWwwSeedService::seedComments($documentId, $comments);
    }

    public function countForDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($documentIds === [] || !$this->isActive()) {
            return ['approved' => 0, 'total' => 0, 'active' => false];
        }

        try {
            return [
                'approved' => (int) Db::name('weapp_doc_comment_comments')
                    ->whereIn('document_id', $documentIds)
                    ->where('status', $this->approvedStatus())
                    ->count(),
                'total'    => (int) Db::name('weapp_doc_comment_comments')
                    ->whereIn('document_id', $documentIds)
                    ->count(),
                'active'   => true,
            ];
        } catch (\Throwable $e) {
            app(WeappSupportGateway::class)->kernelOpsLog('document_comment_stats_failed', [
                'document_ids' => count($documentIds),
                'msg'          => $e->getMessage(),
            ]);

            return ['approved' => 0, 'total' => 0, 'active' => true];
        }
    }

    private function ensureLoaded(): void
    {
        $this->registerWeappAutoload('doc_comment');
        \weapp\doc_comment\service\CommentService::ensureAutoload();
    }
}
