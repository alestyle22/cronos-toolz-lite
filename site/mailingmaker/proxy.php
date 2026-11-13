<?php
// ══════════════════════════════════════════════════════════════════
//  proxy.php — API-Football + The Odds API + Upload de imágenes
//  Subir al mismo directorio que generador_placas.html
// ══════════════════════════════════════════════════════════════════

// ODDS_API_KEY y APIF_KEY vienen de _private/secrets.php (los carga auth.php)
define('ALLOWED_ORIGIN', '');
define('IMGS_DIR', __DIR__ . '/imgs/');   // directorio de imágenes
define('IMGS_URL', './imgs/');            // URL relativa para el cliente
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB por archivo

// ── Acceso ────────────────────────────────────────────────────────
// Antes respondía a cualquiera (sin login y con CORS abierto): se podían subir y borrar imágenes,
// subir SVG con código y usar img_proxy para pedir cualquier URL. Ahora exige sesión y el permiso
// del módulo, y como no manda cabeceras CORS, otros sitios no pueden llamarlo desde el navegador.
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
if (!getCurrentUser()) { http_response_code(401); echo json_encode(['error' => 'Iniciá sesión de nuevo']); exit; }

// Crear directorio /imgs si no existe
if (!is_dir(IMGS_DIR)) {
    mkdir(IMGS_DIR, 0755, true);
    // .htaccess para servir imágenes directamente
    file_put_contents(IMGS_DIR . '.htaccess', "Options -Indexes\n");
}

/** Reduce una imagen JPEG/PNG/WebP a $maxW de ancho, mismo formato y nombre. Si algo falla, la deja como estaba. */
function g7_shrink_image(string $path, string $mime, int $maxW): void {
    if (!function_exists('imagecreatetruecolor')) return;
    $info = @getimagesize($path);
    if (!$info || $info[0] <= $maxW) return;
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };
    if (!$src) return;
    $w = $maxW; $h = (int)round($info[1] * $maxW / $info[0]);
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    $tmp = $path . '.tmp';
    $ok = match ($mime) {
        'image/jpeg' => @imagejpeg($dst, $tmp, 85),
        'image/png'  => @imagepng($dst, $tmp, 8),
        'image/webp' => @imagewebp($dst, $tmp, 85),
    };
    if ($ok && filesize($tmp) < filesize($path)) @rename($tmp, $path); else @unlink($tmp);
}

// ── UPLOAD (multipart/form-data) ──────────────────────────────────
// Frontend envía: FormData con file + type (league|bg|shield|event|template_thumb|pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file  = $_FILES['file'];
    $type  = preg_replace('/[^a-z_]/', '', $_POST['type'] ?? 'misc');
    $name  = trim($_POST['name'] ?? '');

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400); echo json_encode(['error' => 'Error en upload: ' . $file['error']]); exit;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400); echo json_encode(['error' => 'Archivo muy grande (máx 5MB)']); exit;
    }

    // Validar tipo MIME
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed_mime = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($mime, $allowed_mime)) {
        http_response_code(400); echo json_encode(['error' => 'Tipo no permitido: ' . $mime]); exit;
    }

    $ext   = match($mime) {
        'image/jpeg'   => 'jpg',
        'image/png'    => 'png',
        'image/gif'    => 'gif',
        'image/webp'   => 'webp',
        'image/svg+xml'=> 'svg',
        default        => 'jpg'
    };

    $filename  = $type . '_' . uniqid() . '.' . $ext;
    $dest      = IMGS_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['error' => 'No se pudo guardar el archivo']); exit;
    }
    g7_shrink_image($dest, $mime, 1200);

    echo json_encode(['url' => IMGS_URL . $filename, 'name' => $name ?: $filename]);
    exit;
}

// ── JSON POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Método no permitido']); exit;
}
$body = file_get_contents('php://input');
$req  = json_decode($body, true);
if (!$req || !isset($req['action'])) {
    http_response_code(400); echo json_encode(['error' => 'action requerido']); exit;
}

