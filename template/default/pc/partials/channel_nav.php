<!-- 侧栏块：栏目导航 · AD-031 真分类 site_nav / {pv:nav} -->
<div class="card shadow-sm pv-side-block pv-tags-nav">
    <div class="card-body">
        <h3 class="card-title h6 sidebar-title"><i class="bi bi-grid-3x3-gap"></i> 栏目导航</h3>
        <nav class="list-group list-group-flush channel-nav pv-side-list pv-side-list--nav pv-channel-nav-tree" aria-label="栏目导航">
            {pv:nav item="t" currentclass="active" content_kind="document,product"}
            {pv:if name="t.has_children"}
            <details class="pv-channel-nav-branch{pv:if name='t.is_active_branch'} is-open{/pv:if}"{pv:if name="t.is_active_branch"} open{/pv:if}>
                <summary class="pv-channel-nav-summary">
                    <a href="{$t.url}" class="list-group-item list-group-item-action channel-link {$t.nav_class}" onclick="event.stopPropagation()"><i class="bi bi-folder2"></i> {$t.title}</a>
                    <span class="pv-channel-nav-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
                </summary>
                <div class="pv-channel-nav-children">
                    {pv:nav name="t.children" item="c" currentclass="active"}
                    {pv:if name="c.has_children"}
                    <details class="pv-channel-nav-branch{pv:if name='c.is_active_branch'} is-open{/pv:if}"{pv:if name="c.is_active_branch"} open{/pv:if}>
                        <summary class="pv-channel-nav-summary">
                            <a href="{$c.url}" class="list-group-item list-group-item-action channel-link {$c.nav_class}" onclick="event.stopPropagation()"><i class="bi bi-folder2"></i> {$c.title}</a>
                            <span class="pv-channel-nav-chevron" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
                        </summary>
                        <div class="pv-channel-nav-children">
                            {pv:nav name="c.children" item="g" currentclass="active"}
                            <a href="{$g.url}" class="list-group-item list-group-item-action channel-link {$g.nav_class}"><i class="bi bi-folder"></i> {$g.title}</a>
                            {/pv:nav}
                        </div>
                    </details>
                    {pv:else}
                    <a href="{$c.url}" class="list-group-item list-group-item-action channel-link {$c.nav_class}"><i class="bi bi-folder"></i> {$c.title}</a>
                    {/pv:if}
                    {/pv:nav}
                </div>
            </details>
            {pv:else}
            <a href="{$t.url}" class="list-group-item list-group-item-action channel-link {$t.nav_class}"><i class="bi bi-folder"></i> {$t.title}</a>
            {/pv:if}
            {/pv:nav}
        </nav>
    </div>
</div>
