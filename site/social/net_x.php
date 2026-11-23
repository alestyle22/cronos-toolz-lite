<?php
/**
 * social/net_x.php — X connector (API v2, OAuth 2.0 with PKCE). Official API only.
 * Verified against docs.x.com on 2026-11-17:
 *  - X charges per use (prepaid credits): reading a post 0.005 USD, a user 0.010, creating a post 0.015 (0.20 if it has a link), replying
 *    to someone who mentioned you 0.010. That is why metrics and mentions are read once a day and only what is needed; it is said on screen.
 *  - Replying by API is only allowed when the author mentioned or quoted the account ("summoned"): the Inbox only brings mentions and
 *    only replies to them. Likes, follows and quotes by API were removed from self-service plans.
 *  - Code of authorization valid for 30 seconds: it is exchanged on the return itself. Access 2 h; refresh with offline.access (kept the last one).
 *  - Media: POST /2/media/upload (images) and initialize/append/finalize (video). The v1.1 upload is not used.
 *  - Direct messages: the API has them, but they are read and paid per event; they are off unless the admin turns them on (DM option).
 */

function ss_x_api(): string { return ss_net_url('x', 'api', 'https://api.x.com'); }
function ss_x_www(): string { return ss_net_url('x', 'www', 'https://x.com'); }

function ss_x_scopes(array $c): array {
    $s = ['tweet.read', 'users.read', 'offline.access'];
    if ($c['publish']) array_push($s, 'tweet.write', 'media.write');
    if ($c['inbox'] && !in_array('tweet.write', $s, true)) $s[] = 'tweet.write';
    if (!empty($c['extra']['dm'])) array_push($s, 'dm.read', 'dm.write');
    return $s;
}
function ss_x_info(array $c): array {
    return [
        'console' => 'https://console.x.com',
        'scope_info' => ['tweet.read' => 'Leer posts', 'users.read' => 'Ver el perfil y los seguidores', 'offline.access' => 'Mantener la conexión sin volver a pedir permiso',
                         'tweet.write' => 'Publicar y responder', 'media.write' => 'Subir imágenes y videos', 'dm.read' => 'Leer mensajes directos', 'dm.write' => 'Enviar mensajes directos'],
        'caps' => [
            ['Publicar', $c['publish'] ? true : 'off', 'Texto (280 caracteres sin Premium), hasta 4 imágenes o 1 video.'],
            ['Métricas', $c['analytics'] ? true : 'off', 'Seguidores del día y números de los últimos posts (impresiones, me gusta, respuestas, reposteos, clics de los últimos 30 días).'],
            ['Inbox: menciones', $c['inbox'] ? true : 'off', 'Posts que mencionan a la cuenta; X solo deja responder por API a quien te mencionó.'],
            ['Inbox: mensajes directos', !empty($c['extra']['dm']) ? true : 'off', 'La API los tiene pero cobra cada mensaje leído y enviado: se activan aparte.'],
        ],
        'limits' => [
            'X cobra por uso con créditos que se cargan en la consola de X (por ejemplo: crear un post 0,015 USD, con link 0,20 USD; leer un post 0,005 USD). Social Studio lee métricas y menciones una vez por día para gastar poco.',
            'Responder por API solo está permitido a posts que mencionan o citan a la cuenta. Dar me gusta, seguir y citar ya no se permiten por API.',
            'Las métricas privadas (impresiones orgánicas, clics) solo existen para posts de los últimos 30 días.',
        ],
        'steps' => [
            'En console.x.com, crear un proyecto y una app, y cargar créditos (X cobra por uso).',
            'En la configuración de autenticación de la app: tipo "Web App" (cliente confidencial), permisos de lectura y escritura (y mensajes directos si se van a usar).',
            'Cargar la URI de redireccionamiento de abajo (X la exige idéntica) y la web del sitio.',
            'Copiar el Client ID y el Client Secret de OAuth 2.0 (no las claves de OAuth 1.0a).',
        ],
        'fields' => [['id' => 'dm', 'type' => 'check', 'label' => 'Usar mensajes directos en el Inbox (X cobra cada mensaje leído y enviado)'],
                     ['id' => 'long', 'type' => 'check', 'label' => 'Las cuentas tienen Premium (posts de más de 280 caracteres)']],
    ];
}
function ss_x_authorize(array $c, string $state, string $challenge, array $scopes): string {
    return ss_x_www() . '/i/oauth2/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => ss_net_redirect_uri(), 'scope' => implode(' ', $scopes),
        'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
}
function ss_x_error(array $r): array {
    $j = $r['json'];
    if (isset($j['errors'][0])) return [(string)($j['errors'][0]['message'] ?? $j['errors'][0]['detail'] ?? 'Error de X'), (string)($j['errors'][0]['type'] ?? $j['errors'][0]['code'] ?? '')];
    return [(string)($j['detail'] ?? $j['error_description'] ?? $j['title'] ?? ('HTTP ' . $r['code'])), (string)($j['type'] ?? $j['error'] ?? '')];
}
function ss_x_token(PDO $db, array $form, ?int $acc, string $action, array $prev = []): array {
    $c = ss_net_cfg('x');
    $r = ss_net_req($db, 'x', 'POST', ss_x_api() . '/2/oauth2/token', ['form' => $form, 'basic' => [$c['client_id'], ss_net_secret('x')]], $acc, $action);
    if (empty($r['json']['access_token'])) ss_fail('X no devolvió el permiso.', 502);
    return ss_net_bundle_from($r['json'], $prev);
}
function ss_x_exchange(PDO $db, string $code, string $verifier): array {
    return ss_x_token($db, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => ss_net_redirect_uri(), 'code_verifier' => $verifier], null, 'oauth_code');
}
function ss_x_refresh(PDO $db, array $b, int $acc): array { return ss_x_token($db, ['grant_type' => 'refresh_token', 'refresh_token' => $b['refresh']], $acc, 'refresh', $b); }
function ss_x_revoke(PDO $db, array $b, int $acc): void {
    $c = ss_net_cfg('x');
    foreach (array_filter([$b['refresh'] ?? '', $b['access']]) as $t) ss_net_req($db, 'x', 'POST', ss_x_api() . '/2/oauth2/revoke', ['form' => ['token' => $t], 'basic' => [$c['client_id'], ss_net_secret('x')]], $acc, 'revoke');
}
function ss_x_call(PDO $db, $accOrTok, string $method, string $path, array $o = [], string $action = 'x'): array {
    $tok = is_array($accOrTok) ? ss_net_token($db, $accOrTok) : (string)$accOrTok;
    $o['headers'] = array_merge(['Authorization: Bearer ' . $tok], (array)($o['headers'] ?? []));
    return ss_net_req($db, 'x', $method, ss_x_api() . '/2/' . ltrim($path, '/'), $o, is_array($accOrTok) ? (int)$accOrTok['id'] : null, $action);
}
/**
 * Reads posts. X's current reference calls the fields parameter "post.fields" and the older docs "tweet.fields": it tries the one
 * that worked last time and, if X rejects the parameter (400), the other one, and remembers it.
 */
