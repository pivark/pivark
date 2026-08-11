<?php
declare(strict_types=1);

namespace weapp\doc_comment\service;

use app\common\service\weapp\WeappAdminGateway;

/** comment 后台 SPA 路由（从 PivarkVueRoute 迁出） */
final class CommentAdminSpaRoutes
{
    public static function register(): void
    {
        $gw = app(WeappAdminGateway::class);
        $id = 'doc_comment';

        $gw->adminSpaHostRegisterPluginPage(
            $id,
            'settings',
            'WeappDocCommentSettings',
            ['title' => '基础设置'],
        );
        $gw->adminSpaHostRegisterPluginPage(
            $id,
            'list',
            'WeappDocCommentList',
            ['title' => '评论列表'],
        );

        foreach (['usage' => ['WeappDocCommentUsage', '前台调用说明', '/weapp/usage/index'], 'guide' => ['WeappDocCommentGuide', '功能介绍', '/weapp/guide/index']] as $seg => [$name, $title, $component]) {
            $path = $gw->adminSpaHostPath($id, $seg);
            $gw->adminSpaExplicitRouteRegister($path, [
                'name'      => $name,
                'path'      => $path,
                'component' => $component,
                'meta'      => ['pivarkWeapp' => $id, 'title' => $title],
            ]);
        }

        $gw->adminSpaRouteIconRegister($gw->adminSpaHostPath($id, 'settings'), 'lucide:message-square');
        $gw->adminSpaRouteIconRegister($gw->adminSpaHostPath($id, 'list'), 'lucide:list');
        $gw->adminWeappNavTabsRegister($id, [
            $gw->adminWeappNavTab('settings', '基础设置', 'settings'),
            $gw->adminWeappNavTab('list', '评论管理', 'list'),
        ]);
    }
}
