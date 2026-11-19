<?php
/**
 * studio_lib.php — Herramientas del "estudio": las del Taller (Globo, Etiqueta, Calco, Rótulo) y las de Cronos con el
 * diseño estudio (WebP, WebP a JPG, SVG → PNG, Recolorear, Renombrador). Es la lista del sidebar común:
 *   - en las páginas del Taller (taller/*.php) la dibuja taller/nav.js
 *   - en las de Cronos la dibuja assets/g7-studio.js (topbar.php deja la lista en #g7-studio-tools)
 * Cada persona ve solo las que tiene habilitadas (g7_href_visible). Doc: toolz/MOVIMIENTO.md › Estudio
 */
const G7_STUDIO_TOOLS = [
    ['id' => 'globo',       'name' => 'Globo',          'desc' => 'Mensajes, chats y notificaciones', 'url' => '/toolz/taller/globo.php',    'fa' => 'fa-comment-dots',   'lu' => 'message-circle-heart', 'grad' => 'linear-gradient(135deg,#34C8FF,#0A7AFF 60%,#5B5BFF)'],
    ['id' => 'etiqueta',    'name' => 'Etiqueta',       'desc' => 'Etiquetas de usuario para fotos',  'url' => '/toolz/taller/etiqueta.php', 'fa' => 'fa-at',             'lu' => 'at-sign',              'grad' => 'linear-gradient(135deg,#FCAF45,#E1306C 55%,#833AB4)'],
    ['id' => 'calco',       'name' => 'Calco',          'desc' => 'Stickers troquelados desde una foto', 'url' => '/toolz/taller/calco.php', 'fa' => 'fa-note-sticky',    'lu' => 'sticker',              'grad' => 'linear-gradient(135deg,#FFD43B,#FF6A3D 55%,#FF3B6B)'],
    ['id' => 'rotulo',      'name' => 'Rótulo',         'desc' => 'Títulos animados',                 'url' => '/toolz/taller/rotulo.php',   'fa' => 'fa-heading',        'lu' => 'type',                 'grad' => 'linear-gradient(135deg,#00E5FF,#7C5CFF 55%,#FF2BD6)'],
    ['id' => 'resena',      'name' => 'Reseña',         'desc' => 'Puntajes y reseñas',               'url' => '/toolz/taller/resena.php',   'fa' => 'fa-star',           'lu' => 'star',                 'grad' => 'linear-gradient(135deg,#FDE047,#F59E0B 55%,#EA580C)'],
    ['id' => 'codigo',      'name' => 'Código QR',      'desc' => 'QR con estilo',                    'url' => '/toolz/taller/codigo.php',   'fa' => 'fa-qrcode',         'lu' => 'qr-code',              'grad' => 'linear-gradient(135deg,#34D399,#0EA5E9 55%,#6366F1)'],
    ['id' => 'webp',        'name' => 'Convertidor WebP', 'desc' => 'Imágenes livianas con el peso que elijas', 'url' => '/toolz/webpmaker.php', 'fa' => 'fa-feather',  'lu' => 'feather',              'grad' => 'linear-gradient(135deg,#FFB547,#FF6A00 60%,#E8441A)'],
    ['id' => 'jpg',         'name' => 'WebP a JPG',     'desc' => 'De WebP, PNG o AVIF a JPG',         'url' => '/toolz/jpg_converter.php',  'fa' => 'fa-image',          'lu' => 'image',                'grad' => 'linear-gradient(135deg,#7DD3FC,#3B82F6 60%,#4338CA)'],
    ['id' => 'svg',         'name' => 'SVG → PNG',      'desc' => 'Todos los tamaños y versiones de un logo', 'url' => '/toolz/svg_converter.php', 'fa' => 'fa-vector-square', 'lu' => 'pen-tool',        'grad' => 'linear-gradient(135deg,#A3E635,#22C55E 60%,#0F766E)'],
    ['id' => 'recolorear',  'name' => 'Recolorear SVG', 'desc' => 'Un logo, todos los colores en PNG', 'url' => '/toolz/recolorearsvg.php',  'fa' => 'fa-palette',        'lu' => 'palette',              'grad' => 'linear-gradient(135deg,#F9A8D4,#EC4899 55%,#9333EA)'],
    ['id' => 'renombrador', 'name' => 'Renombrador',    'desc' => 'Nombres prolijos y un ZIP listo',   'url' => '/toolz/renombrador.php',    'fa' => 'fa-signature',      'lu' => 'signature',            'grad' => 'linear-gradient(135deg,#FDE68A,#F59E0B 55%,#B45309)'],
    ['id' => 'mockups',     'name' => 'Mockup Studio',  'desc' => 'Tus diseños sobre productos y escenas', 'url' => '/toolz/mockups/', 'fa' => 'fa-layer-group', 'lu' => 'layers', 'grad' => 'linear-gradient(135deg,#22D3EE,#0EA5E9 50%,#6366F1)'],
];

/** Las herramientas del estudio que esta persona puede abrir, en orden. */
function g7_studio_tools(int $uid): array {
    $out = [];
    foreach (G7_STUDIO_TOOLS as $t) {
        if (function_exists('g7_href_visible') && !g7_href_visible($uid, $t['url'])) continue;
        $out[] = $t;
    }
    return $out;
}
/** ¿Esta dirección es una herramienta del estudio? (para que topbar.php deje la lista solo donde hace falta) */
function g7_studio_is_page(string $path): bool {
    $p = parse_url($path, PHP_URL_PATH) ?: $path;
    foreach (G7_STUDIO_TOOLS as $t) if (rtrim($p, '/') === $t['url']) return true;
    return in_array(basename($p), ['webp_converter.php'], true);
}
