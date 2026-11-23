<?php
/**
 * social/net_lib.php — Social Studio, Stage 6: networks beyond Meta (TikTok, LinkedIn, X, YouTube).
 *  - Registry of connectors with what each one can do TODAY through its official API (and what is blocked by the network,
 *    shown as such on screen: nothing is made to look like it works).
 *  - App of each network (client id + secret encrypted, like Meta's), loaded by a site admin.
 *  - Shared OAuth 2.0 core: single-use state + Lax cookie of the same browser + PKCE (S256) where the network asks for it;
 *    tokens encrypted (access + refresh) and renewed on their own before they expire (with a lock, because X rotates them).
 *  - HTTP calls with retries on 429/5xx, log in g7_ss_sync_log without tokens, errors as SsNetError (extends SsMetaError so the
 *    generic code of publishing/analytics treats "expired" (190) and "wait" (4) the same way for every network).
 * Each network lives in its own file (net_tiktok.php, net_linkedin.php, net_x.php, net_youtube.php) and implements the functions
 * ss_{p}_*: authorize, exchange, refresh, revoke, candidates, check, publish, an_sync, inbox_sync, reply. Doc: toolz/SOCIAL_STUDIO.md (Stage 6)
 */
require_once __DIR__ . '/meta_lib.php';

/**
 * Connectors. kinds: account type => [network, name]. caps: what is connected today (true), what depends on an approval or plan
 * of the network ('approval'), and what the network does not allow ('no'). The texts of 'limits' are shown in Accounts as is.
 */
const SS_PROVIDERS = [
    'tiktok'   => ['name' => 'TikTok', 'icon' => 'fa-tiktok', 'color' => '#25f4ee', 'kinds' => ['tt_user' => ['tiktok', 'Cuenta de TikTok']], 'pkce' => true],
    'linkedin' => ['name' => 'LinkedIn', 'icon' => 'fa-linkedin', 'color' => '#0a66c2', 'kinds' => ['li_org' => ['linkedin', 'Página de LinkedIn']], 'pkce' => false],
    'x'        => ['name' => 'X', 'icon' => 'fa-x-twitter', 'color' => '#a1a1aa', 'kinds' => ['x_user' => ['x', 'Cuenta de X']], 'pkce' => true],
    'youtube'  => ['name' => 'YouTube', 'icon' => 'fa-youtube', 'color' => '#ff0033', 'kinds' => ['yt_channel' => ['youtube', 'Canal de YouTube']], 'pkce' => true],
];
/** Every account type (Meta included) => [provider, network, name]. */
function ss_kinds(): array {
    static $k = null;
    if ($k !== null) return $k;
    $k = ['fb_page' => ['meta', 'facebook', 'Página de Facebook'], 'ig_business' => ['meta', 'instagram', 'Instagram profesional']];
    foreach (SS_PROVIDERS as $p => $d) foreach ($d['kinds'] as $kind => [$net, $name]) $k[$kind] = [$p, $net, $name];
    return $k;
}
function ss_kind_network(string $kind): string { return ss_kinds()[$kind][1] ?? 'facebook'; }
function ss_kind_name(string $kind): string { return ss_kinds()[$kind][2] ?? $kind; }
/** Kinds of a network (for filters: "network = tiktok" → a.kind IN (...)). */
function ss_network_kinds(string $net): array { return array_keys(array_filter(ss_kinds(), fn($k) => $k[1] === $net)); }
function ss_network_kind_sql(string $net): string {
    $ks = ss_network_kinds($net);
    return $ks ? "a.kind IN ('" . implode("','", $ks) . "')" : '0';
}
/** Networks that can be connected today (Meta's + the ones of this stage). */
function ss_connectable_networks(): array { return array_values(array_unique(array_map(fn($k) => $k[1], ss_kinds()))); }

class SsNetError extends SsMetaError { public string $netCode = ''; }

