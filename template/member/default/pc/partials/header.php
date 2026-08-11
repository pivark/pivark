<!-- 会员区顶栏：轻量壳，不依赖站点主题 partials -->
<header class="pv-member-site-header border-bottom bg-white">
    <nav class="navbar navbar-expand-lg navbar-light py-2">
        <div class="container">
            <a class="navbar-brand fw-semibold text-truncate" href="{$home_url}">{$site_name}</a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a class="btn btn-sm btn-outline-secondary" href="{$home_url}">返回网站</a>
                {pv:if name="member_center_open"}
                {pv:if name="front_member_logged_in"}
                <a class="btn btn-sm btn-primary" href="{$member_center_url}">会员中心</a>
                {pv:else}
                <a class="btn btn-sm btn-primary" href="{$member_login_url}">登录</a>
                {/pv:if}
                {/pv:if}
            </div>
        </div>
    </nav>
</header>