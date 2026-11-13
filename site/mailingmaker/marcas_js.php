<?php
/**
 * mailingmaker/marcas_js.php — Entrega como JavaScript los datos de marcas del Mailing Maker (window.MM_PACK),
 * que viven en el paquete de marcas del sitio (marcas/mailingmaker/plataformas.js; doc: toolz/MARCAS.md).
 * Un sitio sin paquete recibe un JS vacío (y no un 404 en la consola): los generadores usan su plataforma de ejemplo neutra.
 * La carpeta marcas/ no ejecuta PHP (marcas/.htaccess), por eso el cargador está acá.
 */
require_once __DIR__ . '/../site_lib.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
$f = g7_pack_file('mailingmaker/plataformas.js');
if ($f !== '') readfile(__DIR__ . '/../' . $f);
else echo "/* Mailing Maker: este sitio no tiene paquete de marcas; se usa la plataforma de ejemplo. */\n";
