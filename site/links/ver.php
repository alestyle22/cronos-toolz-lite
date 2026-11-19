<?php
/**
 * links/ver.php?id=N — Muestra un HTML subido a la Biblioteca (g7_links.html_file), solo con sesión.
 * El archivo vive en _private/biblioteca/ (bloqueado para la web). Se sirve aislado (CSP sandbox): sus scripts
 * corren, pero en un origen propio, sin acceso a la sesión ni a las APIs de Toolz.
 * Las que vinieron del Catálogo Cronos (cat_tool) llevan además el estilo de Cronos de quien la mira (toolz/CATALOGO.md).
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
$db = getDB();
$q = $db->prepare('SELECT * FROM g7_links WHERE id = ?');   // * : cat_tool existe solo si ya se usó el Catálogo Cronos
$q->execute([(int)($_GET['id'] ?? 0)]);
$l = $q->fetch(PDO::FETCH_ASSOC);
$path = $l && $l['html_file'] ? __DIR__ . '/../_private/biblioteca/' . basename($l['html_file']) : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('Esta página ya no está en la Biblioteca.'); }
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-downloads allow-modals");
header('Cache-Control: private, no-cache');
// Las del Catálogo Cronos se visten con el estilo de Cronos de quien la mira (links/catalogo_lib.php › cat_theme_html)
if (!empty($l['cat_tool'])) { require_once __DIR__ . '/catalogo_lib.php'; echo cat_theme_html((string)file_get_contents($path), (int)$currentUser['id']); }
else readfile($path);
