<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\SiteUrl;
use think\facade\Cache;

use app\common\service\user\PermissionService;

/** 后台预览受限文档：短时 HMAC 令牌，替代裸 ?preview=1 */
class DocumentPreviewService
{

    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    private const CACHE_PREFIX = 'doc_preview:';
    private const TTL_SECONDS  = 600;

    /** 为后台预览签发短时文档令牌 */
    public function issueToken(int $documentId, int $adminUserId): ?string
    {
        if ($documentId < 1 || $adminUserId < 1) {
            return null;
        }
        if (!$this->permissions->hasBackofficeRole($adminUserId)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        Cache::set(self::CACHE_PREFIX . $token, [
            'document_id'   => $documentId,
            'admin_user_id' => $adminUserId,
        ], self::TTL_SECONDS);

        return $token;
    }

    /** 带完整 URL 的预览链接 */
    public function previewUrl(int $documentId, int $adminUserId): ?string
    {
        $token = $this->issueToken($documentId, $adminUserId);
        if ($token === null) {
            return null;
        }

        return SiteUrl::document($documentId) . '?preview_token=' . rawurlencode($token);
    }

    /** 校验 preview_token（可选校验 documentId） */
    public function validateToken(string $token, int $documentId = 0): bool
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return false;
        }

        $payload = Cache::get(self::CACHE_PREFIX . $token);
        if (!is_array($payload)) {
            return false;
        }

        $docId = (int) ($payload['document_id'] ?? 0);
        if ($docId < 1) {
            return false;
        }
        if ($documentId > 0 && $docId !== $documentId) {
            return false;
        }

        return $this->permissions->hasBackofficeRole((int) ($payload['admin_user_id'] ?? 0));
    }

    public function consumeIfValid(string $token, int $documentId = 0): bool
    {
        if (!$this->validateToken($token, $documentId)) {
            return false;
        }
        Cache::delete(self::CACHE_PREFIX . trim($token));

        return true;
    }
}
