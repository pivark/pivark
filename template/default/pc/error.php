<!DOCTYPE html>
<html lang="zh-CN">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$seo_title}</title>
        <meta name="description" content="{$seo_description}">
        {pv:if name="seo_keywords"}
        <meta name="keywords" content="{$seo_keywords}">
        {/pv:if}
        {pv:seo /}
        <link rel="icon" href="{$theme_asset}/favicon.ico" sizes="any">
        <link href="/static/common/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="/static/common/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
        <link href="{$theme_asset}/css/fonts.css?v={$theme_asset_ver}" rel="stylesheet">
        <link href="{$theme_asset}/css/demo.css?v={$theme_asset_ver}" rel="stylesheet">
        <script type="application/json" id="pv-front-script-urls">{$front_script_urls_json|raw}</script>
        <script>
            window.PV = window.PV || {};
            (function () {
                var el = document.getElementById('pv-front-script-urls');
                try { PV.urls = JSON.parse((el && el.textContent) || '{}'); } catch (e) { PV.urls = {}; }
                window.PV_URLS = PV.urls;
            })();
        </script>
        <script src="{$pv_kernel_urls_js}"></script>
        <script src="{$theme_asset}/js/pivark-api-client.js?v={$theme_asset_ver}"></script>
    </head>
    <body class="pv-portal-site pv-error-site">
        {pv:include file="partials/header"}

        <!-- 区块：面包屑 -->
        {pv:include file="partials/breadcrumb"}

        <!-- 区块：错误提示（错误码 · 返回首页 / 上页）；flex 仅挂 main，勿挂 body（否则头/脚横排错位） -->
        <main aria-label="错误提示" class="pv-channel-main pv-error-page">
            <div class="container text-center">
                <div class="error-content">
                    <div class="error-icon">
                        <i class="bi bi-exclamation-circle"></i>
                    </div>
                    <p class="error-code" aria-hidden="true">{$error_code}</p>
                    <h1 class="error-title">{$error_msg}</h1>
                    {pv:if name="error_desc"}
                    <p class="error-desc">{$error_desc}</p>
                    {/pv:if}
                    <div class="error-actions">
                        <a href="{pv:if name="error_back"}{$error_back}{pv:else}{$home_url}{/pv:if}" class="btn btn-primary">
                            <i class="bi bi-house"></i>
                            返回首页
                        </a>
                        <a href="javascript:history.back()" class="btn btn-outline-dark">
                            <i class="bi bi-arrow-left"></i>
                            返回上页
                        </a>
                    </div>
                </div>
            </div>
        </main>

        {pv:include file="partials/footer"}
        </body>
    </html>

