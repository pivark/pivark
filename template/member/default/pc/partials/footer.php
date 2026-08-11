<footer class="pv-member-site-footer border-top py-4 mt-4">
    <div class="container text-center text-muted small">
        <p class="mb-0">
            {pv:if empty="site_copyright"}© {$site_name}{pv:else}{$site_copyright}{/pv:if}
            {pv:if empty="site_icp"}{pv:else}
            · <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer">{$site_icp}</a>
            {/pv:if}
        </p>
    </div>
</footer>
{pv:if name="float_contact_enabled"}
{$float_contact_html|raw}
{/pv:if}
{pv:if name="site_overlay_ads_enabled"}
{$site_overlay_ads_html|raw}
{/pv:if}
<script src="{$vendor_bootstrap_js}"></script>
<script src="{$member_asset}/js/member.js?v={$member_asset_ver}" defer></script>
<input type="hidden" id="pv-front-csrf-token" value="{$front_csrf_token}">
</body>
</html>