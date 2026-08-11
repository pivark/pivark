/**
 * PivArk 自定表单 — AJAX 挂载（data-pv-form-slug / data-pv-form-ajax）
 */
(function () {
  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function renderFields(fields) {
    var html = '';
    (fields || []).forEach(function (f) {
      if (!f || !f.key) return;
      var key = f.key;
      var label = esc(f.label || key);
      var req = f.required ? ' required' : '';
      html +=
        '<div class="pv-form-field mb-3" data-field-key="' +
        esc(key) +
        '" data-field-type="' +
        esc(f.type || 'text') +
        '"><label class="form-label">' +
        label +
        '</label>';
      if (f.type === 'textarea') {
        html +=
          '<textarea class="form-control" name="' +
          esc(key) +
          '"' +
          req +
          ' rows="4"></textarea>';
      } else if (f.type === 'select' && Array.isArray(f.options)) {
        html += '<select class="form-select" name="' + esc(key) + '"' + req + '>';
        f.options.forEach(function (o) {
          html += '<option value="' + esc(o) + '">' + esc(o) + '</option>';
        });
        html += '</select>';
      } else if (
        (f.type === 'radio' || f.type === 'checkbox') &&
        Array.isArray(f.options)
      ) {
        var nameAttr = f.type === 'checkbox' ? esc(key) + '[]' : esc(key);
        html += '<div class="pv-form-options">';
        f.options.forEach(function (o, i) {
          var oneReq = f.type === 'checkbox' && i === 0 ? req : f.type === 'radio' ? req : '';
          var id = esc(key) + '-' + i;
          html +=
            '<div class="form-check"><input class="form-check-input" type="' +
            f.type +
            '" name="' +
            nameAttr +
            '" id="' +
            id +
            '" value="' +
            esc(o) +
            '"' +
            oneReq +
            '><label class="form-check-label" for="' +
            id +
            '">' +
            esc(o) +
            '</label></div>';
        });
        html += '</div>';
      } else {
        var t = ['email', 'tel', 'number'].indexOf(f.type) >= 0 ? f.type : 'text';
        html +=
          '<input class="form-control" type="' +
          t +
          '" name="' +
          esc(key) +
          '"' +
          req +
          '>';
      }
      html += '</div>';
    });
    return html;
  }

  function mount(el) {
    var slug = el.getAttribute('data-pv-form-slug');
    if (!slug) return;
    fetch('/api/v1/forms/' + encodeURIComponent(slug))
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        var form = res.data || res.form || res;
        if (!form || !form.id) {
          el.innerHTML = '<p class="text-muted">表单不可用</p>';
          return;
        }
        var title = form.title ? '<h3 class="pv-form-title">' + esc(form.title) + '</h3>' : '';
        el.innerHTML =
          '<div class="pv-form pv-form--ajax" data-form-id="' +
          form.id +
          '"><form class="pv-form-inner">' +
          title +
          renderFields(form.fields) +
          '<div class="pv-form-actions"><button type="submit" class="btn btn-primary">提交</button></div>' +
          '<p class="pv-form-msg small text-muted mt-2" hidden></p></form></div>';
        var formEl = el.querySelector('form');
        formEl.addEventListener('submit', function (ev) {
          ev.preventDefault();
          var fd = new FormData(formEl);
          var body = { form_id: form.id };
          fd.forEach(function (v, k) {
            body[k] = v;
          });
          fetch('/api/v1/forms/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          })
            .then(function (r) {
              return r.json();
            })
            .then(function (out) {
              var msg = el.querySelector('.pv-form-msg');
              if (msg) {
                msg.hidden = false;
                msg.textContent = out.msg || (out.code === 1 ? '提交成功' : '提交失败');
                msg.className =
                  'pv-form-msg small mt-2 ' + (out.code === 1 ? 'text-success' : 'text-danger');
              }
              if (out.code === 1) formEl.reset();
            });
        });
      })
      .catch(function () {
        el.innerHTML = '<p class="text-danger">表单加载失败</p>';
      });
  }

  function boot() {
    document.querySelectorAll('[data-pv-form-slug][data-pv-form-ajax]').forEach(mount);
    document.querySelectorAll('.pv-form-slot[data-pv-form-slug]').forEach(function (slot) {
      var sel = slot.getAttribute('data-pv-form-selector');
      var target = sel ? document.querySelector(sel) : slot;
      if (!target) return;
      target.setAttribute('data-pv-form-slug', slot.getAttribute('data-pv-form-slug'));
      target.setAttribute('data-pv-form-ajax', '1');
      mount(target);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
