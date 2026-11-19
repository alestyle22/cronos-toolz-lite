<?php
/**
 * _update/lib.php — Actualizador de Toolz: instala versiones firmadas, respalda y vuelve atrás.
 * No depende de auth.php ni de la base: si una versión rompe el sitio, esto tiene que seguir andando.
 *
 * Por sitio: _private/site.php (no viaja en ninguna versión). Estado: _private/updates/.
 * Versiones: las arma dev/release.php en la PC, que es la única que tiene la clave privada.
 * Doc: toolz/ACTUALIZACIONES.md
 */

if (!defined('U_ROOT')) define('U_ROOT', dirname(__DIR__));
const U_DIR = U_ROOT . '/_private/updates';
const U_RELEASES = U_ROOT . '/_private/releases';   // solo en el sitio central
const U_PUBKEY = __DIR__ . '/pubkey.pem';
const U_KEEP_BACKUPS = 3;

// Lo que es de cada sitio: una versión nunca lo toca aunque venga en el paquete
const U_PROTECTED = ['_private/', 'marcas/', 'config.local.php', 'backups/', 'temp/', 'avatars/', 'covers/', 'link_covers/', 'post_imgs/', 'stock_files/', 'chat_files/', 'pages_files/',
    'client_avatars/', 'facturacion/logos/', 'brand/', 'imghost/', 'landings/', 'promos/imgs/', 'promos/mailings/', 'placasdeportivas/cache/', 'placasdeportivas/imgs/', 'placasdeportivas/sp/cache/',
    'placasdeportivas/sp/files/', 'placasdeportivas/diarias/refs/', 'placaspaid/imgs/', 'mailingmaker/imgs/', 'landing-gen/heroes/', 'tienda/imgs/', 'eventos/media/', 'portal/media/', 'landing-gen-2/imports/', 'tienda/themes/', 'propiedades/fotos/', 'juegos/files/', 'builds/files/', 'plugins/backup/storage/', 'monday_cache.json'];

class UError extends RuntimeException {}

function u_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = U_ROOT . '/_private/site.php';
    $site = is_file($f) ? (include $f) : [];
    $c = array_merge(['id' => '', 'name' => '', 'url' => '', 'central_url' => '', 'secret' => '', 'propios' => [], 'sites' => []], is_array($site) ? $site : []);
    // Sitio base: los sitios que se agregan desde su panel viven en _private/sites.json (se suman a los de site.php)
    $j = is_file(U_ROOT . '/_private/sites.json') ? json_decode((string)file_get_contents(U_ROOT . '/_private/sites.json'), true) : null;
    if (is_array($j)) $c['sites'] = array_merge((array)$c['sites'], $j);
    return $c;
}
function u_is_central(): bool { return is_file(U_RELEASES . '/catalog.json'); }

function u_protected(string $p): bool {
    foreach (U_PROTECTED as $x) if ($p === $x || (substr($x, -1) === '/' && strncmp($p, $x, strlen($x)) === 0)) return true;
    return false;
}
/** Rutas del paquete: relativas, sin subir de carpeta. */
function u_path_ok(string $p): bool {
    return $p !== '' && $p[0] !== '/' && strpos($p, '\\') === false && strpos($p, "\0") === false && !preg_match('#(^|/)\.\.?(/|$)#', $p) && !preg_match('#^[a-zA-Z]:#', $p);
}
function u_version_ok(string $v): bool { return (bool)preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $v); }

// ── Estado ──────────────────────────────────────────────
function u_json_read(string $f, $default = null) {
    if (!is_file($f)) return $default;
    $v = json_decode((string)file_get_contents($f), true);
    return $v === null ? $default : $v;
}
function u_json_write(string $f, $v): void {
    if (!is_dir(dirname($f)) && !mkdir(dirname($f), 0755, true) && !is_dir(dirname($f))) throw new UError('No se pudo crear ' . dirname($f));
    $tmp = $f . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !rename($tmp, $f)) {
        @unlink($tmp); throw new UError('No se pudo guardar ' . basename($f));
    }
}
function u_state(): array { return u_json_read(U_DIR . '/state.json', []) + ['version' => '', 'seq' => 0, 'history' => []]; }
function u_state_save(array $s): void { $s['history'] = array_slice($s['history'] ?? [], -30); u_json_write(U_DIR . '/state.json', $s); }
function u_log(string $action, string $version, string $detail = ''): void {
    $s = u_state(); $s['history'][] = ['at' => date('Y-m-d H:i:s'), 'action' => $action, 'version' => $version, 'detail' => $detail]; u_state_save($s);
}
/** Una actualización que se cortó a la mitad (se cayó PHP, se cerró la conexión). */
function u_interrupted(): ?array {
    $j = u_json_read(U_DIR . '/journal.json');
    return $j && ($j['status'] ?? '') !== 'done' ? $j : null;
}
function u_lock() {
    if (!is_dir(U_DIR)) @mkdir(U_DIR, 0755, true);
    $h = fopen(U_DIR . '/lock', 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) throw new UError('Ya hay una actualización corriendo en este sitio.');
    return $h;
}

