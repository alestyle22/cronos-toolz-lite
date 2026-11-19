<?php
/**
 * login_alert_lib.php — Inicios de sesión: registro con ubicación, sesiones activas y avisos de seguridad.
 *
 * Aviso de dispositivo nuevo (2026-11-13, "hagamos el login más seguro"): cada ingreso guarda una huella del navegador y el
 * sistema (sin versiones, así una actualización del navegador no cuenta como dispositivo nuevo). Si la persona entra desde uno
 * que nunca usó, le llega un aviso a la campana y un email. El primer ingreso de la cuenta no avisa.
 *
 * Registro de inicios de sesión (2026-11-16, "algo similar a como funciona en Facebook"):
 *  - g7_login_history: cada ingreso con dispositivo, IP y ubicación aproximada (ciudad, provincia, país, a partir de la IP).
 *  - Cada sesión lleva su propio id de ingreso ($_SESSION['g7_login_sid']): desde Mi perfil › Dónde iniciaste sesión la persona
 *    ve dónde tiene la cuenta abierta y puede cerrar una sesión de otro dispositivo (rige en su próximo pedido).
 *  - Avisos: dispositivo nuevo, país donde nunca había entrado (aunque el dispositivo sea conocido) e intentos fallidos
 *    (muchas contraseñas equivocadas, o la contraseña bien y el código de 2 pasos mal).
 *  - Dispositivos de confianza: con la verificación en dos pasos, "no volver a pedir el código en este dispositivo" por 30 días.
 *  - Nombres propios para los dispositivos ("PC de la oficina").
 *
 * Nunca frena el ingreso: cualquier error se ignora. La ubicación y los avisos se resuelven después de responder.
 * Lo llaman auth.php (g7_login_finish, login, g7_login_2fa, g7_login_fail, getCurrentUser, logout), password_api.php y
 * sesiones_api.php. Doc: toolz/SEGURIDAD_LOGIN.md
 */

const G7_TD_DAYS = 30;   // días que un dispositivo de confianza no pide el código de 2 pasos

