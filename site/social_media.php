<?php
/**
 * social_media.php?f=<id>&e=<expires>&j=<0|1>&s=<signature> — Public, temporary link (2 to 4 h) to a file of a post, so that
 * Meta can download it when publishing (Instagram and Facebook require a public URL). Without a session: the signature (ss_sign) and the
 * expiry are the permission. j=1 converts PNG/WebP to JPEG (Instagram only accepts JPEG). Outside social/ on purpose (it is not part of the module).
 */
require_once __DIR__ . '/auth.php';
$db = getDB();
require_once __DIR__ . '/social/meta_lib.php';
$f = (int)($_GET['f'] ?? 0); $e = (int)($_GET['e'] ?? 0); $j = (int)($_GET['j'] ?? 0) ? 1 : 0; $s = (string)($_GET['s'] ?? '');
if (!$f || $e < time() || $e > time() + 86400 || !hash_equals(ss_sign("media|$f|$e|$j"), $s)) { http_response_code(404); exit('No encontrado'); }
$q = $db->prepare('SELECT f.* FROM g7_ss_files f LEFT JOIN g7_ss_posts p ON p.id = f.post_id WHERE f.id = ? AND (f.conv_id IS NOT NULL OR (p.id IS NOT NULL AND p.deleted_at IS NULL))'); $q->execute([$f]);
$row = $q->fetch(PDO::FETCH_ASSOC);
$path = $row ? ss_file_path($row) : '';
if (!$row || !is_file($path)) { http_response_code(404); exit('No encontrado'); }
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=600');
header('X-Robots-Tag: noindex');
if ($j && in_array($row['ext'], ['png', 'webp'], true) && function_exists('imagecreatefromstring')) {
    $im = @imagecreatefromstring((string)file_get_contents($path));
    if ($im) {
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));   // the transparency goes white
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        header('Content-Type: image/jpeg');
        imagejpeg($bg, null, 92); exit;
    }
}
header('Content-Type: ' . (SS_MIME[$row['ext']] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
readfile($path);
