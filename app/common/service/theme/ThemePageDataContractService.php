<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\theme;

use app\common\service\plugin\extension\PluginPortalInvoke;

use app\common\support\OpsLog;
use app\common\support\ProjectPaths;

/** 读取 theme.json data_contract，按模板自动注入页面业务变量 */
final class ThemePageDataContractService
{

    public function __construct(
        private readonly ThemeService $themeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function readContract(?string $themeId = null): array
    {
        $themeId = $themeId ?? $this->themeService->getCurrentTheme();

        return $this->contractFromTheme($themeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function contractFromTheme(string $themeId): array
    {
        $meta     = $this->themeService->readMeta($themeId);
        $contract = $meta['data_contract'] ?? [];

        return is_array($contract) ? $contract : [];
    }

    /**
     * parse 缓存指纹：契约 roots + parse_cache.content_hash_keys（替代内核硬编码 www_* 前缀）
     *
     * @return list<string>
     */
    public function parseCacheContentHashKeys(?string $themeId = null): array
    {
        $contract  = $this->readContract($themeId);
        $keys      = [];
        $templates = $contract['templates'] ?? [];
        if (is_array($templates)) {
            foreach ($templates as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach ($entry['roots'] ?? [] as $root) {
                    if (is_string($root) && $root !== '') {
                        $keys[$root] = true;
                    }
                }
            }
        }
        $parseCache = $contract['parse_cache'] ?? [];
        if (is_array($parseCache)) {
            foreach ($parseCache['content_hash_keys'] ?? [] as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * 内部探针：首页模板声明的 home_* 配置槽名（扫变量）。
     * 栏目意图靠 seed/主题默认/迁站写入；取数命中靠换主题后缓存预热——勿接运营后台勾选面。
     *
     * @return list<string> 如 home_news_nav_id
     */
    public function listHomeBindingSlotKeys(?string $themeId = null): array
    {
        $themeId = $themeId ?? $this->themeService->getCurrentTheme();
        $home = ProjectPaths::root() . 'template/' . $themeId . '/pc/home.php';
        if (!is_file($home)) {
            $home = ProjectPaths::root() . 'template/' . $themeId . '/home.php';
        }
        if (!is_file($home)) {
            return [];
        }
        $body = (string) file_get_contents($home);
        if ($body === '') {
            return [];
        }
        $keys = [];
        if (preg_match_all('/\{\$?home_([a-z0-9_]+)_nav_id\}/', $body, $m)) {
            foreach ($m[1] as $slot) {
                $keys['home_' . $slot . '_nav_id'] = true;
            }
        }
        if (preg_match_all('/\$home_([a-z0-9_]+)_nav_id/', $body, $m2)) {
            foreach ($m2[1] as $slot) {
                $keys['home_' . $slot . '_nav_id'] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @return array{required: list<string>, missing: list<string>, bound: array<string, int>}
     */
    public function homeBindingStatus(?string $themeId = null): array
    {
        $required = $this->listHomeBindingSlotKeys($themeId);
        $bound = [];
        $missing = [];
        $cfg = app(\app\common\service\config\ConfigService::class);
        foreach ($required as $key) {
            $id = (int) $cfg->get($key, 0);
            $bound[$key] = $id;
            if ($id < 1) {
                $missing[] = $key;
            }
        }

        return [
            'required' => $required,
            'missing'  => $missing,
            'bound'    => $bound,
        ];
    }

    /**
     * 模板文件名（如 list_page_devkit.php / home.php）→ data_contract.templates 绑定
     *
     * @return array<string, mixed>|null
     */
    public function bindingForTemplateFile(string $filename): ?array
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $templates = $this->readContract()['templates'] ?? [];
        if (!is_array($templates)) {
            return null;
        }
        $entry = $templates[$basename] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * 单页 tpl_name（如 list_product_templates）→ 契约绑定
     *
     * @return array<string, mixed>|null
     */
    public function bindingForTpl(string $tpl): ?array
    {
        $tpl = trim($tpl);
        if ($tpl === '') {
            return null;
        }

        return $this->bindingForTemplateFile(
            str_ends_with($tpl, '.php') ? $tpl : $tpl . '.php'
        );
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $context route_id、page 等
     *
     * @return array<string, mixed>
     */
    public function mergeTplVars(string $tpl, array $vars, array $context = []): array
    {
        $binding = $this->bindingForTpl($tpl);
        if ($binding === null) {
            return $vars;
        }

        $extra = $this->invokeBinding($binding, $context);
        if ($extra === []) {
            // provider/service 失败时仍注入空根，避免「当前展示  个插件」式空壳露白
            return array_merge($vars, $this->emptyBindingFallback($binding));
        }

        return array_merge($vars, $extra);
    }

    /**
     * 契约 roots 空默认：列表根 → [] + *_empty=1；*_items 再补 shown_count/total=0
     *
     * @param array<string, mixed> $binding
     *
     * @return array<string, mixed>
     */
    private function emptyBindingFallback(array $binding): array
    {
        $out = [];
        foreach ($binding['roots'] ?? [] as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $out[$root] = [];
            if (!str_ends_with($root, '_empty')) {
                $out[$root . '_empty'] = 1;
            }
            if (str_ends_with($root, '_items')) {
                $prefix = substr($root, 0, -strlen('_items'));
                $out[$prefix . '_shown_count'] = 0;
                $out[$prefix . '_total'] = 0;
            }
        }

        return $out;
    }

    /**
     * 路径级注入（如首页 market_plugins）
     *
     * @return array<string, mixed>
     */
    public function mergePathExtras(string $path): array
    {
        $path = rtrim($path, '/') ?: '/';
        $out  = [];

        if ($path === '/') {
            $binding = $this->bindingForTemplateFile('home.php');
            if ($binding !== null) {
                $out = array_merge($out, $this->invokeBinding($binding, []));
            }
            if (($out['market_plugins'] ?? []) === []) {
                $out = array_merge($out, $this->safeHomePluginsVars());
            }
        }

        if ($path === '/pricing') {
            $binding = $this->bindingForTemplateFile('list_product_pricing.php');
            if ($binding !== null) {
                $out = array_merge($out, $this->invokeBinding($binding, []));
            }
        }

        if ($path === '/faq' && PluginPortalInvoke::portalClassExists('FaqDefaultsService')) {
            $out['faq_default_html'] = (string) (PluginPortalInvoke::portalInvoke('FaqDefaultsService', 'html') ?? '');
        }

        if ($path !== '/') {
            $out['market_plugins']       = $out['market_plugins'] ?? [];
            $out['market_plugins_empty'] = $out['market_plugins_empty'] ?? 1;
        }

        return $out;
    }

    /**
     * data_contract 详情页变量（context.route_id = URL 段）
     *
     * @return array<string, mixed>|null
     */
    public function detailVars(string $templateFile, string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $binding = $this->bindingForTemplateFile($templateFile);
        if ($binding === null) {
            return null;
        }

        $vars = $this->invokeBinding($binding, ['route_id' => $identifier]);
        if ($vars === []) {
            return null;
        }

        return $vars;
    }

    /**
     * @return array{template: string, vars: array<string, mixed>}|null
     */
    public function detailRenderPayload(string $templateFile, string $identifier): ?array
    {
        $vars = $this->detailVars($templateFile, $identifier);
        if ($vars === null) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $templateFile));
        $template = str_ends_with($basename, '.php')
            ? substr($basename, 0, -4)
            : $basename;

        return ['template' => $template, 'vars' => $vars];
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function invokeBinding(array $binding, array $context): array
    {
        $provider = trim((string) ($binding['provider'] ?? ''));
        if ($provider !== '') {
            return app(ThemeDataProviderService::class)->resolve($provider, $context);
        }

        $service = trim((string) ($binding['service'] ?? ''));
        $method  = trim((string) ($binding['method'] ?? ''));
        if ($service === '') {
            return [];
        }

        if (str_contains($service, '+')) {
            return [];
        }

        $class = $this->resolveServiceClass($service);
        if ($class === null || !class_exists($class)) {
            return [];
        }

        $instance = app($class);

        if ($method === '') {
            return [];
        }

        return $this->dispatchMethod($class, $method, $instance, $context);
    }

    private function resolveServiceClass(string $service): ?string
    {
        $short = ltrim($service, '\\');
        if (str_starts_with($short, 'app\\')) {
            $rel = str_replace('\\', '/', $short) . '.php';

            return is_file(ProjectPaths::root() . $rel) && class_exists($short) ? $short : null;
        }

        return PluginPortalInvoke::portalFqcn($short);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function dispatchMethod(string $class, string $method, object $instance, array $context): array
    {
        if (!method_exists($instance, $method)) {
            return [];
        }

        try {
            $ref    = new \ReflectionMethod($instance, $method);
            $result = $ref->invokeArgs($instance, $this->buildMethodArgs($ref, $context));

            if ($result === null) {
                return [];
            }

            if ($method === 'marketPluginsForHome' && is_array($result) && array_is_list($result)) {
                return $this->wrapHomePlugins($result);
            }

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            OpsLog::businessWarning('theme_page_data_contract_dispatch_failed', [
                'key' => $class . '::' . $method,
                'msg' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<mixed>
     */
    private function buildMethodArgs(\ReflectionMethod $ref, array $context): array
    {
        $args = [];
        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            $args[] = match ($name) {
                'category', 'cat' => strtolower(trim((string) ($context['cat'] ?? request()->get('cat', 'all')))),
                'keyword', 'q' => trim((string) ($context['q'] ?? request()->get('q', ''))),
                'id', 'identifier', 'route_id' => (string) ($context['route_id'] ?? $context['identifier'] ?? ''),
                'tab' => trim((string) ($context['tab'] ?? request()->get('tab', 'official'))),
                'sort' => trim((string) ($context['sort'] ?? request()->get('sort', 'latest'))),
                'page' => max(1, (int) ($context['page'] ?? request()->get('page', 1))),
                'limit' => max(1, (int) ($context['limit'] ?? 8)),
                default => $param->isDefaultValueAvailable()
                    ? $param->getDefaultValue()
                    : ($param->allowsNull() ? null : ''),
            };
        }

        return $args;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function wrapHomePlugins(array $rows): array
    {
        return [
            'market_plugins'       => $rows,
            'market_plugins_empty' => $rows === [] ? 1 : 0,
        ];
    }

    /**
     * 首页插件数据：契约或回退失败时降级为空列表，禁止拖垮整页。
     *
     * @return array<string, mixed>
     */
    private function safeHomePluginsVars(): array
    {
        try {
            $rows = PluginPortalInvoke::portalInvoke('HomeCatalogService', 'marketPluginsForHome');
            return $this->wrapHomePlugins(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            OpsLog::businessWarning('theme_page_data_home_plugins_failed', [
                'msg' => $e->getMessage(),
            ]);

            return $this->wrapHomePlugins([]);
        }
    }
}
