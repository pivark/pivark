/**
 * PivArk 内核 — 点赞收藏条（L1 favorite）
 * @namespace PV.modules.favorite
 */
(function (PV, pvUrl) {
  'use strict';

  function toast(msg, ok) {
    var box = document.getElementById('pvFavoriteToast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'pvFavoriteToast';
      box.setAttribute('role', 'alert');
      document.body.appendChild(box);
    }
    box.className = 'pv-favorite-toast alert alert-' + (ok ? 'success' : 'warning');
    box.textContent = msg;
    box.style.display = 'block';
    clearTimeout(box._timer);
    box._timer = setTimeout(function () {
      box.style.display = 'none';
    }, 2800);
  }

  function loginUrl() {
    var link = document.querySelector('a[href*="/member/login"], a[href*="/login"]');
    return link ? link.getAttribute('href') : pvUrl('memberLogin', '/member/login');
  }

  function applyStats(bar, stats) {
    if (!stats) return;
    var likeBtn = bar.querySelector('[data-action="like"]');
    var collectBtn = bar.querySelector('[data-action="collect"]');
    var likeCount = bar.querySelector('.pv-favorite-like');
    var collectCount = bar.querySelector('.pv-favorite-collect');
    if (likeCount) likeCount.textContent = String(stats.like_count != null ? stats.like_count : 0);
    if (collectCount) collectCount.textContent = String(stats.collect_count != null ? stats.collect_count : 0);
    if (likeBtn) {
      var liked = !!stats.liked;
      likeBtn.classList.toggle('is-active', liked);
      likeBtn.setAttribute('aria-pressed', liked ? 'true' : 'false');
      var icon = likeBtn.querySelector('i.bi');
      if (icon) icon.className = 'bi bi-heart' + (liked ? '-fill' : '');
    }
    if (collectBtn) {
      var collected = !!stats.collected;
      collectBtn.classList.toggle('is-active', collected);
      collectBtn.setAttribute('aria-pressed', collected ? 'true' : 'false');
      var cIcon = collectBtn.querySelector('i.bi');
      if (cIcon) cIcon.className = 'bi bi-bookmark' + (collected ? '-fill' : '');
    }
  }

  function postFavorite(action, documentId) {
    var path = action === 'collect'
      ? pvUrl('apiFavoriteCollect', '/api/v1/favorite/collect')
      : pvUrl('apiFavoriteLike', '/api/v1/favorite/like');
    var fd = new FormData();
    fd.append('document_id', String(documentId));
    return fetch(path, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  /** REST {data:stats} 与旧版 {code:1,stats} 兼容 */
  function parseFavoriteResponse(res) {
    if (!res) {
      return { ok: false, msg: '操作失败' };
    }
    if (res.data && typeof res.data === 'object'
      && ('like_count' in res.data || 'liked' in res.data || 'collect_count' in res.data)) {
      return { ok: true, stats: res.data };
    }
    if (res.code === 1 || res.code === '1') {
      return { ok: true, stats: res.stats || res.data || null };
    }
    var errCode = res.error && res.error.code;
    var msg = (res.error && res.error.message) || res.msg || '操作失败';
    if (msg === 'login_required' || errCode === 'auth_required') {
      return { ok: false, loginRequired: true, msg: msg };
    }
    return { ok: false, msg: msg };
  }

  function onFavoriteClick(ev) {
    var btn = ev.currentTarget;
    var bar = btn.closest('.pv-favorite-bar');
    if (!bar || btn.disabled) return;
    var documentId = parseInt(bar.getAttribute('data-document-id') || '0', 10);
    var action = btn.getAttribute('data-action') || 'like';
    if (documentId < 1) return;

    btn.disabled = true;
    postFavorite(action, documentId)
      .then(function (res) {
        var parsed = parseFavoriteResponse(res);
        if (!parsed.ok) {
          if (parsed.loginRequired) {
            toast('请先登录后再操作', false);
            if (window.confirm('需要登录，是否前往登录？')) {
              window.location.href = loginUrl();
            }
            return;
          }
          toast(parsed.msg || '操作失败', false);
          return;
        }
        applyStats(bar, parsed.stats);
      })
      .catch(function () {
        toast('网络错误，请稍后重试', false);
      })
      .finally(function () {
        btn.disabled = false;
      });
  }

  function init() {
    document.querySelectorAll('.pv-favorite-bar__btn').forEach(function (btn) {
      if (btn._pvFavoriteBound) return;
      btn._pvFavoriteBound = true;
      btn.addEventListener('click', onFavoriteClick);
    });
  }
  PV.modules = PV.modules || {};
  PV.modules.favorite = { init: init };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window.PV = window.PV || {}, window.pvUrl);
