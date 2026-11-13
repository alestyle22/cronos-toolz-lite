<?php
/**
 * integraciones/opencloud_api.php — OpenCloud desde la pantalla (assets/opencloud.js). JSON {action}, cabecera X-INT: 1.
 * Elegir y adjuntar: status, list, link (rige "Quién puede adjuntar" de Admin › Integraciones).
 * Carpeta del cliente: client_get, client_list, client_create, client_set, client_unlink, client_share (hace falta el permiso de Clientes).
 * Todas las rutas son relativas a la "Carpeta de Toolz" y no pueden salir de ella (oc_clean). Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/opencloud.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: volvé a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_INT'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
session_write_close();
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? ''); $uid = (int)$me['id'];
$db = getDB();

if ($act === 'status') {
    $c = oc_cfg();
    $out(['on' => oc_ready(), 'can' => oc_can_use($me), 'clients' => oc_ready() && g7_can($uid, 'clientes'), 'link_mode' => $c['link_mode'], 'root' => basename(oc_root()) ?: 'OpenCloud',
        'server' => oc_ready() ? (string)parse_url((string)$c['url'], PHP_URL_HOST) : '']);
}
if (!oc_ready()) $out(['error' => 'OpenCloud no está conectado. Lo configura un administrador en Admin › Integraciones.'], 409);

/** Cliente que existe (o corta) con su carpeta vinculada. */
$client = function () use ($db, $in, $uid, $out): array {
    if (!g7_can($uid, 'clientes')) $out(['error' => 'No tenés acceso a Clientes.'], 403);
    $q = $db->prepare('SELECT id, name FROM g7_clients WHERE id = ?'); $q->execute([(int)($in['client_id'] ?? 0)]);
    $c = $q->fetch(PDO::FETCH_ASSOC); if (!$c) $out(['error' => 'Ese cliente no existe.'], 404);
    return ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'folder' => oc_client_folder($db, (int)$c['id'])];
};
/** Ruta dentro de la carpeta del cliente (sub relativa a ella): no puede salir de la carpeta del cliente. */
$inside = fn(string $folder, string $sub) => rtrim(oc_clean($folder), '/') . (oc_clean($sub) === '/' ? '' : oc_clean($sub));
$pub = fn(array $f, ?array $cl = null) => ['path' => $f['path'], 'public_url' => (string)($f['public_url'] ?? ''), 'updated_at' => (string)($f['updated_at'] ?? '')];

try {
    switch ($act) {
    case 'list':
        if (!oc_can_use($me)) $out(['error' => 'Solo los administradores pueden adjuntar desde OpenCloud.'], 403);
        $out(oc_list((string)($in['path'] ?? '/')));
    case 'link':
        if (!oc_can_use($me)) $out(['error' => 'Solo los administradores pueden adjuntar desde OpenCloud.'], 403);
        $public = array_key_exists('public', $in) ? !empty($in['public']) : oc_cfg()['link_mode'] === 'public';
        $l = oc_link((string)($in['path'] ?? ''), $public);
        int_log('opencloud', 'link', true, ($public ? 'Link público' : 'Link interno') . ' de ' . $l['path']);
        $out(['url' => $l['url'], 'public' => $l['public'], 'name' => $l['name'], 'dir' => $l['dir'], 'size' => $l['size'], 'mime' => $l['mime'], 'path' => $l['path']]);

    // ── Carpeta del cliente ──
    case 'client_get':
        $c = $client();
        $sug = '/' . trim((string)(oc_cfg()['clients_dir'] ?: 'Clientes'), '/') . '/' . oc_folder_name($c['name']);
        if (!$c['folder']) $out(['folder' => null, 'suggest' => oc_clean($sug), 'can_pick' => oc_can_use($me)]);
        $st = oc_stat($c['folder']['path']);
        $out(['folder' => $pub($c['folder']) + ['exists' => (bool)$st, 'private_url' => $st ? (oc_same_host($st['privatelink']) ? $st['privatelink'] : '') : ''], 'suggest' => oc_clean($sug), 'can_pick' => oc_can_use($me)]
            + ($st ? ['list' => oc_list($c['folder']['path'])] : []));
    case 'client_list':
        $c = $client(); if (!$c['folder']) $out(['error' => 'Este cliente no tiene carpeta vinculada.'], 400);
        $l = oc_list($inside($c['folder']['path'], (string)($in['sub'] ?? '/')));
        $out($l + ['sub' => substr($l['path'], strlen(rtrim(oc_clean($c['folder']['path']), '/'))) ?: '/']);
    case 'client_create':
        $c = $client();
        $path = '/' . trim((string)(oc_cfg()['clients_dir'] ?: 'Clientes'), '/') . '/' . oc_folder_name($c['name']);
        oc_mkdir($path); oc_client_set($db, $c['id'], $path, $uid);
        int_log('opencloud', 'client', true, 'Carpeta creada para el cliente ' . $c['id'] . ': ' . oc_clean($path));
        g7_audit('opencloud.client_folder', 'cliente ' . $c['id'] . ' · ' . oc_clean($path));
        $out(['ok' => true, 'path' => oc_clean($path)]);
    case 'client_set':
        $c = $client();
        if (!oc_can_use($me)) $out(['error' => 'Solo los administradores pueden elegir carpetas de OpenCloud.'], 403);
        $path = oc_clean((string)($in['path'] ?? ''));
        $st = oc_stat($path);
        if (!$st || !$st['dir']) $out(['error' => 'Elegí una carpeta que exista en OpenCloud.'], 400);
        oc_client_set($db, $c['id'], $path, $uid);
        g7_audit('opencloud.client_folder', 'cliente ' . $c['id'] . ' · ' . $path);
        $out(['ok' => true, 'path' => $path]);
    case 'client_unlink':
        $c = $client(); oc_client_set($db, $c['id'], null, $uid);
        g7_audit('opencloud.client_unlink', 'cliente ' . $c['id']);
        $out(['ok' => true]);
    case 'client_share':
        $c = $client(); if (!$c['folder']) $out(['error' => 'Este cliente no tiene carpeta vinculada.'], 400);
        $l = oc_link($c['folder']['path'], true);
        oc_client_set($db, $c['id'], $c['folder']['path'], $uid, $l['url']);
        int_log('opencloud', 'link', true, 'Link público de la carpeta del cliente ' . $c['id']);
        $out(['ok' => true, 'url' => $l['url']]);
    }
    $out(['error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) { int_log('opencloud', $act, false, $e->getMessage()); $out(['error' => int_scrub($e->getMessage())], 400); }
catch (Throwable $e) { error_log('[opencloud] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); $out(['error' => 'No se pudo completar. Probá de nuevo.'], 500); }
