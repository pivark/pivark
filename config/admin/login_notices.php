<?php
/**
 * 后台登录弹窗 Registry
 * SSOT：admin/src/components/pivark/shell/ADMIN-LOGIN-NOTICE.md
 *
 * audience:
 *   broadcast — 有 admin.dashboard 即可
 *   personal  — 须 admin.notice.{id} + 可选 data_permission
 */
return [
    'notices' => [
        'core.update' => [
            'permission'      => 'admin.notice.core_update',
            'audience'        => 'broadcast',
            'client_only'     => true,
            'label'           => '版本更新弹窗',
        ],
        'form.pending_submissions' => [
            'provider'        => app\common\service\admin\login_notice\FormPendingLoginNoticeProvider::class,
            'permission'      => 'admin.notice.form_pending',
            'data_permission' => 'admin.form.list',
            'audience'        => 'personal',
            'label'           => '新留言弹窗',
        ],
    ],
];
