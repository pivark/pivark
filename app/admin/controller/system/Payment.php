<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\payment\PaymentOrderService;
use app\common\service\payment\PaymentConfigService;
use app\common\service\payment\AlipayKeyImportService;
use app\common\service\payment\WechatPayCertImportService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\facade\Session;
use think\Response;

/** 系统内置支付（配置页已迁至系统设置） */
class Payment extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly PaymentConfigService $paymentConfig,
        private readonly WechatPayCertImportService $wechatPayCertImport,
        private readonly AlipayKeyImportService $alipayKeyImport,
        private readonly PaymentOrderService $paymentOrder,
    ) {
        parent::__construct($csrf);
    }

    public function index(): Response
    {
        return redirect(SiteUrl::adminSpa('/system/channels?pane=payment'));
    }

    public function orders(): Response
    {
        return redirect(SiteUrl::adminSpa('/system/channels?pane=payment'));
    }

    public function configSave(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->paymentConfig->saveAdmin(Request::post()));
    }

    /** POST multipart：key_file=apiclient_key.pem，cert_file=apiclient_cert.pem（可选） */
    public function importWechatCerts(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $keyPem  = $this->wechatPayCertImport->readUploadedPem(Request::file('key_file'));
        $certPem = $this->wechatPayCertImport->readUploadedPem(Request::file('cert_file'));
        if ($keyPem === '' && $certPem === '') {
            return AdminApiResponse::fail('请上传 apiclient_key.pem（必填），可选 apiclient_cert.pem 自动填序列号');
        }

        return AdminApiResponse::admin($this->wechatPayCertImport->importFromPemContents($keyPem, $certPem));
    }

    /** POST multipart：private_key_file / public_key_file（.txt），payment_alipay_app_id 可选 */
    public function importAlipayKeys(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $privateKey = $this->alipayKeyImport->readUploadedText(Request::file('private_key_file'));
        $publicKey  = $this->alipayKeyImport->readUploadedText(Request::file('public_key_file'));
        if ($privateKey === '' && $publicKey === '') {
            return AdminApiResponse::fail('请上传应用私钥 .txt 或支付宝公钥 .txt（可分批上传）');
        }

        return AdminApiResponse::admin($this->alipayKeyImport->importAndStore(
            trim((string) Request::post('payment_alipay_app_id', '')),
            $privateKey,
            $publicKey
        ));
    }

    public function refundOrder(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $admin = Session::get('admin_user') ?? [];

        return AdminApiResponse::admin($this->paymentOrder->markRefunded(
            trim((string) Request::post('order_no', '')),
            trim((string) Request::post('reason', '')),
            (int) ($admin['id'] ?? 0),
            (int) Request::post('via_gateway', 1) === 1
        ));
    }

    public function closeOrder(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $admin = Session::get('admin_user') ?? [];

        return AdminApiResponse::admin($this->paymentOrder->closeAdmin(
            trim((string) Request::post('order_no', '')),
            (int) ($admin['id'] ?? 0),
            trim((string) Request::post('reason', ''))
        ));
    }

    public function closeStaleOrders(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $admin = Session::get('admin_user') ?? [];

        return AdminApiResponse::admin($this->paymentOrder->closeStaleInvalidAdmin(
            max(1, (int) Request::post('older_than_days', 7)),
            (int) ($admin['id'] ?? 0),
            max(1, (int) Request::post('limit', 500))
        ));
    }
}
