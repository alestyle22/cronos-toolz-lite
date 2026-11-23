<?php
/**
 * social/net_tiktok.php — TikTok connector (Login Kit + Display API + Content Posting API). Official API only.
 * Verified against developers.tiktok.com on 2026-11-17:
 *  - OAuth v2: www.tiktok.com/v2/auth/authorize (scopes with commas, no PKCE for web), token/refresh/revoke in open.tiktokapis.com/v2/oauth/*.
 *    Access 2 h, refresh 180 days and it ROTATES (the last one is always kept: ss_net_token does it with a lock).
 *  - Videos: Direct Post with FILE_UPLOAD (the bytes go from our server: no domain to verify). Photos: only PULL_FROM_URL, which needs
 *    the domain or URL prefix verified in the TikTok portal (option in Accounts).
 *  - Mandatory UX: creator's nickname, privacy chosen by hand without a default, music usage consent, commercial content disclosure.
 *  - Apps not audited by TikTok: only SELF_ONLY (private) and private accounts. That is TikTok's rule, shown as such.
 *  - NO comments or DMs API for regular accounts (Login Kit): there is no TikTok Inbox, it is said on screen.
 *  - Metrics: TikTok gives followers, total likes and the counters of each video (views, likes, comments, shares). No daily reach.
 */

function ss_tiktok_api(): string { return ss_net_url('tiktok', 'api', 'https://open.tiktokapis.com'); }
function ss_tiktok_www(): string { return ss_net_url('tiktok', 'www', 'https://www.tiktok.com'); }

