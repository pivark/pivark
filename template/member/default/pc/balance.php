<!--
pv:template
label: 余额流水
hint: 账户余额变动
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="记录充值、消费、退款等导致的账户余额变动；正数为增加，负数为扣减。"}
        <p class="pv-member-page-desc text-muted small mb-3">当前余额：<strong class="text-primary" id="pv-balance-page-total">¥{$member_balance_text}</strong>
            <a href="{$member_recharge_url}" class="ms-2">去充值</a>
        </p>
        {pv:if name="member_balance_low_show"}
        <div class="alert alert-warning small py-2 mb-3" role="alert">余额较低，建议前往<a href="{$member_recharge_url}" class="alert-link">充值中心</a>充余额。</div>
        {/pv:if}

        {pv:if name="ledger_empty"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无余额流水"}
        {pv:else}
        <div class="table-responsive">
            <table class="table table-hover align-middle pv-member-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>时间</th>
                        <th class="text-end">变动</th>
                        <th class="text-end">余额</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    {pv:foreach name="ledger_list" item="row"}
                    <tr>
                        <td class="text-nowrap small text-muted">{$row.created_at}</td>
                        <td class="text-end fw-semibold">{$row.delta}</td>
                        <td class="text-end">{$row.balance}</td>
                        <td class="small">{$row.reason}</td>
                    </tr>
                    {/pv:foreach}
                </tbody>
            </table>
        </div>
        {pv:include file="member/_pager"}
        {/pv:if}
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