// ── Pedidos firmados entre sitios ───────────────────────
function u_sig(string $secret, string $site, string $ts, string $nonce, string $body): string { return hash_hmac('sha256', "$site|$ts|$nonce|$body", $secret); }
/** Pide algo a otro sitio. Devuelve [código HTTP, cuerpo]. */
function u_post(string $url, string $site, string $secret, array $payload, int $timeout = 60): array {
    $body = json_encode($payload); $ts = (string)time(); $nonce = bin2hex(random_bytes(12));
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-U-Site: $site", "X-U-Ts: $ts", "X-U-Nonce: $nonce", 'X-U-Sig: ' . u_sig($secret, $site, $ts, $nonce, $body)]]);
    $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
    if ($r === false) throw new UError('No hubo respuesta de ' . parse_url($url, PHP_URL_HOST) . ': ' . $err);
    return [$code, (string)$r];
}
/** Lee y valida un pedido firmado. $secretFor: site => clave o null. Devuelve [sitio, datos]. */
function u_request(callable $secretFor): array {
    $site = (string)($_SERVER['HTTP_X_U_SITE'] ?? ''); $ts = (string)($_SERVER['HTTP_X_U_TS'] ?? ''); $nonce = (string)($_SERVER['HTTP_X_U_NONCE'] ?? ''); $sig = (string)($_SERVER['HTTP_X_U_SIG'] ?? '');
    $body = (string)file_get_contents('php://input');
    $secret = $site !== '' ? $secretFor($site) : null;
    if (!$secret || strlen($secret) < 32 || !ctype_digit($ts) || !preg_match('/^[a-f0-9]{24}$/', $nonce)) u_deny();
    if (!hash_equals(u_sig($secret, $site, $ts, $nonce, $body), $sig) || abs(time() - (int)$ts) > 300) u_deny();
    // Cada pedido vale una sola vez
    $f = U_DIR . '/nonces.json';
    if (!is_dir(U_DIR)) @mkdir(U_DIR, 0755, true);
    $h = fopen($f . '.lock', 'c'); flock($h, LOCK_EX);
    $seen = array_filter(u_json_read($f, []), fn($t) => $t > time() - 700);
    if (isset($seen[$nonce])) { flock($h, LOCK_UN); u_deny(); }
    $seen[$nonce] = time(); u_json_write($f, $seen); flock($h, LOCK_UN);
    return [$site, json_decode($body, true) ?: []];
}
function u_deny(): void { http_response_code(403); header('Content-Type: application/json'); echo '{"error":"No autorizado"}'; exit; }

