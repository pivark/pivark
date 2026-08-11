<div class="pv-member-empty">
    <i class="bi {$member_empty_icon} pv-member-empty__icon" aria-hidden="true"></i>
    <p class="pv-member-empty__title mb-0">{$member_empty_title}</p>
    {pv:if name="member_empty_hint"}
    <p class="pv-member-empty__hint mb-0 mt-2">{$member_empty_hint}</p>
    {/pv:if}
    {pv:if name="member_empty_cta_url"}
    <a href="{$member_empty_cta_url}" class="btn btn-primary mt-4">{$member_empty_cta_label}</a>
    {/pv:if}
</div>
