<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\ai\AIMetadataService;
use app\common\service\ai\AiConfigAdminService;
use app\common\service\ai\AiConfigProcessService;
use app\common\service\ai\LlmChatService;
use app\common\service\config\AiConfigService;
use think\facade\Request;

/** L1 内核 · AI 配置与超级搜索（/admin/system/ai-config/*） */
class AiConfig extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AiConfigAdminService $aiConfigAdmin,
        private readonly AiConfigService $aiConfig,
        private readonly LlmChatService $llmChat,
        private readonly AIMetadataService $aiMetadata,
        private readonly AiConfigProcessService $aiConfigProcess,
    ) {
        parent::__construct($csrf);
    }

    public function configSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->aiConfigAdmin->saveAdmin(Request::post()));
    }

    public function testConnection()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        if (!$this->aiConfig->isEnabled()) {
            return AdminApiResponse::fail('请先开启 AI 功能');
        }
        if (!$this->aiConfig->activeProviderConfigured()) {
            return AdminApiResponse::fail('请先配置当前服务商 API Key');
        }

        $res = $this->llmChat->chat([
            ['role' => 'user', 'content' => '回复 OK 两个字母即可。'],
        ]);
        if (!$res->isOk()) {
            return AdminApiResponse::fail((string) ($res->message() ?? '连接失败'));
        }

        return AdminApiResponse::fromResult(ServiceResult::ok(null, '连接成功'));
    }

    public function generateMeta()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $documentId = (int) Request::post('document_id', 0);
        $onlyEmpty  = (int) Request::post('only_empty', 1) === 1;

        return AdminApiResponse::admin($this->aiMetadata->applyForDocument($documentId, $onlyEmpty));
    }

    public function processDocument()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $documentId = (int) Request::post('document_id', 0);
        $onlyEmpty  = (int) Request::post('only_empty', 1) === 1;

        return AdminApiResponse::admin($this->aiConfigProcess->processDocument($documentId, true, $onlyEmpty));
    }

    public function processStatus()
    {
        $documentId = (int) Request::get('document_id', 0);

        return AdminApiResponse::fromResult(ServiceResult::ok($this->aiConfigProcess->statusForDocument($documentId)));
    }

    public function testExtract()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $file = Request::file('file');
        if ($file === null) {
            return AdminApiResponse::fail('请上传文件');
        }

        $saved = $file->move(runtime_path() . 'ai_extract_test');
        if ($saved === false) {
            return AdminApiResponse::fail('上传失败');
        }
        $abs  = $saved->getPathname();
        $mime = (string) ($file->getMime() ?? '');
        $name = (string) ($file->getOriginalName() ?? basename($abs));

        return AdminApiResponse::admin($this->aiConfigProcess->testExtractFile($abs, $mime, $name));
    }

    public function testOcr()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $file = Request::file('file');
        if ($file === null) {
            return AdminApiResponse::fail('请上传图片或 PDF');
        }
        $saved = $file->move(runtime_path() . 'ai_ocr_test');
        if ($saved === false) {
            return AdminApiResponse::fail('上传失败');
        }
        $abs  = $saved->getPathname();
        $mime = (string) ($file->getMime() ?? '');

        return AdminApiResponse::admin($this->aiConfigProcess->testOcrFile($abs, $mime));
    }
}
