<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\ai\DocumentPlainTextService;

use app\common\model\Document;
use app\common\service\ai\LlmChatService;
use app\common\service\config\AiConfigService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\support\HtmlSanitizer;
use app\common\service\tag\TagService;
use think\facade\Db;

/** 文档保存后 AI 生成摘要 / TDK / 标签 */
class AIMetadataService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly DocumentPlainTextService $documentPlainTextService,
        private readonly LlmChatService $llmChatService,
        private readonly TagService $tagService,
    ) {
    }

    private static bool $running = false;

    /**
     * @return ServiceResult
     */
    public function applyForDocument(int $documentId, bool $onlyEmpty = true): ServiceResult
    {
        if (!!$this->aiConfigService->autoMetadataOnSave() || !$this->aiConfigService->activeProviderConfigured()) {
            return ServiceResult::fail('AI 自动元数据未启用');
        }
        if ($documentId < 1) {
            return ServiceResult::fail('参数错误');
        }
        if (self::$running) {
            return ServiceResult::fail('处理中');
        }

        $row = Document::where('id', $documentId)->whereNull('deleted_at')->find()?->toArray();
        if (!$row) {
            return ServiceResult::fail('文档不存在');
        }

        $plain = $this->documentPlainTextService->fromDocumentRow($row);
        if ($plain === '') {
            return ServiceResult::fail('正文为空，跳过 AI 分析');
        }

        self::$running = true;
        try {
            $gen = $this->llmChatService->generateDocumentMeta($plain, (string) ($row['title'] ?? ''));
            if (!$gen->isOk() || !is_array($gen['data'] ?? null)) {
                return ServiceResult::fail((string) ($gen->message() ?? 'AI 生成失败'));
            }
            $data    = $gen['data'];
            $updated = [];
            $patch   = ['updated_at' => AppTime::now()];

            $fill = static function (string $field, string $value, int $maxLen) use ($row, $onlyEmpty, &$patch, &$updated): void {
                $value = HtmlSanitizer::cleanPlainText($value, $maxLen);
                if ($value === '') {
                    return;
                }
                $cur = trim((string) ($row[$field] ?? ''));
                if ($onlyEmpty && $cur !== '') {
                    return;
                }
                $patch[$field] = $value;
                $updated[]     = $field;
            };

            $fill('summary', (string) ($data['summary'] ?? ''), 500);
            $fill('seo_title', (string) ($data['seo_title'] ?? ''), 200);
            $fill('seo_keywords', (string) ($data['seo_keywords'] ?? ''), 500);
            $fill('seo_description', (string) ($data['seo_description'] ?? ''), 500);

            $title = HtmlSanitizer::cleanPlainText((string) ($data['title'] ?? ''), 200);
            if ($title !== '' && (!$onlyEmpty || trim((string) ($row['title'] ?? '')) === '')) {
                $patch['title'] = $title;
                $updated[]      = 'title';
            }

            if (count($patch) > 1) {
                Document::where('id', $documentId)->whereNull('deleted_at')->update($patch);
            }

            $tags = $data['tags'] ?? [];
            if (is_array($tags) && $tags !== []) {
                $existing = $this->tagService->getTagsForDocument($documentId);
                if (!$onlyEmpty || $existing === []) {
                    $names = [];
                    foreach ($tags as $t) {
                        $name = trim(is_string($t) ? $t : (string) ($t['name'] ?? ''));
                        if ($name !== '') {
                            $names[] = $name;
                        }
                    }
                    if ($names !== []) {
                        $this->tagService->syncDocumentTags($documentId, implode(',', $names));
                        $updated[] = 'tags';
                    }
                }
            }

            if ($updated === []) {
                return ServiceResult::ok(['updated' => []], '无需更新（字段已有内容）');
            }

            return ServiceResult::ok(['updated' => $updated], '已更新 ' . implode('、', $updated));
        } finally {
            self::$running = false;
        }
    }

}
