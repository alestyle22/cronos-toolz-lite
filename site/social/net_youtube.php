<?php
/**
 * social/net_youtube.php — YouTube connector (Google OAuth + YouTube Data API v3 + YouTube Analytics API v2). Official API only.
 * Verified against developers.google.com on 2026-11-17:
 *  - Google OAuth web server flow with access_type=offline and prompt=consent (the refresh token only comes on the first exchange).
 *    While the Google project is in "Testing", the permission expires in 7 days; unverified in production: max. 100 users for life.
 *  - Upload: videos.insert resumable (own quota: 100 uploads per day since 1-jun-2026). Since 2-nov-2026 YouTube no longer forces
 *    unverified projects' uploads to private. The privacy is chosen when publishing.
 *  - Comments: commentThreads.list (1 unit) and comments.insert (50 units, youtube.force-ssl). No webhooks for comments: they are read periodically.
 *  - Analytics: views, minutes watched, likes, comments, shares, subscribers gained per day (48-72 h of delay).
 */

function ss_youtube_api(): string { return ss_net_url('youtube', 'api', 'https://www.googleapis.com'); }
function ss_youtube_analytics(): string { return ss_net_url('youtube', 'analytics', 'https://youtubeanalytics.googleapis.com'); }
function ss_youtube_oauth(): string { return ss_net_url('youtube', 'oauth', 'https://oauth2.googleapis.com'); }
function ss_youtube_accounts(): string { return ss_net_url('youtube', 'accounts', 'https://accounts.google.com'); }

