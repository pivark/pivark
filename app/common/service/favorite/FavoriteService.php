<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\favorite;

use app\common\enum\ApiErrorCode;
use app\common\model\Document;
use app\common\model\FavoriteAction;
use app\common\model\FavoriteStat;
use app\common\service\front\FrontAssetRegistry;
use app\common\service\front\FrontAuthService;
use app\common\service\template\TemplateEngine;
use app\common\support\AppTime;
use app\common\support\ServiceResult;
use think\facade\Request;

/** 文档点赞、收藏 */
class FavoriteService
{
    public function __construct(
        private readonly FavoriteConfigService $favoriteConfig,
        private readonly FrontAuthService $frontAuth,
        private readonly TemplateEngine $templateEngine,
        private readonly FrontAssetRegistry $frontAssetRegistry,
    ) {
    }

    public function boot(): void
    {
        if (!$this->isActive()) {
            return;
        }
        $this->templateEngine->registerKernelTag(
            'favorite',
            fn (array $attrs, array $pageVars, string $tpl) => $this->renderTag($attrs, $pageVars, $tpl)
        );
    }

    public function isActive(): bool
    {
        return $this->favoriteConfig->isOpen();
    }

    /**
     * 后台点赞收藏排行
     *
     * @return array{total:int, list:list<array<string, mixed>>}
     */
    public function listAdminRanked(int $page, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = max(1, min($limit, 100));
        $total = (int) FavoriteStat::count();
        $rows  = array_values(FavoriteStat::order('like_count', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray());
        $docIds = [];
        foreach ($rows as $row) {
            $docId = (int) ($row['document_id'] ?? 0);
            if ($docId > 0) {
                $docIds[$docId] = $docId;
            }
        }
        $titles = $docIds === []
            ? []
            : Document::whereIn('id', array_values($docIds))->column('title', 'id');
        $list = [];
        foreach ($rows as $row) {
            $docId = (int) ($row['document_id'] ?? 0);
            $row['title'] = (string) ($titles[$docId] ?? '');
            $list[] = $row;
        }

        return ['total' => $total, 'list' => $list];
    }

    /**
     * @return array{like_count:int,collect_count:int,liked:bool,collected:bool}
     */
    public function stats(int $documentId): array
    {
        $this->ensureRow($documentId);
        $row = FavoriteStat::where('document_id', $documentId)->find();
        $key = $this->visitorKey();

        return [
            'like_count'    => (int) ($row['like_count'] ?? 0),
            'collect_count' => (int) ($row['collect_count'] ?? 0),
            'liked'         => $this->hasAction($documentId, 'like', $key),
            'collected'     => $this->hasAction($documentId, 'collect', $key),
        ];
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, array{like_count:int,collect_count:int}>
     */
    public function statsForDocuments(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($documentIds === []) {
            return [];
        }
        $rows = FavoriteStat::whereIn('document_id', $documentIds)->select()->toArray();
        $out  = array_fill_keys($documentIds, ['like_count' => 0, 'collect_count' => 0]);
        foreach ($rows as $row) {
            $id = (int) ($row['document_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = [
                    'like_count'    => (int) ($row['like_count'] ?? 0),
                    'collect_count' => (int) ($row['collect_count'] ?? 0),
                ];
            }
        }

        return $out;
    }

    public function toggleLike(int $documentId): ServiceResult
    {
        return $this->toggle($documentId, 'like');
    }

    public function toggleCollect(int $documentId): ServiceResult
    {
        return $this->toggle($documentId, 'collect');
    }

    private function toggle(int $documentId, string $action): ServiceResult
    {
        if (!$this->isActive() || $documentId < 1) {
            return ServiceResult::fail('功能未启用');
        }
        if (!$this->favoriteConfig->guestAllowed() && $this->frontAuth->current() === null) {
            return ServiceResult::fail('请先登录', ApiErrorCode::AUTH_REQUIRED);
        }

        if (!Document::where('id', $documentId)->where('status', 1)->whereNull('deleted_at')->find()) {
            return ServiceResult::fail('文档不存在或不可访问');
        }

        $this->ensureRow($documentId);
        $key    = $this->visitorKey();
        $exists = $this->hasAction($documentId, $action, $key);
        $col    = $action === 'like' ? 'like_count' : 'collect_count';
        $now    = AppTime::now();
        $userId = (int) ($this->frontAuth->current()['id'] ?? 0);

        if ($exists) {
            FavoriteAction::where('document_id', $documentId)
                ->where('action', $action)
                ->where('visitor_key', $key)
                ->delete();
            FavoriteStat::where('document_id', $documentId)->dec($col)->update(['updated_at' => $now]);
        } else {
            FavoriteAction::insert([
                'document_id' => $documentId,
                'action'      => $action,
                'visitor_key' => $key,
                'user_id'     => $userId,
                'created_at'  => $now,
            ]);
            FavoriteStat::where('document_id', $documentId)->inc($col)->update(['updated_at' => $now]);
        }

        return ServiceResult::ok(['stats' => $this->stats($documentId)], 'ok');
    }

    /**
     * 模板标签 {pv:favorite document_id="123" mode="bar"}
     *
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     */
    public function renderTag(array $attrs, array $pageVars, string $tpl): string
    {
        if (!$this->isActive()) {
            return '';
        }
        $documentId = (int) ($attrs['document_id'] ?? $attrs['aid'] ?? $pageVars['document_id'] ?? 0);
        if ($documentId < 1) {
            return '';
        }
        $stats = $this->stats($documentId);
        $mode  = strtolower(trim((string) ($attrs['mode'] ?? 'counts')));

        if ($mode === 'json') {
            return htmlspecialchars(json_encode($stats, JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8');
        }

        if ($mode === 'bar') {
            $this->frontAssetRegistry->registerFavoriteBar();
            $liked     = !empty($stats['liked']);
            $collected = !empty($stats['collected']);
            $likeIcon  = $liked ? 'bi-heart-fill' : 'bi-heart';
            $collectIcon = $collected ? 'bi-bookmark-fill' : 'bi-bookmark';

            return '<div class="pv-favorite-bar" data-document-id="' . $documentId . '">'
                . '<button type="button" class="pv-favorite-bar__btn pv-favorite-bar__btn--like'
                . ($liked ? ' is-active' : '') . '" data-action="like" aria-pressed="' . ($liked ? 'true' : 'false') . '">'
                . '<i class="bi ' . $likeIcon . '" aria-hidden="true"></i>'
                . '<span class="pv-favorite-like pv-favorite-bar__count">' . (int) $stats['like_count'] . '</span>'
                . '</button>'
                . '<button type="button" class="pv-favorite-bar__btn pv-favorite-bar__btn--collect'
                . ($collected ? ' is-active' : '') . '" data-action="collect" aria-pressed="' . ($collected ? 'true' : 'false') . '">'
                . '<i class="bi ' . $collectIcon . '" aria-hidden="true"></i>'
                . '<span class="pv-favorite-collect pv-favorite-bar__count">' . (int) $stats['collect_count'] . '</span>'
                . '</button>'
                . '</div>';
        }

        return '<span class="pv-favorite" data-document-id="' . $documentId . '" data-like="' . $stats['like_count']
            . '" data-collect="' . $stats['collect_count'] . '">'
            . '<span class="pv-favorite-like">' . $stats['like_count'] . '</span>'
            . ' · <span class="pv-favorite-collect">' . $stats['collect_count'] . '</span></span>';
    }

    private function ensureRow(int $documentId): void
    {
        $row = FavoriteStat::where('document_id', $documentId)->find();
        if ($row) {
            return;
        }
        FavoriteStat::insert([
            'document_id'   => $documentId,
            'like_count'    => 0,
            'collect_count' => 0,
            'updated_at'    => AppTime::now(),
        ]);
    }

    private function hasAction(int $documentId, string $action, string $visitorKey): bool
    {
        return FavoriteAction::where('document_id', $documentId)
            ->where('action', $action)
            ->where('visitor_key', $visitorKey)
            ->count() > 0;
    }

    private function visitorKey(): string
    {
        $member = $this->frontAuth->current();
        if ($member !== null) {
            return 'u:' . (int) ($member['id'] ?? 0);
        }
        $ip = (string) Request::ip();
        $ua = substr((string) Request::header('user-agent', ''), 0, 120);

        return 'g:' . substr(hash('sha256', $ip . '|' . $ua), 0, 61);
    }
}
