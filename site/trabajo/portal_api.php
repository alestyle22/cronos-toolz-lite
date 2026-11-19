<?php
/**
 * trabajo/portal_api.php — API del Portal de Solicitudes (portal.js, builder.js). POST JSON {action, ...}.
 * Todo permiso, validación, estimación y cálculo de capacidad se decide acá. Doc: toolz/SOLICITUDES.md
 */
require_once __DIR__ . '/../auth.php';
$me = getCurrentUser();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!$me) { http_response_code(401); echo json_encode(['error' => 'Sesión vencida: volvé a ingresar']); exit; }
$db = getDB();
require_once __DIR__ . '/lib.php';
wk_install($db);
session_write_close();

$uid = (int)$me['id'];
$ctx = wk_ctx($db, $uid);
$cfg = tk_config($db);
define('PT_DIR', defined('G7_PT_DIR') ? G7_PT_DIR : __DIR__ . '/../_private/solicitudes');   // las pruebas usan su propia carpeta

function pa_out(array $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function pa_fail(string $m, int $c = 400, array $extra = []): void { http_response_code($c); pa_out(['error' => $m] + $extra); }
function pa_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) && date('Y-m-d', strtotime($v)) === $v ? $v : null; }
function pa_int($v): ?int { return (int)$v ?: null; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) pa_fail('Pedido inválido', 405);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');

/** Departamento que existe (o corta). */
function pa_dept(PDO $db, $id): array { $d = pt_dept($db, (int)$id); if (!$d) pa_fail('Ese departamento no existe', 404); return $d; }
function pa_need_config(PDO $db, array $ctx, int $team): void { if (!pt_can_config($db, $ctx, $team)) pa_fail('Solo administración o el jefe del departamento cambian su configuración', 403); }
function pa_need_manage(PDO $db, array $ctx, int $team): void { if (!pt_can_manage($db, $ctx, $team)) pa_fail('Solo administración, el jefe o un responsable del departamento pueden hacer esto', 403); }

/** Categorías de un departamento en árbol plano (con nivel). */
function pa_cats(PDO $db, int $team, bool $all = false): array {
    $q = $db->prepare('SELECT * FROM g7_wk_ticket_cats WHERE team_id = ?' . ($all ? '' : ' AND active = 1') . ' ORDER BY position, name');
    $q->execute([$team]);
    $rows = array_map(fn($c) => ['id' => (int)$c['id'], 'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null, 'name' => $c['name'], 'icon' => (string)$c['icon'],
        'color' => $c['color'], 'image' => (string)$c['image'], 'description' => (string)$c['description'], 'base_min' => (int)$c['base_min'], 'min_min' => (int)$c['min_min'],
        'max_min' => (int)$c['max_min'], 'rules' => json_decode((string)$c['rules'], true) ?: [], 'components' => json_decode((string)$c['components'], true) ?: [],
        'form_id' => $c['form_id'] ? (int)$c['form_id'] : null, 'active' => (int)$c['active'], 'position' => (int)$c['position']], $q->fetchAll(PDO::FETCH_ASSOC));
    $taskCat = (int)wk_cfg_get($db, 'work_task_cat', '0');
    return array_values(array_filter($rows, fn($c) => $c['id'] !== $taskCat));
}
/** Ids válidos para cada elemento "categorías visuales": [raíz|0 => [ids hijos]]. */
function pa_cat_choices(array $cats): array {
    $out = [0 => []];
    foreach ($cats as $c) { $p = $c['parent_id'] ?: 0; $out[$p][] = $c['id']; }
    return $out;
}

/** Formulario con su versión publicada (o borrador si $draft). */
function pa_form(PDO $db, int $id): array {
    $q = $db->prepare('SELECT * FROM g7_wk_forms WHERE id = ?'); $q->execute([$id]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) pa_fail('Ese formulario no existe', 404);
    $f['id'] = (int)$f['id']; $f['team_id'] = $f['team_id'] ? (int)$f['team_id'] : null; $f['version'] = (int)$f['version'];
    $f['settings'] = json_decode((string)$f['settings'], true) ?: [];
    return $f;
}
function pa_version(PDO $db, int $formId, ?int $version = null): ?array {
    $q = $db->prepare('SELECT * FROM g7_wk_form_versions WHERE form_id = ? ' . ($version ? 'AND version = ?' : 'ORDER BY version DESC LIMIT 1'));
    $q->execute($version ? [$formId, $version] : [$formId]); $v = $q->fetch(PDO::FETCH_ASSOC);
    if (!$v) return null;
    return ['id' => (int)$v['id'], 'version' => (int)$v['version'], 'schema' => pt_clean_schema(json_decode($v['schema_json'], true) ?: []), 'theme' => pt_clean_theme(json_decode((string)$v['theme'], true) ?: []), 'published_at' => $v['published_at']];
}
/** Formulario publicado que esta persona puede usar (o corta). */
function pa_usable_form(PDO $db, array $ctx, int $id): array {
    $f = pa_form($db, $id);
    if (!$f['team_id'] || !$f['active'] || $f['version'] < 1 || $f['is_template']) pa_fail('Ese formulario no está publicado', 404);
    $d = pa_dept($db, $f['team_id']);
    if (!pt_can_request($ctx, $d)) pa_fail('No podés hacerle pedidos a este departamento', 403);
    $v = pa_version($db, $f['id']);
    if (!$v) pa_fail('Ese formulario no está publicado', 404);
    return [$f, $v, $d];
}

/** Contexto de validación: prioridades, categorías, personas y adjuntos ya subidos por quien pide. */
function pa_vctx(PDO $db, array $cfg, array $d, int $uid, bool $checkFiles = true): array {
    $cats = pa_cats($db, $d['id']);
    $members = array_column(pt_members($db, $d['id']), 'id');
    return [
        'priorities' => array_column($cfg['priorities'], 'id'),
        'cats' => pa_cat_choices($cats),
        'people' => function (int $u, array $it) use ($db, $members) {
            if ($it['type'] === 'approval') return in_array($u, $members, true) || in_array($u, wk_team_people($db, null), true);
            $q = $db->prepare('SELECT 1 FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$u]); return (bool)$q->fetchColumn();
        },
        'files' => function (array $list, array $it) use ($db, $uid, $checkFiles) {
            $out = []; $maxMb = (float)($it['valid']['maxmb'] ?? 25);
            $allowed = $it['valid']['ext'] ?? ($it['type'] === 'image' ? PT_IMG_EXT : PT_FILE_EXT);
            foreach ($list as $f) {
                $tok = preg_replace('/[^a-f0-9]/', '', (string)($f['token'] ?? ''));
                if (strlen($tok) !== 32) return 'Archivo no válido';
                if (!$checkFiles) { $out[] = ['token' => $tok, 'name' => pt_s($f['name'] ?? 'archivo', 200)]; continue; }
                $q = $db->prepare('SELECT * FROM g7_wk_tk_files WHERE token = ? AND user_id = ? AND done = 1 AND ticket_id IS NULL'); $q->execute([$tok, $uid]); $r = $q->fetch(PDO::FETCH_ASSOC);
                if (!$r) return 'Un archivo no terminó de subirse: volvé a adjuntarlo';
                if (!in_array($r['ext'], $allowed, true)) return 'Tipo de archivo no permitido (' . $r['ext'] . ')';
                if ($r['size'] > $maxMb * 1048576) return 'Un archivo pesa más de ' . $maxMb . ' MB';
                $out[] = ['token' => $tok, 'name' => $r['name'], 'size' => (int)$r['size'], 'ext' => $r['ext']];
            }
            return $out;
        },
    ];
}
/** Categoría elegida en el formulario (el primer elemento "categorías visuales" con valor) o la del formulario. */
function pa_pick_cat(array $schema, array $ans, ?int $formCat): ?int {
    foreach (pt_items($schema) as $it) if ($it['type'] === 'catpick' && !empty($ans[$it['id']])) $leaf = (int)$ans[$it['id']];
    return $leaf ?? $formCat;
}
function pa_pick_prio(array $schema, array $ans, array $f, array $cfg): string {
    foreach (pt_items($schema) as $it) if ($it['type'] === 'priority' && !empty($ans[$it['id']])) return (string)$ans[$it['id']];
    return $f['priority'] ?: $cfg['default_priority'];
}

/** Lo que calcula el servidor para un pedido (estimación y entrega estimada). Nunca usa tiempos que mande el navegador. */
function pa_calc(PDO $db, array $cfg, array $f, array $v, array $d, array $ans): array {
    $catId = pa_pick_cat($v['schema'], $ans, !empty($f['category_id']) ? (int)$f['category_id'] : null);
    $est = pt_estimate($db, $v['schema'], $ans, $catId);
    $prio = pa_pick_prio($v['schema'], $ans, $f, $cfg);
    $urgent = in_array($prio, PT_URGENT, true);
    $today = pt_today(); $to = date('Y-m-d', strtotime('+' . max(30, $d['max_lead_d']) . ' days'));
    $cap = pt_capacity($db, $d, $today, $to); $com = pt_committed($db, $d['id'], $today, $to);
    $start = max($today, pt_min_date($d));
    $end = $est['work_min'] > 0 ? pt_forward_end($cap, $com, $est['work_min'], $today, $urgent) : null;
    if ($end && $end < $start) $end = null;
    if (!$end && $est['work_min'] > 0) { $alt = pt_alternatives($db, $d, $est['work_min'], $urgent, 1); $end = $alt[0]['day'] ?? null; }
    // tiempo calendario: días corridos desde hoy hasta la entrega estimada
    $calDays = $end ? (int)round((strtotime($end) - strtotime($today)) / 86400) + 1 : null;
    return ['est' => $est, 'priority' => $prio, 'urgent' => $urgent, 'cat_id' => $est['cat_id'], 'earliest' => $end, 'calendar_days' => $calDays, 'min_date' => pt_min_date($d),
            'max_date' => date('Y-m-d', strtotime('+' . max(1, $d['max_lead_d']) . ' days'))];
}

/** Ticket del portal que alguien puede ver. Devuelve el ticket con 'agent', 'manage', 'is_req'. */
function pa_ticket(PDO $db, array $ctx, int $id): array {
    $t = wk_tk_row($db, $id);
    if (!$t) pa_fail('Esa solicitud no existe', 404);
    $team = $t['team_id'] ? (int)$t['team_id'] : null;
    $agent = wk_tk_is_agent($db, $ctx, $t);   // misma regla que Trabajo (lib.php)
    $collab = !$agent && function_exists('wk_tkc_is') && wk_tkc_is($db, $id, $ctx['id']);
    $isReq = (int)$t['requester_id'] === $ctx['id'];
    if (!$agent && !$isReq && !$collab) pa_fail('Esa solicitud no existe', 404);
    $t['agent'] = $agent; $t['is_req'] = $isReq; $t['collab'] = $collab;
    $t['manage'] = $team ? pt_can_manage($db, $ctx, $team) : $ctx['all'];
    return $t;
}
function pa_tk_out(PDO $db, array $t, array $stages): array {
    $st = null; foreach ($stages as $s) if ($s['id'] === $t['stage']) $st = $s;
    return ['id' => (int)$t['id'], 'code' => wk_tk_code((int)$t['id']), 'subject' => $t['subject'], 'status' => $t['status'], 'stage' => $t['stage'], 'stage_name' => $st['name'] ?? $t['stage'],
        'stage_color' => $st['color'] ?? '#8b8b94', 'stage_icon' => $st['icon'] ?? 'fa-circle', 'priority' => $t['priority'], 'team_id' => $t['team_id'] ? (int)$t['team_id'] : null,
        'category_id' => $t['category_id'] ? (int)$t['category_id'] : null, 'requester_id' => (int)$t['requester_id'], 'assignee_id' => $t['assignee_id'] ? (int)$t['assignee_id'] : null,
        'created_at' => $t['created_at'], 'requested_date' => $t['requested_date'], 'committed_date' => $t['committed_date'], 'est_min' => $t['est_min'] !== null ? (int)$t['est_min'] : null,
        'real_min' => (int)$t['real_min'], 'overload' => (int)$t['overload'], 'approval' => $t['approval'], 'cancelled' => (int)$t['cancelled'], 'form_id' => $t['form_id'] ? (int)$t['form_id'] : null,
        'updated_at' => $t['updated_at'], 'completed_at' => $t['completed_at'], 'late' => $t['committed_date'] && $t['committed_date'] < pt_today() && in_array($t['status'], ['abierto', 'en_curso', 'esperando'], true)];
}

/** Cambia la etapa (y el estado principal si corresponde), con el registro y los avisos. */
function pa_set_stage(PDO $db, array $t, string $to, int $uid, string $note = ''): void {
    $s = pt_stage($db, $to); if (!$s) pa_fail('Etapa no válida');
    $now = pt_now();
    $f = ['stage' => $to];
    if ($s['status'] !== $t['status']) $f += wk_tk_status_fields($t, $s['status'], $now);
    if ($to === 'cancelado') $f['cancelled'] = 1; elseif ($t['cancelled']) $f['cancelled'] = 0;
    if ($to === 'progreso' && !$t['started_at']) $f['started_at'] = $now;
    if ($to === 'completado') $f['completed_at'] = $now; elseif ($t['completed_at'] && $to !== 'cancelado') $f['completed_at'] = null;
    wk_tk_set($db, (int)$t['id'], $f);
    wk_tk_event($db, (int)$t['id'], $uid, 'pasó a ' . $s['name'] . ($note !== '' ? ': "' . $note . '"' : ''), false);
    wk_task_from_tk($db, (int)$t['id'], $uid);
    if (!empty($t['base_id']) && isset($f['status'])) { require_once __DIR__ . '/base_sync.php'; wb_after($db, $t, null, null, $f['status'], wk_user_name($db, $uid)); }
    $t2 = wk_tk_row($db, (int)$t['id']);
    $ev = ['progreso' => 'pt_started', 'completado' => 'pt_done', 'info' => 'pt_info'][$to] ?? null;
    if ($ev && !($ev === 'pt_started' && $t['started_at'])) pt_notify($db, $ev, $t2, $uid, $note);
    pt_audit($db, $t['team_id'] ? (int)$t['team_id'] : null, (int)$t['id'], $uid, 'stage', ['from' => $t['stage'], 'to' => $to]);
    pt_auto_fire($db, 'stage', (int)$t['id'], $uid);
}

/** Mueve los adjuntos subidos a la carpeta del ticket y los ata a él. */
function pa_attach(PDO $db, int $ticketId, array $files, ?string $fieldId, ?int $msgId = null, bool $internal = false): void {
    foreach ($files as $f) {
        $q = $db->prepare('SELECT * FROM g7_wk_tk_files WHERE token = ? AND ticket_id IS NULL AND done = 1'); $q->execute([$f['token']]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) continue;
        $src = PT_DIR . '/tmp/' . $r['token']; $dir = PT_DIR . '/' . $ticketId;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!@rename($src, $dir . '/' . $r['token'] . '.' . $r['ext'])) continue;
        $db->prepare('UPDATE g7_wk_tk_files SET ticket_id = ?, field_id = ?, msg_id = ?, internal = ? WHERE id = ?')->execute([$ticketId, $fieldId, $msgId, $internal ? 1 : 0, (int)$r['id']]);
    }
}
function pa_files_dir(): void {
    foreach ([PT_DIR, PT_DIR . '/tmp', PT_DIR . '/img'] as $d) if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file(PT_DIR . '/.htaccess')) @file_put_contents(PT_DIR . '/.htaccess', "Require all denied\n");
}
/** Las personas (id, nombre, avatar) para mostrar. */
function pa_people(PDO $db): array {
    return array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)($u['display_name'] ?: $u['username']), 'avatar' => (string)($u['avatar'] ?? '')],
        $db->query("SELECT id, username, display_name, avatar FROM g7_users WHERE active = 1 ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC));
}

