<?php
/**
 * 后台 REST 搜索域路由（/api/v1/admin/search/*）
 */
declare(strict_types=1);

use app\admin\controller\system\SearchConfig;
use app\admin\controller\system\SearchIndex;
use app\admin\controller\system\SearchQueryLog;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$search = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'search',
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
    $search('GET', 'search/config', [SearchConfig::class, 'index'], 'searchconfig', 'index'),
    $search('POST', 'search/config', [SearchConfig::class, 'save'], 'search', 'configsave'),
    $search('GET', 'search/engine-status', [SearchIndex::class, 'engineStatus'], 'search', 'enginestatus'),
    $search('POST', 'search/reindex', [SearchIndex::class, 'reindex'], 'search', 'reindex'),
    $search('POST', 'search/drain-queue', [SearchIndex::class, 'drainQueue']),
    $search('POST', 'search/retry-failed-queue', [SearchIndex::class, 'retryFailedQueue']),
    $search('GET', 'search/rebuild-search-text/status', [SearchIndex::class, 'rebuildSearchTextStatus']),
    $search('POST', 'search/rebuild-search-text/batch', [SearchIndex::class, 'rebuildSearchTextBatch']),
    $search('GET', 'search/query-log', [SearchQueryLog::class, 'index'], 'searchquerylog', 'index'),
    $search('POST', 'search/query-log/append-synonym', [SearchQueryLog::class, 'appendSynonym']),
];
