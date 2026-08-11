<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\api\controller\v1;

use app\common\service\media\MediaPublicGateway;
use app\common\support\ApiResponse;
use app\common\support\AppService;
use app\common\support\UploadGate;
use app\common\support\UploadGateException;
use think\facade\Request;
use think\response\Json;

class Media
{
    private function gateway(): MediaPublicGateway
    {
        /** @var MediaPublicGateway $gw */
        $gw = AppService::make(MediaPublicGateway::class);

        return $gw;
    }

    /** GET /api/v1/media?page&limit&folder&keyword */
    public function index(): Json
    {
        try {
            UploadGate::assertMediaList();
        } catch (UploadGateException $e) {
            return UploadGate::failJson($e);
        }

        $gw   = $this->gateway();
        $data = $gw->listImages([
            'page'    => Request::get('page', 1),
            'limit'   => Request::get('limit', 20),
            'keyword' => Request::get('keyword', ''),
            'folder'  => Request::get('folder', ''),
        ]);

        return ApiResponse::success([
            'list'        => $data['list'],
            'folders'     => $data['folders'],
            'max_size_mb' => $gw->maxSizeMb(),
        ], [
            'page'  => $data['page'],
            'limit' => $data['limit'],
            'total' => $data['total'],
        ]);
    }
}
