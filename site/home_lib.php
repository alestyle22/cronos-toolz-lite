<?php
/**
 * home_lib.php — Configuración del Inicio (index.php): accesos rápidos y secciones.
 * Se guarda en g7_config 'home_config' y se edita en Admin › Inicio y accesos (admin_home.js).
 * Sin configuración guardada, el Inicio se ve como siempre (los valores de abajo).
 */
require_once __DIR__ . '/access.php';

const HOME_SECTIONS = [
    'alert'   => ['label' => 'Aviso de lo que vence hoy',  'desc' => 'Promos que terminan hoy, en rojo arriba de todo.', 'title' => false],
    'working' => ['label' => 'Tareas en curso',            'desc' => 'Las tareas en proceso y las pendientes: primero las de cada persona, con una pestaña para todo el equipo.', 'title' => true, 'new_on' => true],
    'recent'  => ['label' => 'Sigue donde dejaste',        'desc' => 'Las últimas herramientas que abrió cada persona.',  'title' => true],
    'tiles'   => ['label' => 'Accesos rápidos',            'desc' => 'Los bloques de colores que armas acá abajo.',       'title' => true],
    // new_on: secciones agregadas después; en un Inicio ya configurado aparecen encendidas y en su lugar, no apagadas al final
    'clients' => ['label' => 'Clientes',                   'desc' => 'Los clientes activos con su foto: abre su ficha o su carpeta de Materiales.', 'title' => true, 'new_on' => true],
    'more'    => ['label' => 'Más herramientas',           'desc' => 'Las herramientas que no tienen bloque.',             'title' => true],
    'panels'  => ['label' => 'Vencen pronto y Resumen',    'desc' => 'Dos tarjetas lado a lado: promos por vencer, números y actividad del equipo.', 'title' => false],
    'favs'    => ['label' => 'Tus favoritos',              'desc' => 'Favoritos de la Biblioteca de cada persona.',       'title' => true],
    'popular' => ['label' => 'Lo más usado esta semana',   'desc' => 'Lo más abierto de la Biblioteca en 7 días.',         'title' => true],
];

function home_default_tiles(): array {
    $t = fn($id, $href, $icon, $name, $desc, $color, $badge = '') => ['id' => $id, 'href' => $href, 'icon' => $icon, 'name' => $name, 'desc' => $desc,
        'color' => $color, 'badge' => $badge, 'newtab' => false, 'on' => true, 'audience' => 'all', 'teams' => []];
    return [
        $t('biblioteca', '/toolz/links/',             'fa-book-bookmark',  'Biblioteca',      'Recursos y enlaces',      '#ff6600'),
        $t('promos',     '/toolz/promos/',            'fa-layer-group',    'Promos CMS',      'Gestión de contenido',    '#a78bfa'),
        $t('mailing',    '/toolz/mailingmaker/',      'fa-envelope',       'Mailing Maker',   'Generador de mailings',   '#60a5fa'),
        $t('linkgen',    '/toolz/linkgen/',           'fa-link',           'Links Generator', 'Generador de enlaces',    '#4ade80'),
        $t('placas',     '/toolz/placasdeportivas/hub.php',   'fa-futbol',         'Placas Dep.',     'Partidos, cuotas y lote', '#fbbf24'),
        $t('placas_diarias', '/toolz/placasdeportivas/diarias.php', 'fa-calendar-day', 'Placas Diarias', 'Placas de redes por día', '#22c55e'),
        $t('placas_paid', '/toolz/placasdeportivas/paid.php', 'fa-bullhorn', 'Placas Paid', 'Placas de pauta por campaña', '#38bdf8'),
        $t('promos_gen', '/toolz/placasdeportivas/promos.php', 'fa-tags', 'Generador de promos', 'Todas las piezas de una promo', '#f472b6'),
        $t('comunidad',  '/toolz/comunidad.php',      'fa-people-group',   'Comunidad',       'Perfiles y actividad',    '#ec4899'),
        $t('stock',      '/toolz/stock.php',          'fa-photo-film',     'Stock',           'Galería multimedia',      '#a78bfa'),
        $t('chat',       '/toolz/chat_page.php',      'fa-comment-dots',   'Chat',            'Mensajes del equipo',     '#ff6600'),
        $t('webp',       '/toolz/webp_converter.php', 'fa-image',          'WebP Converter',  'Convierte imágenes',       '#60a5fa'),
        $t('jpg',        '/toolz/jpg_converter.php',  'fa-file-image',     'WebP a JPG',      'Pasa imágenes a JPG',     '#4ade80'),
        $t('landing',    '/toolz/landing-gen/',       'fa-rocket',         'Landing Gen',     'Editor de landings',      '#ff6600', 'IA'),
        $t('popup',      '/toolz/popup-gen/',         'fa-window-restore', 'Popup Gen',       'Generador de popups',     '#a78bfa'),
    ];
}

function home_defaults(): array {
    $sections = [];
    foreach (HOME_SECTIONS as $id => $s) $sections[] = ['id' => $id, 'on' => true, 'title' => ''];
    return [
        'tiles' => home_default_tiles(),
        'sections' => $sections,
        'subtitle' => '',          // vacío = el texto automático ("N promos vencen esta semana")
        'search' => true,          // buscador de herramientas del encabezado
        'greeting' => true,        // "Buen día, Nombre"
        'panel_soon' => true, 'panel_summary' => true, 'panel_activity' => true,
    ];
}