// ═══════════════════════════════════════════════════════════
// APP OF EACH NETWORK (a site admin loads it)
// ═══════════════════════════════════════════════════════════
function ss_net_load(string $p): void {
    if (!isset(SS_PROVIDERS[$p])) ss_fail('Esa red no existe.', 404);
    require_once __DIR__ . '/net_' . $p . '.php';
}
function ss_net_load_all(): void { foreach (array_keys(SS_PROVIDERS) as $p) ss_net_load($p); }
/** Calls ss_{p}_{fn} (every network file defines the same set). */
function ss_net_fn(string $p, string $fn, ...$args) {
    ss_net_load($p);
    $f = 'ss_' . $p . '_' . $fn;
    if (!function_exists($f)) ss_fail(SS_PROVIDERS[$p]['name'] . ' no permite esto desde su API.', 400);
    return $f(...$args);
}
function ss_net_has(string $p, string $fn): bool { ss_net_load($p); return function_exists('ss_' . $p . '_' . $fn); }

function ss_net_cfg(string $p): array {
    if (function_exists('ss_hub_is_client') && ss_hub_is_client()) {   // central connection: what the central allows for this network
        $n = (array)(ss_hub_info_cached()['nets'][$p] ?? []);
        return ['client_id' => '', 'secret_enc' => '', 'publish' => (bool)($n['publish'] ?? false), 'analytics' => (bool)($n['analytics'] ?? false), 'inbox' => (bool)($n['inbox'] ?? false),
                'extra' => (array)($n['extra'] ?? []), 'central' => true, 'ready' => !empty($n['ready'])];
    }
    $c = g7_cfg_json('ss_net_' . $p, []);
    return ['client_id' => (string)($c['client_id'] ?? ''), 'secret_enc' => (string)($c['secret_enc'] ?? ''), 'publish' => (bool)($c['publish'] ?? true),
            'analytics' => (bool)($c['analytics'] ?? true), 'inbox' => (bool)($c['inbox'] ?? true), 'extra' => (array)($c['extra'] ?? [])];
}
function ss_net_secret(string $p): string { return ss_decrypt(ss_net_cfg($p)['secret_enc']); }
function ss_net_ready(string $p): bool { $c = ss_net_cfg($p); if (!empty($c['central'])) return $c['ready']; return $c['client_id'] !== '' && $c['secret_enc'] !== '' && ss_net_secret($p) !== ''; }
/** Is the network of this account ready to be used (its app loaded)? */
function ss_provider_ready(string $p): bool { return $p === 'meta' ? ss_meta_ready() : (isset(SS_PROVIDERS[$p]) && ss_net_ready($p)); }
/** One redirect URI for every network: the provider comes from the state (stored on our side), not from the URL. */
function ss_net_redirect_uri(): string { return g7_site_origin() . '/toolz/social/oauth.php'; }
/**
 * Addresses of each network: [key => default]. Only a server file (_private/site.php or the tests, "social_net_{p}_{key}")
 * can change them: never the screen.
 */
function ss_net_url(string $p, string $key, string $default): string { return rtrim((string)g7_site('social_net_' . $p . '_' . $key, $default), '/'); }

