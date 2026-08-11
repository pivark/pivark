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
    <body class="pv-portal-site pv-doc-detail pv-doc-perm-test">
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

        <!-- 区块：权限测试详情（perm banner · 正文 · 侧栏） -->
        <main aria-label="文档权限测试详情" class="pv-channel-main pv-portal-main">
            <div class="container">
                <div class="row g-3 g-lg-4">
                    <article class="col-lg-9">
                        <div class="pv-doc-article-panel">
                            <!-- 区块：阅读权限测试信息条 -->
                            <section class="pv-perm-test-banner" aria-label="阅读权限测试信息">
                                <h2><i class="bi bi-shield-lock"></i> 阅读权限测试（本模板仅便于后台联调）</h2>
                                <dl class="pv-perm-test-grid">
                                    <div>
                                        <dt>当前访客</dt>
                                        <dd>
                                            {pv:if name="front_member_logged_in"}
                                                <span class="pv-perm-test-state-ok">已登录（{$front_member_nickname}）</span>
                                            {pv:else}
                                                <span class="pv-perm-test-state-warn">未登录</span>
                                            {/pv:if}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>文档要求</dt>
                                        <dd>
                                            {pv:if name="document_login_required"}
                                                {pv:if name="document_read_level_name"}{$document_read_level_name} 及以上会员{pv:else}须登录后阅读全文{/pv:if}
                                                {pv:else}
                                                    <span class="pv-perm-test-state-ok">开放浏览</span>
                                                {/pv:if}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>正文可见</dt>
                                            <dd>
                                                {pv:if name="document_login_required"}
                                                    <span class="pv-perm-test-state-warn">仅摘要（未满足权限）</span>
                                                {pv:else}
                                                    <span class="pv-perm-test-state-ok">全文已展示</span>
                                                {/pv:if}
                                            </dd>
                                        </div>
                                    </dl>
                                </section>

                                <header class="article-header mb-4">
                                    <div class="article-header__title-row">
                                        <div class="article-header__main">
                                            <h1 class="article-main-title">{$document_title}</h1>
                                            <div class="article-info">
                                                <span class="info-item"><i class="bi bi-calendar"></i> {$document_date}</span>
                                                <span class="info-item"><i class="bi bi-eye"></i> {$document_click} 阅读</span>
                                                {pv:if empty="document_author"}
                                                {pv:else}
                                                    <span class="info-item"><i class="bi bi-person"></i> {$document_author}</span>
                                                {/pv:if}
                                            </div>
                                        </div>
                                        {pv:if name="document_qr_url"}
                                            <figure class="pv-page-qr">
                                                {pv:if name="document_share_url"}
                                                <a class="pv-page-qr-link" href="{$document_share_url}" title="打开或复制本文链接" rel="noopener">
                                                    <img src="{$document_qr_url}" alt="本文页面二维码" width="80" height="80" class="pv-page-qr-img" loading="lazy">
                                                    <figcaption class="pv-page-qr-label">扫码打开</figcaption>
                                                </a>
                                                {pv:else}
                                                <img src="{$document_qr_url}" alt="本文页面二维码" width="80" height="80" class="pv-page-qr-img" loading="lazy">
                                                <figcaption class="pv-page-qr-label">扫码查看</figcaption>
                                                {/pv:if}
                                            </figure>
                                        {/pv:if}
                                    </div>
                                </header>

                                {pv:if empty="document_litpic"}
                                {pv:else}
                                    <figure class="article-figure mb-4">
                                        <img src="{$document_litpic}" alt="{$document_title}" class="img-fluid rounded">
                                    </figure>
                                {/pv:if}

                                <div class="article-content">
                                    {pv:if name="document_login_required"}
                                        <div class="pv-perm-test-body-locked">
                                            <p class="text-muted mb-2">
                                                {pv:if name="document_perm_hint"}{$document_perm_hint}{pv:else}
                                                    {pv:if name="document_read_level_name"}
                                                        本文需 <strong>{$document_read_level_name}</strong> 及以上会员阅读，当前仅展示摘要。
                                                    {pv:else}
                                                        本文需登录后阅读，当前仅展示摘要。
                                                    {/pv:if}{/pv:if}
                                                </p>
                                                <div class="mb-3">{$document_summary}</div>
                                                {pv:if name="document_perm_cta_url"}
                                                    <a class="btn btn-primary btn-sm" href="{$document_perm_cta_url}">{$document_perm_cta_label}</a>
                                                {pv:else}
                                                    <a class="btn btn-primary btn-sm" href="{$member_login_url}">登录后阅读全文</a>
                                                {/pv:if}
                                            </div>
                                        {pv:else}
                                            {$document_content|raw}
                                        {/pv:if}
                                    </div>

                                    {pv:if empty="document_tags"}
                                    {pv:else}
                                        <footer class="article-tags-footer mt-4">
                                            <div class="tags-label"><i class="bi bi-tags"></i> 标签</div>
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
                                </div>
                            </article>

                            <!-- 区块：文档侧栏 -->
                            <aside class="col-lg-3" aria-label="权限测试侧栏">
                                <div class="sidebar">
                                    <div class="card shadow-sm pv-side-block">
                                        <div class="card-body">
                                    <h3 class="card-title h6 sidebar-title text-muted">测试提示</h3>
                                    <ol class="small text-muted ps-3 mb-0">
                                        <li>后台「更多设置」改阅读权限后保存</li>
                                        <li>未登录 / 低等级会员应只看摘要</li>
                                        <li>满足权限后刷新应见全文</li>
                                    </ol>
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

