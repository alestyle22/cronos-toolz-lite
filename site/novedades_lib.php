<?php
/**
 * novedades_lib.php — Ventana "Qué hay de nuevo": aparece una vez por persona después de cada actualización, con un slider de
 * lo nuevo (novedades_data.php). Al cerrarla queda guardada la versión vista (g7_user_prefs: whats_new_seen) y no vuelve hasta
 * la próxima versión con novedades. Se apaga para todo el sitio con g7_config whats_new = 0.
 * La arma topbar.php; "Novedades" en el menú de la persona la vuelve a abrir. Doc: toolz/NOVEDADES.md
 */

function g7_nov_all(): array {
    static $all = null;
    if ($all === null) { $all = is_file(__DIR__ . '/novedades_data.php') ? (array)(include __DIR__ . '/novedades_data.php') : []; uksort($all, fn($a, $b) => version_compare($b, $a)); }
    return $all;
}
function g7_nov_latest(): string { return (string)(array_key_first(g7_nov_all()) ?? ''); }

/** Diapositivas que le tocan a esta persona según su rol (las de admin y superadmin, solo a ellos). */
function g7_nov_for(array $rel, bool $admin, bool $super, int &$hidden = 0): array {
    $out = []; $hidden = 0;
    foreach ((array)($rel['slides'] ?? []) as $s) {
        $r = (string)($s['role'] ?? '');
        if (($r === 'admin' && !$admin) || ($r === 'super' && !$super)) continue;
        $link = (string)($s['link'] ?? '');
        // Una novedad de un módulo apagado en este sitio (p. ej. Blog o Social Studio en la Lite) no se anuncia
        $mod = (string)($s['module'] ?? '');
        if ($mod === '' && $link !== '' && function_exists('g7_module_for_href')) $mod = (string)g7_module_for_href($link);
        if ($mod !== '' && function_exists('g7_modules') && (g7_modules()[$mod]['mode'] ?? 'off') === 'off') { $hidden++; continue; }
        $out[] = ['icon' => preg_replace('/[^a-z0-9-]/', '', (string)($s['icon'] ?? 'fa-star')), 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['color'] ?? '')) ? $s['color'] : '#ff6600',
            'title' => (string)($s['title'] ?? ''), 'text' => (string)($s['text'] ?? ''), 'link' => str_starts_with($link, '/toolz/') ? $link : '',
            'chips' => array_slice(array_map(fn($c) => [preg_replace('/[^a-z0-9-]/', '', (string)($c[0] ?? 'fa-star')), (string)($c[1] ?? '')], (array)($s['chips'] ?? [])), 0, 3)];
    }
    return $out;
}

/**
 * Lo que la ventana tiene que mostrar a esta persona: las versiones con novedades más nuevas que la última que vio
 * (como mucho las últimas 3). Quien nunca vio ninguna ve solo la última. Vacío si no hay nada pendiente.
 */
function g7_nov_pending(?string $seen, bool $admin, bool $super): array {
    if (function_exists('g7_cfg') && g7_cfg('whats_new', '1') === '0') return [];
    $all = g7_nov_all(); if (!$all) return [];
    $latest = array_key_first($all);
    if ($seen !== null && $seen !== '' && version_compare($seen, $latest, '>=')) return [];
    $out = [];
    foreach ($all as $v => $rel) {
        if ($seen !== null && $seen !== '' && version_compare($v, $seen, '<=')) break;
        $hidden = 0; $slides = g7_nov_for($rel, $admin, $super, $hidden);
        // Si se ocultó alguna novedad de un módulo apagado, el título de la versión (que la nombra) se cambia por uno neutro
        $title = (string)($rel['title'] ?? '');
        if ($slides && $hidden) $title = 'Novedades de la versión ' . $v;
        if ($slides) $out[] = ['version' => $v, 'title' => $title, 'date' => (string)($rel['date'] ?? ''), 'slides' => $slides];
        if ($seen === null || $seen === '' || count($out) >= 3) break;
    }
    return $out;
}
