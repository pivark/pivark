<aside class="pv-member-sidebar">
    <div class="card pv-member-sidebar__card">
        <div class="card-body pv-member-sidebar__profile">
            <button
                type="button"
                class="pv-member-sidebar__avatar pv-member-sidebar__avatar--editable"
                id="pv-sidebar-avatar-btn"
                data-upload-url="{$member_avatar_upload_url}"
                data-profile-url="{$member_profile_url}"
                data-avatar-letter="{$member_avatar_letter}"
                aria-label="点击更换头像"
            >
                <img
                    src="{$member_profile.avatar}"
                    alt=""
                    class="pv-member-sidebar__avatar-img"
                    id="pv-sidebar-avatar-img"
                    {pv:if name="member_profile.avatar"}
                    {pv:else}
                    hidden
                    {/pv:if}
                >
                <span
                    class="pv-member-sidebar__avatar-letter"
                    id="pv-sidebar-avatar-letter"
                    {pv:if name="member_profile.avatar"}
                    hidden
                    {/pv:if}
                >{$member_avatar_letter}</span>
                <i
                    class="bi bi-person-fill pv-member-sidebar__avatar-icon"
                    id="pv-sidebar-avatar-icon"
                    {pv:if name="member_profile.avatar"}
                    hidden
                    {/pv:if}
                ></i>
                <span class="pv-member-sidebar__avatar-overlay" id="pv-sidebar-avatar-overlay">更换</span>
            </button>
            <input
                type="file"
                id="pv-sidebar-avatar-file"
                class="d-none"
                accept="image/jpeg,image/png,image/gif,image/webp,image/*"
            >
            <input type="hidden" id="pv-member-csrf-token" value="{$front_csrf_token}">
            <div class="pv-member-sidebar__meta">
                <h2 class="h6 mb-1">{$member_display_name}</h2>
                {pv:if name="member_username_text"}
                <p class="text-muted small mb-1">@{$member_username_text}</p>
                {/pv:if}
                {pv:if name="member_profile.member_level_name"}
                <span class="badge text-bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                    {$member_profile.member_level_name}
                </span>
                {/pv:if}
                {pv:if name="member_profile.member_level_has_expire"}
                <p class="text-muted small mb-0 mt-1">{$member_profile.member_level_expire_text}</p>
                {/pv:if}
                <p id="pv-sidebar-avatar-tip" class="text-danger small mb-0 mt-1 d-none" role="alert"></p>
            </div>
        </div>
    </div>

    <nav class="card pv-member-sidebar__card pv-member-sidebar__nav" aria-label="会员中心导航">
        <a href="{$member_center_url}" class="list-group-item list-group-item-action pv-member-nav-top{$member_nav_class_center}">
            <i class="bi bi-grid-1x2 me-2"></i>个人中心
        </a>
        <div class="pv-member-nav-accordion list-group list-group-flush pv-member-nav">
            {pv:foreach name="member_nav_groups" item="group"}
            <div class="pv-member-nav-group">
                <button
                    type="button"
                    class="pv-member-nav-group__toggle{pv:if name="group.expanded"}{pv:else} collapsed{/pv:if}"
                    data-bs-toggle="collapse"
                    data-bs-target="#pv-nav-{$group.id}"
                    aria-expanded="{pv:if name="group.expanded"}true{pv:else}false{/pv:if}"
                    aria-controls="pv-nav-{$group.id}"
                >
                    <span>{$group.label}</span>
                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                </button>
                <div class="collapse{pv:if name="group.expanded"} show{/pv:if}" id="pv-nav-{$group.id}">
                    {pv:foreach name="group.items" item="nav"}
                    <a href="{$nav.url}" class="list-group-item list-group-item-action{pv:if name="nav.active"} active{/pv:if}">
                        <i class="bi {$nav.icon} me-2"></i>{$nav.label}
                    </a>
                    {/pv:foreach}
                </div>
            </div>
            {/pv:foreach}
        </div>
    </nav>

    <a href="{$member_logout_url}" class="btn btn-outline-secondary w-100 pv-member-logout-btn">
        <i class="bi bi-box-arrow-right me-1"></i>退出登录
    </a>
</aside>
