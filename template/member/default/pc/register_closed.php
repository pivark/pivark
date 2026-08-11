<!--
pv:template
label: 注册关闭
hint: 站点关闭注册时的提示页
scope: member
-->
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$seo_title}</title>
    <meta name="description" content="{$seo_description}">
    {pv:if name="seo_keywords"}
    <meta name="keywords" content="{$seo_keywords}">
    {/pv:if}
    {pv:seo /}
    {pv:include file="partials/vendor_head"}
</head>
<body>
{pv:include file="partials/header"}
{pv:breadcrumb /}

<section class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <h1 class="h3 mb-4 text-center">{$page_title}</h1>
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 text-center">
                        <p class="text-muted mb-3">站点暂未开放新用户注册。如需开通账号，请联系站点管理员。</p>
                        <ul class="list-unstyled small text-muted text-start mb-4">
                            {pv:if name="site_phone"}
                            <li class="mb-2"><i class="bi bi-telephone me-2"></i>电话：<a href="tel:{$site_phone}">{$site_phone}</a></li>
                            {/pv:if}
                            {pv:if name="site_email"}
                            <li class="mb-2"><i class="bi bi-envelope me-2"></i>邮箱：<a href="mailto:{$site_email}">{$site_email}</a></li>
                            {/pv:if}
                            {pv:if name="site_address"}
                            <li class="mb-0"><i class="bi bi-geo-alt me-2"></i>地址：{$site_address}</li>
                            {/pv:if}
                        </ul>
                        <a href="{$member_login_url}" class="btn btn-primary">返回登录</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{pv:include file="partials/footer"}
