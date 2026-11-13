<?php
/**
 * integraciones/google_oauth.php — Conectar el Google Calendar de una persona.
 *   ?start=1        (con sesión) lleva a Google a pedir permiso.
 *   ?code=…&state=… es la vuelta de Google (URI de redireccionamiento que se registra en Google Cloud). La cookie de
 *                   sesión es Strict y no llega en esta vuelta: la persona sale del state firmado (gcal.php).
 * Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/gcal.php';
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$page = function (string $title, string $msg, bool $ok) {
    $back = '/toolz/user_config.php#google';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0d0d0d;color:#eee;display:grid;place-items:center;min-height:100vh;margin:0}.b{max-width:440px;padding:28px;border:1px solid #2a2a2a;border-radius:14px;background:#141414;text-align:center}'
        . 'h1{font-size:19px;margin:0 0 10px;color:' . ($ok ? '#22c55e' : '#f87171') . '}p{color:#bbb;line-height:1.5;font-size:14px}a{display:inline-block;margin-top:10px;background:#ff6600;color:#fff;padding:9px 16px;border-radius:9px;text-decoration:none;font-weight:700}</style></head>'
        . '<body><div class="b"><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p><a id="v" href="' . $back . '"' . ($ok ? ' data-auto="1"' : '') . '>Volver a mi perfil</a></div>'
        // Navegar con JS desde esta página (mismo sitio) hace que la cookie Strict vuelva a viajar (google-oauth.js, sin código en línea)
        . ($ok ? '<script src="/toolz/integraciones/google-oauth.js?v=' . (int)@filemtime(__DIR__ . '/google-oauth.js') . '"></script>' : '') . '</body></html>';
    exit;
};
if (!int_ready('google_calendar')) $page('Google Calendar no está activo', 'Un administrador tiene que configurarlo en Admin › Integraciones.', false);

if (isset($_GET['start'])) {
    $me = requireLogin();
    $url = gc_auth_url((int)$me['id'], $nonce);
    // El regreso de Google tiene que llegar al mismo navegador que pidió el link (cookie Lax de 15 minutos):
    // un link reenviado a otra persona no conecta su calendario a esta cuenta.
    setcookie('g7_gc_nonce', $nonce, ['expires' => time() + 900, 'path' => '/', 'secure' => g7_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $url); exit;
}
$stParts = explode('.', (string)($_GET['state'] ?? ''));
if (count($stParts) !== 4 || !hash_equals((string)($_COOKIE['g7_gc_nonce'] ?? ''), $stParts[2])) $page('El link venció', 'Abre el link desde el mismo navegador donde tocaste "Conectar".', false);
setcookie('g7_gc_nonce', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => g7_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
$uid = gc_state_uid((string)($_GET['state'] ?? ''));
if (!$uid) $page('El link venció', 'Vuelve a tu perfil y toca "Conectar" otra vez.', false);
if (isset($_GET['error'])) { int_log('google_calendar', 'connect', false, "Persona $uid canceló en Google (" . substr((string)$_GET['error'], 0, 60) . ')'); $page('No se conectó', 'Cancelaste el permiso en Google. Puedes intentarlo de nuevo cuando quieras.', false); }
try {
    $u = gc_exchange($uid, (string)($_GET['code'] ?? ''));
    try { gc_sync_user(getDB(), $uid); } catch (Throwable $e) { error_log('[gcal] ' . $e->getMessage()); }
    $page('¡Listo!', 'Tu Google Calendar' . ($u['email'] ? ' (' . $u['email'] . ')' : '') . ' quedó conectado. Tus eventos de Toolz se copian solos.', true);
} catch (RuntimeException $e) { $page('No se conectó', $e->getMessage(), false); }
