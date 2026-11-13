/* integraciones/google-oauth.js — Vuelta de Google Calendar (google_oauth.php): si se conectó, vuelve solo al perfil a los 1,5 s.
   Navegar con JS desde esta página (mismo sitio) hace que la cookie Strict vuelva a viajar. Antes era código en línea (CSP estricta, SEC-24). */
(function () {
  var a = document.getElementById('v');
  if (a && a.getAttribute('data-auto')) setTimeout(function () { location.href = a.getAttribute('href'); }, 1500);
})();
