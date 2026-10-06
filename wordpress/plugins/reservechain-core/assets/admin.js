/* ReserveChain admin helpers */
(function ($) {
  'use strict';
  // Media picker for registry file fields.
  $(document).on('click', '.rc-pick-file', function (e) {
    e.preventDefault();
    var target = $('#' + $(this).data('target'));
    var label = $(this).siblings('.rc-file-name');
    var frame = wp.media({ title: 'Select evidence document', button: { text: 'Use this file' }, multiple: false });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      target.val(a.id);
      label.text(a.filename + ' — fingerprint computed on save');
    });
    frame.open();
  });
  // QR codes (MFA enrolment).
  $(function () {
    if (!window.qrcode) return;
    $('[data-qr]').each(function () {
      var q = window.qrcode(0, 'M'); q.addData($(this).attr('data-qr')); q.make();
      this.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    });
  });
})(jQuery);
