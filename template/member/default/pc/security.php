<!--
pv:template
label: 账号安全
hint: 修改密码与注销
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open" member_panel_mod="mb-4"}
        {pv:include file="member/_page_head" member_page_lead="修改登录密码，保护账号安全"}

        {pv:include file="member/_section_head" section_title="修改密码"}
        <div id="pv-password-alert" class="alert d-none" role="alert"></div>
        <form id="pv-password-form" data-pv-submit="{$member_password_url}">
            <input type="hidden" name="__token" value="{$front_csrf_token}">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">原密码</label>
                    <input type="password" name="old_password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label">新密码</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                </div>
                <div class="col-md-4">
                    <label class="form-label">确认新密码</label>
                    <input type="password" name="password_confirm" class="form-control" required minlength="6" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" class="btn btn-outline-primary mt-3">修改密码</button>
        </form>
{pv:include file="member/_panel_end"}

{pv:if name="oauth_security_open"}
{pv:include file="member/_panel_open" member_panel_mod="mb-4"}
        {pv:include file="member/_section_head" section_title="第三方账号"}
        <p class="text-muted small mb-3">绑定后可用对应平台一键登录；解绑不影响本站密码登录。</p>
        <div id="pv-oauth-alert" class="alert d-none" role="alert"></div>
        <div id="pv-oauth-box" class="list-group" data-unbind-url="{$member_oauth_unbind_url}" data-csrf="{$front_csrf_token}">
            {pv:foreach name="oauth_security_rows" item="oauth"}
            <div class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <strong>{$oauth.label}</strong>
                    {pv:if name="oauth.bound"}
                    <span class="badge text-bg-success ms-2">已绑定</span>
                    {pv:if name="oauth.nickname"}
                    <span class="text-muted small ms-2">{$oauth.nickname}</span>
                    {/pv:if}
                    {pv:else}
                    <span class="badge text-bg-secondary ms-2">未绑定</span>
                    {/pv:if}
                </div>
                <div class="d-flex gap-2">
                    {pv:if name="oauth.bound"}
                    <button type="button" class="btn btn-outline-danger btn-sm pv-oauth-unbind" data-provider="{$oauth.provider}">解绑</button>
                    {pv:else}
                    <a class="btn btn-outline-primary btn-sm" href="{$oauth.bind_url}">去绑定</a>
                    {/pv:if}
                </div>
            </div>
            {/pv:foreach}
        </div>
        <script>
        (function () {
            var box = document.getElementById('pv-oauth-box');
            if (!box) return;
            var unbindUrl = box.getAttribute('data-unbind-url') || '';
            var token = box.getAttribute('data-csrf') || '';
            var alertEl = document.getElementById('pv-oauth-alert');
            box.querySelectorAll('.pv-oauth-unbind').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.confirm('确定解绑该第三方账号？')) return;
                    btn.disabled = true;
                    var fd = new FormData();
                    fd.append('provider', btn.getAttribute('data-provider') || '');
                    fd.append('__token', token);
                    fetch(unbindUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                        body: fd
                    }).then(function (r) { return r.json(); }).then(function (body) {
                        var ok = !!(body && !body.error && (body.code === 0 || body.ok === true || body.data !== undefined));
                        if (body && body.error) ok = false;
                        if (alertEl) {
                            alertEl.classList.remove('d-none', 'alert-success', 'alert-danger');
                            alertEl.classList.add(ok ? 'alert-success' : 'alert-danger');
                            alertEl.textContent = (body && (body.msg || body.message || (body.error && body.error.message))) || (ok ? '已解绑' : '解绑失败');
                        }
                        if (ok) location.reload();
                        else btn.disabled = false;
                    }).catch(function () {
                        btn.disabled = false;
                        if (alertEl) {
                            alertEl.classList.remove('d-none', 'alert-success');
                            alertEl.classList.add('alert-danger');
                            alertEl.textContent = '网络错误';
                        }
                    });
                });
            });
        })();
        </script>
{pv:include file="member/_panel_end"}
{/pv:if}

{pv:if name="member_cancel_open"}
{pv:include file="member/_panel_open" member_panel_mod="pv-member-panel--danger"}
        {pv:include file="member/_section_head" section_title="账号注销"}
        <p class="text-muted small">提交后由管理员审核，通过后将无法登录。</p>
        <div id="pv-cancel-alert" class="alert d-none" role="alert"></div>
        <form id="pv-cancel-form" data-pv-submit="{$member_cancel_url}">
            <input type="hidden" name="__token" value="{$front_csrf_token}">
            <div class="mb-3">
                <label class="form-label">注销原因</label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="500" placeholder="请简要说明注销原因"></textarea>
            </div>
            <button type="submit" class="btn btn-outline-danger btn-sm">提交注销申请</button>
        </form>
{pv:include file="member/_panel_end"}
{/pv:if}

{pv:include file="member/_layout_end"}
