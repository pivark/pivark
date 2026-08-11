<!--
pv:template
label: 重置密码
hint: 邮件链接重置密码
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
                        <h1 id="pv-member-auth-title" class="pv-member-auth__title">{$page_title}</h1>
                        <p class="pv-member-auth__subtitle">设置新密码后可用登录名进入会员中心</p>
                    </header>

                    <div id="pv-reset-alert" class="alert d-none pv-member-auth__alert" role="alert"></div>

                    <form id="pv-reset-form" class="pv-member-auth__form" novalidate>
                        <input type="hidden" name="__token" value="{$front_csrf_token}">
                        <input type="hidden" name="reset_token" value="{$reset_token}">
                        <div class="pv-member-auth__field-row">
                            <div class="pv-member-auth__field">
                                <label class="form-label">新密码</label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password" placeholder="至少 8 位，含字母与数字">
                                </div>
                            </div>
                            <div class="pv-member-auth__field">
                                <label class="form-label">确认密码</label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="password_confirm" class="form-control" required autocomplete="new-password" placeholder="再输入一次">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary pv-member-auth__submit">确认重置</button>
                    </form>

                    <div class="pv-member-auth__foot pv-member-auth__foot--center">
                        <a href="{$member_login_url}" class="pv-member-auth__foot-link pv-member-auth__foot-link--primary">返回登录</a>
                    </div>
                </div>
{pv:include file="partials/auth_shell_close"}

<script>
(function(){
    var form = document.getElementById('pv-reset-form');
    var alertEl = document.getElementById('pv-reset-alert');
    if (!form) return;
    form.addEventListener('submit', function(e){
        e.preventDefault();
        alertEl.className = 'alert d-none pv-member-auth__alert';
        fetch('{$member_reset_password_post_url}', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var ok = PivarkApi.ajaxOk(res);
                alertEl.textContent = PivarkApi.ajaxMsg(res, ok ? '已重置' : '失败');
                alertEl.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' pv-member-auth__alert';
                if (ok) {
                    setTimeout(function(){ window.location.href = '{$member_login_url}'; }, 1500);
                }
            })
            .catch(function(){ alertEl.className = 'alert alert-danger pv-member-auth__alert'; alertEl.textContent = '网络错误'; });
    });
})();
</script>
{pv:include file="partials/footer"}
