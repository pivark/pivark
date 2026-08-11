(function () {
  var api = window.PivarkApi;
  var ajaxOk = api ? api.ajaxOk.bind(api) : function () {
    return false;
  };
  var ajaxMsg = api ? api.ajaxMsg.bind(api) : function (_res, fallback) {
    return fallback || '';
  };
  var ajaxPayload = api ? api.ajaxPayload.bind(api) : function (res) {
    return res || {};
  };
  var parseFetchJson = api && api.parseFetchJson
    ? api.parseFetchJson.bind(api)
    : function (response) {
        return response.json();
      };

  function showAlert(el, ok, msg) {
    if (!el) return;
    el.textContent = msg || (ok ? '操作成功' : '操作失败');
    el.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
  }

  function friendlyPayError(msg) {
    var s = String(msg || '').trim();
    if (!s) return '发起支付失败，请稍后重试';
    if (/timed out|timeout|0 bytes received/i.test(s)) {
      return '连接微信支付超时或服务器无法访问微信接口。请稍后重试；开发环境可在后台改用「演示模式」联调。';
    }
    if (/ssl|certificate/i.test(s)) {
      return '支付 HTTPS 证书校验失败，请联系管理员配置服务器 CA 证书。';
    }
    if (/could not resolve host/i.test(s)) {
      return '无法解析微信支付域名，请检查服务器 DNS。';
    }
    return s;
  }

  function setRechargePayLoading(loading) {
    var buttons = document.querySelectorAll(
      '.pv-recharge-pay, .pv-recharge-custom-pay, .pv-recharge-buy, .pv-recharge-custom-buy'
    );
    buttons.forEach(function (btn) {
      if (loading) {
        if (!btn.dataset.pvPayOrigHtml) {
          btn.dataset.pvPayOrigHtml = btn.innerHTML;
        }
        btn.disabled = true;
        btn.classList.add('disabled');
      } else {
        btn.disabled = false;
        btn.classList.remove('disabled');
        if (btn.dataset.pvPayOrigHtml) {
          btn.innerHTML = btn.dataset.pvPayOrigHtml;
          delete btn.dataset.pvPayOrigHtml;
        }
      }
    });
  }

  function applyMemberSnapshot(snapshot) {
    if (!snapshot || typeof snapshot !== 'object') return;
    var statusHtml =
      '<span class="text-secondary">' + (snapshot.member_recharge_status_line || '') + '</span>';
    var line = document.getElementById('pv-recharge-status-line');
    if (line && snapshot.member_recharge_status_line) {
      line.innerHTML = statusHtml;
    }
    var assetLine = document.getElementById('pv-member-asset-line');
    if (assetLine && snapshot.member_recharge_status_line) {
      assetLine.innerHTML = statusHtml;
    }
    if (snapshot.member_balance_text != null) {
      var bal = '¥' + snapshot.member_balance_text;
      var balStat = document.getElementById('pv-member-balance-stat');
      if (balStat) balStat.textContent = bal;
      var balPage = document.getElementById('pv-balance-page-total');
      if (balPage) balPage.textContent = bal;
    }
    if (snapshot.member_points_text != null) {
      var ptsStat = document.getElementById('pv-member-points-stat');
      if (ptsStat) ptsStat.textContent = snapshot.member_points_text;
      var ptsPage = document.getElementById('pv-points-page-total');
      if (ptsPage) ptsPage.textContent = snapshot.member_points_text;
    }
  }

  var applyRechargeMember = applyMemberSnapshot;

  function formatPayAmount(amount) {
    if (amount == null || isNaN(amount)) {
      return '—';
    }
    return '¥' + Number(amount).toFixed(2);
  }

  function parsePayInfoFromTitle(title) {
    var t = String(title || '').trim();
    var m = t.match(/¥\s*([\d,.]+)\s*$/);
    var amount = null;
    var product = t;
    if (m) {
      amount = parseFloat(m[1].replace(/,/g, ''));
      product = t.slice(0, m.index).trim();
    }
    return { product: product || '订单', amount: amount };
  }

  /** 根据套餐类型或标题推断充值场景文案 */
  function resolvePayScene(productTitle, rechargeType) {
    var type = (rechargeType || '').trim();
    var name = String(productTitle || '').trim();
    if (!type) {
      if (/余额/.test(name)) {
        type = 'balance';
      } else if (/积分/.test(name)) {
        type = 'points';
      } else {
        type = 'membership';
      }
    }
    if (type === 'balance') {
      return {
        type: type,
        itemLabel: '账户余额充值',
        itemDesc: '向账户内增加可用余额',
        hintWechat:
          '请使用微信扫码完成付款；成功后账户余额将增加（本次不使用账户余额扣款）',
        hintAlipay:
          '请使用支付宝完成付款；成功后账户余额将增加（本次不使用账户余额扣款）',
        hintBalance: '确认后将从当前账户余额扣款，并即时增加对应余额',
      };
    }
    if (type === 'points') {
      return {
        type: type,
        itemLabel: '积分充值',
        itemDesc: '购买后积分即时到账',
        hintWechat: '请使用微信扫码完成付款；成功后积分将增加到您的账户',
        hintAlipay: '请使用支付宝完成付款；成功后积分将增加到您的账户',
        hintBalance: '确认后将从账户余额扣款，并即时发放积分',
      };
    }
    var pkgName = name.replace(/¥\s*[\d,.]+\s*$/, '').trim() || '会员套餐';
    return {
      type: 'membership',
      itemLabel: '会员套餐',
      itemDesc: pkgName,
      hintWechat: '请使用微信扫码完成付款；成功后会员权益即时生效',
      hintAlipay: '请使用支付宝完成付款；成功后会员权益即时生效',
      hintBalance: '确认后将从账户余额扣款，并即时开通/续期会员',
    };
  }

  /** 居中确认框（替代浏览器顶部 confirm） */
  function pvConfirm(message, options) {
    options = options || {};
    return new Promise(function (resolve) {
      var msg = message || '';
      if (typeof bootstrap === 'undefined') {
        resolve(window.confirm(msg));
        return;
      }
      var el = document.getElementById('pvMemberConfirmModal');
      if (!el) {
        el = document.createElement('div');
        el.id = 'pvMemberConfirmModal';
        el.className = 'modal fade';
        el.setAttribute('tabindex', '-1');
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML =
          '<div class="modal-dialog modal-dialog-centered">' +
          '<div class="modal-content shadow pv-member-confirm-dialog">' +
          '<div class="modal-header border-0 pb-0">' +
          '<h5 class="modal-title pv-member-confirm-title">请确认</h5>' +
          '<button type="button" class="btn-close pv-member-confirm-x" data-bs-dismiss="modal" aria-label="关闭"></button>' +
          '</div>' +
          '<div class="modal-body pt-2 pb-2">' +
          '<p class="mb-0 pv-member-confirm-body text-secondary"></p>' +
          '<div class="pv-pay-confirm-panel d-none">' +
          '<dl class="pv-pay-confirm-meta mb-3">' +
          '<div class="pv-pay-confirm-meta__row">' +
          '<dt>充值项目</dt><dd class="pv-pay-confirm-item-name"></dd>' +
          '</div>' +
          '<div class="pv-pay-confirm-meta__row">' +
          '<dt>支付方式</dt><dd><span class="pv-pay-confirm-channel"></span></dd>' +
          '</div>' +
          '</dl>' +
          '<p class="pv-pay-confirm-item-desc small text-muted text-center mb-3"></p>' +
          '<div class="pv-pay-confirm-amount-box rounded-3 p-3 mb-2 text-center">' +
          '<div class="pv-pay-confirm-amount-label small text-muted mb-1">实付金额</div>' +
          '<div class="pv-pay-confirm-amount"></div>' +
          '</div>' +
          '<p class="pv-pay-confirm-hint small text-muted text-center mb-0"></p>' +
          '</div></div>' +
          '<div class="modal-footer border-0 pt-0 gap-2 flex-nowrap">' +
          '<button type="button" class="btn btn-outline-secondary flex-fill pv-member-confirm-cancel">取消</button>' +
          '<button type="button" class="btn btn-primary flex-fill pv-member-confirm-ok">确定</button>' +
          '</div></div></div>';
        document.body.appendChild(el);
      }
      var dialogEl = el.querySelector('.pv-member-confirm-dialog');
      var titleEl = el.querySelector('.pv-member-confirm-title');
      var bodyEl = el.querySelector('.pv-member-confirm-body');
      var payPanel = el.querySelector('.pv-pay-confirm-panel');
      var okBtn = el.querySelector('.pv-member-confirm-ok');
      var cancelBtn = el.querySelector('.pv-member-confirm-cancel');
      var isPay = options.variant === 'pay';

      if (dialogEl) {
        dialogEl.classList.toggle('pv-member-confirm-dialog--pay', isPay);
      }
      if (titleEl) titleEl.textContent = options.title || (isPay ? '确认付款' : '请确认');

      if (isPay && payPanel) {
        if (bodyEl) bodyEl.classList.add('d-none');
        payPanel.classList.remove('d-none');

        var channel = options.channel || 'wechat';
        var info = parsePayInfoFromTitle(options.productTitle || '');
        var amount = options.amount != null ? options.amount : info.amount;
        var scene = resolvePayScene(options.productTitle || info.product, options.rechargeType);

        var channelEl = payPanel.querySelector('.pv-pay-confirm-channel');
        var itemNameEl = payPanel.querySelector('.pv-pay-confirm-item-name');
        var itemDescEl = payPanel.querySelector('.pv-pay-confirm-item-desc');
        var amountEl = payPanel.querySelector('.pv-pay-confirm-amount');
        var hintEl = payPanel.querySelector('.pv-pay-confirm-hint');

        var channelClass = 'pv-pay-confirm-channel--wechat';
        var channelText = '微信';
        var okClass = 'btn pv-member-confirm-ok pv-pay-confirm-ok--wechat';
        var defaultHint = scene.hintWechat;
        if (channel === 'alipay') {
          channelClass = 'pv-pay-confirm-channel--alipay';
          channelText = '支付宝';
          okClass = 'btn pv-member-confirm-ok pv-pay-confirm-ok--alipay';
          defaultHint = scene.hintAlipay;
        } else if (channel === 'balance') {
          channelClass = 'pv-pay-confirm-channel--balance';
          channelText = '账户余额';
          okClass = 'btn pv-member-confirm-ok pv-pay-confirm-ok--balance';
          defaultHint = scene.hintBalance;
        }
        if (channelEl) {
          channelEl.className = 'pv-pay-confirm-channel ' + channelClass;
          channelEl.textContent = channelText;
        }
        if (itemNameEl) itemNameEl.textContent = scene.itemLabel;
        if (itemDescEl) {
          itemDescEl.textContent = scene.itemDesc;
          itemDescEl.classList.toggle('d-none', !scene.itemDesc || scene.type === 'balance');
        }
        if (amountEl) amountEl.textContent = formatPayAmount(amount);
        if (hintEl) hintEl.textContent = options.hint || defaultHint;
        if (okBtn) {
          okBtn.className = okClass + ' flex-fill';
          okBtn.textContent =
            options.okLabel ||
            (channel === 'balance' ? '确认支付 ' + formatPayAmount(amount) : '立即支付 ' + formatPayAmount(amount));
        }
        if (cancelBtn) cancelBtn.textContent = options.cancelLabel || '暂不支付';
      } else {
        if (bodyEl) {
          bodyEl.classList.remove('d-none');
          bodyEl.textContent = msg;
          bodyEl.style.whiteSpace = 'pre-line';
        }
        if (payPanel) payPanel.classList.add('d-none');
        if (okBtn) {
          okBtn.className = 'btn btn-primary flex-fill pv-member-confirm-ok';
          okBtn.textContent = options.okLabel || '确定';
        }
        if (cancelBtn) cancelBtn.textContent = options.cancelLabel || '取消';
      }

      var settled = false;
      function settle(ok) {
        if (settled) return;
        settled = true;
        el._pvConfirmSettle = null;
        resolve(!!ok);
        bootstrap.Modal.getOrCreateInstance(el).hide();
      }

      if (!el.dataset.pvConfirmInit) {
        el.dataset.pvConfirmInit = '1';
        okBtn.addEventListener('click', function () {
          if (el._pvConfirmSettle) el._pvConfirmSettle(true);
        });
        cancelBtn.addEventListener('click', function () {
          if (el._pvConfirmSettle) el._pvConfirmSettle(false);
        });
        el.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            if (el._pvConfirmSettle) el._pvConfirmSettle(false);
          });
        });
        el.addEventListener('hidden.bs.modal', function () {
          if (el._pvConfirmSettle) el._pvConfirmSettle(false);
        });
      }
      el._pvConfirmSettle = settle;

      bootstrap.Modal.getOrCreateInstance(el).show();
    });
  }

  function showSidebarTip(ok, msg) {
    var tip = document.getElementById('pv-sidebar-avatar-tip');
    if (!tip) return;
    if (!msg) {
      tip.className = 'text-muted small mb-0 mt-1 d-none';
      tip.textContent = '';
      return;
    }
    tip.className = (ok ? 'text-success' : 'text-danger') + ' small mb-0 mt-1';
    tip.textContent = msg;
  }

  function getCsrfToken() {
    var el = document.getElementById('pv-member-csrf-token');
    if (el && el.value) return el.value;
    var form = document.getElementById('pv-profile-form');
    if (form) {
      var input = form.querySelector('[name="__token"]');
      if (input && input.value) return input.value;
    }
    return '';
  }

  function syncCsrfFromResponse(res) {
    if (!res || typeof res !== 'object' || !res.meta) return;
    var token = res.meta.csrf_token ? String(res.meta.csrf_token) : '';
    if (!token) return;
    var field = res.meta.csrf_field ? String(res.meta.csrf_field) : '__token';
    var holder = document.getElementById('pv-member-csrf-token');
    if (holder) holder.value = token;
    document.querySelectorAll('input[name="' + field + '"], input[name="__token"]').forEach(function (input) {
      input.value = token;
    });
  }

  function syncSidebarAvatar(url) {
    var img = document.getElementById('pv-sidebar-avatar-img');
    var icon = document.getElementById('pv-sidebar-avatar-icon');
    var letter = document.getElementById('pv-sidebar-avatar-letter');
    var hasUrl = !!(url && String(url).trim());
    if (img) {
      if (hasUrl) {
        img.src = url;
        img.hidden = false;
      } else {
        img.removeAttribute('src');
        img.hidden = true;
      }
    }
    if (icon) icon.hidden = hasUrl;
    if (letter) letter.hidden = hasUrl;
  }

  function bindSidebarAvatar() {
    var btn = document.getElementById('pv-sidebar-avatar-btn');
    var fileInput = document.getElementById('pv-sidebar-avatar-file');
    if (!btn || !fileInput) return;

    var uploadUrl = btn.getAttribute('data-upload-url') || pvUrl('memberUploadImage', '/api/v1/member/upload/image');
    var profileUrl = btn.getAttribute('data-profile-url') || pvUrl('memberProfile', '/api/v1/member/profile');
    var overlay = document.getElementById('pv-sidebar-avatar-overlay');

    btn.addEventListener('click', function () {
      if (!btn.disabled) fileInput.click();
    });

    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      fileInput.value = '';
      if (!file) return;
      if (!file.type || file.type.indexOf('image/') !== 0) {
        showSidebarTip(false, '请选择图片文件');
        return;
      }

      var token = getCsrfToken();
      if (!token) {
        showSidebarTip(false, '页面已过期，请刷新后重试');
        return;
      }

      var fd = new FormData();
      fd.append('file', file);
      fd.append('scene', 'user');
      fd.append('__token', token);

      btn.disabled = true;
      if (overlay) overlay.textContent = '上传中';

      fetch(uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          var url = res && res.data && res.data.url ? res.data.url : '';
          if (!url || (res && res.error)) {
            showSidebarTip(false, (res && res.error && res.error.message) || (res && res.msg) || '上传失败');
            return;
          }
          syncSidebarAvatar(url);
          return saveAvatarProfile(profileUrl, url, token);
        })
        .then(function (saveRes) {
          if (saveRes === undefined) return;
          syncCsrfFromResponse(saveRes);
          if (saveRes && saveRes.code) {
            showSidebarTip(true, '头像已更新');
            showAlert(document.getElementById('pv-profile-alert'), true, '头像已更新');
          } else {
            showSidebarTip(false, (saveRes && saveRes.msg) || '保存头像失败');
          }
        })
        .catch(function () {
          showSidebarTip(false, '网络错误，请稍后重试');
        })
        .finally(function () {
          btn.disabled = false;
          if (overlay) overlay.textContent = '更换';
        });
    });
  }

  function saveAvatarProfile(profileUrl, avatarUrl, token) {
    var fd = new FormData();
    fd.append('__token', token);
    fd.append('avatar', avatarUrl);
    return fetch(profileUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json();
    });
  }

  function bindAjaxForm(formId, alertId) {
    var form = document.getElementById(formId);
    if (!form) return;
    var alertEl = document.getElementById(alertId);
    var url = form.getAttribute('data-pv-submit');
    if (!url) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (alertEl) alertEl.className = 'alert d-none';
      fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          var ok = ajaxOk(res);
          if (ok) syncCsrfFromResponse(res);
          showAlert(alertEl, ok, ajaxMsg(res, ''));
          if (ok && formId === 'pv-password-form') {
            form.reset();
          }
          if (ok && formId === 'pv-cancel-form') {
            form.reset();
          }
        })
        .catch(function () {
          showAlert(alertEl, false, '网络错误，请稍后重试');
        });
    });
  }

  var signinForm = document.getElementById('pv-signin-form');
  if (signinForm) {
    signinForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = document.getElementById('pv-signin-btn');
      var tip = document.getElementById('pv-signin-tip');
      if (btn) btn.disabled = true;
      fetch(signinForm.getAttribute('action') || pvUrl('memberSignin', '/api/v1/member/signin'), {
        method: 'POST',
        body: new FormData(signinForm),
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (ajaxOk(res)) {
            var payload = ajaxPayload(res);
            if (tip) {
              tip.textContent = ajaxMsg(res, '签到成功');
              tip.classList.remove('d-none');
            }
            if (payload.member) applyMemberSnapshot(payload.member);
            if (btn) {
              btn.textContent = '已签到';
              btn.classList.remove('btn-primary');
              btn.classList.add('btn-outline-secondary');
            }
            var valEl = signinForm.closest('.pv-member-stat');
            if (valEl) {
              var v = valEl.querySelector('.pv-member-stat__value');
              if (v) v.textContent = '今日已签';
            }
          } else {
            if (btn) btn.disabled = false;
            if (tip) {
              tip.textContent = ajaxMsg(res, '签到失败');
              tip.classList.remove('d-none', 'text-success');
              tip.classList.add('text-danger');
            }
          }
        })
        .catch(function () {
          if (btn) btn.disabled = false;
          if (tip) {
            tip.textContent = '网络错误，请稍后重试';
            tip.classList.remove('d-none', 'text-success');
            tip.classList.add('text-danger');
          }
        });
    });
  }

  bindSidebarAvatar();
  bindAjaxForm('pv-profile-form', 'pv-profile-alert');
  bindAjaxForm('pv-password-form', 'pv-password-alert');
  bindAjaxForm('pv-cancel-form', 'pv-cancel-alert');

  var rechargeForm = document.getElementById('pv-recharge-form');
  var rechargeAlert = document.getElementById('pv-recharge-alert');
  var rechargePackageId = document.getElementById('pv-recharge-package-id');
  var rechargeCustomAmountField = document.getElementById('pv-recharge-custom-amount-field');
  var rechargeTypeField = document.getElementById('pv-recharge-recharge-type');
  var rechargeUrl = rechargeForm ? rechargeForm.getAttribute('data-pv-submit') : '';

  function clearPackagePurchaseFields() {
    if (rechargePackageId) rechargePackageId.value = '';
    if (rechargeCustomAmountField) rechargeCustomAmountField.value = '';
    if (rechargeTypeField) rechargeTypeField.value = '';
  }

  function setPackagePurchaseFields(packageId) {
    if (rechargePackageId) rechargePackageId.value = packageId;
    if (rechargeCustomAmountField) rechargeCustomAmountField.value = '';
    if (rechargeTypeField) rechargeTypeField.value = '';
  }

  function setCustomPurchaseFields(type, amount) {
    if (rechargePackageId) rechargePackageId.value = '0';
    if (rechargeCustomAmountField) rechargeCustomAmountField.value = String(amount);
    if (rechargeTypeField) rechargeTypeField.value = type;
  }

  function parseCustomAmountFromPanel(panel) {
    if (!panel) return null;
    var input = panel.querySelector('.pv-recharge-custom-amount');
    if (!input) return null;
    var min = parseFloat(panel.getAttribute('data-min') || '0.01');
    var max = parseFloat(panel.getAttribute('data-max') || '50000');
    var val = parseFloat(String(input.value).replace(/,/g, ''));
    if (isNaN(val) || val < min - 0.0001 || val > max + 0.0001) {
      return null;
    }
    return Math.round(val * 100) / 100;
  }

  function customTitle(type, amount) {
    return type === 'points' ? '积分充值 ¥' + amount.toFixed(2) : '余额充值 ¥' + amount.toFixed(2);
  }

  function updateCustomEstimate(panel) {
    if (!panel) return;
    var amount = parseCustomAmountFromPanel(panel);
    var estEl = panel.querySelector('.pv-recharge-custom-est');
    if (!estEl) return;
    var type = panel.getAttribute('data-recharge-type') || 'balance';
    if (amount === null) {
      estEl.textContent = type === 'points' ? '0' : '¥0.00';
      return;
    }
    if (type === 'points') {
      var perYuan = parseFloat(panel.getAttribute('data-points-per-yuan') || '10');
      if (isNaN(perYuan) || perYuan < 1) perYuan = 10;
      estEl.textContent = String(Math.floor(amount * perYuan));
    } else {
      estEl.textContent = '¥' + amount.toFixed(2);
    }
  }

  document.querySelectorAll('.pv-recharge-custom').forEach(function (panel) {
    var input = panel.querySelector('.pv-recharge-custom-amount');
    if (input) {
      input.addEventListener('input', function () {
        updateCustomEstimate(panel);
      });
    }
    updateCustomEstimate(panel);
  });

  function submitRechargePurchase() {
    if (!rechargeForm || !rechargeUrl) return;
    if (rechargeAlert) rechargeAlert.className = 'alert d-none';
    fetch(rechargeUrl, { method: 'POST', body: new FormData(rechargeForm), credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        showAlert(rechargeAlert, ajaxOk(res), ajaxMsg(res, ''));
        if (ajaxOk(res)) {
          var payload = ajaxPayload(res);
          if (payload.member) {
            applyRechargeMember(payload.member);
          } else {
            setTimeout(function () {
              location.reload();
            }, 600);
          }
        }
      })
      .catch(function () {
        showAlert(rechargeAlert, false, '网络错误，请稍后重试');
      });
  }

  document.querySelectorAll('.pv-recharge-buy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-package-id') || '';
      var title = btn.getAttribute('data-package-title') || '该套餐';
      if (!rechargePackageId || !id) return;
      var buyPrice = parseFloat(btn.getAttribute('data-package-price') || '');
      var buyInfo = parsePayInfoFromTitle(title);
      pvConfirm('', {
        variant: 'pay',
        title: '余额购买',
        channel: 'balance',
        productTitle: buyInfo.product || title,
        amount: !isNaN(buyPrice) && buyPrice > 0 ? buyPrice : buyInfo.amount,
        rechargeType: btn.getAttribute('data-package-type') || '',
      }).then(function (ok) {
        if (!ok) return;
        setPackagePurchaseFields(id);
        submitRechargePurchase();
      });
    });
  });

  document.querySelectorAll('.pv-recharge-custom-buy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = btn.closest('.pv-recharge-custom');
      var type = panel ? panel.getAttribute('data-recharge-type') || '' : '';
      var amount = parseCustomAmountFromPanel(panel);
      if (!type || amount === null) {
        showAlert(rechargeAlert, false, '请输入有效充值金额');
        return;
      }
      var title = customTitle(type, amount);
      pvConfirm('', {
        variant: 'pay',
        title: '余额购买',
        channel: 'balance',
        productTitle: customTitle(type, amount),
        amount: amount,
        rechargeType: type,
      }).then(function (ok) {
        if (!ok) return;
        setCustomPurchaseFields(type, amount);
        submitRechargePurchase();
      });
    });
  });

  var payForm = document.getElementById('pv-pay-form');
  var payPackageId = document.getElementById('pv-pay-package-id');
  var payCustomAmount = document.getElementById('pv-pay-custom-amount');
  var payRechargeType = document.getElementById('pv-pay-recharge-type');
  var payChannel = document.getElementById('pv-pay-channel');
  var payUrl = payForm ? payForm.getAttribute('data-pv-submit') : '';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  var payStatusPollActive = false;
  var payStatusPollTimer = null;

  function stopPayStatusPoll() {
    payStatusPollActive = false;
    if (payStatusPollTimer) {
      clearTimeout(payStatusPollTimer);
      payStatusPollTimer = null;
    }
  }

  /**
   * 轮询支付结果（微信扫码弹窗、支付回跳页共用）
   * @param {function(object):void} [onPaid] 默认跳转 res.reload
   */
  function setPayPollHint(text) {
    document.querySelectorAll('.pv-wechat-pay-poll-hint, #pv-pay-poll-hint').forEach(function (el) {
      if (text) el.textContent = text;
    });
  }

  function startPayStatusPoll(orderNo, options) {
    options = options || {};
    orderNo = (orderNo || '').trim();
    if (!orderNo) return;

    stopPayStatusPoll();
    payStatusPollActive = true;

    var pollUrl = options.statusUrl || pvUrl('memberPayStatus', '/api/v1/member/pay/status');
    var maxTries = options.maxTries || 45;
    var intervalMs = options.intervalMs || 2000;
    var tries = 0;
    setPayPollHint('正在等待支付结果…（约每 2 秒查询一次）');

    function tick() {
      if (!payStatusPollActive) return;
      tries++;
      if (tries > 1 && tries % 5 === 0) {
        setPayPollHint('仍在等待支付，请完成扫码（已查询 ' + tries + ' 次）');
      }
      fetch(pollUrl + '?order_no=' + encodeURIComponent(orderNo), { credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          if (!payStatusPollActive) return;
          if (ajaxOk(res) && ajaxPayload(res).paid) {
            stopPayStatusPoll();
            setPayPollHint('支付成功，正在跳转…');
            if (typeof options.onPaid === 'function') {
              options.onPaid(ajaxPayload(res));
              return;
            }
            var target = (ajaxPayload(res).reload || '').trim();
            if (target) {
              location.href = target;
            } else {
              location.reload();
            }
            return;
          }
          if (tries < maxTries) {
            payStatusPollTimer = setTimeout(tick, intervalMs);
          }
        })
        .catch(function () {
          if (payStatusPollActive && tries < maxTries) {
            payStatusPollTimer = setTimeout(tick, intervalMs);
          }
        });
    }

    payStatusPollTimer = setTimeout(tick, 800);
  }

  /** 微信 Native：code_url 为 weixin://，须生成二维码供手机扫，不能 window.open */
  function showWechatPayQr(codeUrl, orderNo) {
    var el = document.getElementById('pvMemberWechatPayQrModal');
    if (!el) {
      el = document.createElement('div');
      el.id = 'pvMemberWechatPayQrModal';
      el.className = 'modal fade';
      el.setAttribute('tabindex', '-1');
      el.setAttribute('aria-hidden', 'true');
      el.innerHTML =
        '<div class="modal-dialog modal-dialog-centered">' +
        '<div class="modal-content">' +
        '<div class="modal-header">' +
        '<h5 class="modal-title">微信扫码支付</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>' +
        '</div>' +
        '<div class="modal-body text-center">' +
        '<p class="text-muted small">请使用手机微信扫一扫（勿在电脑点「打开微信」）</p>' +
        '<img class="pv-wechat-pay-qr mb-3" alt="微信支付二维码" width="220" height="220" />' +
        '<p class="text-muted small mb-1 pv-wechat-pay-order"></p>' +
        '<p class="text-muted small mb-2 pv-wechat-pay-poll-hint">支付成功后将自动跳转，请勿关闭本页</p>' +
        '<a href="#" class="btn btn-sm btn-outline-secondary pv-wechat-pay-done d-none">我已完成支付</a>' +
        '</div></div></div>';
      document.body.appendChild(el);
      el.addEventListener('hidden.bs.modal', function () {
        stopPayStatusPoll();
      });
    }
    var img = qs('.pv-wechat-pay-qr', el);
    var orderEl = qs('.pv-wechat-pay-order', el);
    var doneBtn = qs('.pv-wechat-pay-done', el);
    if (img) {
      img.src =
        'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' +
        encodeURIComponent(codeUrl);
    }
    if (orderEl) {
      orderEl.textContent = orderNo ? '订单号：' + orderNo : '';
    }
    if (doneBtn) {
      doneBtn.classList.toggle('d-none', !orderNo);
      doneBtn.onclick = function (e) {
        e.preventDefault();
        if (orderNo) {
          location.href = pvPayReturn(orderNo);
        }
      };
    }
    if (typeof bootstrap !== 'undefined') {
      bootstrap.Modal.getOrCreateInstance(el).show();
    }
    if (orderNo) {
      startPayStatusPoll(orderNo, {
        onPaid: function (res) {
          if (typeof bootstrap !== 'undefined') {
            var inst = bootstrap.Modal.getInstance(el);
            if (inst) inst.hide();
          }
          if (res.member) {
            applyRechargeMember(res.member);
          }
          var target = (res.reload || '').trim();
          if (target) {
            location.href = target;
          } else if (orderNo) {
            location.href = pvPayReturn(orderNo);
          } else {
            location.reload();
          }
        },
      });
    }
  }

  function submitOnlinePay(channel, packageId, title, opts) {
    opts = opts || {};
    if (!payForm || !payUrl || !payPackageId || !payChannel) return;
    if (!packageId && !opts.customAmount) return;
    var payInfo = parsePayInfoFromTitle(title);
    var amount =
      opts.customAmount != null
        ? opts.customAmount
        : opts.amount != null
          ? opts.amount
          : payInfo.amount;
    pvConfirm('', {
      variant: 'pay',
      title: '确认付款',
      channel: channel,
      productTitle: payInfo.product || title,
      amount: amount,
      rechargeType: opts.rechargeType || '',
    }).then(function (ok) {
      if (!ok) return;
      doSubmitOnlinePay(channel, packageId, opts);
    });
  }

  function doSubmitOnlinePay(channel, packageId, opts) {
    opts = opts || {};
    if (!payForm || !payUrl || !payPackageId || !payChannel) return;
    if (rechargeAlert) rechargeAlert.className = 'alert d-none';
    payPackageId.value = packageId || '0';
    payChannel.value = channel;
    if (payCustomAmount) payCustomAmount.value = opts.customAmount ? String(opts.customAmount) : '';
    if (payRechargeType) payRechargeType.value = opts.rechargeType || '';
    setRechargePayLoading(true);
    if (rechargeAlert) {
      rechargeAlert.textContent = channel === 'wechat' ? '正在连接微信支付，请稍候…' : '正在连接支付宝，请稍候…';
      rechargeAlert.className = 'alert alert-info';
    }
    fetch(payUrl, { method: 'POST', body: new FormData(payForm), credentials: 'same-origin' })
      .then(function (r) {
        return parseFetchJson(r);
      })
      .then(function (res) {
        setRechargePayLoading(false);
        var payload = ajaxPayload(res);
        if (!ajaxOk(res)) {
          showAlert(rechargeAlert, false, friendlyPayError(ajaxMsg(res, '')));
          return;
        }
        if (rechargeAlert) rechargeAlert.className = 'alert d-none';
        if (payload.form_html) {
          var w = window.open('', '_blank');
          if (w) {
            w.document.write(payload.form_html);
            w.document.close();
          } else {
            showAlert(rechargeAlert, false, '请允许弹窗后重试');
          }
          return;
        }
        if (payload.code_url) {
          showWechatPayQr(payload.code_url, payload.order_no || '');
          if (rechargeAlert) rechargeAlert.className = 'alert d-none';
          return;
        }
        if (payload.redirect) {
          if (payload.demo || payload.type === 'demo') {
            var demoMsg = ajaxMsg(res, '演示模式：将模拟支付成功，不会调用微信/支付宝，也不会扣余额。');
            pvConfirm(demoMsg + '\n\n确定继续？', { title: '演示模式' }).then(function (demoOk) {
              if (demoOk) location.href = payload.redirect;
            });
            return;
          }
          location.href = payload.redirect;
          return;
        }
        showAlert(rechargeAlert, true, ajaxMsg(res, '已提交'));
        setTimeout(function () {
          location.reload();
        }, 800);
      })
      .catch(function () {
        setRechargePayLoading(false);
        showAlert(rechargeAlert, false, '网络错误，请稍后重试');
      });
  }

  document.querySelectorAll('.pv-recharge-pay').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-package-id') || '';
      var title = btn.getAttribute('data-package-title') || '该套餐';
      var channel = btn.getAttribute('data-channel') || 'alipay';
      var pkgPrice = parseFloat(btn.getAttribute('data-package-price') || '');
      if (payCustomAmount) payCustomAmount.value = '';
      if (payRechargeType) payRechargeType.value = '';
      submitOnlinePay(channel, id, title, {
        amount: !isNaN(pkgPrice) && pkgPrice > 0 ? pkgPrice : undefined,
        rechargeType: btn.getAttribute('data-package-type') || '',
      });
    });
  });

  document.querySelectorAll('.pv-recharge-custom-pay').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = btn.closest('.pv-recharge-custom');
      var type = panel ? panel.getAttribute('data-recharge-type') || '' : '';
      var amount = parseCustomAmountFromPanel(panel);
      if (!type || amount === null) {
        showAlert(rechargeAlert, false, '请输入有效充值金额');
        return;
      }
      var channel = btn.getAttribute('data-channel') || 'alipay';
      submitOnlinePay(channel, '0', customTitle(type, amount), {
        customAmount: amount,
        rechargeType: type,
      });
    });
  });

  var payPollEl = document.getElementById('pv-pay-return-poll');
  if (payPollEl && payPollEl.getAttribute('data-paid') === '1') {
    var paidRedirect = (payPollEl.getAttribute('data-auto-redirect') || '').trim();
    if (paidRedirect) {
      setTimeout(function () {
        location.href = paidRedirect;
      }, 1200);
    }
  } else if (payPollEl && payPollEl.getAttribute('data-paid') !== '1') {
    var pollOrderNo = payPollEl.getAttribute('data-order-no') || '';
    var pollUrl = payPollEl.getAttribute('data-status-url') || pvUrl('memberPayStatus', '/api/v1/member/pay/status');
    var pollHint = document.getElementById('pv-pay-poll-hint');
    if (pollOrderNo && pollHint) {
      pollHint.classList.remove('d-none');
    }
    if (pollOrderNo) {
      startPayStatusPoll(pollOrderNo, { statusUrl: pollUrl, maxTries: 45, intervalMs: 2000 });
    }
  }

  var docForm = document.getElementById('pv-document-form');
  if (docForm) {
    var docAlert = document.getElementById('pv-document-alert');
    docForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (docAlert) docAlert.className = 'alert d-none';
      fetch(pvUrl('memberDocumentSave', '/api/v1/member/document/save'), { method: 'POST', body: new FormData(docForm), credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (res) {
          var payload = ajaxPayload(res);
          if (ajaxOk(res) && payload.redirect) {
            location.href = payload.redirect;
            return;
          }
          showAlert(docAlert, ajaxOk(res), ajaxMsg(res, ''));
        })
        .catch(function () {
          showAlert(docAlert, false, '网络错误，请稍后重试');
        });
    });
  }
})();
