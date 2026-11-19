<?php
/**
 * bienestar/api.php — API del módulo Bienestar (app.js y admin_bienestar.js). POST JSON {action, ...}; la imagen de un reto
 * llega como multipart (action=ch_upload) con la cabecera X-BN. Todo permiso, alcance y validación se decide acá y en lib.php:
 * la pantalla solo muestra. Respuestas: JSON; errores {error} con 400/401/403/404. Doc: toolz/BIENESTAR.md (lista de acciones).
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function bn_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$me = getCurrentUser();
if (!$me) bn_out(['error' => 'Tu sesión venció: vuelve a entrar.'], 401);
$uid = (int)$me['id'];
if (!g7_can($uid, 'bienestar')) bn_out(['error' => 'No tienes acceso a Bienestar.'], 403);
// Solo POST, y JSON o multipart con cabecera propia: un formulario de otro sitio no puede mandar ninguna de las dos cosas
$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isJson = preg_match('#^application/json\s*(;|$)#i', $ct);
$isUpload = stripos($ct, 'multipart/form-data') === 0 && ($_SERVER['HTTP_X_BN'] ?? '') === '1';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (!$isJson && !$isUpload)) bn_out(['error' => 'Pedido inválido.'], 400);
$db = getDB();
require_once __DIR__ . '/lib.php';
bn_install($db);
session_write_close();   // no retiene la sesión mientras arma estadísticas (reference: pedidos largos)
$in = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_POST;
$act = (string)($in['action'] ?? '');
$id = (int)($in['id'] ?? 0);

try {
    $ctx = bn_ctx($db, $uid);
    switch ($act) {
        // ── Portada y preferencias ──
        case 'home': bn_out(bn_home($db, $ctx) + ['meta' => bn_meta($db, $ctx)]);
        case 'prefs_get': bn_out(['prefs' => bn_prefs($db, $uid), 'labels' => BN_NOTIF_KEYS]);
        case 'prefs_save': bn_out(['prefs' => bn_prefs_save($db, $uid, $in['prefs'] ?? [])]);

        // ── Ánimo (los registros son los de "¿Cómo estás hoy?": mood_lib.php) ──
        case 'mood':
            $days = in_array((int)($in['days'] ?? 30), [7, 30, 90], true) ? (int)$in['days'] : 30;
            $org = bn_flag('bn_show_org_mood') || $ctx['manage'] ? bn_mood_agg($db, null, $days) : null;
            bn_out(['mine' => bn_mood_mine($db, $uid, $days), 'org' => $org, 'teams' => $ctx['manage'] ? bn_mood_teams($db, $ctx, $days) : null, 'days' => $days]);
        case 'mood_set':
            mood_set($db, $uid, (int)($in['mood'] ?? 0), $in['reasons'] ?? []);
            bn_out(['ok' => true, 'mine' => bn_mood_mine($db, $uid, 30)]);

        // ── Hábitos (privados) ──
        case 'habits': bn_out(['habits' => bn_habit_list($db, $uid), 'archived' => bn_habit_list($db, $uid, null, true), 'suggested' => bn_tpl_list($db), 'icons' => BN_ICONS, 'colors' => BN_COLORS]);
        case 'habit_save': $hid = bn_habit_save($db, $uid, $in); bn_out(['ok' => true, 'id' => $hid, 'habits' => bn_habit_list($db, $uid)]);
        case 'habit_check': bn_out(bn_habit_check($db, $uid, $id, $in['day'] ?? null, !empty($in['done'])));
        case 'habit_archive': bn_habit_archive($db, $uid, $id, !isset($in['archive']) || !empty($in['archive'])); bn_out(['ok' => true]);
        case 'habit_delete': bn_habit_delete($db, $uid, $id); bn_out(['ok' => true]);

        // ── Retos ──
        case 'challenges': bn_out(['items' => bn_ch_list($db, $ctx), 'programs' => bn_programs_active($db, $ctx)]);
        case 'ch_join': bn_ch_join($db, $ctx, $id, !isset($in['in_ranking']) || !empty($in['in_ranking'])); bn_out(['ok' => true, 'items' => bn_ch_list($db, $ctx)]);
        case 'ch_leave': bn_ch_leave($db, $ctx, $id); bn_out(['ok' => true, 'items' => bn_ch_list($db, $ctx)]);
        case 'ch_log': bn_out(bn_ch_log($db, $ctx, $id, (int)($in['amount'] ?? 0), (string)($in['note'] ?? '')));
        case 'ch_mylog': bn_out(['items' => bn_ch_mylog($db, $ctx, $id)]);
        case 'ch_log_delete': bn_ch_log_delete($db, $ctx, $id); bn_out(['ok' => true, 'items' => bn_ch_list($db, $ctx)]);

        // ── Encuestas ──
        case 'surveys': bn_out(['items' => bn_sv_mine($db, $ctx)]);
        case 'survey': bn_out(['survey' => bn_sv_for_answer($db, $ctx, $id)]);
        case 'survey_submit': bn_sv_submit($db, $ctx, $id, (array)($in['answers'] ?? [])); bn_out(['ok' => true]);

        // ── Reconocimientos (públicos = kudos de Comunidad) ──
        case 'kudos_feed':
            $f = in_array($in['filter'] ?? 'all', ['all', 'received', 'given', 'private'], true) ? $in['filter'] : 'all';
            if ($f === 'private') bn_out(['items' => bn_kudos_private($db, $uid, 60), 'more' => false]);
            bn_out(bn_kudos_feed($db, $uid, $f, (string)($in['cat'] ?? ''), (int)($in['before'] ?? 0), (int)($in['after'] ?? 0)));
        case 'kudos_give': bn_out(['ok' => true] + bn_kudos_give($db, $ctx, (int)($in['to'] ?? 0), (string)($in['badge'] ?? ''), (string)($in['message'] ?? ''), !empty($in['private'])));
        case 'kudos_react': bn_kudos_need_public($db, $id); bn_out(cm_react($db, $uid, $id, (string)($in['emoji'] ?? 'like')));
        case 'kudos_reply': bn_kudos_need_public($db, $id); $r = cm_add_reply($db, $uid, $id, (string)($in['body'] ?? ''));
            bn_out(['reply' => ['id' => (int)$r['id'], 'user_id' => $uid, 'name' => (string)($r['display_name'] ?: $r['username']), 'avatar' => (string)$r['avatar'], 'body' => (string)$r['body'], 'at' => (string)$r['created_at']]]);
        case 'kudos_thank': bn_kudos_thank($db, $uid, $id); bn_out(['ok' => true]);
        case 'kudos_delete':
            // quien lo publicó o un admin del sitio (moderación); queda en la auditoría
            $p = bn_kudos_need_public($db, $id);
            if ((int)$p['user_id'] !== $uid && !$ctx['admin']) bn_fail('Solo quien lo publicó o un administrador puede borrarlo.', 403);
            $db->prepare('DELETE FROM g7_posts WHERE id = ?')->execute([$id]);
            if ((int)$p['user_id'] !== $uid) bn_audit($ctx, 'kudos_delete', (string)$id, ['from' => (int)$p['user_id'], 'to' => (int)$p['target_user_id'], 'body' => mb_substr((string)$p['body'], 0, 120)]);
            bn_out(['ok' => true]);
        case 'people':
            $q = $db->prepare("SELECT id, username, display_name, avatar FROM g7_users WHERE active = 1 AND id <> ? ORDER BY COALESCE(NULLIF(display_name, ''), username)");
            $q->execute([$uid]);
            bn_out(['items' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)($u['display_name'] ?: $u['username']), 'username' => $u['username'], 'avatar' => (string)$u['avatar']], $q->fetchAll(PDO::FETCH_ASSOC))]);

        // ── Carga laboral ──
        case 'load': bn_out(['mine' => bn_load_mine($db, $uid), 'teams' => $ctx['manage'] ? bn_load_teams($db, $ctx) : null]);
        case 'load_set': bn_load_set($db, $uid, (int)($in['level'] ?? 0)); bn_out(['ok' => true, 'mine' => bn_load_mine($db, $uid)]);

        // ── Vacaciones y licencias ──
        case 'leave': bn_out(bn_leave_mine($db, $ctx) + ['to_decide' => bn_leave_to_decide($db, $ctx)]);
        case 'leave_request': $lid = bn_leave_request($db, $ctx, $in); bn_out(['ok' => true, 'id' => $lid] + bn_leave_mine($db, $ctx));
        case 'leave_cancel': bn_leave_cancel($db, $ctx, $id); bn_out(['ok' => true] + bn_leave_mine($db, $ctx));
        case 'leave_decide': bn_leave_decide($db, $ctx, $id, !empty($in['approve']), (string)($in['note'] ?? '')); bn_out(['ok' => true, 'to_decide' => bn_leave_to_decide($db, $ctx)]);
        case 'leave_calendar': bn_out(bn_leave_calendar($db, $ctx, (string)($in['month'] ?? date('Y-m'))));
        case 'leave_days': $f = bn_date($in['from'] ?? ''); $t = bn_date($in['to'] ?? '') ?: $f; bn_out(['days' => $f && $t >= $f && (strtotime($t) - strtotime($f)) / 86400 < 60 ? bn_workdays($db, $f, $t) : 0]);

        // ── Gestión (admins del sitio y quienes gestionan Bienestar en sus equipos) ──
        case 'manage': bn_need_manage($ctx);
            bn_out(['challenges' => bn_ch_manage($db, $ctx), 'surveys' => bn_sv_manage($db, $ctx), 'programs' => bn_programs_manage($db, $ctx), 'teams' => bn_teams_for($db, $ctx),
                'org' => $ctx['scope'] === null, 'templates' => BN_SV_TEMPLATES, 'cats' => BN_SV_CATS, 'types' => BN_SV_TYPES, 'recur' => BN_SV_RECUR, 'icons' => BN_ICONS, 'colors' => BN_COLORS,
                'rankings' => bn_flag('bn_rankings'), 'to_decide' => count(bn_leave_to_decide($db, $ctx))]);
        case 'ch_save': $cid = bn_ch_save($db, $ctx, $in); bn_out(['ok' => true, 'id' => $cid, 'challenges' => bn_ch_manage($db, $ctx)]);
        case 'ch_status': bn_ch_status($db, $ctx, $id, (string)($in['to'] ?? '')); bn_out(['ok' => true, 'challenges' => bn_ch_manage($db, $ctx)]);
        case 'ch_upload': bn_out(['ok' => true, 'path' => $p = bn_ch_upload($ctx, $_FILES['image'] ?? null), 'url' => bn_ch_img($p)]);
        case 'sv_save': $sid = bn_sv_save($db, $ctx, $in); bn_out(['ok' => true, 'id' => $sid, 'surveys' => bn_sv_manage($db, $ctx)]);
        case 'sv_status': bn_sv_status($db, $ctx, $id, (string)($in['to'] ?? '')); bn_out(['ok' => true, 'surveys' => bn_sv_manage($db, $ctx)]);
        case 'sv_results': bn_out(bn_sv_results($db, $ctx, $id, (int)($in['team'] ?? 0)));
        case 'program_save': $pid = bn_program_save($db, $ctx, $in); bn_out(['ok' => true, 'id' => $pid, 'programs' => bn_programs_manage($db, $ctx)]);
        case 'program_delete': bn_program_delete($db, $ctx, $id); bn_out(['ok' => true, 'programs' => bn_programs_manage($db, $ctx)]);
        case 'insights': bn_need_manage($ctx);
            $days = in_array((int)($in['days'] ?? 30), [7, 30, 90], true) ? (int)$in['days'] : 30;
            $team = (int)($in['team'] ?? 0);
            if ($team && !in_array($team, array_column(bn_teams_for($db, $ctx), 'id'), true)) bn_fail('Ese equipo no está en lo que gestionas.', 403);
            $users = $team ? bn_users_of($db, [$team]) : ($ctx['scope'] === null ? null : bn_users_of($db, $ctx['scope']));
            bn_out(['mood' => bn_mood_agg($db, $users, $days), 'teams' => bn_mood_teams($db, $ctx, $days), 'load' => bn_load_teams($db, $ctx), 'participation' => bn_participation($db, $users), 'days' => $days, 'team' => $team]);

        // ── Admin › Bienestar (solo admins del sitio) ──
        case 'adm_get': bn_need_admin($ctx); bn_out(bn_admin_state($db, $ctx));
        case 'adm_save': bn_need_admin($ctx); bn_admin_save($db, $ctx, $in); bn_out(['ok' => true] + bn_admin_state($db, $ctx));
        case 'adm_tpl_save': bn_tpl_save($db, $ctx, $in); bn_out(['ok' => true, 'tpls' => bn_tpl_list($db, true)]);
        case 'adm_tpl_delete': bn_tpl_delete($db, $ctx, $id); bn_out(['ok' => true, 'tpls' => bn_tpl_list($db, true)]);
        case 'adm_balances': bn_out(['items' => bn_leave_balances($db, $ctx, (int)($in['year'] ?? date('Y'))), 'default' => bn_vac_days()]);
        case 'adm_balance_set': bn_leave_balance_set($db, $ctx, (int)($in['user_id'] ?? 0), (int)($in['year'] ?? date('Y')), isset($in['days']) && $in['days'] !== '' && $in['days'] !== null ? (int)$in['days'] : null); bn_out(['ok' => true]);
        case 'adm_kudos': bn_need_admin($ctx); bn_out(bn_kudos_feed($db, $uid, 'all', '', (int)($in['before'] ?? 0), 0, 25));
        case 'adm_audit': bn_need_admin($ctx);
            $q = $db->query("SELECT a.action, a.target, a.detail, a.created_at, u.username, u.display_name FROM g7_admin_audit a LEFT JOIN g7_users u ON u.id = a.user_id WHERE a.action LIKE 'bienestar.%' ORDER BY a.id DESC LIMIT 100");
            bn_out(['items' => array_map(fn($r) => ['action' => substr($r['action'], 10), 'target' => $r['target'], 'detail' => json_decode((string)$r['detail'], true), 'at' => $r['created_at'], 'by' => (string)($r['display_name'] ?: $r['username'])], $q->fetchAll(PDO::FETCH_ASSOC))]);
    }
    bn_out(['error' => 'Acción desconocida.'], 400);
} catch (BnError $e) { bn_out(['error' => $e->getMessage()], $e->getCode() ?: 400); }
catch (RuntimeException $e) { bn_out(['error' => $e->getMessage() === 'invalid' ? 'Escribe una respuesta de hasta 280 caracteres.' : $e->getMessage()], 400); }
catch (Throwable $e) { error_log('[bienestar] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); bn_out(['error' => 'Algo salió mal. Prueba de nuevo en un momento.'], 500); }
