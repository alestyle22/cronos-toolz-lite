<?php
/**
 * social_report.php?t=<token> — Social Studio report for the client, without a session: the token of the link is the permission
 * (it is created when sending it or with "Online link", and it is revoked from Reports). Outside social/ on purpose (the client does not
 * enter the module). It shows the frozen snapshot of the report, with the date of the data. Only "Print or save as PDF".
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/social/report_lib.php';
ss_install($db);
header('Cache-Control: private, no-store'); header('X-Robots-Tag: noindex'); header('Referrer-Policy: no-referrer');
$t = (string)($_GET['t'] ?? '');
$r = null;
if (preg_match('/^[a-f0-9]{40}$/', $t)) { $q = $db->prepare('SELECT * FROM g7_ss_reports WHERE token = ?'); $q->execute([$t]); $r = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
if (!$r) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Este informe no existe o el enlace ya no está activo. Pedile a la agencia uno nuevo.'); }
echo ss_rep_html($r, true);
