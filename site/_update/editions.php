<?php
/**
 * _update/editions.php â€” Ediciones de Toolz (por ahora: completa y Â«liteÂ»).
 * Una ediciÃ³n deja afuera mÃ³dulos enteros: sus archivos no vienen en el instalador y el actualizador no los instala.
 * El sitio dice su ediciÃ³n en _private/site.php ('edition' => 'lite'); sin esa clave es la ediciÃ³n completa.
 * Viaja en cada versiÃ³n (firmada), asÃ­ un sitio lite sabe quÃ© no instalar aunque el paquete sea el completo.
 * Doc: toolz/LITE.md
 */

function u_editions(): array {
    return [
        // Cronos Toolz Lite: Inicio, Soporte, Perfil, Trabajo/Tareas, Clientes (sin portal ni facturas), Chat, Contenidos + Materiales,
        // Landing Gen, Mailing Maker, EstadÃ­sticas, Jira, convertidores WebP/JPG/SVG, Comunidad, Mi calendario y To-Do.
        'lite' => [
            'id'   => 'lite',
            'name' => 'Cronos Toolz Lite',
            'plan' => 'lite',   // plan de licencia que le corresponde en el sitio base
            // Apagados por el sitio (modulos_off): ni los admins los prenden. Los que ademÃ¡s no traen archivos estÃ¡n en 'exclude'
            'modules_off' => ['popups', 'pages', 'planify', 'placas_diarias', 'placas_paid', 'promos_gen', 'placas', 'imghost', 'recolorear', 'renombrador',
                'claude', 'linkgen', 'encuesta', 'linkstats', 'status', 'tienda', 'monday', 'facturacion', 'pagos', 'propiedades', 'vencimientos',
                'turnos', 'talentos', 'builds', 'juegos', 'placas_ia'],
            // Archivos o carpetas (terminan en /) que no se instalan. Ninguno puede ser cargado por un archivo que sÃ­ viaja (lo controla release.php)
            'exclude' => ['placasdeportivas/', 'placaspaid/', 'placas-ia/', 'sport_placas.html', 'sport_placas.php', 'popup-gen/', 'pages.php', 'planify.php',
                'imghost.php', 'imghost_album.php', 'recolorearsvg.html', 'recolorearsvg.php', 'renombrador.html', 'renombrador.php', 'claude/',
                'linkgen/', 'linkgenerator.html', 'linkgenerator.php', 'encuesta/', 'linkstats/', 'r/', 'status.php',
                'tienda/', 'monday.php', 'monday_widget.php', 'facturacion/', 'pagos/', 'pagar/', 'portal/', 'admin_portal.php', 'admin_portal.js',
                'portal_cfg_api.php', 'portal_cfg_lib.php', 'propiedades/', 'vencimientos/', 'turnos/', 'talentos/', 'builds/', 'juegos/', 'tasking/'],   // Biblioteca (links/ y sus guías guias/) está en la Lite desde la 1.0.54
            // Excepciones dentro de lo excluido: archivos sueltos que sÃ­ viajan porque los usa algo de la ediciÃ³n
            'keep' => ['juegos/reportes_lib.php'],   // Trabajo â€º Tickets lee los reportes que llegan de un juego
        ],
    ];
}

function u_edition_def(string $id): ?array { return u_editions()[$id] ?? null; }

/** EdiciÃ³n de este sitio ('' = completa). */
function u_site_edition(): string {
    $e = function_exists('u_cfg') ? (string)(u_cfg()['edition'] ?? '') : '';
    return u_edition_def($e) ? $e : '';
}

function u_edition_excluded(?array $def, string $path): bool {
    if (!$def || in_array($path, (array)($def['keep'] ?? []), true)) return false;
    foreach ($def['exclude'] as $x) if ($path === $x || (substr($x, -1) === '/' && strncmp($path, $x, strlen($x)) === 0)) return true;
    return false;
}

/** Normaliza a/b/../c â†’ a/c. */
function u_edition_norm(string $p): string {
    $o = [];
    foreach (explode('/', str_replace('\\', '/', $p)) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { array_pop($o); continue; }
        $o[] = $s;
    }
    return implode('/', $o);
}

/** Rutas (relativas a la raÃ­z de Toolz) que carga un archivo PHP con require/include. */
function u_edition_requires(string $rel, string $code): array {
    $dir = dirname($rel); $dir = $dir === '.' ? '' : $dir;
    $out = [];
    $re = '/\b(?:require|include)(?:_once)?\s*\(?\s*(__DIR__|dirname\(__DIR__\)|dirname\(__DIR__,\s*(\d)\)|G7P_TOOLZ|I_ROOT|RED_ROOT|U_ROOT)\s*\.\s*([\'"])([^\'"]+)\3/';
    if (!preg_match_all($re, $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
    foreach ($m as $x) {
        $base = $x[1][0];
        if ($base === '__DIR__') $b = $dir;
        elseif ($base === 'dirname(__DIR__)') $b = $dir . '/..';
        elseif (strpos($base, 'dirname(__DIR__,') === 0) $b = $dir . str_repeat('/..', (int)$x[2][0]);
        else $b = '';   // constantes que valen la raÃ­z de Toolz
        $line = substr_count(substr($code, 0, $x[0][1]), "\n") + 1;
        // Carga protegida: la condiciÃ³n de las lÃ­neas de antes (o de la misma) pregunta is_file por ese mismo archivo
        $before = substr($code, max(0, $x[0][1] - 600), 600 + strlen($x[0][0]));
        $guard = (bool)preg_match('/(is_file|file_exists)\s*\([^;]*' . preg_quote($x[4][0], '/') . '/', $before);
        $out[] = [u_edition_norm($b . '/' . $x[4][0]), $line, $guard];
    }
    return $out;
}

/** "archivo:lÃ­nea â†’ carga" de cada archivo que viaja y carga uno que la ediciÃ³n deja afuera (o que no existe). */
function u_edition_broken_requires(array $def, array $files): array {
    $o = [];
    foreach ($files as $rel => $code) {
        if (u_edition_excluded($def, $rel) || substr($rel, -4) !== '.php') continue;
        foreach (u_edition_requires($rel, $code) as [$req, $line, $guard]) {
            if ($guard || !u_edition_excluded($def, $req)) continue;
            $o[] = "$rel:$line â†’ $req";
        }
    }
    return $o;
}

/** Otras menciones de rutas excluidas (links del menÃº, fetch, etc.): no rompen, pero conviene mirarlas. */
function u_edition_refs(array $def, array $files): array {
    $o = [];
    $dirs = array_filter($def['exclude'], fn($x) => substr($x, -1) === '/');
    foreach ($files as $rel => $code) {
        if (u_edition_excluded($def, $rel) || !preg_match('/\.(php|js)$/', $rel)) continue;
        foreach (explode("\n", $code) as $n => $l) foreach ($dirs as $d) if (strpos($l, $d) !== false && !preg_match('/\b(require|include)/', $l)) { $o[] = "$rel:" . ($n + 1) . " ($d)"; break; }
    }
    return $o;
}
