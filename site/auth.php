<?php
if (!defined('G7_SITE_ROOT')) define('G7_SITE_ROOT', __DIR__);   // raíz de toolz/ (para armar rutas de assets desde cualquier carpeta)
if (PHP_SAPI !== 'cli') header_remove('X-Powered-By');   // no anunciar la versión de PHP (la ve cualquiera en los encabezados)
// =============================================
// auth.php — Configura tus credenciales de BD
// =============================================
// config.local.php (solo en desarrollo, nunca se sube) puede redefinir cualquiera de estas constantes.
if (is_file(__DIR__ . '/config.local.php')) require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/site_lib.php';   // lo que cambia de un sitio a otro: _private/site.php
require_once __DIR__ . '/license_lib.php';   // licencia de la instalación (plan, topes, vencimiento): _private/license.json
require_once __DIR__ . '/tz_lib.php';   // zonas horarias: almacenamiento fijo y cómo se muestra a cada persona
g7_activation_guard();   // instalación nueva sin conectar a la red Cronos: solo activar.php y el login
g7_origin_guard();   // CSRF: un formulario o fetch de otro sitio no puede escribir acá
lic_guard();   // en solo lectura o sin espacio corta los pedidos que guardan algo

/**
 * Defensa CSRF general (además de SameSite y de las cabeceras propias de cada módulo): todo pedido que escribe
 * (POST, PUT, PATCH, DELETE) con cabecera Origin —o, si falta, Referer— de otro dominio se corta con 403.
 * Los avisos de pago y los pedidos entre servidores no mandan Origin, así que no se ven afectados.
 */
function g7_origin_guard(): void {
    if (PHP_SAPI === 'cli' || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '') $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($src === '') return;   // sin Origin ni Referer (servidores, webhooks, navegadores que no lo mandan): decide SameSite
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $p = $src === 'null' ? null : parse_url($src);
    $from = $p && !empty($p['host']) ? strtolower($p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')) : '';
    $norm = fn(string $h) => preg_replace('/:(80|443)$/', '', $h);
    if ($from !== '' && $norm($from) === $norm($host)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Pedido rechazado: viene de otro sitio.']);
    exit;
}
if (!defined('DB_HOST')) define('DB_HOST', g7_site('db_host', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', g7_site('db_name'));
if (!defined('DB_USER')) define('DB_USER', g7_site('db_user'));
// Las claves (MySQL, APIs) están en _private/secrets.php: carpeta bloqueada para la web, una sola copia.
if (is_file(__DIR__ . '/_private/secrets.php')) require_once __DIR__ . '/_private/secrets.php';
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('SESSION_NAME')) define('SESSION_NAME', g7_site('session_name'));
define('SESSION_LIFETIME', 60 * 60 * 8); // valor por defecto; el admin lo cambia en Configuración

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    // En hosting compartido MySQL limita las conexiones simultáneas: con mucha gente a la vez una conexión puede rebotar
    // un instante. Se reintenta dos veces (0,2 s y 0,5 s) antes de rendirse; si igual no hay, 503 + Retry-After para que
    // el navegador (chat, notificaciones) espere y vuelva a probar en vez de insistir. Prueba de carga: toolz/CARGA.md
    foreach ([0, 200000, 500000] as $i => $wait) {
        if ($wait) usleep($wait);
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]   // tope por intento si la base no contesta
            );
            break;
        } catch (PDOException $e) {
            if ($i === 2) {
                if (!headers_sent()) { http_response_code(503); header('Retry-After: 5'); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
                die(json_encode(['ok' => false, 'busy' => true, 'error' => 'El servidor está muy ocupado. Vuelve a intentar en unos segundos.']));
            }
        }
    }
    g7_tz_boot($pdo);   // PHP y MySQL escriben en la misma zona (la de almacenamiento): tz_lib.php, ZONAS_HORARIAS.md
    return $pdo;
}

/** ¿El pedido llegó por HTTPS? (directo o detrás de un proxy/Cloudflare que lo avisa) */
function g7_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);      // no acepta ids de sesión inventados (fijación de sesión)
    ini_set('session.use_only_cookies', 1);
    if (g7_is_https()) {
        ini_set('session.cookie_secure', 1);    // la cookie nunca viaja por HTTP
        if (!headers_sent()) header('Strict-Transport-Security: max-age=15552000');
    }
    $life = SESSION_LIFETIME;
    if (function_exists('g7_setting')) {
        $h = (int)g7_setting('session_hours');
        if ($h >= 1 && $h <= 720) $life = $h * 3600;
    }
    ini_set('session.gc_maxlifetime', (string)$life);
    session_name(SESSION_NAME);
    session_set_cookie_params($life);
    session_start();
    // CSP en modo "solo informar", si un superadmin prendió la medición (Mi perfil › Seguridad)
    require_once __DIR__ . '/csp_lib.php'; g7_csp_header();
}

