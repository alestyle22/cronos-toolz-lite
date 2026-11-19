<?php
/** theme_list.php — Lista única de temas (g7-theme.css). La usan Mi configuración, el onboarding y el "Tema por defecto" del Admin.
 *  Para sumar un tema: sus tokens en g7-theme.css, una fila acá y, si es claro, su clave en G7_THEMES_LIGHT
 *  (y en las listas html:is(...) de los CSS que dicen "claro"). Claves solo con letras: theme-boot.js usa theme-[a-z]+. */
const G7_THEMES_LIGHT = ['light','sand','sky','lilac','mint','sakura','lemon','cloud','desktop','classic','vintage','mica','aqua','studio','material','mobile','glassfrost','glasspeach','glassmint','glasssky','glasslilac','glasslemon','glassblush','glasspearl','coral','lagoon','peach','fog','lime','latte','indigo','pocket','platinum'];
const G7_THEMES_GLASS = ['glass','glassocean','glasssunset','glassforest','glassrose','glassnight','glassgold','glassfrost','glasspeach','glassmint','glassberry','glassice','glasslava','glasscandy','glassaurora','glassmono','glasssky','glasslilac','glasslemon','glassblush','glasspearl'];
const G7_THEMES_SPECIAL = ['desktop','classic','vintage','mica','aero','aqua','studio','gamer','oldgamer','material','mobile','console','pocket','commodore','metro','aubergine','platinum','player','materialdark','mobiledark','studiodark','arcade'];

