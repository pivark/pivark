<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split — 模板标签渲染
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\service\template\TemplateEngine;

class SiteFormRenderService
{

    public function __construct(
        private readonly SiteFormCrudService $forms,
    ) {
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function renderTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $slug = trim((string) ($attrs['slug'] ?? $attrs['id'] ?? ''));
        if ($slug === '') {
            return '';
        }
        $form = $this->forms->findBySlug($slug);
        if ($form === null) {
            return '<!-- form not found -->';
        }

        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $mode     = strtolower(trim((string) ($settings['invoke_mode'] ?? 'tag')));
        if (isset($attrs['mode']) && trim((string) $attrs['mode']) !== '') {
            $mode = strtolower(trim((string) $attrs['mode']));
        }

        return match ($mode) {
            'ajax', 'js'     => $this->renderAjaxMount($form, $attrs),
            'container', 'mount' => $this->renderContainerMount($form, $attrs),
            default          => $this->renderInlineForm($form, $attrs),
        };
    }

    /**
     * 组合版：表单开头（与 form_field / form_close 搭配，便于自定义布局与样式）
     *
     * @param array<string, mixed> $attrs
     */
    public function renderFormOpenTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $slug = trim((string) ($attrs['slug'] ?? $attrs['id'] ?? ''));
        if ($slug === '') {
            return '';
        }
        $form = $this->forms->findBySlug($slug);
        if ($form === null) {
            return '<!-- form not found -->';
        }

