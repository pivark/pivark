<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\template\TemplateEngine;
use app\common\service\template\TemplateTagBlockOnlyService;
use app\common\service\template\TemplateTagParser;
use app\common\service\document\satellite\DocumentListTemplateService;
use app\common\support\ModelRelationLoad;
use app\common\support\SiteUrl;

/** 前台会员列表块 `{pv:member}…{/pv:member}` */
class MemberListTagService
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly TemplateTagParser $templateTagParser,
        private readonly TemplateTagBlockOnlyService $templateTagBlockOnly,
        private readonly DocumentListTemplateService $documentListTemplate,
    ) {
    }

    public function boot(): void
    {
        $this->templateEngine->registerKernelTag(
            'member',
            fn (array $attrs, array $pageVars, string $tpl = '') => $this->renderTag($attrs, $pageVars, $tpl)
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    public function renderTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        if (trim($tpl) === '') {
            return $this->templateTagBlockOnly->rejectSelfClosing(
                'member',
                '块内写 {$member_nickname}、{$member_center_url} 等'
            );
        }

        $rows = $this->loadRows($attrs);
        if ($rows === []) {
            return '';
        }

        return $this->renderMemberLoop($tpl, $rows, $pageVars, $attrs);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return list<array<string, mixed>>
     */
    private function loadRows(array $attrs): array
    {
        $limit   = max(1, min(50, (int) ($attrs['limit'] ?? 10)));
        $levelId = max(0, (int) ($attrs['level_id'] ?? $attrs['level'] ?? 0));
        $roleId  = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($roleId < 1) {
            return [];
        }

        $memberUserIds = UserRole::where('role_id', $roleId)->column('user_id');
        if ($memberUserIds === []) {
            return [];
        }

        $query = User::with(['memberLevel' => static function ($levelQuery): void {
            $levelQuery->field('id,name');
        }])
            ->whereIn('id', $memberUserIds)
            ->where('status', 1)
            ->field('id,username,nickname,avatar,member_level_id,member_points,member_growth')
            ->order('id', 'desc')
            ->limit($limit);
        if ($levelId > 0) {
            $query->where('member_level_id', $levelId);
        }

        $models = $query->select();
        if ($models->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($models as $model) {
            $row = ModelRelationLoad::mergeBelongsTo($model, 'memberLevel', ['name' => 'member_level_name']);
            $rows[] = [
                'member_id'         => (int) ($row['id'] ?? 0),
                'member_username'   => (string) ($row['username'] ?? ''),
                'member_nickname'   => (string) ($row['nickname'] ?? ''),
                'member_level_name' => (string) ($row['member_level_name'] ?? ''),
                'member_points'     => (int) ($row['member_points'] ?? 0),
                'member_growth'     => (int) ($row['member_growth'] ?? 0),
                'member_avatar'     => (string) ($row['avatar'] ?? ''),
                'member_center_url' => SiteUrl::memberCenter(),
            ];
        }

        return $rows;
    }

    /**
     * 保留 {$member_nickname} 扁平变量，并支持块内 {pv:if} 等标签。
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>      $attrs
     */
    private function renderMemberLoop(string $tpl, array $rows, array $pageVars, array $attrs): string
    {
        $listSvc    = $this->documentListTemplate;
        $startIndex = $listSvc->resolveLoopStartFromAttrs($attrs);
        $out        = '';
        foreach ($rows as $pos => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row  = $listSvc->applyLoopIndexFields($row, (int) $pos, $startIndex, $attrs);
            $vars = array_merge($pageVars, $row, ['member' => $row]);
            $chunk = $this->templateTagParser->parseTags($tpl, $vars);
            $out  .= $this->templateTagParser->applyPageVars($chunk, $vars);
        }

        return $out;
    }
}
