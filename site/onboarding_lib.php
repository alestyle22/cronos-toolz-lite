<?php
/**
 * onboarding_lib.php — Bienvenida y configuración inicial de cada persona (2026-11-13). Doc: toolz/ONBOARDING.md
 * Reutiliza lo que ya existía: perfil (g7_users), preferencias (g7_user_prefs), zona horaria (tz_lib.php),
 * verificación en dos pasos (totp_lib.php + totp_api.php) y avisos (g7_notifications). No hay otro login ni otro 2FA.
 *
 * Estados: pending (no iniciado) · progress (en progreso) · security (pendiente de seguridad) · done (completado).
 */
require_once __DIR__ . '/totp_lib.php';

const OB_STATUS = ['pending' => 'No iniciado', 'progress' => 'En progreso', 'security' => 'Pendiente de seguridad', 'done' => 'Completado'];
const OB_FIELDS = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'display_name' => 'Nombre visible', 'avatar' => 'Foto de perfil', 'username' => 'Nombre de usuario',
    'bio' => 'Biografía', 'position' => 'Cargo o puesto', 'department' => 'Departamento', 'timezone' => 'Zona horaria', 'country' => 'País', 'language' => 'Idioma'];
const OB_LANGS = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português'];
const OB_DEFAULT_CFG = [
    'enabled' => true,
    'existing' => 'skip',          // personas que ya estaban: skip (no se les muestra) | missing (solo si les falta algo obligatorio)
    'steps' => ['profile' => true, 'prefs' => false, 'security' => 'policy'],   // security: policy (lo que diga el sitio) | required | optional
    'allow_skip_security' => true,  // solo cuando el 2FA no es obligatorio
    'fields' => ['first_name' => 'required', 'last_name' => 'required', 'display_name' => 'required', 'avatar' => 'optional', 'username' => 'show',
        'bio' => 'optional', 'position' => 'optional', 'department' => 'optional', 'timezone' => 'required', 'country' => 'optional', 'language' => 'optional'],
    'defaults' => ['theme' => 'dark', 'language' => 'es', 'notif_app' => true, 'notif_email' => true, 'notif_desktop' => true, 'notif_sound' => true,
        'privacy_online' => true, 'privacy_read_receipts' => true, 'chat_enter_send' => true],
    'texts' => ['welcome_title' => '¡Bienvenido a {plataforma}!', 'welcome_text' => 'Estamos preparando tu espacio de trabajo. Vamos a configurar tu perfil y proteger tu cuenta para que puedas aprovechar todas las funcionalidades.',
        'welcome_cta' => 'Comenzar configuración', 'final_title' => '¡Tu espacio está listo!', 'final_text' => 'Ya configuraste tu cuenta y podés comenzar a trabajar.', 'final_cta' => 'Ir a mi escritorio'],
    'colors' => ['accent' => '', 'accent2' => ''],
];