/** Configuración vigente: lo guardado, completado con los valores por defecto. */
/** Sin la función de clientes, esa sección no aparece. */
function home_config(): array {
    $c = home_config_all();
    if (!g7_site_has('clientes')) $c['sections'] = array_values(array_filter($c['sections'], fn($s) => $s['id'] !== 'clients'));
    return $c;
}
function home_config_all(): array {
    $d = home_defaults();
    $c = g7_cfg_json('home_config', []);
    if (!$c) return $d;
    $c = home_sanitize($c);
    return $c + $d;
}

function home_str($v, int $max): string { return mb_substr(trim(strip_tags((string)$v)), 0, $max); }

/** Valida lo que manda el editor. Lo que no sirve se descarta; las secciones que falten se agregan al final, apagadas. */
function home_sanitize(array $in): array {
    $d = home_defaults();
    $tiles = [];
    $seen = [];
    foreach (array_slice(is_array($in['tiles'] ?? null) ? $in['tiles'] : [], 0, 60) as $t) {
        if (!is_array($t)) continue;
        $href = trim((string)($t['href'] ?? ''));
        if (!preg_match('~^(/(?!/)|https?://)~i', $href)) continue;           // solo rutas internas o http(s)
        $name = home_str($t['name'] ?? '', 40);
        if ($name === '') continue;
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($t['id'] ?? ''))) ?: 't' . substr(md5($href . $name), 0, 8);
        while (isset($seen[$id])) $id .= 'x';
        $seen[$id] = true;
        $aud = in_array($t['audience'] ?? '', ['all', 'admins', 'teams'], true) ? $t['audience'] : 'all';
        $tiles[] = [
            'id' => $id, 'href' => mb_substr($href, 0, 500), 'name' => $name, 'desc' => home_str($t['desc'] ?? '', 80),
            'icon' => preg_match('/^fa-[a-z0-9-]{1,40}$/', (string)($t['icon'] ?? '')) ? $t['icon'] : 'fa-link',
            'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($t['color'] ?? '')) ? strtolower($t['color']) : '#ff6600',
            'badge' => home_str($t['badge'] ?? '', 8), 'newtab' => !empty($t['newtab']), 'on' => !isset($t['on']) || !empty($t['on']),
            'audience' => $aud, 'teams' => $aud === 'teams' ? array_values(array_unique(array_filter(array_map('intval', (array)($t['teams'] ?? []))))) : [],
        ];
    }
    $sections = [];
    foreach (is_array($in['sections'] ?? null) ? $in['sections'] : [] as $s) {
        $id = is_array($s) ? (string)($s['id'] ?? '') : '';
        if (!isset(HOME_SECTIONS[$id]) || isset($sections[$id])) continue;
        $sections[$id] = ['id' => $id, 'on' => !empty($s['on']), 'title' => HOME_SECTIONS[$id]['title'] ? home_str($s['title'] ?? '', 40) : ''];
    }
    $prev = null;
    foreach (HOME_SECTIONS as $id => $def) {
        if (!isset($sections[$id])) {
            $row = ['id' => $id, 'on' => !empty($def['new_on']), 'title' => ''];
            // Va detrás de la sección que la precede en la lista de siempre (si está); si no, al final
            if (!empty($def['new_on']) && $prev !== null && isset($sections[$prev])) {
                $pos = array_search($prev, array_keys($sections), true) + 1;
                $sections = array_slice($sections, 0, $pos, true) + [$id => $row] + array_slice($sections, $pos, null, true);
            } else $sections[$id] = $row;
        }
        $prev = $id;
    }
    $b = fn($k) => array_key_exists($k, $in) ? !empty($in[$k]) : $d[$k];
    return [
        'tiles' => $tiles, 'sections' => array_values($sections), 'subtitle' => home_str($in['subtitle'] ?? '', 160),
        'search' => $b('search'), 'greeting' => $b('greeting'),
        'panel_soon' => $b('panel_soon'), 'panel_summary' => $b('panel_summary'), 'panel_activity' => $b('panel_activity'),
    ];
}

/** ¿Esta persona ve el bloque? Encendido, para su público, y si es una página de la intranet, que pueda abrirla. */
function home_tile_visible(int $uid, array $t): bool {
    if (empty($t['on'])) return false;
    $ctx = g7_ctx($uid);
    if ($t['audience'] === 'admins' && $ctx['role'] !== 'admin') return false;
    if ($t['audience'] === 'teams' && $ctx['role'] !== 'admin' && !array_intersect($t['teams'], $ctx['teams'])) return false;
    return !str_starts_with($t['href'], '/') || g7_href_visible($uid, $t['href']);
}

/** "#ff6600" → "255,102,0" para los degradés del bloque. */
function home_rgb(string $hex): string {
    $h = ltrim($hex, '#');
    return hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2));
}

/** Título de una sección: el que puso el admin o el de siempre. */
function home_title(array $cfg, string $id): string {
    foreach ($cfg['sections'] as $s) if ($s['id'] === $id && $s['title'] !== '') return $s['title'];
    // Rubro: la sección Clientes toma el nombre del vocabulario si la agencia lo cambió (sin cambio, el de siempre)
    if ($id === 'clients' && function_exists('g7_term') && isset(g7_cfg_json('vocab', [])['client'])) return g7_term('client', 'p');
    return HOME_SECTIONS[$id]['label'];
}
