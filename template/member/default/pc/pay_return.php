<!--
pv:template
label: 支付结果
hint: 在线支付回跳页
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        <div class="pv-member-pay-result text-center py-2">
            {pv:if name="pay_order_paid"}
            <i class="bi bi-check-circle-fill pv-member-pay-result__icon text-success" aria-hidden="true"></i>
            {pv:else}
            <i class="bi bi-hourglass-split pv-member-pay-result__icon text-warning" aria-hidden="true"></i>
            {/pv:if}
            <h1 class="pv-member-page-head__title mb-2">{$pay_panel_title}</h1>
            <p class="text-muted mb-4" id="pv-pay-panel-body">{$pay_panel_body}</p>
            <p class="text-muted small mb-3 d-none" id="pv-pay-poll-hint">正在向支付平台确认结果，请稍候…</p>
            <a href="{$pay_panel_cta_url}" class="btn btn-primary px-4" id="pv-pay-panel-cta">{$pay_panel_cta_label}</a>
        </div>
{pv:include file="member/_panel_end"}

<div id="pv-pay-return-poll"
    class="d-none"
    data-order-no="{$pay_order_no}"
    data-paid="{$pay_order_paid}"
    data-status-url="{$pay_status_poll_url}"
    data-auto-redirect="{$pay_auto_redirect}"></div>

{pv:include file="member/_layout_end"}