function getCurrentUser() {
    startSecureSession();
    if (empty($_SESSION['g7_user_id'])) return null;
    // Una sesión ya renovada sigue valiendo 2 minutos (pedidos que salieron con el id viejo, como el chat) y después muere
    if (!empty($_SESSION['g7_replaced'])) {
        if (time() - (int)$_SESSION['g7_replaced'] > 120) { $_SESSION = []; session_destroy(); return null; }
    }
    // Renovación periódica del id. No se borra la vieja en el acto: si se borrara, un pedido simultáneo con el id viejo
    // recibiría (con use_strict_mode) una sesión nueva vacía, su cookie pisaría la buena y la persona quedaría afuera.
    elseif (empty($_SESSION['g7_last_regen']) || time() - $_SESSION['g7_last_regen'] > 300) {
        $_SESSION['g7_replaced'] = time();        // queda grabado en la copia vieja
        session_regenerate_id(false);
        unset($_SESSION['g7_replaced']);          // la nueva no lo lleva
        $_SESSION['g7_last_regen'] = time();
    }
    $user = [
        'id'       => $_SESSION['g7_user_id'],
        'username' => $_SESSION['g7_username'],
        'role'     => $_SESSION['g7_role'],
    ];
    // El rol se lee de la base: si un admin lo cambia, rige en la próxima página sin re-login.
    $ctx = g7_ctx((int)$user['id']);
    if ($ctx['role'] && $ctx['role'] !== $user['role']) { $user['role'] = $ctx['role']; $_SESSION['g7_role'] = $ctx['role']; }
    g7_enforce_access($user); // mantenimiento, usuario desactivado y acceso al módulo de esta página
    g7_pass_enforce($user);   // contraseña vencida (rotación): solo la página para cambiarla
    if (is_file(__DIR__ . '/onboarding_lib.php')) { require_once __DIR__ . '/onboarding_lib.php'; ob_enforce($user); }   // bienvenida y configuración inicial (ONBOARDING.md)
    g7_totp_enforce($user);   // sin verificación en dos pasos, si el sitio se la exige: solo Mi perfil para activarla
    return $user;
}

/**
 * ¿Instalación nueva todavía sin conectar a la red Cronos? La marca la deja instalar.php y la borra _update/remote.php
 * con el primer pedido firmado del base. Las instalaciones de antes no la tienen: nunca se bloquean.
 */
function g7_activation_pending(): bool {
    $d = __DIR__ . '/_private/updates';
    return is_file("$d/activation_pending") && !is_file("$d/central_seen");
}
/** Mientras esté pendiente no se usa nada: páginas, APIs, portal, tienda y checkout. Solo el login y activar.php. */
function g7_activation_guard(): void {
    if (PHP_SAPI === 'cli' || !g7_activation_pending()) return;
    $script = strtr((string)($_SERVER['SCRIPT_NAME'] ?? ''), '\\', '/');
    if (preg_match('#/toolz/(activar|login|logout|csp_report)\.php$#', $script)) return;
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($json) { http_response_code(423); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Esta instalación todavía no está activada: falta conectarla a la red Cronos.']); exit; }
    header('Location: /toolz/activar.php'); exit;
}

/** Contraseña vencida por la rotación del sitio (pass_lib.php): mientras no la cambie, solo puede ir a cambiarla. */
function g7_pass_enforce(array $user): void {
    static $done = false; if ($done) return; $done = true;
    if (PHP_SAPI === 'cli' || !function_exists('g7_cfg') || (int)g7_cfg('pass_max_days', '0') <= 0) return;
    $uid = (int)$user['id'];
    if (($_SESSION['g7_pass_ok'][0] ?? 0) === $uid && ($_SESSION['g7_pass_ok'][1] ?? 0) > time()) return;
    require_once __DIR__ . '/pass_lib.php';
    $left = g7_pass_days_left(getDB(), $uid);
    if ($left === null || $left > 0) { $_SESSION['g7_pass_ok'] = [$uid, time() + 1800]; if ($left !== null && $left <= 7) g7_pass_warn(getDB(), $uid, $left); return; }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['password.php', 'password_api.php', 'logout.php', 'login.php', 'ping.php', 'notif.php', 'track.php', 'activar.php'], true)) return;
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($json) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Tu contraseña venció: cámbiala para seguir.']); exit; }
    header('Location: /toolz/password.php?vencida=1'); exit;
}

