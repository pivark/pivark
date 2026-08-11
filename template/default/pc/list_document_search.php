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
<body class="pv-portal-site pv-channel-search">
    {pv:include file="partials/header"}

    <!-- 区块：搜索 Hero（对齐迅通：标题为「搜索：关键词」，搜框在侧栏） -->
    <section class="pv-channel-hero pv-portal-page-hero pv-portal-hero--center pv-search-hero" aria-label="站内搜索">
        <div class="container">
            <h1 class="pv-channel-hero-title">
                {pv:if name="search_keyword"}搜索：{$search_keyword}{pv:else}站内搜索{/pv:if}
            </h1>
            {pv:if name="search_keyword"}
                <p class="pv-channel-hero-desc">搜索「{$search_keyword}」相关的内容</p>
            {pv:else}
                <p class="pv-channel-hero-desc">输入型号、资料名或问题关键词，可在右侧继续筛选浏览</p>
            {/pv:if}

            {pv:if name="search_session_bar.show"}
            <div class="pv-search-session" role="status">
                <span class="pv-search-session__label">当前条件</span>
                {pv:if name="search_session_bar.keyword"}
                    <span class="badge text-bg-secondary">{$search_session_bar.keyword}</span>
                {/pv:if}
                {pv:foreach name="search_session_bar.filters" item="sf"}
                    <span class="badge text-bg-primary">{$sf.label}={$sf.value}</span>
                {/pv:foreach}
                <span class="pv-search-session__actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{$search_session_bar.clear_url}">清除条件</a>
                    {pv:if name="search_append_hint"}
                        <span class="small text-muted">{$search_append_hint}</span>
                    {/pv:if}
                </span>
            </div>
            {/pv:if}

            {pv:if name="smart_filter_chips"}
            <div class="pv-search-chips" aria-label="已选筛选">
                {pv:foreach name="smart_filter_chips" item="chip"}
                    <a class="pv-search-chip pv-search-chip--active" href="{$chip.url}">{$chip.label}：{$chip.value} ×</a>
                {/pv:foreach}
                {pv:if name="smart_catalog_url"}
                    <a class="pv-search-chip" href="{$smart_catalog_url}">查看筛选列表</a>
                {/pv:if}
            </div>
            {/pv:if}
        </div>
    </section>

    {pv:include file="partials/breadcrumb"}

    <main aria-label="搜索结果" class="pv-search-main pv-portal-main">
        <div class="container">
            <div class="row g-3 g-lg-4">
                <div class="col-lg-9 pv-search-col-main">

                    <script>
                        (function () {
                            var input = document.querySelector('.pv-search-sidebar-form input[name="q"], .pv-search-form input[name="q"]');
                            if (!input) return;
                            var field = input.closest('.pv-search-form__field') || input.parentElement;
                            var box = document.createElement('div');
                            box.className = 'pv-search-suggest list-group shadow-sm';
                            box.setAttribute('role', 'listbox');
                            field.style.position = 'relative';
                            field.appendChild(box);
                            var timer = null;
                            function esc(s) {
                                return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
                            }
                            function hide() { box.style.display = 'none'; }
                            function pick(q) {
                                input.value = q;
                                hide();
                                input.form && input.form.submit();
                            }
                            input.addEventListener('input', function () {
                                var q = input.value.trim();
                                clearTimeout(timer);
                                if (q.length < 1) { hide(); return; }
                                timer = setTimeout(function () {
                                    fetch(pvUrl('apiSearchSuggest', '/api/v1/search/suggest') + '?q=' + encodeURIComponent(q))
                                        .then(function (r) { return r.json(); })
                                        .then(function (res) {
                                            var list = (res && res.data && res.data.list) ? res.data.list : [];
                                            if (!list.length) { hide(); return; }
                                            box.innerHTML = list.map(function (item) {
                                                var t = typeof item === 'string' ? item : (item.text || item.q || '');
                                                return '<button type="button" class="list-group-item list-group-item-action py-2" role="option">' + esc(t) + '</button>';
                                            }).join('');
                                            box.style.display = 'block';
                                            Array.prototype.forEach.call(box.querySelectorAll('button'), function (btn, i) {
                                                btn.onclick = function () {
                                                    pick(list[i] && typeof list[i] === 'string' ? list[i] : (list[i].text || list[i].q || ''));
                                                };
                                            });
                                        }).catch(hide);
                                }, 220);
                            });
                            document.addEventListener('click', function (e) {
                                if (!box.contains(e.target) && e.target !== input) hide();
                            });
                            var kw = input.value || '';
                            function logClick(type, id, url) {
                                if (!kw) return;
                                var body = new URLSearchParams({ q: kw, type: type || 'link', id: String(id || 0), url: url || '' });
                                if (navigator.sendBeacon) {
                                    navigator.sendBeacon(pvUrl('apiSearchClick', '/api/v1/search/click'), body);
                                } else {
                                    fetch(pvUrl('apiSearchClick', '/api/v1/search/click'), { method: 'POST', body: body, credentials: 'same-origin' });
                                }
                            }
                            document.querySelectorAll('.pv-search-products a[href], .pv-smart-search a[href], .pv-search-hit a[href]').forEach(function (a) {
                                a.addEventListener('click', function () {
                                    var id = parseInt(a.getAttribute('data-pv-item-id') || '0', 10) || 0;
                                    var type = a.closest('.pv-search-products') ? 'product' : 'document';
                                    logClick(type, id, a.getAttribute('href') || '');
                                });
                            });
                        })();
                    </script>

                    <script>
                        (function () {
                            var form = document.querySelector('.pv-search-sidebar-form, .pv-search-form');
                            var input = form ? form.querySelector('input[name="q"]') : null;
                            if (!form || !input) return;

                            function esc(s) {
                                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                            }

                            function productSkeletonCard() {
                                return '<div class="pv-search-product-card pv-search-product-card--row pv-search-product-card--skeleton" aria-hidden="true">'
                                    + '<div class="pv-search-product-card__media"><div class="pv-skeleton-block pv-skeleton-block--cover"></div></div>'
                                    + '<div class="pv-search-product-card__body">'
                                    + '<div class="pv-skeleton-block pv-skeleton-block--title"></div>'
                                    + '<div class="pv-skeleton-block pv-skeleton-block--line mt-2"></div>'
                                    + '<div class="pv-skeleton-block pv-skeleton-block--line mt-2"></div>'
                                    + '</div></div>';
                            }

                            function docSkeletonRow() {
                                return '<div class="pv-search-hit pv-search-hit--skeleton" aria-hidden="true">'
                                    + '<div class="pv-search-hit__thumb"><div class="pv-skeleton-block pv-skeleton-block--cover"></div></div>'
                                    + '<div class="pv-search-hit__body">'
                                    + '<div class="pv-skeleton-block pv-skeleton-block--title mb-2"></div>'
                                    + '<div class="pv-skeleton-block pv-skeleton-block--line"></div>'
                                    + '</div></div>';
                            }

                            function pendingHtml(keyword) {
                                var html = '<section class="pv-search-results pv-search-results--pending" aria-busy="true" aria-live="polite">';
                                html += '<header class="pv-search-results__head"><p class="search-count mb-0">正在搜索「' + esc(keyword) + '」…</p></header>';
                                html += '<div class="pv-search-pending-smart pv-skeleton-block mb-4"></div>';
                                html += '<div class="pv-search-products__list mb-4">';
                                html += productSkeletonCard() + productSkeletonCard();
                                html += '</div><div class="pv-search-hits">';
                                html += docSkeletonRow() + docSkeletonRow() + docSkeletonRow();
                                html += '</div></section>';
                                return html;
                            }

                            function showPendingSkeleton(keyword) {
                                var existing = document.querySelector('.pv-search-results');
                                if (existing) existing.classList.add('d-none');
                                document.querySelectorAll('.pv-search-empty-panel, .pv-search-browse').forEach(function (el) {
                                    el.classList.add('d-none');
                                });
                                var host = document.getElementById('pv-search-pending-host');
                                if (!host) {
                                    host = document.createElement('div');
                                    host.id = 'pv-search-pending-host';
                                    var col = document.querySelector('.pv-search-col-main');
                                    if (col) col.appendChild(host);
                                }
                                host.innerHTML = pendingHtml(keyword);
                            }

                            form.addEventListener('submit', function () {
                                var keyword = (input.value || '').trim();
                                if (!keyword) return;
                                showPendingSkeleton(keyword);
                            });
                        })();
                    </script>

                    {pv:if empty="search_keyword"}
                    <section class="pv-search-browse" aria-label="开始浏览">
                        <div class="pv-search-browse__intro">
                            <h2 class="pv-search-browse__title">还没想好关键词？</h2>
                            <p class="pv-search-browse__text">可先按分类进入频道浏览，或点热门词直接搜。</p>
                        </div>
                        <div class="row g-3 pv-search-browse__cards">
                            <div class="col-sm-6">
                                <a class="pv-search-browse-card" href="{$url_product_catalog}">
                                    <span class="pv-search-browse-card__icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                                    <span class="pv-search-browse-card__body">
                                        <span class="pv-search-browse-card__name">产品中心</span>
                                        <span class="pv-search-browse-card__desc">按型号与参数筛选品项</span>
                                    </span>
                                </a>
                            </div>
                            <div class="col-sm-6">
                                <a class="pv-search-browse-card" href="{$tag_url_pv_demo_download}">
                                    <span class="pv-search-browse-card__icon" aria-hidden="true"><i class="bi bi-download"></i></span>
                                    <span class="pv-search-browse-card__body">
                                        <span class="pv-search-browse-card__name">资料下载</span>
                                        <span class="pv-search-browse-card__desc">手册、图纸与认证文件</span>
                                    </span>
                                </a>
                            </div>
                            <div class="col-sm-6">
                                <a class="pv-search-browse-card" href="{$tag_url_pv_demo_news}">
                                    <span class="pv-search-browse-card__icon" aria-hidden="true"><i class="bi bi-newspaper"></i></span>
                                    <span class="pv-search-browse-card__body">
                                        <span class="pv-search-browse-card__name">新闻动态</span>
                                        <span class="pv-search-browse-card__desc">公司资讯与行业动态</span>
                                    </span>
                                </a>
                            </div>
                            <div class="col-sm-6">
                                <a class="pv-search-browse-card" href="{$tag_url_pv_demo_video}">
                                    <span class="pv-search-browse-card__icon" aria-hidden="true"><i class="bi bi-play-btn"></i></span>
                                    <span class="pv-search-browse-card__body">
                                        <span class="pv-search-browse-card__name">产品视频</span>
                                        <span class="pv-search-browse-card__desc">安装演示与工况解说</span>
                                    </span>
                                </a>
                            </div>
                        </div>
                    </section>
                    {pv:else}
                        {pv:if name="search_no_results"}
                        <section class="pv-search-empty-panel" aria-live="polite">
                            <div class="pv-search-empty-panel__icon" aria-hidden="true"><i class="bi bi-inbox"></i></div>
                            <h2 class="pv-search-empty-panel__title">未找到「{$search_keyword}」相关内容</h2>
                            {pv:if name="smart_answer"}
                                <div class="pv-search-empty-panel__message">{$smart_answer_html|raw}</div>
                            {pv:else}
                                <p class="pv-search-empty-panel__message">请尝试更短的关键词、产品货号，或从右侧分类继续浏览。</p>
                            {/pv:if}
                            {pv:if name="search_suggestions"}
                            <div class="pv-search-empty-panel__suggest">
                                <span class="pv-search-empty-panel__suggest-label">您可能在找</span>
                                <div class="pv-search-empty-panel__chips">
                                    {pv:foreach name="search_suggestions" item="sg"}
                                        <a class="pv-search-chip" href="{$search_url}?q={$sg}">{$sg}</a>
                                    {/pv:foreach}
                                </div>
                            </div>
                            {/pv:if}
                            <div class="pv-search-empty-panel__actions">
                                <a href="{$url_product_catalog}" class="btn btn-primary btn-sm"><i class="bi bi-box-seam"></i> 浏览产品</a>
                                <a href="{$tag_url_pv_demo_download}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> 资料下载</a>
                                <a href="{$home_url}" class="btn btn-link btn-sm text-secondary">返回首页</a>
                            </div>
                        </section>
                        {pv:else}
                            {pv:if name="smart_search_on"}
                            <section class="pv-smart-search" aria-label="知识搜索摘要">
                                <div class="pv-smart-search__inner">
                                    <header class="pv-smart-search__head">
                                        <div class="pv-smart-search__icon" aria-hidden="true"><i class="bi bi-stars"></i></div>
                                        <div class="pv-smart-search__meta">
                                            <span class="pv-smart-search-badge"><i class="bi bi-lightning-charge"></i> 知识搜索</span>
                                            <span class="pv-smart-search__mode">{pv:if name="smart_fallback"}规则摘要{pv:else}AI 摘要{/pv:if} · 站内资源</span>
                                        </div>
                                    </header>
                                    <div class="pv-smart-search__body">
                                        {pv:if name="smart_answer"}
                                            <div class="pv-smart-search-answer">{$smart_answer_html|raw}</div>
                                        {pv:else}
                                            <p class="pv-smart-search-paragraph text-muted mb-0">已匹配相关资源，见下方列表。</p>
                                        {/pv:if}
                                        {pv:if name="smart_chunk_citations"}
                                        <div class="pv-smart-search-cites">
                                            <div class="pv-smart-search-cites__label">引用片段</div>
                                            <ul class="pv-smart-search-cites__list">
                                                {pv:foreach name="smart_chunk_citations" item="cite"}
                                                    <li><strong>{$cite.title}</strong><span>{$cite.snippet}</span></li>
                                                {/pv:foreach}
                                            </ul>
                                        </div>
                                        {/pv:if}
                                    </div>
                                </div>
                            </section>
                            {/pv:if}

                            <section class="pv-search-results" aria-label="搜索结果">
                                {pv:if name="product_search_on"}
                                <section class="pv-search-products" aria-label="相关品项">
                                    <h2 class="pv-search-section-title">相关品项{pv:if name="product_total"}（{$product_total}）{/pv:if}</h2>
                                    <div class="pv-search-products__list">
                                        {pv:foreach name="product_list" item="field"}
                                        <a class="pv-search-product-card pv-search-product-card--row" href="{$field.card_url}" data-pv-item-id="{$field.id}">
                                            <div class="pv-search-product-card__media">
                                                {pv:if name="field.cover_url"}
                                                <img src="{$field.cover_url}" alt="{$field.name}" loading="lazy">
                                                {pv:else}
                                                {pv:if name="field.litpic"}
                                                <img src="{$field.litpic}" alt="{$field.name}" loading="lazy">
                                                {pv:else}
                                                <span class="pv-search-product-card__media-empty">暂无图</span>
                                                {/pv:if}
                                                {/pv:if}
                                            </div>
                                            <div class="pv-search-product-card__body">
                                                <h3 class="pv-search-product-card__name">{$field.name}</h3>
                                                <div class="pv-search-product-card__meta">
                                                    {pv:if name="field.model"}<span>型号 {$field.model}</span>{/pv:if}
                                                    {pv:if name="field.code"}<span>货号 {$field.code}</span>{/pv:if}
                                                </div>
                                                {pv:if name="field.param_rows"}
                                                <ul class="pv-search-product-card__params">
                                                    {pv:foreach name="field.param_rows" item="pr"}
                                                    <li class="pv-search-product-card__param">
                                                        <span class="pv-search-product-card__param-k">{$pr.label}</span>
                                                        <span class="pv-search-product-card__param-v">{$pr.value}</span>
                                                    </li>
                                                    {/pv:foreach}
                                                </ul>
                                                {pv:else}
                                                {pv:if name="field.attrs_summary_text"}
                                                <div class="pv-search-product-card__attrs">{$field.attrs_summary_text}</div>
                                                {pv:else}
                                                {pv:if name="field.attrs_summary_html"}
                                                <div class="pv-search-product-card__attrs">{$field.attrs_summary_html|raw}</div>
                                                {/pv:if}
                                                {/pv:if}
                                                {/pv:if}
                                                {pv:if name="field.match_reason_html"}
                                                <div class="pv-search-product-card__reason">匹配：{$field.match_reason_html|raw}</div>
                                                {pv:else}
                                                {pv:if name="field.match_reason_text"}<div class="pv-search-product-card__reason">匹配：{$field.match_reason_text}</div>{/pv:if}
                                                {/pv:if}
                                                <span class="pv-search-product-card__cta">查看详情</span>
                                            </div>
                                        </a>
                                        {/pv:foreach}
                                    </div>
                                </section>
                                {/pv:if}

                                {pv:if empty="list"}
                                {pv:else}
                                <div class="pv-search-articles">
                                    <h2 class="pv-search-section-title">相关文章{pv:if name="total"}（{$total}）{/pv:if}</h2>
                                    {pv:list row="12" item="field" wrap="pv-search-hits"}
                                    <article class="pv-search-hit">
                                        <a class="pv-search-hit__thumb" href="{$field.url}" tabindex="-1" aria-hidden="true">
                                            {pv:if empty="field.litpic"}
                                                <span class="pv-search-hit__ph"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                                            {pv:else}
                                                <img src="{$field.litpic}" alt="" loading="lazy" decoding="async">
                                            {/pv:if}
                                        </a>
                                        <div class="pv-search-hit__body">
                                            <div class="pv-search-hit__meta">
                                                <time datetime="{$field.create_date}">{$field.create_date}</time>
                                                {pv:if empty="field.attr_label_text"}{pv:else}
                                                    <span class="pv-search-hit__badge">{$field.attr_label_text}</span>
                                                {/pv:if}
                                            </div>
                                            <h3 class="pv-search-hit__title">
                                                <a href="{$field.url}" class="{$field.title_class}">{$field.title}</a>
                                            </h3>
                                            <p class="pv-search-hit__excerpt">{$field.excerpt_short}</p>
                                        </div>
                                    </article>
                                    {pv:empty}
                                    <div class="text-center py-5 text-muted" role="status">
                                        <i class="bi bi-inbox display-4 d-block mb-3 opacity-50" aria-hidden="true"></i>
                                        <p class="mb-0 fs-5">暂无内容</p>
                                    </div>
                                    {/pv:empty}
                                    {/pv:list}
                                    {pv:include file="partials/pagination"}
                                </div>
                                {/pv:if}
                            </section>
                        {/pv:if}
                    {/pv:if}
                </div>

                <!-- 侧栏：搜框 + 热门词 + 产品分类（去掉重复块） -->
                <aside class="col-lg-3" aria-label="搜索侧栏">
                    <div class="pv-search-sidebar">
                        <div class="card pv-side-block pv-side-block--search">
                            <div class="card-body">
                                <form class="pv-search-form pv-search-sidebar-form" action="{$search_url}" method="get" role="search">
                                    <div class="pv-search-form__field input-group">
                                        <input type="search" name="q" class="form-control" placeholder="搜索产品、新闻、资料" value="{$search_keyword}" maxlength="100" autocomplete="off" aria-label="搜索关键词">
                                        <button class="btn btn-primary pv-search-form__submit" type="submit" aria-label="搜索"><i class="bi bi-search" aria-hidden="true"></i></button>
                                    </div>
                                    {pv:if name="search_suggestions"}
                                    <div class="pv-search-form__examples" aria-label="热门搜索">
                                        <span class="pv-search-form__examples-label">热门</span>
                                        {pv:foreach name="search_suggestions" item="sg"}
                                            <a class="pv-search-chip" href="{$search_url}?q={$sg}">{$sg}</a>
                                        {/pv:foreach}
                                    </div>
                                    {pv:else}
                                    {pv:if name="search_examples"}
                                    <div class="pv-search-form__examples" aria-label="搜索示例">
                                        <span class="pv-search-form__examples-label">试试</span>
                                        {pv:foreach name="search_examples" item="ex"}
                                            <a class="pv-search-chip" href="{$search_url}?q={$ex}">{$ex}</a>
                                        {/pv:foreach}
                                    </div>
                                    {/pv:if}
                                    {/pv:if}
                                </form>
                            </div>
                        </div>

                        <div class="card pv-side-block">
                            <div class="card-body">
                                <h3 class="card-title h6 sidebar-title"><i class="bi bi-list-ul" aria-hidden="true"></i> 产品分类</h3>
                                <nav class="list-group list-group-flush channel-nav pv-side-list pv-side-list--nav" aria-label="产品分类">
                                    <a class="list-group-item list-group-item-action channel-link" href="{$url_product_catalog}">全部产品</a>
                                    {pv:foreach name="product_catalog_tags" item="cat"}
                                        <a class="list-group-item list-group-item-action channel-link" href="{$cat.url}">{$cat.name}</a>
                                    {/pv:foreach}
                                </nav>
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
