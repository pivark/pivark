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

/** 支付宝 RSA2 密钥文件导入（multipart 直写 config_secrets，规避 POST 粘贴被 WAF 拦截） */
class AlipayKeyImportService
{

    private const MAX_KEY_BYTES = 65536;

    public function __construct(
        private readonly PaymentConfigService $paymentConfig,
    ) {
    }

    public function readUploadedText(?object $file): string
    {
        if ($file === null || !method_exists($file, 'isValid') || !$file->isValid()) {
            return '';
        }
        if (method_exists($file, 'getSize') && (int) $file->getSize() > self::MAX_KEY_BYTES) {
            return '';
        }
        $path = method_exists($file, 'getPathname') ? (string) $file->getPathname() : '';
        if ($path === '' || !is_readable($path)) {
            return '';
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? $this->normalizeKeyText($raw) : '';
    }

    /**
     * @param string $appId 可空：沿用 configs 中已保存的 AppID
     */
    public function importAndStore(string $appId, string $privateKey, string $publicKey): ServiceResult
    {
        $existing = $this->paymentConfig->all();
        if (trim($appId) === '') {
            $appId = trim((string) ($existing['payment_alipay_app_id'] ?? ''));
        }
        if ($privateKey === '') {
            $privateKey = trim((string) ($existing['payment_alipay_private_key'] ?? ''));
        }
        if ($publicKey === '') {
            $publicKey = trim((string) ($existing['payment_alipay_public_key'] ?? ''));
        }

        return $this->paymentConfig->storeAlipayChannel($appId, $privateKey, $publicKey);
    }

    private function normalizeKeyText(string $raw): string
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > self::MAX_KEY_BYTES) {
            return '';
        }

        return $raw;
    }
}
