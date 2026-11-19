<?php
/**
 * site_lib.php — Lo que cambia de un sitio a otro. Un solo código; cada sitio tiene su _private/site.php, que ninguna versión pisa.
 * Sin ese archivo valen los valores de abajo, y antes los del paquete de marcas del sitio (marcas/marcas.php → 'site'), si lo tiene.
 * Doc: toolz/ACTUALIZACIONES.md y toolz/MARCAS.md
 */
/**
 * Paquete de marcas de este sitio: marcas/marcas.php devuelve un arreglo con las marcas, plataformas y textos propios
 * (logos, plantillas y listas de cada módulo). No viaja en ninguna versión ni instalador (U_PROTECTED); un sitio sin paquete
 * arranca sin marcas. $key null = todo el paquete.
 */
function g7_pack(?string $key = null, $default = null) {
    static $p = null;
    if ($p === null) {
        $f = __DIR__ . '/marcas/marcas.php';
        $p = is_file($f) ? (include $f) : [];
        if (!is_array($p)) $p = [];
    }
    return $key === null ? $p : ($p[$key] ?? $default);
}
/** URL relativa a la raíz de Toolz de un archivo del paquete de marcas, o '' si el sitio no lo tiene. */
function g7_pack_file(string $rel): string { return is_file(__DIR__ . '/marcas/' . $rel) ? 'marcas/' . $rel : ''; }
/** Saca de una página HTML los <script src="/toolz/marcas/..."> cuyo archivo este sitio no tiene (así no hay 404 sin paquete). */
function g7_pack_strip_missing(string $html): string {
    return (string)preg_replace_callback('#<script[^>]*src="/toolz/marcas/([^"?]+)[^"]*"[^>]*>\s*</script>\s*#i',
        fn($m) => g7_pack_file($m[1]) !== '' ? $m[0] : '', $html);
}
function g7_site(?string $key = null, $default = null) {
    static $s = null;
    if ($s === null) {
        $f = __DIR__ . '/_private/site.php';
        $own = is_file($f) ? (include $f) : [];
        $s = array_merge([
            'name'  => 'Toolz',                 // nombre completo: título del Inicio, correos
            'short' => 'Toolz',                 // nombre corto: títulos de las páginas
            'logo'  => '',
            'favicon' => '',
            'db_name' => '', 'db_user' => '', 'session_name' => 'g7_session',
            'funciones' => [],                  // 'clientes' (fichas y portada del Inicio), 'materiales' (Stock como carpetas por cliente), 'placas_ia'
            'modulos_off' => [],                // módulos apagados en este sitio
        ], (array)g7_pack('site', []), is_array($own) ? $own : []);
        // Solo pruebas locales: un servidor con auto_prepend_file puede sumar claves (dev/base_local/prepend.php). En los sitios no existe.
        if (defined('G7_SITE_EXTRA') && is_array(G7_SITE_EXTRA)) $s = array_merge($s, G7_SITE_EXTRA);
        // Módulos que no incluye el plan de la licencia: se apagan igual que los de modulos_off (license_lib.php)
        $lf = __DIR__ . '/_private/license.json';
        if (is_file($lf) && is_array($lic = json_decode((string)file_get_contents($lf), true)) && !empty($lic['plan']) && !empty($lic['modules_off']))
            $s['modulos_off'] = array_values(array_unique(array_merge((array)$s['modulos_off'], array_map('strval', (array)$lic['modules_off']))));
    }
    return $key === null ? $s : ($s[$key] ?? $default);
}
function g7_site_has(string $feature): bool { return in_array($feature, (array)g7_site('funciones'), true); }
/** Los módulos de este sitio: agrega los opcionales. Los de modulos_off los apaga g7_modules(). */
function g7_site_modules(array $mods): array {
    if (g7_site_has('clientes')) $mods['clientes'] = ['label' => 'Clientes', 'icon' => 'fa-address-book', 'group' => 'Gestión', 'paths' => ['clientes.php', 'clientes_api.php'], 'default_mode' => 'admins'];
    // Facturación usa las fichas de Clientes: sin Clientes no existe. edit = crear, emitir, cobrar y cancelar; config = plantillas y datos del emisor
    if (g7_site_has('clientes')) $mods['facturacion'] = ['label' => 'Facturación', 'icon' => 'fa-file-invoice-dollar', 'group' => 'Gestión', 'paths' => ['facturacion/'], 'actions' => ['view', 'edit', 'config'], 'default_mode' => 'admins'];
    // Finanzas y pagos (pasarela central): cobra facturas en línea. view = ver; edit = links y cancelar; approve = transferencias; config = medios de pago y claves
    if (g7_site_has('clientes')) $mods['pagos'] = ['label' => 'Finanzas y pagos', 'icon' => 'fa-wallet', 'group' => 'Gestión', 'paths' => ['pagos/admin/'], 'actions' => ['view', 'edit', 'approve', 'config'], 'grant_actions' => ['approve', 'config'], 'default_mode' => 'admins'];
    // Propiedades (rubro inmobiliaria o extra de la licencia): usa las fichas de Clientes como propietarios e interesados
    if (g7_site_has('clientes') && g7_prop_enabled()) $mods['propiedades'] = ['label' => 'Propiedades', 'icon' => 'fa-building', 'group' => 'Gestión', 'paths' => ['propiedades/'], 'actions' => ['view', 'edit', 'config'], 'default_mode' => 'everyone'];
    if (g7_site_has('clientes') && g7_ven_enabled()) $mods['vencimientos'] = ['label' => 'Vencimientos', 'icon' => 'fa-calendar-xmark', 'group' => 'Gestión', 'paths' => ['vencimientos/'], 'actions' => ['view', 'edit', 'config'], 'default_mode' => 'everyone'];
    if (g7_site_has('clientes') && g7_ap_enabled()) $mods['turnos'] = ['label' => 'Turnos', 'icon' => 'fa-calendar-check', 'group' => 'Gestión', 'paths' => ['turnos/'], 'actions' => ['view', 'edit', 'config'], 'default_mode' => 'everyone'];
    if (g7_site_has('clientes') && g7_tl_enabled()) $mods['talentos'] = ['label' => 'Talentos', 'icon' => 'fa-star', 'group' => 'Gestión', 'paths' => ['talentos/'], 'actions' => ['view', 'edit'], 'default_mode' => 'everyone'];
    if (g7_site_has('clientes') && g7_bd_enabled()) $mods['builds'] = ['label' => 'Builds y bugs', 'icon' => 'fa-bug', 'group' => 'Gestión', 'paths' => ['builds/'], 'actions' => ['view', 'edit'], 'default_mode' => 'everyone'];
    // Juegos (rubro videojuegos o extra): seguimiento del juego, marketing, Steam simulado y Mercado. Ideas y tareas van a Trabajo; bugs a Builds y bugs
    if (g7_jg_enabled()) $mods['juegos'] = ['label' => 'Juegos', 'icon' => 'fa-gamepad', 'group' => 'Gestión', 'paths' => ['juegos/'], 'actions' => ['view', 'edit', 'approve', 'config'], 'grant_actions' => ['approve', 'config'], 'default_mode' => 'everyone'];
    if (g7_site_has('placas_ia')) $mods['placas_ia'] = ['label' => 'Placas IA',          'icon' => 'fa-wand-magic-sparkles', 'group' => 'Diseño',    'paths' => ['placas-ia/'], 'default_mode' => 'admins'];
    // Afiliados de Cronos (afiliados/, doc AFILIADOS.md). El programa vive en el sitio conectado al base (el que cobra los planes):
    // ahí está el panel "Afiliados"; en cada agencia está "Recomienda Cronos" (recomendar/), que habla con el base.
    // Afiliados: view = ver; edit = afiliados, referidos y pedidos; pay = liquidar y pagar; config = ajustes y tasa
    $main = (string)g7_site('base_url', '') !== '' && strlen((string)g7_site('base_sso_secret', '')) >= 32;
    if ($main) $mods['afiliados'] = ['label' => 'Afiliados', 'icon' => 'fa-handshake', 'group' => 'Gestión', 'paths' => ['afiliados/index.php', 'afiliados/api.php'], 'actions' => ['view', 'edit', 'pay', 'config'], 'grant_actions' => ['pay', 'config'], 'default_mode' => 'superadmins'];
    // Recomienda Cronos: view = ver lo ganado; edit = recomendar agencias; config = descontar o cobrar y datos de cobro
    else $mods['recomendar'] = ['label' => 'Recomienda Cronos', 'icon' => 'fa-handshake', 'group' => 'Gestión', 'paths' => ['recomendar/'], 'actions' => ['view', 'edit', 'config'], 'grant_actions' => ['config'], 'default_mode' => 'admins'];
    if (g7_site_has('materiales') && isset($mods['stock'])) $mods['stock'] = ['label' => 'Materiales', 'icon' => 'fa-folder-open'] + $mods['stock'];
    return $mods;
}

