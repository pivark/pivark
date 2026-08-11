<!--
pv:template
label: 会员中心
hint: 概览与推荐套餐
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open" member_panel_mod="mb-4"}
        {pv:include file="member/_page_head" member_page_lead="欢迎回来，管理您的账号与资产"}

        <div class="pv-member-kind-banner mb-3">
            <span class="pv-member-kind-banner__label">账号类型</span>
            <span class="pv-member-kind-banner__value">{$member_profile.account_kind_label}</span>
            {pv:if name="member_profile.is_enterprise"}
            <span class="pv-member-kind-banner__extra text-muted">{$member_profile.enterprise.company_name}</span>
            {/pv:if}
            <a href="{$member_profile_page_url}" class="pv-member-kind-banner__link">查看资料</a>
        </div>

        <div class="row g-3 pv-member-overview">
            {pv:if name="member_document_publish_open"}
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_document_create_url}" class="pv-member-stat card h-100 text-decoration-none pv-member-stat--primary">
                    <div class="card-body">
                        <i class="bi bi-pencil-square pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">发布文章</div>
                        <div class="pv-member-stat__value pv-member-stat__value--sm">写新文章</div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_documents_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-file-earmark-text pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">我的文章</div>
                        <div class="pv-member-stat__value pv-member-stat__value--sm">管理稿件</div>
                    </div>
                </a>
            </div>
            {/pv:if}
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_balance_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-wallet2 pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">账户余额</div>
                        <div class="pv-member-stat__value" id="pv-member-balance-stat">¥{$member_profile.member_balance_text}</div>
                    </div>
                </a>
            </div>
            {pv:if name="member_points_enabled"}
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_points_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-coin pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">{$member_points_name}</div>
                        <div class="pv-member-stat__value" id="pv-member-points-stat">{$member_points_text}</div>
                    </div>
                </a>
            </div>
            {/pv:if}
            {pv:if name="member_signin_enabled"}
            <div class="col-sm-6 col-xl-3">
                <div class="pv-member-stat card h-100">
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <i class="bi bi-calendar-check pv-member-stat__icon"></i>
                            <div class="pv-member-stat__label">每日签到</div>
                            <div class="pv-member-stat__value pv-member-stat__value--sm">
                                {pv:if name="member_profile.member_signin_today"}今日已签{pv:else}签到领{$member_points_name}{/pv:if}
                            </div>
                        </div>
                        {pv:if name="member_profile.member_signin_today"}
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" disabled>已签到</button>
                        {pv:else}
                        <form method="post" action="{$member_signin_url}" class="mt-2" id="pv-signin-form">
                            <input type="hidden" name="__token" value="{$front_csrf_token}">
                            <button type="submit" class="btn btn-sm btn-primary w-100" id="pv-signin-btn">立即签到</button>
                        </form>
                        <p id="pv-signin-tip" class="small text-success mb-0 mt-1 d-none" role="status"></p>
                        {/pv:if}
                    </div>
                </div>
            </div>
            {/pv:if}
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_consumption_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-receipt pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">消费记录</div>
                        <div class="pv-member-stat__value pv-member-stat__value--sm">查看明细</div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_recharge_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-credit-card pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">充值中心</div>
                        <div class="pv-member-stat__value pv-member-stat__value--sm">会员/积分/余额</div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="{$member_profile_page_url}" class="pv-member-stat card h-100 text-decoration-none">
                    <div class="card-body">
                        <i class="bi bi-person-vcard pv-member-stat__icon"></i>
                        <div class="pv-member-stat__label">基本资料</div>
                        <div class="pv-member-stat__value pv-member-stat__value--sm">编辑资料</div>
                    </div>
                </a>
            </div>
        </div>
{pv:include file="member/_panel_end"}

{pv:if name="member_recommended_empty"}
{pv:else}
{pv:include file="member/_panel_open" member_panel_mod="mb-4"}
        {pv:include file="member/_section_head" section_title="推荐会员套餐" section_action_url="{$member_recharge_url}" section_action_label="查看全部" section_desc="升级或续费会员，支持微信/支付宝在线支付。"}
        <div id="pv-recharge-alert" class="alert d-none" role="alert"></div>
        <div class="row g-3">
            {pv:foreach name="member_recommended_packages" item="pkg"}
            <div class="col-md-4">{pv:include file="member/_recharge_package_card"}</div>
            {/pv:foreach}
        </div>
{pv:include file="member/_panel_end"}
{/pv:if}

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
