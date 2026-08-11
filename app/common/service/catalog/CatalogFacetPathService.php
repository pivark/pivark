<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 目录参数组筛 · 路径伪静态编解码（内核）
 * 例：/plugins/免费_官方自营_文档扩展
 * site_nav.extra_json.catalog_facet_group = 参数组 group_key（如 plugin）；门牌归栏目
 */
declare(strict_types=1);

namespace app\common\service\catalog;

use app\common\model\ProductParamDef;
use app\common\model\ProductParamGroup;
use app\common\service\plugin\extension\PluginOfficialProduct;
use app\common\service\tag\TagCore;
use app\common\support\DbTable;
use app\common\service\site\SiteUrlModeService;

final class CatalogFacetPathService
{
    /** 未选（全部）占位，保证槽位顺序 */
    public const EMPTY_SLOT = '0';

    public const SEPARATOR = '_';

    /** site_nav.extra_json 声明本栏目门牌绑定的参数组 */
    public const DOOR_EXTRA_GROUP_KEY = 'catalog_facet_group';

    /**
     * @return list<array{param_key:string,label:string,options:list<string>,option_rows:list<array{value:string,label:string}>}>
     */
    public function filterableDefsForGroupKey(string $groupKey): array
    {
        $groupKey = trim($groupKey);
        if ($groupKey === '' || !DbTable::modelExists(ProductParamDef::class) || !DbTable::modelExists(ProductParamGroup::class)) {
            return [];
        }
        $group = ProductParamGroup::where('group_key', $groupKey)->order('id', 'asc')->find();
        if ($group === null) {
            return [];
        }
        $gid = (int) ($group['id'] ?? 0);
        if ($gid < 1) {
            return [];
        }
        $rows = ProductParamDef::where('group_id', $gid)
            ->where('filterable', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['param_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $options = \app\common\service\product\ProductService::normalizeParamOptions(
                json_decode((string) ($row['options_json'] ?? '[]'), true) ?: []
            );
            $out[] = [
                'param_key' => $key,
                'label'     => trim((string) ($row['label'] ?? $key)) ?: $key,
                'options'   => \app\common\service\product\ProductService::paramOptionValues($options),
                /** @var list<array{value:string,label:string}> */
                'option_rows' => $options,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, string> $filters filter_{param_key} => value
     */
    public function encode(string $groupKey, array $filters): string
    {
        $defs = $this->filterableDefsForGroupKey($groupKey);
        if ($defs === []) {
            return '';
        }
        $parts = [];
        $any = false;
        foreach ($defs as $def) {
            $key = $def['param_key'];
            $val = trim((string) ($filters['filter_' . $key] ?? ''));
            if ($val === '') {
                $parts[] = self::EMPTY_SLOT;
                continue;
            }
            if (!in_array($val, $def['options'], true)) {
                return '';
            }
            if (str_contains($val, self::SEPARATOR)) {
                return '';
            }
            $parts[] = $val;
            $any = true;
        }

        return $any ? implode(self::SEPARATOR, $parts) : '';
    }

    /**
     * @return array<string, string>|null  null=不是合法 facet 段
     */
    public function decode(string $groupKey, string $segment): ?array
    {
        $segment = trim(rawurldecode($segment));
        if ($segment === '' || $segment === self::EMPTY_SLOT) {
            return [];
        }
        $defs = $this->filterableDefsForGroupKey($groupKey);
        if ($defs === []) {
            return null;
        }
        $parts = explode(self::SEPARATOR, $segment);
        if (count($parts) !== count($defs)) {
            return null;
        }
        $out = [];
        foreach ($defs as $i => $def) {
            $slot = trim((string) ($parts[$i] ?? ''));
            if ($slot === '' || $slot === self::EMPTY_SLOT) {
                continue;
            }
            if (!in_array($slot, $def['options'], true)) {
                return null;
            }
            $out['filter_' . $def['param_key']] = $slot;
        }

        return $out;
    }

    /**
     * 从栏目（或带 extra_json 的门牌行）读参数组 key。
     *
     * @param array<string, mixed> $doorRow site_nav 行（也可读同形 extra_json）
     */
    public function groupKeyFromExtra(array $doorRow): string
    {
        $raw = $doorRow['extra_json'] ?? null;
        $bag = app(TagCore::class)->normalizeExtraFields($raw);
        $key = trim((string) ($bag[self::DOOR_EXTRA_GROUP_KEY] ?? $bag['filter_group_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return '';
        }
        $flat = $raw[self::DOOR_EXTRA_GROUP_KEY] ?? $raw['filter_group_key'] ?? null;
        if (is_string($flat)) {
            return trim($flat);
        }
        if (is_array($flat)) {
            return trim((string) ($flat['value'] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string, string> $filters
     */
    public function buildPath(string $baseUrlPath, string $groupKey, array $filters): string
    {
        $base = trim(str_replace('\\', '/', $baseUrlPath), '/');
        if ($base === '') {
            return '/';
        }
        $seg = $this->encode($groupKey, $filters);
        $path = $seg === '' ? '/' . $base : '/' . $base . '/' . $seg;
        if (app(SiteUrlModeService::class)->usesPrettyUrl()) {
            // 仅给末段加后缀，避免中文段被破坏：整路径由入口 stripSuffix 对称处理
            return $path;
        }

        return $path;
    }

    /**
     * 嵌套段是否可解析为该栏目门牌的参数组筛。
     *
     * @param array<string, mixed> $doorRow
     * @return array<string, string>|null
     */
    public function tryDecodeForExtra(array $doorRow, string $segment): ?array
    {
        $groupKey = $this->groupKeyFromExtra($doorRow);
        if ($groupKey === '') {
            return null;
        }

        return $this->decode($groupKey, $segment);
    }

    /**
     * 由品项元数据推导缺省筛值（写路径/组装共用；禁止各站私有再猜一套）
     *
     * @param array<string, string> $existing 已有 param_key => value
     * @param array{
     *   publisher_type?: string,
     *   is_third_party?: bool,
     *   price?: float|int|string,
     *   price_label?: string,
     *   kind?: string,
     *   kind_label?: string
     * } $meta
     * @return array<string, string>
     */
    public function mergeDerivedFilterAttrs(array $existing, array $meta): array
    {
        $out = [];
        foreach ($existing as $k => $v) {
            $key = trim((string) $k);
            $val = trim((string) $v);
            if ($key !== '' && $val !== '') {
                $out[$key] = $val;
            }
        }

        if (($out['product_line'] ?? '') === '') {
            if (array_key_exists('is_third_party', $meta)) {
                $out['product_line'] = !empty($meta['is_third_party']) ? '第三方商家' : '官方自营';
            } else {
                $pub = strtolower(trim((string) ($meta['publisher_type'] ?? '')));
                $out['product_line'] = ($pub === '' || $pub === 'official' || $pub === 'platform')
                    ? '官方自营'
                    : '第三方商家';
            }
        }

        if (($out['accuracy'] ?? '') === '') {
            $priceLabel = trim((string) ($meta['price_label'] ?? ''));
            $price = (float) ($meta['price'] ?? 0);
            $out['accuracy'] = ($price <= 0.00001 || $priceLabel === '' || str_contains($priceLabel, '免费'))
                ? '免费'
                : '收费';
        } elseif (array_key_exists('price', $meta) || array_key_exists('price_label', $meta)) {
            // 改价写路径：价/价签是 accuracy 真源，禁止旧「免费」残留
            $priceLabel = trim((string) ($meta['price_label'] ?? ''));
            $price = (float) ($meta['price'] ?? 0);
            $out['accuracy'] = ($price <= 0.00001 || str_contains($priceLabel, '免费'))
                ? '免费'
                : '收费';
        }

        if (($out['output_signal'] ?? '') === '') {
            $kindMap = [
                'document-addon' => '文档扩展',
                'platform'       => '平台能力',
                'application'    => '独立应用',
                'miniprogram'    => '小程序',
            ];
            $kind = strtolower(trim((string) ($meta['kind'] ?? '')));
            if (isset($kindMap[$kind])) {
                $out['output_signal'] = $kindMap[$kind];
            } else {
                $label = trim((string) ($meta['kind_label'] ?? ''));
                if ($label !== '') {
                    $out['output_signal'] = $label;
                }
            }
        }

        return $out;
    }

    /**
     * 保存品项前：插件目录品项缺省筛字段写入 attrs（→ syncFromAttrs → EAV）
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function ensurePluginFilterAttrsOnItemAttrs(array $attrs, string $itemCode = ''): array
    {
        if (!$this->isPluginCatalogItemAttrs($attrs, $itemCode)) {
            return $attrs;
        }

        $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        // 内核只认通用袋；宿主扩展经 plugin_filter_commercial_meta 补商业元数据
        $commercial = [];
        foreach (['marketplace_commercial', 'official_plugin'] as $bagKey) {
            if (is_array($attrs[$bagKey] ?? null)) {
                $commercial = $attrs[$bagKey];
                break;
            }
        }

        $meta = [
            'publisher_type' => (string) ($catalog['publisher_type'] ?? $attrs['publisher_type'] ?? 'official'),
            'price'          => $commercial['price'] ?? $attrs['price'] ?? 0,
            'price_label'    => (string) ($commercial['price_label'] ?? $attrs['price_label'] ?? ''),
            'kind'           => (string) ($catalog['kind'] ?? $attrs['kind'] ?? ''),
            'kind_label'     => (string) ($catalog['kind_label'] ?? $attrs['kind_label'] ?? ''),
        ];
        if (isset($catalog['is_third_party'])) {
            $meta['is_third_party'] = !empty($catalog['is_third_party']);
        }

        $hostMeta = PluginOfficialProduct::dispatch('plugin_filter_commercial_meta', [
            'attrs'      => $attrs,
            'item_code'  => $itemCode,
            'meta'       => $meta,
            'commercial' => $commercial,
        ], null);
        if (is_array($hostMeta)) {
            $meta = array_merge($meta, $hostMeta);
        }

        $derived = $this->mergeDerivedFilterAttrs([
            'product_line'   => trim((string) ($attrs['product_line'] ?? '')),
            'accuracy'       => trim((string) ($attrs['accuracy'] ?? '')),
            'output_signal'  => trim((string) ($attrs['output_signal'] ?? '')),
        ], $meta);

        foreach (['product_line', 'accuracy', 'output_signal'] as $key) {
            if (($derived[$key] ?? '') !== '') {
                $attrs[$key] = $derived[$key];
            }
        }

        return $attrs;
    }

    /**
     * 模板/整站目录品项：从宿主 shelf 补顶层筛字段（→ EAV）。
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function ensureTemplateFilterAttrsOnItemAttrs(array $attrs, string $itemCode = ''): array
    {
        $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        $line = strtolower(trim((string) ($catalog['line'] ?? '')));
        $isTpl = in_array($line, ['template', 'site'], true);
        if (!$isTpl) {
            $host = PluginOfficialProduct::dispatch('is_template_catalog_item', [
                'attrs'     => $attrs,
                'item_code' => $itemCode,
            ], null);
            if ($host !== true) {
                return $attrs;
            }
        }

        $filled = PluginOfficialProduct::dispatch('template_filter_attrs_ensure', [
            'attrs'     => $attrs,
            'item_code' => $itemCode,
        ], null);
        if (!is_array($filled)) {
            return $attrs;
        }
        foreach (['site_kind', 'industry', 'layout', 'color'] as $key) {
            $val = trim((string) ($filled[$key] ?? ''));
            if ($val !== '') {
                $attrs[$key] = $val;
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function isPluginCatalogItemAttrs(array $attrs, string $itemCode = ''): bool
    {
        $catalog = is_array($attrs['official_catalog'] ?? null) ? $attrs['official_catalog'] : [];
        if (trim((string) ($catalog['plugin_identifier'] ?? '')) !== '') {
            return true;
        }
        if (strtolower(trim((string) ($catalog['line'] ?? ''))) === 'plugin') {
            return true;
        }

        $host = PluginOfficialProduct::dispatch('is_plugin_catalog_item', [
            'attrs'     => $attrs,
            'item_code' => $itemCode,
        ], null);

        return is_bool($host) ? $host : false;
    }

    /**
     * 请求参 → 合法 filter_*（只保留本参数组 filterable 键）
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    public function normalizeFilters(array $params, string $groupKey): array
    {
        $allowed = [];
        foreach ($this->filterableDefsForGroupKey($groupKey) as $def) {
            $allowed['filter_' . $def['param_key']] = true;
        }
        $out = [];
        foreach ($params as $key => $val) {
            $key = (string) $key;
            if (!str_starts_with($key, 'filter_')) {
                continue;
            }
            $val = trim((string) $val);
            if ($val === '' || !isset($allowed[$key])) {
                continue;
            }
            $out[$key] = $val;
        }
        ksort($out);

        return $out;
    }

    /**
     * 按 filter_attrs 匹配卡片；若提供 EAV 命中的 identifier 集合则优先 SQL 结果
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, string>      $active
     * @param array<string, true>|null   $eavIdentifierSet lowercase identifier => true；null=不用 EAV
     * @return list<array<string, mixed>>
     */
    public function matchByFilterAttrs(array $cards, array $active, ?array $eavIdentifierSet = null): array
    {
        if ($active === []) {
            return $cards;
        }
        if ($eavIdentifierSet !== null) {
            return array_values(array_filter($cards, static function (array $card) use ($eavIdentifierSet): bool {
                $id = strtolower(trim((string) ($card['identifier'] ?? '')));

                return $id !== '' && isset($eavIdentifierSet[$id]);
            }));
        }

        return array_values(array_filter($cards, static function (array $card) use ($active): bool {
            $attrs = is_array($card['filter_attrs'] ?? null) ? $card['filter_attrs'] : [];
            foreach ($active as $filterKey => $want) {
                $paramKey = substr((string) $filterKey, 7);
                $got = trim((string) ($attrs[$paramKey] ?? ''));
                if ($got !== $want) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * 前台筛条行（选项计数 + URL）；URL 由调用方注入（内核不绑站点 path）
     *
     * @param list<array<string, mixed>>              $allCards
     * @param array<string, string>                   $active
     * @param callable(array<string, string>): string $urlForFilters
     * @return list<array{param_key:string,label:string,clear_url:string,has_active:int,options:list<array<string,mixed>>}>
     */
    public function buildFilterBarRows(
        array $allCards,
        array $active,
        string $groupKey,
        callable $urlForFilters
    ): array {
        $defs = $this->filterableDefsForGroupKey($groupKey);
        if ($defs === []) {
            return [];
        }
        $rows = [];
        foreach ($defs as $def) {
            $paramKey = $def['param_key'];
            $filterKey = 'filter_' . $paramKey;
            $current = (string) ($active[$filterKey] ?? '');
            $peerActive = $active;
            unset($peerActive[$filterKey]);
            // 筛条计数必须按卡片子集（市场列表），禁止用全站 ItemFilterFacet 冒充
            $scoped = $this->matchByFilterAttrs($allCards, $peerActive);

            $options = [];
            foreach ($def['options'] as $optVal) {
                $cnt = 0;
                foreach ($scoped as $card) {
                    $attrs = is_array($card['filter_attrs'] ?? null) ? $card['filter_attrs'] : [];
                    if (trim((string) ($attrs[$paramKey] ?? '')) === $optVal) {
                        ++$cnt;
                    }
                }
                $next = $peerActive;
                $next[$filterKey] = $optVal;
                $options[] = [
                    'value'     => $optVal,
                    'count'     => $cnt,
                    'url'       => (string) $urlForFilters($next),
                    'is_active' => $current === $optVal ? 1 : 0,
                    'disabled'  => $peerActive !== [] && $cnt < 1 ? 1 : 0,
                ];
            }

            $rows[] = [
                'param_key'  => $paramKey,
                'label'      => $def['label'],
                'clear_url'  => (string) $urlForFilters($peerActive),
                'has_active' => $current !== '' ? 1 : 0,
                'options'    => $options,
            ];
        }

        return $rows;
    }
}
