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
    <body class="pv-portal-site pv-video-channel-page">
        {pv:include file="partials/header"}

        <!-- 区块：面包屑 -->
        {pv:include file="partials/breadcrumb"}

        <!-- 区块：视频频道详情（系列信息 · 选集播放） -->
        <main aria-label="视频频道详情" class="pv-channel-main pv-portal-main pv-video-channel">
            <div class="container">
                <header class="pv-video-channel__hero mb-4">
                    <div class="row g-4 align-items-center">
                        {pv:if empty="series_cover_url"}
                        {pv:else}
                            <div class="col-auto">
                                <img src="{$series_cover_url}" alt="{$series_title}" class="pv-video-channel__cover rounded shadow-sm" loading="lazy" width="160" height="90">
                            </div>
                        {/pv:if}
                        <div class="col">
                            <h1 class="pv-page-title h3 mb-2">{$series_title}</h1>
                            {pv:if empty="series_summary"}
                            {pv:else}
                                <p class="text-muted mb-2">{$series_summary}</p>
                            {/pv:if}
                            <p class="text-muted small mb-0">共 {$episode_count} 集 · 点击下方选集播放</p>
                        </div>
                    </div>
                </header>
                {$video_channel_html|raw}
            </div>
        </main>

        {pv:include file="partials/footer"}
        </body>
    </html>

