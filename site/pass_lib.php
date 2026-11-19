<?php
/**
 * pass_lib.php — Rotación de contraseñas: el sitio puede pedir que cada persona renueve la suya cada X días
 * (Mi perfil › Seguridad, superadmin). Al vencer, auth.php (g7_pass_enforce) solo deja ir a password.php para cambiarla.
 * También es el único lugar para que alguien cambie su propia contraseña (password_api.php).
 * Doc: toolz/SEGURIDAD_2FA.md (Contraseñas)
 */
require_once __DIR__ . '/auth.php';

const G7_PASS_DAYS = [0 => 'Nunca', 30 => 'Cada 30 días', 60 => 'Cada 60 días', 90 => 'Cada 90 días', 180 => 'Cada 6 meses', 365 => 'Cada año'];

/** Columna pass_changed_at en g7_users (se agrega sola). */
function g7_pass_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $have = array_column($db->query('SHOW COLUMNS FROM g7_users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('pass_changed_at', $have, true)) $db->exec('ALTER TABLE g7_users ADD COLUMN pass_changed_at DATETIME NULL');
    } catch (Throwable $e) {}
}
function g7_pass_days(): int { $d = (int)g7_cfg('pass_max_days', '0'); return isset(G7_PASS_DAYS[$d]) ? $d : 0; }
/** Cambia la política. Al prenderla, cuenta desde hoy para quien no tiene fecha de cambio (nadie vence de golpe). */
function g7_pass_days_set(int $days, int $uid): void {
    if (!isset(G7_PASS_DAYS[$days])) $days = 0;
    if ($days > 0 && g7_pass_days() === 0) g7_cfg_set('pass_policy_since', (string)time());
    g7_cfg_set('pass_max_days', (string)$days);
    if (function_exists('g7_audit')) g7_audit('auth.pass_policy', (string)$days, null, $uid);
}
/** Días que le quedan a la contraseña de esta persona (0 o menos = vencida). null si el sitio no pide renovarlas. */
function g7_pass_days_left(PDO $db, int $uid): ?int {
    $days = g7_pass_days(); if (!$days) return null;
    g7_pass_install($db);
    $q = $db->prepare('SELECT pass_changed_at FROM g7_users WHERE id = ?'); $q->execute([$uid]); $at = $q->fetchColumn();
    $base = max($at ? (int)strtotime((string)$at) : 0, (int)g7_cfg('pass_policy_since', (string)time()));
    return (int)ceil(($base + $days * 86400 - time()) / 86400);
}
/** Aviso en la campana una vez por día cuando faltan 7 días o menos. */
function g7_pass_warn(PDO $db, int $uid, int $left): void {
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM g7_notifications WHERE user_id = ? AND type = 'pass' AND created_at >= CURDATE()"); $q->execute([$uid]);
        if ((int)$q->fetchColumn()) return;
        $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, body, created_at) VALUES (?, NULL, 'pass', 0, ?, NOW())")
           ->execute([$uid, $left <= 1 ? 'Tu contraseña vence mañana: cámbiala ahora.' : "Tu contraseña vence en $left días: cámbiala cuando puedas."]);
    } catch (Throwable $e) {}
}
/** Largo mínimo: el ajuste del sitio, nunca menos de 8. */
function g7_pass_min(): int { return max(8, (int)(function_exists('g7_setting') ? g7_setting('password_min_length') : 0)); }

/**
 * Cambia la contraseña de la propia persona: pide la actual, que la nueva tenga el largo mínimo y que no sea la misma.
 * Devuelve null si salió bien, o el motivo.
 */
/** Reglas de una contraseña nueva (largo mínimo, distinta de la actual, no trivial). null si está bien; si no, el motivo. */
function g7_pass_check_new(string $new, string $confirm, string $currentHash = ''): ?string {
    if ($new !== $confirm) return 'Las dos contraseñas nuevas no coinciden.';
    if (mb_strlen($new) < g7_pass_min()) return 'La nueva tiene que tener al menos ' . g7_pass_min() . ' caracteres.';
    if ($currentHash !== '' && password_verify($new, $currentHash)) return 'La nueva tiene que ser distinta de la actual.';
    if (preg_match('/^(.)\1+$/u', $new) || in_array(mb_strtolower($new), ['12345678', '123456789', 'password', 'contraseña', 'qwertyui', '11111111'], true)) return 'Esa contraseña es demasiado fácil de adivinar.';
    return null;
}

function g7_pass_change(PDO $db, int $uid, string $current, string $new, string $confirm): ?string {
    g7_pass_install($db);
    if (g7_login_locked('pw:' . $uid) > 0) return 'Demasiados intentos. Prueba de nuevo en unos minutos.';
    $q = $db->prepare('SELECT password FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$uid]); $hash = (string)$q->fetchColumn();
    if ($hash === '' || !password_verify($current, $hash)) { g7_login_fail('pw:' . $uid); return 'La contraseña actual no es correcta.'; }
    if ($why = g7_pass_check_new($new, $confirm, $hash)) return $why;
    $db->prepare('UPDATE g7_users SET password = ?, pass_changed_at = NOW() WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    unset($_SESSION['g7_pass_ok']);
    if (function_exists('g7_audit')) g7_audit('auth.pass_change', 'propia', null, $uid);
    return null;
}
