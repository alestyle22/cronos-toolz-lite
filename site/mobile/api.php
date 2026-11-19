<?php
/**
 * Cronos Mobile — API de la app nativa (toolz/cronos-mobile). Documentación: toolz/docs/mobile/API_INTEGRATION_MAP.md
 *
 * Todo es JSON. Lo que no es de esta API (chat, tickets, tareas, comunidad, bienestar, calendario) la app lo pide
 * directo a las APIs que ya usa la web, con la misma sesión: acá solo está lo que faltaba para un cliente nativo.
 *
 *   POST ?action=login        {username, password, device, app_version}  → {need_2fa} | {device_token, me}
 *   POST ?action=login_2fa    {code, remember, device, app_version}      → {device_token, me}
 *   POST ?action=refresh      cabecera X-Cronos-Device                   → {me}   (reabre la sesión PHP)
 *   POST ?action=logout       (cierra la sesión y anula el token)
 *   GET  ?action=me           → perfil, sitio (zona horaria) y módulos habilitados
 *   GET  ?action=home         → resumen del Inicio
 *   GET  ?action=pulse        {since_msg, since_notif, since_chnotif} → novedades para las notificaciones en segundo plano
 *   GET  ?action=devices      → teléfonos con la app abiertos por esta persona
 *   POST ?action=device_revoke {id}
 */
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/lib.php';

