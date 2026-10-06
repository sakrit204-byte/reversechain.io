/* ReserveChain motion layer.
 * Smooth scroll (Lenis), parallax, masked headline reveals, scroll-drawn diagrams, timeline fill,
 * pointer tilt + metallic sheen, magnetic CTAs, counters. Everything is skipped for reduced motion
 * and degrades to static, fully readable content without JavaScript.
 */
(function () {
  'use strict';
  var d = document, w = window, root = d.documentElement;
  var reduce = w.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fine = w.matchMedia('(pointer: fine)').matches;
  if (reduce) { root.classList.add('rc-static'); return; }
  root.classList.add('rc-motion');

  var $$ = function (s, c) { return Array.prototype.slice.call((c || d).querySelectorAll(s)); };
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var vh = w.innerHeight;
  w.addEventListener('resize', function () { vh = w.innerHeight; }, { passive: true });

  /* ---------- smooth scroll ---------- */
  var lenis = null;
  if (w.Lenis) {
    lenis = new w.Lenis({ duration: 1.15, easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); }, smoothWheel: true });
    d.addEventListener('click', function (e) {
      var a = e.target.closest('a[href^="#"]');
      if (a && a.getAttribute('href').length > 1) {
        var t = d.querySelector(a.getAttribute('href'));
        if (t) { e.preventDefault(); lenis.scrollTo(t, { offset: -90 }); }
      }
    });
  }

  /* ---------- reading progress ---------- */
  var bar = d.createElement('div'); bar.className = 'rc-progress'; d.body.appendChild(bar);

  /* ---------- masked word reveal for display headings ---------- */
  function splitWords(el) {
    if (el.dataset.split) return; el.dataset.split = '1';
    var idx = 0;
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 3) {
          var parts = n.textContent.split(/(\s+)/), frag = d.createDocumentFragment();
          parts.forEach(function (p) {
            if (!p) return;
            if (/^\s+$/.test(p)) { frag.appendChild(d.createTextNode(p)); return; }
            var o = d.createElement('span'); o.className = 'rc-w';
            var i = d.createElement('span'); i.className = 'rc-w__i'; i.style.setProperty('--i', idx++); i.textContent = p;
            o.appendChild(i); frag.appendChild(o);
          });
          n.parentNode.replaceChild(frag, n);
        } else if (n.nodeType === 1 && !/^(svg|code)$/i.test(n.tagName)) { walk(n); }
      });
    })(el);
  }
  $$('.rc-hero h1, .rc-pagehero__title, .rc-section__head h2, .rc-dap-hero__title, .rc-cta h2').forEach(splitWords);

  /* ---------- reveal observer ---------- */
  var revealables = $$('.rc-section__head, .rc-hero h1, .rc-pagehero__title, .rc-cta, .rc-steps, .rc-diagram, .rc-card, .rc-pcard, .rc-doc, .rc-claims > div, .rc-trustbar li, .rc-por__prog, .rc-coa, .rc-ftable, .rc-kv, .rc-table-wrap, .rc-faq details, .rc-roadmap > div, .rc-chainview, .rc-timeline__step, .rc-element, .rc-ledger, .rc-split > *');
  revealables.forEach(function (el) {
    el.classList.add('rc-rv');
    var sibs = el.parentNode ? Array.prototype.indexOf.call(el.parentNode.children, el) : 0;
    el.style.setProperty('--rv-d', (Math.min(sibs, 8) * 0.06) + 's');
  });
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (en) {
      if (!en.isIntersecting) return;
      en.target.classList.add('rc-in');
      io.unobserve(en.target);
      countUp(en.target);
    });
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0.12 });
  revealables.forEach(function (el) { io.observe(el); });

  /* ---------- counters (numeric figures only, never invented) ---------- */
  function countUp(scope) {
    $$('.rc-por__grid dd, .rc-stats dd', scope.matches && scope.matches('.rc-por__grid dd, .rc-stats dd') ? scope.parentNode : scope).forEach(function (dd) {
      if (dd.dataset.counted) return; dd.dataset.counted = '1';
      var raw = dd.textContent.trim(), m = raw.match(/^([\d,.]+)(.*)$/);
      if (!m) return;
      var target = parseFloat(m[1].replace(/,/g, '')), suffix = m[2], dec = (m[1].split('.')[1] || '').length;
      if (!isFinite(target) || target === 0) return;
      var t0 = null;
      (function step(ts) {
        t0 = t0 || ts; var p = clamp((ts - t0) / 1300, 0, 1), e = 1 - Math.pow(1 - p, 4);
        dd.textContent = (target * e).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
        if (p < 1) requestAnimationFrame(step); else dd.textContent = raw;
      })(performance.now());
    });
  }

  /* ---------- parallax layers ---------- */
  var layers = [];
  function addLayer(el, speed, opts) { if (el) layers.push({ el: el, speed: speed, o: opts || {} }); }
  $$('.rc-hero .rc-element').forEach(function (el, i) { addLayer(el, i ? -0.16 : -0.08); });
  addLayer(d.querySelector('.rc-hero .rc-ledger'), -0.04);
  addLayer(d.querySelector('.rc-hero .rc-hero__grid > div:first-child'), 0.06, { fade: true });
  $$('.rc-pagehero .rc-wrap, .rc-dap-hero .rc-wrap').forEach(function (el) { addLayer(el, 0.22, { fade: true }); });
  $$('.rc-pagehero__grid').forEach(function (el) { addLayer(el, 0.35); });
  $$('.rc-program-visual svg').forEach(function (el) { addLayer(el, -0.1, { rotate: true }); });
  $$('.rc-section__head h2').forEach(function (el) { addLayer(el, -0.025); });

  /* ---------- scroll-drawn chain diagram ---------- */
  var chains = $$('.rc-diagram--chain, .rc-diagram').map(function (fig) {
    var paths = $$('line, path', fig).filter(function (p) { return p.getTotalLength && p.getTotalLength() > 60; });
    paths.forEach(function (p) { var L = p.getTotalLength(); p.style.strokeDasharray = L; p.style.strokeDashoffset = L; p.dataset.len = L; });
    var nodes = $$('svg > g g, svg > g > rect, svg > g > circle', fig);
    return { fig: fig, paths: paths, nodes: nodes };
  });

  /* ---------- timeline fill for process steps ---------- */
  var steps = $$('.rc-steps, .rc-timeline');
  steps.forEach(function (s) { s.classList.add('rc-track'); });

  /* ---------- frame loop ---------- */
  var lastY = -1;
  function frame() {
    var y = w.scrollY || w.pageYOffset;
    if (y !== lastY) {
      lastY = y;
      var max = d.documentElement.scrollHeight - vh;
      bar.style.transform = 'scaleX(' + (max > 0 ? y / max : 0) + ')';
      root.classList.toggle('rc-scrolled', y > 40);

      layers.forEach(function (l) {
        var r = l.el.getBoundingClientRect();
        if (r.bottom < -200 || r.top > vh + 200) return;
        var c = (r.top + r.height / 2 - vh / 2);
        var t = 'translate3d(0,' + (c * l.speed).toFixed(1) + 'px,0)';
        if (l.o.rotate) t += ' rotate(' + (c * 0.008).toFixed(2) + 'deg)';
        l.el.style.transform = t + (l.el.dataset.tilt || '');
        if (l.o.fade) l.el.style.opacity = clamp(1 - Math.max(0, -r.top) / (r.height * 0.9), 0, 1).toFixed(3);
      });

      chains.forEach(function (c) {
        var r = c.fig.getBoundingClientRect();
        var p = clamp((vh * 0.85 - r.top) / (vh * 0.6 + r.height * 0.4), 0, 1);
        c.paths.forEach(function (pa) { pa.style.strokeDashoffset = (pa.dataset.len * (1 - p)).toFixed(1); });
        c.nodes.forEach(function (n, i) { n.classList.toggle('rc-lit', p > (i + 0.5) / (c.nodes.length + 0.5)); });
      });

      steps.forEach(function (s) {
        var r = s.getBoundingClientRect();
        var p = clamp((vh * 0.65 - r.top) / r.height, 0, 1);
        s.style.setProperty('--fill', p.toFixed(3));
        $$(':scope > li, :scope > .rc-timeline__step', s).forEach(function (li) {
          var lr = li.getBoundingClientRect();
          li.classList.toggle('rc-lit', lr.top < vh * 0.65);
        });
      });
    }
    if (lenis) lenis.raf(performance.now());
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  /* ---------- pointer: tilt, sheen, glow, magnetic buttons ---------- */
  if (fine) {
    $$('.rc-element, .rc-dap-card, .rc-card--link, .rc-pcard').forEach(function (el) {
      el.classList.add('rc-tilt');
      el.addEventListener('pointermove', function (e) {
        var r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, yy = (e.clientY - r.top) / r.height - 0.5;
        var amp = el.classList.contains('rc-element') ? 14 : 5;
        el.dataset.tilt = ' perspective(900px) rotateX(' + (-yy * amp).toFixed(2) + 'deg) rotateY(' + (x * amp).toFixed(2) + 'deg)';
        if (!layers.some(function (l) { return l.el === el; })) el.style.transform = el.dataset.tilt;
        el.style.setProperty('--mx', ((x + 0.5) * 100).toFixed(1) + '%');
        el.style.setProperty('--my', ((yy + 0.5) * 100).toFixed(1) + '%');
        lastY = -1;
      });
      el.addEventListener('pointerleave', function () { el.dataset.tilt = ''; if (!layers.some(function (l) { return l.el === el; })) el.style.transform = ''; lastY = -1; });
    });

    $$('.rc-hero, .rc-pagehero, .rc-dap-hero').forEach(function (h) {
      h.addEventListener('pointermove', function (e) {
        var r = h.getBoundingClientRect();
        h.style.setProperty('--gx', (e.clientX - r.left) + 'px');
        h.style.setProperty('--gy', (e.clientY - r.top) + 'px');
      });
    });

    $$('.rc-btn--primary, .rc-btn--cream').forEach(function (b) {
      b.addEventListener('pointermove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * 0.18).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * 0.28).toFixed(1) + 'px)';
      });
      b.addEventListener('pointerleave', function () { b.style.transform = ''; });
    });
  }
})();