/** Si el sitio exige la verificación en dos pasos (a todas las personas, a los admins o a los superadmins) y esta persona no la tiene, solo puede activarla. */
function g7_totp_enforce(array $user): void {
    static $done = false; if ($done) return; $done = true;
    if (PHP_SAPI === 'cli' || !function_exists('g7_cfg')) return;
    require_once __DIR__ . '/totp_lib.php';
    if (g7_totp_scope() === 'none') return;
    $uid = (int)$user['id'];
    $ck = $uid . '|' . g7_totp_scope();   // lo comprobado vale para esta persona y este ajuste: si se sube la exigencia, rige enseguida
    if (($_SESSION['g7_totp_ok'] ?? '') === $ck) return;
    if (!g7_totp_required_for($uid) || g7_totp_enabled(getDB(), $uid)) { $_SESSION['g7_totp_ok'] = $ck; return; }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['activar_2fa.php', 'user_config.php', 'totp_api.php', 'logout.php', 'login.php', 'ping.php', 'notif.php', 'track.php', 'password.php', 'password_api.php', 'activar.php', 'bienvenida.php', 'onboarding_api.php'], true)) return;
    $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($json) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Activa la verificación en dos pasos en Mi perfil › Seguridad para seguir.']); exit; }
    // Guía paso a paso (activar_2fa.php); al terminar vuelve a la página que quería abrir
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/toolz/');
    header('Location: /toolz/activar_2fa.php' . (preg_match('#^/toolz/#', $uri) ? '?next=' . rawurlencode($uri) : '')); exit;
}

function requireLogin() {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: /toolz/login.php');   // relativa: el navegador se queda en el mismo dominio (no depende de la cabecera Host)
        exit;
    }
    g7_enforce_access($user);
    return $user;
}

function requireAdmin() {
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('Acceso denegado.');
    }
    return $user;
}

function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

// ── Freno a la fuerza bruta ─────────────────────────────────
// Cuenta los intentos fallidos de los últimos 15 minutos, por usuario (sin importar la IP) y por IP.
const G7_LOGIN_WINDOW_MIN   = 15;
const G7_LOGIN_MAX_USER_IP = 8;    // fallos para un mismo usuario desde una misma IP
const G7_LOGIN_MAX_USER    = 40;   // fallos para un mismo usuario desde cualquier IP (frena el ataque repartido sin dejar que un tercero bloquee la cuenta con pocos intentos)
const G7_LOGIN_MAX_IP      = 25;   // fallos desde una misma IP, con cualquier usuario

/** Rangos publicados de Cloudflare: solo si el pedido viene de ahí se cree la cabecera CF-Connecting-IP. */
const G7_CF_RANGES = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14',
    '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32'];

function g7_ip_in_cidr(string $ip, string $cidr): bool {
    [$net, $bits] = explode('/', $cidr); $a = @inet_pton($ip); $n = @inet_pton($net);
    if ($a === false || $n === false || strlen($a) !== strlen($n)) return false;
    $bits = (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
    if (strncmp($a, $n, $bytes) !== 0) return false;
    if ($rest === 0) return true;
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($a[$bytes] & $mask) === ($n[$bytes] & $mask));
}

function g7_client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = $remote;
    // Detrás de Cloudflare la IP real viene en CF-Connecting-IP, pero solo vale si quien la manda es Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        foreach (G7_CF_RANGES as $r) if (g7_ip_in_cidr($remote, $r)) { $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP']; break; }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function g7_login_table(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_login_failures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(190) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (username, created_at), INDEX idx_ip (ip, created_at)
    )");
}