// ── upload_base64 — sube imagen desde dataURL base64 ─────────────
// Útil para guardar thumbnails, capturas de canvas, etc.
if ($req['action'] === 'upload_base64') {
    $dataUrl = $req['dataUrl'] ?? '';
    $type    = preg_replace('/[^a-z_]/', '', $req['type'] ?? 'misc');
    if (!$dataUrl) { echo json_encode(['error' => 'dataUrl requerido']); exit; }

    // Parsear data URL
    if (!preg_match('/^data:(image\/\w+(\+xml)?);base64,(.+)$/s', $dataUrl, $m)) {
        echo json_encode(['error' => 'dataUrl inválido']); exit;
    }
    $mime    = $m[1];
    $b64data = $m[3];
    $imgData = base64_decode($b64data);
    if (!$imgData) { echo json_encode(['error' => 'Base64 inválido']); exit; }
    if (strlen($imgData) > MAX_FILE_SIZE) { echo json_encode(['error' => 'Imagen muy grande']); exit; }

    $ext  = match($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/svg+xml' => 'svg', default => 'jpg'
    };
    if ($mime === 'image/svg+xml') { echo json_encode(['error' => 'SVG no permitido']); exit; }
    $filename = $type . '_' . uniqid() . '.' . $ext;
    file_put_contents(IMGS_DIR . $filename, $imgData);
    g7_shrink_image(IMGS_DIR . $filename, $mime, 1200);
    echo json_encode(['url' => IMGS_URL . $filename]);
    exit;
}

// ── delete_img — elimina una imagen del servidor ──────────────────
if ($req['action'] === 'delete_img') {
    $url  = $req['url'] ?? '';
    $file = basename($url);
    // Solo permitir borrar archivos del directorio imgs
    if (!$file || strpos($file, '..') !== false || !file_exists(IMGS_DIR . $file)) {
        echo json_encode(['ok' => false, 'error' => 'Archivo no encontrado']); exit;
    }
    unlink(IMGS_DIR . $file);
    echo json_encode(['ok' => true]);
    exit;
}

// ── list_imgs — lista imágenes por tipo ──────────────────────────
if ($req['action'] === 'list_imgs') {
    $type = preg_replace('/[^a-z_]/', '', $req['type'] ?? '');
    $files = glob(IMGS_DIR . ($type ? $type . '_*' : '*'));
    $result = [];
    foreach ($files as $f) {
        $bn = basename($f);
        if ($bn === '.htaccess') continue;
        $result[] = ['url' => IMGS_URL . $bn, 'name' => $bn, 'size' => filesize($f)];
    }
    // Ordenar por fecha de modificación desc
    usort($result, fn($a,$b)=>filemtime(IMGS_DIR.basename($b['url']))<=>filemtime(IMGS_DIR.basename($a['url'])));
    echo json_encode(['files' => $result]);
    exit;
}

// ── search_team ───────────────────────────────────────────────────
if ($req['action'] === 'search_team') {
    $query  = trim($req['query']  ?? '');
    $apikey = trim($req['apikey'] ?? '') ?: APIF_KEY;
    if (!$query) { echo json_encode(['teams' => []]); exit; }
    $url  = 'https://v3.football.api-sports.io/teams?search=' . urlencode($query);
    $data = apif_get($url, $apikey);
    if (!$data) { echo json_encode(['teams' => [], 'error' => 'Error conectando']); exit; }
    $json = json_decode($data, true);
    if (!empty($json['errors'])) { $err=is_array($json['errors'])?implode(', ',$json['errors']):json_encode($json['errors']); echo json_encode(['teams'=>[],'error'=>$err]); exit; }
    $result = [];
    foreach ($json['response'] ?? [] as $item) {
        $t = $item['team'] ?? [];
        if (!($t['logo'] ?? '')) continue;
        $result[] = ['name'=>$t['name']??'','country'=>$t['country']??'','logo'=>$t['logo']??'','id'=>$t['id']??''];
        if (count($result) >= 8) break;
    }
    echo json_encode(['teams' => $result]); exit;
}

