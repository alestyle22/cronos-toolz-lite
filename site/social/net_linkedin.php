<?php
/**
 * social/net_linkedin.php — LinkedIn connector (Community Management API, company pages). Official API only.
 * Verified against learn.microsoft.com/linkedin on 2026-11-17 (version 202611):
 *  - OAuth 2.0 3-legged; access token of 60 days; refresh token (365 days, fixed) only for approved partners: when there is none,
 *    Social Studio warns a week before so the connection is renewed (LinkedIn renews it without asking again if the person is logged in).
 *  - Pages the person administers (organizationAcls, role ADMINISTRATOR). Each page is an account.
 *  - Publishing as the page: text, one image or one video (posts + images/videos API). Comments of the page's posts → Inbox, and replies as the page.
 *  - Metrics: followers (networkSizes), new followers per day, impressions, unique impressions, clicks, likes, comments, shares per day.
 *  - NO API for page direct messages: said on screen.
 */

function ss_linkedin_api(): string { return ss_net_url('linkedin', 'api', 'https://api.linkedin.com'); }
function ss_linkedin_www(): string { return ss_net_url('linkedin', 'www', 'https://www.linkedin.com'); }
function ss_linkedin_version(): string { $v = (string)(ss_net_cfg('linkedin')['extra']['version'] ?? ''); return preg_match('/^20\d{4}$/', $v) ? $v : '202611'; }

