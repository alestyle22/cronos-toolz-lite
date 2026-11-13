<?php
/**
 * red_lib.php — Lo que este sitio le cuenta al Cronos principal (sitio base) y los banners que recibe de él.
 *  - red_stats(): salud y uso (usuarios, actividad, clientes, espacio, último ingreso). Viaja en el "status" firmado (_update/lib.php).
 *    Solo totales: nunca datos de los clientes de la agencia.
 *  - Banners: el base los manda por remote.php (acción "banners") y quedan en _private/banners.json; se muestran en el Inicio y en Admin.
 * No necesita auth.php: el actualizador lo carga aunque el sitio esté roto. Doc: toolz/RED.md
 */
if (!defined('RED_ROOT')) define('RED_ROOT', __DIR__);
const RED_STATS_FILE = RED_ROOT . '/_private/red_stats.json';
const RED_BANNERS_FILE = RED_ROOT . '/_private/banners.json';
const RED_STATS_TTL = 600;   // el panel del base consulta seguido: los números se recalculan cada 10 minutos
const RED_STYLES = ['novedad', 'aviso', 'promo'];

/** Conexión propia a la base del sitio (mismos datos que auth.php), con tiempo de espera corto. */
function red_db(): ?PDO {
    try {
        if (is_file(RED_ROOT . '/config.local.php')) require_once RED_ROOT . '/config.local.php';
        if (!function_exists('g7_site')) require_once RED_ROOT . '/site_lib.php';
        if (is_file(RED_ROOT . '/_private/secrets.php')) require_once RED_ROOT . '/_private/secrets.php';
        $host = defined('DB_HOST') ? DB_HOST : g7_site('db_host', 'localhost');
        $name = defined('DB_NAME') ? DB_NAME : g7_site('db_name');
        $user = defined('DB_USER') ? DB_USER : g7_site('db_user');
        return new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", (string)$user, defined('DB_PASS') ? DB_PASS : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (Throwable $e) { return null; }
}

/** Salud y uso del sitio, para el Cronos principal. */
function red_stats(bool $fresh = false): array {
    $c = is_file(RED_STATS_FILE) ? (json_decode((string)file_get_contents(RED_STATS_FILE), true) ?: []) : [];
    if (!$fresh && !empty($c['at']) && time() - (int)$c['at'] < RED_STATS_TTL) return $c;
    $db = red_db();
    $q = function (string $sql) use ($db) { try { return $db ? $db->query($sql)->fetchColumn() : null; } catch (Throwable $e) { return null; } };
    $s = ['at' => time(), 'db_ok' => (bool)$db, 'php' => PHP_VERSION];
    if ($db) {
        $s['users'] = (int)$q('SELECT COUNT(*) FROM g7_users WHERE active = 1');
        $s['admins'] = (int)$q("SELECT COUNT(*) FROM g7_users WHERE active = 1 AND role = 'admin'");
        $s['active_7d'] = (int)$q('SELECT COUNT(*) FROM g7_users WHERE active = 1 AND (last_login >= NOW() - INTERVAL 7 DAY OR last_seen >= NOW() - INTERVAL 7 DAY)');
        $s['active_30d'] = (int)$q('SELECT COUNT(*) FROM g7_users WHERE active = 1 AND (last_login >= NOW() - INTERVAL 30 DAY OR last_seen >= NOW() - INTERVAL 30 DAY)');
        $a = $q('SELECT MAX(last_login) FROM g7_users'); $b = $q('SELECT MAX(last_seen) FROM g7_users');
        $s['last_login'] = max((string)$a, (string)$b) ?: null;
        $cl = $q("SELECT COUNT(*) FROM g7_clients WHERE status <> 'terminado'");
        $s['clients'] = $cl === null ? null : (int)$cl;
        $s['installed'] = $q('SELECT MIN(created_at) FROM g7_users') ?: null;
    } elseif ($c) {   // la base no responde: se informa y se reusan los últimos números
        foreach (['users', 'admins', 'active_7d', 'active_30d', 'last_login', 'clients', 'installed'] as $k) if (array_key_exists($k, $c)) $s[$k] = $c[$k];
    }
    // Espacio: lo calcula license_lib (con su propia caché de una hora) si está; si no, se reusa el último
    if (function_exists('lic_usage')) { $u = lic_usage(); $s['bytes'] = (int)($u['bytes'] ?? 0); }
    elseif (isset($c['bytes'])) $s['bytes'] = (int)$c['bytes'];
    $site = function_exists('g7_site') ? g7_site() : [];
    $s['modules_off'] = count((array)($site['modulos_off'] ?? []));
    $s['funciones'] = array_values((array)($site['funciones'] ?? []));
    @file_put_contents(RED_STATS_FILE, json_encode($s), LOCK_EX);
    return $s;
}

// ── Banners ────────────────────────────────────────────────
/** Limpia lo que manda el base: solo texto, links http(s) e imágenes del propio base. */
function red_banner_clean(array $b, string $baseHost): ?array {
    $id = preg_replace('/[^a-z0-9_-]/i', '', (string)($b['id'] ?? ''));
    $place = in_array($b['place'] ?? '', ['dashboard', 'admin'], true) ? $b['place'] : '';
    if ($id === '' || $place === '') return null;
    $url = trim((string)($b['btn_url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
    $img = trim((string)($b['image'] ?? ''));
    if ($img !== '' && (!preg_match('#^https://#i', $img) || strtolower((string)parse_url($img, PHP_URL_HOST)) !== $baseHost)) $img = '';
    $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    return ['id' => $id, 'place' => $place, 'title' => mb_substr(trim((string)($b['title'] ?? '')), 0, 120), 'text' => mb_substr(trim((string)($b['text'] ?? '')), 0, 400),
        'btn_text' => mb_substr(trim((string)($b['btn_text'] ?? '')), 0, 40), 'btn_url' => $url, 'image' => $img,
        'style' => in_array($b['style'] ?? '', RED_STYLES, true) ? $b['style'] : 'novedad', 'start' => $d($b['start'] ?? ''), 'end' => $d($b['end'] ?? ''),
        'dismissible' => !empty($b['dismissible']), 'rev' => (int)($b['rev'] ?? 0)];
}
/** Guarda los banners que manda el base (reemplaza los anteriores). */
function red_banners_save(array $list, string $centralUrl): array {
    $host = strtolower((string)parse_url($centralUrl, PHP_URL_HOST));
    $out = [];
    foreach (array_slice($list, 0, 20) as $b) if (is_array($b) && ($c = red_banner_clean($b, $host))) $out[] = $c;
    if (@file_put_contents(RED_BANNERS_FILE, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('No se pudieron guardar los banners en este sitio.');
    return ['banners' => count($out)];
}
/** Banners vigentes hoy para un lugar ('dashboard' o 'admin'). */
function red_banners(string $place): array {
    $all = is_file(RED_BANNERS_FILE) ? (json_decode((string)file_get_contents(RED_BANNERS_FILE), true) ?: []) : [];
    $today = date('Y-m-d');
    return array_values(array_filter($all, fn($b) => ($b['place'] ?? '') === $place && (($b['start'] ?? '') === '' || $b['start'] <= $today) && (($b['end'] ?? '') === '' || $b['end'] >= $today)));
}
/** HTML de los banners de un lugar. Los que se pueden cerrar quedan cerrados para esa persona en ese navegador (hasta que cambien). */
function red_banners_html(string $place): string {
    $list = red_banners($place);
    if (!$list) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<style>.rbn{position:relative;display:flex;align-items:center;gap:18px;border-radius:14px;padding:16px 20px;margin:0 0 18px;border:1px solid var(--rbn-b);background:var(--rbn-bg);overflow:hidden}
.rbn.novedad{--rbn-c:var(--g7-accent,#ff7700);--rbn-bg:color-mix(in srgb,var(--rbn-c) 10%,var(--g7-bg-3,#141414));--rbn-b:color-mix(in srgb,var(--rbn-c) 35%,transparent)}
.rbn.aviso{--rbn-c:#f59e0b;--rbn-bg:color-mix(in srgb,#f59e0b 10%,var(--g7-bg-3,#141414));--rbn-b:color-mix(in srgb,#f59e0b 40%,transparent)}
.rbn.promo{--rbn-c:#a855f7;--rbn-bg:linear-gradient(110deg,color-mix(in srgb,#a855f7 18%,var(--g7-bg-3,#141414)),color-mix(in srgb,var(--g7-accent,#ff7700) 14%,var(--g7-bg-3,#141414)));--rbn-b:color-mix(in srgb,#a855f7 40%,transparent)}
.rbn-img{width:120px;height:72px;border-radius:10px;object-fit:cover;flex-shrink:0}.rbn-tx{flex:1;min-width:0}.rbn-tx b{display:block;font-size:15px;font-weight:800;color:var(--g7-text,#fff)}
.rbn-tx span{display:block;font-size:13px;color:var(--g7-text-3,#bbb);margin-top:2px;line-height:1.5}.rbn-btn{flex-shrink:0;background:var(--rbn-c);color:#000;text-decoration:none;font-weight:700;font-size:13px;padding:9px 16px;border-radius:9px;white-space:nowrap}
.rbn-x{position:absolute;top:8px;right:10px;background:none;border:0;color:var(--g7-text-4,#888);font-size:15px;cursor:pointer;padding:4px}.rbn-x:hover{color:var(--g7-text,#fff)}
@media(max-width:700px){.rbn{flex-wrap:wrap;padding:14px 16px}.rbn-img{width:100%;height:120px}.rbn-btn{width:100%;text-align:center}}</style>';
    foreach ($list as $b) {
        $key = 'rbn_' . $b['id'] . '_' . $b['rev'];
        $o .= '<div class="rbn ' . $h($b['style']) . '" data-rbn="' . $h($key) . '"' . ($b['dismissible'] ? ' hidden' : '') . ' role="region" aria-label="' . $h($b['title'] ?: 'Aviso') . '">'
            . ($b['image'] ? '<img class="rbn-img" src="' . $h($b['image']) . '" alt="" loading="lazy">' : '')
            . '<div class="rbn-tx">' . ($b['title'] !== '' ? '<b>' . $h($b['title']) . '</b>' : '') . ($b['text'] !== '' ? '<span>' . nl2br($h($b['text'])) . '</span>' : '') . '</div>'
            . ($b['btn_url'] !== '' ? '<a class="rbn-btn" href="' . $h($b['btn_url']) . '" target="_blank" rel="noopener">' . $h($b['btn_text'] ?: 'Ver más') . '</a>' : '')
            . ($b['dismissible'] ? '<button class="rbn-x" type="button" aria-label="Cerrar" title="Cerrar">&times;</button>' : '') . '</div>';
    }
    // Los que se pueden cerrar arrancan ocultos y se muestran si esta persona no los cerró (sin parpadeo)
    $o .= '<script src="/toolz/assets/red-banners.js?v=1"></script>';   // antes en línea (CSP estricta)
    return $o;
}
