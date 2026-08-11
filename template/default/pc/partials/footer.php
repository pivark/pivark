<!-- 区块：全站页脚 -->
<footer class="pv-portal-footer" aria-label="网站页脚">
    <div class="container">
        <div class="row g-4 g-lg-5 pv-portal-footer-main">
            <div class="col-lg-4">
                <!-- 深色底用浅色 Logo（site_logo_hero）；无则回退深色 Logo；不叠站名文字 -->
                <a href="{$home_url}" class="pv-portal-footer-brand" aria-label="{$site_name}">
                    {pv:if name="site_logo_hero"}
                    <img src="{$site_logo_hero}" alt="{$site_name}" class="pv-portal-logo-img pv-portal-footer-logo">
                    {pv:else}
                    {pv:if name="site_logo"}
                    <img src="{$site_logo}" alt="{$site_name}" class="pv-portal-logo-img pv-portal-footer-logo">
                    {/pv:if}
                    {/pv:if}
                </a>
                {pv:if empty="site_description"}
                    <p class="pv-portal-footer-desc">专注工业自动化、过程测控与智能装备，为制造企业提供可靠的产品、系统方案与全生命周期服务。</p>
                {pv:else}
                    <p class="pv-portal-footer-desc">{$site_description}</p>
                {/pv:if}
                <div class="pv-portal-footer-actions">
                    <a class="btn btn-sm pv-portal-footer-cta" href="{$url_contact}">选型咨询</a>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <h4>网站导航</h4>
                <ul>
                    <li><a href="{$url_about}">关于我们</a></li>
                    <li><a href="{$tag_url_pv_demo_news}">新闻中心</a></li>
                    <li><a href="{$url_product_catalog}">产品展示</a></li>
                    <li><a href="{$tag_url_pv_demo_gallery}">应用案例</a></li>
                    <li><a href="{$tag_url_pv_demo_video}">视频中心</a></li>
                    <li><a href="{$search_url}">全站搜索</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <h4>产品与方案</h4>
                <ul>
                    <li><a href="{$url_product_catalog}">全部产品</a></li>
                    {pv:if empty="product_catalog_tags_empty"}
                        {pv:foreach name="product_catalog_tags" item="cat"}
                            <li><a href="{$cat.url}">{$cat.name}</a></li>
                        {/pv:foreach}
                    {pv:else}
                        <li><a href="{$tag_url_pv_demo_cat_digital}">测控仪器</a></li>
                        <li><a href="{$tag_url_pv_demo_cat_service}">系统集成</a></li>
                        <li><a href="{$tag_url_pv_demo_cat_resource}">配件耗材</a></li>
                    {/pv:if}
                    <li><a href="{$tag_url_pv_demo_download}">资料下载</a></li>
                </ul>
            </div>
            <div class="col-md-4 col-lg-4">
                <h4>联系我们</h4>
                <ul class="pv-portal-footer-contact">
                    <li>
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span>{$site_address}</span>
                    </li>
                    <li>
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                        <a href="tel:{$site_phone}">{$site_phone}</a>
                        <span class="pv-portal-footer-contact__note">7×12 小时热线</span>
                    </li>
                    <li>
                        <i class="bi bi-envelope" aria-hidden="true"></i>
                        <a href="mailto:{$site_email}">{$site_email}</a>
                    </li>
                    <li>
                        <i class="bi bi-clock" aria-hidden="true"></i>
                        <span>工作日 8:30–17:30 · 协议客户 48h 现场响应</span>
                    </li>
                </ul>
                {pv:if name="site_wechat_qr"}
                <div class="pv-portal-footer-qr" aria-label="微信与公众号">
                    <figure class="pv-portal-footer-qr__item">
                        <img src="{$site_wechat_qr}" alt="官方微信二维码" width="88" height="88" loading="lazy" decoding="async">
                        <figcaption>官方微信</figcaption>
                    </figure>
                    {pv:if name="site_wechat_mp_qr"}
                    <figure class="pv-portal-footer-qr__item">
                        <img src="{$site_wechat_mp_qr}" alt="公众号二维码" width="88" height="88" loading="lazy" decoding="async">
                        <figcaption>公众号</figcaption>
                    </figure>
                    {/pv:if}
                </div>
                {pv:else}
                {pv:if name="site_wechat_mp_qr"}
                <div class="pv-portal-footer-qr" aria-label="公众号">
                    <figure class="pv-portal-footer-qr__item">
                        <img src="{$site_wechat_mp_qr}" alt="公众号二维码" width="88" height="88" loading="lazy" decoding="async">
                        <figcaption>公众号</figcaption>
                    </figure>
                </div>
                {/pv:if}
                {/pv:if}
                <p class="pv-portal-footer-service">
                    <a href="{$url_faq}">常见问题</a>
                    <span aria-hidden="true">·</span>
                    <a href="{$url_careers}">招贤纳士</a>
                </p>
            </div>
        </div>

        <div class="pv-portal-footer-bottom">
            <div class="row align-items-center g-2">
                <div class="col-md-6">
                    <p class="mb-0 pv-portal-footer-copy">
                        {pv:if empty="site_copyright"}© {$site_name}{pv:else}{$site_copyright}{/pv:if}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-0 pv-portal-footer-meta text-md-end">
                            {pv:if empty="site_icp"}{pv:else}
                                <a class="pv-footer-icp" href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">{$site_icp}</a>
                            {/pv:if}
                            {pv:if empty="site_police"}{pv:else}
                                <span aria-hidden="true"> · </span>
                                <a href="https://beian.mps.gov.cn/" target="_blank" rel="noopener noreferrer">{$site_police}</a>
                            {/pv:if}
                            <span aria-hidden="true"> · </span>
                            <a href="{$url_privacy}">隐私政策</a>
                            <span aria-hidden="true"> · </span>
                            <a href="{$url_terms}">服务条款</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    {pv:if name="float_contact_enabled"}
        {$float_contact_html|raw}
    {/pv:if}
    {pv:if name="site_overlay_ads_enabled"}
        {$site_overlay_ads_html|raw}
    {/pv:if}

    <script src="/static/common/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="{$theme_asset}/js/demo-header-toolbar.js?v={$theme_asset_ver}" defer></script>
    <script src="{$theme_asset}/js/demo.js?v={$theme_asset_ver}" defer></script>
    {pv:frontassets /}
    <input type="hidden" id="pv-front-csrf-token" value="{$front_csrf_token}">
