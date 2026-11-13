<?php
/**
 * landing-gen/frame.php — Marco aislado para ver y editar una landing (CSP estricta, SEC-24 fase 2).
 * Las páginas del generador (index.php, editor.php) tienen la CSP estricta; un iframe srcdoc o un blob: hereda esa política,
 * así que los scripts propios de la landing (y los del editor visual que se le inyectan) no correrían.
 * Esta página es un cascarón vacío que se sirve aislado (CSP sandbox, sin allow-same-origin): corre en un origen propio,
 * sin acceso a la sesión ni a las APIs de Toolz. frame.js pide el HTML a la página que lo abrió (postMessage) y lo escribe.
 * No muestra datos: solo acepta HTML que le manda una página de este mismo servidor (frame-host.js).
 */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-modals allow-downloads');
header('Cache-Control: no-store');
$host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
?><!DOCTYPE html>
<html lang="es" data-host="//<?= htmlspecialchars($host, ENT_QUOTES) ?>">
<head><meta charset="utf-8"><title>Vista previa</title></head>
<body style="margin:0">
<script src="frame.js?v=<?= (int)@filemtime(__DIR__ . '/frame.js') ?>"></script>
</body>
</html>
