<?php
/**
 * social/meta_lib.php — Social Studio, Etapa 2: conexión oficial con Meta (Facebook Login + Graph API).
 *  - Cifrado de tokens (AES-256-GCM, clave aparte en _private/social_key.php). Nunca viajan al navegador.
 *  - App de Meta de la agencia (App ID, App Secret cifrado, versión, configuración de Login for Business).
 *  - OAuth con state de un solo uso + cookie Lax del mismo navegador; elegir páginas y cuentas de Instagram; renovar; desconectar.
 *  - Webhook firmado (X-Hub-Signature-256) — lo que llega se guarda para la Etapa 3 (Inbox).
 *  - Publicar en Facebook e Instagram (ahora o programado), con reintentos y registro.
 * Solo APIs oficiales: nada de scraping, nunca se pide la contraseña de la red. Doc: toolz/SOCIAL_STUDIO.md
 */
require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/priv_lib.php';

const SS_META_VERSION = 'v27.0';   // última estable al 2026-11-17 (v27.0 salió el 4-nov-2026); se cambia en Cuentas › App de Meta
/**
 * Permisos de Meta por función. Solo se piden los de las funciones que existen hoy; los de Inbox y Analytics se suman
 * cuando lleguen esas etapas (la persona renueva la conexión y Meta le pide los nuevos).
 */
const SS_META_SCOPES = [
    'base'    => ['pages_show_list', 'business_management', 'pages_read_engagement', 'instagram_basic'],
    'publish' => ['pages_manage_posts', 'instagram_content_publish'],
    'webhook' => ['pages_manage_metadata'],
    'analytics' => ['read_insights', 'instagram_manage_insights'],
    'inbox'   => ['pages_messaging', 'instagram_manage_messages', 'instagram_manage_comments', 'pages_manage_engagement', 'pages_read_user_content'],
];
const SS_META_SCOPE_INFO = [
    'pages_show_list' => 'Ver la lista de páginas que administrás', 'business_management' => 'Ver las páginas de tus portfolios comerciales',
    'pages_read_engagement' => 'Leer el contenido y datos básicos de la página', 'instagram_basic' => 'Leer el perfil de la cuenta de Instagram',
    'pages_manage_posts' => 'Publicar en la página de Facebook', 'instagram_content_publish' => 'Publicar en Instagram',
    'pages_manage_metadata' => 'Recibir avisos (webhooks) de la página',
    'pages_messaging' => 'Leer y responder los mensajes de Messenger', 'instagram_manage_messages' => 'Leer y responder los mensajes directos de Instagram',
    'instagram_manage_comments' => 'Leer y responder comentarios de Instagram', 'pages_manage_engagement' => 'Responder comentarios de la página',
    'pages_read_user_content' => 'Leer los comentarios de la página',
    'read_insights' => 'Leer las métricas de la página', 'instagram_manage_insights' => 'Leer las métricas de Instagram',
];
/** Lo que sigue esperando una aprobación de Meta o una etapa nueva (se muestra en Cuentas, sin simular nada). */
const SS_META_LATER = [];
const SS_KIND_NAME = ['fb_page' => 'Página de Facebook', 'ig_business' => 'Instagram profesional'];

// ═══════════════════════════════════════════════════════════
// CIFRADO
// ═══════════════════════════════════════════════════════════
function ss_priv(string $f = ''): string { return (defined('G7_SS_PRIV') ? G7_SS_PRIV : dirname(__DIR__) . '/_private') . ($f !== '' ? '/' . $f : ''); }
function ss_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $raw = g7_priv_read(ss_priv('social_key.php'));
    if ($raw !== null && strlen($x = base64_decode(trim($raw), true) ?: '') === 32) return $k = $x;
    $x = random_bytes(32);
    if (!g7_priv_write(ss_priv('social_key.php'), base64_encode($x))) ss_fail('No se pudo crear la clave de cifrado en _private/.', 500);
    return $k = $x;
}
function ss_encrypt(string $plain): string {
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', ss_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) ss_fail('No se pudo cifrar.', 500);
    return 's1:' . base64_encode($iv . $tag . $c);
}
function ss_decrypt(?string $enc): string {
    if (!$enc || strncmp($enc, 's1:', 3) !== 0) return '';
    $b = base64_decode(substr($enc, 3), true); if ($b === false || strlen($b) < 29) return '';
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', ss_key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $p === false ? '' : $p;
}
/** Firma corta para enlaces públicos (medios que Meta descarga). */
function ss_sign(string $data): string { return substr(hash_hmac('sha256', $data, ss_key() . '|link'), 0, 32); }

// ═══════════════════════════════════════════════════════════
// APP DE META (la carga un admin del sitio)
// ═══════════════════════════════════════════════════════════
/** Direcciones de Meta. Solo un archivo del servidor (_private/site.php o las pruebas) puede cambiarlas: nunca la pantalla. */
function ss_meta_graph(): string { return rtrim((string)g7_site('social_meta_graph', 'https://graph.facebook.com'), '/'); }
function ss_meta_www(): string { return rtrim((string)g7_site('social_meta_www', 'https://www.facebook.com'), '/'); }
function ss_meta_cfg(): array {
    if (function_exists('ss_hub_is_client') && ss_hub_is_client()) {   // central connection: the app lives in the central; here only what it allows
        $m = (array)(ss_hub_info_cached()['meta'] ?? []);
        return ['app_id' => '', 'secret_enc' => '', 'version' => preg_match('/^v\d{1,2}\.\d$/', (string)($m['version'] ?? '')) ? $m['version'] : SS_META_VERSION, 'config_id' => '', 'verify' => '',
                'webhooks' => (bool)($m['webhooks'] ?? false), 'publish' => (bool)($m['publish'] ?? false), 'inbox' => (bool)($m['inbox'] ?? false), 'analytics' => (bool)($m['analytics'] ?? false),
                'human_agent' => (bool)($m['human_agent'] ?? false), 'central' => true, 'ready' => !empty($m['ready'])];
    }
    $c = g7_cfg_json('ss_meta', []);
    $v = (string)($c['version'] ?? '');
    return ['app_id' => (string)($c['app_id'] ?? ''), 'secret_enc' => (string)($c['secret_enc'] ?? ''), 'version' => preg_match('/^v\d{1,2}\.\d$/', $v) ? $v : SS_META_VERSION,
            'config_id' => (string)($c['config_id'] ?? ''), 'verify' => (string)($c['verify'] ?? ''), 'webhooks' => (bool)($c['webhooks'] ?? true), 'publish' => (bool)($c['publish'] ?? true),
            'inbox' => (bool)($c['inbox'] ?? true), 'analytics' => (bool)($c['analytics'] ?? true), 'human_agent' => (bool)($c['human_agent'] ?? false)];
}
function ss_meta_secret(): string { return ss_decrypt(ss_meta_cfg()['secret_enc']); }
function ss_meta_ready(): bool { $c = ss_meta_cfg(); if (!empty($c['central'])) return $c['ready']; return $c['app_id'] !== '' && $c['secret_enc'] !== '' && ss_meta_secret() !== ''; }
function ss_meta_redirect_uri(): string { return g7_site_origin() . '/toolz/social/oauth.php'; }
function ss_meta_webhook_uri(): string { return g7_site_origin() . '/toolz/social/webhook.php?p=meta'; }
function ss_meta_scopes(): array {
    $c = ss_meta_cfg();
    return array_values(array_unique(array_merge(SS_META_SCOPES['base'], $c['publish'] ? SS_META_SCOPES['publish'] : [], $c['webhooks'] ? SS_META_SCOPES['webhook'] : [], $c['inbox'] ? SS_META_SCOPES['inbox'] : [], $c['analytics'] ? SS_META_SCOPES['analytics'] : [])));
}
/** Lo que ve la pantalla (nunca el secreto). */
function ss_meta_public(array $ctx): array {
    $c = ss_meta_cfg();
    $out = ['ready' => ss_meta_ready(), 'plan_ok' => ss_plan_net_ok('facebook') || ss_plan_net_ok('instagram'), 'central' => !empty($c['central']), 'app_id' => $c['app_id'], 'secret_saved' => $c['secret_enc'] !== '', 'version' => $c['version'], 'config_id' => $c['config_id'],
            'webhooks' => $c['webhooks'], 'publish' => $c['publish'], 'inbox' => $c['inbox'], 'analytics' => $c['analytics'], 'human_agent' => $c['human_agent'], 'redirect_uri' => ss_meta_redirect_uri(), 'webhook_uri' => ss_meta_webhook_uri(),
            'scopes' => array_map(fn($s) => ['id' => $s, 'label' => SS_META_SCOPE_INFO[$s] ?? $s], ss_meta_scopes()), 'later' => SS_META_LATER,
            'https' => str_starts_with(g7_site_origin(), 'https://'), 'can_edit' => $ctx['admin'] && empty($c['central'])];
    if ($ctx['admin']) $out['verify'] = $c['verify'];   // el token de verificación del webhook solo lo ve un admin (se pega en Meta)
    return $out;
}
function ss_meta_cfg_save(PDO $db, array $ctx, array $in): void {
    if (!$ctx['admin']) ss_fail('La app de Meta la configura un administrador del sitio.', 403);
    $c = g7_cfg_json('ss_meta', []);
    $app = trim((string)($in['app_id'] ?? ''));
    if ($app !== '' && !preg_match('/^\d{5,25}$/', $app)) ss_fail('El App ID son solo números (lo ves arriba a la izquierda en developers.facebook.com).');
    $c['app_id'] = $app;
    $sec = trim((string)($in['app_secret'] ?? ''));
    if ($sec !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/i', $sec)) ss_fail('El App Secret tiene 32 caracteres (letras a-f y números). Está en Configuración de la app › Básica.');
        $c['secret_enc'] = ss_encrypt($sec);
    }
    if (!empty($in['clear_secret'])) $c['secret_enc'] = '';
    $v = trim((string)($in['version'] ?? ''));
    if ($v !== '' && !preg_match('/^v\d{1,2}\.\d$/', $v)) ss_fail('La versión se escribe así: v27.0');
    $c['version'] = $v ?: SS_META_VERSION;
    $cfgId = trim((string)($in['config_id'] ?? ''));
    if ($cfgId !== '' && !preg_match('/^\d{5,25}$/', $cfgId)) ss_fail('El ID de configuración de Facebook Login for Business son solo números.');
    $c['config_id'] = $cfgId;
    $c['webhooks'] = !empty($in['webhooks']); $c['publish'] = !empty($in['publish']); $c['inbox'] = !empty($in['inbox']); $c['analytics'] = !empty($in['analytics']); $c['human_agent'] = !empty($in['human_agent']);
    if (empty($c['verify']) || !empty($in['new_verify'])) $c['verify'] = bin2hex(random_bytes(16));
    g7_cfg_set('ss_meta', json_encode($c));
    g7_audit('social.meta_app', $app, ['secret_changed' => $sec !== '', 'version' => $c['version'], 'login_for_business' => $cfgId !== '']);
}

