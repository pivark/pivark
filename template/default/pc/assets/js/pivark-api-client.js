/**
 * 前台 REST JSON 辅助（/api/v1 · /member · 表单提交）
 * Success: { data, meta? } · Failure: { error: { code, message } }
 */
(function () {
  'use strict';

  var ERROR_CODE_ZH = {
    AUTH_REQUIRED: '请先登录',
    CAPTCHA_INVALID: '验证码错误或已过期',
    CONFLICT: '操作冲突，请刷新后重试',
    CSRF_EXPIRED: '表单已过期，请刷新页面后重试',
    METHOD_NOT_ALLOWED: '请求方式不正确',
    NOT_FOUND: '请求的资源不存在',
    PAYMENT_CLOSED: '订单已关闭',
    PAYMENT_FAILED: '支付失败',
    PERMISSION_DENIED: '没有操作权限',
    RATE_LIMITED: '操作过于频繁，请稍后再试',
    UNKNOWN: '操作失败，请稍后重试',
    UPLOAD_REJECTED: '上传被拒绝',
    VALIDATION_FAILED: '提交的数据有误，请检查后重试',
  };

  function isGenericTransportMessage(msg) {
    var s = String(msg || '').trim();
    return (
      /^Request failed with status code \d+$/i.test(s) ||
      /^HTTP \d+$/i.test(s) ||
      /^Network Error$/i.test(s) ||
      /^Not Found\b/i.test(s) ||
      /^Internal Server Error\b/i.test(s)
    );
  }

  function isUserFacingMessage(msg) {
    var s = String(msg || '').trim();
    if (!s || isGenericTransportMessage(s)) {
      return false;
    }
    if (ERROR_CODE_ZH[s] || /^[A-Z][A-Z0-9_]+$/.test(s)) {
      return false;
    }
    if (/^[a-z][a-z0-9_]+$/.test(s)) {
      return false;
    }
    if (!/[\u3400-\u9fff]/.test(s)) {
      return false;
    }
    return true;
  }

  function httpStatusMessage(status) {
    switch (status) {
      case 400:
        return '请求参数有误';
      case 401:
        return '请先登录';
      case 403:
        return '没有操作权限';
      case 404:
        return '请求的资源不存在';
      case 408:
        return '请求超时，请稍后重试';
      case 422:
        return '提交的数据有误，请检查后重试';
      case 429:
        return '操作过于频繁，请稍后再试';
      case 500:
        return '操作未能完成，请再试一次。若仍失败，请记下刚才的步骤后联系支持';
      case 502:
      case 503:
      case 504:
        return '服务暂时不可用，请稍后重试';
      default:
        return status >= 400 ? '操作失败，请稍后重试' : '服务器返回异常';
    }
  }

  function errorCodeMessage(code) {
    return ERROR_CODE_ZH[String(code || '')] || '';
  }

  function ajaxOk(res) {
    return (
      res &&
      typeof res === 'object' &&
      !res.error &&
      Object.prototype.hasOwnProperty.call(res, 'data')
    );
  }

  function ajaxMsg(res, fallback) {
    if (res && res.error && res.error.message) {
      var message = String(res.error.message).trim();
      if (isUserFacingMessage(message)) {
        return message;
      }
    }
    if (res && res.error && res.error.code) {
      var fromCode = errorCodeMessage(res.error.code);
      if (fromCode) {
        return fromCode;
      }
    }
    if (res && res.meta && res.meta.message) {
      var metaMessage = String(res.meta.message);
      if (isUserFacingMessage(metaMessage)) {
        return metaMessage;
      }
    }
    if (res && res.msg) {
      var legacyMsg = String(res.msg);
      if (isUserFacingMessage(legacyMsg)) {
        return legacyMsg;
      }
    }
    return fallback || '请求失败';
  }

  function ajaxPayload(res) {
    if (ajaxOk(res)) {
      var d = res.data;
      if (d && typeof d === 'object' && !Array.isArray(d)) {
        var out = Object.assign({}, d);
        if (res.meta && res.meta.message && !out.msg) {
          out.msg = String(res.meta.message);
        }
        if (res.redirect && !out.redirect) {
          out.redirect = res.redirect;
        }
        return out;
      }
      return { data: d, msg: ajaxMsg(res, ''), redirect: res.redirect || '' };
    }
    return res || {};
  }

  function listData(res) {
    if (!ajaxOk(res)) {
      return [];
    }
    var data = res.data;
    if (Array.isArray(data)) {
      return data;
    }
    if (data && typeof data === 'object' && Array.isArray(data.list)) {
      return data.list;
    }
    return [];
  }

  /** catalog 响应中的 filters 元数据（list+filters 或 filters_only=1） */
  function catalogFilters(res) {
    if (!ajaxOk(res)) {
      return [];
    }
    var data = res.data;
    if (Array.isArray(data)) {
      return data;
    }
    if (data && typeof data === 'object' && Array.isArray(data.filters)) {
      return data.filters;
    }
    return [];
  }

  /**
   * 懒加载 catalog 筛选项（SSOT：GET catalog/{domain}?filters_only=1）
   * @param {string} catalogUrl 如 /api/v1/catalog/items
   * @param {URLSearchParams|Record<string,string>|undefined} query
   * @returns {Promise<object>}
   */
  function fetchCatalogFilters(catalogUrl, query) {
    var base = String(catalogUrl || '').split('?')[0];
    var params =
      query instanceof URLSearchParams ? new URLSearchParams(query) : new URLSearchParams(query || {});
    params.set('filters_only', '1');
    params.set('skip_total', '1');
    params.set('limit', '1');
    return fetch(base + '?' + params.toString(), { headers: { Accept: 'application/json' } }).then(
      parseFetchJson,
    );
  }

  function errorMessage(res, fallback) {
    return ajaxMsg(res, fallback || '请求失败');
  }

  /** fetch Response → REST envelope；非 2xx 也解析 body，禁止向用户展示 HTTP 状态码 */
  function parseFetchJson(response) {
    return response.text().then(function (text) {
      var body = null;
      try {
        body = text ? JSON.parse(text) : null;
      } catch (e) {
        body = null;
      }
      if (body && typeof body === 'object') {
        if (!response.ok && !body.error) {
          body.error = { message: httpStatusMessage(response.status) };
        }
        return body;
      }
      return {
        error: {
          message: response.ok ? '服务器返回异常' : httpStatusMessage(response.status),
        },
      };
    });
  }

  window.PivarkApi = {
    ajaxOk: ajaxOk,
    ajaxMsg: ajaxMsg,
    ajaxPayload: ajaxPayload,
    catalogFilters: catalogFilters,
    errorCodeMessage: errorCodeMessage,
    errorMessage: errorMessage,
    fetchCatalogFilters: fetchCatalogFilters,
    httpStatusMessage: httpStatusMessage,
    isOk: ajaxOk,
    listData: listData,
    parseFetchJson: parseFetchJson,
  };
})();