/** Módulos extra de un rubro (propiedades, vencimientos…): si la licencia trae extras, manda la licencia; si no, el rubro que lo trae o el interruptor <extra>_on. */
function g7_extra_enabled(string $extra, string $rubro, string $flag): bool {
    $lf = __DIR__ . '/_private/license.json';
    if (is_file($lf) && is_array($l = json_decode((string)file_get_contents($lf), true)) && !empty($l['plan']) && array_key_exists('extras', $l))
        return in_array($extra, (array)$l['extras'], true);
    if (!function_exists('g7_cfg')) return false;
    return g7_cfg('rubro', '') === $rubro || g7_cfg($flag, '') === '1';
}
function g7_prop_enabled(): bool { return g7_extra_enabled('propiedades', 'inmobiliaria', 'prop_on'); }
function g7_ven_enabled(): bool { return g7_extra_enabled('vencimientos', 'estudio', 'ven_on'); }
function g7_ap_enabled(): bool { return g7_extra_enabled('turnos', 'salud', 'ap_on'); }
function g7_tl_enabled(): bool { return g7_extra_enabled('talentos', 'influencers', 'tl_on'); }
function g7_bd_enabled(): bool { return g7_extra_enabled('builds', 'videojuegos', 'bd_on'); }
function g7_jg_enabled(): bool { return g7_extra_enabled('juegos', 'videojuegos', 'jg_on'); }   // Juegos: el Game Hub (juegos/)

