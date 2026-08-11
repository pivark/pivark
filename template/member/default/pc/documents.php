<!--
pv:template
label: 我的文章
hint: 会员发布的文档列表
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head"}

        {pv:if name="ledger_empty"}
        {pv:include file="member/_empty_state"}
        {pv:else}
        <div class="table-responsive">
            <table class="table table-hover align-middle pv-member-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>标题</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th class="text-end">操作</th>
                    </tr>
                </thead>
                <tbody>
                    {pv:foreach name="document_list" item="doc"}
                    <tr>
                        <td class="fw-semibold">{$doc.title}</td>
                        <td>
                            <span class="badge text-bg-light border">{$doc.status_text}</span>
                        </td>
                        <td class="small text-muted text-nowrap">{$doc.create_time}</td>
                        <td class="text-end text-nowrap">
                            <a href="{$doc.edit_url}" class="btn btn-sm btn-outline-primary">编辑</a>
                            {pv:if name="doc.front_url"}
                            <a href="{$doc.front_url}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">查看</a>
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