function ss_x_posts_get(PDO $db, array $a, string $path, array $q, string $fields, string $action): array {
    $key = (string)g7_cfg('ss_x_fields_param', 'tweet.fields');
    try { return ss_x_call($db, $a, 'GET', $path, ['query' => $q + [$key => $fields]], $action); }
    catch (SsNetError $e) {
        if ($e->http !== 400) throw $e;
        $other = $key === 'tweet.fields' ? 'post.fields' : 'tweet.fields';
        $r = ss_x_call($db, $a, 'GET', $path, ['query' => $q + [$other => $fields]], $action);
        g7_cfg_set('ss_x_fields_param', $other);
        return $r;
    }
}
function ss_x_me(PDO $db, $accOrTok): array {
    return (array)(ss_x_call($db, $accOrTok, 'GET', 'users/me', ['query' => ['user.fields' => 'public_metrics,profile_image_url,username,name']], 'x_me')['json']['data'] ?? []);
}
function ss_x_candidates(PDO $db, array $b): array {
    $u = ss_x_me($db, $b['access']);
    if (empty($u['id'])) ss_fail('X no devolvió la cuenta.', 502);
    return ['user_ext_id' => (string)$u['id'], 'user_name' => '@' . ($u['username'] ?? ''),
            'cands' => [['kind' => 'x_user', 'ext_id' => (string)$u['id'], 'name' => (string)($u['name'] ?? ''), 'username' => (string)($u['username'] ?? ''), 'picture' => (string)($u['profile_image_url'] ?? ''), 'can_post' => true]]];
}
function ss_x_check(PDO $db, array $a): array {
    $u = ss_x_me($db, $a);
    return ['name' => (string)($u['name'] ?? ''), 'username' => (string)($u['username'] ?? ''), 'picture' => (string)($u['profile_image_url'] ?? '')];
}

