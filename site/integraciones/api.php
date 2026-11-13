<?php
/**
 * integraciones/api.php — Admin › Integraciones (admin_integraciones.js). Solo admins. JSON {action}, cabecera X-INT: 1.
 * Nunca devuelve una clave: los campos secretos llegan como {saved: true|false}. Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/gcal.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/arca.php';
require_once __DIR__ . '/portales.php';
require_once __DIR__ . '/youtube.php';
require_once __DIR__ . '/opencloud.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
if (($me['role'] ?? '') !== 'admin') $out(['error' => 'Solo los administradores pueden ver las integraciones.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_INT'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$db = getDB(); $uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? ''); $id = (string)($in['id'] ?? '');
$defs = int_defs();
if (in_array($act, ['save', 'test'], true) && !isset($defs[$id])) $out(['error' => 'Integración desconocida.'], 400);
if (!in_array($act, ['get', 'log', 'test'], true) && function_exists('lic_readonly') && lic_readonly()) $out(['error' => lic_readonly_msg()], 423);

$state = function () use ($defs, $uid) {
    $items = [];
    foreach ($defs as $k => $d) {
        $fields = []; foreach ($d['fields'] as $fk => $f) $fields[$fk] = array_intersect_key($f, array_flip(['label', 'type', 'options', 'placeholder', 'help', 'required', 'advanced']));
        $x = ['name' => $d['name'], 'icon' => $d['icon'], 'color' => $d['color'], 'desc' => $d['desc'], 'defs' => $fields,
            'module' => $d['module'] ?? '', 'module_on' => empty($d['module']) || int_module_on($d['module'])] + int_public($k);
        if ($k === 'google_calendar') { $x['extra'] = ['redirect_uri' => gc_redirect_uri(), 'origin' => int_site_url(), 'users' => count(array_filter((array)(int_cfg($k)['users'] ?? []), fn($u) => !empty($u['refresh_token']))), 'me' => gc_user_public($uid)]; }
        if ($k === 'portales' && $x['enabled']) $x['extra'] = ['feed_xml' => pt_feed_url('xml'), 'feed_json' => pt_feed_url('json')];
        if ($k === 'arca') $x['extra'] = ['ta' => isset(int_cfg($k)['ta']['expires']) ? int_cfg($k)['ta']['expires'] : '', 'facturacion' => function_exists('g7_site_has') && g7_site_has('clientes')];
        $items[$k] = $x;
    }
    return ['items' => $items, 'pending' => INT_PENDING, 'log' => int_log_read(null, 40), 'market' => int_market(), 'cats' => INT_CATS];
};
$test = function (string $id) use ($db, $uid): array {
    if (!int_ready($id)) return [false, 'Primero actívala y completa los datos obligatorios.'];
    try {
        switch ($id) {
            case 'google_calendar': return gc_test($uid);
            case 'whatsapp': return wa_test();
            case 'arca': return arca_test();
            case 'portales': return pt_test($db);
            case 'youtube': return yt_test();
            case 'opencloud': return oc_test();
        }
    } catch (RuntimeException $e) { return [false, $e->getMessage()]; }
    return [false, 'Sin prueba para esta integración.'];
};

try {
    if ($act === 'get') $out($state());
    if ($act === 'save') {
        int_save($id, ['fields' => (array)($in['fields'] ?? [])] + (array_key_exists('enabled', $in) ? ['enabled' => !empty($in['enabled'])] : []), array_map('strval', (array)($in['clear'] ?? [])));
        g7_audit('integraciones.save', $id);
        $out(['ok' => true] + $state());
    }
    if ($act === 'test') {
        [$ok, $msg] = $test($id);
        int_log($id, 'test', $ok, 'Probar conexión: ' . $msg);
        $out(['ok' => $ok, 'message' => int_scrub($msg)] + $state());
    }
    if ($act === 'wa_hello') {
        if (!int_ready('whatsapp')) $out(['error' => 'Primero activa WhatsApp y guarda sus datos.'], 400);
        $to = (string)($in['to'] ?? ''); if (wa_number($to) === '') $out(['error' => 'Escribe un número con código de país, por ejemplo +54 9 11 5555 0000.'], 400);
        [$ok, $msg] = wa_send_template($to, 'hello_world', 'en_US');
        $out(['ok' => $ok, 'message' => $ok ? 'Mensaje enviado. Debería llegar en unos segundos.' : 'No se envió: ' . $msg] + $state());
    }
    if ($act === 'feed_regen') { pt_feed_regen(); g7_audit('integraciones.feed_regen', 'portales'); $out(['ok' => true] + $state()); }
    if ($act === 'yt_refresh') { $r = yt_refresh($db); $out(['ok' => true, 'message' => "Se consultaron {$r['videos']} videos y se actualizaron {$r['updated']} entregables."] + $state()); }
    if ($act === 'arca_reset_ta') { int_store_update(function ($d) { unset($d['arca']['ta']); return $d; }); int_log('arca', 'wsaa', true, 'Se descartó el ticket de acceso guardado'); $out(['ok' => true] + $state()); }
    if ($act === 'log') $out(['log' => int_log_read(isset($defs[$id]) ? $id : null, 200)]);
    $out(['error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) { $out(['error' => int_scrub($e->getMessage())], 400); }
catch (Throwable $e) { error_log('[integraciones] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); $out(['error' => 'No se pudo completar. Prueba de nuevo.'], 500); }
