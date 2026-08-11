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
    <body class="pv-portal-site pv-video-plaza-page">
        {pv:include file="partials/header"}

        <!-- 区块：面包屑 -->
        {pv:include file="partials/breadcrumb"}

        <!-- 区块：视频频道广场（搜索 · 排序 · 频道卡片 · 分页） -->
        <main aria-label="视频频道广场" class="pv-channel-main pv-portal-main pv-video-plaza">
            <div class="container">
                <header class="pv-video-plaza__head mb-4">
                    <h1 class="pv-page-title h3 mb-2">{$page_title}</h1>
                    <p class="text-muted mb-0">共 {$plaza_total} 个视频频道，支持关键词搜索与排序。</p>
                </header>

                <form class="pv-video-plaza__toolbar row g-2 align-items-center mb-4" method="get" action="{$url_video_plaza}">
                    <div class="col-md-5">
                        <input type="search" name="q" class="form-control" placeholder="搜索频道" value="{$plaza_keyword}">
                    </div>
                    <div class="col-auto">
                        <select name="sort" class="form-select">
                            <option value="hot" {pv:if name="plaza_sort" value="hot"}selected{/pv:if}>最热</option>
                            <option value="new" {pv:if name="plaza_sort" value="new"}selected{/pv:if}>最新</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">筛选</button>
                    </div>
                </form>

                {pv:if name="plaza_empty"}
                    <p class="text-muted">暂无视频频道，请稍后再来。</p>
                {pv:else}
                    <div class="row g-4 pv-video-plaza__grid">
                        {pv:foreach name="plaza_list" item="item"}
                            <div class="col-sm-6 col-lg-4 col-xl-3">
                                <article class="card h-100 shadow-sm border-0 pv-video-plaza__card">
                                    {pv:if empty="item.cover_url"}
                                        <div class="pv-video-plaza__cover pv-video-plaza__cover--empty"></div>
                                    {pv:else}
                                        <a href="{$item.channel_url}" class="pv-video-plaza__cover-link">
                                            <img src="{$item.cover_url}" alt="{$item.title}" class="card-img-top pv-video-plaza__cover" loading="lazy">
                                        </a>
                                    {/pv:if}
                                    <div class="card-body d-flex flex-column">
                                        <h2 class="h6 card-title mb-1">
                                            {pv:if empty="item.channel_url"}
                                                {$item.title}
                                            {pv:else}
                                                <a href="{$item.channel_url}" class="text-decoration-none stretched-link">{$item.title}</a>
                                            {/pv:if}
                                        </h2>
                                        {pv:if empty="item.summary"}
                                        {pv:else}
                                            <p class="card-text text-muted small flex-grow-1">{$item.summary}</p>
                                        {/pv:if}
                                        <p class="card-text small text-muted mb-0">
                                            {$item.ep_count} 集 · {$item.play_sum} 次播放
                                        </p>
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

