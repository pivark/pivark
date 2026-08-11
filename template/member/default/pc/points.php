<!--
pv:template
label: 我的积分
hint: 积分流水
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="签到、消费赠送、充值套餐等产生的{$member_points_name}变动明细。"}
        <p class="pv-member-page-desc text-muted small mb-3">当前余额：<strong class="text-primary" id="pv-points-page-total">{$member_points_text}</strong> {$member_points_name}
            <a href="{$member_recharge_url}" class="ms-2">去充{$member_points_name}</a>
        </p>

        {pv:if name="ledger_empty"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无{$member_points_name}记录"}
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