// ── search_league ─────────────────────────────────────────────────
if ($req['action'] === 'search_league') {
    $query  = trim($req['query']  ?? '');
    $apikey = trim($req['apikey'] ?? '') ?: APIF_KEY;
    if (!$query) { echo json_encode(['leagues' => []]); exit; }
    $url  = 'https://v3.football.api-sports.io/leagues?search=' . urlencode($query) . '&type=league';
    $data = apif_get($url, $apikey);
    if (!$data) { echo json_encode(['leagues' => [], 'error' => 'Error conectando']); exit; }
    $json = json_decode($data, true);
    $result = [];
    foreach ($json['response'] ?? [] as $item) {
        $l=$item['league']??[]; $c=$item['country']??[];
        $result[] = ['id'=>$l['id']??'','name'=>$l['name']??'','country'=>$c['name']??'','logo'=>$l['logo']??''];
        if (count($result) >= 8) break;
    }
    echo json_encode(['leagues' => $result]); exit;
}

// ── next_events ───────────────────────────────────────────────────
if ($req['action'] === 'next_events') {
    $leagueId = trim($req['league_id'] ?? '');
    $apikey   = trim($req['apikey']    ?? '') ?: APIF_KEY;
    if (!$leagueId) { echo json_encode(['events'=>[],'error'=>'Falta league_id']); exit; }
    $season = date('Y');
    $url    = "https://v3.football.api-sports.io/fixtures?league={$leagueId}&season={$season}&next=20";
    $data   = apif_get($url, $apikey);
    $json   = json_decode($data ?: '{}', true);
    if (!empty($json['errors']) || empty($json['response'])) {
        $url2 = "https://v3.football.api-sports.io/fixtures?league={$leagueId}&season=".($season-1)."&next=20";
        $data2 = apif_get($url2, $apikey);
        $json  = json_decode($data2 ?: '{}', true);
    }
    if (empty($json['response'])) {
        $url3 = "https://v3.football.api-sports.io/fixtures?league={$leagueId}&season={$season}&last=10";
        $data3 = apif_get($url3, $apikey);
        $json  = json_decode($data3 ?: '{}', true);
    }
    $result = [];
    foreach ($json['response'] ?? [] as $f) {
        $fix=$f['fixture']??[]; $home=$f['teams']['home']??[]; $away=$f['teams']['away']??[]; $lg=$f['league']??[];
        $dt = $fix['date'] ?? '';
        $result[] = ['id'=>$fix['id']??'','home'=>$home['name']??'','away'=>$away['name']??'',
            'home_logo'=>$home['logo']??'','away_logo'=>$away['logo']??'',
            'date'=>$dt?date('Y-m-d',strtotime($dt)):'','time'=>$dt?date('H:i',strtotime($dt)):'',
            'league'=>$lg['name']??'','round'=>$lg['round']??''];
        if (count($result) >= 20) break;
    }
    echo json_encode(['events' => $result]); exit;
}

