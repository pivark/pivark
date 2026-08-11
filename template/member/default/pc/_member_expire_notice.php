{pv:if name="member_expire_notice_show"}
<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3" role="alert">
    <span class="small mb-0">{$member_expire_notice_msg}</span>
    <a href="{$member_recharge_url}" class="btn btn-sm btn-warning">立即续费</a>
</div>
{/pv:if}
