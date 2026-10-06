/* ReserveChain Participant Portal — single-page client of the rc/v1 API. No framework, no tracking.
 * Tokens: access token in memory only; refresh token in sessionStorage only (cleared when the tab closes).
 * 401 → one silent refresh + retry. Inactivity (15 min) → sign-out. */
(function () {
  'use strict';
  var root = document.querySelector('[data-rc-portal]');
  if (!root) return;
  var C = window.RCPortal || {};
  var t = C.i18n || {};
  var RKEY = 'rc_portal_rt';
  var S = { access: null, refresh: null, mfaToken: null, me: null, config: null, idleTimer: null, refreshing: null };

  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(s, v) { return String(s).replace('%s', v); }
  function announce(msg) { var l = $('[data-portal-live]'); if (l) { l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 30); } }
  function store(k, v) { try { if (v) sessionStorage.setItem(k, v); else sessionStorage.removeItem(k); } catch (e) { /* storage unavailable */ } }
  function load(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function date(s) { if (!s) return '—'; var d = new Date(s); return isNaN(d) ? s : d.toLocaleDateString(C.lang || undefined, { year: 'numeric', month: 'short', day: 'numeric' }); }

  /* ---------------- pills (site status hallmarks) ---------------- */
  var CHECK_CLASS = { approved: 'verified', pending: 'pending_verification', not_started: 'pending', rejected: 'restricted', not_applicable: 'not_applicable', eligible: 'eligible', restricted: 'restricted', incomplete: 'in_development', eligible_subject_to_final_approval: 'pending_verification' };
  function pill(cls, label) { return '<span class="rc-pill rc-pill--' + esc(cls) + '"><i aria-hidden="true"></i>' + esc(label) + '</span>'; }
  function checkPill(v) { return pill(CHECK_CLASS[v] || 'pending', (t.st && t.st[v]) || v); }
  function statusPill(v) { return pill(v || 'in_development', (t.statuses && t.statuses[v]) || v); }
  function locked(title, msg) { return '<div class="rc-locked"><span class="rc-locked__icon" aria-hidden="true"></span><div><strong>' + esc(title) + '</strong><p>' + esc(msg || t.locked) + '</p></div></div>'; }

  /* ---------------- API ---------------- */
  function raw(path, opts) {
    opts = opts || {};
    var h = { 'Accept': 'application/json' };
    if (opts.body) h['Content-Type'] = 'application/json';
    if (opts.auth && S.access) h.Authorization = 'Bearer ' + S.access;
    return fetch(C.api + path, { method: opts.method || 'GET', headers: h, credentials: 'omit', cache: 'no-store', body: opts.body ? JSON.stringify(opts.body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, data: j }; }); })
      .catch(function () { return { ok: false, status: 0, data: { message: t.network } }; });
  }
  function setTokens(d) {
    S.access = d.access_token || null;
    S.refresh = d.refresh_token || null;
    store(RKEY, S.refresh);
  }
  function doRefresh() {
    if (!S.refresh) return Promise.resolve(false);
    if (S.refreshing) return S.refreshing;
    S.refreshing = raw('/auth/refresh', { method: 'POST', body: { refresh_token: S.refresh } }).then(function (r) {
      S.refreshing = null;
      if (r.ok && r.data.access_token) { setTokens(r.data); return true; }
      return false;
    });
    return S.refreshing;
  }
  function api(path, opts) {
    opts = opts || {}; opts.auth = true;
    return raw(path, opts).then(function (r) {
      if (r.status !== 401) return r;
      return doRefresh().then(function (ok) {
        if (!ok) { endSession(t.expired); return r; }
        return raw(path, opts).then(function (r2) { if (r2.status === 401) endSession(t.expired); return r2; });
      });
    });
  }
  function errMsg(r) { return (r && r.data && r.data.message) || t.error; }

  /* ---------------- views ---------------- */
  function show(view) {
    $$('[data-view]').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== view; });
    var h = $('[data-view="' + view + '"] h2');
    if (h) h.focus({ preventScroll: false });
  }
  function result(form, msg, kind) {
    var el = $('[data-result]', form);
    if (!el) return;
    el.className = 'rc-form__result' + (msg ? ' rc-alert rc-alert--' + (kind || 'err') : '');
    el.textContent = msg || '';
  }
  function busy(form, on) { $$('button[type=submit]', form).forEach(function (b) { b.disabled = on; b.setAttribute('aria-busy', on ? 'true' : 'false'); }); }
  function values(form) {
    var o = {};
    $$('input, select, textarea', form).forEach(function (el) {
      if (!el.name) return;
      if (el.type === 'checkbox') o[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; }
      else o[el.name] = el.value.trim();
    });
    return o;
  }
  function fieldErrors(form, fields) {
    $$('.rc-field--error', form).forEach(function (el) { el.classList.remove('rc-field--error'); });
    $$('.rc-err', form).forEach(function (el) { el.remove(); });
    $$('[aria-invalid]', form).forEach(function (el) { el.removeAttribute('aria-invalid'); });
    var first = null;
    Object.keys(fields || {}).forEach(function (k) {
      var wrap = $('[data-field="' + k + '"]', form);
      if (!wrap) return;
      wrap.classList.add('rc-field--error');
      var input = $('input, select, textarea', wrap);
      if (input) { input.setAttribute('aria-invalid', 'true'); first = first || input; }
      var p = document.createElement('small'); p.className = 'rc-err'; p.textContent = fields[k];
      wrap.appendChild(p);
    });
    if (first) first.focus();
  }

  /* ---------------- session ---------------- */
  function resetIdle() {
    clearTimeout(S.idleTimer);
    if (!S.access) return;
    S.idleTimer = setTimeout(function () { logout(t.idleOut); }, C.idleMs || 900000);
  }
  ['click', 'keydown', 'pointermove', 'scroll', 'touchstart'].forEach(function (ev) {
    document.addEventListener(ev, function () { if (S.access) resetIdle(); }, { passive: true });
  });
  function endSession(msg) {
    S.access = null; S.refresh = null; S.me = null; S.mfaToken = null;
    store(RKEY, null);
    clearTimeout(S.idleTimer);
    $$('form', root).forEach(function (f) { if (f.getAttribute('data-form') !== 'signin') f.reset(); });
    $$('[data-mfa-codes-list], [data-mfa-qr], [data-mfa-secret]').forEach(function (el) { el.innerHTML = ''; });
    show('signin');
    if (msg) { var f = $('[data-form="signin"]'); result(f, msg, 'info'); announce(msg); }
  }
  function logout(msg) {
    var p = S.access ? raw('/auth/logout', { method: 'POST', auth: true }) : Promise.resolve();
    p.then(function () { endSession(msg || t.signedOut); });
  }

  /* ---------------- auth forms ---------------- */
  function onSignin(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    if (!v.email || !v.password) { result(f, t.required); return; }
    busy(f, true); result(f, t.signingIn, 'info');
    raw('/auth/login', { method: 'POST', body: { email: v.email, password: v.password, client: 'web' } }).then(function (r) {
      busy(f, false);
      if (!r.ok) { result(f, errMsg(r)); return; }
      f.password.value = '';
      result(f, '');
      if (r.data.mfa_required) { S.mfaToken = r.data.mfa_token; show('mfa'); return; }
      setTokens(r.data); start();
    });
  }
  function onMfa(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    if (!v.code) { result(f, t.required); return; }
    busy(f, true);
    raw('/auth/mfa/verify', { method: 'POST', body: { mfa_token: S.mfaToken, code: v.code, client: 'web' } }).then(function (r) {
      busy(f, false);
      if (!r.ok) {
        result(f, errMsg(r));
        if (r.data && r.data.code === 'rc_mfa_expired') { setTimeout(function () { endSession(errMsg(r)); }, 1500); }
        return;
      }
      f.reset(); result(f, ''); S.mfaToken = null;
      setTokens(r.data); start();
    });
  }
  function onRegister(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    fieldErrors(f, {});
    busy(f, true); result(f, t.sending, 'info');
    raw('/auth/register', { method: 'POST', body: v }).then(function (r) {
      busy(f, false);
      if (!r.ok) { result(f, errMsg(r)); fieldErrors(f, r.data && r.data.data && r.data.data.fields); return; }
      f.reset();
      show('signin');
      var s = $('[data-form="signin"]'); result(s, r.data.message || t.registered, 'ok'); announce(r.data.message || t.registered);
    });
  }

  /* ---------------- dashboard ---------------- */
  function start() {
    announce(t.signedIn);
    resetIdle();
    Promise.all([api('/me'), raw('/config')]).then(function (res) {
      if (!res[0].ok) { if (S.access) endSession(errMsg(res[0])); return; }
      S.me = res[0].data; S.config = res[1].ok ? res[1].data : { modules: {} };
      renderMe();
      show('app');
      tab((location.hash || '').replace('#portal-', '') || 'overview', true);
      loadNotifications();
    });
  }
  var loaded = {};
  function tab(name, initial) {
    if (!$('[data-panel="' + name + '"]')) name = 'overview';
    $$('[data-tab]').forEach(function (a) { if (a.getAttribute('data-tab') === name) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    $$('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    if (!initial) { var h = $('[data-panel="' + name + '"] h3'); if (h) h.focus(); }
    if (history.replaceState) history.replaceState(null, '', '#portal-' + name);
    var loader = LOADERS[name];
    if (loader && !loaded[name]) { loaded[name] = true; loader(); }
  }
  function kv(rows) { return rows.map(function (r) { return '<div><dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd></div>'; }).join(''); }

  function renderMe() {
    var m = S.me, el = m.eligibility || {};
    $$('[data-bind="name"]').forEach(function (n) { n.textContent = m.name || ''; });
    $$('[data-bind="email"]').forEach(function (n) { n.textContent = m.email || ''; });
    var mfa = m.mfa_enabled ? pill('verified', t.mfaEnabled) : pill('pending_verification', t.mfaNotEnabled);
    $('[data-overview]').innerHTML = kv([
      [t.name, esc(m.name || '—')],
      [t.eligibility, checkPill(el.overall || 'incomplete')],
      [t.jurisdiction, checkPill(el.jurisdiction || 'pending')],
      [t.mfa, mfa + (m.mfa_enabled ? '' : ' <a href="#portal-security" data-tab-link="security">' + esc(t.mfaSetup) + '</a>')],
      [t.notices, '<span data-bind="unread-count">—</span>'],
      [t.holdings, (S.config.modules || {}).holdings ? pill('verified', t.active) : pill('proposed', t.inactive)]
    ]);
    $('[data-profile]').innerHTML = kv([
      [t.name, esc(m.name || '—')],
      [t.email, esc(m.email)],
      [t.country, esc(m.country || t.notProvided)],
      [t.entity, esc(m.entity_type === 'institution' ? t.institution : t.individual)],
      [t.language, esc((m.language || 'en').toUpperCase())],
      [t.memberSince, esc(date(m.created_at))]
    ]);
    var pf = $('[data-form="profile"]'); pf.name.value = m.name || ''; pf.language.value = m.language || 'en';
    var rows = [['jurisdiction', t.jurisdiction + (el.country ? ' (' + el.country + ')' : '')], ['kyc', t.kyc], ['kyb', t.kyb], ['aml', t.aml], ['sanctions', t.sanctions], ['overall', t.overall]];
    $('[data-eligibility]').innerHTML = rows.map(function (r) { return '<div><strong>' + esc(r[1]) + '</strong>' + checkPill(el[r[0]] || 'not_started') + '</div>'; }).join('');
    renderMfa();
  }

  function renderMfa() {
    var on = S.me && S.me.mfa_enabled;
    $('[data-mfa-status]').innerHTML = on
      ? '<div class="rc-alert rc-alert--ok">' + pill('verified', t.mfaEnabled) + ' ' + esc(t.mfaOn) + '</div>'
      : '<div class="rc-alert rc-alert--warn">' + pill('pending_verification', t.mfaNotEnabled) + ' ' + esc(t.mfaOff) + '<p><button type="button" class="rc-btn rc-btn--primary rc-btn--sm" data-action="mfa-start">' + esc(t.mfaSetup) + '</button></p></div>';
  }
  function mfaStart() {
    api('/auth/mfa/setup', { method: 'POST' }).then(function (r) {
      if (!r.ok) { announce(errMsg(r)); return; }
      var box = $('[data-mfa-setup]'); box.hidden = false;
      var qrEl = $('[data-mfa-qr]'); qrEl.innerHTML = '';
      if (window.qrcode) {
        var q = window.qrcode(0, 'M'); q.addData(r.data.otpauth_url); q.make();
        qrEl.innerHTML = q.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
        var svg = qrEl.querySelector('svg'); if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code'); }
      }
      $('[data-mfa-secret]').textContent = (r.data.secret || '').replace(/(.{4})/g, '$1 ').trim();
      var inp = $('[data-form="mfa-enable"] input[name=code]'); if (inp) inp.focus();
    });
  }
  function onMfaEnable(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    if (!/^\d{6}$/.test(v.code || '')) { result(f, t.required); return; }
    busy(f, true);
    api('/auth/mfa/enable', { method: 'POST', body: { code: v.code } }).then(function (r) {
      busy(f, false);
      if (!r.ok) { result(f, errMsg(r)); return; }
      f.reset(); result(f, '');
      $('[data-mfa-setup]').hidden = true; $('[data-mfa-qr]').innerHTML = ''; $('[data-mfa-secret]').textContent = '';
      var list = $('[data-mfa-codes-list]');
      list.innerHTML = (r.data.recovery_codes || []).map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('');
      var box = $('[data-mfa-codes]'); box.hidden = false; box.focus();
      S.me.mfa_enabled = true; renderMe(); announce(t.mfaDone);
    });
  }

  function loadNotifications() {
    return api('/me/notifications').then(function (r) {
      var items = r.ok && Array.isArray(r.data) ? r.data : [];
      var unread = items.filter(function (n) { return !n.read && !n.broadcast; }).length;
      var b = $('[data-bind="unread"]'); if (b) { b.hidden = !unread; b.textContent = unread; }
      $$('[data-bind="unread-count"]').forEach(function (n) { n.textContent = String(unread); });
      $('[data-notifications]').innerHTML = items.length ? items.map(function (n) {
        return '<li class="rc-portal__notice' + (n.read || n.broadcast ? '' : ' is-unread') + '"><div><strong>' + esc(n.title) + '</strong> ' +
          (n.broadcast ? '<span class="rc-tag">' + esc(t.broadcast) + '</span>' : (n.read ? '<span class="rc-tag">' + esc(t.read) + '</span>' : '<span class="rc-tag rc-tag--gold">' + esc(t.unread) + '</span>')) +
          '<p>' + esc(n.body) + '</p><small class="rc-fine">' + esc(date(n.created_at)) + '</small></div>' +
          (!n.read && !n.broadcast ? '<button type="button" class="rc-btn rc-btn--sm" data-action="read" data-id="' + n.id + '">' + esc(t.markRead) + '</button>' : '') + '</li>';
      }).join('') : '<li class="rc-empty">' + esc(t.noNotices) + '</li>';
    });
  }

  function loadPrograms() {
    raw('/programs').then(function (r) {
      var items = r.ok && Array.isArray(r.data) ? r.data : [];
      $('[data-programs]').innerHTML = items.length ? items.map(function (p) {
        return '<article class="rc-portal__card"><span class="rc-portal__el" aria-hidden="true">' + esc(p.symbol || 'RC') + '</span><div><h4>' + esc(p.name) + '</h4><p class="rc-fine">' + esc(p.record_no || '') + '</p>' + statusPill(p.status) +
          '<p>' + esc(p.summary || '') + '</p><a class="rc-btn rc-btn--sm" href="' + esc(C.programUrl + encodeURIComponent(p.slug) + '/') + '">' + esc(t.viewProgram) + '</a></div></article>';
      }).join('') : '<p class="rc-empty">' + esc(t.noPrograms) + '</p>';
    });
  }

  function loadPassports() {
    raw('/passports').then(function (r) {
      var el = $('[data-passports]');
      if (r.status === 403) { el.innerHTML = '<li>' + locked(t.passportsOff) + '</li>'; return; }
      var items = r.ok && Array.isArray(r.data) ? r.data : [];
      el.innerHTML = items.length ? items.map(function (p) {
        return '<li class="rc-portal__row"><span class="rc-tag">' + esc(p.symbol || 'RC') + '</span><a class="rc-mono" href="' + esc(p.url) + '">' + esc(p.passport_no || p.record_no) + '</a> <span>' + esc(p.title || p.entity_label || '') + '</span> ' + statusPill(p.status) + '</li>';
      }).join('') : '<li class="rc-empty">' + esc(t.noPassports) + '</li>';
    });
  }

  function docRow(d, restricted) {
    var ext = (d.mime || '').indexOf('pdf') > -1 ? 'PDF' : ((d.mime || '').indexOf('image/') === 0 ? 'IMG' : 'DOC');
    var action = restricted
      ? '<button type="button" class="rc-btn rc-btn--sm" data-action="download" data-id="' + d.id + '">' + esc(t.download) + '</button>'
      : (d.url ? '<a class="rc-btn rc-btn--sm" href="' + esc(d.url) + '" download>' + esc(t.download) + '</a>' : '');
    return '<article class="rc-doc"><div class="rc-doc__icon" aria-hidden="true">' + ext + '</div><div class="rc-doc__body"><h3>' + esc(d.title) + '</h3><p class="rc-doc__meta">' + esc(d.type_label || '') +
      (d.version ? ' · ' + esc(d.version) : '') + (d.issue_date ? ' · ' + esc(d.issue_date) : '') + (restricted ? ' · <span class="rc-tag">' + esc(d.audience_label || d.audience) + '</span>' : '') +
      '</p>' + (d.sha256 ? '<p class="rc-doc__hash"><span>SHA-256</span><code class="rc-mono">' + esc(d.sha256) + '</code></p>' : '') + '</div><div class="rc-doc__actions">' + statusPill(d.status) + action + '</div></article>';
  }
  function loadDocuments() {
    api('/me/documents').then(function (r) {
      var d = r.ok ? r.data : { items: [], rooms: {} };
      var rooms = d.rooms || {}, html = '';
      [['investor', t.roomInvestor], ['enterprise', t.roomEnterprise]].forEach(function (x) {
        var room = rooms[x[0]];
        if (room && room.member && !room.enabled) html += locked(x[1]);
      });
      $('[data-rooms]').innerHTML = html;
      $('[data-restricted-docs]').innerHTML = (d.items || []).length ? d.items.map(function (x) { return docRow(x, true); }).join('') : '<p class="rc-empty">' + esc(t.noRestricted) + '</p>';
    });
    raw('/documents').then(function (r) {
      var items = r.ok && Array.isArray(r.data) ? r.data : [];
      $('[data-public-docs]').innerHTML = items.length ? items.map(function (x) { return docRow(x, false); }).join('') : '<p class="rc-empty">' + esc(t.noPublic) + '</p>';
    });
  }
  function download(id, btn) {
    btn.disabled = true;
    api('/me/documents/' + id + '/link').then(function (r) {
      btn.disabled = false;
      if (!r.ok || !r.data.url) { announce(errMsg(r)); return; }
      window.location.assign(r.data.url);
    });
  }

  function gatedList(target, title, r) {
    var d = r.ok ? r.data : { enabled: false };
    if (!d.enabled) { target.innerHTML = '<h4>' + esc(title) + '</h4>' + locked(title, d.reason || t.locked); return; }
    var items = d.items || [];
    target.innerHTML = '<h4>' + esc(title) + '</h4>' + (items.length ? '<ul class="rc-portal__list">' + items.map(function (i) { return '<li class="rc-portal__row">' + esc(JSON.stringify(i)) + '</li>'; }).join('') + '</ul>' : '<p class="rc-empty">' + esc(t.noItems) + '</p>');
  }
  function loadHoldings() {
    api('/me/holdings').then(function (r) { gatedList($('[data-holdings]'), t.holdings, r); });
    api('/me/transactions').then(function (r) { gatedList($('[data-transactions]'), t.transactions, r); });
  }
  function modulePanel(name) {
    var el = $('[data-module-panel="' + name + '"]');
    var on = !!((S.config && S.config.modules) || {})[name];
    var title = t[name] || name;
    if (!on) { el.innerHTML = locked(title); return; }
    var href = el.getAttribute('data-href');
    el.innerHTML = '<div class="rc-alert rc-alert--ok">' + esc(t.moduleOn) + (href ? ' <a class="rc-btn rc-btn--sm" href="' + esc(href) + '">' + esc(t.open) + '</a>' : '') + '</div>';
  }

  var LOADERS = {
    programs: loadPrograms,
    passports: loadPassports,
    documents: loadDocuments,
    holdings: loadHoldings,
    redemption: function () { modulePanel('redemption'); },
    wallet: function () { modulePanel('wallet'); }
  };

  function onProfile(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    busy(f, true);
    api('/me', { method: 'PATCH', body: { name: v.name, language: v.language } }).then(function (r) {
      busy(f, false);
      if (!r.ok) { result(f, errMsg(r)); return; }
      S.me = r.data; renderMe(); result(f, t.saved, 'ok'); announce(t.saved);
    });
  }
  function onSupport(e) {
    e.preventDefault();
    var f = e.target, v = values(f);
    fieldErrors(f, {});
    if ((v.message || '').length < 10) { fieldErrors(f, { message: t.required }); return; }
    busy(f, true); result(f, t.sending, 'info');
    api('/support', { method: 'POST', body: v }).then(function (r) {
      busy(f, false);
      if (!r.ok) { result(f, errMsg(r)); return; }
      f.reset(); result(f, fmt(t.ticket, r.data.ticket), 'ok'); announce(fmt(t.ticket, r.data.ticket));
    });
  }

  /* ---------------- wiring ---------------- */
  var handlers = { signin: onSignin, mfa: onMfa, register: onRegister, 'mfa-enable': onMfaEnable, profile: onProfile, support: onSupport };
  root.addEventListener('submit', function (e) {
    var h = handlers[e.target.getAttribute('data-form')];
    if (h) h(e);
  });
  root.addEventListener('click', function (e) {
    var go = e.target.closest('[data-go]');
    if (go) { e.preventDefault(); show(go.getAttribute('data-go')); return; }
    var tb = e.target.closest('[data-tab], [data-tab-link]');
    if (tb) { e.preventDefault(); tab(tb.getAttribute('data-tab') || tb.getAttribute('data-tab-link')); return; }
    var a = e.target.closest('[data-action]');
    if (!a) return;
    var act = a.getAttribute('data-action');
    if (act === 'logout') logout();
    else if (act === 'mfa-start') mfaStart();
    else if (act === 'read') {
      a.disabled = true;
      api('/me/notifications/' + a.getAttribute('data-id') + '/read', { method: 'POST' }).then(loadNotifications);
    } else if (act === 'download') download(a.getAttribute('data-id'), a);
    else if (act === 'copy-codes') {
      var txt = $$('[data-mfa-codes-list] li').map(function (li) { return li.textContent; }).join('\n');
      if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function () { a.textContent = t.copied; });
    } else if (act === 'codes-saved') {
      $('[data-mfa-codes-list]').innerHTML = ''; $('[data-mfa-codes]').hidden = true; tab('security');
    }
  });

  /* ---------------- boot ---------------- */
  S.refresh = load(RKEY);
  if (S.refresh) {
    doRefresh().then(function (ok) { if (ok) start(); else endSession(); });
  } else {
    show('signin');
  }
})();
