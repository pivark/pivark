<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 前台安全响应头 SSOT（CSP 强制 + Report-Only 采集）
 */
return [
    /** 是否附加 Content-Security-Policy-Report-Only（与强制 CSP 同策略，仅上报） */
    'csp_report_only' => (string) env('PIVARK_CSP_REPORT_ONLY', '1') !== '0',
    /** 浏览器 CSP 违规上报路径（相对站点根；空则不带 report-uri） */
    'csp_report_uri'  => trim((string) env('PIVARK_CSP_REPORT_URI', '/api/v1/system/csp-report')),
];
