<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$url = (string)($_GET['url'] ?? '');
require_once __DIR__ . '/net_lib.php';   // solo http(s) a direcciones públicas, sin seguir redirecciones a la red interna
$got = $url !== '' ? g7_net_fetch($url, ['text/html', 'application/xhtml'], 600000, 8) : null;
if (!$got) { header('Content-Type: application/json'); echo json_encode(['error' => 'No se pudo acceder al sitio']); exit; }
$html = $got['body']; $url = $got['url'];

$result = ['og_image' => '', 'title' => '', 'favicon' => ''];
$parsed = parse_url($url);
$base   = $parsed['scheme'] . '://' . $parsed['host'];

// Title
if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m))
    $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');

// OG image (varios formatos posibles)
$ogPatterns = [
    '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i',
    '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*>/i',
];
foreach ($ogPatterns as $pattern) {
    if (preg_match($pattern, $html, $m)) { $result['og_image'] = $m[1]; break; }
}

// Favicon
$favPatterns = [
    '/<link[^>]+rel=["\'][^"\']*shortcut icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
    '/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
    '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*>/i',
];
foreach ($favPatterns as $pattern) {
    if (preg_match($pattern, $html, $m)) {
        $fav = $m[1];
        $result['favicon'] = (strpos($fav, 'http') === 0) ? $fav : $base . '/' . ltrim($fav, '/');
        break;
    }
}
if (!$result['favicon']) $result['favicon'] = $base . '/favicon.ico';

// Make og_image absolute
if ($result['og_image'] && strpos($result['og_image'], 'http') !== 0)
    $result['og_image'] = $base . '/' . ltrim($result['og_image'], '/');

foreach (['og_image', 'favicon'] as $k) if ($result[$k] !== '' && !preg_match('#^https?://#i', $result[$k])) $result[$k] = '';
header('Content-Type: application/json');
echo json_encode($result);