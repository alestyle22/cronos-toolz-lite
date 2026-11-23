<?php
/**
 * social/hub_lib.php — Social Studio: central connection ("Conexión central de Cronos").
 * One Toolz installation (cronostoolz.com/toolz) holds the apps of Meta, TikTok, LinkedIn, X and YouTube; the other installations
 * connect their accounts through it, so no agency has to create apps in each network.
 *
 * Roles (g7_config):
 *  - local  (default): the site loads its own apps, as before.
 *  - hub    (ss_hub_role = 'hub'): it is the central. Admin › Social Studio › Cuentas lists the authorized sites (name, origin and a
 *           secret key of each one, encrypted). The network returns to the central; the central hands the permission to the site.
 *  - client (ss_hub = {url, site, secret_enc}): uses a central. The admin pastes the connection code that the central gave.
 *
 * Security:
 *  - Server-to-server requests: HMAC-SHA256 with the site's key over "ts\nnonce\nbody", ±5 minutes, single-use nonce. Signed answers.
 *  - Browser start: link signed by the site (single use through its state + same-browser cookie on BOTH ends).
 *  - Tokens never travel through the browser: the site picks them up with a single-use code, server to server.
 *  - The app secrets never leave the central. What needs them is asked to the central: renewing, revoking and Meta's appsecret_proof
 *    (calculated per page token at connection time and stored encrypted with the account).
 *  - Meta webhooks arrive at the central and are passed only to the site that proved (with the page token) that it manages that page.
 * Doc: toolz/SOCIAL_STUDIO.md (Conexión central)
 */
require_once __DIR__ . '/net_lib.php';

const SS_HUB_SKEW = 300;   // seconds of tolerance between servers

// ═══════════════════════════════════════════════════════════
// ROLE
// ═══════════════════════════════════════════════════════════
function ss_hub_is_client(): bool { return ss_hub_role_now() === 'client'; }
function ss_hub_is_hub(): bool { return ss_hub_role_now() === 'hub'; }
/** Role without the static cache (the tests and the screen change it in the same request). */
function ss_hub_role_now(): string {
    if (g7_cfg('ss_hub_role', '') === 'hub') return 'hub';
    $c = g7_cfg_json('ss_hub', []);
    return !empty($c['url']) && !empty($c['site']) && !empty($c['secret_enc']) ? 'client' : 'local';
}
function ss_hub_client_cfg(): array { $c = g7_cfg_json('ss_hub', []); return ['url' => rtrim((string)($c['url'] ?? ''), '/'), 'site' => (int)($c['site'] ?? 0), 'secret' => ss_decrypt((string)($c['secret_enc'] ?? ''))]; }
function ss_hub_base(): string { return g7_site_origin() . '/toolz'; }

// ═══════════════════════════════════════════════════════════
// SIGNATURES
// ═══════════════════════════════════════════════════════════
function ss_hub_sig(string $secret, string $ts, string $nonce, string $body): string { return hash_hmac('sha256', "$ts\n$nonce\n$body", $secret); }
/** Single-use nonce (replays are rejected). */
function ss_hub_nonce_ok(PDO $db, string $nonce): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return false;
    try { $db->prepare('INSERT INTO g7_ss_hub_nonce (nonce, at) VALUES (?, ?)')->execute([$nonce, ss_now()]); }
    catch (PDOException $e) { return false; }
    if (random_int(1, 50) === 1) $db->exec('DELETE FROM g7_ss_hub_nonce WHERE at < NOW() - INTERVAL 1 DAY');
    return true;
}
/** Valid origin of a site: https (or http only for localhost, for the tests), without path. */
function ss_hub_origin_ok(string $o): bool {
    return (bool)preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $o) || (bool)preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#i', $o);
}

