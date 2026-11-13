<?php
/**
 * soporte/api.php — Reportes de errores, sugerencias y consultas de plan: los manda al Cronos principal (sitio base) como ticket.
 * Habla con {central_url}/tickets_api.php con la clave de este sitio (_private/site.php). Cada persona ve solo sus tickets.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$me = getCurrentUser();
if (!$me) { http_response_code(401); echo '{"error":"La sesión venció: recarga la página."}'; exit; }
require_once __DIR__ . '/../_update/lib.php';
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
function sp_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$c = u_cfg();
if (!$c['central_url'] || !$c['secret'] || !$c['id']) sp_out(['error' => 'Este sitio todavía no está conectado al Cronos principal. Avisale a un administrador.'], 409);
$user = ['id' => (int)$me['id'], 'name' => (string)(($me['display_name'] ?? '') ?: $me['username']), 'email' => (string)($me['email'] ?? '')];
if ($user['email'] === '') { try { $st = getDB()->prepare('SELECT email, display_name FROM g7_users WHERE id = ?'); $st->execute([$user['id']]); $r = $st->fetch(); if ($r) { $user['email'] = (string)$r['email']; $user['name'] = (string)($r['display_name'] ?: $user['name']); } } catch (Throwable $e) {} }

$act = (string)($in['action'] ?? '');
$payload = ['action' => $act, 'user' => $user];
if ($act === 'new') {
    $imgs = array_values(array_filter(array_slice((array)($in['images'] ?? []), 0, 3), fn($x) => is_string($x) && strlen($x) < 6 * 1048576));
    $payload += ['type' => (string)($in['type'] ?? ''), 'title' => mb_substr((string)($in['title'] ?? ''), 0, 150), 'body' => mb_substr((string)($in['body'] ?? ''), 0, 6000),
        'priority' => ($in['blocking'] ?? false) ? 'alta' : 'media', 'images' => $imgs,
        'meta' => ['url' => (preg_match('#^https?://#i', $spU = mb_substr(trim((string)($in['url'] ?? '')), 0, 300)) ? $spU : ''), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), 'version' => (string)u_state()['version'], 'ip' => '']];
} elseif ($act === 'reply') $payload += ['id' => (int)($in['id'] ?? 0), 'text' => mb_substr((string)($in['text'] ?? ''), 0, 4000)];
elseif ($act !== 'mine') sp_out(['error' => 'Acción desconocida.'], 400);

try { [$code, $body] = u_post(rtrim($c['central_url'], '/') . '/tickets_api.php', $c['id'], $c['secret'], $payload, 40); }
catch (Throwable $e) { sp_out(['error' => 'No pudimos llegar al Cronos principal. Prueba de nuevo en un rato.'], 502); }
$d = json_decode($body, true);
if (!is_array($d)) sp_out(['error' => $code === 403 ? 'El Cronos principal no reconoce este sitio. Avisale a un administrador.' : 'El Cronos principal no respondió bien. Prueba de nuevo en un rato.'], 502);
sp_out($d, isset($d['error']) ? 409 : 200);
