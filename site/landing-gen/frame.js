/* landing-gen/frame.js — Corre dentro de frame.php (aislado). Avisa a la página que lo abrió (iframe o pestaña nueva)
 * que está listo, recibe el HTML de la landing y lo escribe en este documento, con sus scripts.
 * Solo acepta el mensaje de esa misma ventana y si viene de este servidor (data-host): otra página no puede usarlo para mostrar su HTML acá. */
(function () {
  var host = document.documentElement.getAttribute('data-host') || '';
  var src = window.parent !== window ? window.parent : window.opener;
  if (!src) { document.body.textContent = 'Esta vista se abre desde el generador de landings.'; return; }
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (e.source !== src || !d || d.t !== 'lgLoad' || typeof d.html !== 'string') return;
    if (String(e.origin).replace(/^https?:/, '') !== host) return;
    document.open();
    document.write(d.html);
    document.close();
  });
  src.postMessage({ t: 'lgFrameReady' }, '*');
})();
