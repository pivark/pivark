<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\site\SiteModeService;
use think\facade\Log;

/** 禁止 {pv:* /} 与空块体内置 HTML；须写 {pv:tag}…{/pv:tag} 或 foreach/include */
class TemplateTagBlockOnlyService
{

    public function rejectSelfClosing(string $tag, string $hint = ''): string
    {
        if ($hint === '') {
            $hint = 'pv:' . $tag . ' 块标签（须写开闭标签体，禁止 /）';
        }

        if (!app(SiteModeService::class)->isDev()) {
            Log::warning('template_tag_block_only_rejected tag=' . $tag . ' hint=' . $hint);

            return '';
        }

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!-- pv:' . $h($tag) . ' 禁止自闭合/空体内置 HTML；' . $h($hint) . ' -->';
    }

    public function rejectEmptyBlockBody(string $tag, string $hint = ''): string
    {
        return $this->rejectSelfClosing($tag, $hint);
    }
}
