<?php
/**
 * social/api.php — API de Social Studio (app.js). POST JSON {action, ...} con la cabecera X-SS; los archivos llegan
 * como multipart (action=file_upload) con la misma cabecera. Permisos, alcance y validación: lib.php.
 * Respuestas JSON; errores {error} con 400/401/403/404/429. Doc: toolz/SOCIAL_STUDIO.md (lista de acciones).
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ss_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$me = getCurrentUser();
if (!$me) ss_out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
$uid = (int)$me['id'];
if (!g7_can($uid, 'social')) ss_out(['error' => 'No tienes acceso a Social Studio.'], 403);
// Solo POST, JSON o multipart, y siempre con la cabecera propia: un formulario de otro sitio no puede mandar ninguna de las dos cosas
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isJson = preg_match('#^application/json\s*(;|$)#i', $ct);
$isUpload = stripos($ct, 'multipart/form-data') === 0;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (!$isJson && !$isUpload) || ($_SERVER['HTTP_X_SS'] ?? '') !== '1') ss_out(['error' => 'Pedido inválido.'], 400);
$db = getDB();
require_once __DIR__ . '/report_lib.php';
ss_install($db);
session_write_close();
$in = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
$act = (string)($in['action'] ?? '');
$id = (int)($in['id'] ?? 0);
$writes = ['post_save', 'post_status', 'post_move', 'post_delete', 'post_duplicate', 'file_upload', 'file_delete', 'tickets_create', 'ticket_link', 'ticket_unlink', 'config_save', 'planify_import',
           'meta_cfg_save', 'meta_save', 'net_cfg_save', 'hub_role', 'hub_site_add', 'hub_site_set', 'hub_join', 'hub_leave', 'pf_upload', 'pf_save', 'pf_set', 'pf_duplicate', 'pf_delete', 'pf_restore', 'pf_ai_start', 'pf_ai_status', 'pf_ai_thumbs', 'pf_ai_thumbs_poll', 'pf_gallery_set', 'account_update', 'account_disconnect', 'account_check', 'publish', 'publish_cancel',
           'inbox_update', 'inbox_note', 'inbox_reply', 'inbox_upload', 'inbox_sync', 'reply_save', 'reply_delete', 'inbox_ticket', 'inbox_statuses_save', 'an_sync', 'goal_save', 'goal_delete',
           'rep_create', 'rep_update', 'rep_refresh', 'rep_duplicate', 'rep_delete', 'rep_link', 'rep_send', 'rep_tpl_save', 'rep_tpl_delete', 'rep_sched_save', 'rep_sched_delete'];
if (in_array($act, $writes, true) && function_exists('lic_readonly') && lic_readonly()) ss_out(['error' => 'El sitio está en modo solo lectura: revisa la licencia.'], 403);

try {
    $ctx = ss_ctx($db, $uid);
    if (in_array($act, $writes, true) && !ss_rate_ok($db, 'w:' . $uid, 240, 300)) ss_fail('Demasiados cambios seguidos: espera un minuto.', 429);
    switch ($act) {
        case 'meta': ss_out(ss_meta($db, $ctx));
        case 'list': ss_meta_maybe_tick($db); ss_out(['items' => ss_posts_list($db, $ctx, (array)($in['filters'] ?? []))]);
        case 'get': ss_out(ss_post_detail($db, $ctx, $id));
        case 'post_save': $pid = ss_post_save($db, $ctx, (array)($in['post'] ?? []), $id); ss_out(['ok' => true, 'id' => $pid] + ss_post_detail($db, $ctx, $pid));
        case 'post_status': ss_post_status($db, $ctx, $id, (int)($in['status_id'] ?? 0)); ss_out(['ok' => true] + ss_post_detail($db, $ctx, $id));
        case 'post_move':
            ss_post_move($db, $ctx, $id, isset($in['date']) ? (string)$in['date'] : null, array_key_exists('time', $in) && $in['time'] !== null ? (string)$in['time'] : null);
            ss_out(['ok' => true, 'post' => ss_post_detail($db, $ctx, $id)['post']]);
        case 'post_delete': ss_post_delete($db, $ctx, $id); ss_out(['ok' => true]);
        case 'post_duplicate': $nid = ss_post_duplicate($db, $ctx, $id); ss_out(['ok' => true, 'id' => $nid]);
        case 'file_upload': $files = ss_file_upload($db, $ctx, $id, $_FILES['file'] ?? null); ss_out(['ok' => true, 'files' => $files]);
        case 'file_delete': $pid = ss_file_delete($db, $ctx, $id); ss_out(['ok' => true, 'files' => ss_files($db, $pid)]);
        case 'ticket_options': ss_out(ss_ticket_options($db, $ctx));
        case 'tickets_create': $ids = ss_make_tickets($db, $ctx, $id, (array)($in['items'] ?? [])); ss_out(['ok' => true, 'tickets' => $ids] + ss_post_detail($db, $ctx, $id));
        case 'ticket_link': ss_link_ticket($db, $ctx, $id, (int)($in['ticket_id'] ?? 0), (string)($in['kind'] ?? 'otro')); ss_out(['ok' => true] + ss_post_detail($db, $ctx, $id));
        case 'ticket_unlink': ss_unlink_ticket($db, $ctx, $id, (int)($in['ticket_id'] ?? 0)); ss_out(['ok' => true] + ss_post_detail($db, $ctx, $id));
        case 'promo_search': ss_out(['items' => ss_promo_search($db, $ctx, (string)($in['q'] ?? ''))]);
        case 'config_save': ss_config_save($db, $ctx, $in); ss_out(['ok' => true] + ss_meta($db, $ctx));
        case 'planify_projects': ss_out(['items' => ss_planify_projects($db, $ctx)]);
        case 'planify_import': $n = ss_planify_import($db, $ctx, (array)($in['map'] ?? [])); ss_out(['ok' => true, 'imported' => $n]);

        // ── Accounts and Meta (Stage 2) ──
        case 'accounts': $hub = ss_hub_public($db, $ctx);
            ss_out(['meta' => ss_meta_public($ctx), 'nets' => array_map(fn($p) => ss_net_public($p, $ctx), array_keys(SS_PROVIDERS)), 'items' => ss_accounts($db, $ctx), 'hub' => $hub, 'can' => ['accounts' => $ctx['can']['accounts'], 'admin' => $ctx['admin']]]);
        // ── Profile Studio (profile_lib.php) ──
        case 'pf_list': require_once __DIR__ . '/profile_lib.php'; ss_out(['items' => ss_pf_list($db, $ctx)]);
        case 'pf_get': require_once __DIR__ . '/profile_lib.php'; ss_out(['project' => ss_pf_out(ss_pf_row($db, $ctx, $id), true)]);
        case 'pf_upload': require_once __DIR__ . '/profile_lib.php'; ss_out(['ok' => true, 'file' => ss_pf_upload($db, $ctx, $_FILES['file'] ?? null, (string)($in['kind'] ?? ''))]);
        case 'pf_save': require_once __DIR__ . '/profile_lib.php'; $pid = ss_pf_save($db, $ctx, $id, $in); ss_out(['ok' => true, 'id' => $pid, 'project' => ss_pf_out(ss_pf_row($db, $ctx, $pid))]);
        case 'pf_set': require_once __DIR__ . '/profile_lib.php'; ss_pf_set($db, $ctx, $id, $in); ss_out(['ok' => true, 'items' => ss_pf_list($db, $ctx)]);
        case 'pf_duplicate': require_once __DIR__ . '/profile_lib.php'; $nid = ss_pf_duplicate($db, $ctx, $id); ss_out(['ok' => true, 'id' => $nid, 'items' => ss_pf_list($db, $ctx)]);
        case 'pf_delete': require_once __DIR__ . '/profile_lib.php'; ss_pf_delete($db, $ctx, $id); ss_out(['ok' => true, 'items' => ss_pf_list($db, $ctx)]);
        case 'pf_versions': require_once __DIR__ . '/profile_lib.php'; ss_out(['items' => ss_pf_versions($db, $ctx, $id)]);
        case 'pf_ai_meta': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(ss_pfa_meta() + ['admin' => $ctx['admin']]);
        case 'pf_ai_start': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(['ok' => true] + ss_pfa_start($db, $ctx, $in));
        case 'pf_ai_status': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(ss_pfa_status($db, $ctx, $id));
        case 'pf_ai_suggest': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(['ideas' => ss_pfa_suggest($db, $ctx, (string)($in['idea'] ?? ''), (string)($in['style'] ?? ''), (string)($in['mode'] ?? ''))]);
        case 'pf_ai_recent': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(['items' => ss_pfa_recent($db, $ctx)]);
        case 'pf_ai_thumbs': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(['ok' => true] + ss_pfa_thumbs_start($db, $ctx, !empty($in['redo'])));
        case 'pf_ai_thumbs_poll': require_once __DIR__ . '/profile_ai_lib.php'; ss_out(['ok' => true] + ss_pfa_thumbs_poll($db, $ctx));
        case 'pf_gallery': require_once __DIR__ . '/profile_lib.php'; ss_out(['on' => ss_pf_gallery_on(), 'items' => ss_pf_gallery($db, $ctx), 'can_config' => $ctx['can']['config']]);
        case 'pf_gallery_look': require_once __DIR__ . '/profile_lib.php'; ss_out(['look' => ss_pf_gallery_look($db, $ctx, $id)]);
        case 'pf_gallery_set': require_once __DIR__ . '/profile_lib.php'; ss_pf_gallery_set($db, $ctx, !empty($in['on'])); ss_out(['ok' => true, 'on' => ss_pf_gallery_on()]);
        case 'pf_restore': require_once __DIR__ . '/profile_lib.php'; ss_out(['ok' => true, 'project' => ss_pf_restore($db, $ctx, $id, (int)($in['version_id'] ?? 0))]);
        // ── Central connection ──
        case 'hub_role': ss_hub_set_role($db, $ctx, !empty($in['on'])); ss_out(['ok' => true, 'hub' => ss_hub_public($db, $ctx)]);
        case 'hub_site_add': $r = ss_hub_site_add($db, $ctx, (string)($in['name'] ?? ''), (string)($in['origin'] ?? '')); ss_out(['ok' => true, 'code' => $r['code'], 'hub' => ss_hub_public($db, $ctx)]);
        case 'hub_site_set': ss_hub_site_set($db, $ctx, $id, isset($in['active']) ? !empty($in['active']) : null, !empty($in['delete'])); ss_out(['ok' => true, 'hub' => ss_hub_public($db, $ctx)]);
        case 'hub_join': ss_hub_join($db, $ctx, (string)($in['code'] ?? '')); ss_out(['ok' => true]);
        case 'hub_leave': ss_hub_leave($db, $ctx); ss_out(['ok' => true]);
        case 'net_cfg_save': $p = (string)($in['provider'] ?? ''); ss_net_cfg_save($db, $ctx, $p, (array)($in['cfg'] ?? [])); ss_out(['ok' => true, 'net' => ss_net_public($p, $ctx)]);
        case 'meta_cfg_save': ss_meta_cfg_save($db, $ctx, (array)($in['cfg'] ?? [])); ss_out(['ok' => true, 'meta' => ss_meta_public($ctx)]);
        case 'meta_pending': ss_out(ss_meta_pending($db, $ctx, $id));
        case 'meta_save': $n = ss_meta_save($db, $ctx, $id, (array)($in['picks'] ?? [])); ss_out(['ok' => true, 'saved' => $n, 'items' => ss_accounts($db, $ctx)]);
        case 'account_update': ss_account_update($db, $ctx, $id, $in); ss_out(['ok' => true, 'items' => ss_accounts($db, $ctx)]);
        case 'account_check': ss_need($ctx, 'accounts'); $a = ss_account_check($db, ss_account_row($db, $ctx, $id)); ss_out(['ok' => true, 'account' => ss_account_out($a), 'items' => ss_accounts($db, $ctx)]);
        case 'account_disconnect': ss_account_disconnect($db, $ctx, $id, !empty($in['forget'])); ss_out(['ok' => true, 'items' => ss_accounts($db, $ctx)]);
        case 'account_log': ss_need($ctx, 'accounts'); ss_out(['items' => ss_account_log($db, $ctx, $id)]);
        case 'publish_options':
            $p = ss_post_visible($db, $ctx, $id); ss_targets_tick($db, $id);
            ss_out(['accounts' => ss_publish_accounts($db, $ctx, $p), 'targets' => ss_targets($db, $id), 'ready' => ss_meta_ready(),
                    'ready_by' => array_merge(['meta' => ss_meta_ready()], array_map('ss_net_ready', array_combine(array_keys(SS_PROVIDERS), array_keys(SS_PROVIDERS)))),
                    'tt_privacy' => SS_TT_PRIVACY, 'yt_privacy' => SS_YT_PRIVACY]);
        case 'tt_creator':   // what TikTok allows for this account today (mandatory before publishing: nickname and privacy options)
            $a = ss_account_row($db, $ctx, $id); if ($a['provider'] !== 'tiktok') ss_fail('No es una cuenta de TikTok.');
            ss_out(ss_tiktok_creator($db, $a));
        case 'publish': $t = ss_publish_request($db, $ctx, $id, (array)($in['accounts'] ?? []), ($in['mode'] ?? '') === 'schedule' ? 'schedule' : 'now', (array)($in['opts'] ?? []));
            ss_out(['ok' => true, 'targets' => $t] + ss_post_detail($db, $ctx, $id));
        // ── Report Maker (Stage 5) ──
        case 'rep_meta': ss_out(['auto_ok' => ss_plan_allows('social_reports_auto'), 'reports' => ss_rep_list($db, $ctx), 'templates' => ss_rep_templates($db, $ctx), 'scheds' => ss_rep_scheds($db, $ctx), 'sections' => SS_REP_SECTIONS,
                                 'metrics' => array_map(fn($m) => $m[0], SS_AN_METRICS), 'ai' => ss_plan_allows('social_ai') && (function () { require_once dirname(__DIR__) . '/ia_lib.php'; return function_exists('g7_claude_ready') && g7_claude_ready(); })()]);
        case 'rep_get': ss_out(['report' => ss_rep_out(ss_rep_row($db, $ctx, $id), true)]);
        case 'rep_create': $rid = ss_rep_create($db, $ctx, (array)($in['report'] ?? [])); ss_out(['ok' => true, 'id' => $rid, 'report' => ss_rep_out(ss_rep_row($db, $ctx, $rid), true)]);
        case 'rep_update': ss_rep_update($db, $ctx, $id, $in); ss_out(['ok' => true, 'report' => ss_rep_out(ss_rep_row($db, $ctx, $id), true)]);
        case 'rep_refresh': ss_rep_refresh($db, $ctx, $id); ss_out(['ok' => true, 'report' => ss_rep_out(ss_rep_row($db, $ctx, $id), true)]);
        case 'rep_duplicate': $rid = ss_rep_duplicate($db, $ctx, $id, (array)($in['range'] ?? [])); ss_out(['ok' => true, 'id' => $rid]);
        case 'rep_delete': ss_rep_delete($db, $ctx, $id); ss_out(['ok' => true]);
        case 'rep_link': $link = ss_rep_link($db, $ctx, $id, !empty($in['on'])); ss_out(['ok' => true, 'link' => $link]);
        case 'rep_send': $n = ss_rep_send($db, $ctx, $id, (string)($in['to'] ?? ''), (string)($in['message'] ?? '')); ss_out(['ok' => true, 'sent' => $n, 'report' => ss_rep_out(ss_rep_row($db, $ctx, $id), true)]);
        case 'rep_ai': ss_out(ss_rep_ai($db, $ctx, $id));
        case 'rep_tpl_save': ss_rep_tpl_save($db, $ctx, (array)($in['tpl'] ?? [])); ss_out(['ok' => true, 'templates' => ss_rep_templates($db, $ctx)]);
        case 'rep_tpl_delete': ss_rep_tpl_delete($db, $ctx, $id); ss_out(['ok' => true, 'templates' => ss_rep_templates($db, $ctx)]);
        case 'rep_sched_save': ss_rep_sched_save($db, $ctx, (array)($in['sched'] ?? [])); ss_out(['ok' => true, 'scheds' => ss_rep_scheds($db, $ctx)]);
        case 'rep_sched_delete': ss_rep_sched_delete($db, $ctx, $id); ss_out(['ok' => true, 'scheds' => ss_rep_scheds($db, $ctx)]);
        // ── Analytics (Stage 4) ──
        case 'an_report': ss_out(ss_an_report($db, $ctx, (array)($in['filters'] ?? [])));
        case 'an_sync': ss_need($ctx, 'analytics'); if (!ss_rate_ok($db, 'ansync:' . $uid, 3, 3600)) ss_fail('Ya se actualizó varias veces en la última hora: esperá un rato.', 429); ss_out(['ok' => true] + ss_an_sync_all($db, $ctx));
        case 'goal_save': ss_an_goal_save($db, $ctx, (array)($in['goal'] ?? [])); ss_out(['ok' => true, 'goals' => ss_an_goals($db, $ctx)]);
        case 'goal_delete': ss_an_goal_delete($db, $ctx, $id); ss_out(['ok' => true, 'goals' => ss_an_goals($db, $ctx)]);
        case 'goals': ss_out(['goals' => ss_an_goals($db, $ctx)]);
        // ── Inbox (Stage 3) ──
        case 'inbox_meta': ss_need($ctx, 'inbox');
            ss_out(['statuses' => array_values(ss_inbox_statuses()), 'priorities' => array_map(fn($p) => ['name' => $p[0], 'color' => $p[1]], SS_PRIORITIES), 'replies' => ss_replies($db, $ctx),
                    'accounts' => array_values(array_filter(ss_accounts($db, $ctx), fn($a) => $a['connected'])), 'teams' => $db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
                    'agents' => array_values(array_filter(ss_people($db), fn($p) => g7_can($p['id'], 'social', 'inbox'))), 'ai' => ss_plan_allows('social_ai') && (function () { require_once dirname(__DIR__) . '/ia_lib.php'; return function_exists('g7_claude_ready') && g7_claude_ready(); })(),
                    'human_agent' => ss_meta_cfg()['human_agent'], 'ticket_opts' => $ctx['can']['tickets'] ? ss_ticket_options($db, $ctx) : null]);
        case 'inbox_list': ss_inbox_process($db, 50); ss_out(ss_inbox_list($db, $ctx, (array)($in['filters'] ?? [])));
        case 'inbox_get': ss_out(ss_conv_detail($db, $ctx, $id));
        case 'inbox_update': ss_conv_update($db, $ctx, $id, $in); ss_out(['ok' => true] + ss_conv_detail($db, $ctx, $id, false));
        case 'inbox_note': ss_conv_note($db, $ctx, $id, (string)($in['body'] ?? '')); ss_out(['ok' => true] + ss_conv_detail($db, $ctx, $id, false));
        case 'inbox_reply': ss_conv_reply($db, $ctx, $id, (string)($in['body'] ?? ''), (int)($in['file_id'] ?? 0)); ss_out(['ok' => true] + ss_conv_detail($db, $ctx, $id, false));
        case 'inbox_upload': ss_out(['ok' => true, 'file' => ss_conv_upload($db, $ctx, $id, $_FILES['file'] ?? null)]);
        case 'inbox_sync': ss_need($ctx, 'inbox'); if (!ss_rate_ok($db, 'sync:' . $uid, 6, 600)) ss_fail('Ya se sincronizó hace poco: esperá unos minutos.', 429); ss_out(['ok' => true] + ss_inbox_sync_all($db, $ctx));
        case 'inbox_reply_render': ss_out(['text' => ss_reply_render($db, $ctx, (int)($in['reply_id'] ?? 0), $id)]);
        case 'inbox_suggest': ss_out(['text' => ss_conv_suggest($db, $ctx, $id, (string)($in['hint'] ?? ''))]);
        case 'inbox_ticket': $tid = ss_conv_ticket($db, $ctx, $id, $in); ss_out(['ok' => true, 'ticket' => $tid] + ss_conv_detail($db, $ctx, $id, false));
        case 'reply_save': ss_reply_save($db, $ctx, (array)($in['reply'] ?? [])); ss_out(['ok' => true, 'replies' => ss_replies($db, $ctx)]);
        case 'reply_delete': ss_reply_delete($db, $ctx, $id); ss_out(['ok' => true, 'replies' => ss_replies($db, $ctx)]);
        case 'inbox_statuses_save': ss_out(['ok' => true, 'statuses' => array_values(ss_inbox_statuses_save($db, $ctx, (array)($in['statuses'] ?? [])))]);
        case 'publish_cancel': ss_publish_cancel($db, $ctx, $id, (int)($in['target_id'] ?? 0)); ss_out(['ok' => true, 'targets' => ss_targets($db, $id)]);
    }
    ss_out(['error' => 'Acción desconocida.'], 400);
} catch (SsError $e) { ss_out(['error' => $e->getMessage()], $e->getCode() ?: 400); }
catch (RuntimeException $e) { ss_out(['error' => $e->getMessage()], 400); }   // validaciones de Trabajo (wk_create_ticket)
catch (Throwable $e) { error_log('[social] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); ss_out(['error' => 'Algo salió mal. Prueba de nuevo en un momento.'], 500); }
