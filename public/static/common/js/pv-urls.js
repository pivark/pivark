/**
 * PivArk 前台 URL 助手（与 TemplateSiteVars 注入的 PV.urls 配合）
 */
(function (global) {
  'use strict';

  global.PV = global.PV || {};
  global.PV.urls = global.PV.urls || global.PV_URLS || {};
  global.PV_URLS = global.PV.urls;

  function pvUrl(key, fallback) {
    var map = global.PV.urls || {};
    if (map[key] !== undefined && map[key] !== '') {
      return map[key];
    }
    return fallback || '';
  }

  global.PV.mergeUrls = function (extra) {
    global.PV.urls = Object.assign(global.PV.urls || {}, extra || {});
    global.PV_URLS = global.PV.urls;
  };

  global.PV.url = pvUrl;
  global.pvUrl = pvUrl;

  function pvMemberLoginRedirect(path) {
    var base = pvUrl('memberLogin', '/member/login');
    var redirect = path || (global.location && global.location.pathname) || '/';
    var q = 'redirect=' + encodeURIComponent(redirect);
    return base.indexOf('?') >= 0 ? base + '&' + q : base + '?' + q;
  }

  function pvPayReturn(orderNo) {
    var base = pvUrl('memberPayReturn', '/member/pay/return');
    return base + '?order_no=' + encodeURIComponent(orderNo || '');
  }

  global.pvMemberLoginRedirect = pvMemberLoginRedirect;
  global.pvPayReturn = pvPayReturn;
})(typeof window !== 'undefined' ? window : this);
