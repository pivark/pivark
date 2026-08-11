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
    <body class="pv-portal-site pv-channel-product pv-product-hub-page">
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

        <!-- 区块：产品列表（左：检索筛选网格 · 右：分类/热门/咨询） -->
        <main aria-label="产品列表" class="pv-channel-main pv-portal-main pv-product-hub">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <div class="col-lg-9 d-flex flex-column gap-3">
                        <div class="card shadow-sm pv-product-catalog-toolbar" aria-label="产品检索">
                            <div class="card-body">
                                <form class="pv-product-catalog-toolbar__search pv-product-search mb-0" method="get" action="" role="search">
                                    <div class="input-group pv-product-search__input">
                                        <input type="search" name="keyword" id="pv-product-kw" class="form-control" placeholder="型号、名称或规格" aria-label="搜索产品">
                                        <button class="btn btn-primary" type="submit">搜索</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm pv-product-filter-board" aria-label="产品筛选">
                            <div class="card-body">
                                <div class="pv-product-filter-board__head d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                    <span class="card-title h6 mb-0 pv-product-filter-board__title">参数筛选</span>
                                    <button type="button" class="btn btn-link btn-sm p-0 d-none" id="pv-product-filter-reset">清除筛选</button>
                                </div>
                                <div class="pv-product-filters" id="pv-product-filters" data-catalog-api="/api/v1/catalog/items" aria-live="polite"></div>
                            </div>
                        </div>

                        <div class="pv-product-list pv-product-list--catalog row g-3 g-md-4" id="pv-product-list" data-ajax-list="1" data-catalog-api="/api/v1/catalog/items" data-limit="12" data-tag="{$tag_slug}" aria-live="polite">
                            {pv:arclist entity="product" tag="{$tag_slug}" row="12"}
                            <div class="col-6 col-md-4" data-pv-product-card>
                                <article class="card h-100 shadow-sm text-center pv-product-card pv-product-card--catalog">
                                    <a class="d-flex flex-column h-100 text-decoration-none text-body" href="{$field.card_url}">
                                        <div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media">
                                            <img src="{$field.litpic}" alt="{$field.name}" loading="lazy" decoding="async" width="400" height="400" class="object-fit-cover">
                                        </div>
                                        <div class="card-body py-3 pv-product-card__body">
                                            <h2 class="h6 card-title mb-1 pv-product-card__name">{$field.name}</h2>
                                            <p class="card-text small text-muted mb-0 pv-product-card__model">型号：{$field.model}</p>
                                        </div>
                                    </a>
                                </article>
                            </div>
                            {/pv:arclist}
                        </div>

                        <div id="pv-product-empty" class="pv-product-empty text-center py-5 d-none" role="status">
                            <p class="h5 text-secondary mb-2">暂无符合条件的产品</p>
                            <p class="text-muted small mb-3">试试切换分类或放宽筛选条件。</p>
                            <a class="btn btn-outline-primary btn-sm" href="?" id="pv-product-empty-reset">查看全部</a>
                        </div>

                        {pv:include file="partials/pagination"}
                    </div>

                    <aside class="col-lg-3" aria-label="产品侧栏">
                        <div class="sidebar pv-product-sidebar">
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> 产品分类</h3>
                                    <nav class="list-group list-group-flush channel-nav pv-side-list pv-side-list--nav pv-catalog-nav" aria-label="产品分类">
                                        <a class="list-group-item list-group-item-action channel-link {pv:if empty='tag_slug'}active{/pv:if}" href="{$url_product_catalog}" data-pv-catalog-tag="">全部产品</a>
                                        {$product_catalog_nav_html|raw}
                                    </nav>
                                    {pv:if name="product_catalog_tags_empty"}
                                        <p class="text-muted small mb-0">可在后台创建使用「产品展示列表」模板的子级 TAG，侧栏将自动列出分类。</p>
                                    {/pv:if}
                                </div>
                            </div>

                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-fire" aria-hidden="true"></i> 热门型号</h3>
                                    <ul class="pv-side-list pv-side-list--thumb">
                                        {pv:arclist entity="product" nav="page" row="5" orderby="new" indexpad="2"}
                                        <li>
                                            <a href="{$field.url}">
                                                {pv:if empty="field.litpic"}
                                                    <span class="pv-side-list__media" aria-hidden="true"></span>
                                                {pv:else}
                                                    <img class="pv-side-list__media" src="{$field.litpic}" alt="" loading="lazy" decoding="async" width="48" height="48">
                                                {/pv:if}
                                                <span class="pv-side-list__body">
                                                    <strong>{$field.title}</strong>
                                                    <small>{$field.model}</small>
                                                </span>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ul>
                                </div>
                            </div>

                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-headset" aria-hidden="true"></i> 选型咨询</h3>
                                    <p class="small text-muted mb-0">应用工程师协助对比型号、确认量程与输出信号。</p>
                                    <a class="d-block fw-bold text-decoration-none" href="tel:{$site_phone}">{$site_phone}</a>
                                    <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                        <li><i class="bi bi-envelope" aria-hidden="true"></i> {$site_email}</li>
                                        <li><i class="bi bi-geo-alt" aria-hidden="true"></i> {$site_address}</li>
                                    </ul>
                                    <div class="d-grid gap-2">
                                        <a class="btn btn-primary btn-sm" href="{$url_contact}">在线留言</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </main>

        <script src="{$theme_asset}/js/demo-product-catalog.js?v={$theme_asset_ver}" defer></script>

        {pv:include file="partials/footer"}
        </body>
    </html>