// ── Publishing ─────────────────────────────────────────
function ss_x_plan(array $post, array $files, array $opts): array {
    $imgs = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)));
    $vids = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['mp4', 'mov'], true)));
    $fmt = $post['format'];
    $cap = ss_caption($post);
    if ($fmt === 'historia') ss_fail('X no tiene historias.');
    if (in_array($fmt, ['video', 'reel', 'video_corto'], true) || ($fmt === 'personalizado' && $vids && !$imgs)) {
        if (!$vids) ss_fail('Para X falta el video (MP4 o MOV) en Archivos.');
        $plan = ['kind' => 'video', 'images' => [], 'video' => $vids[0]];
    } elseif ($fmt === 'texto' || !$imgs) {
        if ($cap === '') ss_fail('Falta el texto del post para X.');
        $plan = ['kind' => 'text', 'images' => [], 'video' => null];
    } else {
        if (count($imgs) > 4) ss_fail('X admite hasta 4 imágenes por post: dejá 4 o publicá en dos posts.');
        foreach ($imgs as $f) if ($f['size'] > 5 * 1048576) ss_fail('X acepta imágenes de hasta 5 MB: "' . $f['name'] . '" pesa más.');
        $plan = ['kind' => count($imgs) > 1 ? 'carousel' : 'image', 'images' => $imgs, 'video' => null];
    }
    if (ss_x_len($cap) > 280 && empty(ss_net_cfg('x')['extra']['long'])) ss_fail('El texto pasa los 280 caracteres que X permite sin Premium (' . ss_x_len($cap) . '). Acortá el copy o los hashtags.');
    return $plan;
}
/** Length as X counts it: links count 23. */
function ss_x_len(string $t): int { return mb_strlen(preg_replace('#https?://\S+#u', str_repeat('x', 23), $t)); }
function ss_x_upload(PDO $db, array $a, array $f, string $cat): string {
    $path = ss_net_file_path($db, (int)$f['id']); $size = filesize($path);
    $mime = SS_MIME[$f['ext']] ?? 'application/octet-stream';
    if ($cat === 'tweet_image') {
        $r = ss_x_call($db, $a, 'POST', 'media/upload', ['raw' => ss_x_multipart(['media_category' => $cat], $path, $mime, $b), 'headers' => ['Content-Type: multipart/form-data; boundary=' . $b], 'timeout' => 120], 'x_media');
        return (string)($r['json']['data']['id'] ?? '');
    }
    $r = ss_x_call($db, $a, 'POST', 'media/upload/initialize', ['json' => ['media_type' => $mime, 'total_bytes' => $size, 'media_category' => $cat]], 'x_media_init');
    $mid = (string)($r['json']['data']['id'] ?? ''); if ($mid === '') ss_fail('X no devolvió el id del video.', 502);
    $seg = 4 * 1048576;
    for ($i = 0, $off = 0; $off < $size; $i++, $off += $seg) {
        $tmp = tempnam(sys_get_temp_dir(), 'ssx'); file_put_contents($tmp, ss_net_chunk($path, $off, min($seg, $size - $off)));
        try { ss_x_call($db, $a, 'POST', 'media/upload/' . rawurlencode($mid) . '/append', ['raw' => ss_x_multipart(['segment_index' => (string)$i], $tmp, 'application/octet-stream', $b), 'headers' => ['Content-Type: multipart/form-data; boundary=' . $b], 'timeout' => 300, 'tries' => 2], 'x_media_append'); }
        finally { @unlink($tmp); }
    }
    ss_x_call($db, $a, 'POST', 'media/upload/' . rawurlencode($mid) . '/finalize', [], 'x_media_final');
    return $mid;
}
function ss_x_multipart(array $fields, string $file, string $mime, ?string &$b): string {
    $b = 'ss' . bin2hex(random_bytes(12)); $o = '';
    foreach ($fields as $k => $v) $o .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    return $o . "--$b\r\nContent-Disposition: form-data; name=\"media\"; filename=\"media\"\r\nContent-Type: $mime\r\n\r\n" . file_get_contents($file) . "\r\n--$b--\r\n";
}
function ss_x_tweet(PDO $db, array $a, string $text, array $media): array {
    $body = ['text' => $text]; if ($media) $body['media'] = ['media_ids' => array_values($media)];
    $r = ss_x_call($db, $a, 'POST', 'tweets', ['json' => $body, 'tries' => 1], 'x_post');
    $id = (string)($r['json']['data']['id'] ?? ''); if ($id === '') ss_fail('X no devolvió el id del post.', 502);
    return ['id' => $id, 'link' => 'https://x.com/' . ($a['username'] ? rawurlencode($a['username']) : 'i/web') . '/status/' . $id];
}
/** Images go up and the post goes out at once; a video waits until X processes it (poll). */
function ss_x_publish(PDO $db, array $a, array $plan, string $cap, array $post, array $opts): array {
    if ($plan['kind'] === 'video') return ['pending' => ss_x_upload($db, $a, $plan['video'], 'tweet_video')];
    $ids = []; foreach ($plan['images'] as $f) $ids[] = ss_x_upload($db, $a, $f, 'tweet_image');
    return ss_x_tweet($db, $a, $cap, $ids);
}
function ss_x_poll(PDO $db, array $a, string $mid, array $plan, string $cap, array $post): ?array {
    $r = ss_x_call($db, $a, 'GET', 'media/upload', ['query' => ['command' => 'STATUS', 'media_id' => $mid]], 'x_media_status');
    $pi = (array)($r['json']['data']['processing_info'] ?? []);
    $st = (string)($pi['state'] ?? 'succeeded');
    if ($st === 'failed') ss_fail('X no pudo procesar el video: ' . (string)($pi['error']['message'] ?? 'revisá el formato (MP4 H.264) y la duración.'));
    if ($st !== 'succeeded') return null;
    return ss_x_tweet($db, $a, $cap, [$mid]);
}