        return $this->renderFormOpenHtml($form, $attrs);
    }

    /**
     * 组合版：单个字段（可插入任意布局容器内）
     *
     * @param array<string, mixed> $attrs
     */
    public function renderFormFieldTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $slug = trim((string) ($attrs['slug'] ?? $attrs['id'] ?? ''));
        $key  = trim((string) ($attrs['key'] ?? ''));
        if ($slug === '' || $key === '') {
            return '';
        }
        $form = $this->forms->findBySlug($slug);
        if ($form === null || !is_array($form['fields'] ?? null)) {
            return '<!-- form field not found -->';
        }
        foreach ($form['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            if ((string) ($field['key'] ?? '') === $key) {
                return $this->renderSingleFieldHtml($field);
            }
        }

        return '<!-- form field not found -->';
    }

    /**
     * 组合版：提交按钮与表单结尾
     *
     * @param array<string, mixed> $attrs
     */
    public function renderFormCloseTag(array $attrs, array $pageVars, string $tpl = ''): string
    {
        $label = trim((string) ($attrs['label'] ?? $attrs['submit'] ?? '提交'));

        return '<div class="pv-form-actions">'
            . '<button type="submit" class="btn btn-primary">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
            . '</div></form></div>';
    }

    /**
     * 后台「调用说明」片段
     *
     * @return array{tag:string,ajax_html:string,api_schema:string,api_submit:string,fetch_example:string}
     */
    public function invokeSnippets(array $form): array
    {
        $slug = (string) ($form['slug'] ?? 'contact');
        $fid  = (int) ($form['id'] ?? 0);
        $mountId = 'pv-form-' . preg_replace('/[^a-z0-9_-]/', '-', $slug);

        $fieldKeys = [];
        foreach ($form['fields'] as $field) {
            if (is_array($field) && !empty($field['key'])) {
                $fieldKeys[] = (string) $field['key'];
            }
        }
        $composedLines = ['{pv:form_open slug="' . $slug . '" class="my-form-layout"}'];
        foreach ($fieldKeys as $fkey) {
            $composedLines[] = '  {pv:form_field slug="' . $slug . '" key="' . $fkey . '"}';
        }
        if ($fieldKeys === []) {
            $composedLines[] = '  {pv:form_field slug="' . $slug . '" key="字段key"}';
            $composedLines[] = '  <!-- 按后台字段 key 逐行添加 -->';
        }
        $composedLines[] = '{pv:form_close}';

        return [
            'tag'           => '{pv:form slug="' . $slug . '"}',
            'tag_composed'  => implode("\n", $composedLines),
            'style_example' => '.pv-form[data-form-slug="' . $slug . '"] [data-field-key="'
                . ($fieldKeys[0] ?? 'name') . '"] input { /* 单字段样式 */ }',
            'ajax_html'     => '<div id="' . $mountId . '" data-pv-form-slug="' . $slug . '"></div>'
                . "\n" . '<script src="' . \app\common\support\PvPublicAsset::js('pv-form.js') . '" defer></script>',
            'api_schema'    => '/api/v1/forms/' . $slug,
            'api_submit'    => '/api/v1/forms/submit',
            'fetch_example' => "fetch('/api/v1/forms/" . $slug . "').then(r=>r.json()).then(schema=>{\n"
                . "  /* 按 schema.fields 渲染 UI */\n"
                . "  fetch('/api/v1/forms/submit',{method:'POST',headers:{'Content-Type':'application/json'},"
                . "body:JSON.stringify({form_id:" . $fid . ",name:'张三'})}).then(r=>r.json());\n});",
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $attrs
     */
    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $attrs
     */
    private function renderInlineForm(array $form, array $attrs = []): string
    {
        $slug  = (string) ($form['slug'] ?? '');
        $fid   = (int) ($form['id'] ?? 0);
        $extra = trim((string) ($attrs['class'] ?? ''));
        $wrapClass = 'pv-form' . ($extra !== '' ? ' ' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') : '');
        $showTitle = !isset($attrs['title']) || (string) $attrs['title'] !== '0';
        $titleBlock = '';
        if ($showTitle) {
            $rawTitle = (string) ($form['title'] ?? '');
            if ($rawTitle !== '') {
                $titleBlock = '<h3 class="pv-form-title">' . htmlspecialchars($rawTitle, ENT_QUOTES, 'UTF-8') . '</h3>';
            }
        }

        return '<div class="' . $wrapClass . '" data-form-slug="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" data-form-id="' . $fid . '">'
            . $this->renderFormOpenInner($form, $titleBlock)
            . $this->renderFieldsHtml($form)
            . '<div class="pv-form-actions"><button type="submit" class="btn btn-primary">提交</button></div>'
            . '</form></div>';
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $attrs
     */
    private function renderFormOpenHtml(array $form, array $attrs = []): string
    {
        $slug  = (string) ($form['slug'] ?? '');
        $fid   = (int) ($form['id'] ?? 0);
        $extra = trim((string) ($attrs['class'] ?? ''));
        $wrapClass = 'pv-form pv-form--composed' . ($extra !== '' ? ' ' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') : '');
        $showTitle = !isset($attrs['title']) || (string) $attrs['title'] !== '0';
        $titleBlock = '';
        if ($showTitle) {
            $rawTitle = (string) ($form['title'] ?? '');
            if ($rawTitle !== '') {
                $titleBlock = '<h3 class="pv-form-title">' . htmlspecialchars($rawTitle, ENT_QUOTES, 'UTF-8') . '</h3>';
            }
        }

        return '<div class="' . $wrapClass . '" data-form-slug="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" data-form-id="' . $fid . '">'
            . $this->renderFormOpenInner($form, $titleBlock);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderFormOpenInner(array $form, string $titleBlock = ''): string
    {
        $fid = (int) ($form['id'] ?? 0);

        return '<form class="pv-form-inner" method="post" action="/api/v1/forms/submit" data-pv-form="' . $fid . '">'
            . '<input type="hidden" name="form_id" value="' . $fid . '">'
            . $titleBlock;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $attrs
     */
    private function renderAjaxMount(array $form, array $attrs): string
    {
        $slug    = (string) ($form['slug'] ?? '');
        $mountId = trim((string) ($attrs['mount_id'] ?? 'pv-form-' . preg_replace('/[^a-z0-9_-]/', '-', $slug)));

        return '<div id="' . htmlspecialchars($mountId, ENT_QUOTES, 'UTF-8') . '" class="pv-form-mount" data-pv-form-slug="'
            . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" data-pv-form-ajax="1"></div>'
            . '<script src="' . \app\common\support\PvPublicAsset::js('pv-form.js') . '" defer></script>';
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $attrs
     */
    private function renderContainerMount(array $form, array $attrs): string
    {
        $slug = (string) ($form['slug'] ?? '');
        $sel  = trim((string) ($attrs['selector'] ?? $attrs['container'] ?? ''));

        return '<div class="pv-form-slot" data-pv-form-slug="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"'
            . ($sel !== '' ? ' data-pv-form-selector="' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') . '"' : '')
            . '></div><script src="' . \app\common\support\PvPublicAsset::js('pv-form.js') . '" defer></script>';
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderFieldsHtml(array $form): string
    {
        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $html   = '';
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $html .= $this->renderSingleFieldHtml($field);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderSingleFieldHtml(array $field): string
    {
        $key      = (string) ($field['key'] ?? '');
        if ($key === '') {
            return '';
        }
        $keyEsc   = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $label    = htmlspecialchars((string) ($field['label'] ?? $key), ENT_QUOTES, 'UTF-8');
        $type     = (string) ($field['type'] ?? 'text');
        $required = !empty($field['required']) ? ' required' : '';
        $typeEsc  = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

        $html = '<div class="pv-form-field mb-3" data-field-key="' . $keyEsc . '" data-field-type="' . $typeEsc . '">'
            . '<label class="form-label">' . $label . '</label>';
        if ($type === 'textarea') {
            $html .= '<textarea class="form-control" name="' . $keyEsc . '"' . $required . ' rows="4"></textarea>';
        } elseif ($type === 'select' && is_array($field['options'] ?? null)) {
            $html .= '<select class="form-select" name="' . $keyEsc . '"' . $required . '>';
            foreach ($field['options'] as $opt) {
                $o = htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8');
                $html .= '<option value="' . $o . '">' . $o . '</option>';
            }
            $html .= '</select>';
        } elseif (in_array($type, ['radio', 'checkbox'], true) && is_array($field['options'] ?? null)) {
            $html .= '<div class="pv-form-options">';
            $nameAttr = $type === 'checkbox' ? $keyEsc . '[]' : $keyEsc;
            $reqAttr  = $type === 'radio' ? $required : '';
            foreach ($field['options'] as $i => $opt) {
                $o = htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8');
                $inputType = $type === 'checkbox' ? 'checkbox' : 'radio';
                $oneReq    = ($type === 'checkbox' && $i === 0) ? $required : $reqAttr;
                $html .= '<div class="form-check"><input class="form-check-input" type="' . $inputType
                    . '" name="' . $nameAttr . '" id="' . $keyEsc . '-' . $i . '" value="' . $o . '"' . $oneReq
                    . '><label class="form-check-label" for="' . $keyEsc . '-' . $i . '">' . $o . '</label></div>';
            }
            $html .= '</div>';
        } else {
            $inputType = in_array($type, ['email', 'tel', 'number'], true) ? $type : 'text';
            $html .= '<input class="form-control" type="' . $inputType . '" name="' . $keyEsc . '"' . $required . '>';
        }
        $html .= '</div>';

        return $html;
    }

}
