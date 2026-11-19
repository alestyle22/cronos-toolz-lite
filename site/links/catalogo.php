<?php
/**
 * links/catalogo.php — Catálogo Cronos desde la Biblioteca. Pide el permiso "Agregar del catálogo" (links.catalog; los admins lo tienen).
 *   GET  ?cover=<id>&f=<archivo>   portada de una herramienta (se guarda en _private/catalogo_cache/ la primera vez)
 *   GET  ?preview=<id>             la herramienta aislada (CSP sandbox, igual que ver.php), para probarla antes de agregarla
 *   POST {action:'list'}           el catálogo con lo que ya hay acá
 *   POST {action:'add', id, category_id}   la copia a la Biblioteca
 *   POST {action:'update', link_id}        la pasa a la última versión
 * Doc: toolz/CATALOGO.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/catalogo_lib.php';
$uid = (int)$currentUser['id'];
$can = g7_can($uid, 'links', 'catalog');
$plain = function (int $code, string $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($msg); };

if (isset($_GET['cover'])) {
    if (!$can) $plain(403, '');
    $id = (string)$_GET['cover']; $f = (string)($_GET['f'] ?? '');
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $id) || !cat_cover_ok($f) || strpos($f, $id . '_') !== 0) $plain(404, '');
    $dir = cat_cache_dir(); $path = $dir . $f;
    if (!is_file($path)) {
        session_write_close();
        try { $r = cat_call(['action' => 'cover', 'id' => $id], 20); } catch (Throwable $e) { $plain(404, ''); }
        $raw = base64_decode((string)($r['cover']['data'] ?? ''), true); $info = $raw ? @getimagesizefromstring($raw) : false;
        if (!$raw || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) $plain(404, '');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($path, $raw);
    }
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($f, PATHINFO_EXTENSION)];
    header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=86400');
    readfile($path); exit;
}
if (isset($_GET['preview'])) {
    if (!$can) $plain(403, 'No tenés permiso para ver el catálogo.');
    $id = (string)$_GET['preview'];
    if (!preg_match('/^[a-z0-9-]{1,60}$/', $id)) $plain(404, 'Esa herramienta no existe.');
    session_write_close();
    try { $p = cat_fetch($id); } catch (Throwable $e) { $plain(409, $e->getMessage()); }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-downloads allow-modals');
    header('Cache-Control: private, no-cache');
    echo cat_theme_html((string)$p['html'], $uid); exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['error' => 'Pedido inválido.'], 405);
if (!$can) $out(['error' => 'No tenés permiso para agregar herramientas del catálogo. Lo da un administrador en Admin › Módulos › Biblioteca.'], 403);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
session_write_close();
$db = getDB();
try {
    switch ((string)($in['action'] ?? '')) {
        case 'list':
            $out(cat_list($db) + ['categories' => $db->query('SELECT id, name FROM g7_categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC)]);
        case 'add':
            $id = (string)($in['id'] ?? ''); if (!preg_match('/^[a-z0-9-]{1,60}$/', $id)) $out(['error' => 'Esa herramienta no existe.'], 400);
            $lid = cat_add($db, $id, (int)($in['category_id'] ?? 0), $uid);
            g7_audit('links.catalog_add', $id, ['link' => $lid], $uid);
            $out(['ok' => true, 'link_id' => $lid]);
        case 'update':
            $r = cat_update($db, (int)($in['link_id'] ?? 0));
            if ($r['changed']) g7_audit('links.catalog_update', (string)(int)$in['link_id'], ['ver' => $r['ver']], $uid);
            $out(['ok' => true] + $r);
        default: $out(['error' => 'Acción desconocida.'], 400);
    }
} catch (RuntimeException $e) { $out(['error' => $e->getMessage()], 409); }
catch (Throwable $e) { error_log('catalogo: ' . $e->getMessage()); $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500); }
