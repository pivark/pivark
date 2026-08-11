<div class="card h-100 pv-member-recharge-card border">
    <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
            <h2 class="h6 mb-0">{$pkg.title}</h2>
            <span class="badge text-bg-light border small">{$pkg.package_type_label}</span>
        </div>
        {pv:if name="pkg.description"}
        <p class="text-muted small mb-2">{$pkg.description}</p>
        {/pv:if}
        {pv:if name="pkg.benefit_text"}
        <p class="small text-secondary mb-3">{$pkg.benefit_text}</p>
        {/pv:if}
        <div class="mt-auto pv-recharge-card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div class="pv-member-recharge-card__price text-primary fw-bold mb-0">¥{$pkg.price_text}</div>
            <div class="pv-recharge-pay-actions d-flex flex-wrap align-items-center justify-content-end gap-2">
                {pv:if name="pkg.show_balance_buy"}
                <button type="button"
                    class="btn btn-primary btn-sm pv-recharge-buy"
                    data-package-id="{$pkg.id}"
                    data-package-title="{$pkg.title}"
                    data-package-price="{$pkg.price}"
                    data-package-type="{$pkg.package_type}">余额购买</button>
                {pv:elseif name="pkg.show_balance_shortfall"}
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="余额不足">余额不足</button>
                {/pv:if}
                {pv:foreach name="member_pay_channel_options" item="paybtn"}
                <button type="button" class="{$paybtn.class}"
                    data-channel="{$paybtn.channel}" data-package-id="{$pkg.id}" data-package-title="{$pkg.title}" data-package-price="{$pkg.price}" data-package-type="{$pkg.package_type}">{$paybtn.label}</button>
                {/pv:foreach}
            </div>
        </div>
    </div>
</div>
