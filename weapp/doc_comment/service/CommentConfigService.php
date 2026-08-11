<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace weapp\doc_comment\service;


use app\common\service\weapp\WeappConfigGateway;
use app\common\service\weapp\WeappPluginGateway;
use app\common\service\weapp\WeappSupportGateway;


/** 评论插件全局配置（configs.comment_*） */
class CommentConfigService
{
    public const SENSITIVE_REPLACE = 'replace';
    public const SENSITIVE_REVIEW  = 'review';
    public const SENSITIVE_BLOCK   = 'block';

    /** @return list<string> */
    public static function keys(): array
    {
        return [
            'comment_open',
            'comment_guest_allowed',
            'comment_sensitive_mode',
            'comment_sensitive_words',
            'comment_page_size',
        ];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = (string) app(WeappConfigGateway::class)->configGet($key, self::defaultFor($key));
        }

        return $out;
    }

    public static function defaultFor(string $key): string
    {
        return match ($key) {
            'comment_open'            => '1',
            'comment_guest_allowed'   => '0',
            'comment_sensitive_mode'  => self::SENSITIVE_REPLACE,
            'comment_sensitive_words' => '',
            'comment_page_size'       => '10',
            default                   => '',
        };
    }

    public static function isOpen(): bool
    {
        return (string) app(WeappConfigGateway::class)->configGet('comment_open', '1') === '1';
    }

    public static function guestAllowed(): bool
    {
        return (string) app(WeappConfigGateway::class)->configGet('comment_guest_allowed', '0') === '1';
    }

    public static function pageSize(): int
    {
        return max(5, min(50, (int) app(WeappConfigGateway::class)->configGet('comment_page_size', 10)));
    }

    /** @return list<string> */
    public static function sensitiveWords(): array
    {
        $raw = (string) app(WeappConfigGateway::class)->configGet('comment_sensitive_words', '');
        $parts = preg_split('/[,，\s]+/u', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    public static function sensitiveMode(): string
    {
        $mode = (string) app(WeappConfigGateway::class)->configGet('comment_sensitive_mode', self::SENSITIVE_REPLACE);

        return in_array($mode, [self::SENSITIVE_REPLACE, self::SENSITIVE_REVIEW, self::SENSITIVE_BLOCK], true)
            ? $mode
            : self::SENSITIVE_REPLACE;
    }

    /**
     * @param array<string, mixed> $data
     * @return mixed
     */
    public static function saveAdmin(array $data)
    {
        $mode = (string) ($data['comment_sensitive_mode'] ?? self::SENSITIVE_REPLACE);
        if (!in_array($mode, [self::SENSITIVE_REPLACE, self::SENSITIVE_REVIEW, self::SENSITIVE_BLOCK], true)) {
            $mode = self::SENSITIVE_REPLACE;
        }

        $payload = [
            'comment_open'            => !empty($data['comment_open']) ? '1' : '0',
            'comment_guest_allowed'   => !empty($data['comment_guest_allowed']) ? '1' : '0',
            'comment_sensitive_mode'  => $mode,
            'comment_sensitive_words' => trim((string) ($data['comment_sensitive_words'] ?? '')),
            'comment_page_size'       => (string) max(5, min(50, (int) ($data['comment_page_size'] ?? 10))),
        ];
        foreach ($payload as $key => $value) {
            app(WeappConfigGateway::class)->configSet($key, $value);
        }
        // 游客开关与等级表 member_level_id=0 对齐，避免开了游客仍被 level_denied
        CommentService::syncLevelRows();
        app(WeappPluginGateway::class)->pluginAfterConfigSaved(
            'all'
        );

        return app(WeappSupportGateway::class)->resultOk(null, '配置已保存');
    }
}