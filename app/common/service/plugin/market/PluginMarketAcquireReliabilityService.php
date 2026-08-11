<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\market;

use app\common\service\plugin\commerce\PluginCommerceService;
use app\common\service\plugin\entitlement\EntitlementService;
use app\common\service\audit\AuditLogService;
use app\common\support\AppTime;
use app\common\support\ProjectPaths;
use app\common\support\RuntimeJsonFile;
use app\common\support\ServiceResult;

/**
 * 市场获取可靠性（原 Saga + 补偿重试两文件并一）
 * — Saga：grant→install 步骤落盘，Cron 续跑卡住流程
 * — 补偿队列：退款/撤权/续装失败入队，Cron 兜底
 */
final class PluginMarketAcquireReliabilityService
{
    private const SAGA_FILE = 'plugin_acquire_sagas.json';

    private const RETRY_FILE = 'plugin_acquire_compensation_retries.json';

    private const STALE_SECONDS = 300;

    private const MAX_ATTEMPTS = 8;

    /**
     * @param array{granted_now:bool,order_no:string,had_before:bool} $compensate
     * @param array{auto_install:bool,auto_enable:bool,with_remote_upgrade:bool} $flags
     */
    public function begin(string $identifier, array $compensate, array $flags): string
    {
        $identifier = strtolower(trim($identifier));
        $id         = 'saga_' . substr(hash('sha256', $identifier . microtime(true)), 0, 12);
        $this->mutateSaga(function (array $data) use ($id, $identifier, $compensate, $flags): array {
            $data['items'][] = [
                'id'         => $id,
                'identifier' => $identifier,
                'step'       => 'started',
                'compensate' => $compensate,
                'flags'      => $flags,
                'status'     => 'pending',
                'created_at' => AppTime::format('c'),
                'updated_at' => AppTime::format('c'),
            ];
            if (count($data['items']) > 200) {
                $data['items'] = array_slice($data['items'], -200);
            }

            return $data;
        });

        return $id;
    }

