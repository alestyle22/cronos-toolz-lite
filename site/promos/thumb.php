<?php
/**
 * thumb.php — Miniaturas livianas de las portadas de Promos.
 *   thumb.php?src=imgs/archivo.png&w=640
 * Las portadas originales pesan ~1,6 MB y la grilla las muestra a ~300 px.
 * Genera una vez una versión reducida (WebP si el servidor lo soporta, si no JPEG),
 * la guarda en imgs/_thumbs/ y la sirve con caché larga. Si algo falla, redirige al original.
 * Solo acepta archivos de imgs/ y dos anchos fijos, así no se puede usar para generar archivos arbitrarios.
 */
$src = (string)($_GET['src'] ?? '');
$w   = (int)($_GET['w'] ?? 640);
if (!in_array($w, [160, 640], true)) $w = 640;

$base = realpath(__DIR__ . '/imgs');
$path = $base ? realpath(__DIR__ . '/' . ltrim($src, '/')) : false;
if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)
    || !preg_match('/\.(jpe?g|png|webp|gif)$/i', $path)) {
    http_response_code(404); exit;
}
$original = 'imgs/' . str_replace('\\', '/', substr($path, strlen($base) + 1));

$canWebp = function_exists('imagewebp');
$dir = $base . '/_thumbs';
$out = $dir . '/' . md5($original . '|' . filemtime($path)) . '-' . $w . ($canWebp ? '.webp' : '.jpg');

if (!is_file($out)) {
    $ok = false;
    if (function_exists('imagecreatetruecolor') && (@is_dir($dir) || @mkdir($dir, 0755, true))) {
        $info = @getimagesize($path);
        $img = null;
        if ($info) {
            switch ($info[2]) {
                case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($path); break;
                case IMAGETYPE_PNG:  $img = @imagecreatefrompng($path); break;
                case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null; break;
                case IMAGETYPE_GIF:  $img = @imagecreatefromgif($path); break;
            }
        }
        if ($img) {
            $sw = imagesx($img); $sh = imagesy($img);
            if ($sw <= $w) {
                $dst = $img; // ya es chica: solo se recomprime
            } else {
                $dh = (int)round($sh * $w / $sw);
                $dst = imagecreatetruecolor($w, $dh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                if (!$canWebp) { imagefill($dst, 0, 0, imagecolorallocate($dst, 17, 17, 19)); imagealphablending($dst, true); }
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $dh, $sw, $sh);
            }
            $tmp = $out . '.' . getmypid() . '.tmp';
            $ok = $canWebp ? @imagewebp($dst, $tmp, 78) : @imagejpeg($dst, $tmp, 80);
            if ($ok) $ok = @rename($tmp, $out); else @unlink($tmp);
        }
    }
    if (!$ok) { header('Location: ' . $original, true, 302); exit; }
}

header('Content-Type: ' . ($canWebp ? 'image/webp' : 'image/jpeg'));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($out));
readfile($out);
