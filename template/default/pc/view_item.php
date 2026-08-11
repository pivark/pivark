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
    <body class="pv-portal-site pv-channel-product pv-product-hub-page pv-item-detail">
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

        <!-- 区块：品项详情（图集 · 参数 · 正文 · 侧栏） -->
        <main aria-label="品项详情" class="pv-channel-main pv-portal-main pv-item-page">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <div class="col-lg-9 order-lg-1">
                        <div class="pv-item-article-panel">
                            <div class="row g-3 align-items-start pv-item-page__top">
                                <div class="col-md-6">
                                    <div class="pv-item-gallery" data-pv-item-gallery>
                                        {pv:if value="0" name="product_images_empty"}
                                            <figure class="pv-item-gallery__cover">
                                                <img src="{$product_image_main}" alt="{$field.name}" loading="eager" data-pv-gallery-main>
                                            </figure>
                                            <div class="pv-item-gallery__thumbs" role="list">
                                                {pv:volist name="product_images" item="img"}
                                                <button type="button" class="pv-item-gallery__thumb" role="listitem" data-pv-gallery-thumb data-src="{$img.url}" aria-label="产品图片">
                                                    <img src="{$img.url}" alt="" loading="lazy" decoding="async">
                                                </button>
                                                {/pv:volist}
                                            </div>
                                        {/pv:if}
                                        {pv:if value="1" name="product_images_empty"}
                                            {pv:if name="field.cover_url"}
                                            <figure class="pv-item-gallery__cover">
                                                <img src="{$field.cover_url}" alt="{$field.name}" loading="eager">
                                            </figure>
                                            {/pv:if}
                                        {/pv:if}
                                        {pv:if value="1" name="product_images_empty"}
                                            {pv:if empty="field.cover_url"}
                                                {pv:if value="1" name="item_has_gallery"}
                                            {pv:doc_gallery id="{$document_id}" style="carousel"}
                                                {/pv:if}
                                            {/pv:if}
                                        {/pv:if}
                                    </div>
                                </div>
                                <div class="col-md-6 pv-item-overview">
                                    <h1 class="pv-item-page__title">{$field.name}</h1>

                                    {pv:if value="0" name="field.summary_empty"}
                                        <p class="pv-item-page__summary">{$field.summary}</p>
                                    {pv:else}
                                        <p class="pv-item-page__summary text-muted">请联系应用工程师获取详细选型说明。</p>
                                    {/pv:if}

                                    <dl class="pv-item-meta">
                                        {pv:if value="0" name="field.product_brand_empty"}
                                        <div class="pv-item-meta__row">
                                            <dt>产品品牌</dt>
                                            <dd>{$field.product_brand}</dd>
                                        </div>
                                        {/pv:if}
                                        <div class="pv-item-meta__row">
                                            <dt>产品型号</dt>
                                            <dd>{$field.model}</dd>
                                        </div>
                                        <div class="pv-item-meta__row">
                                            <dt>产品类型</dt>
                                            <dd>{$field.item_type_text}</dd>
                                        </div>
                                    </dl>

                                    <div class="pv-item-consult">
                                        <span class="pv-item-consult__label">产品咨询</span>
                                        <a class="pv-item-consult__phone" href="tel:{$site_phone}">{$site_phone}</a>
                                        <a class="btn btn-primary btn-sm" href="{$url_contact}">在线留言</a>
                                    </div>

                                    {pv:section}
                                    <section class="pv-item-page__shop pv-doc-shop mt-3" aria-label="在线购买">
                                        <h2 class="pv-item-block__title h6 mb-2">在线购买</h2>
                                        <div class="pv-doc-shop__offers">
                                            {pv:content}
                                            {pv:shop item_id="{$field.id}"}
                                            {/pv:content}
                                        </div>
                                    </section>
                                    {/pv:section}
                                </div>
                            </div>

                            <!-- 区块：技术参数表 -->
                            <section class="card mb-3 mt-3 shadow-sm pv-item-block pv-item-block--params" aria-labelledby="pv-item-specs-title">
                                <div class="card-body">
                                <h2 class="card-title h6 pv-item-block__title border-bottom border-primary border-2 d-inline-block pb-1 mb-2" id="pv-item-specs-title">产品参数</h2>
                                {$field.specs_html|raw}
                                </div>
                            </section>

                            {pv:if value="0" name="field.document_content_empty"}
                                <!-- 区块：详细介绍 -->
                                <section class="card mb-3 shadow-sm pv-item-block pv-item-block--detail" aria-labelledby="pv-item-detail-title">
                                    <div class="card-body">
                                    <h2 class="card-title h6 pv-item-block__title border-bottom border-primary border-2 d-inline-block pb-1 mb-2" id="pv-item-detail-title">详细介绍</h2>
                                    <div class="pv-item-detail-body">{$field.document_content|raw}</div>
                                    </div>
                                </section>
                            {/pv:if}

                            {pv:if value="0" name="field.tag_labels_empty"}
                                <section class="pv-item-tags" aria-label="产品标签">
                                    <span class="pv-item-tags__label">Tag：</span>
                                    {pv:foreach name="field.tag_labels" item="tag_label"}
                                        <span class="badge rounded-pill text-bg-light me-1">{$tag_label}</span>
                                    {/pv:foreach}
                                </section>
                            {/pv:if}

                            <nav class="pv-item-pager" aria-label="同分类产品导航">
                                {pv:if value="0" name="item_prev_empty"}
                                    <a class="card shadow-sm text-decoration-none text-body pv-item-pager__link pv-item-pager__link--prev" href="{$item_prev.card_url}">
                                        <div class="card-body">
                                        <span class="small text-muted d-block">上一篇</span>
                                        <span class="fw-semibold text-truncate d-block">{$item_prev.name}</span>
                                        </div>
                                    </a>
                                {pv:else}
                                    <span class="pv-item-pager__link pv-item-pager__link--prev" aria-hidden="true"></span>
                                {/pv:if}
                                <a class="btn btn-outline-secondary pv-item-pager__link pv-item-pager__link--list" href="{$product_list_url}">返回列表</a>
                                {pv:if value="0" name="item_next_empty"}
                                    <a class="card shadow-sm text-decoration-none text-body pv-item-pager__link pv-item-pager__link--next" href="{$item_next.card_url}">
                                        <div class="card-body">
                                        <span class="small text-muted d-block">下一篇</span>
                                        <span class="fw-semibold text-truncate d-block">{$item_next.name}</span>
                                        </div>
                                    </a>
                                {pv:else}
                                    <span class="pv-item-pager__link pv-item-pager__link--next" aria-hidden="true"></span>
                                {/pv:if}
                            </nav>

                            {pv:section}
                            <!-- 区块：配件与辅件（无配件时整块不渲染） -->
                            <section class="pv-item-page__accessories mt-4 pt-4 border-top" aria-label="配件与辅件">
                                <header class="mb-3">
                                    <h2 class="h5 pv-product-section-title">配件与辅件</h2>
                                    <p class="text-muted small mb-0">与本型号配套的原厂配件、备件与辅件。</p>
                                </header>
                                <div class="row g-3 g-md-4 pv-product-list pv-product-list--catalog">
                                    {pv:content}
                                    {pv:product_accessories limit="24" item_id="{$field.id}"}
                                    <div class="col-6 col-md-4 col-lg-3" data-pv-product-card>
                                        <article class="card h-100 shadow-sm text-center pv-product-card pv-product-card--catalog">
                                            <a class="d-flex flex-column h-100 text-decoration-none text-body" href="{$field.card_url}">
                                                <div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media">
                                                    <img src="{$field.litpic}" alt="{$field.name}" loading="lazy" class="object-fit-cover" width="400" height="400">
                                                </div>
                                                <div class="card-body py-3 pv-product-card__body">
                                                    <h3 class="h6 card-title mb-1 pv-product-card__name">{$field.name}</h3>
                                                    <p class="card-text small text-muted mb-0 pv-product-card__model">型号：{$field.model}</p>
                                                </div>
                                            </a>
                                        </article>
                                    </div>
                                    {/pv:product_accessories}
                                    {/pv:content}
                                </div>
                            </section>
                            {/pv:section}

                            {pv:section}
                            <!-- 区块：相关产品（无同标签相关型号时整块不渲染） -->
                            <section class="pv-item-page__related mt-4 pt-4 border-top" aria-label="相关产品">
                                <header class="mb-3">
                                    <h2 class="h5 pv-product-section-title">相关产品推荐</h2>
                                    <p class="text-muted small mb-0">同系列或同应用场景下的其他型号，便于对比选型。</p>
                                </header>
                                <div class="row g-3 g-md-4 pv-product-list pv-product-list--catalog">
                                    {pv:content}
                                    {pv:product_related limit="4" item_id="{$field.id}"}
                                    <div class="col-6 col-md-4 col-lg-3" data-pv-product-card>
                                        <article class="card h-100 shadow-sm text-center pv-product-card pv-product-card--catalog">
                                            <a class="d-flex flex-column h-100 text-decoration-none text-body" href="{$field.card_url}">
                                                <div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media">
                                                    <img src="{$field.litpic}" alt="{$field.name}" loading="lazy" class="object-fit-cover" width="400" height="400">
                                                </div>
                                                <div class="card-body py-3 pv-product-card__body">
                                                    <h3 class="h6 card-title mb-1 pv-product-card__name">{$field.name}</h3>
                                                    <p class="card-text small text-muted mb-0 pv-product-card__model">型号：{$field.model}</p>
                                                </div>
                                            </a>
                                        </article>
                                    </div>
                                    {/pv:product_related}
                                    {/pv:content}
                                </div>
                            </section>
                            {/pv:section}
                        </div>
                    </div>
                    <!-- 侧栏：本页示范形态 --nav · --thumb · CTA（产品详情专用数据源） -->
                    <aside class="col-lg-3 order-lg-2" aria-label="产品侧栏">
                        <div class="sidebar pv-product-sidebar pv-product-sidebar--item">
                            <!-- 侧栏块：产品分类 · 形态 pv-side-list--nav -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-grid-3x3-gap"></i> 产品分类</h3>
                                    <nav class="list-group list-group-flush channel-nav pv-side-list pv-side-list--nav pv-catalog-nav" aria-label="产品分类">
                                        <a class="list-group-item list-group-item-action channel-link {pv:if empty='tag_slug'}active{/pv:if}" href="{$url_product_catalog}" data-pv-catalog-tag="">全部产品</a>
                                        {$product_catalog_nav_html|raw}
                                    </nav>
                                    {pv:if name="product_catalog_tags_empty"}
                                        <p class="text-muted small mb-0">可在后台创建使用「产品展示列表」模板的子级 TAG，侧栏将自动列出分类。</p>
                                    {/pv:if}
                                </div>
                            </div>

                            <!-- 侧栏块：相关型号 · 形态 pv-side-list--thumb（左图右文） -->
                            {pv:section}
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-box-seam"></i> 相关型号</h3>
                                    <ul class="pv-side-list pv-side-list--thumb">
                                        {pv:content}
                                        {pv:product_related limit="3" item_id="{$field.id}"}
                                        <li>
                                            <a href="{$field.card_url}">
                                                {pv:if empty="field.litpic"}
                                                    <span class="pv-side-list__media" aria-hidden="true"></span>
                                                {pv:else}
                                                    <img class="pv-side-list__media" src="{$field.litpic}" alt="" loading="lazy" width="48" height="48">
                                                {/pv:if}
                                                <span class="pv-side-list__body">
                                                    <strong>{$field.name}</strong>
                                                    <small>{$field.model}</small>
                                                </span>
                                            </a>
                                        </li>
                                        {/pv:product_related}
                                        {/pv:content}
                                    </ul>
                                </div>
                            </div>
                            {/pv:section}

                            <!-- 侧栏块：选型咨询 · CTA 咨询卡（仅卡壳，正文自定义） -->
                            <div class="card shadow-sm pv-side-block">
                                <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title"><i class="bi bi-headset"></i> 选型咨询</h3>
                                    <p class="small text-muted mb-0">应用工程师 1 对 1 协助对比型号、确认量程与输出信号。</p>
                                    <a class="d-block fw-bold text-decoration-none" href="tel:{$site_phone}">{$site_phone}</a>
                                    <ul class="list-unstyled small text-muted mb-0 d-flex flex-column gap-1">
                                        <li><i class="bi bi-envelope"></i> {$site_email}</li>
                                        <li><i class="bi bi-geo-alt"></i> {$site_address}</li>
                                    </ul>
                                    <div class="d-grid gap-2">
                                        <a class="btn btn-primary btn-sm" href="{$url_contact}">在线留言</a>
                                        <a class="btn btn-outline-secondary btn-sm" href="{$product_list_url}">返回产品列表</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </main>

        {pv:include file="partials/footer"}
        <script>
        (function () {
            var root = document.querySelector('[data-pv-item-gallery]');
            if (!root) return;
            var main = root.querySelector('[data-pv-gallery-main]');
            var thumbs = root.querySelectorAll('[data-pv-gallery-thumb]');
            if (!main || !thumbs.length) return;
            function setMain(src, activeBtn) {
                if (!src) return;
                main.src = src;
                thumbs.forEach(function (t) { t.classList.remove('is-active'); });
                if (activeBtn) activeBtn.classList.add('is-active');
            }
            thumbs.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setMain(btn.getAttribute('data-src') || '', btn);
                });
            });
            var first = thumbs[0];
            var firstSrc = first.getAttribute('data-src') || '';
            if (!main.getAttribute('src') && firstSrc) {
                setMain(firstSrc, first);
            } else if (first) {
                first.classList.add('is-active');
            }
        })();
        </script>
        </body>
    </html>

