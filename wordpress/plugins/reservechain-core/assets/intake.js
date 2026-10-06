/* ReserveChain asset intake — multi-step enhancement of a plain multipart form (works without JS). */
(function () {
  'use strict';
  var form = document.querySelector('[data-rc-intake]');
  if (!form || !window.FormData) return;
  var C = window.RCIntake || {};
  var t = C.i18n || {};
  var steps = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
  var inds = Array.prototype.slice.call(form.querySelectorAll('[data-step-ind]'));
  var prev = form.querySelector('[data-prev]'), next = form.querySelector('[data-next]'), submit = form.querySelector('[data-submit]');
  var live = form.querySelector('[data-step-live]'), out = form.querySelector('[data-result]');
  var cur = 0;

  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || form).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function msg(text, kind) { out.className = 'rc-form__result' + (text ? ' rc-alert rc-alert--' + (kind || 'err') : ''); out.textContent = text || ''; }
  function clearErrors(scope) {
    $$('.rc-field--error', scope).forEach(function (el) { el.classList.remove('rc-field--error'); });
    $$('.rc-err', scope).forEach(function (el) { el.remove(); });
    $$('[aria-invalid]', scope).forEach(function (el) { el.removeAttribute('aria-invalid'); });
  }
  function markError(key, text) {
    var wrap = form.querySelector('[data-field="' + key + '"]');
    if (!wrap) return null;
    wrap.classList.add('rc-field--error');
    var input = wrap.querySelector('input, select, textarea');
    if (input) input.setAttribute('aria-invalid', 'true');
    if (text) { var s = document.createElement('small'); s.className = 'rc-err'; s.textContent = text; wrap.appendChild(s); }
    return input;
  }

  function go(i, focus) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, k) { s.hidden = k !== cur; });
    inds.forEach(function (li, k) { if (k === cur) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current'); li.classList.toggle('is-done', k < cur); });
    prev.hidden = cur === 0;
    next.hidden = cur === steps.length - 1;
    submit.hidden = cur !== steps.length - 1;
    if (live) live.textContent = (t.step || 'Step %1$d of %2$d').replace('%1$d', cur + 1).replace('%2$d', steps.length);
    if (cur === steps.length - 1) summary();
    if (focus) { var l = steps[cur].querySelector('legend'); if (l) { l.setAttribute('tabindex', '-1'); l.focus(); } }
    msg('');
  }

  function checkFiles() {
    var input = form.querySelector('input[type=file]');
    var files = input && input.files ? Array.prototype.slice.call(input.files) : [];
    var list = form.querySelector('[data-files]');
    list.innerHTML = files.map(function (f) { return '<li><span>' + esc(f.name) + '</span> <small class="rc-fine">' + (f.size / 1048576).toFixed(2) + ' MB</small></li>'; }).join('');
    if (files.length > (C.maxFiles || 5)) return t.tooMany;
    for (var i = 0; i < files.length; i++) {
      if (files[i].size > (C.maxBytes || 20971520)) return t.tooBig;
      var ext = (files[i].name.split('.').pop() || '').toLowerCase();
      if ((C.ext || []).indexOf(ext) < 0) return t.badType;
    }
    return '';
  }

  function validStep(i) {
    var s = steps[i], first = null;
    clearErrors(s);
    $$('[required]', s).forEach(function (el) {
      var ok = el.type === 'radio' ? !!s.querySelector('input[name="' + el.name + '"]:checked') : el.type === 'checkbox' ? el.checked : el.value.trim() !== '' && el.checkValidity();
      if (!ok) {
        var w = el.closest('[data-field]');
        var inp = markError(w ? w.getAttribute('data-field') : '', '');
        first = first || inp || el;
      }
    });
    if (i === 1) {
      var mat = (s.querySelector('input[name=material]:checked') || {}).value;
      if ((mat === 'other_metal' || mat === 'other_asset') && !form.material_other.value.trim()) first = first || markError('material_other', '');
    }
    if (i === 2) { var fe = checkFiles(); if (fe) { first = first || markError('certificates', fe); } }
    if (first) { msg(t.required); first.focus(); return false; }
    return true;
  }

  function summary() {
    var dl = form.querySelector('[data-summary]');
    var rows = [];
    function label(name) { var el = form.querySelector('[name="' + name + '"]'); var w = el && el.closest('.rc-field'); var sp = w && w.querySelector('span, legend'); return sp ? sp.textContent.replace('*', '').trim() : name; }
    function val(name) {
      var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]');
      if (!el) return '';
      if (el.tagName === 'SELECT') return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : '';
      if (el.type === 'radio') return el.parentNode.textContent.trim();
      return el.value;
    }
    ['organisation', 'contact_name', 'email', 'role', 'material', 'quantity', 'unit', 'country'].forEach(function (n) { var v = val(n); if (v) rows.push('<div><dt>' + esc(label(n)) + '</dt><dd>' + esc(v) + '</dd></div>'); });
    var input = form.querySelector('input[type=file]');
    if (input && input.files && input.files.length) rows.push('<div><dt>' + esc(label('certificates[]')) + '</dt><dd>' + input.files.length + '</dd></div>');
    dl.innerHTML = rows.join('');
    dl.hidden = !rows.length;
  }

  next.addEventListener('click', function () { if (validStep(cur)) go(cur + 1, true); });
  prev.addEventListener('click', function () { go(cur - 1, true); });
  form.querySelector('input[type=file]').addEventListener('change', function () { clearErrors(steps[2]); var e = checkFiles(); if (e) markError('certificates', e); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    for (var i = 0; i < steps.length; i++) { if (!validStep(i)) { go(i, false); validStep(i); return; } }
    submit.disabled = true; submit.setAttribute('aria-busy', 'true');
    msg(t.sending, 'info');
    fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        submit.disabled = false; submit.removeAttribute('aria-busy');
        if (j && j.ok) {
          var done = document.createElement('div');
          done.className = 'rc-alert rc-alert--ok'; done.setAttribute('role', 'status'); done.setAttribute('tabindex', '-1');
          done.textContent = j.message;
          form.parentNode.replaceChild(done, form); done.focus();
          return;
        }
        var fields = (j && j.fields) || {};
        var keys = Object.keys(fields), firstStep = null;
        clearErrors(form);
        keys.forEach(function (k) {
          markError(k, fields[k]);
          var w = form.querySelector('[data-field="' + k + '"]'), st = w && w.closest('[data-step]');
          if (st && (firstStep === null || +st.getAttribute('data-step') < firstStep)) firstStep = +st.getAttribute('data-step');
        });
        if (firstStep !== null) go(firstStep, false);
        msg((j && j.message) || t.error);
      })
      .catch(function () { submit.disabled = false; submit.removeAttribute('aria-busy'); msg(t.error); });
  });

  go(0, false);
})();
