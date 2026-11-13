/* landing-gen/frame-host.js — Muestra el HTML de una landing en frame.php, aislado (CSP estricta, SEC-24 fase 2).
 * Antes era un iframe srcdoc o un blob:, que con la CSP estricta heredan la política de la página: los scripts de la landing
 * no correrían. frame.php se sirve en un origen propio (CSP sandbox) y pide el HTML por postMessage (frame.js).
 *   lgFrameShow(iframe, html): carga (o recarga) el iframe con ese HTML. Si la landing se recarga sola, se le manda de nuevo.
 *   lgFrameOpen(html): lo abre en una pestaña nueva. Devuelve la ventana (o null si el navegador la bloqueó). */
(function () {
  var FRAME = '/toolz/landing-gen/frame.php';
  var seq = 0, pending = [];   // {el: iframe} o {w: ventana}, con su html
  function url() { return FRAME + '?n=' + (++seq); }
  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.t !== 'lgFrameReady') return;
    for (var i = 0; i < pending.length; i++) {
      var p = pending[i], w = p.el ? p.el.contentWindow : p.w;
      if (!w || e.source !== w) continue;
      e.stopImmediatePropagation();
      w.postMessage({ t: 'lgLoad', html: p.html }, '*');
      if (!p.el) pending.splice(i, 1);   // la pestaña nueva se carga una sola vez
      return;
    }
  }, true);
  window.lgFrameShow = function (iframe, html) {
    pending = pending.filter(function (p) { return p.el ? (p.el !== iframe && p.el.isConnected) : !p.w.closed; });
    pending.push({ el: iframe, html: String(html || '') });
    iframe.src = url();
  };
  window.lgFrameOpen = function (html) {
    var w = window.open(url(), '_blank');
    if (w) pending.push({ w: w, html: String(html || '') });
    return w;
  };
})();
