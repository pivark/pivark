<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\SiteNav;

/**
 * 网站栏目通道解析：content_kind / catalog_line 单一入口。
 * 列表、表单、前台、品项筛均走本类，禁止再叠 formatAdmin / 客户端 hydrate。
 * 真分类只认 site_nav；禁再经 Tag 回查栏目。
 */
final class NavChannelResolver
{
    /**
     * 产品橱窗 target → catalog_line（SSOT；admin 由 emit_nav_catalog_line_ts_cli 生成）
     *
     * @var array<string, string>
     */
    public const CATALOG_LINE_BY_TARGET = [
        'apps-plugins'        => 'plugin',
        'plugins'             => 'plugin',
        'templates'           => 'template',
        'industry-templates'  => 'template',
        'apps-miniprogram'    => 'miniprogram',
        'miniprogram'         => 'miniprogram',
        'apps-site'           => 'site',
        'portal'              => 'site',
        'license'             => 'license',
        'pricing-license'     => 'license',
        'pricing'             => 'license',
        'product-license'     => 'license',
        'product-plugin'      => 'plugin',
        'product-miniprogram' => 'miniprogram',
        'product-site'        => 'site',
        'products'            => '',
        'product'             => '',
        'chanpin'             => '',
    ];

    /**
     * 无 target 映射时的标题兜底（SSOT）
     *
     * @var array<string, string>
     */
    public const CATALOG_LINE_BY_TITLE = [
        '域名授权' => 'license',
        '系统插件' => 'plugin',
        '网站模板' => 'template',
        '整站程序' => 'site',
        '小程序'   => 'miniprogram',
    ];

    public function __construct(
        private readonly NavChannelKind $kind,
    ) {
    }

    /**
     * @param array<string, mixed> $row site_nav 行（至少含 nav_type/target；宜含 content_kind/title）
     * @return array{content_kind: string, catalog_line: string}
     */
    public function resolve(array $row): array
    {
        $navType = strtolower(trim((string) ($row['nav_type'] ?? SiteNavService::TYPE_ROUTE)));
        $target  = trim((string) ($row['target'] ?? ''));
        $title   = trim((string) ($row['title'] ?? ''));
        $contentKind = $this->kind->resolveForRow($row, $navType, $target);

        return [
            'content_kind' => $contentKind,
            'catalog_line' => $this->resolveCatalogLine($contentKind, $target, $title),
        ];
    }

    /**
     * @return array{content_kind: string, catalog_line: string}|null
     */
    public function resolveFromNavId(int $navId): ?array
    {
        if ($navId < 1) {
            return null;
        }
        $row = SiteNav::where('id', $navId)->find()?->toArray();
        if (!is_array($row) || $row === []) {
            return null;
        }

        return $this->resolve($row);
    }

    /**
     * 产品橱窗 → 品项产线 key（与 official_catalog.lines / 后台中栏跳转 SSOT）
     */
    public function resolveCatalogLine(string $contentKind, string $target, string $title = ''): string
    {
        if ($contentKind !== NavChannelKind::PRODUCT && $contentKind !== NavChannelKind::PAGE) {
            return '';
        }
        $t = $this->kind->normalizeTargetKey($target);
        if ($t !== '' && array_key_exists($t, self::CATALOG_LINE_BY_TARGET)) {
            $line = self::CATALOG_LINE_BY_TARGET[$t];
            if ($line !== '') {
                return $line;
            }
        }
        $title = trim($title);

        return self::CATALOG_LINE_BY_TITLE[$title] ?? '';
    }
}
