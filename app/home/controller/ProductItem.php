<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);


namespace app\home\controller;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\service\document\satellite\DocumentQrService;
use app\common\service\front\FrontRenderService;
use app\common\model\Item;
use think\facade\Request;
use think\Response;

/** 品项独立展示页 canonical：`/items/{slug}` */
class ProductItem extends Base
{
    public function __construct(
        private readonly FrontRenderService $frontRender,
    ) {
    }

    public function view(string $slug = ''): Response
    {
        $slug = trim((string) ($slug ?: Request::param('slug', '')));
        if ($slug === '') {
            return $this->error('参数错误');
        }

        $cached = $this->tryPageCacheResponse();
        if ($cached !== null) {
            return $cached;
        }

        /**
         * 宿主扩展可声明品项 slug → 市场详情 URL（Community 无 handler 时仍走 /items/{slug}）。
         */
        $redirect = PluginOfficialProduct::dispatch('product_item_redirect', ['slug' => $slug], null);
        if (is_string($redirect) && $redirect !== '') {
            return redirect($redirect, 302);
        }

        $payload = $this->frontRender->productItemPayload($slug);
        if ($payload === null) {
            return $this->error('品项不存在或已下架');
        }

        return $this->render($payload['template'], $payload['vars']);
    }

    /** 品项页 URL 二维码 PNG（路由 item/qrcode/{id}） */
    public function qrcode($id = 0): Response
    {
        $qr = app(DocumentQrService::class);
        if (!$qr->isEnabled()) {
            return response('Not Found', 404);
        }
        $id = (int) ($id ?: Request::param('id', 0));
        if ($id < 1) {
            return response('Not Found', 404);
        }
        $row = Item::where('id', $id)->find()?->toArray();
        $slug = trim((string) ($row['slug'] ?? ''));
        if ($slug === '') {
            return response('Not Found', 404);
        }
        $path = $qr->cachedItemPngPath($id, $slug);
        if ($path === null || !is_file($path)) {
            return response('Not Found', 404);
        }
        $bytes = (string) file_get_contents($path);
        if ($bytes === '' || strlen($bytes) < 100) {
            return response('Not Found', 404);
        }

        return Response::create($bytes, 'html', 200)
            ->header([
                'Content-Type'        => 'image/png',
                'Content-Disposition' => 'inline; filename="item-' . $id . '-qr.png"',
                'Cache-Control'       => 'public, max-age=86400',
            ]);
    }
}
