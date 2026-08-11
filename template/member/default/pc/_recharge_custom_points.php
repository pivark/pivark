<div class="card pv-recharge-custom mb-3 border-0 shadow-sm"
    data-recharge-type="points"
    data-min="{$member_recharge_custom_min}"
    data-max="{$member_recharge_custom_max}"
    data-points-per-yuan="{$member_recharge_points_per_yuan}">
    <div class="card-body p-4">
        <h2 class="h6 mb-2">自定义充值</h2>
        <p class="text-muted small mb-3">按 ¥1 = {$member_recharge_points_per_yuan} {$member_points_name} 兑换，不改动会员等级与账户余额。</p>
        <label class="form-label small mb-1" for="pv-custom-amount-points">充值金额（元）</label>
        <div class="input-group mb-2">
            <span class="input-group-text">¥</span>
            <input type="number" class="form-control pv-recharge-custom-amount" id="pv-custom-amount-points"
                step="0.01" min="{$member_recharge_custom_min}" max="{$member_recharge_custom_max}"
                placeholder="例如 50">
        </div>
        <p class="small text-muted mb-3">预计获得 <strong class="text-primary pv-recharge-custom-est">0</strong> {$member_points_name}</p>
        <div class="pv-recharge-pay-actions d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary btn-sm pv-recharge-custom-buy">余额购买</button>
            {pv:foreach name="member_pay_channel_custom_options" item="paybtn"}
            <button type="button" class="{$paybtn.class}" data-channel="{$paybtn.channel}">{$paybtn.label}</button>
            {/pv:foreach}
        </div>
    </div>
</div>
