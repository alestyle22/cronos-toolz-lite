/* assets/stats-jira-print.js — Informe de Jira para imprimir (stats_jira_export.php, fmt=pdf): abre la ventana de impresión sola. */
(function () {
  var p = document.getElementById('jx-print'), c = document.getElementById('jx-close');
  if (p) p.addEventListener('click', function () { window.print(); });
  if (c) c.addEventListener('click', function () { window.close(); });
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
})();
