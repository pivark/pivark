<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$seo_title}</title>
    <meta name="description" content="{$seo_description}">
    {pv:seo /}
    <link rel="icon" href="{$theme_asset}/favicon.ico" sizes="any">
    <link href="/static/common/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="/static/common/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{$theme_asset}/css/fonts.css?v={$theme_asset_ver}" rel="stylesheet">
    <link href="{$theme_asset}/css/demo.css?v={$theme_asset_ver}" rel="stylesheet">
    {pv:frontassets /}
    <script type="application/json" id="pv-front-script-urls">{$front_script_urls_json|raw}</script>
    <script>
        window.PV = window.PV || {};
        (function () {
            var el = document.getElementById('pv-front-script-urls');
            try { PV.urls = JSON.parse((el && el.textContent) || '{}'); } catch (e) { PV.urls = {}; }
            window.PV_URLS = PV.urls;
        })();
    </script>
    <script src="{$pv_kernel_urls_js}"></script>
    <script src="{$theme_asset}/js/pivark-api-client.js?v={$theme_asset_ver}"></script>
    <style>
        .pv-job-filters { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; margin-bottom: 1.25rem; }
        .pv-job-filters .form-label { font-size: .8rem; margin-bottom: .25rem; }
        .pv-job-card { border: 1px solid rgba(0,0,0,.08); border-radius: .5rem; background: #fff; padding: 0; }
        .pv-job-card > summary { list-style: none; cursor: pointer; padding: 1rem 1.25rem; }
        .pv-job-card > summary::-webkit-details-marker { display: none; }
        .pv-job-title { font-size: 1.1rem; margin: 0 0 .35rem; }
        .pv-job-meta { margin: 0; color: #6c757d; font-size: .9rem; }
        .pv-job-salary { color: #0d6efd; font-weight: 600; margin-left: .5rem; }
        .pv-job-body { padding: 0 1.25rem 1.25rem; border-top: 1px solid rgba(0,0,0,.06); }
        .pv-job-section { margin-top: 1rem; }
        .pv-job-section h3 { font-size: .95rem; margin-bottom: .35rem; }
        .pv-job-section p { white-space: pre-wrap; margin-bottom: 0; color: #495057; }
        .pv-job-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; }
        .pv-job-chip { font-size: .75rem; padding: .15rem .5rem; border-radius: 999px; background: #f1f3f5; color: #495057; }
    </style>
</head>
<body class="pv-portal-site pv-careers-hub">
{pv:include file="partials/header"}

<section class="pv-channel-hero pv-portal-page-hero pv-portal-hero--center" aria-label="招贤纳士">
    <div class="container">
        <h1 class="pv-channel-hero-title">{$page_title}</h1>
        <p class="pv-channel-hero-desc">加入我们 · 与现场一起成长</p>
    </div>
</section>

{pv:include file="partials/breadcrumb"}

<main class="pv-portal-main" aria-label="招聘岗位">
    <div class="container py-4">
        {pv:if name="talent_jobs_live"}
            <form class="pv-job-filters" method="get" action="{$nav_current_path}">
                <div>
                    <label class="form-label" for="talent-q">关键词</label>
                    <input id="talent-q" class="form-control form-control-sm" type="search" name="q" value="{$talent_filter_keyword}" placeholder="岗位 / 地点 / 要求">
                </div>
                <div>
                    <label class="form-label" for="talent-loc">地点</label>
                    <select id="talent-loc" class="form-select form-select-sm" name="location">
                        <option value="">全部地点</option>
                        {pv:foreach name="talent_location_options" item="loc"}
                        <option value="{$loc.value}" {pv:if name="loc.selected"}selected{/pv:if}>{$loc.label}</option>
                        {/pv:foreach}
                    </select>
                </div>
                <div>
                    <label class="form-label" for="talent-type">用工类型</label>
                    <select id="talent-type" class="form-select form-select-sm" name="employment_type">
                        <option value="">全部类型</option>
                        {pv:foreach name="talent_employment_options" item="eto"}
                        <option value="{$eto.value}" {pv:if name="eto.selected"}selected{/pv:if}>{$eto.label}</option>
                        {/pv:foreach}
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">筛选</button>
                <a class="btn btn-sm btn-outline-secondary" href="{$nav_current_path}">重置</a>
            </form>

            {pv:if name="talent_jobs_empty"}
                <div class="alert alert-light border">暂无符合条件的岗位。欢迎调整筛选，或将简历发至人事邮箱。</div>
            {pv:else}
                <p class="text-muted small mb-3">共 {$talent_jobs_total} 个在招岗位 · 点击展开查看详情</p>
                <div class="d-flex flex-column gap-3">
                    {pv:foreach name="talent_jobs_items" item="job" key="k"}
                    <details class="pv-job-card">
                        <summary>
                            <h2 class="pv-job-title">
                                {$job.title}
                                {pv:if name="job.salary_text"}<span class="pv-job-salary">{$job.salary_text}</span>{/pv:if}
                            </h2>
                            <p class="pv-job-meta">{$job.meta_line}</p>
                            <div class="pv-job-chips">
                                {pv:if name="job.employment_type_label"}<span class="pv-job-chip">{$job.employment_type_label}</span>{/pv:if}
                                {pv:if name="job.experience"}<span class="pv-job-chip">经验：{$job.experience}</span>{/pv:if}
                                {pv:if name="job.education"}<span class="pv-job-chip">学历：{$job.education}</span>{/pv:if}
                            </div>
                        </summary>
                        <div class="pv-job-body">
                            {pv:if name="job.responsibilities"}
                            <div class="pv-job-section">
                                <h3>岗位职责</h3>
                                <p>{$job.responsibilities}</p>
                            </div>
                            {/pv:if}
                            {pv:if name="job.requirements"}
                            <div class="pv-job-section">
                                <h3>任职要求</h3>
                                <p>{$job.requirements}</p>
                            </div>
                            {/pv:if}
                            {pv:if name="job.benefits"}
                            <div class="pv-job-section">
                                <h3>福利待遇</h3>
                                <p>{$job.benefits}</p>
                            </div>
                            {/pv:if}
                            <div class="pv-job-section d-flex flex-wrap align-items-center gap-2">
                                {pv:if name="job.apply_mailto"}
                                <a class="btn btn-sm btn-primary" href="mailto:{$job.apply_mailto}?subject=应聘-{$job.title}">
                                    <i class="bi bi-envelope" aria-hidden="true"></i> 邮件投递
                                </a>
                                {/pv:if}
                                {pv:if name="job.apply_url"}
                                <a class="btn btn-sm btn-outline-primary" href="{$job.apply_url}" target="_blank" rel="noopener">在线投递</a>
                                {/pv:if}
                                {pv:if name="job.apply_note"}
                                <span class="text-muted small">{$job.apply_note}</span>
                                {/pv:if}
                            </div>
                        </div>
                    </details>
                    {/pv:foreach}
                </div>
            {/pv:if}
        {pv:else}
            <div class="alert alert-warning">招聘插件未启用。请在插件中心启用「招聘」。</div>
        {/pv:if}
    </div>
</main>

{pv:include file="partials/footer"}
<script src="/static/common/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
<script src="{$theme_asset}/js/demo.js?v={$theme_asset_ver}" defer></script>
</body>
</html>
