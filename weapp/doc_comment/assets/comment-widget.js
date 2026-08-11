/** PivArk weapp — comment widget
 * @namespace PV.modules.comment
 */
(function (PV, pvUrl) {
  'use strict';
  PV = PV || {};
  PV.modules = PV.modules || {};

(function () {
  function qs(el, sel) { return el.querySelector(sel); }
  function qsa(el, sel) { return Array.prototype.slice.call(el.querySelectorAll(sel)); }

  function api(path, opts) {
    opts = opts || {};
    return fetch(path, {
      method: opts.method || 'GET',
      headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, opts.headers || {}),
      body: opts.body || null,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function apiOk(res) {
    if (window.PivarkApi && window.PivarkApi.isOk) {
      return window.PivarkApi.isOk(res);
    }
    return res && !res.error && res.data !== undefined;
  }

  function apiList(res) {
    if (window.PivarkApi && window.PivarkApi.listData) {
      return window.PivarkApi.listData(res);
    }
    if (Array.isArray(res && res.data)) return res.data;
    if (res && Array.isArray(res.list)) return res.list;
    return [];
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function renderQuote(content) {
    if (!content) return '';
    return '<blockquote class="pv-comment-quote">' + escapeHtml(content) + '</blockquote>';
  }

  function renderItem(row, depth, floorNo) {
    depth = depth || 0;
    floorNo = floorNo || 0;
    var nestClass = depth > 0 ? ' is-nested' : '';
    var liked = !!row.liked;
    var html = '<div class="pv-comment-item' + nestClass + '" data-id="' + row.id + '" data-depth="' + depth + '" data-floor="' + floorNo + '">';
    html += '<div class="pv-comment-meta">';
    if (floorNo > 0) {
      html += '<span class="pv-comment-floor" title="楼层">#' + floorNo + '</span>';
    }
    html += '<strong>' + escapeHtml(row.username) + '</strong><span>' + escapeHtml(row.created_at) + '</span></div>';
    if (row.quote_content) {
      html += renderQuote(row.quote_content);
    }
    html += '<div class="pv-comment-content">' + escapeHtml(row.content) + '</div>';
    html += '<div class="pv-comment-actions">';
    html += '<button type="button" class="pv-reply-btn" data-id="' + row.id + '" data-user="' + escapeHtml(row.username) + '" data-content="' + escapeHtml(row.content).replace(/"/g, '&quot;') + '">回复</button>';
    html += '<button type="button" class="pv-like-btn' + (liked ? ' is-liked' : '') + '" data-id="' + row.id + '">'
      + (liked ? '已赞' : '赞') + ' (' + (row.like_count || 0) + ')</button>';
    if (row.is_mine) {
      html += '<button type="button" class="pv-del-btn" data-id="' + row.id + '">删除</button>';
    }
    html += '</div></div>';
    return html;
  }

  function buildTree(list) {
    var map = {};
    var roots = [];
    list.forEach(function (row) {
      map[row.id] = Object.assign({}, row, { children: [] });
    });
    list.forEach(function (row) {
      var node = map[row.id];
      var pid = parseInt(row.parent_id, 10) || 0;
      if (pid > 0 && map[pid]) {
        map[pid].children.push(node);
      } else {
        roots.push(node);
      }
    });
    return roots;
  }

  function renderTree(nodes, depth, counter) {
    depth = depth || 0;
    counter = counter || { n: 0 };
    var html = '';
    nodes.forEach(function (node) {
      counter.n += 1;
      html += renderItem(node, depth, counter.n);
      if (node.children && node.children.length) {
        html += '<div class="pv-comment-children" data-depth="' + (depth + 1) + '">';
        html += renderTree(node.children, depth + 1, counter);
        html += '</div>';
      }
    });
    return html;
  }

  function initWidget(root) {
    var docId = root.getAttribute('data-document-id');
    var apiBase = (root.getAttribute('data-api-base') || '/api/v1/plugins/doc_comment').replace(/\/+$/, '');
    var csrfField = root.getAttribute('data-csrf-field') || '__token';
    var csrfToken = root.getAttribute('data-csrf-token') || '';
    var loggedIn = root.getAttribute('data-logged-in') === '1';
    var guestAllowed = root.getAttribute('data-guest') === '1';
    var canCompose = loggedIn || guestAllowed;
    var loginUrl = root.getAttribute('data-login-url') || '/member/login';
    var listEl = qs(root, '.pv-comment-list');
    var formEl = qs(root, '.pv-comment-form');
    var toastEl = qs(root, '[data-comment-toast]');
    var nickInput = qs(root, '.pv-comment-nick');
    var input = qs(root, '.pv-comment-input');
    var submit = qs(root, '.pv-comment-submit');
    var cancelReply = qs(root, '.pv-comment-cancel-reply');
    var charCount = qs(root, '[data-charcount]');
    var replyParentId = 0;
    var replyQuote = '';
    var submitting = false;

    var pageSize = Math.max(5, Math.min(50, parseInt(root.getAttribute('data-page-size') || '10', 10) || 10));
    var allRoots = [];
    var visibleRootCount = 0;

    function loginHref() {
      var redirect = encodeURIComponent(window.location.pathname + window.location.search);
      if (loginUrl.indexOf('redirect=') >= 0) return loginUrl;
      return loginUrl + (loginUrl.indexOf('?') >= 0 ? '&' : '?') + 'redirect=' + redirect;
    }

    function errMsg(res) {
      return (res && res.error && res.error.message) || (res && res.msg) || '';
    }

    function showToast(text, kind) {
      if (!toastEl) return;
      toastEl.hidden = false;
      toastEl.className = 'pv-comment-toast' + (kind ? ' is-' + kind : '');
      toastEl.textContent = text;
      window.clearTimeout(showToast._t);
      showToast._t = window.setTimeout(function () { toastEl.hidden = true; }, 4500);
    }

    function updateCharCount() {
      if (!input || !charCount) return;
      var n = (input.value || '').length;
      charCount.textContent = n + '/2000';
      charCount.classList.toggle('is-near', n >= 1800);
    }

    function applyComposeGate() {
      if (!formEl || canCompose) {
        if (nickInput) {
          nickInput.hidden = !!loggedIn;
        }
        return;
      }
      formEl.innerHTML = '<div class="pv-comment-login-gate" data-login-gate="1">'
        + '<p>登录后才能发表评论与回复</p>'
        + '<a class="pv-comment-login-link" href="' + escapeHtml(loginHref()) + '">去登录</a>'
        + '</div>';
      input = null;
      submit = null;
      cancelReply = null;
      nickInput = null;
      charCount = null;
    }

    function setReply(parentId, username, content) {
      if (!input) return;
      replyParentId = parentId || 0;
      replyQuote = content || '';
      if (username) {
        input.placeholder = '回复 @' + username + '…';
      }
      if (cancelReply) cancelReply.hidden = false;
      input.focus();
    }

    function clearReply() {
      replyParentId = 0;
      replyQuote = '';
      if (input) input.placeholder = '写下你的评论…';
      if (cancelReply) cancelReply.hidden = true;
    }

    function enrichQuotes(rows) {
      var byId = {};
      rows.forEach(function (row) { byId[row.id] = row; });
      return rows.map(function (row) {
        var pid = parseInt(row.parent_id, 10) || 0;
        if (pid > 0 && byId[pid] && byId[pid].content) {
          var raw = String(byId[pid].content || '');
          row.quote_content = raw.length > 120 ? raw.slice(0, 120) + '…' : raw;
        }
        return row;
      });
    }

    function renderVisibleTree() {
      var slice = allRoots.slice(0, visibleRootCount);
      var html = renderTree(slice, 0, { n: 0 });
      if (visibleRootCount < allRoots.length) {
        html += '<div class="pv-comment-more">'
          + '<button type="button" class="pv-comment-more-btn" data-comment-more="1">'
          + '加载更多（还剩 ' + (allRoots.length - visibleRootCount) + ' 条主楼）'
          + '</button></div>';
      }
      listEl.innerHTML = html;
      bindActions();
      var moreBtn = qs(listEl, '[data-comment-more]');
      if (moreBtn) {
        moreBtn.onclick = function () {
          visibleRootCount = Math.min(allRoots.length, visibleRootCount + pageSize);
          renderVisibleTree();
        };
      }
    }

    function loadList() {
      listEl.innerHTML = '<div class="pv-comment-empty">加载评论中…</div>';
      api(apiBase + '/tree/' + docId).then(function (res) {
        if (!apiOk(res)) {
          listEl.innerHTML = '<div class="pv-comment-empty is-error">评论加载失败，请刷新重试</div>';
          return;
        }
        var rows = apiList(res);
        if (!rows.length) {
          listEl.innerHTML = '<div class="pv-comment-empty">暂无评论，来抢沙发吧</div>';
          return;
        }
        allRoots = buildTree(enrichQuotes(rows));
        visibleRootCount = Math.min(pageSize, allRoots.length);
        renderVisibleTree();
      }).catch(function () {
        listEl.innerHTML = '<div class="pv-comment-empty is-error">评论加载失败，请刷新重试</div>';
      });
    }

    function bindActions() {
      qsa(root, '.pv-reply-btn').forEach(function (btn) {
        btn.onclick = function () {
          if (!canCompose) {
            alert('请先登录后再回复');
            window.location.href = loginHref();
            return;
          }
          setReply(parseInt(btn.getAttribute('data-id'), 10), btn.getAttribute('data-user'), btn.getAttribute('data-content'));
        };
      });
      qsa(root, '.pv-like-btn').forEach(function (btn) {
        btn.onclick = function () {
          var body = csrfField + '=' + encodeURIComponent(csrfToken) + '&comment_id=' + encodeURIComponent(btn.getAttribute('data-id'));
          api(apiBase + '/like', { method: 'POST', body: body }).then(function (res) {
            if (apiOk(res)) {
              loadList();
              return;
            }
            var msg = errMsg(res);
            if (msg === 'login_required' || (res && res.error && res.error.code === 'AUTH_REQUIRED')) {
              alert('请先登录后再点赞');
              window.location.href = loginHref();
            } else if (msg) {
              alert(msg);
            }
          });
        };
      });
      qsa(root, '.pv-del-btn').forEach(function (btn) {
        btn.onclick = function () {
          if (!confirm('确定删除这条评论？')) return;
          var body = csrfField + '=' + encodeURIComponent(csrfToken) + '&id=' + encodeURIComponent(btn.getAttribute('data-id'));
          api(apiBase + '/delete', { method: 'POST', body: body }).then(function (res) {
            if (apiOk(res)) loadList();
          });
        };
      });
    }

    if (formEl) {
      formEl.addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('pv-comment-cancel-reply')) {
          clearReply();
        }
      });
    }

    applyComposeGate();

    if (input) {
      input.addEventListener('input', updateCharCount);
      updateCharCount();
    }

    if (submit) {
      submit.onclick = function () {
        if (!input || submitting) return;
        var content = (input.value || '').trim();
        if (!content) return;
        var nick = nickInput && !nickInput.hidden ? String(nickInput.value || '').trim() : '';
        if (!loggedIn && guestAllowed) {
          if (!nick) {
            alert('请填写昵称');
            if (nickInput) nickInput.focus();
            return;
          }
        }
        if (replyQuote && content.indexOf('> ') !== 0) {
          content = '> 引用\n' + content;
        }
        var body = csrfField + '=' + encodeURIComponent(csrfToken)
          + '&document_id=' + encodeURIComponent(docId)
          + '&content=' + encodeURIComponent(content);
        if (nick) {
          body += '&username=' + encodeURIComponent(nick);
        }
        if (replyParentId > 0) {
          body += '&parent_id=' + encodeURIComponent(String(replyParentId));
        }
        submitting = true;
        submit.disabled = true;
        submit.textContent = '提交中…';
        api(apiBase + '/add', { method: 'POST', body: body }).then(function (res) {
          submitting = false;
          submit.disabled = false;
          submit.textContent = '发表评论';
          if (!apiOk(res)) {
            var msg = errMsg(res) || '提交失败';
            if (msg === 'login_required' || (res && res.error && res.error.code === 'AUTH_REQUIRED')) {
              alert('请先登录后再评论');
              window.location.href = loginHref();
            } else if (msg === 'level_denied') {
              alert('当前会员等级不可评论');
            } else {
              alert(msg);
            }
            return;
          }
          input.value = '';
          updateCharCount();
          clearReply();
          var hint = (res.meta && res.meta.message) || '';
          if (hint === 'pending_review') {
            showToast('评论已提交，等待审核通过后显示', 'pending');
          } else {
            showToast('评论已发布', 'ok');
          }
          loadList();
        }).catch(function () {
          submitting = false;
          submit.disabled = false;
          submit.textContent = '发表评论';
          alert('提交失败，请稍后重试');
        });
      };
    }

    loadList();
  }

  document.addEventListener('DOMContentLoaded', function () {
    qsa(document, '.pv-comment-widget').forEach(initWidget);
  });
})();

  PV.modules.comment = PV.modules.comment || {};
})(window.PV = window.PV || {}, window.pvUrl);
