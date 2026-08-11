{pv:include file="member/_head"}
{pv:include file="partials/header"}

<section class="py-2 pv-member-page">
    <div class="container">
        <div class="d-lg-none mb-3">
            <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="offcanvas" data-bs-target="#pvMemberNavOffcanvas" aria-controls="pvMemberNavOffcanvas">
                <i class="bi bi-list me-1"></i>会员中心菜单
            </button>
        </div>
        <div class="pv-member-breadcrumb mb-3">
            {pv:breadcrumb /}
        </div>
        <div class="row g-3 pv-member-layout">
            <div class="col-12 col-lg-3 pv-member-layout__side">
                <div class="offcanvas-lg offcanvas-start pv-member-nav-offcanvas" tabindex="-1" id="pvMemberNavOffcanvas" aria-labelledby="pvMemberNavOffcanvasLabel">
                    <div class="offcanvas-header border-bottom d-lg-none">
                        <h2 class="offcanvas-title h6 mb-0" id="pvMemberNavOffcanvasLabel">会员中心</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#pvMemberNavOffcanvas" aria-label="关闭"></button>
                    </div>
                    <div class="offcanvas-body p-0 p-lg-0">
                        {pv:include file="member/_sidebar"}
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-9 pv-member-layout__main pv-member-main">
                {pv:include file="member/_member_expire_notice"}
                {pv:if name="member_flash_msg"}
                <div class="alert alert-warning mb-3" role="alert">{$member_flash_msg}</div>
                {/pv:if}
                {pv:if name="member_show_asset_bar"}
                {pv:include file="member/_member_asset_bar"}
                {/pv:if}
