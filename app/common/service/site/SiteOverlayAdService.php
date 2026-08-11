<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\PvPublicAsset;

class SiteOverlayAdService
{

    public function __construct(
        private readonly SiteSlideService $slides,
        private readonly SiteNavService $nav,
    ) {
    }

    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $cache = null;

    public function isOverlayCreativeType(string $type): bool
    {
        return in_array($type, $this->slides->overlayCreativeTypes(), true);
    }

    public function isHomePage(): bool
    {
        $path = $this->nav->currentPath();

        return $path === '/' || $path === '/index.html';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveForCurrentPage(): array
    {
        $isHome = $this->isHomePage();
        $key    = $isHome ? 'home' : 'site';

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $out = [];
        foreach ($this->slides->listActiveOverlayMaterials() as $row) {
            $scope = (string) ($row['display_scope'] ?? SiteSlideService::DISPLAY_SCOPE_ALL);
            if ($scope === SiteSlideService::DISPLAY_SCOPE_HOME && !$isHome) {
                continue;
            }
            $out[] = $row;
        }

        return self::$cache[$key] = $out;
    }

    public function renderForCurrentPage(): string
    {
        $rows = $this->listActiveForCurrentPage();
        if ($rows === []) {
            return '';
        }

        $parts = [
            '<link rel="stylesheet" href="' . PvPublicAsset::css('pv-site-overlay.css') . '">',
        ];

        $hasMourning = false;
        foreach ($rows as $row) {
            $type = (string) ($row['creative_type'] ?? '');
            if ($type === SiteSlideService::TYPE_MOURNING) {
                $hasMourning = true;
                continue;
            }
            if ($type === SiteSlideService::TYPE_OVERLAY_CENTER) {
                $parts[] = $this->renderOverlayCenter($row);
            }
        }

        if ($hasMourning) {
            $mourning = null;
            foreach ($rows as $row) {
                if ((string) ($row['creative_type'] ?? '') === SiteSlideService::TYPE_MOURNING) {
                    $mourning = $row;
                    break;
                }
            }
            if ($mourning !== null) {
                $parts[] = $this->renderMourning($mourning);
            }
        }

        $parts[] = '<script src="' . PvPublicAsset::js('pv-site-overlay.js') . '" defer></script>';

        return implode("\n", array_filter($parts));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderOverlayCenter(array $row): string
    {
        $id      = (int) ($row['id'] ?? 0);
        $title   = htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string) ($row['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $image   = htmlspecialchars((string) ($row['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $link    = trim((string) ($row['link_url'] ?? ''));
        $linkText = htmlspecialchars(trim((string) ($row['link_text'] ?? '')) ?: '查看详情', ENT_QUOTES, 'UTF-8');
        $target  = ((int) ($row['open_new_tab'] ?? 0) === 1) ? '_blank' : '_self';
        $scope   = htmlspecialchars((string) ($row['display_scope'] ?? 'all'), ENT_QUOTES, 'UTF-8');

        if ($image === '') {
            return '';
        }

        $cta = '';
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            $href = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $cta  = '<a class="pv-site-overlay__cta" href="' . $href . '" target="' . $target . '" rel="noopener noreferrer">' . $linkText . '</a>';
        }

        $subtitleHtml = $subtitle !== '' ? '<p class="pv-site-overlay__subtitle">' . $subtitle . '</p>' : '';

        return <<<HTML
<div class="pv-site-overlay pv-site-overlay--center" data-overlay-id="{$id}" data-overlay-type="center" data-overlay-scope="{$scope}" hidden>
  <div class="pv-site-overlay__backdrop" data-overlay-close></div>
  <div class="pv-site-overlay__panel" role="dialog" aria-modal="true" aria-label="{$title}">
    <button type="button" class="pv-site-overlay__close" data-overlay-close aria-label="关闭">&times;</button>
    <div class="pv-site-overlay__body">
      <img class="pv-site-overlay__image" src="{$image}" alt="{$title}">
      <div class="pv-site-overlay__text">
        <h3 class="pv-site-overlay__title">{$title}</h3>
        {$subtitleHtml}
        {$cta}
      </div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderMourning(array $row): string
    {
        $id       = (int) ($row['id'] ?? 0);
        $title    = htmlspecialchars(trim((string) ($row['title'] ?? '')) ?: '沉痛悼念', ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string) ($row['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $image    = trim((string) ($row['image_url'] ?? ''));
        $imageHtml = $image !== ''
            ? '<img class="pv-site-mourning__photo" src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="">'
            : '';
        $subtitleHtml = $subtitle !== ''
            ? '<p class="pv-site-mourning__subtitle">' . $subtitle . '</p>'
            : '';

        return <<<HTML
<div class="pv-site-mourning" data-overlay-id="{$id}" data-overlay-type="mourning" aria-hidden="true">
  <div class="pv-site-mourning__bar">
    {$imageHtml}
    <div class="pv-site-mourning__text">
      <strong class="pv-site-mourning__title">{$title}</strong>
      {$subtitleHtml}
    </div>
  </div>
</div>
HTML;
    }
}
