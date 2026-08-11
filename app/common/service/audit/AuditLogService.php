<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\audit;
use app\common\support\AppTime;

use app\common\model\AuditLog;
use think\facade\Config;
use think\facade\Request;
use think\facade\Session;

/**
 * 操作审计写入 pv_logs
 *
 * 默认延迟写：入队后在请求结束 shutdown 阶段落库，避免与业务事务争抢连接；
 * 落库失败写入 data/runtime/log/audit_fail.log，不阻断主流程。
 */
class AuditLogService
{

    private const REDACT_KEYS = [
        'password',
        'passwd',
        'token',
        'secret',
        'captcha',
        'access_key',
        'api_key',
        'private_key',
        'refresh_token',
        'app_key',
        'app_secret',
        'client_secret',
        'credential',
    ];

    /** @var list<array<string, mixed>> */
    private static array $pending = [];

    private static bool $shutdownRegistered = false;

    /**
     * @param string               $action  行为描述
     * @param string               $module  模块
     * @param array<string, mixed> $params  请求参数（敏感键脱敏）
     * @param bool                 $success 是否成功
     * @return void
     */
    public function operate(string $action, string $module, array $params = [], bool $success = true): void
    {
        $admin = Session::get('admin_user');
        $uid   = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        $name  = is_array($admin) ? (string) ($admin['username'] ?? '') : '';

        $this->write('operate', $action, $module, $params, $success, $uid, $name);
    }

    /**
     * @param string               $type
     * @param string               $action
     * @param string               $module
     * @param array<string, mixed> $params
     * @param bool                 $success
     * @param int                  $userId
     * @param string               $username
     * @return void
     */
    public function write(
        string $type,
        string $action,
        string $module,
        array $params,
        bool $success,
        int $userId = 0,
        string $username = ''
    ): void {
        $this->enqueue($this->buildRecord($type, $action, $module, $params, $success, $userId, $username));
    }

    /**
     * 立即冲刷队列（单测 / 长驻进程手动调用）
     *
     * @return void
     */
    public function flushPending(): void
    {
        if (self::$pending === []) {
            return;
        }
        $batch         = self::$pending;
        self::$pending = [];
        foreach ($batch as $record) {
            $this->insertRecord($record);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function enqueue(array $record): void
    {
        if (!$this->deferWrite()) {
            $this->insertRecord($record);
            return;
        }

        self::$pending[] = $record;
        $this->registerShutdownFlush();
    }

    private function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flushPending();
        });
    }

    private function deferWrite(): bool
    {
        return (bool) Config::get('audit.defer_write', true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function buildRecord(
        string $type,
        string $action,
        string $module,
        array $params,
        bool $success,
        int $userId,
        string $username
    ): array {
        $req = Request::instance();

        return [
            'user_id'        => $userId > 0 ? $userId : null,
            'username'       => $username !== '' ? $username : null,
            'type'           => $type,
            'action'         => mb_substr($action, 0, 100),
            'module'         => mb_substr($module, 0, 50),
            'request_method' => $req->method(),
            'request_url'    => mb_substr($req->url(true), 0, 255),
            'request_params' => $this->encodeParams($params),
            'ip'             => $req->ip(),
            'user_agent'     => mb_substr((string) $req->header('user-agent', ''), 0, 500),
            'result'         => $success ? 1 : 0,
            'created_at'     => AppTime::now(),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function insertRecord(array $record): void
    {
        try {
            AuditLog::insert($record);
        } catch (\Throwable $e) {
            $this->logInsertFailure($e, $record);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function logInsertFailure(\Throwable $e, array $record): void
    {
        if (!Config::get('audit.failure_log', true)) {
            return;
        }

        $dir = defined('RUNTIME_PATH') ? \RUNTIME_PATH . 'log' : \app\common\support\ProjectPaths::runtimeDir() . 'log';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[PivArk audit] failed to create log dir: ' . $dir);
            return;
        }

        $line = json_encode([
            'time'    => AppTime::format('c'),
            'error'   => $e->getMessage(),
            'record'  => $record,
        ], JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            error_log('[PivArk audit] failed to encode audit failure payload');
            return;
        }

        $target = $dir . '/audit_fail.log';
        $written = file_put_contents($target, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('[PivArk audit] failed to write audit_fail.log: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function encodeParams(array $params): ?string
    {
        if ($params === []) {
            return null;
        }
        $safe = $this->redact($params);
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 65000);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($this->shouldRedactKey((string) $key)) {
                $out[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function shouldRedactKey(string $key): bool
    {
        $k = strtolower($key);
        if (in_array($k, self::REDACT_KEYS, true) || str_contains($k, 'password')) {
            return true;
        }
        foreach (['secret', 'token', 'credential', 'captcha', 'authorization'] as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }

        return str_ends_with($k, '_key') || $k === 'apikey';
    }
}
