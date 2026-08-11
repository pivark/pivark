<!--
pv:template
label: 充值套餐
hint: 会员/积分/余额三类充值
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="分会员套餐、充积分、充余额三类；支付成功后对应资产即时更新。"}
        <p class="pv-member-page-desc small mb-3" id="pv-recharge-status-line"><span class="text-secondary">{$member_recharge_status_line}</span></p>
        {pv:if name="member_recharge_success_show"}
        <div class="alert alert-success small mb-3" role="alert">{$member_recharge_success_msg}</div>
        {/pv:if}
        {pv:if name="member_payment_demo"}
        <div class="alert alert-warning small mb-3" role="alert">
            当前为<strong>演示模式</strong>：点微信/支付宝将<strong>模拟成功</strong>（不弹二维码、不扣余额）。正式收款请在后台选择「正式」并保存。
        </div>
        {pv:elseif name="member_payment_online"}
        <p class="text-muted small mb-3">{$member_pay_hint}；充积分可选用余额购买或在线支付。</p>
        {/pv:if}

        <div id="pv-recharge-alert" class="alert d-none" role="alert"></div>

        <ul class="nav mb-3 pv-recharge-tabs" id="pvRechargeTabs" role="tablist">
            {pv:if name="member_recharge_has_membership"}
            <li class="nav-item" role="presentation">
                <button class="nav-link{$member_recharge_tab_membership_class}" id="pv-tab-membership" data-bs-toggle="tab" data-bs-target="#pv-pane-membership" type="button" role="tab">会员套餐</button>
            </li>
            {/pv:if}
            {pv:if name="member_recharge_has_points"}
            <li class="nav-item" role="presentation">
                <button class="nav-link{$member_recharge_tab_points_class}" id="pv-tab-points" data-bs-toggle="tab" data-bs-target="#pv-pane-points" type="button" role="tab">充积分</button>
            </li>
            {/pv:if}
            {pv:if name="member_recharge_has_balance"}
            <li class="nav-item" role="presentation">
                <button class="nav-link{$member_recharge_tab_balance_class}" id="pv-tab-balance" data-bs-toggle="tab" data-bs-target="#pv-pane-balance" type="button" role="tab">充余额</button>
            </li>
            {/pv:if}
        </ul>

        <div class="tab-content" id="pvRechargeTabContent">
            {pv:if name="member_recharge_has_membership"}
            <div class="tab-pane fade{$member_recharge_pane_membership_class}" id="pv-pane-membership" role="tabpanel">
                <p class="text-muted small">升级会员等级、延长有效期，可附带赠送积分；{$member_pay_hint}（不支持余额购买）。</p>
                <div class="row g-3">
                    {pv:foreach name="member_recharge_packages_membership" item="pkg"}
                    <div class="col-md-6">{pv:include file="member/_recharge_package_card"}</div>
                    {/pv:foreach}
                </div>
            </div>
            {/pv:if}
            {pv:if name="member_recharge_has_points"}
            <div class="tab-pane fade{$member_recharge_pane_points_class}" id="pv-pane-points" role="tabpanel">
                <p class="text-muted small">购买后{$member_points_name}即时到账，不改动会员等级与账户余额。</p>
                {pv:if name="member_recharge_custom_points"}
                {pv:include file="member/_recharge_custom_points"}
                {/pv:if}
                <div class="row g-3">
                    {pv:foreach name="member_recharge_packages_points" item="pkg"}
                    <div class="col-md-6">{pv:include file="member/_recharge_package_card"}</div>
                    {/pv:foreach}
                </div>
            </div>
            {/pv:if}
            {pv:if name="member_recharge_has_balance"}
            <div class="tab-pane fade{$member_recharge_pane_balance_class}" id="pv-pane-balance" role="tabpanel">
                <p class="text-muted small">{$member_pay_hint}；支付成功后<strong>账户余额增加</strong>，可用于站内消费。</p>
                {pv:if name="member_recharge_custom_balance"}
                {pv:include file="member/_recharge_custom_balance"}
                {/pv:if}
                <div class="row g-3">
                    {pv:foreach name="member_recharge_packages_balance" item="pkg"}
                    <div class="col-md-6">{pv:include file="member/_recharge_package_card"}</div>
                    {/pv:foreach}
                </div>
            </div>
            {/pv:if}
        </div>
{pv:include file="member/_panel_end"}

<form id="pv-recharge-form" class="d-none" data-pv-submit="{$member_recharge_purchase_url}">
    <input type="hidden" name="package_id" id="pv-recharge-package-id" value="">
    <input type="hidden" name="custom_amount" id="pv-recharge-custom-amount-field" value="">
    <input type="hidden" name="recharge_type" id="pv-recharge-recharge-type" value="">
    <input type="hidden" name="__token" value="{$front_csrf_token}">
</form>
<form id="pv-pay-form" class="d-none" data-pv-submit="{$member_pay_create_url}">
    <input type="hidden" name="package_id" id="pv-pay-package-id" value="">
    <input type="hidden" name="custom_amount" id="pv-pay-custom-amount" value="">
    <input type="hidden" name="recharge_type" id="pv-pay-recharge-type" value="">
    <input type="hidden" name="channel" id="pv-pay-channel" value="">
    <input type="hidden" name="__token" value="{$front_csrf_token}">
</form>

{pv:include file="member/_layout_end"}
