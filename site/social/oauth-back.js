/* social/oauth-back.js — Return from Meta (oauth.php): if it went well, goes back to Social Studio after 1.2 s on its own.
   Navigating with JS from this page (same site) makes the Strict session cookie travel again. No inline code (CSP). */
(function () {
  var a = document.getElementById('v');
  if (a && a.getAttribute('data-auto')) setTimeout(function () { location.href = a.getAttribute('href'); }, 1200);
})();
