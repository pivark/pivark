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
    <body class="pv-portal-site pv-home-portal pv-home-cinema">
        {pv:include file="partials/header"}
        <!-- #region 首页正文 -->
        <main class="pv-home-main" aria-label="首页正文">
        <!-- 区块：首页轮播（home_carousel · site_slides；无数据则不输出，禁止对访客暴露后台占位） -->
        {pv:if name="site_slides"}
            <section class="pv-home-banner pv-home-hero" aria-label="首页轮播">
                {* pause=false：鼠标停在大图上也不停轮，避免「幻灯片不会动」误判 *}
                <div id="pvHomeCarousel" class="carousel slide carousel-fade pv-home-hero__carousel" data-bs-ride="carousel" data-bs-interval="5000" data-bs-pause="false">
                    <div class="carousel-indicators pv-hero-indicators">
                        {pv:foreach name="site_slides" item="slide"}
                            <button type="button" data-bs-target="#pvHomeCarousel" data-bs-slide-to="{$slide.loop}" class="{$slide.active_class}" aria-label="幻灯 {$slide.index}"></button>
                        {/pv:foreach}
                    </div>
                    <div class="carousel-inner">
                        {pv:foreach name="site_slides" item="slide"}
                            <div class="carousel-item {$slide.active_class}">
                                <div class="pv-hero-slide" style="background-image:url('{$slide.image_url}')">
                                    <div class="container pv-hero-caption">
                                        <p class="pv-hero-tag">{$site_name}</p>
                                        <h1 class="pv-hero-title">{$slide.title}</h1>
                                        {pv:if empty="slide.subtitle"}{pv:else}<p class="pv-hero-lead">{$slide.subtitle}</p>{/pv:if}
                                        <div class="pv-hero-actions">
                                            {pv:if empty="slide.link_url"}{pv:else}
                                                <a href="{$slide.link_url}" class="btn btn-primary btn-lg">{$slide.link_text}</a>
                                            {/pv:if}
                                            <a href="{$url_contact}" class="btn btn-lg pv-hero-btn-ghost">选型咨询</a>
                                            <a href="tel:{$site_phone}" class="pv-hero-phone"><i class="bi bi-telephone"></i> {$site_phone}</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {/pv:foreach}
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#pvHomeCarousel" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                        <button class="carousel-control-next" type="button" data-bs-target="#pvHomeCarousel" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                    </div>
                    <span class="pv-hero-scroll" aria-hidden="true"><i class="bi bi-chevron-double-down"></i></span>
                </section>
            {/pv:if}

            <!-- 区块：快捷入口 -->
            <section class="pv-home-quick-nav pv-home-quick-nav--bar" aria-label="快捷入口">
                <div class="container-fluid px-0">
                    <div class="row row-cols-2 row-cols-md-5 g-0">
                        <div class="col">
                            <a class="pv-home-quick-nav__item d-flex flex-column align-items-center justify-content-center text-center text-decoration-none text-secondary border-end h-100 py-4 px-3" href="{$tag_url_pv_demo_cat_digital}">
                                <span class="pv-home-quick-nav__icon d-flex align-items-center justify-content-center mb-2" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
                                <span class="pv-home-quick-nav__label">测控仪器</span>
                            </a>
                        </div>
                        <div class="col">
                            <a class="pv-home-quick-nav__item d-flex flex-column align-items-center justify-content-center text-center text-decoration-none text-secondary border-end h-100 py-4 px-3" href="{$tag_url_pv_demo_cat_service}">
                                <span class="pv-home-quick-nav__icon d-flex align-items-center justify-content-center mb-2" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
                                <span class="pv-home-quick-nav__label">系统集成</span>
                            </a>
                        </div>
                        <div class="col">
                            <a class="pv-home-quick-nav__item d-flex flex-column align-items-center justify-content-center text-center text-decoration-none text-secondary border-end h-100 py-4 px-3" href="{$tag_url_pv_demo_cat_resource}">
                                <span class="pv-home-quick-nav__icon d-flex align-items-center justify-content-center mb-2" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                                <span class="pv-home-quick-nav__label">配件耗材</span>
                            </a>
                        </div>
                        <div class="col">
                            <a class="pv-home-quick-nav__item d-flex flex-column align-items-center justify-content-center text-center text-decoration-none text-secondary border-end h-100 py-4 px-3" href="{$tag_url_pv_demo_download}">
                                <span class="pv-home-quick-nav__icon d-flex align-items-center justify-content-center mb-2" aria-hidden="true"><i class="bi bi-cloud-arrow-down"></i></span>
                                <span class="pv-home-quick-nav__label">资料下载</span>
                            </a>
                        </div>
                        <div class="col">
                            <a class="pv-home-quick-nav__item d-flex flex-column align-items-center justify-content-center text-center text-decoration-none text-secondary h-100 py-4 px-3" href="{$url_contact}">
                                <span class="pv-home-quick-nav__icon d-flex align-items-center justify-content-center mb-2" aria-hidden="true"><i class="bi bi-headset"></i></span>
                                <span class="pv-home-quick-nav__label">选型咨询</span>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 区块：企业实力数据 -->
            <section class="pv-home-stats-band" aria-label="企业实力数据">
                <div class="container">
                    <div class="row row-cols-2 row-cols-md-4 g-4 g-md-5 text-center">
                        <div class="col">
                            <strong class="pv-stat-counter d-block" data-pv-count="120" data-pv-suffix="万+">0</strong>
                            <span class="text-muted">在役设备</span>
                        </div>
                        <div class="col">
                            <strong class="pv-stat-counter d-block" data-pv-count="3200" data-pv-suffix="+" data-pv-format="comma">0</strong>
                            <span class="text-muted">服务客户</span>
                        </div>
                        <div class="col">
                            <strong class="pv-stat-counter d-block" data-pv-count="48" data-pv-suffix="h">0</strong>
                            <span class="text-muted">现场响应</span>
                        </div>
                        <div class="col">
                            <strong class="pv-stat-counter d-block" data-pv-count="17" data-pv-suffix="年">0</strong>
                            <span class="text-muted">行业深耕</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 区块：关于我们 -->
            <section class="pv-home-block pv-home-about">
                <div class="container">
                    <div class="row g-4 g-xl-5 align-items-stretch">
                        <div class="col-lg-6 d-flex flex-column">
                            <div class="pv-home-about-visual flex-grow-1">
                                <img class="pv-home-about-visual__img" src="{$theme_asset}/images/gallery/02.jpg" alt="{$site_name} 工业测控现场" loading="lazy" width="640" height="400">
                                <div class="pv-home-about-visual__overlay">
                                    <div class="pv-home-about-visual__inner">
                                        <p class="pv-home-about-visual__quote">可靠 · 专业 · 开放 · 共赢</p>
                                        <p class="pv-home-about-visual__sub">流程工业测控与系统集成</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 d-flex flex-column">
                            <header class="pv-home-section-head pv-home-section-head--left mb-3">
                                <span class="pv-home-section-label">About Us</span>
                                <h2>关于我们</h2>
                            </header>
                            <h3 class="pv-home-about-kicker">{$site_name} 产品与服务概览</h3>
                            <div class="pv-home-about-intro">
                                <p>华仪智控以<strong>智能传感、现场仪表、数据采集与系统集成</strong>为主线，为石化、电力、冶金、水处理等行业客户提供从单机选型到整厂改造的可交付方案。产品中心可按分类浏览在售型号，支持按产品线、输出信号与精度等级组合筛选。</p>
                                <p class="mb-0">我们坚持「可靠、专业、开放、共赢」：产品设计遵循 IEC 标准，制造环节 MES 全程追溯，出厂 100% 标定。无论您需要一台变送器还是一套能源平台，均可获得一致的技术响应与售后保障。</p>
                            </div>
                            <div class="pv-home-about-actions">
                                <a href="{$home_about_url}" class="btn btn-primary btn-lg">了解更多</a>
                                <a href="{$url_contact}" class="btn btn-lg btn-outline-secondary">联系我们</a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 区块：典型行业应用 -->
            <section class="section pv-home-spotlight pv-home-spotlight--alt">
                <div class="container">
                    <section class="pv-product-applications mb-5" aria-label="行业应用">
                        <header class="pv-home-section-head pv-home-section-head--center pv-home-section-head--spotlight">
                            <div class="pv-home-section-head__main">
                                <span class="pv-home-section-label">Applications</span>
                                <h2>典型行业应用</h2>
                                <p class="pv-home-section-desc">华仪智控产品与方案已在下列行业批量应用，积累从单机仪表到整厂测控的交付经验。</p>
                            </div>
                        </header>
                        <div class="row g-3 g-md-4">
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-droplet-half fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">石化化工</strong>
                                    <span class="small text-muted">压力/流量/液位测控，防爆区仪表与数据记录</span>
                                    </div>
                                </article>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-lightning-charge fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">电力能源</strong>
                                    <span class="small text-muted">锅炉汽水参数监测，无纸记录仪与 DCS 对接</span>
                                    </div>
                                </article>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-fire fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">冶金建材</strong>
                                    <span class="small text-muted">高温工况变送器，炉窑温度采集与趋势分析</span>
                                    </div>
                                </article>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-water fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">水处理</strong>
                                    <span class="small text-muted">液位开关与电磁流量计，加药与泵站监控</span>
                                    </div>
                                </article>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-capsule fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">制药食品</strong>
                                    <span class="small text-muted">审计追溯记录仪，卫生型过程连接与校准</span>
                                    </div>
                                </article>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <article class="card h-100 shadow-sm text-center pv-product-app-card">
                                    <div class="card-body py-3">
                                    <i class="bi bi-gear-wide-connected fs-3 text-primary mb-2" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">装备制造</strong>
                                    <span class="small text-muted">产线测控改造，PLC 组网与能源管理平台</span>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </section>
                </div>
            </section>

            <!-- 区块：产品展示（优先 home_product_nav_id 栏目；无栏目时回落 Tag） -->
            {pv:if name="product_front_open"}
            {pv:if name="home_product_has"}
            <section class="pv-home-block pv-home-block--alt pv-home-products-section">
                <div class="container">
                    <header class="pv-home-section-head pv-home-section-head--center pv-home-section-head--products">
                        <div class="pv-home-section-head__main">
                            <span class="pv-home-section-label">Products</span>
                            <h2>产品中心</h2>
                            <p class="pv-home-section-desc">在售型号一览，支持按分类、输出信号与精度等级筛选检索。</p>
                        </div>
                        <nav class="nav nav-pills flex-wrap justify-content-center pv-product-subnav pv-product-subnav--home" aria-label="产品分类">
                            <a class="nav-link active" href="{$url_product_catalog}">全部产品</a>
                            {pv:foreach name="product_catalog_tags" item="cat"}
                                <a class="nav-link" href="{$cat.url}">{$cat.name}</a>
                            {/pv:foreach}
                        </nav>
                    </header>
                    <div class="row g-4 g-md-5">
                        {pv:arclist entity="product" navid="{$home_product_nav_id}" row="6"}
                        <div class="col-12 col-sm-6 col-lg-4">
                            <article class="card h-100 shadow-sm pv-home-product-card">
                                <a class="card-body text-decoration-none text-body d-block pv-home-product" href="{$field.card_url}">
                                    <div class="pv-home-product-img mb-2">
                                        {pv:if empty="field.litpic"}
                                            <span class="pv-home-ph"><i class="bi bi-box-seam"></i></span>
                                        {pv:else}
                                            <img src="{$field.litpic}" alt="{$field.name}" loading="lazy">
                                        {/pv:if}
                                    </div>
                                    <h3 class="h6 card-title mb-1">{$field.name}</h3>
                                    <p class="card-text small text-muted mb-0">型号：{$field.model}</p>
                                </a>
                            </article>
                        </div>
                        {/pv:arclist}
                    </div>
                    <div class="text-center mt-5">
                        <a href="{$url_product_catalog}" class="btn btn-primary btn-lg">浏览全部产品</a>
                    </div>
                </div>
            </section>
            {/pv:if}
            {/pv:if}

            <!-- 区块：服务流程 -->
            <section class="section pv-home-spotlight">
                <div class="container">
                    <section class="pv-product-flow mb-5" aria-label="服务流程">
                        <header class="pv-home-section-head pv-home-section-head--center pv-home-section-head--spotlight">
                            <div class="pv-home-section-head__main">
                                <span class="pv-home-section-label">Service</span>
                                <h2>从选型到运维的服务流程</h2>
                                <p class="pv-home-section-desc">我们提供贯穿项目全生命周期的技术支持，确保测控系统长期稳定运行。</p>
                            </div>
                        </header>
                        <ol class="row g-3 g-md-4 list-unstyled mb-0 pv-product-flow__steps">
                            <li class="col-md-6 col-lg-3">
                                <article class="card h-100 shadow-sm">
                                <div class="card-body">
                                <span class="pv-product-flow__num">01</span>
                                <strong class="d-block mb-2">工况沟通与选型</strong>
                                <p class="small text-muted mb-0">应用工程师了解介质、量程、安装环境与认证要求，推荐型号并出具选型建议书。</p>
                                </div>
                                </article>
                            </li>
                            <li class="col-md-6 col-lg-3">
                                <article class="card h-100 shadow-sm">
                                <div class="card-body">
                                <span class="pv-product-flow__num">02</span>
                                <strong class="d-block mb-2">方案设计与报价</strong>
                                <p class="small text-muted mb-0">项目型需求提供系统架构、机柜清单与交期评估；标准品提供含税报价与供货周期。</p>
                                </div>
                                </article>
                            </li>
                            <li class="col-md-6 col-lg-3">
                                <article class="card h-100 shadow-sm">
                                <div class="card-body">
                                <span class="pv-product-flow__num">03</span>
                                <strong class="d-block mb-2">供货与现场调试</strong>
                                <p class="small text-muted mb-0">产品出厂标定附带证书；系统集成项目含 FAT/SAT、组态下载与 operator 培训。</p>
                                </div>
                                </article>
                            </li>
                            <li class="col-md-6 col-lg-3">
                                <article class="card h-100 shadow-sm">
                                <div class="card-body">
                                <span class="pv-product-flow__num">04</span>
                                <strong class="d-block mb-2">运维与备件保障</strong>
                                <p class="small text-muted mb-0">7×12 远程支持，协议客户 48 小时现场响应；备件库与年度巡检可选。</p>
                                </div>
                                </article>
                            </li>
                        </ol>
                    </section>
                </div>
            </section>

            <!-- 区块：为什么选择我们 -->
            <section class="section pv-home-spotlight pv-home-spotlight--alt">
                <div class="container">
                    <section class="pv-product-strengths pv-product-strengths--rich" aria-label="企业实力">
                        <header class="pv-home-section-head pv-home-section-head--center pv-home-section-head--spotlight">
                            <div class="pv-home-section-head__main">
                                <span class="pv-home-section-label">Why Us</span>
                                <h2>为什么选择华仪智控</h2>
                                <p class="pv-home-section-desc">从芯体设计到系统集成，从出厂标定到运维保障 — 我们以可验证的制造能力与服务网络，成为流程工业客户长期信赖的测控伙伴。</p>
                            </div>
                        </header>
                        <div class="row g-3 g-md-4">
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-building-gear fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">研发生产实力</strong>
                                    <span class="small text-muted">上海临港与苏州双制造基地，MES 全程追溯</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-people fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">专业技术团队</strong>
                                    <span class="small text-muted">博士后工作站，应用工程师驻场支持</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-shield-check fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">严格质量控制</strong>
                                    <span class="small text-muted">ISO 9001 · 100% 出厂标定与证书</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-headset fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">选型技术支持</strong>
                                    <span class="small text-muted">7×12 热线 · 48h 协议客户现场响应</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-sliders fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">定制方案能力</strong>
                                    <span class="small text-muted">非标量程、防爆与 SIL 认证可定制</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <div class="card h-100 shadow-sm text-center pv-product-strength">
                                    <div class="card-body py-3">
                                    <i class="bi bi-tools fs-3 text-primary mb-2 pv-product-strength__icon" aria-hidden="true"></i>
                                    <strong class="d-block mb-1">完善售后保障</strong>
                                    <span class="small text-muted">区域服务中心 · 备件库 · 年度巡检</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </section>

            <!-- 区块：选型咨询 CTA -->
            <section class="pv-home-cta-band-wrap">
                <div class="container">
                    <section class="card shadow-sm border-0 pv-product-cta-band" aria-label="联系与资料">
                        <div class="card-body p-4 p-lg-5">
                        <div class="row g-4 align-items-center">
                            <div class="col-lg-8">
                                <h2 class="h5 mb-2">需要选型帮助或项目方案？</h2>
                                <p class="mb-0 text-secondary">请留下工况参数与联系方式，应用工程师将在 <strong>1 个工作日</strong>内回复。也可拨打全国统一服务热线 <strong>400-800-6688</strong>（7×12 小时），或前往<a href="{$tag_url_pv_demo_download}">资料下载</a>获取产品手册与 CAD 图纸。</p>
                            </div>
                            <div class="col-lg-4 d-flex flex-wrap gap-2 justify-content-lg-end">
                                <a class="btn btn-primary" href="{$url_contact}">联系应用工程师</a>
                                <a class="btn btn-outline-primary" href="{$tag_url_pv_demo_download}">下载产品手册</a>
                            </div>
                        </div>
                        </div>
                    </section>
                </div>
            </section>
            <!-- 区块：新闻动态（独立大块） -->
            {pv:if name="home_news_has"}
            <section class="pv-home-block pv-home-news-panel">
                <div class="container">
                    <header class="pv-home-section-head pv-home-section-head--row">
                        <div class="pv-home-section-head__main">
                            <span class="pv-home-section-label">News</span>
                            <h2>新闻动态</h2>
                            <p class="pv-home-section-desc">公司资讯、项目动态与行业观察</p>
                        </div>
                        <a href="{$home_news_url}" class="pv-home-more pv-home-more--pill">更多资讯 <i class="bi bi-chevron-right"></i></a>
                    </header>
                    <div class="row g-4 g-xl-5 align-items-stretch pv-home-news-layout">
                        <div class="col-lg-7">
                            {pv:arclist navid="{$home_news_nav_id}" row="1" orderby="new"}
                            <article class="card h-100 shadow-sm border-0 overflow-hidden pv-home-news-feature">
                                <a class="ratio ratio-16x9 bg-light d-block overflow-hidden pv-home-news-feature__media" href="{$field.url}">
                                    {pv:if empty="field.litpic"}
                                        <span class="pv-home-ph"><i class="bi bi-newspaper"></i></span>
                                    {pv:else}
                                        <img src="{$field.litpic}" alt="{$field.title}" loading="lazy">
                                    {/pv:if}
                                </a>
                                <div class="card-body pv-home-news-feature__body">
                                    <p class="pv-home-news-date"><time>{$field.published_date}</time></p>
                                    <h3 class="h4 card-title mb-2"><a class="text-decoration-none text-body" href="{$field.url}">{$field.title}</a></h3>
                                    <p class="pv-home-news-excerpt mb-3">{$field.excerpt_short}</p>
                                    <a href="{$field.url}" class="btn btn-link btn-sm p-0 pv-home-duo-read">阅读全文 <i class="bi bi-arrow-right"></i></a>
                                </div>
                            </article>
                            {/pv:arclist}
                        </div>
                        <div class="col-lg-5">
                            <ul class="list-unstyled mb-0 pv-home-news-stack">
                                {pv:arclist navid="{$home_news_nav_id}" row="4" offset="1" orderby="new"}
                                <li class="pv-home-news-stack__item">
                                    <a class="pv-home-news-stack__link" href="{$field.url}">
                                        <span class="pv-home-news-stack__thumb" aria-hidden="true">
                                            {pv:if empty="field.litpic"}
                                                <span class="pv-home-ph"><i class="bi bi-image"></i></span>
                                            {pv:else}
                                                <img src="{$field.litpic}" alt="" loading="lazy">
                                            {/pv:if}
                                        </span>
                                        <span class="pv-home-news-stack__body">
                                            <time class="pv-home-news-stack__date">{$field.published_date}</time>
                                            <strong class="pv-home-news-stack__title">{$field.title}</strong>
                                            <span class="pv-home-news-stack__excerpt">{$field.excerpt_short}</span>
                                        </span>
                                    </a>
                                </li>
                                {/pv:arclist}
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
            {/pv:if}

            <!-- 区块：资料下载 + 产品视频（左右合块） -->
            {pv:if name="home_dl_video_has"}
            <section class="pv-home-block pv-home-dl-video-panel">
                <div class="container">
                    <div class="card shadow-sm overflow-hidden border-0 pv-home-duo-panel">
                        <div class="row g-0">
                            {pv:if name="home_download_has"}
                            <div class="col-lg-6 pv-home-duo-col pv-home-duo-col--download">
                                <header class="pv-home-duo-head">
                                    <div class="pv-home-section-head__main">
                                        <span class="pv-home-section-label">Downloads</span>
                                        <h2>资料下载</h2>
                                    </div>
                                    <a href="{$home_download_url}" class="pv-home-more">下载中心 <i class="bi bi-chevron-right"></i></a>
                                </header>
                                <ol class="pv-rank-list pv-rank-list--stretch">
                                    {pv:arclist navid="{$home_download_nav_id}" row="6" orderby="hot"}
                                    <li>
                                        <a class="pv-rank-list__link" href="{$field.url}">
                                            <span class="pv-rank-list__num" aria-hidden="true"></span>
                                            <span class="pv-rank-list__body">
                                                <strong>{$field.title}</strong>
                                                <span class="pv-rank-list__meta">
                                                    <i class="bi bi-cloud-arrow-down"></i>
                                                    <em>{$field.click}</em> 次下载
                                                </span>
                                            </span>
                                            <i class="bi bi-chevron-right pv-rank-list__go"></i>
                                        </a>
                                    </li>
                                    {/pv:arclist}
                                </ol>
                            </div>
                            {/pv:if}
                            {pv:if name="home_video_has"}
                            <div class="col-lg-6 pv-home-duo-col pv-home-duo-col--video">
                                <header class="pv-home-duo-head">
                                    <div class="pv-home-section-head__main">
                                        <span class="pv-home-section-label">Video</span>
                                        <h2>产品视频</h2>
                                    </div>
                                    <a href="{$home_video_url}" class="pv-home-more">更多视频 <i class="bi bi-chevron-right"></i></a>
                                </header>
                                <div class="row g-3 pv-home-duo-videos">
                                    {pv:arclist navid="{$home_video_nav_id}" row="4" orderby="new"}
                                    <div class="col-6">
                                        <article class="card h-100 border-0 shadow-sm overflow-hidden pv-home-video-card pv-home-video-card--compact">
                                            <a class="text-decoration-none text-body d-block h-100 pv-home-video" href="{$field.url}">
                                                <div class="ratio ratio-16x9 bg-light overflow-hidden position-relative pv-home-video-thumb">
                                                    {pv:if empty="field.litpic"}
                                                        <span class="pv-home-ph"><i class="bi bi-play-circle"></i></span>
                                                    {pv:else}
                                                        <img class="pv-home-video-thumb__img" src="{$field.litpic}" alt="{$field.title}" loading="lazy">
                                                    {/pv:if}
                                                    <span class="pv-home-video-play"><i class="bi bi-play-fill"></i></span>
                                                </div>
                                                <div class="card-body py-2 px-2">
                                                    <h3 class="h6 card-title mb-0">{$field.title}</h3>
                                                </div>
                                            </a>
                                        </article>
                                    </div>
                                    {/pv:arclist}
                                </div>
                            </div>
                            {/pv:if}
                        </div>
                    </div>
                </div>
            </section>
            {/pv:if}


            <!-- 区块：合作伙伴（无友链整块隐藏；禁对访客露后台运营文案） -->
            {pv:if name="friend_links"}
            <section class="pv-home-block pv-home-friends pv-home-partners-section pv-home-block--alt">
                <div class="container">
                    <header class="pv-home-section-head pv-home-section-head--center">
                        <div class="pv-home-section-head__main">
                            <span class="pv-home-section-label">Partners</span>
                            <h2>合作伙伴</h2>
                            <p class="pv-home-section-desc">与通信、能源、工业龙头及行业机构建立长期合作</p>
                        </div>
                    </header>
                    <div class="pv-home-partner-marquee" aria-label="合作伙伴 Logo 滚动">
                        <div class="pv-home-partner-marquee__track">
                            <div class="pv-home-partner-marquee__set">
                                {pv:friendlinks row="24"}
                                <a href="{$field.url}" target="{$field.link_target}" rel="noopener noreferrer" class="card border-0 shadow-sm pv-home-partner-card" title="{$field.title}">
                                    <span class="pv-home-partner-card__media">
                                        <img src="{$field.logo_url}" alt="{$field.title}" loading="lazy" class="pv-home-partner-card__logo" width="160" height="56">
                                        <span class="pv-home-partner-card__ph" aria-hidden="true">{$field.card_initials}</span>
                                    </span>
                                </a>
                                {/pv:friendlinks}
                            </div>
                            <div class="pv-home-partner-marquee__set" aria-hidden="true">
                                {pv:friendlinks row="24"}
                                <a href="{$field.url}" target="{$field.link_target}" rel="noopener noreferrer" class="card border-0 shadow-sm pv-home-partner-card" tabindex="-1">
                                    <span class="pv-home-partner-card__media">
                                        <img src="{$field.logo_url}" alt="" loading="lazy" class="pv-home-partner-card__logo" width="160" height="56">
                                        <span class="pv-home-partner-card__ph" aria-hidden="true">{$field.card_initials}</span>
                                    </span>
                                </a>
                                {/pv:friendlinks}
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            {/pv:if}
        </main>
        <!-- #endregion -->

        {pv:include file="partials/footer"}
    </body>
</html>