const SS_YT_SCOPE = 'https://www.googleapis.com/auth/';
function ss_youtube_scopes(array $c): array {
    $s = [SS_YT_SCOPE . 'youtube.readonly'];
    if ($c['publish']) $s[] = SS_YT_SCOPE . 'youtube.upload';
    if ($c['inbox']) $s[] = SS_YT_SCOPE . 'youtube.force-ssl';
    if ($c['analytics']) $s[] = SS_YT_SCOPE . 'yt-analytics.readonly';
    return $s;
}
function ss_youtube_info(array $c): array {
    return [
        'console' => 'https://console.cloud.google.com/apis/credentials',
        'scope_info' => [SS_YT_SCOPE . 'youtube.readonly' => 'Ver el canal y sus videos', SS_YT_SCOPE . 'youtube.upload' => 'Subir videos al canal',
                         SS_YT_SCOPE . 'youtube.force-ssl' => 'Leer y responder comentarios del canal', SS_YT_SCOPE . 'yt-analytics.readonly' => 'Ver las estadísticas de YouTube Analytics'],
        'caps' => [
            ['Publicar videos', $c['publish'] ? true : 'off', 'Se suben desde el servidor; la privacidad (pública, no listada o privada) se elige al publicar.'],
            ['Métricas', $c['analytics'] ? true : 'off', 'Suscriptores, suscriptores nuevos, vistas, minutos vistos, me gusta, comentarios y compartidos por día (YouTube tarda 2 o 3 días en cerrarlas).'],
            ['Inbox: comentarios', $c['inbox'] ? true : 'off', 'Comentarios de los videos del canal; se responden como el canal.'],
            ['Inbox: mensajes directos', 'no', 'YouTube no tiene mensajes directos.'],
        ],
        'limits' => [
            'Google da por defecto 10.000 unidades de cuota por día (leer cuesta 1, responder un comentario 50) y 100 subidas de video por día. Más cuota se pide con la auditoría de YouTube.',
            'Mientras el proyecto de Google esté en modo "Prueba", solo conectan las cuentas cargadas como testers y la conexión vence a los 7 días. Para que no venza hay que publicar la app y pasar la verificación de Google.',
            'El número de suscriptores que da YouTube viene redondeado, y si el canal lo oculta no se muestra.',
        ],
        'steps' => [
            'En console.cloud.google.com, crear un proyecto y habilitar "YouTube Data API v3" y "YouTube Analytics API".',
            'Configurar la pantalla de consentimiento de OAuth (externa), con la web y la política de privacidad, y agregar los permisos de la lista.',
            'Crear credenciales "ID de cliente de OAuth" de tipo "Aplicación web" con la URI de redireccionamiento de abajo.',
            'Para usarlo con clientes: publicar la app y pedir la verificación de Google (los permisos de YouTube son sensibles).',
        ],
        'fields' => [],
    ];
}
function ss_youtube_authorize(array $c, string $state, string $challenge, array $scopes): string {
    return ss_youtube_accounts() . '/o/oauth2/v2/auth?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => ss_net_redirect_uri(), 'response_type' => 'code', 'scope' => implode(' ', $scopes),
        'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state], '', '&', PHP_QUERY_RFC3986);
}
function ss_youtube_error(array $r): array {
    $j = $r['json'];
    if (isset($j['error']) && is_array($j['error'])) return [(string)($j['error']['message'] ?? 'Error de Google'), (string)($j['error']['errors'][0]['reason'] ?? $j['error']['status'] ?? '')];
    return [(string)($j['error_description'] ?? ('HTTP ' . $r['code'])), (string)($j['error'] ?? '')];
}
function ss_youtube_token(PDO $db, array $form, ?int $acc, string $action, array $prev = []): array {
    $c = ss_net_cfg('youtube');
    $r = ss_net_req($db, 'youtube', 'POST', ss_youtube_oauth() . '/token', ['form' => $form + ['client_id' => $c['client_id'], 'client_secret' => ss_net_secret('youtube')]], $acc, $action);
    if (empty($r['json']['access_token'])) ss_fail('Google no devolvió el permiso.', 502);
    return ss_net_bundle_from($r['json'], $prev);
}
function ss_youtube_exchange(PDO $db, string $code, string $verifier): array {
    return ss_youtube_token($db, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => ss_net_redirect_uri()], null, 'oauth_code');
}
function ss_youtube_refresh(PDO $db, array $b, int $acc): array { return ss_youtube_token($db, ['grant_type' => 'refresh_token', 'refresh_token' => $b['refresh']], $acc, 'refresh', $b); }
function ss_youtube_revoke(PDO $db, array $b, int $acc): void {
    ss_net_req($db, 'youtube', 'POST', ss_youtube_oauth() . '/revoke', ['form' => ['token' => ($b['refresh'] ?? '') ?: $b['access']]], $acc, 'revoke');
}
function ss_youtube_call(PDO $db, $accOrTok, string $method, string $url, array $o = [], string $action = 'yt'): array {
    $tok = is_array($accOrTok) ? ss_net_token($db, $accOrTok) : (string)$accOrTok;
    $o['headers'] = array_merge(['Authorization: Bearer ' . $tok], (array)($o['headers'] ?? []));
    return ss_net_req($db, 'youtube', $method, $url, $o, is_array($accOrTok) ? (int)$accOrTok['id'] : null, $action);
}
function ss_youtube_channels(PDO $db, $accOrTok): array {
    $r = ss_youtube_call($db, $accOrTok, 'GET', ss_youtube_api() . '/youtube/v3/channels', ['query' => ['part' => 'snippet,statistics,contentDetails', 'mine' => 'true']], 'yt_channels');
    return (array)($r['json']['items'] ?? []);
}
function ss_youtube_candidates(PDO $db, array $b): array {
    if (($b['refresh'] ?? '') === '') ss_log_call($db, null, 'oauth_warn', false, 200, 'Google no mandó refresh token (¿ya había un permiso?): la conexión vence en 1 hora');
    $cands = [];
    foreach (ss_youtube_channels($db, $b['access']) as $ch) {
        $sn = (array)($ch['snippet'] ?? []);
        $cands[] = ['kind' => 'yt_channel', 'ext_id' => (string)$ch['id'], 'name' => (string)($sn['title'] ?? ''), 'username' => ltrim((string)($sn['customUrl'] ?? ''), '@'),
                    'picture' => (string)($sn['thumbnails']['default']['url'] ?? ''), 'page_id' => (string)($ch['contentDetails']['relatedPlaylists']['uploads'] ?? ''), 'can_post' => true];
    }
    if (!$cands) ss_fail('Esa cuenta de Google no tiene canal de YouTube (o no se eligió el canal en la pantalla de Google).', 400);
    return ['user_ext_id' => $cands[0]['ext_id'], 'user_name' => $cands[0]['name'], 'cands' => $cands];
}
function ss_youtube_check(PDO $db, array $a): array {
    $ch = ss_youtube_channels($db, $a)[0] ?? null;
    if (!$ch || (string)$ch['id'] !== $a['ext_id']) ss_fail('El permiso guardado ya no corresponde a este canal: renová la conexión.');
    $sn = (array)$ch['snippet'];
    return ['name' => (string)($sn['title'] ?? ''), 'username' => ltrim((string)($sn['customUrl'] ?? ''), '@'), 'picture' => (string)($sn['thumbnails']['default']['url'] ?? '')];
}

