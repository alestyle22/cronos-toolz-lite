<?php
/**
 * Cronos Mobile — biblioteca de la API de la app nativa de Android (toolz/cronos-mobile, docs/mobile/).
 *
 * La app usa la MISMA sesión PHP que la web (cookie): no hay un segundo sistema de autenticación. Lo único nuevo es el
 * "token del dispositivo": al entrar, la app recibe un token largo (en la base solo queda su hash) atado a la fila de
 * g7_login_history de ese ingreso. Cuando la sesión PHP vence (session_hours, 8 h por defecto), la app la reabre con el
 * token sin pedir la contraseña. Si la persona cierra esa sesión desde Mi perfil › Dónde iniciaste sesión, cambia la
 * contraseña, un admin la cierra o la cuenta se bloquea, la fila del historial queda terminada y el token deja de servir.
 */

const MB_API_VERSION = 1;
const MB_TOKEN_IDLE_DAYS = 60;   // un teléfono que no abre la app en 60 días tiene que volver a entrar con la contraseña

function mb_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_mobile_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        login_sid CHAR(32) NOT NULL DEFAULT '',
        label VARCHAR(120) NOT NULL DEFAULT '',
        platform VARCHAR(16) NOT NULL DEFAULT 'android',
        app_version VARCHAR(20) NOT NULL DEFAULT '',
        push_token VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        last_used DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        revoked_by VARCHAR(16) NOT NULL DEFAULT '',
        UNIQUE KEY uq_tok (token_hash),
        INDEX idx_user (user_id),
        INDEX idx_sid (login_sid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mb_out(array $data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => $code < 400] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function mb_fail(string $msg, int $code = 400, array $extra = []): void { mb_out(['error' => $msg] + $extra, $code); }

function mb_body(): array {
    static $b = null; if ($b !== null) return $b;
    $raw = file_get_contents('php://input');
    $j = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
    return $b = is_array($j) ? $j + $_POST : $_POST;
}

/** Nombre del teléfono para Mi perfil › Dónde iniciaste sesión ("Cronos para Android · Pixel 8"). */
function mb_device_label(string $model): string {
    $model = trim(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $model));
    return mb_substr('App Cronos · ' . ($model !== '' ? $model : 'Android'), 0, 120);
}

/** Crea el token del dispositivo para el ingreso actual (la sesión ya está abierta). Devuelve el token en claro, una sola vez. */
function mb_issue_token(PDO $db, int $uid, string $model, string $appVersion): string {
    mb_install($db);
    $sid = (string)($_SESSION['g7_login_sid'] ?? '');
    if ($sid === '' && is_file(dirname(__DIR__) . '/login_alert_lib.php')) {
        require_once dirname(__DIR__) . '/login_alert_lib.php';
        try { g7_lh_open($db, $uid, 'password', false); $sid = (string)($_SESSION['g7_login_sid'] ?? ''); } catch (Throwable $e) {}
    }
    $tok = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO g7_mobile_devices (user_id, token_hash, login_sid, label, app_version, created_at, last_used) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$uid, hash('sha256', $tok), $sid, mb_device_label($model), mb_substr(preg_replace('/[^0-9A-Za-z.\-]/', '', $appVersion), 0, 20)]);
    // La fila del historial muestra el nombre del teléfono en vez del user agent técnico
    if ($sid !== '') { try { $db->prepare('UPDATE g7_login_history SET label = ? WHERE sid = ?')->execute([mb_device_label($model), $sid]); } catch (Throwable $e) {} }
    return $tok;
}