/**
 * Origen público del sitio (https://dominio) para armar enlaces que salen por correo o a terceros.
 * Sale de _private/site.php ('url'), no de la cabecera Host: así nadie puede mandar a un cliente un enlace
 * de recuperación que apunte a otro dominio. Si el pedido llega por ese mismo dominio se respeta su esquema;
 * sin 'url' configurada (copias de prueba) se usa el Host del pedido, saneado.
 */
function g7_site_origin(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $reqHost = strtolower(preg_replace('/[^a-z0-9.:\-\[\]]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $cfg = (string)g7_site('url', '');
    if ($cfg !== '' && ($p = parse_url($cfg)) && !empty($p['host'])) {
        $host = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        $scheme = strtolower($p['scheme'] ?? 'https');
        if ($reqHost === $host) $scheme = $https ? 'https' : $scheme;
        return $scheme . '://' . $host;
    }
    return ($https ? 'https' : 'http') . '://' . ($reqHost !== '' ? $reqHost : 'localhost');
}
/** Dominio para el remitente de los correos (no-reply@dominio), sin www ni puerto. Sale de g7_site_origin(), no del Host. */
function g7_site_mail_host(): string {
    $h = (string)parse_url(g7_site_origin(), PHP_URL_HOST);
    return preg_replace('/^www\./', '', $h !== '' ? $h : 'localhost');
}
