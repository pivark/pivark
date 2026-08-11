<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 领域事件总线（L1 event_bus 薄实现）
 * 白名单事件 + Hook 分发 + 可选落库；manifest subscribes 注册监听器。
 */
declare(strict_types=1);

namespace app\common\service\event;
use app\common\service\event\DomainEventDispatchQueueService;
use app\common\model\DomainEventLog;

use app\common\service\hook\HookService;
use app\common\support\OpsLog;
use app\common\support\AppTime;

class EventBusService
{

    public function __construct(
        private readonly DomainEventDispatchQueueService $domainEventDispatchQueueService,
        private readonly HookService $hookService,
    ) {
    }

    /** @var list<string> SSOT：插件生态架构大纲 §6.4 */
    public const ALLOWED_EVENTS = [
        'document.after_save',
        'document.deleted',
        'entitlement.granted',
        'project.created',
        'project.go_no_go',
        'project.submitted',
        'project.won',
        'project.lost',
        'order.confirmed',
        'order.line.allocated',
        'payment.fulfilled',
        'payment.refunded',
        'entitlement.revoked',
        'item.deleted',
        'item.status_changed',
        'item.updated',
        'item.params_changed',
        'oa.approval.approved',
        'oa.approval.rejected',
        'crm.customer.created',
        'crm.contract.approved',
        'erp.inventory.changed',
        'erp.sales_order.confirmed',
        'mes.workorder.completed',
        'geo.article.published',
        'plm.bom.revised',
        'plm.ecn.approved',
        'plm.ecn.applied',
    ];

    /**
     * @param array<string, mixed> $payload 仅传 ID 等标量（勿传 ORM 对象）
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $event = strtolower(trim($event));
        if ($event === '') {
            return;
        }
        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            OpsLog::businessWarning('event_bus_dispatch_blocked', [
                'event' => $event,
            ]);

            return;
        }

        $this->writeLog($event, $payload);
        if ($this->domainEventDispatchQueueService->shouldQueue($event)) {
            $this->domainEventDispatchQueueService->enqueue($event, $payload);

            return;
        }
        $this->hookService->fire($event, $payload);
    }

    /**
     * @param callable(array<string, mixed>): void $listener
     */
    public function listen(string $event, callable $listener, ?string $subscriberIdentifier = null): void
    {
        $event = strtolower(trim($event));
        if ($event === '') {
            return;
        }
        $this->hookService->on($event, $listener, $subscriberIdentifier);
    }

    public function removeForIdentifier(string $identifier): void
    {
        $this->hookService->removeForIdentifier($identifier);
    }

    /**
     * 从 plugin.json 注册 subscribes（插件 boot 之后调用）
     *
     * @param array<string, mixed>|null $manifest
     */
    public function registerManifestSubscribes(string $identifier, ?array $manifest): void
    {
        if (!is_array($manifest)) {
            return;
        }
        $subs = $manifest['subscribes'] ?? null;
        if (!is_array($subs) || $subs === []) {
            return;
        }

        foreach ($subs as $event => $handlers) {
            $eventName = strtolower(trim((string) $event));
            if ($eventName === '') {
                continue;
            }
            $list = is_array($handlers) ? $handlers : [$handlers];
            foreach ($list as $handler) {
                $this->bindHandler($identifier, $eventName, (string) $handler);
            }
        }
    }

    private function bindHandler(string $identifier, string $event, string $handler): void
    {
        $handler = trim($handler);
        if ($handler === '' || !str_contains($handler, '::')) {
            return;
        }
        [$class, $method] = explode('::', $handler, 2);
        $class  = trim($class);
        $method = trim($method);
        if ($class === '' || $method === '' || !class_exists($class) || !method_exists($class, $method)) {
            return;
        }
        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            OpsLog::businessWarning('event_bus_subscribe_blocked', [
                'event'      => $event,
                'identifier' => $identifier,
                'handler'    => $class . '::' . $method,
            ]);

            return;
        }

        $this->listen($event, function (array $payload) use ($class, $method, $identifier, $event): void {
            try {
                $this->invokeHandler($class, $method, $payload);
            } catch (\Throwable $e) {
                OpsLog::businessWarning('event_bus_handler_failed', [
                    'event'      => $event,
                    'subscriber' => $identifier,
                    'handler'    => $class . '::' . $method,
                    'error'      => $e->getMessage(),
                ]);
                $this->writeLog($event, [
                    'subscriber' => $identifier,
                    'handler'    => $class . '::' . $method,
                    'error'      => $e->getMessage(),
                ], 'error');
            }
        }, $identifier);
    }

    /**
     * manifest 订阅：静态方法保持兼容；实例方法走容器以支持 DI。
     *
     * @param array<string, mixed> $payload
     */
    private function invokeHandler(string $class, string $method, array $payload): void
    {
        $ref = new \ReflectionMethod($class, $method);
        if ($ref->isStatic()) {
            $class::$method($payload);

            return;
        }

        $instance = app()->make($class);
        app()->invoke([$instance, $method], [$payload]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeLog(string $event, array $payload, string $source = 'core'): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        try {
            DomainEventLog::insert([
                'event_name'     => $event,
                'payload_json'   => $json,
                'source'         => substr($source, 0, 32),
                'created_at'     => AppTime::now(),
            ]);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('domain_event_log_write_failed', [
                'event'  => $event,
                'source' => $source,
                'msg'    => $e->getMessage(),
            ]);
        }
    }
}
