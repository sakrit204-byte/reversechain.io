/* ReserveChain Web3 — wallet linking (EIP-4361 + personal_sign) and USDT intents. EIP-1193 only, no libraries. */
(function () {
  'use strict';
  var C = window.RC_WEB3 || {};
  var t = C.i18n || {};
  var root = document.querySelector('[data-rc-web3]');
  if (!root) return;

  var wallEl = root.querySelector('[data-rc-web3-wallets]');
  var msgEl = root.querySelector('[data-rc-web3-msg]');
  var payEl = root.querySelector('[data-rc-web3-payments]');
  var btn = root.querySelector('[data-rc-web3-connect]');
  var wallets = [];

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function say(text, kind) {
    msgEl.hidden = !text;
    msgEl.className = 'rc-alert rc-alert--' + (kind || 'info');
    msgEl.textContent = text || '';
  }

  function api(path, method, body) {
    return fetch(C.api + path, {
      method: method || 'GET',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': C.nonce },
      credentials: 'same-origin',
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || t.error); e.data = j; throw e; }
        return j;
      });
    });
  }

  function eth() { return window.ethereum && typeof window.ethereum.request === 'function' ? window.ethereum : null; }

  function utf8Hex(str) {
    var bytes = new TextEncoder().encode(str), out = '0x';
    for (var i = 0; i < bytes.length; i++) out += ('0' + bytes[i].toString(16)).slice(-2);
    return out;
  }

  function walletError(e) {
    if (e && e.code === 4001) return t.rejected;
    return (e && e.message) || t.error;
  }

  function ensureChain(provider) {
    var want = '0x' + Number(C.chainId).toString(16);
    return provider.request({ method: 'eth_chainId' }).then(function (cid) {
      if (String(cid).toLowerCase() === want) return true;
      return provider.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: want }] })
        .then(function () { return true; })
        .catch(function () { throw new Error(t.wrongChain); });
    });
  }

  function renderWallets(data) {
    wallets = (data && data.items) || [];
    if (!wallets.length) { wallEl.innerHTML = '<p>' + esc(t.none) + '</p>'; }
    else {
      wallEl.innerHTML = '<table class="rc-table"><tbody>' + wallets.map(function (w) {
        return '<tr><td><code>' + esc(w.address) + '</code>' + (w.explorer ? ' <a href="' + esc(w.explorer) + '" target="_blank" rel="noopener">↗</a>' : '') +
          '</td><td><small>' + esc(String(w.linked_at).slice(0, 10)) + '</small></td><td><button type="button" class="rc-btn" data-unlink="' + esc(w.address) + '">' + esc(t.unlink) + '</button></td></tr>';
      }).join('') + '</tbody></table>';
    }
    btn.disabled = wallets.length >= ((data && data.max) || 3);
  }

  function loadWallets() {
    return api('me/wallets').then(renderWallets).catch(function (e) { say(e.message, 'err'); });
  }

  function link() {
    var provider = eth();
    if (!provider) { say(t.noProvider, 'warn'); return; }
    btn.disabled = true;
    var address;
    provider.request({ method: 'eth_requestAccounts' })
      .then(function (accts) { address = accts && accts[0]; if (!address) throw new Error(t.error); return ensureChain(provider); })
      .then(function () { return api('me/wallet/challenge', 'POST', { address: address }); })
      .then(function (ch) {
        say(t.signing, 'info');
        return provider.request({ method: 'personal_sign', params: [utf8Hex(ch.message), address] }).then(function (sig) {
          return api('me/wallet/verify', 'POST', { address: ch.address, nonce: ch.nonce, signature: sig, message: ch.message });
        });
      })
      .then(function (res) { say(t.linked, 'ok'); renderWallets(res); loadPayments(); })
      .catch(function (e) { say(walletError(e), 'err'); })
      .then(function () { btn.disabled = wallets.length >= 3; });
  }

  wallEl.addEventListener('click', function (ev) {
    var a = ev.target.getAttribute('data-unlink');
    if (!a || !window.confirm(t.unlinkConfirm)) return;
    api('me/wallets/' + a, 'DELETE').then(function (res) { renderWallets(res); say('', ''); }).catch(function (e) { say(e.message, 'err'); });
  });
  btn.addEventListener('click', link);

  /* ------------------------------------------------------------ payments (only when the gate is open) */

  function fmt(minor) {
    if (minor == null) return '—';
    var s = String(minor), d = Number(C.decimals) || 6;
    while (s.length <= d) s = '0' + s;
    return s.slice(0, s.length - d) + '.' + s.slice(s.length - d);
  }

  function pad32(hex) { hex = hex.replace(/^0x/, ''); while (hex.length < 64) hex = '0' + hex; return hex; }

  function renderPayments(data) {
    var items = (data && data.items) || [];
    var html = '<h3>' + esc(t.payments) + '</h3><p><small>' + esc(data && data.note) + '</small></p>';
    if (C.programs && C.programs.length && wallets.length) {
      html += '<p><select data-pay-program>' + C.programs.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.title) + '</option>'; }).join('') + '</select> ' +
        '<select data-pay-wallet>' + wallets.map(function (w) { return '<option>' + esc(w.address) + '</option>'; }).join('') + '</select> ' +
        '<button type="button" class="rc-btn" data-pay-create>' + esc(t.requestIntent) + '</button></p>';
    }
    html += '<table class="rc-table"><tbody>' + items.map(function (i) {
      var st = (t.status && t.status[i.status]) || i.status, act = '';
      if (i.status === 'created') act = '<small>' + esc(t.awaitingAmount) + '</small>';
      if (i.status === 'awaiting_tx') {
        act = '<button type="button" class="rc-btn rc-btn--primary" data-pay-send="' + i.id + '">' + esc(t.sendUsdt) + '</button><br>' +
          '<input data-pay-hash="' + i.id + '" placeholder="' + esc(t.txPlaceholder) + '" size="30"> <button type="button" class="rc-btn" data-pay-submit="' + i.id + '">' + esc(t.submitTx) + '</button>';
      }
      if (i.status === 'confirming' || i.status === 'confirmed') act = esc(t.confirmations) + ': ' + i.confirmations + '/' + i.required_confirmations + (i.tx_url ? ' <a href="' + esc(i.tx_url) + '" target="_blank" rel="noopener">tx ↗</a>' : '');
      if (i.failure_reason) act += ' <small>' + esc(i.failure_reason) + '</small>';
      return '<tr><td>#' + i.id + ' ' + esc(i.program || '') + '<br><small><code>' + esc(i.wallet) + '</code></small></td><td>' + esc(t.amount) + ': ' + (i.amount_approved ? esc(fmt(i.amount_minor)) : '—') +
        '</td><td><span class="rc-pill rc-pill--' + esc(i.status) + '"><i aria-hidden="true"></i>' + esc(st) + '</span></td><td>' + act + '</td></tr>';
    }).join('') + '</tbody></table><p><button type="button" class="rc-btn" data-pay-refresh>' + esc(t.refresh) + '</button></p>';
    payEl.innerHTML = html;
    payEl._items = items;
  }

  function loadPayments() {
    if (!payEl || !C.payments) return;
    api('me/payments').then(renderPayments).catch(function (e) { payEl.innerHTML = '<div class="rc-alert rc-alert--warn">' + esc(e.message) + '</div>'; });
  }

  function submitHash(id, hash) {
    return api('me/payments/' + id + '/tx', 'POST', { tx_hash: hash }).then(function () { loadPayments(); });
  }

  if (payEl) {
    payEl.addEventListener('click', function (ev) {
      var el = ev.target;
      if (el.hasAttribute('data-pay-refresh')) { loadPayments(); return; }
      if (el.hasAttribute('data-pay-create')) {
        api('me/payments', 'POST', { program_id: payEl.querySelector('[data-pay-program]').value, wallet: payEl.querySelector('[data-pay-wallet]').value })
          .then(loadPayments).catch(function (e) { say(e.message, 'err'); });
        return;
      }
      var sid = el.getAttribute('data-pay-submit');
      if (sid) {
        var inp = payEl.querySelector('[data-pay-hash="' + sid + '"]');
        submitHash(sid, inp ? inp.value.trim() : '').catch(function (e) { say(e.message, 'err'); });
        return;
      }
      var pid = el.getAttribute('data-pay-send');
      if (pid) {
        var it = (payEl._items || []).filter(function (x) { return String(x.id) === pid; })[0];
        var provider = eth();
        if (!it || !provider) { say(t.noProvider, 'warn'); return; }
        if (typeof BigInt !== 'function') { say(t.error, 'err'); return; }
        var data = '0xa9059cbb' + pad32(it.treasury) + pad32(BigInt(it.amount_minor).toString(16));
        provider.request({ method: 'eth_requestAccounts' })
          .then(function () { return ensureChain(provider); })
          .then(function () { return provider.request({ method: 'eth_sendTransaction', params: [{ from: it.wallet, to: it.token_contract, data: data, value: '0x0' }] }); })
          .then(function (hash) { return submitHash(pid, hash); })
          .catch(function (e) { say(walletError(e), 'err'); });
      }
    });
  }

  loadWallets().then(loadPayments);
})();
