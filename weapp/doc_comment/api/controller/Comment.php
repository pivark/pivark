<?php
declare(strict_types=1);

namespace weapp\doc_comment\api\controller;

use app\common\service\weapp\WeappFrontGateway;
use app\common\service\weapp\WeappSupportGateway;
use think\facade\Request;
use think\response\Json;
use weapp\doc_comment\service\CommentHubService;
use weapp\doc_comment\service\CommentService;

/** 评论插件前台 API */
class Comment
{
    public function list(int $document_id): Json
    {
        CommentService::ensureAutoload();
        $page = max(1, (int) Request::get('page', 1));
        $parentId = max(0, (int) Request::get('parent_id', 0));

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentService::listPublic($document_id, $page, $parentId));
    }

    public function add(): Json
    {
        CommentService::ensureAutoload();
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed('请使用 POST 提交');
        }
        $token = (string) Request::post(app(WeappFrontGateway::class)->frontCsrfFieldName(), '');
        $header = Request::header('X-CSRF-Token');
        if (!app(WeappFrontGateway::class)->frontCsrfValidateRequest($token, is_string($header) ? $header : null)) {
            return app(WeappSupportGateway::class)->apiCsrfExpired();
        }

        $result = CommentService::submit(Request::post());
        if ($result->isOk()) {
            app(WeappFrontGateway::class)->frontCsrfRotateAfterSuccess();
        }

        return app(WeappSupportGateway::class)->apiFromServiceResult($result);
    }

    public function delete(): Json
    {
        CommentService::ensureAutoload();
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed('请使用 POST 提交');
        }
        $token = (string) Request::post(app(WeappFrontGateway::class)->frontCsrfFieldName(), '');
        $header = Request::header('X-CSRF-Token');
        if (!app(WeappFrontGateway::class)->frontCsrfValidateRequest($token, is_string($header) ? $header : null)) {
            return app(WeappSupportGateway::class)->apiCsrfExpired();
        }

        $result = CommentService::deleteOwn((int) Request::post('id', 0));
        if ($result->isOk()) {
            app(WeappFrontGateway::class)->frontCsrfRotateAfterSuccess();
        }

        return app(WeappSupportGateway::class)->apiFromServiceResult($result);
    }

    public function like(): Json
    {
        CommentService::ensureAutoload();
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed('请使用 POST 提交');
        }
        $token = (string) Request::post(app(WeappFrontGateway::class)->frontCsrfFieldName(), '');
        $header = Request::header('X-CSRF-Token');
        if (!app(WeappFrontGateway::class)->frontCsrfValidateRequest($token, is_string($header) ? $header : null)) {
            return app(WeappSupportGateway::class)->apiCsrfExpired();
        }

        $result = CommentService::toggleLike((int) Request::post('comment_id', 0));
        if ($result->isOk()) {
            app(WeappFrontGateway::class)->frontCsrfRotateAfterSuccess();
        }

        return app(WeappSupportGateway::class)->apiFromServiceResult($result);
    }

    public function tree(int $document_id): Json
    {
        CommentService::ensureAutoload();

        return app(WeappSupportGateway::class)->apiFromServiceResult(CommentHubService::listTreePublic($document_id));
    }

    public function threadCreate(): Json
    {
        CommentService::ensureAutoload();
        if (!Request::isPost()) {
            return app(WeappSupportGateway::class)->apiMethodNotAllowed('请使用 POST 提交');
        }
        $token = (string) Request::post(app(WeappFrontGateway::class)->frontCsrfFieldName(), '');
        $header = Request::header('X-CSRF-Token');
        if (!app(WeappFrontGateway::class)->frontCsrfValidateRequest($token, is_string($header) ? $header : null)) {
            return app(WeappSupportGateway::class)->apiCsrfExpired();
        }

        $result = CommentHubService::createThread(
            (string) Request::post('title', ''),
            (string) Request::post('content', '')
        );
        if ($result->isOk()) {
            app(WeappFrontGateway::class)->frontCsrfRotateAfterSuccess();
        }

        return app(WeappSupportGateway::class)->apiFromServiceResult($result);
    }
}
