<section class="pv-member-auth" aria-labelledby="pv-member-auth-title">
    <div class="pv-member-auth__shell">
        <aside class="pv-member-auth__aside" aria-label="会员服务说明">
            <a href="{$home_url}" class="pv-member-auth__brand">
                {pv:if empty="site_logo"}
                <span class="pv-member-auth__brand-mark" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                {pv:else}
                <img src="{$site_logo}" alt="" class="pv-member-auth__brand-logo" width="48" height="48">
                {/pv:if}
                <span class="pv-member-auth__brand-name">{$site_name}</span>
            </a>

            <div class="pv-member-auth__aside-body">
                <p class="pv-member-auth__eyebrow">会员中心</p>
                <h2 class="pv-member-auth__headline">订单与权益<br>一处掌控</h2>
                <p class="pv-member-auth__lead">登录后可查看订单、下载资料，管理会员账户。</p>
                <ul class="pv-member-auth__features">
                    <li><i class="bi bi-bag-check" aria-hidden="true"></i><span>订单与权益查询</span></li>
                    <li><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i><span>资料下载与权限内容</span></li>
                    <li><i class="bi bi-shield-lock" aria-hidden="true"></i><span>安全中心与密码保护</span></li>
                </ul>
            </div>

            <a href="{$home_url}" class="pv-member-auth__home-link"><i class="bi bi-arrow-left" aria-hidden="true"></i> 返回网站首页</a>
        </aside>

        <div class="pv-member-auth__main">
            <div class="pv-member-auth__topbar">
                <a href="{$home_url}" class="pv-member-auth__topbar-link">返回网站</a>
                <div class="pv-member-auth__topbar-actions">
                    <a href="{$member_login_url}" class="pv-member-auth__topbar-link">登录</a>
                    <a href="{$member_register_url}" class="pv-member-auth__topbar-link pv-member-auth__topbar-link--solid">注册</a>
                </div>
            </div>
            <div class="pv-member-auth__stage">
