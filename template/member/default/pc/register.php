<!--
pv:template
label: 会员注册
hint: 前台注册表单
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
                <div class="pv-member-auth__card pv-member-auth__card--wide">
                    <header class="pv-member-auth__head">
                        <h1 id="pv-member-auth-title" class="pv-member-auth__title">{pv:if name="member_kind_is_enterprise"}企业注册{pv:else}创建账号{/pv:if}</h1>
                        <p class="pv-member-auth__subtitle">{pv:if name="member_kind_is_enterprise"}开通企业会员，登录后管理订单与权益{pv:else}几步完成注册，用登录名进入会员中心{/pv:if}</p>
                    </header>

                    {pv:if name="member_enterprise_register_open"}
                    <div class="pv-member-auth__kind-tabs" role="tablist" aria-label="注册类型">
                        <a href="{$member_register_personal_url}" class="pv-member-auth__kind-tab{pv:if name="member_kind_is_personal"} is-active{/pv:if}">个人</a>
                        <a href="{$member_register_enterprise_url}" class="pv-member-auth__kind-tab{pv:if name="member_kind_is_enterprise"} is-active{/pv:if}">企业</a>
                    </div>
                    {/pv:if}

                    <div id="pv-member-alert" class="alert d-none pv-member-auth__alert" role="alert"></div>

                    <form id="pv-member-register-form" class="pv-member-auth__form" novalidate>
                        <input type="hidden" name="__token" value="{$front_csrf_token}">
                        <input type="hidden" name="redirect" value="{$member_redirect}">
                        <input type="hidden" name="account_kind" value="{$member_account_kind}">

                        <div class="pv-member-auth__field-row">
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-username">登录名 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                                    <input type="text" name="username" id="pv-reg-username" class="form-control" required maxlength="32" pattern="[A-Za-z0-9_]{3,32}" autocomplete="username" placeholder="3~32 位字母数字下划线" aria-describedby="pv-reg-username-hint">
                                </div>
                                <div id="pv-reg-username-hint" class="form-text pv-member-auth__field-hint" role="status" aria-live="polite"></div>
                            </div>
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-nickname">显示昵称 <span class="badge text-bg-light text-muted fw-normal">选填</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-emoji-smile"></i></span>
                                    <input type="text" name="nickname" id="pv-reg-nickname" class="form-control" maxlength="50" placeholder="站内展示名，可不填">
                                </div>
                            </div>
                        </div>

                        <div class="pv-member-auth__field-row">
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-password">密码 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="password" id="pv-reg-password" class="form-control" required minlength="6" maxlength="100" autocomplete="new-password" placeholder="至少 6 位">
                                </div>
                            </div>
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-password2">确认密码 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" name="password_confirm" id="pv-reg-password2" class="form-control" required minlength="6" maxlength="100" autocomplete="new-password" placeholder="再输入一次">
                                </div>
                            </div>
                        </div>

                        {$member_fields_html|raw}

                        {pv:if name="member_kind_is_enterprise"}
                        <div class="pv-member-auth__field-row">
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-company">企业名称 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
                                    <input type="text" name="company_name" id="pv-reg-company" class="form-control" required maxlength="200" placeholder="企业全称" autocomplete="organization">
                                </div>
                            </div>
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-contact">联系人 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
                                    <input type="text" name="contact_name" id="pv-reg-contact" class="form-control" required maxlength="100" placeholder="联系人姓名" autocomplete="name">
                                </div>
                            </div>
                        </div>
                        <div class="pv-member-auth__field-row">
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-contact-phone">联系电话 <span class="text-danger">*</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="contact_phone" id="pv-reg-contact-phone" class="form-control" required maxlength="32" placeholder="手机或座机" autocomplete="tel">
                                </div>
                            </div>
                            <div class="pv-member-auth__field">
                                <label class="form-label" for="pv-reg-company-email">企业邮箱 <span class="badge text-bg-light text-muted fw-normal">选填</span></label>
                                <div class="pv-member-auth__input-wrap">
                                    <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="company_email" id="pv-reg-company-email" class="form-control" maxlength="120" placeholder="工作邮箱" autocomplete="email">
                                </div>
                            </div>
                        </div>
                        <details class="pv-member-auth__more">
                            <summary>更多选填信息</summary>
                            <div class="pv-member-auth__more-body">
                                <div class="pv-member-auth__field-row">
                                    <div class="pv-member-auth__field">
                                        <label class="form-label" for="pv-reg-usci">统一社会信用代码</label>
                                        <div class="pv-member-auth__input-wrap">
                                            <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-upc"></i></span>
                                            <input type="text" name="usci" id="pv-reg-usci" class="form-control" maxlength="32" placeholder="18 位信用代码">
                                        </div>
                                    </div>
                                    <div class="pv-member-auth__field">
                                        <label class="form-label" for="pv-reg-job">职务</label>
                                        <div class="pv-member-auth__input-wrap">
                                            <span class="pv-member-auth__input-icon" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
                                            <input type="text" name="job_title" id="pv-reg-job" class="form-control" maxlength="100" placeholder="如：采购负责人">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </details>
                        {/pv:if}

                        {pv:if name="member_register_agreement"}
                        <div class="form-text">{$member_register_agreement}</div>
                        {/pv:if}

                        <button type="submit" class="btn btn-primary pv-member-auth__submit">立即注册</button>
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
                            <span class="pv-member-auth__foot-muted">已有账号？</span>
                            <a href="{$member_login_url}" class="pv-member-auth__foot-link pv-member-auth__foot-link--primary">去登录</a>
                        </div>
                    </div>

                    <div class="pv-social-logins-wrap">
                        <p class="pv-social-logins-divider">或使用第三方账号</p>
                        {pv:social_logins}
                    </div>
                </div>
{pv:include file="partials/auth_shell_close"}