/** Minutos que faltan para poder volver a intentar (0 = puede intentar). */
function g7_login_locked(string $username): int {
    try {
        $db = getDB(); g7_login_table($db);
        $u = mb_strtolower(trim($username)); $ip = g7_client_ip(); $w = G7_LOGIN_WINDOW_MIN;
        $q = $db->prepare("SELECT COUNT(*) c, MIN(created_at) f FROM g7_login_failures WHERE username=? AND created_at > NOW() - INTERVAL $w MINUTE");
        $q->execute([$u]); $byUser = $q->fetch();
        $q = $db->prepare("SELECT COUNT(*) c, MIN(created_at) f FROM g7_login_failures WHERE username=? AND ip=? AND created_at > NOW() - INTERVAL $w MINUTE");
        $q->execute([$u, $ip]); $byUserIp = $q->fetch();
        $q = $db->prepare("SELECT COUNT(*) c, MIN(created_at) f FROM g7_login_failures WHERE ip=? AND created_at > NOW() - INTERVAL $w MINUTE");
        $q->execute([$ip]); $byIp = $q->fetch();
        $first = null;
        if ((int)$byUserIp['c'] >= G7_LOGIN_MAX_USER_IP) $first = $byUserIp['f'];
        if ((int)$byUser['c'] >= G7_LOGIN_MAX_USER && (!$first || $byUser['f'] < $first)) $first = $byUser['f'];
        if ((int)$byIp['c'] >= G7_LOGIN_MAX_IP && (!$first || $byIp['f'] < $first)) $first = $byIp['f'];
        if (!$first) return 0;
        $left = (strtotime($first) + $w * 60 - time()) / 60;
        return max(1, (int)ceil($left));
    } catch (Exception $e) { return 0; }
}

function g7_login_fail(string $username): void {
    try {
        $db = getDB(); g7_login_table($db);
        $db->prepare('INSERT INTO g7_login_failures (username, ip) VALUES (?,?)')->execute([mb_strtolower(trim($username)), g7_client_ip()]);
        // Registro de seguridad: cuando un usuario del equipo queda bloqueado por intentos (no las cuentas de tienda ni portal)
        if (strpos($username, ':') === false && g7_login_locked($username) > 0 && function_exists('g7_audit'))
            g7_audit('auth.locked', 'login:' . mb_substr(mb_strtolower(trim($username)), 0, 80), ['ip' => g7_client_ip()], 0);
        if (random_int(1, 50) === 1) $db->exec('DELETE FROM g7_login_failures WHERE created_at < NOW() - INTERVAL 2 DAY');
    } catch (Exception $e) {}
}

function login($username, $password) {
    if (g7_login_locked((string)$username) > 0) return false;
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM g7_users WHERE (username = ? OR email = ?) AND active = 1 LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password'])) {
        if (!$user) password_verify($password, '$2y$10$AB4aski/tfIhhmG6LZbb5uO.LRFrne3OEabhMmVLWBxzddH0FDIVC'); // mismo tiempo de respuesta exista o no el usuario
        g7_login_fail((string)$username);
        return false;
    }
    try { $db->prepare('DELETE FROM g7_login_failures WHERE username=?')->execute([mb_strtolower(trim($username))]); } catch (Exception $e) {}
    // Verificación en dos pasos: la contraseña sola no abre la sesión; queda pendiente el código (5 minutos)
    require_once __DIR__ . '/totp_lib.php';
    if (g7_totp_enabled($db, (int)$user['id'])) {
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION = ['g7_2fa' => ['uid' => (int)$user['id'], 'at' => time()]];
        return ['need_2fa' => true];
    }
    return g7_login_finish($user);
}

/** Segundo paso del login. Devuelve el usuario, o 'expired' | 'locked' | false (código incorrecto). */
function g7_login_2fa(string $code) {
    startSecureSession();
    $p = $_SESSION['g7_2fa'] ?? null;
    if (!$p || time() - (int)$p['at'] > 300) { unset($_SESSION['g7_2fa']); return 'expired'; }
    $uid = (int)$p['uid']; $key = '2fa:' . $uid;
    if (g7_login_locked($key) > 0) return 'locked';
    require_once __DIR__ . '/totp_lib.php';
    $db = getDB();
    $how = g7_totp_check($db, $uid, $code);
    if (!$how) { g7_login_fail($key); return false; }
    try { $db->prepare('DELETE FROM g7_login_failures WHERE username=?')->execute([$key]); } catch (Exception $e) {}
    $q = $db->prepare('SELECT * FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$uid]); $user = $q->fetch();
    unset($_SESSION['g7_2fa']);
    if (!$user) return 'expired';
    if ($how === 'recovery' && function_exists('g7_audit')) g7_audit('auth.2fa_recovery', 'user:' . $uid, ['ip' => g7_client_ip()], $uid);
    return g7_login_finish($user);
}

/** Abre la sesión del usuario (después de la contraseña y, si lo tiene, del código). */
function g7_login_finish(array $user) {
    $db = getDB();
    // Update last login
    $db->prepare('UPDATE g7_users SET last_login = NOW(), last_seen = NOW() WHERE id = ?')->execute([$user['id']]);
    startSecureSession();
    session_regenerate_id(true);
    unset($_SESSION['g7_2fa']);
    $_SESSION['g7_user_id']  = $user['id'];
    $_SESSION['g7_username'] = $user['username'];
    $_SESSION['g7_role']     = $user['role'];
    $_SESSION['g7_last_regen'] = time();
    if (function_exists('g7_audit')) g7_audit('auth.login', 'user:' . (int)$user['id'], ['ip' => g7_client_ip(), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)], (int)$user['id']);
    return $user;
}

