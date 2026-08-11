<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 核心升级包 RSA 验签（manifest sha256 的独立签名，防同源篡改）
 */
return [
    /** PEM 公钥；空则仅 SHA256（向后兼容） */
    'public_key_pem' => trim((string) env('PIVARK_RELEASE_SIGN_PUBLIC_KEY', '')),
    /** 1 时无签名则拒绝 finalize（须已配置公钥） */
    'require_signature' => (int) env('PIVARK_RELEASE_SIGN_REQUIRED', 0) === 1,
];
