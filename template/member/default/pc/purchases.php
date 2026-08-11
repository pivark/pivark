<!--
pv:template
label: 我的购买
hint: 统一支付订单（充值/下载/视频等）
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="微信/支付宝等在线支付订单（充值、付费下载、付费视频等）；待支付订单可点击「继续支付」完成付款。"}

        <form class="row g-2 align-items-end pv-member-filter" method="get" action="">
            <div class="col-auto">
                <label class="form-label">状态</label>
                <select name="status" class="form-select">
                    <option value="">全部</option>
                    <option value="pending"{pv:if name="purchase_status" value="pending"} selected{/pv:if}>待支付</option>
                    <option value="paid"{pv:if name="purchase_status" value="paid"} selected{/pv:if}>已支付</option>
                    <option value="failed"{pv:if name="purchase_status" value="failed"} selected{/pv:if}>失败</option>
                    <option value="closed"{pv:if name="purchase_status" value="closed"} selected{/pv:if}>已关闭</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
            </div>
        </form>

        {pv:if name="ledger_empty"}
        {pv:if name="member_payment_enabled"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无购买记录" member_empty_hint="付费下载、付费视频或充值套餐支付成功后会显示在这里。"}
        {pv:else}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无购买记录" member_empty_hint="站点尚未开启在线支付。"}
        {/pv:if}
        {pv:else}
        <div class="table-responsive">
            <table class="table table-hover align-middle pv-member-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>时间</th>
                        <th>类型</th>
                        <th>说明</th>
                        <th class="text-end">金额</th>
                        <th>状态</th>
                        <th class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    {pv:foreach name="purchase_list" item="row"}
                    <tr>
                        <td class="text-nowrap small text-muted">{$row.created_at}</td>
                        <td><span class="badge text-bg-light border">{$row.scene_label}</span></td>
                        <td class="small">
                            <div class="fw-semibold">{$row.title}</div>
                            <div class="text-muted">{$row.order_no}</div>
                        </td>
                        <td class="text-end fw-semibold">{$row.amount_text}</td>
                        <td class="small">
                            {pv:if name="row.is_paid"}
                            <span class="text-success">{$row.status_label}</span>
                            {pv:elseif name="row.is_pending"}
                            <span class="text-warning">{$row.status_label}</span>
                            {pv:else}
                            <span class="text-muted">{$row.status_label}</span>
                            {/pv:if}
                        </td>
                        <td class="text-end text-nowrap">
                            {pv:if name="row.show_continue_pay"}
                            <a href="{$row.continue_pay_url}" class="btn btn-sm btn-primary">继续支付</a>
                            {pv:else}
                            <span class="text-muted small">—</span>
                            {/pv:if}
                        </td>
                    </tr>
                    {/pv:foreach}
                </tbody>
            </table>
        </div>
        {pv:include file="member/_pager"}
        {/pv:if}
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
