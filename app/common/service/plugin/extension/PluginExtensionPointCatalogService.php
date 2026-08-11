<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

/** 内核扩展点目录（工作台 / 审包 SSOT · 与 docs/06-插件/内核扩展点目录.md 对齐） */
final class PluginExtensionPointCatalogService
{
    /**
     * @return list<array{
     *   id:string,
     *   title:string,
     *   manifest_key:string,
     *   gateway_method:string,
     *   load_mode:string,
     *   payload_summary:string,
     *   returns:string,
     *   doc_path:string,
     *   sample:array<string,mixed>
     * }>
     */
    public function catalog(): array
    {
        return [
            $this->entry(
                'payment.fulfillment',
                '支付履约',
                '',
                'paymentFulfillmentRegister',
                'boot',
                'order[]',
                'ServiceResult',
                '/docs/#/06-插件/内核扩展点目录',
                [],
            ),
            $this->entry(
                'document_addon.save',
                '文档 Tab POST 同步',
                'document_save',
                'PluginExtensionRegistry::register',
                'manifest+boot',
                'document_id, rows[]',
                'ServiceResult',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'post_keys' => ['my_addon_json'],
                    'handler'   => 'weapp\\my_plugin\\service\\MyDocumentAddonSync::syncForDocument',
                    'priority'  => 50,
                ],
            ),
            $this->entry(
                'product_tab.before_persist',
                '品项 Tab 落库前校验',
                'product_tab_before_persist',
                'productTabBeforePersistRegister',
                'manifest+boot',
                'document_id, item_id, item_type, post',
                'ServiceResult',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'post_keys' => ['product_variants'],
                    'handler'   => 'weapp\\demo\\service\\DemoDocumentTabSync::beforePersist',
                    'priority'  => 90,
                ],
            ),
            $this->entry(
                'product_tab.after_persist',
                '品项 Tab 落库后',
                'product_tab_after_persist',
                'productTabAfterPersistRegister',
                'manifest+boot',
                'document_id, item_id, item_type, post',
                'ServiceResult',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'post_keys' => ['product_item_demo_json'],
                    'handler'   => 'weapp\\demo\\service\\DemoDocumentTabSync::afterPersist',
                    'priority'  => 100,
                ],
            ),
            $this->entry(
                'item.after_save',
                '产品中心保存品项后',
                'item_after_save',
                'itemAfterSaveRegister',
                'manifest+boot',
                'item_id, is_new, prev_status, row',
                'ServiceResult',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'handler'  => 'weapp\\shop\\service\\ShopItemAfterSaveSync::afterSave',
                    'priority' => 100,
                ],
            ),
            $this->entry(
                'admin.spa_meta',
                '后台 SPA 初始 meta',
                'admin_spa_meta',
                'adminSpaMetaRegister',
                'boot',
                'scope, user_id, route?',
                'array',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'bucket'  => 'demoCenterNav',
                    'handler' => 'weapp\\demo\\service\\DemoAdminSpaMeta::build',
                    'priority'=> 100,
                ],
            ),
            $this->entry(
                'admin.menu.dynamic',
                '后台动态菜单',
                'admin_menu',
                'adminMenuRegister',
                'boot',
                '—',
                'menu[]',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'handler' => 'weapp\\demo\\service\\DemoAdminMenu::collect',
                ],
            ),
            $this->entry(
                'member.center.nav',
                '会员中心导航过滤',
                'member_center_nav',
                'memberCenterNavRegisterRouteFilter',
                'boot',
                'entry[]',
                'bool',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'handler' => 'weapp\\demo\\service\\DemoMemberNav::allowRoute',
                ],
            ),
            $this->entry(
                'member.center.page',
                '会员中心插件页',
                'member_center_page',
                'memberCenterPageRegister',
                'boot',
                'host{member,render,redirect_unavailable}',
                'Response',
                '/docs/#/06-插件/内核扩展点目录',
                [
                    'pages' => [
                        'demo_page' => 'weapp\\demo\\service\\DemoMemberPages::demoPage',
                    ],
                ],
            ),
            $this->entry(
                'admin.plugin.route',
                '后台插件 API 路由',
                'admin_plugin_route',
                'adminPluginRouteRegister',
                'boot',
                'method, path, handler',
                '—',
                '/docs/#/06-插件/内核扩展点目录',
                [
                    'routes' => [
                        ['method' => 'get', 'path' => 'demo/meta', 'handler' => 'weapp\\demo\\admin\\DemoController::meta'],
                    ],
                ],
            ),
            $this->entry(
                'front.plugin.path',
                '前台 pathPaged 插件段',
                'front_plugin_path',
                'frontPluginPathRegister',
                'boot',
                'segment',
                'Response|null',
                '/docs/#/06-插件/内核扩展点目录',
                [
                    'path_prefix' => 'demo',
                    'handler'     => 'weapp\\demo\\service\\DemoFrontPathPages::dispatchSegment',
                ],
            ),
            $this->entry(
                'plugin.sku_tier_apply',
                'SKU 档位履约',
                'plugin_sku_tier_apply',
                'pluginSkuTierApplyRegister',
                'manifest+boot',
                'tier',
                'void',
                '/docs/#/06-插件/manifest-extensions-参考',
                [
                    'handler' => 'weapp\\demo\\service\\DemoSkuFulfillment::applyTier',
                ],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $sampleInner
     * @return array<string, mixed>
     */
    private function entry(
        string $id,
        string $title,
        string $manifestKey,
        string $gatewayMethod,
        string $loadMode,
        string $payloadSummary,
        string $returns,
        string $docPath,
        array $sampleInner,
    ): array {
        return [
            'id'               => $id,
            'title'            => $title,
            'manifest_key'     => $manifestKey,
            'gateway_method'   => $gatewayMethod,
            'load_mode'        => $loadMode,
            'payload_summary'  => $payloadSummary,
            'returns'          => $returns,
            'doc_path'         => $docPath,
            'sample'           => $sampleInner,
        ];
    }

    /** manifest `extensions` 片段（可复制到 plugin.json） */
    public function manifestSnippet(string $manifestKey, string $identifier = 'my_plugin'): string
    {
        $manifestKey = strtolower(trim($manifestKey));
        $identifier  = strtolower(trim($identifier));
        if ($manifestKey === '' || $identifier === '') {
            return '{}';
        }

        $classId = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $identifier)));
        $ns      = 'weapp\\' . $identifier;

        foreach ($this->catalog() as $row) {
            if (($row['manifest_key'] ?? '') !== $manifestKey) {
                continue;
            }
            $inner = is_array($row['sample'] ?? null) ? $row['sample'] : [];
            $inner = $this->personalizeSample($inner, $identifier, $classId, $ns);

            $payload = ['extensions' => [$manifestKey => $inner]];
            $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return is_string($json) ? $json : '{}';
        }

        return '{}';
    }

    /**
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    private function personalizeSample(array $sample, string $identifier, string $classId, string $ns): array
    {
        $json = json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return $sample;
        }
        $json = str_replace(
            ['demo', 'Demo', 'weapp\\\\demo'],
            [$identifier, $classId, str_replace('\\', '\\\\', $ns)],
            $json
        );
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : $sample;
    }
}