function mb_header_token(): string {
    $t = (string)($_SERVER['HTTP_X_CRONOS_DEVICE'] ?? '');
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

/** Busca el token vigente. Devuelve la fila o null. */
function mb_find_token(PDO $db, string $tok): ?array {
    if ($tok === '') return null;
    mb_install($db);
    $q = $db->prepare('SELECT * FROM g7_mobile_devices WHERE token_hash = ? AND revoked_at IS NULL');
    $q->execute([hash('sha256', $tok)]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (strtotime((string)$r['last_used']) < time() - MB_TOKEN_IDLE_DAYS * 86400) { mb_revoke($db, (int)$r['id'], 'idle'); return null; }
    return $r;
}

function mb_revoke(PDO $db, int $id, string $by): void {
    try { $db->prepare('UPDATE g7_mobile_devices SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL')->execute([$by, $id]); } catch (Throwable $e) {}
}

/**
 * Reabre la sesión PHP con el token del dispositivo. Devuelve [true, user] o [false, motivo].
 * Motivos: token | ended:<remote|password|admin|locked|logout> | inactive | locked | blocked
 */
function mb_refresh(PDO $db, string $tok): array {
    $row = mb_find_token($db, $tok);
    if (!$row) return [false, 'token'];
    $uid = (int)$row['user_id'];
    $q = $db->prepare('SELECT * FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$uid]); $user = $q->fetch(PDO::FETCH_ASSOC);
    if (!$user) { mb_revoke($db, (int)$row['id'], 'inactive'); return [false, 'inactive']; }
    if (is_file(dirname(__DIR__) . '/login_alert_lib.php')) {
        require_once dirname(__DIR__) . '/login_alert_lib.php';
        if (function_exists('g7_acct_locked') && g7_acct_locked($db, $uid)) return [false, 'locked'];
        if (function_exists('g7_blk_match') && g7_blk_match(g7_client_ip())) return [false, 'blocked'];
        $sid = (string)$row['login_sid'];
        if ($sid !== '') {
            g7_ld_install($db);
            $h = $db->prepare('SELECT ended_at, ended_by FROM g7_login_history WHERE sid = ? AND user_id = ?'); $h->execute([$sid, $uid]);
            $hr = $h->fetch(PDO::FETCH_ASSOC);
            // Fila terminada (cerrada desde otro dispositivo, contraseña cambiada, admin, bloqueo): el token muere con ella.
            // Fila borrada por antigüedad (180 días): se abre una nueva al reabrir.
            if ($hr && $hr['ended_at'] !== null) { mb_revoke($db, (int)$row['id'], 'ended'); return [false, 'ended:' . ((string)$hr['ended_by'] ?: 'remote')]; }
            if (!$hr) $sid = '';
        }
    }
    startSecureSession();
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['g7_user_id'] = (int)$user['id'];
    $_SESSION['g7_username'] = $user['username'];
    $_SESSION['g7_role'] = $user['role'];
    $_SESSION['g7_last_regen'] = time();
    if (isset($sid) && $sid !== '') {
        $_SESSION['g7_login_sid'] = $sid;
        try { $db->prepare('UPDATE g7_login_history SET last_seen = NOW(), last_ip = ? WHERE sid = ?')->execute([mb_substr(g7_client_ip(), 0, 45), $sid]); } catch (Throwable $e) {}
    } elseif (function_exists('g7_lh_open')) {
        try { g7_lh_open($db, $uid, 'session', false); $db->prepare('UPDATE g7_mobile_devices SET login_sid = ? WHERE id = ?')->execute([(string)($_SESSION['g7_login_sid'] ?? ''), (int)$row['id']]); } catch (Throwable $e) {}
    }
    $db->prepare('UPDATE g7_mobile_devices SET last_used = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    $db->prepare('UPDATE g7_users SET last_seen = NOW() WHERE id = ?')->execute([$uid]);
    return [true, $user];
}

/** URL absoluta de un avatar o archivo guardado como ruta relativa del sitio. */
function mb_url(?string $p): ?string {
    $p = trim((string)$p);
    if ($p === '') return null;
    if (preg_match('#^https?://#i', $p) || strpos($p, 'data:') === 0) return $p;
    return strpos($p, '/') === 0 ? $p : G7_BASE . $p;
}

/** Avatar de una conversación: puede ser una URL, una ruta del sitio o "api.php?action=file&id=N" (relativa a chat/). */
function mb_chat_avatar(string $a): ?string {
    $a = trim($a); if ($a === '') return null;
    return strpos($a, 'api.php') === 0 ? G7_BASE . 'chat/' . $a : mb_url($a);
}

/** Personas activas para Trabajo (la misma consulta que wk_users de trabajo/api.php, que vive dentro de ese archivo). */
function wk_users_list(PDO $db): array {
    $rows = $db->query("SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS name, avatar, role FROM g7_users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$u) { $u['id'] = (int)$u['id']; $u['avatar'] = mb_url($u['avatar'] ?? ''); }
    return $rows;
}

/** Categoría de un aviso de la campana para los filtros del centro de notificaciones de la app. */
function mb_notif_category(string $t): string {
    if (in_array($t, ['kudos', 'bn_kudos'], true)) return 'recognitions';
    if (in_array($t, ['mention', 'mention_post', 'task_mention'], true)) return 'mentions';
    if (in_array($t, ['wk_tk', 'pt_tk', 'wk_req'], true)) return 'tickets';
    if (str_starts_with($t, 'task_') || $t === 'wk_run') return 'tasks';
    if (in_array($t, ['post_reply', 'comment', 'reply', 'poll_vote', 'coffee', 'birthday'], true)) return 'community';
    if ($t === 'cal_remind') return 'events';
    if (str_starts_with($t, 'bn_')) return 'wellbeing';
    return 'system';
}

/** Datos de la persona para la app (perfil + qué módulos puede usar). */
function mb_me(PDO $db, int $uid): array {
    $q = $db->prepare('SELECT * FROM g7_users WHERE id = ?'); $q->execute([$uid]); $u = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $mods = [];
    foreach (g7_modules() as $id => $m) {
        if (($m['mode'] ?? '') === 'off' || !empty($m['site_off'])) continue;
        if (!g7_can($uid, $id)) continue;
        $mods[] = ['id' => $id, 'label' => (string)$m['label'], 'icon' => (string)($m['icon'] ?? ''), 'group' => (string)($m['group'] ?? ''),
                   'url' => (string)($m['url'] ?? ''), 'description' => (string)($m['description'] ?? '')];
    }
    $acc = (string)($u['accent_color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $acc)) $acc = preg_match('/^#[0-9a-fA-F]{6}$/', (string)g7_setting('accent_color')) ? (string)g7_setting('accent_color') : '#ff6600';
    return [
        'api' => MB_API_VERSION,
        'user' => [
            'id' => $uid,
            'username' => (string)($u['username'] ?? ''),
            'name' => (string)(($u['display_name'] ?? '') ?: ($u['username'] ?? '')),
            'email' => (string)($u['email'] ?? ''),
            'avatar' => mb_url($u['avatar'] ?? ''),
            'position' => (string)($u['position'] ?? ''),
            'role' => (string)($u['role'] ?? 'user'),
            'admin' => ($u['role'] ?? '') === 'admin',
            'status' => (string)($u['availability'] ?? ''),
            'status_text' => (string)($u['focus_text'] ?? ''),
            'accent' => $acc,
        ],
        'site' => [
            'name' => (string)(g7_setting('platform_name') ?: g7_site('name')),
            'logo' => mb_url((string)(trim((string)g7_cfg('logo_url', '')) ?: g7_site('logo'))),
            'tz_storage' => function_exists('g7_tz_storage') ? g7_tz_storage() : date_default_timezone_get(),
            'tz_site' => function_exists('g7_tz_site') ? g7_tz_site() : date_default_timezone_get(),
            'now' => date('Y-m-d H:i:s'),
        ],
        'modules' => $mods,
    ];
}