// ═══════════════════════════════════════════════════════════
// LLAMADAS A LA GRAPH API (reintentos, registro, sin tokens en los logs)
// ═══════════════════════════════════════════════════════════
class SsMetaError extends SsError {
    public int $metaCode = 0; public int $http = 0;
}
function ss_log_call(PDO $db, ?int $accountId, string $action, bool $ok, int $http, string $msg): void {
    $msg = preg_replace('/(access_token|client_secret|fb_exchange_token|appsecret_proof|code)=[^&\s"]+/i', '$1=***', $msg);
    try { $db->prepare('INSERT INTO g7_ss_sync_log (account_id, action, ok, http, msg, at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$accountId, $action, $ok ? 1 : 0, $http, mb_substr($msg, 0, 600), ss_now()]); } catch (Throwable $e) {}
}
/**
 * Llama a la Graph API. $params van en la URL (GET/DELETE) o en el cuerpo (POST). Reintenta 429 y 5xx con espera corta
 * (hasta 3 intentos); los errores de Meta salen como SsMetaError con su código (190 = token vencido o revocado).
 */
function ss_meta_call(PDO $db, string $method, string $path, array $params = [], ?int $accountId = null, string $action = 'graph'): array {
    $c = ss_meta_cfg();
    $url = ss_meta_graph() . '/' . $c['version'] . '/' . ltrim($path, '/');
    if (!empty($params['access_token'])) {
        if (($sec = ss_meta_secret()) !== '') $params['appsecret_proof'] = hash_hmac('sha256', $params['access_token'], $sec);
        elseif (function_exists('ss_hub_is_client') && ss_hub_is_client() && ($pf = ss_hub_proof($params['access_token'])) !== '') $params['appsecret_proof'] = $pf;   // calculated by the central
    }
    $tries = 0; $resp = null; $code = 0; $err = '';
    while ($tries++ < 3) {
        $ch = curl_init();
        $u = $url;
        if ($method === 'GET' || $method === 'DELETE') $u .= ($params ? (str_contains($u, '?') ? '&' : '?') . http_build_query($params) : '');
        curl_setopt_array($ch, [CURLOPT_URL => $u, CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_SSL_VERIFYPEER => true]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($resp !== false && $code < 500 && $code !== 429) break;
        if ($tries < 3) usleep($tries * 700000);
    }
    if ($resp === false) { ss_log_call($db, $accountId, $action, false, 0, "$method $path: sin conexión ($err)"); $e = new SsMetaError('No se pudo hablar con Meta. Probá de nuevo en un rato.', 502); throw $e; }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) $j = [];
    if ($code >= 400 || isset($j['error'])) {
        $me = (array)($j['error'] ?? []);
        $mc = (int)($me['code'] ?? 0); $msg = (string)($me['error_user_msg'] ?? $me['message'] ?? 'Error de Meta');
        ss_log_call($db, $accountId, $action, false, $code, "$method $path: [$mc] $msg");
        $friendly = $mc === 190 ? 'La conexión con Meta venció o la revocaron: renová la cuenta.' : ($mc === 4 || $mc === 17 || $mc === 32 || $mc === 613 || $code === 429 ? 'Meta pidió esperar (límite de uso). Se reintenta más tarde.'
            : ($mc === 10 || $mc === 200 ? 'Falta un permiso en Meta para esto: renová la conexión aceptando todos los permisos (o la app todavía no tiene la aprobación de Meta).' : 'Meta respondió: ' . mb_substr($msg, 0, 240)));
        $e = new SsMetaError($friendly, $code >= 500 ? 502 : 400); $e->metaCode = $mc; $e->http = $code;
        throw $e;
    }
    ss_log_call($db, $accountId, $action, true, $code, "$method $path");
    return $j;
}

// ═══════════════════════════════════════════════════════════
// OAUTH: conectar y renovar
// ═══════════════════════════════════════════════════════════
/** Link al diálogo de Meta. Devuelve [url, nonce] (el nonce va en una cookie Lax de este navegador). */
function ss_meta_auth_url(PDO $db, array $ctx): array {
    if (empty($ctx['can']['accounts'])) ss_need($ctx, 'accounts');
    if (ss_hub_is_client()) return ss_hub_start_url($db, $ctx, 'meta');
    if (!ss_meta_ready()) ss_fail('Primero hay que cargar la app de Meta (Cuentas › App de Meta).');
    if (!ss_rate_ok($db, 'oauth:' . $ctx['id'], 10, 600)) ss_fail('Demasiados intentos de conexión: esperá unos minutos.', 429);
    return ss_meta_auth_make($db, (int)$ctx['id']);
}
/** State + nonce + link to Meta's dialog. $site/$cs: when the central connects on behalf of another site (hub_lib.php). */
function ss_meta_auth_make(PDO $db, int $uid, ?int $site = null, string $cs = ''): array {
    $state = bin2hex(random_bytes(24)); $nonce = bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO g7_ss_oauth (user_id, provider, state_hash, nonce_hash, site_id, client_state, created_at, expires_at) VALUES (?, 'meta', ?, ?, ?, ?, ?, ?)")
       ->execute([$uid, hash('sha256', $state), hash('sha256', $nonce), $site, $cs, ss_now(), date('Y-m-d H:i:s', time() + 900)]);
    $db->exec("DELETE FROM g7_ss_oauth WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $c = ss_meta_cfg();
    $q = ['client_id' => $c['app_id'], 'redirect_uri' => ss_meta_redirect_uri(), 'state' => $state, 'response_type' => 'code'];
    if ($c['config_id'] !== '') $q['config_id'] = $c['config_id'];   // Facebook Login for Business: los permisos salen de la configuración armada en Meta
    else $q['scope'] = implode(',', ss_meta_scopes());
    return [ss_meta_www() . '/' . $c['version'] . '/dialog/oauth?' . http_build_query($q), $nonce];
}

/**
 * Vuelta de Meta (oauth.php). Valida state + nonce, cambia el code por un token de larga duración, lee las páginas y las
 * cuentas de Instagram vinculadas y deja la lista cifrada para que la persona elija. Devuelve el id de la conexión.
 */
function ss_meta_callback(PDO $db, string $state, string $nonce, string $code): int {
    $q = $db->prepare("SELECT * FROM g7_ss_oauth WHERE state_hash = ? AND provider = 'meta'"); $q->execute([hash('sha256', $state)]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['used_at'] || strtotime($o['expires_at']) < time()) ss_fail('El link de conexión venció. Volvé a Social Studio › Cuentas y tocá "Conectar" otra vez.', 400);
    if ($nonce === '' || !hash_equals($o['nonce_hash'], hash('sha256', $nonce))) ss_fail('Abrí la conexión desde el mismo navegador donde tocaste "Conectar".', 400);
    $db->prepare('UPDATE g7_ss_oauth SET used_at = ? WHERE id = ?')->execute([ss_now(), (int)$o['id']]);   // un solo uso, salga bien o mal
    $c = ss_meta_cfg(); $sec = ss_meta_secret();
    $t = ss_meta_call($db, 'GET', 'oauth/access_token', ['client_id' => $c['app_id'], 'redirect_uri' => ss_meta_redirect_uri(), 'client_secret' => $sec, 'code' => $code], null, 'oauth_code');
    $short = (string)($t['access_token'] ?? '');
    if ($short === '') ss_fail('Meta no devolvió el permiso. Probá de nuevo.', 502);
    $l = ss_meta_call($db, 'GET', 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'], 'client_secret' => $sec, 'fb_exchange_token' => $short], null, 'oauth_long');
    $user = (string)($l['access_token'] ?? $short);
    $exp = !empty($l['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$l['expires_in']) : null;
    $me = ss_meta_call($db, 'GET', 'me', ['fields' => 'id,name', 'access_token' => $user], null, 'oauth_me');
    $perms = ss_meta_call($db, 'GET', 'me/permissions', ['access_token' => $user], null, 'oauth_perms');
    $granted = []; $declined = [];
    foreach ((array)($perms['data'] ?? []) as $p) { if (($p['status'] ?? '') === 'granted') $granted[] = (string)$p['permission']; else $declined[] = (string)$p['permission']; }
    $cands = []; $after = null; $guard = 0;
    do {
        $params = ['fields' => 'id,name,access_token,tasks,picture{url},instagram_business_account{id,username,name,profile_picture_url}', 'limit' => 100, 'access_token' => $user];
        if ($after) $params['after'] = $after;
        $r = ss_meta_call($db, 'GET', 'me/accounts', $params, null, 'oauth_pages');
        foreach ((array)($r['data'] ?? []) as $pg) {
            if (empty($pg['id']) || empty($pg['access_token'])) continue;
            $cands[] = ['kind' => 'fb_page', 'ext_id' => (string)$pg['id'], 'name' => (string)($pg['name'] ?? ''), 'username' => '', 'picture' => (string)($pg['picture']['data']['url'] ?? ''),
                        'page_id' => (string)$pg['id'], 'token' => (string)$pg['access_token'], 'tasks' => array_values((array)($pg['tasks'] ?? []))];
            if (!empty($pg['instagram_business_account']['id'])) {
                $ig = $pg['instagram_business_account'];
                $cands[] = ['kind' => 'ig_business', 'ext_id' => (string)$ig['id'], 'name' => (string)($ig['name'] ?? $ig['username'] ?? ''), 'username' => (string)($ig['username'] ?? ''),
                            'picture' => (string)($ig['profile_picture_url'] ?? ''), 'page_id' => (string)$pg['id'], 'token' => (string)$pg['access_token'], 'tasks' => []];
            }
        }
        $after = $r['paging']['cursors']['after'] ?? null;
        if (empty($r['paging']['next'])) $after = null;
    } while ($after && ++$guard < 10);
    // appsecret_proof of each page token: a site of the central connection cannot calculate it (it has no secret), so it travels with the account
    foreach ($cands as &$cd) $cd['proof'] = hash_hmac('sha256', $cd['token'], $sec);
    unset($cd);
    $payload = ['user_ext_id' => (string)($me['id'] ?? ''), 'user_name' => (string)($me['name'] ?? ''), 'user_token' => $user, 'user_expires' => $exp,
                'granted' => $granted, 'declined' => $declined, 'cands' => $cands];
    $db->prepare('UPDATE g7_ss_oauth SET payload_enc = ?, expires_at = ? WHERE id = ?')->execute([ss_encrypt(json_encode($payload)), date('Y-m-d H:i:s', time() + 1800), (int)$o['id']]);
    ss_log_call($db, null, 'oauth_ok', true, 200, count($cands) . ' cuentas disponibles para la persona ' . (int)$o['user_id']);
    return (int)$o['id'];
}

/** Conexión pendiente de elegir (sin tokens). Solo la ve quien la empezó, por 30 minutos. */
/** Pending connection of any network (Meta or Stage 6). Adds 'provider' to the payload. */
function ss_meta_pending_payload(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT * FROM g7_ss_oauth WHERE id = ? AND user_id = ?'); $q->execute([$id, $ctx['id']]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || !$o['payload_enc'] || strtotime($o['expires_at']) < time()) ss_fail('Esa conexión ya venció o no es tuya. Tocá "Conectar" otra vez.', 404);
    $p = json_decode(ss_decrypt($o['payload_enc']), true);
    if (!is_array($p) || !isset($p['cands'])) ss_fail('No se pudo leer la conexión. Tocá "Conectar" otra vez.', 400);
    $p['provider'] = (string)$o['provider'];
    return $p;
}
function ss_meta_pending(PDO $db, array $ctx, int $id): array {
    ss_need($ctx, 'accounts');
    $p = ss_meta_pending_payload($db, $ctx, $id);
    $existing = [];
    $q = $db->prepare('SELECT kind, ext_id, id, brand_id, client_id, status FROM g7_ss_accounts WHERE provider = ?'); $q->execute([$p['provider']]);
    foreach ($q as $r) $existing[$r['kind'] . ':' . $r['ext_id']] = $r;
    $need = $p['provider'] === 'meta' ? ss_meta_scopes() : ss_net_fn($p['provider'], 'scopes', ss_net_cfg($p['provider']));
    return ['plan' => ss_plan($db), 'provider' => $p['provider'], 'provider_name' => $p['provider'] === 'meta' ? 'Meta' : SS_PROVIDERS[$p['provider']]['name'],
            'user' => $p['user_name'], 'granted' => $p['granted'], 'declined' => $p['declined'], 'missing' => array_values(array_diff($need, $p['granted'])),
            'cands' => array_map(function ($c) use ($existing) {
                $e = $existing[$c['kind'] . ':' . $c['ext_id']] ?? null;
                return ['kind' => $c['kind'], 'kind_name' => ss_kind_name($c['kind']), 'network' => ss_kind_network($c['kind']), 'ext_id' => $c['ext_id'], 'name' => $c['name'], 'username' => $c['username'], 'picture' => $c['picture'],
                        'page_id' => $c['page_id'] ?? '', 'existing' => $e ? ['id' => (int)$e['id'], 'brand_id' => $e['brand_id'] ? (int)$e['brand_id'] : null, 'client_id' => $e['client_id'] ? (int)$e['client_id'] : null, 'status' => $e['status']] : null,
                        'can_post' => isset($c['can_post']) ? (bool)$c['can_post'] : ($c['kind'] === 'ig_business' || in_array('CREATE_CONTENT', $c['tasks'] ?? [], true) || empty($c['tasks'])),
                        'note' => (string)($c['note'] ?? ''), 'plan_ok' => ss_plan_net_ok(ss_kind_network($c['kind']))];
            }, $p['cands'])];
}

/**
 * Guarda las cuentas elegidas. $picks: [{kind, ext_id, brand_id?, client_id?}]. Las que ya estaban se renuevan (token nuevo)
 * y conservan su marca si no se cambia. Suscribe cada página a los webhooks si está prendido. Devuelve cuántas.
 */
function ss_meta_save(PDO $db, array $ctx, int $id, array $picks): int {
    ss_need($ctx, 'accounts');
    $p = ss_meta_pending_payload($db, $ctx, $id);
    $byKey = []; foreach ($p['cands'] as $c) $byKey[$c['kind'] . ':' . $c['ext_id']] = $c;
    $picks = array_values(array_filter($picks, fn($x) => is_array($x) && isset($byKey[($x['kind'] ?? '') . ':' . ($x['ext_id'] ?? '')])));
    if (!$picks) ss_fail('Elegí al menos una cuenta.');
    // Plan: networks included and maximum of connected accounts (renewing an account already connected does not count)
    $new = 0;
    foreach ($picks as $x) {
        $c = $byKey[$x['kind'] . ':' . $x['ext_id']];
        if (!ss_plan_net_ok(ss_kind_network($c['kind']))) ss_plan_fail('conectar cuentas de ' . (SS_NETWORKS[ss_kind_network($c['kind'])][0] ?? ss_kind_network($c['kind'])));
        $q = $db->prepare("SELECT 1 FROM g7_ss_accounts WHERE provider = ? AND kind = ? AND ext_id = ? AND token_enc IS NOT NULL AND token_enc <> ''"); $q->execute([$p['provider'], $c['kind'], $c['ext_id']]);
        if (!$q->fetchColumn()) $new++;
    }
    $max = function_exists('lic_cap') ? lic_cap('social_accounts') : null;
    if ($max !== null && $new && ss_plan_accounts_used($db) + $new > $max) {
        $left = max(0, (int)$max - ss_plan_accounts_used($db));
        ss_fail('Tu plan incluye ' . (int)$max . ' cuentas de redes conectadas' . ($left ? ': podés sumar ' . $left . ' más.' : ' y ya están todas en uso.') . ' Para sumar más, mejorá el plan desde Admin › Licencia.', 403);
    }
    $scopes = implode(',', $p['granted']); $now = ss_now(); $n = 0; $subscribed = [];
    $prov = $p['provider']; $routes = [];
    $cfg = $prov === 'meta' ? ss_meta_cfg() : ['webhooks' => false, 'inbox' => false];
    foreach ($picks as $x) {
        $c = $byKey[$x['kind'] . ':' . $x['ext_id']];
        $bid = (int)($x['brand_id'] ?? 0) ?: null; $cid = (int)($x['client_id'] ?? 0) ?: null;
        if ($bid && !isset(g7_brands(true)[$bid])) ss_fail('Una de las marcas elegidas no existe.');
        if ($bid && $ctx['brands'] !== null && !in_array($bid, $ctx['brands'], true)) ss_fail('No tenés acceso a una de las marcas elegidas.', 403);
        if ($cid) { $q = $db->prepare('SELECT 1 FROM g7_clients WHERE id = ?'); $q->execute([$cid]); if (!$q->fetchColumn()) ss_fail('Uno de los clientes elegidos no existe.'); }
        $q = $db->prepare('SELECT * FROM g7_ss_accounts WHERE provider = ? AND kind = ? AND ext_id = ?'); $q->execute([$prov, $c['kind'], $c['ext_id']]);
        $old = $q->fetch(PDO::FETCH_ASSOC);
        if ($old && $ctx['brands'] !== null && $old['brand_id'] && !in_array((int)$old['brand_id'], $ctx['brands'], true)) ss_fail('"' . $c['name'] . '" ya está conectada en una marca que no ves.', 403);
        // Meta: a page token (string). Other networks: the encrypted bundle (access + refresh + expiry) of the connection.
        $bundle = $prov === 'meta' ? null : ($c['bundle'] ?? $p['bundle']);
        $tok = ss_encrypt($bundle === null ? $c['token'] : json_encode($bundle));
        $texp = $bundle && !empty($bundle['exp']) ? date('Y-m-d H:i:s', (int)$bundle['exp']) : null;
        $pageId = (string)($c['page_id'] ?? '');
        $proof = !empty($c['proof']) && ss_hub_is_client() ? ss_encrypt((string)$c['proof']) : null;
        if ($old) {
            $db->prepare("UPDATE g7_ss_accounts SET name = ?, username = ?, picture = ?, page_id = ?, brand_id = ?, client_id = ?, token_enc = ?, token_expires = ?, proof_enc = ?, scopes = ?, status = 'ok', status_msg = '', user_ext_id = ?, connected_by = ?, last_check_at = ?, updated_at = ? WHERE id = ?")
               ->execute([$c['name'], $c['username'], $c['picture'], $pageId, array_key_exists('brand_id', $x) ? $bid : $old['brand_id'], array_key_exists('client_id', $x) ? $cid : $old['client_id'], $tok, $texp, $proof, $scopes, $p['user_ext_id'], $ctx['id'], $now, $now, (int)$old['id']]);
            $accId = (int)$old['id'];
        } else {
            $db->prepare("INSERT INTO g7_ss_accounts (provider, kind, ext_id, name, username, picture, page_id, brand_id, client_id, token_enc, token_expires, proof_enc, scopes, status, user_ext_id, connected_by, connected_at, last_check_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ok', ?, ?, ?, ?, ?)")
               ->execute([$prov, $c['kind'], $c['ext_id'], $c['name'], $c['username'], $c['picture'], $pageId, $bid, $cid, $tok, $texp, $proof, $scopes, $p['user_ext_id'], $ctx['id'], $now, $now, $now]);
            $accId = (int)$db->lastInsertId();
        }
        if ($prov === 'meta') $routes[] = ['kind' => $c['kind'], 'ext_id' => $c['ext_id'], 'token' => $c['token']];
        // Webhooks: se suscribe la página (una vez por página; las cuentas de Instagram vinculadas avisan por ella)
        if ($prov === 'meta' && $cfg['webhooks'] && !isset($subscribed[$c['page_id']])) {
            $subscribed[$c['page_id']] = true;
            try {
                ss_meta_call($db, 'POST', $c['page_id'] . '/subscribed_apps', ['subscribed_fields' => $cfg['inbox'] ? 'feed,messages' : 'feed', 'access_token' => $c['token']], $accId, 'subscribe');
                $db->prepare("UPDATE g7_ss_accounts SET subscribed = 1 WHERE provider = 'meta' AND page_id = ?")->execute([$c['page_id']]);
            } catch (SsError $e) {
                $db->prepare('UPDATE g7_ss_accounts SET status_msg = ? WHERE id = ?')->execute(['Conectada, pero no se pudieron activar los avisos: ' . mb_substr($e->getMessage(), 0, 200), $accId]);
            }
        }
        $n++;
    }
    // The Instagram accounts linked to a subscribed page notify through it
    foreach (array_keys($subscribed) as $pg) $db->prepare("UPDATE g7_ss_accounts SET subscribed = 1 WHERE provider = 'meta' AND page_id = ? AND status_msg NOT LIKE 'Conectada, pero%'")->execute([$pg]);
    $db->prepare('UPDATE g7_ss_oauth SET payload_enc = NULL WHERE id = ?')->execute([$id]);   // los tokens de las que no se eligieron se descartan
    ss_hub_routes($db, $routes);   // central connection: the central passes Meta's notifications of these pages to this site
    g7_audit('social.accounts_connect', $prov, ['n' => $n, 'by_network_user' => $p['user_ext_id']]);
    return $n;
}

// ═══════════════════════════════════════════════════════════
// CUENTAS
// ═══════════════════════════════════════════════════════════
function ss_acc_scope_sql(array $ctx): string { return str_replace('p.brand_id', 'a.brand_id', ss_scope_sql($ctx)); }
function ss_account_row(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT a.* FROM g7_ss_accounts a WHERE a.id = ? AND ' . ss_acc_scope_sql($ctx)); $q->execute([$id]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) ss_fail('Esa cuenta no existe o no está en tus marcas.', 404);
    return $a;
}
function ss_account_out(array $a): array {
    return ['id' => (int)$a['id'], 'provider' => $a['provider'], 'kind' => $a['kind'], 'kind_name' => ss_kind_name($a['kind']), 'ext_id' => $a['ext_id'],
            'name' => $a['name'], 'username' => $a['username'], 'picture' => $a['picture'], 'brand_id' => $a['brand_id'] ? (int)$a['brand_id'] : null,
            'client_id' => $a['client_id'] ? (int)$a['client_id'] : null, 'status' => $a['status'], 'status_msg' => $a['status_msg'], 'connected' => $a['token_enc'] !== null && $a['token_enc'] !== '',
            'scopes' => array_values(array_filter(explode(',', (string)$a['scopes']))), 'subscribed' => (bool)$a['subscribed'], 'connected_at' => $a['connected_at'],
            'last_check_at' => $a['last_check_at'], 'last_sync_at' => $a['last_sync_at'], 'network' => ss_kind_network($a['kind']), 'url' => ss_account_url($a),
            'conn_expires' => $a['token_expires']];
}
function ss_accounts(PDO $db, array $ctx): array {
    return array_map('ss_account_out', $db->query('SELECT a.* FROM g7_ss_accounts a WHERE ' . ss_acc_scope_sql($ctx) . ' ORDER BY a.status = \'disconnected\', a.name')->fetchAll(PDO::FETCH_ASSOC));
}
/** Saved access token (Meta: the page token; other networks: the access of the bundle, without renewing it — use ss_net_token to call). */
function ss_account_token(array $a): string {
    $raw = ss_decrypt($a['token_enc'] ?? '');
    if (($a['provider'] ?? 'meta') === 'meta' || $raw === '') return $raw;
    $j = json_decode($raw, true);
    return is_array($j) ? (string)($j['access'] ?? '') : '';
}

/** Verifica una cuenta contra su red (nombre, foto) y actualiza su estado. */
function ss_account_check(PDO $db, array $a): array {
    $tok = ss_account_token($a);
    if ($tok === '') return $a;
    if ($a['provider'] !== 'meta') { ss_net_check($db, $a); return ss_net_account($db, (int)$a['id']); }
    try {
        $f = $a['kind'] === 'ig_business' ? 'id,username,name,profile_picture_url' : 'id,name,picture{url}';
        $r = ss_meta_call($db, 'GET', $a['ext_id'], ['fields' => $f, 'access_token' => $tok], (int)$a['id'], 'check');
        $pic = $a['kind'] === 'ig_business' ? (string)($r['profile_picture_url'] ?? $a['picture']) : (string)($r['picture']['data']['url'] ?? $a['picture']);
        $db->prepare("UPDATE g7_ss_accounts SET name = ?, username = ?, picture = ?, status = 'ok', status_msg = IF(status_msg LIKE 'Conectada, pero%', status_msg, ''), last_check_at = ?, updated_at = ? WHERE id = ?")
           ->execute([(string)($r['name'] ?? $r['username'] ?? $a['name']), (string)($r['username'] ?? $a['username']), $pic, ss_now(), ss_now(), (int)$a['id']]);
    } catch (SsMetaError $e) {
        $st = $e->metaCode === 190 ? 'expired' : 'error';
        $db->prepare('UPDATE g7_ss_accounts SET status = ?, status_msg = ?, last_check_at = ?, updated_at = ? WHERE id = ?')->execute([$st, mb_substr($e->getMessage(), 0, 300), ss_now(), ss_now(), (int)$a['id']]);
        if ($st === 'expired') ss_notify($db, ss_account_admins($db, $a), 0, null, 'Venció la conexión de ' . ($a['username'] ? '@' . $a['username'] : $a['name']) . ': renovala en Social Studio › Cuentas');
    }
    $q = $db->prepare('SELECT * FROM g7_ss_accounts WHERE id = ?'); $q->execute([(int)$a['id']]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
/** Quién maneja las cuentas (permiso accounts y ve la marca): a ellos van los avisos de vencimiento. */
function ss_account_admins(PDO $db, array $a): array {
    $out = [];
    foreach ($db->query('SELECT id FROM g7_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $uid = (int)$uid;
        if (!g7_can($uid, 'social', 'accounts')) continue;
        if ($a['brand_id']) { $b = g7_user_brand_ids($uid); if ($b !== null && !in_array((int)$a['brand_id'], $b, true)) continue; }
        $out[] = $uid;
    }
    return $out;
}
function ss_account_update(PDO $db, array $ctx, int $id, array $in): void {
    ss_need($ctx, 'accounts');
    $a = ss_account_row($db, $ctx, $id);
    $bid = (int)($in['brand_id'] ?? 0) ?: null; $cid = (int)($in['client_id'] ?? 0) ?: null;
    if ($bid && !isset(g7_brands(true)[$bid])) ss_fail('Esa marca no existe.');
    if ($bid && $ctx['brands'] !== null && !in_array($bid, $ctx['brands'], true)) ss_fail('No tenés acceso a esa marca.', 403);
    if ($cid) { $q = $db->prepare('SELECT 1 FROM g7_clients WHERE id = ?'); $q->execute([$cid]); if (!$q->fetchColumn()) ss_fail('Ese cliente no existe.'); }
    $db->prepare('UPDATE g7_ss_accounts SET brand_id = ?, client_id = ?, updated_at = ? WHERE id = ?')->execute([$bid, $cid, ss_now(), $id]);
    g7_audit('social.account_update', '#' . $id, ['brand_id' => $bid, 'client_id' => $cid]);
}
/**
 * Desconecta: borra el token guardado, y si es la última cuenta de esa página, le saca la suscripción a los avisos.
 * La cuenta queda en la lista como "desconectada" para el historial de lo publicado. $forget = borrarla de la lista.
 */
function ss_account_disconnect(PDO $db, array $ctx, int $id, bool $forget = false): void {
    ss_need($ctx, 'accounts');
    $a = ss_account_row($db, $ctx, $id);
    $tok = ss_account_token($a);
    if ($a['provider'] !== 'meta') { if ($tok !== '') ss_net_disconnect($db, $a); }
    else {
    $q = $db->prepare("SELECT COUNT(*) FROM g7_ss_accounts WHERE provider = 'meta' AND page_id = ? AND id <> ? AND token_enc IS NOT NULL AND token_enc <> ''"); $q->execute([$a['page_id'], $id]);
    if ($tok !== '' && $a['subscribed'] && !(int)$q->fetchColumn()) {
        try { ss_meta_call($db, 'DELETE', $a['page_id'] . '/subscribed_apps', ['access_token' => $tok], $id, 'unsubscribe'); } catch (SsError $e) {}
    }
    }
    $db->prepare("UPDATE g7_ss_targets SET status = 'error', error = 'La cuenta se desconectó antes de publicar', updated_at = ? WHERE account_id = ? AND status IN ('scheduled', 'processing')")->execute([ss_now(), $id]);
    if ($a['provider'] === 'meta') ss_hub_routes($db, [], [['kind' => $a['kind'], 'ext_id' => $a['ext_id']]]);
    if ($forget) $db->prepare('DELETE FROM g7_ss_accounts WHERE id = ?')->execute([$id]);
    else $db->prepare("UPDATE g7_ss_accounts SET token_enc = NULL, status = 'disconnected', status_msg = '', subscribed = 0, updated_at = ? WHERE id = ?")->execute([ss_now(), $id]);
    g7_audit('social.account_disconnect', '#' . $id, ['name' => $a['name'], 'forget' => $forget]);
}
function ss_account_log(PDO $db, array $ctx, int $id): array {
    ss_account_row($db, $ctx, $id);
    $q = $db->prepare('SELECT action, ok, http, msg, at FROM g7_ss_sync_log WHERE account_id = ? ORDER BY id DESC LIMIT 50'); $q->execute([$id]);
    return array_map(fn($r) => ['action' => $r['action'], 'ok' => (bool)$r['ok'], 'http' => (int)$r['http'], 'msg' => $r['msg'], 'at' => $r['at']], $q->fetchAll(PDO::FETCH_ASSOC));
}

// ═══════════════════════════════════════════════════════════
// WEBHOOK (lo que manda Meta; se procesa en la Etapa 3)
// ═══════════════════════════════════════════════════════════
/** Verificación GET de Meta. Devuelve el challenge si el token coincide, o null. */
function ss_webhook_verify(array $get): ?string {
    $c = ss_meta_cfg();
    if (($get['hub_mode'] ?? '') !== 'subscribe' || $c['verify'] === '' || !hash_equals($c['verify'], (string)($get['hub_verify_token'] ?? ''))) return null;
    $ch = (string)($get['hub_challenge'] ?? '');
    return preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $ch) ? $ch : null;
}
/** Firma del cuerpo crudo con el App Secret (X-Hub-Signature-256: sha256=<hex>). */
function ss_webhook_sig_ok(string $raw, string $header): bool {
    $sec = ss_meta_secret();
    if ($sec === '' || !str_starts_with($header, 'sha256=')) return false;
    return hash_equals(hash_hmac('sha256', $raw, $sec), substr($header, 7));
}
function ss_webhook_store(PDO $db, string $raw): int {
    $j = json_decode($raw, true);
    $db->prepare("INSERT INTO g7_ss_webhook (provider, object, payload, received_at) VALUES ('meta', ?, ?, ?)")->execute([mb_substr((string)($j['object'] ?? ''), 0, 40), $raw, ss_now()]);
    if (random_int(1, 100) === 1) $db->exec("DELETE FROM g7_ss_webhook WHERE received_at < NOW() - INTERVAL 30 DAY");
    return (int)$db->lastInsertId();
}

// ═══════════════════════════════════════════════════════════
// PUBLICAR
// ═══════════════════════════════════════════════════════════
/** Enlace público y temporal a un archivo de la publicación, para que Meta lo descargue (social_media.php). $jpg = convertirlo a JPEG. */
function ss_media_url(array $file, bool $jpg = false, int $ttl = 7200): string {
    $exp = time() + $ttl; $j = $jpg ? 1 : 0;
    return g7_site_origin() . '/toolz/social_media.php?' . http_build_query(['f' => (int)$file['id'], 'e' => $exp, 'j' => $j, 's' => ss_sign("media|{$file['id']}|$exp|$j")]);
}
const SS_IG_IMG = ['jpg', 'jpeg', 'png', 'webp'];   // Instagram pide JPEG: los PNG y WebP se convierten al servirlos
const SS_VIDEO = ['mp4', 'mov', 'm4v'];

/** Qué hay que mandar a cada red según el formato y los archivos. Devuelve ['kind' => text|image|carousel|video|story, 'images' => [...], 'video' => file|null] o corta con el motivo. */
function ss_publish_plan(array $post, array $files, string $network): array {
    $imgs = array_values(array_filter($files, fn($f) => in_array($f['ext'], SS_IG_IMG, true)));
    $vids = array_values(array_filter($files, fn($f) => in_array($f['ext'], SS_VIDEO, true)));
    $fmt = $post['format'];
    if ($fmt === 'personalizado' || $fmt === 'imagen' && !$imgs && $vids) $fmt = $vids ? 'video' : (count($imgs) > 1 ? 'carrusel' : ($imgs ? 'imagen' : 'texto'));
    $net = $network === 'instagram' ? 'Instagram' : 'Facebook';
    switch ($fmt) {
        case 'texto':
            if ($network === 'instagram') ss_fail('Instagram no publica solo texto: sumá una imagen o un video, o cambiá el formato.');
            return ['kind' => 'text', 'images' => [], 'video' => null];
        case 'imagen':
            if (!$imgs) ss_fail("Para publicar una imagen en $net falta subirla (JPG, PNG o WebP) en Archivos.");
            return ['kind' => 'image', 'images' => [$imgs[0]], 'video' => null];
        case 'carrusel':
            if (count($imgs) < 2) ss_fail('Un carrusel necesita al menos 2 imágenes en Archivos.');
            if (count($imgs) > 20) ss_fail('Un carrusel admite hasta 20 imágenes.');
            return ['kind' => 'carousel', 'images' => $imgs, 'video' => null];
        case 'historia':
            if ($network === 'facebook') ss_fail('Las historias de Facebook todavía no se publican desde Social Studio (Meta usa otro flujo de subida): publicala como imagen o video, o subila desde Meta.');
            if (!$vids && !$imgs) ss_fail('Para una historia falta la imagen o el video en Archivos.');
            return ['kind' => 'story', 'images' => $vids ? [] : [$imgs[0]], 'video' => $vids[0] ?? null];
        default:   // video, reel, video_corto
            if (!$vids) ss_fail("Para publicar un video en $net falta subirlo (MP4 o MOV) en Archivos.");
            return ['kind' => 'video', 'images' => [], 'video' => $vids[0]];
    }
}
function ss_caption(array $post): string {
    return trim(trim((string)$post['copy']) . (trim((string)$post['hashtags']) !== '' ? "\n\n" . trim((string)$post['hashtags']) : ''));
}
/** Cuentas a las que se puede mandar esta publicación (conectadas, de sus redes y de su marca o cliente). */
function ss_publish_accounts(PDO $db, array $ctx, array $post): array {
    $nets = ss_split($post['networks']);
    $w = ["a.token_enc IS NOT NULL", "a.token_enc <> ''", ss_acc_scope_sql($ctx)];
    if ($post['brand_id']) $w[] = '(a.brand_id IS NULL OR a.brand_id = ' . (int)$post['brand_id'] . ')';
    if ($post['client_id']) $w[] = '(a.client_id IS NULL OR a.client_id = ' . (int)$post['client_id'] . ')';
    $out = [];
    foreach ($db->query('SELECT a.* FROM g7_ss_accounts a WHERE ' . implode(' AND ', $w) . ' ORDER BY a.kind, a.name')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $o = ss_account_out($a);
        if (!in_array($o['network'], $nets, true) || !ss_plan_net_ok($o['network'])) continue;   // networks outside the plan: paused
        $out[] = $o;
    }
    return $out;
}
function ss_targets(PDO $db, int $postId): array {
    $q = $db->prepare('SELECT t.*, a.name, a.username, a.kind, a.picture FROM g7_ss_targets t LEFT JOIN g7_ss_accounts a ON a.id = t.account_id WHERE t.post_id = ? ORDER BY t.id'); $q->execute([$postId]);
    return array_map(fn($t) => ['id' => (int)$t['id'], 'account_id' => (int)$t['account_id'], 'name' => (string)($t['username'] ? '@' . $t['username'] : $t['name']), 'kind' => (string)$t['kind'],
        'kind_name' => $t['kind'] ? ss_kind_name((string)$t['kind']) : '', 'network' => $t['kind'] ? ss_kind_network((string)$t['kind']) : '', 'picture' => (string)$t['picture'], 'status' => $t['status'], 'run_at' => $t['run_at'], 'permalink' => $t['permalink'], 'error' => $t['error'],
        'attempts' => (int)$t['attempts'], 'published_at' => $t['published_at']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Manda a publicar (ahora o programado) en las cuentas elegidas. La publicación tiene que estar aprobada o programada.
 * Ahora: pide "publish"; programado: pide "schedule" y una fecha y hora futuras (lo publica social/cron.php a esa hora).
 */
function ss_publish_request(PDO $db, array $ctx, int $postId, array $accountIds, string $mode, array $opts = []): array {
    $p = ss_post_visible($db, $ctx, $postId);
    $st = ss_statuses($db, true); $kind = $st[(int)$p['status_id']]['kind'] ?? '';
    if (!in_array($kind, ['approved', 'scheduled', 'published'], true)) ss_fail('Antes de publicar, la publicación tiene que estar aprobada.');
    if (!ss_plan_allows('social_publish')) ss_plan_fail('publicar en las redes desde Social Studio');
    if ($mode === 'schedule') {
        ss_need($ctx, 'schedule');
        if (!$p['pub_date'] || !$p['pub_time']) ss_fail('Para programar, la publicación necesita fecha y hora.');
        $when = $p['pub_date'] . ' ' . $p['pub_time'];
        if (strtotime($when) < time() + 120) ss_fail('La fecha y hora tienen que ser al menos 2 minutos más adelante. Para ya, usá "Publicar ahora".');
    } else { ss_need($ctx, 'publish'); $when = ss_now(); }
    if (!ss_rate_ok($db, 'pub:' . $ctx['id'], 30, 600)) ss_fail('Demasiadas publicaciones seguidas: esperá unos minutos.', 429);
    $ok = []; foreach (ss_publish_accounts($db, $ctx, $p) as $a) $ok[$a['id']] = $a;
    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
    if (!$accountIds) ss_fail('Elegí al menos una cuenta.');
    $files = ss_files($db, $postId);
    foreach ($accountIds as $aid) {
        if (!isset($ok[$aid])) ss_fail('Una de las cuentas no está conectada, no es de la marca o no coincide con las redes de la publicación.');
        $prov = $ok[$aid]['provider'];
        if (!ss_provider_ready($prov)) ss_fail('La app de ' . ($prov === 'meta' ? 'Meta' : SS_PROVIDERS[$prov]['name']) . ' no está configurada (Social Studio › Cuentas).');
        if ($prov === 'meta') {
            ss_publish_plan($p, $files, $ok[$aid]['network']);   // valida antes de mandar nada
            if (ss_caption($p) === '' && $ok[$aid]['network'] === 'facebook' && ss_publish_plan($p, $files, 'facebook')['kind'] === 'text') ss_fail('Falta el copy de la publicación.');
        } else {
            if (!ss_net_cfg($prov)['publish']) ss_fail('Publicar en ' . SS_PROVIDERS[$prov]['name'] . ' está apagado en Cuentas.');
            ss_net_fn($prov, 'plan', $p, $files, ss_net_opts($prov, $opts));
        }
    }
    $now = ss_now();
    foreach ($accountIds as $aid) {
        $q = $db->prepare('SELECT * FROM g7_ss_targets WHERE post_id = ? AND account_id = ?'); $q->execute([$postId, $aid]);
        $t = $q->fetch(PDO::FETCH_ASSOC);
        if ($t && in_array($t['status'], ['published', 'processing', 'publishing'], true)) ss_fail('Ya se publicó (o se está publicando) en ' . $ok[$aid]['name'] . '.');
        $o = $ok[$aid]['provider'] === 'meta' ? null : json_encode(ss_net_opts($ok[$aid]['provider'], $opts));
        if ($t) $db->prepare("UPDATE g7_ss_targets SET status = 'scheduled', run_at = ?, container_id = '', children = NULL, error = '', attempts = 0, opts = ?, created_by = ?, updated_at = ? WHERE id = ?")->execute([$when, $o, $ctx['id'], $now, (int)$t['id']]);
        else $db->prepare("INSERT INTO g7_ss_targets (post_id, account_id, status, run_at, opts, created_by, created_at, updated_at) VALUES (?, ?, 'scheduled', ?, ?, ?, ?, ?)")->execute([$postId, $aid, $when, $o, $ctx['id'], $now, $now]);
    }
    ss_log($db, $postId, $ctx['id'], 'publish', ($mode === 'schedule' ? 'programó la publicación para el ' . ss_fmt_date($p['pub_date'], $p['pub_time']) : 'mandó a publicar') . ' en ' . implode(', ', array_map(fn($i) => $ok[$i]['name'], $accountIds)));
    g7_audit('social.publish', '#' . $postId, ['mode' => $mode, 'accounts' => $accountIds]);
    if ($mode === 'schedule' && $kind !== 'scheduled' && ($sid = ss_status_of_kind($db, 'scheduled'))) {
        $db->prepare('UPDATE g7_ss_posts SET status_id = ?, updated_at = ? WHERE id = ?')->execute([$sid, $now, $postId]);
        ss_after_status($db, $ctx, ss_post_row($db, $postId), (int)$p['status_id'], false);
    }
    if ($mode === 'now') ss_targets_tick($db, $postId);
    return ss_targets($db, $postId);
}
function ss_publish_cancel(PDO $db, array $ctx, int $postId, int $targetId): void {
    ss_need($ctx, 'schedule');
    ss_post_visible($db, $ctx, $postId);
    $q = $db->prepare("DELETE FROM g7_ss_targets WHERE id = ? AND post_id = ? AND status IN ('scheduled', 'error')"); $q->execute([$targetId, $postId]);
    if (!$q->rowCount()) ss_fail('Eso ya se publicó o se está publicando: no se puede cancelar.');
    ss_log($db, $postId, $ctx['id'], 'publish', 'canceló una publicación programada');
}

/** Avanza los envíos pendientes (los de una publicación, o todos los que vencieron). Lo llaman la API y social/cron.php. */
function ss_targets_tick(PDO $db, ?int $postId = null, int $max = 20): int {
    $w = $postId ? 't.post_id = ' . (int)$postId . ' AND ' : '';
    $rows = $db->query("SELECT t.id FROM g7_ss_targets t WHERE $w t.status IN ('scheduled', 'processing') AND t.run_at <= NOW() ORDER BY t.run_at LIMIT " . (int)$max)->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($rows as $tid) {
        // Tomar el envío de forma atómica: si otro proceso ya lo agarró, se saltea
        $lock = $db->prepare("UPDATE g7_ss_targets SET status = 'publishing', updated_at = ? WHERE id = ? AND status IN ('scheduled', 'processing')"); $lock->execute([ss_now(), (int)$tid]);
        if (!$lock->rowCount()) continue;
        ss_target_step($db, (int)$tid); $n++;
    }
    return $n;
}

/** Un paso de un envío: crea el contenedor, espera que Meta lo procese y publica. */
function ss_target_step(PDO $db, int $tid): void {
    $t = $db->query('SELECT * FROM g7_ss_targets WHERE id = ' . $tid)->fetch(PDO::FETCH_ASSOC);
    $a = $db->query('SELECT * FROM g7_ss_accounts WHERE id = ' . (int)$t['account_id'])->fetch(PDO::FETCH_ASSOC);
    $p = ss_post_row($db, (int)$t['post_id']);
    $set = function (array $f) use ($db, $tid) { $f['updated_at'] = ss_now(); $db->prepare('UPDATE g7_ss_targets SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $tid]); };
    $fail = function (string $msg) use ($set, $db, $t, $p, $a) {
        $set(['status' => 'error', 'error' => mb_substr($msg, 0, 600)]);
        if ($p) { ss_log($db, (int)$p['id'], null, 'publish_error', 'No se publicó en ' . ($a ? ($a['username'] ? '@' . $a['username'] : $a['name']) : 'la cuenta') . ': ' . $msg);
                  ss_notify($db, array_filter([(int)$t['created_by'], (int)($p['owner_id'] ?? 0)]), (int)$p['id'], null, 'No se pudo publicar "' . $p['title'] . '": ' . mb_substr($msg, 0, 160)); }
    };
    if (!$p) { $fail('La publicación se borró.'); return; }
    if (!$a || ($tok = ss_account_token($a)) === '') { $fail('La cuenta ya no está conectada.'); return; }
    if (!ss_plan_allows('social_publish') || !ss_plan_net_ok(ss_kind_network($a['kind']))) { $fail('El plan de la agencia ya no incluye publicar en esta red.'); return; }
    if ($a['provider'] !== 'meta') { ss_net_target_step($db, $t, $a, $p, $set, $fail); return; }
    $files = ss_files($db, (int)$p['id']);
    $net = $a['kind'] === 'ig_business' ? 'instagram' : 'facebook';
    try {
        $plan = ss_publish_plan($p, $files, $net);
        $cap = ss_caption($p);
        if ($net === 'facebook') {
            $r = ss_fb_publish($db, $a, $tok, $plan, $cap, $p);
            ss_target_done($db, $t, $a, $p, (string)$r['id'], $tok);
            return;
        }
        // Instagram: 1) contenedor 2) esperar FINISHED 3) media_publish
        $cid = $t['container_id'];
        if ($cid === '') {
            $cid = ss_ig_container($db, $a, $tok, $plan, $cap);
            $set(['container_id' => $cid, 'status' => 'processing', 'attempts' => (int)$t['attempts'] + 1, 'run_at' => ss_now()]);
        }
        $deadline = microtime(true) + 10;   // imágenes: suelen estar listas en segundos; videos: los retoma el cron una vez por minuto (lo que recomienda Meta)
        do {
            $s = ss_meta_call($db, 'GET', $cid, ['fields' => 'status_code,status', 'access_token' => $tok], (int)$a['id'], 'ig_status');
            $code = (string)($s['status_code'] ?? '');
            if ($code === 'FINISHED') break;
            if ($code === 'ERROR' || $code === 'EXPIRED') { $fail('Instagram no pudo procesar el archivo (' . $code . ($s['status'] ?? '' ? ': ' . $s['status'] : '') . '). Revisá el formato y el tamaño.'); return; }
            if (microtime(true) > $deadline) break;
            usleep(3000000);
        } while (true);
        if ($code !== 'FINISHED') {   // sigue procesando (videos): lo retoma el cron en un minuto
            $tries = (int)$db->query('SELECT attempts FROM g7_ss_targets WHERE id = ' . $tid)->fetchColumn();
            if ($tries > 15) { $fail('Instagram tardó más de 15 minutos en procesar el video.'); return; }
            $set(['status' => 'processing', 'run_at' => date('Y-m-d H:i:s', time() + 60), 'attempts' => $tries + 1]);
            return;
        }
        $r = ss_meta_call($db, 'POST', $a['ext_id'] . '/media_publish', ['creation_id' => $cid, 'access_token' => $tok], (int)$a['id'], 'ig_publish');
        ss_target_done($db, $t, $a, $p, (string)($r['id'] ?? ''), $tok);
    } catch (SsMetaError $e) {
        if ($e->metaCode === 190) $db->prepare("UPDATE g7_ss_accounts SET status = 'expired', status_msg = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), (int)$a['id']]);
        $retry = in_array($e->metaCode, [4, 17, 32, 613], true) || $e->http >= 500 || $e->http === 429;
        $tries = (int)$db->query('SELECT attempts FROM g7_ss_targets WHERE id = ' . $tid)->fetchColumn();
        if ($retry && $tries < 5) $set(['status' => $t['container_id'] !== '' ? 'processing' : 'scheduled', 'run_at' => date('Y-m-d H:i:s', time() + 300 * ($tries + 1)), 'attempts' => $tries + 1, 'error' => mb_substr($e->getMessage(), 0, 600)]);
        else $fail($e->getMessage());
    } catch (SsError $e) { $fail($e->getMessage()); }
}
function ss_target_done(PDO $db, array $t, array $a, array $p, string $extId, string $tok, ?string $link = null): void {
    if ($link === null) { $link = '';
    try {
        if ($a['kind'] === 'ig_business') $link = (string)(ss_meta_call($db, 'GET', $extId, ['fields' => 'permalink', 'access_token' => $tok], (int)$a['id'], 'permalink')['permalink'] ?? '');
        else $link = (string)(ss_meta_call($db, 'GET', $extId, ['fields' => 'permalink_url', 'access_token' => $tok], (int)$a['id'], 'permalink')['permalink_url'] ?? '');
    } catch (SsError $e) {} }
    $db->prepare("UPDATE g7_ss_targets SET status = 'published', ext_id = ?, permalink = ?, error = '', published_at = ?, updated_at = ? WHERE id = ?")->execute([$extId, mb_substr($link, 0, 1000), ss_now(), ss_now(), (int)$t['id']]);
    $who = $a['username'] ? '@' . $a['username'] : $a['name'];
    ss_log($db, (int)$p['id'], null, 'published', 'Publicada en ' . $who . ($link ? ' · ' . $link : ''));
    // Cuando salió en todas las cuentas elegidas, la publicación pasa a Publicado (lo hizo la persona que la mandó, con su permiso)
    $left = (int)$db->query("SELECT COUNT(*) FROM g7_ss_targets WHERE post_id = " . (int)$p['id'] . " AND status <> 'published'")->fetchColumn();
    if (!$left && ($sid = ss_status_of_kind($db, 'published')) && (int)$p['status_id'] !== $sid) {
        $db->prepare('UPDATE g7_ss_posts SET status_id = ?, updated_at = ? WHERE id = ?')->execute([$sid, ss_now(), (int)$p['id']]);
        ss_log($db, (int)$p['id'], null, 'status', 'pasó a "Publicado" (salió en todas las cuentas elegidas)');
    }
    ss_notify($db, array_filter([(int)$t['created_by'], (int)($p['owner_id'] ?? 0)]), (int)$p['id'], null, 'Publicada en ' . $who . ': "' . $p['title'] . '"');
}
function ss_fb_publish(PDO $db, array $a, string $tok, array $plan, string $cap, array $p): array {
    $pid = $a['ext_id']; $acc = (int)$a['id'];
    switch ($plan['kind']) {
        case 'text':
            $links = ss_json_list($p['links']);
            $q = ['message' => $cap, 'access_token' => $tok]; if ($links) $q['link'] = $links[0]['url'];
            return ss_meta_call($db, 'POST', "$pid/feed", $q, $acc, 'fb_feed');
        case 'image':
            $r = ss_meta_call($db, 'POST', "$pid/photos", ['url' => ss_media_url($plan['images'][0]), 'caption' => $cap, 'published' => 'true', 'access_token' => $tok], $acc, 'fb_photo');
            return ['id' => (string)($r['post_id'] ?? $r['id'] ?? '')];
        case 'carousel':
            $media = [];
            foreach ($plan['images'] as $i) $media[] = ['media_fbid' => (string)ss_meta_call($db, 'POST', "$pid/photos", ['url' => ss_media_url($i), 'published' => 'false', 'access_token' => $tok], $acc, 'fb_photo_unpub')['id']];
            $q = ['message' => $cap, 'access_token' => $tok];
            foreach ($media as $k => $m) $q["attached_media[$k]"] = json_encode($m);
            return ss_meta_call($db, 'POST', "$pid/feed", $q, $acc, 'fb_multi');
        case 'video':
            $r = ss_meta_call($db, 'POST', "$pid/videos", ['file_url' => ss_media_url($plan['video'], false, 14400), 'description' => $cap, 'access_token' => $tok], $acc, 'fb_video');
            return ['id' => (string)($r['id'] ?? '')];
    }
    ss_fail('Ese formato no se puede publicar en Facebook.');
}
function ss_ig_container(PDO $db, array $a, string $tok, array $plan, string $cap): string {
    $ig = $a['ext_id']; $acc = (int)$a['id'];
    $img = fn($f) => ss_media_url($f, $f['ext'] !== 'jpg' && $f['ext'] !== 'jpeg');
    foreach ($plan['images'] as $f) if ($f['size'] > 8 * 1048576) ss_fail('Instagram acepta imágenes de hasta 8 MB: "' . $f['name'] . '" pesa más.');
    if ($plan['video'] && $plan['video']['size'] > ($plan['kind'] === 'story' ? 100 : 300) * 1048576) ss_fail('El video pesa más de lo que acepta Instagram (' . ($plan['kind'] === 'story' ? '100' : '300') . ' MB).');
    switch ($plan['kind']) {
        case 'image': $q = ['image_url' => $img($plan['images'][0]), 'caption' => $cap]; break;
        case 'carousel':
            $kids = [];
            foreach ($plan['images'] as $f) $kids[] = (string)ss_meta_call($db, 'POST', "$ig/media", ['image_url' => $img($f), 'is_carousel_item' => 'true', 'access_token' => $tok], $acc, 'ig_child')['id'];
            $q = ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $cap]; break;
        case 'video': $q = ['media_type' => 'REELS', 'video_url' => ss_media_url($plan['video'], false, 14400), 'caption' => $cap]; break;
        case 'story': $q = $plan['video'] ? ['media_type' => 'STORIES', 'video_url' => ss_media_url($plan['video'], false, 14400)] : ['media_type' => 'STORIES', 'image_url' => $img($plan['images'][0])]; break;
        default: ss_fail('Instagram no publica solo texto.');
    }
    $r = ss_meta_call($db, 'POST', "$ig/media", $q + ['access_token' => $tok], $acc, 'ig_container');
    if (empty($r['id'])) ss_fail('Instagram no devolvió el contenedor.');
    return (string)$r['id'];
}

/** Tarea periódica: publicar lo programado y revisar las cuentas una vez por día. */
function ss_meta_cron(PDO $db): array {
    $n = ss_targets_tick($db, null, 50);
    $checked = 0;
    foreach ($db->query("SELECT * FROM g7_ss_accounts WHERE token_enc IS NOT NULL AND token_enc <> '' AND (last_check_at IS NULL OR last_check_at < NOW() - INTERVAL 1 DAY) LIMIT 30")->fetchAll(PDO::FETCH_ASSOC) as $a) { ss_account_check($db, $a); $checked++; }
    return ['published_steps' => $n, 'checked' => $checked];
}
/** Sin cron configurado: lo programado avanza igual cuando alguien abre Social Studio (como mucho una vez por minuto). */
function ss_meta_maybe_tick(PDO $db): void {
    $last = (int)g7_cfg('ss_tick_at', '0');
    if (time() - $last < 60) return;
    g7_cfg_set('ss_tick_at', (string)time());
    try { ss_targets_tick($db, null, 5); } catch (Throwable $e) { error_log('[social] tick: ' . $e->getMessage()); }
}

// Stage 6: the other networks (TikTok, LinkedIn, X, YouTube) plug into the same accounts, publishing, analytics and Inbox.
require_once __DIR__ . '/net_lib.php';
