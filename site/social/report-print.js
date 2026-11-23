/* social/report-print.js — "Print or save as PDF" button of the report (report.php and social_report.php). No inline code (CSP). */
(function () {
  var b = document.getElementById('rp-print');
  if (b) b.addEventListener('click', function () { window.print(); });
})();
