<?php
/**
 * social/hub.php — Central connection of Social Studio (only answers on the site that is the central: Cuentas › Conexión central).
 *   GET  ?a=start&site=&p=&cs=&exp=&sig=  The browser of a person of an authorized site: checks the site's signature and goes to the network
 *                                         with the central's app (the network returns to this site's oauth.php, which hands it back).
 *   POST (JSON, X-SS-Site/Ts/Nonce/Sig)   Server to server: info, pickup, refresh, revoke, routes. Signed answers (X-SS-Sig).
 * No session: whoever calls is an authorized site and its signature proves it. Doc: toolz/SOCIAL_STUDIO.md (Conexión central)
 */
require_once __DIR__ . '/../auth.php';
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
if ((g7_modules()['social']['mode'] ?? 'off') === 'off') { http_response_code(404); exit; }
$db = getDB();
require_once __DIR__ . '/net_lib.php';
ss_install($db);
if (!ss_hub_is_hub()) { http_response_code(404); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['a'] ?? '') === 'start') {
    try {
        if (!ss_rate_ok($db, 'hubstart:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 600)) ss_fail('Demasiados intentos: esperá unos minutos.', 429);
        [$url, $nonce] = ss_hub_start($db, $_GET);
        setcookie('ss_hub_nonce', $nonce, ['expires' => time() + 900, 'path' => '/toolz/social/', 'secure' => g7_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        header('Location: ' . $url); exit;
    } catch (SsError $e) {
        http_response_code($e->getCode() ?: 400); header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo conectar: ' . $e->getMessage(); exit;
    }
}
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) { http_response_code(413); exit; }
$body = (string)file_get_contents('php://input', false, null, 0, 1048576);
$h = ['site' => $_SERVER['HTTP_X_SS_SITE'] ?? '', 'ts' => $_SERVER['HTTP_X_SS_TS'] ?? '', 'nonce' => $_SERVER['HTTP_X_SS_NONCE'] ?? '', 'sig' => $_SERVER['HTTP_X_SS_SIG'] ?? ''];
$site = ss_hub_verify($db, $h, $body);
if (!$site) {
    if (ss_rate_ok($db, 'hubbad', 30, 3600)) ss_log_call($db, null, 'hub_bad_sig', false, 403, 'Pedido a la conexión central sin firma válida (sitio ' . (int)$h['site'] . ')');
    http_response_code(403); echo json_encode(['error' => 'Firma inválida.']); exit;
}
$out = function (array $d, int $code = 200) use ($site, $h) {
    $b = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('X-SS-Sig: ' . hash_hmac('sha256', $h['nonce'] . "\n" . $b, $site['secret']));
    http_response_code($code); echo $b; exit;
};
try {
    if (!ss_rate_ok($db, 'hub:' . (int)$site['id'], 600, 600)) ss_fail('Demasiados pedidos: esperá un rato.', 429);
    $out(['ok' => true, 'data' => ss_hub_action($db, $site, json_decode($body, true) ?: [])]);
} catch (SsMetaError $e) { $out(['error' => $e->getMessage(), 'code' => $e->metaCode], $e->getCode() ?: 400); }
catch (SsError $e) { $out(['error' => $e->getMessage()], $e->getCode() ?: 400); }
catch (Throwable $e) { error_log('[social hub] ' . $e->getMessage()); $out(['error' => 'Algo salió mal en la conexión central.'], 500); }