function g7_ld_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_login_devices (
        user_id INT NOT NULL,
        fp CHAR(64) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        last_ip VARCHAR(45) NOT NULL DEFAULT '',
        name VARCHAR(60) NOT NULL DEFAULT '',
        PRIMARY KEY (user_id, fp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        sid CHAR(32) NOT NULL,
        fp CHAR(64) NOT NULL DEFAULT '',
        label VARCHAR(120) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        country_code CHAR(2) NOT NULL DEFAULT '',
        place VARCHAR(160) NOT NULL DEFAULT '',
        lat DECIMAL(7,2) NULL,
        lon DECIMAL(7,2) NULL,
        method VARCHAR(16) NOT NULL DEFAULT 'password',
        new_device TINYINT(1) NOT NULL DEFAULT 0,
        alert VARCHAR(12) NOT NULL DEFAULT '',
        confirmed VARCHAR(8) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        last_ip VARCHAR(45) NOT NULL DEFAULT '',
        ended_at DATETIME NULL,
        ended_by VARCHAR(16) NOT NULL DEFAULT '',
        UNIQUE KEY uq_sid (sid),
        INDEX idx_user (user_id, created_at),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ip_geo (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        country_code CHAR(2) NOT NULL DEFAULT '',
        place VARCHAR(160) NOT NULL DEFAULT '',
        lat DECIMAL(7,2) NULL,
        lon DECIMAL(7,2) NULL,
        at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_login_trusted (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        fp CHAR(64) NOT NULL DEFAULT '',
        label VARCHAR(120) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        place VARCHAR(160) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        last_used DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        UNIQUE KEY uq_tok (token_hash),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Enlaces firmados de un solo uso que van en los emails (cuenta_segura.php): revisar un ingreso, bloquear o desbloquear la cuenta
    $db->exec("CREATE TABLE IF NOT EXISTS g7_account_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        purpose VARCHAR(16) NOT NULL,
        ref_id INT NOT NULL DEFAULT 0,
        token_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        UNIQUE KEY uq_tok (token_hash),
        INDEX idx_user (user_id, purpose)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Cuentas bloqueadas por emergencia ("Bloquear mi cuenta"): nadie entra hasta que la persona elija contraseña nueva o un admin la libere
    $db->exec("CREATE TABLE IF NOT EXISTS g7_account_locks (
        user_id INT NOT NULL PRIMARY KEY,
        locked_at DATETIME NOT NULL,
        by_user INT NOT NULL DEFAULT 0,
        reason VARCHAR(32) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Columnas sumadas después (las tablas pueden venir de una versión anterior: g7_login_devices existe desde la 1.0.59)
    $add = ['g7_login_devices' => ['name' => "VARCHAR(60) NOT NULL DEFAULT ''"],
        'g7_login_history' => ['lat' => 'DECIMAL(7,2) NULL', 'lon' => 'DECIMAL(7,2) NULL', 'alert' => "VARCHAR(12) NOT NULL DEFAULT ''"],
        'g7_ip_geo' => ['lat' => 'DECIMAL(7,2) NULL', 'lon' => 'DECIMAL(7,2) NULL']];
    foreach ($add as $t => $cols) {
        $have = array_column($db->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach ($cols as $c => $ddl) if (!in_array($c, $have, true)) $db->exec("ALTER TABLE $t ADD COLUMN $c $ddl");
    }
}

/** "Chrome en Windows", "Safari en iPhone"… (aproximado, solo para mostrar). */
function g7_ld_label(string $ua): string {
    if (preg_match('/^CronosMobile\//', $ua)) return 'App Cronos en Android';   // app nativa (toolz/cronos-mobile)
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
        : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Otro navegador'))));
    $o = preg_match('/iPhone/', $ua) ? 'iPhone' : (preg_match('/iPad/', $ua) ? 'iPad' : (preg_match('/Android/', $ua) ? 'Android'
        : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : 'otro sistema')))));
    return "$b en $o";
}
/** Huella del dispositivo: el user agent sin números (versiones) ni espacios de más. */
function g7_ld_fp(string $ua): string {
    return hash('sha256', preg_replace('/\s+/', ' ', preg_replace('/[\d._]+/', '', mb_strtolower(trim($ua)))));
}
function g7_ld_ua(): string { return (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); }
function g7_ld_ip(): string { return mb_substr(function_exists('g7_client_ip') ? g7_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

// ── Ubicación aproximada a partir de la IP ───────────────────────────────────────
/** IP de una red privada, local o reservada: no tiene ubicación pública. */
function g7_geo_private(string $ip): bool {
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
/**
 * Respuesta de ip-api.com → [código de país, "Ciudad, Provincia, País", lat, lon] (sin repetir si la ciudad y la provincia
 * se llaman igual). Las coordenadas se redondean a 2 decimales (~1 km): alcanza para el mapa y no dice más que la ciudad.
 */
function g7_geo_parse($j): array {
    if (!is_array($j) || ($j['status'] ?? '') !== 'success') return ['', '', null, null];
    $cc = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($j['countryCode'] ?? '')), 0, 2));
    $parts = [];
    foreach ([(string)($j['city'] ?? ''), (string)($j['regionName'] ?? ''), (string)($j['country'] ?? '')] as $p) {
        $p = trim(strip_tags($p));
        if ($p !== '' && !in_array(mb_strtolower($p), array_map('mb_strtolower', $parts), true)) $parts[] = $p;
    }
    $lat = is_numeric($j['lat'] ?? null) && abs((float)$j['lat']) <= 90 ? round((float)$j['lat'], 2) : null;
    $lon = is_numeric($j['lon'] ?? null) && abs((float)$j['lon']) <= 180 ? round((float)$j['lon'], 2) : null;
    return [$cc, mb_substr(implode(', ', $parts), 0, 160), $lat, $lon];
}
/**
 * Ubicación de una IP: [código de país, lugar, lat, lon]. Se guarda 30 días por IP (g7_ip_geo) para no consultar de más.
 * Fuente: ip-api.com (la misma que usan landing-gen y los links cortos), con 3 s de tope. Si falla o está apagado
 * (g7_config login_geo = 0), queda el país que manda Cloudflare, si el sitio está detrás de Cloudflare.
 * Las pruebas definen G7_GEO_FAKE (IP → respuesta) para no salir a internet.
 */
function g7_geo_lookup(PDO $db, string $ip): array {
    if ($ip === '' || g7_geo_private($ip)) return ['', 'Red local', null, null];
    try {
        $q = $db->prepare('SELECT country_code, place, lat, lon FROM g7_ip_geo WHERE ip = ? AND at > NOW() - INTERVAL 30 DAY'); $q->execute([$ip]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return [(string)$r['country_code'], (string)$r['place'], $r['lat'] !== null ? (float)$r['lat'] : null, $r['lon'] !== null ? (float)$r['lon'] : null];
    } catch (Throwable $e) {}
    $g = ['', '', null, null];
    if (defined('G7_GEO_FAKE')) $g = g7_geo_parse(G7_GEO_FAKE[$ip] ?? null);
    elseif (!function_exists('g7_cfg') || g7_cfg('login_geo', '1') === '1') {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://ip-api.com/json/' . rawurlencode($ip) . '?lang=es&fields=status,countryCode,country,regionName,city,lat,lon', false, $ctx);
        $g = g7_geo_parse(json_decode((string)$raw, true));
    }
    $cf = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($g[0] === '' && preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX') $g[0] = $cf;
    if ($g[0] !== '' || $g[1] !== '') {
        try { $db->prepare('REPLACE INTO g7_ip_geo (ip, country_code, place, lat, lon, at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([$ip, $g[0], $g[1], $g[2], $g[3]]); } catch (Throwable $e) {}
    }
    return $g;
}

// ── Registro de inicios de sesión ───────────────────────────────────────────
/** Anota un ingreso y deja su id en la sesión. Devuelve el id de la fila. $method: password | 2fa | recovery | trusted | session */
function g7_lh_open(PDO $db, int $uid, string $method, bool $newDevice): int {
    g7_ld_install($db);
    $ua = g7_ld_ua(); $ip = g7_ld_ip();
    $sid = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO g7_login_history (user_id, sid, fp, label, ip, method, new_device, created_at, last_seen, last_ip) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)')
        ->execute([$uid, $sid, g7_ld_fp($ua), g7_ld_label($ua), $ip, $method, $newDevice ? 1 : 0, $ip]);
    $id = (int)$db->lastInsertId();
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['g7_login_sid'] = $sid;
    if (random_int(1, 50) === 1) {
        try {
            $db->exec('DELETE FROM g7_login_history WHERE created_at < NOW() - INTERVAL 180 DAY');
            $db->exec('DELETE FROM g7_ip_geo WHERE at < NOW() - INTERVAL 60 DAY');
            $db->exec('DELETE FROM g7_login_trusted WHERE expires_at < NOW()');
        } catch (Throwable $e) {}
    }
    return $id;
}
/** Completa la ubicación de una fila (lo hace después de responder). Devuelve [cc, lugar, lat, lon]. */
function g7_lh_locate(PDO $db, int $id, string $ip): array {
    $g = g7_geo_lookup($db, $ip);
    try { $db->prepare('UPDATE g7_login_history SET country_code = ?, place = ?, lat = ?, lon = ? WHERE id = ?')->execute([$g[0], $g[1], $g[2], $g[3], $id]); } catch (Throwable $e) {}
    return $g;
}
/** Corre $fn después de mandar la respuesta (en el hosting, con FastCGI); en el servidor de pruebas, al terminar el pedido. */
function g7_lh_later(callable $fn): void {
    register_shutdown_function(function () use ($fn) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // suelta la sesión: la página siguiente no espera a la ubicación
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { $fn(); } catch (Throwable $e) {}
    });
}

/**
 * Cada pedido con sesión (getCurrentUser): ¿esta sesión sigue abierta? false si la cerraron desde otro dispositivo
 * (el motivo queda en $GLOBALS['g7_lh_bye']: remote | not_me | password | admin).
 * Actualiza "última actividad" cada 5 minutos o si cambió la IP. Una sesión de antes de esta función se anota una vez.
 * Ante cualquier error devuelve true: nunca deja a nadie afuera por un problema del registro.
 */
function g7_lh_alive(): bool {
    static $r = null; if ($r !== null) return $r;
    if (empty($_SESSION['g7_user_id'])) return $r = true;
    try {
        $db = getDB();   // las tablas se crean al entrar; si todavía no existen (recién instalada la versión), se crean acá una vez
        $uid = (int)$_SESSION['g7_user_id']; $sid = (string)($_SESSION['g7_login_sid'] ?? '');
        $find = function () use ($db, $sid) { $q = $db->prepare('SELECT id, user_id, ended_at, ended_by, UNIX_TIMESTAMP(last_seen) ls, last_ip FROM g7_login_history WHERE sid = ?'); $q->execute([$sid]); return $q->fetch(PDO::FETCH_ASSOC) ?: null; };
        try { $row = $find(); } catch (PDOException $e) { g7_ld_install($db); $row = $find(); }
        if (!$row) {   // sesión abierta antes del registro (o fila ya borrada por antigüedad): se anota sin avisar
            $id = g7_lh_open($db, $uid, 'session', false); $ip = g7_ld_ip();
            g7_lh_later(fn() => g7_lh_locate($db, $id, $ip));
            return $r = true;
        }
        if ((int)$row['user_id'] !== $uid || $row['ended_at'] !== null) { $GLOBALS['g7_lh_bye'] = (string)$row['ended_by'] ?: 'remote'; return $r = false; }
        $ip = g7_ld_ip();
        if (time() - (int)$row['ls'] > 300 || $ip !== (string)$row['last_ip']) {
            $db->prepare('UPDATE g7_login_history SET last_seen = NOW(), last_ip = ? WHERE id = ?')->execute([$ip, (int)$row['id']]);
        }
        return $r = true;
    } catch (Throwable $e) { return $r = true; }
}
/** Al salir: la sesión de este navegador queda cerrada en el registro. */
function g7_lh_close_current(string $by = 'logout'): void {
    try {
        $sid = (string)($_SESSION['g7_login_sid'] ?? ''); if ($sid === '') return;
        $db = getDB(); g7_ld_install($db);
        $db->prepare('UPDATE g7_login_history SET ended_at = NOW(), ended_by = ? WHERE sid = ? AND ended_at IS NULL')->execute([$by, $sid]);
    } catch (Throwable $e) {}
}
/** Cierra las sesiones abiertas de una persona (todas menos la de $keepSid; con '' cierra todas). Devuelve cuántas cerró. */
function g7_lh_close_others(PDO $db, int $uid, string $keepSid, string $by): int {
    g7_ld_install($db);
    $q = $db->prepare('UPDATE g7_login_history SET ended_at = NOW(), ended_by = ? WHERE user_id = ? AND sid <> ? AND ended_at IS NULL');
    $q->execute([$by, $uid, $keepSid]);
    return $q->rowCount();
}

// ── Dispositivos de confianza (no piden el código de 2 pasos por 30 días) ───────────────
function g7_td_cookie(): string { return 'g7td_' . preg_replace('/[^A-Za-z0-9_]/', '', defined('SESSION_NAME') ? (string)SESSION_NAME : 'toolz'); }
/** ¿Está activada la opción en este sitio? (g7_config totp_remember = 0 la apaga) */
function g7_td_enabled(): bool { return !function_exists('g7_cfg') || g7_cfg('totp_remember', '1') !== '0'; }
/** Marca este navegador como de confianza para $uid: cookie con un token al azar (en la base solo queda su hash). */
function g7_td_issue(PDO $db, int $uid): void {
    if (!g7_td_enabled()) return;
    g7_ld_install($db);
    $tok = bin2hex(random_bytes(32)); $ua = g7_ld_ua(); $ip = g7_ld_ip();
    $db->prepare('INSERT INTO g7_login_trusted (user_id, token_hash, fp, label, ip, created_at, last_used, expires_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW() + INTERVAL ' . G7_TD_DAYS . ' DAY)')
        ->execute([$uid, hash('sha256', $tok), g7_ld_fp($ua), g7_ld_label($ua), $ip]);
    $id = (int)$db->lastInsertId();
    g7_lh_later(function () use ($db, $id, $ip) { $g = g7_geo_lookup($db, $ip); $db->prepare('UPDATE g7_login_trusted SET place = ? WHERE id = ?')->execute([$g[1], $id]); });
    if (!headers_sent()) setcookie(g7_td_cookie(), $tok, ['expires' => time() + G7_TD_DAYS * 86400, 'path' => '/toolz/',
        'secure' => function_exists('g7_is_https') && g7_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
}
/** ¿Este navegador es de confianza para $uid? Tiene que coincidir el token, la persona y el tipo de navegador, y no estar vencido. */
function g7_td_valid(PDO $db, int $uid): bool {
    if (!g7_td_enabled()) return false;
    $tok = (string)($_COOKIE[g7_td_cookie()] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return false;
    try {
        g7_ld_install($db);
        $q = $db->prepare('SELECT id, fp FROM g7_login_trusted WHERE token_hash = ? AND user_id = ? AND expires_at > NOW()'); $q->execute([hash('sha256', $tok), $uid]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r || !hash_equals((string)$r['fp'], g7_ld_fp(g7_ld_ua()))) return false;
        $db->prepare('UPDATE g7_login_trusted SET last_used = NOW() WHERE id = ?')->execute([(int)$r['id']]);
        return true;
    } catch (Throwable $e) { return false; }
}
/** Quita los dispositivos de confianza de una persona (todos, o uno por id). Devuelve cuántos quitó. */
function g7_td_revoke(PDO $db, int $uid, int $id = 0): int {
    try {
        g7_ld_install($db);
        $q = $id ? $db->prepare('DELETE FROM g7_login_trusted WHERE user_id = ? AND id = ?') : $db->prepare('DELETE FROM g7_login_trusted WHERE user_id = ?');
        $q->execute($id ? [$uid, $id] : [$uid]);
        return $q->rowCount();
    } catch (Throwable $e) { return 0; }
}

// ── Avisos (campana + email) ───────────────────────────────────────────────
/**
 * Aviso a la campana y email con la plantilla $key de Admin › Mails ($type: login_new | login_fail).
 * El email lleva botones con enlaces firmados de un solo uso (cuenta_segura.php): en un ingreso, "Fui yo" y "No fui yo";
 * en intentos fallidos, "Bloquear mi cuenta". Además, "Revisar en mi cuenta" (Mi perfil › Dónde iniciaste sesión).
 */
function g7_ld_notify(PDO $db, array $user, string $type, int $target, string $body, string $key, array $vars, string $infoHtml): void {
    $uid = (int)$user['id'];
    try { $db->prepare('INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, NULL, ?, ?, NULL, ?)')->execute([$uid, $type, $target, mb_substr($body, 0, 300)]); } catch (Throwable $e) {}   // body es VARCHAR(300)
    if (defined('G7_LD_NO_MAIL') || !filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) return;
    $mine = (function_exists('g7_site_origin') ? g7_site_origin() : '') . '/toolz/user_config.php' . ($type === 'login_new' && $target ? '?ingreso=' . $target : '') . '#sesiones';
    if ($type === 'login_new' && $target) {
        $t = g7_tok_new($db, $uid, 'review', $target, 24 * 7);
        $buttons = [['label' => 'Fui yo', 'url' => g7_tok_url($t) . '&a=me', 'style' => 'ghost'], ['label' => 'No fui yo', 'url' => g7_tok_url($t) . '&a=notme', 'style' => 'danger']];
    } else {
        $buttons = [['label' => 'Bloquear mi cuenta', 'url' => g7_tok_url(g7_tok_new($db, $uid, 'lock', 0, 24 * 7)), 'style' => 'danger']];
    }
    $buttons[] = ['label' => 'Revisar en mi cuenta', 'url' => $mine, 'style' => 'ghost'];
    g7_ld_mail($db, $user, $key, $vars, $buttons, $infoHtml);
}

/**
 * Registra el ingreso y, después de responder, busca la ubicación y avisa si hace falta:
 *  - dispositivo nuevo (no avisa en el primer ingreso de la cuenta);
 *  - país donde la cuenta nunca había entrado, aunque el dispositivo sea conocido (por ejemplo, una cookie robada).
 * Devuelve true si el ingreso es de un dispositivo nuevo que va a avisar. $method: password | 2fa | recovery | trusted.
 */
function g7_login_device(PDO $db, array $user, string $method = 'password'): bool {
    try {
        g7_ld_install($db);
        $uid = (int)$user['id']; $ua = g7_ld_ua(); $ip = g7_ld_ip();
        $fp = g7_ld_fp($ua); $label = g7_ld_label($ua);
        $q = $db->prepare('SELECT COUNT(*) FROM g7_login_devices WHERE user_id = ?'); $q->execute([$uid]); $known = (int)$q->fetchColumn();
        $q = $db->prepare('SELECT 1 FROM g7_login_devices WHERE user_id = ? AND fp = ?'); $q->execute([$uid, $fp]);
        $isNew = !$q->fetchColumn();
        if ($isNew) $db->prepare('INSERT INTO g7_login_devices (user_id, fp, label, first_seen, last_seen, last_ip) VALUES (?, ?, ?, NOW(), NOW(), ?)')->execute([$uid, $fp, $label, $ip]);
        else $db->prepare('UPDATE g7_login_devices SET last_seen = NOW(), last_ip = ? WHERE user_id = ? AND fp = ?')->execute([$ip, $uid, $fp]);
        $newDev = $isNew && $known > 0;   // el primer ingreso de la cuenta no avisa
        $hid = g7_lh_open($db, $uid, $method, $newDev);
        $when = date('d/m/Y H:i');
        g7_lh_later(function () use ($db, $user, $uid, $hid, $ip, $label, $when, $newDev, $known) {
            [$cc, $place] = g7_lh_locate($db, $hid, $ip);
            $kind = $newDev ? 'device' : '';
            if (!$kind && $known > 0 && $cc !== '') {   // dispositivo conocido desde un país nuevo para la cuenta
                $q = $db->prepare("SELECT SUM(country_code = ?) same, COUNT(*) total FROM g7_login_history WHERE user_id = ? AND id <> ? AND country_code <> ''");
                $q->execute([$cc, $uid, $hid]); $c = $q->fetch(PDO::FETCH_ASSOC);
                if ((int)$c['total'] > 0 && (int)$c['same'] === 0) $kind = 'country';
            }
            if (!$kind) return;
            $db->prepare('UPDATE g7_login_history SET alert = ? WHERE id = ?')->execute([$kind, $hid]);
            $where = $place !== '' && $place !== 'Red local' ? ", cerca de $place" : '';
            $body = $kind === 'device'
                ? "Ingresaron a tu cuenta desde $label$where (IP $ip) el $when. Si no fuiste vos, ciérrala desde Dónde iniciaste sesión y cambia tu contraseña."
                : "Ingresaron a tu cuenta desde un país donde nunca habías entrado: $label$where (IP $ip) el $when. Si no fuiste vos, ciérrala y cambia tu contraseña.";
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $info = '<b>' . $h($label) . '</b><br>' . ($place !== '' ? $h($place) . ($place !== 'Red local' ? ' (aproximado)' : '') . '<br>' : '') . 'IP ' . $h($ip) . '<br>' . $h($when);
            g7_ld_notify($db, $user, 'login_new', $hid, $body, $kind === 'device' ? 'sec.device' : 'sec.country', ['dispositivo' => $label, 'lugar' => $place, 'ip' => $ip, 'fecha' => $when], $info);
            if (function_exists('g7_audit')) g7_audit($kind === 'device' ? 'auth.new_device' : 'auth.new_country', 'user:' . $uid, ['ip' => $ip, 'device' => $label, 'place' => $place], $uid);
        });
        return $newDev;
    } catch (Throwable $e) { return false; }
}

/**
 * Intentos fallidos (lo llama g7_login_fail en auth.php después de anotar el fallo). Avisa a la persona:
 *  - con 8 contraseñas equivocadas en 15 minutos (alguien prueba entrar);
 *  - con 3 códigos de 2 pasos equivocados: la contraseña estaba bien, así que alguien la sabe.
 * Un aviso de cada tipo cada 6 horas como mucho. $key es lo que anotó g7_login_fail: el usuario/email escrito o "2fa:ID".
 */
function g7_ld_failed(PDO $db, string $key): void {
    try {
        if (preg_match('/^2fa:(\d+)$/', $key, $m)) { $kind = '2fa'; $where = 'id = ?'; $arg = (int)$m[1]; $min = 3; }
        elseif ($key !== '' && strpos($key, ':') === false) { $kind = 'pass'; $where = '(username = ? OR email = ?)'; $arg = $key; $min = 8; }
        else return;
        $w = defined('G7_LOGIN_WINDOW_MIN') ? G7_LOGIN_WINDOW_MIN : 15;
        $q = $db->prepare("SELECT COUNT(*) FROM g7_login_failures WHERE username = ? AND created_at > NOW() - INTERVAL $w MINUTE"); $q->execute([$key]);
        $n = (int)$q->fetchColumn();
        if ($n < $min) return;
        $q = $db->prepare("SELECT * FROM g7_users WHERE $where AND active = 1 LIMIT 1"); $q->execute($kind === 'pass' ? [$arg, $arg] : [$arg]);
        $user = $q->fetch(PDO::FETCH_ASSOC); if (!$user) return;
        $uid = (int)$user['id'];
        $tag = $kind === '2fa' ? 2 : 1;   // target_id del aviso: 1 = contraseñas, 2 = códigos (sirve para no repetirlo)
        $q = $db->prepare("SELECT 1 FROM g7_notifications WHERE user_id = ? AND type = 'login_fail' AND target_id = ? AND created_at > NOW() - INTERVAL 6 HOUR LIMIT 1");
        $q->execute([$uid, $tag]);
        if ($q->fetchColumn()) return;
        $ip = g7_ld_ip(); $label = g7_ld_label(g7_ld_ua()); $when = date('d/m/Y H:i');
        g7_ld_install($db);
        g7_lh_later(function () use ($db, $user, $uid, $kind, $n, $ip, $label, $when, $tag) {
            $place = g7_geo_lookup($db, $ip)[1];
            $where = $place !== '' && $place !== 'Red local' ? ", cerca de $place" : '';
            $body = $kind === '2fa'
                ? "Alguien escribió bien tu contraseña pero falló $n veces el código, desde $label$where (IP $ip) el $when. Si no fuiste vos, cámbiala ahora."
                : "Hubo $n intentos fallidos de entrar a tu cuenta desde $label$where (IP $ip) el $when. Si no fuiste vos, activa la verificación en dos pasos.";
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $info = '<b>' . $n . ' intentos fallidos</b><br>' . $h($label) . '<br>' . ($place !== '' ? $h($place) . ($place !== 'Red local' ? ' (aproximado)' : '') . '<br>' : '') . 'IP ' . $h($ip) . '<br>' . $h($when);
            g7_ld_notify($db, $user, 'login_fail', $tag, $body, $kind === '2fa' ? 'sec.fail_2fa' : 'sec.fail_pass', ['dispositivo' => $label, 'lugar' => $place, 'ip' => $ip, 'fecha' => $when, 'cantidad' => (string)$n], $info);
            if (function_exists('g7_audit')) g7_audit($kind === '2fa' ? 'auth.fail_2fa_alert' : 'auth.fail_alert', 'user:' . $uid, ['ip' => $ip, 'n' => $n, 'place' => $place], $uid);
        });
    } catch (Throwable $e) {}
}

// ── Enlaces firmados de un solo uso (en los emails) ─────────────────────────────
/** Crea un enlace para $purpose (review | lock | unlock) y devuelve el token (en la base queda solo su hash). */
function g7_tok_new(PDO $db, int $uid, string $purpose, int $ref, int $hours): string {
    g7_ld_install($db);
    $raw = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO g7_account_tokens (user_id, purpose, ref_id, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . max(1, $hours) . ' HOUR)')
        ->execute([$uid, $purpose, $ref, hash('sha256', $raw)]);
    if (random_int(1, 30) === 1) { try { $db->exec('DELETE FROM g7_account_tokens WHERE expires_at < NOW() - INTERVAL 30 DAY'); } catch (Throwable $e) {} }
    return $raw;
}
/** El enlace, si existe, no venció y no se usó. */
function g7_tok_find(PDO $db, string $raw): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    g7_ld_install($db);
    $q = $db->prepare('SELECT * FROM g7_account_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()'); $q->execute([hash('sha256', $raw)]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** Lo marca usado. Devuelve false si otro pedido lo usó antes (dos clics a la vez). */
function g7_tok_use(PDO $db, int $id): bool {
    $q = $db->prepare('UPDATE g7_account_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL'); $q->execute([$id]);
    return $q->rowCount() === 1;
}
function g7_tok_url(string $raw): string { return (function_exists('g7_site_origin') ? g7_site_origin() : '') . '/toolz/cuenta_segura.php?t=' . $raw; }

// ── Bloqueo de emergencia de la cuenta ─────────────────────────────────────
function g7_acct_locked(PDO $db, int $uid): bool {
    try { $q = $db->prepare('SELECT 1 FROM g7_account_locks WHERE user_id = ?'); $q->execute([$uid]); return (bool)$q->fetchColumn(); }
    catch (Throwable $e) { return false; }   // tabla todavía no creada: no hay bloqueos
}
/**
 * Bloquea la cuenta: cierra todas sus sesiones, quita los dispositivos de confianza y le manda un email para desbloquearla eligiendo
 * contraseña nueva (24 h; si vence, la libera un admin). $by: quién la bloqueó (la persona misma o un admin).
 */
function g7_acct_lock(PDO $db, int $uid, int $by, string $reason): void {
    g7_ld_install($db);
    $db->prepare('INSERT INTO g7_account_locks (user_id, locked_at, by_user, reason) VALUES (?, NOW(), ?, ?) ON DUPLICATE KEY UPDATE locked_at = NOW(), by_user = VALUES(by_user), reason = VALUES(reason)')->execute([$uid, $by, $reason]);
    g7_lh_close_others($db, $uid, '', 'locked');
    g7_td_revoke($db, $uid);
    if (function_exists('g7_audit')) g7_audit('auth.account_lock', 'user:' . $uid, ['reason' => $reason, 'ip' => g7_ld_ip()], $by ?: $uid);
    $q = $db->prepare('SELECT * FROM g7_users WHERE id = ?'); $q->execute([$uid]); $user = $q->fetch(PDO::FETCH_ASSOC);
    if (!$user) return;
    $url = g7_tok_url(g7_tok_new($db, $uid, 'unlock', 0, 24));
    g7_ld_mail($db, $user, 'sec.locked', ['fecha' => date('d/m/Y H:i')], [['label' => 'Elegir contraseña nueva y desbloquear', 'url' => $url]]);
}
function g7_acct_unlock(PDO $db, int $uid, int $by): void {
    try { $db->prepare('DELETE FROM g7_account_locks WHERE user_id = ?')->execute([$uid]); } catch (Throwable $e) {}
    try { $db->prepare("UPDATE g7_account_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'unlock' AND used_at IS NULL")->execute([$uid]); } catch (Throwable $e) {}
    if (function_exists('g7_audit')) g7_audit('auth.account_unlock', 'user:' . $uid, null, $by ?: $uid);
}

// ── Bloqueo de IP o país para entrar (lo decide un superadmin) ─────────────────
/** Bloqueos vigentes: [{id, kind: ip|country, value, note, by, at, until (0 = siempre)}]. Viven en g7_config (login_blocks). */
function g7_blk_list(): array {
    if (!function_exists('g7_cfg')) return [];
    $l = json_decode((string)g7_cfg('login_blocks', '[]'), true) ?: [];
    return array_values(array_filter($l, fn($b) => is_array($b) && (empty($b['until']) || (int)$b['until'] > time())));
}
function g7_blk_save(array $list): void { g7_cfg_set('login_blocks', array_values($list)); }
/** ¿Esta IP (o su país) tiene la entrada bloqueada? Devuelve el bloqueo o null. Busca el país solo si hay bloqueos de país. */
function g7_blk_match(string $ip): ?array {
    $list = g7_blk_list(); if (!$list) return null;
    foreach ($list as $b) if ($b['kind'] === 'ip' && $b['value'] === $ip) return $b;
    $cc = null;
    foreach ($list as $b) {
        if ($b['kind'] !== 'country') continue;
        if ($cc === null) { try { $cc = g7_geo_lookup(getDB(), $ip)[0]; } catch (Throwable $e) { $cc = ''; } }
        if ($cc !== '' && $b['value'] === $cc) return $b;
    }
    return null;
}

// ── Emails de seguridad (con la plantilla editable de Admin › Mails) ──────────────
/** Arma y manda un email de seguridad. $vars completa la plantilla ({dispositivo}, {lugar}, …); $buttons: [[label, url], …]. */
function g7_ld_mail(PDO $db, array $user, string $key, array $vars, array $buttons = [], string $infoHtml = ''): void {
    $email = (string)($user['email'] ?? '');
    if (defined('G7_LD_NO_MAIL') || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    require_once __DIR__ . '/mail_tpl_lib.php';
    $vars += ['nombre' => (string)(($user['display_name'] ?? '') ?: ($user['username'] ?? ''))];
    [$subject, $html] = g7_mail_build($key, $vars, ['info_html' => $infoHtml, 'buttons' => $buttons]);
    if (!function_exists('tk_mail_send') && is_file(__DIR__ . '/tareas_lib.php')) require_once __DIR__ . '/tareas_lib.php';
    if (function_exists('tk_mail_send')) { $GLOBALS['g7_mail_key'] = $key; @tk_mail_send($email, $subject, $html); unset($GLOBALS['g7_mail_key']); }
}
