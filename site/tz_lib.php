<?php
/**
 * tz_lib.php — Zonas horarias: cómo se guardan las fechas y cómo se muestran a cada persona.
 *
 * Reglas (doc completa: toolz/ZONAS_HORARIAS.md):
 *   - Zona de almacenamiento (g7_config tz_storage): una sola por instalación y fija. Se toma la primera vez de la zona del sitio
 *     y no cambia aunque el admin cambie la zona del sitio. PHP (date()) y MySQL (NOW(), CURRENT_TIMESTAMP) escriben siempre en ella:
 *     getDB() llama a g7_tz_boot() al conectar. Así cada DATETIME guardado es un instante exacto.
 *   - Zona del sitio (g7_config timezone): la que se usa para mostrar a quien no eligió la suya.
 *   - Zona de cada persona (g7_users.timezone, id IANA como America/Argentina/Buenos_Aires): la elige en Mi perfil › Ubicación.
 *     Los cambios de horario de verano se aplican solos porque se guarda el id, no el desfase.
 *   - Cambiar la zona de una persona o del sitio nunca toca lo guardado: solo cambia cómo se muestra.
 *   - Las fechas sin hora (DATE, eventos de todo el día) no se convierten.
 * En el navegador, el par de este archivo es assets/g7-time.js (lo carga topbar.php con la configuración de g7_tz_js_config()).
 */

const G7_TZ_DEFAULT = 'America/Argentina/Buenos_Aires';

/** Zona principal de los países con varias (la que se propone primero). El resto: la primera de la lista de PHP. */
const G7_TZ_MAIN = [
    'AR' => 'America/Argentina/Buenos_Aires', 'US' => 'America/New_York', 'BR' => 'America/Sao_Paulo', 'MX' => 'America/Mexico_City',
    'ES' => 'Europe/Madrid', 'CA' => 'America/Toronto', 'AU' => 'Australia/Sydney', 'RU' => 'Europe/Moscow', 'CL' => 'America/Santiago',
    'EC' => 'America/Guayaquil', 'PT' => 'Europe/Lisbon', 'CN' => 'Asia/Shanghai', 'ID' => 'Asia/Jakarta', 'KZ' => 'Asia/Almaty',
    'NZ' => 'Pacific/Auckland', 'DE' => 'Europe/Berlin', 'CO' => 'America/Bogota', 'PY' => 'America/Asuncion', 'UY' => 'America/Montevideo',
    'GB' => 'Europe/London', 'FR' => 'Europe/Paris', 'IT' => 'Europe/Rome', 'UA' => 'Europe/Kyiv', 'MN' => 'Asia/Ulaanbaatar',
    'CD' => 'Africa/Kinshasa', 'MY' => 'Asia/Kuala_Lumpur', 'UZ' => 'Asia/Tashkent', 'PF' => 'Pacific/Tahiti', 'KI' => 'Pacific/Tarawa',
    'FM' => 'Pacific/Pohnpei', 'MH' => 'Pacific/Majuro', 'GL' => 'America/Nuuk', 'CY' => 'Asia/Nicosia', 'PS' => 'Asia/Gaza',
];