<script>
(function(){
    var form = document.getElementById('pv-member-register-form');
    var alertEl = document.getElementById('pv-member-alert');
    var usernameInput = document.getElementById('pv-reg-username');
    var usernameHint = document.getElementById('pv-reg-username-hint');
    var checkUrl = '{$member_check_username_url}';
    if (!form) return;

    var checkTimer = null;
    var checkSeq = 0;
    var usernameOk = null;

    function setUsernameHint(state, message) {
        if (!usernameHint || !usernameInput) return;
        usernameHint.textContent = message || '';
        usernameHint.className = 'form-text pv-member-auth__field-hint' + (state ? ' is-' + state : '');
        usernameInput.classList.toggle('is-invalid', state === 'invalid');
        usernameInput.classList.toggle('is-valid', state === 'valid');
    }

    function checkUsernameNow() {
        if (!usernameInput || !checkUrl) return;
        var value = (usernameInput.value || '').trim();
        if (value === '') {
            usernameOk = null;
            setUsernameHint('', '');
            return;
        }
        if (!/^[A-Za-z0-9_]{3,32}$/.test(value)) {
            usernameOk = false;
            setUsernameHint('invalid', '登录名须为 3~32 位字母、数字或下划线');
            return;
        }
        var seq = ++checkSeq;
        setUsernameHint('pending', '正在检查登录名…');
        fetch(checkUrl + (checkUrl.indexOf('?') >= 0 ? '&' : '?') + 'username=' + encodeURIComponent(value), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (seq !== checkSeq) return;
                var payload = PivarkApi.ajaxPayload(res) || {};
                if (!PivarkApi.ajaxOk(res)) {
                    usernameOk = false;
                    setUsernameHint('invalid', PivarkApi.ajaxMsg(res, '登录名检查失败'));
                    return;
                }
                if (payload.available) {
                    usernameOk = true;
                    setUsernameHint('valid', PivarkApi.ajaxMsg(res, '登录名可用'));
                } else {
                    usernameOk = false;
                    setUsernameHint('invalid', PivarkApi.ajaxMsg(res, '登录名已被占用'));
                }
            })
            .catch(function(){
                if (seq !== checkSeq) return;
                usernameOk = null;
                setUsernameHint('', '');
            });
    }

    function scheduleUsernameCheck() {
        if (checkTimer) clearTimeout(checkTimer);
        checkTimer = setTimeout(checkUsernameNow, 400);
    }

    if (usernameInput) {
        usernameInput.addEventListener('input', function(){
            usernameOk = null;
            setUsernameHint('', '');
            scheduleUsernameCheck();
        });
        usernameInput.addEventListener('blur', function(){
            if (checkTimer) clearTimeout(checkTimer);
            checkUsernameNow();
        });
    }

    form.addEventListener('submit', function(e){
        e.preventDefault();
        alertEl.className = 'alert d-none pv-member-auth__alert';
        if (usernameOk === false) {
            alertEl.textContent = (usernameHint && usernameHint.textContent) || '登录名不可用';
            alertEl.className = 'alert alert-danger pv-member-auth__alert';
            if (usernameInput) usernameInput.focus();
            return;
        }
        var fd = new FormData(form);
        fetch('{$member_register_post_url}', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var payload = PivarkApi.ajaxPayload(res);
                if (PivarkApi.ajaxOk(res)) {
                    location.href = payload.redirect || res.redirect || '{$member_center_url}';
                } else {
                    alertEl.textContent = PivarkApi.ajaxMsg(res, '注册失败');
                    alertEl.className = 'alert alert-danger pv-member-auth__alert';
                    var msg = String(alertEl.textContent || '');
                    if (/(用户名|登录名)/.test(msg) && usernameInput) {
                        usernameOk = false;
                        setUsernameHint('invalid', msg);
                    }
                }
            })
            .catch(function(){ alertEl.textContent = '网络错误'; alertEl.className = 'alert alert-danger pv-member-auth__alert'; });
    });
})();
</script>

{pv:include file="partials/footer"}

