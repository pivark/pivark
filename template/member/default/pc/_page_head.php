<!--
  页面标题区：h1 使用控制器注入的 {$page_title}
  可选：{$member_page_lead} 副标题、{$member_page_toolbar|raw} 右侧操作按钮 HTML
-->
<header class="pv-member-page-head{pv:if name="member_page_toolbar"} pv-member-page-head--split{/pv:if}">
    <div class="pv-member-page-head__main">
        <h1 class="pv-member-page-head__title mb-0">{$page_title}</h1>
        {pv:if name="member_page_lead"}
        <p class="pv-member-page-head__lead mb-0 mt-2">{$member_page_lead}</p>
        {/pv:if}
    </div>
    {pv:if name="member_page_toolbar"}
    <div class="pv-member-page-head__toolbar flex-shrink-0">{$member_page_toolbar|raw}</div>
    {/pv:if}
</header>