// ── Metrics (one read a day: X charges per post read) ──
function ss_x_an_sync(PDO $db, array $a, int $days): array {
    $id = (int)$a['id'];
    $u = ss_x_me($db, $a);
    if (isset($u['public_metrics']['followers_count'])) ss_an_put($db, $id, date('Y-m-d'), 'followers', $u['public_metrics']['followers_count']);
    $f = 'created_at,public_metrics,non_public_metrics,attachments';
    $r = ss_x_posts_get($db, $a, 'users/' . rawurlencode($a['ext_id']) . '/tweets', ['max_results' => 20, 'exclude' => 'retweets,replies'], $f, 'an_x_posts');
    $up = ss_an_media_upsert_stmt($db); $n = 0;
    foreach ((array)($r['json']['data'] ?? []) as $t) {
        $p = (array)($t['public_metrics'] ?? []); $np = (array)($t['non_public_metrics'] ?? []);
        $mt = ['views' => (int)($np['impression_count'] ?? $p['impression_count'] ?? 0), 'likes' => (int)($p['like_count'] ?? 0), 'comments' => (int)($p['reply_count'] ?? 0),
               'shares' => (int)($p['retweet_count'] ?? 0) + (int)($p['quote_count'] ?? 0), 'saves' => (int)($p['bookmark_count'] ?? 0)];
        if (isset($np['url_link_clicks'])) $mt['link_clicks'] = (int)$np['url_link_clicks'];
        $mt['interactions'] = $mt['likes'] + $mt['comments'] + $mt['shares'] + $mt['saves'];
        ss_an_media_put($db, $up, $id, (string)$t['id'], !empty($t['attachments']['media_keys']) ? 'MEDIA' : 'POST', (string)($t['text'] ?? ''), 'https://x.com/' . rawurlencode((string)$a['username']) . '/status/' . $t['id'], '',
                        ss_meta_time($t['created_at'] ?? null), $mt);
        $n++;
    }
    return ['days' => 1, 'media' => $n, 'skipped' => []];
}

