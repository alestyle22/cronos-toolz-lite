<?php
/**
 * onboarding_api.php — API de la bienvenida (assets/onboarding.js) y del panel de administración (assets/onboarding-admin.js).
 * POST JSON {action, ...} con cabecera X-OB: 1. La verificación en dos pasos se activa con totp_api.php (la de siempre). Doc: toolz/ONBOARDING.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/onboarding_lib.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function (array $d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
$me = getCurrentUser();
if (!$me) $out(['error' => 'Tu sesión venció: volvé a entrar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_OB'] ?? '') !== '1') $out(['error' => 'Pedido inválido.'], 400);
$db = getDB(); ob_install($db);
$uid = (int)$me['id'];
$in = json_decode((string)file_get_contents('php://input'), true) ?: []; $act = (string)($in['action'] ?? '');
$isAdmin = ($me['role'] ?? '') === 'admin';
$s = fn($v, int $max) => mb_substr(trim((string)$v), 0, $max);
$now = date('Y-m-d H:i:s');

/** Lo que necesita la pantalla de bienvenida. */
$state = function () use ($db, $uid) {
    $cfg = ob_cfg(); $r = ob_refresh($db, $uid); $u = ob_user($db, $uid);
    $plat = (string)(g7_setting('platform_name') ?: g7_site('name'));
    $t = $cfg['texts']; foreach ($t as $k => $v) $t[$k] = str_replace('{plataforma}', $plat, $v);
    $prefs = [];
    foreach (['theme', 'notif_desktop', 'notif_sound', 'privacy_online', 'privacy_read_receipts', 'chat_enter_send'] as $k) { $v = ob_pref($db, $uid, $k); if ($v !== null) $prefs[$k] = $v; }
    $tn = ob_pref($db, $uid, 'tasks_notif');
    if (is_array($tn) && !empty($tn['events'])) { $ev = array_values($tn['events']); $prefs['notif_app'] = (bool)array_filter($ev, fn($e) => !empty($e['app'])); $prefs['notif_email'] = (bool)array_filter($ev, fn($e) => !empty($e['email'])); }
    $req2fa = ob_2fa_required($cfg, $uid, $r);
    return [
        'status' => $r['status'], 'status_name' => OB_STATUS[$r['status']], 'last_step' => (int)$r['last_step'], 'steps_done' => ob_steps_done($r), 'started' => (bool)$r['started_at'],
        'forced' => (int)$r['forced'], 'force_mode' => $r['force_mode'], 'force_reason' => $r['force_reason'],
        'user' => ['first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'display_name' => $u['display_name'], 'username' => $u['username'], 'avatar' => $u['avatar'],
            'bio' => $u['bio'], 'position' => $u['position'], 'teams' => $u['teams'], 'department' => $u['department'], 'timezone' => $u['timezone'], 'country' => $u['country'], 'language' => $u['language'] ?: $cfg['defaults']['language']],
        'missing' => ob_missing($u, $cfg), 'prefs' => $prefs + $cfg['defaults'], 'defaults' => $cfg['defaults'],
        'security' => ['enabled' => g7_totp_enabled($db, $uid), 'required' => $req2fa, 'can_skip' => !$req2fa && $cfg['allow_skip_security'] && $cfg['steps']['security'] !== 'required', 'ack' => (bool)$r['recovery_ack_at'], 'skipped' => (int)$r['security_skipped']],
        'cfg' => ['steps' => $cfg['steps'], 'fields' => $cfg['fields'], 'texts' => $t, 'colors' => $cfg['colors']], 'langs' => OB_LANGS, 'platform' => $plat,
        'tz' => function_exists('g7_tz_countries') ? ['countries' => g7_tz_countries(), 'site' => g7_tz_site()] : null, 'pending' => ob_pending_steps($db, $uid, $r),
    ];
};

