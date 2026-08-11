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
</head>
<body class="pv-portal-site pv-ask-hub">
{pv:include file="partials/header"}

<section class="pv-channel-hero pv-portal-page-hero pv-portal-hero--center" aria-label="问答中心">
    <div class="container">
        <h1 class="pv-channel-hero-title">{$page_title}</h1>
        <p class="pv-channel-hero-desc">常见问题与用户提问 · 站长回复后公开展示</p>
    </div>
</section>

{pv:include file="partials/breadcrumb"}

<main class="pv-portal-main" aria-label="问答列表">
    <div class="container py-4">
        {pv:if name="ask_hub_live"}
            {pv:if name="ask_hub_empty"}
                <div class="alert alert-light border mb-4">暂无已公开问答。你可以在下方提交问题，站长回复后会显示在这里。</div>
            {pv:else}
                <p class="text-muted small mb-3">共 {$ask_hub_total} 条已答复</p>
                <div class="pv-ask-hub-list accordion mb-4" id="pvAskHub">
                    {pv:foreach name="ask_hub_items" item="item" key="k"}
                    <details class="card shadow-sm mb-2 pv-ask-item">
                        <summary class="card-header bg-white">
                            {pv:if name="item.hub_pinned"}<span class="badge text-bg-warning me-1">置顶</span>{/pv:if}
                            {pv:if name="item.hub_essence"}<span class="badge text-bg-primary me-1">精华</span>{/pv:if}
                            {pv:if name="item.is_user"}<span class="badge text-bg-secondary me-1">用户问</span>{/pv:if}
                            {$item.question}
                        </summary>
                        <div class="card-body pv-ask-answer">
                            {pv:if name="item.asker_name"}<p class="small text-muted mb-2">提问人：{$item.asker_name}</p>{/pv:if}
                            {$item.answer|raw}
                        </div>
                    </details>
                    {/pv:foreach}
                </div>
            {/pv:if}

            {pv:if name="ask_user_ask_open"}
            <section class="pv-ask-submit card border-0 shadow-sm" aria-label="我要提问" id="pv-ask-submit">
                <div class="card-body">
                    <h2 class="h5 mb-2">我要提问</h2>
                    <p class="text-muted small mb-3">提交后进入待答队列，站长回复并发布后会出现在上方列表。</p>
                    <form id="pv-ask-submit-form" class="pv-ask-submit-form">
                        <input type="hidden" name="{$front_csrf_field}" value="{$front_csrf_token}">
                        <div class="mb-3">
                            <label class="form-label" for="pv-ask-question">问题</label>
                            <textarea class="form-control" id="pv-ask-question" name="question" rows="3" maxlength="500" required placeholder="一句话说明你的问题…"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pv-ask-asker">昵称</label>
                            <input type="text" class="form-control" id="pv-ask-asker" name="asker_name" maxlength="80" required placeholder="怎么称呼你">
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary" id="pv-ask-submit-btn">提交问题</button>
                            <span class="small text-muted" id="pv-ask-submit-msg" role="status" aria-live="polite"></span>
                        </div>
                    </form>
                </div>
            </section>
            {/pv:if}
        {pv:else}
            <div class="alert alert-warning">问答插件未启用或未开放广场。请在插件中心启用「问答」并打开 FAQ 广场。</div>
        {/pv:if}
    </div>
</main>

{pv:include file="partials/footer"}
<script src="/static/common/vendor/bootstrap/js/bootstrap.bundle.min.js" defer></script>
<script src="{$theme_asset}/js/demo.js?v={$theme_asset_ver}" defer></script>
{pv:if name="ask_user_ask_open"}
<script>
(function () {
  var form = document.getElementById('pv-ask-submit-form');
  if (!form) return;
  var btn = document.getElementById('pv-ask-submit-btn');
  var msg = document.getElementById('pv-ask-submit-msg');
  var csrfField = form.querySelector('input[type="hidden"]');
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (btn) btn.disabled = true;
    if (msg) msg.textContent = '提交中…';
    var body = new FormData(form);
    fetch('/api/v1/plugins/doc_ask/submit', {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (pack) {
        var j = pack.j || {};
        var err = j.error || {};
        var data = j.data;
        if (pack.ok && data) {
          if (msg) msg.textContent = (typeof j.message === 'string' && j.message) ? j.message : '已提交，等待站长回复';
          form.reset();
          if (csrfField && j.data && j.data.csrf_token) {
            csrfField.value = j.data.csrf_token;
          }
          return;
        }
        var m = (err && err.message) ? err.message : (j.message || '提交失败，请稍后重试');
        if (msg) msg.textContent = m;
      })
      .catch(function () {
        if (msg) msg.textContent = '网络异常，请稍后重试';
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  });
})();
</script>
{/pv:if}
</body>
</html>
