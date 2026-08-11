/*! PivArk admin official — 联系我们 + 首页广告位（单文件）
 * 远程真源：https://www.pivark.cn/static/common/js/pivark-admin-contact/widget.js
 * 换广告：改本文件 DATA.promoSlots 后上传到 www.pivark.cn 同路径即可覆盖客户站兜底
 */
(function () {
  var QR_DEFAULT =
    'https://www.pivark.cn/static/common/js/pivark-admin-contact/wechat-qr.jpg';

  var DATA = window.PIVARK_ADMIN_CONTACT_DATA || {
    buttonLabel: '联系',
    wechatQr: QR_DEFAULT,
    wechatTip: '微信扫码咨询',
    promoSlots: [
      {
        id: 'docs',
        title: '使用文档',
        desc: '安装、升级与二次开发',
        href: 'https://www.pivark.cn/docs/',
        cta: '打开文档',
        tone: 'sky'
      },
      {
        id: 'license',
        title: '商业授权',
        desc: '去版权、升级与官方支持',
        href: 'https://www.pivark.cn/pricing',
        cta: '了解授权',
        tone: 'teal'
      }
    ],
    panels: [
      {
        id: 'consult',
        title: '技术服务咨询',
        body: [
          '安装部署与升级问题排查',
          '主题 / 插件定制与系统集成',
          '商业授权与去版权咨询'
        ],
        note: '技术服务为有偿服务，费用按项目复杂度约定。',
        ctaLabel: '打开官网联系页',
        ctaHref: 'https://www.pivark.cn/contact'
      },
      {
        id: 'docs',
        title: '使用文档',
        body: ['安装、升级、模板与插件开发文档', '对外文档库与 CHANGELOG'],
        ctaLabel: '打开文档',
        ctaHref: 'https://www.pivark.cn/docs/'
      },
      {
        id: 'site',
        title: 'PivArk 官网',
        body: ['产品介绍、版本对比与商业授权'],
        ctaLabel: '打开官网',
        ctaHref: 'https://www.pivark.cn'
      }
    ]
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function remountPromo() {
    var host = document.getElementById('pv-admin-official-promo');
    if (!host) return false;
    var slots = Array.isArray(DATA.promoSlots) ? DATA.promoSlots.slice(0, 2) : [];
    if (!slots.length) return false;
    // 已有卡片则跳过，避免 MutationObserver ↔ innerHTML 死循环
    if (host.querySelector('.pv-aop__card')) return true;
    var html = '';
    slots.forEach(function (s) {
      var tone = esc(s.tone || 'sky');
      html +=
        '<a class="pv-aop__card pv-aop__card--' + tone + '" href="' + esc(s.href) + '" target="_blank" rel="noopener noreferrer">' +
        '<span class="pv-aop__title">' + esc(s.title) + '</span>' +
        (s.desc ? '<span class="pv-aop__desc">' + esc(s.desc) + '</span>' : '') +
        '<span class="pv-aop__cta">' + esc(s.cta || '了解') + '</span>' +
        '</a>';
    });
    host.innerHTML = html;
    host.setAttribute('data-pv-filled', '1');
    return true;
  }

  // 广告位 API 必须在「已加载过」时也能挂上（旧脚本只挂了联系钮时补填）
  window.PvArkAdminOfficial = window.PvArkAdminOfficial || {};
  window.PvArkAdminOfficial.remountPromo = remountPromo;

  if (!window.__PV_ADMIN_OFFICIAL_PROMO_CSS__) {
    window.__PV_ADMIN_OFFICIAL_PROMO_CSS__ = true;
    var promoCss = document.createElement('style');
    promoCss.setAttribute('data-pv-admin-official-promo', '1');
    promoCss.textContent = [
      '#pv-admin-official-promo{display:grid;grid-template-columns:1fr;gap:10px}',
      '#pv-admin-official-promo .pv-aop__card{display:flex;flex-direction:column;gap:4px;min-height:78px;padding:14px 16px 12px;border-radius:14px;color:#fff;text-decoration:none;box-shadow:0 8px 20px rgba(15,23,42,.10)}',
      '#pv-admin-official-promo .pv-aop__card:hover{filter:brightness(1.04)}',
      '#pv-admin-official-promo .pv-aop__card--sky{background:linear-gradient(145deg,#1e9fff 0%,#3b82f6 100%)}',
      '#pv-admin-official-promo .pv-aop__card--teal{background:linear-gradient(145deg,#0d9488 0%,#14b8a6 100%)}',
      '#pv-admin-official-promo .pv-aop__card--emerald{background:linear-gradient(145deg,#059669 0%,#10b981 100%)}',
      '#pv-admin-official-promo .pv-aop__card--amber{background:linear-gradient(145deg,#d97706 0%,#f59e0b 100%)}',
      '#pv-admin-official-promo .pv-aop__title{font-size:15px;font-weight:700;line-height:1.3}',
      '#pv-admin-official-promo .pv-aop__desc{font-size:12px;line-height:1.4;opacity:.92}',
      '#pv-admin-official-promo .pv-aop__cta{margin-top:auto;padding-top:6px;font-size:12px;font-weight:600;opacity:.95}'
    ].join('');
    document.head.appendChild(promoCss);
  }

  remountPromo();
  if (!window.__PV_ADMIN_OFFICIAL_PROMO_WATCH__) {
    window.__PV_ADMIN_OFFICIAL_PROMO_WATCH__ = true;
    if (typeof MutationObserver !== 'undefined') {
      var promoObs = new MutationObserver(function () {
        remountPromo();
      });
      promoObs.observe(document.documentElement, { childList: true, subtree: true });
    } else {
      var tries = 0;
      var timer = window.setInterval(function () {
        if (remountPromo() || ++tries > 80) {
          window.clearInterval(timer);
        }
      }, 400);
    }
  }

  if (window.__PV_ADMIN_OFFICIAL_WIDGET__) {
    return;
  }
  window.__PV_ADMIN_OFFICIAL_WIDGET__ = true;

  var css = document.createElement('style');
  css.textContent = [
    '#pv-admin-official-contact{position:fixed;right:24px;bottom:88px;z-index:1900;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;pointer-events:none}',
    '#pv-admin-official-contact .pv-aoc__btn{pointer-events:auto;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;padding:0;border:0;border-radius:999px;background:#1e9fff;color:#fff;cursor:pointer;box-shadow:0 8px 22px rgba(30,159,255,.35);transition:transform .15s,filter .15s}',
    '#pv-admin-official-contact .pv-aoc__btn:hover{filter:brightness(1.06);transform:translateY(-1px)}',
    '#pv-admin-official-contact .pv-aoc__btn svg{display:block;width:18px;height:18px;flex-shrink:0}',
    '#pv-admin-official-contact .pv-aoc__btn-label{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}',
    '#pv-admin-official-contact .pv-aoc__mask{pointer-events:auto;position:fixed;inset:0;z-index:2100;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.45);backdrop-filter:blur(2px)}',
    '#pv-admin-official-contact .pv-aoc__mask[hidden]{display:none!important}',
    '#pv-admin-official-contact .pv-aoc__modal{position:relative;width:min(560px,100%);padding:28px 28px 22px;background:#fff;border-radius:18px;box-shadow:0 18px 48px rgba(15,23,42,.22);color:#0f172a}',
    '#pv-admin-official-contact .pv-aoc__close{position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:999px;background:#e11d48;color:#fff;font-size:20px;line-height:1;cursor:pointer;z-index:2}',
    '#pv-admin-official-contact .pv-aoc__tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;padding-right:36px}',
    '#pv-admin-official-contact .pv-aoc__tab{padding:6px 12px;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#334155;font-size:12px;cursor:pointer}',
    '#pv-admin-official-contact .pv-aoc__tab.is-active{border-color:#1e9fff;background:#e8f3ff;color:#0b5cad;font-weight:600}',
    '#pv-admin-official-contact .pv-aoc__body{display:flex;gap:20px;align-items:flex-start}',
    '#pv-admin-official-contact .pv-aoc__main{flex:1;min-width:0}',
    '#pv-admin-official-contact .pv-aoc__title{margin:0 0 14px;font-size:22px;font-weight:700}',
    '#pv-admin-official-contact .pv-aoc__list{margin:0 0 14px;padding-left:1.1em;font-size:14px;line-height:1.65;color:#334155}',
    '#pv-admin-official-contact .pv-aoc__note{margin:0 0 14px;font-size:12px;color:#64748b}',
    '#pv-admin-official-contact .pv-aoc__cta{display:inline-flex;align-items:center;justify-content:center;min-width:140px;padding:10px 22px;border-radius:999px;background:#1e9fff;color:#fff;font-size:14px;font-weight:600;text-decoration:none}',
    '#pv-admin-official-contact .pv-aoc__qr{flex:0 0 132px;text-align:center}',
    '#pv-admin-official-contact .pv-aoc__qr img{display:block;width:120px;height:120px;margin:0 auto;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc}',
    '#pv-admin-official-contact .pv-aoc__qr-tip{margin:8px 0 0;font-size:12px;color:#64748b;line-height:1.4}',
    '@media (max-width:640px){#pv-admin-official-contact{right:16px;bottom:84px}#pv-admin-official-contact .pv-aoc__body{flex-direction:column}#pv-admin-official-contact .pv-aoc__qr{flex-basis:auto;align-self:center}}'
  ].join('');
  document.head.appendChild(css);

  if (document.getElementById('pv-admin-official-contact')) {
    return;
  }

  var icon =
    '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/>' +
    '</svg>';

  var label = DATA.buttonLabel || '联系';
  var qrSrc = DATA.wechatQr || QR_DEFAULT;
  var qrTip = DATA.wechatTip || '微信扫码咨询';
  var root = document.createElement('div');
  root.id = 'pv-admin-official-contact';
  root.innerHTML =
    '<button type="button" class="pv-aoc__btn" aria-haspopup="dialog" title="联系我们" aria-label="联系我们">' +
    icon +
    '<span class="pv-aoc__btn-label">' + label + '</span>' +
    '</button>' +
    '<div class="pv-aoc__mask" hidden>' +
    '<div class="pv-aoc__modal" role="dialog" aria-modal="true">' +
    '<button type="button" class="pv-aoc__close" aria-label="关闭">&times;</button>' +
    '<div class="pv-aoc__tabs"></div>' +
    '<div class="pv-aoc__body">' +
    '<div class="pv-aoc__main">' +
    '<h3 class="pv-aoc__title"></h3>' +
    '<ul class="pv-aoc__list"></ul>' +
    '<p class="pv-aoc__note" hidden></p>' +
    '<a class="pv-aoc__cta" href="#" target="_blank" rel="noopener noreferrer" hidden></a>' +
    '</div>' +
    '<aside class="pv-aoc__qr">' +
    '<img src="' + qrSrc + '" alt="微信二维码" width="120" height="120" loading="lazy" decoding="async" />' +
    '<p class="pv-aoc__qr-tip">' + qrTip + '</p>' +
    '</aside>' +
    '</div></div></div>';
  document.body.appendChild(root);

  var btn = root.querySelector('.pv-aoc__btn');
  var mask = root.querySelector('.pv-aoc__mask');
  var closeBtn = root.querySelector('.pv-aoc__close');
  var tabsEl = root.querySelector('.pv-aoc__tabs');
  var titleEl = root.querySelector('.pv-aoc__title');
  var listEl = root.querySelector('.pv-aoc__list');
  var noteEl = root.querySelector('.pv-aoc__note');
  var ctaEl = root.querySelector('.pv-aoc__cta');
  var qrImg = root.querySelector('.pv-aoc__qr img');
  var panels = Array.isArray(DATA.panels) ? DATA.panels : [];
  var active = 0;

  if (qrImg) {
    qrImg.addEventListener('error', function () {
      var local = '/static/common/js/pivark-admin-contact/wechat-qr.jpg';
      if (qrImg.getAttribute('src') !== local) {
        qrImg.setAttribute('src', local);
      }
    });
  }

  function renderPanel(i) {
    active = i;
    var p = panels[i] || {};
    titleEl.textContent = p.title || '';
    listEl.innerHTML = '';
    (p.body || []).forEach(function (line) {
      var li = document.createElement('li');
      li.textContent = String(line);
      listEl.appendChild(li);
    });
    if (p.note) {
      noteEl.hidden = false;
      noteEl.textContent = p.note;
    } else {
      noteEl.hidden = true;
      noteEl.textContent = '';
    }
    if (p.ctaHref) {
      ctaEl.hidden = false;
      ctaEl.href = p.ctaHref;
      ctaEl.textContent = p.ctaLabel || '了解更多';
    } else {
      ctaEl.hidden = true;
    }
    Array.prototype.forEach.call(tabsEl.querySelectorAll('.pv-aoc__tab'), function (el, idx) {
      el.classList.toggle('is-active', idx === i);
    });
  }

  panels.forEach(function (p, idx) {
    var t = document.createElement('button');
    t.type = 'button';
    t.className = 'pv-aoc__tab' + (idx === 0 ? ' is-active' : '');
    t.textContent = p.title || ('入口' + (idx + 1));
    t.addEventListener('click', function () { renderPanel(idx); });
    tabsEl.appendChild(t);
  });

  function open() {
    renderPanel(active);
    mask.hidden = false;
  }
  function close() {
    mask.hidden = true;
  }

  btn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  mask.addEventListener('click', function (e) {
    if (e.target === mask) close();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });
})();