// ── get_odds ──────────────────────────────────────────────────────
if ($req['action'] === 'get_odds') {
    $key    = ODDS_API_KEY ?: ($req['apiKey'] ?? '');
    $team1  = trim($req['team1']     ?? '');
    $team2  = trim($req['team2']     ?? '');
    $bookie = trim($req['bookmaker'] ?? 'betfair');
    if (!$key)              { echo json_encode(['error'=>'API key no configurada']); exit; }
    if (!$team1 || !$team2) { echo json_encode(['error'=>'Ingresá ambos equipos']); exit; }
    $sports_data = fetch_url("https://api.the-odds-api.com/v4/sports/?apiKey={$key}");
    if (!$sports_data) { echo json_encode(['error'=>'No se pudo conectar']); exit; }
    $sports = json_decode($sports_data, true);
    if (isset($sports['message'])) { echo json_encode(['error'=>'API key inválida: '.$sports['message']]); exit; }
    $soccer_keys = [];
    foreach ($sports as $s) { if ($s['group']==='Soccer') $soccer_keys[]=$s['key']; }
    $soccer_keys = array_slice($soccer_keys, 0, 12);
    $found = null;
    foreach ($soccer_keys as $sport) {
        $data = fetch_url("https://api.the-odds-api.com/v4/sports/{$sport}/odds/?apiKey={$key}&regions=eu&markets=h2h&bookmakers={$bookie}");
        if (!$data) continue;
        $events = json_decode($data, true);
        if (!is_array($events)) continue;
        foreach ($events as $e) {
            $ht=strtolower($e['home_team']??''); $at=strtolower($e['away_team']??'');
            $t1=strtolower($team1); $t2=strtolower($team2);
            if ((str_contains($ht,$t1)||str_contains($t1,$ht))&&(str_contains($at,$t2)||str_contains($t2,$at)))
                { $found=$e; break 2; }
        }
    }
    if (!$found) { echo json_encode(['error'=>'Partido no encontrado']); exit; }
    $bm=$found['bookmakers'][0]??null;
    if (!$bm) { echo json_encode(['error'=>'Casa no disponible']); exit; }
    $h2h=null; foreach($bm['markets'] as $m){if($m['key']==='h2h'){$h2h=$m;break;}}
    if (!$h2h) { echo json_encode(['error'=>'Sin cuotas H2H']); exit; }
    $result=['bookmaker'=>$bm['title'],'home'=>null,'draw'=>null,'away'=>null];
    foreach($h2h['outcomes'] as $o){
        if($o['name']===$found['home_team']) $result['home']=round($o['price'],2);
        elseif($o['name']===$found['away_team']) $result['away']=round($o['price'],2);
        elseif($o['name']==='Draw') $result['draw']=round($o['price'],2);
    }
    echo json_encode($result); exit;
}

// ── img_proxy ─────────────────────────────────────────────────────
if ($req['action'] === 'img_proxy') {
    $url = trim($req['url'] ?? '');
    if (!$url) { http_response_code(400); echo json_encode(['error'=>'URL requerida']); exit; }
    $allowed = ['thesportsdb.com','upload.wikimedia.org','cdn.thesportsdb.com','media.api-sports.io','apifootball.com'];
    $host = parse_url($url, PHP_URL_HOST);
    $ok = false;
    foreach ($allowed as $a) { if ($host===$a||str_ends_with($host,'.'.$a)){$ok=true;break;} }
    if (!$ok) { http_response_code(403); echo json_encode(['error'=>'Dominio no permitido: '.$host]); exit; }
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_USERAGENT=>'PlacasDeportivas/1.0',CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3]);
    $img_data=curl_exec($ch); $ct=curl_getinfo($ch,CURLINFO_CONTENT_TYPE); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$img_data||$http!==200) { http_response_code(404); echo json_encode(['error'=>'Imagen no encontrada']); exit; }
    $b64=base64_encode($img_data);
    if (!$ct||$ct==='application/octet-stream') {
        $ct='image/png';
        if (str_contains($url,'.svg')) $ct='image/svg+xml';
        if (str_contains($url,'.jpg')||str_contains($url,'.jpeg')) $ct='image/jpeg';
        if (str_contains($url,'.webp')) $ct='image/webp';
    }
    $ct=explode(';',$ct)[0];
    echo json_encode(['dataUrl'=>'data:'.$ct.';base64,'.$b64]); exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción desconocida: '.$req['action']]);

// ── Helpers ───────────────────────────────────────────────────────
function apif_get($url, $apikey) {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_USERAGENT=>'PlacasDeportivas/1.0',
        CURLOPT_HTTPHEADER=>['x-rapidapi-key: '.$apikey,'x-rapidapi-host: v3.football.api-sports.io','Accept: application/json']]);
    $resp=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    return $err?null:$resp;
}
function fetch_url($url) {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_USERAGENT=>'PlacasDeportivas/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $resp=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    return $err?null:$resp;
}