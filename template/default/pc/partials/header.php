<!-- 区块：全站顶栏（Logo / 导航 / 搜索 / 会员） -->
<header class="pv-portal-header sticky-top">
    <nav class="navbar navbar-expand-lg pv-portal-navbar">
        <div class="container">
            <!-- Logo：深色 pv-site-logo · 浅色 pv-site-logo-hero；首页 pv-home-cinema 滚动后由 CSS 切换 -->
            <a href="{$home_url}" class="navbar-brand pv-portal-brand">
                {pv:if name="site_logo"}
                <img src="{$site_logo}" alt="{$site_name}" class="pv-portal-logo-img pv-site-logo">
                {/pv:if}
                {pv:if name="site_logo_hero"}
                <img src="{$site_logo_hero}" alt="{$site_name}" class="pv-portal-logo-img pv-site-logo-hero">
                {/pv:if}
            </a>

            <!-- 右侧工具栏：搜索 · 会员 · 移动端折叠按钮（大屏时 order-lg-last 靠右） -->
            <div class="pv-portal-toolbar d-flex align-items-center gap-1 order-lg-last">
                <!-- 顶栏搜索：图标展开为表单，提交到 {$search_url} -->
                <div class="pv-portal-header-search" data-pv-header-search>
                    <button type="button" class="pv-portal-icon-btn pv-portal-header-search__trigger" aria-label="搜索" aria-expanded="false" aria-controls="pvPortalHeaderSearchForm">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </button>
                    <form id="pvPortalHeaderSearchForm" class="pv-portal-header-search__form" action="{$search_url}" method="get" role="search" aria-hidden="true">
                        <label class="visually-hidden" for="pvPortalHeaderSearchInput">搜索关键词</label>
                        <input type="search" name="q" id="pvPortalHeaderSearchInput" class="pv-portal-header-search__input form-control" placeholder="搜索产品、新闻、资料…" maxlength="100" required autocomplete="off">
                        <button type="submit" class="pv-portal-icon-btn pv-portal-header-search__submit" aria-label="开始搜索">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
                {pv:if name="member_center_open"}
                {pv:if name="front_member_logged_in"}
                    <!-- 已登录：头像下拉（积分/余额 · 快捷入口 · 资料/安全/退出） -->
                    <div class="dropdown pv-portal-user-dropdown">
                        <button type="button" class="pv-portal-icon-btn pv-portal-icon-btn--user dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="{$front_member_nickname}">
                            {pv:if empty="portal_member_avatar_empty"}
                                <img src="{$portal_member_avatar}" alt="" class="pv-portal-user-toggle-avatar" width="28" height="28" aria-hidden="true">
                            {pv:else}
                                <span class="pv-portal-user-toggle-avatar pv-portal-user-toggle-avatar--letter" aria-hidden="true">{$portal_member_avatar_letter}</span>
                            {/pv:if}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end pv-portal-dropdown pv-portal-user-menu">
                            <li class="pv-portal-user-menu__head">
                                <div class="pv-portal-user-menu__profile">
                                    <span class="pv-portal-user-menu__avatar" aria-hidden="true">
                                        {pv:if empty="portal_member_avatar_empty"}
                                            <img src="{$portal_member_avatar}" alt="" class="pv-portal-user-menu__avatar-img" width="44" height="44" aria-hidden="true">
                                        {pv:else}
                                            <span class="pv-portal-user-menu__avatar-letter">{$portal_member_avatar_letter}</span>
                                        {/pv:if}
                                    </span>
                                    <div class="pv-portal-user-menu__meta">
                                        <span class="pv-portal-user-menu__name">{$portal_member_display_name}</span>
                                        {pv:if empty="portal_member_username"}{pv:else}
                                            <span class="pv-portal-user-menu__user">@{$portal_member_username}</span>
                                        {/pv:if}
                                        <span class="pv-portal-user-menu__level">{$portal_member_level_name}</span>
                                    </div>
                                </div>
                                <div class="pv-portal-user-menu__stats">
                                    {pv:if name="member_points_enabled"}
                                        <a class="pv-portal-user-menu__stat" href="{$member_points_url}">
                                            <span class="pv-portal-user-menu__stat-value">{$portal_member_points_text}</span>
                                            <span class="pv-portal-user-menu__stat-label">{$member_points_name}</span>
                                        </a>
                                    {/pv:if}
                                    <a class="pv-portal-user-menu__stat" href="{$member_balance_url}">
                                        <span class="pv-portal-user-menu__stat-value">¥{$portal_member_balance_text}</span>
                                        <span class="pv-portal-user-menu__stat-label">账户余额</span>
                                    </a>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider my-0"></li>
                            <li class="pv-portal-user-menu__quick">
                                <a class="pv-portal-user-menu__quick-item" href="{$member_center_url}">
                                    <i class="bi bi-grid-1x2" aria-hidden="true"></i><span>个人中心</span>
                                </a>
                                <a class="pv-portal-user-menu__quick-item" href="{$member_downloads_url}">
                                    <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i><span>下载记录</span>
                                </a>
                                <a class="pv-portal-user-menu__quick-item" href="{$member_purchases_url}">
                                    <i class="bi bi-bag-check" aria-hidden="true"></i><span>我的购买</span>
                                </a>
                                <a class="pv-portal-user-menu__quick-item" href="{$member_shop_orders_url}">
                                    <i class="bi bi-receipt" aria-hidden="true"></i><span>商城订单</span>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider my-0"></li>
                            <li><a class="dropdown-item" href="{$member_profile_url}"><i class="bi bi-person-gear"></i> 基本资料</a></li>
                            <li><a class="dropdown-item" href="{$member_security_url}"><i class="bi bi-shield-lock"></i> 账号安全</a></li>
                            {pv:if name="member_points_enabled"}
                                <li><a class="dropdown-item" href="{$member_recharge_url}"><i class="bi bi-credit-card"></i> 充值中心</a></li>
                            {/pv:if}
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="{$member_logout_url}"><i class="bi bi-box-arrow-right"></i> 退出登录</a></li>
                        </ul>

                    </div>
                {pv:else}
                    <!-- 未登录：打开本页底部 #pvPortalLoginModal 弹窗 -->
                    <button type="button" class="pv-portal-icon-btn" title="会员登录" aria-label="登录" data-bs-toggle="modal" data-bs-target="#pvPortalLoginModal">
                        <i class="bi bi-person"></i>
                    </button>
                {/pv:if}
                {/pv:if}
                <button class="navbar-toggler pv-portal-icon-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#pvPortalNavDrawer" aria-controls="pvPortalNavDrawer" aria-label="菜单">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
            </div>

            <!-- 桌面主导航：悬停出二级/三级；点击进栏目页（无 data-bs-toggle，无「全部xxx」） -->
            <div class="d-none d-lg-block flex-grow-1" id="pvPortalNav">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 pv-portal-nav-desktop justify-content-center">
                    {pv:nav item="n" currentclass="active"}
                    {pv:if name="n.has_children"}
                    <li class="nav-item dropdown pv-nav-hover {$n.currentclass}">
                        <a class="nav-link dropdown-toggle {$n.currentclass}" href="{$n.url}"{$n.extends|raw}>{$n.title}</a>
                        <ul class="dropdown-menu">
                            {pv:nav name="n.children" item="c" currentclass="active"}
                            {pv:if name="c.has_children"}
                            <li class="dropend pv-nav-hover">
                                <a class="dropdown-item dropdown-toggle {$c.currentclass}" href="{$c.url}"{$c.extends|raw}>{$c.title}</a>
                                <ul class="dropdown-menu">
                                    {pv:nav name="c.children" item="g" currentclass="active"}
                                    <li><a class="dropdown-item {$g.currentclass}" href="{$g.url}"{$g.extends|raw}>{$g.title}</a></li>
                                    {/pv:nav}
                                </ul>
                            </li>
                            {pv:else}
                            <li><a class="dropdown-item {$c.currentclass}" href="{$c.url}"{$c.extends|raw}>{$c.title}</a></li>
                            {/pv:if}
                            {/pv:nav}
                        </ul>
                    </li>
                    {pv:else}
                    <li class="nav-item {$n.currentclass}"><a class="nav-link {$n.currentclass}" href="{$n.url}"{$n.extends|raw}>{$n.title}</a></li>
                    {/pv:if}
                    {/pv:nav}
                </ul>
            </div>
        </div>
    </nav>

    <!-- 小屏：右侧抽屉 + 多级钻取 -->
    <div class="offcanvas offcanvas-end pv-portal-nav-drawer" tabindex="-1" id="pvPortalNavDrawer" aria-labelledby="pvPortalNavDrawerLabel">
        <div class="offcanvas-header pv-portal-nav-drawer__head">
            <a href="{$home_url}" class="pv-portal-nav-drawer__brand" id="pvPortalNavDrawerLabel">
                {pv:if name="site_logo"}
                <img src="{$site_logo}" alt="{$site_name}" class="pv-portal-logo-img pv-site-logo">
                {/pv:if}
                {pv:if name="site_logo_hero"}
                <img src="{$site_logo_hero}" alt="{$site_name}" class="pv-portal-logo-img pv-site-logo-hero">
                {/pv:if}
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="关闭菜单"></button>
        </div>
        <div class="offcanvas-body p-0">
            <div class="pv-portal-nav-sheet" data-pv-nav-sheet>
                <div class="pv-portal-nav-sheet__stack">
                    <div class="pv-portal-nav-panel pv-portal-nav-panel--root">
                        <ul class="navbar-nav pv-portal-nav-mobile">
                            {pv:nav item="n" currentclass="active"}
                            {pv:if name="n.has_children"}
                            <li class="nav-item dropdown {$n.currentclass}">
                                <a class="nav-link dropdown-toggle {$n.currentclass}" href="{$n.url}"{$n.extends|raw} data-pv-nav-drill="{$n.title}">{$n.title}</a>
                                <ul class="dropdown-menu">
                                    {pv:nav name="n.children" item="c" currentclass="active"}
                                    {pv:if name="c.has_children"}
                                    <li class="dropend">
                                        <a class="dropdown-item dropdown-toggle {$c.currentclass}" href="{$c.url}"{$c.extends|raw} data-pv-nav-drill="{$c.title}">{$c.title}</a>
                                        <ul class="dropdown-menu">
                                            {pv:nav name="c.children" item="g" currentclass="active"}
                                            <li><a class="dropdown-item {$g.currentclass}" href="{$g.url}"{$g.extends|raw}>{$g.title}</a></li>
                                            {/pv:nav}
                                        </ul>
                                    </li>
                                    {pv:else}
                                    <li><a class="dropdown-item {$c.currentclass}" href="{$c.url}"{$c.extends|raw}>{$c.title}</a></li>
                                    {/pv:if}
                                    {/pv:nav}
                                </ul>
                            </li>
                            {pv:else}
                            <li class="nav-item {$n.currentclass}"><a class="nav-link {$n.currentclass}" href="{$n.url}"{$n.extends|raw}>{$n.title}</a></li>
                            {/pv:if}
                            {/pv:nav}
                        </ul>
                        <div class="pv-portal-nav-sheet__foot">
                            {pv:if name="member_center_open"}
                            {pv:if name="front_member_logged_in"}
                            <a class="pv-portal-nav-sheet__cta" href="{$member_center_url}">个人中心</a>
                            {pv:else}
                            <button type="button" class="pv-portal-nav-sheet__cta" data-bs-toggle="modal" data-bs-target="#pvPortalLoginModal" data-bs-dismiss="offcanvas">登录 / 注册</button>
                            {/pv:if}
                            {/pv:if}
                        </div>
                    </div>
                    <div class="pv-portal-nav-panel pv-portal-nav-panel--sub">
                        <div class="pv-portal-nav-sheet__panel-head">
                            <button type="button" class="pv-portal-nav-sheet__back" data-pv-nav-back aria-label="返回上级">
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                            </button>
                            <h2 class="pv-portal-nav-sheet__panel-title" data-pv-nav-sub-title>栏目</h2>
                            <button type="button" class="pv-portal-nav-sheet__close" data-bs-dismiss="offcanvas" aria-label="关闭菜单">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                        <ul class="pv-portal-nav-sheet__sublist" data-pv-nav-sublist></ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
{pv:if name="member_center_open"}
<!-- 区块：登录弹窗（会员登录 · social_logins） -->
<div class="modal fade pv-portal-login-modal" id="pvPortalLoginModal" tabindex="-1" aria-labelledby="pvPortalLoginModalLabel" aria-hidden="true" data-login-post="{$member_login_post_url}" data-member-center="{$member_center_url}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content pv-portal-login-modal__content border-0 overflow-hidden">
            <div class="pv-portal-login-modal__hero">
                <button type="button" class="btn-close pv-portal-login-modal__close" data-bs-dismiss="modal" aria-label="关闭"></button>
                <div class="pv-portal-login-modal__hero-icon" aria-hidden="true">
                    <i class="bi bi-person-badge"></i>
                </div>
                <h2 class="pv-portal-login-modal__title" id="pvPortalLoginModalLabel">会员登录</h2>
                <p class="pv-portal-login-modal__desc">登录后可下载资料、查看订单与会员权益</p>
            </div>
            <div class="modal-body pv-portal-login-modal__body">
                <div id="pv-portal-login-alert" class="alert d-none py-2 mb-3" role="alert"></div>
                <form id="pv-portal-login-form" class="pv-portal-login-form" novalidate>
                    <input type="hidden" name="__token" value="{$front_csrf_token}">
                    <input type="hidden" name="redirect" value="" id="pv-portal-login-redirect">
                    <div class="mb-3">
                        <label class="form-label" for="pv-portal-login-username">用户名</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                            <input type="text" name="username" id="pv-portal-login-username" class="form-control" required maxlength="32" autocomplete="username" placeholder="请输入用户名">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="pv-portal-login-password">密码</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock" aria-hidden="true"></i></span>
                            <input type="password" name="password" id="pv-portal-login-password" class="form-control" required autocomplete="current-password" placeholder="请输入密码">
                        </div>
                    </div>
                    {pv:if name="home_captcha_on"}
                        <div class="mb-3">
                            <label class="form-label" for="pv-portal-login-captcha">验证码</label>
                            <div class="d-flex gap-2 align-items-stretch">
                                <div class="input-group flex-grow-1">
                                    <span class="input-group-text"><i class="bi bi-shield" aria-hidden="true"></i></span>
                                    <input type="text" name="captcha" id="pv-portal-login-captcha" class="form-control" maxlength="8" autocomplete="off" required placeholder="图形验证码">
                                </div>
                                <button type="button" class="btn btn-outline-secondary p-0 pv-portal-login-captcha-btn" title="刷新验证码" aria-label="刷新验证码">
                                    <img src="{$home_captcha_url}" alt="验证码" class="pv-captcha-img" width="120" height="38">
                                </button>
                            </div>
                        </div>
                    {/pv:if}
                    <button type="submit" class="btn btn-primary w-100 pv-portal-login-form__submit">登录</button>
                </form>

                <div class="pv-portal-login-modal__links">
                    {pv:if name="member_forgot_open"}
                        <a href="{$member_forgot_url}">忘记密码？</a>
                    {/pv:if}
                    <span class="pv-portal-login-modal__links-muted">还没有账号？</span>
                    <a href="{$member_register_url}" class="pv-portal-login-modal__links-primary">立即注册</a>
                </div>

                {pv:if name="portal_social_login_on"}
                    <div class="pv-portal-login-social">
                        <p class="pv-social-logins-divider">其他登录方式</p>
                        {pv:social_logins layout="icons" redirect="current"}
                    </div>
                {/pv:if}

                <p class="pv-portal-login-modal__fullpage mb-0">
                    <a href="{$member_login_url}"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> 前往完整登录页</a>
                </p>
            </div>
        </div>
    </div>
</div>
{/pv:if}
