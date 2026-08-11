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
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\channel\MiniprogramPageConfigService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

class MiniprogramPage extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MiniprogramPageConfigService $miniprogramPageConfig,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        return redirect(SiteUrl::adminSpa('/system/miniprogram/decor'));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->miniprogramPageConfig->saveAdmin(
            $this->miniprogramPageConfig->parseAdminSavePayload(
                Request::post(),
                (string) Request::getContent(),
            )
        ));
    }

    /** POST — 小程序页面装修实时预览（REST · 原 Spa::miniprogramPagePreview） */
    public function preview(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $payload = $this->miniprogramPageConfig->parseAdminSavePayload(
            Request::post(),
            (string) Request::getContent(),
        );

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $this->miniprogramPageConfig->buildAdminPreview(
                $payload['theme'],
                $payload['blocks'],
                $payload['page'],
            ),
        ));
    }

    /** POST — 小程序页面装修保存（REST · 原 Spa::miniprogramPageSave · 路径 layout 兼容） */
    public function layout(): Response
    {
        return $this->save();
    }

    /** GET — 下载已注入 AppID/apiBase 的小程序工程 zip（REST · 原 Spa::mpWechatSdkDownload） */
    public function wechatSdkDownload(): Response
    {
        $edition  = strtolower(trim((string) Request::get('edition', '')));
        $platform = strtolower(trim((string) Request::get('platform', 'wechat')));
        /** @var \app\common\service\admin\AdminSpaChannelService $channel */
        $channel = \app\common\support\AppService::make(\app\common\service\admin\AdminSpaChannelService::class);
        $built   = $channel->buildWechatSdkZip(
            $platform !== '' ? $platform : null,
            $edition !== '' ? $edition : null,
        );
        if (!$built->isOk()) {
            return AdminApiResponse::fail((string) ($built->message() ?? '打包失败'));
        }
        $data = $built->dataArray() ?? [];

        return download((string) ($data['path'] ?? ''), (string) ($data['filename'] ?? 'pivark-wechat-content.zip'))->expire(0);
    }
}
