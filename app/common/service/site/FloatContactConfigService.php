<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\ServiceResult;
use app\common\service\site\FloatContactStyleService;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;
use app\common\service\plugin\weapp\WeappPluginSaveSupport;
use app\common\service\static\StaticHtmlDispatch;

/** 悬浮联系全局配置 */
class FloatContactConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly FloatContactStyleService $floatContactStyleService,
        private readonly WeappPluginSaveSupport $weappPluginSaveSupport,
    ) {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'float_contact_enabled',
            'float_contact_style',
            'float_contact_panel_title',
            'float_contact_theme_color',
            'float_contact_offset_bottom',
            'float_contact_collapsed_label',
            'float_contact_show_mobile',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'float_contact_enabled'         => '1',
            'float_contact_style'           => FloatContactStyleService::STYLE_SIDEBAR,
            'float_contact_panel_title'     => '联系我们',
            'float_contact_theme_color'     => '#1e9fff',
            'float_contact_offset_bottom'   => '120',
            'float_contact_collapsed_label' => '联系',
            'float_contact_show_mobile'     => '1',
            default                         => '',
        };
    }

    public function normalizedStyle(): string
    {
        return $this->floatContactStyleService->normalize(
            (string) $this->configService->get('float_contact_style', $this->defaultFor('float_contact_style'))
        );
    }

    /** @return array<string, array{id:string,title:string,desc:string,hint:string}> */
    public function styleOptions(): array
    {
        return $this->floatContactStyleService->options();
    }

    public function isEnabled(): bool
    {
        return (string) $this->configService->get('float_contact_enabled', '1') === '1';
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $enabled = (int) ($data['float_contact_enabled'] ?? 1) === 1 ? '1' : '0';
        $title   = mb_substr(trim((string) ($data['float_contact_panel_title'] ?? '')), 0, 40);
        if ($title === '') {
            $title = '联系我们';
        }
        $color = trim((string) ($data['float_contact_theme_color'] ?? ''));
        if ($color === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            $color = '#1e9fff';
        }
        $bottom = max(40, min(400, (int) ($data['float_contact_offset_bottom'] ?? 120)));
        $label  = mb_substr(trim((string) ($data['float_contact_collapsed_label'] ?? '')), 0, 12);
        if ($label === '') {
            $label = '联系';
        }
        $mobile = (int) ($data['float_contact_show_mobile'] ?? 1) === 1 ? '1' : '0';
        $style  = $this->floatContactStyleService->normalize((string) ($data['float_contact_style'] ?? ''));

        $this->configService->set('float_contact_enabled', $enabled);
        $this->configService->set('float_contact_style', $style);
        $this->configService->set('float_contact_panel_title', $title);
        $this->configService->set('float_contact_theme_color', $color);
        $this->configService->set('float_contact_offset_bottom', (string) $bottom);
        $this->configService->set('float_contact_collapsed_label', $label);
        $this->configService->set('float_contact_show_mobile', $mobile);

        $this->weappPluginSaveSupport->afterConfigSaved(
            \app\common\service\plugin\weapp\WeappPluginSaveSupport::SCOPE_META
        );
        app(StaticHtmlDispatch::class)->afterGlobalEmbedChange();

        return ServiceResult::ok(null, '配置已保存');
    }
}
