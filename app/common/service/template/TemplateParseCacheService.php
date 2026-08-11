<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\support\MoneyMath;
use app\common\service\template\TemplateEngineState;

use app\common\service\front\FrontAuthService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\site\SiteNavService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\theme\ThemePageDataContractService;
use app\common\service\theme\ThemeService;

final class TemplateParseCacheService
{

    /** 写入 parse 缓存前替换为占位符，读回时再注入当次 CSRF */
    private const CSRF_PLACEHOLDER = '__PV_CSRF_PLACEHOLDER__';

    /** 不参与 parse 缓存键的每请求变量 */
    private const VOLATILE_FINGERPRINT_KEYS = [
        'front_csrf_token',
    ];

    /**
     * 由导航/配置派生的 HTML 或大块 JSON，纳入指纹会迫使全量 siteVars；
     * 失效由 fc_gen + nav + member 等上下文保证。
     *
     * @var list<string>
     */
    private const DERIVED_HTML_FINGERPRINT_KEYS = [
        'site_nav_html',
        'float_contact_html',
        'site_map_html',
        'site_overlay_ads_html',
        'public_docs_manifest_json',
        'front_script_urls_json',
    ];

    public function __construct(
        private readonly TemplateEngineState $templateEngineState,
        private readonly SiteNavService $siteNavService,
        private readonly FrontAuthService $frontAuthService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly ThemePageDataContractService $themePageDataContract,
        private readonly ThemeService $themeService,
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly SeoStaticConfigService $seoStaticConfigService,
    ) {
    }

    /** @var array<string, true>|null */
    private ?array $contentHashKeySet = null;

    private const PREFIX = 'pv_tpl_parse_';

    public function ttl(): int
    {
        return max(0, (int) config('pivark.tpl_parse_cache_ttl', 86400));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function get(string $theme, string $templatePath, array $pageVars): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $key = $this->cacheKey($theme, $templatePath, $pageVars);
        $hit = \think\facade\Cache::get($key);
        if (!is_string($hit) || $hit === '') {
            return null;
        }

        return $hit;
    }

    public function set(string $theme, string $templatePath, array $pageVars, string $html): void
    {
        if (!$this->enabled() || $html === '') {
            return;
        }

        \think\facade\Cache::set($this->cacheKey($theme, $templatePath, $pageVars), $html, $this->ttl());
    }

    public function clearAll(): void
    {
        // ThinkPHP file 驱动无 tag 批量删；与页缓存一并由运维清 runtime/cache
        // 内容变更走 clearPageCache → runtime/cache 清理见 SiteModeService::clearRuntimeCaches
    }

