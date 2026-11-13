/* assets/password.js — password.php: cambia la propia contraseña con password_api.php. Al terminar vuelve al inicio. */
(function () {
  var f = document.getElementById('pw-form'); if (!f) return;
  var msg = document.getElementById('pw-msg'), go = document.getElementById('pw-go');
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    var cur = document.getElementById('pw-cur').value, nw = document.getElementById('pw-new').value, rep = document.getElementById('pw-rep').value;
    msg.className = 'pw-msg';
    if (nw !== rep) { msg.className = 'pw-msg bad'; msg.textContent = 'Las dos contraseñas nuevas no coinciden.'; return; }
    go.disabled = true; msg.textContent = 'Guardando…';
    fetch('/toolz/password_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-PW': '1' },
      body: JSON.stringify({ action: 'change', current: cur, new: nw, confirm: rep }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { msg.className = 'pw-msg bad'; msg.textContent = d.error; go.disabled = false; return; }
        msg.className = 'pw-msg ok'; msg.textContent = 'Listo: tu contraseña nueva ya está guardada.';
        setTimeout(function () { location.href = '/toolz/'; }, 1200);
      })
      .catch(function () { msg.className = 'pw-msg bad'; msg.textContent = 'No hay conexión con el servidor.'; go.disabled = false; });
  });
})();
