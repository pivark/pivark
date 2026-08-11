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
    <body class="pv-portal-site pv-channel-aggregate">
        {pv:include file="partials/header"}

        <!-- 区块：频道 Hero / Banner（无头图 Hero · 有头图 Banner） -->
        {pv:if empty="channel_banner_image"}
            <section class="pv-channel-hero pv-portal-page-hero pv-portal-hero--center" aria-label="频道标题区">
                <div class="container">
                    <h1 class="pv-channel-hero-title">{$page_title}</h1>
                    {pv:if name="tag_description"}
                        <p class="pv-channel-hero-desc">{$tag_description}</p>
                    {pv:else}
                        {pv:if name="seo_description"}
                            <p class="pv-channel-hero-desc">{$seo_description}</p>
                        {/pv:if}
                    {/pv:if}
                </div>
            </section>
        {pv:else}
            <section class="pv-channel-banner pv-portal-page-banner" aria-label="频道头图">
                <div class="pv-channel-banner-media" style="background-image:url('{$channel_banner_image}')"></div>
                <div class="pv-channel-banner-overlay"></div>
                <div class="container pv-channel-banner-caption pv-portal-hero--center">
                    <h1 class="pv-channel-banner-title">{$page_title}</h1>
                    <span class="pv-channel-banner-accent" aria-hidden="true"></span>
                    {pv:if name="tag_description"}
                        <p class="pv-channel-banner-desc">{$tag_description}</p>
                    {pv:else}
                        {pv:if name="seo_description"}
                            <p class="pv-channel-banner-desc">{$seo_description}</p>
                        {/pv:if}
                    {/pv:if}
                </div>
            </section>
        {/pv:if}

        <!-- 区块：相关资料列表（documentlist · pagelist） -->
        <main aria-label="聚合专题正文" class="pv-channel-main">
            <div class="container">
                <div class="section-header mb-4">
                    <h2 class="h4 mb-0">相关资料</h2>
                </div>
                {pv:list row="12" item="field" wrap="articles-grid"}
                <article class="card shadow-sm article-card overflow-hidden">
                    <div class="card-body">
                        <span class="article-meta">{$field.create_date}</span>
                        <h3 class="article-title"><a href="{$field.url}">{$field.title}</a></h3>
                        <p class="article-excerpt">{$field.excerpt_short}</p>
                    </div>
                </article>
                {pv:empty}
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox display-4 d-block mb-3 opacity-50"></i>
                    <p class="mb-0 fs-5">暂无内容</p>
                </div>
                {/pv:empty}
                {/pv:list}
                {pv:include file="partials/pagination"}
            </div>
        </main>

        <!-- 区块：页底 CTA（联系工程师） -->
        <section class="pv-portal-cta section">
            <div class="container text-center">
                <h2 class="h4 mb-3">需要方案对接？</h2>
                <p class="text-muted mb-4">提交需求，我们的工程师将尽快与您联系。</p>
                <a href="{$url_contact}" class="btn btn-primary">联系我们</a>
            </div>
        </section>

        {pv:include file="partials/footer"}
        </body>
    </html>


