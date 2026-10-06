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
      burger.setAttribute('aria-expanded', String(open)); nav.classList.toggle('is-open', open); d.body.classList.toggle('nav-open', open);
    };
    burger.addEventListener('click', function () { setNav(burger.getAttribute('aria-expanded') !== 'true'); });
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape') setNav(false); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) setNav(false); });
  }

  // Reveal on scroll (stagger siblings).
  var els = d.querySelectorAll('[data-reveal]');
  if ('IntersectionObserver' in window && els.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach(function (el) {
      var sib = el.parentNode ? Array.prototype.indexOf.call(el.parentNode.querySelectorAll(':scope > [data-reveal]'), el) : 0;
      if (sib > 0) el.style.setProperty('--d', Math.min(sib, 6) * 0.07 + 's');
      io.observe(el);
    });
  } else { els.forEach(function (el) { el.classList.add('is-in'); }); }

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
    var set = function (k, v) { var el = ledger.querySelector('[data-k="' + k + '"]'); if (el) { el.textContent = v; el.title = v; } };
    Promise.all([
      fetch(api + '/audit/head').then(function (r) { return r.json(); }),
      fetch(api + '/registry/stats').then(function (r) { return r.json(); })
    ]).then(function (res) {
      var h = res[0], s = res[1], e = s.entities || {};
      set('seq', '#' + h.seq + (h.last_verified && h.last_verified.ok ? ' · intact' : ''));
      set('head', h.chain_head);
      set('triggers', h.db_triggers ? 'UPDATE/DELETE blocked' : 'not active');
      var assets = ['rc_lot', 'rc_batch', 'rc_container', 'rc_coil'].reduce(function (n, k) { return n + ((e[k] && e[k].published) || 0); }, 0);
      set('passports', assets + ' · pending verification');
      set('docs', ((e.rc_document && e.rc_document.published) || 0) + ' SHA-256 fingerprinted');
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
  document.addEventListener('click', function (e) { if (!e.target.closest('.rc-nav__list')) items.forEach(function (o) { o.classList.remove('is-open'); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') items.forEach(function (o) { o.classList.remove('is-open'); }); });
})();
