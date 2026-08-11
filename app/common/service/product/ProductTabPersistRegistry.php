<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\product;

use app\common\service\plugin\extension\ExtensionTraceService;
use app\common\support\ServiceResult;

/**
 * 文档产品 Tab 落库后扩展点（product_tab.after_persist）
 *
 * 产品中心写完品项/规格后 dispatch；扩展 handler 解析自有 POST 字段。
 */
final class ProductTabPersistRegistry
{

    /** @var list<array{identifier:string, post_keys:list<string>, handler:callable, priority:int}> */
    private static array $handlers = [];

    /** @var list<array{identifier:string, post_keys:list<string>, handler:callable, priority:int}> */
    private static array $beforeHandlers = [];

    public function reset(): void
    {
        self::$handlers = [];
        self::$beforeHandlers = [];
    }

    /**
     * @param list<string> $postKeys POST 键名；出现任一键时该 handler 可被 dispatch 选中
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function register(string $identifier, array $postKeys, callable $handler, int $priority = 100): void
    {
        $this->pushHandler(self::$handlers, $identifier, $postKeys, $handler, $priority);
    }

    /**
     * 文档产品 Tab 写规格/品项前校验（失败则整单不落库）
     *
     * @param list<string> $postKeys
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    public function registerBeforePersist(string $identifier, array $postKeys, callable $handler, int $priority = 100): void
    {
        $this->pushHandler(self::$beforeHandlers, $identifier, $postKeys, $handler, $priority);
    }

    /**
     * @param list<array{identifier:string, post_keys:list<string>, handler:callable, priority:int}> $bucket
     * @param list<string> $postKeys
     * @param callable(array<string,mixed>): ServiceResult $handler
     */
    private function pushHandler(
        array &$bucket,
        string $identifier,
        array $postKeys,
        callable $handler,
        int $priority,
    ): void {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }
        $keys = [];
        foreach ($postKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        $bucket[] = [
            'identifier' => $identifier,
            'post_keys'  => $keys,
            'handler'    => $handler,
            'priority'   => $priority,
        ];
        usort($bucket, static function (array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['identifier'], $b['identifier']);
        });
    }

    /** @param array<string, mixed> $post */
    public function postDataHasRegisteredKeys(array $post): bool
    {
        if ($post === [] || self::$handlers === []) {
            return false;
        }
        foreach (self::$handlers as $entry) {
            foreach ($entry['post_keys'] as $key) {
                if (array_key_exists($key, $post)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx document_id, item_id, item_type, layout_mode?, post
     */
    public function dispatchBeforePersist(array $ctx): ?ServiceResult
    {
        return $this->dispatchPhase('product_tab.before_persist', self::$beforeHandlers, $ctx);
    }

    /**
     * @param array<string, mixed> $ctx document_id, item_id, item_type, layout_mode?, post
     */
    public function dispatchAfterPersist(array $ctx): ?ServiceResult
    {
        return $this->dispatchPhase('product_tab.after_persist', self::$handlers, $ctx);
    }

    /**
     * @param list<array{identifier:string, post_keys:list<string>, handler:callable, priority:int}> $handlers
     * @param array<string, mixed> $ctx
     */
    private function dispatchPhase(string $point, array $handlers, array $ctx): ?ServiceResult
    {
        if ($handlers === []) {
            return null;
        }
        $post = is_array($ctx['post'] ?? null) ? $ctx['post'] : [];
        $ran  = false;
        $last = null;
        foreach ($handlers as $entry) {
            if (!$this->handlerMatchesPost($entry, $post)) {
                continue;
            }
            $ran  = true;
            app(ExtensionTraceService::class)->log($point, $entry['identifier'], [
                'document_id' => (int) ($ctx['document_id'] ?? 0),
                'item_id'     => (int) ($ctx['item_id'] ?? 0),
            ]);
            $last = ($entry['handler'])($ctx);
            if ($last instanceof ServiceResult && !$last->isOk()) {
                return $last;
            }
        }

        return $ran ? ($last instanceof ServiceResult ? $last : ServiceResult::ok(null, 'ok')) : null;
    }

    /** @return list<string> */
    public function registeredPostKeys(): array
    {
        $keys = [];
        foreach (self::$handlers as $entry) {
            foreach ($entry['post_keys'] as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array{identifier:string, post_keys:list<string>, handler:callable, priority:int} $entry
     * @param array<string, mixed> $post
     */
    private function handlerMatchesPost(array $entry, array $post): bool
    {
        if ($entry['post_keys'] === []) {
            return true;
        }
        foreach ($entry['post_keys'] as $key) {
            if (array_key_exists($key, $post)) {
                return true;
            }
        }

        return false;
    }
}
