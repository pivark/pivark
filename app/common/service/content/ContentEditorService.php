<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\content;

use app\common\service\config\ConfigService;

/**
 * 后台内容编辑器类型（全站配置 content_editor）
 */
class ContentEditorService
{
    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    /** 富文本 HTML（Vditor 所见即所得） */
    public const RICH = 'rich';

    /** 富文本 HTML（TipTap · 仓内 @pivark/plugins/tiptap） */
    public const TIPTAP = 'tiptap';

    /** Markdown 源码（Vditor） */
    public const MARKDOWN = 'markdown';

    /**
     * 将历史值规范为 rich | tiptap | markdown
     */
    public function normalize(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === self::MARKDOWN || $v === 'vditor' || $v === 'md') {
            return self::MARKDOWN;
        }
        if ($v === self::TIPTAP) {
            return self::TIPTAP;
        }
        if ($v === self::RICH || $v === 'html' || $v === 'wangeditor') {
            // 历史 wangeditor 名漂 → 默认 Vditor 富文本（官方 wang 已停更，不新建接入）
            return self::RICH;
        }
        if (in_array($v, ['ueditor_new', 'ueditor_old', 'ueditor', 'ckeditor'], true)) {
            return self::RICH;
        }

        return self::TIPTAP;
    }

    public function current(): string
    {
        return $this->normalize((string) $this->config->get('content_editor', self::TIPTAP));
    }

    public function isMarkdown(string $mode): bool
    {
        return $this->normalize($mode) === self::MARKDOWN;
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return [
            self::TIPTAP   => '富文本（TipTap）',
            self::RICH     => '富文本（Vditor 所见即所得）',
            self::MARKDOWN => 'Markdown（Vditor）',
        ];
    }
}
