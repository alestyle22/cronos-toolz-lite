<?php
error_reporting(0);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$id  = (int)($_POST['id'] ?? 0);
$ref = substr(trim($_POST['ref'] ?? ''), 0, 500);
if (!$id) { http_response_code(400); exit; }

require_once __DIR__ . '/../auth.php';
$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_landing_views (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        landing_id INT NOT NULL,
        ip         VARCHAR(45)  DEFAULT '',
        country    VARCHAR(100) DEFAULT '',
        city       VARCHAR(100) DEFAULT '',
        referrer   VARCHAR(500) DEFAULT '',
        ref_domain VARCHAR(200) DEFAULT '',
        device     VARCHAR(20)  DEFAULT 'desktop',
        ua         TEXT,
        created_at DATETIME DEFAULT NOW(),
        INDEX idx_landing(landing_id),
        INDEX idx_created(created_at)
    )");
} catch(Exception $e) {}

// IP — soporta Cloudflare y proxies
$ip = '';
foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) { $ip = trim(explode(',', $_SERVER[$k])[0]); break; }
}

// Dispositivo desde UA
$ua     = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
$device = 'desktop';
if (preg_match('/tablet|ipad/i', $ua))                                         $device = 'tablet';
elseif (preg_match('/mobile|android|iphone|ipod|blackberry|windows.?phone/i', $ua)) $device = 'mobile';

// Dominio referrer
$refDomain = '';
if ($ref) {
    $h = parse_url($ref, PHP_URL_HOST);
    if ($h) $refDomain = preg_replace('/^www\./i', '', $h);
}

// Geo vía ip-api.com (free, 45 req/min, sin key)
$country = ''; $city = '';
if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $geo = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city", false, $ctx);
    if ($geo) {
        $g = json_decode($geo, true);
        if (($g['status'] ?? '') === 'success') {
            $country = $g['country'] ?? '';
            $city    = $g['city']    ?? '';
        }
    }
}

try {
    $db->prepare("INSERT INTO g7_landing_views (landing_id,ip,country,city,referrer,ref_domain,device,ua)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$id, $ip, $country, $city, $ref, $refDomain, $device, $ua]);
} catch(Exception $e) {}

http_response_code(204);