// ── Catálogo y paquetes ─────────────────────────────────
function u_verify_sig(string $data, string $sigB64): bool {
    if (!is_file(U_PUBKEY)) throw new UError('Falta la clave pública del actualizador.');
    $sig = base64_decode($sigB64, true);
    return $sig !== false && openssl_verify($data, $sig, (string)file_get_contents(U_PUBKEY), OPENSSL_ALGO_SHA256) === 1;
}
function u_central_get(array $payload, int $timeout = 60): string {
    $c = u_cfg();
    if (!$c['central_url'] || !$c['secret'] || !$c['id']) throw new UError('Este sitio no tiene configurado de dónde bajar las versiones (_private/site.php).');
    [$code, $body] = u_post(rtrim($c['central_url'], '/') . '/_update/feed.php', $c['id'], $c['secret'], $payload, $timeout);
    if ($code !== 200) throw new UError("El sitio central respondió $code.");
    return $body;
}
/** Catálogo firmado de versiones. Se rechaza uno firmado pero más viejo que el último visto. */
function u_catalog(): array {
    if (u_is_central()) { $raw = (string)file_get_contents(U_RELEASES . '/catalog.json'); $sig = trim((string)file_get_contents(U_RELEASES . '/catalog.sig')); }
    else { $r = json_decode(u_central_get(['get' => 'catalog']), true) ?: []; $raw = (string)($r['catalog'] ?? ''); $sig = (string)($r['sig'] ?? ''); }
    if (!u_verify_sig($raw, $sig)) throw new UError('La firma del catálogo de versiones no es válida. No se instala nada.');
    $cat = json_decode($raw, true);
    if (!is_array($cat) || !isset($cat['seq'], $cat['releases'])) throw new UError('Catálogo de versiones inválido.');
    $st = u_state();
    if ((int)$cat['seq'] < (int)$st['seq']) throw new UError('El catálogo recibido es más viejo que el último visto. No se instala nada.');
    if ((int)$cat['seq'] > (int)$st['seq']) { $st['seq'] = (int)$cat['seq']; u_state_save($st); }
    return $cat;
}
function u_release(array $cat, string $version): array {
    foreach ($cat['releases'] as $r) if (($r['version'] ?? '') === $version) return $r;
    throw new UError("La versión $version no está en el catálogo.");
}
/** Trae el paquete a _private/updates/pkg/ y comprueba que sea el firmado. */
function u_package(array $rel): string {
    $v = $rel['version'];
    if (!u_version_ok($v)) throw new UError('Versión inválida.');
    $dst = U_DIR . "/pkg/$v.zip";
    if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
    if (!is_file($dst) || !hash_equals((string)$rel['sha256'], hash_file('sha256', $dst))) {
        $bytes = u_is_central() ? (string)@file_get_contents(U_RELEASES . "/$v.zip") : u_central_get(['get' => 'zip', 'version' => $v], 300);
        if (!hash_equals((string)$rel['sha256'], hash('sha256', $bytes))) throw new UError('El paquete no coincide con el firmado. No se instala nada.');
        if (file_put_contents($dst, $bytes) === false) throw new UError('No hay lugar para guardar el paquete.');
    }
    return $dst;
}
function u_manifest(ZipArchive $z, array $rel): array {
    $raw = $z->getFromName('__manifest.json');
    if ($raw === false || !hash_equals((string)$rel['manifest_sha256'], hash('sha256', $raw))) throw new UError('La lista de archivos del paquete no coincide con la firmada.');
    $m = json_decode($raw, true);
    if (!is_array($m['files'] ?? null) || ($m['version'] ?? '') !== $rel['version']) throw new UError('Lista de archivos inválida.');
    return $m;
}
/** Qué haría instalar esta versión en este sitio, sin tocar nada. */
/** Definición de la edición de este sitio (lite…) o null si es la completa. */
function u_edition(): ?array {
    if (!is_file(__DIR__ . '/editions.php')) return null;
    require_once __DIR__ . '/editions.php';
    return u_edition_def(u_site_edition());
}
function u_plan(array $m): array {
    $c = u_cfg(); $own = array_flip($c['propios']); $prev = u_json_read(U_DIR . '/manifest.json', []);
    $ed = u_edition();
    $p = ['new' => [], 'change' => [], 'remove' => [], 'kept' => [], 'edited' => [], 'same' => 0, 'edition_skip' => 0];
    foreach ($m['files'] as $path => $sha) {
        if (!u_path_ok($path)) throw new UError("Ruta inválida en el paquete: $path");
        if (u_protected($path)) continue;
        if ($ed && u_edition_excluded($ed, $path) && !is_file(U_ROOT . '/' . $path)) { $p['edition_skip']++; continue; }   // módulo que esta edición no trae
        if (isset($own[$path])) { $p['kept'][] = $path; continue; }
        $f = U_ROOT . '/' . $path;
        if (!is_file($f)) { $p['new'][] = $path; continue; }
        $cur = hash_file('sha256', $f);
        if ($cur === $sha) { $p['same']++; continue; }
        $p['change'][] = $path;
        if (isset($prev[$path]) && $prev[$path] !== $cur) $p['edited'][] = $path;   // lo cambiaron a mano en este sitio
    }
    // Solo se borra lo que instaló una versión anterior: nunca algo que no conocemos
    foreach ($prev as $path => $sha) if (!isset($m['files'][$path]) && !isset($own[$path]) && !u_protected($path) && u_path_ok($path) && is_file(U_ROOT . '/' . $path)) $p['remove'][] = $path;
    return $p;
}
function u_preview(string $version): array {
    $rel = u_release(u_catalog(), $version); $zip = u_package($rel);
    $z = new ZipArchive; if ($z->open($zip) !== true) throw new UError('No se pudo abrir el paquete.');
    $m = u_manifest($z, $rel); $z->close();
    return ['version' => $version, 'notes' => $rel['notes'] ?? '', 'plan' => u_plan($m)];
}

