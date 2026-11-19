/* trabajo/informes.js — Trabajo › Reports (informes.php): changing department or board applies the filter right away.
   The rest (tabs, charts, list, export) is assets/stats-jira.js, the same as in Statistics › Jira. No inline code (CSP). */
(function () {
  document.querySelectorAll('.wr-auto').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
})();
