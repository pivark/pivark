<!--
pv:template
label: 我的下载
hint: 会员已下载记录
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="您近期成功下载过的附件（需登录且有权访问）"}

        {pv:if name="download_items"}
        <div class="pv-download-history">
            {pv:foreach name="download_items" item="dl"}
            <div class="pv-download-history__item">
                <div class="pv-download-history__main">
                    <div class="pv-download-history__icon" aria-hidden="true">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                    </div>
                    <div>
                        <div class="fw-semibold">{$dl.label}</div>
                        {pv:if name="dl.title"}
                        <div class="small text-muted">{$dl.title}</div>
                        {/pv:if}
                        <div class="small text-muted mt-1">
                            {$dl.file_size_text}
                            {pv:if name="dl.download_count"}
                            · 已下 {$dl.download_count} 次
                            {/pv:if}
                        </div>
                    </div>
                </div>
                <div class="pv-download-history__action">
                    {pv:if name="dl.url"}
                    <a href="{$dl.url}" class="btn btn-sm btn-primary" rel="nofollow">
                        <i class="bi bi-download me-1"></i>再次下载
                    </a>
                    {/pv:if}
                    {pv:if name="dl.need_login"}
                    <a href="{$dl.login_url}" class="btn btn-sm btn-outline-secondary">登录后下载</a>
                    {/pv:if}
                    {pv:if name="dl.need_payment"}
                    <span class="btn btn-sm btn-outline-secondary disabled">需购买 ¥{$dl.price}</span>
                    {/pv:if}
                </div>
            </div>
            {/pv:foreach}
        </div>
        {/pv:if}

        {pv:if name="download_items_empty"}
        {pv:include file="member/_empty_state" member_empty_icon="bi-inbox" member_empty_title="暂无下载记录" member_empty_hint="浏览文档附件并成功下载后，会出现在这里。" member_empty_cta_url="{$home_url}" member_empty_cta_label="去首页浏览内容"}
        {/pv:if}
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
