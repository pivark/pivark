/**
 * PivArk 前台访问统计信标（停留、跳出、点击热力格）
 */
(function () {
  if (typeof window === 'undefined' || window.__pvStatsLoaded) {
    return;
  }
  window.__pvStatsLoaded = true;

  var start = Date.now();
  var path = window.location.pathname || '/';
  var maxScroll = 0;
  var pageSent = false;

  function pct(n, d) {
    if (!d) {
      return 0;
    }
    return Math.max(0, Math.min(100, Math.round((n / d) * 100)));
  }

  function post(body) {
    try {
      var url = '/api/v1/stats/beacon';
      var json = JSON.stringify(body);
      if (navigator.sendBeacon) {
        var blob = new Blob([json], { type: 'application/json' });
        navigator.sendBeacon(url, blob);
        return;
      }
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: json,
        keepalive: true,
      }).catch(function () {});
    } catch (e) {
      /* ignore */
    }
  }

  function sendPageLeave() {
    if (pageSent) {
      return;
    }
    pageSent = true;
    var dwell = Math.round((Date.now() - start) / 1000);
    var bounced = dwell < 8 && maxScroll < 15 ? 1 : 0;
    post({ path: path, dwell_sec: dwell, bounced: bounced });
  }

  window.addEventListener(
    'scroll',
    function () {
      var st = window.scrollY || document.documentElement.scrollTop || 0;
      var dh =
        (document.documentElement.scrollHeight || 1) -
        (window.innerHeight || 1);
      if (dh > 0) {
        maxScroll = Math.max(maxScroll, pct(st, dh));
      }
    },
    { passive: true },
  );

  document.addEventListener(
    'click',
    function () {
      post({
        path: path,
        dwell_sec: 0,
        bounced: 0,
        click_x: 50,
        click_y: 50,
      });
    },
    true,
  );

  window.addEventListener('pagehide', sendPageLeave);
  window.addEventListener('beforeunload', sendPageLeave);
})();