/** What the screen sees (never the secret). */
function ss_net_public(string $p, array $ctx): array {
    $c = ss_net_cfg($p); $d = SS_PROVIDERS[$p];
    if (!empty($c['central'])) {   // the central's own description (caps, limits) as seen by its app
        $n = (array)(ss_hub_info_cached()['nets'][$p] ?? []);
        return $n + ['id' => $p, 'name' => $d['name'], 'icon' => $d['icon'], 'color' => $d['color'], 'ready' => false, 'caps' => [], 'limits' => [], 'steps' => [], 'scopes' => [], 'fields' => [], 'extra' => [], 'console' => '']
             + ['central' => true, 'client_id' => '', 'secret_saved' => false, 'redirect_uri' => '', 'https' => true, 'can_edit' => false];
    }
    $info = ss_net_fn($p, 'info', $c);
    return ['id' => $p, 'name' => $d['name'], 'icon' => $d['icon'], 'color' => $d['color'], 'ready' => ss_net_ready($p), 'client_id' => $c['client_id'], 'secret_saved' => $c['secret_enc'] !== '',
            'publish' => $c['publish'], 'analytics' => $c['analytics'], 'inbox' => $c['inbox'], 'extra' => $c['extra'], 'redirect_uri' => ss_net_redirect_uri(),
            'scopes' => array_map(fn($s) => ['id' => $s, 'label' => $info['scope_info'][$s] ?? $s], ss_net_fn($p, 'scopes', $c)),
            'caps' => $info['caps'], 'limits' => $info['limits'], 'steps' => $info['steps'], 'fields' => $info['fields'] ?? [], 'console' => $info['console'],
            'https' => str_starts_with(g7_site_origin(), 'https://'), 'can_edit' => $ctx['admin']];
}
function ss_net_cfg_save(PDO $db, array $ctx, string $p, array $in): void {
    if (!$ctx['admin']) ss_fail('La app de cada red la configura un administrador del sitio.', 403);
    if (ss_hub_is_client()) ss_fail('Este sitio usa la conexión central: las apps de las redes se cargan allá.');
    ss_net_load($p);
    $c = g7_cfg_json('ss_net_' . $p, []);
    $id = trim((string)($in['client_id'] ?? ''));
    if ($id !== '' && !preg_match('/^[A-Za-z0-9._\-]{4,200}$/', $id)) ss_fail('El ID de la app tiene caracteres que no van (se copia tal cual de la consola de ' . SS_PROVIDERS[$p]['name'] . ').');
    $c['client_id'] = $id;
    $sec = trim((string)($in['client_secret'] ?? ''));
    if ($sec !== '') {
        if (!preg_match('/^[A-Za-z0-9._~+\/=\-]{8,300}$/', $sec)) ss_fail('El secreto de la app no tiene el formato esperado (se copia tal cual de la consola de ' . SS_PROVIDERS[$p]['name'] . ').');
        $c['secret_enc'] = ss_encrypt($sec);
    }
    if (!empty($in['clear_secret'])) $c['secret_enc'] = '';
    foreach (['publish', 'analytics', 'inbox'] as $f) $c[$f] = !empty($in[$f]);
    $extra = [];
    foreach ((ss_net_fn($p, 'info', ss_net_cfg($p))['fields'] ?? []) as $f) {
        $v = $in['extra'][$f['id']] ?? null;
        if ($f['type'] === 'check') $extra[$f['id']] = !empty($v);
        else { $v = trim((string)$v); if ($v !== '' && isset($f['pattern']) && !preg_match($f['pattern'], $v)) ss_fail($f['error']); $extra[$f['id']] = $v; }
    }
    $c['extra'] = $extra;
    g7_cfg_set('ss_net_' . $p, json_encode($c));
    g7_audit('social.net_app', $p, ['client_id' => $id, 'secret_changed' => $sec !== '']);
}

// ═══════════════════════════════════════════════════════════
// HTTP (retries, log without secrets)
// ═══════════════════════════════════════════════════════════
/**
 * $o: query (array), form (array → x-www-form-urlencoded), json (array), raw (string body), headers (list), basic ([user, pass]),
 * timeout. Returns ['code', 'json', 'body', 'headers' (lowercase => value)]. Does NOT throw on 4xx: the caller decides (ss_net_req does).
 */
function ss_net_http(PDO $db, string $method, string $url, array $o = [], ?int $acc = null, string $action = 'net'): array {
    if (!empty($o['query'])) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($o['query']);
    $h = array_merge(['Accept: application/json'], (array)($o['headers'] ?? []));
    $body = null;
    if (isset($o['form'])) { $body = http_build_query($o['form']); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
    elseif (isset($o['json'])) { $body = json_encode($o['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    elseif (isset($o['raw'])) $body = $o['raw'];
    $tries = 0; $resp = false; $code = 0; $err = ''; $hdrs = [];
    $max = (int)($o['tries'] ?? 3);
    while ($tries++ < $max) {
        $hdrs = [];
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => (int)($o['timeout'] ?? 40), CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdrs) { $p = strpos($line, ':'); if ($p) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line); }]);
        if (!empty($o['basic'])) { curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC); curl_setopt($ch, CURLOPT_USERPWD, $o['basic'][0] . ':' . $o['basic'][1]); }
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($resp !== false && $code < 500 && $code !== 429) break;
        if ($tries < $max) usleep($tries * 700000);
    }
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    if ($resp === false) {
        ss_log_call($db, $acc, $action, false, 0, "$method $path: sin conexión ($err)");
        $e = new SsNetError('No se pudo hablar con la red. Probá de nuevo en un rato.', 502); $e->http = 0; $e->metaCode = 4; throw $e;
    }
    $j = json_decode((string)$resp, true);
    return ['code' => $code, 'json' => is_array($j) ? $j : [], 'body' => (string)$resp, 'headers' => $hdrs, 'path' => $path];
}
/**
 * Like ss_net_http but throws SsNetError on 4xx/5xx with the network's message ($p's "error" function extracts it).
 * metaCode: 190 = permission expired or revoked (renew), 4 = wait (rate limit), 10 = missing permission/plan/approval.
 */
