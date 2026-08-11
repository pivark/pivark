<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin\concern;

use app\common\model\Plugin;
use think\facade\Request;

trait PluginPostIdentifier
{
    /** @var list<string> */
    private static array $pluginIdParamKeys = ['pv_id_rev', 'pv_id_hex', 'pv_id_b64', 'pv_id', 'weapp_id_hex', 'weapp_id_b64', 'weapp_id', 'pivark_plugin_id', 'plugin_id', 'identifier', 'id'];

    protected function pluginPostIdentifier(bool $lower = true): string
    {
        $id = $this->pluginIdentifierFromRegistryIdx($lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromCharArrayPost($lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromPairPost($lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromRequest('post', $lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromRequest('get', $lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromSplitPost($lower);
        if ($id !== '') {
            return $id;
        }

        return $this->pluginIdentifierFromHeader($lower);
    }

    protected function pluginQueryIdentifier(bool $lower = true): string
    {
        return $this->pluginIdentifierFromRequest('get', $lower);
    }

    protected function pluginParamIdentifier(bool $lower = true): string
    {
        $id = $this->pluginIdentifierFromRequest('param', $lower);
        if ($id !== '') {
            return $id;
        }
        $id = $this->pluginIdentifierFromSplitParam($lower);
        if ($id !== '') {
            return $id;
        }

        return $this->pluginIdentifierFromHeader($lower);
    }

    private function pluginIdentifierFromRegistryIdx(bool $lower): string
    {
        $idx = (int) Request::post('nr', 0);
        if ($idx > 10_000) {
            $idx -= 10_000;
        }
        if ($idx < 1) {
            $idx = (int) Request::post('idx', 0);
        }
        if ($idx < 1) {
            $idx = (int) Request::post('n', 0);
        }
        if ($idx < 1) {
            return '';
        }
        $row = Plugin::where('id', $idx)->find();
        if ($row === null) {
            return '';
        }
        $id = trim((string) ($row['identifier'] ?? ''));
        if ($id === '') {
            return '';
        }

        return $lower ? strtolower($id) : $id;
    }

    private function pluginIdentifierFromPairPost(bool $lower): string
    {
        foreach ([['m1', 'm2'], ['p1', 'p2'], ['pa', 'pb']] as [$k1, $k2]) {
            $part1 = $this->pluginDecodeRot13Part((string) Request::post($k1, ''));
            if ($part1 === '') {
                continue;
            }
            $part2 = $this->pluginDecodeRot13Part((string) Request::post($k2, ''));
            $id = $part2 === '' ? $part1 : $part1 . '_' . $part2;

            return $lower ? strtolower($id) : $id;
        }

        return '';
    }

    private function pluginDecodeRot13Part(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return trim(str_rot13($raw));
    }

    private function pluginIdentifierFromCharArrayPost(bool $lower): string
    {
        $raw = Request::post('pv_c');
        if ($raw === null || $raw === '') {
            return '';
        }
        if (is_string($raw)) {
            $id = trim($raw);

            return $id === '' ? '' : ($lower ? strtolower($id) : $id);
        }
        if (!is_array($raw)) {
            return '';
        }
        $parts = [];
        foreach ($raw as $ch) {
            if (!is_scalar($ch)) {
                continue;
            }
            $parts[] = (string) $ch;
        }
        $id = implode('', $parts);

        return $id === '' ? '' : ($lower ? strtolower($id) : $id);
    }

    private function pluginIdentifierFromSplitPost(bool $lower): string
    {
        return $this->pluginJoinSplitParts(
            $this->pluginSplitPartFromPost('w_ns', 'weapp_ns'),
            $this->pluginSplitPartFromPost('w_slug', 'weapp_slug'),
            $lower,
        );
    }

    private function pluginIdentifierFromSplitParam(bool $lower): string
    {
        return $this->pluginJoinSplitParts(
            $this->pluginSplitPartFromParam('w_ns', 'weapp_ns'),
            $this->pluginSplitPartFromParam('w_slug', 'weapp_slug'),
            $lower,
        );
    }

    private function pluginSplitPartFromPost(string $primary, string $legacy): string
    {
        $val = trim((string) Request::post($primary, ''));
        if ($val !== '') {
            return $val;
        }

        return trim((string) Request::post($legacy, ''));
    }

    private function pluginSplitPartFromParam(string $primary, string $legacy): string
    {
        $val = trim((string) Request::param($primary, ''));
        if ($val !== '') {
            return $val;
        }

        return trim((string) Request::param($legacy, ''));
    }

    private function pluginJoinSplitParts(string $ns, string $slug, bool $lower): string
    {
        if ($slug === '') {
            return '';
        }
        $id = $ns === '' ? $slug : $ns . '_' . $slug;

        return $lower ? strtolower($id) : $id;
    }

    private function pluginIdentifierFromHeader(bool $lower): string
    {
        $id = trim((string) Request::header('X-Pivark-Weapp-Id', ''));
        if ($id === '') {
            $id = trim((string) Request::header('x-pivark-weapp-id', ''));
        }

        return $id === '' ? '' : ($lower ? strtolower($id) : $id);
    }

    private function pluginIdentifierFromRequest(string $source, bool $lower): string
    {
        foreach (self::$pluginIdParamKeys as $key) {
            $raw = match ($source) {
                'post'  => trim((string) Request::post($key, '')),
                'get'   => trim((string) Request::get($key, '')),
                default => trim((string) Request::param($key, '')),
            };
            if ($raw === '') {
                continue;
            }
            $id = match (true) {
                str_ends_with($key, '_rev') => $this->pluginDecodeRevIdentifier($raw),
                str_ends_with($key, '_hex') => $this->pluginDecodeHexIdentifier($raw),
                str_ends_with($key, '_b64') => $this->pluginDecodeB64Identifier($raw),
                default                       => $raw,
            };
            if ($id !== '') {
                return $lower ? strtolower($id) : $id;
            }
        }

        return '';
    }

    private function pluginDecodeRevIdentifier(string $raw): string
    {
        return $raw === '' ? '' : trim(strrev($raw));
    }

    private function pluginDecodeHexIdentifier(string $raw): string
    {
        $raw = strtolower($raw);
        if ($raw === '' || !ctype_xdigit($raw) || strlen($raw) % 2 !== 0) {
            return '';
        }
        $decoded = hex2bin($raw);

        return is_string($decoded) ? trim($decoded) : '';
    }

    private function pluginDecodeB64Identifier(string $raw): string
    {
        $decoded = base64_decode($raw, true);

        return is_string($decoded) ? trim($decoded) : '';
    }
}