/** Nombres en español de las zonas más vistas. Las demás: la última parte del id, sin guiones bajos. */
const G7_TZ_NAMES = [
    'America/Argentina/Buenos_Aires' => 'Buenos Aires', 'America/Argentina/Cordoba' => 'Córdoba', 'America/Argentina/Tucuman' => 'Tucumán',
    'America/Argentina/Rio_Gallegos' => 'Río Gallegos', 'America/Argentina/San_Luis' => 'San Luis', 'America/Argentina/San_Juan' => 'San Juan',
    'America/New_York' => 'Nueva York (Este)', 'America/Chicago' => 'Chicago (Centro)', 'America/Denver' => 'Denver (Montaña)',
    'America/Phoenix' => 'Phoenix (Arizona)', 'America/Los_Angeles' => 'Los Ángeles (Pacífico)', 'America/Anchorage' => 'Anchorage (Alaska)',
    'Pacific/Honolulu' => 'Honolulu (Hawái)', 'America/Sao_Paulo' => 'São Paulo (Brasilia)', 'America/Manaus' => 'Manaos',
    'America/Noronha' => 'Fernando de Noronha', 'America/Belem' => 'Belém', 'America/Fortaleza' => 'Fortaleza', 'America/Recife' => 'Recife',
    'America/Bahia' => 'Bahía', 'America/Cuiaba' => 'Cuiabá', 'America/Porto_Velho' => 'Porto Velho', 'America/Rio_Branco' => 'Río Branco',
    'America/Mexico_City' => 'Ciudad de México (Centro)', 'America/Cancun' => 'Cancún', 'America/Mazatlan' => 'Mazatlán (Pacífico)',
    'America/Tijuana' => 'Tijuana', 'America/Hermosillo' => 'Hermosillo (Sonora)', 'America/Merida' => 'Mérida', 'America/Monterrey' => 'Monterrey',
    'America/Chihuahua' => 'Chihuahua', 'Europe/Madrid' => 'Madrid (Península)', 'Atlantic/Canary' => 'Canarias', 'Africa/Ceuta' => 'Ceuta y Melilla',
    'Europe/London' => 'Londres', 'Europe/Lisbon' => 'Lisboa', 'Atlantic/Azores' => 'Azores', 'Atlantic/Madeira' => 'Madeira',
    'America/Toronto' => 'Toronto (Este)', 'America/Vancouver' => 'Vancouver (Pacífico)', 'America/Winnipeg' => 'Winnipeg (Centro)',
    'America/Edmonton' => 'Edmonton (Montaña)', 'America/Halifax' => 'Halifax (Atlántico)', 'America/St_Johns' => 'Terranova',
    'America/Santiago' => 'Santiago', 'Pacific/Easter' => 'Isla de Pascua', 'America/Punta_Arenas' => 'Punta Arenas',
    'America/Guayaquil' => 'Guayaquil', 'Pacific/Galapagos' => 'Galápagos', 'Europe/Moscow' => 'Moscú', 'UTC' => 'UTC',
];

function g7_tz_valid(?string $tz): bool {
    static $ok = null;
    if ($tz === null || $tz === '') return false;
    if ($ok === null) $ok = array_flip(DateTimeZone::listIdentifiers());
    return isset($ok[$tz]) || $tz === 'UTC';
}

/**
 * Al conectar a la base: deja PHP y MySQL en la zona de almacenamiento. La primera vez la fija (tz_storage) y anota
 * desde cuándo y con qué desfase venía escribiendo MySQL, para poder corregir lo histórico (dev/tz_corregir_historico.php).
 */
function g7_tz_boot(PDO $pdo): void {
    $store = '';
    try {
        $cfg = [];
        foreach ($pdo->query("SELECT cfg_key, cfg_value FROM g7_config WHERE cfg_key IN ('tz_storage','timezone')")->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['cfg_key']] = (string)$r['cfg_value'];
        $store = $cfg['tz_storage'] ?? '';
        if (!g7_tz_valid($store)) {
            $store = g7_tz_valid($cfg['timezone'] ?? '') ? $cfg['timezone'] : G7_TZ_DEFAULT;
            date_default_timezone_set($store);
            // Cómo escribía MySQL hasta ahora (su zona propia) comparado con la de almacenamiento: dato para corregir lo histórico
            $dbOff = (int)$pdo->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())')->fetchColumn();
            $ins = $pdo->prepare("INSERT IGNORE INTO g7_config (cfg_key, cfg_value) VALUES (?, ?)");
            $ins->execute(['tz_storage', $store]);
            $ins->execute(['tz_fixed_at', date('Y-m-d H:i:s')]);
            $ins->execute(['tz_db_offset_before', (string)$dbOff]);
            // Si otro pedido la fijó al mismo tiempo, vale la que quedó guardada
            $store = (string)$pdo->query("SELECT cfg_value FROM g7_config WHERE cfg_key = 'tz_storage'")->fetchColumn() ?: $store;
        }
    } catch (Throwable $e) {
        // Sin g7_config (instalación a medias): la zona por defecto
    }
    if (!g7_tz_valid($store)) $store = G7_TZ_DEFAULT;
    date_default_timezone_set($store);
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
}

/** Zona en la que están guardadas las fechas. */
function g7_tz_storage(): string {
    $z = function_exists('g7_cfg') ? (string)g7_cfg('tz_storage', '') : '';
    return g7_tz_valid($z) ? $z : date_default_timezone_get();
}

/** Zona por defecto para mostrar (la del sitio). */
function g7_tz_site(): string {
    $z = function_exists('g7_cfg') ? (string)g7_cfg('timezone', '') : '';
    return g7_tz_valid($z) ? $z : g7_tz_storage();
}

