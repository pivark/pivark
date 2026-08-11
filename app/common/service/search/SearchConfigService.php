<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\search;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
use app\common\service\content\ContentSearchService;

/** 全文检索引擎 / 搜索模式 / 限流 / 敏感词 */
class SearchConfigService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    /** 破环：ContentSearchService → SearchDriverFactory → SearchConfigService */
    private function contentSearch(): ContentSearchService
    {
        return app(ContentSearchService::class);
    }

    private function driverFactory(): SearchDriverFactory
    {
        return app(SearchDriverFactory::class);
    }

    public const MODE_TITLE_SEG   = 'title_seg';
    public const MODE_TITLE_EXACT = 'title_exact';
    public const MODE_FUZZY       = 'fuzzy';

    public const TOKEN_ANY = 'any';
    public const TOKEN_ALL = 'all';

    /** @return list<string> */
    public const DRIVER_SQL     = 'sql';
    public const DRIVER_MEILI   = 'meili';
    public const DRIVER_ELASTIC = 'elastic';

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'search_driver',
            'search_meili_host',
            'search_meili_key',
            'search_meili_index',
            'search_meili_timeout',
            'search_elastic_hosts',
            'search_elastic_index',
            'search_elastic_user',
            'search_elastic_pass',
            'search_mode',
            'search_token_match',
            'search_rate_max',
            'search_rate_window',
            'search_lock_seconds',
            'search_blocked_tag_ids',
            'search_sensitive_chars',
            'search_async_index',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) $this->config->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'search_driver'          => self::DRIVER_SQL,
            'search_meili_host'      => 'http://127.0.0.1:7700',
            'search_meili_key'       => '',
            'search_meili_index'     => 'documents',
            'search_meili_timeout'   => '15',
            'search_elastic_hosts'   => 'http://127.0.0.1:9200',
            'search_elastic_index'   => 'pivark_documents',
            'search_elastic_user'    => '',
            'search_elastic_pass'    => '',
            'search_mode'            => self::MODE_TITLE_SEG,
            'search_token_match'     => self::TOKEN_ANY,
            'search_rate_max'        => '5',
            'search_rate_window'     => '60',
            'search_lock_seconds'    => '120',
            'search_blocked_tag_ids' => '',
            'search_sensitive_chars' => '<>";\'@&#\\',
            'search_async_index'     => '1',
            default                  => '',
        };
    }

    /** Meili/Elastic 下是否异步写索引（减轻主库写路径延迟） */
    public function asyncIndexEnabled(): bool
    {
        return (string) $this->config->get('search_async_index', '1') === '1';
    }

    public function driver(): string
    {
        $v = (string) $this->config->get('search_driver', self::DRIVER_SQL);

        return in_array($v, [self::DRIVER_SQL, self::DRIVER_MEILI, self::DRIVER_ELASTIC], true)
            ? $v
            : self::DRIVER_SQL;
    }

    /** @return array{hosts:string,index:string,user:string,pass:string} */
    public function elasticConfig(): array
    {
        return [
            'hosts' => trim((string) $this->config->get('search_elastic_hosts', $this->defaultFor('search_elastic_hosts'))),
            'index' => trim((string) $this->config->get('search_elastic_index', 'pivark_documents')) ?: 'pivark_documents',
            'user'  => (string) $this->config->get('search_elastic_user', ''),
            'pass'  => (string) $this->config->get('search_elastic_pass', ''),
        ];
    }

    /** @return array{host:string,key:string,index:string,timeout:int} */
    public function meiliConfig(): array
    {
        $timeout = max(3, min(120, (int) $this->config->get(
            'search_meili_timeout',
            $this->defaultFor('search_meili_timeout')
        )));

        return [
            'host'    => rtrim((string) $this->config->get('search_meili_host', $this->defaultFor('search_meili_host')), '/'),
            'key'     => (string) $this->config->get('search_meili_key', ''),
            'index'   => (string) $this->config->get('search_meili_index', 'documents') ?: 'documents',
            'timeout' => $timeout,
        ];
    }

    public function mode(): string
    {
        $mode = (string) $this->config->get('search_mode', self::MODE_TITLE_SEG);

        return in_array($mode, [self::MODE_TITLE_SEG, self::MODE_TITLE_EXACT, self::MODE_FUZZY], true)
            ? $mode
            : self::MODE_TITLE_SEG;
    }

    public function tokenMatch(): string
    {
        $v = (string) $this->config->get('search_token_match', self::TOKEN_ANY);

        return $v === self::TOKEN_ALL ? self::TOKEN_ALL : self::TOKEN_ANY;
    }

    /** @return array{enabled:bool,max_requests:int,window_seconds:int,lock_seconds:int} */
    public function rateLimitConfig(): array
    {
        $policy = app(\app\common\service\infra\RateLimitRegistry::class)->resolve('front.search');
        if ($policy === null) {
            return [
                'enabled'         => true,
                'max_requests'    => 5,
                'window_seconds'  => 60,
                'lock_seconds'    => 120,
            ];
        }

        return [
            'enabled'         => (bool) ($policy['enabled'] ?? true),
            'max_requests'    => (int) ($policy['max_requests'] ?? 5),
            'window_seconds'  => (int) ($policy['window_seconds'] ?? 60),
            'lock_seconds'    => (int) ($policy['lock_seconds'] ?? 120),
        ];
    }

    /** @return list<int> */
    public function blockedTagIds(): array
    {
        $raw = (string) $this->config->get('search_blocked_tag_ids', '');
        $ids = [];
        foreach (preg_split('/[\s,，]+/', $raw) ?: [] as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    public function sensitiveChars(): array
    {
        $raw = (string) $this->config->get('search_sensitive_chars', $this->defaultFor('search_sensitive_chars'));
        if ($raw === '') {
            return [];
        }
        $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($chars) ? $chars : [];
    }

    public function isAiAnswerEnabled(): bool
    {
        return (string) $this->config->get('search_ai_answer_on', '0') === '1';
    }

    public function aiDocLimit(): int
    {
        return max(3, min(20, (int) $this->config->get('search_ai_doc_limit', '8')));
    }

    /**
     * @return ServiceResult|null
     */
    public function guardKeyword(string $keyword): ?ServiceResult
    {
        $keyword = $this->contentSearch()->normalizeKeyword($keyword);
        if ($keyword === '') {
            return null;
        }
        foreach ($this->sensitiveChars() as $ch) {
            if ($ch !== '' && mb_strpos($keyword, $ch) !== false) {
                return ServiceResult::fail('搜索词包含不允许的字符');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $mode = (string) ($data['search_mode'] ?? self::MODE_TITLE_SEG);
        if (!in_array($mode, [self::MODE_TITLE_SEG, self::MODE_TITLE_EXACT, self::MODE_FUZZY], true)) {
            $mode = self::MODE_TITLE_SEG;
        }
        $token = (string) ($data['search_token_match'] ?? self::TOKEN_ANY);
        if (!in_array($token, [self::TOKEN_ANY, self::TOKEN_ALL], true)) {
            $token = self::TOKEN_ANY;
        }

        $driver = (string) ($data['search_driver'] ?? self::DRIVER_MEILI);
        if (!in_array($driver, [self::DRIVER_SQL, self::DRIVER_MEILI, self::DRIVER_ELASTIC], true)) {
            $driver = self::DRIVER_MEILI;
        }

        $payload = [
            'search_driver'          => $driver,
            'search_meili_host'      => rtrim(trim((string) ($data['search_meili_host'] ?? '')), '/'),
            'search_meili_key'       => trim((string) ($data['search_meili_key'] ?? '')),
            'search_meili_index'     => trim((string) ($data['search_meili_index'] ?? 'documents')) ?: 'documents',
            'search_elastic_hosts'   => trim((string) ($data['search_elastic_hosts'] ?? '')),
            'search_elastic_index'   => trim((string) ($data['search_elastic_index'] ?? 'pivark_documents')) ?: 'pivark_documents',
            'search_elastic_user'    => trim((string) ($data['search_elastic_user'] ?? '')),
            'search_elastic_pass'    => (string) ($data['search_elastic_pass'] ?? ''),
            'search_mode'            => $mode,
            'search_token_match'     => $token,
            'search_rate_max'        => (string) max(1, (int) ($data['search_rate_max'] ?? 5)),
            'search_rate_window'     => (string) max(1, (int) ($data['search_rate_window'] ?? 60)),
            'search_lock_seconds'    => (string) max(1, (int) ($data['search_lock_seconds'] ?? 120)),
            'search_blocked_tag_ids' => trim((string) ($data['search_blocked_tag_ids'] ?? '')),
            'search_sensitive_chars' => (string) ($data['search_sensitive_chars'] ?? ''),
        ];
        foreach ($payload as $key => $value) {
            $this->config->set($key, $value);
        }

        $this->config->forgetRequestCache();
        $this->driverFactory()->reset();

        return ServiceResult::ok(null, '搜索配置已保存');
    }
}
