<?php
/** presence_api.php — Cambiar mi estado y mi insignia (presence_lib.php). POST JSON con X-Presence: 1, siempre sobre uno mismo. */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/presence_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$me = getCurrentUser();
if (!$me) { http_response_code(401); exit(json_encode(['error' => 'Tu sesión venció: vuelve a entrar'])); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_PRESENCE'] ?? '') !== '1') { http_response_code(403); exit(json_encode(['error' => 'Pedido inválido'])); }
session_write_close();
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$db = getDB(); $uid = (int)$me['id'];
$out = ['ok' => true];
if (isset($in['key'])) {
    if (!isset(G7_PRESENCE[(string)$in['key']])) { http_response_code(400); exit(json_encode(['error' => 'Estado inválido'])); }
    $out['status'] = pr_set($db, $uid, (string)$in['key'], (string)($in['text'] ?? ''), isset($in['min']) ? (int)$in['min'] : null);
}
if (isset($in['badge'])) pr_badge_set($db, $uid, (string)$in['badge']);
$out['badges'] = (object)pr_badges($db);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
