<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\item;

use think\facade\Config;
use think\db\Query;

/** 品项渠道可见性（SSOT config/kernel/item_public_visibility.php · flags.web_visible） */
final class ItemPublicVisibilityService
{

    public const CHANNEL_WWW  = 'www';
    public const CHANNEL_COMMERCE = 'commerce';
    public const CHANNEL_ADMIN = 'admin';

    /**
     * @param array<string, mixed> $row items 表行或 formatAdminRow 结果
     */
    public function isVisibleOnChannel(array $row, string $channel = self::CHANNEL_WWW): bool
    {
        $cfg = $this->channelConfig($channel);
        if ($cfg === []) {
            return true;
        }

        $status = (string) ($row['status'] ?? '');
        $allowed = $cfg['require_status'] ?? [];
        if (is_array($allowed) && $allowed !== [] && !in_array($status, $allowed, true)) {
            return false;
        }

        if (!empty($cfg['require_web_visible']) && !$this->resolveWebVisible($row, $cfg)) {
            return false;
        }

        if (!empty($cfg['require_sellable']) && !$this->flagTruthy($row, 'sellable')) {
            return false;
        }

        if (!empty($cfg['require_primary_document']) && (int) ($row['primary_document_id'] ?? 0) < 1) {
            return false;
        }

        if (!empty($cfg['require_slug']) && trim((string) ($row['slug'] ?? '')) === '') {
            return false;
        }

        return true;
    }

    public function isVisibleOnWww(array $row): bool
    {
        return $this->isVisibleOnChannel($row, self::CHANNEL_WWW);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function adminVisibilityMeta(array $row): array
    {
        $www = $this->isVisibleOnWww($row);

        return [
            'www_visible'      => $www,
            'www_visible_text' => $www ? '是' : '否',
        ];
    }

    /** @return array<string, mixed> */
    public function rulesPayload(): array
    {
        return [
            'default_channel' => (string) Config::get('item_public_visibility.default_channel', self::CHANNEL_WWW),
            'channels'        => Config::get('item_public_visibility.channels', []),
            'admin'           => Config::get('item_public_visibility.admin', []),
        ];
    }

    /**
     * @template T of Query
     * @param T $query
     * @return T
     */
    public function applyToQuery(Query $query, string $channel = self::CHANNEL_WWW): Query
    {
        $cfg = $this->channelConfig($channel);
        if ($cfg === []) {
            return $query;
        }

        $allowed = $cfg['require_status'] ?? [];
        if (is_array($allowed) && $allowed !== []) {
            $query->whereIn('status', $allowed);
        }

        if (!empty($cfg['require_web_visible'])) {
            $query->whereRaw($this->webVisibleSql($cfg));
        }

        if (!empty($cfg['require_sellable'])) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`flags`, '$.sellable')) = '1'");
        }

        if (!empty($cfg['require_primary_document'])) {
            $query->where('primary_document_id', '>', 0);
        }

        if (!empty($cfg['require_slug'])) {
            $query->where('slug', '<>', '')->whereNotNull('slug');
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $channelCfg
     */
    private function resolveWebVisible(array $row, array $channelCfg): bool
    {
        $flags = $this->normalizeFlags($row);
        if (array_key_exists('web_visible', $flags)) {
            return $this->flagTruthy($row, 'web_visible');
        }

        if (!empty($channelCfg['legacy_infer_from_sellable'])) {
            return $this->flagTruthy($row, 'sellable');
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function flagTruthy(array $row, string $key): bool
    {
        $flags = $this->normalizeFlags($row);
        if (array_key_exists($key, $flags)) {
            return !empty($flags[$key]);
        }

        $flat = 'flag_' . $key;
        if (array_key_exists($flat, $row)) {
            return !empty($row[$flat]);
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function normalizeFlags(array $row): array
    {
        $flags = $row['flags'] ?? [];
        if (is_string($flags) && $flags !== '') {
            $decoded = json_decode($flags, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($flags) ? $flags : [];
    }

    /** @param array<string, mixed> $channelCfg */
    private function webVisibleSql(array $channelCfg): string
    {
        if (!empty($channelCfg['legacy_infer_from_sellable'])) {
            return "(JSON_UNQUOTE(JSON_EXTRACT(`flags`, '$.web_visible')) = '1'"
                . " OR (JSON_EXTRACT(`flags`, '$.web_visible') IS NULL"
                . " AND JSON_UNQUOTE(JSON_EXTRACT(`flags`, '$.sellable')) = '1'))";
        }

        return "JSON_UNQUOTE(JSON_EXTRACT(`flags`, '$.web_visible')) = '1'";
    }

    /** @return array<string, mixed> */
    private function channelConfig(string $channel): array
    {
        $channel = strtolower(trim($channel));
        $all     = Config::get('item_public_visibility.channels', []);
        if (!is_array($all)) {
            return [];
        }
        $cfg = $all[$channel] ?? [];

        return is_array($cfg) ? $cfg : [];
    }
}
