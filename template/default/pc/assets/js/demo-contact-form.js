/**
 * 演示站 · 联系我们页 · 后台自定表单 AJAX 提交（slug=contact · 服务端渲染字段）
 * 页面：list_page_contact.php（.pv-form 简版标签）
 */
(function () {
  'use strict';

  function bindInlineForm(form) {
    var wrap = form.closest('.pv-form');
    if (!wrap || wrap.classList.contains('pv-form--ajax') || form.dataset.pvBound) {
      return;
    }
    form.dataset.pvBound = '1';

    var formId = wrap.getAttribute('data-form-id') || form.getAttribute('data-pv-form');
    var cardBody = wrap.parentElement;
    var msgEl =
      (cardBody && cardBody.querySelector('.pv-form-msg')) ||
      wrap.querySelector('.pv-form-msg');

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var body = { form_id: parseInt(formId, 10) || 0 };
      new FormData(form).forEach(function (v, k) {
        body[k] = v;
      });

      fetch(pvUrl('apiFormsSubmit', '/api/v1/forms/submit'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (out) {
          if (!msgEl) {
            return;
          }
          var ok = window.PivarkApi ? PivarkApi.ajaxOk(out) : false;
          msgEl.hidden = false;
          msgEl.textContent = window.PivarkApi
            ? PivarkApi.ajaxMsg(out, ok ? '提交成功' : '提交失败')
            : ok
              ? '提交成功'
              : '提交失败';
          msgEl.className =
            'pv-form-msg small mt-2 ' + (ok ? 'text-success' : 'text-danger');
          if (ok) {
            form.reset();
          }
        })
        .catch(function () {
          if (!msgEl) {
            return;
          }
          msgEl.hidden = false;
          msgEl.textContent = '网络错误，请稍后重试';
          msgEl.className = 'pv-form-msg small mt-2 text-danger';
        });
    });
  }

  document.querySelectorAll('.pv-form .pv-form-inner').forEach(bindInlineForm);
})();
