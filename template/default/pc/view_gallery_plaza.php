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
    <body class="pv-portal-site pv-gallery-plaza-page">
        {pv:include file="partials/header"}

        <!-- 区块：面包屑 -->
        {pv:include file="partials/breadcrumb"}

        <!-- 区块：图集广场（搜索 · 图集卡片 · 分页） -->
        <main aria-label="图集广场" class="pv-channel-main pv-portal-main pv-gallery-plaza">
            <div class="container">
                <header class="pv-gallery-plaza__head mb-4">
                    <h1 class="pv-page-title h3 mb-2">{$page_title}</h1>
                    <p class="text-muted mb-0">共 {$plaza_total} 个图集文档，支持关键词搜索与排序。</p>
                </header>

                <form class="pv-gallery-plaza__toolbar row g-2 align-items-center mb-4" method="get" action="/doc_gallery">
                    <div class="col-md-5">
                        <input type="search" name="q" class="form-control" placeholder="搜索图集" value="{$plaza_keyword}">
                    </div>
                    <div class="col-auto">
                        <select name="sort" class="form-select">
                            <option value="hot" {pv:if name="plaza_sort" value="hot"}selected{/pv:if}>图片最多</option>
                            <option value="new" {pv:if name="plaza_sort" value="new"}selected{/pv:if}>最新发布</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">筛选</button>
                    </div>
                </form>

                {pv:if name="plaza_empty"}
                    <div class="pv-gallery-plaza__empty text-center py-5">
                        <p class="text-muted mb-2">暂无图集可展示。</p>
                        <p class="small text-muted mb-0">请确认已启用文档图集插件，并为文档添加图片；广场入口可在插件「基础设置 → 前台体验」开关。</p>
                    </div>
                {pv:else}
                    <div class="row g-4 pv-gallery-plaza__grid">
                        {pv:foreach name="plaza_list" item="item"}
                            <div class="col-sm-6 col-lg-4 col-xl-3">
                                <article class="card h-100 shadow-sm border-0 pv-gallery-plaza__card">
                                    {pv:if empty="item.cover_url"}
                                        <div class="pv-gallery-plaza__cover pv-gallery-plaza__cover--empty"></div>
                                    {pv:else}
                                        <a href="{$item.document_url}" class="pv-gallery-plaza__cover-link">
                                            <img src="{$item.cover_url}" alt="{$item.title}" class="card-img-top pv-gallery-plaza__cover" loading="lazy">
                                        </a>
                                    {/pv:if}
                                    <div class="card-body d-flex flex-column">
                                        <h2 class="h6 card-title mb-1">
                                            <a href="{$item.document_url}" class="text-decoration-none stretched-link">{$item.title}</a>
                                        </h2>
                                        {pv:if empty="item.summary"}
                                        {pv:else}
                                            <p class="card-text text-muted small flex-grow-1">{$item.summary}</p>
                                        {/pv:if}
                                        <p class="card-text small text-muted mb-0">{$item.image_count} 张图片</p>
                                    </div>
                                </article>
                            </div>
                        {/pv:foreach}
                    </div>

                    {pv:include file="partials/pagination"}
                {/pv:if}
            </div>
        </main>

        {pv:include file="partials/footer"}
        </body>
    </html>

