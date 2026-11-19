<?php
/**
 * admin_base_tool.php?id=<herramienta>&what=html|cover — Admin › Red Cronos › Herramientas: muestra el HTML (aislado con CSP sandbox,
 * como links/ver.php) o la portada de una herramienta del catálogo del base. Solo superadmin, solo lectura (tool_get firmado). Doc: toolz/CATALOGO.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/base_client_lib.php';
$no = function (int $code, string $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($msg); };
$me = getCurrentUser();
if (!$me) $no(401, 'Tu sesión venció.');
if (!bc_configured() || !g7_is_super((int)$me['id'])) $no(403, 'Solo el superadmin.');
$id = (string)($_GET['id'] ?? ''); $what = (string)($_GET['what'] ?? 'html');
if (!preg_match('/^[a-z0-9-]{1,60}$/', $id)) $no(404, 'No existe.');
session_write_close();
[$code, $body] = bc_call(['act' => $what === 'cover' ? 'tool_cover' : 'tool_get', 'id' => $id], (string)$me['username']);   // la portada sola: la lista muestra muchas
$d = json_decode($body, true);
if ($code !== 200 || !is_array($d) || ($what !== 'cover' && empty($d['tool']))) $no(404, (string)($d['error'] ?? 'No se pudo traer la herramienta.'));
if ($what === 'cover') {
    $c = $d['cover'] ?? null; $raw = is_array($c) ? base64_decode((string)$c['data'], true) : false; $info = $raw ? @getimagesizefromstring($raw) : false;
    if (!$raw || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) $no(404, '');
    header('Content-Type: ' . $info['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
    echo $raw; exit;
}
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-downloads allow-modals');
header('Cache-Control: private, no-cache');
require_once __DIR__ . '/links/catalogo_lib.php';
echo cat_theme_html((string)$d['html'], (int)$me['id']);   // como la verá una agencia, con el estilo de Cronos
