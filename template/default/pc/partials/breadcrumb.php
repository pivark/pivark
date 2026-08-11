        <!-- 区块：面包屑（nav landmark；勿再套 container） -->
        {pv:if name="breadcrumbs"}
        <nav class="breadcrumb-nav py-3" aria-label="面包屑">
            <ol class="breadcrumb">
                {pv:breadcrumb item="bc"}
                    {pv:if name="bc.active"}
                        <li class="breadcrumb-item active" aria-current="page">{$bc.title}</li>
                    {pv:else}
                        {pv:if empty="bc.url"}
                            <li class="breadcrumb-item">{$bc.title}</li>
                        {pv:else}
                            <li class="breadcrumb-item"><a href="{$bc.url}">{$bc.title}</a></li>
                        {/pv:if}
                    {/pv:if}
                {/pv:breadcrumb}
            </ol>
        </nav>
        {/pv:if}
