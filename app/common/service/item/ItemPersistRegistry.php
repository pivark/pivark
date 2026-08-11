<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use app\common\service\plugin\extension\ExtensionTraceService;
use app\common\service\plugin\boot\PluginRuntimeFaultGuard;
use app\common\support\ServiceResult;

/**
 * 产品中心品项保存后扩展点（item.after_save）
 *
 * L1 ItemService::saveAdmin 提交后 dispatch；插件同步商城/ERP 等衍生数据，不复写品项主表。
 */
final class ItemPersistRegistry
{

    /** @var list<array{identifier:string, handler:callable, priority:int}> */
    private static array $handlers = [];

    public function reset(): void
    {
        self::$handlers = [];
    }

    /** @param callable(array<string,mixed>): ServiceResult $handler */
    public function register(string $identifier, callable $handler, int $priority = 100): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        self::$handlers[] = [
            'identifier' => $identifier,
            'handler'    => $handler,
            'priority'   => $priority,
        ];
        usort(self::$handlers, static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['identifier'], $b['identifier']);
        });
    }

    /**
     * @param array<string, mixed> $ctx item_id, is_new, prev_status, row
     */
    public function dispatchAfterSave(array $ctx): ?ServiceResult
    {
        if (self::$handlers === [] || (int) ($ctx['item_id'] ?? 0) < 1) {
            return null;
        }
        $ran  = false;
        $last = null;
        foreach (self::$handlers as $entry) {
            $ran = true;
            app(ExtensionTraceService::class)->log('item.after_save', $entry['identifier'], [
                'item_id' => (int) ($ctx['item_id'] ?? 0),
                'is_new'  => (int) ($ctx['is_new'] ?? 0),
            ]);
            $last = PluginRuntimeFaultGuard::invoke(
                static fn (): mixed => ($entry['handler'])($ctx),
                'plugin_item_after_save_failed',
                [
                    'identifier' => $entry['identifier'],
                    'item_id'    => (int) ($ctx['item_id'] ?? 0),
                ],
                null,
            );
            if ($last instanceof ServiceResult && !$last->isOk()) {
                return $last;
            }
        }

        return $ran ? ($last instanceof ServiceResult ? $last : ServiceResult::ok(null, 'ok')) : null;
    }
}
