<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 浮动联系方式 — 前台 widget HTML 渲染
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\service\config\ConfigService;
use app\common\support\PvPublicAsset;

final class FloatContactWidgetRenderService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    private function contact(): FloatContactService
    {
        return app(FloatContactService::class);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    public function renderForStyle(array $items, array $cfg, string $style): string
    {
        return match ($style) {
            FloatContactStyleService::STYLE_ORBS  => $this->renderOrbsWidget($items, $cfg),
            FloatContactStyleService::STYLE_RAIL  => $this->renderRailWidget($items, $cfg),
            FloatContactStyleService::STYLE_DOCK  => $this->renderDockWidget($items, $cfg),
            FloatContactStyleService::STYLE_STACK => $this->renderStackWidget($items, $cfg),
            FloatContactStyleService::STYLE_FAB   => $this->renderFabWidget($items, $cfg),
            FloatContactStyleService::STYLE_EDGE  => $this->renderEdgeWidget($items, $cfg),
            FloatContactStyleService::STYLE_CARD  => $this->renderCardWidget($items, $cfg),
            default                               => $this->renderSidebarWidget($items, $cfg),
        };
    }
    private function renderSidebarWidget(array $items, array $cfg): string
    {
        $title = htmlspecialchars((string) ($cfg['float_contact_panel_title'] ?? '联系我们'), ENT_QUOTES, 'UTF-8');
        $barLbl = htmlspecialchars((string) ($cfg['float_contact_collapsed_label'] ?? '联系'), ENT_QUOTES, 'UTF-8');
        $rows   = '';
        foreach ($items as $item) {
            $rows .= $this->renderItemRow($item);
        }
        $inner = <<<HTML
    <button type="button" class="pv-float-contact__tab" aria-expanded="false" aria-controls="pv-float-contact-panel" title="{$barLbl}">
      <span class="pv-float-contact__tab-icon" aria-hidden="true">✉</span>
      <span class="pv-float-contact__tab-text">{$barLbl}</span>
    </button>
    <div id="pv-float-contact-panel" class="pv-float-contact__panel" hidden>
      <div class="pv-float-contact__panel-hd">
        <strong>{$title}</strong>
        <button type="button" class="pv-float-contact__close" aria-label="关闭">&times;</button>
      </div>
      <div class="pv-float-contact__list">{$rows}</div>
    </div>
HTML;

        return $this->wrapWidget(FloatContactStyleService::STYLE_SIDEBAR, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderOrbsWidget(array $items, array $cfg): string
    {
        $stack = '';
        foreach ($items as $item) {
            $stack .= $this->renderOrbNode($item);
        }
        $inner = '<div class="pv-fc-orbs__stack" role="group" aria-label="快捷联系">' . $stack . '</div>';

        return $this->wrapWidget(FloatContactStyleService::STYLE_ORBS, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderRailWidget(array $items, array $cfg): string
    {
        $stack = '';
        foreach ($items as $item) {
            $stack .= $this->renderRailNode($item);
        }
        $inner = '<div class="pv-fc-rail__stack" role="group" aria-label="快捷联系">' . $stack . '</div>';

        return $this->wrapWidget(FloatContactStyleService::STYLE_RAIL, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderDockWidget(array $items, array $cfg): string
    {
        $chips = '';
        foreach ($items as $item) {
            $chips .= $this->renderDockNode($item);
        }
        $inner = '<div class="pv-fc-dock__bar" role="group" aria-label="快捷联系">' . $chips . '</div>';

        return $this->wrapWidget(FloatContactStyleService::STYLE_DOCK, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderStackWidget(array $items, array $cfg): string
    {
        $stack = '';
        foreach ($items as $item) {
            $stack .= $this->renderStackNode($item);
        }
        $inner = '<div class="pv-fc-stack__list" role="group" aria-label="快捷联系">' . $stack . '</div>';

        return $this->wrapWidget(FloatContactStyleService::STYLE_STACK, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderFabWidget(array $items, array $cfg): string
    {
        $rows = '';
        foreach ($items as $item) {
            $rows .= $this->renderFabMenuNode($item);
        }
        $inner = '<div class="pv-fc-fab__list" role="group" aria-label="快捷联系">' . $rows . '</div>';

        return $this->wrapWidget(FloatContactStyleService::STYLE_FAB, $cfg, $inner, true);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderEdgeWidget(array $items, array $cfg): string
    {
        $stack = '';
        foreach ($items as $item) {
            $stack .= $this->renderEdgeNode($item);
        }
        $inner = '<div class="pv-fc-edge__stack" role="group" aria-label="快捷联系">' . $stack . '</div>'
            . $this->renderEdgeModalShell();

        return $this->wrapWidget(FloatContactStyleService::STYLE_EDGE, $cfg, $inner, true);
    }

    private function renderEdgeModalShell(): string
    {
        return <<<HTML
    <div class="pv-fc-edge__mask" hidden data-pv-fc-edge-mask>
      <div class="pv-fc-edge__modal" role="dialog" aria-modal="true">
        <button type="button" class="pv-fc-edge__close" aria-label="关闭">&times;</button>
        <h3 class="pv-fc-edge__modal-title"></h3>
        <ul class="pv-fc-edge__modal-list"></ul>
        <div class="pv-fc-edge__modal-qr" hidden>
          <img src="" alt="二维码" class="pv-fc-edge__modal-qr-img" />
          <p class="pv-fc-edge__modal-qr-tip"></p>
        </div>
        <p class="pv-fc-edge__modal-note" hidden></p>
        <a class="pv-fc-edge__modal-cta" href="#" target="_blank" rel="noopener noreferrer" hidden></a>
      </div>
    </div>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, string>      $cfg
     */
    private function renderCardWidget(array $items, array $cfg): string
    {
        $title = htmlspecialchars((string) ($cfg['float_contact_panel_title'] ?? '联系我们'), ENT_QUOTES, 'UTF-8');
        $rows  = '';
        foreach ($items as $item) {
            $rows .= $this->renderCardNode($item);
        }
        $inner = <<<HTML
    <div class="pv-fc-card">
      <div class="pv-fc-card__hd"><strong>{$title}</strong></div>
      <div class="pv-fc-card__list">{$rows}</div>
    </div>
HTML;

        return $this->wrapWidget(FloatContactStyleService::STYLE_CARD, $cfg, $inner, true);
    }

    /**
     * @param array<string, string> $cfg
     */
    private function wrapWidget(string $style, array $cfg, string $inner, bool $withQrLayer): string
    {
        $color   = htmlspecialchars((string) ($cfg['float_contact_theme_color'] ?? '#1e9fff'), ENT_QUOTES, 'UTF-8');
        $bottom  = max(40, min(400, (int) ($cfg['float_contact_offset_bottom'] ?? 120)));
        $mobile  = (string) ($cfg['float_contact_show_mobile'] ?? '1') === '1';
        $mobileClass = $mobile ? '' : ' pv-float-contact--hide-mobile';
        $styleEsc = htmlspecialchars($style, ENT_QUOTES, 'UTF-8');
        $fcCss    = PvPublicAsset::css('float-contact/widget.css');
        $fcJs     = PvPublicAsset::js('float-contact/widget.js');

        $qr = '';
        if ($withQrLayer) {
            $qr = <<<HTML
    <div class="pv-float-contact__qr" hidden>
      <div class="pv-float-contact__qr-box">
        <button type="button" class="pv-float-contact__qr-close" aria-label="关闭">&times;</button>
        <img src="" alt="微信二维码" class="pv-float-contact__qr-img" />
        <p class="pv-float-contact__qr-tip"></p>
      </div>
    </div>
HTML;
        }

        return <<<HTML
<div id="pv-float-contact" class="pv-float-contact pv-float-contact--{$styleEsc}{$mobileClass}" style="--pv-fc-color:{$color};--pv-fc-bottom:{$bottom}px" data-pv-float-contact data-style="{$styleEsc}">
{$inner}
{$qr}
</div>
<link rel="stylesheet" href="{$fcCss}">
<script src="{$fcJs}" defer></script>
HTML;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderOrbNode(array $item): string
    {
        $type    = (string) ($item['contact_type'] ?? '');
        $typeCls = htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', $type) ?: 'link', ENT_QUOTES, 'UTF-8');
        $label   = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value   = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tip     = trim((string) ($item['tip'] ?? ''));
        $glyph   = $this->typeGlyph($type);
        $title   = htmlspecialchars($this->hoverCaption($item), ENT_QUOTES, 'UTF-8');

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');
            $qrTip = $tip !== '' ? htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') : '扫码添加好友';
            $pop = $qr !== ''
                ? '<span class="pv-fc-orb__pop pv-fc-orb__pop--qr"><img class="pv-fc-qr-img" src="' . $qr . '" alt="微信二维码" width="120" height="120" loading="lazy"><span class="pv-fc-qr-tip">' . $qrTip . '</span></span>'
                : '<span class="pv-fc-orb__pop">' . $title . '</span>';

            return '<button type="button" class="pv-fc-orb pv-fc-orb--' . $typeCls . ' pv-float-contact__item--wechat" data-action="wechat" data-qr="' . $qr . '" data-wechat-id="' . $value . '" title="' . $label . '">'
                . '<span class="pv-fc-orb__glyph" aria-hidden="true">' . $glyph . '</span>' . $pop . '</button>';
        }

        $action = $this->contact()->buildAction($item);
        $pop    = '<span class="pv-fc-orb__pop">' . $title . '</span>';
        $core   = '<span class="pv-fc-orb__glyph" aria-hidden="true">' . $glyph . '</span>' . $pop;
        $href   = (string) ($action['href'] ?? '');
        if ($href === '') {
            return '<span class="pv-fc-orb pv-fc-orb--' . $typeCls . ' is-disabled" title="' . $label . '">' . $core . '</span>';
        }
        $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $target  = !empty($action['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a class="pv-fc-orb pv-fc-orb--' . $typeCls . '" href="' . $hrefEsc . '"' . $target . ' title="' . $label . '">' . $core . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderRailNode(array $item): string
    {
        return $this->renderCompactNode(
            $item,
            'pv-fc-rail__item',
            'pv-fc-rail__icon',
            'pv-fc-rail__label',
            true,
            true,
            'pv-fc-rail__pop'
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderDockNode(array $item): string
    {
        return $this->renderCompactNode($item, 'pv-fc-dock__item', 'pv-fc-dock__icon', 'pv-fc-dock__text');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderStackNode(array $item): string
    {
        return $this->renderCompactNode(
            $item,
            'pv-fc-stack__item',
            'pv-fc-stack__icon',
            'pv-fc-stack__label',
            false,
            true,
            'pv-fc-stack__pop'
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderFabMenuNode(array $item): string
    {
        return $this->renderCompactNode(
            $item,
            'pv-fc-fab__item',
            'pv-fc-fab__icon',
            'pv-fc-fab__label',
            false,
            true,
            'pv-fc-fab__pop'
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderEdgeNode(array $item): string
    {
        $type    = (string) ($item['contact_type'] ?? '');
        $typeCls = htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', $type) ?: 'link', ENT_QUOTES, 'UTF-8');
        $label   = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value   = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tip     = trim((string) ($item['tip'] ?? ''));
        $glyph   = $this->typeGlyph($type);
        $action  = $this->contact()->buildAction($item);
        $href    = (string) ($action['href'] ?? '');
        $hint    = (string) ($action['hint'] ?? '');
        $lines   = array_values(array_filter([
            $value !== '' ? $value : null,
            $tip !== '' ? $tip : null,
            $hint !== '' ? $hint : null,
        ]));
        $bodyJson = htmlspecialchars(json_encode($lines, JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES, 'UTF-8');
        $hrefEsc  = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $ctaLabel = htmlspecialchars($hint !== '' ? $hint : '立即联系', ENT_QUOTES, 'UTF-8');
        $core     = '<span class="pv-fc-edge__glyph" aria-hidden="true">' . $glyph . '</span>';

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');
            $qrTip = $tip !== '' ? htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') : '扫码添加好友';

            return '<button type="button" class="pv-fc-edge__orb pv-fc-edge__orb--' . $typeCls . '" data-action="edge-panel"'
                . ' data-title="' . $label . '" data-body="' . $bodyJson . '" data-qr="' . $qr . '" data-qr-tip="' . $qrTip . '"'
                . ' data-wechat-id="' . $value . '" title="' . $label . '">' . $core . '</button>';
        }

        if ($href === '') {
            return '<span class="pv-fc-edge__orb pv-fc-edge__orb--' . $typeCls . ' is-disabled" title="' . $label . '">' . $core . '</span>';
        }

        return '<button type="button" class="pv-fc-edge__orb pv-fc-edge__orb--' . $typeCls . '" data-action="edge-panel"'
            . ' data-title="' . $label . '" data-body="' . $bodyJson . '" data-href="' . $hrefEsc . '" data-cta="' . $ctaLabel . '"'
            . ' title="' . $label . '">' . $core . '</button>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderCardNode(array $item): string
    {
        $type    = (string) ($item['contact_type'] ?? '');
        $typeCls = htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', $type) ?: 'link', ENT_QUOTES, 'UTF-8');
        $label   = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value   = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tip     = trim((string) ($item['tip'] ?? ''));
        $glyph   = $this->typeGlyph($type);
        $tipHtml = $tip !== '' ? '<span class="pv-fc-card__tip">' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '</span>' : '';

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');

            return '<button type="button" class="pv-fc-card__row pv-fc-card__row--' . $typeCls . ' pv-float-contact__item--wechat" data-action="wechat" data-qr="' . $qr . '" data-wechat-id="' . $value . '">'
                . '<span class="pv-fc-card__icon" aria-hidden="true">' . $glyph . '</span>'
                . '<span class="pv-fc-card__body"><strong>' . $label . '</strong><span>' . $value . '</span>' . $tipHtml . '</span>'
                . '<span class="pv-fc-card__go">扫码</span></button>';
        }

        $action = $this->contact()->buildAction($item);
        $hint   = htmlspecialchars((string) ($action['hint'] ?? ''), ENT_QUOTES, 'UTF-8');
        $body   = '<span class="pv-fc-card__icon" aria-hidden="true">' . $glyph . '</span>'
            . '<span class="pv-fc-card__body"><strong>' . $label . '</strong><span>' . $value . '</span>' . $tipHtml . '</span>'
            . '<span class="pv-fc-card__go">' . $hint . '</span>';
        $href   = (string) ($action['href'] ?? '');
        if ($href === '') {
            return '<div class="pv-fc-card__row pv-fc-card__row--' . $typeCls . '">' . $body . '</div>';
        }
        $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $target  = !empty($action['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a class="pv-fc-card__row pv-fc-card__row--' . $typeCls . '" href="' . $hrefEsc . '"' . $target . '>' . $body . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     */
    /**
     * @param array<string, mixed> $item
     */
    private function renderItemHoverPop(array $item, string $popClass): string
    {
        $type  = (string) ($item['contact_type'] ?? '');
        $tip   = trim((string) ($item['tip'] ?? ''));
        $title = htmlspecialchars($this->hoverCaption($item), ENT_QUOTES, 'UTF-8');

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');
            if ($qr === '') {
                return '<span class="' . $popClass . '">' . $title . '</span>';
            }
            $qrTip = $tip !== '' ? htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') : '扫码添加好友';

            return '<span class="' . $popClass . ' ' . $popClass . '--qr"><img class="pv-fc-qr-img" src="' . $qr . '" alt="微信二维码" width="120" height="120" loading="lazy"><span class="pv-fc-qr-tip">' . $qrTip . '</span></span>';
        }

        return '<span class="' . $popClass . '">' . $title . '</span>';
    }

    private function renderCompactNode(
        array $item,
        string $itemClass,
        string $iconClass,
        string $textClass,
        bool $truncateLabel = true,
        bool $withHoverPop = false,
        string $popClass = 'pv-fc-rail__pop'
    ): string {
        $type    = (string) ($item['contact_type'] ?? '');
        $typeCls = htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', $type) ?: 'link', ENT_QUOTES, 'UTF-8');
        $label   = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value   = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $glyph   = $this->typeGlyph($type);
        $rawLabel = (string) ($item['label'] ?? '');
        $short   = htmlspecialchars(
            $truncateLabel && mb_strlen($rawLabel) > 6 ? mb_substr($rawLabel, 0, 6) : $rawLabel,
            ENT_QUOTES,
            'UTF-8'
        );

        $popHtml = $withHoverPop ? $this->renderItemHoverPop($item, $popClass) : '';

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');

            return '<button type="button" class="' . $itemClass . ' ' . $itemClass . '--' . $typeCls . ' pv-float-contact__item--wechat" data-action="wechat" data-qr="' . $qr . '" data-wechat-id="' . $value . '">'
                . '<span class="' . $iconClass . '" aria-hidden="true">' . $glyph . '</span>'
                . '<span class="' . $textClass . '">' . $short . '</span>'
                . $popHtml . '</button>';
        }

        $action = $this->contact()->buildAction($item);
        $inner  = '<span class="' . $iconClass . '" aria-hidden="true">' . $glyph . '</span>'
            . '<span class="' . $textClass . '">' . $short . '</span>'
            . $popHtml;
        $href   = (string) ($action['href'] ?? '');
        if ($href === '') {
            return '<span class="' . $itemClass . ' ' . $itemClass . '--' . $typeCls . ' is-disabled">' . $inner . '</span>';
        }
        $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $target  = !empty($action['external']) ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a class="' . $itemClass . ' ' . $itemClass . '--' . $typeCls . '" href="' . $hrefEsc . '"' . $target . '>' . $inner . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hoverCaption(array $item): string
    {
        $type  = (string) ($item['contact_type'] ?? '');
        $label = trim((string) ($item['label'] ?? ''));
        $value = trim((string) ($item['value'] ?? ''));
        $tip   = trim((string) ($item['tip'] ?? ''));

        $prefix = match ($type) {
            FloatContactService::TYPE_QQ     => 'QQ',
            FloatContactService::TYPE_PHONE  => '电话',
            FloatContactService::TYPE_WECHAT => '微信',
            FloatContactService::TYPE_EMAIL  => '邮箱',
            FloatContactService::TYPE_VIP    => 'VIP',
            default           => $label !== '' ? $label : '联系',
        };

        if ($value === '') {
            return $tip !== '' ? $tip : $prefix;
        }

        $line = $prefix . '：' . $value;

        return $tip !== '' ? $line . ' · ' . $tip : $line;
    }

    private function typeGlyph(string $type): string
    {
        return $this->contact()->typeGlyphHtml($type);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderItemRow(array $item): string
    {
        $type  = (string) ($item['contact_type'] ?? '');
        $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tip   = htmlspecialchars((string) ($item['tip'] ?? ''), ENT_QUOTES, 'UTF-8');
        $icon  = $this->contact()->typeIcon($type);
        $action = $this->contact()->buildAction($item);
        $tipHtml = $tip !== '' ? '<span class="pv-float-contact__item-tip">' . $tip . '</span>' : '';

        if ($type === FloatContactService::TYPE_WECHAT) {
            $qr = htmlspecialchars($this->contact()->mediaUrl((string) ($item['qrcode'] ?? '')), ENT_QUOTES, 'UTF-8');

            return <<<HTML
<div class="pv-float-contact__item pv-float-contact__item--wechat" data-action="wechat" data-wechat-id="{$value}" data-qr="{$qr}">
    <span class="pv-float-contact__item-icon" aria-hidden="true">{$icon}</span>
    <span class="pv-float-contact__item-body">
      <span class="pv-float-contact__item-label">{$label}</span>
      <span class="pv-float-contact__item-value">{$value}</span>
      {$tipHtml}
    </span>
    <span class="pv-float-contact__item-go">扫码</span>
</div>
HTML;
        }

        $hint = htmlspecialchars((string) ($action['hint'] ?? ''), ENT_QUOTES, 'UTF-8');
        $inner = <<<HTML
    <span class="pv-float-contact__item-icon" aria-hidden="true">{$icon}</span>
    <span class="pv-float-contact__item-body">
      <span class="pv-float-contact__item-label">{$label}</span>
      <span class="pv-float-contact__item-value">{$value}</span>
      {$tipHtml}
    </span>
    <span class="pv-float-contact__item-go">{$hint}</span>
HTML;

        $href = (string) ($action['href'] ?? '');
        if ($href === '') {
            return '<div class="pv-float-contact__item">' . $inner . '</div>';
        }
        $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $target  = !empty($action['external']) ? ' target="_blank" rel="noopener"' : '';

        return '<a class="pv-float-contact__item" href="' . $hrefEsc . '"' . $target . '>' . $inner . '</a>';
    }

    /**
     * @param array<string, mixed> $item
     * @return array{href:string,external:bool,hint:string}
     */
}
