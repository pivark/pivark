<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\support;



use app\common\service\config\ConfigService;



/**

 * 内核前台静态资源（只读）

 *

 * SSOT 目录：public/static/common/js|css/

 * 用户可改：public/static/theme/{主题}/

 */

final class PvPublicAsset

{

    private const JS_BASE  = '/static/common/js/';

    private const CSS_BASE = '/static/common/css/';



    public static function js(string $relativePath): string

    {

        return self::build(self::JS_BASE, $relativePath);

    }



    public static function css(string $relativePath): string

    {

        return self::build(self::CSS_BASE, $relativePath);

    }



    /** @deprecated 使用 js()；保留兼容旧调用 */

    public static function url(string $relativePath): string

    {

        return self::js($relativePath);

    }



    /** @return array<string, string> */

    public static function templateVars(): array

    {

        return [

            'pv_kernel_urls_js'  => self::js('pv-urls.js'),

            'pv_kernel_stats_js' => self::js('pv-stats.js'),

            'pv_kernel_form_js'  => self::js('pv-form.js'),

        ];

    }



    private static function build(string $base, string $relativePath): string

    {

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        $ver          = rawurlencode((string) app(ConfigService::class)->get('site_version', '1'));



        return $base . $relativePath . '?v=' . $ver;

    }

}

