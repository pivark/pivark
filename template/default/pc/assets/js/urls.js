/**
 * 前台 JS 统一 URL 查表辅助（window.PV_URLS，由内核 head 注入）
 * 用法：pvUrl('key', '/api/fallback') — 优先用 key 对应地址，否则用 fallback
 */
(function () {
  'use strict';

  window.PV_URLS = window.PV_URLS || {};

  window.pvUrl = function (key, fallback) {
    if (key && window.PV_URLS[key]) {
      return window.PV_URLS[key];
    }
    return fallback || '';
  };
})();
