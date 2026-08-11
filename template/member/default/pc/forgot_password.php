<!--
pv:template
label: 找回密码
hint: 邮件找回密码
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
                        <h1 id="pv-member-auth-title" class="pv-member-auth__title">找回密码</h1>
                        <p class="pv-member-auth__subtitle">输入注册邮箱，发送重置链接（1 小时内有效）</p>
                    </header>

                    <div id="pv-forgot-alert" class="alert d-none pv-member-auth__alert" role="alert"></div>

                    <form id="pv-forgot-form" class="pv-member-auth__form" novalidate>
                        <input type="hidden" name="__token" value="{$front_csrf_token}">
                        <div class="pv-member-auth__field">
                            <label class="form-label" for="pv-forgot-email">注册邮箱</label>
                            <div class="pv-member-auth__input-wrap">
                                <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="pv-forgot-email" class="form-control" required autocomplete="email" placeholder="name@example.com">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary pv-member-auth__submit">发送重置邮件</button>
                    </form>

                    <div class="pv-member-auth__foot">
                        <a href="{$member_forgot_username_url}" class="pv-member-auth__foot-link">忘记登录名？</a>
                        <a href="{$member_login_url}" class="pv-member-auth__foot-link pv-member-auth__foot-link--primary"><i class="bi bi-arrow-left" aria-hidden="true"></i> 返回登录</a>
                    </div>
                </div>
{pv:include file="partials/auth_shell_close"}

<script>
(function(){
    var form = document.getElementById('pv-forgot-form');
    var alertEl = document.getElementById('pv-forgot-alert');
    if (!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        alertEl.className = 'alert d-none pv-member-auth__alert';
        var fd = new FormData(form);
        fetch('{$member_forgot_password_post_url}', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var ok = PivarkApi.ajaxOk(res);
                alertEl.textContent = PivarkApi.ajaxMsg(res, ok ? '已发送' : '发送失败');
                alertEl.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' pv-member-auth__alert';
            })
            .catch(function(){ alertEl.textContent = '网络错误'; alertEl.className = 'alert alert-danger pv-member-auth__alert'; });
    });
})();
</script>

{pv:include file="partials/footer"}

