<?php
/**
 * license_lib.php — Licencia de esta instalación: plan, vencimiento y topes (usuarios, clientes, espacio, módulos).
 * La manda el sitio base por el canal firmado (_update/remote.php, acción "license") y queda en _private/license.json.
 * Sin ese archivo el sitio no tiene límites: así siguen los sitios propios.
 * Firma (2026-11-10): el base firma cada licencia con su clave privada (RSA-SHA256, _private/license_sign.pem del base)
 * y este sitio la verifica con LIC_PUBKEY. La firma lleva el id del sitio y la fecha de emisión: no sirve la de otra agencia
 * ni una más vieja. Desde la primera licencia firmada (_private/license_signed.json), una licencia sin firma, alterada,
 * vieja o borrada deja la plataforma en solo lectura ("alterada") y el base lo ve en su panel.
 * Vencimiento: aviso desde LIC_WARN_DAYS días antes, LIC_GRACE_DAYS de gracia y después solo lectura (no se borra nada).
 * No usa la base de datos al cargar: auth.php la incluye antes de conectar. Doc: toolz/LICENCIAS.md
 */
if (!defined('LIC_ROOT')) define('LIC_ROOT', __DIR__);
const LIC_FILE = LIC_ROOT . '/_private/license.json';
const LIC_USAGE_FILE = LIC_ROOT . '/_private/license_usage.json';
const LIC_WARN_DAYS = 7;
const LIC_GRACE_DAYS = 7;
const LIC_USAGE_TTL = 3600;   // el espacio usado se recalcula a lo sumo una vez por hora
const LIC_SIGNED_MARK = LIC_ROOT . '/_private/license_signed.json';   // desde que llegó la primera licencia firmada: {issued, at}
/** Clave pública del sitio base para verificar las licencias. La privada vive solo en el base (_private/license_sign.pem). */
const LIC_PUBKEY = "-----BEGIN PUBLIC KEY-----\nMIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEApHPDPMmwot2vRqgLHq1S\n/XIg0AKiFI4vcnlKOMe4jlAi4zo8nZt4hVsW2DVzzohgjZQM6GxLxFHHZI/erd1z\nPzHhTIVbYvEL5Tu8W6/y0zO1gK9alzDuO3C/goSRSe0BC5sCHRj6MbyRWNwYeaZy\nXkxWIAMaE44FlAiFmgkSgu+JJtv4Mn1QHS3g4KtXbrubV2bqX76JvKxa05KyHjlC\nep3c0gPTRuE2ybpfNgmfItKlac2o05i/Te7G8zDt7v9n7nomCojdVVQhLIYYx/5W\nqUol4TFLWVOiqQp+oDNyrp/LL8aywu9xwh5E3mE7eYfcwIz4BLNuxBX7GH78GSGP\nusEfDj8O7T1yqyZm/4G702l7XVsgHYmUs4sgEgY8F87tiL7ylucIAjxx4kEtGHIV\nwjOJ6hIvgYJuhoUeRnRMhRFhm+DDH0xdhPcsElbtaUuhT9hxXJ8iqUgeXisnV7AP\nDgtCuzd2o4XDBED+gChhWLestFJLOMzA+CbmhTgTO6h7AgMBAAE=\n-----END PUBLIC KEY-----\n";
const LIC_KEEP = ['plan', 'plan_id', 'expires', 'status', 'users', 'clients', 'storage_gb', 'modules_off', 'modules_on', 'renew', 'issued', 'notes_public', 'client_types', 'rubro', 'extras', 'limits'];

