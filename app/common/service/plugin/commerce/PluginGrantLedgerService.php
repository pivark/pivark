<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\commerce;

use app\common\model\SitePluginWalletLedger;
use app\common\support\AppTime;

final class PluginGrantLedgerService
{
    public const REASON_LIMITED_FREE = 'grant_limited_free';

    /** 与 uk_spwl_plugin_reason_ref 对齐：每插件限免只占一条（ref 固定空串） */
    private const LIMITED_FREE_REF = '';

    public function recordLimitedFree(string $identifier, string $ref = '', string $detail = ''): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        if ($this->hasLimitedFreeGrant($identifier)) {
            return;
        }

        $bits = [];
        if ($ref !== '') {
            $bits[] = 'src=' . mb_substr($ref, 0, 40);
        }
        if ($detail !== '') {
            $bits[] = $detail;
        }
        $detailOut = $bits !== [] ? mb_substr(implode(';', $bits), 0, 255) : null;

        try {
            SitePluginWalletLedger::insert([
                'plugin_identifier' => $identifier,
                'delta'             => 0,
                'balance_after'     => null,
                'reason'            => self::REASON_LIMITED_FREE,
                'ref'               => self::LIMITED_FREE_REF,
                'detail'            => $detailOut,
                'created_at'        => AppTime::now(),
            ]);
        } catch (\Throwable $e) {
            // 并发双写时由 UNIQUE(plugin, reason, ref) 拦下，视为已记过
            if ($this->isUniqueViolation($e)) {
                return;
            }
            throw $e;
        }
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '1062') || stripos($msg, 'Duplicate') !== false) {
            return true;
        }
        $prev = $e->getPrevious();

        return $prev instanceof \Throwable && $this->isUniqueViolation($prev);
    }

    public function hasLimitedFreeGrant(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        return SitePluginWalletLedger::where('plugin_identifier', $identifier)
            ->where('reason', self::REASON_LIMITED_FREE)
            ->count() > 0;
    }
}
