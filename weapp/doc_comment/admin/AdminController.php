<?php
declare(strict_types=1);

namespace weapp\doc_comment\admin;


use weapp\doc_comment\service\CommentConfigService;
use weapp\doc_comment\model\WeappDocCommentLevel;
use app\common\service\weapp\WeappPluginGateway;
use app\common\service\weapp\WeappSupportGateway;
use app\common\contract\PluginAdminBaseTrait;
use app\common\contract\PluginAdminUsageTrait;
use think\facade\Request;
use think\Response;
use weapp\doc_comment\service\CommentService;

/** 评论插件后台（weapp 自包含） */
class AdminController
{
    use PluginAdminBaseTrait;
    use PluginAdminUsageTrait;

    public function __construct()
    {
        $this->pluginIdentifier = 'doc_comment';
    }

    public function index(): Response
    {
        if (!$this->entitled()) {
            return $this->renderDisabled();
        }
        CommentService::ensureAutoload();
        $manifest = app(WeappPluginGateway::class)->pluginReadManifest('doc_comment') ?? [];

        return $this->renderPluginView('index', array_merge(
            $this->pluginContext($manifest),
            [
                'cfg'    => CommentConfigService::all(),
                'stats'  => CommentService::statsAdmin(),
                'levels' => CommentService::levelsForAdmin(),
            ]
        ));
    }

    public function list(): Response
    {
        if (!$this->entitled()) {
            return $this->renderDisabled();
        }
        CommentService::ensureAutoload();
        $manifest = app(WeappPluginGateway::class)->pluginReadManifest('doc_comment') ?? [];

        if (Request::isAjax()) {
            $page = max(1, (int) Request::get('page', 1));
            $pageSize = min(100, max(10, (int) Request::get('limit', Request::get('page_size', 20))));
            $filters = [
                'status'      => Request::get('status', ''),
                'document_id' => (int) Request::get('document_id', 0),
                'keyword'     => (string) Request::get('keyword', ''),
            ];

            return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::listAdmin($filters, $page, $pageSize));
        }

        return $this->renderPluginView('list', array_merge(
            $this->pluginContext($manifest, 'comments'),
            ['stats' => CommentService::statsAdmin()]
        ));
    }

    public function configSave(): Response
    {
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed();
        }
        if (!$this->entitled()) {
            return app(WeappSupportGateway::class)->apiFromServiceResult(app(WeappSupportGateway::class)->resultForbidden('插件未授权'));
        }
        $result = CommentConfigService::saveAdmin(Request::post());
        CommentService::ensureAutoload();
        CommentService::syncLevelRows();
        $guestAllowed = CommentConfigService::guestAllowed();
        WeappDocCommentLevel::where('member_level_id', 0)->update([
            'can_comment' => $guestAllowed ? 1 : 0,
            'updated_at'  => app(WeappSupportGateway::class)->appTimeNow(),
        ]);
        $levels = Request::post('levels/a', []);
        if (is_array($levels) && $levels !== []) {
            CommentService::saveLevelsAdmin($levels);
        }

        return app(WeappSupportGateway::class)->apiFromServiceResult($result);
    }

    public function levelSave(): Response
    {
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed();
        }
        if (!$this->entitled()) {
            return app(WeappSupportGateway::class)->apiFromServiceResult(app(WeappSupportGateway::class)->resultForbidden('插件未授权'));
        }
        CommentService::ensureAutoload();
        $rows = Request::post('levels/a', []);

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::saveLevelsAdmin(is_array($rows) ? $rows : []));
    }

    public function review(): Response
    {
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed();
        }
        CommentService::ensureAutoload();
        $ids = app(WeappSupportGateway::class)->adminBatchParsePostIds();

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::reviewAdmin($ids, (int) Request::post('status', 1)));
    }

    public function reviewAll(): Response
    {
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed();
        }
        CommentService::ensureAutoload();

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::approveAllPendingAdmin());
    }

    public function delete(): Response
    {
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed();
        }
        CommentService::ensureAutoload();

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::deleteAdmin((int) Request::post('id', 0)));
    }
}
