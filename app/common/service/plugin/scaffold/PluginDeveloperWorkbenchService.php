<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\scaffold;

use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\extension\PluginExtensionManifestLoader;
use app\common\service\plugin\extension\PluginExtensionPointCatalogService;

use app\common\support\ServiceResult;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * 插件中心「开发者工作台」：清单校验、内核 Gateway 目录、示例清单。
 */
class PluginDeveloperWorkbenchService
{
    private const MANIFEST_JSON_MAX_BYTES = 65536;

    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
        private readonly PluginScaffoldService $pluginScaffoldService,
        private readonly PluginExtensionPointCatalogService $extensionPointCatalog,
        private readonly PluginReservedIdentifierService $pluginReservedIdentifierService,
        private readonly PluginExtensionManifestLoader $pluginExtensionManifestLoader,
    ) {
    }

    /** @var list<array{gateway:string,label:string,methods:list<array{name:string,summary:string,params:list<array{name:string,type:string,optional:bool}>}>}>|null */
    private static ?array $gatewayCatalogCache = null;

    private const GATEWAY_CATALOG_CACHE_VERSION = 2;

    private static int $gatewayCatalogCacheVersion = 0;

    /**
     * @return array{
     *   title: string,
     *   description: string,
     *   doc_links: list<array{title:string,path:string}>,
     *   kind_cards: list<array<string, mixed>>,
     *   samples: list<array{id:string,title:string,description:string,json:string}>
     * }
     */
    public function meta(): array
    {
        return [
            'title'             => '开发者工作台',
            'description'       => '安装前校验插件清单、查阅官方插件接口、审计 zip 包体。',
            'doc_links'         => $this->docLinks(),
            'kind_cards'        => $this->pluginScaffoldService->scaffoldKindCards(),
            'samples'           => $this->manifestSamples(),
            'identifier_policy' => $this->pluginReservedIdentifierService->namingPolicyMeta(),
        ];
    }

    public function validateManifestJson(string $raw): ServiceResult
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ServiceResult::fail('请粘贴 plugin.json 内容');
        }
        if (strlen($raw) > self::MANIFEST_JSON_MAX_BYTES) {
            return ServiceResult::fail(
                'plugin.json 内容过长（最大 ' . self::MANIFEST_JSON_MAX_BYTES . ' 字节）'
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ServiceResult::fail('JSON 格式错误：' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            return ServiceResult::fail('plugin.json 根节点须为 JSON 对象');
        }

        $structErrors = $this->validateManifestStructure($decoded);
        $docSearch    = null;
        if (array_key_exists('document_search', $decoded)) {
            $docSearch = $decoded['document_search'];
            unset($decoded['document_search']);
        }

        $manifest = $this->pluginManifestService->applyValidation($decoded);
        $errors   = array_values(array_unique(array_merge(
            $structErrors,
            is_array($manifest['_manifest_errors'] ?? null) ? $manifest['_manifest_errors'] : [],
            $this->validateDocumentSearchStatic($decoded, $docSearch),
            $this->pluginExtensionManifestLoader->validate($decoded),
        )));

        $hints = [];
        foreach ($errors as $error) {
            $hint = $this->hintForError((string) $error);
            if ($hint !== null) {
                $hints[] = array_merge(['error' => $error], $hint);
            }
        }

        return ServiceResult::ok([
            'ok'              => $errors === [],
            'errors'          => $errors,
            'hints'           => $hints,
            'publisher_type'  => (string) ($manifest['publisher_type'] ?? ''),
            'publisher_label' => (string) ($manifest['publisher_label'] ?? ''),
            'kind'            => (string) ($manifest['kind'] ?? ''),
            'kind_label'      => $this->pluginManifestService->labelForKind((string) ($manifest['kind'] ?? '')),
        ], $errors === [] ? '清单校验通过' : '清单校验未通过');
    }

    /**
     * @return list<array{gateway:string,label:string,methods:list<array{name:string,summary:string,params:list<array{name:string,type:string,optional:bool}>}>}>
     */
    public function gatewayCatalog(): array
    {
        if (
            self::$gatewayCatalogCache !== null
            && self::$gatewayCatalogCacheVersion === self::GATEWAY_CATALOG_CACHE_VERSION
        ) {
            return self::$gatewayCatalogCache;
        }

        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'weapp';
        $files = glob($root . DIRECTORY_SEPARATOR . 'Weapp*Gateway.php') ?: [];
        sort($files);

        $catalog = [];
        foreach ($files as $file) {
            $base = basename($file, '.php');
            if ($base === 'WeappCoreGateway') {
                continue;
            }
            $class = 'app\\common\\service\\weapp\\' . $base;
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $methods = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->isStatic()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $name = $method->getName();
                if (str_starts_with($name, '__')) {
                    continue;
                }

                $params = [];
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    $typeName = 'mixed';
                    if ($type instanceof ReflectionNamedType) {
                        $typeName = $type->getName();
                    }
                    $params[] = [
                        'name'     => $param->getName(),
                        'type'     => $typeName,
                        'optional' => $param->isOptional(),
                    ];
                }

                $methods[] = [
                    'name'    => $name,
                    'summary' => $this->sanitizeUtf8Text($this->docSummary($method)),
                    'params'  => $params,
                ];
            }

            if ($methods === []) {
                continue;
            }

            usort($methods, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

            $catalog[] = [
                'gateway' => $base,
                'label'   => $this->gatewayLabel($base),
                'methods' => $methods,
            ];
        }

        usort($catalog, static fn (array $a, array $b): int => strcmp((string) $a['gateway'], (string) $b['gateway']));

        self::$gatewayCatalogCache = $catalog;
        self::$gatewayCatalogCacheVersion = self::GATEWAY_CATALOG_CACHE_VERSION;

        return $catalog;
    }

    public function renderGatewayCatalogMarkdown(): string
    {
        $catalog = $this->gatewayCatalog();
        $lines   = [
            '# 插件内核 Gateway 目录',
            '',
            '> **读者：** 插件开发者',
            '> **同源：** 后台 **插件中心 → 开发者工作台 → 内核接口**（`PluginDeveloperWorkbenchService` 反射生成）',
            '> **维护：** `npm run docs:sync-plugin-gateway`（改 Gateway 后执行，再 `npm run docs:publish`）',
            '> **规范：** [能力优先与防重复开发](03-开发/能力优先与防重复开发.md) · [插件开发规范](06-插件/插件开发规范.md)',
            '',
            '---',
            '',
            '## 使用约定',
            '',
            '| 规则 | 说明 |',
            '|------|------|',
            '| **入口** | 插件内直调 `app(Weapp*Gateway::class)->…`（按域选 Gateway） |',
            '| **禁止** | 插件内自建 `PaymentOrderService`、渠道 SDK、`handleNotify` 等平行管道 |',
            '| **返回** | 业务方法多用 `ServiceResult`；调用方须判断 `isOk()` |',
            '',
            '生成时间：' . date('Y-m-d H:i:s') . ' · Gateway 组数 ' . count($catalog),
            '',
        ];

        foreach ($catalog as $group) {
            $gateway = $group['gateway'];
            $label   = $group['label'];
            $methods = $group['methods'];
            $lines[] = '## ' . $gateway;
            $lines[] = '';
            if ($label !== '') {
                $lines[] = $label . ' · 方法数 ' . count($methods);
                $lines[] = '';
            }
            foreach ($methods as $method) {
                $name    = $method['name'];
                $params  = $method['params'];
                $sig     = $name . $this->formatMethodSignature($params);
                $lines[] = '### `' . $sig . '`';
                $summary = trim($method['summary']);
                if ($summary !== '') {
                    $lines[] = '';
                    $lines[] = $summary;
                }
                $lines[] = '';
            }
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '[← 插件开发规范](06-插件/插件开发规范.md) · [开发者工作台（后台）](/admin#/plugin/workbench)';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{name:string,type:string,optional:bool}> $params
     */
    private function formatMethodSignature(array $params): string
    {
        if ($params === []) {
            return '()';
        }

        $parts = [];
        foreach ($params as $param) {
            $opt     = $param['optional'] ? '?' : '';
            $parts[] = $opt . $param['name'] . ': ' . $param['type'];
        }

        return '(' . implode(', ', $parts) . ')';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function validateManifestStructure(array $manifest): array
    {
        $errors = [];

        $identifier = strtolower(trim((string) ($manifest['identifier'] ?? '')));
        if ($identifier === '') {
            $errors[] = '缺少 identifier（插件唯一标识）';
        } else {
            $check = $this->pluginReservedIdentifierService->checkPayload($identifier);
            if (($check['ok'] ?? false) !== true) {
                $errors[] = (string) ($check['message'] ?? $this->pluginReservedIdentifierService->identifierFormatMessage());
            }
        }

        $package = strtolower(trim((string) ($manifest['package'] ?? '')));
        if ($package !== '') {
            $packageErr = $this->pluginReservedIdentifierService->validatePackageForPlugin($package);
            if ($packageErr !== null) {
                $errors[] = $packageErr;
            }
        }

        if (trim((string) ($manifest['name'] ?? '')) === '') {
            $errors[] = '缺少 name（展示名称）';
        }

        if (trim((string) ($manifest['version'] ?? '')) === '') {
            $errors[] = '缺少 version（语义化版本，如 1.0.0）';
        }

        $kind = strtolower(trim((string) ($manifest['kind'] ?? '')));
        if ($kind === '') {
            $errors[] = '缺少 kind（document-addon / platform / application）';
        }

        return $errors;
    }

    /**
     * 工作台静态校验 document_search（不 autoload、不 new contributor）。
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    private function validateDocumentSearchStatic(array $manifest, mixed $block): array
    {
        if ($block === null) {
            return [];
        }

        if (!is_array($block)) {
            return ['document_search 须为对象'];
        }

        $kind = $this->pluginManifestService->resolveKind($manifest);
        if ($kind === '') {
            $kind = PluginManifestService::KIND_DOCUMENT_ADDON;
        }
        if ($kind !== PluginManifestService::KIND_DOCUMENT_ADDON) {
            return ['仅 kind=document-addon 可声明 document_search'];
        }

        $bridge = is_array($block['bridge'] ?? null) ? $block['bridge'] : null;
        if ($bridge !== null) {
            $fetch = trim((string) ($bridge['fetch'] ?? 'listForDocument'));
            if ($fetch === '') {
                $fetch = 'listForDocument';
            }
            $allowedFetch = ['listForDocument', 'doc_vod_merge'];
            if (!in_array($fetch, $allowedFetch, true)) {
                return ['document_search.bridge.fetch 无效'];
            }
            if (array_key_exists('attachments', $bridge) && !is_bool($bridge['attachments'])) {
                return ['document_search.bridge.attachments 须为布尔值'];
            }

            return [];
        }

        $contributor = trim((string) ($block['contributor'] ?? ''));
        if ($contributor === '') {
            return ['document_search 须声明 contributor 或 bridge'];
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\\\\[a-zA-Z_][a-zA-Z0-9_]*)+$/', $contributor)) {
            return ['document_search.contributor 须为合法 PHP 完全限定类名'];
        }

        return [];
    }

    /**
     * @return list<array{title:string,path:string}>
     */
    private function docLinks(): array
    {
        // 开发文档不进普通后台工作台顶栏（docs.pivark.com）
        return [];
    }

    /**
     * @return array{points:list<array<string,mixed>>,doc_path:string}
     */
    public function extensionPoints(): array
    {
        return [
            'points'   => $this->extensionPointCatalog->catalog(),
            'doc_path' => '/docs/#/06-插件/内核扩展点目录',
        ];
    }

    public function extensionManifestSnippet(string $manifestKey, string $identifier = 'my_plugin'): string
    {
        return $this->extensionPointCatalog->manifestSnippet($manifestKey, $identifier);
    }

    /**
     * @return list<array{id:string,title:string,description:string,json:string}>
     */
    private function manifestSamples(): array
    {
        $documentAddon = [
            'identifier'      => 'my_addon',
            'name'            => '示例文档增强件',
            'version'         => '0.1.0',
            'kind'            => PluginManifestService::KIND_DOCUMENT_ADDON,
            'publisher_type'  => PluginManifestService::TYPE_PERSONAL,
            'package'         => 'acme/my_addon',
            'author'          => '开发者',
            'surfaces'        => [
                'document_editor' => ['enabled' => true, 'slot' => 'tab'],
                'frontend'        => ['template_tags' => ['my_addon']],
            ],
            'extensions'      => [
                'product_tab_after_persist' => [
                    'post_keys' => ['product_item_my_addon_json'],
                    'handler'   => 'weapp\\my_addon\\service\\MyAddonDocumentTabSync::afterPersist',
                    'priority'  => 100,
                ],
            ],
        ];

        $application = [
            'identifier'     => 'my_app',
            'name'           => '示例企业应用',
            'version'        => '0.1.0',
            'kind'           => PluginManifestService::KIND_APPLICATION,
            'publisher_type' => PluginManifestService::TYPE_ENTERPRISE,
            'package'        => 'acme/my_app',
            'author'         => '开发者',
        ];

        $platform = [
            'identifier'     => 'my_platform',
            'name'           => '示例平台能力',
            'version'        => '0.1.0',
            'kind'           => PluginManifestService::KIND_PLATFORM,
            'publisher_type' => PluginManifestService::TYPE_PERSONAL,
            'package'        => 'acme/my_platform',
            'author'         => '开发者',
            'surfaces'       => [
                'document_editor' => ['enabled' => false],
            ],
        ];

        return [
            [
                'id'          => 'document-addon',
                'title'       => '文档增强件',
                'description' => '绑定文档、前台标签或发布页扩展',
                'json'        => $this->encodeManifestSample($documentAddon),
            ],
            [
                'id'          => 'application',
                'title'       => '企业应用',
                'description' => '独立业务子系统，自带后台菜单',
                'json'        => $this->encodeManifestSample($application),
            ],
            [
                'id'          => 'platform',
                'title'       => '平台能力',
                'description' => '横切全站能力，不占文档 Tab',
                'json'        => $this->encodeManifestSample($platform),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeManifestSample(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('manifest sample encode failed');
        }

        return $json;
    }

    /**
     * @return array{title:string,doc_path:string}|null
     */
    private function hintForError(string $error): ?array
    {
        $rules = [
            ['keys' => ['publisher_type', '官方插件', '官方标识', 'vendor'], 'title' => '发布者与包名', 'doc_path' => '/docs/#/06-插件/插件开发规范'],
            ['keys' => ['surfaces', 'document_editor', 'template_tags', 'render_hooks'], 'title' => '展示面 surfaces', 'doc_path' => '/docs/#/06-插件/插件展示面与Surface规范'],
            ['keys' => ['commercial', 'sku_id', 'billing_type'], 'title' => '商业化 SKU', 'doc_path' => '/docs/#/06-插件/插件SKU与运营配置手册'],
            ['keys' => ['api_version'], 'title' => '核心 API 版本', 'doc_path' => '/docs/#/06-插件/插件开发规范'],
            ['keys' => ['document_search', 'contributor'], 'title' => '超级搜索接入', 'doc_path' => '/docs/#/06-插件/文档扩展插件-超级搜索接入'],
            ['keys' => ['extensions', 'product_tab', 'post_keys', 'handler'], 'title' => 'extensions 扩展点', 'doc_path' => '/docs/#/06-插件/manifest-extensions-参考'],
            ['keys' => ['identifier', 'kind', 'version', 'name'], 'title' => '清单基础字段', 'doc_path' => '/docs/#/06-插件/插件快速入门'],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['keys'] as $key) {
                if (stripos($error, $key) !== false) {
                    return [
                        'title'    => $rule['title'],
                        'doc_path' => $rule['doc_path'],
                    ];
                }
            }
        }

        return null;
    }

    private function gatewayLabel(string $gateway): string
    {
        $map = [
            'WeappPaymentGateway'     => '支付',
            'WeappEntitlementGateway' => '授权与权益',
            'WeappMemberGateway'      => '会员',
            'WeappUploadGateway'      => '上传',
            'WeappDocumentGateway'    => '文档',
            'WeappFrontGateway'       => '前台会话',
            'WeappPluginGateway'      => '插件运行时',
            'WeappSearchGateway'      => '搜索（LIKE + 智能搜索）',
            'WeappItemGateway'        => '品项',
            'WeappHookGateway'        => 'Hook',
            'WeappEventGateway'       => '事件总线',
        ];

        return $map[$gateway] ?? str_replace('Weapp', '', str_replace('Gateway', '', $gateway));
    }

    private function docSummary(ReflectionMethod $method): string
    {
        $doc = $method->getDocComment();
        if (!is_string($doc) || $doc === '') {
            return '';
        }

        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim($line, " \t/*");
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '@') || str_contains($line, '@')) {
                continue;
            }

            return $line;
        }

        return '';
    }

    private function sanitizeUtf8Text(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if (is_string($clean) && mb_check_encoding($clean, 'UTF-8')) {
            return $clean;
        }

        return '';
    }
}