function ss_tiktok_scopes(array $c): array {
    $s = ['user.info.basic', 'user.info.profile', 'user.info.stats'];
    if ($c['publish']) $s[] = 'video.publish';
    if ($c['analytics']) $s[] = 'video.list';
    return $s;
}
function ss_tiktok_info(array $c): array {
    $photos = !empty($c['extra']['photo_domain']);
    return [
        'console' => 'https://developers.tiktok.com/apps',
        'scope_info' => ['user.info.basic' => 'Ver el nombre y la foto de la cuenta', 'user.info.profile' => 'Ver el usuario (@) y el link al perfil',
                         'user.info.stats' => 'Ver seguidores, me gusta y cantidad de videos', 'video.publish' => 'Publicar videos y fotos en la cuenta', 'video.list' => 'Leer los videos públicos y sus números'],
        'caps' => [
            ['Publicar videos', $c['publish'] ? true : 'off', 'Se suben directo desde el servidor (MP4, MOV o WebM, hasta 10 minutos).'],
            ['Publicar fotos y carruseles', !$c['publish'] ? 'off' : ($photos ? true : 'setup'), 'TikTok descarga las fotos de un link: hay que verificar el dominio del sitio en el portal de TikTok.'],
            ['Métricas', $c['analytics'] ? true : 'off', 'Seguidores del día y números de cada video (vistas, me gusta, comentarios, compartidos). TikTok no da alcance diario por API.'],
            ['Inbox (comentarios y mensajes)', 'no', 'La API de TikTok para cuentas comunes no permite leer ni responder comentarios ni mensajes.'],
        ],
        'limits' => [
            'Mientras TikTok no audite la app, solo se puede publicar en privado ("Solo yo"), en cuentas privadas y con hasta 5 personas por día. La auditoría se pide en el portal de TikTok.',
            'TikTok limita a unas 15 publicaciones por día por cuenta (entre todas las apps).',
            'Antes de publicar hay que elegir a mano quién lo puede ver y aceptar el aviso de uso de música de TikTok: Social Studio no elige por vos.',
        ],
        'steps' => [
            'En developers.tiktok.com, crear una app (tipo web) con la cuenta de la agencia.',
            'Agregar los productos Login Kit, Content Posting API (con Direct Post activado) y Display API (para métricas).',
            'En Login Kit, cargar la URI de redireccionamiento de abajo (https, sin parámetros).',
            'Pedir los permisos de la lista y mandar la app a revisión (App Review). Después, pedir la auditoría de Content Posting para publicar en público.',
            'Para fotos: en "URL properties", verificar el dominio del sitio (registro DNS) y tildar la opción de abajo.',
        ],
        'fields' => [['id' => 'photo_domain', 'type' => 'check', 'label' => 'Verifiqué el dominio de este sitio en TikTok (para publicar fotos)']],
    ];
}
function ss_tiktok_authorize(array $c, string $state, string $challenge, array $scopes): string {
    return ss_tiktok_www() . '/v2/auth/authorize/?' . http_build_query(['client_key' => $c['client_id'], 'scope' => implode(',', $scopes), 'redirect_uri' => ss_net_redirect_uri(), 'state' => $state, 'response_type' => 'code']);
}
function ss_tiktok_error(array $r): array {
    $j = $r['json'];
    if (isset($j['error']) && is_array($j['error'])) return [(string)($j['error']['message'] ?? 'Error de TikTok'), (string)($j['error']['code'] ?? '')];
    return [(string)($j['error_description'] ?? $j['message'] ?? ('HTTP ' . $r['code'])), (string)($j['error'] ?? '')];
}
function ss_tiktok_token(PDO $db, array $form, ?int $acc, string $action, array $prev = []): array {
    $c = ss_net_cfg('tiktok');
    $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/oauth/token/', ['form' => $form + ['client_key' => $c['client_id'], 'client_secret' => ss_net_secret('tiktok')]], $acc, $action);
    if (empty($r['json']['access_token'])) { $e = new SsNetError('TikTok no devolvió el permiso: ' . ($r['json']['error_description'] ?? 'probá de nuevo'), 400); $e->metaCode = 190; throw $e; }
    $t = $r['json']; $t['scope'] = str_replace(',', ' ', (string)($t['scope'] ?? ''));
    return ss_net_bundle_from($t, $prev);
}
function ss_tiktok_exchange(PDO $db, string $code, string $verifier): array {
    return ss_tiktok_token($db, ['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => ss_net_redirect_uri()], null, 'oauth_code');
}
function ss_tiktok_refresh(PDO $db, array $b, int $acc): array {
    return ss_tiktok_token($db, ['grant_type' => 'refresh_token', 'refresh_token' => $b['refresh']], $acc, 'refresh', $b);
}
function ss_tiktok_revoke(PDO $db, array $b, int $acc): void {
    $c = ss_net_cfg('tiktok');
    ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/oauth/revoke/', ['form' => ['client_key' => $c['client_id'], 'client_secret' => ss_net_secret('tiktok'), 'token' => $b['access']]], $acc, 'revoke');
}
/** GET /v2/user/info/ with a token. */
function ss_tiktok_user(PDO $db, string $tok, ?int $acc, bool $stats = false): array {
    $f = 'open_id,union_id,avatar_url,display_name,username,profile_deep_link' . ($stats ? ',follower_count,following_count,likes_count,video_count' : '');
    $r = ss_net_req($db, 'tiktok', 'GET', ss_tiktok_api() . '/v2/user/info/', ['query' => ['fields' => $f], 'headers' => ['Authorization: Bearer ' . $tok]], $acc, 'tt_user');
    return (array)($r['json']['data']['user'] ?? []);
}
function ss_tiktok_candidates(PDO $db, array $b): array {
    $u = ss_tiktok_user($db, $b['access'], null);
    $oid = (string)($u['open_id'] ?? ($b['extra']['open_id'] ?? ''));
    if ($oid === '') ss_fail('TikTok no devolvió la cuenta.', 502);
    return ['user_ext_id' => $oid, 'user_name' => (string)($u['display_name'] ?? ''),
            'cands' => [['kind' => 'tt_user', 'ext_id' => $oid, 'name' => (string)($u['display_name'] ?? ''), 'username' => (string)($u['username'] ?? ''), 'picture' => (string)($u['avatar_url'] ?? ''), 'can_post' => true]]];
}
function ss_tiktok_check(PDO $db, array $a): array {
    $u = ss_tiktok_user($db, ss_net_token($db, $a), (int)$a['id']);
    return ['name' => (string)($u['display_name'] ?? ''), 'username' => (string)($u['username'] ?? ''), 'picture' => (string)($u['avatar_url'] ?? '')];
}
/** creator_info: who posts, which privacy options TikTok allows today and the max video length. The screen shows it before publishing. */
function ss_tiktok_creator(PDO $db, array $a): array {
    $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/post/publish/creator_info/query/', ['json' => new stdClass(), 'headers' => ['Authorization: Bearer ' . ss_net_token($db, $a)]], (int)$a['id'], 'tt_creator');
    $d = (array)($r['json']['data'] ?? []);
    return ['nickname' => (string)($d['creator_nickname'] ?? ''), 'username' => (string)($d['creator_username'] ?? ''), 'avatar' => (string)($d['creator_avatar_url'] ?? ''),
            'privacy' => array_values((array)($d['privacy_level_options'] ?? [])), 'comment_disabled' => !empty($d['comment_disabled']),
            'max_sec' => (int)($d['max_video_post_duration_sec'] ?? 0)];
}
const SS_TT_PRIVACY = ['PUBLIC_TO_EVERYONE' => 'Todos', 'MUTUAL_FOLLOW_FRIENDS' => 'Amigos (se siguen mutuamente)', 'FOLLOWER_OF_CREATOR' => 'Seguidores', 'SELF_ONLY' => 'Solo yo (privado)'];

/** What goes to TikTok: a video, or photos (1 to 35) when the domain is verified. $opts: privacy, allow_comments, brand (none|own|branded), music_ok. */
function ss_tiktok_plan(array $post, array $files, array $opts): array {
    $imgs = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['jpg', 'jpeg', 'webp', 'png'], true)));
    $vids = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['mp4', 'mov', 'webm'], true)));
    if (!isset(SS_TT_PRIVACY[$opts['privacy'] ?? ''])) ss_fail('Para TikTok falta elegir quién puede ver la publicación (TikTok pide que se elija a mano).');
    if (empty($opts['music_ok'])) ss_fail('Para TikTok hay que aceptar el aviso de uso de música de TikTok antes de publicar.');
    if (in_array($post['format'], ['imagen', 'carrusel'], true) || !$vids) {
        if (!$imgs) ss_fail('Para TikTok falta el video (MP4, MOV o WebM) o, si es de fotos, las imágenes en Archivos.');
        if (empty(ss_net_cfg('tiktok')['extra']['photo_domain'])) ss_fail('TikTok solo publica fotos desde un dominio verificado en su portal: verificalo y tildá la opción en Cuentas › TikTok, o publicá un video.');
        if (count($imgs) > 35) ss_fail('TikTok acepta hasta 35 fotos por publicación.');
        foreach ($imgs as $f) if ($f['size'] > 20 * 1048576) ss_fail('TikTok acepta fotos de hasta 20 MB: "' . $f['name'] . '" pesa más.');
        return ['kind' => count($imgs) > 1 ? 'carousel' : 'image', 'images' => $imgs, 'video' => null];
    }
    if ($vids[0]['size'] > 4 * 1073741824) ss_fail('TikTok acepta videos de hasta 4 GB.');
    return ['kind' => 'video', 'images' => [], 'video' => $vids[0]];
}
function ss_tiktok_post_info(array $opts, string $cap, bool $photo): array {
    $pi = ['privacy_level' => $opts['privacy'], 'disable_comment' => empty($opts['allow_comments'])];
    if (($opts['brand'] ?? 'none') !== 'none') { $pi['brand_organic_toggle'] = $opts['brand'] === 'own'; $pi['brand_content_toggle'] = $opts['brand'] === 'branded'; }
    if ($photo) { $pi['title'] = mb_substr(strtok($cap, "\n") ?: '', 0, 90); $pi['description'] = mb_substr($cap, 0, 4000); $pi['auto_add_music'] = true; }
    else { $pi['title'] = mb_substr($cap, 0, 2200); $pi['disable_duet'] = false; $pi['disable_stitch'] = false; }
    return $pi;
}
/** Starts the post. Returns ['pending' => publish_id]: TikTok processes it and the cron asks for its status (poll). */
function ss_tiktok_publish(PDO $db, array $a, array $plan, string $cap, array $post, array $opts): array {
    $tok = ss_net_token($db, $a); $acc = (int)$a['id']; $h = ['Authorization: Bearer ' . $tok];
    $cr = ss_tiktok_creator($db, $a);
    if (!in_array($opts['privacy'], $cr['privacy'], true)) ss_fail('TikTok no permite hoy esa privacidad para esta cuenta' . ($cr['privacy'] === ['SELF_ONLY'] ? ' (mientras la app no esté auditada, solo "Solo yo")' : '') . '.');
    if ($plan['video'] === null) {
        $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/post/publish/content/init/', ['headers' => $h, 'json' => [
            'post_info' => ss_tiktok_post_info($opts, $cap, true), 'post_mode' => 'DIRECT_POST', 'media_type' => 'PHOTO',
            'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => array_map(fn($f) => ss_media_url($f, $f['ext'] === 'png', 14400), $plan['images'])]], 'tries' => 1], $acc, 'tt_photo_init');
        return ['pending' => (string)($r['json']['data']['publish_id'] ?? '')];
    }
    $path = ss_net_file_path($db, (int)$plan['video']['id']); $size = filesize($path);
    $chunk = $size <= 64 * 1048576 ? $size : 10 * 1048576; $count = $size <= 64 * 1048576 ? 1 : intdiv($size, $chunk);
    $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/post/publish/video/init/', ['headers' => $h, 'json' => [
        'post_info' => ss_tiktok_post_info($opts, $cap, false), 'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunk, 'total_chunk_count' => $count]], 'tries' => 1], $acc, 'tt_video_init');
    $pid = (string)($r['json']['data']['publish_id'] ?? ''); $up = (string)($r['json']['data']['upload_url'] ?? '');
    if ($pid === '' || $up === '') ss_fail('TikTok no devolvió dónde subir el video.', 502);
    $mime = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][$plan['video']['ext']] ?? 'video/mp4';
    for ($i = 0; $i < $count; $i++) {
        $from = $i * $chunk; $len = $i === $count - 1 ? $size - $from : $chunk;
        ss_net_req($db, 'tiktok', 'PUT', $up, ['raw' => ss_net_chunk($path, $from, $len), 'timeout' => 600, 'tries' => 2,
            'headers' => ['Content-Type: ' . $mime, 'Content-Length: ' . $len, 'Content-Range: bytes ' . $from . '-' . ($from + $len - 1) . '/' . $size]], $acc, 'tt_upload');
    }
    return ['pending' => $pid];
}
/** Status of a post being processed: null = still processing; ['id', 'link'] when it is published; it fails with TikTok's reason. */
function ss_tiktok_poll(PDO $db, array $a, string $pid): ?array {
    $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/post/publish/status/fetch/', ['headers' => ['Authorization: Bearer ' . ss_net_token($db, $a)], 'json' => ['publish_id' => $pid]], (int)$a['id'], 'tt_status');
    $d = (array)($r['json']['data'] ?? []);
    switch ((string)($d['status'] ?? '')) {
        case 'PUBLISH_COMPLETE':
            $vid = (string)(((array)($d['publicaly_available_post_id'] ?? $d['publicly_available_post_id'] ?? []))[0] ?? '');
            return ['id' => $vid !== '' ? $vid : $pid, 'link' => $vid !== '' && $a['username'] ? 'https://www.tiktok.com/@' . rawurlencode($a['username']) . '/video/' . $vid : ''];
        case 'FAILED':
            $why = (string)($d['fail_reason'] ?? '');
            $txt = ['file_format_check_failed' => 'el formato del archivo no sirve', 'duration_check_failed' => 'el video es más largo de lo permitido', 'frame_rate_check_failed' => 'los cuadros por segundo no sirven (23 a 60)',
                    'picture_size_check_failed' => 'el tamaño de la imagen no sirve', 'video_pull_failed' => 'TikTok no pudo descargar el video', 'photo_pull_failed' => 'TikTok no pudo descargar las fotos (¿dominio verificado?)',
                    'spam_risk_too_many_posts' => 'se llegó al tope diario de publicaciones de la cuenta', 'auth_removed' => 'se sacó el permiso de la app', 'publish_cancelled' => 'se canceló'][$why] ?? $why;
            ss_fail('TikTok no la publicó: ' . ($txt ?: 'error interno de TikTok') . '.');
    }
    return null;
}
/** Metrics: followers of the day (snapshot) and the counters of the last videos. TikTok gives no daily series. */
function ss_tiktok_an_sync(PDO $db, array $a, int $days): array {
    $tok = ss_net_token($db, $a); $id = (int)$a['id'];
    $u = ss_tiktok_user($db, $tok, $id, true);
    if (isset($u['follower_count'])) ss_an_put($db, $id, date('Y-m-d'), 'followers', $u['follower_count']);
    $n = 0;
    if (!ss_net_cfg('tiktok')['analytics']) return ['days' => 1, 'media' => 0, 'skipped' => ['video.list']];
    $up = ss_an_media_upsert_stmt($db);
    $r = ss_net_req($db, 'tiktok', 'POST', ss_tiktok_api() . '/v2/video/list/', ['query' => ['fields' => 'id,title,video_description,create_time,cover_image_url,share_url,view_count,like_count,comment_count,share_count'],
        'headers' => ['Authorization: Bearer ' . $tok], 'json' => ['max_count' => 20]], $id, 'tt_videos');
    foreach ((array)($r['json']['data']['videos'] ?? []) as $v) {
        $mt = ['views' => (int)($v['view_count'] ?? 0), 'likes' => (int)($v['like_count'] ?? 0), 'comments' => (int)($v['comment_count'] ?? 0), 'shares' => (int)($v['share_count'] ?? 0)];
        $mt['interactions'] = $mt['likes'] + $mt['comments'] + $mt['shares'];
        ss_an_media_put($db, $up, $id, (string)$v['id'], 'VIDEO', (string)($v['video_description'] ?? $v['title'] ?? ''), (string)($v['share_url'] ?? ''), (string)($v['cover_image_url'] ?? ''),
                        date('Y-m-d H:i:s', (int)($v['create_time'] ?? time())), $mt);
        $n++;
    }
    return ['days' => 1, 'media' => $n, 'skipped' => []];
}
