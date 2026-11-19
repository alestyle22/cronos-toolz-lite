<?php
/**
 * taller/serve.php — Sirve una herramienta del Taller (Globo, Etiqueta, Calco, Rótulo) dentro de Cronos: pide sesión,
 * respeta el permiso del módulo (auth.php › g7_enforce_access), anota el acceso para "Sigue donde dejaste" y le pasa
 * al sidebar (taller/nav.js) las herramientas del estudio que esta persona puede abrir. Las herramientas son HTML de un
 * solo archivo (taller/<id>/index.html) y no se tocan. Doc: toolz/MOVIMIENTO.md › Estudio
 */
function g7_taller_serve(string $id, string $name): void {
    require_once __DIR__ . '/../auth.php';
    $user = requireLogin();
    require_once __DIR__ . '/../studio_lib.php';
    $file = __DIR__ . '/' . $id . '/index.html';
    $html = is_file($file) ? file_get_contents($file) : false;
    if ($html === false) { http_response_code(404); echo 'Herramienta no encontrada'; exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        try { getDB()->prepare('INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name) VALUES (?,?,?)')->execute([$user['id'], '/toolz/taller/' . $id . '.php', $name]); } catch (Throwable $e) {}
    }
    $data = ['here' => $id, 'home' => '/toolz/', 'tools' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'desc' => $t['desc'], 'url' => $t['url'], 'icon' => $t['lu'], 'grad' => $t['grad']], g7_studio_tools((int)$user['id']))];
    $tag = '<script type="application/json" id="g7-studio-data">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    // la lista va antes de nav.js (que la lee al cargar)
    $html = preg_replace_callback('#<script src="\.\./taller/nav\.js"></script>#', fn() => $tag . '<script src="/toolz/taller/nav.js?v=' . (int)@filemtime(__DIR__ . '/nav.js') . '"></script>', $html, 1);
    // Skin de Cronos: el tema y el acento de la persona (como topbar.php), con taller/skin.css mapeando las variables
    $prefs = [];
    try { $q = getDB()->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','accent_color')"); $q->execute([(int)$user['id']]);
          foreach ($q->fetchAll() as $r) $prefs[$r['pref_key']] = json_decode($r['pref_value'], true); } catch (Throwable $e) {}
    require_once __DIR__ . '/../theme_list.php';
    $theme = (string)($prefs['theme'] ?? (function_exists('g7_setting') ? (g7_setting('default_theme') ?: 'dark') : 'dark'));
    if (!g7_theme_valid($theme)) $theme = 'dark';
    $acc = (string)($prefs['accent_color'] ?? (g7_themes()[$theme]['accent'] ?? '#ff6600'));
    if (!preg_match('/^#[0-9a-f]{6}$/i', $acc)) $acc = '#ff6600';
    $rgb = hexdec(substr($acc, 1, 2)) . ',' . hexdec(substr($acc, 3, 2)) . ',' . hexdec(substr($acc, 5, 2));
    $cls = 'theme-' . $theme . ' g7-skin' . (g7_theme_is_light($theme) ? ' g7-skin-light' : '');
    $html = preg_replace('/<html\b([^>]*)>/i', '<html$1 class="' . $cls . '" style="--g7-accent:' . $acc . ';--g7-accent-rgb:' . $rgb . '">', $html, 1);
    $v = fn($f) => (int)@filemtime(__DIR__ . '/../' . $f);
    $html = preg_replace('/<head>/i', '<head><link rel="stylesheet" href="/toolz/g7-theme.css?v=' . $v('g7-theme.css') . '">', $html, 1);
    $html = preg_replace('#</head>#i', '<link rel="stylesheet" href="/toolz/taller/skin.css?v=' . $v('taller/skin.css') . '"></head>', $html, 1);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
