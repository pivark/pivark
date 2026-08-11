<div class="card pv-recharge-custom mb-3 border-0 shadow-sm"
    data-recharge-type="balance"
    data-min="{$member_recharge_custom_min}"
    data-max="{$member_recharge_custom_max}"
    data-points-per-yuan="{$member_recharge_points_per_yuan}">
    <div class="card-body p-4">
        <h2 class="h6 mb-2">自定义充值</h2>
        <p class="text-muted small mb-3">请使用微信或支付宝支付；到账余额与实付金额一致（¥{$member_recharge_custom_min}～¥{$member_recharge_custom_max}）。</p>
        <label class="form-label small mb-1" for="pv-custom-amount-balance">充值金额（元）</label>
        <div class="input-group mb-2">
            <span class="input-group-text">¥</span>
            <input type="number" class="form-control pv-recharge-custom-amount" id="pv-custom-amount-balance"
                step="0.01" min="{$member_recharge_custom_min}" max="{$member_recharge_custom_max}"
                placeholder="例如 100">
        </div>
        <p class="small text-muted mb-3">预计到账余额：<strong class="text-primary pv-recharge-custom-est">¥0.00</strong></p>
        <div class="pv-recharge-pay-actions d-flex flex-wrap gap-2">
            {pv:foreach name="member_pay_channel_custom_options" item="paybtn"}
            <button type="button" class="{$paybtn.class}" data-channel="{$paybtn.channel}">{$paybtn.label}</button>
            {/pv:foreach}
        </div>
    </div>
</div>
