/* ReserveChain card icons — hand-drawn 1.5px line icons, matched to each card's subject (EN/ES/IT keywords).
 * Decorative only (aria-hidden); content stays fully editable in the CMS. */
(function () {
  'use strict';
  var P = function (d) { return '<path d="' + d + '"/>'; };
  var I = {
    vault: P('M4 5h24v22H4z') + P('M8 9h16v14H8z') + '<circle cx="16" cy="16" r="3.5"/>' + P('M16 12.5V10M16 22v-2.5M12.5 16H10M22 16h-2.5') + P('M7 27v2M25 27v2'),
    flask: P('M12 4h8M13 4v8l-7 13a2 2 0 0 0 1.8 3h16.4a2 2 0 0 0 1.8-3l-7-13V4') + P('M9.5 20h13') + '<circle cx="14" cy="23.5" r="1"/><circle cx="18.5" cy="22.5" r=".8"/>',
    passport: P('M7 4h18v24H7z') + '<circle cx="16" cy="13" r="4"/>' + P('M11 21h10M11 24h6') + P('M7 8h18'),
    hexcoin: P('M16 3l11 6.5v13L16 29 5 22.5v-13z') + P('M16 9l5.5 3.2v6.6L16 22l-5.5-3.2v-6.6z'),
    scale: P('M16 5v22M9 27h14M6 9h20') + P('M6 9l-3.5 8a3.5 2.5 0 0 0 7 0zM26 9l-3.5 8a3.5 2.5 0 0 0 7 0z'),
    shield: P('M16 3l11 4v8c0 7-4.6 12-11 14C9.6 27 5 22 5 15V7z') + P('M11 16l3.5 3.5L21 13'),
    truck: P('M3 9h15v13H3zM18 13h6l4 4.5V22H18') + '<circle cx="8" cy="23.5" r="2.5"/><circle cx="23" cy="23.5" r="2.5"/>',
    doc: P('M8 3h11l6 6v20H8z') + P('M19 3v6h6') + P('M12 15h9M12 19h9M12 23h5'),
    fingerprint: P('M10 26c-1.5-2.5-2-5.5-2-9a8 8 0 0 1 16 0c0 2-.3 3.5-1 5') + P('M13 27c-1-2.5-1.5-5-1.5-8.5a4.5 4.5 0 0 1 9 0c0 4-1 7-2.5 9') + P('M16 18.5c0 3.5-.5 6.5-1.5 9'),
    chain: P('M13.5 18.5l5-5') + P('M11 15.5l-3 3a4.2 4.2 0 0 0 6 6l3-3') + P('M21 16.5l3-3a4.2 4.2 0 0 0-6-6l-3 3'),
    building: P('M5 28V9l11-5 11 5v19') + P('M3 28h26M10 13h3M19 13h3M10 18h3M19 18h3M14 28v-5h4v5'),
    globe: '<circle cx="16" cy="16" r="12"/>' + P('M4 16h24M16 4c4 3.5 5 8 5 12s-1 8.5-5 12c-4-3.5-5-8-5-12s1-8.5 5-12z'),
    cube: P('M16 3l11 6v14l-11 6-11-6V9z') + P('M5 9l11 6 11-6M16 15v14'),
    lock: P('M8 14h16v14H8z') + P('M11 14v-4a5 5 0 0 1 10 0v4') + '<circle cx="16" cy="21" r="1.6"/>',
    graph: P('M4 27h24') + P('M7 22l6-7 5 4 8-10') + '<circle cx="13" cy="15" r="1.4"/><circle cx="18" cy="19" r="1.4"/><circle cx="26" cy="9" r="1.4"/>',
    people: '<circle cx="11" cy="11" r="4"/><circle cx="22" cy="12" r="3"/>' + P('M3 27c0-5 3.6-8 8-8s8 3 8 8M19 20c3.8 0 7 2.4 7 7')
  };
  var RULES = [
    [/custod|vault|cust[oó]dia|custodia|caveau|almac|magazz|storage|warehouse|seal|sell|sigill/i, 'vault'],
    [/inspect|inspecci|ispezion|release|liberaci|rilascio|control/i, 'lock'],
    [/verif|laborator|assay|analys|an[aá]lisi|certificat|sampl|muestr|campion|test/i, 'flask'],
    [/passport|pasaporte|passaporto|identity|identidad|identit/i, 'passport'],
    [/token|erc-20|mint|emisi|emission|tokeniz/i, 'hexcoin'],
    [/reserve|reserva|riserv|reconcil|concilia|riconcilia|coverage|cobertura|copertura/i, 'scale'],
    [/complian|cumplim|conformit|kyc|kyb|aml|sanction|eligib|jurisdic|giurisdiz|restrict/i, 'shield'],
    [/redemp|reembolso|rimborso|deliver|entrega|consegn|logistic|log[ií]stic/i, 'truck'],
    [/fingerprint|huella|impronta|sha-256|hash/i, 'fingerprint'],
    [/document|whitepaper|report|informe|policy|pol[ií]tica|legal|terms/i, 'doc'],
    [/audit|chain|cadena|catena|ledger|registro|registry|anchor|ancla/i, 'chain'],
    [/enterprise|empresa|impres|licens|licenc|white-label|institution|instituc|istituz/i, 'building'],
    [/jurisdiction|global|world|mundo|mondo|future|futur/i, 'globe'],
    [/security|seguridad|sicurezza|mfa|access|acceso|accesso|session|key|clave|chiave/i, 'lock'],
    [/market|mercado|mercato|valuation|valoraci|valutazion|price|value|valor/i, 'graph'],
    [/owner|propietari|proprietari|buyer|compradores|acquirent|participa|partecipa|team|people|governance|gobernanza/i, 'people'],
    [/material|physical|f[ií]sic|powder|polvo|polvere|wire|alambre|filo|metal/i, 'cube']
  ];
  function pick(text) {
    for (var i = 0; i < RULES.length; i++) { if (RULES[i][0].test(text)) return RULES[i][1]; }
    return null;
  }
  var fallback = ['cube', 'chain', 'doc', 'shield', 'graph', 'globe'];
  document.querySelectorAll('.rc-grid').forEach(function (grid) {
    var cards = Array.prototype.filter.call(grid.children, function (c) { return c.classList && c.classList.contains('rc-card'); });
    if (!cards.length) return;
    var picks = cards.map(function (card) {
      if (card.querySelector('.rc-card__icon, .rc-card__glyph, form, table, img')) return false;
      var h = card.querySelector('h3');
      return h ? pick(h.textContent + ' ' + ((card.querySelector('.rc-card__num') || {}).textContent || '')) : false;
    });
    if (!picks.some(Boolean)) return;
    cards.forEach(function (card, i) {
      if (picks[i] === false) return;
      var key = picks[i] || fallback[i % fallback.length];
      var s = document.createElement('span');
      s.className = 'rc-card__glyph';
      s.setAttribute('aria-hidden', 'true');
      s.innerHTML = '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' + I[key] + '</svg>';
      card.insertBefore(s, card.firstChild);
    });
  });
})();