/** Id de este sitio (el que va dentro de la firma). */
function lic_site_id(): string {
    if (defined('LIC_SITE_ID')) return (string)LIC_SITE_ID;   // pruebas
    if (function_exists('u_cfg')) { $i = (string)(u_cfg()['id'] ?? ''); if ($i !== '') return $i; }
    $f = LIC_ROOT . '/_private/site.php';
    if (is_file($f)) { $s = include $f; if (is_array($s) && !empty($s['id'])) return (string)$s['id']; }
    return '';
}
/** Verifica una licencia firmada {data: base64(JSON), sig: base64}. Devuelve su contenido o null. */
function lic_verify(array $p): ?array {
    $raw = base64_decode((string)($p['data'] ?? ''), true); $sig = base64_decode((string)($p['sig'] ?? ''), true);
    if ($raw === false || $raw === '' || $sig === false || $sig === '' || !function_exists('openssl_verify')) return null;
    if (openssl_verify($raw, $sig, LIC_PUBKEY, OPENSSL_ALGO_SHA256) !== 1) return null;
    $l = json_decode($raw, true);
    if (!is_array($l) || (int)($l['v'] ?? 0) !== 1) return null;
    $sid = lic_site_id();
    if ($sid === '' || !hash_equals($sid, (string)($l['site'] ?? ''))) return null;   // la licencia de otra agencia no sirve acá
    return $l;
}
function lic_mark(): array { return is_file(LIC_SIGNED_MARK) ? (json_decode((string)file_get_contents(LIC_SIGNED_MARK), true) ?: ['issued' => 0]) : []; }
/** Deja solo los campos conocidos, saneados. */
function lic_clean(array $l): array {
    $l = array_intersect_key($l, array_flip(LIC_KEEP));
    // Rubros: el rubro que asigna el base (la agencia lo ve como aviso en Admin › Rubro) y los módulos extra que compró
    if (isset($l['rubro'])) $l['rubro'] = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$l['rubro']));
    if (isset($l['extras'])) $l['extras'] = array_values(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9_]/', '', strtolower((string)$x)), (array)$l['extras'])));
    $l['status'] = ($l['status'] ?? '') === 'suspendida' ? 'suspendida' : 'activa';
    if (isset($l['limits'])) $l['limits'] = lic_limits_clean((array)$l['limits']);
    return $l;
}

// ── Limits inside the modules (2026-11-18) ─────────────
/**
 * What a plan can limit inside each module: key => [module, label, type (num|bool|list), options of a list].
 * Missing in the license = no limit. num = maximum; bool false = not included; list = only those values.
 * The base (Red Cronos › Planes) edits them with this same list (B_LIMITS); they travel inside the signed license. Doc: toolz/LICENCIAS.md
 */
