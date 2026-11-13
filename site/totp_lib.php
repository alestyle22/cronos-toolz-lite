<?php
/**
 * totp_lib.php — Verificación en dos pasos (TOTP, RFC 6238) para la intranet (2026-11-10, auditoría de seguridad).
 * - Códigos de 6 dígitos cada 30 s (Google Authenticator, Authy, 1Password, Microsoft Authenticator…).
 * - Acepta el código anterior y el siguiente (±30 s de reloj) y nunca el mismo dos veces (totp_last).
 * - 8 códigos de respaldo de un solo uso, guardados con password_hash.
 * - "Exigir a los superadmins" (g7_config totp_required_super = 1): un superadmin sin el segundo paso solo puede entrar a Mi perfil para activarlo.
 * Si alguien pierde el teléfono y los códigos: otro superadmin se lo quita en Mi perfil › Seguridad,
 * o, con acceso a la base: UPDATE g7_users SET totp_on = 0, totp_secret = '' WHERE id = …;
 * Doc: toolz/SEGURIDAD_2FA.md
 */
const G7_TOTP_STEP = 30;
const G7_TOTP_DIGITS = 6;
const G7_TOTP_ISSUER_MAX = 40;

/** Columnas en g7_users (se agregan solas la primera vez). */
function g7_totp_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    $have = [];
    try { foreach ($db->query('SHOW COLUMNS FROM g7_users')->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true; } catch (Throwable $e) { return; }
    foreach (['totp_secret' => "VARCHAR(255) NOT NULL DEFAULT ''", 'totp_on' => 'TINYINT(1) NOT NULL DEFAULT 0', 'totp_last' => 'BIGINT NOT NULL DEFAULT 0', 'totp_recovery' => 'TEXT NULL'] as $col => $def)
        if (!isset($have[$col])) try { $db->exec("ALTER TABLE g7_users ADD COLUMN $col $def"); } catch (Throwable $e) {}
    // El secreto se guarda cifrado (g7_totp_seal): no entra en 64 caracteres
    try { $len = (int)$db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'g7_users' AND COLUMN_NAME = 'totp_secret'")->fetchColumn();
          if ($len && $len < 255) $db->exec("ALTER TABLE g7_users MODIFY totp_secret VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
}

// ── Secreto cifrado en la base (2026-11-13, onboarding: "nunca guardar el secreto TOTP sin cifrar") ──
// AES-256-GCM con una clave que vive fuera de la base: _private/totp_key.php (se crea sola; _private nunca viaja en las versiones).
// Formato guardado: "v1:" + base64(iv 12 bytes + etiqueta 16 bytes + cifrado). Los secretos viejos en texto plano se cifran al leerlos.
function g7_totp_key(): string {
    static $k = null; if ($k !== null) return $k;
    if (defined('G7_TOTP_KEY')) return $k = hash('sha256', (string)G7_TOTP_KEY, true);   // pruebas
    $f = __DIR__ . '/_private/totp_key.php';
    $raw = is_file($f) ? (string)(include $f) : '';
    if ($raw === '') {
        $raw = base64_encode(random_bytes(32));
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0755, true);
        if (@file_put_contents($f, "<?php
// Clave para cifrar los secretos de la verificación en dos pasos. NO borrarla ni cambiarla: sin ella nadie puede entrar con su app.
// Copiarla junto con los respaldos de la base.
return " . var_export($raw, true) . ";
", LOCK_EX) === false)
            throw new RuntimeException('No se pudo guardar la clave de la verificación en dos pasos');
        @chmod($f, 0600);
    }
    return $k = hash('sha256', $raw, true);
}
function g7_totp_seal(string $b32): string {
    if ($b32 === '') return '';
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($b32, 'aes-256-gcm', g7_totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('No se pudo cifrar el secreto');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
/** Secreto en base32 a partir de lo guardado ('' si no se puede descifrar). */
function g7_totp_unseal(string $stored): string {
    if ($stored === '' || strncmp($stored, 'v1:', 3) !== 0) return $stored;   // texto plano de antes
    $bin = base64_decode(substr($stored, 3), true);
    if ($bin === false || strlen($bin) < 29) return '';
    try { $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', g7_totp_key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16)); } catch (Throwable $e) { $pt = false; }
    // Si no se puede descifrar (clave perdida o cambiada) NO se devuelve vacío: eso dejaría entrar sin el segundo paso.
    // Se devuelve una marca inválida: la verificación sigue "activa" y ningún código coincide (hay que quitársela desde Seguridad).
    return $pt === false ? '!' : $pt;
}

// ── Base32 (RFC 4648, sin relleno) ─────────────────────────
function g7_b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function g7_b32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = ''; $out = '';
    foreach (str_split($s) as $ch) { $p = strpos($a, $ch); if ($p === false) return ''; $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** Código de un paso de tiempo (HOTP con contador = paso). */
function g7_totp_code(string $secretB32, int $step): string {
    $key = g7_b32_decode($secretB32);
    $h = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), $key, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % (10 ** G7_TOTP_DIGITS)), G7_TOTP_DIGITS, '0', STR_PAD_LEFT);
}
/** Paso en el que el código es válido (±1), o null. $last: último paso usado (no se repite). */
function g7_totp_match(string $secretB32, string $code, int $last = 0, ?int $now = null): ?int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== G7_TOTP_DIGITS || $secretB32 === '' || g7_b32_decode($secretB32) === '') return null;   // sin clave válida, ningún código sirve
    $t = intdiv($now ?? time(), G7_TOTP_STEP);
    foreach ([0, -1, 1] as $d) { $s = $t + $d; if ($s > $last && hash_equals(g7_totp_code($secretB32, $s), $code)) return $s; }
    return null;
}
function g7_totp_new_secret(): string { return g7_b32_encode(random_bytes(20)); }
/** Enlace otpauth:// para el QR de la app. */
function g7_totp_uri(string $secret, string $account): string {
    $issuer = mb_substr((string)(function_exists('g7_setting') ? (g7_setting('platform_name') ?: g7_site('name')) : 'Toolz'), 0, G7_TOTP_ISSUER_MAX);
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
/** 8 códigos de respaldo: [visibles, guardables]. */
function g7_totp_recovery_new(): array {
    $plain = []; $hashed = [];
    for ($i = 0; $i < 8; $i++) { $c = strtoupper(bin2hex(random_bytes(5))); $c = substr($c, 0, 5) . '-' . substr($c, 5); $plain[] = $c; $hashed[] = password_hash($c, PASSWORD_DEFAULT); }
    return [$plain, $hashed];
}

// ── Estado por persona ─────────────────────────────────────
function g7_totp_row(PDO $db, int $uid): array {
    g7_totp_install($db);
    $q = $db->prepare('SELECT id, username, password, totp_secret, totp_on, totp_last, totp_recovery FROM g7_users WHERE id = ?'); $q->execute([$uid]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($r && ($r['totp_secret'] ?? '') !== '') {
        $stored = (string)$r['totp_secret'];
        $r['totp_secret'] = g7_totp_unseal($stored);   // los que llaman reciben siempre el secreto en base32
        if (strncmp($stored, 'v1:', 3) !== 0 && $r['totp_secret'] !== '')   // de antes, en texto plano: se cifra ahora
            try { $db->prepare('UPDATE g7_users SET totp_secret = ? WHERE id = ? AND totp_secret = ?')->execute([g7_totp_seal($r['totp_secret']), $uid, $stored]); } catch (Throwable $e) {}
    }
    return $r;
}
function g7_totp_enabled(PDO $db, int $uid): bool { $r = g7_totp_row($db, $uid); return !empty($r['totp_on']) && ($r['totp_secret'] ?? '') !== ''; }
/** A quién exige el sitio la verificación en dos pasos. Por defecto, a todas las personas. */
const G7_TOTP_SCOPES = ['all' => 'Todas las personas', 'admins' => 'Admins y superadmins', 'super' => 'Solo los superadmins', 'none' => 'A nadie (cada persona decide)'];
const G7_TOTP_RANK = ['none' => 0, 'super' => 1, 'admins' => 2, 'all' => 3];
function g7_totp_scope(): string {
    $v = function_exists('g7_cfg') ? (string)g7_cfg('totp_required', '') : '';
    return isset(G7_TOTP_SCOPES[$v]) ? $v : 'all';
}
function g7_totp_required_for(int $uid): bool {
    $sc = g7_totp_scope();
    if ($sc === 'none') return false;
    if ($sc === 'all') return true;
    $super = function_exists('g7_is_super') && g7_is_super($uid);
    if ($sc === 'super') return $super;
    if ($super) return true;
    try { $q = getDB()->prepare('SELECT role FROM g7_users WHERE id = ?'); $q->execute([$uid]); return $q->fetchColumn() === 'admin'; } catch (Throwable $e) { return false; }
}
/**
 * Comprueba un código (de la app o de respaldo) y lo marca como usado. Devuelve 'app', 'recovery' o null.
 */
function g7_totp_check(PDO $db, int $uid, string $code): ?string {
    $r = g7_totp_row($db, $uid);
    if (empty($r['totp_on']) || ($r['totp_secret'] ?? '') === '') return null;
    $code = trim($code);
    if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code))) {
        $s = g7_totp_match((string)$r['totp_secret'], $code, (int)$r['totp_last']);
        if ($s === null) return null;
        // Solo uno gana si llegan dos pedidos con el mismo código a la vez
        $u = $db->prepare('UPDATE g7_users SET totp_last = ? WHERE id = ? AND totp_last < ?'); $u->execute([$s, $uid, $s]);
        return $u->rowCount() ? 'app' : null;
    }
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (strlen($norm) !== 10) return null;
    $norm = substr($norm, 0, 5) . '-' . substr($norm, 5);
    $list = json_decode((string)($r['totp_recovery'] ?? '[]'), true) ?: [];
    foreach ($list as $i => $h) if (password_verify($norm, (string)$h)) {
        unset($list[$i]);
        $db->prepare('UPDATE g7_users SET totp_recovery = ? WHERE id = ?')->execute([json_encode(array_values($list)), $uid]);
        return 'recovery';
    }
    return null;
}
function g7_totp_disable(PDO $db, int $uid): void {
    g7_totp_install($db);
    $db->prepare("UPDATE g7_users SET totp_on = 0, totp_secret = '', totp_last = 0, totp_recovery = NULL WHERE id = ?")->execute([$uid]);
}
