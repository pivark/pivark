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
    <!-- pv:template label="产品视频详情" hint="视频优先 · {pv:doc_vod} 播放器 + 介绍正文" scope="document" -->
    <body class="pv-portal-site pv-doc-detail pv-channel-video pv-doc-video-detail">
        {pv:include file="partials/header"}

        <!-- 区块：频道 Hero / Banner（无头图 Hero · 有头图 Banner） -->
        {pv:if empty="channel_banner_image"}
            <section class="pv-channel-hero pv-portal-page-hero pv-portal-hero--center" aria-label="频道标题区">
                <div class="container">
                    <p class="pv-channel-hero-title">{$page_title}</p>
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
                    <p class="pv-channel-banner-title">{$page_title}</p>
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

        <!-- 区块：文档详情（正文主栏 + 右侧边栏） -->
        <main aria-label="文档详情" class="pv-channel-main pv-portal-main">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <article class="col-lg-9">
                        <div class="pv-doc-article-panel">
                            <header class="article-header mb-4">
                                <div class="article-header__title-row">
                                    <div class="article-header__main">
                                        <h1 class="article-main-title">{$document_title}</h1>
                                        <div class="article-info">
                                            <span class="info-item">
                                                <i class="bi bi-calendar" aria-hidden="true"></i>
                                                {$document_date}
                                            </span>
                                            <span class="info-item">
                                                <i class="bi bi-eye" aria-hidden="true"></i>
                                                {$document_click} 阅读
                                            </span>
                                            {pv:if empty="document_author"}
                                            {pv:else}
                                                <span class="info-item">
                                                    <i class="bi bi-person" aria-hidden="true"></i>
                                                    {$document_author}
                                                </span>
                                            {/pv:if}
                                        </div>
                                    </div>
                                    {pv:if name="document_qr_url"}
                                        <figure class="pv-page-qr">
                                            {pv:if name="document_share_url"}
                                            <a class="pv-page-qr-link" href="{$document_share_url}" title="打开或复制本文链接" rel="noopener">
                                                <img src="{$document_qr_url}" alt="本文页面二维码" width="80" height="80" class="pv-page-qr-img" loading="lazy" decoding="async">
                                                <figcaption class="pv-page-qr-label">扫码打开</figcaption>
                                            </a>
                                            {pv:else}
                                            <img src="{$document_qr_url}" alt="本文页面二维码" width="80" height="80" class="pv-page-qr-img" loading="lazy" decoding="async">
                                            <figcaption class="pv-page-qr-label">扫码查看</figcaption>
                                            {/pv:if}
                                        </figure>
                                    {/pv:if}
                                </div>
                                <div class="pv-doc-favorite article-actions mt-3" role="group" aria-label="点赞收藏">
                                    {pv:favorite document_id="{$document_id}" mode="bar"}
                                </div>
                            </header>

                            {pv:if name="document_has_doc_gallery"}
                                <!-- 有图集：图集置顶，不展示封面缩略图 -->
                                <section class="pv-doc-gallery mb-4" id="pv-doc-gallery" aria-label="文档图集">
                                    <header class="pv-doc-section-head">
                                        <h2 class="pv-doc-section-title"><i class="bi bi-images" aria-hidden="true"></i> 图集</h2>
                                    </header>
                                    {pv:doc_gallery_groups id="{$document_id}"}
                                    <section class="pv-gallery-group-block" data-group="{$field.group_key}" data-style="{$field.display_style}">
                                        <header class="pv-gallery-group-head">
                                            <h3 class="pv-gallery-group-title">{$field.title}</h3>
                                            {pv:if name="field.display_style_label"}
                                                <span class="badge text-bg-light border pv-gallery-style-badge">{$field.display_style_label}</span>
                                            {/pv:if}
                                            {pv:if name="field.pack_download"}
                                                <a href="{$field.pack_download_url}" class="btn btn-sm btn-outline-primary pv-gallery-pack-dl" rel="noopener">
                                                    <i class="bi bi-file-earmark-zip" aria-hidden="true"></i>
                                                    {$field.pack_download_label}{pv:if name="field.pack_download_size_text"} ({$field.pack_download_size_text}){/pv:if}
                                                </a>
                                            {/pv:if}
                                        </header>
                                        {gallery}
                                    </section>
                                    {/pv:doc_gallery_groups}
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_doc_vod"}
                                <!-- 区块：视频播放（详情页优先） -->
                                <section class="pv-doc-video mb-4" id="pv-doc-video" aria-label="文档视频">
                                    <div class="pv-doc-video__list">
                                        {pv:doc_vod id="{$document_id}"}
                                    </div>
                                </section>
                            {pv:else}
                                {pv:if empty="document_litpic"}
                                {pv:else}
                                    <figure class="article-figure mb-4">
                                        <img src="{$document_litpic}" alt="{$document_title}" class="img-fluid rounded" decoding="async" fetchpriority="high">
                                    </figure>
                                {/pv:if}
                            {/pv:if}

                            <div class="article-content">
                                {pv:if name="document_login_required"}
                                    <p class="text-muted">{pv:if name="document_perm_hint"}{$document_perm_hint}{pv:else}{pv:if name="document_read_level_name"}本文需 {$document_read_level_name} 及以上会员阅读，当前仅展示摘要。{pv:else}本文需登录后阅读，当前仅展示摘要。{/pv:if}{/pv:if}</p>
                                    {pv:if name="document_perm_cta_url"}
                                        <p><a class="btn btn-primary btn-sm" href="{$document_perm_cta_url}">{$document_perm_cta_label}</a></p>
                                    {/pv:if}
                                    <p class="mt-3">{$document_summary}</p>
                                    <p class="mt-3"><a class="btn btn-primary btn-sm" href="{$member_login_url}">登录后阅读全文</a></p>
                                {pv:else}
                                    {$document_content|raw}
                                    {pv:if name="document_content_paged"}
                                        <nav class="pv-content-pages mt-4" aria-label="正文分页">
                                            <span class="text-muted small">第 {$document_content_page} / {$document_content_page_total} 页</span>
                                            {pv:if name="document_content_page_prev_url"}
                                                <a class="btn btn-sm btn-outline-secondary ms-2" href="{$document_content_page_prev_url}">上一页</a>
                                            {/pv:if}
                                            {pv:if name="document_content_page_next_url"}
                                                <a class="btn btn-sm btn-outline-secondary ms-1" href="{$document_content_page_next_url}">下一页</a>
                                            {/pv:if}
                                        </nav>
                                    {/pv:if}
                                {/pv:if}
                            </div>

                            {pv:if name="document_has_doc_ask"}
                                <!-- 区块：本文 FAQ（广场主入口 /faq） -->
                                <section class="pv-doc-ask mt-4" id="pv-doc-ask" aria-label="常见问题">
                                    <header class="pv-doc-section-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <h2 class="pv-doc-section-title mb-0"><i class="bi bi-question-circle" aria-hidden="true"></i> 常见问题</h2>
                                        <a class="small text-decoration-none" href="{$url_faq}">全部 FAQ <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    </header>
                                    <div class="pv-doc-ask__list">
                                        {pv:doc_ask id="{$document_id}"}
                                        <details class="pv-ask-item" id="pv-ask-{$field.id}">
                                            <summary>{$field.question}</summary>
                                            <div class="pv-ask-answer">{$field.answer}</div>
                                        </details>
                                        {/pv:doc_ask}
                                    </div>
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_doc_talent"}
                                <!-- 区块：相关岗位（招聘主入口 /careers） -->
                                <section class="pv-doc-talent mt-4" id="pv-doc-talent" aria-label="相关岗位">
                                    <header class="pv-doc-section-head d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <h2 class="pv-doc-section-title mb-0"><i class="bi bi-briefcase" aria-hidden="true"></i> 相关岗位</h2>
                                        <a class="small text-decoration-none" href="{$url_careers}">招贤纳士 <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    </header>
                                    <div class="pv-doc-talent__list d-flex flex-column gap-3">
                                        {pv:doc_talent id="{$document_id}"}
                                        <details class="pv-job-card border rounded bg-white">
                                            <summary class="p-3" style="list-style:none;cursor:pointer">
                                                <h3 class="h6 mb-1">
                                                    {$field.title}
                                                    {pv:if name="field.salary_text"}<span class="text-primary fw-semibold ms-2">{$field.salary_text}</span>{/pv:if}
                                                </h3>
                                                <p class="small text-muted mb-0">{$field.meta_line}</p>
                                            </summary>
                                            <div class="px-3 pb-3 border-top">
                                                {pv:if name="field.responsibilities"}
                                                <div class="mt-3">
                                                    <h4 class="h6">岗位职责</h4>
                                                    <p class="small mb-0" style="white-space:pre-wrap">{$field.responsibilities}</p>
                                                </div>
                                                {/pv:if}
                                                {pv:if name="field.requirements"}
                                                <div class="mt-3">
                                                    <h4 class="h6">任职要求</h4>
                                                    <p class="small mb-0" style="white-space:pre-wrap">{$field.requirements}</p>
                                                </div>
                                                {/pv:if}
                                                <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
                                                    {pv:if name="field.apply_mailto"}
                                                    <a class="btn btn-sm btn-primary" href="mailto:{$field.apply_mailto}?subject=应聘-{$field.title}">邮件投递</a>
                                                    {/pv:if}
                                                    {pv:if name="field.apply_url"}
                                                    <a class="btn btn-sm btn-outline-primary" href="{$field.apply_url}" target="_blank" rel="noopener">在线投递</a>
                                                    {/pv:if}
                                                    {pv:if name="field.apply_note"}
                                                    <span class="small text-muted">{$field.apply_note}</span>
                                                    {/pv:if}
                                                </div>
                                            </div>
                                        </details>
                                        {/pv:doc_talent}
                                    </div>
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_product"}
                                <!-- 区块：关联产品 -->
                                <section class="pv-doc-product mt-4" id="pv-doc-product" aria-label="关联产品">
                                    <header class="pv-doc-section-head">
                                        <h2 class="pv-doc-section-title"><i class="bi bi-box-seam" aria-hidden="true"></i> 关联产品</h2>
                                    </header>
                                    <div class="pv-doc-product__cards">
                                        {pv:product}
                                        <article class="card h-100 shadow-sm pv-product-card pv-product-card--doc overflow-hidden">
                                            <div class="row g-0 align-items-stretch">
                                            {pv:if empty="field.litpic"}
                                            {pv:else}
                                                <div class="col-md-4">
                                                <div class="ratio ratio-4x3 bg-light h-100 pv-product-cover">
                                                    <img src="{$field.litpic}" alt="{$field.name}" loading="lazy" class="object-fit-cover">
                                                </div>
                                                </div>
                                            {/pv:if}
                                            <div class="col">
                                            <div class="card-body">
                                                <span class="badge text-bg-light mb-2 pv-product-card__type">{$field.item_type_text}</span>
                                                <h4 class="h6 card-title pv-product-name mb-1">{$field.name}</h4>
                                                <div class="small text-muted pv-product-code mb-2">型号 {$field.code}</div>
                                                {pv:if empty="field.attrs_summary_html"}
                                                {pv:else}
                                                    <div class="pv-product-attrs small mb-2">{$field.attrs_summary_html|raw}</div>
                                                {/pv:if}
                                                {pv:if empty="field.card_url"}
                                                {pv:else}
                                                    <a class="btn btn-sm btn-outline-primary pv-product-card__cta" href="{$field.card_url}">查看详情</a>
                                                {/pv:if}
                                            </div>
                                            </div>
                                            </div>
                                        </article>
                                        {/pv:product}
                                    </div>
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_product_related"}
                                <!-- 区块：相关产品推荐 -->
                                <section class="pv-doc-product-related mt-4" aria-label="相关产品推荐">
                                    <header class="pv-doc-section-head">
                                        <h2 class="pv-doc-section-title"><i class="bi bi-grid" aria-hidden="true"></i> 相关产品</h2>
                                    </header>
                                    {pv:product_related limit="4"}
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_offer"}
                                <!-- 区块：报价 / 商城方案 -->
                                <section class="pv-doc-shop mt-4" id="pv-doc-shop" aria-label="报价方案">
                                    <header class="pv-doc-section-head">
                                        <h2 class="pv-doc-section-title"><i class="bi bi-cart3" aria-hidden="true"></i> 报价方案</h2>
                                    </header>
                                    <div class="pv-doc-shop__offers">
                                        {pv:shop}
                                    </div>
                                </section>
                            {/pv:if}

                            {pv:if name="document_has_doc_bundle"}

                                {pv:doc_bundle layout="cards" id="{$document_id}" /}

                            {/pv:if}

                                {pv:if name="document_has_doc_comment"}
                                    <!-- 区块：评论 -->
                                    <section class="pv-doc-comments mt-5" id="pv-doc-comments" aria-label="评论">
                                        <header class="pv-doc-section-head">
                                            <h2 class="pv-doc-section-title"><i class="bi bi-chat-dots" aria-hidden="true"></i> 评论</h2>
                                        </header>
                                        {pv:doc_comment id="{$document_id}"}
                                    </section>
                                {/pv:if}

                                <!-- 区块：文末标签条（侧栏另有 --tags 形态 chip；此处保留文内维度入口） -->
                                {pv:if empty="document_tags"}
                                {pv:else}
                                    <footer class="article-tags-footer">
                                        <div class="tags-label">
                                            <i class="bi bi-tags"></i>
                                            相关内容维度
                                        </div>
                                        <div class="tags-list">
                                            {pv:foreach name="document_tags" item="tag"}
                                                <a href="{$tag.url}" class="badge text-bg-light text-decoration-none tag-badge">{$tag.name}</a>
                                            {/pv:foreach}
                                        </div>
                                    </footer>
                                {/pv:if}

                                <aside class="pv-doc-article-meta" aria-label="版权与来源">
                                    <dl class="pv-doc-article-meta__list">
                                        <div class="pv-doc-article-meta__row">
                                            <dt class="pv-doc-article-meta__label">版权声明：</dt>
                                            <dd class="pv-doc-article-meta__value">
                                                本文由 {$document_meta_publisher} {$document_date} {$document_meta_source_phrase}，如侵犯了您的权益，请于<a href="{$url_contact}">本站联系</a>！
                                            </dd>
                                        </div>
                                        <div class="pv-doc-article-meta__row">
                                            <dt class="pv-doc-article-meta__label">文章地址：</dt>
                                            <dd class="pv-doc-article-meta__value">
                                                <a href="{$document_meta_address_url}" class="pv-doc-article-meta__link">{$document_meta_address}</a>
                                            </dd>
                                        </div>
                                    </dl>
                                </aside>

                                <nav class="article-nav" aria-label="上下篇">
                                    {pv:if name="prev_document"}
                                        <a href="{$prev_document.url}" class="card shadow-sm text-decoration-none text-body nav-article prev">
                                            <div class="card-body">
                                            <span class="small text-muted d-flex align-items-center gap-1 nav-label"><i class="bi bi-chevron-left"></i> 上一篇</span>
                                            <span class="d-block fw-semibold nav-title mt-1 text-truncate">{$prev_document.title}</span>
                                            </div>
                                        </a>
                                    {pv:else}<span aria-hidden="true"></span>{/pv:if}
                                        {pv:if name="next_document"}
                                            <a href="{$next_document.url}" class="card shadow-sm text-decoration-none text-body nav-article next">
                                                <div class="card-body">
                                                <span class="small text-muted d-flex align-items-center gap-1 nav-label justify-content-end">下一篇 <i class="bi bi-chevron-right"></i></span>
                                                <span class="d-block fw-semibold nav-title mt-1 text-truncate">{$next_document.title}</span>
                                                </div>
                                            </a>
                                        {pv:else}<span aria-hidden="true"></span>{/pv:if}
                                    </nav>
                                </div>
                            </article>

                            <!-- 区块：文档侧栏（本页示范：插件导览 · --tags · --thumb） -->
                            <aside class="col-lg-3" aria-label="文档侧栏">
                                <div class="sidebar">
                                    <!-- 侧栏块：内容导览（有插件区块时出现；结构近 --nav） -->
                                    {pv:if name="document_has_plugin_nav"}
                                        <div class="card shadow-sm pv-side-block pv-doc-plugin-nav">
                                            <div class="card-body">
                                            {pv:if empty="document_sidebar_highlight_title"}
                                                <h3 class="card-title h6 sidebar-title">内容导览</h3>
                                            {pv:else}
                                                <h3 class="card-title h6 sidebar-title">{$document_sidebar_highlight_title}</h3>
                                            {/pv:if}
                                            {pv:if empty="document_sidebar_highlight_text"}
                                            {pv:else}
                                                <p class="pv-doc-plugin-nav-desc">{$document_sidebar_highlight_text}</p>
                                            {/pv:if}
                                            <nav class="list-group list-group-flush channel-nav pv-doc-plugin-nav-links" aria-label="页面区块导航">
                                                {pv:if name="document_has_doc_gallery"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-gallery"><i class="bi bi-images"></i> 图集</a>
                                                {/pv:if}
                                                {pv:if name="document_has_doc_vod"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-video"><i class="bi bi-play-btn"></i> 视频</a>
                                                {/pv:if}
                                                {pv:if name="document_has_doc_ask"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-ask"><i class="bi bi-question-circle"></i> 常见问题</a>
                                                {/pv:if}
                                                {pv:if name="document_has_doc_talent"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-talent"><i class="bi bi-briefcase"></i> 相关岗位</a>
                                                {/pv:if}
                                                {pv:if name="document_has_product"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-product"><i class="bi bi-box-seam"></i> 关联产品</a>
                                                {/pv:if}
                                                {pv:if name="document_has_product_accessories"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-product-accessories"><i class="bi bi-puzzle"></i> 配件辅件</a>
                                                {/pv:if}
                                                {pv:if name="document_has_offer"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-shop"><i class="bi bi-cart3"></i> 报价方案</a>
                                                {/pv:if}
                                                {pv:if name="document_has_doc_bundle"}
                                                    <a class="list-group-item list-group-item-action channel-link" href="#pv-doc-downloads"><i class="bi bi-cloud-download"></i> 资源下载</a>
                                                {/pv:if}
                                            </nav>
                                            {pv:if name="document_has_offer"}
                                                <div class="pv-doc-shop-sidebar">
                                                    {pv:shop}
                                                </div>
                                            {/pv:if}
                                            </div>
                                        </div>
                                    {/pv:if}
                                    <!-- 侧栏块：本文标签 · 形态 pv-side-list--tags（chip；数据用 document_tags） -->
                                    {pv:if empty="document_tags"}
                                    {pv:else}
                                        <div class="card shadow-sm pv-side-block">
                                            <div class="card-body">
                                                <h3 class="card-title h6 sidebar-title"><i class="bi bi-tags"></i> 本文标签</h3>
                                                <ul class="pv-side-list pv-side-list--tags">
                                                    {pv:foreach name="document_tags" item="tag"}
                                                        <li><a class="pv-side-list__chip" href="{$tag.url}">{$tag.name}</a></li>
                                                    {/pv:foreach}
                                                </ul>
                                            </div>
                                        </div>
                                    {/pv:if}
                                    <!-- 侧栏块：相关推荐 · 形态 pv-side-list--thumb（左图右文） -->
                                    {pv:if empty="related_documents"}
                                    {pv:else}
                                        <div class="card shadow-sm pv-side-block">
                                            <div class="card-body">
                                            <h3 class="card-title h6 sidebar-title">
                                                <i class="bi bi-link"></i>
                                                相关推荐
                                            </h3>
                                            <ul class="pv-side-list pv-side-list--thumb">
                                                {pv:foreach name="related_documents" item="rel"}
                                                    <li>
                                                        <a href="{$rel.url}">
                                                            {pv:if empty="rel.litpic"}
                                                                <i class="bi bi-file-text pv-side-list__media" aria-hidden="true"></i>
                                                            {pv:else}
                                                                <img class="pv-side-list__media" src="{$rel.litpic}" alt="" loading="lazy">
                                                            {/pv:if}
                                                            <span class="pv-side-list__body"><strong>{$rel.title}</strong></span>
                                                        </a>
                                                    </li>
                                                {/pv:foreach}
                                            </ul>
                                            </div>
                                        </div>
                                    {/pv:if}
                                </div>
                            </aside>
                        </div>
                    </div>
                </main>

        {pv:include file="partials/footer"}
                </body>
            </html>