const LIC_LIMITS = [
    'social_accounts'     => ['social', 'Cuentas de redes conectadas', 'num', []],
    'social_networks'     => ['social', 'Redes que se pueden conectar', 'list', ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube']],
    'social_publish'      => ['social', 'Publicar y programar en las redes', 'bool', []],
    'social_inbox'        => ['social', 'Inbox (mensajes y comentarios)', 'bool', []],
    'social_analytics'    => ['social', 'Métricas de las redes', 'bool', []],
    'social_reports'      => ['social', 'Informes para clientes', 'bool', []],
    'social_reports_auto' => ['social', 'Informes automáticos mensuales', 'bool', []],
    'social_ai'           => ['social', 'Sugerencias con IA en Social Studio', 'bool', []],
    'social_central'      => ['social', 'Conexión central de Cronos', 'bool', []],
    'social_profiles'     => ['social', 'Profile Studio (imágenes de perfil)', 'bool', []],
    'social_profiles_ai'  => ['social', 'IA en Profile Studio', 'bool', []],
    'social_profiles_ai_month' => ['social', 'Imágenes con IA por mes (Profile Studio)', 'num', []],
];
function lic_limits_clean(array $in): array {
    $o = [];
    foreach (LIC_LIMITS as $k => [, , $type, $opts]) {
        if (!array_key_exists($k, $in) || $in[$k] === null || $in[$k] === '') continue;
        $v = $in[$k];
        if ($type === 'num') { if (is_numeric($v) && (int)$v >= 0) $o[$k] = (int)$v; }
        elseif ($type === 'bool') $o[$k] = (bool)$v;
        else $o[$k] = array_values(array_intersect(array_keys($opts), array_map('strval', (array)$v)));
    }
    return $o;
}
/** A limit of the license: null = no limit (or no license). */
function lic_cap(string $key) {
    $l = lic_get();
    if (!$l || !isset(LIC_LIMITS[$key])) return null;
    return array_key_exists($key, (array)($l['limits'] ?? [])) ? $l['limits'][$key] : null;
}
/** Is something included in the plan? (bool limits; no license or no limit = yes) */
function lic_allows(string $key): bool { $v = lic_cap($key); return $v === null || $v === true; }
/** Is this value of a list limit included? */
function lic_allows_item(string $key, string $item): bool { $v = lic_cap($key); return !is_array($v) || in_array($item, $v, true); }
/** Message when the plan does not include something (the agency sees it as is). */
function lic_plan_msg(string $what): string { return 'Tu plan no incluye ' . $what . '. Para sumarlo, mejorá el plan desde Admin › Licencia.'; }
/**
 * Lee y valida la licencia guardada. integrity:
 *   ok (firmada y válida) · legacy (sin firma, de antes de la primera firmada) · none (no hay y nunca hubo firmada)
 *   missing · invalid · old · unsigned → alterada: se borró, se cambió, se puso una vieja o una sin firma.
 */
function lic_load(bool $reset = false): array {
    static $r = null;
    if ($r !== null && !$reset) return $r;
    $mark = lic_mark(); $seen = $mark !== [];
    $r = ['lic' => null, 'integrity' => 'none', 'fp' => null, 'issued' => null];
    if (!is_file(LIC_FILE)) { if ($seen) $r['integrity'] = 'missing'; return $r; }
    $d = json_decode((string)file_get_contents(LIC_FILE), true);
    if (!is_array($d)) { $r['integrity'] = $seen ? 'invalid' : 'none'; return $r; }
    if (isset($d['signed'])) {
        $l = lic_verify((array)$d['signed']);
        if (!$l) { $r['integrity'] = 'invalid'; return $r; }
        $r['fp'] = hash('sha256', (string)$d['signed']['data']); $r['issued'] = (int)($l['issued'] ?? 0);
        if ($seen && $r['issued'] < (int)($mark['issued'] ?? 0)) { $r['integrity'] = 'old'; return $r; }
        $r['integrity'] = 'ok';
        if (!empty($l['plan'])) $r['lic'] = lic_clean($l) + ['received' => (int)($d['received'] ?? 0)];   // plan vacío firmado = sin límites
        return $r;
    }
    if ($seen) { $r['integrity'] = 'unsigned'; return $r; }   // ya hubo firmadas: una sin firma no vale
    $r['integrity'] = 'legacy';
    if (!empty($d['plan'])) $r['lic'] = $d;
    return $r;
}
/** La licencia vigente, o null si este sitio no tiene límites. */
function lic_get(): ?array { return lic_load()['lic']; }
/** ¿La licencia se borró o se alteró en el servidor? */
function lic_tampered(): bool { return in_array(lic_load()['integrity'], ['missing', 'invalid', 'old', 'unsigned'], true); }
function lic_active(): bool { return lic_get() !== null || lic_tampered(); }

/** Días hasta el vencimiento (negativo = vencida). null si no vence. */
function lic_days_left(?array $l = null): ?int {
    $l = $l ?? lic_get();
    if (!$l || empty($l['expires'])) return null;
    $exp = strtotime($l['expires'] . ' 23:59:59');
    if (!$exp) return null;
    return (int)floor(($exp - time()) / 86400);
}

/** ok | aviso | gracia | solo_lectura | suspendida | alterada | sin_licencia */
function lic_state(?array $l = null): string {
    if ($l === null) { if (lic_tampered()) return 'alterada'; $l = lic_get(); }
    if (!$l) return 'sin_licencia';
    if (($l['status'] ?? 'activa') === 'suspendida') return 'suspendida';
    $d = lic_days_left($l);
    if ($d === null || $d > LIC_WARN_DAYS) return 'ok';
    if ($d >= 0) return 'aviso';
    if ($d >= -LIC_GRACE_DAYS) return 'gracia';
    return 'solo_lectura';
}
function lic_readonly(): bool { return in_array(lic_state(), ['solo_lectura', 'suspendida', 'alterada'], true); }

/** Topes de la licencia; null = sin tope. */
function lic_limit(string $what): ?float {
    $l = lic_get();
    if (!$l) return null;
    $k = ['users' => 'users', 'clients' => 'clients', 'storage' => 'storage_gb'][$what] ?? $what;
    $v = $l[$k] ?? null;
    return ($v === null || $v === '' || (float)$v <= 0) ? null : (float)$v;
}
/** Módulos que la licencia no incluye (los suma site_lib.php a modulos_off). */
function lic_modules_off(): array {
    $l = lic_get();
    return $l ? array_values(array_filter(array_map('strval', (array)($l['modules_off'] ?? [])))) : [];
}

// ── Uso ────────────────────────────────────────────────────
function lic_count_users(PDO $db): int {
    try { return (int)$db->query('SELECT COUNT(*) FROM g7_users WHERE active = 1')->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function lic_count_clients(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM g7_clients WHERE status <> 'terminado'")->fetchColumn(); }
    catch (Throwable $e) {
        try { return (int)$db->query('SELECT COUNT(*) FROM g7_clients')->fetchColumn(); } catch (Throwable $e2) { return 0; }
    }
}
/** Bytes que ocupan los datos del sitio: todo lo que no es código instalado, respaldos ni versiones. */
function lic_storage_bytes(): int {
    $man = is_file(LIC_ROOT . '/_private/updates/manifest.json') ? (json_decode((string)file_get_contents(LIC_ROOT . '/_private/updates/manifest.json'), true) ?: []) : [];
    $skip = ['_private/updates/', '_private/releases/', 'backups/', '_update/'];
    $total = 0; $base = strlen(LIC_ROOT) + 1;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(LIC_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), $base));
            foreach ($skip as $s) if (strncmp($rel, $s, strlen($s)) === 0) continue 2;
            if (isset($man[$rel])) continue;
            $total += $f->getSize();
        }
    } catch (Throwable $e) {}
    return $total;
}
/** Uso actual. El espacio se toma del caché si tiene menos de una hora (o si $fresh es false y no hay base). */
function lic_usage(?PDO $db = null, bool $fresh = false): array {
    $c = is_file(LIC_USAGE_FILE) ? (json_decode((string)file_get_contents(LIC_USAGE_FILE), true) ?: []) : [];
    $old = $fresh || empty($c['at']) || (time() - (int)$c['at']) > LIC_USAGE_TTL;
    if ($db) { $c['users'] = lic_count_users($db); $c['clients'] = lic_count_clients($db); }
    if ($old) { $c['bytes'] = lic_storage_bytes(); $c['at'] = time(); }
    if ($db || $old) @file_put_contents(LIC_USAGE_FILE, json_encode($c), LOCK_EX);
    return $c + ['users' => 0, 'clients' => 0, 'bytes' => 0, 'at' => 0];
}

