<?php
/**
 * trabajo/portal_file.php — Entrega los archivos del Portal de Solicitudes, siempre después de mirar el permiso.
 *   ?f=ID   adjunto de una solicitud (quien la pidió, quien la atiende o un colaborador; los internos solo quien atiende)
 *   ?img=X  banner de un departamento o imagen de una categoría (cualquier persona con sesión)
 * Los archivos viven en _private/solicitudes/ (Require all denied): nunca se sirven directo.
 */
require_once __DIR__ . '/../auth.php';
$me = getCurrentUser();
if (!$me) { http_response_code(401); exit('Sesión vencida'); }
$db = getDB();
require_once __DIR__ . '/lib.php';
session_write_close();
$dir = defined('G7_PT_DIR') ? G7_PT_DIR : __DIR__ . '/../_private/solicitudes';
header('X-Content-Type-Options: nosniff');

if (isset($_GET['img'])) {
    $n = (string)$_GET['img'];
    if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp)$/', $n) || !is_file("$dir/img/$n")) { http_response_code(404); exit; }
    header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($n, PATHINFO_EXTENSION)]);
    header('Cache-Control: private, max-age=86400');
    readfile("$dir/img/$n"); exit;
}

$id = (int)($_GET['f'] ?? 0);
$q = $db->prepare('SELECT * FROM g7_wk_tk_files WHERE id = ? AND ticket_id IS NOT NULL'); $q->execute([$id]); $f = $q->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('No existe'); }
$t = wk_tk_row($db, (int)$f['ticket_id']);
$ctx = wk_ctx($db, (int)$me['id']);
$agent = $t && (wk_is_agent($ctx, $t['team_id'] ? (int)$t['team_id'] : null) || (int)$t['assignee_id'] === (int)$me['id'] || (int)$t['approver_id'] === (int)$me['id']);
$req = $t && (int)$t['requester_id'] === (int)$me['id'];
$collab = $t && !$agent && function_exists('wk_tkc_is') && wk_tkc_is($db, (int)$t['id'], (int)$me['id']);
if (!$t || (!$agent && !$req && !$collab) || ((int)$f['internal'] && !$agent)) { http_response_code(404); exit('No existe'); }
$path = "$dir/" . (int)$f['ticket_id'] . '/' . $f['token'] . '.' . $f['ext'];
if (!is_file($path)) { http_response_code(404); exit('El archivo ya no está'); }
$inline = in_array($f['ext'], ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'], true) && !isset($_GET['dl']);
$mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'][$f['ext']] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
header('Cache-Control: private, no-store');
readfile($path);