/** Columnas de ubicación en g7_users (se agregan solas la primera vez). */
function g7_tz_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    if (function_exists('g7_cfg') && g7_cfg('tz_schema') === '1') return;
    $have = [];
    try { foreach ($db->query('SHOW COLUMNS FROM g7_users')->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true; } catch (Throwable $e) { return; }
    foreach (['country' => "CHAR(2) NOT NULL DEFAULT ''", 'timezone' => "VARCHAR(64) NOT NULL DEFAULT ''", 'city' => "VARCHAR(100) NOT NULL DEFAULT ''",
              'city_public' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $def)
        if (!isset($have[$col])) try { $db->exec("ALTER TABLE g7_users ADD COLUMN $col $def"); } catch (Throwable $e) {}
    if (function_exists('g7_cfg_set')) try { g7_cfg_set('tz_schema', '1'); } catch (Throwable $e) {}
}

/** Ubicación guardada de una persona: ['country','timezone','city','city_public']. */
function g7_tz_row(int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $r = ['country' => '', 'timezone' => '', 'city' => '', 'city_public' => 0];
    if ($uid > 0) try {
        g7_tz_install(getDB());
        $q = getDB()->prepare('SELECT country, timezone, city, city_public FROM g7_users WHERE id = ?');
        $q->execute([$uid]);
        if ($x = $q->fetch(PDO::FETCH_ASSOC)) $r = ['country' => (string)$x['country'], 'timezone' => (string)$x['timezone'], 'city' => (string)$x['city'], 'city_public' => (int)$x['city_public']];
    } catch (Throwable $e) {}
    return $cache[$uid] = $r;
}

/** Zona con la que se le muestran las fechas a una persona: la suya o, si no eligió, la del sitio. */
function g7_tz_user(int $uid): string {
    $z = g7_tz_row($uid)['timezone'];
    return g7_tz_valid($z) ? $z : g7_tz_site();
}

/** Zona de quien está mirando la página (sesión actual). */
function g7_tz_viewer(): string {
    $uid = (int)($_SESSION['g7_user_id'] ?? 0);
    return $uid ? g7_tz_user($uid) : g7_tz_site();
}

/** Una fecha guardada ('Y-m-d H:i:s', zona de almacenamiento) como DateTimeImmutable en la zona pedida (por defecto, la de quien mira). */
function g7_dt_obj(?string $stored, ?string $tz = null): ?DateTimeImmutable {
    if ($stored === null || $stored === '' || strpos($stored, '0000-00-00') === 0) return null;
    try { $d = new DateTimeImmutable($stored, new DateTimeZone(g7_tz_storage())); } catch (Throwable $e) { return null; }
    return $d->setTimezone(new DateTimeZone(g7_tz_valid($tz) ? $tz : g7_tz_viewer()));
}

/** Fecha guardada → texto en la zona de quien mira (o la pedida). Formato de date(). */
function g7_dt(?string $stored, string $fmt = 'd/m/Y H:i', ?string $tz = null): string {
    $d = g7_dt_obj($stored, $tz);
    return $d ? $d->format($fmt) : '';
}

/** Fecha guardada → ISO 8601 con desfase (para APIs que la quieran sin ambigüedad). */
function g7_dt_iso(?string $stored): string {
    $d = g7_dt_obj($stored, 'UTC');
    return $d ? $d->format('Y-m-d\TH:i:s\Z') : '';
}

/** Instante (timestamp) de una fecha guardada. */
function g7_dt_ts(?string $stored): ?int {
    $d = g7_dt_obj($stored, 'UTC');
    return $d ? $d->getTimestamp() : null;
}

/** Desfase de una zona respecto de UTC, en minutos, en un instante dado (por defecto, ahora). */
function g7_tz_offset_min(string $tz, ?int $ts = null): int {
    if (!g7_tz_valid($tz)) return 0;
    return intdiv((new DateTimeZone($tz))->getOffset(new DateTimeImmutable('@' . ($ts ?? time()))), 60);
}

/** "UTC−3", "UTC+5:30", "UTC". */
function g7_tz_offset_label(string $tz, ?int $ts = null): string {
    $m = g7_tz_offset_min($tz, $ts);
    if ($m === 0) return 'UTC';
    $a = abs($m);
    return 'UTC' . ($m < 0 ? '−' : '+') . intdiv($a, 60) . ($a % 60 ? ':' . str_pad((string)($a % 60), 2, '0', STR_PAD_LEFT) : '');
}

/**
 * Diferencia entre la zona de otra persona y la mía, en palabras: "Tu misma zona horaria", "2 horas menos", "1 hora más",
 * "3 h 30 min más". Se calcula para el instante dado, así que cambia sola con el horario de verano.
 */
function g7_tz_diff_label(string $mine, string $other, ?int $ts = null): string {
    $d = g7_tz_offset_min($other, $ts) - g7_tz_offset_min($mine, $ts);
    if ($d === 0) return 'Tu misma zona horaria';
    $a = abs($d); $h = intdiv($a, 60); $m = $a % 60;
    $txt = $m ? ($h ? "$h h $m min" : "$m min") : ($h === 1 ? '1 hora' : "$h horas");
    return $txt . ($d < 0 ? ' menos' : ' más');
}

/** Nombre corto de una zona: "Buenos Aires", "Canarias", "Nueva York (Este)". */
function g7_tz_name(string $tz): string {
    if (isset(G7_TZ_NAMES[$tz])) return G7_TZ_NAMES[$tz];
    $p = explode('/', $tz);
    return str_replace('_', ' ', end($p));
}

/** País (código ISO de 2 letras) de una zona, o ''. */
function g7_tz_country_of(string $tz): string {
    if (!g7_tz_valid($tz) || $tz === 'UTC') return '';
    try { $l = (new DateTimeZone($tz))->getLocation(); return is_array($l) && ($l['country_code'] ?? '??') !== '??' ? (string)$l['country_code'] : ''; } catch (Throwable $e) { return ''; }
}

/** Zonas de un país, ordenadas por desfase y con la principal primero: [['id','name','offset']]. */
function g7_tz_country_zones(string $cc): array {
    $cc = strtoupper($cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return [];
    try { $ids = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $cc); } catch (Throwable $e) { return []; }
    $main = G7_TZ_MAIN[$cc] ?? ($ids[0] ?? '');
    $out = [];
    foreach ($ids as $id) $out[] = ['id' => $id, 'name' => g7_tz_name($id), 'offset' => g7_tz_offset_min($id)];
    usort($out, fn($a, $b) => [$a['id'] !== $main, -$a['offset'], $a['name']] <=> [$b['id'] !== $main, -$b['offset'], $b['name']]);
    return $out;
}

/** Todos los países con zona: [código => [zonas]] (para el selector de Mi perfil). */
function g7_tz_countries(): array {
    $out = [];
    foreach (DateTimeZone::listIdentifiers() as $id) {
        $cc = g7_tz_country_of($id);
        if ($cc !== '') $out[$cc][] = $id;
    }
    foreach ($out as $cc => $ids) {
        $main = G7_TZ_MAIN[$cc] ?? $ids[0];
        usort($ids, fn($a, $b) => [$a !== $main, $a] <=> [$b !== $main, $b]);
        $out[$cc] = $ids;
    }
    ksort($out);
    return $out;
}

/** ¿La zona pertenece al país? (Al guardar se exige que coincidan.) */
function g7_tz_in_country(string $tz, string $cc): bool {
    return $cc !== '' && in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($cc)), true);
}

