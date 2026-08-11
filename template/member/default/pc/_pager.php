{pv:if name="ledger_show_pager"}
<nav class="pv-member-pager mt-4" aria-label="分页">
    <ul class="pagination justify-content-end mb-0">
        {pv:if name="ledger_has_prev"}
        <li class="page-item">
            <a class="page-link" href="?page={$ledger_page_prev}{pv:if name='ledger_biz_query'}&amp;{$ledger_biz_query}{/pv:if}">上一页</a>
        </li>
        {/pv:if}
        <li class="page-item disabled">
            <span class="page-link">第 {$ledger_page} / {$ledger_pages} 页 · 共 {$ledger_total} 条</span>
        </li>
        {pv:if name="ledger_has_next"}
        <li class="page-item">
            <a class="page-link" href="?page={$ledger_page_next}{pv:if name='ledger_biz_query'}&amp;{$ledger_biz_query}{/pv:if}">下一页</a>
        </li>
        {/pv:if}
    </ul>
</nav>
{/pv:if}