switch ($act) {

// ── Portal (quien pide) ──────────────────────────────────────
case 'boot':
    wk_sla_sweep($db); pt_sweep($db);
    $depts = [];
    foreach (pt_depts($db) as $d) {
        $role = pt_role($db, $ctx, $d['id']);
        if (!$role) continue;
        if (!$d['portal_on'] && !in_array($role, ['admin', 'dept_admin'], true)) continue;
        $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_forms WHERE team_id = ? AND active = 1 AND version > 0 AND is_template = 0'); $q->execute([$d['id']]);
        $q2 = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets WHERE team_id = ? AND status IN ('abierto', 'en_curso', 'esperando')"); $q2->execute([$d['id']]);
        $depts[] = ['id' => $d['id'], 'name' => $d['name'], 'description' => $d['description'], 'icon' => $d['icon'], 'color' => $d['color'], 'banner' => $d['banner'],
                    'portal_on' => $d['portal_on'], 'role' => $role, 'forms' => (int)$q->fetchColumn(), 'open' => in_array($role, ['admin', 'dept_admin', 'responsable', 'member'], true) ? (int)$q2->fetchColumn() : null];
    }
    $st = pt_stages($db);
    pa_out(['me' => ['id' => $uid, 'role' => $ctx['role'], 'teams' => $ctx['teams'], 'lead' => $ctx['lead']], 'admin' => $ctx['role'] === 'admin', 'all' => $ctx['all'],
        'depts' => $depts, 'stages' => $st['stages'], 'trans' => $st['trans'], 'priorities' => $cfg['priorities'], 'people' => pa_people($db), 'today' => pt_today(),
        'types' => array_map(fn($t) => $t[0], PT_TYPES), 'off_kinds' => PT_OFF_KINDS, 'file_ext' => PT_FILE_EXT, 'img_ext' => PT_IMG_EXT,
        'old_requests' => (int)$db->query('SELECT COUNT(*) FROM g7_wk_requests')->fetchColumn() > 0,
        'teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)),   // portal aparte (solicitudes/): no tiene el arranque de Trabajo
        'filters' => json_decode((string)$db->query("SELECT pref_value FROM g7_user_prefs WHERE user_id = $uid AND pref_key = 'wk_pt_filters'")->fetchColumn(), true) ?: []]);

case 'dept_home':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    $role = pt_role($db, $ctx, $d['id']);
    if (!$role) pa_fail('No podés hacerle pedidos a este departamento', 403);
    $q = $db->prepare('SELECT id, name, description, icon, color, version, settings FROM g7_wk_forms WHERE team_id = ? AND active = 1 AND version > 0 AND is_template = 0 ORDER BY position, name');
    $q->execute([$d['id']]);
    $forms = array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'description' => $f['description'], 'icon' => $f['icon'], 'color' => $f['color']], $q->fetchAll(PDO::FETCH_ASSOC));
    // Carga de los próximos 14 días (para la franja de disponibilidad del departamento)
    $to = date('Y-m-d', strtotime('+13 days'));
    $cap = pt_capacity($db, $d, pt_today(), $to); $com = pt_committed($db, $d['id'], pt_today(), $to);
    $load = []; foreach ($cap as $day => $c) $load[] = ['day' => $day, 'work' => $c['work'], 'pct' => $c['usable'] ? (int)round(($com[$day]['min'] ?? 0) * 100 / $c['usable']) : null];
    pa_out(['dept' => ['id' => $d['id'], 'name' => $d['name'], 'description' => $d['description'], 'icon' => $d['icon'], 'color' => $d['color'], 'banner' => $d['banner'],
            'show_estimate' => $d['show_estimate'], 'green_pct' => $d['green_pct'], 'yellow_pct' => $d['yellow_pct']], 'role' => $role, 'forms' => $forms, 'cats' => pa_cats($db, $d['id']), 'load' => $load]);

case 'form_get':   // el formulario para llenarlo (versión publicada) + el borrador de quien pide
    [$f, $v, $d] = pa_usable_form($db, $ctx, (int)($in['form_id'] ?? 0));
    $q = $db->prepare('SELECT data FROM g7_wk_pt_drafts WHERE user_id = ? AND form_id = ?'); $q->execute([$uid, $f['id']]);
    $members = array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name']], pt_members($db, $d['id']));
    pa_out(['form' => ['id' => $f['id'], 'name' => $f['name'], 'description' => $f['description'], 'icon' => $f['icon'], 'color' => $f['color'], 'version' => $v['version']],
        'schema' => $v['schema'], 'theme' => $v['theme'], 'cats' => pa_cats($db, $d['id']), 'members' => $members,
        'dept' => ['id' => $d['id'], 'name' => $d['name'], 'color' => $d['color'], 'icon' => $d['icon'], 'show_estimate' => $d['show_estimate'] || pt_can_manage($db, $ctx, $d['id']),
                   'est_unit' => $d['est_unit'], 'overload' => $d['overload'], 'min_date' => pt_min_date($d), 'max_date' => date('Y-m-d', strtotime('+' . max(1, $d['max_lead_d']) . ' days')),
                   'green_pct' => $d['green_pct'], 'yellow_pct' => $d['yellow_pct']],
        'can_override' => pt_can_manage($db, $ctx, $d['id']), 'draft' => json_decode((string)$q->fetchColumn(), true) ?: null]);

case 'estimate':   // cálculo en vivo mientras se llena (no guarda nada)
    [$f, $v, $d] = pa_usable_form($db, $ctx, (int)($in['form_id'] ?? 0));
    [$ans] = pt_validate($v['schema'], (array)($in['answers'] ?? []), pa_vctx($db, $cfg, $d, $uid, false));
    $c = pa_calc($db, $cfg, $f, $v, $d, $ans);
    $show = $d['show_estimate'] || pt_can_manage($db, $ctx, $d['id']);
    pa_out(['show' => (bool)$show, 'work_min' => $show ? $c['est']['work_min'] : null, 'lines' => $show ? $c['est']['lines'] : [], 'unit' => $d['est_unit'],
        'earliest' => $c['earliest'], 'calendar_days' => $show ? $c['calendar_days'] : null, 'min_date' => $c['min_date'], 'max_date' => $c['max_date'], 'priority' => $c['priority'], 'has_work' => $c['est']['work_min'] > 0]);

case 'calendar':   // calendario de carga de un mes para un pedido (o solo la carga si no hay formulario)
    $from = pa_date($in['from'] ?? '') ?: date('Y-m-01'); $to = pa_date($in['to'] ?? '') ?: date('Y-m-t', strtotime($from));
    if ((strtotime($to) - strtotime($from)) / 86400 > 62) pa_fail('Rango demasiado largo');
    if (!empty($in['form_id'])) {
        [$f, $v, $d] = pa_usable_form($db, $ctx, (int)$in['form_id']);
        [$ans] = pt_validate($v['schema'], (array)($in['answers'] ?? []), pa_vctx($db, $cfg, $d, $uid, false));
        $c = pa_calc($db, $cfg, $f, $v, $d, $ans);
        $work = $c['est']['work_min']; $urgent = $c['urgent']; $except = null;
    } else {
        $d = pa_dept($db, $in['team_id'] ?? 0);
        if (!pt_role($db, $ctx, $d['id'])) pa_fail('No ves la carga de este departamento', 403);
        $work = 0; $urgent = false; $except = null;
        if (!empty($in['ticket_id'])) {   // cambiar la fecha de un ticket: su propia carga no cuenta
            $t = pa_ticket($db, $ctx, (int)$in['ticket_id']);
            if ((int)$t['team_id'] !== $d['id']) pa_fail('Ese ticket es de otro departamento');
            $work = max(0, (int)$t['est_min'] - (int)$t['real_min']); $urgent = in_array($t['priority'], PT_URGENT, true); $except = (int)$t['id'];
        }
    }
    $days = pt_calendar($db, $d, $work, $from, $to, $urgent, $except);
    $agentView = pt_role($db, $ctx, $d['id']) !== 'requester';
    $out = ['days' => $days, 'work_min' => $work, 'green_pct' => $d['green_pct'], 'yellow_pct' => $d['yellow_pct'], 'overload' => $d['overload'], 'agent' => $agentView];
    if ($day = pa_date($in['day'] ?? '')) {   // detalle del día elegido + fechas alternativas
        $row = null; foreach ($days as $r) if ($r['day'] === $day) $row = $r;
        if (!$row) { $one = pt_calendar($db, $d, $work, $day, $day, $urgent, $except); $row = $one[0] ?? null; }
        $out['detail'] = $row;
        $out['alternatives'] = $work > 0 ? array_values(array_filter(pt_alternatives($db, $d, $work, $urgent, 4, $except), fn($a) => $a['day'] !== $day)) : [];
        $out['alternatives'] = array_slice($out['alternatives'], 0, 3);
        if ($agentView && $row) {   // quiénes trabajan ese día y cuánto tienen
            $cap = pt_capacity($db, $d, $day, $day)[$day] ?? null; $com = pt_committed($db, $d['id'], $day, $day, $except)[$day] ?? ['people' => []];
            $out['people'] = array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'cap' => $cap['people'][$m['id']] ?? 0, 'committed' => $com['people'][$m['id']] ?? 0, 'off' => $cap['off'][$m['id']] ?? null],
                array_values(array_filter(pt_members($db, $d['id']), fn($m) => $m['works'])));
        }
    }
    pa_out($out);

case 'draft_save':
    [$f] = pa_usable_form($db, $ctx, (int)($in['form_id'] ?? 0));
    $data = json_encode(['answers' => (array)($in['answers'] ?? []), 'title' => pt_s($in['title'] ?? '', 200), 'step' => (int)($in['step'] ?? 0), 'date' => pa_date($in['date'] ?? '')], JSON_UNESCAPED_UNICODE);
    if (strlen($data) > 500000) pa_fail('El borrador es demasiado grande');
    $db->prepare('INSERT INTO g7_wk_pt_drafts (user_id, form_id, data, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)')->execute([$uid, $f['id'], $data, pt_now()]);
    pa_out(['ok' => true, 'saved_at' => pt_now()]);

case 'draft_del':
    $db->prepare('DELETE FROM g7_wk_pt_drafts WHERE user_id = ? AND form_id = ?')->execute([$uid, (int)($in['form_id'] ?? 0)]);
    pa_out(['ok' => true]);

