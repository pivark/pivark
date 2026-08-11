<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

/**
 * 网站栏目「入口类型」单一真源（禁再在 SiteNavService/TS 叠特例）。
 *
 * - 落库字段：site_nav.content_kind（保存时写入；列表优先读库）
 * - 橱窗历史 target 白名单只维护本类常量
 * - 禁再经 Tag slug 推断栏目类型（假分类桥已废）
 */
final class NavChannelKind
{
    public const HOME     = 'home';
    public const DOCUMENT = 'document';
    public const PRODUCT  = 'product';
    public const PAGE     = 'page';
    public const EXTERNAL = 'external';

    /**
     * 历史产品橱窗 target 键（path / 旧 tpl 归一后）。
     * 新增产线：改本常量 + 同步 admin content-publish-ssot（门禁校验）。
     *
     * @var list<string>
     */
    public const SHOWCASE_TARGET_KEYS = [
        'chanpin',
        'product',
        'products',
        'apps-plugins',
        'plugins',
        'templates',
        'industry-templates',
        'apps-miniprogram',
        'miniprogram',
        'apps-site',
        'portal',
        'license',
        'pricing',
        'pricing-license',
        'product-license',
        'product-plugin',
        'product-miniprogram',
        'product-site',
    ];

    public function normalize(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if ($kind === '' || $kind === 'auto') {
            return 'auto';
        }
        if ($kind === 'other') {
            return self::DOCUMENT;
        }
        if (in_array($kind, [self::HOME, self::DOCUMENT, self::PRODUCT, self::PAGE, self::EXTERNAL], true)) {
            return $kind;
        }

        return self::DOCUMENT;
    }

    public function normalizeTargetKey(string $target): string
    {
        $t = strtolower(trim($target));
        $t = ltrim($t, '/');
        if (str_starts_with($t, 'list_product_')) {
            $t = substr($t, strlen('list_product_'));
            $t = str_replace('_', '-', $t);
        } elseif (str_starts_with($t, 'list_page_')) {
            $t = substr($t, strlen('list_page_'));
            $t = str_replace('_', '-', $t);
        }

        return $t;
    }

    public function isShowcaseTarget(string $target): bool
    {
        return in_array($this->normalizeTargetKey($target), self::SHOWCASE_TARGET_KEYS, true);
    }

    /**
     * 列表/读路径：优先落库 content_kind；空则一次性推断（仅兼容旧行）。
     *
     * @param array<string, mixed> $row site_nav 行
     */
    public function resolveForRow(array $row, string $navType, string $target): string
    {
        $stored = $this->normalize((string) ($row['content_kind'] ?? ''));
        if ($stored !== 'auto') {
            return $stored;
        }

        return $this->inferFromNavTypeTarget($navType, $target);
    }

    public function inferFromNavTypeTarget(string $navType, string $target): string
    {
        $navType = strtolower(trim($navType));
        $target  = trim($target);
        if ($navType === SiteNavService::TYPE_EXTERNAL) {
            return self::EXTERNAL;
        }
        if ($navType === SiteNavService::TYPE_PAGE) {
            return $this->isShowcaseTarget($target) ? self::PRODUCT : self::PAGE;
        }
        if ($navType === SiteNavService::TYPE_ROUTE || $navType === SiteNavService::TYPE_TAG) {
            // TYPE_TAG 遗留行：按 route 门牌推断（normalizeType 会归一为 route）
            if ($target === '' || $target === '/') {
                return self::HOME;
            }
            if ($this->isShowcaseTarget($target)) {
                return self::PRODUCT;
            }

            return self::DOCUMENT;
        }
        if ($navType === SiteNavService::TYPE_NONE) {
            if ($target !== '' && $this->isShowcaseTarget($target)) {
                return self::PRODUCT;
            }

            return self::DOCUMENT;
        }

        return self::DOCUMENT;
    }

    /**
     * @return list<string>
     */
    public static function showcaseTargetKeys(): array
    {
        return self::SHOWCASE_TARGET_KEYS;
    }
}
