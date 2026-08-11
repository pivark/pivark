<?php
/**
 * 后台 REST 系统域路由（/api/v1/admin/users|roles|menus|config|media|upload|logs|backups）
 */
declare(strict_types=1);

use app\admin\controller\system\Backup;
use app\admin\controller\system\Config;
use app\admin\controller\system\Log;
use app\admin\controller\system\Media;
use app\admin\controller\system\Menu;
use app\admin\controller\system\Role;
use app\admin\controller\system\Upload;
use app\admin\controller\system\User;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$sr = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'gateway',
    );

    return [
        'method'  => $method,
        'path'    => $path,
        'handler' => $handler,
        'options' => [
            'permission_controller' => $controller,
            'permission_action'     => $permissionAction ?? strtolower((string) $handler[1]),
        ],
    ];
};

return [
    // menus
    $sr('GET', 'menus/init', [Menu::class, 'init'], 'menu', 'init'),
    $sr('GET', 'menus/records', [Menu::class, 'index'], 'menu', 'index'),
    $sr('POST', 'menus', [Menu::class, 'save'], 'menu', 'save'),
    $sr('POST', 'menus/sort', [Menu::class, 'sort']),
    $sr('POST', 'menus/delete', [Menu::class, 'delete']),

    // users
    $sr('GET', 'users', [User::class, 'index'], 'user', 'index'),
    $sr('POST', 'users', [User::class, 'save'], 'user', 'save'),
    $sr('POST', 'users/delete', [User::class, 'delete']),
    $sr('GET', 'users/export', [User::class, 'export']),
    $sr('POST', 'users/import', [User::class, 'import']),
    $sr('GET', 'users/detail', [User::class, 'detail'], 'user', 'detail'),

    // roles
    $sr('GET', 'roles', [Role::class, 'index'], 'role', 'index'),
    $sr('POST', 'roles', [Role::class, 'save'], 'role', 'save'),
    $sr('POST', 'roles/delete', [Role::class, 'delete']),
    $sr('GET', 'roles/detail', [Role::class, 'detail'], 'role', 'detail'),

    // config
    $sr('GET', 'config', [Config::class, 'index'], 'config', 'index'),
    $sr('POST', 'config', [Config::class, 'save'], 'config', 'save'),
    $sr('POST', 'config/test-redis-cache', [Config::class, 'testRedisCache']),
    $sr('GET', 'config/cache-status', [Config::class, 'cacheStatus'], 'config', 'index'),
    $sr('POST', 'config/custom-vars', [Config::class, 'saveCustomVar']),
    $sr('POST', 'config/custom-vars/delete', [Config::class, 'deleteCustomVar']),
    $sr('POST', 'config/enable-special-chars', [Config::class, 'enableSpecialChars']),
    $sr('POST', 'config/disable-special-chars', [Config::class, 'disableSpecialChars']),
    $sr('POST', 'config/rewrite-media-urls', [Config::class, 'rewriteMediaUrls'], 'config', 'save'),
    $sr('POST', 'config/rewrite-media-urls/tick', [Config::class, 'rewriteMediaUrlsTick'], 'config', 'save'),
    $sr('GET', 'config/rewrite-media-urls/job', [Config::class, 'rewriteMediaUrlsJob'], 'config', 'save'),
    $sr('POST', 'config/rewrite-media-urls/cancel', [Config::class, 'rewriteMediaUrlsCancel'], 'config', 'save'),

    // media
    $sr('GET', 'media/meta', [Media::class, 'meta'], 'media', 'meta'),
    $sr('GET', 'media/list', [Media::class, 'list'], 'media', 'list'),
    $sr('GET', 'media/enterprise-meta', [Media::class, 'enterpriseMeta']),
    $sr('GET', 'media/enterprise-list', [Media::class, 'enterpriseList']),
    $sr('POST', 'media/enterprise-save', [Media::class, 'enterpriseSave']),
    $sr('POST', 'media/enterprise-archive', [Media::class, 'enterpriseArchive']),
    $sr('POST', 'media/enterprise-delete', [Media::class, 'enterpriseDelete']),
    $sr('POST', 'media/enterprise-batch-archive', [Media::class, 'enterpriseBatchArchive']),
    $sr('POST', 'media/enterprise-batch-delete', [Media::class, 'enterpriseBatchDelete']),
    $sr('GET', 'media/enterprise-export', [Media::class, 'enterpriseExport']),
    $sr('POST', 'media/enterprise-entity-save', [Media::class, 'enterpriseEntitySave']),
    $sr('POST', 'media/enterprise-entity-archive', [Media::class, 'enterpriseEntityArchive']),
    $sr('GET', 'media/enterprise-entity-list', [Media::class, 'enterpriseEntityList']),
    $sr('POST', 'media/enterprise-entity-restore', [Media::class, 'enterpriseEntityRestore']),
    $sr('POST', 'media/enterprise-entity-delete', [Media::class, 'enterpriseEntityDelete']),
    $sr('POST', 'media/enterprise-entity-consolidate', [Media::class, 'enterpriseEntityConsolidate']),
    $sr('POST', 'media/delete', [Media::class, 'delete']),
    $sr('POST', 'media/batch-delete', [Media::class, 'batchDelete']),
    $sr('POST', 'media/move', [Media::class, 'move']),
    $sr('GET', 'media/invalid-preview', [Media::class, 'invalidPreview']),
    $sr('POST', 'media/purge-invalid', [Media::class, 'purgeInvalid']),
    $sr('POST', 'media/purge-invalid-batch/start', [Media::class, 'purgeInvalidBatchStart']),
    $sr('POST', 'media/purge-invalid-batch/step', [Media::class, 'purgeInvalidBatchStep']),
    $sr('GET', 'media/delete-preview', [Media::class, 'deletePreview']),
    $sr('POST', 'media/delete-preview-batch', [Media::class, 'deletePreviewBatch']),

    // upload
    $sr('GET', 'upload/config', [Upload::class, 'config'], 'upload', 'config'),
    $sr('POST', 'upload/check', [Upload::class, 'check']),
    $sr('POST', 'upload/chunk-init', [Upload::class, 'chunkInit']),
    $sr('POST', 'upload/chunk-status', [Upload::class, 'chunkStatus']),
    $sr('POST', 'upload/chunk', [Upload::class, 'chunk']),
    $sr('POST', 'upload/chunk-complete', [Upload::class, 'chunkComplete']),
    $sr('POST', 'upload/image', [Upload::class, 'image']),
    $sr('POST', 'upload/file', [Upload::class, 'file']),
    $sr('POST', 'upload/video', [Upload::class, 'video']),

    // logs
    $sr('GET', 'logs', [Log::class, 'index'], 'log', 'index'),
    $sr('GET', 'logs/export', [Log::class, 'export']),
    $sr('GET', 'logs/detail', [Log::class, 'detail']),
    $sr('POST', 'logs/delete', [Log::class, 'delete']),
    $sr('POST', 'logs/cleanup', [Log::class, 'cleanup']),
    $sr('POST', 'logs/purge-all', [Log::class, 'purgeAll']),

    // backups
    $sr('GET', 'backups', [Backup::class, 'index'], 'backup', 'index'),
    $sr('GET', 'backups/tables', [Backup::class, 'tables']),
    $sr('POST', 'backups', [Backup::class, 'create']),
    $sr('POST', 'backups/jobs', [Backup::class, 'startJob']),
    $sr('POST', 'backups/jobs/tick', [Backup::class, 'tickJob']),
    $sr('GET', 'backups/jobs', [Backup::class, 'jobStatus']),
    $sr('POST', 'backups/jobs/cancel', [Backup::class, 'cancelJob']),
    $sr('POST', 'backups/jobs/restore', [Backup::class, 'startRestoreJob']),
    $sr('GET', 'backups/download', [Backup::class, 'download']),
    $sr('POST', 'backups/delete', [Backup::class, 'delete']),
    $sr('POST', 'backups/restore', [Backup::class, 'restore']),
];
