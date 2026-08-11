<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use think\facade\Cookie;
use think\facade\Request;

/** 多轮搜索会话：叠加 filter 条件（P3） */
final class SmartSearchSessionService
{

    public function __construct(
        private readonly SmartSearchConfigService $smartSearch,
    ) {
    }

    private const COOKIE = 'pv_smart_search_ctx';

    /** Cookie 解码上限，防止 base64+json 翻倍撑爆内存 */
    private const MAX_COOKIE_BYTES = 8192;

    public function clear(): void
    {
        Cookie::delete(self::COOKIE);
    }

    /**
     * @return array{show:int,keyword:string,filters:list<array{key:string,label:string,value:string}>,clear_url:string,append_url:string}
     */
    public function barForTemplate(string $currentKeyword): array
    {
        $empty = [
            'show'        => 0,
            'keyword'     => '',
            'filters'     => [],
            'clear_url'   => '/search',
            'append_url'  => '',
        ];
        if (!$this->smartSearch->sessionEnabled()) {
            return $empty;
        }
        $ctx = $this->read();
        $last = trim((string) ($ctx['last_keyword'] ?? ''));
        $filters = is_array($ctx['filters'] ?? null) ? $ctx['filters'] : [];
        if ($last === '' && $filters === [] && trim($currentKeyword) === '') {
            return $empty;
        }
        $chipRows = [];
        foreach ($filters as $key => $val) {
            $key = (string) $key;
            $val = trim((string) $val);
            if ($key === '' || $val === '') {
                continue;
            }
            $chipRows[] = ['key' => $key, 'label' => $key, 'value' => $val];
        }

        return [
            'show'       => 1,
            'keyword'    => $last !== '' ? $last : trim($currentKeyword),
            'filters'    => $chipRows,
            'clear_url'  => '/search?search_clear=1',
            'append_url' => '/search?search_append=1&q=',
        ];
    }

    /**
     * @return array{keyword:string,filters:array<string,string>}
     */
    public function mergeKeyword(string $keyword): array
    {
        $keyword = trim($keyword);
        if ((int) Request::get('search_clear', 0) === 1) {
            $this->clear();
            return ['keyword' => '', 'filters' => []];
        }
        if (!$this->smartSearch->sessionEnabled()) {
            return ['keyword' => $keyword, 'filters' => []];
        }
        $ctx = $this->read();
        $append = (int) Request::param('search_append', 0) === 1
            || str_starts_with($keyword, '+')
            || str_starts_with($keyword, '还要')
            || str_starts_with($keyword, '再要');
        if ($append) {
            $keyword = ltrim($keyword, '+');
            foreach (['还要', '再要', '并且', '另外'] as $prefix) {
                if (str_starts_with($keyword, $prefix)) {
                    $keyword = trim(mb_substr($keyword, mb_strlen($prefix)));
                }
            }
        }
        $filters = is_array($ctx['filters'] ?? null) ? $ctx['filters'] : [];
        if ($append && $filters !== []) {
            $keyword = trim(($ctx['last_keyword'] ?? '') . ' ' . $keyword);
        }

        return ['keyword' => $keyword, 'filters' => $filters];
    }

    /**
     * @param array{filters?:array<string,string>,soft_filters?:array<string,string>,keyword?:string} $parsed
     */
    public function persist(string $keyword, array $parsed): void
    {
        if (!$this->smartSearch->sessionEnabled()) {
            return;
        }
        $filters = is_array($parsed['filters'] ?? null) ? $parsed['filters'] : [];
        $soft    = is_array($parsed['soft_filters'] ?? null) ? $parsed['soft_filters'] : [];
        $merged  = array_merge($filters, $soft);
        if ($merged === [] && trim($keyword) === '') {
            return;
        }
        $this->write([
            'last_keyword' => trim($keyword),
            'filters'      => $merged,
            'updated_at'   => time(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $raw = (string) Cookie::get(self::COOKIE, '');
        if ($raw === '' || strlen($raw) > self::MAX_COOKIE_BYTES) {
            return [];
        }
        $decoded = json_decode(base64_decode($raw, true) ?: '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function write(array $ctx): void
    {
        $json = json_encode($ctx, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        Cookie::set(self::COOKIE, base64_encode($json), [
            'expire'   => time() + 86400,
            'path'     => '/',
            'httponly' => true,
            'secure'   => (bool) config('cookie.secure', false),
            'samesite' => (string) (config('cookie.samesite') ?: 'lax'),
        ]);
    }
}
