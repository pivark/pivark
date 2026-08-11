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

use app\common\service\template\TemplateEngine;
use app\common\service\template\TemplateTagParser;
use app\common\service\site\FloatContactConfigService;

/**
 * 悬浮联系条目 · 内核模板标签（L1）
 * {pv:contact} · {pv:contacts} · {pv:floatcontact}
 */
final class FloatContactTemplateTagService
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly TemplateTagParser $templateTagParser,
        private readonly FloatContactService $floatContact,
        private readonly FloatContactConfigService $floatContactConfig,
    ) {
    }

    /** @var list<array<string, mixed>>|null */
    private static ?array $itemsCache = null;

    public function boot(): void
    {
        $this->templateEngine->registerKernelTag('contact', fn (array $attrs, array $pageVars, string $tpl = '') => $this->renderContactTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('contacts', fn (array $attrs, array $pageVars, string $tpl = '') => $this->renderContactsTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('floatcontact', fn (array $attrs, array $pageVars, string $tpl = '') => $this->renderFloatcontactTag($attrs, $pageVars, $tpl));
    }

    /** @return list<array<string, mixed>> */
    public function listItemsForTemplate(): array
    {
        if (self::$itemsCache !== null) {
            return self::$itemsCache;
        }
        if (!$this->serviceReady()) {
            return self::$itemsCache = [];
        }

        return self::$itemsCache = $this->floatContact->listPublicForTemplate();
    }

    public function forgetRequestCache(): void
    {
        self::$itemsCache = null;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderContactTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $item = $this->resolveItem($attrs, $pageVars);
        if ($item === null) {
            return '';
        }
        if (trim($tpl) !== '') {
            return $this->templateTagParser->renderItemLoop($tpl, [$item], $pageVars, 'contact');
        }

        return $this->renderContactField($item, $attrs);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderContactsTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $items = $this->filterItems($attrs, $pageVars);
        if ($items === []) {
            return '';
        }
        if (trim($tpl) === '') {
            return $this->renderDefaultList($items);
        }

        return $this->templateTagParser->renderItemLoop($tpl, $items, $pageVars, 'contact');
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderFloatcontactTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        unset($attrs, $pageVars, $tpl);
        if (!$this->serviceReady()) {
            return '';
        }
        if (!$this->floatContactConfig->isEnabled()) {
            return '';
        }

        return $this->floatContact->renderWidget();
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @return array<string, mixed>|null
     */
    private function resolveItem(array $attrs, array $pageVars): ?array
    {
        if (!$this->serviceReady()) {
            return null;
        }

        $criteria = [
            'id'    => (int) ($attrs['id'] ?? 0),
            'type'  => (string) ($attrs['type'] ?? ''),
            'index' => (int) ($attrs['index'] ?? 0),
            'label' => (string) ($attrs['label'] ?? ''),
        ];
        $fromVars = $this->itemsFromPageVars($pageVars);
        if ($fromVars !== []) {
            $hit = $this->floatContact->resolveFromList($fromVars, $criteria);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->floatContact->resolvePublicItem($criteria);
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $pageVars
     * @return list<array<string, mixed>>
     */
    private function filterItems(array $attrs, array $pageVars): array
    {
        $items = $this->itemsFromPageVars($pageVars);
        if ($items === []) {
            $items = $this->listItemsForTemplate();
        }
        $type = strtolower(trim((string) ($attrs['type'] ?? '')));
        if ($type === '') {
            return $items;
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['contact_type'] ?? '') === $type) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $pageVars
     * @return list<array<string, mixed>>
     */
    private function itemsFromPageVars(array $pageVars): array
    {
        $raw = $pageVars['float_contact_items'] ?? null;

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $attrs
     */
    private function renderContactField(array $item, array $attrs): string
    {
        $field = strtolower(trim((string) ($attrs['field'] ?? 'value')));
        $wrap  = strtolower(trim((string) ($attrs['wrap'] ?? '')));
        $allowed = ['value', 'label', 'tip', 'qrcode_url', 'href', 'type_text', 'contact_type', 'id', 'action_hint'];
        if (!in_array($field, $allowed, true)) {
            $field = 'value';
        }

        if ($wrap === 'link') {
            $href = trim((string) ($item['href'] ?? ''));
            if ($href === '' && $field === 'value') {
                $href = $this->fallbackHref($item);
            }
            if ($href !== '') {
                $text = (string) ($attrs['text'] ?? '');
                if ($text === '') {
                    $text = $field === 'label'
                        ? (string) ($item['label'] ?? '')
                        : (string) ($item['value'] ?? '');
                }

                return $this->linkHtml($href, $text, !empty($item['href_external']));
            }
        }

        if ($wrap === 'img' || ($wrap === 'qrcode' && $field === 'qrcode_url')) {
            $src = trim((string) ($item['qrcode_url'] ?? ''));
            if ($src === '') {
                return '';
            }
            $alt = htmlspecialchars((string) ($item['label'] ?? '微信二维码'), ENT_QUOTES, 'UTF-8');

            return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy" class="pv-contact-qrcode">';
        }

        $raw = (string) ($item[$field] ?? '');
        if ($raw === '' && $field === 'value') {
            $raw = (string) ($item['label'] ?? '');
        }

        return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function fallbackHref(array $item): string
    {
        $type = (string) ($item['contact_type'] ?? '');
        $value = trim((string) ($item['value'] ?? ''));

        return match ($type) {
            'phone' => $value !== '' ? 'tel:' . preg_replace('/\s+/', '', $value) : '',
            'email' => $value !== '' ? 'mailto:' . $value : '',
            default => trim((string) ($item['href'] ?? '')),
        };
    }

    private function linkHtml(string $href, string $text, bool $external): string
    {
        $target = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="pv-contact-link"' . $target . '>'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderDefaultList(array $items): string
    {
        $html = '<ul class="pv-contact-list">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $href  = trim((string) ($item['href'] ?? ''));
            if ($href === '') {
                $href = $this->fallbackHref($item);
            }
            $inner = $label !== '' ? $label . '：' . $value : $value;
            if ($href !== '') {
                $html .= '<li>' . $this->linkHtml($href, $inner, !empty($item['href_external'])) . '</li>';
            } else {
                $html .= '<li><span class="pv-contact-text">' . $inner . '</span></li>';
            }
        }
        $html .= '</ul>';

        return $html;
    }

    private function serviceReady(): bool
    {
        return class_exists(FloatContactService::class);
    }
}
