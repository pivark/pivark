<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 文档属性标记（attr_flags）、外链展示
 *
 * @see app/common/service/document/README.md
 */
declare(strict_types=1);

namespace app\common\service\document;

/** 文档列表/表单/模板共用的 attr_flags 逻辑 */
final class DocumentAttrHelper
{

    /** @var list<string> */
    public const ATTR_FLAGS = ['headline', 'recommend', 'push', 'bold', 'has_image', 'external'];

    /** @var array<string, string> */
    public const ATTR_FLAG_LABELS = [
        'headline'   => '头条',
        'recommend'  => '推荐',
        'push'       => '加推',
        'bold'       => '标粗',
        'has_image'  => '有图',
        'external'   => '外链',
    ];

    /** @var array<string, string> */
    public const ATTR_FLAG_SHORT = [
        'headline'   => '头',
        'recommend'  => '荐',
        'push'       => '推',
        'bold'       => '粗',
        'has_image'  => '图',
        'external'   => '链',
    ];

    /**
     * @param array<string, mixed> $data POST 字段（attr_headline 等 checkbox）
     */
    public function attrFlagsFromPost(array $data): string
    {
        $raw = trim((string) ($data['attr_flags'] ?? ''));
        if ($raw !== '') {
            return $this->stripAttrFlag($this->normalizeAttrFlags($raw), 'has_image');
        }

        $picked = [];
        foreach (self::ATTR_FLAGS as $flag) {
            if ($flag === 'has_image') {
                continue;
            }
            $key = 'attr_' . $flag;
            if (!empty($data[$key]) && (string) $data[$key] === '1') {
                $picked[] = $flag;
            }
        }

        return implode(',', $picked);
    }

    public function stripAttrFlag(string $flagsCsv, string $flag): string
    {
        if ($flagsCsv === '' || $flag === '') {
            return $this->normalizeAttrFlags($flagsCsv);
        }
        $parts = [];
        foreach (array_filter(array_map('trim', explode(',', $flagsCsv))) as $f) {
            if ($f !== $flag) {
                $parts[] = $f;
            }
        }

        return $this->normalizeAttrFlags(implode(',', $parts));
    }

    public function mergeAttrFlag(string $flagsCsv, string $flag): string
    {
        $flag = trim($flag);
        if ($flag === '' || !in_array($flag, self::ATTR_FLAGS, true)) {
            return $this->normalizeAttrFlags($flagsCsv);
        }
        if ($this->hasAttrFlag($flagsCsv, $flag)) {
            return $this->normalizeAttrFlags($flagsCsv);
        }
        $parts   = array_filter(array_map('trim', explode(',', $this->normalizeAttrFlags($flagsCsv))));
        $parts[] = $flag;

        return $this->normalizeAttrFlags(implode(',', $parts));
    }

    /**
     * @param list<string>|string $flags
     * @return list<string>
     */
    public function normalizeManualAttrFlags(array|string $flags): array
    {
        if (is_string($flags)) {
            $flags = $flags === '' ? [] : array_map('trim', explode(',', $flags));
        }
        $out = [];
        foreach ($flags as $flag) {
            $flag = trim((string) $flag);
            if ($flag === '' || $flag === 'has_image' || !in_array($flag, self::ATTR_FLAGS, true)) {
                continue;
            }
            if (!in_array($flag, $out, true)) {
                $out[] = $flag;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function decorateAttrFlagsForView(array $item): array
    {
        $flags = (string) ($item['attr_flags'] ?? '');
        $labels = $this->attrFlagLabels($flags);
        $item['is_bold']          = $this->hasAttrFlag($flags, 'bold') ? 1 : 0;
        $item['is_headline']      = $this->hasAttrFlag($flags, 'headline') ? 1 : 0;
        $item['is_recommend']     = $this->hasAttrFlag($flags, 'recommend') ? 1 : 0;
        $item['is_push']          = $this->hasAttrFlag($flags, 'push') ? 1 : 0;
        $item['title_class']      = $this->hasAttrFlag($flags, 'bold') ? 'fw-bold' : '';
        $item['attr_labels']      = $labels;
        $item['attr_label_text']  = $labels !== [] ? implode(' · ', $labels) : '';
        $item['external_open_new_tab'] = (int) ($item['external_open_new_tab'] ?? 0);
        $item['link_target'] = '_self';
        if ($this->hasAttrFlag($flags, 'external')) {
            $ext = trim((string) ($item['external_url'] ?? ''));
            if ($ext !== '' && preg_match('#^https?://#i', $ext)) {
                $item['external_href'] = $ext;
                $item['link_target'] = $this->externalOpensInNewTab($item) ? '_blank' : '_self';
            }
        }

        return $item;
    }

    public function hasAttrFlag(string $flagsCsv, string $flag): bool
    {
        if ($flagsCsv === '' || $flag === '') {
            return false;
        }
        $set = array_flip(array_filter(array_map('trim', explode(',', $flagsCsv))));

        return isset($set[$flag]);
    }

    public function normalizeAttrFlags(string $flagsCsv): string
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $flagsCsv))) as $f) {
            if (in_array($f, self::ATTR_FLAGS, true) && !in_array($f, $out, true)) {
                $out[] = $f;
            }
        }

        return implode(',', $out);
    }

    /** @return list<string> */
    public function attrFlagLabels(string $flagsCsv): array
    {
        $labels = [];
        foreach (array_filter(array_map('trim', explode(',', $flagsCsv))) as $flag) {
            if (isset(self::ATTR_FLAG_LABELS[$flag])) {
                $labels[] = self::ATTR_FLAG_LABELS[$flag];
            }
        }

        return $labels;
    }

    public function resolveExternalRedirectUrl(array $row): ?string
    {
        if (!$this->hasAttrFlag((string) ($row['attr_flags'] ?? ''), 'external')) {
            return null;
        }
        $url = trim((string) ($row['external_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    public function externalOpensInNewTab(array $row): bool
    {
        return (int) ($row['external_open_new_tab'] ?? 0) === 1;
    }

    public function mergeAttrHasImage(string $flagsCsv, string $litpic): string
    {
        $flags = [];
        foreach (array_filter(array_map('trim', explode(',', $this->normalizeAttrFlags($flagsCsv)))) as $f) {
            if ($f !== 'has_image') {
                $flags[] = $f;
            }
        }
        if (trim($litpic) !== '') {
            $flags[] = 'has_image';
        }

        return $this->normalizeAttrFlags(implode(',', $flags));
    }

    /** @return list<string> */
    public function attrFlagShorts(string $flagsCsv): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $flagsCsv))) as $flag) {
            if (isset(self::ATTR_FLAG_SHORT[$flag])) {
                $out[] = self::ATTR_FLAG_SHORT[$flag];
            }
        }

        return $out;
    }
}
