<?php
/** _update/health.php — ¿El sitio arranca? Carga el núcleo y llega a la base. Solo contesta sí o no. */
define('G7_UPDATE_HEALTH', true);
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    require_once dirname(__DIR__) . '/auth.php';
    getDB()->query('SELECT 1');
    echo '{"ok":true}';
} catch (Throwable $e) { http_response_code(500); echo '{"ok":false}'; }
