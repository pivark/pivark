<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 剪贴板链接洞察：本地规则 + 可选抓取页面 OG/TDK（仅后台）
 */
declare(strict_types=1);

namespace app\common\service\content;

use app\common\support\ServiceResult;

use app\common\support\HtmlSanitizer;
use app\common\support\LocalFile;

class ClipboardUrlInsightService
{

    private const TIMEOUT = 5;
    private const MAX_BYTES = 524288;

    /**
     * @return ServiceResult
     */
    public function analyze(string $url, string $rawContext = ''): ServiceResult
    {
        $url = trim($url);
        if ($url === '' || !$this->isFetchableUrl($url)) {
            return ServiceResult::fail('链接无效');
        }

        $local = $this->localInsight($url, $rawContext);
        $remote = $this->fetchOgInsight($url);
        $merged = $this->mergeInsight($local, $remote);

        return ServiceResult::ok($merged, 'ok');
    }

    /**
     * @return array<string, mixed>
     */
    private function localInsight(string $url, string $rawContext): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $platform = $this->platformName($host);
        $title = $this->titleFromContext($rawContext, $url);
        if ($title === '') {
            $title = $this->titleFromUrlPath($url);
        }

        $tags = [];
        if ($platform !== '') {
            $tags[] = $platform;
            $tags[] = '网盘资源';
        }

        $seoTitle = $title !== '' ? $title . ($platform !== '' ? ' - ' . $platform : '') : '';
        $seoTitle = mb_substr($seoTitle, 0, 60);

        return [
            'title'           => $title,
            'seo_title'       => $seoTitle !== '' ? $seoTitle : $title,
            'seo_description' => $this->buildDescription($title, $platform),
            'seo_keywords'    => $this->buildKeywords($title, $platform),
            'tags'            => array_values(array_unique(array_filter($tags))),
            'source'          => 'local',
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private function mergeInsight(array $a, array $b): array
    {
        $tags = array_values(array_unique(array_merge(
            is_array($a['tags'] ?? null) ? $a['tags'] : [],
            is_array($b['tags'] ?? null) ? $b['tags'] : [],
        )));

        return [
            'title'           => (string) ($b['title'] ?? $a['title'] ?? ''),
            'seo_title'       => (string) ($b['seo_title'] ?? $a['seo_title'] ?? ''),
            'seo_description' => (string) ($b['seo_description'] ?? $a['seo_description'] ?? ''),
            'seo_keywords'    => (string) ($b['seo_keywords'] ?? $a['seo_keywords'] ?? ''),
            'tags'            => $tags,
            'source'          => ($b['source'] ?? '') === 'remote' ? 'remote' : ($a['source'] ?? 'local'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOgInsight(string $url): array
    {
        $html = $this->fetchHtml($url);
        if ($html === null || $html === '') {
            return [];
        }

        $head = mb_substr($html, 0, 120000);
        $title = $this->matchMeta($head, 'og:title') ?: $this->matchTitleTag($head);
        $description = $this->matchMeta($head, 'og:description')
            ?: $this->matchMeta($head, 'description', 'name');

        if ($title === '' && $description === '') {
            return [];
        }

        $title = HtmlSanitizer::cleanPlainText($title, 80);
        $description = HtmlSanitizer::cleanPlainText($description, 160);

        return [
            'title'           => $title,
            'seo_title'       => mb_substr($title, 0, 60),
            'seo_description' => $description,
            'seo_keywords'    => $title !== '' ? $title : '',
            'tags'            => $title !== '' ? [$title] : [],
            'source'          => 'remote',
        ];
    }

    private function matchMeta(string $html, string $key, string $attr = 'property'): string
    {
        $pattern = '/<meta[^>]+' . preg_quote($attr, '/') . '=["\']' . preg_quote($key, '/')
            . '["\'][^>]+content=["\']([^"\']+)["\']/iu';
        if (preg_match($pattern, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $pattern2 = '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+' . preg_quote($attr, '/')
            . '=["\']' . preg_quote($key, '/') . '["\']/iu';
        if (preg_match($pattern2, $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function matchTitleTag(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/iu', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function fetchHtml(string $url): ?string
    {
        if (!$this->isFetchableUrl($url)) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'Mozilla/5.0 (compatible; PivArkClipboardInsight/1.0)',
                'header'          => "Accept: text/html,application/xhtml+xml\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = LocalFile::getContents($url, false, $ctx);
        if (!is_string($data) || $data === '') {
            return null;
        }
        if (strlen($data) > self::MAX_BYTES) {
            $data = substr($data, 0, self::MAX_BYTES);
        }

        return is_string($data) ? $data : null;
    }

    public function isFetchableUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    private function platformName(string $host): string
    {
        $map = [
            'pan.baidu.com'   => '百度网盘',
            'yun.baidu.com'   => '百度网盘',
            'pan.quark.cn'    => '夸克网盘',
            'aliyundrive.com' => '阿里云盘',
            'alipan.com'      => '阿里云盘',
            'cloud.189.cn'    => '天翼云盘',
            'bilibili.com'    => '哔哩哔哩',
            'www.bilibili.com'=> '哔哩哔哩',
        ];
        foreach ($map as $needle => $name) {
            if ($host === $needle || str_ends_with($host, '.' . $needle)) {
                return $name;
            }
        }
        if (str_contains($host, 'lanzou')) {
            return '蓝奏云';
        }

        return '';
    }

    private function titleFromContext(string $raw, string $url): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_contains($line, $url)) {
                continue;
            }
            if (preg_match('/^https?:\/\//i', $line)) {
                continue;
            }
            if (preg_match('/^(链接|提取码|访问码|密码)/u', $line) && mb_strlen($line) < 40) {
                continue;
            }
            $line = preg_replace('/https?:\/\/\S+/u', '', $line) ?? $line;
            $line = trim((string) preg_replace('/提取码[：:\s]*\w+/u', '', $line));
            if (mb_strlen($line) >= 2 && mb_strlen($line) <= 80) {
                return HtmlSanitizer::cleanPlainText($line, 80);
            }
        }

        return '';
    }

    private function titleFromUrlPath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        $decoded = rawurldecode($base);
        if ($decoded === '' || $decoded === '/') {
            return '';
        }
        $decoded = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $decoded) ?? $decoded;

        return HtmlSanitizer::cleanPlainText($decoded, 80);
    }

    private function buildDescription(string $title, string $platform): string
    {
        $parts = array_filter([
            $title !== '' ? $title : '',
            $platform !== '' ? $platform . '分享资源' : '远程资源',
        ]);

        return HtmlSanitizer::cleanPlainText(implode('，', $parts) . '。', 160);
    }

    private function buildKeywords(string $title, string $platform): string
    {
        $words = array_filter([$title, $platform, '资源下载']);
        $words = array_values(array_unique($words));

        return HtmlSanitizer::cleanPlainText(implode(',', $words), 120);
    }
}
