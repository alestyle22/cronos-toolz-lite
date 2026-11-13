<?php
/**
 * login_alert_lib.php — Aviso de ingreso desde un dispositivo nuevo (2026-11-13, "hagamos el login más seguro").
 * Cada ingreso guarda una huella del navegador y el sistema (sin versiones, así una actualización del navegador no cuenta
 * como dispositivo nuevo). Si la persona entra desde uno que nunca usó, le llega un aviso a la campana y un email con la IP,
 * para que se dé cuenta si no fue ella. El primer ingreso de una cuenta no avisa. Nunca frena el ingreso: cualquier error se ignora.
 * Lo llama g7_login_finish() en auth.php. Doc: toolz/SEGURIDAD_LOGIN.md
 */

function g7_ld_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_login_devices (
        user_id INT NOT NULL,
        fp CHAR(64) NOT NULL,
        label VARCHAR(120) NOT NULL DEFAULT '',
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        last_ip VARCHAR(45) NOT NULL DEFAULT '',
        PRIMARY KEY (user_id, fp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** "Chrome en Windows", "Safari en iPhone"… (aproximado, solo para mostrar). */
function g7_ld_label(string $ua): string {
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

/**
 * Registra el ingreso y avisa si el dispositivo es nuevo. Devuelve true si avisó.
 * $send: envía el email (las pruebas lo apagan con G7_LD_NO_MAIL).
 */
function g7_login_device(PDO $db, array $user): bool {
    try {
        g7_ld_install($db);
        $uid = (int)$user['id']; $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); $ip = function_exists('g7_client_ip') ? g7_client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $fp = g7_ld_fp($ua); $label = g7_ld_label($ua);
        $q = $db->prepare('SELECT COUNT(*) FROM g7_login_devices WHERE user_id = ?'); $q->execute([$uid]); $known = (int)$q->fetchColumn();
        $q = $db->prepare('SELECT 1 FROM g7_login_devices WHERE user_id = ? AND fp = ?'); $q->execute([$uid, $fp]);
        if ($q->fetchColumn()) {
            $db->prepare('UPDATE g7_login_devices SET last_seen = NOW(), last_ip = ? WHERE user_id = ? AND fp = ?')->execute([mb_substr($ip, 0, 45), $uid, $fp]);
            return false;
        }
        $db->prepare('INSERT INTO g7_login_devices (user_id, fp, label, first_seen, last_seen, last_ip) VALUES (?, ?, ?, NOW(), NOW(), ?)')->execute([$uid, $fp, $label, mb_substr($ip, 0, 45)]);
        if ($known === 0) return false;   // el primer ingreso de la cuenta no avisa
        $when = date('d/m/Y H:i');
        $body = "Ingresaron a tu cuenta desde $label (IP $ip) el $when. Si fuiste vos, no hace falta hacer nada. Si no, cambiá tu contraseña y activá la verificación en dos pasos.";
        try { $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, profile_id, body) VALUES (?, NULL, 'login_new', 0, NULL, ?)")->execute([$uid, $body]); } catch (Throwable $e) {}
        if (function_exists('g7_audit')) g7_audit('auth.new_device', 'user:' . $uid, ['ip' => $ip, 'device' => $label], $uid);
        $email = (string)($user['email'] ?? '');
        if (!defined('G7_LD_NO_MAIL') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $site = function_exists('g7_site') ? (string)g7_site('name') : 'Toolz';
            $url = (function_exists('g7_site_origin') ? g7_site_origin() : '') . '/toolz/perfil_seguridad.php';
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $html = '<!doctype html><html><body style="margin:0;background:#000;padding:24px;font-family:Arial,sans-serif;color:#e5e5e5">'
                . '<div style="max-width:520px;margin:0 auto;background:#111;border:1px solid #222;border-radius:14px;padding:24px">'
                . '<h2 style="margin:0 0 10px;font-size:18px;color:#fff">Nuevo ingreso a tu cuenta</h2>'
                . '<p style="font-size:14px;line-height:1.6;margin:0 0 14px">Hola ' . $h($user['display_name'] ?: $user['username']) . ', alguien entró a tu cuenta de ' . $h($site) . ' desde un dispositivo que no habías usado:</p>'
                . '<p style="font-size:14px;line-height:1.7;margin:0 0 14px;background:#1a1a1a;border-radius:10px;padding:12px 14px"><b>' . $h($label) . '</b><br>IP ' . $h($ip) . '<br>' . $h($when) . '</p>'
                . '<p style="font-size:14px;line-height:1.6;margin:0 0 18px">Si fuiste vos, no hace falta hacer nada. Si no, cambiá tu contraseña ahora y activá la verificación en dos pasos.</p>'
                . '<a href="' . $h($url) . '" style="display:inline-block;background:#ff6600;color:#000;font-weight:700;text-decoration:none;padding:11px 18px;border-radius:9px">Revisar la seguridad de mi cuenta</a>'
                . '</div></body></html>';
            // Después de responder: el email no demora el ingreso
            register_shutdown_function(function () use ($email, $site, $html) {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                if (!function_exists('tk_mail_send') && is_file(__DIR__ . '/tareas_lib.php')) require_once __DIR__ . '/tareas_lib.php';
                if (function_exists('tk_mail_send')) @tk_mail_send($email, 'Nuevo ingreso a tu cuenta de ' . $site, $html);
            });
        }
        return true;
    } catch (Throwable $e) { return false; }
}