/** null si se puede sumar uno más; si no, el motivo para mostrar. */
function lic_can_add(string $what, PDO $db): ?string {
    if (lic_readonly()) return lic_readonly_msg();
    $lim = lic_limit($what);
    if ($lim === null) return null;
    $n = $what === 'users' ? lic_count_users($db) : lic_count_clients($db);
    if ($n < $lim) return null;
    $plan = (string)(lic_get()['plan'] ?? '');
    return $what === 'users'
        ? "Tu plan $plan incluye hasta " . (int)$lim . ' usuarios activos y ya los usaste. Desactiva a alguien o pasate a un plan más grande.'
        : "Tu plan $plan incluye hasta " . (int)$lim . ' clientes y ya los usaste. Pasa a terminado alguno que ya no trabaje con ustedes o pasate a un plan más grande.';
}
/** null si entra; si no, el motivo. $incoming: bytes que se quieren subir. */
function lic_can_upload(int $incoming): ?string {
    $lim = lic_limit('storage');
    if ($lim === null) return null;
    $used = (int)lic_usage()['bytes'];
    if ($used + $incoming <= $lim * 1073741824) return null;
    return 'Se llenó el espacio de tu plan (' . lic_gb($used) . ' de ' . rtrim(rtrim(number_format($lim, 1, ',', ''), '0'), ',') . ' GB). Borra archivos que ya no uses o pasate a un plan más grande.';
}
function lic_gb(int $bytes): string { return number_format($bytes / 1073741824, $bytes < 1073741824 ? 2 : 1, ',', '.') . ' GB'; }
function lic_readonly_msg(): string {
    if (lic_state() === 'alterada') return 'No se pudo validar la licencia de esta plataforma: puedes entrar y ver todo, pero no cargar cambios. Escríbenos para revisarla.';
    return lic_state() === 'suspendida'
        ? 'La licencia de esta plataforma está suspendida: puedes entrar y ver todo, pero no cargar cambios. Escríbenos para reactivarla.'
        : 'La licencia venció: puedes entrar y ver todo, pero no cargar cambios hasta renovarla. No se perdió nada.';
}

