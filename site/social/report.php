<?php
/**
 * social/report.php?id=<id> — A Social Studio report as a printable page (it is saved as PDF from the browser's
 * print window). &pdf=1 downloads the server PDF (EvPdf), the same one that goes attached in the email. Needs the "reports" permission.
 * Doc: toolz/SOCIAL_STUDIO.md (Stage 5)
 */
require_once __DIR__ . '/../auth.php';
$me = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/report_lib.php';
ss_install($db);
try {
    $r = ss_rep_row($db, ss_ctx($db, (int)$me['id']), (int)($_GET['id'] ?? 0));
} catch (SsError $e) { http_response_code($e->getCode() ?: 404); header('Content-Type: text/plain; charset=utf-8'); exit($e->getMessage()); }
header('Cache-Control: no-store');
if (!empty($_GET['pdf'])) {
    $pdf = ss_rep_pdf($r);
    $file = preg_replace('/[^A-Za-z0-9_\-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $r['title']) ?: 'informe') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $file . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; exit;
}
echo ss_rep_html($r);
