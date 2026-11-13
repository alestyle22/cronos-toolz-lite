/* assets/imghost-album.js — Script de imghost_album.php (lote público del ImageHost), sin código en línea (CSP estricta, SEC-24).
   Las URLs llegan en <script type="application/json" id="ih-album-urls">. Los botones llevan data-copy="todas|link|esta|anterior". */
(function () {
  var el = document.getElementById('ih-album-urls');
  var URLS = [];
  try { URLS = el ? JSON.parse(el.textContent) : []; } catch (e) {}
  async function copy(text, msg) {
    try { await navigator.clipboard.writeText(text); }
    catch (e) { const ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;opacity:0'; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); }
    const t = document.getElementById('toast'); t.textContent = '✓ ' + msg; t.style.opacity = '1';
    clearTimeout(copy.t); copy.t = setTimeout(() => t.style.opacity = '0', 2200);
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    switch (b.getAttribute('data-copy')) {
      case 'todas': copy(URLS.join('\n'), 'Copiadas las ' + URLS.length + ' URLs'); break;
      case 'link': copy(location.href, 'Link del lote copiado'); break;
      case 'esta': copy(b.textContent, 'URL copiada'); break;
      case 'anterior': copy(b.previousElementSibling.textContent, 'URL copiada'); break;
    }
  });
})();