// ── Instalar y volver atrás ─────────────────────────────
function u_write(string $path, string $bytes): void {
    $f = U_ROOT . '/' . $path;
    if (!is_dir(dirname($f)) && !mkdir(dirname($f), 0755, true) && !is_dir(dirname($f))) throw new UError('No se pudo crear la carpeta de ' . $path);
    $tmp = $f . '.u' . getmypid();
    if (file_put_contents($tmp, $bytes) === false) throw new UError("No se pudo escribir $path");
    @chmod($tmp, 0644);
    if (!rename($tmp, $f)) { @unlink($tmp); throw new UError("No se pudo reemplazar $path"); }
}
function u_opcache(): void { if (function_exists('opcache_reset')) @opcache_reset(); clearstatcache(true); }
/** El sitio responde: carga auth.php, llega a la base y el login abre. */
function u_health(): ?string {
    $c = u_cfg();
    if (!$c['url']) return 'El sitio no tiene configurada su dirección (url en _private/site.php).';
    $base = rtrim($c['health_url'] ?? '' ?: $c['url'], '/');   // health_url: solo para probar con el servidor de PHP, que atiende de a un pedido
    foreach (['/_update/health.php' => '"ok":true', '/login.php' => '<form'] as $page => $needle) {
        $h = curl_init($base . $page . '?u=' . time());
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $r = curl_exec($h); $code = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); curl_close($h);
        if ($r === false || $code !== 200 || strpos((string)$r, $needle) === false) return "$page respondió $code";
    }
    return null;
}
function u_apply(string $version): array {
    if (!extension_loaded('zip') || !extension_loaded('openssl') || !extension_loaded('curl')) throw new UError('Faltan extensiones de PHP en este sitio (zip, openssl, curl).');
    @set_time_limit(0); ignore_user_abort(true);
    $lock = u_lock();
    if ($j = u_interrupted()) throw new UError('Hay una actualización que quedó a medias (' . ($j['version'] ?? '') . '). Primero hay que volver atrás.');
    $rel = u_release(u_catalog(), $version);
    // Nunca se instala una versión más vieja que la que hay (volver atrás es el rollback, que usa el respaldo):
    // así nadie puede reinstalar una versión firmada que tenía fallas ya corregidas.
    $cur = (string)(u_state()['version'] ?? '');
    if ($cur !== '' && u_version_ok($cur) && version_compare($version, $cur, '<')) throw new UError("La versión $version es más vieja que la instalada ($cur). Para volver atrás usa el rollback.");
    if (version_compare(PHP_VERSION, (string)($rel['min_php'] ?? '8.0'), '<')) throw new UError('Esta versión necesita PHP ' . $rel['min_php'] . ' y el sitio tiene ' . PHP_VERSION . '.');
    if ($why = u_health()) throw new UError("El sitio ya no responde bien antes de actualizar ($why). No se tocó nada.");
    $zip = u_package($rel);
    $z = new ZipArchive; if ($z->open($zip) !== true) throw new UError('No se pudo abrir el paquete.');
    $m = u_manifest($z, $rel); $plan = u_plan($m);

    // Todo se lee y se comprueba antes de tocar el primer archivo
    $bytes = [];
    foreach (array_merge($plan['new'], $plan['change']) as $path) {
        $b = $z->getFromName($path);
        if ($b === false || !hash_equals((string)$m['files'][$path], hash('sha256', $b))) throw new UError("El archivo $path del paquete está dañado.");
        $bytes[$path] = $b;
    }
    $z->close();
    foreach ($plan['change'] as $path) if (!is_writable(U_ROOT . '/' . $path)) throw new UError("El servidor no deja reescribir $path. No se tocó nada.");

    // Respaldo y bitácora
    $state = u_state(); $from = (string)$state['version'];
    $bk = U_DIR . '/backup/' . date('Ymd_His') . '_' . $version;
    if (!mkdir($bk . '/files', 0755, true)) throw new UError('No se pudo crear el respaldo.');
    foreach (array_merge($plan['change'], $plan['remove']) as $path) {
        $t = $bk . '/files/' . $path;
        if (!is_dir(dirname($t))) mkdir(dirname($t), 0755, true);
        if (!copy(U_ROOT . '/' . $path, $t)) throw new UError("No se pudo respaldar $path. No se tocó nada.");
    }
    if (is_file(U_DIR . '/manifest.json')) copy(U_DIR . '/manifest.json', $bk . '/manifest.json');
    $journal = ['status' => 'applying', 'version' => $version, 'from' => $from, 'backup' => basename($bk), 'at' => date('Y-m-d H:i:s'), 'new' => $plan['new'], 'change' => $plan['change'], 'remove' => $plan['remove']];
    u_json_write($bk . '/journal.json', $journal);
    u_json_write(U_DIR . '/journal.json', $journal);

    try {
        // El actualizador va último: si algo falla antes, sigue el que está corriendo
        $order = array_keys($bytes);
        usort($order, fn($a, $b) => (strncmp($a, '_update/', 8) === 0) <=> (strncmp($b, '_update/', 8) === 0));
        foreach ($order as $path) u_write($path, $bytes[$path]);
        foreach ($plan['remove'] as $path) if (!@unlink(U_ROOT . '/' . $path)) throw new UError("No se pudo borrar $path");
        u_opcache();
        if ($why = u_health()) throw new UError("Después de instalar, $why");
    } catch (Throwable $e) {
        u_rollback_locked();
        u_log('falló', $version, $e->getMessage());
        throw new UError('No se instaló: ' . $e->getMessage() . ' Volvió solo a la versión anterior.');
    }

    // Manifiesto de lo instalado: lo propio del sitio queda afuera, así no se borra después
    $ed = u_edition();
    $inst = array_filter($m['files'], fn($p) => !u_protected($p) && !in_array($p, u_cfg()['propios'], true) && (!$ed || !u_edition_excluded($ed, $p) || is_file(U_ROOT . '/' . $p)), ARRAY_FILTER_USE_KEY);
    u_json_write(U_DIR . '/manifest.json', $inst);
    $journal['status'] = 'done'; u_json_write($bk . '/journal.json', $journal); u_json_write(U_DIR . '/journal.json', $journal);
    $state = u_state(); $state['version'] = $version; u_state_save($state);
    u_log('actualizó', $version, count($plan['new']) . ' nuevos, ' . count($plan['change']) . ' cambiados, ' . count($plan['remove']) . ' borrados' . ($from ? ", desde $from" : ''));
    u_prune();
    @unlink($zip);
    flock($lock, LOCK_UN);
    return ['version' => $version, 'from' => $from, 'plan' => $plan];
}
function u_backups(): array {
    $o = [];
    foreach (glob(U_DIR . '/backup/*', GLOB_ONLYDIR) ?: [] as $d) { $j = u_json_read($d . '/journal.json'); if ($j) $o[basename($d)] = $j; }
    krsort($o);
    return $o;
}
function u_rrmdir(string $d): void {
    foreach (scandir($d) ?: [] as $x) { if ($x === '.' || $x === '..') continue; $p = "$d/$x"; is_dir($p) ? u_rrmdir($p) : @unlink($p); }
    @rmdir($d);
}
function u_prune(): void { foreach (array_slice(array_keys(u_backups()), U_KEEP_BACKUPS) as $b) u_rrmdir(U_DIR . '/backup/' . $b); }
/** Deja los archivos como estaban antes de la última actualización. La base no vuelve: los cambios de esquema tienen que ser solo agregados. */
function u_rollback_locked(): array {
    $bks = u_backups();
    if (!$bks) throw new UError('No hay ninguna actualización para deshacer.');
    $name = array_key_first($bks); $j = $bks[$name]; $bk = U_DIR . '/backup/' . $name;
    foreach (array_merge($j['change'], $j['remove']) as $path) {
        if (!u_path_ok($path) || u_protected($path)) continue;
        $b = @file_get_contents($bk . '/files/' . $path);
        if ($b === false) throw new UError("Falta el respaldo de $path");
        u_write($path, $b);
    }
    foreach ($j['new'] as $path) if (u_path_ok($path) && !u_protected($path)) @unlink(U_ROOT . '/' . $path);
    is_file($bk . '/manifest.json') ? copy($bk . '/manifest.json', U_DIR . '/manifest.json') : @unlink(U_DIR . '/manifest.json');
    @unlink(U_DIR . '/journal.json');
    u_rrmdir($bk);
    u_opcache();
    $st = u_state(); $st['version'] = (string)$j['from']; u_state_save($st);
    return ['version' => (string)$j['from'], 'undone' => (string)$j['version']];
}
function u_rollback(): array {
    @set_time_limit(0); ignore_user_abort(true);
    $lock = u_lock();
    $r = u_rollback_locked();
    u_log('volvió atrás', $r['version'], 'Deshizo ' . $r['undone']);
    flock($lock, LOCK_UN);
    return $r;
}
/** Lo que un sitio cuenta de sí mismo: al panel propio y al central. */
function u_status(): array {
    $s = u_state(); $i = u_interrupted(); $b = u_backups();
    return ['id' => u_cfg()['id'], 'name' => u_cfg()['name'], 'version' => $s['version'], 'edition' => u_edition()['id'] ?? '', 'php' => PHP_VERSION, 'interrupted' => $i ? $i['version'] : null,
        'can_rollback' => (bool)$b, 'rollback_to' => $b ? (string)reset($b)['from'] : null, 'history' => array_reverse(array_slice($s['history'], -10)), 'propios' => count(u_cfg()['propios'])]
        + (u_lic_lib() ? ['license' => lic_report()] : [])
        + (u_red_lib() ? ['stats' => red_stats()] : []);
}
/** Red (red_lib.php): estadísticas para el Cronos principal y banners que manda. */
function u_red_lib(): bool {
    if (function_exists('red_stats')) return true;
    if (!is_file(U_ROOT . '/red_lib.php')) return false;
    if (!defined('RED_ROOT')) define('RED_ROOT', U_ROOT);
    require_once U_ROOT . '/red_lib.php';
    return true;
}
/** Licencias (license_lib.php): sin la base de datos, para que ande aunque el sitio esté roto. */
function u_lic_lib(): bool {
    if (function_exists('lic_report')) return true;
    if (!is_file(U_ROOT . '/license_lib.php')) return false;
    if (!defined('LIC_ROOT')) define('LIC_ROOT', U_ROOT);
    require_once U_ROOT . '/license_lib.php';
    return true;
}
/** Acciones del actualizador; las usan el panel y los pedidos del central. */
function u_dispatch(array $in): array {
    switch ((string)($in['action'] ?? '')) {
        case 'status':   return u_status();
        case 'catalog':  $c = u_catalog(); return ['releases' => $c['releases']];
        case 'preview':  if (!u_version_ok((string)($in['version'] ?? ''))) throw new UError('Versión inválida.'); return u_preview((string)$in['version']);
        case 'apply':    if (!u_version_ok((string)($in['version'] ?? ''))) throw new UError('Versión inválida.'); return u_apply((string)$in['version']);
        case 'rollback': return u_rollback();
        case 'license':  // la manda el sitio base; solo llega por remote.php, firmada con la clave del central
            if (!u_lic_lib()) throw new UError('Este sitio todavía no tiene licencias: instala la versión nueva.');
            if (!is_array($in['license'] ?? null)) throw new UError('Licencia inválida.');
            try { $r = lic_save($in['license'], is_array($in['signed'] ?? null) ? $in['signed'] : null); } catch (RuntimeException $e) { throw new UError($e->getMessage()); }
            u_log('licencia', (string)($in['license']['plan'] ?? 'sin licencia'), (string)($in['license']['expires'] ?? ''));
            return $r + ['license' => lic_report()];
        case 'banners':  // los manda el Cronos principal; reemplazan a los anteriores
            if (!u_red_lib()) throw new UError('Este sitio todavía no muestra banners: instala la versión nueva.');
            if (!is_array($in['banners'] ?? null)) throw new UError('Banners inválidos.');
            try { $r = red_banners_save($in['banners'], (string)u_cfg()['central_url']); } catch (RuntimeException $e) { throw new UError($e->getMessage()); }
            u_log('banners', '', $r['banners'] . ' recibidos');
            return $r;
    }
    throw new UError('Acción desconocida.');
}
