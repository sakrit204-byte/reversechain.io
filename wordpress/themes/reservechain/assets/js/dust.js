/* ReserveChain — metal dust engine (homepage).
 *
 * Hero: two serpentine dust "dragons" — copper and nickel-silver — coil in a twin spiral. Scrolling draws
 * them inward until they interlock and merge between the Cu and Ni tiles, where a gold core blooms.
 * Every grain is a damped spring toward its slot on the dragon's body, perturbed by curl noise and by the
 * cursor, so the motion has inertia and settles naturally.
 *
 * Ambient: sparse drifting motes, a fine cursor trail (inherits pointer velocity, gravity + drag) and a
 * soft radial burst on click. Subtle by design. Disabled for prefers-reduced-motion.
 */
(function () {
  'use strict';
  var d = document, w = window;
  if (w.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var hero = d.querySelector('.rc-hero');
  if (!hero) return;

  var DPR = Math.min(w.devicePixelRatio || 1, 1.6);
  var mobile = w.matchMedia('(max-width: 760px)').matches || !w.matchMedia('(pointer: fine)').matches;
  var TAU = Math.PI * 2;
  var COPPER = [[255, 178, 120], [214, 122, 62], [240, 150, 92]];
  var SILVER = [[236, 244, 250], [176, 196, 210], [208, 222, 232]];
  var GOLD = [255, 222, 150];

  /* ---------- glow sprites (pre-rendered, additive) ---------- */
  function sprite(rgb, size) {
    var c = d.createElement('canvas'); c.width = c.height = size;
    var g = c.getContext('2d'), r = size / 2, grd = g.createRadialGradient(r, r, 0, r, r, r);
    grd.addColorStop(0, 'rgba(' + rgb + ',1)');
    grd.addColorStop(0.25, 'rgba(' + rgb + ',.55)');
    grd.addColorStop(1, 'rgba(' + rgb + ',0)');
    g.fillStyle = grd; g.fillRect(0, 0, size, size);
    return c;
  }
  var SPR = { cu: COPPER.map(function (c) { return sprite(c, 32); }), ni: SILVER.map(function (c) { return sprite(c, 32); }), gold: sprite(GOLD, 64) };

  /* ---------- smooth value noise → curl field ---------- */
  var perm = new Uint8Array(512);
  (function () { var p = []; for (var i = 0; i < 256; i++) p[i] = i; for (i = 255; i > 0; i--) { var j = (Math.random() * (i + 1)) | 0, t = p[i]; p[i] = p[j]; p[j] = t; } for (i = 0; i < 512; i++) perm[i] = p[i & 255]; })();
  function fade(t) { return t * t * (3 - 2 * t); }
  function hash(x, y) { return perm[(perm[x & 255] + y) & 511] / 255; }
  function noise(x, y) {
    var xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi, u = fade(xf), v = fade(yf);
    var a = hash(xi, yi), b = hash(xi + 1, yi), c = hash(xi, yi + 1), e = hash(xi + 1, yi + 1);
    return a + (b - a) * u + (c - a) * v + (a - b - c + e) * u * v;
  }
  function curl(x, y, t) {
    var s = 0.0042, eps = 0.6, n1 = noise(x * s, (y + eps) * s + t), n2 = noise(x * s, (y - eps) * s + t);
    var n3 = noise((x + eps) * s + t, y * s), n4 = noise((x - eps) * s + t, y * s);
    return [(n1 - n2) / (2 * eps), -(n3 - n4) / (2 * eps)];
  }

  /* =====================================================================
     HERO VORTEX
     ===================================================================== */
  var hc = d.createElement('canvas');
  hc.className = 'rc-dust-hero'; hc.setAttribute('aria-hidden', 'true');
  hero.insertBefore(hc, hero.firstChild);
  var hx = hc.getContext('2d');
  var HW = 0, HH = 0;
  function sizeHero() {
    HW = hero.clientWidth; HH = hero.clientHeight;
    hc.width = HW * DPR; hc.height = HH * DPR; hc.style.width = HW + 'px'; hc.style.height = HH + 'px';
    hx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }
  sizeHero();

  var N = mobile ? 650 : 1500;
  var grains = [];
  for (var i = 0; i < N; i++) {
    var side = i % 2;                       // 0 = copper dragon, 1 = nickel dragon
    var u = Math.pow(Math.random(), 0.92);  // position along body: 0 tail … 1 head (denser near head)
    grains.push({
      side: side, u: u,
      lat: (Math.random() + Math.random() + Math.random() - 1.5) / 1.5, // gaussian-ish lateral offset
      ring: Math.random(),                  // scale-band phase (dragon scales)
      x: Math.random() * HW, y: Math.random() * HH, vx: 0, vy: 0,
      k: 0.018 + Math.random() * 0.022,     // spring stiffness
      size: 0.6 + Math.random() * (u > 0.88 ? 2.4 : 1.4),
      tone: (Math.random() * 3) | 0,
      a: 0.35 + Math.random() * 0.6
    });
  }
  var sparks = [];
  var center = { x: HW * 0.72, y: HH * 0.45 };
  var mouse = { x: -9999, y: -9999, vx: 0, vy: 0, inHero: false };
  var prog = 0, progT = 0, merged = false, heroVisible = true, t0 = performance.now();

  function updateCenter() {
    var tiles = hero.querySelectorAll('.rc-element');
    var hr = hero.getBoundingClientRect();
    if (tiles.length === 2) {
      var a = tiles[0].getBoundingClientRect(), b = tiles[1].getBoundingClientRect();
      center.x = (a.right + b.left) / 2 - hr.left;
      center.y = (a.top + a.bottom + b.top + b.bottom) / 4 - hr.top;
    } else { center.x = HW * 0.72; center.y = HH * 0.45; }
  }

  function heroFrame(now) {
    var t = (now - t0) / 1000;
    var hr = hero.getBoundingClientRect();
    heroVisible = hr.bottom > 0 && hr.top < w.innerHeight;
    if (!heroVisible) return;
    updateCenter();

    // Scroll progress → merge. Eased toward target for inertia.
    progT = Math.min(1, Math.max(0, -hr.top / (hr.height * 0.36)));
    prog += (progT - prog) * 0.08;
    var p = prog, pe = p * p * (3 - 2 * p);

    var Rmax = Math.min(HW, HH) * (mobile ? 0.62 : 0.5);
    var turns = 1.08;
    var spin = t * 0.22 + pe * 3.2;           // whole vortex rotates; scrolling winds it tighter

    hx.globalCompositeOperation = 'source-over';
    hx.clearRect(0, 0, HW, HH);
    hx.globalCompositeOperation = 'lighter';

    for (var j = 0; j < grains.length; j++) {
      var g = grains[j];
      var dir = g.side ? 1 : -1;
      // Dragon spine: a spiral arm whose radius shrinks toward the head and with scroll progress.
      var along = 1 - g.u;                                   // 0 at head … 1 at tail
      var radius = Rmax * (0.16 + along * 0.84) * (1 - pe * 0.95) + 4;
      var theta = spin + g.side * Math.PI + along * turns * TAU * dir * 0.5;
      // Serpentine undulation travelling along the body (the "dragon" motion).
      var wave = Math.sin(along * 9 - t * 2.1 + g.side * 1.7) * (22 + along * 64) * (1 - pe * 0.82);
      // Body thickness: tapers to the tail, swells behind the head; scale bands modulate it.
      var thick = (4 + 42 * Math.pow(Math.sin(Math.PI * Math.min(1, g.u * 1.02)), 0.7)) * (1 - pe * 0.75);
      var scale = 0.75 + 0.25 * Math.sin(g.ring * TAU + along * 40);
      var off = g.lat * thick * scale + wave;
      var cx = center.x + Math.cos(theta) * radius, cy = center.y + Math.sin(theta) * radius * 0.86;
      // normal to the spiral for lateral offset
      var nx = Math.cos(theta), ny = Math.sin(theta) * 0.86;
      var tx = cx + nx * off, ty = cy + ny * off;

      // Physics: spring to target + curl turbulence + cursor disturbance, damped.
      var cf = curl(g.x, g.y, t * 0.15);
      g.vx += (tx - g.x) * g.k + cf[0] * 0.9;
      g.vy += (ty - g.y) * g.k + cf[1] * 0.9;
      if (mouse.inHero) {
        var mx = g.x - mouse.x, my = g.y - mouse.y, md = mx * mx + my * my;
        if (md < 14400) { var f = (1 - md / 14400) * 1.6; g.vx += (mx / Math.sqrt(md + 1)) * f + mouse.vx * 0.04; g.vy += (my / Math.sqrt(md + 1)) * f + mouse.vy * 0.04; }
      }
      g.vx *= 0.86; g.vy *= 0.86;
      g.x += g.vx; g.y += g.vy;

      var head = g.u > 0.9 ? 1.3 : (g.u < 0.15 ? 0.6 : 1);
      var alpha = g.a * (0.55 + 0.4 * g.u) * (0.75 + pe * 0.35) * (g.u > 0.9 ? 0.55 : 1);
      var sz = g.size * head * (1 + pe * 0.4) * 3.6;
      hx.globalAlpha = Math.min(1, alpha);
      hx.drawImage((g.side ? SPR.ni : SPR.cu)[g.tone], g.x - sz / 2, g.y - sz / 2, sz, sz);
    }

    // Merge core: blooms as the dragons interlock; throws sparks once at the moment of union.
    var core = Math.max(0, (pe - 0.55) / 0.45);
    if (core > 0) {
      var pulse = 1 + Math.sin(t * 3.1) * 0.08;
      var cs = (60 + core * 220) * pulse;
      hx.globalAlpha = Math.min(1, core * 0.9);
      hx.drawImage(SPR.gold, center.x - cs / 2, center.y - cs / 2, cs, cs);
      hx.globalAlpha = core * 0.55;
      hx.drawImage(SPR.gold, center.x - cs * 1.4, center.y - cs * 0.06, cs * 2.8, cs * 0.12); // horizontal glint
      hx.drawImage(SPR.gold, center.x - cs * 0.04, center.y - cs * 0.9, cs * 0.08, cs * 1.8); // vertical glint
    }
    if (pe > 0.93 && !merged) { merged = true; burst(sparks, center.x, center.y, mobile ? 50 : 110, 7, 'mix'); }
    if (pe < 0.7) merged = false;
    stepParticles(hx, sparks, 0.045, 0.965);
  }

  /* =====================================================================
     AMBIENT LAYER (whole homepage): motes, cursor trail, click bursts
     ===================================================================== */
  var ac = d.createElement('canvas');
  ac.className = 'rc-dust-ambient'; ac.setAttribute('aria-hidden', 'true');
  d.body.appendChild(ac);
  var ax = ac.getContext('2d');
  var AW = 0, AH = 0;
  function sizeAmbient() {
    AW = w.innerWidth; AH = w.innerHeight;
    ac.width = AW * DPR; ac.height = AH * DPR; ac.style.width = AW + 'px'; ac.style.height = AH + 'px';
    ax.setTransform(DPR, 0, 0, DPR, 0, 0);
  }
  sizeAmbient();

  var motes = [], trail = [];
  for (i = 0; i < (mobile ? 26 : 60); i++) {
    motes.push({ x: Math.random() * AW, y: Math.random() * AH, vx: 0, vy: 0, s: 0.5 + Math.random() * 1.3, a: 0.12 + Math.random() * 0.22, cu: Math.random() < 0.5, tone: (Math.random() * 3) | 0 });
  }

  function burst(list, x, y, n, speed, kind) {
    for (var q = 0; q < n; q++) {
      var ang = Math.random() * TAU, sp = speed * (0.25 + Math.random() * 0.75);
      list.push({ x: x, y: y, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp - speed * 0.15, life: 1, decay: 0.008 + Math.random() * 0.014,
        s: 0.6 + Math.random() * 1.8, cu: kind === 'mix' ? Math.random() < 0.5 : kind === 'cu', tone: (Math.random() * 3) | 0, gold: kind === 'mix' && Math.random() < 0.25, swirl: (Math.random() - 0.5) * 0.08 });
    }
  }

  function stepParticles(ctx, list, gravity, drag) {
    for (var q = list.length - 1; q >= 0; q--) {
      var s = list[q];
      // swirl: rotate velocity slightly → curling dust
      var c = Math.cos(s.swirl || 0), sn = Math.sin(s.swirl || 0), vx = s.vx * c - s.vy * sn;
      s.vy = s.vx * sn + s.vy * c; s.vx = vx;
      s.vy += gravity; s.vx *= drag; s.vy *= drag;
      s.x += s.vx; s.y += s.vy; s.life -= s.decay;
      if (s.life <= 0) { list.splice(q, 1); continue; }
      var sz = s.s * 3 * (0.6 + s.life * 0.6);
      ctx.globalAlpha = Math.min(1, s.life * (s.gold ? 0.9 : 0.6));
      ctx.drawImage(s.gold ? SPR.gold : (s.cu ? SPR.cu : SPR.ni)[s.tone], s.x - sz / 2, s.y - sz / 2, sz, sz);
    }
  }

  var lastScroll = w.scrollY, scrollV = 0;
  function ambientFrame(now) {
    var t = now / 1000;
    var sy = w.scrollY; scrollV = scrollV * 0.8 + (sy - lastScroll) * 0.2; lastScroll = sy;
    ax.globalCompositeOperation = 'source-over';
    ax.clearRect(0, 0, AW, AH);
    ax.globalCompositeOperation = 'lighter';

    // Motes drift on the curl field and lag behind scrolling (depth cue).
    for (var q = 0; q < motes.length; q++) {
      var m = motes[q], cf = curl(m.x, m.y, t * 0.05);
      m.vx += cf[0] * 0.35; m.vy += cf[1] * 0.35 - scrollV * 0.012 * m.s;
      m.vx *= 0.93; m.vy *= 0.93; m.x += m.vx; m.y += m.vy;
      if (m.x < -10) m.x = AW + 10; if (m.x > AW + 10) m.x = -10; if (m.y < -10) m.y = AH + 10; if (m.y > AH + 10) m.y = -10;
      var tw = 0.7 + 0.3 * Math.sin(t * 1.3 + q);
      ax.globalAlpha = m.a * tw;
      var sz = m.s * 3;
      ax.drawImage((m.cu ? SPR.cu : SPR.ni)[m.tone], m.x - sz / 2, m.y - sz / 2, sz, sz);
    }
    stepParticles(ax, trail, 0.018, 0.955);
  }

  /* ---------- input ---------- */
  var lastMove = 0, px = null, py = null;
  w.addEventListener('pointermove', function (e) {
    var now = performance.now();
    var vx = px === null ? 0 : e.clientX - px, vy = py === null ? 0 : e.clientY - py;
    px = e.clientX; py = e.clientY;
    var hr = hero.getBoundingClientRect();
    mouse.inHero = e.clientY >= hr.top && e.clientY <= hr.bottom;
    mouse.x = e.clientX - hr.left; mouse.y = e.clientY - hr.top; mouse.vx = vx; mouse.vy = vy;
    if (mobile || now - lastMove < 16) return;
    lastMove = now;
    var speed = Math.sqrt(vx * vx + vy * vy);
    var n = Math.min(3, Math.floor(speed / 9));
    for (var q = 0; q < n && trail.length < 260; q++) {
      trail.push({ x: e.clientX + (Math.random() - 0.5) * 6, y: e.clientY + (Math.random() - 0.5) * 6,
        vx: vx * 0.12 + (Math.random() - 0.5) * 0.6, vy: vy * 0.12 + (Math.random() - 0.5) * 0.6,
        life: 1, decay: 0.012 + Math.random() * 0.012, s: 0.4 + Math.random() * 1.1, cu: Math.random() < 0.5, tone: (Math.random() * 3) | 0, swirl: (Math.random() - 0.5) * 0.05 });
    }
  }, { passive: true });
  w.addEventListener('pointerleave', function () { mouse.inHero = false; });
  w.addEventListener('pointerdown', function (e) {
    if (e.target.closest('input, textarea, select')) return;
    burst(trail, e.clientX, e.clientY, mobile ? 18 : 34, 3.2, Math.random() < 0.5 ? 'cu' : 'ni');
  }, { passive: true });

  var rt;
  w.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { sizeHero(); sizeAmbient(); }, 120); });

  d.addEventListener('visibilitychange', function () { if (!d.hidden) t0 = performance.now() - 1; });
  /* QA perf: cap at ~60 fps. Particle physics is per-frame, so on 120/144/165 Hz displays the
     uncapped loop both burned 2-3x the CPU and ran the motion faster than designed. */
  var lastFrame = 0;
  (function loop(now) {
    requestAnimationFrame(loop);
    if (now - lastFrame < 15) return;
    lastFrame = now;
    heroFrame(now);
    ambientFrame(now);
  })(performance.now());
})();
