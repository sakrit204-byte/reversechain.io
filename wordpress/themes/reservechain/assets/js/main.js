/* ReserveChain theme interactions — dependency-free, progressive enhancement. */
(function () {
  'use strict';
  var d = document;
  d.documentElement.classList.add('js');

  // Prelaunch notice toggle.
  var nt = d.querySelector('.rc-notice__toggle'), nf = d.getElementById('rc-notice-full');
  if (nt && nf) nt.addEventListener('click', function () {
    var open = nt.getAttribute('aria-expanded') === 'true';
    nt.setAttribute('aria-expanded', String(!open)); nf.hidden = open;
  });

  // Sticky header state.
  var header = d.querySelector('[data-rc-header]');
  var onScroll = function () { if (header) header.classList.toggle('is-scrolled', window.scrollY > 8); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  // Mobile navigation.
  var burger = d.querySelector('.rc-burger'), nav = d.getElementById('rc-nav');
  if (burger && nav) {
    var setNav = function (open) {
      var was = burger.getAttribute('aria-expanded') === 'true';
      burger.setAttribute('aria-expanded', String(open)); nav.classList.toggle('is-open', open); d.body.classList.toggle('nav-open', open);
      /* QA a11y: while the off-canvas menu is open, content behind it is inert; focus moves in and back out. */
      Array.prototype.forEach.call(d.body.children, function (el) {
        if (el.tagName === 'SCRIPT' || el.contains(nav)) return;
        if (open) { el.setAttribute('inert', ''); el.setAttribute('data-rc-inert', ''); }
        else if (el.hasAttribute('data-rc-inert')) { el.removeAttribute('inert'); el.removeAttribute('data-rc-inert'); }
      });
      if (open && !was) { var f = nav.querySelector('a, button'); if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 50); }
      if (!open && was && nav.contains(d.activeElement)) burger.focus();
    };
    burger.addEventListener('click', function () { setNav(burger.getAttribute('aria-expanded') !== 'true'); });
    d.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || burger.getAttribute('aria-expanded') !== 'true') return;
      if (e.target.closest && e.target.closest('.rc-nav__list > li.is-open')) return; // first Escape closes an open accordion
      var inNav = nav.contains(d.activeElement); setNav(false); if (inNav || d.activeElement === d.body) burger.focus();
    });
    window.addEventListener('resize', function () { if (burger.getAttribute('aria-expanded') === 'true' && !window.matchMedia('(max-width: 1120px)').matches) setNav(false); });
    // QA fix: parent items toggle their accordion (preventDefault in the mega-menu handler) and must not close the menu.
    nav.addEventListener('click', function (e) { if (e.target.closest('a') && !e.defaultPrevented) setNav(false); });
  }

  // Scroll reveals live in motion.js; legacy data-reveal content is shown immediately.
  d.querySelectorAll('[data-reveal]').forEach(function (el) { el.classList.add('is-in'); });

  // Passport: print + active section in TOC.
  d.addEventListener('click', function (e) { if (e.target.closest('[data-print]')) window.print(); });
  var toc = d.querySelectorAll('.rc-dap__toc a');
  if (toc.length && 'IntersectionObserver' in window) {
    var map = {}; toc.forEach(function (a) { map[a.getAttribute('href').slice(1)] = a; });
    var so = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && map[en.target.id]) { toc.forEach(function (a) { a.classList.remove('is-active'); }); map[en.target.id].classList.add('is-active'); }
      });
    }, { rootMargin: '-30% 0px -60% 0px' });
    Object.keys(map).forEach(function (id) { var s = d.getElementById(id); if (s) so.observe(s); });
  }

  // Live integrity ledger (homepage hero): reads the public API, proving the registry is real.
  var ledger = d.querySelector('[data-rc-ledger]');
  if (ledger && window.fetch) {
    var api = (window.RC && window.RC.api) || (ledger.getAttribute('data-api') || '/wp-json/rc/v1');
    var T = (window.RC && window.RC.i18n) || {};
    var set = function (k, v) { var el = ledger.querySelector('[data-k="' + k + '"]'); if (el) { el.textContent = v; el.title = v; } };
    Promise.all([
      fetch(api + '/audit/head').then(function (r) { return r.json(); }),
      fetch(api + '/registry/stats').then(function (r) { return r.json(); })
    ]).then(function (res) {
      var h = res[0], s = res[1], e = s.entities || {};
      set('seq', '#' + h.seq + (h.last_verified && h.last_verified.ok ? ' · ' + (T.intact || 'intact') : ''));
      set('head', h.chain_head);
      set('triggers', h.db_triggers ? (T.blocked || 'UPDATE/DELETE blocked') : (T.notActive || 'not active'));
      var assets = ['rc_lot', 'rc_batch', 'rc_container', 'rc_coil'].reduce(function (n, k) { return n + ((e[k] && e[k].published) || 0); }, 0);
      set('passports', assets + ' · ' + (T.pendingVer || 'pending verification'));
      set('docs', ((e.rc_document && e.rc_document.published) || 0) + ' ' + (T.fingerprinted || 'SHA-256 fingerprinted'));
      set('verified', String(s.verified_records || 0));
      ledger.classList.add('is-live');
    }).catch(function () { ledger.classList.add('is-offline'); });
  }
})();

