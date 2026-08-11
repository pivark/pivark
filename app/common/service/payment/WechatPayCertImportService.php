<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment;

use app\common\support\ServiceResult;

use app\common\service\payment\PaymentConfigService;

/** 微信支付 API 证书包解析（apiclient_key.pem / apiclient_cert.pem） */
class WechatPayCertImportService
{

    public function __construct(
        private readonly PaymentConfigService $paymentConfig,
    ) {
    }

    private const MAX_PEM_BYTES = 65536;

    /**
     * @return ServiceResult
     */
    public function importFromPemContents(string $keyPem, string $certPem = ''): ServiceResult
    {
        $keyPem  = $this->normalizePemInput($keyPem);
        $certPem = $this->normalizePemInput($certPem);

        if ($keyPem === '' && $certPem === '') {
            return ServiceResult::fail('请上传 apiclient_key.pem，或同时上传 apiclient_cert.pem');
        }

        $privateKey = '';
        $serialNo   = '';
        $keyOk      = false;
        $serialOk   = false;

        if ($keyPem !== '') {
            if (str_contains($keyPem, 'BEGIN CERTIFICATE')) {
                return ServiceResult::fail('私钥文件选错了：请上传 apiclient_key.pem（PRIVATE KEY），不要上传 apiclient_cert.pem');
            }
            if (!$this->paymentConfig->wechatPrivateKeyParsable($keyPem)) {
                return ServiceResult::fail('无法识别商户私钥，请确认是 apiclient_key.pem 完整内容');
            }
            $privateKey = $keyPem;
            $keyOk      = true;
        }

        if ($certPem !== '') {
            if (!str_contains($certPem, 'BEGIN CERTIFICATE')) {
                return ServiceResult::fail('证书文件应为 apiclient_cert.pem（BEGIN CERTIFICATE）');
            }
            $serialNo = $this->serialFromCertPem($certPem);
            if ($serialNo === '') {
                return ServiceResult::fail('无法从 apiclient_cert.pem 读取证书序列号，请到商户平台复制');
            }
            $serialOk = true;
        }

        if (!$keyOk && !$serialOk) {
            return ServiceResult::fail('未解析到有效内容');
        }

        $parts = [];
        if ($keyOk) {
            $parts[] = '商户私钥';
        }
        if ($serialOk) {
            $parts[] = '证书序列号';
        }

        return ServiceResult::ok(['private_key' => $privateKey, 'serial_no' => $serialNo, 'private_key_ok' => $keyOk, 'serial_ok' => $serialOk], '已导入' . implode('、', $parts) . '，请核对 AppID/商户号/APIv3 密钥后点「保存配置」');
    }

    public function serialFromCertPem(string $pem): string
    {
        $pem = trim($pem);
        if ($pem === '') {
            return '';
        }
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            return '';
        }
        $info = openssl_x509_parse($cert, false);
        if (!is_array($info)) {
            return '';
        }
        $sn = trim((string) ($info['serialNumber'] ?? ''));
        if ($sn === '') {
            return '';
        }
        if (preg_match('/^0x([0-9A-Fa-f]+)$/i', $sn, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^[0-9A-Fa-f]{16,}$/', $sn)) {
            return strtoupper($sn);
        }
        if (ctype_digit($sn) && function_exists('gmp_init')) {
            return strtoupper(gmp_strval(gmp_init($sn, 10), 16));
        }

        $hex = strtoupper(preg_replace('/[^A-F0-9]/i', '', $sn) ?? '');

        return strlen($hex) >= 16 ? $hex : '';
    }

    public function readUploadedPem(?object $file): string
    {
        if ($file === null || !method_exists($file, 'isValid') || !$file->isValid()) {
            return '';
        }
        if (method_exists($file, 'getSize') && (int) $file->getSize() > self::MAX_PEM_BYTES) {
            return '';
        }
        $path = method_exists($file, 'getPathname') ? (string) $file->getPathname() : '';
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? $this->normalizePemInput($raw) : '';
    }

    private function normalizePemInput(string $raw): string
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > self::MAX_PEM_BYTES) {
            return '';
        }

        return $raw;
    }
}