/**
 * Lo que se muestra de la ubicación de una persona a otra (chat, perfil): país y zona siempre que los haya cargado;
 * la ciudad solo si eligió compartirla. Sin zona propia no se inventa nada: devuelve tz ''.
 */
function g7_tz_public(array $row): array {
    $tz = (string)($row['timezone'] ?? '');
    $ok = g7_tz_valid($tz);
    return [
        'cc'   => $ok ? strtoupper((string)($row['country'] ?? '')) : '',
        'tz'   => $ok ? $tz : '',
        'zone' => $ok ? g7_tz_name($tz) : '',
        'city' => $ok && !empty($row['city_public']) ? (string)($row['city'] ?? '') : '',
    ];
}

/** Configuración para assets/g7-time.js: zona de almacenamiento, de quien mira y del sitio. */
function g7_tz_js_config(): array {
    return ['store' => g7_tz_storage(), 'tz' => g7_tz_viewer(), 'site' => g7_tz_site()];
}

/** Guarda la ubicación de una persona. Devuelve un mensaje de error o null. */
function g7_tz_save(int $uid, string $cc, string $tz, string $city, bool $cityPublic): ?string {
    $cc = strtoupper(trim($cc)); $tz = trim($tz); $city = trim(preg_replace('/\s+/', ' ', $city));
    if ($cc === '' && $tz === '') { $city = ''; $cityPublic = false; }
    else {
        if (!preg_match('/^[A-Z]{2}$/', $cc)) return 'Elige un país.';
        if (!g7_tz_valid($tz) || !g7_tz_in_country($tz, $cc)) return 'Elige una zona horaria de ese país.';
    }
    if (mb_strlen($city) > 100) return 'La ciudad es demasiado larga.';
    g7_tz_install(getDB());
    getDB()->prepare('UPDATE g7_users SET country = ?, timezone = ?, city = ?, city_public = ? WHERE id = ?')->execute([$cc, $tz, $city, $cityPublic ? 1 : 0, $uid]);
    return null;
}