switch ($act) {
case 'state':
    $out($state());

case 'start':   // tocó "Comenzar configuración"
    $r = ob_row($db, $uid);
    if ($r['status'] === 'pending') ob_set($db, $uid, ['status' => 'progress', 'started_at' => $r['started_at'] ?: $now]);
    $out(['ok' => true] + $state());

case 'save_profile':
    $cfg = ob_cfg(); $F = $cfg['fields']; $p = (array)($in['profile'] ?? []); $err = [];
    $val = [];
    foreach (['first_name' => 80, 'last_name' => 80, 'display_name' => 100, 'bio' => 300, 'position' => 100] as $k => $max) {
        if (($F[$k] ?? 'hidden') === 'hidden' || !array_key_exists($k, $p)) continue;
        $v = $s($p[$k], $max);
        if ($v !== '' && preg_match('/[<>]/', $v)) { $err[$k] = 'No uses los signos < ni >'; continue; }
        if ($F[$k] === 'required' && $v === '') { $err[$k] = 'Completá ' . mb_strtolower(OB_FIELDS[$k]); continue; }
        if (in_array($k, ['first_name', 'last_name'], true) && $v !== '' && !preg_match("/^[\\p{L}][\\p{L}\\p{M} '\\-\\.]{0,79}$/u", $v)) { $err[$k] = 'Usá solo letras, espacios, guiones o apóstrofos'; continue; }
        if ($k === 'display_name' && $v !== '' && mb_strlen($v) < 2) { $err[$k] = 'Poné al menos 2 caracteres'; continue; }
        $val[$k] = $v;
    }
    if (($F['avatar'] ?? '') === 'required') { $q = $db->prepare('SELECT avatar FROM g7_users WHERE id = ?'); $q->execute([$uid]); if (!(string)$q->fetchColumn()) $err['avatar'] = 'Subí una foto de perfil'; }
    // Departamento: solo entre los equipos de los que ya es parte (queda como principal)
    $team = null;
    if (($F['department'] ?? 'hidden') !== 'hidden' && !empty($p['department'])) {
        $q = $db->prepare('SELECT 1 FROM g7_team_members WHERE user_id = ? AND team_id = ?'); $q->execute([$uid, (int)$p['department']]);
        if (!$q->fetchColumn()) $err['department'] = 'Elegí uno de tus equipos'; else $team = (int)$p['department'];
    }
    // País y zona horaria (tz_lib.php: valida que la zona sea de ese país)
    $tzWant = ($F['timezone'] ?? 'hidden') !== 'hidden' || ($F['country'] ?? 'hidden') !== 'hidden';
    $cc = strtoupper($s($p['country'] ?? '', 2)); $tz = $s($p['timezone'] ?? '', 64);
    if ($tzWant) {
        if (($F['timezone'] ?? '') === 'required' && $tz === '') $err['timezone'] = 'Elegí tu zona horaria';
        elseif (($F['country'] ?? '') === 'required' && $cc === '') $err['country'] = 'Elegí tu país';
        elseif ($tz !== '' && !in_array($tz, timezone_identifiers_list(), true)) $err['timezone'] = 'Zona horaria no válida';
        elseif ($tz !== '' && $cc === '') { $loc = (new DateTimeZone($tz))->getLocation(); $cc = strtoupper((string)($loc['country_code'] ?? '')); if ($cc === '??') $cc = ''; }
    }
    $lang = (string)($p['language'] ?? '');
    if (($F['language'] ?? 'hidden') !== 'hidden' && $lang !== '' && !isset(OB_LANGS[$lang])) $err['language'] = 'Idioma no válido';
    if ($err) $out(['error' => 'Revisá los datos marcados', 'errors' => $err], 422);
    if ($val) $db->prepare('UPDATE g7_users SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($val))) . ' WHERE id = ?')->execute([...array_values($val), $uid]);
    if ($team) { $db->prepare('UPDATE g7_team_members SET is_primary = (team_id = ?) WHERE user_id = ?')->execute([$team, $uid]); }
    if ($tzWant && ($tz !== '' || $cc !== '') && function_exists('g7_tz_save')) {
        $row = g7_tz_row($uid);
        if ($e = g7_tz_save($uid, $cc, $tz, (string)($row['city'] ?? ''), !empty($row['city_public']))) $out(['error' => $e, 'errors' => ['timezone' => $e]], 422);
    }
    if (($F['language'] ?? 'hidden') !== 'hidden' && $lang !== '') ob_pref_set($db, $uid, 'language', $lang);
    ob_mark($db, $uid, 'profile', 1);
    g7_audit('onboarding.profile', 'user:' . $uid, array_keys($val), $uid);
    $out(['ok' => true] + $state());

case 'save_prefs':
    $cfg = ob_cfg(); $p = (array)($in['prefs'] ?? []);
    $theme = in_array($p['theme'] ?? '', ['dark', 'light', 'auto'], true) ? $p['theme'] : $cfg['defaults']['theme'];
    ob_pref_set($db, $uid, 'theme', $theme);
    foreach (['notif_desktop', 'notif_sound', 'privacy_online', 'privacy_read_receipts', 'chat_enter_send'] as $k) ob_pref_set($db, $uid, $k, array_key_exists($k, $p) ? !empty($p[$k]) : (bool)$cfg['defaults'][$k]);
    if (isset($p['language']) && isset(OB_LANGS[$p['language']])) ob_pref_set($db, $uid, 'language', $p['language']);
    if (isset($p['timezone']) && $p['timezone'] !== '' && function_exists('g7_tz_save') && in_array((string)$p['timezone'], timezone_identifiers_list(), true)) {
        $row = g7_tz_row($uid); $loc = (new DateTimeZone((string)$p['timezone']))->getLocation(); $cc = strtoupper((string)($loc['country_code'] ?? '')); if ($cc === '??') $cc = (string)($row['country'] ?? '');
        if ($e = g7_tz_save($uid, $cc, (string)$p['timezone'], (string)($row['city'] ?? ''), !empty($row['city_public']))) $out(['error' => $e], 422);
    }
    // Avisos: campana y email para todos los eventos de Tareas, Tickets y Solicitudes (después se ajustan uno por uno en Tareas › Mis avisos)
    require_once __DIR__ . '/tareas_lib.php';
    $tcfg = tk_config($db); $ev = [];
    $app = array_key_exists('notif_app', $p) ? !empty($p['notif_app']) : (bool)$cfg['defaults']['notif_app'];
    $mail = array_key_exists('notif_email', $p) ? !empty($p['notif_email']) : (bool)$cfg['defaults']['notif_email'];
    foreach ($tcfg['notif']['events'] as $k => $e) $ev[$k] = ['app' => $app && $k !== 'tk_digest', 'email' => $mail && !empty($e['email'])];   // por email, los que el sitio manda por email
    ob_pref_set($db, $uid, 'tasks_notif', ['events' => $ev, 'browser' => array_key_exists('notif_desktop', $p) ? !empty($p['notif_desktop']) : true]);
    ob_mark($db, $uid, 'prefs', 2);
    $out(['ok' => true] + $state());

case 'security_ack':   // ya activó con totp_api.php: confirma que guardó los códigos de recuperación
    if (!g7_totp_enabled($db, $uid)) $out(['error' => 'Todavía no está activa la verificación en dos pasos.'], 409);
    if (empty($in['saved'])) $out(['error' => 'Confirmá que guardaste los códigos de recuperación.'], 422);
    ob_set($db, $uid, ['recovery_ack_at' => $now, 'totp_at' => ob_row($db, $uid)['totp_at'] ?: $now, 'security_skipped' => 0]);
    ob_mark($db, $uid, 'security', 3);
    g7_audit('onboarding.security', 'user:' . $uid, null, $uid);
    $out(['ok' => true] + $state());

case 'security_skip':
    $cfg = ob_cfg(); $r = ob_row($db, $uid);
    if (ob_2fa_required($cfg, $uid, $r) || !$cfg['allow_skip_security'] || $cfg['steps']['security'] === 'required') $out(['error' => 'La verificación en dos pasos es obligatoria en este sitio.'], 403);
    ob_set($db, $uid, ['security_skipped' => 1]);
    ob_mark($db, $uid, 'security', 3);
    $out(['ok' => true] + $state());

case 'step':   // último paso visto (para retomar desde ahí)
    $r = ob_row($db, $uid);
    ob_set($db, $uid, ['last_step' => max(0, min(4, (int)($in['step'] ?? 0)))] + ($r['status'] === 'pending' ? ['status' => 'progress', 'started_at' => $r['started_at'] ?: $now] : []));
    $out(['ok' => true]);

case 'finish':
    $r = ob_row($db, $uid);
    if ($r['status'] === 'done') $out(['ok' => true] + $state());
    $p = ob_pending_steps($db, $uid, $r);
    if ($p) {
        if (isset($p['security']) && count($p) === 1) ob_set($db, $uid, ['status' => 'security']);
        $out(['error' => 'Todavía falta: ' . implode(' · ', $p), 'pending' => $p], 409);
    }
    ob_set($db, $uid, ['status' => 'done', 'completed_at' => $now, 'last_step' => 4, 'forced' => 0, 'force_mode' => '', 'force_reason' => '', 'require_2fa' => 0]);
    unset($_SESSION['g7_ob_ok']);
    g7_audit('onboarding.done', 'user:' . $uid, null, $uid);
    $out(['ok' => true] + $state());

// ── Administración ───────────────────────────────────────────
case 'admin_state':
    if (!$isAdmin) $out(['error' => 'Solo administración.'], 403);
    // Quien todavía no entró desde que existe la bienvenida no tiene fila: se evalúa ahora (existente → completado sin mostrar, etc.)
    foreach ($db->query('SELECT u.id FROM g7_users u LEFT JOIN g7_onboarding o ON o.user_id = u.id WHERE u.active = 1 AND o.user_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $x) ob_row($db, (int)$x);
    $rows = $db->query("SELECT u.id, u.username, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.avatar, u.role, u.created_at, u.last_login, u.totp_on,
            u.first_name, u.last_name, u.position, o.status, o.started_at, o.last_step, o.steps_done, o.completed_at, o.auto, o.forced, o.force_mode, o.force_reason, o.require_2fa, o.reminded_at, o.security_skipped
        FROM g7_users u LEFT JOIN g7_onboarding o ON o.user_id = u.id WHERE u.active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $cfg = ob_cfg();
    $users = array_map(function ($r) use ($db, $cfg) {
        $u = ob_user($db, (int)$r['id']);
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'username' => $r['username'], 'avatar' => $r['avatar'], 'role' => $r['role'], 'created_at' => $r['created_at'], 'last_login' => $r['last_login'],
            'status' => $r['status'] ?: 'pending', 'started_at' => $r['started_at'], 'last_step' => (int)$r['last_step'], 'completed_at' => $r['completed_at'], 'auto' => (int)$r['auto'],
            'forced' => (int)$r['forced'], 'force_mode' => (string)$r['force_mode'], 'force_reason' => (string)$r['force_reason'], 'require_2fa' => (int)$r['require_2fa'], 'reminded_at' => $r['reminded_at'],
            'twofa' => (int)$r['totp_on'], 'twofa_required' => ob_2fa_required($cfg, (int)$r['id'], $r), 'skipped' => (int)$r['security_skipped'], 'missing' => ob_missing($u, $cfg)];
    }, $rows);
    $out(['users' => $users, 'stats' => ob_stats($db), 'cfg' => $cfg, 'status' => OB_STATUS, 'fields' => OB_FIELDS, 'langs' => OB_LANGS,
        'policy' => ['scope' => g7_totp_scope(), 'scopes' => G7_TOTP_SCOPES], 'is_super' => function_exists('g7_super_ok') && g7_super_ok($uid)]);

case 'admin_cfg':
    if (!$isAdmin) $out(['error' => 'Solo administración.'], 403);
    $c = ob_cfg_clean((array)($in['cfg'] ?? []));
    g7_cfg_set('onboarding_cfg', $c); ob_bump();
    g7_audit('onboarding.cfg', '', ['enabled' => $c['enabled'], 'security' => $c['steps']['security']], $uid);
    $out(['ok' => true, 'cfg' => ob_cfg()]);

case 'admin_action':   // forzar, pedir lo que falta, exigir 2FA, reiniciar o recordar — a una o varias personas
    if (!$isAdmin) $out(['error' => 'Solo administración.'], 403);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
    if (!$ids || count($ids) > 500) $out(['error' => 'Elegí a quién.'], 400);
    $op = (string)($in['op'] ?? ''); $reason = $s($in['reason'] ?? '', 300); $n = 0;
    foreach ($ids as $id) {
        $q = $db->prepare('SELECT id, role, is_super FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$id]); $tu = $q->fetch(PDO::FETCH_ASSOC);
        if (!$tu) continue;
        // Un admin común no le cambia el onboarding a un superadmin (misma regla que el resto del panel)
        if (!empty($tu['is_super']) && function_exists('g7_super_ok') && !g7_super_ok($uid)) continue;
        ob_row($db, $id);
        if ($op === 'force') ob_set($db, $id, ['status' => 'pending', 'forced' => 1, 'force_mode' => 'full', 'force_reason' => $reason, 'forced_by' => $uid, 'steps_done' => g7_totp_enabled($db, $id) ? 'security' : '', 'last_step' => 0, 'completed_at' => null, 'auto' => 0]);
        elseif ($op === 'missing') ob_set($db, $id, ['status' => 'pending', 'forced' => 1, 'force_mode' => 'missing', 'force_reason' => $reason, 'forced_by' => $uid, 'completed_at' => null, 'auto' => 0]);
        elseif ($op === 'require_2fa') { if (g7_totp_enabled($db, $id)) { ob_set($db, $id, ['require_2fa' => 1]); $n++; continue; } ob_set($db, $id, ['status' => 'security', 'forced' => 1, 'force_mode' => '2fa', 'force_reason' => $reason, 'forced_by' => $uid, 'require_2fa' => 1, 'completed_at' => null, 'auto' => 0]); }
        elseif ($op === 'reset') ob_set($db, $id, ['status' => 'pending', 'started_at' => null, 'last_step' => 0, 'steps_done' => g7_totp_enabled($db, $id) ? 'security' : '', 'recovery_ack_at' => null, 'security_skipped' => 0, 'completed_at' => null, 'auto' => 0, 'forced' => 1, 'force_mode' => 'full', 'force_reason' => $reason, 'forced_by' => $uid]);
        elseif ($op === 'release') ob_set($db, $id, ['status' => 'done', 'completed_at' => $now, 'forced' => 0, 'force_mode' => '', 'require_2fa' => 0]);
        elseif ($op === 'remind') {}
        else $out(['error' => 'Acción desconocida.'], 400);
        if ($op !== 'release') {   // aviso en la campana (lo lleva a la bienvenida)
            $msg = ['force' => 'Te pidieron que completes la configuración de tu cuenta.', 'missing' => 'Te pidieron que completes los datos que faltan en tu perfil.', 'require_2fa' => 'Te pidieron que actives la verificación en dos pasos.', 'reset' => 'Se reinició la configuración de tu cuenta.', 'remind' => 'Recordá terminar la configuración de tu cuenta.'][$op];
            try { $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, ?, 'onboarding', 0, NULL, ?)")->execute([$id, $uid, mb_substr($msg . ($reason !== '' ? ' ' . $reason : ''), 0, 280)]); } catch (Throwable $e) {}
            ob_set($db, $id, ['reminded_at' => $now]);
        }
        $n++;
    }
    ob_bump();
    g7_audit('onboarding.admin_' . $op, implode(',', array_slice($ids, 0, 50)), ['n' => $n, 'reason' => $reason], $uid);
    $out(['ok' => true, 'n' => $n]);
}
$out(['error' => 'Acción desconocida.'], 400);