function ss_linkedin_scopes(array $c): array {
    $s = ['rw_organization_admin', 'r_organization_social'];
    if ($c['publish'] || $c['inbox']) $s[] = 'w_organization_social';
    return $s;
}
function ss_linkedin_info(array $c): array {
    return [
        'console' => 'https://www.linkedin.com/developers/apps',
        'scope_info' => ['rw_organization_admin' => 'Ver las páginas que administrás y sus estadísticas', 'r_organization_social' => 'Leer las publicaciones y comentarios de la página',
                         'w_organization_social' => 'Publicar y responder comentarios como la página'],
        'caps' => [
            ['Publicar como la página', $c['publish'] ? true : 'off', 'Texto, una imagen (JPG, PNG o GIF) o un video MP4 (3 s a 30 min, hasta 500 MB).'],
            ['Métricas', $c['analytics'] ? true : 'off', 'Seguidores, seguidores nuevos, impresiones, impresiones únicas, clics, me gusta, comentarios y compartidos por día.'],
            ['Inbox: comentarios', $c['inbox'] ? true : 'off', 'Comentarios de las publicaciones de la página; se responden como la página.'],
            ['Inbox: mensajes directos', 'no', 'LinkedIn no tiene API para los mensajes de las páginas.'],
        ],
        'limits' => [
            'Community Management API es un producto que LinkedIn aprueba: primero el nivel de desarrollo (con límites bajos de uso) y después el estándar, con un video de la integración.',
            'La conexión dura 60 días. Si LinkedIn no le dio a la app renovación automática, Social Studio avisa una semana antes para renovarla.',
            'Solo se ven las páginas donde quien conecta es Administrador.',
        ],
        'steps' => [
            'En linkedin.com/developers, crear una app nueva (sin otros productos), asociada a la página de LinkedIn de la agencia, y que un superadministrador de esa página la verifique.',
            'En Products, pedir Community Management API (nivel de desarrollo). LinkedIn pide empresa registrada, mail corporativo, política de privacidad y web.',
            'En Auth, cargar la URI de redireccionamiento de abajo y revisar que figuren los permisos de la lista.',
            'Cuando todo funcione, pedir el nivel estándar con el video que LinkedIn solicita.',
        ],
        'fields' => [['id' => 'version', 'type' => 'text', 'label' => 'Versión de la API (avanzado)', 'placeholder' => '202611', 'pattern' => '/^20\d{4}$/', 'error' => 'La versión de LinkedIn se escribe AAAAMM, por ejemplo 202611.']],
    ];
}
function ss_linkedin_authorize(array $c, string $state, string $challenge, array $scopes): string {
    return ss_linkedin_www() . '/oauth/v2/authorization?' . http_build_query(['response_type' => 'code', 'client_id' => $c['client_id'], 'redirect_uri' => ss_net_redirect_uri(), 'state' => $state, 'scope' => implode(' ', $scopes)], '', '&', PHP_QUERY_RFC3986);
}
function ss_linkedin_error(array $r): array {
    $j = $r['json'];
    return [(string)($j['message'] ?? $j['error_description'] ?? ('HTTP ' . $r['code'])), (string)($j['code'] ?? $j['serviceErrorCode'] ?? $j['error'] ?? '')];
}
function ss_linkedin_token(PDO $db, array $form, ?int $acc, string $action, array $prev = []): array {
    $c = ss_net_cfg('linkedin');
    $r = ss_net_req($db, 'linkedin', 'POST', ss_linkedin_www() . '/oauth/v2/accessToken', ['form' => $form + ['client_id' => $c['client_id'], 'client_secret' => ss_net_secret('linkedin')]], $acc, $action);
    if (empty($r['json']['access_token'])) ss_fail('LinkedIn no devolvió el permiso.', 502);
    return ss_net_bundle_from($r['json'], $prev);
}
function ss_linkedin_exchange(PDO $db, string $code, string $verifier): array {
    return ss_linkedin_token($db, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => ss_net_redirect_uri()], null, 'oauth_code');
}
function ss_linkedin_refresh(PDO $db, array $b, int $acc): array {
    return ss_linkedin_token($db, ['grant_type' => 'refresh_token', 'refresh_token' => $b['refresh']], $acc, 'refresh', $b);
}
/** Versioned REST call with the account (or a bare token). */
function ss_linkedin_call(PDO $db, $accOrTok, string $method, string $path, array $o = [], string $action = 'li'): array {
    $tok = is_array($accOrTok) ? ss_net_token($db, $accOrTok) : (string)$accOrTok;
    $o['headers'] = array_merge(['Authorization: Bearer ' . $tok, 'Linkedin-Version: ' . ss_linkedin_version(), 'X-Restli-Protocol-Version: 2.0.0'], (array)($o['headers'] ?? []));
    return ss_net_req($db, 'linkedin', $method, ss_linkedin_api() . '/rest/' . ltrim($path, '/'), $o, is_array($accOrTok) ? (int)$accOrTok['id'] : null, $action);
}
function ss_li_urn_id(string $urn): string { return (string)substr($urn, strrpos($urn, ':') + 1); }
/** Logo of a page: logoV2.original is a digitalmediaAsset; the Images API gives its download link (it expires: it is refreshed on every check). */
function ss_linkedin_logo(PDO $db, $accOrTok, array $org): string {
    $asset = (string)($org['logoV2']['cropped'] ?? $org['logoV2']['original'] ?? '');
    if ($asset === '') return '';
    try { $r = ss_linkedin_call($db, $accOrTok, 'GET', 'images/' . rawurlencode('urn:li:image:' . ss_li_urn_id($asset)), [], 'li_logo'); return (string)($r['json']['downloadUrl'] ?? ''); }
    catch (SsError $e) { return ''; }
}
function ss_linkedin_candidates(PDO $db, array $b): array {
    $cands = []; $start = 0; $person = ''; $guard = 0;
    do {
        $r = ss_linkedin_call($db, $b['access'], 'GET', 'organizationAcls', ['query' => ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'count' => 50, 'start' => $start]], 'oauth_orgs');
        $els = (array)($r['json']['elements'] ?? []);
        foreach ($els as $e) {
            $person = $person ?: (string)($e['roleAssignee'] ?? '');
            $urn = (string)($e['organization'] ?? $e['organizationTarget'] ?? '');
            if (!str_starts_with($urn, 'urn:li:organization:')) continue;
            $id = ss_li_urn_id($urn);
            try { $o = ss_linkedin_call($db, $b['access'], 'GET', 'organizations/' . rawurlencode($id), [], 'oauth_org')['json']; } catch (SsError $x) { $o = []; }
            $cands[] = ['kind' => 'li_org', 'ext_id' => $id, 'name' => (string)($o['localizedName'] ?? ('Página ' . $id)), 'username' => (string)($o['vanityName'] ?? ''),
                        'picture' => $o ? ss_linkedin_logo($db, $b['access'], $o) : '', 'can_post' => true];
        }
        $start += 50;
    } while (count($els) === 50 && ++$guard < 6);
    return ['user_ext_id' => $person !== '' ? ss_li_urn_id($person) : substr(hash('sha256', $b['access']), 0, 16), 'user_name' => 'tu cuenta de LinkedIn', 'cands' => $cands];
}
function ss_linkedin_check(PDO $db, array $a): array {
    $o = ss_linkedin_call($db, $a, 'GET', 'organizations/' . rawurlencode($a['ext_id']), [], 'check')['json'];
    return ['name' => (string)($o['localizedName'] ?? ''), 'username' => (string)($o['vanityName'] ?? ''), 'picture' => ss_linkedin_logo($db, $a, $o)];
}

