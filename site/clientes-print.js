/* clientes-print.js — Página suelta para imprimir una factura o el historial (cli_print_page en clientes_lib.php).
   Abre el diálogo de impresión al cargar y engancha el botón. Antes era código en línea (CSP estricta, SEC-24). */
(function () {
  var b = document.getElementById('pp-print');
  if (b) b.addEventListener('click', function () { print(); });
  window.addEventListener('load', function () { setTimeout(function () { print(); }, 400); });
})();
