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
    <body class="pv-portal-site pv-channel-download">
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

        <!-- 区块：资料主栏 + 右侧栏（本页示范侧栏形态：--nav · --rank · --text · --media · CTA） -->
        <main aria-label="资料下载列表" class="pv-channel-main pv-portal-main pv-download-hub">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <!-- 主栏：资料下载列表 -->
                    <div class="col-lg-9">
                        <section class="pv-download-list pv-list-stack" aria-label="资料与手册">
                            {pv:list row="12"}
                            <article class="card shadow-sm pv-list-download-item">
                                <a class="card-body d-flex align-items-center gap-3 text-decoration-none text-body pv-list-download-item__link" href="{$field.url}">
                                    <span class="pv-list-download-icon" aria-hidden="true"><i class="bi bi-file-earmark-arrow-down"></i></span>
                                    <div class="pv-list-download-text flex-grow-1 min-w-0">
                                        <h2 class="h6 mb-1 text-truncate">{$field.title}</h2>
                                        {pv:if empty="field.excerpt_short"}
                                            <p class="small text-muted mb-0">{$field.click} 次浏览</p>
                                        {pv:else}
                                            <p class="small text-muted mb-0 text-truncate">{$field.excerpt_short}</p>
                                        {/pv:if}
                                    </div>
                                    <i class="bi bi-chevron-right pv-list-download-go flex-shrink-0" aria-hidden="true"></i>
                                </a>
                            </article>
                            {pv:empty}
                            <div class="text-center py-5 text-muted" role="status">
                                <i class="bi bi-inbox display-4 d-block mb-3 opacity-50" aria-hidden="true"></i>
                                <p class="mb-0 fs-5">暂无内容</p>
                            </div>
                            {/pv:empty}
                            {/pv:list}
                            <!-- 区块：分页 -->
                            {pv:include file="partials/pagination"}
                        </section>
                    </div>
                    <!-- 侧栏：排行 + 纯文资讯 + 图标视频 + 咨询 CTA（形态组合与新闻/案例/视频页不同） -->
                    <aside class="col-lg-3" aria-label="资料下载侧栏">
                        <div class="sidebar pv-download-sidebar">
                            <!-- 侧栏块：栏目导航 · 多级可折叠 · partials/channel_nav -->
                            {pv:include file="partials/channel_nav"}

                            <!-- 侧栏块：下载排行 · 形态 pv-rank-list / --rank -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-bar-chart-line"></i> 下载排行</h3>
                                    <ol class="pv-rank-list pv-side-list pv-side-list--rank">
                                        {pv:arclist nav="page" row="5" orderby="hot" indexpad="2"}
                                        <li>
                                            <a class="pv-rank-list__link" href="{$field.url}">
                                                <span class="pv-rank-list__num" aria-hidden="true">{$field.index_pad}</span>
                                                <span class="pv-rank-list__body">
                                                    <strong>{$field.title}</strong>
                                                    <small><i class="bi bi-cloud-arrow-down"></i> {$field.click} 次</small>
                                                </span>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ol>
                                </div>
                            </div>

                            <!-- 侧栏块：最新资讯 · 形态 pv-side-list--text（标题+时间，无图） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-newspaper"></i> 最新资讯</h3>
                                    <ul class="pv-side-list pv-side-list--text">
                                        {pv:arclist navid="{$home_news_nav_id}" row="5" orderby="new"}
                                        <li>
                                            <a href="{$field.url}">
                                                <strong>{$field.title}</strong>
                                                <time datetime="{$field.create_date}">{$field.create_date}</time>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ul>
                                </div>
                            </div>

                            <!-- 侧栏块：产品视频 · 形态 pv-side-list--media（图标+文） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-play-btn"></i> 产品视频</h3>
                                    <ul class="pv-side-list pv-side-list--media">
                                        {pv:arclist navid="{$home_video_nav_id}" row="4" orderby="new"}
                                        <li>
                                            <a href="{$field.url}">
                                                <i class="bi bi-play-circle" aria-hidden="true"></i>
                                                <span>{$field.title}</span>
                                            </a>
                                        </li>
                                        {/pv:arclist}
                                    </ul>
                                </div>
                            </div>

                            <!-- 侧栏块：文档索取 · CTA 咨询卡（仅卡壳，正文自定义） -->
                            <div class="card shadow-sm pv-side-block pv-download-contact">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-headset"></i> 文档索取</h3>
                                    <p class="pv-download-contact-desc">找不到所需手册或 CAD 图纸？应用工程师可协助选型与协议定制。</p>
                                    <ul class="pv-download-contact-list">
                                        <li><i class="bi bi-telephone" aria-hidden="true"></i> <a href="tel:{$site_phone}">{$site_phone}</a></li>
                                        <li><i class="bi bi-envelope" aria-hidden="true"></i> <a href="mailto:{$site_email}">{$site_email}</a></li>
                                    </ul>
                                    <a href="{$url_contact}" class="btn btn-primary btn-sm w-100">在线留言</a>
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