    public function markStep(string $sagaId, string $step): void
    {
        $sagaId = trim($sagaId);
        $step   = trim($step);
        if ($sagaId === '' || $step === '') {
            return;
        }
        $this->mutateSaga(function (array $data) use ($sagaId, $step): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $idx => $row) {
                if (!is_array($row) || ($row['id'] ?? '') !== $sagaId) {
                    continue;
                }
                $items[$idx]['step']       = $step;
                $items[$idx]['updated_at'] = AppTime::format('c');
                break;
            }
            $data['items'] = $items;

            return $data;
        });
    }

    public function complete(string $sagaId): void
    {
        $sagaId = trim($sagaId);
        if ($sagaId === '') {
            return;
        }
        $this->mutateSaga(function (array $data) use ($sagaId): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $idx => $row) {
                if (!is_array($row) || ($row['id'] ?? '') !== $sagaId) {
                    continue;
                }
                $items[$idx]['status']     = 'done';
                $items[$idx]['step']       = 'completed';
                $items[$idx]['updated_at'] = AppTime::format('c');
                break;
            }
            $data['items'] = $items;

            return $data;
        });
    }

    public function processStale(int $limit = 10): int
    {
        /** @var list<array<string, mixed>> $claimed */
        $claimed = [];
        $this->mutateSaga(function (array $data) use ($limit, &$claimed): array {
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $now   = time();
            $n     = 0;
            foreach ($items as $idx => $row) {
                if ($n >= $limit || !is_array($row)) {
                    continue;
                }
                $status = (string) ($row['status'] ?? '');
                if ($status !== 'pending' && $status !== 'resuming') {
                    continue;
                }
                $updated = strtotime((string) ($row['updated_at'] ?? ''));
                if ($updated > 0 && ($now - $updated) < self::STALE_SECONDS) {
                    continue;
                }
                $step = (string) ($row['step'] ?? '');
                if (!in_array($step, ['package', 'granted', 'install_failed', 'started'], true)) {
                    continue;
                }
                $items[$idx]['status']     = 'resuming';
                $items[$idx]['updated_at'] = AppTime::format('c');
                $claimed[]                 = $items[$idx];
                ++$n;
            }
            $data['items'] = $items;

            return $data;
        });

        $done = 0;
        foreach ($claimed as $row) {
            $sagaId     = (string) ($row['id'] ?? '');
            $identifier = (string) ($row['identifier'] ?? '');
            $flags      = is_array($row['flags'] ?? null) ? $row['flags'] : [];
            $result     = $this->marketAcquire()->resumeInstallOnly(
                $identifier,
                (bool) ($flags['auto_install'] ?? true),
                (bool) ($flags['auto_enable'] ?? true),
                (bool) ($flags['with_remote_upgrade'] ?? true),
            );

            if ($result->isOk()) {
                $this->mutateSaga(function (array $data) use ($sagaId): array {
                    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                    foreach ($items as $idx => $item) {
                        if (!is_array($item) || ($item['id'] ?? '') !== $sagaId) {
                            continue;
                        }
                        $items[$idx]['status']     = 'done';
                        $items[$idx]['step']       = 'completed';
                        $items[$idx]['updated_at'] = AppTime::format('c');
                        break;
                    }
                    $data['items'] = $items;

                    return $data;
                });
                ++$done;
                $this->auditLog()->operate('市场获取 Saga 续跑成功', 'admin.plugin', [
                    'saga_id'    => $sagaId,
                    'identifier' => $identifier,
                ]);
                continue;
            }

            $compensate = is_array($row['compensate'] ?? null) ? $row['compensate'] : [];
            $this->marketAcquire()->runAcquireFailureCompensation($identifier, [
                'granted_now' => (bool) ($compensate['granted_now'] ?? false),
                'order_no'    => trim((string) ($compensate['order_no'] ?? '')),
                'had_before'  => (bool) ($compensate['had_before'] ?? false),
            ]);
            $this->mutateSaga(function (array $data) use ($sagaId, $result): array {
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                foreach ($items as $idx => $item) {
                    if (!is_array($item) || ($item['id'] ?? '') !== $sagaId) {
                        continue;
                    }
                    $items[$idx]['step']       = 'install_failed';
                    $items[$idx]['last_error'] = mb_substr((string) ($result->message() ?? ''), 0, 300);
                    $items[$idx]['status']     = 'dead';
                    $items[$idx]['updated_at'] = AppTime::format('c');
                    break;
                }
                $data['items'] = $items;

                return $data;
            });
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $kind, array $payload, string $reason = ''): void
    {
        $kind = trim($kind);
        if ($kind === '') {
            return;
        }

        $data = $this->loadRetries();
        $data['items'][] = [
            'id'         => 'acq_' . substr(hash('sha256', $kind . json_encode($payload) . microtime(true)), 0, 12),
            'kind'       => $kind,
            'payload'    => $payload,
            'reason'     => mb_substr(trim($reason), 0, 500),
            'attempts'   => 0,
            'status'     => 'pending',
            'created_at' => AppTime::format('c'),
            'updated_at' => AppTime::format('c'),
        ];
        if (count($data['items']) > 500) {
            $data['items'] = array_slice($data['items'], -500);
        }
        $this->saveRetries($data);

        $this->auditLog()->operate('市场获取补偿入队', 'admin.plugin', [
            'kind'    => $kind,
            'payload' => $payload,
            'reason'  => $reason,
        ]);
    }

    public function processPending(int $limit = 20): int
    {
        $data  = $this->loadRetries();
        $done  = 0;
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        foreach ($items as $idx => $row) {
            if ($done >= $limit || !is_array($row) || ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            if ((int) ($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
                if (($row['kind'] ?? '') === 'resume_install') {
                    $this->finalizeResumeInstallFailure(is_array($row['payload'] ?? null) ? $row['payload'] : []);
                }
                $items[$idx]['status']     = 'dead';
                $items[$idx]['updated_at'] = AppTime::format('c');
                continue;
            }

            $result = $this->dispatchRetry((string) ($row['kind'] ?? ''), is_array($row['payload'] ?? null) ? $row['payload'] : []);
            $items[$idx]['attempts']   = (int) ($row['attempts'] ?? 0) + 1;
            $items[$idx]['updated_at'] = AppTime::format('c');
            if ($result->isOk()) {
                $items[$idx]['status'] = 'done';
                ++$done;
            } else {
                $items[$idx]['last_error'] = mb_substr((string) ($result->message() ?? ''), 0, 300);
            }
        }

        $data['items'] = $items;
        $this->saveRetries($data);

        return $done;
    }

    /**
     * Cron：先跑补偿队列，再续跑卡住的 Saga
     *
     * @return array{retries:int,sagas:int}
     */
    public function processCron(int $retryLimit = 20, int $sagaLimit = 10): array
    {
        return [
            'retries' => $this->processPending($retryLimit),
            'sagas'   => $this->processStale($sagaLimit),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchRetry(string $kind, array $payload): ServiceResult
    {
        return match ($kind) {
            'resume_install' => $this->marketAcquire()->resumeInstallOnly(
                (string) ($payload['identifier'] ?? ''),
                (bool) ($payload['auto_install'] ?? true),
                (bool) ($payload['auto_enable'] ?? true),
                (bool) ($payload['with_remote_upgrade'] ?? true),
            ),
            'refund_order' => $this->retryRefundOrder($payload),
            'revoke_entitlement' => $this->retryRevokeEntitlement($payload),
            default => ServiceResult::fail('未知补偿类型'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function finalizeResumeInstallFailure(array $payload): void
    {
        $identifier = strtolower(trim((string) ($payload['identifier'] ?? '')));
        if ($identifier === '') {
            return;
        }
        $compensate = is_array($payload['compensate'] ?? null) ? $payload['compensate'] : [];
        $this->marketAcquire()->runAcquireFailureCompensation($identifier, [
            'granted_now' => (bool) ($compensate['granted_now'] ?? false),
            'order_no'    => trim((string) ($compensate['order_no'] ?? '')),
            'had_before'  => (bool) ($compensate['had_before'] ?? false),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function retryRefundOrder(array $payload): ServiceResult
    {
        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        if ($orderNo === '') {
            return ServiceResult::fail('缺少 order_no');
        }

        return $this->pluginCommerce()->refundEntitlementOrder(
            $orderNo,
            (string) ($payload['reason'] ?? '市场获取安装失败自动退款（重试）'),
            0,
            false
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function retryRevokeEntitlement(array $payload): ServiceResult
    {
        $identifier = strtolower(trim((string) ($payload['identifier'] ?? '')));
        if ($identifier === '') {
            return ServiceResult::fail('缺少 identifier');
        }

        $grantedBy = strtolower(trim((string) ($payload['granted_by'] ?? 'market')));
        if ($grantedBy === '') {
            $grantedBy = 'market';
        }

        return app(EntitlementService::class)->revokeIfGrantedBy($identifier, $grantedBy);
    }

    /**
     * @param callable(array{items:list<array<string,mixed>>}): array{items:list<array<string,mixed>>} $mutator
     */
    private function mutateSaga(callable $mutator): void
    {
        RuntimeJsonFile::update($this->sagaPath(), static function (array $data) use ($mutator): array {
            if (!isset($data['items']) || !is_array($data['items'])) {
                $data['items'] = [];
            }
            $next = $mutator($data);

            return is_array($next) ? $next : $data;
        }, ['items' => []]);
    }

    /**
     * @return array{items:list<array<string,mixed>>}
     */
    private function loadRetries(): array
    {
        return RuntimeJsonFile::read($this->retryPath(), ['items' => []]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveRetries(array $data): void
    {
        RuntimeJsonFile::write($this->retryPath(), $data);
    }

    private function sagaPath(): string
    {
        return rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . self::SAGA_FILE;
    }

    private function retryPath(): string
    {
        return rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . self::RETRY_FILE;
    }

    private function marketAcquire(): PluginMarketAcquireService
    {
        return app(PluginMarketAcquireService::class);
    }

    private function auditLog(): AuditLogService
    {
        return app(AuditLogService::class);
    }

    private function pluginCommerce(): PluginCommerceService
    {
        return app(PluginCommerceService::class);
    }
}
