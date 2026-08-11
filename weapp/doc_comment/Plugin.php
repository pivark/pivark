<?php
declare(strict_types=1);

namespace weapp\doc_comment;

use app\common\service\weapp\WeappAdminGateway;
use app\common\service\weapp\WeappEntitlementGateway;
use app\common\service\weapp\WeappFrontGateway;
use app\common\service\weapp\WeappMiniprogramGateway;
use app\common\service\weapp\WeappPluginGateway;
use app\common\service\weapp\WeappSiteGateway;
use app\common\service\weapp\WeappTemplateGateway;
use weapp\doc_comment\service\CommentPublicApi;
use weapp\doc_comment\service\CommentService;
use weapp\doc_comment\service\CommentAdminSpaMeta;
use weapp\doc_comment\service\CommentAdminSpaRoutes;
use weapp\doc_comment\service\CommentFrontAssets;
use weapp\doc_comment\service\CommentDocumentAddonBridge;

/** 官方评论插件 */
class Plugin
{
    public function install(): void
    {
        app(WeappEntitlementGateway::class)->entitlementGrantFreeInstall('doc_comment');
        app(WeappPluginGateway::class)->weappSchemaApply('doc_comment');
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }

    public function boot(): void
    {
        $admin = app(WeappAdminGateway::class);
        $plugin = app(WeappPluginGateway::class);
        $admin->adminSpaMetaRegister('doc_comment', 'doc_comment', [CommentAdminSpaMeta::class, 'build']);
        $plugin->pluginExtensionRegisterDocumentAddonBridge(
            'doc_comment',
            new CommentDocumentAddonBridge(),
            100,
            [
                'document_availability'   => 'doc_comment',
                'document_comment_global' => true,
            ],
        );
        CommentService::ensureAutoload();
        CommentAdminSpaRoutes::register();
        $perm = 'plugin.doc_comment.use';
        $admin->adminPermissionExtensionRegisterMap('doc_comment', [
            'index'      => $perm,
            'list'       => $perm,
            'usage'      => $perm,
            'configsave' => $perm,
            'levelsave'  => $perm,
            'review'     => $perm,
            'reviewall'  => $perm,
            'delete'     => $perm,
        ]);
        $plugin->pluginLogicalTableRegister('doc_comment', [
            'comments' => 'weapp_doc_comment_comments',
            'likes'    => 'weapp_doc_comment_likes',
            'levels'   => 'weapp_doc_comment_levels',
        ]);
        app(WeappFrontGateway::class)->frontAssetHandlerRegister('doc_comment', 'widget', [CommentFrontAssets::class, 'registerWidget']);
        app(WeappMiniprogramGateway::class)->miniprogramFeatureRegister('doc_comment', '评论', true);
        $plugin->pluginApiRegistryRegister('CommentPublic', new CommentPublicApi());
        app(WeappTemplateGateway::class)->templateRegisterExtensionTag('doc_comment', [CommentService::class, 'renderTag']);
        $plugin->pluginRouteRegister(
            'get',
            'api/v1/plugins/doc_comment/list/:document_id',
            '\\weapp\\doc_comment\\api\\controller\\Comment@list',
            ['document_id' => '\\d+']
        );
        $plugin->pluginRouteRegister('post', 'api/v1/plugins/doc_comment/add', '\\weapp\\doc_comment\\api\\controller\\Comment@add');
        $plugin->pluginRouteRegister('post', 'api/v1/plugins/doc_comment/delete', '\\weapp\\doc_comment\\api\\controller\\Comment@delete');
        $plugin->pluginRouteRegister('post', 'api/v1/plugins/doc_comment/like', '\\weapp\\doc_comment\\api\\controller\\Comment@like');
        $plugin->pluginRouteRegister(
            'get',
            'api/v1/plugins/doc_comment/tree/:document_id',
            '\\weapp\\doc_comment\\api\\controller\\Comment@tree',
            ['document_id' => '\\d+']
        );
        $plugin->pluginRouteRegister('post', 'api/v1/plugins/doc_comment/thread', '\\weapp\\doc_comment\\api\\controller\\Comment@threadCreate');
        app(WeappSiteGateway::class)->hubCapabilityRegisterCommunityHub('doc_comment');
    }
}