function ob_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_onboarding (
        user_id INT NOT NULL PRIMARY KEY, status VARCHAR(10) NOT NULL DEFAULT 'pending', started_at DATETIME NULL, last_step TINYINT NOT NULL DEFAULT 0,
        steps_done VARCHAR(100) NOT NULL DEFAULT '', data TEXT NULL, totp_at DATETIME NULL, recovery_ack_at DATETIME NULL, security_skipped TINYINT(1) NOT NULL DEFAULT 0,
        completed_at DATETIME NULL, auto TINYINT(1) NOT NULL DEFAULT 0, forced TINYINT(1) NOT NULL DEFAULT 0, force_mode VARCHAR(10) NOT NULL DEFAULT '', force_reason VARCHAR(300) NOT NULL DEFAULT '',
        require_2fa TINYINT(1) NOT NULL DEFAULT 0, forced_by INT NULL, reminded_at DATETIME NULL, updated_at DATETIME NOT NULL, KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    $have = [];
    foreach ($db->query('SHOW COLUMNS FROM g7_users')->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
    foreach (['first_name' => "VARCHAR(80) NOT NULL DEFAULT ''", 'last_name' => "VARCHAR(80) NOT NULL DEFAULT ''"] as $col => $def)
        if (!isset($have[$col])) $db->exec("ALTER TABLE g7_users ADD COLUMN $col $def");
    // Desde cuándo rige: quien ya tenía usuario antes de esta fecha es "persona existente"
    if (g7_cfg('onboarding_since', null) === null) g7_cfg_set('onboarding_since', date('Y-m-d H:i:s'));
    if (function_exists('g7_tz_install')) g7_tz_install($db);
    g7_totp_install($db);
}

/** Configuración (lo guardado sobre los valores de fábrica). */
function ob_cfg(): array {
    $raw = g7_cfg('onboarding_cfg', null);
    $c = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $d = OB_DEFAULT_CFG;
    $out = array_replace($d, array_intersect_key($c, $d));
    foreach (['steps', 'fields', 'defaults', 'texts', 'colors'] as $k) $out[$k] = array_replace($d[$k], array_intersect_key((array)($c[$k] ?? []), $d[$k]));
    $out['enabled'] = (bool)$out['enabled']; $out['allow_skip_security'] = (bool)$out['allow_skip_security'];
    return $out;
}
function ob_cfg_clean(array $in): array {
    $d = OB_DEFAULT_CFG; $c = ob_cfg();
    $c['enabled'] = !empty($in['enabled']);
    $c['existing'] = in_array($in['existing'] ?? '', ['skip', 'missing'], true) ? $in['existing'] : $c['existing'];
    $c['steps']['profile'] = !empty($in['steps']['profile']);
    $c['steps']['prefs'] = !empty($in['steps']['prefs']);
    $c['steps']['security'] = in_array($in['steps']['security'] ?? '', ['policy', 'required', 'optional'], true) ? $in['steps']['security'] : 'policy';
    $c['allow_skip_security'] = !empty($in['allow_skip_security']);
    foreach (array_keys($d['fields']) as $f) {
        $v = (string)($in['fields'][$f] ?? $c['fields'][$f]);
        $ok = $f === 'username' ? ['show', 'hidden'] : ['required', 'optional', 'hidden'];
        if (in_array($v, $ok, true)) $c['fields'][$f] = $v;
    }
    $df = (array)($in['defaults'] ?? []);
    $c['defaults']['theme'] = in_array($df['theme'] ?? '', ['dark', 'light', 'auto'], true) ? $df['theme'] : $c['defaults']['theme'];
    $c['defaults']['language'] = isset(OB_LANGS[$df['language'] ?? '']) ? $df['language'] : $c['defaults']['language'];
    foreach (['notif_app', 'notif_email', 'notif_desktop', 'notif_sound', 'privacy_online', 'privacy_read_receipts', 'chat_enter_send'] as $k) if (array_key_exists($k, $df)) $c['defaults'][$k] = !empty($df[$k]);
    foreach (array_keys($d['texts']) as $k) if (isset($in['texts'][$k])) $c['texts'][$k] = mb_substr(trim((string)$in['texts'][$k]), 0, $k === 'welcome_text' || $k === 'final_text' ? 400 : 120) ?: $d['texts'][$k];
    foreach (['accent', 'accent2'] as $k) $c['colors'][$k] = preg_match('/^#[0-9a-f]{6}$/i', (string)($in['colors'][$k] ?? '')) ? strtolower($in['colors'][$k]) : '';
    return $c;
}
/** Cada cambio del admin sube la revisión: el control de acceso vuelve a mirar en el próximo pedido. */
function ob_bump(): void { g7_cfg_set('onboarding_rev', (string)((int)g7_cfg('onboarding_rev', 0) + 1)); }

// ── Datos de cada persona ────────────────────────────────────
function ob_user(PDO $db, int $uid): array {
    $q = $db->prepare('SELECT id, username, email, display_name, first_name, last_name, avatar, bio, position, role, created_at, last_login FROM g7_users WHERE id = ?');
    $q->execute([$uid]); $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$u) return [];
    $tz = function_exists('g7_tz_row') ? g7_tz_row($uid) : [];
    $u['timezone'] = (string)($tz['timezone'] ?? ''); $u['country'] = (string)($tz['country'] ?? ''); $u['city'] = (string)($tz['city'] ?? '');
    $q = $db->prepare('SELECT m.team_id, m.is_primary, t.name FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1 WHERE m.user_id = ? ORDER BY m.is_primary DESC, t.name');
    $q->execute([$uid]); $u['teams'] = array_map(fn($r) => ['id' => (int)$r['team_id'], 'name' => $r['name'], 'primary' => (int)$r['is_primary']], $q->fetchAll(PDO::FETCH_ASSOC));
    $u['department'] = $u['teams'] ? $u['teams'][0]['id'] : null;
    $u['language'] = (string)(ob_pref($db, $uid, 'language') ?? '');
    return $u;
}
function ob_pref(PDO $db, int $uid, string $k) {
    $q = $db->prepare('SELECT pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key = ?'); $q->execute([$uid, $k]);
    $v = $q->fetchColumn(); return $v === false ? null : json_decode((string)$v, true);
}
function ob_pref_set(PDO $db, int $uid, string $k, $v): void {
    $db->prepare('INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)')->execute([$uid, $k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
/** Campos obligatorios que le faltan (según la configuración). */
function ob_missing(array $u, array $cfg): array {
    $miss = [];
    foreach ($cfg['fields'] as $f => $mode) {
        if ($mode !== 'required' || $f === 'username') continue;
        $v = $f === 'department' ? ($u['teams'] ? 1 : null) : ($u[$f] ?? '');
        if ($f === 'department' && !$u['teams']) continue;   // sin equipos no hay qué elegir
        if ($v === '' || $v === null) $miss[] = $f;
    }
    return $miss;
}
/** ¿Se le exige la verificación en dos pasos? (política del sitio, configuración del onboarding o pedido del admin). */
function ob_2fa_required(array $cfg, int $uid, ?array $row = null): bool {
    if ($row && !empty($row['require_2fa'])) return true;
    if ($cfg['steps']['security'] === 'required') return true;
    return g7_totp_required_for($uid);
}

/** Fila de estado. La crea la primera vez: nuevo → pendiente; existente → completado sin mostrar (o pendiente si le falta algo y así se configuró). */
function ob_row(PDO $db, int $uid): array {
    ob_install($db);
    $q = $db->prepare('SELECT * FROM g7_onboarding WHERE user_id = ?'); $q->execute([$uid]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
    $cfg = ob_cfg(); $u = ob_user($db, $uid);
    $since = (string)g7_cfg('onboarding_since', '');
    $existing = $since !== '' && ($u['created_at'] ?? '') <= $since;   // el mismo segundo cuenta como existente (instalaciones y pruebas que crean usuarios y enseguida entran)
    $now = date('Y-m-d H:i:s');
    $status = 'pending'; $auto = 0;
    if ($existing) {
        $incomplete = ob_missing($u, $cfg) || (ob_2fa_required($cfg, $uid) && !g7_totp_enabled($db, $uid));
        if ($cfg['existing'] === 'skip' || !$incomplete) { $status = 'done'; $auto = 1; }
    }
    $had2fa = g7_totp_enabled($db, $uid);   // quien ya la tenía no la vuelve a configurar: el paso de seguridad queda hecho
    $db->prepare('INSERT IGNORE INTO g7_onboarding (user_id, status, auto, completed_at, totp_at, steps_done, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $status, $auto, $status === 'done' ? $now : null, $had2fa ? $now : null, $had2fa ? 'security' : '', $now]);
    return ob_row($db, $uid);
}
function ob_set(PDO $db, int $uid, array $f): void {
    $f['updated_at'] = date('Y-m-d H:i:s');
    $db->prepare('UPDATE g7_onboarding SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE user_id = ?')->execute([...array_values($f), $uid]);
}
function ob_steps_done(array $r): array { return array_values(array_filter(explode(',', (string)$r['steps_done']))); }
function ob_mark(PDO $db, int $uid, string $step, int $lastStep): void {
    $r = ob_row($db, $uid); $d = ob_steps_done($r);
    if (!in_array($step, $d, true)) $d[] = $step;
    $f = ['steps_done' => implode(',', $d), 'last_step' => max((int)$r['last_step'], $lastStep)];
    if ($r['status'] === 'pending') { $f['status'] = 'progress'; $f['started_at'] = $r['started_at'] ?: date('Y-m-d H:i:s'); }
    ob_set($db, $uid, $f);
}
/** Qué falta para terminar: [paso => motivo]. */
function ob_pending_steps(PDO $db, int $uid, ?array $r = null): array {
    $cfg = ob_cfg(); $r = $r ?: ob_row($db, $uid); $done = ob_steps_done($r); $out = [];
    $u = ob_user($db, $uid);
    if ($cfg['steps']['profile'] && ($miss = ob_missing($u, $cfg))) $out['profile'] = 'Faltan datos: ' . implode(', ', array_map(fn($f) => OB_FIELDS[$f], $miss));
    if ($cfg['steps']['prefs'] && !in_array('prefs', $done, true)) $out['prefs'] = 'Faltan las preferencias';
    if (ob_2fa_required($cfg, $uid, $r)) {
        if (!g7_totp_enabled($db, $uid)) $out['security'] = 'Falta activar la verificación en dos pasos';
        elseif (!$r['recovery_ack_at'] && !in_array('security', $done, true)) $out['security'] = 'Falta confirmar que guardaste los códigos de recuperación';
    }
    return $out;
}

/** Estado que corresponde según lo que falta (lo usa la API después de cada paso). */
function ob_refresh(PDO $db, int $uid): array {
    $r = ob_row($db, $uid);
    if ($r['status'] === 'done') return $r;
    $p = ob_pending_steps($db, $uid, $r);
    if ($r['status'] !== 'pending' && count($p) === 1 && isset($p['security'])) ob_set($db, $uid, ['status' => 'security']);
    return ob_row($db, $uid);
}

/**
 * Control de acceso (auth.php → getCurrentUser): quien tiene el onboarding pendiente solo ve la bienvenida.
 * Las páginas lo llevan a /toolz/bienvenida.php; las APIs siguen andando, salvo que lo pendiente sea la verificación en dos pasos
 * que le exigen (responden 403, como la política del sitio). Recuerda en la sesión lo ya comprobado, atado a la revisión de la configuración.
 */
function ob_enforce(array $user): void {
    static $done = false; if ($done) return; $done = true;
    if (PHP_SAPI === 'cli' || !function_exists('g7_cfg')) return;
    $uid = (int)$user['id'];
    $ck = $uid . '|' . (int)g7_cfg('onboarding_rev', 0);
    if (($_SESSION['g7_ob_ok'] ?? '') === $ck) return;
    try { $db = getDB(); $cfg = ob_cfg(); $r = ob_row($db, $uid); } catch (Throwable $e) { return; }
    if ($r['status'] === 'done' || (!$cfg['enabled'] && empty($r['forced']))) { $_SESSION['g7_ob_ok'] = $ck; return; }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['bienvenida.php', 'onboarding_api.php', 'totp_api.php', 'user_config.php', 'logout.php', 'login.php', 'ping.php', 'notif.php', 'track.php', 'password.php', 'password_api.php', 'activar.php', 'activar_2fa.php'], true)) return;
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($json) {
        if (ob_2fa_required($cfg, $uid, $r) && !g7_totp_enabled($db, $uid)) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Terminá la configuración de tu cuenta (verificación en dos pasos) para seguir.', 'onboarding' => true]); exit; }
        return;
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/toolz/');
    header('Location: /toolz/bienvenida.php' . (preg_match('#^/toolz/#', $uri) && strpos($uri, 'bienvenida.php') === false ? '?next=' . rawurlencode($uri) : '')); exit;
}

/** Números para el panel de administración. */
function ob_stats(PDO $db): array {
    ob_install($db);
    $rows = $db->query("SELECT u.id, u.created_at, COALESCE(o.status, '') st, u.totp_on FROM g7_users u LEFT JOIN g7_onboarding o ON o.user_id = u.id WHERE u.active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $by = array_fill_keys(array_keys(OB_STATUS), 0); $twofa = 0;
    foreach ($rows as $r) { $st = $r['st'] ?: 'pending'; $by[$st] = ($by[$st] ?? 0) + 1; if ($r['totp_on']) $twofa++; }
    $total = count($rows);
    return ['total' => $total, 'by' => $by, 'done_pct' => $total ? round($by['done'] * 100 / $total, 1) : 0, 'twofa' => $twofa, 'twofa_pct' => $total ? round($twofa * 100 / $total, 1) : 0];
}
