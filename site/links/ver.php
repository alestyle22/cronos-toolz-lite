<?php
/**
 * links/ver.php?id=N — Muestra un HTML subido a la Biblioteca (g7_links.html_file), solo con sesión.
 * El archivo vive en _private/biblioteca/ (bloqueado para la web). Se sirve aislado (CSP sandbox): sus scripts
 * corren, pero en un origen propio, sin acceso a la sesión ni a las APIs de Toolz.
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
$db = getDB();
$q = $db->prepare('SELECT id, name, html_file FROM g7_links WHERE id = ?');
$q->execute([(int)($_GET['id'] ?? 0)]);
$l = $q->fetch(PDO::FETCH_ASSOC);
$path = $l && $l['html_file'] ? __DIR__ . '/../_private/biblioteca/' . basename($l['html_file']) : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('Esta página ya no está en la Biblioteca.'); }
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms allow-downloads allow-modals");
header('Cache-Control: private, no-cache');
readfile($path);
