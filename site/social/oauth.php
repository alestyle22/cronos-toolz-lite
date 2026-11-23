<?php
/**
 * social/oauth.php — Return from Meta, TikTok, LinkedIn, X or Google after "Connect" (the same URI is registered in every app).
 *   ?start=1[&p=tiktok|linkedin|x|youtube] (with a session and the "accounts" permission) sends you to the network to ask for permission.
 *                   The network of the return comes from the state stored on our side (not from the URL).
 *   ?code=…&state=… is Meta's return. The session cookie is Strict and does not arrive on this return: the person comes from
 *                   the single-use state + a Lax cookie of the same browser. At the end it sends you to Social Studio › Accounts
 *                   to choose which pages and Instagram accounts get connected (oauth-back.js, no inline code).
 * Doc: toolz/SOCIAL_STUDIO.md
 */
require_once __DIR__ . '/../auth.php';
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$db = getDB();
require_once __DIR__ . '/meta_lib.php';
ss_install($db);
$page = function (string $title, string $msg, bool $ok, string $back = '/toolz/social/#cuentas') {
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
        . '<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2"><link rel="stylesheet" href="/toolz/social/app.css?v=' . (int)@filemtime(__DIR__ . '/app.css') . '"></head>'
        . '<body class="ss-oauth"><div class="ss-oauth-b"><p class="ss-kicker">Social Studio</p><h1 class="' . ($ok ? 'ok' : 'bad') . '">' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p>'
        . '<a id="v" class="ss-btn" href="' . htmlspecialchars($back) . '"' . ($ok ? ' data-auto="1"' : '') . '>' . ($ok ? 'Elegir las cuentas' : 'Volver a Cuentas') . '</a></div>'
        . '<script src="/toolz/social/oauth-back.js?v=' . (int)@filemtime(__DIR__ . '/oauth-back.js') . '"></script></body></html>';
    exit;
};
$cookie = fn(string $v, int $exp) => setcookie('ss_meta_nonce', $v, ['expires' => $exp, 'path' => '/toolz/social/', 'secure' => g7_is_https(), 'httponly' => true, 'samesite' => 'Lax']);

if (isset($_GET['start'])) {
    $me = requireLogin();
    try {
        $ctx = ss_ctx($db, (int)$me['id']);
        $prov = (string)($_GET['p'] ?? 'meta');
        [$url, $nonce] = $prov === 'meta' ? ss_meta_auth_url($db, $ctx) : ss_net_auth_url($db, $ctx, $prov);
        $cookie($nonce, time() + 900);
        header('Location: ' . $url); exit;
    } catch (SsError $e) { $page('No se pudo conectar', $e->getMessage(), false); }
}
// Central connection, on a site: the central sends the person back here with a single-use pickup code (hub_lib.php)
if (isset($_GET['hs'])) {
    $nonce = (string)($_COOKIE['ss_meta_nonce'] ?? ''); $cookie('', time() - 3600);
    $hs = (string)$_GET['hs'];
    if (!preg_match('/^[a-f0-9]{48}$/', $hs)) $page('El link venció', 'Volvé a Social Studio › Cuentas y tocá "Conectar" otra vez.', false);
    if (isset($_GET['error'])) {
        try { $db->prepare("UPDATE g7_ss_oauth SET used_at = ? WHERE state_hash = ? AND used_at IS NULL")->execute([ss_now(), hash('sha256', $hs)]); } catch (Throwable $e) {}
        $page('No se conectó', 'Se canceló el permiso en la red. Podés volver a intentarlo desde Social Studio › Cuentas.', false);
    }
    try {
        $id = ss_hub_finish($db, $hs, $nonce, (string)($_GET['pick'] ?? ''));
        $page('¡Listo! La red dio el permiso', 'Ahora elegí qué cuentas se conectan y a qué marca o cliente pertenece cada una.', true, '/toolz/social/#cuentas/elegir/' . $id);
    } catch (SsError $e) { $page('No se conectó', $e->getMessage(), false); }
}
// Central connection, on the central: the network returns here for another site (its own cookie, ss_hub_nonce)
$forSite = ss_oauth_site($db, (string)($_GET['state'] ?? ''));
if ($forSite) {
    $nonce = (string)($_COOKIE['ss_hub_nonce'] ?? '');
    setcookie('ss_hub_nonce', '', ['expires' => time() - 3600, 'path' => '/toolz/social/', 'secure' => g7_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
} else {
    $nonce = (string)($_COOKIE['ss_meta_nonce'] ?? '');
    $cookie('', time() - 3600);
}
if (isset($_GET['error'])) {
    // Canceled at Meta (or Meta refused): the state is burned so it cannot be reused
    try { $db->prepare("UPDATE g7_ss_oauth SET used_at = ? WHERE state_hash = ? AND used_at IS NULL")->execute([ss_now(), hash('sha256', (string)($_GET['state'] ?? ''))]); } catch (Throwable $e) {}
    if ($forSite && ($back = ss_hub_cancel_url($db, (string)$_GET['state']))) { header('Location: ' . $back); exit; }
    $who = ss_oauth_provider($db, (string)($_GET['state'] ?? ''));
    $whoName = $who && $who !== 'meta' && isset(SS_PROVIDERS[$who]) ? SS_PROVIDERS[$who]['name'] : 'Meta';
    ss_log_call($db, null, 'oauth_cancel', false, 0, $whoName . ': ' . mb_substr((string)($_GET['error_reason'] ?? $_GET['error_description'] ?? $_GET['error']), 0, 80));
    $page('No se conectó', 'Se canceló el permiso en ' . $whoName . '. Podés volver a intentarlo desde Social Studio › Cuentas.', false);
}
$state = (string)($_GET['state'] ?? ''); $code = (string)($_GET['code'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $state) || $code === '' || strlen($code) > 2000) $page('El link venció', 'Volvé a Social Studio › Cuentas y tocá "Conectar" otra vez.', false);
try {
    $prov = ss_oauth_provider($db, $state);
    if ($forSite) {   // the central got the permission for another site: it hands it over with a pickup code
        $id = $prov !== 'meta' ? ss_net_callback($db, $state, $nonce, $code) : ss_meta_callback($db, $state, $nonce, $code);
        header('Location: ' . ss_hub_handoff($db, $id)); exit;
    }
    if ($prov !== '' && $prov !== 'meta') {
        $id = ss_net_callback($db, $state, $nonce, $code);
        $page('¡Listo! ' . SS_PROVIDERS[$prov]['name'] . ' dio el permiso', 'Ahora elegí qué cuentas se conectan y a qué marca o cliente pertenece cada una.', true, '/toolz/social/#cuentas/elegir/' . $id);
    }
    $id = ss_meta_callback($db, $state, $nonce, $code);
    $page('¡Listo! Meta dio el permiso', 'Ahora elegí qué páginas y cuentas de Instagram se conectan y a qué marca o cliente pertenece cada una.', true, '/toolz/social/#cuentas/elegir/' . $id);
} catch (SsError $e) { $page('No se conectó', $e->getMessage(), false); }
