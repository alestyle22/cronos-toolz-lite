/* assets/vocab.js — Vocabulario del rubro para el JS (antes era un script en línea de vocab_lib.php). Datos: #g7-terms. */
(function () {
  var el = document.getElementById('g7-terms');
  try { window.G7_TERMS = el ? JSON.parse(el.textContent) : {}; } catch (e) { window.G7_TERMS = {}; }
  window.g7Term = function (k, f, l) { var t = (window.G7_TERMS[k] || {})[f === 'p' ? 'p' : 's'] || k; return l ? t.toLowerCase() : t; };
})();
