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
    <body class="pv-portal-site pv-about-page">
        {pv:include file="partials/header"}

        <!-- 区块：关于我们 Hero -->
        <section class="pv-channel-hero pv-channel-hero--about pv-portal-page-hero pv-about-hero" aria-label="关于我们标题区">
            <div class="container">
                <div class="row align-items-center g-4">
                    <div class="col-lg-7 pv-about-hero__copy">
                        <span class="pv-portal-kicker">关于我们</span>
                        <h1 class="pv-channel-hero-title">{$page_title}</h1>
                        <p class="pv-channel-hero-desc">专注工业自动化与过程测控 · 为石化、电力、冶金等行业提供可靠仪表与系统方案</p>
                    </div>
                    <div class="col-lg-5 d-none d-lg-block pv-about-hero__media">
                        <img class="pv-about-hero-img" src="{$theme_asset}/images/gallery/02.jpg" alt="华仪智控工业测控现场" loading="lazy" width="560" height="360">
                    </div>
                </div>
            </div>
        </section>

        <!-- 区块：数据条 · 锚点导航 · 单页正文 · 页底 CTA -->
        <main aria-label="关于我们正文" class="pv-channel-main">
            <div class="container">
                <div class="pv-about-stats row g-3 mb-5">
                    <div class="col-6 col-md-3">
                        <div class="card h-100 text-center shadow-sm pv-about-stat">
                            <div class="card-body py-3">
                                <strong class="pv-stat-counter d-block" data-pv-count="2008">2008</strong>
                                <span class="small text-muted">成立年份</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100 text-center shadow-sm pv-about-stat">
                            <div class="card-body py-3">
                                <strong class="pv-stat-counter d-block" data-pv-count="3200" data-pv-suffix="+" data-pv-format="comma">0</strong>
                                <span class="small text-muted">服务客户</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100 text-center shadow-sm pv-about-stat">
                            <div class="card-body py-3">
                                <strong class="pv-stat-counter d-block" data-pv-count="120" data-pv-suffix="万+">0</strong>
                                <span class="small text-muted">在役设备</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card h-100 text-center shadow-sm pv-about-stat">
                            <div class="card-body py-3">
                                <strong class="pv-stat-counter d-block" data-pv-count="8" data-pv-suffix="%">0</strong>
                                <span class="small text-muted">研发投入占比</span>
                            </div>
                        </div>
                    </div>
                </div>

                <nav class="pv-about-float-nav mb-4" aria-label="本页导航">
                    <a href="#about-capability" title="核心能力">能力</a>
                    <a href="#about-history" title="发展历程">历程</a>
                    <a href="#about-cert" title="资质认证">资质</a>
                    <a href="#about-culture" title="企业文化">文化</a>
                </nav>

                {pv:if value="0" name="page_content_empty"}
                    <div class="pv-content pv-article-content pv-about-body">{$page_content|raw}</div>
                {/pv:if}

                <div class="card shadow-sm border-0 text-center pv-about-cta">
                    <div class="card-body p-4 p-lg-5">
                    <h2 class="pv-about-section__title pv-about-section__title--center mb-2">期待与您合作</h2>
                    <p class="text-secondary mb-3">产品选型、方案评估、商务合作或售后支持，欢迎随时与我们联系。</p>
                    <a href="{$url_contact}" class="btn btn-primary">联系我们</a>
                    </div>
                </div>
            </div>
        </main>

        {pv:include file="partials/footer"}
        </body>
    </html>

