<?php
/**
 * recomendar/api.php — "Recomienda Cronos" de cada agencia (app.js). POST JSON {action} con la cabecera X-RC: 1.
 * Habla con el base (recomendar/lib.php › rc_call), con la clave de este sitio. Permisos del módulo 'recomendar':
 * view = ver lo ganado · edit = recomendar agencias · config = descontar o cobrar y datos de cobro. Doc: toolz/AFILIADOS.md
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function rc_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$me = getCurrentUser();
if (!$me) rc_out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
$uid = (int)$me['id'];
if (!g7_can($uid, 'recomendar')) rc_out(['error' => 'No tienes acceso a Recomienda Cronos.'], 403);
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !preg_match('#^application/json\s*(;|$)#i', $ct) || ($_SERVER['HTTP_X_RC'] ?? '') !== '1') rc_out(['error' => 'Pedido inválido.'], 400);
g7_origin_guard();
session_write_close();   // el base puede tardar: que no trabe otras pestañas
require_once __DIR__ . '/lib.php';
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$CAN = ['edit' => g7_can($uid, 'recomendar', 'edit'), 'config' => g7_can($uid, 'recomendar', 'config')];
$name = (string)(($me['display_name'] ?? '') ?: ($me['username'] ?? ''));
try {
    if (!rc_connected()) rc_out(['connected' => false, 'can' => $CAN]);
    switch ((string)($in['action'] ?? '')) {
        case 'me':
            $r = rc_call(['action' => 'me']);
            // La clave pública no se manda a la pantalla: el sobre se arma acá, en el servidor
            rc_out(['connected' => true, 'can' => $CAN, 'summary' => $r['summary'] ?? null, 'queued' => $r['queued'] ?? [], 'synced_at' => (int)($r['synced_at'] ?? 0), 'ready' => ($r['pub'] ?? '') !== '']);
        case 'lead':
            if (!$CAN['edit']) rc_out(['error' => 'No tienes permiso para recomendar agencias.'], 403);
            rc_call(rc_lead_payload($in, ['name' => $name]));
            g7_audit('recomendar.lead', '', ['agency' => rc_txt($in['agency'] ?? '', 160)], $uid);
            rc_out(['ok' => true]);
        case 'prefs':
            if (!$CAN['config']) rc_out(['error' => 'No tienes permiso para cambiar cómo se usa lo ganado.'], 403);
            $mode = (string)($in['mode'] ?? ''); if (!in_array($mode, ['descuento', 'cobro'], true)) rc_out(['error' => 'Elige descontar o cobrar.'], 400);
            $payout = '';
            if ($mode === 'cobro' && is_array($in['payout'] ?? null) && array_filter($in['payout'], fn($v) => trim((string)$v) !== '')) {
                $p = []; foreach (['method' => 20, 'holder' => 120, 'tax_id' => 40, 'bank' => 80, 'account' => 120, 'alias' => 120, 'email' => 190, 'wallet' => 120, 'phone' => 40, 'country' => 60, 'notes' => 400] as $k => $n) $p[$k] = rc_txt($in['payout'][$k] ?? '', $n);
                if ($p['holder'] === '') rc_out(['error' => 'Escribe el nombre del titular.'], 400);
                if (($p['account'] . $p['alias'] . $p['wallet'] . $p['email'] . $p['phone']) === '') rc_out(['error' => 'Completa la cuenta, el alias, el correo o la billetera donde cobras.'], 400);
                $pub = (string)(rc_call(['action' => 'me'])['pub'] ?? '');
                if ($pub === '') rc_out(['error' => 'El Cronos principal todavía no se conectó con el programa. Prueba de nuevo en unos minutos.'], 409);
                $payout = rc_seal(json_encode($p, JSON_UNESCAPED_UNICODE), $pub);
            }
            rc_call(['action' => 'prefs', 'key' => bin2hex(random_bytes(12)), 'mode' => $mode, 'payout' => $payout]);
            g7_audit('recomendar.prefs', '', ['mode' => $mode, 'payout' => $payout !== ''], $uid);   // nunca los datos en sí
            rc_out(['ok' => true]);
    }
    rc_out(['error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) { rc_out(['error' => $e->getMessage()], 502); }
catch (Throwable $e) { error_log('recomendar/api: ' . $e->getMessage()); rc_out(['error' => 'Algo salió mal. Prueba de nuevo.'], 500); }
