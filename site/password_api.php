<?php
/**
 * password_api.php — Cambiar la propia contraseña (Mi perfil y password.php). JSON por POST con cabecera X-PW: 1.
 * Acciones: state (cuántos días le quedan) · change {current, new, confirm}. Doc: toolz/SEGURIDAD_2FA.md (Contraseñas)
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/pass_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_PW'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$db = getDB(); $uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
if ($act === 'state') $out(['days' => g7_pass_days(), 'left' => g7_pass_days_left($db, $uid), 'min' => g7_pass_min()]);
if ($act === 'change') {
    $why = g7_pass_change($db, $uid, (string)($in['current'] ?? ''), (string)($in['new'] ?? ''), (string)($in['confirm'] ?? ''));
    if ($why !== null) $out(['error' => $why], 422);
    // Con la contraseña nueva, las sesiones abiertas en otros dispositivos se cierran (Mi perfil › Dónde iniciaste sesión)
    $closed = 0;
    if (is_file(__DIR__ . '/login_alert_lib.php')) { require_once __DIR__ . '/login_alert_lib.php'; try { $closed = g7_lh_close_others($db, $uid, (string)($_SESSION['g7_login_sid'] ?? ''), 'password'); g7_td_revoke($db, $uid); } catch (Throwable $e) {} }
    $out(['ok' => true, 'left' => g7_pass_days_left($db, $uid), 'closed' => $closed]);
}
$out(['error' => 'Acción desconocida.'], 400);