$action = (string)($_GET['action'] ?? (mb_body()['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$writes = ['login', 'login_2fa', 'refresh', 'logout', 'device_revoke', 'push_register', 'notif_read'];
if (in_array($action, $writes, true) && $method !== 'POST') mb_fail('Usá POST.', 405);
// Defensa CSRF extra para lo que escribe: un formulario de otro sitio no puede mandar esta cabecera
if ($method === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') mb_fail('Pedido inválido.', 400);

$db = getDB();
mb_install($db);

switch ($action) {
    case 'info':
        mb_out(['app' => 'cronos-toolz', 'api' => MB_API_VERSION, 'name' => (string)(g7_setting('platform_name') ?: g7_site('name')),
                'logo' => mb_url((string)(trim((string)g7_cfg('logo_url', '')) ?: g7_site('logo')))]);

    case 'login': {
        $b = mb_body();
        $u = trim((string)($b['username'] ?? '')); $p = (string)($b['password'] ?? '');
        if ($u === '' || $p === '') mb_fail('Completá usuario y contraseña.');
        $r = login($u, $p);
        if (is_array($r) && !empty($r['need_2fa'])) mb_out(['need_2fa' => true]);
        if (is_array($r) && !empty($r['locked'])) mb_fail('Tu cuenta está bloqueada por seguridad. Para desbloquearla, usá el enlace que te mandamos por email o pedíselo a un administrador.', 403, ['reason' => 'locked']);
        if (is_array($r) && !empty($r['blocked'])) mb_fail('No se puede entrar desde esta conexión. Si es un error, avisá a un administrador.', 403, ['reason' => 'blocked']);
        if (!$r) {
            $wait = g7_login_locked($u);
            mb_fail($wait > 0 ? "Demasiados intentos fallidos. Probá de nuevo en $wait " . ($wait === 1 ? 'minuto' : 'minutos') . '.' : 'Usuario o contraseña incorrectos.', 400, ['reason' => $wait > 0 ? 'rate' : 'credentials']);
        }
        $tok = mb_issue_token($db, (int)$r['id'], (string)($b['device'] ?? ''), (string)($b['app_version'] ?? ''));
        mb_out(['device_token' => $tok, 'me' => mb_me($db, (int)$r['id'])]);
    }

    case 'login_2fa': {
        $b = mb_body();
        $r = g7_login_2fa(trim((string)($b['code'] ?? '')), !empty($b['remember']));
        if (!is_array($r)) {
            $msg = ['expired' => 'Pasaron más de 5 minutos. Volvé a escribir tu usuario y contraseña.',
                    'locked' => 'Demasiados códigos incorrectos. Probá de nuevo en 15 minutos.',
                    'locked_account' => 'Tu cuenta está bloqueada por seguridad.',
                    'blocked' => 'No se puede entrar desde esta conexión.'][$r] ?? 'El código no es correcto. Revisá la hora del teléfono o usá un código de respaldo.';
            mb_fail($msg, 400, ['reason' => is_string($r) ? $r : 'code']);
        }
        $tok = mb_issue_token($db, (int)$r['id'], (string)($b['device'] ?? ''), (string)($b['app_version'] ?? ''));
        mb_out(['device_token' => $tok, 'me' => mb_me($db, (int)$r['id'])]);
    }

    case 'refresh': {
        [$ok, $res] = mb_refresh($db, mb_header_token());
        if (!$ok) {
            $why = (string)$res;
            $msg = str_starts_with($why, 'ended:') ? ([
                'ended:password' => 'Tu sesión se cerró porque se cambió la contraseña de la cuenta.',
                'ended:admin' => 'Un administrador cerró tu sesión.',
                'ended:locked' => 'Tu cuenta quedó bloqueada y se cerraron todas las sesiones.',
                'ended:logout' => 'Cerraste la sesión.',
            ][$why] ?? 'Tu sesión se cerró desde otro dispositivo.') : ([
                'inactive' => 'Tu usuario está desactivado.',
                'locked' => 'Tu cuenta está bloqueada por seguridad.',
                'blocked' => 'No se puede entrar desde esta conexión.',
            ][$why] ?? 'Tu sesión venció. Volvé a entrar.');
            mb_fail($msg, 401, ['reason' => $why, 'login' => true]);
        }
        mb_out(['me' => mb_me($db, (int)$res['id'])]);
    }

    case 'logout': {
        $row = mb_find_token($db, mb_header_token());
        if ($row) mb_revoke($db, (int)$row['id'], 'logout');
        startSecureSession();
        if (!empty($_SESSION['g7_user_id']) && is_file(dirname(__DIR__) . '/login_alert_lib.php')) { require_once dirname(__DIR__) . '/login_alert_lib.php'; g7_lh_close_current('logout'); }
        $_SESSION = [];
        session_destroy();
        mb_out([]);
    }
}

// ── Desde acá, todo necesita sesión ─────────────────────────────────────────
$user = getCurrentUser();
if (!$user) mb_fail('login', 401, ['login' => true]);
$uid = (int)$user['id'];
session_write_close();   // las lecturas no bloquean otros pedidos de la misma sesión (chat)

switch ($action) {
    case 'me':
        mb_out(mb_me($db, $uid));

    case 'home':
        require_once __DIR__ . '/home.php';
        mb_out(mb_home($db, $uid));

    case 'pulse':
        require_once __DIR__ . '/pulse.php';
        mb_out(mb_pulse($db, $uid, (int)($_GET['since_msg'] ?? 0), (int)($_GET['since_notif'] ?? 0), (int)($_GET['since_chnotif'] ?? 0)));

    case 'work_meta': {
        // Lo mismo que trabajo/api.php boot necesita la app (personas, estados, prioridades, rol), sin los efectos de ese
        // boot (barrido de SLA y sincronización con el sitio base), que pueden tardar varios segundos.
        if (!g7_can($uid, 'tareas')) mb_fail('No tenés acceso a Gestión de trabajo.', 403);
        require_once dirname(__DIR__) . '/trabajo/lib.php';
        wk_install($db);
        $ctx = wk_ctx($db, $uid);
        $cfg = tk_config($db);
        mb_out(['me' => ['id' => $uid, 'role' => $ctx['role'], 'teams' => $ctx['teams'], 'lead' => $ctx['lead']],
            'admin' => $ctx['role'] === 'admin', 'agent' => $ctx['all'] || (bool)$ctx['teams'], 'users' => wk_users_list($db),
            'config' => ['statuses' => $cfg['statuses'], 'priorities' => $cfg['priorities'], 'labels' => $cfg['labels'] ?? []],
            'tk_status' => wk_st('tickets')]);
    }

    case 'notifications': {
        // Centro de notificaciones: la campana paginada, con título, categoría y destino ya resueltos
        require_once dirname(__DIR__) . '/app_notif_lib.php';
        $before = (int)($_GET['before'] ?? 0);
        $unreadOnly = !empty($_GET['unread']);
        $sql = 'SELECT n.id, n.type, n.from_id, n.target_id, n.profile_id, n.body, n.read_at, n.created_at, u.username, u.display_name, u.avatar
            FROM g7_notifications n LEFT JOIN g7_users u ON u.id = n.from_id WHERE n.user_id = ?' . ($before > 0 ? ' AND n.id < ' . $before : '') . ($unreadOnly ? ' AND n.read_at IS NULL' : '') . ' ORDER BY n.id DESC LIMIT 31';
        $q = $db->prepare($sql); $q->execute([$uid]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > 30; $rows = array_slice($rows, 0, 30);
        $u = $db->prepare('SELECT COUNT(*) FROM g7_notifications WHERE user_id = ? AND read_at IS NULL'); $u->execute([$uid]);
        mb_out(['unread' => (int)$u->fetchColumn(), 'more' => $more, 'items' => array_map(fn($n) => [
            'id' => (int)$n['id'], 'type' => (string)$n['type'], 'category' => mb_notif_category((string)$n['type']), 'title' => g7_app_title($n),
            'body' => (string)$n['body'], 'target_id' => (int)$n['target_id'], 'profile_id' => (int)$n['profile_id'],
            'from' => $n['from_id'] ? ['id' => (int)$n['from_id'], 'name' => (string)($n['display_name'] ?: $n['username']), 'avatar' => mb_url($n['avatar'] ?? '')] : null,
            'read' => $n['read_at'] !== null, 'created_at' => $n['created_at'], 'web' => g7_app_target($n)], $rows)]);
    }

    case 'notif_read': {
        $b = mb_body();
        if (!empty($b['all'])) $db->prepare('UPDATE g7_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$uid]);
        else $db->prepare('UPDATE g7_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL')->execute([(int)($b['id'] ?? 0), $uid]);
        mb_out([]);
    }

    case 'push_register': {
        // Token de Firebase de este teléfono: se guarda en el dispositivo de esta sesión (la fila del ingreso)
        $tok = trim((string)(mb_body()['token'] ?? ''));
        if ($tok === '' || strlen($tok) > 255 || !preg_match('/^[\w:\-.]+$/', $tok)) mb_fail('Token inválido.');
        $sid = (string)($_SESSION['g7_login_sid'] ?? '');
        $db->prepare("UPDATE g7_mobile_devices SET push_token = '' WHERE push_token = ?")->execute([$tok]);   // un token, un dispositivo
        $q = $db->prepare('UPDATE g7_mobile_devices SET push_token = ? WHERE user_id = ? AND login_sid = ? AND revoked_at IS NULL');
        $q->execute([$tok, $uid, $sid]);
        if (!$q->rowCount()) {   // ingreso viejo sin sid: el dispositivo más reciente de la persona
            $db->prepare('UPDATE g7_mobile_devices SET push_token = ? WHERE id = (SELECT id FROM (SELECT id FROM g7_mobile_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used DESC LIMIT 1) x)')->execute([$tok, $uid]);
        }
        mb_out([]);
    }

    case 'devices': {
        $q = $db->prepare('SELECT id, label, app_version, created_at, last_used FROM g7_mobile_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_used DESC');
        $q->execute([$uid]);
        $cur = mb_find_token($db, mb_header_token());
        mb_out(['devices' => array_map(fn($r) => $r + ['current' => $cur && (int)$cur['id'] === (int)$r['id']], $q->fetchAll(PDO::FETCH_ASSOC))]);
    }

    case 'device_revoke': {
        $id = (int)(mb_body()['id'] ?? 0);
        $q = $db->prepare('SELECT * FROM g7_mobile_devices WHERE id = ? AND user_id = ? AND revoked_at IS NULL'); $q->execute([$id, $uid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) mb_fail('Ese dispositivo ya no está.', 404);
        mb_revoke($db, $id, 'user');
        if ($r['login_sid'] !== '') { try { $db->prepare("UPDATE g7_login_history SET ended_at = NOW(), ended_by = 'remote' WHERE sid = ? AND ended_at IS NULL")->execute([$r['login_sid']]); } catch (Throwable $e) {} }
        mb_out([]);
    }
}
mb_fail('Acción desconocida.', 404);
