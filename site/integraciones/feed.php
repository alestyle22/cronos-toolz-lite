<?php
/**
 * integraciones/feed.php — Feed público de propiedades disponibles para portales y agregadores.
 *   feed.php?t=TOKEN          XML (formato Trovit)
 *   feed.php?t=TOKEN&f=json   JSON
 * El token se ve y se regenera en Admin › Integraciones › Portales inmobiliarios. Doc: toolz/INTEGRACIONES.md
 */
require_once __DIR__ . '/portales.php';
header('X-Robots-Tag: noindex'); header('Cache-Control: public, max-age=900');
$t = (string)($_GET['t'] ?? '');
if (!int_ready('portales') || $t === '' || !hash_equals(pt_feed_token(), $t)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Feed no disponible.'; exit; }
try {
    $items = pt_listings(getDB());
    if (int_every('pt_feed_log_at', 3600)) int_log('portales', 'feed', true, 'Feed leído (' . count($items) . ' propiedades) desde ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'sin agente'), 0, 80));
    if (($_GET['f'] ?? '') === 'json') { header('Content-Type: application/json; charset=utf-8'); echo pt_feed_json($items); }
    else { header('Content-Type: application/xml; charset=utf-8'); echo pt_feed_xml($items); }
} catch (Throwable $e) {
    error_log('[portales] ' . $e->getMessage()); int_log('portales', 'feed', false, 'El feed falló: ' . $e->getMessage());
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'Error al armar el feed.';
}
