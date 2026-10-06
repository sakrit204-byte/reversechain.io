/* ReserveChain public components — no framework, no tracking, progressive enhancement. */
(function () {
  'use strict';
  var C = window.RC || {};
  var t = C.i18n || {};

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function api(path, opts) {
    opts = opts || {};
    return fetch(C.api + path, {
      method: opts.method || 'GET',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, data: j }; });
    });
  }

  function formData(form) {
    var out = {};
    $$('input, select, textarea', form).forEach(function (el) {
      if (!el.name || el.disabled) return;
      var name = el.name.replace(/\[\]$/, '');
      if (el.type === 'checkbox') {
        if (/\[\]$/.test(el.name)) { out[name] = out[name] || []; if (el.checked) out[name].push(el.value); }
        else out[name] = el.checked;
      } else if (el.type === 'radio') { if (el.checked) out[name] = el.value; }
      else out[name] = el.value.trim();
    });
    return out;
  }

  function showErrors(form, fields) {
    $$('.rc-field--error', form).forEach(function (el) { el.classList.remove('rc-field--error'); });
    $$('.rc-err', form).forEach(function (el) { el.remove(); });
    /* QA a11y: expose errors programmatically (aria-invalid + aria-describedby). */
    $$('[aria-invalid]', form).forEach(function (el) {
      el.removeAttribute('aria-invalid');
      var ids = (el.getAttribute('aria-describedby') || '').split(' ').filter(function (id) { return id && id.indexOf('rc-err-') !== 0; });
      if (ids.length) el.setAttribute('aria-describedby', ids.join(' ')); else el.removeAttribute('aria-describedby');
    });
    var formKey = form.getAttribute('data-rc-form') || 'form';
    Object.keys(fields || {}).forEach(function (k) {
      var el = form.querySelector('[name="' + k + '"]') || form.querySelector('[name="' + k + '[]"]');
      if (!el) return;
      var wrap = el.closest('.rc-field, .rc-check') || el.parentNode;
      wrap.classList.add('rc-field--error');
      var m = document.createElement('small'); m.className = 'rc-err'; m.textContent = fields[k];
      m.id = 'rc-err-' + formKey + '-' + k.replace(/[^a-z0-9_-]/gi, '');
      wrap.appendChild(m);
      $$('[name="' + k + '"], [name="' + k + '[]"]', form).forEach(function (f) {
        f.setAttribute('aria-invalid', 'true');
        f.setAttribute('aria-describedby', ((f.getAttribute('aria-describedby') || '') + ' ' + m.id).trim());
      });
    });
    var first = form.querySelector('.rc-field--error input, .rc-field--error select, .rc-field--error textarea');
    if (first) first.focus();
  }

  function isRestricted(code) {
    if (!code) return false;
    return (C.restrictEu && C.eu.indexOf(code) !== -1) || C.restricted.indexOf(code) !== -1;
  }

  /* ---------------- campaign source (utm) ---------------- */
  var qs = new URLSearchParams(location.search);
  $$('[data-rc-campaign]').forEach(function (el) { el.value = (qs.get('utm_source') || qs.get('ref') || document.referrer.split('/')[2] || '').slice(0, 100); });

  /* ---------------- waitlist + contact ---------------- */
  $$('form[data-rc-form]').forEach(function (form) {
    var kind = form.getAttribute('data-rc-form');
    var result = $('.rc-form__result', form);
    var btn = $('button[type=submit]', form);

    if (kind === 'waitlist') {
      var org = $('.rc-field--org', form);
      var note = $('.rc-restricted-note', form);
      var general = $('.rc-general-updates', form);
      $$('input[name=entity_type]', form).forEach(function (r) {
        r.addEventListener('change', function () { org.hidden = form.entity_type.value !== 'institution'; });
      });
      form.country.addEventListener('change', function () {
        var r = isRestricted(form.country.value);
        note.hidden = !r; general.hidden = !r;
        note.textContent = r ? t.restricted : '';
      });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = formData(form);
      btn.disabled = true; var label = btn.textContent; btn.textContent = t.sending || '…';
      result.className = 'rc-form__result'; result.textContent = '';
      api('/' + kind, { method: 'POST', body: data }).then(function (res) {
        btn.disabled = false; btn.textContent = label;
        if (!res.ok) {
          showErrors(form, res.data && res.data.data && res.data.data.fields);
          result.className = 'rc-form__result rc-alert rc-alert--err';
          result.textContent = (res.data && res.data.message) || t.error;
          return;
        }
        showErrors(form, {});
        var d = res.data;
        var html = '<strong>' + esc(d.message || (d.ticket ? d.ticket : '✓')) + '</strong>';
        if (d.ticket) html = '<strong>✓ ' + esc(d.ticket) + '</strong>';
        if (d.dev_confirm_url) html += '<br><small>Development environment — confirmation link: <a href="' + esc(d.dev_confirm_url) + '">confirm</a></small>';
        result.className = 'rc-form__result rc-alert ' + (d.status === 'ineligible_jurisdiction' || d.jurisdiction === 'restricted' ? 'rc-alert--warn' : 'rc-alert--ok');
        result.innerHTML = html;
        if (d.status !== 'ineligible_jurisdiction') form.reset();
      }).catch(function () {
        btn.disabled = false; btn.textContent = label;
        result.className = 'rc-form__result rc-alert rc-alert--err'; result.textContent = t.error;
      });
    });
  });

  /* ---------------- verification ---------------- */
  function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }

  function renderVerify(box, res) {
    var out = $('.rc-verify__result', box);
    if (!res.ok) { out.innerHTML = '<div class="rc-alert rc-alert--err">' + esc((res.data && res.data.message) || t.error) + '</div>'; return; }
    var d = res.data;
    if (!d.match) {
      out.innerHTML = '<div class="rc-vres rc-vres--none"><div class="rc-vres__icon" aria-hidden="true">✕</div><div><strong>' + esc(t.nomatch) + '</strong><code class="rc-mono">' + esc(d.hash) + '</code></div></div>';
      return;
    }
    var doc = d.document || {};
    var ps = (d.passports || []).map(function (p) { return '<a class="rc-btn rc-btn--sm" href="' + esc(p.url) + '">' + esc(t.viewPassport) + ' · ' + esc(p.passport_no) + '</a>'; }).join(' ');
    out.innerHTML = '<div class="rc-vres rc-vres--' + (d.state === 'current' ? 'ok' : 'warn') + '"><div class="rc-vres__icon" aria-hidden="true">' + (d.state === 'current' ? '✓' : '!') + '</div><div>' +
      '<strong>' + esc(d.state === 'current' ? t.match : t.withdrawn) + '</strong>' +
      '<dl><dt>Document</dt><dd>' + esc(doc.title) + ' <span class="rc-mono">' + esc(doc.record_no || '') + '</span></dd>' +
      '<dt>Type</dt><dd>' + esc(doc.type_label || '') + '</dd>' +
      (doc.issued_by ? '<dt>' + esc(t.issuedBy) + '</dt><dd>' + esc(doc.issued_by) + '</dd>' : '') +
      '<dt>SHA-256</dt><dd><code class="rc-mono">' + esc(d.hash) + '</code></dd>' +
      '<dt>Checked</dt><dd>' + esc(d.checked_at) + '</dd></dl>' +
      '<p>' + ps + (doc.url ? ' <a class="rc-btn rc-btn--sm" href="' + esc(doc.url) + '">' + esc(t.download) + '</a>' : '') + '</p></div></div>';
  }

  function lookup(box, hash) {
    $('.rc-verify__result', box).innerHTML = '<div class="rc-loading">' + esc(t.checking) + '</div>';
    api('/verify?hash=' + encodeURIComponent(hash)).then(function (res) { renderVerify(box, res); })
      .catch(function () { renderVerify(box, { ok: false, data: {} }); });
  }

  $$('[data-rc-verify]').forEach(function (box) {
    var drop = $('.rc-drop', box), input = $('.rc-drop__input', box), form = $('[data-rc-hash-form]', box);
    function handle(file) {
      if (!file) return;
      if (!window.crypto || !crypto.subtle) { $('.rc-verify__result', box).textContent = 'Your browser does not support local hashing (requires HTTPS).'; return; }
      $('.rc-verify__result', box).innerHTML = '<div class="rc-loading">' + esc(t.hashing) + ' <span class="rc-mono">' + esc(file.name) + '</span></div>';
      file.arrayBuffer().then(function (buf) { return crypto.subtle.digest('SHA-256', buf); }).then(function (d) {
        var h = hex(d); form.hash.value = h; lookup(box, h);
      });
    }
    input.addEventListener('change', function () { handle(input.files[0]); });
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) { handle(e.dataTransfer.files[0]); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var h = form.hash.value.trim().toLowerCase();
      if (!/^[a-f0-9]{64}$/.test(h)) { form.hash.focus(); form.hash.setCustomValidity('64 hex characters'); form.hash.reportValidity(); form.hash.setCustomValidity(''); return; }
      lookup(box, h);
    });
    var q = new URLSearchParams(location.search).get('hash');
    if (q && /^[a-fA-F0-9]{64}$/.test(q)) { form.hash.value = q; lookup(box, q.toLowerCase()); }
  });

  /* ---------------- copy-to-clipboard ---------------- */
  /* QA a11y: copyable hashes are reachable and operable by keyboard. */
  $$('[data-copy]').forEach(function (el) {
    if (/^(A|BUTTON|INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.hasAttribute('tabindex')) return;
    el.setAttribute('tabindex', '0'); el.setAttribute('role', 'button');
    if (!el.hasAttribute('aria-label')) el.setAttribute('aria-label', (t.copy || 'Copy') + ': ' + el.textContent.trim());
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-copy][role=button]')) { e.preventDefault(); e.target.click(); }
  });
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-copy]');
    if (!el || !navigator.clipboard) return;
    navigator.clipboard.writeText(el.getAttribute('data-copy') || el.textContent.trim()).then(function () {
      el.classList.add('is-copied'); el.setAttribute('data-copied', t.copied || 'Copied');
      setTimeout(function () { el.classList.remove('is-copied'); }, 1400);
    });
  });

  /* ---------------- QR codes ---------------- */
  if (window.qrcode) {
    $$('[data-qr]').forEach(function (el) {
      var q = window.qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make();
      el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
      var svg = el.querySelector('svg'); if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code'); }
    });
  }
})();