/* Mega menu: click/keyboard toggles (desktop hover handled in CSS; mobile uses accordions). */
(function () {
  var items = document.querySelectorAll('.rc-nav__list > li.menu-item-has-children');
  items.forEach(function (li) {
    var a = li.querySelector(':scope > a');
    a.setAttribute('aria-haspopup', 'true'); a.setAttribute('aria-expanded', 'false');
    a.addEventListener('click', function (e) {
      if (window.matchMedia('(max-width: 1120px)').matches || !li.classList.contains('is-open')) {
        e.preventDefault();
        var open = !li.classList.contains('is-open');
        items.forEach(function (o) { o.classList.remove('is-open'); o.querySelector(':scope > a').setAttribute('aria-expanded', 'false'); });
        li.classList.toggle('is-open', open); a.setAttribute('aria-expanded', String(open));
      }
    });
  });
  var closeAll = function () { items.forEach(function (o) { o.classList.remove('is-open'); var t = o.querySelector(':scope > a'); if (t) t.setAttribute('aria-expanded', 'false'); }); };
  document.addEventListener('click', function (e) { if (!e.target.closest('.rc-nav__list')) closeAll(); });
  /* QA a11y: Escape closes the panel (even when opened by focus/hover) and returns focus to its trigger. */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var li = e.target.closest && e.target.closest('.rc-nav__list > li.menu-item-has-children');
    closeAll();
    if (li && li.contains(document.activeElement)) { li.classList.add('rc-esc'); var a = li.querySelector(':scope > a'); if (a && a !== document.activeElement) a.focus(); }
  });
  items.forEach(function (li) {
    li.addEventListener('focusout', function (e) { if (!li.contains(e.relatedTarget)) li.classList.remove('rc-esc'); });
    li.addEventListener('mouseleave', function () { li.classList.remove('rc-esc'); });
    li.querySelector(':scope > a').addEventListener('click', function () { li.classList.remove('rc-esc'); });
  });
})();

/* QA a11y: horizontally scrollable tables/diagrams must be reachable by keyboard (WCAG 2.1.1). */
(function () {
  var d = document;
  var mark = function () {
    d.querySelectorAll('.rc-table-wrap, .rc-diagram--chain').forEach(function (el) {
      var scrolls = el.scrollWidth > el.clientWidth + 1;
      if (scrolls && !el.hasAttribute('tabindex')) {
        el.setAttribute('tabindex', '0'); el.setAttribute('data-rc-scroll', '');
        if (el.tagName !== 'FIGURE' && !el.hasAttribute('role')) { el.setAttribute('role', 'region'); el.setAttribute('data-rc-scroll', 'r'); }
        if (!el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby')) {
          el.setAttribute('data-rc-scroll', el.getAttribute('data-rc-scroll') + 'l');
          var cap = el.querySelector('caption, figcaption'), sec = el.closest('section'), h = sec && sec.querySelector('h2, h3');
          var label = (cap && cap.textContent) || (h && h.textContent) || '';
          el.setAttribute('aria-label', label.replace(/\s+/g, ' ').trim() || 'Scrollable content');
        }
      } else if (!scrolls && el.hasAttribute('data-rc-scroll')) {
        var f = el.getAttribute('data-rc-scroll');
        el.removeAttribute('tabindex'); if (f.indexOf('r') > -1) el.removeAttribute('role'); if (f.indexOf('l') > -1) el.removeAttribute('aria-label'); el.removeAttribute('data-rc-scroll');
      }
    });
  };
  mark();
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(mark, 150); });
  window.addEventListener('load', mark);
})();