function ss_net_req(PDO $db, string $p, string $method, string $url, array $o = [], ?int $acc = null, string $action = 'net'): array {
    $r = ss_net_http($db, $method, $url, $o, $acc, $action);
    $bad = $r['code'] >= 400 || ($p === 'tiktok' && isset($r['json']['error']['code']) && $r['json']['error']['code'] !== 'ok');
    if (!$bad) { ss_log_call($db, $acc, $action, true, $r['code'], "$method {$r['path']}"); return $r; }
    [$msg, $ncode] = ss_net_fn($p, 'error', $r);
    ss_log_call($db, $acc, $action, false, $r['code'], "$method {$r['path']}: [$ncode] $msg");
    $kind = ss_net_err_kind($p, $r['code'], (string)$ncode);
    $name = SS_PROVIDERS[$p]['name'];
    $friendly = ['auth' => "La conexión con $name venció o la revocaron: renová la cuenta.", 'wait' => "$name pidió esperar (límite de uso). Se reintenta más tarde.",
                 'perm' => "Falta un permiso, un plan o una aprobación de $name para esto: " . mb_substr($msg, 0, 200)][$kind] ?? "$name respondió: " . mb_substr($msg, 0, 240);
    $e = new SsNetError($friendly, $r['code'] >= 500 ? 502 : 400);
    $e->http = $r['code']; $e->netCode = (string)$ncode; $e->metaCode = ['auth' => 190, 'wait' => 4, 'perm' => 10][$kind] ?? 0;
    throw $e;
}
function ss_net_err_kind(string $p, int $http, string $code): string {
    if ($http === 401 || in_array($code, ['access_token_invalid', 'invalid_token', 'invalid_grant', 'token_expired', 'access_token_expired', 'authError', 'UNAUTHENTICATED'], true)) return 'auth';
    if ($http === 429 || in_array($code, ['rate_limit_exceeded', 'spam_risk_too_many_posts', 'quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'TOO_MANY_REQUESTS'], true)) return 'wait';
    if ($http === 403 || in_array($code, ['scope_not_authorized', 'insufficientPermissions', 'forbidden', 'ACCESS_DENIED', 'unaudited_client_can_only_post_to_private_accounts'], true)) return 'perm';
    return 'other';
}

// ═══════════════════════════════════════════════════════════
// TOKENS (encrypted bundle: access, refresh, exp, refresh_exp, scope)
// ═══════════════════════════════════════════════════════════
function ss_net_bundle(array $a): array { $j = json_decode(ss_decrypt($a['token_enc'] ?? ''), true); return is_array($j) ? $j : []; }
/** Normalizes the response of a token endpoint. $prev keeps the refresh token when the network does not send a new one. */
function ss_net_bundle_from(array $t, array $prev = []): array {
    $now = time();
    $b = ['access' => (string)($t['access_token'] ?? ''), 'refresh' => (string)($t['refresh_token'] ?? ($prev['refresh'] ?? '')),
          'exp' => !empty($t['expires_in']) ? $now + (int)$t['expires_in'] : null,
          'refresh_exp' => !empty($t['refresh_token_expires_in']) ? $now + (int)$t['refresh_token_expires_in'] : (!empty($t['refresh_expires_in']) ? $now + (int)$t['refresh_expires_in'] : ($prev['refresh_exp'] ?? null)),
          'scope' => (string)($t['scope'] ?? ($prev['scope'] ?? '')), 'extra' => $prev['extra'] ?? []];
    if (!empty($t['open_id'])) $b['extra']['open_id'] = (string)$t['open_id'];
    return $b;
}
/**
 * Valid access token of the account: if it expires in less than 5 minutes it is renewed (with a lock: X invalidates the refresh token
 * when it is used, so two processes cannot renew at the same time). Updates every account that shares the connection.
 */
function ss_net_token(PDO $db, array $a): string {
    $b = ss_net_bundle($a);
    if (!$b || ($b['access'] ?? '') === '') return '';
    if (empty($b['exp']) || $b['exp'] > time() + 300) return $b['access'];
    if (($b['refresh'] ?? '') === '') return $b['access'];   // no refresh token: it is used until the network says it expired
    $lock = 'ss_ref_' . (int)$a['id'];
    if (!(int)$db->query("SELECT GET_LOCK('$lock', 20)")->fetchColumn()) return $b['access'];
    try {
        $q = $db->prepare('SELECT * FROM g7_ss_accounts WHERE id = ?'); $q->execute([(int)$a['id']]); $a = $q->fetch(PDO::FETCH_ASSOC) ?: $a;
        $b = ss_net_bundle($a);   // another process may have renewed it while we waited
        if (!empty($b['exp']) && $b['exp'] > time() + 300) return $b['access'];
        try { $nb = ss_hub_is_client() ? (array)ss_hub_call($db, 'refresh', ['provider' => $a['provider'], 'bundle' => $b])['bundle'] : ss_net_fn($a['provider'], 'refresh', $db, $b, (int)$a['id']); }
        catch (SsNetError $e) {
            if ($e->metaCode === 190 || $e->http === 400) {
                $db->prepare("UPDATE g7_ss_accounts SET status = 'expired', status_msg = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), (int)$a['id']]);
                $e->metaCode = 190;
            }
            throw $e;
        }
        $enc = ss_encrypt(json_encode($nb));
        $exp = !empty($nb['exp']) ? date('Y-m-d H:i:s', $nb['exp']) : null;
        // Every account of the same connection (e.g. several LinkedIn pages of the same person) gets the new bundle
        $db->prepare("UPDATE g7_ss_accounts SET token_enc = ?, token_expires = ?, updated_at = ? WHERE provider = ? AND user_ext_id = ? AND token_enc IS NOT NULL AND token_enc <> '' AND (id = ? OR user_ext_id <> '')")
           ->execute([$enc, $exp, ss_now(), $a['provider'], $a['user_ext_id'], (int)$a['id']]);
        $db->prepare('UPDATE g7_ss_accounts SET token_enc = ?, token_expires = ? WHERE id = ?')->execute([$enc, $exp, (int)$a['id']]);
        return $nb['access'];
    } finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }
}
// ═══════════════════════════════════════════════════════════
// PUBLISHING (the generic step; each network says how in net_{p}.php)
// ═══════════════════════════════════════════════════════════
/** Options of a send for a network, cleaned (what the screen asked: TikTok and YouTube privacy, etc.). $all: {provider: {...}}. */
function ss_net_opts(string $p, array $all): array {
    ss_net_load($p);
    $o = (array)($all[$p] ?? []);
    switch ($p) {
        case 'tiktok':
            $out = ['allow_comments' => !empty($o['allow_comments']), 'brand' => in_array($o['brand'] ?? '', ['own', 'branded'], true) ? $o['brand'] : 'none', 'music_ok' => !empty($o['music_ok'])];
            if (isset(SS_TT_PRIVACY[$o['privacy'] ?? ''])) $out['privacy'] = $o['privacy'];
            return $out;
        case 'youtube':
            $out = [];
            if (isset(SS_YT_PRIVACY[$o['privacy'] ?? ''])) $out['privacy'] = $o['privacy'];
            if (array_key_exists('kids', $o) && $o['kids'] !== null && $o['kids'] !== '') $out['kids'] = (bool)$o['kids'];
            return $out;
    }
    return [];
}
/**
 * One step of a send to a non-Meta network: the first time it publishes (or starts the upload); if the network processes it afterwards
 * (TikTok, LinkedIn video, X video) it is asked once a minute until it is out (up to ~40 minutes).
 */
function ss_net_target_step(PDO $db, array $t, array $a, array $p, callable $set, callable $fail): void {
    $prov = $a['provider'];
    $opts = ss_json_list($t['opts'] ?? '');
    try {
        $plan = ss_net_fn($prov, 'plan', $p, ss_files($db, (int)$p['id']), $opts);
        $cap = ss_caption($p);
        if ((string)$t['container_id'] === '') {
            $r = ss_net_fn($prov, 'publish', $db, $a, $plan, $cap, $p, $opts);
            if (!empty($r['pending'])) { $set(['container_id' => (string)$r['pending'], 'status' => 'processing', 'attempts' => (int)$t['attempts'] + 1, 'run_at' => date('Y-m-d H:i:s', time() + 20)]); return; }
            ss_target_done($db, $t, $a, $p, (string)$r['id'], '', (string)($r['link'] ?? ''));
            return;
        }
        $r = ss_net_fn($prov, 'poll', $db, $a, (string)$t['container_id'], $plan, $cap, $p);
        if ($r === null) {
            $tries = (int)$db->query('SELECT attempts FROM g7_ss_targets WHERE id = ' . (int)$t['id'])->fetchColumn();
            if ($tries > 40) { $fail(SS_PROVIDERS[$prov]['name'] . ' tardó demasiado en procesar la publicación. Revisá la cuenta en la red antes de volver a intentar.'); return; }
            $set(['status' => 'processing', 'run_at' => date('Y-m-d H:i:s', time() + 60), 'attempts' => $tries + 1]);
            return;
        }
        ss_target_done($db, $t, $a, $p, (string)$r['id'], '', (string)($r['link'] ?? ''));
    } catch (SsMetaError $e) {
        if ($e->metaCode === 190) $db->prepare("UPDATE g7_ss_accounts SET status = 'expired', status_msg = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), (int)$a['id']]);
        $tries = (int)$db->query('SELECT attempts FROM g7_ss_targets WHERE id = ' . (int)$t['id'])->fetchColumn();
        // Retry only what is safe to retry: waiting or the network down. A post that went out is never sent twice (container_id marks it).
        if (($e->metaCode === 4 || $e->http >= 500 || $e->http === 0) && $tries < 5)
            $set(['status' => (string)$t['container_id'] !== '' ? 'processing' : 'scheduled', 'run_at' => date('Y-m-d H:i:s', time() + 300 * ($tries + 1)), 'attempts' => $tries + 1, 'error' => mb_substr($e->getMessage(), 0, 600)]);
        else $fail($e->getMessage());
    } catch (SsError $e) { $fail($e->getMessage()); }
}

/** Path on disk of a file of a post (the networks that take the bytes, not a link: TikTok video, LinkedIn, X, YouTube). */
function ss_net_file_path(PDO $db, int $fileId): string {
    $q = $db->prepare('SELECT * FROM g7_ss_files WHERE id = ?'); $q->execute([$fileId]);
    $f = $q->fetch(PDO::FETCH_ASSOC);
    $p = $f ? ss_file_path($f) : '';
    if ($p === '' || !is_file($p)) ss_fail('No se encontró el archivo "' . ($f['name'] ?? '') . '" en el servidor.');
    return $p;
}
/** Reads $len bytes of a file from $offset (chunked uploads). */
function ss_net_chunk(string $path, int $offset, int $len): string {
    $h = fopen($path, 'rb'); if (!$h) ss_fail('No se pudo leer el archivo.', 500);
    fseek($h, $offset); $d = (string)fread($h, $len); fclose($h);
    return $d;
}
/** Account row by id (fresh), for the network files. */
function ss_net_account(PDO $db, int $id): array { $q = $db->prepare('SELECT * FROM g7_ss_accounts WHERE id = ?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC) ?: []; }

// ═══════════════════════════════════════════════════════════
// OAUTH 2.0 (single-use state + same-browser cookie + PKCE)
// ═══════════════════════════════════════════════════════════
function ss_pkce(): array {
    $v = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');   // 64 characters (43-128 allowed)
    return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')];
}
/** Link to the network's consent screen. Returns [url, nonce] (the nonce goes in a Lax cookie of this browser). */
function ss_net_auth_url(PDO $db, array $ctx, string $p): array {
    ss_need($ctx, 'accounts');
    ss_net_load($p);
    if (ss_hub_is_client()) return ss_hub_start_url($db, $ctx, $p);
    if (!ss_net_ready($p)) ss_fail('Primero hay que cargar la app de ' . SS_PROVIDERS[$p]['name'] . ' (Cuentas › ' . SS_PROVIDERS[$p]['name'] . ').');
    if (!ss_rate_ok($db, 'oauth:' . $ctx['id'], 10, 600)) ss_fail('Demasiados intentos de conexión: esperá unos minutos.', 429);
    return ss_net_auth_make($db, $p, (int)$ctx['id']);
}
/** State + nonce + PKCE + link to the network. $site/$cs: when the central connects on behalf of another site (hub_lib.php). */
function ss_net_auth_make(PDO $db, string $p, int $uid, ?int $site = null, string $cs = ''): array {
    $state = bin2hex(random_bytes(24)); $nonce = bin2hex(random_bytes(16));
    [$verifier, $challenge] = ss_pkce();
    $db->prepare('INSERT INTO g7_ss_oauth (user_id, provider, state_hash, nonce_hash, payload_enc, site_id, client_state, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, $p, hash('sha256', $state), hash('sha256', $nonce), ss_encrypt(json_encode(['verifier' => $verifier])), $site, $cs, ss_now(), date('Y-m-d H:i:s', time() + 900)]);
    $db->exec("DELETE FROM g7_ss_oauth WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $c = ss_net_cfg($p);
    return [ss_net_fn($p, 'authorize', $c, $state, $challenge, ss_net_fn($p, 'scopes', $c)), $nonce];
}
/** Which network a state belongs to (oauth.php routes with this). */
function ss_oauth_provider(PDO $db, string $state): string {
    $q = $db->prepare('SELECT provider FROM g7_ss_oauth WHERE state_hash = ?'); $q->execute([hash('sha256', $state)]);
    return (string)($q->fetchColumn() ?: '');
}
/**
 * Return from the network: validates state + nonce, exchanges the code (with the PKCE verifier), reads the accounts that can be
 * connected and leaves them encrypted for the person to choose (same screen as Meta). Returns the id of the connection.
 */
function ss_net_callback(PDO $db, string $state, string $nonce, string $code): int {
    $q = $db->prepare('SELECT * FROM g7_ss_oauth WHERE state_hash = ?'); $q->execute([hash('sha256', $state)]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['used_at'] || strtotime($o['expires_at']) < time() || !isset(SS_PROVIDERS[$o['provider']])) ss_fail('El link de conexión venció. Volvé a Social Studio › Cuentas y tocá "Conectar" otra vez.', 400);
    if ($nonce === '' || !hash_equals($o['nonce_hash'], hash('sha256', $nonce))) ss_fail('Abrí la conexión desde el mismo navegador donde tocaste "Conectar".', 400);
    $db->prepare('UPDATE g7_ss_oauth SET used_at = ? WHERE id = ?')->execute([ss_now(), (int)$o['id']]);   // single use, whether it goes well or not
    $p = $o['provider'];
    $start = json_decode(ss_decrypt($o['payload_enc']), true) ?: [];
    $bundle = ss_net_fn($p, 'exchange', $db, $code, (string)($start['verifier'] ?? ''));
    if (($bundle['access'] ?? '') === '') ss_fail(SS_PROVIDERS[$p]['name'] . ' no devolvió el permiso. Probá de nuevo.', 502);
    $res = ss_net_fn($p, 'candidates', $db, $bundle);   // ['user_ext_id', 'user_name', 'cands' => [...], 'granted' => [...]]
    $need = ss_net_fn($p, 'scopes', ss_net_cfg($p));
    $granted = $res['granted'] ?? array_values(array_filter(preg_split('/[\s,]+/', (string)$bundle['scope'])));
    $payload = ['user_ext_id' => (string)$res['user_ext_id'], 'user_name' => (string)$res['user_name'], 'bundle' => $bundle, 'granted' => $granted,
                'declined' => array_values(array_diff($need, $granted)), 'cands' => $res['cands']];
    $db->prepare('UPDATE g7_ss_oauth SET payload_enc = ?, expires_at = ? WHERE id = ?')->execute([ss_encrypt(json_encode($payload)), date('Y-m-d H:i:s', time() + 1800), (int)$o['id']]);
    ss_log_call($db, null, 'oauth_ok', true, 200, SS_PROVIDERS[$p]['name'] . ': ' . count($res['cands']) . ' cuentas disponibles para la persona ' . (int)$o['user_id']);
    return (int)$o['id'];
}

// ═══════════════════════════════════════════════════════════
// ACCOUNTS (network-agnostic hooks used by meta_lib)
// ═══════════════════════════════════════════════════════════
/** Public URL of an account on its network. */
function ss_account_url(array $a): string {
    $u = rawurlencode((string)$a['username']);
    switch ($a['kind']) {
        case 'ig_business': return $a['username'] ? "https://www.instagram.com/$u/" : '';
        case 'fb_page': return 'https://www.facebook.com/' . rawurlencode((string)$a['ext_id']);
        case 'tt_user': return $a['username'] ? "https://www.tiktok.com/@$u" : '';
        case 'li_org': return $a['username'] ? "https://www.linkedin.com/company/$u/" : 'https://www.linkedin.com/company/' . rawurlencode((string)$a['ext_id']) . '/';
        case 'x_user': return $a['username'] ? "https://x.com/$u" : '';
        case 'yt_channel': return 'https://www.youtube.com/channel/' . rawurlencode((string)$a['ext_id']);
    }
    return '';
}
/** Checks a non-Meta account against its network (name, photo) and updates its status. */
function ss_net_check(PDO $db, array $a): void {
    try {
        $r = ss_net_fn($a['provider'], 'check', $db, $a);   // ['name', 'username', 'picture']
        $db->prepare("UPDATE g7_ss_accounts SET name = ?, username = ?, picture = ?, status = 'ok', status_msg = '', last_check_at = ?, updated_at = ? WHERE id = ?")
           ->execute([mb_substr((string)($r['name'] ?: $a['name']), 0, 190), mb_substr((string)($r['username'] ?? $a['username']), 0, 120), mb_substr((string)($r['picture'] ?: $a['picture']), 0, 1000), ss_now(), ss_now(), (int)$a['id']]);
        // Connections that do not renew themselves (LinkedIn without partner refresh, Google in "Testing"): warn a week before
        $b = ss_net_bundle(ss_net_account($db, (int)$a['id']));
        $until = ($b['refresh'] ?? '') === '' ? ($b['exp'] ?? null) : ($b['refresh_exp'] ?? null);
        if ($until && $until < time() + 7 * 86400) {
            $msg = 'La conexión vence el ' . date('d/m', (int)$until) . ': renovala desde Cuentas antes de esa fecha.';
            $db->prepare('UPDATE g7_ss_accounts SET status_msg = ? WHERE id = ?')->execute([$msg, (int)$a['id']]);
            if (!str_starts_with((string)$a['status_msg'], 'La conexión vence')) ss_notify($db, ss_account_admins($db, $a), 0, null, ($a['username'] ? '@' . $a['username'] : $a['name']) . ' (' . SS_PROVIDERS[$a['provider']]['name'] . '): ' . $msg);
        }
    } catch (SsMetaError $e) {
        $st = $e->metaCode === 190 ? 'expired' : 'error';
        $db->prepare('UPDATE g7_ss_accounts SET status = ?, status_msg = ?, last_check_at = ?, updated_at = ? WHERE id = ?')->execute([$st, mb_substr($e->getMessage(), 0, 300), ss_now(), ss_now(), (int)$a['id']]);
        if ($st === 'expired') ss_notify($db, ss_account_admins($db, $a), 0, null, 'Venció la conexión de ' . ($a['username'] ? '@' . $a['username'] : $a['name']) . ' (' . SS_PROVIDERS[$a['provider']]['name'] . '): renovala en Social Studio › Cuentas');
    }
}
/** On disconnect: revokes the permission on the network if no other account uses the same connection. */
function ss_net_disconnect(PDO $db, array $a): void {
    $q = $db->prepare("SELECT COUNT(*) FROM g7_ss_accounts WHERE provider = ? AND user_ext_id = ? AND id <> ? AND token_enc IS NOT NULL AND token_enc <> ''");
    $q->execute([$a['provider'], $a['user_ext_id'], (int)$a['id']]);
    if ((int)$q->fetchColumn()) return;
    $b = ss_net_bundle($a);
    if (!$b || !ss_net_has($a['provider'], 'revoke')) return;
    try { if (ss_hub_is_client()) ss_hub_call($db, 'revoke', ['provider' => $a['provider'], 'bundle' => $b]); else ss_net_fn($a['provider'], 'revoke', $db, $b, (int)$a['id']); } catch (SsError $e) {}
}

// The network files are small: they are always loaded (their constants are used by the API and the screen).
ss_net_load_all();
require_once __DIR__ . '/hub_lib.php';   // central connection (Cronos holds the apps; the other sites connect through it)
