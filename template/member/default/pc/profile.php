<!--
pv:template
label: 基本资料
hint: 会员资料编辑
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="完善昵称、联系方式与扩展资料"}
        {pv:include file="member/_section_head" section_title="基本资料"}
        <div id="pv-profile-alert" class="alert d-none" role="alert"></div>
        <form id="pv-profile-form" data-pv-submit="{$member_profile_url}">
            <input type="hidden" name="__token" value="{$front_csrf_token}">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">显示昵称</label>
                    <input type="text" name="nickname" class="form-control" value="{$member_profile.nickname}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">登录名</label>
                    <input type="text" class="form-control" value="{$member_profile.username}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">邮箱</label>
                    <input type="email" name="email" class="form-control" value="{$member_profile.email}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">手机</label>
                    <input type="text" name="mobile" class="form-control" value="{$member_profile.mobile}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">会员等级</label>
                    <input type="text" class="form-control" value="{$member_profile.member_level_name}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">会员到期</label>
                    {pv:if name="member_profile.member_level_has_expire"}
                    <input type="text" class="form-control" value="{$member_profile.member_level_expire_text}" disabled>
                    {pv:else}
                    <input type="text" class="form-control" value="永久有效" disabled>
                    {/pv:if}
                </div>
                <div class="col-md-6">
                    <label class="form-label">成长值</label>
                    <input type="text" class="form-control" value="{$member_profile.member_growth_text}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">账号类型</label>
                    <input type="text" class="form-control" value="{$member_profile.account_kind_label}" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">注册时间</label>
                    <input type="text" class="form-control" value="{$member_profile.created_at}" disabled>
                </div>
            </div>
            {pv:if name="member_profile.is_enterprise"}
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <h3 class="h6 mb-0">企业资料</h3>
                </div>
                <div class="col-md-6">
                    <label class="form-label">企业名称</label>
                    <input type="text" name="company_name" class="form-control" value="{$member_profile.enterprise.company_name}" required maxlength="200">
                </div>
                <div class="col-md-6">
                    <label class="form-label">联系人</label>
                    <input type="text" name="contact_name" class="form-control" value="{$member_profile.enterprise.contact_name}" required maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label">联系电话</label>
                    <input type="text" name="contact_phone" class="form-control" value="{$member_profile.enterprise.contact_phone}" required maxlength="32">
                </div>
                <div class="col-md-6">
                    <label class="form-label">统一社会信用代码</label>
                    <input type="text" name="usci" class="form-control" value="{$member_profile.enterprise.usci}" maxlength="32">
                </div>
                <div class="col-md-6">
                    <label class="form-label">职务</label>
                    <input type="text" name="job_title" class="form-control" value="{$member_profile.enterprise.job_title}" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label">企业邮箱</label>
                    <input type="email" name="company_email" class="form-control" value="{$member_profile.enterprise.company_email}" maxlength="120">
                </div>
            </div>
            {/pv:if}
            {pv:if name="member_can_upgrade_enterprise"}
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <h3 class="h6 mb-1">升级为企业账号</h3>
                    <p class="small text-muted mb-0">填写企业资料后保存，账号将变为企业类型（登录方式不变）。</p>
                </div>
                <input type="hidden" name="account_kind" value="enterprise" id="pv-upgrade-kind" disabled>
                <div class="col-md-6">
                    <label class="form-label">企业名称 <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" maxlength="200" disabled data-pv-upgrade-field>
                </div>
                <div class="col-md-6">
                    <label class="form-label">联系人 <span class="text-danger">*</span></label>
                    <input type="text" name="contact_name" class="form-control" maxlength="100" disabled data-pv-upgrade-field>
                </div>
                <div class="col-md-6">
                    <label class="form-label">联系电话 <span class="text-danger">*</span></label>
                    <input type="text" name="contact_phone" class="form-control" maxlength="32" disabled data-pv-upgrade-field>
                </div>
                <div class="col-md-6">
                    <label class="form-label">统一社会信用代码</label>
                    <input type="text" name="usci" class="form-control" maxlength="32" disabled data-pv-upgrade-field>
                </div>
                <div class="col-md-6">
                    <label class="form-label">职务</label>
                    <input type="text" name="job_title" class="form-control" maxlength="100" disabled data-pv-upgrade-field>
                </div>
                <div class="col-md-6">
                    <label class="form-label">企业邮箱</label>
                    <input type="email" name="company_email" class="form-control" maxlength="120" disabled data-pv-upgrade-field>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pv-enable-enterprise-upgrade">
                        <label class="form-check-label" for="pv-enable-enterprise-upgrade">我要升级为企业账号</label>
                    </div>
                </div>
            </div>
            {/pv:if}
            {$member_fields_html|raw}
            <button type="submit" class="btn btn-primary mt-3">保存资料</button>
        </form>
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
<script>
(function () {
  var box = document.getElementById('pv-enable-enterprise-upgrade');
  if (!box) return;
  var kind = document.getElementById('pv-upgrade-kind');
  var fields = document.querySelectorAll('[data-pv-upgrade-field]');
  function sync() {
    var on = !!box.checked;
    if (kind) kind.disabled = !on;
    fields.forEach(function (el) {
      el.disabled = !on;
      if (on && (el.name === 'company_name' || el.name === 'contact_name' || el.name === 'contact_phone')) {
        el.required = true;
      } else {
        el.required = false;
      }
    });
  }
  box.addEventListener('change', sync);
  sync();
})();
</script>