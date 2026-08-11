/**
 * ==========================================================================
 * 演示站 · 前台通用脚本（demo.js）
 * ==========================================================================
 * 1. 顶栏滚动态（.scrolled / 首页 .pv-nav-scrolled）
 * 2. 首页数据带数字滚动（data-pv-count）
 * 3. 关于我们 · 滚动显现 / 时间轴动效 / 页内导航
 * 4. 内页滚动显现（.pv-reveal → .is-visible）
 * ==========================================================================
 */
(function () {
  'use strict';

  var header = document.querySelector('.pv-portal-header');
  var body = document.body;
  var isHomeCinema = body.classList.contains('pv-home-cinema');

  /* ── 1. 顶栏滚动态 ── */
  function onScrollHeader() {
    var scrollY = window.scrollY;
    if (header) {
      header.querySelector('.pv-portal-navbar')?.classList.toggle('scrolled', scrollY > 24);
    }
    if (isHomeCinema) {
      body.classList.toggle('pv-nav-scrolled', scrollY > 72);
    }
  }

  if (header || isHomeCinema) {
    onScrollHeader();
    window.addEventListener('scroll', onScrollHeader, { passive: true });
  }

  /* ── 1b. 手机导航：右侧抽屉 + 多级钻取；桌面悬停由 CSS 负责 ── */
  var portalNavDrawer = document.getElementById('pvPortalNavDrawer');
  var navSheet = document.querySelector('[data-pv-nav-sheet]');
  var navSubList = navSheet ? navSheet.querySelector('[data-pv-nav-sublist]') : null;
  var navSubTitle = navSheet ? navSheet.querySelector('[data-pv-nav-sub-title]') : null;
  var navBackBtn = navSheet ? navSheet.querySelector('[data-pv-nav-back]') : null;
  var mobileNavMq = window.matchMedia('(max-width: 991.98px)');
  var navMenuStack = [];

  function closeHeaderSearch() {
    var searchRoot = document.querySelector('[data-pv-header-search]');
    if (!searchRoot) {
      return;
    }
    searchRoot.classList.remove('is-open');
    var trigger = searchRoot.querySelector('.pv-portal-header-search__trigger');
    var form = searchRoot.querySelector('.pv-portal-header-search__form');
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
    }
    if (form) {
      form.setAttribute('aria-hidden', 'true');
    }
  }

  function resetNavDrill() {
    if (!navSheet) {
      return;
    }
    navMenuStack = [];
    navSheet.classList.remove('is-drill');
    if (navSubList) {
      navSubList.innerHTML = '';
    }
    if (navSubTitle) {
      navSubTitle.textContent = '栏目';
    }
  }

  function renderNavDrillPanel(title, menuEl, parentUrl) {
    if (!navSheet || !navSubList || !navSubTitle || !menuEl) {
      return;
    }
    navSubTitle.textContent = title || '栏目';
    navSubList.innerHTML = '';

    if (parentUrl && parentUrl !== '#') {
      var enterLi = document.createElement('li');
      var enterLink = document.createElement('a');
      enterLink.href = parentUrl;
      enterLink.className = 'pv-portal-nav-sheet__enter';
      enterLink.textContent = '进入「' + (title || '栏目') + '」';
      enterLi.appendChild(enterLink);
      navSubList.appendChild(enterLi);
    }

    menuEl.querySelectorAll(':scope > li').forEach(function (li) {
      if (li.querySelector(':scope > hr')) {
        return;
      }
      var a = li.querySelector(':scope > a');
      if (!a) {
        return;
      }
      var nested = li.querySelector(':scope > .dropdown-menu');
      var item = document.createElement('li');
      var link = document.createElement('a');
      link.href = a.getAttribute('href') || '#';
      link.textContent = (a.textContent || '').trim();
      if (a.classList.contains('active')) {
        link.classList.add('active');
      }
      var ext = a.getAttribute('target');
      if (ext) {
        link.setAttribute('target', ext);
      }
      if (nested) {
        item.classList.add('has-children');
        link.addEventListener('click', function (e) {
          e.preventDefault();
          pushNavDrill(
            a.getAttribute('data-pv-nav-drill') || (a.textContent || '').trim(),
            nested,
            a.getAttribute('href') || '#'
          );
        });
      }
      item.appendChild(link);
      navSubList.appendChild(item);
    });
    navSheet.classList.add('is-drill');
  }

  function pushNavDrill(title, menuEl, parentUrl) {
    if (!menuEl) {
      return;
    }
    navMenuStack.push({ title: title, menu: menuEl, parentUrl: parentUrl });
    renderNavDrillPanel(title, menuEl, parentUrl);
  }

  if (portalNavDrawer) {
    portalNavDrawer.addEventListener('show.bs.offcanvas', function () {
      body.classList.add('pv-nav-open');
      closeHeaderSearch();
      resetNavDrill();
    });
    portalNavDrawer.addEventListener('hidden.bs.offcanvas', function () {
      body.classList.remove('pv-nav-open');
      resetNavDrill();
    });
  }

  if (navBackBtn) {
    navBackBtn.addEventListener('click', function (e) {
      e.preventDefault();
      if (navMenuStack.length <= 1) {
        resetNavDrill();
        return;
      }
      navMenuStack.pop();
      var cur = navMenuStack[navMenuStack.length - 1];
      renderNavDrillPanel(cur.title, cur.menu, cur.parentUrl);
    });
  }

  if (navSheet) {
    navSheet.querySelectorAll('.nav-item.dropdown > a.dropdown-toggle').forEach(function (toggle) {
      toggle.addEventListener('click', function (e) {
        if (!mobileNavMq.matches) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        var item = toggle.closest('.nav-item.dropdown');
        var menu = item ? item.querySelector(':scope > .dropdown-menu') : null;
        if (!menu) {
          return;
        }
        navMenuStack = [];
        pushNavDrill(
          toggle.getAttribute('data-pv-nav-drill') || (toggle.textContent || '').trim(),
          menu,
          toggle.getAttribute('href') || '#'
        );
      }, true);
    });
  }

  (function bindDesktopHoverNav() {
    var desktopRoot = document.querySelector('.pv-portal-nav-desktop');
    if (!desktopRoot || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
      return;
    }
    desktopRoot.querySelectorAll('.pv-nav-hover').forEach(function (item) {
      var hideTimer = null;
      item.addEventListener('mouseenter', function () {
        if (hideTimer) {
          clearTimeout(hideTimer);
          hideTimer = null;
        }
        item.classList.add('is-hover');
      });
      item.addEventListener('mouseleave', function () {
        hideTimer = window.setTimeout(function () {
          item.classList.remove('is-hover');
        }, 120);
      });
    });
  })();

  /* ── 1c. 首页轮播：指示器索引修好后仍显式初始化，避免 data-api 漏启 ── */
  (function initHomeCarousel() {
    var el = document.getElementById('pvHomeCarousel');
    if (!el || !window.bootstrap || !bootstrap.Carousel) {
      return;
    }
    var pause = el.getAttribute('data-bs-pause') === 'false' ? false : 'hover';
    var interval = parseInt(el.getAttribute('data-bs-interval') || '5000', 10) || 5000;
    /* data-api 可能已建实例且忽略 pause=false；dispose 后按属性重建 */
    var existing = bootstrap.Carousel.getInstance(el);
    if (existing && typeof existing.dispose === 'function') {
      existing.dispose();
    }
    var c = bootstrap.Carousel.getOrCreateInstance(el, {
      interval: interval,
      ride: 'carousel',
      wrap: true,
      pause: pause,
    });
    if (typeof c.cycle === 'function') {
      c.cycle();
    }
  })();

  /* ── 2. 首页数据带数字滚动 ── */
  function formatStatValue(value, el) {
    var suffix = el.getAttribute('data-pv-suffix') || '';
    var n = Math.round(value);
    if (el.getAttribute('data-pv-format') === 'comma') {
      return n.toLocaleString('zh-CN') + suffix;
    }
    return String(n) + suffix;
  }

  function runStatCounter(el) {
    if (el.dataset.pvCounted === '1') {
      return;
    }
    el.dataset.pvCounted = '1';
    var target = parseFloat(el.getAttribute('data-pv-count') || '0');
    if (!target || target <= 0) {
      return;
    }
    var start = performance.now();
    var duration = 1600;

    function tick(now) {
      var p = Math.min((now - start) / duration, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = formatStatValue(target * eased, el);
      if (p < 1) {
        requestAnimationFrame(tick);
      }
    }

    requestAnimationFrame(tick);
  }

  function initStatCounters() {
    var counters = document.querySelectorAll('[data-pv-count]');
    if (!counters.length) {
      return;
    }
    if (!('IntersectionObserver' in window)) {
      counters.forEach(runStatCounter);
      return;
    }
    var statObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            runStatCounter(entry.target);
            statObserver.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.35 }
    );
    counters.forEach(function (el) {
      statObserver.observe(el);
    });
  }

  initStatCounters();

  /* ── 3. 关于我们 · 横向时间轴 ── */
  function initAboutTimeline() {
    document.querySelectorAll('[data-pv-about-timeline]').forEach(function (rail) {
      var viewport = rail.querySelector('.pv-about-timeline__viewport');
      var track = rail.querySelector('.pv-about-timeline__track');
      var prevBtn = rail.querySelector('.pv-about-timeline__btn--prev');
      var nextBtn = rail.querySelector('.pv-about-timeline__btn--next');
      if (!viewport || !track) {
        return;
      }

      var nodes = track.querySelectorAll('.pv-about-timeline__node');
      if (nodes.length < 2) {
        if (prevBtn) {
          prevBtn.disabled = true;
        }
        if (nextBtn) {
          nextBtn.disabled = true;
        }
        return;
      }

      function scrollStep(direction) {
        var nodeWidth = nodes[0].offsetWidth || viewport.clientWidth * 0.75;
        viewport.scrollBy({ left: direction * nodeWidth, behavior: 'smooth' });
      }

      function syncNav() {
        var maxScroll = viewport.scrollWidth - viewport.clientWidth;
        var sl = viewport.scrollLeft;
        if (prevBtn) {
          prevBtn.disabled = sl <= 4;
        }
        if (nextBtn) {
          nextBtn.disabled = sl >= maxScroll - 4;
        }
      }

      if (prevBtn) {
        prevBtn.addEventListener('click', function () {
          scrollStep(-1);
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener('click', function () {
          scrollStep(1);
        });
      }

      viewport.addEventListener('scroll', syncNav, { passive: true });
      window.addEventListener('resize', syncNav);
      syncNav();
    });
  }

  function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* ── 4. 关于我们 · 滚动动效 ── */
  function initAboutPageMotion() {
    if (!document.body.classList.contains('pv-about-page')) {
      return;
    }

    var reduced = prefersReducedMotion();
    var motionClass = 'pv-about-motion';

    function staggerChildren(parent, childSel, step) {
      if (!parent) {
        return;
      }
      parent.querySelectorAll(childSel).forEach(function (el, index) {
        el.style.setProperty('--pv-reveal-delay', String(index * step) + 's');
      });
    }

    var motionTargets = [];
    document.querySelectorAll('.pv-about-hero__copy > *').forEach(function (el, i) {
      el.classList.add(motionClass);
      el.style.setProperty('--pv-reveal-delay', String(i * 0.08) + 's');
      motionTargets.push(el);
    });
    var heroMedia = document.querySelector('.pv-about-hero__media');
    var heroImg = document.querySelector('.pv-about-hero-img');
    if (heroMedia) {
      heroMedia.classList.add(motionClass, motionClass + '--float');
      motionTargets.push(heroMedia);
    } else if (heroImg) {
      heroImg.classList.add(motionClass);
      motionTargets.push(heroImg);
    }

    document.querySelectorAll('.pv-about-stat').forEach(function (el, i) {
      el.classList.add(motionClass);
      el.style.setProperty('--pv-reveal-delay', String(i * 0.07) + 's');
      motionTargets.push(el);
    });

    var introMedia = document.querySelector('.pv-about-intro__media');
    var introBody = document.querySelector('.pv-about-intro__body');
    if (introMedia) {
      introMedia.classList.add(motionClass, motionClass + '--left');
      motionTargets.push(introMedia);
    }
    if (introBody) {
      introBody.classList.add(motionClass, motionClass + '--right');
      motionTargets.push(introBody);
    }

    document.querySelectorAll('.pv-about-vision-grid').forEach(function (grid) {
      staggerChildren(grid, '.pv-about-vision-card', 0.1);
      grid.querySelectorAll('.pv-about-vision-card').forEach(function (card) {
        card.classList.add(motionClass);
        motionTargets.push(card);
      });
    });

    document.querySelectorAll('.pv-about-cap-grid .pv-about-cap-card').forEach(function (card, i) {
      card.classList.add(motionClass);
      card.style.setProperty('--pv-reveal-delay', String((i % 2) * 0.06 + Math.floor(i / 2) * 0.1) + 's');
      motionTargets.push(card);
    });

    var cultureMedia = document.querySelector('.pv-about-culture__media');
    var cultureText = document.querySelector('.pv-about-culture__text');
    if (cultureMedia) {
      cultureMedia.classList.add(motionClass, motionClass + '--left');
      motionTargets.push(cultureMedia);
    }
    if (cultureText) {
      cultureText.classList.add(motionClass, motionClass + '--right');
      motionTargets.push(cultureText);
    }

    document.querySelectorAll('.pv-about-section__title--center, .pv-about-culture > .pv-about-section__title').forEach(function (title) {
      title.classList.add(motionClass);
      motionTargets.push(title);
    });

    var cta = document.querySelector('.pv-about-cta');
    if (cta) {
      cta.classList.add(motionClass);
      motionTargets.push(cta);
    }

    motionTargets.forEach(function (el) {
      if (!el.classList.contains(motionClass)) {
        el.classList.add(motionClass);
      }
    });

    if (reduced) {
      motionTargets.forEach(function (el) {
        el.classList.add('is-visible');
      });
      document.querySelectorAll('[data-pv-about-timeline]').forEach(function (rail) {
        rail.classList.add('is-inview');
        rail.querySelectorAll('.pv-about-timeline__node').forEach(function (node) {
          node.classList.add('is-visible');
        });
      });
      return;
    }

    if (!('IntersectionObserver' in window)) {
      motionTargets.forEach(function (el) {
        el.classList.add('is-visible');
      });
      return;
    }

    var motionObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            motionObserver.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.14, rootMargin: '0px 0px -6% 0px' }
    );
    motionTargets.forEach(function (el) {
      motionObserver.observe(el);
    });

    document.querySelectorAll('.pv-about-hero__copy > *, .pv-about-hero__media, .pv-about-hero-img').forEach(function (el) {
      el.classList.add('is-visible');
    });

    document.querySelectorAll('[data-pv-about-timeline]').forEach(function (rail) {
      var nodes = rail.querySelectorAll('.pv-about-timeline__node');
      nodes.forEach(function (node, index) {
        node.style.setProperty('--pv-reveal-delay', String(index * 0.07) + 's');
      });

      var railObserver = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
              return;
            }
            rail.classList.add('is-inview');
            nodes.forEach(function (node, index) {
              window.setTimeout(function () {
                node.classList.add('is-visible');
              }, index * 70);
            });
            railObserver.unobserve(rail);
          });
        },
        { threshold: 0.2 }
      );
      railObserver.observe(rail);

      var viewport = rail.querySelector('.pv-about-timeline__viewport');
      if (viewport && nodes.length > 2 && !rail.dataset.pvTimelineHint) {
        rail.dataset.pvTimelineHint = '1';
        window.setTimeout(function () {
          if (viewport.scrollLeft > 2) {
            return;
          }
          viewport.scrollTo({ left: 56, behavior: 'smooth' });
          window.setTimeout(function () {
            viewport.scrollTo({ left: 0, behavior: 'smooth' });
          }, 700);
        }, 1400);
      }
    });

    var floatNav = document.querySelector('.pv-about-float-nav');
    if (floatNav) {
      var navLinks = floatNav.querySelectorAll('a[href^="#"]');
      var spySections = [];
      navLinks.forEach(function (link) {
        var id = (link.getAttribute('href') || '').replace(/^#/, '');
        var section = id ? document.getElementById(id) : null;
        if (section) {
          spySections.push({ link: link, section: section });
        }
        link.addEventListener('click', function (event) {
          if (!section) {
            return;
          }
          event.preventDefault();
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
      if (spySections.length && 'IntersectionObserver' in window) {
        var activeId = '';
        var spyObserver = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                activeId = entry.target.id;
              }
            });
            if (!activeId) {
              return;
            }
            navLinks.forEach(function (link) {
              var href = (link.getAttribute('href') || '').replace(/^#/, '');
              link.classList.toggle('is-active', href === activeId);
            });
          },
          { rootMargin: '-35% 0px -55% 0px', threshold: 0 }
        );
        spySections.forEach(function (item) {
          spyObserver.observe(item.section);
        });
      }
    }

    var hero = document.querySelector('.pv-about-hero');
    var heroImage = document.querySelector('.pv-about-hero-img');
    if (hero && heroImage) {
      var ticking = false;
      window.addEventListener(
        'scroll',
        function () {
          if (ticking) {
            return;
          }
          ticking = true;
          requestAnimationFrame(function () {
            ticking = false;
            var rect = hero.getBoundingClientRect();
            if (rect.bottom < 0 || rect.top > window.innerHeight) {
              return;
            }
            var progress = Math.min(Math.max(1 - rect.top / Math.max(rect.height, 1), 0), 1);
            heroImage.style.setProperty('--pv-hero-parallax', String(progress * 14) + 'px');
          });
        },
        { passive: true }
      );
    }
  }

  initAboutTimeline();
  initAboutPageMotion();

  /* ── 5. 内页滚动显现（列表卡 / 产品卡 / 标签 chip / 侧栏块） ── */
  if (!('IntersectionObserver' in window)) {
    return;
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  var revealSelector = [
    '.pv-list-news-item',
    '.pv-media-card',
    '.pv-list-download-item',
    '.article-card',
    '.pv-product-card',
    '.tag-item',
    '.pv-side-list--thumb > li',
    '.pv-side-list--text > li',
    '.pv-side-block',
    '.pv-home-product-card',
    '.pv-home-video-card',
    '.pv-home-stats-band .col',
    '.pv-product-strength',
    '.pv-product-app-card',
    '.pv-contact-channel',
    '.pv-contact-intro .lead',
    '.pv-contact-form-panel',
    '.pv-contact-services',
    '.pv-contact-info-panel--accent',
    '.pv-contact-info-card',
    '.pv-contact-map-section',
  ].join(', ');

  var revealObserver = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
  );

  document.querySelectorAll(revealSelector).forEach(function (el, index) {
    if (el.classList.contains('pv-reveal')) {
      return;
    }
    el.classList.add('pv-reveal');
    if (index < 18) {
      el.style.setProperty('--pv-reveal-delay', String((index % 6) * 0.05) + 's');
    }
    revealObserver.observe(el);
  });
})();
