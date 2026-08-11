(function () {
  'use strict';

  var STORAGE_PREFIX = 'pv_overlay_closed_';

  function storageKey(id) {
    return STORAGE_PREFIX + String(id);
  }

  function isClosed(id) {
    try {
      return sessionStorage.getItem(storageKey(id)) === '1';
    } catch (e) {
      return false;
    }
  }

  function markClosed(id) {
    try {
      sessionStorage.setItem(storageKey(id), '1');
    } catch (e) {
      /* ignore */
    }
  }

  function initMourning() {
    var nodes = document.querySelectorAll('[data-overlay-type="mourning"]');
    if (!nodes.length) {
      return;
    }
    document.documentElement.classList.add('pv-site-mourning-active');
  }

  function bindOverlay(el) {
    var id = el.getAttribute('data-overlay-id') || '';
    if (id && isClosed(id)) {
      el.setAttribute('hidden', 'hidden');
      return false;
    }

    el.removeAttribute('hidden');

    function close() {
      if (id) {
        markClosed(id);
      }
      el.setAttribute('hidden', 'hidden');
    }

    el.querySelectorAll('[data-overlay-close]').forEach(function (btn) {
      btn.addEventListener('click', close);
    });

    return true;
  }

  function initOverlays() {
    initMourning();

    var overlays = Array.prototype.slice.call(
      document.querySelectorAll('.pv-site-overlay--center'),
    );
    if (!overlays.length) {
      return;
    }

    for (var i = 0; i < overlays.length; i += 1) {
      if (bindOverlay(overlays[i])) {
        break;
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOverlays);
  } else {
    initOverlays();
  }
})();
