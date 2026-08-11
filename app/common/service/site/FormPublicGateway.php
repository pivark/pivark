<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\ServiceResult;

use app\common\model\FloatContactItem;

/**
 * 前台表单 API 可注入门面（Phase 2 DI：v1 Form）。
 */
final class FormPublicGateway
{

    /** @return array<string, mixed>|null */
    public function schemaBySlug(string $slug): ?array
    {
        $form = app(SiteFormService::class)->findBySlug($slug);
        if ($form === null) {
            return null;
        }
        unset($form['settings']['admin_email']);

        return $form;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submitPublic(array $payload): ServiceResult
    {
        return app(SiteFormService::class)->submitPublic($payload);
    }
}
