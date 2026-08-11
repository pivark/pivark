<!--
pv:template
label: 邮箱验证结果
hint: 注册邮件验证回跳
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        <div class="pv-member-pay-result text-center py-2">
            {pv:if name="verify_ok"}
            <i class="bi bi-check-circle-fill pv-member-pay-result__icon text-success" aria-hidden="true"></i>
            {pv:else}
            <i class="bi bi-x-circle-fill pv-member-pay-result__icon text-danger" aria-hidden="true"></i>
            {/pv:if}
            <h1 class="pv-member-page-head__title mb-2">{pv:if name="verify_ok"}验证成功{pv:else}验证失败{/pv:if}</h1>
            <p class="text-muted mb-4">{$verify_message}</p>
            <a href="{$member_login_url}" class="btn btn-primary px-4">前往登录</a>
        </div>
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
