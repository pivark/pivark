<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

/** 装站一次性：完成页外链（cn/com 探测）；装完可随 install/ 删除 */

final class InstallCompletionLinksService
{

    /**
     * 安装完成页外链定义（cn/com 成对；未规划定稿前由 resolve 自动探测可达域）
     *
     * @return list<array{label:string,hint:string,url?:string,url_cn?:string,url_com?:string}>
     */
    public function completionExternalLinkDefinitions(): array
    {
        $repo = trim((string) config('pivark.opensource_repo_url', 'https://gitee.com/pivark/pivark/releases'));
        if ($repo === '') {
            $repo = 'https://gitee.com/pivark/pivark/releases';
        }

        return [
            [
                'label'   => '元舟官网',
                'url_cn'  => 'https://pivark.cn',
                'url_com' => 'https://pivark.com',
                'hint'    => '产品动态、授权与商业支持',
            ],
            [
                'label'   => '在线文档',
                'url_cn'  => 'https://help.pivark.cn',
                'url_com' => 'https://help.pivark.com',
                'hint'    => '安装、后台与模板开发手册',
            ],
            [
                'label'   => '演示站点',
                'url_cn'  => 'https://demo.pivark.cn',
                'url_com' => 'https://demo.pivark.com',
                'hint'    => 'Community 演示站，模板与插件效果参考',
            ],
            [
                'label' => '开源发行版',
                'url'   => $repo,
                'hint'  => '下载更新包与 Release 说明',
            ],
        ];
    }

    /**
     * @return list<array{label:string,url:string,hint:string,url_cn?:string,url_com?:string,picked?:string}>
     */
    public function completionExternalLinks(): array
    {
        $out = [];
        foreach ($this->completionExternalLinkDefinitions() as $row) {
            $urlCn  = trim((string) ($row['url_cn'] ?? ''));
            $urlCom = trim((string) ($row['url_com'] ?? ''));
            $url    = trim((string) ($row['url'] ?? ''));
            if ($url === '' && ($urlCn !== '' || $urlCom !== '')) {
                $url = $urlCn !== '' ? $urlCn : $urlCom;
            }
            $item = [
                'label' => (string) ($row['label'] ?? ''),
                'url'   => $url !== '' ? $url : '#',
                'hint'  => (string) ($row['hint'] ?? ''),
            ];
            if ($urlCn !== '') {
                $item['url_cn'] = $urlCn;
            }
            if ($urlCom !== '') {
                $item['url_com'] = $urlCom;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * 安装完成页：cn/com 互为 fallback（先 cn 后 com；均不可达则保留 cn 默认）
     *
     * @return list<array{label:string,url:string,hint:string,picked:string,url_cn?:string,url_com?:string}>
     */
    public function resolveCompletionExternalLinks(): array
    {
        $out = [];
        foreach ($this->completionExternalLinkDefinitions() as $row) {
            $label = (string) ($row['label'] ?? '');
            $hint  = (string) ($row['hint'] ?? '');
            $urlCn = trim((string) ($row['url_cn'] ?? ''));
            $urlCom = trim((string) ($row['url_com'] ?? ''));
            if ($urlCn !== '' && $urlCom !== '') {
                $picked = $this->pickDualDomainUrl($urlCn, $urlCom);
                $out[] = [
                    'label'   => $label,
                    'hint'    => $hint,
                    'url'     => $picked['url'],
                    'picked'  => $picked['picked'],
                    'url_cn'  => $urlCn,
                    'url_com' => $urlCom,
                ];
                continue;
            }

            $single = trim((string) ($row['url'] ?? ''));
            $out[] = [
                'label'  => $label,
                'hint'   => $hint,
                'url'    => $single !== '' ? $single : '#',
                'picked' => 'single',
            ];
        }

        return $out;
    }

    /**
     * @return array{url:string,picked:string}
     */
    private function pickDualDomainUrl(string $urlCn, string $urlCom): array
    {
        foreach ([$urlCn, $urlCom] as $candidate) {
            if ($this->urlReachable($candidate)) {
                return [
                    'url'    => $candidate,
                    'picked' => str_contains(strtolower($candidate), '.cn') ? 'cn' : 'com',
                ];
            }
        }

        return [
            'url'    => $urlCn !== '' ? $urlCn : $urlCom,
            'picked' => 'fallback',
        ];
    }

    private function urlReachable(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($url, $cache)) {
            return $cache[$url];
        }

        $timeout = 2;
        $ok      = false;
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY         => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT      => 'PivArk-Install-LinkProbe/1.0',
                ]);
                curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = (int) curl_errno($ch);
                curl_close($ch);
                $ok = $err === 0 && $code >= 200 && $code < 400;
            }
        } elseif (\ini_get('allow_url_fopen')) {
            $ctx = stream_context_create([
                'http' => [
                    'method'          => 'HEAD',
                    'timeout'         => $timeout,
                    'follow_location' => 1,
                    'ignore_errors'   => true,
                    'header'          => "User-Agent: PivArk-Install-LinkProbe/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $headers = @get_headers($url, true, $ctx);
            if (\is_array($headers)) {
                $statusLine = (string) ($headers[0] ?? '');
                $ok         = preg_match('/\s(2\d\d|3\d\d)\s/', $statusLine) === 1;
            }
        }

        $cache[$url] = $ok;

        return $ok;
    }
}
