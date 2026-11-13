/* assets/home-pedidos.js — Inicio › Mis pedidos: aprobar y cerrar, pedir cambios y calificar con assets/calificar.js.
 * Después de cada acción se recarga el Inicio (volviendo a la misma pestaña) para que el pedido cambie de lugar. ENTREGAS_CALIFICACION.md */
(function () {
  'use strict';
  var sec = document.getElementById('d3-working'); if (!sec || !window.G7Rate) return;
  var done = false;
  var reload = function () { if (done) return; done = true; try { sessionStorage.setItem('d3-wk-tab', 'rqdone'); } catch (e) {} location.reload(); };
  sec.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ev]'); if (!b) return;
    e.preventDefault();
    var id = +b.getAttribute('data-id');
    if (b.getAttribute('data-ev') === 'approve') {
      // Cerrado: se abre la calificación; al cerrarla (con o sin calificar) se recarga
      G7Rate.approve(id, { onDone: reload, onClose: reload }).then(function () { if (!document.querySelector('.g7r-dlg')) reload(); });
    } else if (b.getAttribute('data-ev') === 'changes') G7Rate.changes(id, { onDone: reload });
    else G7Rate.open(id, { stars: +b.getAttribute('data-n') || 0, onDone: reload });
  });
  // Volver a la pestaña de pedidos después de recargar (cuando ya están las pestañas de assets/home-1.js)
  document.addEventListener('DOMContentLoaded', function () {
    try {
      var tab = sessionStorage.getItem('d3-wk-tab');
      if (tab) { sessionStorage.removeItem('d3-wk-tab'); var t = sec.querySelector('[data-wk="' + tab + '"]'); if (t) t.click(); }
    } catch (e) {}
  });
})();
