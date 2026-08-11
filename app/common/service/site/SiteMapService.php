<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;

/** 企业站联系页地图（系统配置 → 基本设置 → 联系与地图） */
class SiteMapService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->configService->get('site_map_enabled', '0') === '1';
    }

    public function buildNavUrl(?string $address = null): string
    {
        $address = trim($address ?? (string) $this->configService->get('site_address', ''));
        $lat     = $this->parseLat($this->configService->get('site_map_lat', ''));
        $lng     = $this->parseLng($this->configService->get('site_map_lng', ''));
        $ak      = $this->baiduMapAk();
        $name    = $this->mapPlaceName();

        // 有坐标 → 百度 marker（有 AK 则带 ak；无 AK 仍走百度，不回落高德）
        if ($lat !== null && $lng !== null) {
            $content = $address !== '' ? $address : $name;

            return $this->baiduMarkerUrl($lat, $lng, $name, $content, $ak);
        }

        if ($address === '') {
            return '';
        }

        return 'https://map.baidu.com/search?querytype=s&wd=' . rawurlencode($address);
    }

    public function buildEmbedUrl(): string
    {
        $custom = trim((string) $this->configService->get('site_map_embed_url', ''));
        if ($custom !== '') {
            return $this->sanitizeEmbedUrl($custom);
        }

        $lat = $this->parseLat($this->configService->get('site_map_lat', ''));
        $lng = $this->parseLng($this->configService->get('site_map_lng', ''));
        if ($lat === null || $lng === null) {
            return '';
        }

        $ak = $this->baiduMapAk();
        // 后台「百度地图 AK」有值 → 静态图嵌入（Web 服务需 AK）
        if ($ak !== '') {
            return 'https://api.map.baidu.com/staticimage/v2?' . http_build_query([
                'ak'           => $ak,
                'center'       => sprintf('%.6F,%.6F', $lng, $lat),
                'width'        => 800,
                'height'       => 450,
                'zoom'         => 16,
                'markers'      => sprintf('%.6F,%.6F', $lng, $lat),
                'markerStyles' => 'l,A',
            ], '', '&', PHP_QUERY_RFC3986);
        }

        $name = $this->mapPlaceName();
        $address = trim((string) $this->configService->get('site_address', ''));
        $content = $address !== '' ? $address : $name;

        // 无 AK：百度 marker 页嵌入（不回落高德）
        return $this->baiduMarkerUrl($lat, $lng, $name, $content, '');
    }

    public function renderIframeHtml(): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $src = $this->buildEmbedUrl();
        if ($src === '') {
            return '';
        }

        $title = htmlspecialchars($this->mapPlaceName(), ENT_QUOTES, 'UTF-8');
        $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

        // 百度静态图用 img；其它仍 iframe
        if (str_contains($src, 'api.map.baidu.com/staticimage/')) {
            return sprintf(
                '<img class="pv-site-map-iframe" alt="%s" loading="lazy" width="800" height="450" src="%s">',
                $title,
                $safeSrc
            );
        }

        return sprintf(
            '<iframe class="pv-site-map-iframe" title="%s" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="%s"></iframe>',
            $title,
            $safeSrc
        );
    }

    /**
     * @param array<string, string> $attrs title="0" link="0" class="..."
     */
    public function renderBlock(array $attrs = []): string
    {
        if (!$this->isEnabled()) {
            return '';
        }

        $navUrl = $this->buildNavUrl();
        $iframe = $this->renderIframeHtml();
        if ($navUrl === '' && $iframe === '') {
            return '';
        }

        $showTitle = ($attrs['title'] ?? '1') !== '0';
        $showLink  = ($attrs['link'] ?? '1') !== '0';
        $extraClass = trim((string) ($attrs['class'] ?? ''));
        $note = trim((string) $this->configService->get('site_map_note', ''));

        $class = 'pv-site-map-section';
        if ($extraClass !== '') {
            $class .= ' ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8');
        }

        $html = '<section class="' . $class . '" aria-label="公司位置地图">';
        if ($showTitle || ($showLink && $navUrl !== '')) {
            $html .= '<div class="pv-site-map-header">';
            if ($showTitle) {
                $html .= '<h2 class="pv-site-map-title">来访导航</h2>';
            }
            if ($showLink && $navUrl !== '') {
                $html .= '<a class="pv-site-map-nav-link" href="'
                    . htmlspecialchars($navUrl, ENT_QUOTES, 'UTF-8')
                    . '" target="_blank" rel="noopener noreferrer">在百度地图中打开</a>';
            }
            $html .= '</div>';
        }
        if ($iframe !== '') {
            $html .= '<div class="pv-site-map">' . $iframe . '</div>';
        }
        if ($note !== '') {
            $html .= '<p class="pv-site-map-note">'
                . htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
        $html .= '</section>';

        return $html;
    }

    /** @return array<string, int|string> */
    public function templateVars(): array
    {
        $enabled = $this->isEnabled();
        $note    = trim((string) $this->configService->get('site_map_note', ''));
        $navUrl  = $this->buildNavUrl();
        $iframe  = $this->renderIframeHtml();

        return [
            'site_map_enabled'    => $enabled ? 1 : 0,
            'site_map_empty'      => (!$enabled || ($iframe === '' && $navUrl === '')) ? 1 : 0,
            'site_map_nav_url'    => $navUrl,
            'site_map_iframe'     => $iframe,
            'site_map_note'       => $note,
            'site_map_note_empty' => $note === '' ? 1 : 0,
            'site_map_html'       => $this->renderBlock([]),
        ];
    }

    private function baiduMapAk(): string
    {
        return trim((string) $this->configService->get('baidu_map_ak', ''));
    }

    private function baiduMarkerUrl(
        float $lat,
        float $lng,
        string $name,
        string $content,
        string $ak = '',
    ): string {
        $url = 'https://api.map.baidu.com/marker?location='
            . rawurlencode(sprintf('%.6F,%.6F', $lat, $lng))
            . '&title=' . rawurlencode($name)
            . '&content=' . rawurlencode($content)
            . '&output=html&src=webapp';
        if ($ak !== '') {
            $url .= '&ak=' . rawurlencode($ak);
        }

        return $url;
    }

    private function mapPlaceName(): string
    {
        $name = trim((string) $this->configService->get('site_name', ''));

        return $name !== '' ? $name : '公司位置';
    }

    private function sanitizeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function parseLat(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $num = (float) $raw;
        if ($num < -90 || $num > 90) {
            return null;
        }

        return $num;
    }

    private function parseLng(mixed $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $num = (float) $raw;
        if ($num < -180 || $num > 180) {
            return null;
        }

        return $num;
    }
}
