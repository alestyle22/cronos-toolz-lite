<?php
/**
 * _update/feed.php — Sitio central: entrega el catálogo y los paquetes a los otros sitios.
 * Solo a sitios cargados en _private/site.php ('sites'). Los paquetes viven en _private/releases/, fuera de la web.
 */
require __DIR__ . '/lib.php';
[$site, $in] = u_request(fn($s) => u_cfg()['sites'][$s]['secret'] ?? null);
if (!u_is_central()) { http_response_code(404); exit; }
if (($in['get'] ?? '') === 'catalog') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['catalog' => file_get_contents(U_RELEASES . '/catalog.json'), 'sig' => trim((string)file_get_contents(U_RELEASES . '/catalog.sig'))]);
    exit;
}
if (($in['get'] ?? '') === 'zip' && u_version_ok((string)($in['version'] ?? '')) && is_file($f = U_RELEASES . '/' . $in['version'] . '.zip')) {
    header('Content-Type: application/zip'); header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}
http_response_code(404);
