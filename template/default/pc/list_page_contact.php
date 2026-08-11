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
    <body class="pv-portal-site pv-contact-page">
        {pv:include file="partials/header"}

        <!-- 区块：联系页首屏（标题 + 关键联系方式） -->
        <section class="pv-contact-hero" aria-label="联系我们">
            <div class="pv-contact-hero__bg" aria-hidden="true"></div>
            <div class="container pv-contact-hero__inner">
                <div class="row g-4 g-xl-5 align-items-stretch">
                    <div class="col-lg-6">
                        <div class="pv-contact-hero__copy">
                            <span class="pv-home-section-label">Contact</span>
                            <h1 class="pv-contact-hero__title">{$page_title}</h1>
                            <p class="pv-contact-hero__lead">产品咨询、方案评估、商务合作与售后支持，我们会在 1 个工作日内回复。</p>
                            <div class="pv-contact-hero__actions">
                                <a class="btn btn-primary btn-lg" href="tel:{$site_phone}">
                                    <i class="bi bi-telephone-fill" aria-hidden="true"></i>
                                    拨打热线
                                </a>
                                <a class="btn btn-outline-primary btn-lg" href="#pv-contact-form">在线留言</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <aside class="pv-contact-hero-panel" aria-label="总部联系方式">
                            <p class="pv-contact-hero-panel__kicker">上海总部</p>
                            <ul class="pv-contact-hero-panel__list">
                                <li>
                                    <span class="pv-contact-hero-panel__icon" aria-hidden="true"><i class="bi bi-telephone-fill"></i></span>
                                    <div>
                                        <span class="pv-contact-hero-panel__label">服务热线</span>
                                        <strong><a href="tel:{$site_phone}">{$site_phone}</a></strong>
                                    </div>
                                </li>
                                <li>
                                    <span class="pv-contact-hero-panel__icon" aria-hidden="true"><i class="bi bi-clock-fill"></i></span>
                                    <div>
                                        <span class="pv-contact-hero-panel__label">工作时间</span>
                                        <strong>工作日 8:30 — 17:30</strong>
                                    </div>
                                </li>
                                <li>
                                    <span class="pv-contact-hero-panel__icon" aria-hidden="true"><i class="bi bi-envelope-fill"></i></span>
                                    <div>
                                        <span class="pv-contact-hero-panel__label">商务邮箱</span>
                                        <strong><a href="mailto:{$site_email}">{$site_email}</a></strong>
                                    </div>
                                </li>
                                <li>
                                    <span class="pv-contact-hero-panel__icon" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></span>
                                    <div>
                                        <span class="pv-contact-hero-panel__label">公司地址</span>
                                        <strong>{$site_address}</strong>
                                    </div>
                                </li>
                            </ul>
                        </aside>
                    </div>
                </div>
            </div>
        </section>

        <!-- 区块：联系页主内容 -->
        <main aria-label="联系我们正文" class="pv-channel-main pv-contact-main">
            <div class="container pv-contact-container">

                <!-- 区块：留言表单 + 服务说明 -->
                <section class="pv-contact-block pv-contact-block--main" id="pv-contact-form" aria-label="留言与服务说明">
                    <header class="pv-contact-block__head">
                        <p class="pv-contact-block__kicker">在线提交</p>
                        <h2 class="pv-contact-block__title">在线留言</h2>
                        <p class="pv-contact-block__desc">留下联系方式与需求，我们将安排对口工程师跟进</p>
                    </header>

                    <div class="row g-4 align-items-stretch">
                        <div class="col-lg-8 pv-contact-layout__form">
                            <div class="card shadow-sm pv-contact-form-panel">
                                <div class="card-body">
                                {pv:form slug="contact" class="pv-contact-form pv-contact-form__grid" title="0"}
                                <p class="pv-form-msg small" hidden role="status"></p>
                                </div>
                            </div>
                        </div>

                        <aside class="col-lg-4 pv-contact-layout__aside" aria-label="服务说明">
                            <div class="pv-contact-aside-stack d-flex flex-column gap-4">
                                <div class="card shadow-sm overflow-hidden pv-contact-aside-card">
                                    <div class="pv-contact-aside-card__section">
                                        <h3 class="pv-contact-aside-card__title">我们能帮您</h3>
                                        <ul class="pv-contact-services__list">
                                            <li class="pv-contact-service">
                                                <span class="pv-contact-service__icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                                                <div>
                                                    <strong>产品咨询</strong>
                                                    <p>型号选型、参数确认、供货周期与报价</p>
                                                </div>
                                            </li>
                                            <li class="pv-contact-service">
                                                <span class="pv-contact-service__icon" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                                                <div>
                                                    <strong>方案合作</strong>
                                                    <p>项目评估、系统集成与批量采购</p>
                                                </div>
                                            </li>
                                            <li class="pv-contact-service">
                                                <span class="pv-contact-service__icon" aria-hidden="true"><i class="bi bi-tools"></i></span>
                                                <div>
                                                    <strong>售后支持</strong>
                                                    <p>安装调试、维修保养与备件服务</p>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                {pv:if name="site_wechat_qr"}
                                <div class="card shadow-sm overflow-hidden pv-contact-aside-card">
                                    <div class="pv-contact-aside-card__section">
                                        <h3 class="pv-contact-aside-card__title">微信沟通</h3>
                                        <div class="pv-contact-qr">
                                            <figure class="pv-contact-qr__item">
                                                <img src="{$site_wechat_qr}" alt="官方微信二维码" width="96" height="96" loading="lazy" decoding="async">
                                                <figcaption>官方微信</figcaption>
                                            </figure>
                                            {pv:if name="site_wechat_mp_qr"}
                                            <figure class="pv-contact-qr__item">
                                                <img src="{$site_wechat_mp_qr}" alt="公众号二维码" width="96" height="96" loading="lazy" decoding="async">
                                                <figcaption>公众号</figcaption>
                                            </figure>
                                            {/pv:if}
                                        </div>
                                    </div>
                                </div>
                                {pv:else}
                                {pv:if name="site_wechat_mp_qr"}
                                <div class="card shadow-sm overflow-hidden pv-contact-aside-card">
                                    <div class="pv-contact-aside-card__section">
                                        <h3 class="pv-contact-aside-card__title">微信沟通</h3>
                                        <div class="pv-contact-qr">
                                            <figure class="pv-contact-qr__item">
                                                <img src="{$site_wechat_mp_qr}" alt="公众号二维码" width="96" height="96" loading="lazy" decoding="async">
                                                <figcaption>公众号</figcaption>
                                            </figure>
                                        </div>
                                    </div>
                                </div>
                                {/pv:if}
                                {/pv:if}
                            </div>
                        </aside>
                    </div>
                </section>

                <!-- 区块：区域服务中心 -->
                <section class="pv-contact-block pv-contact-block--offices" aria-label="区域服务中心">
                    <header class="pv-contact-block__head">
                        <p class="pv-contact-block__kicker">全国服务网络</p>
                        <h2 class="pv-contact-block__title">区域服务中心</h2>
                        <p class="pv-contact-block__desc">华东、华北、华南、西南设有服务网点，可预约区域工程师上门</p>
                    </header>
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3">
                        <div class="col">
                            <div class="card h-100 pv-contact-office-tile">
                                <div class="card-body">
                                    <span class="pv-contact-office-tile__region" aria-hidden="true">华东</span>
                                    <strong class="pv-contact-office-tile__name">华东大区</strong>
                                    <p class="pv-contact-office-tile__cities">上海 · 苏州 · 杭州</p>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 pv-contact-office-tile">
                                <div class="card-body">
                                    <span class="pv-contact-office-tile__region" aria-hidden="true">华北</span>
                                    <strong class="pv-contact-office-tile__name">华北大区</strong>
                                    <p class="pv-contact-office-tile__cities">北京 · 天津 · 石家庄</p>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 pv-contact-office-tile">
                                <div class="card-body">
                                    <span class="pv-contact-office-tile__region" aria-hidden="true">华南</span>
                                    <strong class="pv-contact-office-tile__name">华南大区</strong>
                                    <p class="pv-contact-office-tile__cities">深圳 · 广州 · 厦门</p>
                                </div>
                            </div>
                        </div>
                        <div class="col">
                            <div class="card h-100 pv-contact-office-tile">
                                <div class="card-body">
                                    <span class="pv-contact-office-tile__region" aria-hidden="true">西南</span>
                                    <strong class="pv-contact-office-tile__name">西南大区</strong>
                                    <p class="pv-contact-office-tile__cities">成都 · 重庆 · 昆明</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {pv:if name="site_map_enabled"}
                    {pv:if value="0" name="site_map_empty"}
                        <!-- 区块：公司位置地图 -->
                        <section class="card shadow-sm pv-contact-block pv-contact-block--map" aria-label="公司位置地图">
                            <div class="card-body">
                            <header class="pv-contact-block__head pv-contact-block__head--row">
                                <div>
                                    <h2 class="pv-contact-block__title">来访导航</h2>
                                    <p class="pv-contact-block__desc mb-0">欢迎预约到访，建议提前电话联系前台</p>
                                </div>
                                {pv:if empty="site_map_nav_url"}{pv:else}
                                    <a class="btn btn-sm btn-outline-primary" href="{$site_map_nav_url}" target="_blank" rel="noopener noreferrer">在高德地图中打开</a>
                                {/pv:if}
                            </header>
                                                        <!-- 不嵌高德整页 iframe（弹层干扰）；仅保留外链导航 -->

                            {pv:if empty="site_map_note_empty"}
                                <p class="pv-contact-map-note mb-0">{$site_map_note}</p>
                            {/pv:if}
                            </div>
                        </section>
                    {/pv:if}
                {/pv:if}
            </div>
        </main>

        <script src="{$theme_asset}/js/demo-contact-form.js?v={$theme_asset_ver}" defer></script>

        {pv:include file="partials/footer"}
        </body>
    </html>
