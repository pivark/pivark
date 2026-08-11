(function () {
  var root = document.getElementById('pv-float-contact');
  if (!root) return;

  var style = root.getAttribute('data-style') || 'sidebar';

  function bindWechat(items) {
    items.forEach(function (el) {
      el.addEventListener('click', function (e) {
        var qrPop =
          el.querySelector('.pv-fc-orb__pop--qr') ||
          el.querySelector('.pv-fc-rail__pop--qr') ||
          el.querySelector('.pv-fc-stack__pop--qr') ||
          el.querySelector('.pv-fc-fab__pop--qr');
        if ((style === 'orbs' || style === 'rail' || style === 'stack' || style === 'fab') && qrPop) {
          return;
        }
        e.preventDefault();
        var qr = el.getAttribute('data-qr') || '';
        var id = el.getAttribute('data-wechat-id') || '';
        if (qr) {
          showQr(qr, id ? ('微信号：' + id) : '微信扫码添加');
          return;
        }
        if (id && navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(id).then(function () {
            alert('微信号已复制：' + id);
          });
        }
      });
    });
  }

  var qrLayer = root.querySelector('.pv-float-contact__qr');
  var qrImg = root.querySelector('.pv-float-contact__qr-img');
  var qrTip = root.querySelector('.pv-float-contact__qr-tip');
  var qrClose = root.querySelector('.pv-float-contact__qr-close');

  function showQr(src, tip) {
    if (!qrLayer || !qrImg) return;
    qrImg.src = src || '';
    if (qrTip) qrTip.textContent = tip || '';
    qrLayer.hidden = false;
  }

  function hideQr() {
    if (qrLayer) qrLayer.hidden = true;
  }

  if (qrClose) qrClose.addEventListener('click', hideQr);
  if (qrLayer) {
    qrLayer.addEventListener('click', function (e) {
      if (e.target === qrLayer) hideQr();
    });
  }

  bindWechat(root.querySelectorAll('.pv-float-contact__item--wechat'));

  var edgeMask = root.querySelector('[data-pv-fc-edge-mask]');
  if (style === 'edge' && edgeMask) {
    var edgeTitle = edgeMask.querySelector('.pv-fc-edge__modal-title');
    var edgeList = edgeMask.querySelector('.pv-fc-edge__modal-list');
    var edgeQrWrap = edgeMask.querySelector('.pv-fc-edge__modal-qr');
    var edgeQrImg = edgeMask.querySelector('.pv-fc-edge__modal-qr-img');
    var edgeQrTip = edgeMask.querySelector('.pv-fc-edge__modal-qr-tip');
    var edgeCta = edgeMask.querySelector('.pv-fc-edge__modal-cta');
    var edgeClose = edgeMask.querySelector('.pv-fc-edge__close');

    function hideEdge() {
      edgeMask.hidden = true;
    }

    function showEdge(btn) {
      var title = btn.getAttribute('data-title') || '';
      var bodyRaw = btn.getAttribute('data-body') || '[]';
      var href = btn.getAttribute('data-href') || '';
      var cta = btn.getAttribute('data-cta') || '立即联系';
      var qr = btn.getAttribute('data-qr') || '';
      var qrTipText = btn.getAttribute('data-qr-tip') || '';
      var lines = [];
      try {
        lines = JSON.parse(bodyRaw) || [];
      } catch (err) {
        lines = [];
      }
      if (edgeTitle) edgeTitle.textContent = title;
      if (edgeList) {
        edgeList.innerHTML = '';
        lines.forEach(function (line) {
          if (!line) return;
          var li = document.createElement('li');
          li.textContent = String(line);
          edgeList.appendChild(li);
        });
      }
      if (edgeQrWrap && edgeQrImg) {
        if (qr) {
          edgeQrImg.src = qr;
          if (edgeQrTip) edgeQrTip.textContent = qrTipText || '';
          edgeQrWrap.hidden = false;
        } else {
          edgeQrWrap.hidden = true;
          edgeQrImg.removeAttribute('src');
        }
      }
      if (edgeCta) {
        if (href) {
          edgeCta.href = href;
          edgeCta.textContent = cta;
          edgeCta.hidden = false;
        } else {
          edgeCta.hidden = true;
        }
      }
      edgeMask.hidden = false;
    }

    root.querySelectorAll('[data-action="edge-panel"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        showEdge(btn);
      });
    });
    if (edgeClose) edgeClose.addEventListener('click', hideEdge);
    edgeMask.addEventListener('click', function (e) {
      if (e.target === edgeMask) hideEdge();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        hideQr();
        hideEdge();
      }
    });
    return;
  }

  if (style === 'sidebar') {
    var tab = root.querySelector('.pv-float-contact__tab');
    var panel = root.querySelector('.pv-float-contact__panel');
    var closeBtn = root.querySelector('.pv-float-contact__close');

    function setOpen(open) {
      root.classList.toggle('is-open', open);
      if (panel) panel.hidden = !open;
      if (tab) tab.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (tab) {
      tab.addEventListener('click', function () {
        setOpen(panel && panel.hidden);
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setOpen(false);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        hideQr();
        setOpen(false);
      }
    });
    return;
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideQr();
  });
})();