// ── Inbox: mentions (and DMs if turned on) ─────────────
function ss_x_inbox_sync(PDO $db, array $a): int {
    $n = 0; $since = (string)g7_cfg('ss_x_since_' . (int)$a['id'], '');
    $q = ['max_results' => 50, 'expansions' => 'author_id', 'user.fields' => 'username,name,profile_image_url'];
    if ($since !== '') $q['since_id'] = $since;
    $r = ss_x_posts_get($db, $a, 'users/' . rawurlencode($a['ext_id']) . '/mentions', $q, 'created_at,conversation_id,in_reply_to_user_id', 'inbox_x_mentions');
    $users = []; foreach ((array)($r['json']['includes']['users'] ?? []) as $u) $users[(string)$u['id']] = $u;
    $max = $since;
    foreach (array_reverse((array)($r['json']['data'] ?? [])) as $t) {
        $u = $users[(string)($t['author_id'] ?? '')] ?? [];
        if ((string)($t['author_id'] ?? '') === $a['ext_id']) continue;
        // Each mention is its own thread (the reply goes to that post: X only lets reply to whoever mentioned the account)
        $cid = ss_conv_upsert($db, $a, 'comment', (string)$t['id'], ['contact_id' => (string)($t['author_id'] ?? ''), 'contact_name' => (string)($u['name'] ?? ''), 'contact_username' => (string)($u['username'] ?? ''),
                               'contact_pic' => (string)($u['profile_image_url'] ?? ''), 'post_id' => (string)$t['id'], 'post_link' => 'https://x.com/' . rawurlencode((string)($u['username'] ?? 'i')) . '/status/' . $t['id']]);
        if (ss_msg_store($db, $cid, 'in', (string)$t['id'], (string)($t['text'] ?? ''), [], '@' . ($u['username'] ?? ''), ss_meta_time($t['created_at'] ?? null))) $n++;
        if (strlen((string)$t['id']) > strlen($max) || (strlen((string)$t['id']) === strlen($max) && strcmp((string)$t['id'], $max) > 0)) $max = (string)$t['id'];
    }
    if ($max !== $since) g7_cfg_set('ss_x_since_' . (int)$a['id'], $max);
    if (!empty(ss_net_cfg('x')['extra']['dm'])) $n += ss_x_dm_sync($db, $a);
    return $n;
}
function ss_x_dm_sync(PDO $db, array $a): int {
    $r = ss_x_call($db, $a, 'GET', 'dm_events', ['query' => ['max_results' => 50, 'event_types' => 'MessageCreate', 'dm_event.fields' => 'id,text,created_at,sender_id,dm_conversation_id,participant_ids', 'expansions' => 'sender_id', 'user.fields' => 'username,name,profile_image_url']], 'inbox_x_dm');
    $users = []; foreach ((array)($r['json']['includes']['users'] ?? []) as $u) $users[(string)$u['id']] = $u;
    $n = 0;
    foreach (array_reverse((array)($r['json']['data'] ?? [])) as $e) {
        $sender = (string)($e['sender_id'] ?? ''); $out = $sender === $a['ext_id'];
        $other = $out ? (string)(array_values(array_diff((array)($e['participant_ids'] ?? []), [$a['ext_id']]))[0] ?? '') : $sender;
        if ($other === '') continue;
        $u = $users[$other] ?? [];
        $cid = ss_conv_upsert($db, $a, 'dm', $other, ['contact_id' => $other, 'contact_name' => (string)($u['name'] ?? ''), 'contact_username' => (string)($u['username'] ?? ''), 'contact_pic' => (string)($u['profile_image_url'] ?? '')]);
        if (ss_msg_store($db, $cid, $out ? 'out' : 'in', (string)$e['id'], (string)($e['text'] ?? ''), [], $out ? '' : '@' . ($u['username'] ?? ''), ss_meta_time($e['created_at'] ?? null))) $n++;
    }
    return $n;
}
function ss_x_reply(PDO $db, array $a, array $c, string $text): string {
    if ($c['kind'] === 'dm') {
        if (empty(ss_net_cfg('x')['extra']['dm'])) ss_fail('Los mensajes directos de X están apagados en Cuentas › X.');
        $r = ss_x_call($db, $a, 'POST', 'dm_conversations/with/' . rawurlencode($c['thread']) . '/messages', ['json' => ['text' => $text], 'tries' => 1], 'inbox_reply_x_dm');
        return (string)($r['json']['data']['dm_event_id'] ?? '');
    }
    if (ss_x_len($text) > 280) ss_fail('La respuesta pasa los 280 caracteres de X.');
    $r = ss_x_call($db, $a, 'POST', 'tweets', ['json' => ['text' => $text, 'reply' => ['in_reply_to_tweet_id' => $c['thread']]], 'tries' => 1], 'inbox_reply_x');
    return (string)($r['json']['data']['id'] ?? '');
}