// ── Publishing ─────────────────────────────────────────
/** "Little text format": these characters go escaped or LinkedIn reads them as markup and cuts the text. */
function ss_li_text(string $t): string { return preg_replace('/([|{}@\[\]()<>#\\\\*_~])/', '\\\\$1', $t); }
function ss_linkedin_plan(array $post, array $files, array $opts): array {
    $imgs = array_values(array_filter($files, fn($f) => in_array($f['ext'], ['jpg', 'jpeg', 'png', 'gif'], true)));
    $vids = array_values(array_filter($files, fn($f) => $f['ext'] === 'mp4'));
    $fmt = $post['format'];
    if ($fmt === 'carrusel') ss_fail('LinkedIn: desde Social Studio se publica una imagen o un video por publicación (no carrusel).');
    if ($fmt === 'historia') ss_fail('LinkedIn no tiene historias.');
    if (in_array($fmt, ['video', 'reel', 'video_corto'], true) || ($fmt === 'personalizado' && $vids)) {
        if (!$vids) ss_fail('Para LinkedIn falta el video MP4 en Archivos.');
        if ($vids[0]['size'] > 500 * 1048576) ss_fail('LinkedIn acepta videos de hasta 500 MB.');
        return ['kind' => 'video', 'images' => [], 'video' => $vids[0]];
    }
    if ($fmt === 'texto' || !$imgs) {
        if (trim((string)$post['copy']) === '') ss_fail('Falta el copy de la publicación para LinkedIn.');
        return ['kind' => 'text', 'images' => [], 'video' => null];
    }
    return ['kind' => 'image', 'images' => [$imgs[0]], 'video' => null];
}
function ss_linkedin_post(PDO $db, array $a, string $cap, ?array $media): string {
    $body = ['author' => 'urn:li:organization:' . $a['ext_id'], 'commentary' => ss_li_text($cap), 'visibility' => 'PUBLIC',
             'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []], 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
    if ($media) $body['content'] = ['media' => $media];
    $r = ss_linkedin_call($db, $a, 'POST', 'posts', ['json' => $body, 'tries' => 1], 'li_post');
    $id = (string)($r['headers']['x-restli-id'] ?? $r['json']['id'] ?? '');
    if ($id === '') ss_fail('LinkedIn no devolvió el id de la publicación.', 502);
    return $id;
}
/** Text and image are published right away; a video is uploaded, finalized and posted when LinkedIn says it is AVAILABLE (poll). */
function ss_linkedin_publish(PDO $db, array $a, array $plan, string $cap, array $post, array $opts): array {
    $owner = 'urn:li:organization:' . $a['ext_id'];
    if ($plan['kind'] === 'text') { $id = ss_linkedin_post($db, $a, $cap, null); return ['id' => $id, 'link' => 'https://www.linkedin.com/feed/update/' . $id . '/']; }
    if ($plan['kind'] === 'image') {
        $f = $plan['images'][0];
        $r = ss_linkedin_call($db, $a, 'POST', 'images?action=initializeUpload', ['json' => ['initializeUploadRequest' => ['owner' => $owner]]], 'li_img_init');
        $up = (string)($r['json']['value']['uploadUrl'] ?? ''); $urn = (string)($r['json']['value']['image'] ?? '');
        if ($up === '' || $urn === '') ss_fail('LinkedIn no devolvió dónde subir la imagen.', 502);
        ss_net_req($db, 'linkedin', 'PUT', $up, ['raw' => (string)file_get_contents(ss_net_file_path($db, (int)$f['id'])), 'headers' => ['Authorization: Bearer ' . ss_net_token($db, $a), 'Content-Type: application/octet-stream'], 'timeout' => 300], (int)$a['id'], 'li_img_up');
        $id = ss_linkedin_post($db, $a, $cap, ['id' => $urn, 'altText' => mb_substr((string)$post['title'], 0, 300)]);
        return ['id' => $id, 'link' => 'https://www.linkedin.com/feed/update/' . $id . '/'];
    }
    $path = ss_net_file_path($db, (int)$plan['video']['id']); $size = filesize($path);
    $r = ss_linkedin_call($db, $a, 'POST', 'videos?action=initializeUpload', ['json' => ['initializeUploadRequest' => ['owner' => $owner, 'fileSizeBytes' => $size, 'uploadCaptions' => false, 'uploadThumbnail' => false]]], 'li_vid_init');
    $v = (array)($r['json']['value'] ?? []); $urn = (string)($v['video'] ?? ''); $etags = [];
    foreach ((array)($v['uploadInstructions'] ?? []) as $ins) {
        $from = (int)$ins['firstByte']; $len = (int)$ins['lastByte'] - $from + 1;
        $u = ss_net_req($db, 'linkedin', 'PUT', (string)$ins['uploadUrl'], ['raw' => ss_net_chunk($path, $from, $len), 'headers' => ['Content-Type: application/octet-stream'], 'timeout' => 600, 'tries' => 2], (int)$a['id'], 'li_vid_part');
        $etags[] = trim((string)($u['headers']['etag'] ?? ''), '"');
    }
    if ($urn === '' || !$etags) ss_fail('LinkedIn no devolvió cómo subir el video.', 502);
    ss_linkedin_call($db, $a, 'POST', 'videos?action=finalizeUpload', ['json' => ['finalizeUploadRequest' => ['video' => $urn, 'uploadToken' => (string)($v['uploadToken'] ?? ''), 'uploadedPartIds' => $etags]]], 'li_vid_final');
    return ['pending' => $urn];
}
function ss_linkedin_poll(PDO $db, array $a, string $urn, array $plan, string $cap, array $post): ?array {
    $r = ss_linkedin_call($db, $a, 'GET', 'videos/' . rawurlencode($urn), [], 'li_vid_status');
    $st = (string)($r['json']['status'] ?? '');
    if ($st === 'PROCESSING_FAILED') ss_fail('LinkedIn no pudo procesar el video (revisá que sea MP4 de 3 s a 30 min).');
    if ($st !== 'AVAILABLE') return null;
    $id = ss_linkedin_post($db, $a, $cap, ['id' => $urn, 'title' => mb_substr((string)$post['title'], 0, 200)]);
    return ['id' => $id, 'link' => 'https://www.linkedin.com/feed/update/' . $id . '/'];
}

// ── Metrics ────────────────────────────────────────────
const SS_LI_DAY = ['impressionCount' => 'views', 'uniqueImpressionsCount' => 'reach', 'uniqueImpressionsCounts' => 'reach', 'clickCount' => 'link_clicks', 'likeCount' => 'likes', 'commentCount' => 'comments', 'shareCount' => 'shares'];
function ss_li_intervals(int $days): string {
    $end = strtotime(date('Y-m-d')) * 1000; $start = $end - $days * 86400000;
    return '(timeRange:(start:' . $start . ',end:' . $end . '),timeGranularityType:DAY)';
}
function ss_linkedin_an_sync(PDO $db, array $a, int $days): array {
    $id = (int)$a['id']; $urn = rawurlencode('urn:li:organization:' . $a['ext_id']); $skipped = [];
    $r = ss_linkedin_call($db, $a, 'GET', 'networkSizes/' . $urn, ['query' => ['edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER']], 'an_li_followers');
    if (isset($r['json']['firstDegreeSize'])) ss_an_put($db, $id, date('Y-m-d'), 'followers', $r['json']['firstDegreeSize']);
    $days = max($days, 3);   // LinkedIn has data up to 2 days before
    // Rest.li 2.0: the timeIntervals object goes as is in the query (not url-encoded parentheses)
    $q = '?q=organizationalEntity&organizationalEntity=' . $urn . '&timeIntervals=' . ss_li_intervals($days);
    try {
        foreach ((array)(ss_linkedin_call($db, $a, 'GET', 'organizationalEntityFollowerStatistics' . $q, [], 'an_li_follow_stats')['json']['elements'] ?? []) as $e) {
            $day = date('Y-m-d', (int)($e['timeRange']['start'] ?? 0) / 1000);
            $g = (array)($e['followerGains'] ?? []);
            ss_an_put($db, $id, $day, 'new_followers', (int)($g['organicFollowerGain'] ?? 0) + (int)($g['paidFollowerGain'] ?? 0));
        }
    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; $skipped[] = 'follower_stats'; }
    try {
        foreach ((array)(ss_linkedin_call($db, $a, 'GET', 'organizationalEntityShareStatistics' . $q, [], 'an_li_share_stats')['json']['elements'] ?? []) as $e) {
            $day = date('Y-m-d', (int)($e['timeRange']['start'] ?? 0) / 1000);
            $s = (array)($e['totalShareStatistics'] ?? []);
            foreach (SS_LI_DAY as $k => $m) if (isset($s[$k])) ss_an_put($db, $id, $day, $m, $s[$k]);
            ss_an_put($db, $id, $day, 'interactions', (int)($s['likeCount'] ?? 0) + (int)($s['commentCount'] ?? 0) + (int)($s['shareCount'] ?? 0));
        }
    } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; $skipped[] = 'share_stats'; }
    return ['days' => $days, 'media' => ss_linkedin_media($db, $a), 'skipped' => $skipped];
}
/** Recent posts of the page (last 20) with their lifetime stats. */
function ss_linkedin_posts(PDO $db, array $a, int $count = 20): array {
    $r = ss_linkedin_call($db, $a, 'GET', 'posts?q=author&author=' . rawurlencode('urn:li:organization:' . $a['ext_id']) . '&count=' . $count . '&sortBy=LAST_MODIFIED', ['headers' => ['X-RestLi-Method: FINDER']], 'li_posts');
    return (array)($r['json']['elements'] ?? []);
}
function ss_linkedin_media(PDO $db, array $a): int {
    $id = (int)$a['id']; $posts = ss_linkedin_posts($db, $a); if (!$posts) return 0;
    $stats = []; $urn = rawurlencode('urn:li:organization:' . $a['ext_id']);
    foreach (['share' => 'shares', 'ugcPost' => 'ugcPosts'] as $type => $param) {
        $ids = array_values(array_filter(array_map(fn($p) => (string)$p['id'], $posts), fn($x) => str_starts_with($x, "urn:li:$type:")));
        if (!$ids) continue;
        try {
            $r = ss_linkedin_call($db, $a, 'GET', 'organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . $urn . '&' . $param . '=List(' . implode(',', array_map('rawurlencode', $ids)) . ')', [], 'an_li_post_stats');
            foreach ((array)($r['json']['elements'] ?? []) as $e) $stats[(string)($e['share'] ?? $e['ugcPost'] ?? '')] = (array)($e['totalShareStatistics'] ?? []);
        } catch (SsMetaError $e) { if ($e->metaCode === 190) throw $e; }
    }
    $up = ss_an_media_upsert_stmt($db); $n = 0;
    foreach ($posts as $p) {
        $s = $stats[(string)$p['id']] ?? [];   // posts without activity do not come: they count as 0
        $mt = ['views' => (int)($s['impressionCount'] ?? 0), 'reach' => (int)($s['uniqueImpressionsCount'] ?? $s['uniqueImpressionsCounts'] ?? 0), 'likes' => (int)($s['likeCount'] ?? 0),
               'comments' => (int)($s['commentCount'] ?? 0), 'shares' => (int)($s['shareCount'] ?? 0)];
        $mt['interactions'] = $mt['likes'] + $mt['comments'] + $mt['shares'];
        $type = isset($p['content']['media']['id']) ? (str_contains((string)$p['content']['media']['id'], ':video:') ? 'VIDEO' : 'IMAGE') : 'POST';
        ss_an_media_put($db, $up, $id, (string)$p['id'], $type, (string)($p['commentary'] ?? ''), 'https://www.linkedin.com/feed/update/' . $p['id'] . '/', '',
                        date('Y-m-d H:i:s', (int)(($p['publishedAt'] ?? $p['createdAt'] ?? time() * 1000) / 1000)), $mt);
        $n++;
    }
    return $n;
}

// ── Inbox: comments of the page's posts ────────────────
function ss_linkedin_inbox_sync(PDO $db, array $a): int {
    $n = 0; $me = 'urn:li:organization:' . $a['ext_id'];
    foreach (ss_linkedin_posts($db, $a, 10) as $p) {
        $post = (string)$p['id'];
        $r = ss_linkedin_call($db, $a, 'GET', 'socialActions/' . rawurlencode($post) . '/comments', ['query' => ['count' => 50]], 'inbox_li_comments');
        foreach ((array)($r['json']['elements'] ?? []) as $c) {
            $curn = (string)($c['commentUrn'] ?? ''); if ($curn === '') continue;
            $parent = (string)($c['parentComment'] ?? '');
            $mine = (string)($c['actor'] ?? '') === $me;
            $cid = ss_conv_upsert($db, $a, 'comment', $parent !== '' ? $parent : $curn, ['contact_id' => $mine ? '' : (string)($c['actor'] ?? ''), 'post_id' => $post,
                                   'post_link' => 'https://www.linkedin.com/feed/update/' . $post . '/', 'post_text' => mb_substr((string)($p['commentary'] ?? ''), 0, 300)]);
            if (ss_msg_store($db, $cid, $mine ? 'out' : 'in', $curn, (string)($c['message']['text'] ?? ''), [], $mine ? '' : 'Miembro de LinkedIn', ss_meta_time((int)($c['created']['time'] ?? 0)))) $n++;
        }
    }
    return $n;
}
/** Reply as the page to a comment thread (thread = urn of the top comment). */
function ss_linkedin_reply(PDO $db, array $a, array $c, string $text): string {
    $r = ss_linkedin_call($db, $a, 'POST', 'socialActions/' . rawurlencode($c['thread']) . '/comments', ['json' => ['actor' => 'urn:li:organization:' . $a['ext_id'], 'object' => $c['post_id'],
        'parentComment' => $c['thread'], 'message' => ['text' => $text]], 'tries' => 1], 'inbox_reply_li');
    return (string)($r['headers']['x-restli-id'] ?? $r['json']['commentUrn'] ?? '');
}
