/* assets/activar.js — activar.php: copiar el código de conexión a la red Cronos. */
(function () {
  var b = document.getElementById('act-copy'), t = document.getElementById('act-code'); if (!b || !t) return;
  b.addEventListener('click', function () {
    var ok = function () { var o = b.innerHTML; b.textContent = 'Copiado'; setTimeout(function () { b.innerHTML = o; }, 1800); };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t.value).then(ok);
    else { t.select(); try { document.execCommand('copy'); ok(); } catch (e) {} }
  });
})();
