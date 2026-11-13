/* assets/pages-faq.js — Preguntas desplegables de las landings que arma pages.php (pages_output/ y la vista previa).
   Antes era un onclick en línea: la vista previa (iframe srcdoc) hereda la CSP estricta del editor (SEC-24). */
(function () {
  function toggleFaq(btn) {
    var item = btn.parentElement; var open = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(function (i) { i.classList.remove('open'); });
    if (!open) item.classList.add('open');
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.faq-btn') : null;
    if (b) toggleFaq(b);
  });
})();
