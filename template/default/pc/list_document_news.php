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
    <body class="pv-portal-site pv-channel-news">
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

        <!-- 区块：面包屑 -->
        {pv:include file="partials/breadcrumb"}

        <!-- 区块：新闻主栏 + 右侧栏（本页示范侧栏形态：--nav · --thumb） -->
        <main aria-label="新闻列表" class="pv-channel-main pv-portal-main">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <!-- 主栏：资讯条（左图右文） -->
                    <div class="col-lg-9">
                <div class="pv-list-stack">
                {pv:list row="10"}
                <article class="card shadow-sm pv-list-news-item overflow-hidden">
                    <div class="row g-0 align-items-stretch">
                        <div class="col-md-4">
                    <a class="d-block h-100 ratio ratio-16x10 bg-light pv-list-news-thumb" href="{$field.url}">
                        {pv:if empty="field.litpic"}
                            <span class="pv-list-news-ph" aria-hidden="true"><i class="bi bi-newspaper" aria-hidden="true"></i></span>
                        {pv:else}
                            <img src="{$field.litpic}" alt="{$field.title}" loading="lazy" decoding="async">
                        {/pv:if}
                    </a>
                        </div>
                        <div class="col-md-8">
                    <div class="card-body pv-list-news-body">
                        <time datetime="{$field.create_date}">{$field.create_date}</time>
                        {pv:if empty="field.attr_label_text"}{pv:else} <span class="badge bg-light text-dark border">{$field.attr_label_text}</span>{/pv:if}
                        <h2 class="h5 card-title"><a href="{$field.url}" class="{$field.title_class}">{$field.title}</a></h2>
                        <p class="card-text text-muted mb-0">{$field.excerpt_short}</p>
                    </div>
                        </div>
                    </div>
                </article>
                {pv:empty}
                <div class="text-center py-5 text-muted" role="status">
                    <i class="bi bi-inbox display-4 d-block mb-3 opacity-50" aria-hidden="true"></i>
                    <p class="mb-0 fs-5">暂无内容</p>
                </div>
                {/pv:empty}
                {/pv:list}
                </div>
                <!-- 区块：分页 -->
                {pv:include file="partials/pagination"}
                    </div>
                    <!-- 侧栏：导航 + 左图右文推荐（勿与下载页排行/案例页封面混用同一套） -->
                    <aside class="col-lg-3" aria-label="新闻侧栏">
                        <div class="sidebar">
                            <!-- 侧栏块：栏目导航 · 多级可折叠 · partials/channel_nav -->
                            {pv:include file="partials/channel_nav"}
                            <!-- 侧栏块：相关推荐 · 形态 pv-side-list--thumb（左图右文） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-link"></i> 相关推荐</h3>
                                    <ul class="pv-side-list pv-side-list--thumb">
                                        {pv:arclist nav="page" row="6" orderby="hot"}
                                        <li>
                                            <a href="{$field.url}">
                                                {pv:if empty="field.litpic"}
                                                    <i class="bi bi-file-text pv-side-list__media" aria-hidden="true"></i>
                                                {pv:else}
                                                    <img class="pv-side-list__media" src="{$field.litpic}" alt="" loading="lazy">
                                                {/pv:if}
                                                <span class="pv-side-list__body">
                                                    <strong>{$field.title}</strong>
                                                </span>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </main>

        {pv:include file="partials/footer"}
        </body>
    </html>


