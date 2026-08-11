<!--
pv:template
label: 代登录确认
hint: 后台签发令牌后，会员确认进入个人中心
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
<link href="{$member_asset}/css/member-center.css?v={$member_asset_ver}" rel="stylesheet">
</head>
<body class="pv-member-body pv-member-auth-page">
{pv:include file="partials/auth_shell_open"}
                <div class="pv-member-auth__card">
                    <header class="pv-member-auth__head">
                        <h1 id="pv-member-auth-title" class="pv-member-auth__title">确认进入会员中心</h1>
                        <p class="pv-member-auth__subtitle">管理员已为您签发一次性进入链接，请点击下方按钮继续。</p>
                    </header>

                    <form method="post" action="{$enter_as_action}" class="pv-member-auth__form">
                        <input type="hidden" name="token" value="{$enter_as_token}">
                        <input type="hidden" name="{$front_csrf_field}" value="{$front_csrf_token}">
                        <button type="submit" class="btn btn-primary pv-member-auth__submit">确认进入</button>
                    </form>

                    <div class="pv-member-auth__foot pv-member-auth__foot--center">
                        <span class="pv-member-auth__foot-muted">链接约 2 分钟内有效，请勿转发给他人。</span>
                    </div>
                </div>
{pv:include file="partials/auth_shell_close"}

{pv:include file="partials/footer"}

