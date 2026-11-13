/* assets/json-globals.js — Pasa a window los datos de <script type="application/json" data-global="NOMBRE">.
   Reemplaza los <script>window.X = …;</script> en línea (CSP estricta). Se carga justo después del bloque de datos, así
   la variable existe en el mismo momento que antes. */
(function () {
  document.querySelectorAll('script[type="application/json"][data-global]:not([data-done])').forEach(function (el) {
    el.setAttribute('data-done', '1');
    try { window[el.getAttribute('data-global')] = JSON.parse(el.textContent); } catch (e) { if (window.console) console.warn('json-globals:', el.getAttribute('data-global'), e); }
  });
})();
