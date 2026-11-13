/* assets/stats-reporte.js — Botón de imprimir de stats_reporte.php, sin código en línea (CSP estricta, SEC-24). */
document.addEventListener('click', function (e) {
  if (e.target.closest && e.target.closest('[data-print]')) window.print();
});
