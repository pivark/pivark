<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\service\plugin\weapp\PluginWeappAccess;

/** 会员消费统计插件桥接（禁止 MemberConsumptionService 硬编码 download helper） */
final class PluginMemberConsumptionRegistry
{

    /** @var array<string, string> identifier => service short name */
    private static array $helpers = [];

    /** @var array<string, array{point_unlock_like?: list<string>, point_unlock_prefixes?: list<string>, point_biz_type?: string, paid_biz_type?: string, point_biz_label?: string, paid_biz_label?: string}> */
    private static array $meta = [];

    public function reset(): void
    {
        self::$helpers = [];
        self::$meta    = [];
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        unset(self::$helpers[$identifier], self::$meta[$identifier]);
    }

    public function register(string $identifier, string $serviceShortName): void
    {
        $identifier = strtolower(trim($identifier));
        $serviceShortName = trim($serviceShortName);
        if ($identifier === '' || $serviceShortName === '') {
            return;
        }
        self::$helpers[$identifier] = $serviceShortName;
    }

    /**
     * @param array{point_unlock_like?: list<string>, point_unlock_prefixes?: list<string>, point_biz_type?: string, paid_biz_type?: string, point_biz_label?: string, paid_biz_label?: string} $meta
     */
    public function registerMeta(string $identifier, array $meta): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '' || !isset(self::$helpers[$identifier])) {
            return;
        }
        self::$meta[$identifier] = array_merge(self::$meta[$identifier] ?? [], $meta);
    }

    /** @return array{point_unlock_like?: list<string>, point_unlock_prefixes?: list<string>, point_biz_type?: string, paid_biz_type?: string, point_biz_label?: string, paid_biz_label?: string} */
    public function meta(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));

        return self::$meta[$identifier] ?? [];
    }

    /** @return list<string> */
    public function pointUnlockLikePatterns(): array
    {
        $out = [];
        foreach (self::$meta as $meta) {
            foreach ($meta['point_unlock_like'] ?? [] as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '') {
                    $out[] = $pattern;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function pointUnlockPrefixes(): array
    {
        $out = [];
        foreach (self::$meta as $meta) {
            foreach ($meta['point_unlock_prefixes'] ?? [] as $prefix) {
                $prefix = trim((string) $prefix);
                if ($prefix !== '') {
                    $out[] = $prefix;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function pointBizTypes(): array
    {
        $out = [];
        foreach (self::$meta as $meta) {
            $type = trim((string) ($meta['point_biz_type'] ?? ''));
            if ($type !== '') {
                $out[] = $type;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public function paidBizTypes(): array
    {
        $out = [];
        foreach (self::$meta as $meta) {
            $type = trim((string) ($meta['paid_biz_type'] ?? ''));
            if ($type !== '') {
                $out[] = $type;
            }
        }

        return array_values(array_unique($out));
    }

    public function bizLabel(string $bizType): string
    {
        $bizType = trim($bizType);
        foreach (self::$meta as $meta) {
            if ($bizType !== '' && $bizType === trim((string) ($meta['point_biz_type'] ?? ''))) {
                return trim((string) ($meta['point_biz_label'] ?? '')) ?: $bizType;
            }
            if ($bizType !== '' && $bizType === trim((string) ($meta['paid_biz_type'] ?? ''))) {
                return trim((string) ($meta['paid_biz_label'] ?? '')) ?: $bizType;
            }
        }

        return $bizType;
    }

    public function identifierForPointBizType(string $bizType): ?string
    {
        $bizType = trim($bizType);
        foreach (self::$meta as $identifier => $meta) {
            if ($bizType !== '' && $bizType === trim((string) ($meta['point_biz_type'] ?? ''))) {
                return $identifier;
            }
        }

        return null;
    }

    public function identifierForPaidBizType(string $bizType): ?string
    {
        $bizType = trim($bizType);
        foreach (self::$meta as $identifier => $meta) {
            if ($bizType !== '' && $bizType === trim((string) ($meta['paid_biz_type'] ?? ''))) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $args
     */
    public function invokeAll(string $method, array $args = [], mixed $default = null): mixed
    {
        if ($method === 'unionPurchasePart') {
            $parts = [];
            foreach ($this->identifiers() as $identifier) {
                $part = $this->invoke($identifier, $method, $args, '');
                if (is_string($part) && trim($part) !== '') {
                    $parts[] = $part;
                }
            }

            return $parts === [] ? $default : implode(' UNION ALL ', $parts);
        }
        if ($method === 'unionPayOrderPart') {
            $parts = [];
            foreach ($this->identifiers() as $identifier) {
                $part = $this->invoke($identifier, $method, $args, '');
                if (is_string($part) && trim($part) !== '') {
                    $parts[] = $part;
                }
            }

            return $parts === [] ? $default : implode(' UNION ALL ', $parts);
        }

        foreach ($this->identifiers() as $identifier) {
            $result = $this->invoke($identifier, $method, $args, null);
            if ($result !== null) {
                return $result;
            }
        }

        return $default;
    }

    public function isRegistered(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));

        return $identifier !== '' && isset(self::$helpers[$identifier]);
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_keys(self::$helpers);
    }

    /**
     * @param list<mixed> $args
     */
    public function invoke(string $identifier, string $method, array $args = [], mixed $default = null): mixed
    {
        $identifier = strtolower(trim($identifier));
        $service    = self::$helpers[$identifier] ?? '';
        if ($identifier === '' || $service === '') {
            return $default;
        }

        return PluginWeappAccess::invokeStatic($identifier, $service, $method, $args, $default);
    }
}