function logout() {
    startSecureSession();
    $_SESSION = [];
    $cp = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cp['path'], 'domain' => $cp['domain'], 'secure' => $cp['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    session_destroy();
    header('Location: ' . (defined('G7_BASE') ? G7_BASE : '/toolz/') . 'login.php');
    exit;
}

// ═══════════════════════════════════════════════════════
// SISTEMA DE EQUIPOS Y PERMISOS — Fase 1
// ═══════════════════════════════════════════════════════

/**
 * Devuelve todos los equipos del usuario con su rol
 */
function getUserTeams(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT t.*, tm.role as team_role, tm.is_primary
            FROM g7_teams t
            JOIN g7_team_members tm ON tm.team_id = t.id
            WHERE tm.user_id = ? AND t.active = 1
            ORDER BY tm.is_primary DESC, t.name ASC
        ");
        $stmt->execute([$userId]);
        $cache[$userId] = $stmt->fetchAll();
    } catch (Exception $e) {
        $cache[$userId] = [];
    }
    return $cache[$userId];
}

/**
 * Devuelve el equipo principal del usuario
 */
function getPrimaryTeam(int $userId): ?array {
    $teams = getUserTeams($userId);
    foreach ($teams as $t) {
        if ($t['is_primary']) return $t;
    }
    return $teams[0] ?? null;
}

/**
 * Verifica si el usuario tiene un permiso en cualquiera de sus equipos
 * Los admins globales siempre tienen todos los permisos
 * Si el usuario está en múltiples equipos, los permisos de escritura prevalecen
 */
function hasPermission(int $userId, string $module, string $action, string $role = ''): bool {
    // Reglas completas en access.php (modo del módulo, equipos y excepciones por usuario).
    return g7_can($userId, $module, $action);
}

/**
 * Devuelve todos los permisos del usuario como array plano
 * [['module'=>'promos','action'=>'create'], ...]
 */
function getUserPermissions(int $userId, string $role = ''): array {
    $out = [];
    foreach (g7_modules() as $id => $m) {
        foreach ($m['actions'] as $a) if (g7_can($userId, $id, $a)) $out[] = ['module' => $id, 'action' => $a];
    }
    return $out;
}

/**
 * Verifica si el usuario es admin de un equipo específico
 */
function isTeamAdmin(int $userId, int $teamId): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT role FROM g7_team_members WHERE user_id=? AND team_id=?");
        $stmt->execute([$userId, $teamId]);
        $role = $stmt->fetchColumn();
        return $role === 'admin';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Devuelve la config de topbar del equipo principal del usuario
 * Fallback a la config global si no tiene equipo o config de equipo
 */
function getTeamTopbarConfig(int $userId): array {
    try {
        $db       = getDB();
        $primary  = getPrimaryTeam($userId);
        if (!$primary) return [];
        $stmt = $db->prepare("SELECT * FROM g7_team_config WHERE team_id=?");
        $stmt->execute([$primary['id']]);
        $cfg = $stmt->fetch();
        return $cfg ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Helper: devuelve array de permisos como mapa para JS
 * ['promos.view'=>true, 'promos.create'=>true, ...]
 */
function getUserPermissionsMap(int $userId, string $role = ''): array {
    $perms = getUserPermissions($userId, $role);
    $map   = [];
    foreach ($perms as $p) {
        $map[$p['module'] . '.' . $p['action']] = true;
    }
    return $map;
}

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/vocab_lib.php';    // cómo se llaman las cosas según el rubro (g7_term)
require_once __DIR__ . '/status_lib.php';   // estados editables (st_list)