case 'upload_start':   // adjunto en partes (el hosting limita cada pedido a pocos MB)
    $name = pt_s(basename(str_replace('\\', '/', (string)($in['name'] ?? ''))), 200);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || !in_array($ext, PT_FILE_EXT, true)) pa_fail('Tipo de archivo no permitido (' . ($ext ?: 'sin extensión') . ')');
    $size = (int)($in['size'] ?? 0);
    if ($size <= 0 || $size > 100 * 1048576) pa_fail('El archivo tiene que pesar menos de 100 MB');
    $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_tk_files WHERE user_id = ? AND ticket_id IS NULL AND created_at > ?'); $q->execute([$uid, date('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$q->fetchColumn() > 200) pa_fail('Demasiados archivos subidos sin enviar: probá más tarde');
    pa_files_dir();
    $tok = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO g7_wk_tk_files (token, name, ext, size, done, user_id, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)')->execute([$tok, $name, $ext, $size, $uid, pt_now()]);
    file_put_contents(PT_DIR . '/tmp/' . $tok, '');
    pa_out(['token' => $tok, 'chunk' => 1048576]);

case 'upload_chunk':
    $tok = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? ''));
    $q = $db->prepare('SELECT * FROM g7_wk_tk_files WHERE token = ? AND user_id = ? AND done = 0'); $q->execute([$tok, $uid]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) pa_fail('Esa subida no existe', 404);
    $path = PT_DIR . '/tmp/' . $tok; clearstatcache(true, $path);
    $have = (int)@filesize($path);
    if ((int)($in['offset'] ?? -1) !== $have) pa_fail('La subida se desordenó: volvé a adjuntar el archivo', 409, ['offset' => $have]);
    $bin = base64_decode((string)($in['data'] ?? ''), true);
    if ($bin === false || strlen($bin) > 1048576 || $have + strlen($bin) > (int)$r['size']) pa_fail('Parte del archivo no válida');
    file_put_contents($path, $bin, FILE_APPEND | LOCK_EX);
    pa_out(['offset' => $have + strlen($bin)]);

case 'upload_done':
    $tok = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? ''));
    $q = $db->prepare('SELECT * FROM g7_wk_tk_files WHERE token = ? AND user_id = ? AND done = 0'); $q->execute([$tok, $uid]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) pa_fail('Esa subida no existe', 404);
    $path = PT_DIR . '/tmp/' . $tok; clearstatcache(true, $path);
    if ((int)@filesize($path) !== (int)$r['size']) pa_fail('El archivo no llegó completo');
    if (in_array($r['ext'], ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) && !@getimagesize($path)) { @unlink($path); $db->prepare('DELETE FROM g7_wk_tk_files WHERE id = ?')->execute([(int)$r['id']]); pa_fail('La imagen está dañada o no es una imagen'); }
    $db->prepare('UPDATE g7_wk_tk_files SET done = 1 WHERE id = ?')->execute([(int)$r['id']]);
    pa_out(['token' => $tok, 'name' => $r['name'], 'size' => (int)$r['size'], 'ext' => $r['ext']]);

