{pv:if name="pagination_total"}
<nav aria-label="分页" class="pagination-nav mt-4" aria-live="polite">
    {pv:if name="pagination_show"}
    <ul class="pagination justify-content-center mb-0">
{pv:page item="pg" with_prevnext="1" currentclass="active" show_summary="0"}
        {pv:if name="pg.ellipsis"}
            <li class="page-item disabled"><span class="page-link">{$pg.label}</span></li>
        {pv:else}
            {pv:if name="pg.active"}
                <li class="page-item {$pg.currentclass}"><span class="page-link" aria-current="page">{$pg.label}</span></li>
            {pv:else}
                <li class="page-item"><a class="page-link" href="{$pg.url}">{$pg.label}</a></li>
            {/pv:if}
        {/pv:if}
{/pv:page}
    </ul>
    {/pv:if}
    {pv:if empty="pagination_show"}
    <p class="text-center text-muted small mb-0">共 {$pagination_total} 条</p>
    {pv:else}
    <p class="text-center text-muted small mt-2 mb-0">共 {$pagination_total} 条 · 第 {$pagination_page} / {$pagination_total_pages} 页</p>
    {/pv:if}
</nav>
{/pv:if}