/** Mensaje corto para el aviso de arriba; null si no hay nada que avisar. */
function lic_banner(): ?array {
    $st = lic_state();
    if (in_array($st, ['ok', 'sin_licencia'], true)) return null;
    $d = lic_days_left();
    switch ($st) {
        case 'aviso': return ['warn', $d === 0 ? 'Tu licencia vence hoy.' : "Tu licencia vence en $d " . ($d === 1 ? 'día' : 'días') . '.'];
        case 'gracia': $g = LIC_GRACE_DAYS + $d; return ['warn', "Tu licencia venció. Te quedan $g " . ($g === 1 ? 'día' : 'días') . ' para renovarla antes de que la plataforma pase a solo lectura.'];
        default: return ['bad', lic_readonly_msg()];
    }
}

/**
 * Corta el pedido si la licencia no lo permite. Lo llama auth.php en cada página.
 * - Solo lectura o suspendida: rechaza todo POST salvo entrar y salir.
 * - Espacio lleno: rechaza las subidas (archivos o pedidos grandes, como las partes de una subida).
 */
function lic_guard(): void {
    if (!lic_active() || PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['login.php', 'logout.php', 'ping.php'], true)) return;
    // Los avisos de pago de las pasarelas se registran siempre: un pago cobrado no se puede perder
    if (strpos(strtr((string)($_SERVER['SCRIPT_NAME'] ?? ''), '\\', '/'), '/tienda/webhook.php') !== false) return;
    // Pasarela central (pagos/): sus avisos y el checkout del comprador también (pagar una factura no se frena por la licencia)
    if (preg_match('#/(pagos/webhook\.php|pagar/(api|prueba)\.php)$#', strtr((string)($_SERVER['SCRIPT_NAME'] ?? ''), '\\', '/'))) return;
    $msg = null;
    if (lic_readonly()) { if (!lic_is_read_request()) $msg = lic_readonly_msg(); }
    else {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (!empty($_FILES) || $len > 262144) $msg = lic_can_upload($len);
    }
    if ($msg === null) return;
    http_response_code($msg && lic_readonly() ? 423 : 507);
    $wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['api']) || preg_match('/(api|_api|ajax|upload|files)\.php$/', $script);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'status' => 'error', 'error' => $msg, 'message' => $msg, 'license' => lic_state()], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Licencia</title>'
           . '<body style="margin:0;background:#0f1012;color:#ecedef;font:15px/1.6 system-ui,sans-serif;display:grid;place-items:center;min-height:100vh">'
           . '<div style="max-width:460px;padding:28px;border:1px solid #2a2c31;border-radius:14px;background:#17181b"><h1 style="font-size:20px;margin:0 0 10px">No se guardaron los cambios</h1>'
           . '<p style="color:#b5b9c0;margin:0 0 18px">' . htmlspecialchars($msg) . '</p><a href="/toolz/" style="color:#ff7a14">Volver al inicio</a></div></body>';
    }
    exit;
}

/**
 * Muchas pantallas leen por POST (el chat sincroniza, los paneles piden su estado).
 * En solo lectura se dejan pasar los pedidos cuya acción es de lectura; lo demás se corta.
 */
