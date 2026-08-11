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
    <body class="pv-portal-site pv-channel-gallery">
        {pv:include file="partials/header"}

        <!-- 区块：频道 Hero / Banner（无头图用 Hero · 有头图用 Banner） -->
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

        <!-- 区块：案例主栏网格 + 右侧栏（本页示范侧栏形态：--nav · --cover · --tags） -->
        <main aria-label="应用案例列表" class="pv-channel-main pv-portal-main">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <!-- 主栏：案例卡片网格 -->
                    <div class="col-lg-9">
                        {pv:list row="12" item="field" wrap="row g-4 pv-media-grid"}
                        <div class="col-md-4 col-sm-6">
                            <article class="card h-100 shadow-sm pv-media-card">
                                <a class="d-flex flex-column h-100 text-decoration-none text-body" href="{$field.url}">
                                <div class="ratio ratio-4x3 bg-light border-bottom pv-media-card-thumb">
                                    {pv:if empty="field.litpic"}
                                        <span class="pv-list-news-ph" aria-hidden="true"><i class="bi bi-image" aria-hidden="true"></i></span>
                                    {pv:else}
                                        <img src="{$field.litpic}" alt="{$field.title}" loading="lazy" decoding="async">
                                    {/pv:if}
                                </div>
                                <div class="card-body">
                                    <h2 class="h6 card-title">{$field.title}</h2>
                                    <p class="card-text small text-muted mb-0">{$field.excerpt_short}</p>
                                </div>
                                </a>
                            </article>
                        </div>
                        {pv:empty}
                        <div class="text-center py-5 text-muted" role="status">
                            <i class="bi bi-inbox display-4 d-block mb-3 opacity-50" aria-hidden="true"></i>
                            <p class="mb-0 fs-5">暂无内容</p>
                        </div>
                        {/pv:empty}
                        {/pv:list}
                        {pv:include file="partials/pagination"}
                    </div>

                    <!-- 侧栏：形态演示（上图下文 + Tag chip，与新闻/下载侧栏组合不同） -->
                    <aside class="col-lg-3" aria-label="案例侧栏">
                        <div class="sidebar">
                            <!-- 侧栏块：栏目导航 · 多级可折叠 · partials/channel_nav -->
                            {pv:include file="partials/channel_nav"}

                            <!-- 侧栏块：热门案例 · 形态 pv-side-list--cover（上图下文） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-images"></i> 热门案例</h3>
                                    <ul class="pv-side-list pv-side-list--cover">
                                        {pv:arclist nav="page" row="3" orderby="hot"}
                                        <li>
                                            <a href="{$field.url}">
                                                {pv:if empty="field.litpic"}
                                                    <span class="pv-side-list__media" aria-hidden="true"></span>
                                                {pv:else}
                                                    <img class="pv-side-list__media" src="{$field.litpic}" alt="" loading="lazy">
                                                {/pv:if}
                                                <strong>{$field.title}</strong>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ul>
                                </div>
                            </div>

                            <!-- 侧栏块：相关标签 · 形态 pv-side-list--tags（chip 流） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-tags"></i> 相关标签</h3>
                                    <ul class="pv-side-list pv-side-list--tags">
                                        {pv:tagcloud row="12"}
                                        <li><a class="pv-side-list__chip" href="{$field.url}">{$field.name}</a></li>
                                        {/pv:tagcloud}
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