// ═══════════════════════════════════════════════════════════
// CENTRAL: authorized sites
// ═══════════════════════════════════════════════════════════
function ss_hub_set_role(PDO $db, array $ctx, bool $on): void {
    if (!$ctx['admin']) ss_fail('La conexión central la configura un administrador del sitio.', 403);
    if ($on && ss_hub_role_now() === 'client') ss_fail('Este sitio usa otra conexión central: primero desconectala.');
    g7_cfg_set('ss_hub_role', $on ? 'hub' : '');
    g7_audit('social.hub_role', $on ? 'hub' : 'local', []);
}
function ss_hub_sites(PDO $db): array {
    return array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'origin' => $s['origin'], 'active' => (bool)$s['active'], 'created_at' => $s['created_at'], 'last_seen_at' => $s['last_seen_at'],
        'routes' => (int)$s['routes']], $db->query('SELECT s.*, (SELECT COUNT(*) FROM g7_ss_hub_routes r WHERE r.site_id = s.id) routes FROM g7_ss_hub_sites s ORDER BY s.name')->fetchAll(PDO::FETCH_ASSOC));
}
/** Authorizes a site. Returns the connection code (shown ONCE: it carries the site's key). */
function ss_hub_site_add(PDO $db, array $ctx, string $name, string $origin): array {
    if (!$ctx['admin']) ss_fail('Solo un administrador del sitio autoriza sitios.', 403);
    if (!ss_hub_is_hub()) ss_fail('Primero activá "Ser la conexión central" en este sitio.');
    $name = mb_substr(trim($name), 0, 120); $origin = rtrim(strtolower(trim($origin)), '/');
    $origin = preg_replace('#/toolz$#', '', $origin);
    if ($name === '') ss_fail('Poné un nombre para reconocer el sitio (por ejemplo, el de la agencia).');
    if (!ss_hub_origin_ok($origin)) ss_fail('La dirección del sitio va así: https://agencia.com (sin /toolz ni nada después).');
    $q = $db->prepare('SELECT id FROM g7_ss_hub_sites WHERE origin = ?'); $q->execute([$origin]);
    if ($q->fetchColumn()) ss_fail('Ese sitio ya está autorizado. Si perdiste el código, borralo y autorizalo de nuevo.');
    $secret = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO g7_ss_hub_sites (name, origin, secret_enc, active, created_by, created_at) VALUES (?, ?, ?, 1, ?, ?)')->execute([$name, $origin, ss_encrypt($secret), $ctx['id'], ss_now()]);
    $id = (int)$db->lastInsertId();
    g7_audit('social.hub_site_add', '#' . $id, ['name' => $name, 'origin' => $origin]);
    return ['id' => $id, 'code' => rtrim(strtr(base64_encode(json_encode(['u' => ss_hub_base(), 's' => $id, 'k' => $secret])), '+/', '-_'), '=')];
}
function ss_hub_site_set(PDO $db, array $ctx, int $id, ?bool $active, bool $delete = false): void {
    if (!$ctx['admin']) ss_fail('Solo un administrador del sitio maneja los sitios autorizados.', 403);
    if ($delete) { $db->prepare('DELETE FROM g7_ss_hub_routes WHERE site_id = ?')->execute([$id]); $db->prepare('DELETE FROM g7_ss_hub_sites WHERE id = ?')->execute([$id]); }
    elseif ($active !== null) $db->prepare('UPDATE g7_ss_hub_sites SET active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    g7_audit('social.hub_site_' . ($delete ? 'delete' : ($active ? 'on' : 'off')), '#' . $id, []);
}
function ss_hub_site_row(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM g7_ss_hub_sites WHERE id = ? AND active = 1'); $q->execute([$id]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['secret'] = ss_decrypt($s['secret_enc']);
    return $s['secret'] !== '' ? $s : null;
}

// ═══════════════════════════════════════════════════════════
// CENTRAL: answering the sites (hub.php)
// ═══════════════════════════════════════════════════════════
/** Checks a server-to-server request. Returns the site or null. */
function ss_hub_verify(PDO $db, array $h, string $body): ?array {
    $site = ss_hub_site_row($db, (int)($h['site'] ?? 0));
    $ts = (string)($h['ts'] ?? ''); $nonce = (string)($h['nonce'] ?? ''); $sig = (string)($h['sig'] ?? '');
    if (!$site || !ctype_digit($ts) || abs(time() - (int)$ts) > SS_HUB_SKEW || !hash_equals(ss_hub_sig($site['secret'], $ts, $nonce, $body), $sig)) return null;
    if (!ss_hub_nonce_ok($db, $nonce)) return null;
    $db->prepare('UPDATE g7_ss_hub_sites SET last_seen_at = ? WHERE id = ?')->execute([ss_now(), (int)$site['id']]);
    return $site;
}
/** What the networks allow through this central (without secrets): the sites show it as theirs. */
function ss_hub_info_out(): array {
    $adm = ['admin' => false, 'id' => 0];
    $m = ss_meta_cfg();
    $nets = [];
    foreach (array_keys(SS_PROVIDERS) as $p) { $x = ss_net_public($p, $adm); unset($x['client_id'], $x['secret_saved'], $x['redirect_uri'], $x['can_edit'], $x['https']); $nets[$p] = $x; }
    return ['meta' => ['ready' => ss_meta_ready(), 'version' => $m['version'], 'publish' => $m['publish'], 'webhooks' => $m['webhooks'], 'inbox' => $m['inbox'], 'analytics' => $m['analytics'],
                       'human_agent' => $m['human_agent'], 'scopes' => ss_meta_scopes()], 'nets' => $nets, 'name' => (string)g7_site('name', 'Cronos')];
}
/** Server-to-server action of a site. Returns the data to answer (signed by hub.php). */
function ss_hub_action(PDO $db, array $site, array $in): array {
    $sid = (int)$site['id'];
    switch ((string)($in['action'] ?? '')) {
        case 'info': return ss_hub_info_out();
        case 'pickup':
            $q = $db->prepare('SELECT * FROM g7_ss_oauth WHERE pick_hash = ? AND site_id = ?'); $q->execute([hash('sha256', (string)($in['pick'] ?? '')), $sid]);
            $o = $q->fetch(PDO::FETCH_ASSOC);
            if (!$o || !$o['payload_enc'] || strtotime($o['expires_at']) < time()) ss_fail('La conexión venció: tocá "Conectar" otra vez.', 404);
            $p = json_decode(ss_decrypt($o['payload_enc']), true) ?: [];
            unset($p['user_token']);   // the user token of Meta stays at the central: the site only needs the page tokens
            $db->prepare('UPDATE g7_ss_oauth SET payload_enc = NULL, pick_hash = NULL WHERE id = ?')->execute([(int)$o['id']]);   // single use
            ss_log_call($db, null, 'hub_pickup', true, 200, 'Sitio ' . $site['name'] . ': ' . count($p['cands'] ?? []) . ' cuentas (' . $o['provider'] . ')');
            return ['provider' => $o['provider'], 'payload' => $p];
        case 'refresh':
            $p = (string)($in['provider'] ?? ''); if (!isset(SS_PROVIDERS[$p]) || !ss_net_ready($p)) ss_fail('Esa red no está configurada en la conexión central.', 400);
            $b = (array)($in['bundle'] ?? []); if (($b['refresh'] ?? '') === '') ss_fail('Falta el permiso de renovación.', 400);
            return ['bundle' => ss_net_fn($p, 'refresh', $db, $b, 0)];
        case 'revoke':
            $p = (string)($in['provider'] ?? ''); if (!isset(SS_PROVIDERS[$p]) || !ss_net_has($p, 'revoke')) return ['ok' => true];
            try { ss_net_fn($p, 'revoke', $db, (array)($in['bundle'] ?? []), 0); } catch (SsError $e) {}
            return ['ok' => true];
        case 'routes':
            $n = 0;
            foreach (array_slice((array)($in['add'] ?? []), 0, 200) as $r) {
                $kind = (string)($r['kind'] ?? ''); $ext = (string)($r['ext_id'] ?? ''); $tok = (string)($r['token'] ?? '');
                if (!in_array($kind, ['fb_page', 'ig_business'], true) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $ext) || $tok === '') continue;
                // The site has to prove it manages that page or account: the Graph API answers with that same id for its token
                try { $x = ss_meta_call($db, 'GET', $ext, ['fields' => 'id', 'access_token' => $tok], null, 'hub_route_check'); } catch (SsError $e) { continue; }
                if ((string)($x['id'] ?? '') !== $ext) continue;
                $db->prepare('INSERT INTO g7_ss_hub_routes (site_id, kind, ext_id, at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE site_id = VALUES(site_id), at = VALUES(at)')->execute([$sid, $kind, $ext, ss_now()]);
                $n++;
            }
            foreach (array_slice((array)($in['remove'] ?? []), 0, 200) as $r)
                $db->prepare('DELETE FROM g7_ss_hub_routes WHERE site_id = ? AND kind = ? AND ext_id = ?')->execute([$sid, (string)($r['kind'] ?? ''), (string)($r['ext_id'] ?? '')]);
            return ['routes' => $n];
    }
    ss_fail('Acción desconocida.', 400);
}
/** Browser start sent by a site: checks its signature and goes to the network with the central's app. Returns [url, hub nonce]. */
function ss_hub_start(PDO $db, array $g): array {
    $site = ss_hub_site_row($db, (int)($g['site'] ?? 0));
    $p = (string)($g['p'] ?? ''); $cs = (string)($g['cs'] ?? ''); $exp = (string)($g['exp'] ?? '');
    if (!$site || !preg_match('/^[a-f0-9]{48}$/', $cs) || !ctype_digit($exp) || (int)$exp < time() || (int)$exp > time() + 1200
        || !hash_equals(hash_hmac('sha256', "start|{$site['id']}|$p|$cs|$exp", $site['secret']), (string)($g['sig'] ?? ''))) ss_fail('El link de conexión no es válido o venció. Volvé a Social Studio y tocá "Conectar" otra vez.', 403);
    if (!ss_hub_nonce_ok($db, substr(hash('sha256', $cs), 0, 32))) ss_fail('Ese link de conexión ya se usó. Tocá "Conectar" otra vez.', 403);
    if ($p === 'meta') { if (!ss_meta_ready()) ss_fail('La conexión central todavía no tiene la app de Meta.'); return ss_meta_auth_make($db, 0, (int)$site['id'], $cs); }
    if (!isset(SS_PROVIDERS[$p]) || !ss_net_ready($p)) ss_fail('La conexión central todavía no tiene la app de esa red.');
    return ss_net_auth_make($db, $p, 0, (int)$site['id'], $cs);
}
/** After the network's return at the central: single-use pickup code and the way back to the site. */
function ss_hub_handoff(PDO $db, int $oauthId): string {
    $o = $db->query('SELECT o.*, s.origin FROM g7_ss_oauth o JOIN g7_ss_hub_sites s ON s.id = o.site_id WHERE o.id = ' . $oauthId)->fetch(PDO::FETCH_ASSOC);
    if (!$o) ss_fail('No se encontró la conexión.', 404);
    // What nobody picked up in time is erased (the permissions of other sites never stay at the central)
    $db->exec('UPDATE g7_ss_oauth SET payload_enc = NULL, pick_hash = NULL WHERE site_id IS NOT NULL AND expires_at < NOW()');
    $pick = bin2hex(random_bytes(24));
    $db->prepare('UPDATE g7_ss_oauth SET pick_hash = ?, expires_at = ? WHERE id = ?')->execute([hash('sha256', $pick), date('Y-m-d H:i:s', time() + 600), $oauthId]);
    return $o['origin'] . '/toolz/social/oauth.php?' . http_build_query(['hs' => $o['client_state'], 'pick' => $pick]);
}
/** Way back to the site when the person cancelled at the network. */
function ss_hub_cancel_url(PDO $db, string $state): ?string {
    $q = $db->prepare('SELECT o.client_state, s.origin FROM g7_ss_oauth o JOIN g7_ss_hub_sites s ON s.id = o.site_id WHERE o.state_hash = ?'); $q->execute([hash('sha256', $state)]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    return $o ? $o['origin'] . '/toolz/social/oauth.php?' . http_build_query(['hs' => $o['client_state'], 'error' => 'cancel']) : null;
}
/** Which site the return belongs to (null = this site). */
function ss_oauth_site(PDO $db, string $state): ?int {
    $q = $db->prepare('SELECT site_id FROM g7_ss_oauth WHERE state_hash = ?'); $q->execute([hash('sha256', $state)]);
    $s = $q->fetchColumn();
    return $s ? (int)$s : null;
}
/** Meta webhook at the central: it is passed to the sites that manage the pages or accounts of the event (signed with each site's key). */
function ss_hub_forward(PDO $db, string $raw): int {
    if (!ss_hub_is_hub()) return 0;
    $j = json_decode($raw, true); $kind = ($j['object'] ?? '') === 'instagram' ? 'ig_business' : (($j['object'] ?? '') === 'page' ? 'fb_page' : '');
    if ($kind === '') return 0;
    $sites = [];
    foreach ((array)($j['entry'] ?? []) as $e) {
        $q = $db->prepare('SELECT site_id FROM g7_ss_hub_routes WHERE kind = ? AND ext_id = ?'); $q->execute([$kind, (string)($e['id'] ?? '')]);
        if ($sid = (int)$q->fetchColumn()) $sites[$sid] = true;
    }
    $n = 0;
    foreach (array_keys($sites) as $sid) {
        $s = ss_hub_site_row($db, $sid); if (!$s) continue;
        $ts = (string)time(); $nonce = bin2hex(random_bytes(16));
        try {
            $r = ss_net_http($db, 'POST', $s['origin'] . '/toolz/social/webhook.php?p=hub', ['raw' => $raw, 'tries' => 2, 'timeout' => 8,
                'headers' => ['Content-Type: application/json', 'X-SS-Site: ' . $sid, 'X-SS-Ts: ' . $ts, 'X-SS-Nonce: ' . $nonce, 'X-SS-Sig: ' . ss_hub_sig($s['secret'], $ts, $nonce, $raw)]], null, 'hub_forward');
            $ok = $r['code'] === 200;
        } catch (SsError $e) { $ok = false; }
        ss_log_call($db, null, 'hub_forward', $ok, $r['code'] ?? 0, 'Aviso de Meta para ' . $s['name']);
        if ($ok) $n++;
    }
    return $n;
}

// ═══════════════════════════════════════════════════════════
// SITE (client): using a central
// ═══════════════════════════════════════════════════════════
/** Connects this site to a central with the code it gave. Checks it right away (asks the central what it offers). */
function ss_hub_join(PDO $db, array $ctx, string $code): array {
    if (!$ctx['admin']) ss_fail('La conexión central la configura un administrador del sitio.', 403);
    if (ss_hub_is_hub()) ss_fail('Este sitio es la conexión central: no puede usar otra.');
    if (!ss_plan_allows('social_central')) ss_plan_fail('la conexión central de Cronos');
    $j = json_decode((string)base64_decode(strtr(trim($code), '-_', '+/')), true);
    if (!is_array($j) || empty($j['u']) || empty($j['s']) || !preg_match('/^[a-f0-9]{64}$/', (string)($j['k'] ?? ''))) ss_fail('Ese código no es válido: copialo completo desde la conexión central.');
    $url = rtrim((string)$j['u'], '/');
    if (!preg_match('#^(https://[a-z0-9.-]+(:\d+)?|http://(localhost|127\.0\.0\.1)(:\d+)?)/toolz$#i', $url)) ss_fail('La dirección de la conexión central no es válida.');
    $old = g7_cfg('ss_hub', '');
    g7_cfg_set('ss_hub', json_encode(['url' => $url, 'site' => (int)$j['s'], 'secret_enc' => ss_encrypt((string)$j['k'])]));
    try { $info = ss_hub_info($db, true); }
    catch (SsError $e) { g7_cfg_set('ss_hub', (string)$old); ss_fail('La conexión central no respondió bien: ' . $e->getMessage()); }
    g7_audit('social.hub_join', $url, ['site' => (int)$j['s']]);
    return $info;
}
function ss_hub_leave(PDO $db, array $ctx): void {
    if (!$ctx['admin']) ss_fail('La conexión central la configura un administrador del sitio.', 403);
    g7_cfg_set('ss_hub', ''); g7_cfg_set('ss_hub_info', '');
    g7_audit('social.hub_leave', '', []);
}
/** Signed call to the central. */
function ss_hub_call(PDO $db, string $action, array $data = []): array {
    $c = ss_hub_client_cfg();
    if ($c['url'] === '' || $c['secret'] === '') ss_fail('Este sitio no tiene conexión central.', 400);
    $body = json_encode(['action' => $action] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ts = (string)time(); $nonce = bin2hex(random_bytes(16));
    $r = ss_net_http($db, 'POST', $c['url'] . '/social/hub.php', ['raw' => $body, 'tries' => $action === 'refresh' ? 1 : 2, 'timeout' => 40,
        'headers' => ['Content-Type: application/json', 'X-SS-Site: ' . $c['site'], 'X-SS-Ts: ' . $ts, 'X-SS-Nonce: ' . $nonce, 'X-SS-Sig: ' . ss_hub_sig($c['secret'], $ts, $nonce, $body)]], null, 'hub_' . $action);
    $sig = (string)($r['headers']['x-ss-sig'] ?? '');
    if ($r['code'] === 200 && !hash_equals(hash_hmac('sha256', $nonce . "\n" . $r['body'], $c['secret']), $sig)) {
        ss_log_call($db, null, 'hub_' . $action, false, 200, 'Respuesta de la conexión central sin firma válida'); ss_fail('La respuesta de la conexión central no está firmada: no se usó.', 502);
    }
    $j = $r['json'];
    if ($r['code'] !== 200) {
        ss_log_call($db, null, 'hub_' . $action, false, $r['code'], 'Conexión central: ' . mb_substr((string)($j['error'] ?? 'sin respuesta'), 0, 200));
        $e = new SsNetError((string)($j['error'] ?? 'La conexión central no respondió.'), $r['code'] >= 500 || $r['code'] === 0 ? 502 : 400);
        $e->http = $r['code']; $e->metaCode = (int)($j['code'] ?? 0); throw $e;
    }
    ss_log_call($db, null, 'hub_' . $action, true, 200, 'Conexión central');
    return (array)($j['data'] ?? []);
}
/** What the central offers (cached 10 minutes; if it does not answer, the last one known is used). */
function ss_hub_info(PDO $db, bool $fresh = false): array {
    $c = g7_cfg_json('ss_hub_info', []);
    if (!$fresh && !empty($c['at']) && time() - (int)$c['at'] < 600) return (array)$c['data'];
    try { $d = ss_hub_call($db, 'info'); g7_cfg_set('ss_hub_info', json_encode(['at' => time(), 'data' => $d])); return $d; }
    catch (SsError $e) { if ($fresh || empty($c['data'])) throw $e; return (array)$c['data']; }
}
/** Cached info without calling (for the functions that have no database at hand). */
function ss_hub_info_cached(): array {
    try { return ss_hub_info(getDB()); } catch (Throwable $e) { $c = g7_cfg_json('ss_hub_info', []); return (array)($c['data'] ?? []); }
}
/** "Connect" on a site: local state + cookie as always, and the link to the central signed with the site's key. */
function ss_hub_start_url(PDO $db, array $ctx, string $p): array {
    ss_need($ctx, 'accounts');
    if (!ss_plan_allows('social_central')) ss_plan_fail('la conexión central de Cronos');
    if (!ss_rate_ok($db, 'oauth:' . $ctx['id'], 10, 600)) ss_fail('Demasiados intentos de conexión: esperá unos minutos.', 429);
    $info = ss_hub_info($db);
    $ready = $p === 'meta' ? !empty($info['meta']['ready']) : !empty($info['nets'][$p]['ready']);
    if (!$ready) ss_fail('La conexión central todavía no tiene la app de ' . ($p === 'meta' ? 'Meta' : SS_PROVIDERS[$p]['name'] ?? $p) . '.');
    $c = ss_hub_client_cfg();
    $state = bin2hex(random_bytes(24)); $nonce = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO g7_ss_oauth (user_id, provider, state_hash, nonce_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$ctx['id'], $p, hash('sha256', $state), hash('sha256', $nonce), ss_now(), date('Y-m-d H:i:s', time() + 900)]);
    $exp = (string)(time() + 900);
    $q = ['a' => 'start', 'site' => $c['site'], 'p' => $p, 'cs' => $state, 'exp' => $exp, 'sig' => hash_hmac('sha256', "start|{$c['site']}|$p|$state|$exp", $c['secret'])];
    return [$c['url'] . '/social/hub.php?' . http_build_query($q), $nonce];
}
/** Return from the central to the site: same-browser check, then the permission is picked up server to server. Returns the id to choose accounts. */
function ss_hub_finish(PDO $db, string $state, string $nonce, string $pick): int {
    $q = $db->prepare('SELECT * FROM g7_ss_oauth WHERE state_hash = ?'); $q->execute([hash('sha256', $state)]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['used_at'] || strtotime($o['expires_at']) < time()) ss_fail('El link de conexión venció. Volvé a Social Studio › Cuentas y tocá "Conectar" otra vez.', 400);
    if ($nonce === '' || !hash_equals($o['nonce_hash'], hash('sha256', $nonce))) ss_fail('Abrí la conexión desde el mismo navegador donde tocaste "Conectar".', 400);
    $db->prepare('UPDATE g7_ss_oauth SET used_at = ? WHERE id = ?')->execute([ss_now(), (int)$o['id']]);
    $d = ss_hub_call($db, 'pickup', ['pick' => $pick]);
    if (($d['provider'] ?? '') !== $o['provider'] || !isset($d['payload']['cands'])) ss_fail('La conexión central devolvió otra cosa. Tocá "Conectar" otra vez.', 502);
    $db->prepare('UPDATE g7_ss_oauth SET payload_enc = ?, expires_at = ? WHERE id = ?')->execute([ss_encrypt(json_encode($d['payload'])), date('Y-m-d H:i:s', time() + 1800), (int)$o['id']]);
    return (int)$o['id'];
}
/** Registers in the central the Meta pages and accounts this site manages (so it passes their notifications). */
function ss_hub_routes(PDO $db, array $add, array $remove = []): void {
    if (!ss_hub_is_client() || (!$add && !$remove)) return;
    try { ss_hub_call($db, 'routes', ['add' => $add, 'remove' => $remove]); }
    catch (SsError $e) {
        foreach ($add as $r) $db->prepare("UPDATE g7_ss_accounts SET status_msg = ? WHERE provider = 'meta' AND kind = ? AND ext_id = ?")->execute(['Conectada, pero la conexión central no activó los avisos: ' . mb_substr($e->getMessage(), 0, 160), $r['kind'], $r['ext_id']]);
    }
}
/** Meta's appsecret_proof on a site without the secret: it was calculated by the central for each page token and saved with the account. */
function ss_hub_proof(string $token): string {
    static $map = null;
    if ($map === null || !isset($map[$token])) {
        $map = [];
        try { foreach (getDB()->query("SELECT token_enc, proof_enc FROM g7_ss_accounts WHERE provider = 'meta' AND proof_enc IS NOT NULL AND token_enc IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) $map[ss_decrypt($r['token_enc'])] = ss_decrypt($r['proof_enc']); }
        catch (Throwable $e) {}
    }
    return $map[$token] ?? '';
}
/** Notification passed by the central (webhook.php?p=hub). */
function ss_hub_webhook_ok(PDO $db, array $h, string $body): bool {
    $c = ss_hub_client_cfg();
    if ($c['secret'] === '' || (int)($h['site'] ?? 0) !== $c['site']) return false;
    $ts = (string)($h['ts'] ?? '');
    if (!ctype_digit($ts) || abs(time() - (int)$ts) > SS_HUB_SKEW || !hash_equals(ss_hub_sig($c['secret'], $ts, (string)($h['nonce'] ?? ''), $body), (string)($h['sig'] ?? ''))) return false;
    return ss_hub_nonce_ok($db, (string)($h['nonce'] ?? ''));
}
/** What the screen sees of the central connection. */
function ss_hub_public(PDO $db, array $ctx): array {
    $role = ss_hub_role_now();
    $o = ['role' => $role, 'admin' => $ctx['admin']];
    if ($role === 'client') {
        $o['url'] = ss_hub_client_cfg()['url'];
        try { $i = ss_hub_info($db); $o['name'] = (string)($i['name'] ?? ''); $o['ok'] = true; } catch (SsError $e) { $o['ok'] = false; $o['error'] = $e->getMessage(); }
    }
    if ($role === 'hub' && $ctx['admin']) $o['sites'] = ss_hub_sites($db);
    if ($role === 'hub') $o['base'] = ss_hub_base();
    return $o;
}
