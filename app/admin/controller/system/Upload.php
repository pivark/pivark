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
use app\common\support\SiteUrl;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\site\SiteModeService;
use app\common\service\upload\UploadAdminGateway;
use app\common\service\upload\UploadChunkService;
use app\common\service\upload\UploadService;
use app\common\support\UploadGate;
use app\common\support\UploadGateException;
use think\facade\Request;
use think\facade\Session;

// app/admin/controller/Upload.php — 文件上传（薄控制器 + UploadGate + AuthCheck/RBAC）

class Upload extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly UploadAdminGateway $uploads,
        private readonly UploadChunkService $uploadChunk,
        private readonly SiteModeService $siteMode,
    ) {
        parent::__construct($csrf);
    }

    private function guardScene(string $scene): ?\think\response\Json
    {
        try {
            UploadGate::assertUpload($scene);
            return null;
        } catch (UploadGateException $e) {
            $code = $e->getHttpCode() === 401 ? -1 : 0;
            return ($code === -1 ? AdminApiResponse::authExpired($e->getMessage(), SiteUrl::adminSpa('/auth/login')) : AdminApiResponse::fail($e->getMessage()));
        }
    }

    /** GET — 前端上传能力配置（分片阈值、去重开关） */
    public function config()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->uploads->clientUploadConfig(), ''));
    }

    /** POST — 上传前内容哈希检查 */
    public function check()
    {
        $scene = (string) Request::post('scene', 'general');
        if ($err = $this->guardScene($scene)) {
            return $err;
        }

        $hash = (string) Request::post('content_hash', '');
        $size = (int) Request::post('file_size', 0);

        return AdminApiResponse::admin($this->uploads->checkDuplicate($hash, $size));
    }

  /** POST — 分片会话初始化 */
    public function chunkInit()
    {
        $scene = (string) Request::post('scene', 'general');
        if ($err = $this->guardScene($scene)) {
            return $err;
        }

        return AdminApiResponse::admin($this->uploadChunk->init(
            $scene,
            (string) Request::post('file_name', 'file.bin'),
            (int) Request::post('file_size', 0),
            (string) Request::post('content_hash', ''),
            (int) Request::post('part_size', $this->uploadChunk->defaultPartSizeBytes())
        ));
    }

    /** POST — 查询已上传分片（断点续传） */
    public function chunkStatus()
    {
        $uploadId = (string) Request::post('upload_id', '');
        if ($uploadId === '') {
            return AdminApiResponse::fail('缺少 upload_id');
        }

        return AdminApiResponse::admin($this->uploadChunk->resume($uploadId));
    }

    /** POST — 上传单个分片 */
    public function chunk()
    {
        $uploadId = (string) Request::post('upload_id', '');
        $index    = (int) Request::post('index', -1);
        if ($uploadId === '' || $index < 0) {
            return AdminApiResponse::fail('参数错误');
        }

        $file = request()->file('chunk') ?? request()->file('file');
        if ($file === null) {
            return AdminApiResponse::fail('请选择分片文件');
        }

        return AdminApiResponse::admin($this->uploadChunk->savePart($uploadId, $index, $file->getPathname()));
    }

    /** POST — 合并分片并完成入库 */
    public function chunkComplete()
    {
        $uploadId = (string) Request::post('upload_id', '');
        if ($uploadId === '') {
            return AdminApiResponse::fail('缺少 upload_id');
        }

        $force = in_array(Request::post('force_upload'), ['1', 'true', true], true);

        return AdminApiResponse::admin($this->uploadChunk->complete($uploadId, $force));
    }

    public function image()
    {
        $scene = (string) Request::param('scene', 'general');
        if ($err = $this->guardScene($scene)) {
            return $err;
        }

        try {
            return AdminApiResponse::admin(UploadService::scene($scene)->handle(
                request()->file('file'),
                $this->uploadMetaFromRequest()
            ));
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::fail($e->getMessage());
        }
    }

    public function file()
    {
        if ($err = $this->guardScene('attachment')) {
            return $err;
        }

        return AdminApiResponse::admin(UploadService::scene('attachment')->handle(
            request()->file('file'),
            $this->uploadMetaFromRequest()
        ));
    }

    public function video()
    {
        if ($err = $this->guardScene('media')) {
            return $err;
        }

        return AdminApiResponse::admin(UploadService::scene('media')->handle(
            request()->file('file'),
            $this->uploadMetaFromRequest()
        ));
    }

    public function test()
    {
        if (!$this->siteMode->isDev()) {
            return AdminApiResponse::fail('Not Found', 404);
        }

        $root = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        $sid = session_id();

        return AdminApiResponse::admin(ServiceResult::ok([
            'session'       => Session::get('admin_user') ? '已登录' : '未登录',
            'upload_path'   => $root,
            'path_exists'   => is_dir($root),
            'path_writable' => is_dir($root) && is_writable($root),
            'session_hint'  => $sid !== '' ? substr($sid, 0, 4) . '****' : '',
        ]));
    }

    /** @return array{content_hash?:string,file_size?:int,force_upload?:bool,force_security_upload?:bool,admin_confirm_password?:string} */
    private function uploadMetaFromRequest(): array
    {
        return [
            'content_hash'            => (string) Request::post('content_hash', ''),
            'file_size'               => (int) Request::post('file_size', 0),
            'force_upload'            => in_array(Request::post('force_upload'), ['1', 'true', true], true),
            'force_security_upload'   => in_array(Request::post('force_security_upload'), ['1', 'true', true], true),
            'admin_confirm_password'  => (string) Request::post('admin_confirm_password', ''),
        ];
    }
}
