<?php
/**
 * 操作审计（pv_logs）写入策略
 */
declare(strict_types=1);

return [
    // true：主流程只入队，请求结束时 register_shutdown_function 批量写库（不阻塞业务 SQL）
    'defer_write' => true,

    // 写库失败时追加 JSON 行到 data/runtime/log/audit_fail.log，便于补录与告警
    'failure_log' => true,
];