function lic_is_read_request(): bool {
    // Se juntan las acciones de TODAS las fuentes (formulario, URL y cuerpo JSON): cada API lee de una distinta,
    // así que una acción de lectura en la URL no puede tapar una de escritura en el cuerpo.
    $acts = [];
    foreach (['action', 'act', 'op', 'a', 'do'] as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) $acts[] = $_POST[$k];
        if (isset($_GET[$k]) && is_string($_GET[$k])) $acts[] = $_GET[$k];
    }
    if (!empty($_FILES)) return false;                                   // una subida nunca es lectura
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len >= 65536) return false;                                     // cuerpo grande: no se puede revisar, se corta
    if ($len > 0) {
        $j = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($j)) foreach (['action', 'act', 'op', 'a', 'do'] as $k) if (isset($j[$k]) && is_string($j[$k])) $acts[] = $j[$k];
    }
    if (!$acts) return false;
    foreach ($acts as $act) {
        if (!preg_match('/^(state|list|get|load|sync|boot|history|search|status|ping|read|view|info|count|poll|stats|preview|export|feed|unread|check|lic_|upd_state|login|logout|progress)/i', $act)) return false;
        // Nombres que empiezan como lectura pero escriben (status_save, list_delete…)
        if (!preg_match('/^(lic_|upd_state)/i', $act) && preg_match('/(^|_)(save|delete|del|add|update|set|create|remove|upload|send|edit|move|import|apply|rollback|reset|toggle|clear|publish|write|mark)(_|$)/i', $act)) return false;
    }
    return true;
}

/** Lo que ve el sitio base de esta instalación (lo agrega u_status, sin tocar la base de datos). */
function lic_report(): array {
    $u = lic_usage();
    $ld = lic_load();
    return ['state' => lic_state(), 'plan' => lic_get()['plan'] ?? null, 'expires' => lic_get()['expires'] ?? null, 'rubro' => (string)(lic_get()['rubro'] ?? ''),
        'integrity' => $ld['integrity'], 'fp' => $ld['fp'], 'issued' => $ld['issued'],
        'usage' => ['users' => (int)$u['users'], 'clients' => (int)$u['clients'], 'bytes' => (int)$u['bytes'], 'at' => (int)$u['at']]];
}
/**
 * Guarda la licencia que manda el sitio base. $signed: {data, sig} firmado por el base (desde la 1.0.22).
 * Con firma: se verifica (clave, sitio y que no sea más vieja) y se guarda tal cual llegó. Plan vacío firmado = sin límites.
 * Sin firma: solo mientras este sitio no haya recibido nunca una firmada (bases viejas); después se rechaza.
 */
function lic_save(array $l, ?array $signed = null): array {
    $dir = dirname(LIC_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $write = function (string $file, array $data): void {
        if (file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !rename($file . '.tmp', $file))
            throw new RuntimeException('No se pudo guardar la licencia.');
        @chmod($file, 0640);
    };
    if ($signed) {
        $v = lic_verify($signed);
        if (!$v) throw new RuntimeException('La firma de la licencia no es válida para este sitio.');
        $mark = lic_mark();
        if ((int)($v['issued'] ?? 0) < (int)($mark['issued'] ?? 0)) throw new RuntimeException('Esa licencia es más vieja que la que ya tiene este sitio.');
        if (!empty($v['expires']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v['expires'])) throw new RuntimeException('Fecha de vencimiento inválida.');
        $write(LIC_FILE, ['signed' => ['data' => (string)$signed['data'], 'sig' => (string)$signed['sig']], 'received' => time()]);
        $write(LIC_SIGNED_MARK, ['issued' => (int)($v['issued'] ?? 0), 'at' => time()]);
        lic_load(true);
        return ['ok' => true, 'signed' => true, 'removed' => empty($v['plan']), 'state' => lic_state()];
    }
    if (lic_mark() !== []) throw new RuntimeException('Este sitio solo acepta licencias firmadas por el sitio base.');
    $l = lic_clean($l);
    if (empty($l['plan'])) {   // sin plan = sacarle la licencia (vuelve a no tener límites)
        if (is_file(LIC_FILE)) @unlink(LIC_FILE);
        lic_load(true);
        return ['ok' => true, 'removed' => true];
    }
    if (!empty($l['expires']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$l['expires'])) throw new RuntimeException('Fecha de vencimiento inválida.');
    $l['received'] = time();
    $write(LIC_FILE, $l);
    lic_load(true);
    return ['ok' => true, 'state' => lic_state($l)];
}
