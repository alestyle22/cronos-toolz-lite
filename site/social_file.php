<?php
/**
 * social_file.php?t=<token> — (fuera de social/ a propósito: quien solo está en el ticket no entra al módulo) Sirve un archivo de una publicación. Lo abre quien ve la publicación en Social Studio
 * o quien está en uno de sus tickets de Trabajo (así el ticket enlaza el archivo sin copiarlo). ?dl=1 fuerza la descarga.
 */
require_once __DIR__ . '/auth.php';
$me = getCurrentUser();
if (!$me) { http_response_code(302); header('Location: /toolz/login.php'); exit; }
$db = getDB();
require_once __DIR__ . '/social/lib.php';
ss_install($db);
session_write_close();
$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $t)) { http_response_code(404); exit('No encontrado'); }
$q = $db->prepare('SELECT f.* FROM g7_ss_files f LEFT JOIN g7_ss_posts p ON p.id = f.post_id WHERE f.token = ? AND (f.conv_id IS NOT NULL OR f.pf_user_id IS NOT NULL OR (p.id IS NOT NULL AND p.deleted_at IS NULL))'); $q->execute([$t]);
$f = $q->fetch(PDO::FETCH_ASSOC);
if (!$f || !ss_file_can($db, (int)$me['id'], $f)) { http_response_code(404); exit('No encontrado'); }
$path = ss_file_path($f);
if (!is_file($path)) { http_response_code(404); exit('El archivo ya no está en el servidor'); }
$mime = SS_MIME[$f['ext']] ?? 'application/octet-stream';
$inline = isset(SS_MIME[$f['ext']]) && empty($_GET['dl']);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
readfile($path);