case 'submit':
    [$f, $v, $d] = pa_usable_form($db, $ctx, (int)($in['form_id'] ?? 0));
    $title = pt_s($in['title'] ?? '', 200);
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['key'] ?? ''));
    if (strlen($key) < 6) pa_fail('Falta la clave del envío');
    $dedupe = sha1($uid . '|' . $key);
    // Anti-duplicados: el mismo envío (doble clic o reintento) devuelve el mismo ticket
    $q = $db->prepare('SELECT id FROM g7_wk_tickets WHERE dedupe_key = ?'); $q->execute([$dedupe]);
    if ($dup = (int)$q->fetchColumn()) pa_out(['id' => $dup, 'code' => wk_tk_code($dup), 'duplicate' => true]);
    [$ans, $err] = pt_validate($v['schema'], (array)($in['answers'] ?? []), pa_vctx($db, $cfg, $d, $uid));
    if ($title === '') $err['__title'] = 'Poné un título para tu pedido';
    if ($err) pa_fail('Revisá los campos marcados', 422, ['errors' => $err]);
    $c = pa_calc($db, $cfg, $f, $v, $d, $ans);
    $work = $c['est']['work_min'];
    $date = pa_date($in['date'] ?? '');
    $isManager = pt_can_manage($db, $ctx, $d['id']);
    if ($work > 0 && !$date) pa_fail('Elegí la fecha en que lo necesitás', 422, ['errors' => ['__date' => 'Elegí una fecha']]);
    if ($date) {
        if ($date < $c['min_date'] && !$isManager) pa_fail('La fecha es anterior a la anticipación mínima del departamento (' . $c['min_date'] . ')', 422, ['errors' => ['__date' => 'Muy pronto']]);
        if ($date > $c['max_date']) pa_fail('La fecha es demasiado lejana (hasta ' . $c['max_date'] . ')', 422, ['errors' => ['__date' => 'Muy lejos']]);
        $cd = pt_capacity($db, $d, $date, $date)[$date] ?? null;
        if (!$cd || !$cd['work']) pa_fail('Ese día el departamento no trabaja: elegí otra fecha', 422, ['errors' => ['__date' => 'Día no laborable']]);
    }
    $catId = $c['cat_id'] ?: (!empty($f['category_id']) ? (int)$f['category_id'] : null);
    if ($catId) { $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ? AND active = 1'); $q->execute([$catId]); if ((int)$q->fetchColumn() !== $d['id']) $catId = null; }
    if (!$catId) {   // la primera categoría de primer nivel del departamento; si no tiene, se crea "Pedidos a …"
        $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE active = 1 AND team_id = ? AND parent_id IS NULL AND id <> ? ORDER BY position LIMIT 1'); $q->execute([$d['id'], (int)wk_cfg_get($db, 'work_task_cat', '0')]);
        $catId = (int)$q->fetchColumn();
        if (!$catId) { $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES (?, ?, ?, 1, 99)')->execute(['Pedidos a ' . $d['name'], $d['id'], $d['color']]); $catId = (int)$db->lastInsertId(); }
    }
    // Aprobación: del departamento, del formulario o por un elemento "Aprobación"
    $approver = null; $needApproval = $d['approval'] || !empty($f['settings']['approval']);
    foreach (pt_items($v['schema']) as $it) if ($it['type'] === 'approval' && array_key_exists($it['id'], $ans)) { $needApproval = true; $approver = (int)$ans[$it['id']] ?: ($it['approver'] ?: null); }
    if ($isManager && !empty($in['skip_approval'])) $needApproval = false;
    // Descripción legible (como siempre: "Pregunta: respuesta")
    $catName = function (int $id) use ($db) { $q = $db->prepare('SELECT name FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); return (string)$q->fetchColumn(); };
    $lines = [];
    foreach ($v['schema']['sections'] as $sec) {
        $secLines = [];
        foreach (pt_items(['sections' => [$sec]]) as $it) if (array_key_exists($it['id'], $ans) && $it['type'] !== 'hidden' && ($txt = pt_answer_text($it, $ans[$it['id']], fn($u) => wk_user_name($db, $u), $catName)) !== '') $secLines[] = ($it['label'] ?: PT_TYPES[$it['type']][0]) . ': ' . $txt;
        if ($secLines) { if (count($v['schema']['sections']) > 1 && $sec['title'] !== '') $lines[] = "\n" . mb_strtoupper($sec['title']); array_push($lines, ...$secLines); }
    }
    $desc = trim(implode("\n", $lines)) ?: $title;
    $reason = pt_s($in['overload_reason'] ?? '', 500);
    try {
        $res = pt_with_lock($db, $d['id'], function () use ($db, $d, $f, $v, $c, $ans, $title, $desc, $date, $work, $catId, $dedupe, $uid, $isManager, $reason, $in, $needApproval, $approver) {
            // Dentro del candado: nadie más está guardando carga en este departamento
            $q = $db->prepare('SELECT id FROM g7_wk_tickets WHERE dedupe_key = ?'); $q->execute([$dedupe]);
            if ($dup = (int)$q->fetchColumn()) return ['dup' => $dup];
            $over = 0;
            if ($work > 0 && $date) {
                $today = pt_today();
                $cap = pt_capacity($db, $d, $today, $date); $com = pt_committed($db, $d['id'], $today, $date);
                $p = pt_plan($cap, $com, $work, $today, $date, $c['urgent']);
                $over = $p['over'];
                if ($over > 0) {
                    $alts = pt_alternatives($db, $d, $work, $c['urgent'], 3);
                    $pol = $d['overload'];
                    if ($pol === 'leads' && !$isManager) return ['block' => 'Ese día ya está lleno y el departamento solo acepta pedidos con sobrecarga de sus responsables. Elegí una de las fechas sugeridas.', 'alternatives' => $alts, 'over' => $over];
                    if (empty($in['accept_overload'])) return ['confirm' => 'Ese día ya está lleno: tu pedido quedaría con ' . pt_fmt_min($over) . ' de sobrecarga.', 'alternatives' => $alts, 'over' => $over, 'need_reason' => $pol === 'reason'];
                    if ($pol === 'reason' && $reason === '') return ['confirm' => 'Contá por qué lo necesitás ese día (el departamento lo pide para aceptar sobrecarga).', 'alternatives' => $alts, 'over' => $over, 'need_reason' => true];
                }
            }
            if ($f['board_id']) $GLOBALS['WK_TK_BOARD'] = (int)$f['board_id'];
            $id = wk_create_ticket($db, $uid, $catId, $c['priority'], $title, $desc, ['origin' => 'form', 'form_id' => $f['id'], 'answers' => $ans]);
            unset($GLOBALS['WK_TK_BOARD']);
            $db->prepare('UPDATE g7_wk_tickets SET stage = ?, est_min = ?, est_detail = ?, requested_date = ?, committed_date = ?, form_version_id = ?, approval = ?, approver_id = ?,
                    dedupe_key = ?, overload_note = ?, team_id = ? WHERE id = ?')
               ->execute([$needApproval ? 'revision' : 'nuevo', $work > 0 ? $work : null, json_encode($c['est'], JSON_UNESCAPED_UNICODE), $date, $date, $v['id'], $needApproval ? 'pending' : null, $approver,
                          $dedupe, $over > 0 ? ($reason !== '' ? $reason : 'Aceptó la sobrecarga') : '', $d['id'], $id]);
            pt_recalc($db, $id);
            return ['id' => $id, 'over' => $over];
        });
    } catch (RuntimeException $e) { pa_fail($e->getMessage()); }
    if (!empty($res['dup'])) pa_out(['id' => $res['dup'], 'code' => wk_tk_code($res['dup']), 'duplicate' => true]);
    if (!empty($res['block'])) pa_fail($res['block'], 409, ['overload' => true, 'blocked' => true, 'alternatives' => $res['alternatives'], 'over' => $res['over']]);
    if (!empty($res['confirm'])) pa_fail($res['confirm'], 409, ['overload' => true, 'alternatives' => $res['alternatives'], 'over' => $res['over'], 'need_reason' => $res['need_reason']]);
    $id = $res['id'];
    // Adjuntos del formulario
    foreach (pt_items($v['schema']) as $it) if (in_array($it['type'], ['file', 'image'], true) && !empty($ans[$it['id']])) pa_attach($db, $id, $ans[$it['id']], $it['id']);
    $t = wk_tk_row($db, $id);
    wk_tk_event($db, $id, null, 'Estimación: ' . pt_fmt_min($work) . ($date ? ' · pedido para el ' . $date : '') . ($res['over'] > 0 ? ' · con sobrecarga de ' . pt_fmt_min($res['over']) . ($reason !== '' ? ' ("' . $reason . '")' : '') : ''), true);
    pt_audit($db, $d['id'], $id, $uid, 'submit', ['form' => $f['id'], 'version' => $v['version'], 'est_min' => $work, 'date' => $date, 'over' => $res['over']]);
    if ($res['over'] > 0) { pt_audit($db, $d['id'], $id, $uid, 'overload', ['min' => $res['over'], 'reason' => $reason]); pt_notify($db, 'pt_overload', $t, $uid, 'Sobrecarga de ' . pt_fmt_min($res['over']) . ' el ' . $date . ($reason !== '' ? ': ' . $reason : '')); }
    pt_notify($db, 'pt_received', $t, null, 'Recibimos tu pedido ' . wk_tk_code($id) . '.');
    if ($needApproval) pt_notify($db, 'pt_approval', $t, $uid, $title);
    // Responsable: el fijo del formulario, o el automático del departamento (si no espera aprobación)
    if ($f['assignee_id']) { wk_tk_set($db, $id, ['assignee_id' => (int)$f['assignee_id']]); wk_task_from_tk($db, $id, $uid); wk_tk_notify($db, 'assigned', [(int)$f['assignee_id']], wk_tk_row($db, $id), $uid, 'Pedido desde ' . $f['name']); }
    elseif ($d['auto_assign'] && !$needApproval) {
        $p = pt_auto_pick($db, wk_tk_row($db, $id));
        if ($p['uid']) { wk_tk_set($db, $id, ['assignee_id' => $p['uid']]); wk_task_from_tk($db, $id, null); wk_tk_event($db, $id, null, 'Asignación automática a ' . wk_user_name($db, $p['uid']) . ': ' . $p['why'], true); wk_tk_notify($db, 'assigned', [$p['uid']], wk_tk_row($db, $id), null, 'Asignación automática'); }
        else wk_tk_event($db, $id, null, 'Asignación automática: ' . $p['why'], true);
    }
    pt_auto_fire($db, 'created', $id, $uid);
    $db->prepare('DELETE FROM g7_wk_pt_drafts WHERE user_id = ? AND form_id = ?')->execute([$uid, $f['id']]);
    $t = wk_tk_row($db, $id);
    pa_out(['id' => $id, 'code' => wk_tk_code($id), 'est_min' => $work, 'date' => $date, 'over' => $res['over'], 'stage' => $t['stage'], 'assignee_id' => $t['assignee_id'] ? (int)$t['assignee_id'] : null]);

// ── Tickets ──────────────────────────────────────────────────
case 'tickets':
    $scope = (string)($in['scope'] ?? 'mine');
    $w = []; $a = [];
    if ($scope === 'mine') { $w[] = 't.requester_id = ?'; $a[] = $uid; }
    elseif ($scope === 'assigned') { $w[] = 't.assignee_id = ?'; $a[] = $uid; }
    else {
        if (!$ctx['all']) { $w[] = '(t.team_id IN (' . wk_ints($ctx['teams']) . ') OR t.assignee_id = ? OR t.approver_id = ?)'; $a[] = $uid; $a[] = $uid; }
        if ($scope === 'approval') { $w[] = "t.approval = 'pending'"; }
    }
    $w[] = 't.form_version_id IS NOT NULL';   // solo los del portal (los demás siguen en Trabajo › Tickets)
    if ($x = pa_int($in['team_id'] ?? 0)) { $w[] = 't.team_id = ?'; $a[] = $x; }
    if (array_key_exists('assignee_id', $in) && $in['assignee_id'] !== '' && $in['assignee_id'] !== null) { if ((int)$in['assignee_id'] === 0) $w[] = 't.assignee_id IS NULL'; else { $w[] = 't.assignee_id = ?'; $a[] = (int)$in['assignee_id']; } }
    if (!empty($in['stage'])) { $st = array_values(array_filter((array)$in['stage'], 'is_string')); if ($st) { $w[] = 't.stage IN (' . implode(',', array_fill(0, count($st), '?')) . ')'; array_push($a, ...$st); } }
    if (!empty($in['priority'])) { $w[] = 't.priority = ?'; $a[] = (string)$in['priority']; }
    if ($x = pa_int($in['category_id'] ?? 0)) { $w[] = '(t.category_id = ? OR c.parent_id = ?)'; $a[] = $x; $a[] = $x; }
    if (($in['open'] ?? '') === '1') $w[] = "t.status IN ('abierto', 'en_curso', 'esperando')";
    if (($in['open'] ?? '') === '0') $w[] = "t.status IN ('resuelto', 'cerrado')";
    if (!empty($in['late'])) $w[] = "t.committed_date < '" . pt_today() . "' AND t.status IN ('abierto', 'en_curso', 'esperando')";
    if (!empty($in['overload'])) $w[] = 't.overload = 1';
    if ($x = pa_date($in['from'] ?? '')) { $w[] = 'COALESCE(t.committed_date, DATE(t.created_at)) >= ?'; $a[] = $x; }
    if ($x = pa_date($in['to'] ?? '')) { $w[] = 'COALESCE(t.committed_date, DATE(t.created_at)) <= ?'; $a[] = $x; }
    $qq = pt_s($in['q'] ?? '', 100);
    if ($qq !== '') {
        if (preg_match('/^(?:t-?)?0*(\d{1,9})$/i', $qq, $m)) { $w[] = '(t.id = ? OR t.subject LIKE ?)'; $a[] = (int)$m[1]; $a[] = '%' . $qq . '%'; }
        else { $like = '%' . addcslashes($qq, '%_\\') . '%'; $w[] = '(t.subject LIKE ? OR t.description LIKE ?)'; array_push($a, $like, $like); }
    }
    $sorts = ['date' => 'COALESCE(t.committed_date, \'9999-12-31\')', 'created' => 't.created_at', 'updated' => 't.updated_at', 'priority' => "FIELD(t.priority, 'critica', 'urgente', 'alta', 'media', 'baja')", 'est' => 't.est_min', 'id' => 't.id'];
    $sort = $sorts[$in['sort'] ?? 'date'] ?? $sorts['date']; $dir = ($in['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $per = max(10, min(500, (int)($in['per'] ?? 100))); $page = max(1, (int)($in['page'] ?? 1));
    $where = $w ? implode(' AND ', $w) : '1';
    $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id WHERE $where"); $q->execute($a); $total = (int)$q->fetchColumn();
    $q = $db->prepare("SELECT t.* FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id WHERE $where ORDER BY $sort $dir, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $q->execute($a);
    $stages = pt_stages($db)['stages'];
    $rows = array_map(fn($t) => pa_tk_out($db, $t, $stages), $q->fetchAll(PDO::FETCH_ASSOC));
    // Categorías de lo que se muestra (nombre, ícono y color)
    $cids = array_filter(array_column($rows, 'category_id'));
    $cats = $cids ? $db->query('SELECT id, name, icon, color, parent_id FROM g7_wk_ticket_cats WHERE id IN (' . wk_ints($cids) . ')')->fetchAll(PDO::FETCH_ASSOC) : [];
    pa_out(['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'cats' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'color' => $c['color']], $cats)]);

case 'filters_save':   // filtros guardados de cada persona
    $list = array_slice(array_values(array_filter((array)($in['filters'] ?? []), 'is_array')), 0, 20);
    $list = array_map(fn($f) => ['name' => pt_s($f['name'] ?? '', 60), 'q' => array_slice((array)($f['q'] ?? []), 0, 20)], $list);
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'wk_pt_filters', ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")->execute([$uid, json_encode($list, JSON_UNESCAPED_UNICODE)]);
    pa_out(['ok' => true, 'filters' => $list]);

case 'ticket':
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $stages = pt_stages($db);
    $out = pa_tk_out($db, $t, $stages['stages']);
    $out['description'] = $t['description'];
    $out['agent'] = $t['agent']; $out['manage'] = $t['manage']; $out['is_req'] = $t['is_req'];
    $out['approver_id'] = $t['approver_id'] ? (int)$t['approver_id'] : null;
    $out['task_id'] = $t['task_id'] ? (int)$t['task_id'] : null;
    $out['overload_note'] = $t['overload_note'];
    $out['est_detail'] = ($t['agent'] || ($t['team_id'] && pt_dept($db, (int)$t['team_id'])['show_estimate'])) ? (json_decode((string)$t['est_detail'], true) ?: null) : null;
    // Transiciones posibles para esta persona
    $next = $stages['trans'][$t['stage']] ?? [];
    if (!$t['manage']) {
        if ($t['agent'] && $t['assignee_id'] && (int)$t['assignee_id'] === $uid) $next = array_values(array_diff($next, ['aprobado', 'revision']));   // el que lo hace no se aprueba solo
        elseif ($t['agent']) $next = [];
        elseif ($t['is_req']) $next = in_array($t['stage'], ['nuevo', 'revision', 'aprobado', 'info'], true) ? ['cancelado'] : [];
        else $next = [];
    }
    if ($t['approval'] === 'pending') $next = array_values(array_diff($next, ['aprobado']));   // se aprueba con el botón de aprobar
    $out['next'] = array_values($next);
    $out['can_approve'] = $t['approval'] === 'pending' && ($t['manage'] || ($t['approver_id'] && (int)$t['approver_id'] === $uid));
    // Respuestas con la estructura de la versión con la que nació
    $out['form'] = null;
    if ($t['form_version_id']) {
        $q = $db->prepare('SELECT v.*, f.name FROM g7_wk_form_versions v JOIN g7_wk_forms f ON f.id = v.form_id WHERE v.id = ?'); $q->execute([(int)$t['form_version_id']]);
        if ($fv = $q->fetch(PDO::FETCH_ASSOC)) {
            $sch = pt_clean_schema(json_decode($fv['schema_json'], true) ?: []);
            $ans = json_decode((string)$t['answers'], true) ?: [];
            if (!$t['agent']) {   // lo "oculto interno" no lo ve quien pide (ni en la estructura ni en las respuestas)
                foreach (pt_items($sch) as $it) if ($it['type'] === 'hidden') unset($ans[$it['id']]);
                foreach ($sch['sections'] as &$sec) {
                    $sec['items'] = array_values(array_filter($sec['items'], fn($it) => $it['type'] !== 'hidden'));
                    foreach ($sec['items'] as &$it) if (!empty($it['items'])) $it['items'] = array_values(array_filter($it['items'], fn($x) => $x['type'] !== 'hidden'));
                    unset($it);
                }
                unset($sec);
            }
            $out['form'] = ['id' => (int)$fv['form_id'], 'name' => $fv['name'], 'version' => (int)$fv['version'], 'schema' => $sch, 'answers' => $ans];
        }
    }
    // Conversación (sin notas internas para quien pide y colaboradores)
    $q = $db->prepare('SELECT id, user_id, body, internal, kind, created_at, ext_name FROM g7_wk_ticket_msgs WHERE ticket_id = ?' . ($t['agent'] ? '' : ' AND internal = 0') . ' ORDER BY id');
    $q->execute([$t['id']]);
    $out['msgs'] = array_map(fn($m) => ['id' => (int)$m['id'], 'user_id' => $m['user_id'] !== null ? (int)$m['user_id'] : null, 'body' => $m['body'], 'internal' => (int)$m['internal'], 'kind' => $m['kind'], 'at' => $m['created_at'], 'ext' => $m['ext_name']], $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare('SELECT id, msg_id, field_id, name, ext, size, internal, user_id, created_at FROM g7_wk_tk_files WHERE ticket_id = ?' . ($t['agent'] ? '' : ' AND internal = 0') . ' ORDER BY id');
    $q->execute([$t['id']]);
    $out['files'] = array_map(fn($f) => ['id' => (int)$f['id'], 'msg_id' => $f['msg_id'] ? (int)$f['msg_id'] : null, 'field_id' => $f['field_id'], 'name' => $f['name'], 'ext' => $f['ext'], 'size' => (int)$f['size'], 'internal' => (int)$f['internal']], $q->fetchAll(PDO::FETCH_ASSOC));
    if ($t['agent']) {
        $q = $db->prepare('SELECT day, minutes, is_over, user_id FROM g7_wk_load WHERE ticket_id = ? ORDER BY day'); $q->execute([$t['id']]);
        $out['load'] = array_map(fn($r) => ['day' => $r['day'], 'min' => (int)$r['minutes'], 'over' => (int)$r['is_over'], 'user_id' => $r['user_id'] ? (int)$r['user_id'] : null], $q->fetchAll(PDO::FETCH_ASSOC));
        $q = $db->prepare('SELECT id, user_id, day, minutes, note, created_at FROM g7_wk_tk_time WHERE ticket_id = ? ORDER BY day DESC, id DESC'); $q->execute([$t['id']]);
        $out['time'] = array_map(fn($r) => ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'day' => $r['day'], 'min' => (int)$r['minutes'], 'note' => $r['note']], $q->fetchAll(PDO::FETCH_ASSOC));
        $out['members'] = $t['team_id'] ? array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name'], 'works' => $m['works']], pt_members($db, (int)$t['team_id'])) : [];
    }
    pa_out($out);

case 'ticket_stage':
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $to = (string)($in['stage'] ?? ''); $note = pt_s($in['note'] ?? '', 1000);
    if ($to === $t['stage']) pa_out(['ok' => true]);
    $trans = pt_stages($db)['trans'][$t['stage']] ?? [];
    $mine = $t['assignee_id'] && (int)$t['assignee_id'] === $uid;
    $allowed = $t['manage'] ? in_array($to, $trans, true)
        : (($t['agent'] && $mine && in_array($to, $trans, true) && !in_array($to, ['aprobado', 'revision'], true))
          || ($t['is_req'] && $to === 'cancelado' && in_array($t['stage'], ['nuevo', 'revision', 'aprobado', 'info'], true)));
    if (!$allowed) pa_fail('No podés pasar esta solicitud a esa etapa', 403);
    if ($to === 'aprobado' && $t['approval'] === 'pending') pa_fail('Usá Aprobar para aprobarla');
    if ($to === 'info' && $note === '') pa_fail('Contá qué información falta');
    if ($to === 'info') { [$mid] = wk_tk_post($db, $t, $uid, 'Pedido de información: ' . $note, false, true); wk_tk_mirror($db, (int)$t['id']); }
    pa_set_stage($db, $t, $to, $uid, $to === 'info' ? '' : $note);
    if ($to === 'info') pt_notify($db, 'pt_info', wk_tk_row($db, (int)$t['id']), $uid, $note);
    pa_out(['ok' => true]);

case 'ticket_approve':
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if ($t['approval'] !== 'pending') pa_fail('Esta solicitud no espera aprobación');
    if (!$t['manage'] && !($t['approver_id'] && (int)$t['approver_id'] === $uid)) pa_fail('No podés aprobar esta solicitud', 403);
    $ok = ($in['decision'] ?? '') === 'approve'; $note = pt_s($in['note'] ?? '', 1000);
    if (!$ok && $note === '') pa_fail('Contá el motivo del rechazo');
    wk_tk_set($db, (int)$t['id'], ['approval' => $ok ? 'approved' : 'rejected']);
    $t = wk_tk_row($db, (int)$t['id']) + ['agent' => true];
    pa_set_stage($db, $t, $ok ? 'aprobado' : 'cancelado', $uid, $note);
    wk_tk_event($db, (int)$t['id'], $uid, ($ok ? 'aprobó la solicitud' : 'rechazó la solicitud') . ($note !== '' ? ': "' . $note . '"' : ''), false);
    pt_notify($db, $ok ? 'pt_approved' : 'pt_rejected', wk_tk_row($db, (int)$t['id']), $uid, $note);
    pt_audit($db, $t['team_id'] ? (int)$t['team_id'] : null, (int)$t['id'], $uid, $ok ? 'approve' : 'reject', ['note' => $note]);
    pt_auto_fire($db, $ok ? 'approved' : 'rejected', (int)$t['id'], $uid);
    // Asignar al aprobar (a mano o automático)
    if ($ok && !empty($in['assignee'])) $in = ['id' => $t['id'], 'user' => $in['assignee']] + $in;
    if ($ok && (!empty($in['user']) || (pt_dept($db, (int)$t['team_id'])['auto_assign'] && !$t['assignee_id']))) {
        $want = $in['user'] ?? 'auto';
        $t = wk_tk_row($db, (int)$t['id']);
        $r = pa_assign($db, $ctx, $t, $want, $uid);
        pa_out(['ok' => true, 'assigned' => $r]);
    }
    pa_out(['ok' => true]);

case 'ticket_assign':
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['manage']) pa_fail('Solo administración, el jefe o un responsable del departamento asignan', 403);
    pa_out(['ok' => true, 'assigned' => pa_assign($db, $ctx, $t, $in['user'] ?? null, $uid)]);

case 'auto_preview':   // ranking de la asignación automática, para ver por qué elige a alguien
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['manage']) pa_fail('No podés ver esto', 403);
    pa_out(pt_auto_pick($db, $t));

case 'ticket_dates':   // fecha comprometida y/o estimación (las cambia quien gestiona; queda en el historial y avisa)
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['manage']) pa_fail('Solo administración, el jefe o un responsable cambian fechas y estimaciones', 403);
    $f = []; $ev = [];
    if (array_key_exists('committed_date', $in)) {
        $nd = $in['committed_date'] === null || $in['committed_date'] === '' ? null : pa_date($in['committed_date']);
        if ($in['committed_date'] && !$nd) pa_fail('Fecha no válida');
        if ($nd !== $t['committed_date']) { $f['committed_date'] = $nd; $ev[] = 'fecha comprometida: ' . ($t['committed_date'] ?: '—') . ' → ' . ($nd ?: '—'); }
    }
    if (array_key_exists('est_min', $in)) {
        $ne = max(0, min(100000, (int)$in['est_min']));
        if ($ne !== (int)$t['est_min']) { $f['est_min'] = $ne ?: null; $ev[] = 'estimación: ' . pt_fmt_min((int)$t['est_min']) . ' → ' . pt_fmt_min($ne); }
    }
    if (!$f) pa_out(['ok' => true]);
    $reason = pt_s($in['reason'] ?? '', 500);
    wk_tk_set($db, (int)$t['id'], $f);   // recalcula la carga (pt_on_ticket_set)
    if (isset($f['committed_date']) && $t['task_id']) { $db->prepare('UPDATE g7_tasks SET due_date = ? WHERE id = ?')->execute([$f['committed_date'], (int)$t['task_id']]); }
    $t2 = wk_tk_row($db, (int)$t['id']);
    $txt = implode(' · ', $ev) . ($reason !== '' ? ' ("' . $reason . '")' : '') . ($t2['overload'] ? ' · queda con sobrecarga' : '');
    wk_tk_event($db, (int)$t['id'], $uid, $txt, false);
    if (isset($f['committed_date'])) { pt_notify($db, 'pt_date', $t2, $uid, $txt); pt_auto_fire($db, 'date', (int)$t['id'], $uid); }
    pt_audit($db, (int)$t['team_id'], (int)$t['id'], $uid, 'dates', ['changes' => $ev, 'reason' => $reason]);
    $q = $db->prepare('SELECT day, minutes, is_over AS over_min FROM g7_wk_load WHERE ticket_id = ? ORDER BY day'); $q->execute([(int)$t['id']]);
    pa_out(['ok' => true, 'overload' => (int)$t2['overload'], 'load' => $q->fetchAll(PDO::FETCH_ASSOC)]);

case 'ticket_time':   // horas reales
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['agent']) pa_fail('Solo quien atiende la solicitud carga horas', 403);
    if (!empty($in['del'])) {
        $q = $db->prepare('SELECT * FROM g7_wk_tk_time WHERE id = ? AND ticket_id = ?'); $q->execute([(int)$in['del'], (int)$t['id']]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) pa_fail('Ese registro no existe', 404);
        if ((int)$r['user_id'] !== $uid && !$t['manage']) pa_fail('Solo podés borrar tus horas', 403);
        $db->prepare('DELETE FROM g7_wk_tk_time WHERE id = ?')->execute([(int)$r['id']]);
    } else {
        $m = (int)($in['minutes'] ?? 0); $day = pa_date($in['day'] ?? '') ?: pt_today();
        if ($m < 1 || $m > 24 * 60) pa_fail('Cargá entre 1 minuto y 24 horas');
        if ($day > pt_today()) pa_fail('No se cargan horas en días futuros');
        $db->prepare('INSERT INTO g7_wk_tk_time (ticket_id, user_id, day, minutes, note, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([(int)$t['id'], $uid, $day, $m, pt_s($in['note'] ?? '', 300), pt_now()]);
    }
    $sum = (int)$db->query('SELECT COALESCE(SUM(minutes), 0) FROM g7_wk_tk_time WHERE ticket_id = ' . (int)$t['id'])->fetchColumn();
    wk_tk_set($db, (int)$t['id'], ['real_min' => $sum]);   // lo que falta se vuelve a repartir
    if (empty($in['del'])) wk_tk_event($db, (int)$t['id'], $uid, 'cargó ' . pt_fmt_min((int)$in['minutes']) . ' (total ' . pt_fmt_min($sum) . ')', true);
    pa_out(['ok' => true, 'real_min' => $sum]);

case 'ticket_msg':   // respuesta pública o nota interna, con adjuntos (mismo camino que Tickets: conversación única con la tarea)
    $t = pa_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $body = pt_s($in['body'] ?? '', 20000);
    $files = array_values(array_filter((array)($in['files'] ?? []), 'is_array'));
    if ($body === '' && !$files) pa_fail('Escribí algo');
    $internal = $t['agent'] && !empty($in['internal']);
    $chk = pa_vctx($db, $cfg, pt_dept($db, (int)$t['team_id']) ?? ['id' => 0], $uid)['files'];
    $okFiles = $files ? $chk($files, ['type' => 'file', 'valid' => ['maxfiles' => 10, 'maxmb' => 100]]) : [];
    if (is_string($okFiles)) pa_fail($okFiles);
    [$mid, $up] = wk_tk_post($db, $t, $uid, $body !== '' ? $body : '(adjuntó ' . count($okFiles) . ' archivo' . (count($okFiles) > 1 ? 's' : '') . ')', $internal, $t['agent'], null, wk_user_name($db, $uid));
    if ($okFiles) pa_attach($db, (int)$t['id'], $okFiles, null, $mid, $internal);
    wk_tk_mirror($db, (int)$t['id']);
    if (isset($up['status'])) wk_task_from_tk($db, (int)$t['id'], $uid);
    // Quien pidió responde a un pedido de información: vuelve a la etapa anterior al pedido
    if (!$internal && $t['is_req'] && $t['stage'] === 'info') {
        $q = $db->prepare("SELECT detail FROM g7_wk_pt_audit WHERE ticket_id = ? AND action = 'stage' ORDER BY id DESC LIMIT 1"); $q->execute([(int)$t['id']]);
        $prev = (json_decode((string)$q->fetchColumn(), true) ?: [])['from'] ?? 'nuevo';
        if ($prev === 'info' || !pt_stage($db, $prev)) $prev = 'nuevo';
        pa_set_stage($db, wk_tk_row($db, (int)$t['id']) + ['agent' => true], $prev, $uid, 'respondió el pedido de información');
    }
    $t2 = wk_tk_row($db, (int)$t['id']);
    $men = function_exists('wk_tkc_mentions') ? wk_tkc_mentions($db, $t2, $uid, $body, $internal) : ['notified' => []];
    $to = array_values(array_diff(wk_tk_people($db, $t2, !$internal), $men['notified']));
    if ($internal) $to = array_values(array_filter(array_diff($to, [(int)$t2['requester_id']]), fn($u) => !function_exists('wk_tkc_agent') || wk_tkc_agent($db, $t2, (int)$u)));
    wk_tk_notify($db, 'tk_reply', $to, $t2, $uid, ($internal ? 'Nota interna: ' : '') . mb_substr($body, 0, 200));
    pt_auto_fire($db, 'comment', (int)$t['id'], $uid);
    pa_out(['ok' => true, 'id' => $mid]);

// ── Configuración del departamento ───────────────────────────
case 'dept_create':
    if ($ctx['role'] !== 'admin') pa_fail('Solo administración crea departamentos', 403);
    $name = pt_s($in['name'] ?? '', 100); if ($name === '') pa_fail('Poné un nombre');
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-') ?: 'depto';
    $base = $slug; $i = 1;
    while (true) { $q = $db->prepare('SELECT 1 FROM g7_teams WHERE slug = ?'); $q->execute([$slug]); if (!$q->fetchColumn()) break; $slug = $base . '-' . (++$i); }
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string)($in['color'] ?? '')) ? strtolower($in['color']) : '#ff6600';
    $db->prepare('INSERT INTO g7_teams (name, slug, description, color, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$name, $slug, pt_s($in['description'] ?? '', 500), $color, $uid]);
    $id = (int)$db->lastInsertId();
    pt_dept($db, $id);
    $db->prepare('UPDATE g7_wk_dept SET icon = ?, color = ? WHERE team_id = ?')->execute([preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($in['icon'] ?? '')) ? $in['icon'] : 'fa-building', $color, $id]);
    g7_audit('work_pt_dept_create', 'departamento #' . $id, ['name' => $name], $uid);
    pt_audit($db, $id, null, $uid, 'dept_create', ['name' => $name]);
    pa_out(['id' => $id]);

case 'dept_get':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    $role = pt_role($db, $ctx, $d['id']);
    if (!in_array($role, ['admin', 'dept_admin', 'responsable'], true)) pa_fail('No ves la configuración de este departamento', 403);
    $q = $db->prepare('SELECT * FROM g7_wk_dayoff WHERE (team_id = ? OR team_id IS NULL OR user_id IN (SELECT user_id FROM g7_team_members WHERE team_id = ?)) AND date_to >= ? ORDER BY date_from');
    $q->execute([$d['id'], $d['id'], date('Y-m-d', strtotime('-30 days'))]);
    $offs = array_map(fn($o) => ['id' => (int)$o['id'], 'team_id' => $o['team_id'] ? (int)$o['team_id'] : null, 'user_id' => $o['user_id'] ? (int)$o['user_id'] : null, 'from' => $o['date_from'], 'to' => $o['date_to'],
        'kind' => $o['kind'], 'minutes' => $o['minutes'] === null ? null : (int)$o['minutes'], 'note' => $o['note']], $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare('SELECT day, minutes, note FROM g7_wk_day_cap WHERE team_id = ? AND day >= ? ORDER BY day'); $q->execute([$d['id'], date('Y-m-d', strtotime('-30 days'))]);
    $q2 = $db->prepare('SELECT id, name, description, icon, color, active, version, is_template, updated_at, (draft IS NOT NULL AND draft <> COALESCE((SELECT schema_json FROM g7_wk_form_versions v WHERE v.form_id = g7_wk_forms.id ORDER BY version DESC LIMIT 1), \'\')) AS dirty FROM g7_wk_forms WHERE team_id = ? ORDER BY is_template, position, name');
    $q2->execute([$d['id']]);
    $forms = array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'description' => $f['description'], 'icon' => $f['icon'], 'color' => $f['color'], 'active' => (int)$f['active'], 'version' => (int)$f['version'], 'is_template' => (int)$f['is_template'], 'dirty' => (int)$f['dirty'], 'updated_at' => $f['updated_at']], $q2->fetchAll(PDO::FETCH_ASSOC));
    $q3 = $db->prepare('SELECT * FROM g7_wk_pt_autos WHERE team_id = ? ORDER BY id'); $q3->execute([$d['id']]);
    $autos = array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name'], 'event' => $a['event'], 'cond' => json_decode((string)$a['cond'], true), 'actions' => json_decode((string)$a['actions'], true) ?: [], 'active' => (int)$a['active'], 'runs' => (int)$a['runs'], 'last_run_at' => $a['last_run_at']], $q3->fetchAll(PDO::FETCH_ASSOC));
    // capacidad de los próximos 14 días (para ver el efecto de lo que se configura)
    $to = date('Y-m-d', strtotime('+13 days'));
    $cap = pt_capacity($db, $d, pt_today(), $to); $com = pt_committed($db, $d['id'], pt_today(), $to);
    $next = []; foreach ($cap as $day => $c) $next[] = ['day' => $day, 'work' => $c['work'], 'cap' => $c['usable'], 'raw' => $c['cap'], 'committed' => $com[$day]['min'] ?? 0, 'closed' => $c['closed']];
    pa_out(['dept' => $d, 'role' => $role, 'can_config' => in_array($role, ['admin', 'dept_admin'], true), 'members' => pt_members($db, $d['id']), 'offs' => $offs,
        'day_caps' => $q->fetchAll(PDO::FETCH_ASSOC), 'cats' => pa_cats($db, $d['id'], true), 'forms' => $forms, 'autos' => $autos, 'next' => $next,
        'boards' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT id, name FROM g7_wk_boards WHERE archived = 0 ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC))]);

case 'dept_save':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $x = (array)($in['dept'] ?? []);
    $int = fn($k, $min, $max, $def) => isset($x[$k]) ? max($min, min($max, (int)$x[$k])) : $def;
    $req = ['teams' => array_values(array_map('intval', (array)($x['requesters']['teams'] ?? $d['requesters']['teams'] ?? []))), 'users' => array_values(array_map('intval', (array)($x['requesters']['users'] ?? $d['requesters']['users'] ?? [])))];
    $hours = isset($x['hours']) ? pt_clean_hours($x['hours']) : $d['hours'];
    $vals = [
        'portal_on' => isset($x['portal_on']) ? (int)!empty($x['portal_on']) : $d['portal_on'],
        'description' => isset($x['description']) ? pt_s($x['description'], 2000) : $d['description'],
        'icon' => preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($x['icon'] ?? '')) ? $x['icon'] : $d['icon'],
        'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($x['color'] ?? '')) ? strtolower($x['color']) : $d['color'],
        'banner' => isset($x['banner']) ? (preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp)$/', (string)$x['banner']) ? $x['banner'] : '') : $d['banner'],
        'visibility' => in_array($x['visibility'] ?? '', ['all', 'teams', 'people'], true) ? $x['visibility'] : $d['visibility'],
        'requesters' => json_encode($req), 'hours' => json_encode($hours),
        'cap_day_min' => $int('cap_day_min', 0, 100000, $d['cap_day_min']), 'safety_pct' => $int('safety_pct', 0, 90, $d['safety_pct']), 'reserve_pct' => $int('reserve_pct', 0, 90, $d['reserve_pct']),
        'min_lead_h' => $int('min_lead_h', 0, 24 * 90, $d['min_lead_h']), 'max_lead_d' => $int('max_lead_d', 1, 730, $d['max_lead_d']),
        'green_pct' => $int('green_pct', 1, 200, $d['green_pct']), 'yellow_pct' => $int('yellow_pct', 1, 300, $d['yellow_pct']),
        'overload' => in_array($x['overload'] ?? '', ['warn', 'reason', 'leads'], true) ? $x['overload'] : $d['overload'],
        'show_estimate' => isset($x['show_estimate']) ? (int)!empty($x['show_estimate']) : $d['show_estimate'], 'est_unit' => in_array($x['est_unit'] ?? '', ['h', 'min'], true) ? $x['est_unit'] : $d['est_unit'],
        'approval' => isset($x['approval']) ? (int)!empty($x['approval']) : $d['approval'], 'auto_assign' => isset($x['auto_assign']) ? (int)!empty($x['auto_assign']) : $d['auto_assign'],
        'position' => $int('position', 0, 999, $d['position']),
    ];
    if ($vals['yellow_pct'] < $vals['green_pct']) $vals['yellow_pct'] = $vals['green_pct'];
    $db->prepare('UPDATE g7_wk_dept SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals))) . ', updated_by = ?, updated_at = ? WHERE team_id = ?')->execute([...array_values($vals), $uid, pt_now(), $d['id']]);
    if (isset($x['name']) && ($n = pt_s($x['name'], 100)) !== '' && $n !== $d['name']) $db->prepare('UPDATE g7_teams SET name = ? WHERE id = ?')->execute([$n, $d['id']]);
    $db->prepare('UPDATE g7_teams SET color = ?, description = ? WHERE id = ?')->execute([$vals['color'], mb_substr($vals['description'], 0, 500), $d['id']]);
    $capChanged = $hours !== $d['hours'] || $vals['cap_day_min'] !== $d['cap_day_min'] || $vals['safety_pct'] !== $d['safety_pct'] || $vals['reserve_pct'] !== $d['reserve_pct'];
    $n = $capChanged ? pt_recalc_team($db, $d['id']) : 0;
    pt_audit($db, $d['id'], null, $uid, 'dept_save', ['cap_changed' => $capChanged]);
    pa_out(['ok' => true, 'recalc' => $n]);

case 'member_save':   // horas, dedicación, si produce, responsable y especialidad de un integrante
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $u = (int)($in['user_id'] ?? 0);
    $q = $db->prepare('SELECT 1 FROM g7_team_members WHERE team_id = ? AND user_id = ?'); $q->execute([$d['id'], $u]);
    if (!$q->fetchColumn()) pa_fail('Esa persona no es del departamento');
    $hours = !empty($in['own_hours']) ? json_encode(pt_clean_hours((array)($in['hours'] ?? []))) : null;
    $skills = implode(',', array_slice(array_values(array_intersect(array_map('intval', (array)($in['skills'] ?? [])), array_column(pa_cats($db, $d['id'], true), 'id'))), 0, 50));
    $db->prepare('INSERT INTO g7_wk_member_cap (team_id, user_id, hours, cap_pct, works, is_resp, skills, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE hours = VALUES(hours), cap_pct = VALUES(cap_pct), works = VALUES(works), is_resp = VALUES(is_resp), skills = VALUES(skills), updated_at = VALUES(updated_at)')
       ->execute([$d['id'], $u, $hours, max(0, min(100, (int)($in['cap_pct'] ?? 100))), !empty($in['works']) ? 1 : 0, !empty($in['is_resp']) ? 1 : 0, $skills, pt_now()]);
    pt_audit($db, $d['id'], null, $uid, 'member_save', ['user' => $u]);
    pa_out(['ok' => true, 'recalc' => pt_recalc_team($db, $d['id'])]);

case 'member_add':   // sumar o quitar gente del departamento (es el mismo equipo de Admin › Equipos)
case 'member_remove':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $u = (int)($in['user_id'] ?? 0);
    $q = $db->prepare('SELECT 1 FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$u]); if (!$q->fetchColumn()) pa_fail('Esa persona no existe');
    if ($act === 'member_add') {
        $role = ($in['role'] ?? '') === 'admin' && $ctx['role'] === 'admin' ? 'admin' : 'member';
        $db->prepare('INSERT INTO g7_team_members (team_id, user_id, role) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE role = IF(? = \'admin\', \'admin\', role)')->execute([$d['id'], $u, $role, $role]);
    } else {
        $q = $db->prepare('SELECT role FROM g7_team_members WHERE team_id = ? AND user_id = ?'); $q->execute([$d['id'], $u]);
        if ($q->fetchColumn() === 'admin' && $ctx['role'] !== 'admin') pa_fail('Solo administración saca a un jefe del departamento', 403);
        $db->prepare('DELETE FROM g7_team_members WHERE team_id = ? AND user_id = ?')->execute([$d['id'], $u]);
        $db->prepare('DELETE FROM g7_wk_member_cap WHERE team_id = ? AND user_id = ?')->execute([$d['id'], $u]);
    }
    pt_audit($db, $d['id'], null, $uid, $act, ['user' => $u]);
    pa_out(['ok' => true, 'recalc' => pt_recalc_team($db, $d['id'])]);

case 'off_save':   // feriado (todos), bloqueo (departamento) o ausencia/vacaciones (persona)
    $id = (int)($in['id'] ?? 0);
    $team = pa_int($in['team_id'] ?? 0); $user = pa_int($in['user_id'] ?? 0);
    $from = pa_date($in['from'] ?? ''); $to = pa_date($in['to'] ?? '') ?: $from;
    if (!$from || $to < $from) pa_fail('Revisá las fechas');
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) pa_fail('Como mucho un año');
    $kind = isset(PT_OFF_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'ausencia';
    $min = isset($in['minutes']) && $in['minutes'] !== '' && $in['minutes'] !== null ? max(1, min(1440, (int)$in['minutes'])) : null;
    // Permisos: para todos → admin; departamento → su jefe; persona → el jefe de algún departamento suyo o la persona misma
    $teamsOf = fn(int $u) => array_map('intval', $db->query('SELECT team_id FROM g7_team_members WHERE user_id = ' . $u)->fetchAll(PDO::FETCH_COLUMN));
    $canFor = function (?int $team, ?int $user) use ($db, $ctx, $uid, $teamsOf): bool {
        if ($ctx['role'] === 'admin') return true;
        if ($user) return $user === $uid || (bool)array_filter($teamsOf($user), fn($t) => pt_can_config($db, $ctx, $t));
        if ($team) return pt_can_config($db, $ctx, $team);
        return false;
    };
    if ($id) {
        $q = $db->prepare('SELECT * FROM g7_wk_dayoff WHERE id = ?'); $q->execute([$id]); $old = $q->fetch(PDO::FETCH_ASSOC);
        if (!$old || !$canFor($old['team_id'] ? (int)$old['team_id'] : null, $old['user_id'] ? (int)$old['user_id'] : null)) pa_fail('No podés cambiar ese registro', 403);
    }
    if ($user) $team = null;
    if (!$canFor($team, $user)) pa_fail('No podés cargar este registro', 403);
    $vals = [$team, $user, $from, $to, $kind, $min, pt_s($in['note'] ?? '', 200)];
    if ($id) $db->prepare('UPDATE g7_wk_dayoff SET team_id = ?, user_id = ?, date_from = ?, date_to = ?, kind = ?, minutes = ?, note = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_wk_dayoff (team_id, user_id, date_from, date_to, kind, minutes, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, pt_now()]); $id = (int)$db->lastInsertId(); }
    // recalcula los departamentos afectados y cuenta cuántas solicitudes caen en esas fechas
    $teams = $user ? $teamsOf($user) : ($team ? [$team] : array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($teams as $tm) pt_recalc_team($db, $tm);
    $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets WHERE status IN ('abierto', 'en_curso', 'esperando') AND committed_date BETWEEN ? AND ? AND " . ($user ? 'assignee_id = ' . $user : 'team_id IN (' . wk_ints($teams) . ')'));
    $q->execute([$from, $to]);
    pt_audit($db, $team, null, $uid, 'off_save', ['id' => $id, 'user' => $user, 'from' => $from, 'to' => $to, 'kind' => $kind]);
    pa_out(['id' => $id, 'affected' => (int)$q->fetchColumn()]);

case 'off_del':
    $q = $db->prepare('SELECT * FROM g7_wk_dayoff WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o) pa_fail('Ese registro no existe', 404);
    $ok = $ctx['role'] === 'admin' || ($o['user_id'] && (int)$o['user_id'] === $uid) || ($o['team_id'] && pt_can_config($db, $ctx, (int)$o['team_id']))
        || ($o['user_id'] && array_filter(array_map('intval', $db->query('SELECT team_id FROM g7_team_members WHERE user_id = ' . (int)$o['user_id'])->fetchAll(PDO::FETCH_COLUMN)), fn($t) => pt_can_config($db, $ctx, $t)));
    if (!$ok) pa_fail('No podés borrar ese registro', 403);
    $db->prepare('DELETE FROM g7_wk_dayoff WHERE id = ?')->execute([(int)$o['id']]);
    $teams = $o['user_id'] ? array_map('intval', $db->query('SELECT team_id FROM g7_team_members WHERE user_id = ' . (int)$o['user_id'])->fetchAll(PDO::FETCH_COLUMN)) : ($o['team_id'] ? [(int)$o['team_id']] : array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($teams as $tm) pt_recalc_team($db, $tm);
    pa_out(['ok' => true]);

case 'daycap_save':   // excepción por fecha (minutes null = quitarla)
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $day = pa_date($in['day'] ?? ''); if (!$day) pa_fail('Fecha no válida');
    if ($in['minutes'] === null || $in['minutes'] === '') $db->prepare('DELETE FROM g7_wk_day_cap WHERE team_id = ? AND day = ?')->execute([$d['id'], $day]);
    else $db->prepare('INSERT INTO g7_wk_day_cap (team_id, day, minutes, note) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE minutes = VALUES(minutes), note = VALUES(note)')->execute([$d['id'], $day, max(0, min(100000, (int)$in['minutes'])), pt_s($in['note'] ?? '', 200)]);
    pa_out(['ok' => true, 'recalc' => pt_recalc_team($db, $d['id'])]);

case 'cat_save':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $c = (array)($in['cat'] ?? []); $id = (int)($c['id'] ?? 0);
    if ($id) { $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); if ((int)$q->fetchColumn() !== $d['id']) pa_fail('Esa categoría es de otro departamento', 403); }
    $name = pt_s($c['name'] ?? '', 80); if ($name === '') pa_fail('Poné un nombre');
    $parent = pa_int($c['parent_id'] ?? 0);
    if ($parent) {
        $chain = pt_cat_chain($db, $parent);
        if (!$chain || (int)end($chain)['team_id'] !== $d['id']) pa_fail('La categoría padre no es de este departamento');
        if (in_array($id, array_map(fn($x) => (int)$x['id'], $chain), true)) pa_fail('Una categoría no puede estar dentro de sí misma');
        if (count($chain) >= 3) pa_fail('Hasta tres niveles: categoría, subcategoría y variante');
    }
    $base = max(0, min(100000, (int)($c['base_min'] ?? 0))); $mn = max(0, min(100000, (int)($c['min_min'] ?? 0))); $mx = max(0, min(100000, (int)($c['max_min'] ?? 0)));
    if ($mx && $mn > $mx) pa_fail('El mínimo no puede ser mayor que el máximo');
    $rules = array_values(array_filter(array_map('pt_clean_rule', array_slice((array)($c['rules'] ?? []), 0, 30))));
    $comps = array_values(array_filter(array_map(fn($x) => is_array($x) && pt_s($x['name'] ?? '', 80) !== '' ? ['name' => pt_s($x['name'], 80), 'min' => max(0, min(100000, (int)($x['min'] ?? 0)))] : null, array_slice((array)($c['components'] ?? []), 0, 30))));
    $formId = pa_int($c['form_id'] ?? 0);
    if ($formId) { $q = $db->prepare('SELECT team_id FROM g7_wk_forms WHERE id = ?'); $q->execute([$formId]); if ((int)$q->fetchColumn() !== $d['id']) $formId = null; }
    $vals = [$name, $d['id'], preg_match('/^#[0-9a-f]{6}$/i', (string)($c['color'] ?? '')) ? strtolower($c['color']) : $d['color'], isset($c['active']) ? (int)!empty($c['active']) : 1,
             (int)($c['position'] ?? 0), $parent, preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($c['icon'] ?? '')) ? $c['icon'] : '', preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp)$/', (string)($c['image'] ?? '')) ? $c['image'] : '',
             pt_s($c['description'] ?? '', 500), $base, $mn, $mx, json_encode($rules, JSON_UNESCAPED_UNICODE), $formId, json_encode($comps, JSON_UNESCAPED_UNICODE)];
    if ($id) $db->prepare('UPDATE g7_wk_ticket_cats SET name = ?, team_id = ?, color = ?, active = ?, position = ?, parent_id = ?, icon = ?, image = ?, description = ?, base_min = ?, min_min = ?, max_min = ?, rules = ?, form_id = ?, components = ? WHERE id = ?')->execute([...$vals, $id]);
    else {   // las nuevas van al final de sus hermanas
        $q = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM g7_wk_ticket_cats WHERE team_id = ? AND parent_id <=> ?'); $q->execute([$d['id'], $parent]); $vals[4] = (int)$q->fetchColumn();
        $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position, parent_id, icon, image, description, base_min, min_min, max_min, rules, form_id, components) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($vals); $id = (int)$db->lastInsertId();
    }
    pt_audit($db, $d['id'], null, $uid, 'cat_save', ['id' => $id, 'name' => $name, 'base' => $base]);
    pa_out(['id' => $id]);

case 'cat_del':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $id = (int)($in['id'] ?? 0);
    $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); if ((int)$q->fetchColumn() !== $d['id']) pa_fail('Esa categoría no existe', 404);
    $q = $db->prepare('SELECT (SELECT COUNT(*) FROM g7_wk_tickets WHERE category_id = ?) + (SELECT COUNT(*) FROM g7_wk_ticket_cats WHERE parent_id = ?)'); $q->execute([$id, $id]);
    if ((int)$q->fetchColumn()) { $db->prepare('UPDATE g7_wk_ticket_cats SET active = 0 WHERE id = ?')->execute([$id]); pa_out(['ok' => true, 'msg' => 'Tenía solicitudes o subcategorías: quedó desactivada']); }
    $db->prepare('DELETE FROM g7_wk_ticket_cats WHERE id = ?')->execute([$id]);
    pa_out(['ok' => true]);

case 'est_preview':   // probar una categoría y un esquema sin publicar (configuración y constructor)
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $schema = pt_clean_schema($in['schema'] ?? []);
    [$ans] = pt_validate($schema, (array)($in['answers'] ?? []), pa_vctx($db, $cfg, $d, $uid, false));
    $cat = pa_pick_cat($schema, $ans, pa_int($in['category_id'] ?? 0));
    pa_out(pt_estimate($db, $schema, $ans, $cat));

case 'image_up':   // banner del departamento o imagen de una categoría (≤ 3 MB, base64)
    $team = (int)($in['team_id'] ?? 0); pa_dept($db, $team); pa_need_config($db, $ctx, $team);
    $bin = base64_decode((string)($in['data'] ?? ''), true);
    if (!$bin || strlen($bin) > 3 * 1048576) pa_fail('La imagen tiene que pesar menos de 3 MB');
    $info = @getimagesizefromstring($bin);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) pa_fail('Subí una imagen PNG, JPG o WebP');
    pa_files_dir();
    $name = md5($bin) . '.' . $ext;
    file_put_contents(PT_DIR . '/img/' . $name, $bin);
    pa_out(['name' => $name]);

// ── Formularios (constructor) ────────────────────────────────
case 'form_new':
    $d = pa_dept($db, $in['team_id'] ?? 0);
    pa_need_config($db, $ctx, $d['id']);
    $schema = ['mode' => 'full', 'sections' => [['id' => 's1', 'title' => 'Tu pedido', 'items' => []]]]; $theme = []; $name = pt_s($in['name'] ?? '', 100) ?: 'Formulario nuevo'; $desc = ''; $icon = 'fa-inbox'; $color = $d['color']; $settings = [];
    if ($tpl = (int)($in['from'] ?? 0)) {   // desde una plantilla o duplicando otro formulario
        $src = pa_form($db, $tpl);
        if (!$src['team_id'] || !pt_can_config($db, $ctx, $src['team_id'])) pa_fail('No podés copiar ese formulario', 403);
        $schema = json_decode((string)$src['draft'], true) ?: $schema; $theme = json_decode((string)$src['theme'], true) ?: [];
        $name = pt_s($in['name'] ?? '', 100) ?: ($src['is_template'] || !empty($in['template']) ? $src['name'] : $src['name'] . ' (copia)'); $desc = $src['description']; $icon = $src['icon']; $color = $src['color']; $settings = $src['settings'];
    }
    $schema = pt_clean_schema($schema);
    $now = pt_now();
    $db->prepare('INSERT INTO g7_wk_forms (team_id, name, description, icon, color, fields, board_id, assignee_id, active, position, created_by, created_at, updated_at, draft, version, theme, is_template, settings, schema_v) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, 0, 99, ?, ?, ?, ?, 0, ?, ?, ?, 2)')
       ->execute([$d['id'], $name, $desc, $icon, $color, '[]', $uid, $now, $now, json_encode($schema, JSON_UNESCAPED_UNICODE), json_encode(pt_clean_theme($theme)), !empty($in['template']) ? 1 : 0, json_encode($settings)]);
    $id = (int)$db->lastInsertId();
    pt_audit($db, $d['id'], null, $uid, 'form_new', ['id' => $id, 'from' => $tpl ?: null]);
    pa_out(['id' => $id]);

case 'templates':   // plantillas de los departamentos que esta persona configura
    $rows = [];
    foreach ($db->query('SELECT id, name, team_id FROM g7_wk_forms WHERE is_template = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $f)
        if ($f['team_id'] && pt_can_config($db, $ctx, (int)$f['team_id'])) $rows[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'team_id' => (int)$f['team_id']];
    pa_out(['rows' => $rows]);

case 'form_edit':   // todo lo del constructor: borrador, ajustes, versiones y datos del departamento
    $f = pa_form($db, (int)($in['id'] ?? 0));
    if (!$f['team_id']) pa_fail('Ese formulario no tiene departamento');
    pa_need_config($db, $ctx, $f['team_id']);
    $d = pt_dept($db, $f['team_id']);
    $vs = $db->prepare('SELECT version, published_at, published_by FROM g7_wk_form_versions WHERE form_id = ? ORDER BY version DESC'); $vs->execute([$f['id']]);
    $pub = pa_version($db, $f['id']);
    $q = $db->prepare('SELECT id, name FROM g7_wk_forms WHERE is_template = 1 AND team_id = ? ORDER BY name'); $q->execute([$f['team_id']]);
    pa_out(['form' => ['id' => $f['id'], 'team_id' => $f['team_id'], 'name' => $f['name'], 'description' => $f['description'], 'icon' => $f['icon'], 'color' => $f['color'], 'active' => (int)$f['active'],
            'version' => $f['version'], 'is_template' => (int)$f['is_template'], 'board_id' => $f['board_id'] ? (int)$f['board_id'] : null, 'assignee_id' => $f['assignee_id'] ? (int)$f['assignee_id'] : null,
            'category_id' => !empty($f['category_id']) ? (int)$f['category_id'] : null, 'priority' => $f['priority'], 'settings' => $f['settings'], 'updated_at' => $f['updated_at']],
        'schema' => pt_clean_schema(json_decode((string)$f['draft'], true) ?: []), 'theme' => pt_clean_theme(json_decode((string)$f['theme'], true) ?: []),
        'published' => $pub ? ['version' => $pub['version'], 'schema' => $pub['schema']] : null, 'versions' => $vs->fetchAll(PDO::FETCH_ASSOC),
        'dept' => ['id' => $d['id'], 'name' => $d['name'], 'color' => $d['color'], 'icon' => $d['icon'], 'est_unit' => $d['est_unit']],
        'cats' => pa_cats($db, $d['id']), 'members' => array_map(fn($m) => ['id' => $m['id'], 'name' => $m['name']], pt_members($db, $d['id'])),
        'boards' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT id, name FROM g7_wk_boards WHERE archived = 0 ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC)),
        'templates' => $q->fetchAll(PDO::FETCH_ASSOC), 'priorities' => $cfg['priorities'], 'types' => array_map(fn($t) => $t[0], PT_TYPES)]);

case 'form_save':   // guarda el borrador (no cambia lo publicado)
    $f = pa_form($db, (int)($in['id'] ?? 0));
    if (!$f['team_id']) pa_fail('Ese formulario no tiene departamento');
    pa_need_config($db, $ctx, $f['team_id']);
    if (!empty($in['updated_at']) && $in['updated_at'] !== $f['updated_at'] && empty($in['force'])) pa_fail('Otra persona guardó este formulario mientras lo editabas. Recargá para ver sus cambios.', 409, ['conflict' => true]);
    $schema = pt_clean_schema($in['schema'] ?? []);
    $theme = pt_clean_theme($in['theme'] ?? []);
    $m = (array)($in['meta'] ?? []);
    $name = pt_s($m['name'] ?? $f['name'], 100) ?: $f['name'];
    $board = pa_int($m['board_id'] ?? 0); $asg = pa_int($m['assignee_id'] ?? 0); $cat = pa_int($m['category_id'] ?? 0);
    if ($asg && !in_array($asg, array_column(pt_members($db, $f['team_id']), 'id'), true)) $asg = null;
    if ($cat) { $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$cat]); if ((int)$q->fetchColumn() !== $f['team_id']) $cat = null; }
    $prio = in_array($m['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $m['priority'] : null;
    $settings = ['approval' => !empty($m['settings']['approval'])];
    $now = date('Y-m-d H:i:s');
    $db->prepare('UPDATE g7_wk_forms SET name = ?, description = ?, icon = ?, color = ?, draft = ?, theme = ?, board_id = ?, assignee_id = ?, category_id = ?, priority = ?, settings = ?, updated_at = ? WHERE id = ?')
       ->execute([$name, pt_s($m['description'] ?? $f['description'], 500), preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($m['icon'] ?? '')) ? $m['icon'] : $f['icon'],
                  preg_match('/^#[0-9a-f]{6}$/i', (string)($m['color'] ?? '')) ? strtolower($m['color']) : $f['color'], json_encode($schema, JSON_UNESCAPED_UNICODE), json_encode($theme), $board, $asg, $cat, $prio, json_encode($settings), $now, $f['id']]);
    pa_out(['ok' => true, 'updated_at' => $now, 'schema' => $schema]);

case 'form_publish':   // el borrador pasa a ser una versión nueva (las anteriores quedan para los tickets que nacieron con ellas)
    $f = pa_form($db, (int)($in['id'] ?? 0));
    if (!$f['team_id']) pa_fail('Ese formulario no tiene departamento');
    pa_need_config($db, $ctx, $f['team_id']);
    if ($f['is_template']) pa_fail('Las plantillas no se publican: creá un formulario desde la plantilla');
    $schema = pt_clean_schema(json_decode((string)$f['draft'], true) ?: []);
    $n = count(array_filter(pt_items($schema), fn($it) => PT_TYPES[$it['type']][1] && $it['type'] !== 'hidden'));
    if (!$n) pa_fail('Agregá al menos una pregunta antes de publicar');
    // Las condiciones tienen que apuntar a campos que existen
    $ids = array_column(pt_items($schema), 'id'); $bad = [];
    $walk = function ($c) use (&$walk, $ids, &$bad) { foreach ($c['rules'] ?? [] as $r) { if (isset($r['rules'])) $walk($r); elseif (!in_array($r['f'], $ids, true)) $bad[] = $r['f']; } };
    foreach (pt_items($schema) as $it) if (!empty($it['cond'])) $walk($it['cond']);
    foreach ($schema['sections'] as $s) if (!empty($s['cond'])) $walk($s['cond']);
    if ($bad) pa_fail('Hay condiciones que usan campos que ya no existen: ' . implode(', ', array_unique($bad)));
    $last = pa_version($db, $f['id']);
    if ($last && json_encode($last['schema']) === json_encode($schema) && json_encode($last['theme']) === json_encode(pt_clean_theme(json_decode((string)$f['theme'], true) ?: [])) && $f['active']) pa_out(['ok' => true, 'version' => $last['version'], 'same' => true]);
    $ver = ($last['version'] ?? 0) + 1;
    $db->prepare('INSERT INTO g7_wk_form_versions (form_id, version, schema_json, theme, published_by, published_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$f['id'], $ver, json_encode($schema, JSON_UNESCAPED_UNICODE), $f['theme'], $uid, pt_now()]);
    $db->prepare('UPDATE g7_wk_forms SET version = ?, active = 1, fields = ? WHERE id = ?')->execute([$ver, '[]', $f['id']]);
    g7_audit('work_pt_form_publish', 'formulario #' . $f['id'], ['version' => $ver], $uid);
    pt_audit($db, $f['team_id'], null, $uid, 'form_publish', ['id' => $f['id'], 'version' => $ver]);
    pa_out(['ok' => true, 'version' => $ver]);

case 'form_state':   // activar / desactivar / marcar como plantilla / borrar
    $f = pa_form($db, (int)($in['id'] ?? 0));
    if (!$f['team_id']) pa_fail('Ese formulario no tiene departamento');
    pa_need_config($db, $ctx, $f['team_id']);
    $op = (string)($in['op'] ?? '');
    if ($op === 'delete') {
        $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_tickets WHERE form_id = ?'); $q->execute([$f['id']]);
        if ((int)$q->fetchColumn()) { $db->prepare('UPDATE g7_wk_forms SET active = 0 WHERE id = ?')->execute([$f['id']]); pa_out(['ok' => true, 'msg' => 'Tenía solicitudes: quedó desactivado (no se borra para no perder el historial)']); }
        $db->prepare('DELETE FROM g7_wk_form_versions WHERE form_id = ?')->execute([$f['id']]);
        $db->prepare('DELETE FROM g7_wk_forms WHERE id = ?')->execute([$f['id']]);
        pa_out(['ok' => true]);
    }
    if ($op === 'active') $db->prepare('UPDATE g7_wk_forms SET active = ? WHERE id = ?')->execute([!empty($in['on']) && $f['version'] > 0 ? 1 : 0, $f['id']]);
    if ($op === 'template') $db->prepare('UPDATE g7_wk_forms SET is_template = ?, active = 0 WHERE id = ?')->execute([!empty($in['on']) ? 1 : 0, $f['id']]);
    if ($op === 'restore') {   // vuelve el borrador a una versión publicada
        $v = pa_version($db, $f['id'], (int)($in['version'] ?? 0)); if (!$v) pa_fail('Esa versión no existe');
        $db->prepare('UPDATE g7_wk_forms SET draft = ?, theme = ?, updated_at = ? WHERE id = ?')->execute([json_encode($v['schema'], JSON_UNESCAPED_UNICODE), json_encode($v['theme']), date('Y-m-d H:i:s'), $f['id']]);
    }
    pa_out(['ok' => true]);

// ── Etapas y automatizaciones ────────────────────────────────
case 'stages_save':
    if ($ctx['role'] !== 'admin') pa_fail('Solo administración cambia las etapas', 403);
    $cur = pt_stages($db); $list = []; $ids = [];
    foreach (array_slice((array)($in['stages'] ?? []), 0, 30) as $s) {
        $id = pt_id($s['id'] ?? ''); if ($id === '' || isset($ids[$id])) continue;
        $st = in_array($s['status'] ?? '', ['abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'], true) ? $s['status'] : 'abierto';
        foreach (PT_STAGES_DEFAULT as $d) if ($d['id'] === $id) $st = $d['status'];   // las de fábrica no cambian de estado principal
        $ids[$id] = 1;
        $list[] = ['id' => $id, 'name' => pt_s($s['name'] ?? '', 40) ?: $id, 'status' => $st, 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($s['color'] ?? '')) ? strtolower($s['color']) : '#8b8b94',
                   'icon' => preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($s['icon'] ?? '')) ? $s['icon'] : 'fa-circle'];
    }
    $trans = [];
    foreach ((array)($in['trans'] ?? []) as $from => $tos) if (isset($ids[$from])) $trans[$from] = array_values(array_filter((array)$tos, fn($x) => is_string($x) && isset($ids[$x]) && $x !== $from));
    wk_cfg_put($db, 'work_pt_stages', json_encode(['stages' => $list, 'trans' => $trans], JSON_UNESCAPED_UNICODE));
    pa_out(['ok' => true] + pt_stages($db));

