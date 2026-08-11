<div class="pv-member-section-head d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h2 class="pv-member-section-head__title h6 mb-0">{$section_title}</h2>
    {pv:if name="section_action_url"}
    <a href="{$section_action_url}" class="btn btn-sm btn-outline-primary">{$section_action_label}</a>
    {/pv:if}
    {pv:if name="section_action_html"}
    <div class="pv-member-section-head__actions">{$section_action_html|raw}</div>
    {/pv:if}
</div>
{pv:if name="section_desc"}
<p class="text-muted small mb-3">{$section_desc}</p>
{/pv:if}
