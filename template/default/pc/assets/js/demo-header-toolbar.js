/**
 * 演示站顶栏：展开搜索、弹窗登录
 */
(function () {
  'use strict';

  function initHeaderSearch() {
    var root = document.querySelector('[data-pv-header-search]');
    if (!root) {
      return;
    }
    var trigger = root.querySelector('.pv-portal-header-search__trigger');
    var form = root.querySelector('.pv-portal-header-search__form');
    var input = root.querySelector('.pv-portal-header-search__input');
    if (!trigger || !form || !input) {
      return;
    }

    function setOpen(open) {
      root.classList.toggle('is-open', open);
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      form.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) {
        window.setTimeout(function () {
          input.focus();
        }, 60);
      }
    }

    function close() {
      setOpen(false);
    }

    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (root.classList.contains('is-open')) {
        if (input.value.trim() !== '') {
          form.requestSubmit();
        } else {
          close();
        }
      } else {
        setOpen(true);
      }
    });

    form.addEventListener('submit', function () {
      if (!input.value.trim()) {
        return;
      }
      close();
    });

    window.setTimeout(function () {
      document.addEventListener('click', function (e) {
        if (!root.classList.contains('is-open')) {
          return;
        }
        if (!root.contains(e.target)) {
          close();
        }
      });
    }, 0);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        close();
      }
    });
  }

  function initPortalLoginModal() {
    var modalEl = document.getElementById('pvPortalLoginModal');
    var form = document.getElementById('pv-portal-login-form');
    if (!modalEl || !form) {
      return;
    }

    var alertEl = document.getElementById('pv-portal-login-alert');
    var redirectInput = document.getElementById('pv-portal-login-redirect');
    var captchaImg = form.querySelector('.pv-captcha-img');
    var captchaBtn = form.querySelector('.pv-portal-login-captcha-btn');
    var loginUrl = modalEl.getAttribute('data-login-post') || '/api/v1/member/login';
    var centerUrl = modalEl.getAttribute('data-member-center') || '/member/center';

    function refreshCaptcha() {
      if (!captchaImg) {
        return;
      }
      captchaImg.src = captchaImg.src.split('?')[0] + '?t=' + Date.now();
    }

    if (captchaBtn && captchaImg) {
      captchaBtn.addEventListener('click', refreshCaptcha);
    }

    modalEl.addEventListener('show.bs.modal', function () {
      var currentUrl = window.location.href;
      if (redirectInput) {
        redirectInput.value = currentUrl;
      }
      modalEl.querySelectorAll('.pv-social-logins a[href]').forEach(function (anchor) {
        try {
          var u = new URL(anchor.href, window.location.origin);
          if (u.searchParams.has('redirect')) {
            u.searchParams.set('redirect', currentUrl);
            anchor.href = u.toString();
          }
        } catch (err) {
          /* ignore */
        }
      });
      if (alertEl) {
        alertEl.className = 'alert d-none py-2';
        alertEl.textContent = '';
      }
      try {
        var oauthErr = new URLSearchParams(window.location.search).get('oauth_error');
        if (oauthErr && alertEl) {
          alertEl.textContent = oauthErr;
          alertEl.className = 'alert alert-danger py-2';
        }
      } catch (err) {
        /* ignore */
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (alertEl) {
        alertEl.className = 'alert d-none py-2';
      }
      fetch(loginUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          var payload = window.PivarkApi ? PivarkApi.ajaxPayload(res) : res || {};
          var ok = window.PivarkApi
            ? PivarkApi.ajaxOk(res)
            : res &&
              typeof res === 'object' &&
              !res.error &&
              Object.prototype.hasOwnProperty.call(res, 'data');
          if (ok) {
            window.location.href = payload.redirect || res.redirect || centerUrl;
            return;
          }
          if (alertEl) {
            alertEl.textContent = window.PivarkApi
              ? PivarkApi.ajaxMsg(res, '登录失败')
              : res.msg || '登录失败';
            alertEl.className = 'alert alert-danger py-2';
          }
          refreshCaptcha();
        })
        .catch(function () {
          if (alertEl) {
            alertEl.textContent = '网络错误，请稍后重试';
            alertEl.className = 'alert alert-danger py-2';
          }
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initHeaderSearch();
      initPortalLoginModal();
    });
  } else {
    initHeaderSearch();
    initPortalLoginModal();
  }
})();