case 'auto_save':
    $team = pa_int($in['team_id'] ?? 0);
    if ($team) pa_need_config($db, $ctx, $team); elseif ($ctx['role'] !== 'admin') pa_fail('Solo administración crea reglas para todos los departamentos', 403);
    $a = (array)($in['auto'] ?? []); $id = (int)($a['id'] ?? 0);
    if ($id) { $q = $db->prepare('SELECT team_id FROM g7_wk_pt_autos WHERE id = ?'); $q->execute([$id]); $ot = $q->fetchColumn(); if ($ot === false || (int)$ot !== (int)$team) pa_fail('Esa regla no existe', 404); }
    $events = ['created', 'approved', 'rejected', 'stage', 'date', 'comment', 'due_soon', 'late'];
    $ev = in_array($a['event'] ?? '', $events, true) ? $a['event'] : pa_fail('Elegí cuándo corre la regla');
    $acts = [];
    foreach (array_slice((array)($a['actions'] ?? []), 0, 10) as $x) {
        $k = (string)($x['a'] ?? '');
        if ($k === 'assign') $acts[] = ['a' => 'assign', 'v' => ($x['v'] ?? '') === 'auto' ? 'auto' : (int)($x['v'] ?? 0)];
        elseif ($k === 'stage' && pt_stage($db, (string)($x['v'] ?? ''))) $acts[] = ['a' => 'stage', 'v' => $x['v']];
        elseif ($k === 'priority') $acts[] = ['a' => 'priority', 'v' => pt_s($x['v'] ?? '', 40)];
        elseif ($k === 'notify') $acts[] = ['a' => 'notify', 'to' => in_array($x['to'] ?? '', ['leads', 'requester', 'assignee'], true) ? $x['to'] : (string)(int)($x['to'] ?? 0), 'msg' => pt_s($x['msg'] ?? '', 280)];
        elseif ($k === 'note') $acts[] = ['a' => 'note', 'v' => pt_s($x['v'] ?? '', 1000)];
    }
    if (!$acts) pa_fail('Agregá al menos una acción');
    $vals = [$team, pt_s($a['name'] ?? '', 120) ?: 'Regla', $ev, json_encode(pt_clean_cond($a['cond'] ?? null)), json_encode($acts, JSON_UNESCAPED_UNICODE), isset($a['active']) ? (int)!empty($a['active']) : 1];
    if ($id) $db->prepare('UPDATE g7_wk_pt_autos SET team_id = ?, name = ?, event = ?, cond = ?, actions = ?, active = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_wk_pt_autos (team_id, name, event, cond, actions, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, pt_now()]); $id = (int)$db->lastInsertId(); }
    pa_out(['id' => $id]);

case 'auto_del':
    $q = $db->prepare('SELECT team_id FROM g7_wk_pt_autos WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $ot = $q->fetchColumn();
    if ($ot === false) pa_fail('Esa regla no existe', 404);
    if ($ot) pa_need_config($db, $ctx, (int)$ot); elseif ($ctx['role'] !== 'admin') pa_fail('No podés borrar esta regla', 403);
    $db->prepare('DELETE FROM g7_wk_pt_autos WHERE id = ?')->execute([(int)$in['id']]);
    pa_out(['ok' => true]);

// ── Métricas ─────────────────────────────────────────────────
case 'metrics':
    require __DIR__ . '/portal_metrics.php';
    pa_out(pt_metrics($db, $ctx, $in));

case 'audit':   // historial de configuración y de cada solicitud (quien gestiona)
    $team = pa_int($in['team_id'] ?? 0); $tk = pa_int($in['ticket_id'] ?? 0);
    if ($tk) { $t = pa_ticket($db, $ctx, $tk); if (!$t['agent']) pa_fail('No ves este historial', 403); $team = null; }
    elseif ($team) { if (!pt_can_manage($db, $ctx, $team)) pa_fail('No ves este historial', 403); }
    elseif ($ctx['role'] !== 'admin') pa_fail('No ves este historial', 403);
    $q = $db->prepare('SELECT * FROM g7_wk_pt_audit WHERE ' . ($tk ? 'ticket_id = ?' : ($team ? 'team_id = ?' : '1 = ?')) . ' ORDER BY id DESC LIMIT 300');
    $q->execute([$tk ?: ($team ?: 1)]);
    pa_out(['rows' => array_map(fn($r) => ['at' => $r['created_at'], 'user_id' => $r['user_id'] ? (int)$r['user_id'] : null, 'action' => $r['action'], 'ticket_id' => $r['ticket_id'] ? (int)$r['ticket_id'] : null, 'detail' => json_decode((string)$r['detail'], true)], $q->fetchAll(PDO::FETCH_ASSOC))]);
}
pa_fail('Acción desconocida: ' . $act);

/** Asigna un ticket (persona o 'auto'), validando que la persona sea del departamento, esté y tenga capacidad. */
function pa_assign(PDO $db, array $ctx, array $t, $want, int $uid): array {
    $prev = $t['assignee_id'] ? (int)$t['assignee_id'] : null;
    if ($want === 'auto') {
        $p = pt_auto_pick($db, $t);
        if (!$p['uid']) pa_fail('No se pudo asignar en automático: ' . $p['why'], 409, ['ranking' => $p['ranking']]);
        $u = $p['uid']; $why = $p['why'];
    } elseif ($want === null || $want === '' || (int)$want === 0) {
        $u = null; $why = '';
    } else {
        $u = (int)$want;
        $m = null; foreach (pt_members($db, (int)$t['team_id']) as $x) if ($x['id'] === $u) $m = $x;
        if (!$m) pa_fail('Solo se asigna a gente del departamento');
        // nunca a alguien ausente o sin capacidad hasta la entrega
        $p = pt_auto_pick($db, $t);
        foreach ($p['ranking'] as $r) if ($r['uid'] === $u && !empty($r['skip'])) pa_fail('No se puede asignar a ' . $m['name'] . ': ' . mb_strtolower($r['skip']), 409);
        if (!$m['works']) pa_fail($m['name'] . ' no toma trabajo en este departamento');
        $why = '';
    }
    if ($u === $prev) return ['uid' => $u, 'why' => $why];
    wk_tk_set($db, (int)$t['id'], ['assignee_id' => $u]);
    wk_task_from_tk($db, (int)$t['id'], $uid, $prev);
    wk_tk_event($db, (int)$t['id'], $uid, $u ? 'lo asignó a ' . wk_user_name($db, $u) . ($why ? ' (automático: ' . $why . ')' : '') : 'le sacó el responsable', false);
    $t2 = wk_tk_row($db, (int)$t['id']);
    if ($u) { wk_tk_notify($db, 'assigned', [$u], $t2, $uid, 'Te asignaron la solicitud.'); pt_notify($db, 'pt_assignee', $t2, $uid, wk_user_name($db, $u)); }
    pt_audit($db, (int)$t['team_id'], (int)$t['id'], $uid, 'assign', ['from' => $prev, 'to' => $u, 'auto' => $want === 'auto']);
    pt_auto_fire($db, 'assigned', (int)$t['id'], $uid);
    return ['uid' => $u, 'why' => $why, 'overload' => (int)$t2['overload']];
}