/** clave => [label, bg, card, accent] (bg/card/accent: muestra en el selector) */
function g7_themes(): array {
    return [
        'dark'     =>['label'=>'Oscuro',     'bg'=>'#0a0a0a','card'=>'#18181b','accent'=>'#ff6600'],
        'midnight' =>['label'=>'Medianoche', 'bg'=>'#060810','card'=>'#141a2a','accent'=>'#60a5fa'],
        'violet'   =>['label'=>'Violeta',    'bg'=>'#08050f','card'=>'#1a1326','accent'=>'#a855f7'],
        'green'    =>['label'=>'Verde',      'bg'=>'#040d08','card'=>'#0f2016','accent'=>'#22c55e'],
        'rose'     =>['label'=>'Rosa',       'bg'=>'#0f050a','card'=>'#24101a','accent'=>'#f43f5e'],
        'ocean'    =>['label'=>'Océano', 'bg'=>'#04100f','card'=>'#0f2624','accent'=>'#14b8a6'],
        'sunset'   =>['label'=>'Atardecer', 'bg'=>'#120a0c','card'=>'#28181d','accent'=>'#f59e0b'],
        'graphite' =>['label'=>'Grafito', 'bg'=>'#151517','card'=>'#26262b','accent'=>'#a3e635'],
        'graphitecoral' =>['label'=>'Grafito coral', 'bg'=>'#151517','card'=>'#26262b','accent'=>'#fb7185'],
        'graphiteviolet' =>['label'=>'Grafito violeta', 'bg'=>'#151517','card'=>'#26262b','accent'=>'#a78bfa'],
        'graphiteamber' =>['label'=>'Grafito ámbar', 'bg'=>'#151517','card'=>'#26262b','accent'=>'#fbbf24'],
        'slate' =>['label'=>'Pizarra', 'bg'=>'#13161b','card'=>'#232831','accent'=>'#38bdf8'],
        'stone' =>['label'=>'Piedra', 'bg'=>'#171514','card'=>'#292523','accent'=>'#fb923c'],
        'coffee'   =>['label'=>'Café', 'bg'=>'#100c09','card'=>'#221b16','accent'=>'#d6a26b'],
        'neon'     =>['label'=>'Neón', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#e879f9'],
        'neoncyan' =>['label'=>'Neón cian', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#22d3ee'],
        'neongreen' =>['label'=>'Neón verde', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#39ff88'],
        'neonorange' =>['label'=>'Neón naranja', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#ff8a3d'],
        'neonblue' =>['label'=>'Neón azul', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#4d7cff'],
        'neonred' =>['label'=>'Neón rojo', 'bg'=>'#07060d','card'=>'#161328','accent'=>'#ff3366'],
        'glass' =>['label'=>'Vidrio violeta', 'bg'=>'#0d0b24','card'=>'rgb(139,92,246)','accent'=>'#a78bfa'],
        'gamer'    =>['label'=>'Gamer', 'bg'=>'#171a21','card'=>'#2a3f55','accent'=>'#66c0f4'],
        'aurora'   =>['label'=>'Aurora', 'bg'=>'#06071a','card'=>'#141838','accent'=>'#34d399'],
        'volcano'  =>['label'=>'Volcán', 'bg'=>'#100909','card'=>'#251715','accent'=>'#f97316'],
        'royal'    =>['label'=>'Real', 'bg'=>'#0b0816','card'=>'#1c1632','accent'=>'#eab308'],
        'arctic'   =>['label'=>'Ártico', 'bg'=>'#0c1116','card'=>'#1b2530','accent'=>'#7dd3fc'],
        'sand'     =>['label'=>'Arena', 'bg'=>'#f3ece2','card'=>'#fffdf9','accent'=>'#c2410c'],
        'sky'      =>['label'=>'Cielo', 'bg'=>'#eef2f8','card'=>'#ffffff','accent'=>'#2563eb'],
        'lilac'    =>['label'=>'Lavanda', 'bg'=>'#f3f0fa','card'=>'#ffffff','accent'=>'#7c3aed'],
        'mint'     =>['label'=>'Menta', 'bg'=>'#edf5f1','card'=>'#ffffff','accent'=>'#059669'],
        'terminal' =>['label'=>'Terminal', 'bg'=>'#000000','card'=>'#0e140e','accent'=>'#4ade80'],
        'retro'    =>['label'=>'Retro', 'bg'=>'#0d0718','card'=>'#211337','accent'=>'#f472b6'],
        'forest'   =>['label'=>'Bosque', 'bg'=>'#07100b','card'=>'#15271b','accent'=>'#f59e0b'],
        'carbon'   =>['label'=>'Carbón', 'bg'=>'#000000','card'=>'#141414','accent'=>'#f4f4f5'],
        'console'  =>['label'=>'Consola', 'bg'=>'#0b0700','card'=>'#1c1305','accent'=>'#ffb000'],
        'sakura'   =>['label'=>'Sakura', 'bg'=>'#fbf1f4','card'=>'#ffffff','accent'=>'#db2777'],
        'lemon'    =>['label'=>'Limón', 'bg'=>'#f8f5e6','card'=>'#fffef8','accent'=>'#a16207'],
        'cloud'    =>['label'=>'Nube', 'bg'=>'#f1f2f4','card'=>'#ffffff','accent'=>'#0ea5e9'],
        'desktop'  =>['label'=>'Escritorio 2000', 'bg'=>'#d6e3f8','card'=>'#ece9d8','accent'=>'#316ac5'],
        'classic'  =>['label'=>'Clásico 90', 'bg'=>'#008080','card'=>'#c0c0c0','accent'=>'#000080'],
        'glassocean' =>['label'=>'Vidrio océano', 'bg'=>'#04111c','card'=>'rgb(6,182,212)','accent'=>'#22d3ee'],
        'glasssunset' =>['label'=>'Vidrio atardecer', 'bg'=>'#1a0b14','card'=>'rgb(249,115,22)','accent'=>'#fb923c'],
        'glassforest' =>['label'=>'Vidrio bosque', 'bg'=>'#04140d','card'=>'rgb(34,197,94)','accent'=>'#4ade80'],
        'glassrose' =>['label'=>'Vidrio rosa', 'bg'=>'#1a0614','card'=>'rgb(236,72,153)','accent'=>'#f472b6'],
        'glassnight' =>['label'=>'Vidrio noche', 'bg'=>'#050816','card'=>'rgb(29,78,216)','accent'=>'#60a5fa'],
        'glassgold' =>['label'=>'Vidrio dorado', 'bg'=>'#140d03','card'=>'rgb(245,158,11)','accent'=>'#fbbf24'],
        'glassfrost' =>['label'=>'Vidrio escarcha', 'bg'=>'#eef0fb','card'=>'rgb(165,180,252)','accent'=>'#6366f1'],
        'glasspeach' =>['label'=>'Vidrio durazno', 'bg'=>'#fdf2ea','card'=>'rgb(253,186,116)','accent'=>'#ea580c'],
        'glassmint' =>['label'=>'Vidrio menta', 'bg'=>'#ecfbf4','card'=>'rgb(110,231,183)','accent'=>'#059669'],
        'vintage' =>['label'=>'Ventana 92', 'bg'=>'#a8a8a8','card'=>'#ffffff','accent'=>'#000080'],
        'mica' =>['label'=>'Mica', 'bg'=>'#eef1f6','card'=>'#ffffff','accent'=>'#0067c0'],
        'aero' =>['label'=>'Aero', 'bg'=>'#02060c','card'=>'#183a52','accent'=>'#4cc2ff'],
        'aqua' =>['label'=>'Aqua', 'bg'=>'#e8e8e8','card'=>'#ffffff','accent'=>'#3875d7'],
        'studio' =>['label'=>'Estudio', 'bg'=>'#ececec','card'=>'#ffffff','accent'=>'#007aff'],
        'oldgamer' =>['label'=>'Gamer 2004', 'bg'=>'#3e4637','card'=>'#5a6a50','accent'=>'#c4b550'],
        'material' =>['label'=>'Material', 'bg'=>'#fef7ff','card'=>'#f3edf7','accent'=>'#6750a4'],
        'mobile' =>['label'=>'Móvil', 'bg'=>'#f2f2f7','card'=>'#ffffff','accent'=>'#007aff'],
        'light'    =>['label'=>'Claro',      'bg'=>'#f4f2ee','card'=>'#ffffff','accent'=>'#e26212'],
        'glassberry' =>['label'=>'Vidrio mora', 'bg'=>'#14051a','card'=>'rgb(192,38,211)','accent'=>'#e879f9'],
        'glassice' =>['label'=>'Vidrio hielo', 'bg'=>'#03101f','card'=>'rgb(14,165,233)','accent'=>'#a5f3fc'],
        'glasslava' =>['label'=>'Vidrio lava', 'bg'=>'#170505','card'=>'rgb(220,38,38)','accent'=>'#f87171'],
        'glasscandy' =>['label'=>'Vidrio caramelo', 'bg'=>'#100a1f','card'=>'rgb(244,114,182)','accent'=>'#f472b6'],
        'glassaurora' =>['label'=>'Vidrio aurora', 'bg'=>'#040b16','card'=>'rgb(16,185,129)','accent'=>'#34d399'],
        'glassmono' =>['label'=>'Vidrio grafito', 'bg'=>'#0b0b0d','card'=>'rgb(113,113,122)','accent'=>'#e4e4e7'],
        'glasssky' =>['label'=>'Vidrio cielo', 'bg'=>'#eef4ff','card'=>'rgb(147,197,253)','accent'=>'#2563eb'],
        'glasslilac' =>['label'=>'Vidrio lila', 'bg'=>'#f5f0ff','card'=>'rgb(196,181,253)','accent'=>'#7c3aed'],
        'glasslemon' =>['label'=>'Vidrio limón', 'bg'=>'#fffbe8','card'=>'rgb(253,230,138)','accent'=>'#a16207'],
        'glassblush' =>['label'=>'Vidrio rubor', 'bg'=>'#fff1f5','card'=>'rgb(251,207,232)','accent'=>'#db2777'],
        'glasspearl' =>['label'=>'Vidrio perla', 'bg'=>'#f4f4f5','card'=>'rgb(228,228,231)','accent'=>'#52525b'],
        'coral' =>['label'=>'Coral', 'bg'=>'#fbf0ed','card'=>'#ffffff','accent'=>'#ea5848'],
        'lagoon' =>['label'=>'Laguna', 'bg'=>'#ebf6f5','card'=>'#ffffff','accent'=>'#0d9488'],
        'peach' =>['label'=>'Durazno', 'bg'=>'#fdf1e8','card'=>'#ffffff','accent'=>'#ea580c'],
        'fog' =>['label'=>'Neblina', 'bg'=>'#eef0f3','card'=>'#ffffff','accent'=>'#475569'],
        'lime' =>['label'=>'Lima', 'bg'=>'#f3f7e8','card'=>'#ffffff','accent'=>'#4d7c0f'],
        'latte' =>['label'=>'Latte', 'bg'=>'#f5efe8','card'=>'#ffffff','accent'=>'#92400e'],
        'indigo' =>['label'=>'Índigo', 'bg'=>'#f0f0fb','card'=>'#ffffff','accent'=>'#4f46e5'],
        'pocket' =>['label'=>'Consola portátil', 'bg'=>'#8bac0f','card'=>'#9bbc0f','accent'=>'#306230'],
        'commodore' =>['label'=>'8 bits', 'bg'=>'#352879','card'=>'#40318d','accent'=>'#a0e0ff'],
        'metro' =>['label'=>'Metro', 'bg'=>'#000000','card'=>'#00aba9','accent'=>'#00aba9'],
        'aubergine' =>['label'=>'Berenjena', 'bg'=>'#2c001e','card'=>'#4e1240','accent'=>'#e95420'],
        'platinum' =>['label'=>'Platino', 'bg'=>'#cccccc','card'=>'#eeeeee','accent'=>'#6666cc'],
        'player' =>['label'=>'Reproductor', 'bg'=>'#1b1b25','card'=>'#3b3b52','accent'=>'#00e000'],
        'materialdark' =>['label'=>'Material oscuro', 'bg'=>'#141218','card'=>'#2b2930','accent'=>'#d0bcff'],
        'mobiledark' =>['label'=>'Móvil oscuro', 'bg'=>'#000000','card'=>'#1c1c1e','accent'=>'#0a84ff'],
        'studiodark' =>['label'=>'Estudio oscuro', 'bg'=>'#1e1e1e','card'=>'#2a2a2a','accent'=>'#0a84ff'],
        'arcade' =>['label'=>'Arcade', 'bg'=>'#05030a','card'=>'#1a1233','accent'=>'#ffe600'],
    ];
}
function g7_theme_group(string $k): string {
    if (in_array($k, G7_THEMES_GLASS, true)) return 'Vidrio';
    if (in_array($k, G7_THEMES_SPECIAL, true)) return 'Especiales';
    return in_array($k, G7_THEMES_LIGHT, true) ? 'Claros' : 'Oscuros';
}
/** ["Oscuros" => [clave => tema], "Claros" => …, "Vidrio" => …, "Especiales" => …] */
function g7_theme_groups(): array {
    $g = ['Oscuros' => [], 'Claros' => [], 'Vidrio' => [], 'Especiales' => []];
    foreach (g7_themes() as $k => $t) $g[g7_theme_group($k)][$k] = $t;
    return $g;
}
function g7_theme_is_light(string $k): bool { return in_array($k, G7_THEMES_LIGHT, true); }
function g7_theme_valid(string $k): bool { return isset(g7_themes()[$k]); }
