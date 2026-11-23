<?php
/**
 * social/webhook.php?p=meta — Meta notifications (the "Callback URL" of the Webhooks product in the Meta app).
 *   GET:  verification (hub.mode=subscribe, hub.verify_token = the one in Social Studio › Accounts, hub.challenge is returned).
 *   POST: event signed with X-Hub-Signature-256 (HMAC-SHA256 of the raw body with the App Secret). Without a valid signature → 403.
 * It does not use the session or the account's permissions: whoever calls is Meta, and the signature proves it. What arrives is stored as is
 * (g7_ss_webhook) and the Inbox (inbox_lib.php) processes it right away. It responds right away so Meta does not retry.
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array($_GET['p'] ?? '', ['meta', 'hub'], true)) { http_response_code(404); exit('no'); }
if ((g7_modules()['social']['mode'] ?? 'off') === 'off') { http_response_code(404); exit('no'); }   // Social Studio off: nothing is received
$db = getDB();
require_once __DIR__ . '/meta_lib.php';
ss_install($db);
// Central connection: Meta's notification passed by the central, signed with this site's key (hub_lib.php)
if ($_GET['p'] === 'hub') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) { http_response_code(405); exit; }
    $raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
    if (!ss_hub_is_client() || !ss_hub_webhook_ok($db, ['site' => $_SERVER['HTTP_X_SS_SITE'] ?? '', 'ts' => $_SERVER['HTTP_X_SS_TS'] ?? '', 'nonce' => $_SERVER['HTTP_X_SS_NONCE'] ?? '', 'sig' => $_SERVER['HTTP_X_SS_SIG'] ?? ''], $raw)) {
        if (ss_rate_ok($db, 'whhub', 20, 3600)) ss_log_call($db, null, 'webhook_bad_sig', false, 403, 'Aviso de la conexión central con firma inválida (descartado)');
        http_response_code(403); exit('firma inválida');
    }
    if (!is_array(json_decode($raw, true))) { http_response_code(400); exit('json'); }
    ss_webhook_store($db, $raw);
    try { require_once __DIR__ . '/inbox_lib.php'; ss_inbox_process($db, 20); } catch (Throwable $e) { error_log('[social webhook hub] ' . $e->getMessage()); }
    exit('OK');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $ch = ss_webhook_verify($_GET);
    if ($ch === null) { http_response_code(403); exit('verify token incorrecto'); }
    ss_log_call($db, null, 'webhook_verify', true, 200, 'Meta verificó el webhook');
    exit($ch);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len > 1048576) { http_response_code(413); exit; }
$raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
if (!ss_webhook_sig_ok($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    if (ss_rate_ok($db, 'whbad', 20, 3600)) ss_log_call($db, null, 'webhook_bad_sig', false, 403, 'Aviso con firma inválida (descartado)');
    http_response_code(403); exit('firma inválida');
}
if (!is_array(json_decode($raw, true))) { http_response_code(400); exit('json'); }
ss_webhook_store($db, $raw);
// The Inbox processes it right away (if something fails it stays pending and social/cron.php retries it)
try { require_once __DIR__ . '/inbox_lib.php'; ss_inbox_process($db, 20); } catch (Throwable $e) { error_log('[social webhook] ' . $e->getMessage()); }
// Central connection: what belongs to pages of other sites goes to them (only the ones that proved they manage them)
try { ss_hub_forward($db, $raw); } catch (Throwable $e) { error_log('[social webhook forward] ' . $e->getMessage()); }
echo 'EVENT_RECEIVED';
