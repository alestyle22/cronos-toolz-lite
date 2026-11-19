<?php
/**
 * app_api.php — Lo que usa la app de Android (toolz/android, APP_ANDROID.md). Todo lo demás la app lo muestra con el sitio mismo.
 *   GET ?action=info            Público. Dice "esto es un Cronos" y devuelve nombre y logo de la agencia (pantalla "URL de la agencia").
 *   GET ?action=feed&since=ID   Con sesión. Avisos de la campana sin leer con id > since, con título y destino ya armados,
 *                               para que la app los muestre como notificaciones de Android. since=0 solo devuelve last_id
 *                               (la primera vez no se repiten avisos viejos).
 *   GET ?action=open&id=ID      Con sesión. Marca ese aviso como leído y lleva a su página (lo abre la app al tocar la notificación).
 * Los títulos y destinos son los mismos de assets/topbar.js (notifText y clickNotif): si se suma un tipo allá, sumarlo acá.
 */
require_once __DIR__ . '/auth.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const G7_APP_API = 1;   // sube cuando cambia lo que la app puede esperar de este archivo

$act = (string)($_GET['action'] ?? '');

function g7_app_json(array $out, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/app_notif_lib.php';

// ── Público: ¿es un Cronos? ──
if ($act === 'info') {
    header('Access-Control-Allow-Origin: *');   // datos públicos de la marca; nada de la sesión
    g7_app_json(['app' => 'cronos-toolz', 'api' => G7_APP_API, 'name' => (string)g7_site('name'), 'short' => (string)g7_site('short'),
        'logo' => (string)(g7_site('logo') ?: g7_site('favicon') ?: ''), 'home' => '/toolz/', 'login' => '/toolz/login.php']);
}

$user = getCurrentUser();
if (!$user) g7_app_json(['error' => 'login'], 401);
$uid = (int)$user['id'];
$db  = getDB();

if ($act === 'feed') {
    // Los mismos recordatorios que corre notif.php al consultar la campana: así llegan aunque nadie tenga el sitio abierto.
    if (is_file(__DIR__ . '/tareas_lib.php')) { try { require_once __DIR__ . '/tareas_lib.php'; tk_run_reminders($db); } catch (Throwable $e) {} }
    if (is_file(__DIR__ . '/calendario/lib.php') && (g7_modules()['calendario']['mode'] ?? 'off') !== 'off') { try { require_once __DIR__ . '/calendario/lib.php'; cal_run_reminders($db, $uid); } catch (Throwable $e) {} }
    if (is_file(__DIR__ . '/bienestar/lib.php') && g7_can($uid, 'bienestar')) { try { require_once __DIR__ . '/bienestar/lib.php'; bn_run_user($db, $uid); } catch (Throwable $e) {} }

    $since = max(0, (int)($_GET['since'] ?? 0));
    $q = $db->prepare('SELECT COALESCE(MAX(id),0) m, SUM(read_at IS NULL) u FROM g7_notifications WHERE user_id = ?');
    $q->execute([$uid]); $agg = $q->fetch();
    $out = ['last_id' => (int)$agg['m'], 'unread' => (int)$agg['u'], 'user' => (string)($user['display_name'] ?? $user['username'] ?? ''), 'items' => []];
    if ($since > 0) {
        $s = $db->prepare('SELECT n.id, n.type, n.from_id, n.target_id, n.profile_id, n.body, n.created_at, u.username, u.display_name
            FROM g7_notifications n LEFT JOIN g7_users u ON u.id = n.from_id
            WHERE n.user_id = ? AND n.id > ? AND n.read_at IS NULL ORDER BY n.id ASC LIMIT 20');
        $s->execute([$uid, $since]);
        foreach ($s->fetchAll() as $n) $out['items'][] = ['id' => (int)$n['id'], 'type' => (string)$n['type'], 'title' => g7_app_title($n),
            'body' => mb_substr((string)$n['body'], 0, 240), 'url' => 'app_api.php?action=open&id=' . (int)$n['id'], 'created_at' => (string)$n['created_at']];
    }
    g7_app_json($out);
}

if ($act === 'open') {
    $id = (int)($_GET['id'] ?? 0);
    $s = $db->prepare('SELECT id, type, target_id, profile_id FROM g7_notifications WHERE id = ? AND user_id = ?');
    $s->execute([$id, $uid]); $n = $s->fetch();
    if (!$n) { header('Location: /toolz/'); exit; }
    $db->prepare('UPDATE g7_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([$id, $uid]);
    header('Location: ' . g7_app_target($n));
    exit;
}

g7_app_json(['error' => 'unknown action'], 400);
