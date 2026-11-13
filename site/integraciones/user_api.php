<?php
/**
 * integraciones/user_api.php — Lo de cada persona (Mi perfil): estado de su Google Calendar, sincronizar ahora y desconectar.
 * JSON {action}, cabecera X-INT: 1. Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/gcal.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_INT'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? ''); $uid = (int)$me['id'];
try {
    if ($act === 'gcal') $out(gc_user_public($uid));
    if ($act === 'gcal_sync') {
        if (!gc_user($uid)) $out(['error' => 'Primero conecta tu Google Calendar.'], 400);
        $r = gc_sync_user(getDB(), $uid); $out(['ok' => true, 'result' => $r] + gc_user_public($uid));
    }
    if ($act === 'gcal_disconnect') { gc_disconnect(getDB(), $uid); $out(['ok' => true] + gc_user_public($uid)); }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) { $out(['error' => $e->getMessage()], 400); }
catch (Throwable $e) { error_log('[integraciones] ' . $e->getMessage()); $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500); }
