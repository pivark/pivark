<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);

namespace app\common\service\front;

use app\common\support\PvPublicAsset;

use app\common\support\WeappPublicAsset;

/** 前台按需脚本注册表（标签/插件激活时登记，footer {pv:frontassets} 输出） */

final class FrontAssetRegistry

{

    /** @var array<string, array{url:string,defer:bool}> */

    private array $scripts = [];

    /** @var array<string, array{url:string}> */

    private array $stylesheets = [];

    /** @var array<string, true> */

    private array $urlModules = [];

    public function resetRequest(): void

    {

        $this->scripts     = [];

        $this->stylesheets = [];

        $this->urlModules  = [];

    }

    public function registerUrlModule(string $module): void

    {

        $module = trim($module);

        if ($module !== '') {

            $this->urlModules[$module] = true;

        }

    }

    /**

     * @return list<string>

     */

    public function activeUrlModules(): array

    {

        return array_keys($this->urlModules);

    }

    public function registerScript(string $id, string $url, bool $defer = true): void

    {

        $id = trim($id);

        if ($id === '' || $url === '') {

            return;

        }

        $this->scripts[$id] = ['url' => $url, 'defer' => $defer];

    }

    public function registerStylesheet(string $id, string $url): void

    {

        $id = trim($id);

        if ($id === '' || $url === '') {

            return;

        }

        $this->stylesheets[$id] = ['url' => $url];

    }

    public function registerKernelScript(string $id, string $relativePath, bool $defer = true): void

    {

        $this->registerScript($id, PvPublicAsset::js($relativePath), $defer);

    }

    public function registerKernelStylesheet(string $id, string $relativePath): void

    {

        $this->registerStylesheet($id, PvPublicAsset::css($relativePath));

    }

    public function registerWeappScript(string $pluginId, string $id, string $relativePath, bool $defer = true): void

    {

        $this->registerScript($id, WeappPublicAsset::url($pluginId, $relativePath), $defer);

    }

    public function registerWeappStylesheet(string $pluginId, string $id, string $relativePath): void

    {

        $this->registerStylesheet($id, WeappPublicAsset::url($pluginId, $relativePath));

    }

    public function registerFavoriteBar(): void

    {

        $this->registerUrlModule('favorite');

        $this->registerKernelScript('pv-favorite-bar', 'pv-favorite-bar.js');

    }

    public function registerWeappCommerceModule(string $pluginId): void
    {
        unset($pluginId);
        $this->registerUrlModule('commerce');
    }

    public function registerWeappCommerceScript(string $pluginId, string $handle, string $relativePath): void
    {
        $this->registerUrlModule('commerce');
        $this->registerWeappScript($pluginId, $handle, $relativePath);
    }

    public function renderHtml(): string

    {

        if ($this->scripts === [] && $this->stylesheets === [] && $this->urlModules === []) {

            return '';

        }

        $html  = '';

        $extra = app(FrontScriptUrlMap::class)->mapForModules($this->activeUrlModules());

        if ($extra !== []) {

            $json = json_encode(

                $extra,

                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT

            );

            if (is_string($json) && $json !== '{}') {

                $html .= '<script>window.PV=window.PV||{};'

                    . 'if(typeof PV.mergeUrls==="function"){PV.mergeUrls(' . $json . ');}'

                    . 'else{PV.urls=Object.assign(PV.urls||window.PV_URLS||{},' . $json . ');window.PV_URLS=PV.urls;}'

                    . '</script>';

            }

        }

        foreach ($this->stylesheets as $sheet) {

            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($sheet['url'], ENT_QUOTES, 'UTF-8') . '">';

        }

        foreach ($this->scripts as $script) {

            $defer = !empty($script['defer']) ? ' defer' : '';

            $html .= '<script src="' . htmlspecialchars($script['url'], ENT_QUOTES, 'UTF-8') . '"' . $defer . '></script>';

        }

        return $html;

    }

}

