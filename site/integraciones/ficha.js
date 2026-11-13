/* integraciones/ficha.js — Ficha pública de una propiedad (ficha.php): tocar una foto la abre en grande; tocar el fondo la cierra.
   Antes era código en línea (CSP estricta, SEC-24). */
(function () {
  var l = document.getElementById('lb');
  if (!l) return;
  l.addEventListener('click', function () { l.classList.remove('on'); });
  document.querySelectorAll('.gal img,.thumbs img').forEach(function (i) {
    i.onclick = function () { l.querySelector('img').src = i.src; l.classList.add('on'); };
  });
})();
