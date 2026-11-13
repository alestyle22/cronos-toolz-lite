/* assets/activar-2fa.js — Guía paso a paso de activar_2fa.php (verificación en dos pasos). Habla con totp_api.php. */
(function () {
  'use strict';
  var root = document.getElementById('tfg'); if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var cur = 1, started = false, codes = [];

  function api(action, data) {
    var body = { action: action }; for (var k in (data || {})) body[k] = data[k];
    return fetch('/toolz/totp_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-2FA': '1' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { error: 'El servidor no respondió bien.' }; }); })
      .then(function (j) { if (j.error) throw new Error(j.error); return j; });
  }

  /** Muestra un paso, marca el avance arriba y lleva el foco al título (lectores de pantalla). */
  function go(n) {
    cur = n;
    root.querySelectorAll('.tfg-step').forEach(function (s) { s.classList.toggle('on', +s.getAttribute('data-step') === n); });
    root.querySelectorAll('.tfg-steps li').forEach(function (li) {
      var s = +li.getAttribute('data-s'); li.classList.toggle('done', s < n); li.classList.toggle('on', s === n);
      if (s === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
    var h = root.querySelector('.tfg-step.on h1'); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
    window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    if (n === 3) prepareQr();
    if (n === 4) { var f = $('tfg-code').querySelector('input'); if (f) setTimeout(function () { f.focus(); }, 50); }
  }
  root.addEventListener('click', function (e) { var b = e.target.closest('[data-go]'); if (b && !b.disabled) go(+b.getAttribute('data-go')); });

  // Paso 3: pedir la clave nueva y dibujar el QR (una sola vez por visita)
  function prepareQr() {
    if (started) return; started = true;
    api('start').then(function (r) {
      $('tfg-secret').textContent = r.secret.replace(/(.{4})/g, '$1 ').trim();
      $('tfg-open').href = r.uri;
      var q = $('tfg-qr'); q.textContent = '';
      if (window.QRCode) new QRCode(q, { text: r.uri, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
      else q.textContent = 'No se pudo dibujar el QR: usa la clave de al lado.';
      $('tfg-scanned').disabled = false;
    }).catch(function (x) { started = false; $('tfg-err3').textContent = x.message; });
  }

  // Paso 4: seis casillas; se puede pegar el código entero
  var boxes = Array.prototype.slice.call($('tfg-code').querySelectorAll('input'));
  var code = function () { return boxes.map(function (b) { return b.value; }).join(''); };
  var sync = function () { $('tfg-confirm').disabled = !/^\d{6}$/.test(code()); };
  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      var d = b.value.replace(/\D/g, '');
      if (d.length > 1) { d.split('').slice(0, 6 - i).forEach(function (c, k) { boxes[i + k].value = c; }); (boxes[Math.min(i + d.length, 5)]).focus(); }
      else { b.value = d; if (d && boxes[i + 1]) boxes[i + 1].focus(); }
      $('tfg-err4').textContent = ''; sync();
      if (/^\d{6}$/.test(code())) confirm();
    });
    b.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !b.value && boxes[i - 1]) { boxes[i - 1].focus(); boxes[i - 1].value = ''; sync(); }
      if (e.key === 'Enter' && !$('tfg-confirm').disabled) confirm();
    });
    b.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6); if (!t) return;
      e.preventDefault(); t.split('').forEach(function (c, k) { if (boxes[k]) boxes[k].value = c; }); sync(); if (t.length === 6) confirm();
    });
  });
  var busy = false;
  function confirm() {
    if (busy) return; busy = true; $('tfg-confirm').disabled = true;
    api('confirm', { code: code() }).then(function (j) {
      codes = j.recovery || [];
      $('tfg-codes').innerHTML = codes.map(function (c) { return '<div>' + String(c).replace(/[&<>]/g, '') + '</div>'; }).join('');
      go(5);
    }).catch(function (x) {
      $('tfg-err4').textContent = x.message;
      boxes.forEach(function (b) { b.value = ''; }); boxes[0].focus();
    }).then(function () { busy = false; sync(); });
  }
  $('tfg-confirm').addEventListener('click', confirm);

  // Paso 5: copiar, descargar y confirmar que se guardaron
  var text = function () { return 'Códigos de respaldo · ' + document.title.split('·').pop().trim() + '\nCada uno sirve una sola vez.\n\n' + codes.join('\n') + '\n'; };
  $('tfg-copy').addEventListener('click', function () {
    var b = this;
    (navigator.clipboard ? navigator.clipboard.writeText(text()) : Promise.reject()).then(function () { b.innerHTML = '<i class="fa-solid fa-check"></i> Copiados'; }, function () { b.innerHTML = 'Selecciónalos y cópialos a mano'; });
  });
  $('tfg-dl').addEventListener('click', function () {
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text()], { type: 'text/plain' }));
    a.download = 'codigos-de-respaldo.txt'; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  });
  $('tfg-saved').addEventListener('change', function () { $('tfg-finish').disabled = !this.checked; });
  $('tfg-finish').addEventListener('click', function () { go(6); });

  // Si recarga a mitad de camino no se pierden los códigos: avisar antes de salir del paso 5 sin confirmar
  window.addEventListener('beforeunload', function (e) { if (cur === 5 && !$('tfg-saved').checked) { e.preventDefault(); e.returnValue = ''; } });

  go(1);
})();
