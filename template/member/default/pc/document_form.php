<!--
pv:template
label: 发布文章
hint: 会员投稿表单
scope: member
-->
{pv:include file="member/_layout_start"}

{pv:include file="member/_panel_open"}
        {pv:include file="member/_page_head" member_page_lead="保存后为草稿，可在「我的文章」中继续编辑"}

        <div id="pv-document-alert" class="alert d-none" role="alert"></div>
        <form id="pv-document-form" data-pv-submit="{$member_document_save_url}">
            <input type="hidden" name="__token" value="{$front_csrf_token}">
            <input type="hidden" name="id" value="{$member_document.id}">
            <div class="mb-3">
                <label class="form-label">标题 <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required maxlength="200" value="{$member_document.title}">
            </div>
            <div class="mb-3">
                <label class="form-label">主栏目 <span class="text-danger">*</span></label>
                <select name="nav_id" class="form-select" required>
                    <option value="">请选择栏目</option>
                    {pv:foreach name="member_category_options" item="cat"}
                    <option value="{$cat.id}">{$cat.label}</option>
                    {/pv:foreach}
                </select>
                <div class="form-text">投稿必须选择栏目；正式发文入口为会员中心 Vue 编辑页。</div>
            </div>
            <div class="mb-3">
                <label class="form-label">摘要</label>
                <textarea name="summary" class="form-control" rows="2" maxlength="500">{$member_document.summary}</textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">正文</label>
                <textarea name="content" class="form-control" rows="14">{$member_document.content}</textarea>
                <div class="form-text">支持 HTML；完整富文本编辑器可在后续版本接入。</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">保存草稿</button>
                <a href="{$member_documents_url}" class="btn btn-outline-secondary">返回列表</a>
            </div>
        </form>
{pv:include file="member/_panel_end"}

{pv:include file="member/_layout_end"}
