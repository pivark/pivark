<!--
pv:template
label: 我的观影
hint: 已购视频单集/全集授权
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="您已购买的视频单集或全集，可从关联文档继续观看"}

        {pv:if name="continue_empty"}
        {pv:else}
        <div class="mb-4">
            <h2 class="pv-member-subsection-title">继续观看</h2>
            {pv:foreach name="continue_list" item="cp"}
            <div class="d-flex flex-wrap gap-2 align-items-center border rounded p-2 mb-2 small pv-viewing-continue__item">
                {pv:if name="cp.cover_url"}
                <img src="{$cp.cover_url}" alt="" class="pv-viewing-continue__cover rounded" loading="lazy" width="80" height="45">
                {/pv:if}
                <div class="flex-grow-1 min-w-0">
                    <strong>{$cp.title}</strong>
                    {pv:if name="cp.series_title"}
                    <span class="text-muted"> · {$cp.series_title}</span>
                    {/pv:if}
                    <div class="text-muted">看到 {$cp.position_label} · {$cp.updated_at}</div>
                </div>
                {pv:if name="cp.document_url"}
                <a href="{$cp.document_url}" class="btn btn-sm btn-primary">续播</a>
                {/pv:if}
            </div>
            {/pv:foreach}
        </div>
        {/pv:if}

        <h2 class="pv-member-subsection-title">已购授权</h2>
        {pv:if name="ledger_empty"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-play-btn" member_empty_title="暂无观影授权" member_empty_hint="购买付费视频后，授权会记录在此。"}
        {pv:else}
        <div class="pv-viewing-list">
            {pv:foreach name="viewing_list" item="row"}
            <div class="pv-viewing-list__item border rounded p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <span class="badge text-bg-primary-subtle text-primary-emphasis border me-2">{$row.grant_type_label}</span>
                        <strong>{$row.title}</strong>
                        {pv:if name="row.series_title"}
                        <div class="small text-muted mt-1">剧集：{$row.series_title}</div>
                        {/pv:if}
                    </div>
                    <div class="text-end small text-muted">
                        <div>{$row.created_at}</div>
                        {pv:if name="row.amount"}
                        <div class="text-danger">{$row.amount_text}</div>
                        {/pv:if}
                    </div>
                </div>
                <div class="mt-2">
                    {pv:if name="row.has_doc_link"}
                    <a href="{$row.document_url}" class="btn btn-sm btn-primary">
                        <i class="bi bi-play-circle me-1"></i>前往观看
                    </a>
                    {pv:else}
                    <span class="small text-muted">未绑定文档页，请联系站长获取观看入口</span>
                    {/pv:if}
                </div>
            </div>
            {/pv:foreach}
        </div>
        {pv:include file="member/_pager"}
        {/pv:if}
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
