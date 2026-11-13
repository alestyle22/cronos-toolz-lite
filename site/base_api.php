<?php
/**
 * base_api.php — Proxy del Admin › Red Cronos hacia el sitio base. Solo superadmin, con CSRF propio (bc_csrf) y solo en el sitio
 * que tiene 'base_url' y 'base_sso_secret' en _private/site.php. Firma cada pedido en el servidor (base_client_lib.php) y devuelve
 * la respuesta del base tal cual. Doc: toolz/BASE.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/base_client_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (int $code, string $msg) { http_response_code($code); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); exit; };

$me = getCurrentUser();
if (!$me) $out(401, 'Tu sesión venció: vuelve a entrar.');
if (!bc_configured()) $out(404, 'Este sitio no está conectado a un sitio base.');
if (!g7_is_super((int)$me['id'])) { g7_audit('super.denied', 'base_api', null, (int)$me['id']); $out(403, 'Solo el superadmin puede manejar el sitio base.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(405, 'Pedido inválido.');
if (!bc_csrf_ok((string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $out(403, 'La página quedó vieja: recargala.');
session_write_close();   // instalar una versión tarda: que no bloquee las otras pestañas de Toolz
@set_time_limit(0);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $out(400, 'Pedido inválido.');
$act = (string)($in['act'] ?? ''); $op = (string)($in['op'] ?? '');
if ($act !== '' ? !isset(BC_ACTS[$act]) || in_array($act, BC_SERVER_ONLY, true) : !isset(BC_OPS[$op])) $out(400, 'Pedido inválido.');

$q = getDB()->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) FROM g7_users WHERE id = ?"); $q->execute([(int)$me['id']]);
[$code, $body] = bc_call($in, (string)($q->fetchColumn() ?: $me['username']));   // el nombre que ve la agencia en las respuestas (la sesión solo trae el usuario)
if ($act === '' || !in_array($act, BC_READ, true))
    g7_audit('base.' . ($act ?: $op), (string)($in['site'] ?? $in['id'] ?? ''), ['code' => $code] + array_intersect_key($in, ['version' => 1, 'dir' => 1]), (int)$me['id']);
http_response_code($code);
echo $body;