// ── Publishing ─────────────────────────────────────────
const SS_YT_PRIVACY = ['public' => 'Público', 'unlisted' => 'No listado', 'private' => 'Privado'];
function ss_youtube_plan(array $post, array $files, array $opts): array {
    $vids = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['mp4', 'mov', 'webm', 'm4v'], true)));
    if (!$vids) ss_fail('YouTube solo publica videos: falta el video en Archivos.');
    if (!isset(SS_YT_PRIVACY[$opts['privacy'] ?? ''])) ss_fail('Para YouTube falta elegir la privacidad del video.');
    if (!isset($opts['kids'])) ss_fail('Para YouTube hay que indicar si el video es para chicos (lo exige YouTube).');
    if (trim((string)$post['title']) === '') ss_fail('El video necesita un título.');
    return ['kind' => 'video', 'images' => [], 'video' => $vids[0]];
}
/** Uploads the video (resumable, in parts of 64 MB). YouTube processes it afterwards; the id and link exist from the upload. */
function ss_youtube_publish(PDO $db, array $a, array $plan, string $cap, array $post, array $opts): array {
    $path = ss_net_file_path($db, (int)$plan['video']['id']); $size = filesize($path);
    $mime = SS_MIME[$plan['video']['ext']] ?? 'video/*';
    $meta = ['snippet' => ['title' => mb_substr(str_replace(['<', '>'], '', (string)$post['title']), 0, 100), 'description' => mb_substr(str_replace(['<', '>'], '', $cap), 0, 5000), 'categoryId' => '22'],
             'status' => ['privacyStatus' => $opts['privacy'], 'selfDeclaredMadeForKids' => !empty($opts['kids'])]];
    $tags = array_values(array_filter(array_map(fn($t) => ltrim($t, '#'), preg_split('/\s+/', (string)$post['hashtags']))));
    if ($tags) $meta['snippet']['tags'] = array_slice($tags, 0, 30);
    $r = ss_youtube_call($db, $a, 'POST', ss_youtube_api() . '/upload/youtube/v3/videos', ['query' => ['uploadType' => 'resumable', 'part' => 'snippet,status'], 'json' => $meta,
        'headers' => ['X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . $size]], 'yt_upload_start');
    $loc = (string)($r['headers']['location'] ?? '');
    if ($loc === '') ss_fail('YouTube no devolvió dónde subir el video.', 502);
    $chunk = 64 * 1048576; $res = null;
    for ($off = 0; $off < $size; $off += $chunk) {
        $len = min($chunk, $size - $off);
        $res = ss_youtube_call($db, $a, 'PUT', $loc, ['raw' => ss_net_chunk($path, $off, $len), 'timeout' => 900, 'tries' => 2,
            'headers' => ['Content-Type: ' . $mime, 'Content-Length: ' . $len, 'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size]], 'yt_upload');
    }
    $id = (string)($res['json']['id'] ?? '');
    if ($id === '') ss_fail('YouTube no devolvió el id del video.', 502);
    return ['id' => $id, 'link' => 'https://www.youtube.com/watch?v=' . $id];
}

// ── Metrics ────────────────────────────────────────────
const SS_YT_DAY = ['views' => 'views', 'estimatedMinutesWatched' => 'watch_minutes', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'subscribersGained' => 'new_followers'];
function ss_youtube_an_sync(PDO $db, array $a, int $days): array {
    $id = (int)$a['id']; $skipped = [];
    $ch = ss_youtube_channels($db, $a)[0] ?? [];
    if (!empty($ch['statistics']) && empty($ch['statistics']['hiddenSubscriberCount']) && isset($ch['statistics']['subscriberCount'])) ss_an_put($db, $id, date('Y-m-d'), 'followers', $ch['statistics']['subscriberCount']);
    $days = max($days, 5);   // YouTube closes the days 48-72 h later: the last ones are read again
    try {
        $r = ss_youtube_call($db, $a, 'GET', ss_youtube_analytics() . '/v2/reports', ['query' => ['ids' => 'channel==MINE', 'startDate' => date('Y-m-d', strtotime("-$days days")), 'endDate' => date('Y-m-d'),
            'metrics' => implode(',', array_keys(SS_YT_DAY)), 'dimensions' => 'day', 'sort' => 'day']], 'an_yt_day');
        $cols = array_map(fn($h) => (string)$h['name'], (array)($r['json']['columnHeaders'] ?? []));
        foreach ((array)($r['json']['rows'] ?? []) as $row) {
            $v = array_combine($cols, $row) ?: []; $day = (string)($v['day'] ?? ''); if ($day === '') continue;
            foreach (SS_YT_DAY as $k => $m) if (isset($v[$k])) ss_an_put($db, $id, $day, $m, round((float)$v[$k]));
            ss_an_put($db, $id, $day, 'interactions', (int)($v['likes'] ?? 0) + (int)($v['comments'] ?? 0) + (int)($v['shares'] ?? 0));
        }
    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; $skipped[] = 'analytics'; }
    return ['days' => $days, 'media' => ss_youtube_media($db, $a, (string)($ch['contentDetails']['relatedPlaylists']['uploads'] ?? $a['page_id'])), 'skipped' => $skipped];
}
function ss_youtube_media(PDO $db, array $a, string $uploads): int {
    if ($uploads === '') return 0;
    $r = ss_youtube_call($db, $a, 'GET', ss_youtube_api() . '/youtube/v3/playlistItems', ['query' => ['part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 20]], 'an_yt_list');
    $ids = array_values(array_filter(array_map(fn($i) => (string)($i['contentDetails']['videoId'] ?? ''), (array)($r['json']['items'] ?? []))));
    if (!$ids) return 0;
    $v = ss_youtube_call($db, $a, 'GET', ss_youtube_api() . '/youtube/v3/videos', ['query' => ['part' => 'snippet,statistics', 'id' => implode(',', $ids)]], 'an_yt_videos');
    $up = ss_an_media_upsert_stmt($db); $n = 0;
    foreach ((array)($v['json']['items'] ?? []) as $x) {
        $s = (array)($x['statistics'] ?? []); $sn = (array)($x['snippet'] ?? []);
        $mt = ['views' => (int)($s['viewCount'] ?? 0), 'likes' => (int)($s['likeCount'] ?? 0), 'comments' => (int)($s['commentCount'] ?? 0)];
        $mt['interactions'] = $mt['likes'] + $mt['comments'];
        ss_an_media_put($db, $up, (int)$a['id'], (string)$x['id'], 'VIDEO', (string)($sn['title'] ?? ''), 'https://www.youtube.com/watch?v=' . $x['id'], (string)($sn['thumbnails']['medium']['url'] ?? ''),
                        ss_meta_time($sn['publishedAt'] ?? null), $mt);
        $n++;
    }
    return $n;
}

// ── Inbox: comments of the channel ─────────────────────
function ss_youtube_inbox_sync(PDO $db, array $a): int {
    $r = ss_youtube_call($db, $a, 'GET', ss_youtube_api() . '/youtube/v3/commentThreads', ['query' => ['part' => 'snippet,replies', 'allThreadsRelatedToChannelId' => $a['ext_id'], 'maxResults' => 50, 'order' => 'time', 'textFormat' => 'plainText']], 'inbox_yt_comments');
    $n = 0;
    foreach ((array)($r['json']['items'] ?? []) as $th) {
        $top = (array)($th['snippet']['topLevelComment'] ?? []); $ts = (array)($top['snippet'] ?? []);
        $vid = (string)($th['snippet']['videoId'] ?? $ts['videoId'] ?? '');
        $author = (string)($ts['authorChannelId']['value'] ?? '');
        $mine = $author === $a['ext_id'];
        $cid = ss_conv_upsert($db, $a, 'comment', (string)$th['id'], ['contact_id' => $mine ? '' : $author, 'contact_name' => $mine ? '' : (string)($ts['authorDisplayName'] ?? ''), 'contact_pic' => $mine ? '' : (string)($ts['authorProfileImageUrl'] ?? ''),
                               'post_id' => $vid, 'post_link' => $vid !== '' ? 'https://www.youtube.com/watch?v=' . $vid : '']);
        $all = array_merge([$top], array_reverse((array)($th['replies']['comments'] ?? [])));
        foreach ($all as $c) {
            $cs = (array)($c['snippet'] ?? []); $me = (string)($cs['authorChannelId']['value'] ?? '') === $a['ext_id'];
            if (ss_msg_store($db, $cid, $me ? 'out' : 'in', (string)($c['id'] ?? ''), (string)($cs['textOriginal'] ?? $cs['textDisplay'] ?? ''), [], (string)($cs['authorDisplayName'] ?? ''), ss_meta_time($cs['publishedAt'] ?? null))) $n++;
        }
    }
    return $n;
}
/** Reply as the channel (comments.insert: 50 quota units). thread = id of the thread = id of its top comment. */
function ss_youtube_reply(PDO $db, array $a, array $c, string $text): string {
    $r = ss_youtube_call($db, $a, 'POST', ss_youtube_api() . '/youtube/v3/comments', ['query' => ['part' => 'snippet'], 'json' => ['snippet' => ['parentId' => $c['thread'], 'textOriginal' => $text]], 'tries' => 1], 'inbox_reply_yt');
    return (string)($r['json']['id'] ?? '');
}
