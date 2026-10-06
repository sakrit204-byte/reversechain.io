/* ReserveChain — Redemptions admin (module: redemption). */
(function () {
  'use strict';
  var msg = (window.rcRedemption && window.rcRedemption.confirm) || 'Confirm?';
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.matches || !f.matches('form[data-rc-confirm]')) return;
    if (!window.confirm(msg)) { e.preventDefault(); return; }
    var b = f.querySelector('button');
    if (b) { setTimeout(function () { b.disabled = true; }, 0); }
  });
  // Only one action form open at a time.
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d.classList || !d.classList.contains('rc-rdm-act') || !d.open) return;
    document.querySelectorAll('.rc-rdm-act[open]').forEach(function (o) { if (o !== d) o.open = false; });
  }, true);
})();
