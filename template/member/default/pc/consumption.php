<!--
pv:template
label: 消费记录
hint: 余额/积分/下载消费
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head"}
        <p class="pv-member-page-desc text-muted small mb-0">站内使用余额、{$member_points_name}、在线支付完成的消费汇总；不含「充值入账」（请见<a href="{$member_balance_url}">余额流水</a>或<a href="{$member_purchases_url}">我的购买</a>）。</p>

        <form class="row g-2 align-items-end pv-member-filter mt-3" method="get" action="">
            <div class="col-auto">
                <label class="form-label small mb-1">类型</label>
                {$consumption_biz_filter_html|raw}
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
            </div>
        </form>

        {pv:if name="ledger_empty"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无消费记录"}
        {pv:else}
        <div class="table-responsive">
            <table class="table table-hover align-middle pv-member-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>时间</th>
                        <th>类型</th>
                        <th class="text-end">消费</th>
                        <th>说明</th>
                    </tr>
                </thead>
                <tbody>
                    {pv:foreach name="ledger_list" item="row"}
                    <tr>
                        <td class="text-nowrap small text-muted">{$row.created_at}</td>
                        <td><span class="badge text-bg-light border">{$row.biz_label}</span></td>
                        <td class="text-end text-danger fw-semibold">{$row.amount_text}</td>
                        <td class="small pv-consumption-detail">{$row.detail_html|raw}</td>
                    </tr>
                    {/pv:foreach}
                </tbody>
            </table>
        </div>
        {pv:include file="member/_pager"}
        {/pv:if}
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