    /**
     * 影响导航/会员态的标量指纹（不含列表正文数据）
     */
    public function pageVarsFingerprint(array $pageVars): string
    {
        $contentHashKeys = $this->contentHashKeySet();
        $slice = [];
        foreach ($pageVars as $key => $val) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, self::VOLATILE_FINGERPRINT_KEYS, true)
                || in_array($key, self::DERIVED_HTML_FINGERPRINT_KEYS, true)) {
                continue;
            }
            if (isset($contentHashKeys[$key])) {
                $slice[$key] = hash('sha256', json_encode($val, JSON_UNESCAPED_UNICODE) ?: '');
                continue;
            }
            if (is_scalar($val) || $val === null) {
                $slice[$key] = $val;
                continue;
            }
            if (is_array($val)) {
                $slice[$key] = $this->arrayFingerprint($key, $val);
            }
        }
        ksort($slice);

        return hash('sha256', json_encode($slice, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, true> */
    private function contentHashKeySet(): array
    {
        if ($this->contentHashKeySet !== null) {
            return $this->contentHashKeySet;
        }
        $keys = $this->themePageDataContract->parseCacheContentHashKeys(
            $this->themeService->getCurrentTheme()
        );

        return $this->contentHashKeySet = array_fill_keys($keys, true);
    }

    /**
     * 会员充值等动态列表：条数不变时 price 仍会改，须纳入指纹。
     */
    private function arrayFingerprint(string $key, array $val): string
    {
        if ($key === 'member_recharge_packages'
            || $key === 'member_recommended_packages'
            || str_starts_with($key, 'member_recharge_packages_')) {
            $parts = [];
            foreach ($val as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parts[] = (int) ($row['id'] ?? 0) . ':'
                    . MoneyMath::formatPlain((float) ($row['price'] ?? 0)) . ':'
                    . (int) ($row['status'] ?? 0);
            }

            return 'recharge:' . implode(',', $parts);
        }

        if ($key === 'member_pay_channel_options'
            || $key === 'member_pay_channel_custom_options'
            || $key === 'member_payment_channels') {
            $parts = [];
            foreach ($val as $row) {
                if (is_array($row)) {
                    $parts[] = (string) ($row['channel'] ?? '');
                } elseif (is_string($row)) {
                    $parts[] = $row;
                }
            }

            return 'paych:' . implode(',', $parts);
        }

        // foreach 当前行（pkg/item/field…）：仅 count 会导致 include 幂等串卡
        if ($val !== [] && !array_is_list($val)
            && (isset($val['id']) || isset($val['title']) || isset($val['price']))) {
            return 'row:' . hash('sha256', json_encode($val, JSON_UNESCAPED_UNICODE) ?: '');
        }

        return 'arr:' . count($val);
    }

    /**
     * {pv:arclist} 等列表标签读 query 串，须纳入解析缓存键。
     *
     * @param array<string, mixed> $queryParams
     */
    public function requestListQueryFingerprint(array $queryParams = []): string
    {
        $slice = [];
        foreach ($queryParams as $k => $v) {
            $key = (string) $k;
            if ($key === 'page') {
                $slice['page'] = max(1, (int) $v);
                continue;
            }
            if ($key === 'keyword' || $key === 'q') {
                $val = trim((string) $v);
                if ($val !== '') {
                    $slice['keyword'] = $val;
                }
                continue;
            }
            if ($key === 'tag') {
                $val = trim((string) $v);
                if ($val !== '') {
                    $slice['tag'] = $val;
                }
                continue;
            }
            if (str_starts_with($key, 'filter_')) {
                $val = trim((string) $v);
                if ($val !== '') {
                    $slice[$key] = $val;
                }
            }
        }
        if ($slice === []) {
            return '';
        }
        ksort($slice);

        return hash('sha256', json_encode($slice, JSON_UNESCAPED_UNICODE));
    }

    private function cacheKey(string $theme, string $templatePath, array $pageVars): string
    {
        $mtime = is_file($templatePath) ? (int) filemtime($templatePath) : 0;
        $ctx   = [
            'theme'     => $theme,
            'path'      => str_replace('\\', '/', $templatePath),
            'mtime'     => $mtime,
            'plugins'   => $this->templateEngineState->extensionTagDetectPart(),
            'nav'       => $this->siteNavService->currentPath(),
            'member'    => $this->frontAuthService->isLoggedIn() ? 1 : 0,
            'vars'      => $this->pageVarsFingerprint($pageVars),
            'listQuery' => $this->requestListQueryFingerprint($this->requestQueryFromPageVars($pageVars)),
            'listPage'  => max(1, (int) ($pageVars['page'] ?? $pageVars['pagination_page'] ?? 1)),
            // 出链形态（动态/伪静态/静态+subdir）变了必须 miss：缓存 HTML 已烘焙 href
            'urlOut'    => $this->urlOutlinkFingerprint(),
        ];

        $ctx['fc_gen'] = $this->frontCacheInvalidator->generation();

        return self::PREFIX . hash('sha256', json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }

    /** URL 模式 / 后缀 / 规则 / 静态子目录 —— 与出站 href 所见即所得对齐 */
    private function urlOutlinkFingerprint(): string
    {
        return hash('sha256', json_encode([
            'mode'    => $this->siteUrlModeService->mode(),
            'suffix'  => $this->siteUrlModeService->suffix(),
            'channel' => $this->siteUrlModeService->channelRule(),
            'tagPage' => $this->siteUrlModeService->tagPageRule(),
            'article' => $this->siteUrlModeService->articleRule(),
            'subdir'  => $this->seoStaticConfigService->subdir(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>
     */
    private function requestQueryFromPageVars(array $pageVars): array
    {
        $query = $pageVars['request_query'] ?? [];

        return is_array($query) ? $query : [];
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function neutralizeVolatileForCache(string $html, array $pageVars): string
    {
        $token = trim((string) ($pageVars['front_csrf_token'] ?? ''));
        if ($token === '') {
            return $html;
        }

        return str_replace($token, self::CSRF_PLACEHOLDER, $html);
    }

    /**
     * @param array<string, mixed> $pageVars
     */
    public function restoreVolatileFromCache(string $html, array $pageVars): string
    {
        $token = trim((string) ($pageVars['front_csrf_token'] ?? ''));
        if ($token === '' || !str_contains($html, self::CSRF_PLACEHOLDER)) {
            return $html;
        }

        return str_replace(
            self::CSRF_PLACEHOLDER,
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
            $html
        );
    }
}
