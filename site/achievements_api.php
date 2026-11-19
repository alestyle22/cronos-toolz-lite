<?php
/**
 * achievements_api.php — Logros (achievements_lib.php). POST JSON con la cabecera X-ACH: 1.
 *   check  → calcula, guarda los niveles nuevos y devuelve los que la persona todavía no vio (para el festejo)
 *   state  → la vitrina completa (logros.php la dibuja)
 */
require_once __DIR__ . '/auth.php';
$user = requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_ACH'] ?? '') !== '1') { http_response_code(400); echo '{"error":"Pedido inválido"}'; exit; }
require_once __DIR__ . '/achievements_lib.php';
if (is_file(__DIR__ . '/gamification.php')) require_once __DIR__ . '/gamification.php';
$db = getDB(); $uid = (int)$user['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
try {
    switch ($in['action'] ?? '') {
        case 'check':
            g7_ach_evaluate($db, $uid);
            echo json_encode(['ok' => true, 'new' => g7_ach_unseen($db, $uid)], JSON_UNESCAPED_UNICODE); break;
        case 'state':
            g7_ach_evaluate($db, $uid);
            g7_ach_unseen($db, $uid);   // abrir la vitrina cuenta como verlos
            echo json_encode(['ok' => true, 'items' => g7_ach_state($db, $uid), 'cats' => G7_ACH_CATS, 'tiers' => G7_ACH_TIERS], JSON_UNESCAPED_UNICODE); break;
        default: http_response_code(400); echo '{"error":"Acción desconocida"}';
    }
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => 'No se pudieron calcular los logros']); }
