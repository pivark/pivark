/**
 * 演示站 · 产品目录 · 参数筛选 + AJAX 列表
 */
(function () {
  'use strict';

  var listEl = document.getElementById('pv-product-list');
  var emptyEl = document.getElementById('pv-product-empty');
  var resetBtn = document.getElementById('pv-product-filter-reset');
  var emptyReset = document.getElementById('pv-product-empty-reset');
  var filterEl = document.getElementById('pv-product-filters');
  var loadMoreBtn = document.getElementById('pv-product-load-more');
  var kwInput = document.getElementById('pv-product-kw');
  var kwForm = kwInput ? kwInput.closest('form') : null;
  var catalogApi = (listEl && listEl.getAttribute('data-catalog-api')) || pvUrl('apiItemsCatalog', '/api/v1/catalog/items');
  var filterCatalogApi =
    (filterEl && filterEl.getAttribute('data-catalog-api')) ||
    (filterEl && filterEl.getAttribute('data-api')) ||
    catalogApi;
  var listLimit = (listEl && parseInt(listEl.getAttribute('data-limit') || '24', 10)) || 24;
  var ajaxList = !!(listEl && listEl.getAttribute('data-ajax-list') === '1');
  var optCollapseAt = 12;
  var loading = false;
  var pendingReload = null;
  var nextCursor = '';
  var appendMode = false;

  function currentParams() {
    return new URLSearchParams(location.search);
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  function optValue(opt) {
    return typeof opt === 'string' ? opt : (opt && opt.value ? String(opt.value) : '');
  }

  function optCount(opt) {
    return typeof opt === 'object' && opt && typeof opt.count === 'number' ? opt.count : null;
  }

  function optDisabled(opt) {
    return typeof opt === 'object' && opt && opt.disabled === true;
  }

  function cardCount() {
    return listEl ? listEl.querySelectorAll('[data-pv-product-card]').length : 0;
  }

  function syncEmptyState(count) {
    if (!listEl || !emptyEl) {
      return;
    }
    var n = typeof count === 'number' ? count : cardCount();
    emptyEl.classList.toggle('d-none', n > 0);
    listEl.classList.toggle('d-none', n === 0);
  }

  function syncLoadMore(hasMore) {
    if (!loadMoreBtn) {
      return;
    }
    loadMoreBtn.classList.toggle('d-none', !hasMore);
  }

  function hasActiveFilters() {
    var p = currentParams();
    return Array.from(p.keys()).some(function (k) {
      return (k.indexOf('filter_') === 0 && p.get(k)) || k === 'keyword' || k === 'tag';
    });
  }

  /** 会改变 SSR 列表结果的查询（不含栏目 tag：频道页 data-tag 已落在首屏） */
  function hasListMutatingQuery() {
    var p = currentParams();
    return Array.from(p.keys()).some(function (k) {
      return (k.indexOf('filter_') === 0 && p.get(k)) || k === 'keyword';
    });
  }

  function bindReset() {
    var show = hasActiveFilters();
    if (resetBtn) {
      resetBtn.classList.toggle('d-none', !show);
    }
    var clear = function () {
      if (ajaxList) {
        navigateParams(new URLSearchParams());
      } else {
        location.href = location.pathname;
      }
    };
    if (resetBtn) {
      resetBtn.onclick = clear;
    }
    if (emptyReset) {
      emptyReset.onclick = function (e) {
        e.preventDefault();
        clear();
      };
    }
  }

  function navigateParams(params, keepCursor) {
    if (!keepCursor) {
      params.delete('page');
      params.delete('cursor');
      nextCursor = '';
      appendMode = false;
    }
    var qs = params.toString();
    history.pushState(null, '', location.pathname + (qs ? '?' + qs : ''));
    loadCatalog(!keepCursor);
    bindReset();
    syncSidebarActive();
  }

  function applyFilter(key, value) {
    var params = currentParams();
    if (value) {
      params.set(key, value);
    } else {
      params.delete(key);
    }
    if (ajaxList) {
      navigateParams(params);
      return;
    }
    params.delete('page');
    params.delete('cursor');
    var qs = params.toString();
    location.href = location.pathname + (qs ? '?' + qs : '');
  }

  function renderSkeletonCard() {
    return (
      '<div class="col-6 col-md-4 col-lg-3 col-xl-3" data-pv-product-card>'
      + '<article class="card h-100 shadow-sm text-center pv-product-card pv-product-card--catalog pv-product-card--skeleton" aria-hidden="true">'
      + '<div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media"><div class="pv-skeleton-block pv-skeleton-block--cover"></div></div>'
      + '<div class="card-body py-3 pv-product-card__body">'
      + '<div class="pv-skeleton-block pv-skeleton-block--title"></div>'
      + '<div class="pv-skeleton-block pv-skeleton-block--line"></div>'
      + '</div></article></div>'
    );
  }

  function renderSkeletonGrid(count) {
    var n = count || 8;
    var html = '';
    for (var i = 0; i < n; i++) {
      html += renderSkeletonCard();
    }
    return html;
  }

  function revealCardsProgressively(rows, replace) {
    if (!rows.length) {
      if (replace) listEl.innerHTML = '';
      return Promise.resolve();
    }
    if (replace) listEl.innerHTML = '';
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) {
      listEl.insertAdjacentHTML('beforeend', rows.map(renderCard).join(''));
      syncEmptyState(cardCount());
      return Promise.resolve();
    }
    var index = 0;
    var batch = 4;
    var delay = 64;
    return new Promise(function (resolve) {
      function tick() {
        var chunk = rows.slice(index, index + batch);
        if (!chunk.length) {
          resolve();
          return;
        }
        listEl.insertAdjacentHTML('beforeend', chunk.map(renderCard).join(''));
        index += batch;
        syncEmptyState(cardCount());
        if (index < rows.length) {
          window.setTimeout(tick, delay);
        } else {
          resolve();
        }
      }
      tick();
    });
  }

  function listCoverUrl(item) {
    /* 列表优先 litpic/thumb_url，避免直接甩原图 cover_url（易优迁入常数百 KB～数 MB） */
    return (
      (item && (item.litpic || item.thumb_url || item.cover_url)) ||
      ''
    );
  }

  function renderCard(item) {
    /* 与 list_document_product / list_page_products SSR 列宽一致（左主栏三列） */
    var colClass = listEl.classList.contains('pv-product-list--catalog')
      ? 'col-6 col-md-4'
      : 'col-sm-6 col-lg-4 col-xl-3';
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var wrapReveal = reduceMotion ? '' : ' pv-product-card-wrap--reveal';
    var coverSrc = listCoverUrl(item);
    var cover = coverSrc
      ? '<div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media"><img src="' + esc(coverSrc) + '" alt="' + esc(item.name || '') + '" loading="lazy" decoding="async" class="object-fit-cover" width="400" height="400"></div>'
      : '<div class="ratio ratio-1x1 bg-light border-bottom pv-product-card__media"><div class="pv-product-card__ph" aria-hidden="true"></div></div>';
    return (
      '<div class="' + colClass + wrapReveal + '" data-pv-product-card>'
      + '<article class="card h-100 shadow-sm text-center pv-product-card pv-product-card--catalog">'
      + '<a class="d-flex flex-column h-100 text-decoration-none text-body" href="' +
      esc(item.card_url || item.page_url || '#') +
      '">'
      + cover
      + '<div class="card-body py-3 pv-product-card__body"><h2 class="h6 card-title mb-1 pv-product-card__name">' +
      esc(item.name || '') +
      '</h2>'
      + '<p class="card-text small text-muted mb-0 pv-product-card__model">型号：' +
      esc(item.code || '') +
      '</p>'
      + '</div></a></article></div>'
    );
  }

  function apiParams(skipTotal) {
    var params = currentParams();
    params.set('limit', String(listLimit));
    if (skipTotal) {
      params.set('skip_total', '1');
    }
    if (appendMode && nextCursor) {
      params.set('cursor', nextCursor);
      params.delete('page');
    }
    var scopeTag = listEl && listEl.getAttribute('data-tag');
    if (scopeTag && !params.get('tag')) {
      params.set('tag', scopeTag);
    }
    return params;
  }

  function isApiOk(res) {
    if (window.PivarkApi) {
      return PivarkApi.ajaxOk(res);
    }
    return !!(res && typeof res === 'object' && !res.error && res.data !== undefined);
  }

  function parseFiltersFromResponse(res) {
    if (window.PivarkApi && typeof PivarkApi.catalogFilters === 'function') {
      return PivarkApi.catalogFilters(res);
    }
    if (!isApiOk(res)) {
      return [];
    }
    var data = res.data;
    if (Array.isArray(data)) {
      return data;
    }
    if (data && Array.isArray(data.filters)) {
      return data.filters;
    }
    return [];
  }

  function renderFilters(list) {
    if (!filterEl || !list || !list.length) {
      return;
    }
    var params = currentParams();
    var html = '<div class="pv-product-filter-board__rows">';
    list.forEach(function (f) {
      var paramKey = 'filter_' + f.param_key;
      var current = params.get(paramKey) || '';
      var opts = f.options || [];
      var many = opts.length > optCollapseAt;
      html +=
        '<div class="pv-product-filter-row' +
        (many ? ' pv-product-filter-row--many' : '') +
        '" role="group" aria-label="' +
        esc(f.label) +
        '">';
      html += '<span class="pv-product-filter-row__label">' + esc(f.label) + '：</span>';
      html += '<div class="pv-product-filter-row__opts">';
      html +=
        '<button type="button" class="pv-product-filter-link' +
        (current === '' ? ' is-active' : '') +
        '" data-key="' +
        esc(paramKey) +
        '" data-value="">不限</button>';
      if (many) {
        html +=
          '<input type="search" class="form-control form-control-sm pv-product-filter-search" placeholder="筛选' +
          esc(f.label) +
          '" aria-label="搜索' +
          esc(f.label) +
          '">';
        html += '<div class="pv-product-filter-row__scroll">';
      }
      opts.forEach(function (opt) {
        var val = optValue(opt);
        if (!val) {
          return;
        }
        var cnt = optCount(opt);
        var label =
          esc(val) +
          (cnt !== null && cnt > 0 ? ' <span class="pv-product-filter-count">(' + cnt + ')</span>' : '');
        var dis = optDisabled(opt) ? ' disabled aria-disabled="true"' : '';
        var active = current === val ? ' is-active' : '';
        html +=
          '<button type="button" class="pv-product-filter-link' +
          active +
          '"' +
          dis +
          ' data-key="' +
          esc(paramKey) +
          '" data-value="' +
          esc(val) +
          '" data-label="' +
          esc(val) +
          '">' +
          label +
          '</button>';
      });
      if (many) {
        html += '</div>';
      }
      html += '</div></div>';
    });
    html += '</div>';
    filterEl.innerHTML = html;
    filterEl.querySelectorAll('.pv-product-filter-link').forEach(function (btn) {
      if (btn.disabled) {
        return;
      }
      btn.addEventListener('click', function () {
        applyFilter(btn.getAttribute('data-key') || '', btn.getAttribute('data-value') || '');
      });
    });
    filterEl.querySelectorAll('.pv-product-filter-search').forEach(function (input) {
      input.addEventListener('input', function () {
        var q = (input.value || '').trim().toLowerCase();
        var row = input.closest('.pv-product-filter-row');
        if (!row) {
          return;
        }
        row.querySelectorAll('.pv-product-filter-link[data-value]').forEach(function (btn) {
          var val = (btn.getAttribute('data-label') || btn.getAttribute('data-value') || '').toLowerCase();
          btn.classList.toggle('d-none', q !== '' && val.indexOf(q) === -1);
        });
      });
    });
  }

  function loadFiltersOnly() {
    if (!filterEl || filterEl.querySelector('.pv-product-filter-board__rows')) {
      return;
    }
    var onFilters = function (res) {
      renderFilters(parseFiltersFromResponse(res));
    };
    var onFail = function () {
      /* 筛选加载失败时保留空态，不打断列表 */
    };
    if (window.PivarkApi && typeof PivarkApi.fetchCatalogFilters === 'function') {
      PivarkApi.fetchCatalogFilters(filterCatalogApi, apiParams(true)).then(onFilters).catch(onFail);
      return;
    }
    fetch(filterCatalogApi + '?' + apiParams(true).toString(), { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(onFilters)
      .catch(onFail);
  }

  function loadCatalog(replace) {
    if (!listEl) {
      return;
    }
    // 首屏/筛选项慢请求进行中时点搜索：不能静默丢弃，否则 URL 已变列表未刷（像「不能用」）
    if (loading) {
      pendingReload = { replace: !!replace };
      return;
    }
    loading = true;
    pendingReload = null;
    var hadCards = cardCount() > 0;
    listEl.setAttribute('aria-busy', 'true');
    if (replace && !hadCards) {
      listEl.innerHTML = renderSkeletonGrid(8);
    }

    fetch(catalogApi + '?' + apiParams(true).toString(), { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!isApiOk(res)) {
          throw new Error((res && res.error && res.error.message) ? res.error.message : ((res && res.msg) ? res.msg : 'catalog'));
        }
        var payload = res.data && !Array.isArray(res.data) ? res.data : { list: res.data || [], filters: [] };
        var rows = payload.list || [];
        var filters = payload.filters || parseFiltersFromResponse(res);
        var meta = res.meta || {};
        nextCursor = meta.next_cursor || '';
        if (replace && !hadCards) {
          return revealCardsProgressively(rows, true).then(function () {
            if (filters.length) {
              renderFilters(filters);
            } else {
              loadFiltersOnly();
            }
            syncEmptyState(cardCount());
            syncLoadMore(!!nextCursor || meta.has_more === 1);
          });
        }
        var html = rows.map(renderCard).join('');
        if (replace) {
          listEl.innerHTML = html;
        } else {
          listEl.insertAdjacentHTML('beforeend', html);
        }
        if (filters.length) {
          renderFilters(filters);
        } else {
          loadFiltersOnly();
        }
        syncEmptyState(cardCount());
        syncLoadMore(!!nextCursor || meta.has_more === 1);
      })
      .catch(function () {
        if (replace && !hadCards) {
          listEl.innerHTML = '';
        }
        syncEmptyState(cardCount());
        syncLoadMore(false);
        loadFiltersOnly();
      })
      .finally(function () {
        loading = false;
        listEl.removeAttribute('aria-busy');
        if (pendingReload) {
          var again = pendingReload;
          pendingReload = null;
          loadCatalog(again.replace);
        }
      });
  }

  function syncSidebarActive() {
    var params = currentParams();
    var tag = params.get('tag') || '';
    document.querySelectorAll('.pv-product-catalog-toolbar [data-pv-catalog-tag], .pv-product-hub [data-pv-catalog-tag]').forEach(function (a) {
      var slug = a.getAttribute('data-pv-catalog-tag') || '';
      var on = slug === tag || (slug === '' && tag === '');
      a.classList.toggle('active', on);
    });
  }

  document.querySelectorAll('.pv-product-catalog-toolbar [data-pv-catalog-tag], .pv-product-hub [data-pv-catalog-tag]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!ajaxList) {
        return;
      }
      e.preventDefault();
      var slug = a.getAttribute('data-pv-catalog-tag') || '';
      var params = currentParams();
      params.delete('page');
      params.delete('cursor');
      Array.from(params.keys()).forEach(function (k) {
        if (k.indexOf('filter_') === 0) {
          params.delete(k);
        }
      });
      if (slug) {
        params.set('tag', slug);
      } else {
        params.delete('tag');
      }
      navigateParams(params);
    });
  });

  if (kwForm) {
    kwForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var params = currentParams();
      var kw = kwInput ? (kwInput.value || '').trim() : '';
      if (kw) {
        params.set('keyword', kw);
      } else {
        params.delete('keyword');
      }
      if (ajaxList) {
        navigateParams(params);
      } else {
        params.delete('page');
        params.delete('cursor');
        var qs = params.toString();
        location.href = location.pathname + (qs ? '?' + qs : '');
      }
    });
  }

  if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', function () {
      if (!nextCursor) {
        return;
      }
      appendMode = true;
      loadCatalog(false);
    });
  }

  if (kwInput) {
    var kw = currentParams().get('keyword');
    if (kw) {
      kwInput.value = kw;
    }
  }

  bindReset();
  syncSidebarActive();

  window.addEventListener('popstate', function () {
    if (!ajaxList) {
      return;
    }
    nextCursor = '';
    appendMode = false;
    if (kwInput) {
      var k = currentParams().get('keyword');
      kwInput.value = k || '';
    }
    loadCatalog(true);
    bindReset();
    syncSidebarActive();
  });

  if (ajaxList) {
    syncEmptyState();
    // 首屏已有 SSR 卡片时不重拉 catalog（避免进页二次 /api/v1/catalog/items）；
    // 仅筛选 / 关键词 / 空列表才 AJAX；翻页·换分类·popstate 仍走 loadCatalog。
    if (cardCount() > 0 && !hasListMutatingQuery()) {
      loadFiltersOnly();
    } else {
      loadCatalog(true);
    }
  } else {
    syncEmptyState();
    loadFiltersOnly();
  }
})();
