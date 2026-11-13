<?php
/**
 * csp_report.php — Recibe los avisos de la CSP en modo "solo informar" (ver csp_lib.php). Público a propósito: los manda el navegador.
 * Solo guarda mientras la medición está prendida, con tope de tamaño por pedido y de grupos en total. No usa la base de datos.
 */
require_once __DIR__ . '/csp_lib.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !g7_csp_on()) { http_response_code(204); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) { http_response_code(413); exit; }
$raw = (string)file_get_contents('php://input', false, null, 0, 16384);
$d = json_decode($raw, true);
$list = [];
if (isset($d['csp-report']) && is_array($d['csp-report'])) $list[] = $d['csp-report'];   // report-uri (formato clásico)
elseif (is_array($d)) foreach ($d as $x) if (is_array($x) && ($x['type'] ?? '') === 'csp-violation' && is_array($x['body'] ?? null)) {   // Reporting API
    $b = $x['body']; $list[] = ['effective-directive' => $b['effectiveDirective'] ?? '', 'blocked-uri' => $b['blockedURL'] ?? '', 'document-uri' => $b['documentURL'] ?? '',
        'source-file' => $b['sourceFile'] ?? '', 'line-number' => $b['lineNumber'] ?? 0, 'script-sample' => $b['sample'] ?? ''];
}
if ($list) g7_csp_add(array_slice($list, 0, 20));
http_response_code(204);
