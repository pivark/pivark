<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/** 上传 SVG 消毒（去脚本、事件、嵌入物） */
class SvgSanitizer
{
    /**
     * @return string|null 消毒后的 XML；不安全或无效时 null
     */
    public static function clean(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || stripos($raw, '<svg') === false) {
            return null;
        }

        $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            return self::cleanByRegex($raw);
        }

        $dom                     = dom_import_simplexml($xml)->ownerDocument;
        if ($dom === null) {
            return null;
        }
        $dom->formatOutput       = false;
        $dom->preserveWhiteSpace = true;

        self::stripDangerousNodes($dom->documentElement);
        self::stripDangerousAttributes($dom->documentElement);

        $out = $dom->saveXML($dom->documentElement);
        if ($out === false || !self::isSafeEnough($out)) {
            return null;
        }

        return $out;
    }

    private static function cleanByRegex(string $raw): ?string
    {
        $out = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw) ?? '';
        $out = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $out) ?? '';
        $out = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $out) ?? '';
        $out = preg_replace(
            '/\s+(href|xlink:href)\s*=\s*("\s*javascript[^"]*"|\'\s*javascript[^\']*\'|javascript[^\s>]+)/i',
            '',
            $out
        ) ?? '';
        if (!self::isSafeEnough($out)) {
            return null;
        }
        return trim($out);
    }

    private static function isSafeEnough(string $svg): bool
    {
        if (stripos($svg, '<script') !== false) {
            return false;
        }
        if (preg_match('/\son\w+\s*=/i', $svg) === 1) {
            return false;
        }
        if (preg_match('/javascript\s*:/i', $svg) === 1) {
            return false;
        }
        if (stripos($svg, '<foreignobject') !== false) {
            return false;
        }
        return stripos($svg, '<svg') !== false;
    }

    private static function stripDangerousNodes(?\DOMNode $node): void
    {
        if ($node === null) {
            return;
        }
        $remove = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video'], true)) {
                $remove[] = $child;
                continue;
            }
            self::stripDangerousNodes($child);
        }
        foreach ($remove as $n) {
            $node->removeChild($n);
        }
    }

    private static function stripDangerousAttributes(?\DOMNode $node): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof \DOMElement) {
            $toDrop = [];
            foreach ($node->attributes ?? [] as $attr) {
                $name = strtolower($attr->nodeName);
                $val  = (string) $attr->nodeValue;
                if (str_starts_with($name, 'on')) {
                    $toDrop[] = $name;
                    continue;
                }
                if (in_array($name, ['href', 'xlink:href'], true) && preg_match('/^\s*javascript\s*:/i', $val) === 1) {
                    $toDrop[] = $name;
                }
            }
            foreach ($toDrop as $name) {
                $node->removeAttribute($name);
            }
        }
        foreach ($node->childNodes as $child) {
            self::stripDangerousAttributes($child);
        }
    }
}
