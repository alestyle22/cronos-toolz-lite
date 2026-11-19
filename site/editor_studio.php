<?php
/**
 * editor_studio.php — Capa "estudio" de los editores visuales: constructor del portal, Landing Gen 2.0 y Mailing Maker 2.0
 * (editor y lista de cada uno), Profile Studio (Social Studio › Perfiles: lista y editor en una sola página) y Paletas.
 * Esas pantallas no cargan topbar.php, así que acá se suman el color de acento de la persona,
 * el motor de movimiento y sonido (g7-juice) con sus preferencias y la piel assets/editor-studio.css/.js.
 * Uso, dentro del <head> y después de editor.css: <?php g7_editor_studio_head($db, $me, 'portal'|'landing'|'mail'); ?>
 * Doc: toolz/MOVIMIENTO.md › Editores visuales
 */
function g7_editor_studio_head(PDO $db, array $me, string $tool): void {
    static $done = false; if ($done) return; $done = true;
    $tool = in_array($tool, ['portal', 'landing', 'mail', 'profile', 'palette', 'mockup', 'blog', 'web', 'font', 'logo', 'icon', 'thumb'], true) ? $tool : 'portal';
    $p = [];
    try {
        $q = $db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('accent_color','dashboard_animations','ui_sounds','motion_force')");
        $q->execute([(int)($me['id'] ?? 0)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $p[$r['pref_key']] = json_decode($r['pref_value'], true);
    } catch (Throwable $e) { $p = []; }
    $acc = $p['accent_color'] ?? (function_exists('g7_setting') ? g7_setting('accent_color') : '') ;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$acc)) $acc = '#ff6600';
    $rgb = hexdec(substr($acc, 1, 2)) . ',' . hexdec(substr($acc, 3, 2)) . ',' . hexdec(substr($acc, 5, 2));
    $motion = (($p['dashboard_animations'] ?? true) === false) ? '0' : '1';
    $force = !empty($p['motion_force']) ? '1' : '0';
    $sound = !empty($p['ui_sounds']) ? '1' : '0';
    $dir = __DIR__ . '/assets/';
    $v = fn(string $f) => (int)@filemtime($dir . $f);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    echo '<script src="/toolz/assets/theme-boot.js?v=1" data-accent="' . $e($acc) . '" data-rgb="' . $e($rgb) . '"></script>' . "\n";
    if (!defined('G7_JUICE')) {
        define('G7_JUICE', 1);
        echo '<link rel="stylesheet" href="/toolz/assets/g7-juice.css?v=' . $v('g7-juice.css') . '">' . "\n";
        echo '<script src="/toolz/assets/g7-juice.js?v=' . $v('g7-juice.js') . '" data-motion="' . $motion . '" data-force="' . $force . '" data-sound="' . $sound . '" defer></script>' . "\n";
    }
    echo '<link rel="stylesheet" href="/toolz/assets/editor-studio.css?v=' . $v('editor-studio.css') . '">' . "\n";
    echo '<script src="/toolz/assets/editor-studio.js?v=' . $v('editor-studio.js') . '" data-tool="' . $tool . '" defer></script>' . "\n";
}

/**
 * El menú de Cronos (topbar.php) en estas pantallas. Va justo después de <body>.
 * La barra de Cronos se ve arriba, como en cualquier otra sección; en los editores el área de trabajo ocupa el resto del
 * alto (editor-studio.css › "Menú de Cronos").
 */
function g7_editor_studio_menu(PDO $db, array $me): void {
    static $done = false; if ($done) return; $done = true;
    $currentUser = $me;   // lo que topbar.php espera, además de $db
    $er = error_reporting();
    ob_start();
    try { include __DIR__ . '/topbar.php'; } catch (Throwable $e) { /* sin menú antes que sin editor */ }
    // topbar.php empieza con BOM: impreso en el <body> es una línea de texto que corre el editor 20 px hacia abajo
    echo preg_replace('/^(\s*)\xEF\xBB\xBF/', '$1', (string)ob_get_clean());
    error_reporting($er);   // topbar.php apaga los avisos
}
