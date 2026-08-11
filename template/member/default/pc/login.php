<!--
pv:template
label: 会员登录
hint: 前台登录表单
scope: member
-->
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
    {pv:include file="partials/vendor_head"}
<link href="{$member_asset}/css/member-center.css?v={$member_asset_ver}" rel="stylesheet">
</head>
<body class="pv-member-body pv-member-auth-page">

{pv:include file="partials/auth_shell_open"}
                <div class="pv-member-auth__card">
                    <header class="pv-member-auth__head">
                        <h1 id="pv-member-auth-title" class="pv-member-auth__title">欢迎回来</h1>
                        <p class="pv-member-auth__subtitle">使用登录名进入会员中心</p>
                    </header>

                    <div id="pv-member-alert" class="alert d-none pv-member-auth__alert" role="alert"></div>

                    <form id="pv-member-login-form" class="pv-member-auth__form" novalidate>
                        <input type="hidden" name="__token" value="{$front_csrf_token}">
                        <input type="hidden" name="redirect" value="{$member_redirect}">

                        <div class="pv-member-auth__field">
                            <label class="form-label" for="pv-login-username">登录名</label>
                            <div class="pv-member-auth__input-wrap">
                                <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" id="pv-login-username" class="form-control" required maxlength="32" autocomplete="username" placeholder="请输入登录名">
                            </div>
                        </div>

                        <div class="pv-member-auth__field">
                            <label class="form-label" for="pv-login-password">密码</label>
                            <div class="pv-member-auth__input-wrap">
                                <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
                                <input type="password" name="password" id="pv-login-password" class="form-control" required maxlength="100" autocomplete="current-password" placeholder="请输入密码">
                            </div>
                        </div>

                        {pv:if name="home_captcha_on"}
                        <div class="pv-member-auth__field">
                            <label class="form-label" for="pv-login-captcha">验证码</label>
                            <div class="pv-member-auth__captcha">
                                <div class="pv-member-auth__input-wrap pv-member-auth__input-wrap--captcha">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-shield"></i></span>
                                    <input type="text" name="captcha" id="pv-login-captcha" class="form-control" maxlength="8" autocomplete="off" required placeholder="图形验证码">
                                </div>
                                <button type="button" class="pv-member-auth__captcha-img-btn pv-captcha-refresh" title="点击刷新验证码" aria-label="刷新验证码">
                                    <img src="{$home_captcha_url}" alt="" class="pv-captcha-img" width="132" height="40" data-captcha-base="{$home_captcha_url}">
                                </button>
                            </div>
                        </div>
                        {/pv:if}

                        <button type="submit" class="btn btn-primary pv-member-auth__submit">登录</button>
                    </form>

                    <div class="pv-member-auth__foot">
                        {pv:if name="member_forgot_open"}
                        <div class="pv-member-auth__recover">
                            <a href="{$member_forgot_url}" class="pv-member-auth__foot-link">忘记密码？</a>
                            <span class="pv-member-auth__recover-sep">·</span>
                            <a href="{$member_forgot_username_url}" class="pv-member-auth__foot-link">忘记登录名？</a>
                        </div>
                        {pv:else}
                        <span></span>
                        {/pv:if}
                        <div>
                            <span class="pv-member-auth__foot-muted">还没有账号？</span>
                            <a href="{$member_register_url}" class="pv-member-auth__foot-link pv-member-auth__foot-link--primary">立即注册</a>
                        </div>
                    </div>

                    <div class="pv-social-logins-wrap">
                        <p class="pv-social-logins-divider">或使用第三方账号登录</p>
                        {pv:social_logins}
                    </div>
                </div>
{pv:include file="partials/auth_shell_close"}

<script>
(function(){
    var form = document.getElementById('pv-member-login-form');
    var alertEl = document.getElementById('pv-member-alert');
    var captchaImg = form ? form.querySelector('.pv-captcha-img') : null;
    var captchaBtn = form ? form.querySelector('.pv-captcha-refresh') : null;
    if (!form) return;
    try {
        var oauthErr = new URLSearchParams(window.location.search).get('oauth_error');
        if (oauthErr && alertEl) {
            alertEl.textContent = oauthErr;
            alertEl.className = 'alert alert-danger pv-member-auth__alert';
        }
    } catch (e) {}
    function refreshCaptcha() {
        if (!captchaImg) return;
        var base = captchaImg.getAttribute('data-captcha-base') || captchaImg.getAttribute('src') || '';
        base = String(base).split('#')[0];
        var join = base.indexOf('?') >= 0 ? '&' : '?';
        captchaImg.src = base + join + 't=' + Date.now();
    }
    if (captchaBtn) {
        captchaBtn.addEventListener('click', function(e){
            e.preventDefault();
            refreshCaptcha();
        });
    }
    if (captchaImg) {
        captchaImg.addEventListener('click', function(e){
            e.preventDefault();
            refreshCaptcha();
        });
    }
    form.addEventListener('submit', function(e){
        e.preventDefault();
        alertEl.className = 'alert d-none pv-member-auth__alert';
        var fd = new FormData(form);
        fetch('{$member_login_post_url}', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var payload = PivarkApi.ajaxPayload(res);
                if (PivarkApi.ajaxOk(res)) {
                    location.href = payload.redirect || res.redirect || '{$member_center_url}';
                } else {
                    alertEl.textContent = PivarkApi.ajaxMsg(res, '登录失败');
                    alertEl.className = 'alert alert-danger pv-member-auth__alert';
                    refreshCaptcha();
                }
            })
            .catch(function(){ alertEl.textContent = '网络错误'; alertEl.className = 'alert alert-danger pv-member-auth__alert'; });
    });
})();
</script>

{pv:include file="partials/footer"}

