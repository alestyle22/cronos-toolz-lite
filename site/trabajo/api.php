<?php
/**
 * trabajo/api.php — API de Gestión de trabajo (trabajo.js). POST JSON {action, ...}.
 * Resumen con datos reales según el rol, Mis tareas de todos los tableros, tableros y roles.
 */
require_once __DIR__ . '/../auth.php';
$me = getCurrentUser();
header('Content-Type: application/json; charset=utf-8');
if (!$me) { http_response_code(401); echo json_encode(['error' => 'Sesión vencida: vuelve a ingresar']); exit; }
$db = getDB();
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/base_sync.php';
require_once __DIR__ . '/entrega_lib.php';   // entregas desde Promos y calificaciones de los pedidos (ENTREGAS_CALIFICACION.md)
wk_install($db);
session_write_close();

$uid = (int)$me['id'];
$ctx = wk_ctx($db, $uid);

function wk_out(array $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function wk_fail(string $m, int $c = 400): void { http_response_code($c); wk_out(['error' => $m]); }
function wk_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function wk_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) wk_fail('Pedido inválido', 405);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
$cfg = tk_config($db);
$today = date('Y-m-d');

/** Campos de un formulario de solicitud, limpios. */
function wk_clean_form_fields(array $list): array {
    $out = []; $seen = [];
    foreach (array_slice($list, 0, 40) as $f) {
        $label = wk_str($f['label'] ?? '', 120); if ($label === '') continue;
        $type = isset(WK_FORM_TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
        $id = preg_replace('/[^a-z0-9_]/', '', (string)($f['id'] ?? '')) ?: 'f_' . substr(md5($label . microtime()), 0, 6);
        while (isset($seen[$id])) $id .= 'x';
        $seen[$id] = true;
        $opts = $type === 'select' ? array_values(array_unique(array_filter(array_map(fn($o) => wk_str($o, 60), (array)($f['options'] ?? [])), 'strlen'))) : [];
        if ($type === 'select' && !$opts) continue;
        $out[] = ['id' => $id, 'label' => $label, 'type' => $type, 'required' => $type !== 'section' && !empty($f['required']), 'help' => wk_str($f['help'] ?? '', 200), 'options' => $opts];
    }
    return $out;
}
function wk_form_row(array $r): array {
    return ['id' => (int)$r['id'], 'team_id' => $r['team_id'] ? (int)$r['team_id'] : null, 'board_id' => $r['board_id'] ? (int)$r['board_id'] : null, 'category_id' => !empty($r['category_id']) ? (int)$r['category_id'] : null,
            'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'active' => (int)$r['active'], 'fields' => json_decode($r['fields'] ?: '[]', true) ?: []] + $r;
}
function wk_can_manage_form(array $ctx, ?int $teamId): bool { return $ctx['all'] || ($teamId && in_array($teamId, $ctx['lead'], true)); }
/** Solicitud que alguien puede ver: quien la pidió o quien atiende su departamento. */
function wk_request(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT r.*, f.name AS form_name, f.fields AS form_fields, f.board_id AS form_board, f.icon AS form_icon, f.color AS form_color FROM g7_wk_requests r LEFT JOIN g7_wk_forms f ON f.id = r.form_id WHERE r.id = ?');
    $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) wk_fail('Esa solicitud no existe', 404);
    $r['agent'] = wk_is_agent($ctx, $r['team_id'] ? (int)$r['team_id'] : null);
    if (!$r['agent'] && (int)$r['requester_id'] !== $ctx['id']) wk_fail('Esa solicitud no existe', 404);
    foreach (['id', 'form_id', 'team_id', 'requester_id', 'assignee_id', 'task_id', 'project_id', 'decided_by', 'form_board'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    $r['answers'] = json_decode($r['answers'] ?: '{}', true) ?: [];
    $r['form_fields'] = json_decode($r['form_fields'] ?: '[]', true) ?: [];
    return $r;
}
function wk_req_log(PDO $db, int $id, ?int $uid, string $body, bool $internal = false, string $kind = 'event'): void {
    $db->prepare('INSERT INTO g7_wk_req_msgs (request_id, user_id, body, internal, kind, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $uid, $body, $internal ? 1 : 0, $kind, tk_now()]);
    $db->prepare('UPDATE g7_wk_requests SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
}
/** Ticket que alguien puede ver: quien lo abrió o quien atiende su departamento. */
function wk_ticket(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT t.*, c.name AS cat_name, c.color AS cat_color FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id WHERE t.id = ?');
    $q->execute([$id]); $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t) wk_fail('Ese ticket no existe', 404);
    $t['agent'] = wk_is_agent($ctx, $t['team_id'] ? (int)$t['team_id'] : null) || ($t['assignee_id'] && (int)$t['assignee_id'] === $ctx['id']);
    // Ticket = tarea: quien es responsable de su tarea también lo atiende
    if (!$t['agent'] && $t['task_id']) { $q = $db->prepare('SELECT 1 FROM g7_task_assignees WHERE task_id = ? AND user_id = ?'); $q->execute([(int)$t['task_id'], $ctx['id']]); $t['agent'] = (bool)$q->fetchColumn(); }
    // Colaboradores (tk_collab_lib.php): lo ven y responden, pero no ven las notas internas
    $t['collab'] = !$t['agent'] && (int)$t['requester_id'] !== $ctx['id'] && wk_tkc_is($db, (int)$t['id'], $ctx['id']);
    if (!$t['agent'] && !$t['collab'] && (int)$t['requester_id'] !== $ctx['id']) wk_fail('Ese ticket no existe', 404);
    foreach (['id', 'category_id', 'team_id', 'requester_id', 'assignee_id', 'escalated', 'breach_flags', 'task_id', 'form_id', 'paused_min'] as $k) $t[$k] = $t[$k] === null ? null : (int)$t[$k];
    return $t;
}
function wk_tk_log(PDO $db, int $id, ?int $uid, string $body, bool $internal = true, string $kind = 'event'): int {
    $db->prepare('INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $uid, $body, $internal ? 1 : 0, $kind, tk_now()]);
    $mid = (int)$db->lastInsertId();
    $db->prepare('UPDATE g7_wk_tickets SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
    return $mid;
}
/** Nombre que ve la agencia cuando respondemos desde Trabajo. */
function wk_me_name(array $me): string { global $db; return wk_user_name($db, (int)$me['id']); }

/** Tableros donde alguien puede armar automatizaciones (los que administra). */
function wk_auto_boards(PDO $db, array $ctx): array {
    $out = [];
    foreach ($db->query('SELECT b.id FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.name')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $b = wk_board($db, (int)$id);
        if (wk_can_manage_board($db, $ctx, $b)) $out[] = $b;
    }
    return $out;
}
function wk_can_manage_auto(PDO $db, array $ctx, ?int $boardId): bool {
    if (!$boardId) return $ctx['role'] === 'admin';
    $b = wk_board($db, $boardId);
    return $b && wk_board_visible($db, $ctx, $boardId) && wk_can_manage_board($db, $ctx, $b);
}
function wk_can_manage_process(array $ctx, ?int $teamId): bool { return $ctx['all'] || ($teamId && in_array($teamId, $ctx['lead'], true)); }
/** Ejecución que alguien puede ver: quien la inició, quien tiene un paso, quien atiende el departamento del proceso. */
function wk_run(PDO $db, array $ctx, int $id): array {
    $q = $db->prepare('SELECT r.*, p.name AS process_name, p.team_id, p.color FROM g7_wk_runs r JOIN g7_wk_processes p ON p.id = r.process_id WHERE r.id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) wk_fail('Esa ejecución no existe', 404);
    $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_run_steps WHERE run_id = ? AND assignee_id = ?'); $q->execute([$id, $ctx['id']]);
    $mine = (int)$q->fetchColumn();
    $r['manage'] = wk_can_manage_process($ctx, $r['team_id'] ? (int)$r['team_id'] : null) || (int)$r['started_by'] === $ctx['id'];
    if (!$r['manage'] && !$mine && !wk_is_agent($ctx, $r['team_id'] ? (int)$r['team_id'] : null)) wk_fail('Esa ejecución no existe', 404);
    $r['id'] = (int)$r['id']; $r['started_by'] = (int)$r['started_by']; $r['team_id'] = $r['team_id'] ? (int)$r['team_id'] : null;
    $r['steps'] = json_decode($r['steps'], true) ?: [];
    return $r;
}

/** Tableros visibles con sus contadores. */
function wk_boards_list(PDO $db, array $ctx, bool $archived = false): array {
    $g = wk_general_id($db);
    $today = date('Y-m-d');
    $rows = $db->query('SELECT b.*, t.name AS team_name, t.color AS team_color,
            (SELECT COUNT(*) FROM g7_wk_board_members m WHERE m.board_id = b.id) AS n_members,
            (SELECT role FROM g7_wk_board_members m WHERE m.board_id = b.id AND m.user_id = ' . $ctx['id'] . ') AS my_role
        FROM g7_wk_boards b LEFT JOIN g7_teams t ON t.id = b.team_id
        WHERE b.archived = ' . ($archived ? 1 : 0) . ' AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.position, b.name')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    // Contadores de una sola pasada
    $cnt = [];
    $q = $db->query("SELECT COALESCE(board_id, $g) AS b, SUM(completed_at IS NULL) AS open,
            SUM(completed_at IS NULL AND due_date IS NOT NULL AND due_date < '$today') AS late,
            SUM(completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS done30, MAX(updated_at) AS last
        FROM g7_tasks WHERE archived = 0 GROUP BY COALESCE(board_id, $g)");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cnt[(int)$r['b']] = $r;
    $out = [];
    foreach ($rows as $b) {
        $id = (int)$b['id']; $c = $cnt[$id] ?? [];
        $board = wk_board($db, $id);
        $out[] = ['id' => $id, 'name' => $b['name'], 'description' => $b['description'], 'color' => $b['color'], 'icon' => $b['icon'],
            'team_id' => $b['team_id'] ? (int)$b['team_id'] : null, 'team_name' => $b['team_name'], 'team_color' => $b['team_color'],
            'visibility' => $b['visibility'], 'is_default' => (int)$b['is_default'], 'archived' => (int)$b['archived'],
            'n_members' => (int)$b['n_members'], 'my_role' => (string)$b['my_role'],
            'open' => (int)($c['open'] ?? 0), 'late' => (int)($c['late'] ?? 0), 'done30' => (int)($c['done30'] ?? 0), 'last' => $c['last'] ?? null,
            'can_manage' => wk_can_manage_board($db, $ctx, $board)];
    }
    return $out;
}

function wk_users(PDO $db): array {
    $rows = $db->query("SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS name, avatar, role FROM g7_users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$u) $u['id'] = (int)$u['id'];
    return $rows;
}

switch ($act) {

case 'boot':
    tk_run_reminders($db);
    wk_sla_sweep($db);
    if (wb_enabled()) wb_sync($db);   // tickets del sitio base (solo en el Toolz conectado; como mucho cada minuto)
    $teams = $db->query('SELECT id, name, color FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teams as &$t) $t['id'] = (int)$t['id']; unset($t);
    wk_out(['me' => ['id' => $uid, 'role' => $ctx['role'], 'label' => $ctx['label'], 'teams' => $ctx['teams'], 'lead' => $ctx['lead']],
        'admin' => $ctx['role'] === 'admin', 'team_view' => $ctx['all'] || (bool)$ctx['lead'],
        'can_boards' => $ctx['role'] !== 'solicitante', 'general' => wk_general_id($db),
        'teams' => $teams, 'users' => wk_users($db), 'config' => ['statuses' => $cfg['statuses'], 'priorities' => $cfg['priorities']],
        'roles' => WK_ROLES, 'vis' => WK_VIS, 'today' => $today, 'field_types' => WK_FIELD_TYPES,
        'board_statuses' => (object)wk_statuses_by_board($db, $ctx, $cfg), 'project_status' => wk_st('projects'),
        'cli_label' => wk_cli_label($db), 'req_status' => wk_st('requests'), 'tk_status' => wk_st('tickets'), 'agent' => $ctx['all'] || (bool)$ctx['teams'],
        'my_req_waiting' => (int)$db->query("SELECT COUNT(*) FROM g7_wk_tickets WHERE requester_id = $uid AND status = 'esperando'")->fetchColumn()]);

// ── Resumen ──────────────────────────────────────────────────
case 'summary':
    $wk0 = date('Y-m-d', strtotime('monday this week'));
    $in7 = date('Y-m-d', strtotime('+7 days'));
    // Lo mío
    $q = $db->prepare("SELECT
            SUM(t.completed_at IS NULL) AS open,
            SUM(t.completed_at IS NULL AND t.due_date < ?) AS late,
            SUM(t.completed_at IS NULL AND t.due_date = ?) AS today,
            SUM(t.completed_at IS NULL AND t.due_date > ? AND t.due_date <= ?) AS week,
            SUM(t.completed_at IS NULL AND t.due_date IS NULL) AS nodate,
            SUM(t.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS done7
        FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id AND a.user_id = ? WHERE t.archived = 0");
    $q->execute([$today, $today, $today, $in7, $uid]);
    $mine = array_map('intval', $q->fetch(PDO::FETCH_ASSOC) ?: []);
    $q = $db->prepare('SELECT COALESCE(SUM(minutes), 0) FROM g7_task_time WHERE user_id = ? AND ended_at IS NOT NULL AND started_at >= ?'); $q->execute([$uid, $wk0 . ' 00:00:00']);
    $mine['minutes_week'] = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT COUNT(*) FROM g7_tasks t WHERE t.archived = 0 AND t.completed_at IS NULL AND t.created_by = ? AND NOT EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)');
    $q->execute([$uid, $uid]); $mine['delegated'] = (int)$q->fetchColumn();
    // Próximas: lo abierto mío, primero lo vencido y lo que vence antes
    $g = wk_general_id($db);
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, COALESCE(t.board_id, $g) AS board_id, b.name AS board_name, b.color AS board_color
        FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id AND a.user_id = ? LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g)
        WHERE t.archived = 0 AND t.completed_at IS NULL ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT 8");
    $q->execute([$uid]);
    $next = array_map(fn($r) => ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id']] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    // Lo que hicieron otros en mis tareas
    $q = $db->prepare("SELECT ac.task_id, ac.user_id, ac.action, ac.detail, ac.created_at, t.title, COALESCE(t.board_id, $g) AS board_id FROM g7_task_activity ac JOIN g7_tasks t ON t.id = ac.task_id
        WHERE (ac.user_id IS NULL OR ac.user_id <> ?) AND (t.created_by = ? OR EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND a.user_id = ?))
        ORDER BY ac.id DESC LIMIT 12");
    $q->execute([$uid, $uid, $uid]);
    $acts = array_map(fn($r) => ['task_id' => (int)$r['task_id'], 'board_id' => (int)$r['board_id'], 'user_id' => $r['user_id'] ? (int)$r['user_id'] : null] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    $out = ['mine' => $mine, 'next' => $next, 'activity' => $acts];

    // Equipo: jefes (sus departamentos), directores y admins (todos)
    $people = wk_people_scope($db, $ctx);
    if ($people === null || $people) {
        $pw = $people === null ? '1' : 'a.user_id IN (' . wk_ints($people) . ')';
        $hot = $db->quote(end($cfg['priorities'])['id']);   // la prioridad más alta es la última de la lista
        $rows = $db->query("SELECT a.user_id,
                SUM(t.completed_at IS NULL) AS open,
                SUM(t.completed_at IS NULL AND t.due_date < '$today') AS late,
                SUM(t.completed_at IS NULL AND t.priority = $hot) AS hot,
                SUM(t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS done30
            FROM g7_task_assignees a JOIN g7_tasks t ON t.id = a.task_id AND t.archived = 0
            JOIN g7_users u ON u.id = a.user_id AND u.active = 1
            WHERE $pw GROUP BY a.user_id")->fetchAll(PDO::FETCH_ASSOC);
        $mins = [];
        foreach ($db->query("SELECT user_id, SUM(minutes) AS m FROM g7_task_time WHERE ended_at IS NOT NULL AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY user_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $mins[(int)$r['user_id']] = (int)$r['m'];
        $load = [];
        foreach ($rows as $r) { $u = (int)$r['user_id']; $load[] = ['user_id' => $u, 'open' => (int)$r['open'], 'late' => (int)$r['late'], 'hot' => (int)$r['hot'], 'done30' => (int)$r['done30'], 'minutes30' => $mins[$u] ?? 0]; }
        usort($load, fn($a, $b) => [$b['late'], $b['open']] <=> [$a['late'], $a['open']]);
        // Tareas del alcance: todas (admin/director) o, para un jefe, las de los tableros de sus departamentos y las asignadas a su gente
        $scope = $people === null ? '1' : '(COALESCE(t.board_id, ' . $g . ') IN (SELECT id FROM g7_wk_boards WHERE team_id IN (' . wk_ints($ctx['lead']) . '))'
            . ' OR EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND a.user_id IN (' . wk_ints($people) . ')))';
        $byStatus = $db->query("SELECT t.status, COUNT(*) AS n FROM g7_tasks t WHERE t.archived = 0 AND t.completed_at IS NULL AND $scope GROUP BY t.status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $byPrio = $db->query("SELECT t.priority, COUNT(*) AS n FROM g7_tasks t WHERE t.archived = 0 AND t.completed_at IS NULL AND $scope GROUP BY t.priority")->fetchAll(PDO::FETCH_KEY_PAIR);
        $tot = $db->query("SELECT SUM(t.completed_at IS NULL) AS open, SUM(t.completed_at IS NULL AND t.due_date < '$today') AS late,
                SUM(t.completed_at IS NULL AND NOT EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id)) AS unassigned,
                SUM(t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS done30,
                SUM(t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new30,
                AVG(CASE WHEN t.completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) END) AS lead_h,
                SUM(t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND t.due_date IS NOT NULL AND DATE(t.completed_at) <= t.due_date) AS ontime30,
                SUM(t.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND t.due_date IS NOT NULL) AS withdue30
            FROM g7_tasks t WHERE t.archived = 0 AND $scope")->fetch(PDO::FETCH_ASSOC);
        // Creadas y terminadas por semana (8 semanas, lunes a domingo)
        $weeks = [];
        for ($i = 7; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("monday this week -$i week")); $weeks[$d] = ['week' => $d, 'created' => 0, 'done' => 0]; }
        $from = array_key_first($weeks) . ' 00:00:00';
        $wkOf = fn($dt) => date('Y-m-d', strtotime('monday this week', strtotime(substr($dt, 0, 10))));
        foreach ($db->query("SELECT t.created_at, t.completed_at FROM g7_tasks t WHERE $scope AND (t.created_at >= '$from' OR t.completed_at >= '$from')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['created_at'] >= $from && isset($weeks[$k = $wkOf($r['created_at'])])) $weeks[$k]['created']++;
            if ($r['completed_at'] && $r['completed_at'] >= $from && isset($weeks[$k = $wkOf($r['completed_at'])])) $weeks[$k]['done']++;
        }
        // Creadas y terminadas por día (14 días, fechas de PHP como el resto de los plazos): gráficos del Resumen
        $days = [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = ['day' => $d, 'created' => 0, 'done' => 0]; }
        $fromD = array_key_first($days) . ' 00:00:00';
        foreach ($db->query("SELECT t.created_at, t.completed_at FROM g7_tasks t WHERE $scope AND (t.created_at >= '$fromD' OR t.completed_at >= '$fromD')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['created_at'] >= $fromD && isset($days[$k = substr($r['created_at'], 0, 10)])) $days[$k]['created']++;
            if ($r['completed_at'] && $r['completed_at'] >= $fromD && isset($days[$k = substr($r['completed_at'], 0, 10)])) $days[$k]['done']++;
        }
        // 12 meses, y los últimos 30 días contra los 30 anteriores (variación de los KPIs del Resumen)
        $months = [];
        for ($i = 11; $i >= 0; $i--) { $mk = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $months[$mk] = ['month' => $mk, 'created' => 0, 'done' => 0]; }
        $fromM = array_key_first($months) . '-01 00:00:00';
        foreach ($db->query("SELECT t.created_at, t.completed_at FROM g7_tasks t WHERE $scope AND (t.created_at >= '$fromM' OR t.completed_at >= '$fromM')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['created_at'] >= $fromM && isset($months[$k = substr($r['created_at'], 0, 7)])) $months[$k]['created']++;
            if ($r['completed_at'] && $r['completed_at'] >= $fromM && isset($months[$k = substr($r['completed_at'], 0, 7)])) $months[$k]['done']++;
        }
        $d30 = date('Y-m-d H:i:s', time() - 30 * 86400); $d60 = date('Y-m-d H:i:s', time() - 60 * 86400);
        $cmp = $db->query("SELECT SUM(t.created_at >= '$d30') AS new30, SUM(t.created_at >= '$d60' AND t.created_at < '$d30') AS new_prev,
                SUM(t.completed_at >= '$d30') AS done30, SUM(t.completed_at >= '$d60' AND t.completed_at < '$d30') AS done_prev,
                AVG(CASE WHEN t.completed_at >= '$d30' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) END) AS lead30,
                AVG(CASE WHEN t.completed_at >= '$d60' AND t.completed_at < '$d30' THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) END) AS lead_prev,
                SUM(t.completed_at IS NULL AND t.due_date IS NULL) AS nodate
            FROM g7_tasks t WHERE t.archived = 0 AND $scope")->fetch(PDO::FETCH_ASSOC);
        $out['team'] = [
            'days' => array_values($days), 'months' => array_values($months),
            'cmp' => array_map(fn($v) => $v === null ? null : (int)round((float)$v), $cmp),
            'scope' => $people === null ? 'all' : 'lead',
            'totals' => ['open' => (int)$tot['open'], 'late' => (int)$tot['late'], 'unassigned' => (int)$tot['unassigned'], 'done30' => (int)$tot['done30'], 'new30' => (int)$tot['new30'],
                         'lead_h' => $tot['lead_h'] === null ? null : (int)round($tot['lead_h']), 'ontime30' => (int)$tot['ontime30'], 'withdue30' => (int)$tot['withdue30']],
            'load' => $load, 'by_status' => array_map('intval', $byStatus), 'by_prio' => array_map('intval', $byPrio), 'weeks' => array_values($weeks),
        ];
    }
    $out['boards'] = array_slice(wk_boards_list($db, $ctx), 0, 8);
    wk_out($out);

// ── Mis tareas (de todos los tableros) ───────────────────────
case 'mine':
    $g = wk_general_id($db);
    $who = (int)($in['user'] ?? 0);
    // Ver las tareas de otra persona: admin/director a cualquiera, jefe a la gente de sus departamentos
    if ($who && $who !== $uid) {
        $people = wk_people_scope($db, $ctx);
        if ($people !== null && !in_array($who, $people, true)) wk_fail('No puedes ver las tareas de esa persona', 403);
    } else $who = $uid;
    $extra = $who === $uid ? '' : ' AND ' . wk_task_where($db, $ctx);
    // Clientes es un módulo aparte: si su tabla no existe todavía, las tareas salen igual sin el nombre del cliente
    $cliT = wk_cli_table($db); $hasCli = (bool)$cliT;
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.client_id, t.completed_at, t.updated_at, COALESCE(t.board_id, $g) AS board_id,
            b.name AS board_name, b.color AS board_color, b.statuses AS board_st, " . ($hasCli ? 'c.name' : 'NULL') . " AS client_name,
            (SELECT p.title FROM g7_tasks p WHERE p.id = t.parent_id) AS parent_title,
            (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id) AS n_checks,
            (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id AND k.done = 1) AS n_checks_done,
            (SELECT MIN(x.id) FROM g7_wk_tickets x WHERE x.task_id = t.id) AS ticket_id
        FROM g7_tasks t JOIN g7_task_assignees a ON a.task_id = t.id AND a.user_id = ?
        LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g)" . ($hasCli ? ' LEFT JOIN ' . $cliT . ' c ON c.id = t.client_id' : '') . "
        WHERE t.archived = 0 AND (t.completed_at IS NULL OR t.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))$extra
        ORDER BY t.completed_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.id");
    $q->execute([$who]);
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Para tildar: el primer estado "terminado" y el primero abierto del tablero de cada tarea
        $st = $r['board_st'] ? (json_decode($r['board_st'], true) ?: $cfg['statuses']) : $cfg['statuses'];
        $r['done_status'] = array_values(array_filter($st, fn($s) => !empty($s['done'])))[0]['id'] ?? null;
        $r['open_status'] = array_values(array_filter($st, fn($s) => empty($s['done'])))[0]['id'] ?? null;
        unset($r['board_st']);
        $rows[] = ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id'], 'client_id' => $r['client_id'] ? (int)$r['client_id'] : null,
            'n_checks' => (int)$r['n_checks'], 'n_checks_done' => (int)$r['n_checks_done'], 'ticket_id' => $r['ticket_id'] ? (int)$r['ticket_id'] : null] + $r;   // tarea de un ticket: Mis tareas abre el ticket
    }
    wk_out(['tasks' => $rows, 'user' => $who]);

// ── Tableros ─────────────────────────────────────────────────
case 'boards':
    wk_out(['boards' => wk_boards_list($db, $ctx, !empty($in['archived']))]);

case 'board_get':
    $b = wk_board($db, (int)($in['id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe o no tienes acceso', 404);
    $q = $db->prepare('SELECT user_id, role FROM g7_wk_board_members WHERE board_id = ? ORDER BY role, added_at'); $q->execute([$b['id']]);
    $b['members'] = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'role' => $r['role']], $q->fetchAll(PDO::FETCH_ASSOC));
    $b['can_manage'] = wk_can_manage_board($db, $ctx, $b);
    $b['fields'] = wk_fields($db, $b['id']);
    wk_out(['board' => $b, 'global_statuses' => $cfg['statuses']]);

case 'board_save':
    $id = (int)($in['id'] ?? 0);
    $name = wk_str($in['name'] ?? '', 80); if ($name === '') wk_fail('Pon un nombre para el tablero');
    $team = (int)($in['team_id'] ?? 0);
    if ($team) { $q = $db->prepare('SELECT id FROM g7_teams WHERE id = ? AND active = 1'); $q->execute([$team]); if (!$q->fetch()) wk_fail('Ese departamento no existe'); }
    $vis = isset(WK_VIS[$in['visibility'] ?? '']) ? $in['visibility'] : 'public';
    if ($vis === 'team' && !$team) wk_fail('Para que lo vea solo un departamento, elige cuál');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower($in['color']) : '#ff6600';
    $icon = preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($in['icon'] ?? '')) ? $in['icon'] : 'fa-table-columns';
    $desc = wk_str($in['description'] ?? '', 500);
    $now = tk_now();
    if ($id) {
        $b = wk_board($db, $id);
        if (!$b || !wk_board_visible($db, $ctx, $id)) wk_fail('Ese tablero no existe', 404);
        if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes cambiar este tablero', 403);
        if ($team !== (int)$b['team_id'] && !wk_can_create_board($ctx, $team)) wk_fail('No puedes pasar el tablero a ese departamento', 403);
        if ($b['is_default'] && $vis !== 'public') wk_fail('El tablero General lo ven todos');
        $db->prepare('UPDATE g7_wk_boards SET name = ?, description = ?, color = ?, icon = ?, team_id = ?, visibility = ?, updated_at = ? WHERE id = ?')
           ->execute([$name, $desc, $color, $icon, $team ?: null, $vis, $now, $id]);
        g7_audit('work_board_edit', 'tablero #' . $id, ['name' => $name, 'team' => $team, 'visibility' => $vis], $uid);
    } else {
        if (!wk_can_create_board($ctx, $team)) wk_fail('No puedes crear tableros en ese departamento', 403);
        $pos = (int)$db->query('SELECT COALESCE(MAX(position), 0) + 1 FROM g7_wk_boards')->fetchColumn();
        $db->prepare('INSERT INTO g7_wk_boards (name, description, color, icon, team_id, visibility, position, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$name, $desc, $color, $icon, $team ?: null, $vis, $pos, $uid, $now, $now]);
        $id = (int)$db->lastInsertId();
        // Quien lo crea queda como encargado (Responsable)
        $db->prepare("INSERT INTO g7_wk_board_members (board_id, user_id, role, added_at) VALUES (?, ?, 'manager', ?)")->execute([$id, $uid, $now]);
        g7_audit('work_board_create', 'tablero #' . $id, ['name' => $name, 'team' => $team, 'visibility' => $vis], $uid);
    }
    wk_out(['id' => $id, 'boards' => wk_boards_list($db, $ctx)]);

case 'board_archive':
    $b = wk_board($db, (int)($in['id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe', 404);
    if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes archivar este tablero', 403);
    if ($b['is_default']) wk_fail('El tablero General no se archiva');
    $on = !empty($in['on']) ? 1 : 0;
    $db->prepare('UPDATE g7_wk_boards SET archived = ?, updated_at = ? WHERE id = ?')->execute([$on, tk_now(), $b['id']]);
    g7_audit($on ? 'work_board_archive' : 'work_board_restore', 'tablero #' . $b['id'], ['name' => $b['name']], $uid);
    wk_out(['boards' => wk_boards_list($db, $ctx), 'archived' => wk_boards_list($db, $ctx, true)]);

case 'board_delete':
    // Solo vacío: un tablero con tareas se archiva (las tareas no se pierden nunca por borrar un tablero)
    $b = wk_board($db, (int)($in['id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe', 404);
    if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes borrar este tablero', 403);
    if ($b['is_default']) wk_fail('El tablero General no se borra');
    $q = $db->prepare('SELECT COUNT(*) FROM g7_tasks WHERE board_id = ?'); $q->execute([$b['id']]);
    if ((int)$q->fetchColumn()) wk_fail('El tablero tiene tareas: archivalo, o mueve sus tareas a otro tablero antes de borrarlo');
    $db->prepare('DELETE FROM g7_wk_board_members WHERE board_id = ?')->execute([$b['id']]);
    $db->prepare('DELETE FROM g7_wk_boards WHERE id = ?')->execute([$b['id']]);
    g7_audit('work_board_delete', 'tablero #' . $b['id'], ['name' => $b['name']], $uid);
    wk_out(['boards' => wk_boards_list($db, $ctx), 'archived' => wk_boards_list($db, $ctx, true)]);

case 'board_member':
    // op: add | remove | manager | member
    $b = wk_board($db, (int)($in['board_id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe', 404);
    if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes cambiar los miembros de este tablero', 403);
    $who = (int)($in['user_id'] ?? 0);
    $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$who]); if (!$q->fetch()) wk_fail('Esa persona no existe');
    $op = (string)($in['op'] ?? '');
    if ($op === 'remove') {
        $db->prepare('DELETE FROM g7_wk_board_members WHERE board_id = ? AND user_id = ?')->execute([$b['id'], $who]);
    } elseif (in_array($op, ['add', 'member', 'manager'], true)) {
        $role = $op === 'manager' ? 'manager' : 'member';
        $db->prepare('INSERT INTO g7_wk_board_members (board_id, user_id, role, added_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = ' . ($op === 'add' ? 'role' : 'VALUES(role)'))
           ->execute([$b['id'], $who, $role, tk_now()]);
    } else wk_fail('Acción no válida');
    g7_audit('work_board_member', 'tablero #' . $b['id'], ['user' => $who, 'op' => $op], $uid);
    $q = $db->prepare('SELECT user_id, role FROM g7_wk_board_members WHERE board_id = ? ORDER BY role, added_at'); $q->execute([$b['id']]);
    wk_out(['members' => array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'role' => $r['role']], $q->fetchAll(PDO::FETCH_ASSOC))]);

case 'board_order':
    if (!$ctx['all']) wk_fail('Solo administradores y directores ordenan los tableros', 403);
    $up = $db->prepare('UPDATE g7_wk_boards SET position = ? WHERE id = ?');
    foreach (array_values(array_map('intval', (array)($in['ids'] ?? []))) as $i => $bid) $up->execute([$i + 1, $bid]);
    wk_out(['boards' => wk_boards_list($db, $ctx)]);

// ── Roles (solo admins) ──────────────────────────────────────
case 'roles':
    if ($ctx['role'] !== 'admin') wk_fail('Solo los administradores ven los roles', 403);
    $teams = [];
    foreach ($db->query('SELECT m.user_id, m.role, t.name FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1 ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC) as $r)
        $teams[(int)$r['user_id']][] = ['name' => $r['name'], 'lead' => $r['role'] === 'admin'];
    $out = [];
    foreach (wk_users($db) as $u) {
        $c = wk_ctx($db, $u['id']);
        $out[] = ['id' => $u['id'], 'name' => $u['name'], 'avatar' => $u['avatar'], 'role' => $c['role'], 'teams' => $teams[$u['id']] ?? []];
    }
    wk_out(['people' => $out]);

case 'role_set':
    if ($ctx['role'] !== 'admin') wk_fail('Solo los administradores asignan roles', 403);
    $who = (int)($in['user_id'] ?? 0);
    $q = $db->prepare('SELECT role FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$who]); $gr = $q->fetchColumn();
    if ($gr === false) wk_fail('Esa persona no existe');
    if ($gr === 'admin') wk_fail('Es administrador: ese rol se cambia en Usuarios');
    $role = (string)($in['role'] ?? '');
    if ($role === 'director' || $role === 'solicitante') $db->prepare('INSERT INTO g7_wk_roles (user_id, role, set_by, set_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = VALUES(role), set_by = VALUES(set_by), set_at = VALUES(set_at)')->execute([$who, $role, $uid, tk_now()]);
    elseif ($role === '') $db->prepare('DELETE FROM g7_wk_roles WHERE user_id = ?')->execute([$who]);
    else wk_fail('Rol no válido');
    g7_audit('work_role', 'usuario #' . $who, ['role' => $role ?: 'ninguno'], $uid);
    wk_out(['ok' => true]);

// ── Calendario de todos los tableros ─────────────────────────
case 'calendar':
    $from = wk_date($in['from'] ?? '') ?: date('Y-m-01'); $to = wk_date($in['to'] ?? '') ?: date('Y-m-t');
    if ((strtotime($to) - strtotime($from)) > 120 * 86400) wk_fail('Pide como mucho cuatro meses');
    $g = wk_general_id($db);
    $w = ['t.archived = 0', wk_task_where($db, $ctx), '((t.due_date BETWEEN ? AND ?) OR (t.start_date BETWEEN ? AND ?) OR (t.start_date < ? AND t.due_date > ?))'];
    $args = [$from, $to, $from, $to, $from, $to];
    if (!empty($in['board'])) { $w[] = "COALESCE(t.board_id, $g) = ?"; $args[] = (int)$in['board']; }
    if (!empty($in['user'])) { $w[] = 'EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)'; $args[] = (int)$in['user']; }
    if (empty($in['done'])) $w[] = 't.completed_at IS NULL';
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.start_date, t.completed_at, COALESCE(t.board_id, $g) AS board_id,
            (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg
        FROM g7_tasks t WHERE " . implode(' AND ', $w) . ' ORDER BY t.due_date, t.id LIMIT 1500');
    $q->execute($args);
    wk_out(['from' => $from, 'to' => $to, 'tasks' => array_map(fn($r) => ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id'],
        'assignees' => $r['asg'] ? array_map('intval', explode(',', $r['asg'])) : []] + array_diff_key($r, ['asg' => 1]), $q->fetchAll(PDO::FETCH_ASSOC))]);

// ── Estados y campos de un tablero ───────────────────────────
case 'board_statuses':
    $b = wk_board($db, (int)($in['id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe', 404);
    if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes cambiar los estados de este tablero', 403);
    if ($b['is_default'] && !empty($in['own'])) wk_fail('El tablero General usa los estados generales (se cambian en Tareas › Configurar)');
    $list = null;
    if (!empty($in['own'])) {
        $list = wk_clean_statuses((array)($in['statuses'] ?? []));
        if (!$list) wk_fail('Tiene que quedar al menos un estado');
        if (!array_filter($list, fn($s) => $s['done'])) wk_fail('Marca al menos un estado como "terminado"');
    }
    wk_set_board_statuses($db, $b, $list, (array)($in['remap'] ?? []), $cfg);
    g7_audit('work_board_statuses', 'tablero #' . $b['id'], ['own' => (bool)$list, 'statuses' => $list ? array_column($list, 'name') : null], $uid);
    wk_out(['statuses' => $list, 'global' => $cfg['statuses']]);

case 'field_save':
case 'field_del':
case 'field_order':
    $b = wk_board($db, (int)($in['board_id'] ?? 0));
    if (!$b || !wk_board_visible($db, $ctx, $b['id'])) wk_fail('Ese tablero no existe', 404);
    if (!wk_can_manage_board($db, $ctx, $b)) wk_fail('No puedes cambiar los campos de este tablero', 403);
    $fid = (int)($in['id'] ?? 0);
    if ($fid) { $q = $db->prepare('SELECT id FROM g7_wk_fields WHERE id = ? AND board_id = ?'); $q->execute([$fid, $b['id']]); if (!$q->fetch()) wk_fail('Ese campo no existe', 404); }
    if ($act === 'field_del') {
        $db->prepare('DELETE FROM g7_wk_field_values WHERE field_id = ?')->execute([$fid]);
        $db->prepare('DELETE FROM g7_wk_fields WHERE id = ?')->execute([$fid]);
        g7_audit('work_field_delete', 'tablero #' . $b['id'], ['field' => $fid], $uid);
    } elseif ($act === 'field_order') {
        $up = $db->prepare('UPDATE g7_wk_fields SET position = ? WHERE id = ? AND board_id = ?');
        foreach (array_values(array_map('intval', (array)($in['ids'] ?? []))) as $i => $x) $up->execute([$i + 1, $x, $b['id']]);
    } else {
        $name = wk_str($in['name'] ?? '', 60); if ($name === '') wk_fail('Pon un nombre para el campo');
        $type = (string)($in['type'] ?? 'text'); if (!isset(WK_FIELD_TYPES[$type])) wk_fail('Tipo de campo no válido');
        $opts = [];
        if ($type === 'select') {
            $seen = [];
            foreach ((array)($in['options'] ?? []) as $o) {
                $n = wk_str(is_array($o) ? ($o['name'] ?? '') : $o, 40); if ($n === '') continue;
                $oid = preg_replace('/[^a-z0-9_]/', '', (string)(is_array($o) ? ($o['id'] ?? '') : '')) ?: tk_slug($n);
                $base = $oid; $k = 2; while (isset($seen[$oid])) $oid = $base . '_' . $k++;
                $seen[$oid] = true;
                $opts[] = ['id' => $oid, 'name' => $n, 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($o['color'] ?? '')) ? strtolower($o['color']) : '#8b8b94'];
            }
            if (!$opts) wk_fail('Agrega al menos una opción');
        }
        if ($fid) {
            $q = $db->prepare('SELECT type FROM g7_wk_fields WHERE id = ?'); $q->execute([$fid]);
            if ($q->fetchColumn() !== $type) $db->prepare('DELETE FROM g7_wk_field_values WHERE field_id = ?')->execute([$fid]);   // otro tipo: los valores viejos no sirven
            elseif ($type === 'select') $db->prepare('DELETE FROM g7_wk_field_values WHERE field_id = ? AND value NOT IN (' . implode(',', array_map([$db, 'quote'], array_column($opts, 'id'))) . ')')->execute([$fid]);
            $db->prepare('UPDATE g7_wk_fields SET name = ?, type = ?, options = ? WHERE id = ?')->execute([$name, $type, json_encode($opts, JSON_UNESCAPED_UNICODE), $fid]);
        } else {
            $pos = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM g7_wk_fields WHERE board_id = ?'); $pos->execute([$b['id']]);
            $db->prepare('INSERT INTO g7_wk_fields (board_id, name, type, options, position, created_at) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$b['id'], $name, $type, json_encode($opts, JSON_UNESCAPED_UNICODE), (int)$pos->fetchColumn(), tk_now()]);
        }
        g7_audit('work_field_save', 'tablero #' . $b['id'], ['name' => $name, 'type' => $type], $uid);
    }
    wk_out(['fields' => wk_fields($db, $b['id'])]);

// ── Proyectos (fase 3) ───────────────────────────────────────
case 'projects':
    $arch = !empty($in['archived']);
    $cliT = wk_cli_table($db); $hasCli = (bool)$cliT;
    $rows = $db->query('SELECT p.*, t.name AS team_name' . ($hasCli ? ', c.name AS client_name' : ', NULL AS client_name') . '
        FROM g7_wk_projects p LEFT JOIN g7_teams t ON t.id = p.team_id' . ($hasCli ? ' LEFT JOIN ' . $cliT . ' c ON c.id = p.client_id' : '') . '
        WHERE p.archived = ' . ($arch ? 1 : 0) . ' AND ' . wk_project_where($ctx) . " ORDER BY FIELD(p.status, 'en_curso', 'planificado', 'en_pausa', 'terminado', 'cancelado'), p.due_date IS NULL, p.due_date, p.name")->fetchAll(PDO::FETCH_ASSOC);
    $stats = wk_project_stats($db, array_map(fn($r) => (int)$r['id'], $rows));
    $out = [];
    foreach ($rows as $r) {
        $p = wk_project($db, (int)$r['id']); $s = $stats[$p['id']];
        $out[] = $p + ['team_name' => $r['team_name'], 'client_name' => $r['client_name'], 'stats' => $s, 'health' => wk_project_health($p, $s), 'can_manage' => wk_can_manage_project($db, $ctx, $p)];
    }
    $clients = [];
    if ($hasCli) { $clients = $db->query('SELECT id, name FROM ' . (wk_cli_table($db) ?: 'g7_clients') . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); foreach ($clients as &$c) $c['id'] = (int)$c['id']; unset($c); }
    wk_out(['projects' => $out, 'clients' => $clients, 'status' => wk_st('projects'), 'can_create' => $ctx['role'] !== 'solicitante']);

case 'project_get':
    $p = wk_project($db, (int)($in['id'] ?? 0));
    if (!$p || !wk_project_visible($db, $ctx, $p['id'])) wk_fail('Ese proyecto no existe o no tienes acceso', 404);
    $g = wk_general_id($db);
    $s = wk_project_stats($db, [$p['id']])[$p['id']];
    $p['stats'] = $s; $p['health'] = wk_project_health($p, $s); $p['can_manage'] = wk_can_manage_project($db, $ctx, $p);
    $q = $db->prepare('SELECT user_id, role FROM g7_wk_project_members WHERE project_id = ? ORDER BY role, added_at'); $q->execute([$p['id']]);
    $p['members'] = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'role' => $r['role']], $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare('SELECT * FROM g7_wk_milestones WHERE project_id = ? ORDER BY position, due_date IS NULL, due_date, id'); $q->execute([$p['id']]);
    $p['milestones'] = array_map(fn($m) => ['id' => (int)$m['id'], 'done_by' => $m['done_by'] ? (int)$m['done_by'] : null, 'position' => (int)$m['position']] + $m, $q->fetchAll(PDO::FETCH_ASSOC));
    // Tareas del proyecto que esta persona puede ver (las demás cuentan en el avance pero no se muestran)
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.start_date, t.completed_at, t.milestone_id, t.estimate_min, COALESCE(t.board_id, $g) AS board_id, b.name AS board_name, b.color AS board_color,
            (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg,
            (SELECT COALESCE(SUM(m.minutes), 0) FROM g7_task_time m WHERE m.task_id = t.id AND m.ended_at IS NOT NULL) AS minutes
        FROM g7_tasks t LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g)
        WHERE t.project_id = ? AND t.parent_id IS NULL AND t.archived = 0 AND " . wk_task_where($db, $ctx) . ' ORDER BY t.completed_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.id');
    $q->execute([$p['id']]);
    $p['tasks'] = array_map(fn($r) => ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id'], 'milestone_id' => $r['milestone_id'] ? (int)$r['milestone_id'] : null,
        'estimate_min' => $r['estimate_min'] === null ? null : (int)$r['estimate_min'], 'minutes' => (int)$r['minutes'],
        'assignees' => $r['asg'] ? array_map('intval', explode(',', $r['asg'])) : []] + array_diff_key($r, ['asg' => 1]), $q->fetchAll(PDO::FETCH_ASSOC));
    // Horas por persona
    $q = $db->prepare('SELECT m.user_id, SUM(m.minutes) AS m FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id WHERE m.ended_at IS NOT NULL AND t.project_id = ? GROUP BY m.user_id ORDER BY m DESC');
    $q->execute([$p['id']]);
    $p['hours'] = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'minutes' => (int)$r['m']], $q->fetchAll(PDO::FETCH_ASSOC));
    if ($p['client_id']) { try { $q = $db->prepare('SELECT name FROM ' . (wk_cli_table($db) ?: 'g7_clients') . ' WHERE id = ?'); $q->execute([$p['client_id']]); $p['client_name'] = $q->fetchColumn() ?: null; } catch (Throwable $e) { $p['client_name'] = null; } }
    // Tableros donde puede crear tareas para el proyecto
    $p['boards'] = $ctx['role'] === 'solicitante' ? [] : array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT b.id, b.name FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.position, b.name')->fetchAll(PDO::FETCH_ASSOC));
    $clients = [];
    try { $clients = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $db->query('SELECT id, name FROM ' . (wk_cli_table($db) ?: 'g7_clients') . ' ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)); } catch (Throwable $e) {}
    wk_out(['project' => $p, 'status' => wk_st('projects'), 'clients' => $clients]);

case 'project_save':
    $id = (int)($in['id'] ?? 0);
    $name = wk_str($in['name'] ?? '', 120); if ($name === '') wk_fail('Pon un nombre para el proyecto');
    $team = (int)($in['team_id'] ?? 0);
    if ($team) { $q = $db->prepare('SELECT id FROM g7_teams WHERE id = ? AND active = 1'); $q->execute([$team]); if (!$q->fetch()) wk_fail('Ese departamento no existe'); }
    $client = (int)($in['client_id'] ?? 0);
    if ($client) { try { $q = $db->prepare('SELECT id FROM ' . (wk_cli_table($db) ?: 'g7_clients') . ' WHERE id = ?'); $q->execute([$client]); if (!$q->fetch()) wk_fail('Ese ' . mb_strtolower(wk_cli_label($db)) . ' no existe'); } catch (PDOException $e) { $client = 0; } }
    $owner = (int)($in['owner_id'] ?? 0);
    if ($owner) { $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$owner]); if (!$q->fetch()) wk_fail('Ese responsable no existe'); }
    $vis = isset(WK_VIS[$in['visibility'] ?? '']) ? $in['visibility'] : 'public';
    if ($vis === 'team' && !$team) wk_fail('Para que lo vea solo un departamento, elige cuál');
    $status = isset(wk_st('projects')[$in['status'] ?? '']) ? $in['status'] : 'planificado';
    $start = wk_date($in['start_date'] ?? ''); $due = wk_date($in['due_date'] ?? '');
    if ($start && $due && $due < $start) wk_fail('La entrega no puede ser antes del inicio');
    $budget = (int)round((float)str_replace(',', '.', (string)($in['budget_h'] ?? 0)) * 60); $budget = $budget > 0 ? min($budget, 6000000) : null;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower($in['color']) : '#ff6600';
    $desc = wk_str($in['description'] ?? '', 5000);
    $now = tk_now();
    $vals = [$name, $desc, $client ?: null, $team ?: null, $owner ?: null, $status, $start, $due, $budget, $color, $vis];
    if ($id) {
        $p = wk_project($db, $id);
        if (!$p || !wk_project_visible($db, $ctx, $id)) wk_fail('Ese proyecto no existe', 404);
        if (!wk_can_manage_project($db, $ctx, $p)) wk_fail('No puedes cambiar este proyecto', 403);
        if ($team !== (int)$p['team_id'] && !wk_can_create_board($ctx, $team)) wk_fail('No puedes pasar el proyecto a ese departamento', 403);
        $closed = in_array($status, ['terminado', 'cancelado'], true) ? ($p['closed_at'] ?: $now) : null;
        $db->prepare('UPDATE g7_wk_projects SET name = ?, description = ?, client_id = ?, team_id = ?, owner_id = ?, status = ?, start_date = ?, due_date = ?, budget_min = ?, color = ?, visibility = ?, closed_at = ?, updated_at = ? WHERE id = ?')
           ->execute([...$vals, $closed, $now, $id]);
        g7_audit('work_project_edit', 'proyecto #' . $id, ['name' => $name, 'status' => $status], $uid);
    } else {
        if (!wk_can_create_board($ctx, $team)) wk_fail('No puedes crear proyectos en ese departamento', 403);
        $db->prepare('INSERT INTO g7_wk_projects (name, description, client_id, team_id, owner_id, status, start_date, due_date, budget_min, color, visibility, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([...$vals, $uid, $now, $now]);
        $id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO g7_wk_project_members (project_id, user_id, role, added_at) VALUES (?, ?, 'manager', ?)")->execute([$id, $uid, $now]);
        if ($owner && $owner !== $uid) $db->prepare("INSERT IGNORE INTO g7_wk_project_members (project_id, user_id, role, added_at) VALUES (?, ?, 'manager', ?)")->execute([$id, $owner, $now]);
        g7_audit('work_project_create', 'proyecto #' . $id, ['name' => $name], $uid);
    }
    wk_out(['id' => $id]);

case 'project_archive':
case 'project_delete':
    $p = wk_project($db, (int)($in['id'] ?? 0));
    if (!$p || !wk_project_visible($db, $ctx, $p['id'])) wk_fail('Ese proyecto no existe', 404);
    if (!wk_can_manage_project($db, $ctx, $p)) wk_fail('No puedes cambiar este proyecto', 403);
    if ($act === 'project_archive') {
        $on = !empty($in['on']) ? 1 : 0;
        $db->prepare('UPDATE g7_wk_projects SET archived = ?, updated_at = ? WHERE id = ?')->execute([$on, tk_now(), $p['id']]);
        g7_audit($on ? 'work_project_archive' : 'work_project_restore', 'proyecto #' . $p['id'], ['name' => $p['name']], $uid);
    } else {
        // Las tareas no se borran nunca con el proyecto: se desvinculan y siguen en sus tableros
        $db->prepare('UPDATE g7_tasks SET project_id = NULL, milestone_id = NULL WHERE project_id = ?')->execute([$p['id']]);
        $db->prepare('DELETE FROM g7_wk_milestones WHERE project_id = ?')->execute([$p['id']]);
        $db->prepare('DELETE FROM g7_wk_project_members WHERE project_id = ?')->execute([$p['id']]);
        $db->prepare('DELETE FROM g7_wk_projects WHERE id = ?')->execute([$p['id']]);
        g7_audit('work_project_delete', 'proyecto #' . $p['id'], ['name' => $p['name']], $uid);
    }
    wk_out(['ok' => true]);

case 'project_member':
    $p = wk_project($db, (int)($in['project_id'] ?? 0));
    if (!$p || !wk_project_visible($db, $ctx, $p['id'])) wk_fail('Ese proyecto no existe', 404);
    if (!wk_can_manage_project($db, $ctx, $p)) wk_fail('No puedes cambiar los miembros de este proyecto', 403);
    $who = (int)($in['user_id'] ?? 0);
    $q = $db->prepare('SELECT id FROM g7_users WHERE id = ? AND active = 1'); $q->execute([$who]); if (!$q->fetch()) wk_fail('Esa persona no existe');
    $op = (string)($in['op'] ?? '');
    if ($op === 'remove') $db->prepare('DELETE FROM g7_wk_project_members WHERE project_id = ? AND user_id = ?')->execute([$p['id'], $who]);
    elseif (in_array($op, ['add', 'member', 'manager'], true))
        $db->prepare('INSERT INTO g7_wk_project_members (project_id, user_id, role, added_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE role = ' . ($op === 'add' ? 'role' : 'VALUES(role)'))
           ->execute([$p['id'], $who, $op === 'manager' ? 'manager' : 'member', tk_now()]);
    else wk_fail('Acción no válida');
    g7_audit('work_project_member', 'proyecto #' . $p['id'], ['user' => $who, 'op' => $op], $uid);
    $q = $db->prepare('SELECT user_id, role FROM g7_wk_project_members WHERE project_id = ? ORDER BY role, added_at'); $q->execute([$p['id']]);
    wk_out(['members' => array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'role' => $r['role']], $q->fetchAll(PDO::FETCH_ASSOC))]);

case 'milestone_save':
case 'milestone_del':
case 'milestone_done':
case 'milestone_order':
    $p = wk_project($db, (int)($in['project_id'] ?? 0));
    if (!$p || !wk_project_visible($db, $ctx, $p['id'])) wk_fail('Ese proyecto no existe', 404);
    if (!wk_can_manage_project($db, $ctx, $p)) wk_fail('No puedes cambiar los hitos de este proyecto', 403);
    $mid = (int)($in['id'] ?? 0);
    if ($mid) { $q = $db->prepare('SELECT * FROM g7_wk_milestones WHERE id = ? AND project_id = ?'); $q->execute([$mid, $p['id']]); $m = $q->fetch(PDO::FETCH_ASSOC); if (!$m) wk_fail('Ese hito no existe', 404); }
    if ($act === 'milestone_order') {
        $up = $db->prepare('UPDATE g7_wk_milestones SET position = ? WHERE id = ? AND project_id = ?');
        foreach (array_values(array_map('intval', (array)($in['ids'] ?? []))) as $i => $x) $up->execute([$i + 1, $x, $p['id']]);
    } elseif ($act === 'milestone_del') {
        $db->prepare('UPDATE g7_tasks SET milestone_id = NULL WHERE milestone_id = ?')->execute([$mid]);
        $db->prepare('DELETE FROM g7_wk_milestones WHERE id = ?')->execute([$mid]);
        g7_audit('work_milestone_delete', 'proyecto #' . $p['id'], ['name' => $m['name']], $uid);
    } elseif ($act === 'milestone_done') {
        $on = !empty($in['on']);
        if ($on) {
            // Un hito se cumple cuando no le quedan tareas abiertas (salvo que se fuerce)
            $q = $db->prepare('SELECT COUNT(*) FROM g7_tasks WHERE milestone_id = ? AND parent_id IS NULL AND archived = 0 AND completed_at IS NULL'); $q->execute([$mid]);
            if (($n = (int)$q->fetchColumn()) && empty($in['force'])) wk_out(['confirm' => 'Al hito ' . ($n > 1 ? "le quedan $n tareas abiertas" : 'le queda 1 tarea abierta') . '. ¿Lo marcas cumplido igual?']);
        }
        $db->prepare('UPDATE g7_wk_milestones SET done_at = ?, done_by = ? WHERE id = ?')->execute([$on ? tk_now() : null, $on ? $uid : null, $mid]);
        g7_audit($on ? 'work_milestone_done' : 'work_milestone_reopen', 'proyecto #' . $p['id'], ['name' => $m['name']], $uid);
    } else {
        $name = wk_str($in['name'] ?? '', 120); if ($name === '') wk_fail('Pon un nombre para el hito');
        $due = wk_date($in['due_date'] ?? ''); $desc = wk_str($in['description'] ?? '', 500);
        if ($mid) $db->prepare('UPDATE g7_wk_milestones SET name = ?, due_date = ?, description = ? WHERE id = ?')->execute([$name, $due, $desc, $mid]);
        else {
            $pos = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM g7_wk_milestones WHERE project_id = ?'); $pos->execute([$p['id']]);
            $db->prepare('INSERT INTO g7_wk_milestones (project_id, name, description, due_date, position, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$p['id'], $name, $desc, $due, (int)$pos->fetchColumn(), tk_now()]);
        }
        g7_audit('work_milestone_save', 'proyecto #' . $p['id'], ['name' => $name], $uid);
    }
    $db->prepare('UPDATE g7_wk_projects SET updated_at = ? WHERE id = ?')->execute([tk_now(), $p['id']]);
    wk_out(['ok' => true]);

case 'project_task_search':
    // Tareas que puede sumar al proyecto: las que ve y no son de otro proyecto
    $p = wk_project($db, (int)($in['project_id'] ?? 0));
    if (!$p || !wk_project_visible($db, $ctx, $p['id'])) wk_fail('Ese proyecto no existe', 404);
    $g = wk_general_id($db);
    $q = $db->prepare("SELECT t.id, t.title, t.completed_at, b.name AS board_name FROM g7_tasks t LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g)
        WHERE t.archived = 0 AND t.parent_id IS NULL AND t.project_id IS NULL AND t.title LIKE ? AND " . wk_task_where($db, $ctx) . ' ORDER BY t.completed_at IS NOT NULL, t.updated_at DESC LIMIT 30');
    $q->execute(['%' . wk_str($in['q'] ?? '', 80) . '%']);
    wk_out(['items' => array_map(fn($r) => ['id' => (int)$r['id']] + $r, $q->fetchAll(PDO::FETCH_ASSOC))]);

// ── Solicitudes (fase 4) ─────────────────────────────────────
case 'forms':
    // Catálogo para pedir (activos) y, para quien los administra, todos
    $rows = $db->query('SELECT f.*, t.name AS team_name FROM g7_wk_forms f LEFT JOIN g7_teams t ON t.id = f.team_id ORDER BY f.position, f.name')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) { $f = wk_form_row($r); $f['can_manage'] = wk_can_manage_form($ctx, $f['team_id']); if ($f['active'] || $f['can_manage']) $out[] = $f; }
    $boards = $db->query('SELECT b.id, b.name, b.team_id FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.name')->fetchAll(PDO::FETCH_ASSOC);
    $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'team_id' => $c['team_id'] ? (int)$c['team_id'] : null, 'color' => $c['color']],
        $db->query('SELECT id, name, team_id, color FROM g7_wk_ticket_cats WHERE active = 1 AND id <> ' . (int)wk_cfg_get($db, 'work_task_cat', '0') . ' ORDER BY position, name')->fetchAll(PDO::FETCH_ASSOC));
    $teams = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name'], 'color' => $t['color'] ?? null], $db->query('SELECT * FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC));
    wk_out(['forms' => $out, 'types' => WK_FORM_TYPES, 'boards' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'], 'team_id' => $b['team_id'] ? (int)$b['team_id'] : null], $boards),
            'cats' => $cats, 'teams' => $teams, 'can_forms' => $ctx['all'] || (bool)$ctx['lead'], 'lead' => $ctx['lead'], 'all' => $ctx['all'], 'old_requests' => (int)$db->query('SELECT COUNT(*) FROM g7_wk_requests')->fetchColumn()]);

case 'form_save':
case 'form_del':
    $id = (int)($in['id'] ?? 0);
    $old = null;
    if ($id) { $q = $db->prepare('SELECT * FROM g7_wk_forms WHERE id = ?'); $q->execute([$id]); $old = $q->fetch(PDO::FETCH_ASSOC); if (!$old) wk_fail('Ese formulario no existe', 404);
        if (!wk_can_manage_form($ctx, $old['team_id'] ? (int)$old['team_id'] : null)) wk_fail('No puedes cambiar este formulario', 403); }
    if ($act === 'form_del') {
        $q = $db->prepare('SELECT (SELECT COUNT(*) FROM g7_wk_requests WHERE form_id = ?) + (SELECT COUNT(*) FROM g7_wk_tickets WHERE form_id = ?)'); $q->execute([$id, $id]);
        if ((int)$q->fetchColumn()) { $db->prepare('UPDATE g7_wk_forms SET active = 0, updated_at = ? WHERE id = ?')->execute([tk_now(), $id]); wk_out(['ok' => true, 'msg' => 'Tenía solicitudes: quedó desactivado (no se borra para no perder el historial)']); }
        $db->prepare('DELETE FROM g7_wk_forms WHERE id = ?')->execute([$id]);
        g7_audit('work_form_delete', 'formulario #' . $id, null, $uid);
        wk_out(['ok' => true]);
    }
    $team = (int)($in['team_id'] ?? 0) ?: null;
    if (!wk_can_manage_form($ctx, $team)) wk_fail('Solo los jefes de ese departamento (o administración) arman sus formularios', 403);
    $name = wk_str($in['name'] ?? '', 100); if ($name === '') wk_fail('Pon un nombre para el formulario');
    $fields = wk_clean_form_fields((array)($in['fields'] ?? []));
    $board = (int)($in['board_id'] ?? 0) ?: null;
    if ($board && !wk_board_visible($db, $ctx, $board)) wk_fail('Ese tablero no existe');
    $asg = (int)($in['assignee_id'] ?? 0) ?: null;
    $icon = preg_match('/^fa-[a-z0-9-]{1,36}$/', (string)($in['icon'] ?? '')) ? $in['icon'] : 'fa-inbox';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower($in['color']) : '#ff6600';
    // Categoría del ticket que nace (sus plazos de SLA); si no se elige, la primera del departamento
    $cat = (int)($in['category_id'] ?? 0) ?: null;
    if ($cat) { $q = $db->prepare('SELECT 1 FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$cat]); if (!$q->fetchColumn()) wk_fail('Esa categoría no existe'); }
    $prio = in_array($in['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $in['priority'] : null;   // null = la elige quien pide
    $vals = [$team, $name, wk_str($in['description'] ?? '', 500), $icon, $color, json_encode($fields, JSON_UNESCAPED_UNICODE), $board, $asg, !empty($in['active']) ? 1 : 0, $cat, $prio, tk_now()];
    if ($id) $db->prepare('UPDATE g7_wk_forms SET team_id = ?, name = ?, description = ?, icon = ?, color = ?, fields = ?, board_id = ?, assignee_id = ?, active = ?, category_id = ?, priority = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_wk_forms (team_id, name, description, icon, color, fields, board_id, assignee_id, active, category_id, priority, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, tk_now()]); $id = (int)$db->lastInsertId(); }
    g7_audit('work_form_save', 'formulario #' . $id, ['name' => $name, 'fields' => count($fields)], $uid);
    wk_out(['id' => $id]);

case 'form_submit':
    // Panel de solicitudes: el pedido entra directo como ticket (con su SLA) y su tarea, en el departamento del formulario
    $q = $db->prepare('SELECT * FROM g7_wk_forms WHERE id = ? AND active = 1'); $q->execute([(int)($in['form_id'] ?? 0)]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) wk_fail('Ese formulario no existe o está desactivado', 404);
    $f = wk_form_row($f);
    $title = wk_str($in['title'] ?? '', 200); if ($title === '') wk_fail('Pon un título para tu pedido');
    $ans = []; $given = (array)($in['answers'] ?? []); $lines = [];
    foreach ($f['fields'] as $fd) {
        if ($fd['type'] === 'section') { $lines[] = "\n" . mb_strtoupper($fd['label']); continue; }
        $v = $given[$fd['id']] ?? '';
        $v = is_array($v) ? '' : trim((string)$v);
        if ($fd['type'] === 'select' && $v !== '' && !in_array($v, $fd['options'], true)) wk_fail('Opción no válida en "' . $fd['label'] . '"');
        if ($fd['type'] === 'number' && $v !== '' && !is_numeric(str_replace(',', '.', $v))) wk_fail('"' . $fd['label'] . '" tiene que ser un número');
        if ($fd['type'] === 'date' && $v !== '' && !wk_date($v)) wk_fail('Fecha no válida en "' . $fd['label'] . '"');
        if ($fd['type'] === 'url' && $v !== '' && !preg_match('#^https?://#i', $v)) wk_fail('"' . $fd['label'] . '" tiene que ser un link (https://…)');
        if ($fd['type'] === 'person' && $v !== '') { $v = (string)(int)$v; $q = $db->prepare('SELECT 1 FROM g7_users WHERE id = ? AND active = 1'); $q->execute([(int)$v]); if (!$q->fetchColumn()) wk_fail('Persona no válida en "' . $fd['label'] . '"'); }
        if ($fd['type'] === 'check') $v = $v === '1' ? '1' : '';
        if ($fd['required'] && $v === '') wk_fail('Completa "' . $fd['label'] . '"');
        $ans[$fd['id']] = mb_substr($v, 0, 5000);
        if ($v !== '') $lines[] = $fd['label'] . ': ' . ($fd['type'] === 'check' ? 'Sí' : ($fd['type'] === 'person' ? wk_user_name($db, (int)$v) : $v));
    }
    $prio = $f['priority'] ?: (in_array($in['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $in['priority'] : $cfg['default_priority']);
    $cat = $f['category_id'];
    if (!$cat || !(function () use ($db, $cat) { $q = $db->prepare('SELECT 1 FROM g7_wk_ticket_cats WHERE id = ? AND active = 1'); $q->execute([$cat]); return (bool)$q->fetchColumn(); })()) {
        $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE active = 1 AND id <> ? AND ' . ($f['team_id'] ? 'team_id = ?' : 'team_id IS NULL') . ' ORDER BY position LIMIT 1');
        $q->execute($f['team_id'] ? [(int)wk_cfg_get($db, 'work_task_cat', '0'), $f['team_id']] : [(int)wk_cfg_get($db, 'work_task_cat', '0')]);
        $cat = (int)$q->fetchColumn();
        if (!$cat) {   // el departamento no tiene categoría: se crea una con el nombre del departamento
            $tn = $f['team_id'] ? (string)$db->query('SELECT name FROM g7_teams WHERE id = ' . (int)$f['team_id'])->fetchColumn() : 'Administración';
            $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES (?, ?, ?, 1, 99)')->execute(['Pedidos a ' . $tn, $f['team_id'], $f['color']]);
            $cat = (int)$db->lastInsertId();
        }
    }
    $desc = trim(implode("\n", $lines)) ?: $title;
    if ($f['board_id']) $GLOBALS['WK_TK_BOARD'] = $f['board_id'];   // el formulario manda su tarea a un tablero propio
    try { $id = wk_create_ticket($db, $uid, $cat, $prio, $title, $desc, ['origin' => 'form', 'form_id' => $f['id'], 'answers' => $ans]); }
    catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    unset($GLOBALS['WK_TK_BOARD']);
    if ($f['assignee_id']) { wk_tk_set($db, $id, ['assignee_id' => $f['assignee_id']]); wk_task_from_tk($db, $id, $uid); wk_tk_notify($db, 'assigned', [$f['assignee_id']], wk_tk_row($db, $id), $uid, 'Pedido desde ' . $f['name']); }
    g7_audit('work_form_submit', 'ticket #' . $id, ['form' => $f['id']], $uid);
    wk_out(['id' => $id]);

case 'my_requests':
    // Mis solicitudes: todos los tickets que pidió esta persona (por formulario, a mano o desde una tarea), con lo importante de cada uno.
    // Solo lo que el que pide puede ver: nunca las notas internas del equipo.
    wk_sla_sweep($db);
    $late = "(t.status IN ('abierto', 'en_curso') AND t.paused_at IS NULL AND (t.resolution_due < NOW() OR (t.first_response_at IS NULL AND t.first_response_due < NOW())))";
    $w = ['t.requester_id = ?']; $args = [$uid];
    $st = (string)($in['status'] ?? 'abiertos');
    if ($st === 'abiertos') $w[] = "t.status IN ('abierto', 'en_curso', 'esperando')";
    elseif ($st === 'en_curso') $w[] = "t.status = 'en_curso'";   // alguien ya lo está trabajando
    elseif ($st === 'esperando') $w[] = "t.status = 'esperando'";
    elseif ($st === 'vencidos') $w[] = $late;
    elseif ($st === 'resueltos') $w[] = "t.status IN ('resuelto', 'cerrado')";
    elseif ($st === 'sin_calificar') { wk_ev_install($db); $w[] = "t.status = 'cerrado' AND t.origin <> 'tarea' AND NOT EXISTS (SELECT 1 FROM g7_wk_ratings r WHERE r.ticket_id = t.id)"; }
    $qq = wk_str($in['q'] ?? '', 100);
    if ($qq !== '') {
        if (preg_match('/^(?:t-?)?0*(\d{1,9})$/i', $qq, $m)) { $w[] = '(t.id = ? OR t.subject LIKE ?)'; $args[] = (int)$m[1]; $args[] = '%' . $qq . '%'; }
        else { $like = '%' . addcslashes($qq, '%_\\') . '%'; $w[] = '(t.subject LIKE ? OR t.description LIKE ?)'; array_push($args, $like, $like); }
    }
    $q = $db->prepare("SELECT t.id, t.subject, t.status, t.priority, t.team_id, t.assignee_id, t.origin, t.created_at, t.updated_at, t.first_response_due, t.first_response_at,
            t.resolution_due, t.resolved_at, t.closed_at, t.paused_at, t.escalated, t.task_id, c.name AS cat_name, c.color AS cat_color, tm.name AS team_name,
            f.name AS form_name, f.icon AS form_icon, f.color AS form_color, $late AS late
        FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id LEFT JOIN g7_teams tm ON tm.id = t.team_id LEFT JOIN g7_wk_forms f ON f.id = t.form_id
        WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(t.status, 'esperando', 'abierto', 'en_curso', 'resuelto', 'cerrado'), t.updated_at DESC LIMIT 200");
    $q->execute($args); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    // Última respuesta visible (sin notas internas) y cuántas hay
    $last = [];
    if ($rows) {
        $ids = wk_ints(array_column($rows, 'id'));
        foreach ($db->query("SELECT m.ticket_id, m.user_id, m.body, m.created_at, x.n FROM g7_wk_ticket_msgs m JOIN (SELECT ticket_id, MAX(id) mid, COUNT(*) n FROM g7_wk_ticket_msgs
                WHERE internal = 0 AND kind = 'msg' AND ticket_id IN ($ids) GROUP BY ticket_id) x ON x.mid = m.id")->fetchAll(PDO::FETCH_ASSOC) as $m)
            $last[(int)$m['ticket_id']] = ['user_id' => $m['user_id'] !== null ? (int)$m['user_id'] : null, 'at' => $m['created_at'], 'n' => (int)$m['n'],
                'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$m['body']))), 0, 180)];
    }
    $out = array_map(function ($r) use ($last, $uid) {
        $l = $last[(int)$r['id']] ?? null;
        return ['id' => (int)$r['id'], 'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'team_id' => $r['team_id'] ? (int)$r['team_id'] : null,
            'task_id' => $r['task_id'] ? (int)$r['task_id'] : null, 'escalated' => (int)$r['escalated'], 'paused' => (bool)$r['paused_at'], 'late' => (bool)$r['late'],
            'last' => $l, 'msgs' => $l['n'] ?? 0, 'answered' => $l !== null && $l['user_id'] !== null && $l['user_id'] !== $uid] + $r;
    }, $rows);
    // Calificación de cada pedido cerrado (estrellas) para mostrarla o pedirla
    wk_ev_install($db);
    if ($out) { $rated = []; foreach ($db->query('SELECT ticket_id, stars FROM g7_wk_ratings WHERE ticket_id IN (' . wk_ints(array_column($out, 'id')) . ')')->fetchAll(PDO::FETCH_ASSOC) as $x) $rated[(int)$x['ticket_id']] = (int)$x['stars'];
        foreach ($out as &$o) $o['rating'] = $rated[$o['id']] ?? null; unset($o); }
    // Resumen de todo lo pedido (no solo lo filtrado)
    $s = $db->prepare("SELECT SUM(status IN ('abierto','en_curso','esperando')) open, SUM(status = 'en_curso') progress, SUM(status = 'esperando') waiting, SUM($late) late,
            SUM(resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) solved30, COUNT(*) total,
            AVG(CASE WHEN resolved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) res_min
        FROM g7_wk_tickets t WHERE requester_id = ?");
    $s->execute([$uid]); $sum = array_map(fn($v) => $v === null ? null : (int)round((float)$v), $s->fetch(PDO::FETCH_ASSOC));
    $sum['unrated'] = (int)$db->query("SELECT COUNT(*) FROM g7_wk_tickets t WHERE requester_id = $uid AND status = 'cerrado' AND origin <> 'tarea' AND NOT EXISTS (SELECT 1 FROM g7_wk_ratings r WHERE r.ticket_id = t.id)")->fetchColumn();
    wk_out(['tickets' => $out, 'summary' => $sum, 'now' => tk_now(), 'status' => wk_st('tickets'), 'filter' => $st]);

// ── Calificaciones de los pedidos (trabajo/entrega_lib.php) ──
case 'ev_get':   // lo necesario para calificar un ticket, o su calificación
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $f = wk_ev_form($db, $t);
    $isReq = (int)$t['requester_id'] === $uid;
    wk_out(['criteria' => $f['criteria'], 'rating' => wk_ev_can_see($ctx, $t) ? $f['rating'] : null,
        'can_rate' => $isReq && $t['status'] === 'cerrado' && !$f['rating'] && $t['origin'] !== 'tarea', 'subject' => $t['subject'], 'code' => wk_tk_code($t['id'])]);
case 'ev_save':
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    try { $r = wk_ev_save($db, $uid, $t['id'], (int)($in['stars'] ?? 0), (array)($in['scores'] ?? []), (string)($in['comment'] ?? '')); } catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    wk_out(['ok' => true, 'rating' => $r]);
case 'ev_pending':   // pedidos cerrados sin calificar (Inicio y Mis solicitudes)
    wk_out(['pending' => wk_ev_pending($db, $uid)]);
case 'ev_config':    // Tickets › Calificaciones: puntos por departamento y promedios
    if (!$ctx['all'] && !$ctx['lead']) wk_fail('Lo ven los admins y los jefes de departamento', 403);
    $names = []; foreach ($db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $x) $names[(int)$x['id']] = $x['name'];
    $rep = array_values(array_filter(wk_ev_report($db, $ctx), fn($r) => isset($names[$r['team_id']]) && ($r['can_config'] || $ctx['all'])));
    foreach ($rep as &$r) $r['team'] = $names[$r['team_id']]; unset($r);
    usort($rep, fn($a, $b) => strcmp($a['team'], $b['team']));
    wk_out(['teams' => $rep]);
case 'ev_criteria_save':
    $team = (int)($in['team_id'] ?? 0);
    if (!$team || !$db->query("SELECT 1 FROM g7_teams WHERE id = $team")->fetchColumn()) wk_fail('Ese departamento no existe');
    if (!wk_ev_can_config($ctx, $team)) wk_fail('Solo un admin o el jefe del departamento cambia qué se califica', 403);
    $list = wk_ev_criteria_save($db, $team, (array)($in['items'] ?? []));
    g7_audit('work_rating_criteria', 'team:' . $team, ['n' => count($list)], $uid);
    wk_out(['ok' => true, 'criteria' => $list]);

// ── Entregar un contenido de Promos a un ticket o una tarea ──
case 'deliver_targets':
    wk_out(['items' => wk_deliver_targets($db, $uid, wk_str($in['q'] ?? '', 80))]);
case 'deliver':
    try { $r = wk_deliver($db, $uid, (int)($in['content_id'] ?? 0), (string)($in['kind'] ?? 'ticket') === 'tarea' ? 'tarea' : 'ticket', (int)($in['id'] ?? 0)); } catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    wk_out(['ok' => true] + $r);

// ── Inicio: mis tareas en curso, mis pedidos en curso y mis pedidos resueltos (para aprobar y calificar) ──
case 'home_work': {
    $tasks = $db->query("SELECT t.id, t.title, t.board_id, t.due_date, t.status, t.priority, b.name AS board_name FROM g7_tasks t JOIN g7_task_assignees x ON x.task_id = t.id AND x.user_id = $uid
        LEFT JOIN g7_wk_boards b ON b.id = t.board_id WHERE t.completed_at IS NULL AND t.archived = 0 ORDER BY t.due_date IS NULL, t.due_date, t.updated_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $tk = fn(string $where) => array_map(fn($r) => ['id' => (int)$r['id'], 'code' => wk_tk_code((int)$r['id']), 'subject' => $r['subject'], 'status' => $r['status'],
            'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'assignee' => $r['assignee_id'] ? wk_user_name($db, (int)$r['assignee_id']) : '', 'team' => (string)$r['team_name'],
            'resolution_due' => $r['resolution_due'], 'resolved_at' => $r['resolved_at'], 'updated_at' => $r['updated_at']],
        $db->query("SELECT t.*, tm.name AS team_name FROM g7_wk_tickets t LEFT JOIN g7_teams tm ON tm.id = t.team_id WHERE t.requester_id = $uid AND t.origin <> 'tarea' AND $where ORDER BY t.updated_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC));
    wk_out(['tasks' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'board_id' => (int)$r['board_id'], 'board' => (string)$r['board_name'], 'due_date' => $r['due_date'], 'status' => $r['status']], $tasks),
        'open' => $tk("t.status IN ('abierto','en_curso','esperando')"), 'resolved' => $tk("t.status = 'resuelto'"), 'unrated' => wk_ev_pending($db, $uid), 'today' => $today, 'status' => wk_st('tickets')]);
}

case 'my_form_tickets':
    // Lo que pidió alguien desde el panel (sus tickets de origen formulario)
    $q = $db->prepare("SELECT t.id, t.subject, t.status, t.priority, t.team_id, t.assignee_id, t.created_at, t.updated_at, t.resolution_due, t.resolved_at, t.paused_at, f.name AS form_name, f.icon AS form_icon, f.color AS form_color, tm.name AS team_name
        FROM g7_wk_tickets t LEFT JOIN g7_wk_forms f ON f.id = t.form_id LEFT JOIN g7_teams tm ON tm.id = t.team_id WHERE t.origin = 'form' AND t.requester_id = ? ORDER BY FIELD(t.status, 'abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'), t.updated_at DESC LIMIT 200");
    $q->execute([$uid]);
    wk_out(['tickets' => array_map(fn($r) => ['id' => (int)$r['id'], 'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'paused' => (bool)$r['paused_at']] + $r, $q->fetchAll(PDO::FETCH_ASSOC)), 'now' => tk_now(), 'status' => wk_st('tickets')]);

case 'request_create':
    $q = $db->prepare('SELECT * FROM g7_wk_forms WHERE id = ? AND active = 1'); $q->execute([(int)($in['form_id'] ?? 0)]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) wk_fail('Ese formulario no existe o está desactivado', 404);
    $f = wk_form_row($f);
    $title = wk_str($in['title'] ?? '', 200); if ($title === '') wk_fail('Pon un título para tu pedido');
    $ans = []; $given = (array)($in['answers'] ?? []);
    foreach ($f['fields'] as $fd) {
        $v = $given[$fd['id']] ?? '';
        $v = is_array($v) ? '' : trim((string)$v);
        if ($fd['type'] === 'select' && $v !== '' && !in_array($v, $fd['options'], true)) wk_fail('Opción no válida en "' . $fd['label'] . '"');
        if ($fd['type'] === 'number' && $v !== '' && !is_numeric(str_replace(',', '.', $v))) wk_fail('"' . $fd['label'] . '" tiene que ser un número');
        if ($fd['type'] === 'date' && $v !== '' && !wk_date($v)) wk_fail('Fecha no válida en "' . $fd['label'] . '"');
        if ($fd['type'] === 'url' && $v !== '' && !preg_match('#^https?://#i', $v)) wk_fail('"' . $fd['label'] . '" tiene que ser un link (https://…)');
        if ($fd['type'] === 'check') $v = $v === '1' ? '1' : '';
        if ($fd['required'] && $v === '') wk_fail('Completa "' . $fd['label'] . '"');
        $ans[$fd['id']] = mb_substr($v, 0, 5000);
    }
    $prio = in_array($in['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $in['priority'] : $cfg['default_priority'];
    $now = tk_now();
    $db->prepare('INSERT INTO g7_wk_requests (form_id, team_id, title, answers, priority, requester_id, assignee_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$f['id'], $f['team_id'], $title, json_encode($ans, JSON_UNESCAPED_UNICODE), $prio, $uid, $f['assignee_id'], $now, $now]);
    $id = (int)$db->lastInsertId();
    wk_req_log($db, $id, $uid, 'creó la solicitud');
    wk_notify($db, 'wk_req', $f['assignee_id'] ? [$f['assignee_id']] : wk_team_people($db, $f['team_id'], true), $id, $uid, 'Nueva solicitud: ' . $title);
    wk_out(['id' => $id]);

case 'requests':
    // box: mine (las que pide) | inbox (las de los departamentos que atiendo)
    $box = ($in['box'] ?? 'mine') === 'inbox' ? 'inbox' : 'mine';
    $w = ['1']; $args = [];
    if ($box === 'mine') { $w[] = 'r.requester_id = ?'; $args[] = $uid; }
    else { $teams = wk_agent_teams($ctx); if ($teams !== null) $w[] = 'r.team_id IN (' . wk_ints($teams) . ')'; }
    $st = (string)($in['status'] ?? '');
    if ($st === 'abiertas') $w[] = "r.status IN ('nueva', 'en_revision', 'aprobada')";
    elseif (isset(wk_st('requests')[$st])) { $w[] = 'r.status = ?'; $args[] = $st; }
    $q = $db->prepare('SELECT r.id, r.title, r.status, r.priority, r.team_id, r.requester_id, r.assignee_id, r.task_id, r.project_id, r.created_at, r.updated_at,
            f.name AS form_name, f.icon AS form_icon, f.color AS form_color, t.name AS team_name,
            (SELECT COUNT(*) FROM g7_wk_req_msgs m WHERE m.request_id = r.id AND m.kind = \'msg\'' . ($box === 'mine' ? ' AND m.internal = 0' : '') . ') AS n_msgs
        FROM g7_wk_requests r LEFT JOIN g7_wk_forms f ON f.id = r.form_id LEFT JOIN g7_teams t ON t.id = r.team_id
        WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(r.status, 'nueva', 'en_revision', 'aprobada', 'convertida', 'rechazada', 'cerrada'), r.updated_at DESC LIMIT 300");
    $q->execute($args);
    wk_out(['requests' => array_map(fn($r) => ['id' => (int)$r['id'], 'requester_id' => (int)$r['requester_id'], 'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'n_msgs' => (int)$r['n_msgs']] + $r, $q->fetchAll(PDO::FETCH_ASSOC)),
            'status' => wk_st('requests'), 'agent' => $ctx['all'] || (bool)$ctx['teams']]);

case 'request_get':
    $r = wk_request($db, $ctx, (int)($in['id'] ?? 0));
    $q = $db->prepare('SELECT id, user_id, body, internal, kind, created_at FROM g7_wk_req_msgs WHERE request_id = ?' . ($r['agent'] ? '' : ' AND internal = 0') . ' ORDER BY id');
    $q->execute([$r['id']]);
    $r['msgs'] = array_map(fn($m) => ['id' => (int)$m['id'], 'user_id' => $m['user_id'] ? (int)$m['user_id'] : null, 'internal' => (int)$m['internal']] + $m, $q->fetchAll(PDO::FETCH_ASSOC));
    $boards = $r['agent'] ? array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT b.id, b.name FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.name')->fetchAll(PDO::FETCH_ASSOC)) : [];
    wk_out(['request' => $r, 'status' => wk_st('requests'), 'boards' => $boards]);

case 'request_msg':
    $r = wk_request($db, $ctx, (int)($in['id'] ?? 0));
    $body = wk_str($in['body'] ?? '', 5000); if ($body === '') wk_fail('Escribe algo');
    $internal = $r['agent'] && !empty($in['internal']);
    wk_req_log($db, $r['id'], $uid, $body, $internal, 'msg');
    // Aviso: al que pidió (si no es nota interna) y a quien la atiende
    $to = $internal ? [] : [$r['requester_id']];
    $to = array_merge($to, $r['assignee_id'] ? [$r['assignee_id']] : wk_team_people($db, $r['team_id'], true));
    wk_notify($db, 'wk_req', $to, $r['id'], $uid, ($internal ? 'Nota interna: ' : '') . mb_substr($body, 0, 200));
    wk_out(['ok' => true]);

case 'request_update':
    // Atender: estado, responsable, prioridad, decisión
    $r = wk_request($db, $ctx, (int)($in['id'] ?? 0));
    if (!$r['agent']) wk_fail('Solo quien atiende este departamento puede cambiar la solicitud', 403);
    $f = []; $ev = [];
    if (isset($in['status'])) {
        if (!isset(wk_st('requests')[$in['status']]) || $in['status'] === 'convertida') wk_fail('Estado no válido');
        if ($in['status'] !== $r['status']) { $f['status'] = $in['status']; $ev[] = 'pasó a ' . wk_st('requests')[$in['status']][0];
            if (in_array($in['status'], ['aprobada', 'rechazada'], true)) { $f['decided_by'] = $uid; $f['decided_at'] = tk_now(); $f['decision_note'] = wk_str($in['note'] ?? '', 1000); } }
    }
    if (array_key_exists('assignee_id', $in)) { $a = (int)$in['assignee_id'] ?: null; if ($a !== $r['assignee_id']) { $f['assignee_id'] = $a; $ev[] = $a ? 'la asignó a ' . wk_user_name($db, $a) : 'le sacó el responsable'; } }
    if (isset($in['priority']) && in_array($in['priority'], array_column($cfg['priorities'], 'id'), true) && $in['priority'] !== $r['priority']) { $f['priority'] = $in['priority']; $ev[] = 'cambió la prioridad'; }
    if (!$f) wk_out(['ok' => true]);
    $f['updated_at'] = tk_now();
    $db->prepare('UPDATE g7_wk_requests SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $r['id']]);
    $txt = implode(' · ', $ev) . (!empty($f['decision_note']) ? ': "' . $f['decision_note'] . '"' : '');
    wk_req_log($db, $r['id'], $uid, $txt);
    wk_notify($db, 'wk_req', array_merge([$r['requester_id']], !empty($f['assignee_id']) ? [$f['assignee_id']] : []), $r['id'], $uid, $r['title'] . ': ' . $txt);
    wk_out(['ok' => true]);

case 'request_convert':
    // Convierte en tarea (en un tablero) o en proyecto; la solicitud queda "En marcha" y vinculada
    $r = wk_request($db, $ctx, (int)($in['id'] ?? 0));
    if (!$r['agent']) wk_fail('Solo quien atiende este departamento puede convertir la solicitud', 403);
    if ($r['task_id'] || $r['project_id']) wk_fail('Esta solicitud ya se convirtió');
    $lines = [];
    foreach ($r['form_fields'] as $fd) {
        $v = $r['answers'][$fd['id']] ?? ''; if ($v === '') continue;
        if ($fd['type'] === 'person') $v = wk_user_name($db, (int)$v);
        if ($fd['type'] === 'check') $v = 'Sí';
        $lines[] = $fd['label'] . ': ' . $v;
    }
    $desc = 'Solicitud #' . $r['id'] . ' de ' . wk_user_name($db, $r['requester_id']) . ' (' . $r['form_name'] . ")\n\n" . implode("\n", $lines);
    $to = (string)($in['to'] ?? 'task');
    if ($to === 'project') {
        if ($ctx['role'] === 'solicitante') wk_fail('No puedes crear proyectos', 403);
        $now = tk_now();
        $db->prepare("INSERT INTO g7_wk_projects (name, description, team_id, owner_id, status, start_date, color, visibility, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'planificado', ?, '#ff6600', 'public', ?, ?, ?)")
           ->execute([$r['title'], $desc, $r['team_id'], (int)($in['owner_id'] ?? 0) ?: $uid, date('Y-m-d'), $uid, $now, $now]);
        $pid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO g7_wk_project_members (project_id, user_id, role, added_at) VALUES (?, ?, 'manager', ?)")->execute([$pid, $uid, $now]);
        $db->prepare("UPDATE g7_wk_requests SET project_id = ?, status = 'convertida', updated_at = ? WHERE id = ?")->execute([$pid, $now, $r['id']]);
        wk_req_log($db, $r['id'], $uid, 'la convirtió en el proyecto "' . $r['title'] . '"');
        wk_notify($db, 'wk_req', [$r['requester_id']], $r['id'], $uid, 'Tu solicitud se convirtió en un proyecto: ' . $r['title']);
        g7_audit('work_request_project', 'solicitud #' . $r['id'], ['project' => $pid], $uid);
        wk_out(['project_id' => $pid]);
    }
    $board = (int)($in['board_id'] ?? 0) ?: (int)$r['form_board'] ?: wk_general_id($db);
    if (!wk_board_visible($db, $ctx, $board)) wk_fail('Ese tablero no existe o no tienes acceso', 404);
    $asg = array_filter([(int)($in['assignee_id'] ?? 0) ?: $r['assignee_id']]);
    $tid = wk_create_task($db, $uid, $board, $r['title'], $desc, $asg, wk_date($in['due_date'] ?? ''), $r['priority']);
    $db->prepare("UPDATE g7_wk_requests SET task_id = ?, status = 'convertida', updated_at = ? WHERE id = ?")->execute([$tid, tk_now(), $r['id']]);
    wk_req_log($db, $r['id'], $uid, 'la convirtió en una tarea');
    wk_notify($db, 'wk_req', [$r['requester_id']], $r['id'], $uid, 'Tu solicitud ya está en marcha como tarea: ' . $r['title']);
    g7_audit('work_request_task', 'solicitud #' . $r['id'], ['task' => $tid], $uid);
    wk_out(['task_id' => $tid, 'board_id' => $board]);

// ── Tickets (fase 4) ─────────────────────────────────────────
// Vínculos del ticket (clientes y contenidos) y el buscador del selector (trabajo/tk_links_lib.php, chat/refs_lib.php)
case 'tk_canned_save':
    if (!$ctx['all']) wk_fail('Solo admins y directores pueden editar las respuestas.', 403);
    require_once __DIR__ . '/tk_canned_lib.php';
    try {
        $c = isset($in['canned']) ? tk_canned_save($db, (array)$in['canned']) : tk_canned_all($db);
        $a = isset($in['autoreply']) ? tk_autoreply_save($db, (array)$in['autoreply']) : tk_autoreply_get($db);
    } catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    wk_out(['ok' => true, 'canned' => $c, 'autoreply' => $a]);
case 'tk_link':
    require_once __DIR__ . '/tk_links_lib.php';
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));   // tiene que poder ver el ticket: quien lo atiende o quien lo pidió
    try {
        if (($in['op'] ?? '') === 'remove') wk_tk_link_remove($db, $t['id'], (string)($in['kind'] ?? ''), (int)($in['ref'] ?? 0));
        else wk_tk_link_add($db, $t['id'], $uid, (string)($in['kind'] ?? ''), (int)($in['ref'] ?? 0));
    } catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    wk_out(['ok' => true, 'links' => wk_tk_links($db, $t['id'], $uid)]);
case 'ref_kinds':
    require_once dirname(__DIR__) . '/chat/refs_lib.php';
    $only = array_values(array_intersect(ch_ref_kinds($uid), (array)($in['only'] ?? array_keys(CH_REF_KINDS))));
    wk_out(['kinds' => array_map(fn($k) => ['kind' => $k] + CH_REF_KINDS[$k], $only)]);
case 'ref_search':
    require_once dirname(__DIR__) . '/chat/refs_lib.php';
    $k = (string)($in['kind'] ?? ''); $only = (array)($in['only'] ?? []);
    $items = $k !== '' || !$only ? ch_refs_search($db, $uid, (string)($in['q'] ?? ''), $k) : array_merge(...array_map(fn($x) => ch_refs_search($db, $uid, (string)($in['q'] ?? ''), (string)$x), $only));
    wk_out(['items' => $items]);
case 'ref_resolve':
    require_once dirname(__DIR__) . '/chat/refs_lib.php';
    wk_out(['refs' => ch_refs_resolve($db, $uid, (array)($in['refs'] ?? []))]);
case 'tk_meta':
    $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'team_id' => $c['team_id'] ? (int)$c['team_id'] : null, 'active' => (int)$c['active']] + $c, $db->query('SELECT c.*, t.name AS team_name FROM g7_wk_ticket_cats c LEFT JOIN g7_teams t ON t.id = c.team_id ORDER BY c.position, c.name')->fetchAll(PDO::FETCH_ASSOC));
    $sla = array_map(fn($s) => ['category_id' => (int)$s['category_id'], 'response_min' => (int)$s['response_min'], 'resolution_min' => (int)$s['resolution_min']] + $s, $db->query('SELECT * FROM g7_wk_sla ORDER BY category_id')->fetchAll(PDO::FETCH_ASSOC));
    $isAdm = $ctx['role'] === 'admin';
    $out = ['cats' => $cats, 'sla' => $sla, 'status' => wk_st('tickets'), 'admin' => $isAdm, 'agent' => $ctx['all'] || (bool)$ctx['teams'],
        'origins' => WK_TK_ORIGINS, 'base_on' => wb_enabled(), 'task_cat' => (int)wk_cfg_get($db, 'work_task_cat', '0'),
        'teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC))];
    // Para asignar desde la tabla: quién es de cada departamento (fuera de su departamento solo asignan admin y director, igual que ticket_update)
    if ($out['agent']) {
        $tp = [];
        foreach ($db->query('SELECT m.team_id, m.user_id FROM g7_team_members m JOIN g7_users u ON u.id = m.user_id AND u.active = 1')->fetchAll(PDO::FETCH_ASSOC) as $m) $tp[(int)$m['team_id']][] = (int)$m['user_id'];
        $out['team_people'] = (object)$tp; $out['assign_any'] = (bool)$ctx['all'];
    }
    if ($isAdm) {
        $out['team_boards'] = (object)wk_team_boards($db);
        $out['boards'] = array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'], 'team_id' => $b['team_id'] ? (int)$b['team_id'] : null], $db->query('SELECT id, name, team_id FROM g7_wk_boards WHERE archived = 0 ORDER BY is_default DESC, name')->fetchAll(PDO::FETCH_ASSOC));
        $out['warn_min'] = (int)wk_cfg_get($db, 'work_tk_warn_min', '60');
        $out['chat_min'] = wk_tk_chat_min($db);   // plazo de los tickets del chat (tareas rápidas)
        $out['delivery_tpl'] = (string)wk_cfg_get($db, 'work_delivery_tpl', '') ?: WK_DELIVERY_TPL;   // mensaje de entrega desde Promos
        $out['digest_hour'] = (int)wk_cfg_get($db, 'work_tk_digest_hour', '9');
    }
    // Respuestas rápidas y respuesta automática (trabajo/tk_canned_lib.php): las editan admins y directores
    try { require_once __DIR__ . '/tk_canned_lib.php'; $out['canned'] = tk_canned_all($db); $out['canned_edit'] = (bool)$ctx['all']; if ($ctx['all']) $out['autoreply'] = tk_autoreply_get($db); } catch (Throwable $e) { $out['canned'] = []; }
    wk_out($out);

case 'tk_cfg_save':
    if ($ctx['role'] !== 'admin') wk_fail('Solo los administradores configuran categorías y SLA', 403);
    $keep = []; $idMap = [];
    foreach (array_slice((array)($in['cats'] ?? []), 0, 50) as $i => $c) {
        $name = wk_str($c['name'] ?? '', 80); if ($name === '') continue;
        $team = (int)($c['team_id'] ?? 0) ?: null;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($c['color'] ?? '')) ? strtolower($c['color']) : '#3b82f6';
        $cid = (int)($c['id'] ?? 0);
        if ($cid) $db->prepare('UPDATE g7_wk_ticket_cats SET name = ?, team_id = ?, color = ?, active = ?, position = ? WHERE id = ?')->execute([$name, $team, $color, !empty($c['active']) ? 1 : 0, $i + 1, $cid]);
        else { $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES (?, ?, ?, ?, ?)')->execute([$name, $team, $color, !empty($c['active']) ? 1 : 0, $i + 1]); $cid = (int)$db->lastInsertId(); }
        $keep[] = $cid;
        if (isset($c['key'])) $idMap[(string)$c['key']] = $cid;   // categorías nuevas: sus plazos vienen con una clave provisoria
    }
    // Las categorías quitadas se borran solo si no tienen tickets; si tienen, se desactivan
    foreach ($db->query('SELECT id FROM g7_wk_ticket_cats' . ($keep ? ' WHERE id NOT IN (' . wk_ints($keep) . ')' : ''))->fetchAll(PDO::FETCH_COLUMN) as $gone) {
        $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_tickets WHERE category_id = ?'); $q->execute([$gone]);
        if ((int)$q->fetchColumn()) $db->prepare('UPDATE g7_wk_ticket_cats SET active = 0 WHERE id = ?')->execute([$gone]);
        else { $db->prepare('DELETE FROM g7_wk_ticket_cats WHERE id = ?')->execute([$gone]); $db->prepare('DELETE FROM g7_wk_sla WHERE category_id = ?')->execute([$gone]); }
    }
    // Plazos: los generales (category_id 0) y los propios de cada categoría. Antes se borraban los de categoría al guardar: ahora vienen todos.
    if (isset($in['sla'])) {
        $db->exec('DELETE FROM g7_wk_sla');
        $ins = $db->prepare('INSERT IGNORE INTO g7_wk_sla (category_id, priority, response_min, resolution_min) VALUES (?, ?, ?, ?)');
        foreach ((array)$in['sla'] as $s) {
            $p = (string)($s['priority'] ?? ''); if (!in_array($p, array_column($cfg['priorities'], 'id'), true)) continue;
            $c = isset($s['cat_key']) && isset($idMap[(string)$s['cat_key']]) ? $idMap[(string)$s['cat_key']] : (int)($s['category_id'] ?? 0);
            if ($c && !in_array($c, $keep, true)) continue;
            $r = max(5, min(100000, (int)$s['response_min'])); $z = max($r, min(500000, (int)$s['resolution_min']));
            $ins->execute([$c, $p, $r, $z]);
        }
    }
    // Tablero de las tareas de los tickets de cada departamento
    if (isset($in['team_boards'])) {
        $map = [];
        foreach ((array)$in['team_boards'] as $team => $board) {
            $board = (int)$board; if (!$board || !($b = wk_board($db, $board)) || $b['archived']) continue;
            $map[(string)(int)$team] = $board;
        }
        wk_cfg_put($db, 'work_team_boards', json_encode((object)$map));
    }
    if (isset($in['warn_min'])) wk_cfg_put($db, 'work_tk_warn_min', (string)max(5, min(10080, (int)$in['warn_min'])));
    if (isset($in['chat_min'])) wk_cfg_put($db, 'work_tk_chat_min', (string)max(0, min(500000, (int)$in['chat_min'])));   // 0 = plazos de la categoría
    if (isset($in['delivery_tpl'])) wk_cfg_put($db, 'work_delivery_tpl', mb_substr(trim(strip_tags((string)$in['delivery_tpl'])), 0, 2000));
    if (isset($in['digest_hour'])) wk_cfg_put($db, 'work_tk_digest_hour', (string)max(-1, min(23, (int)$in['digest_hour'])));
    g7_audit('work_ticket_config', 'tickets', ['cats' => count($keep)], $uid);
    wk_out(['ok' => true]);

// ── Reportes desde el juego (juegos/reportes_lib.php, doc JUEGOS_REPORTES.md): prender, claves por juego. Solo admins. ──
case 'jr_admin':
case 'jr_on':
case 'jr_key_new':
case 'jr_key_rotate':
case 'jr_key_set':
case 'jr_key_del':
    if ($ctx['role'] !== 'admin') wk_fail('Solo los administradores configuran los reportes del juego', 403);
    require_once dirname(__DIR__) . '/juegos/reportes_lib.php';
    jr_install($db);
    $games = jr_games($db);
    $gameOk = fn($g) => $g === null || in_array($g, array_column($games, 'id'), true);
    $kid = (int)($in['id'] ?? 0);
    $krow = function () use ($db, $kid) { $q = $db->prepare('SELECT * FROM g7_jr_keys WHERE id = ?'); $q->execute([$kid]); $k = $q->fetch(PDO::FETCH_ASSOC); if (!$k) wk_fail('Esa clave no existe', 404); return $k; };
    $res = [];
    if ($act === 'jr_on') {
        wk_cfg_put($db, 'jr_on', !empty($in['on']) ? '1' : '0');
        g7_audit('game_reports_toggle', 'jr', ['on' => !empty($in['on'])], $uid);
    } elseif ($act === 'jr_key_new') {
        $name = wk_str($in['name'] ?? '', 80); if ($name === '') wk_fail('Ponle un nombre a la clave (por ejemplo, el del juego)');
        $g = (int)($in['game_id'] ?? 0) ?: null; if (!$gameOk($g)) wk_fail('Ese juego no existe');
        [$id, $key] = jr_key_create($db, $name, $g, $uid);
        g7_audit('game_reports_key_new', 'jr_key:' . $id, ['name' => $name], $uid);
        $res = ['key' => $key, 'id' => $id];
    } elseif ($act === 'jr_key_rotate') {
        $k = $krow();
        $res = ['key' => jr_key_rotate($db, (int)$k['id']), 'id' => (int)$k['id']];
        g7_audit('game_reports_key_rotate', 'jr_key:' . $k['id'], ['name' => $k['name']], $uid);
    } elseif ($act === 'jr_key_set') {
        $k = $krow(); $f = [];
        if (isset($in['name'])) { $n = wk_str($in['name'], 80); if ($n === '') wk_fail('El nombre no puede quedar vacío'); $f['name'] = $n; }
        if (array_key_exists('game_id', $in)) { $g = (int)$in['game_id'] ?: null; if (!$gameOk($g)) wk_fail('Ese juego no existe'); $f['game_id'] = $g; }
        if (isset($in['active'])) $f['active'] = !empty($in['active']) ? 1 : 0;
        if ($f) $db->prepare('UPDATE g7_jr_keys SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), (int)$k['id']]);
        if (isset($f['active'])) g7_audit('game_reports_key_' . ($f['active'] ? 'on' : 'off'), 'jr_key:' . $k['id'], ['name' => $k['name']], $uid);
    } elseif ($act === 'jr_key_del') {
        $k = $krow();
        $q = $db->prepare('SELECT COUNT(*) FROM g7_jr_players WHERE key_id = ?'); $q->execute([(int)$k['id']]);
        if ((int)$q->fetchColumn()) wk_fail('Esta clave ya tiene jugadores: desactívala en lugar de borrarla, así sus reportes siguen unidos a ellos.');
        $db->prepare('DELETE FROM g7_jr_keys WHERE id = ?')->execute([(int)$k['id']]);
        g7_audit('game_reports_key_del', 'jr_key:' . $k['id'], ['name' => $k['name']], $uid);
    }
    $cats = [];
    foreach (JR_KINDS as $kk => $v) { $cid = (int)wk_cfg_get($db, 'jr_cat_' . $kk, '0'); $q = $db->prepare('SELECT name FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$cid]); $cats[$kk] = ['label' => $v[0], 'name' => (string)$q->fetchColumn() ?: $v[1] . ' (se crea con el primer reporte)']; }
    wk_out($res + ['on' => jr_enabled($db), 'keys' => jr_keys_list($db), 'games' => $games, 'cats' => $cats,
        'endpoint' => g7_site_origin() . G7_BASE . 'juegos/reportes.php']);

case 'ticket_create':
    try { $id = wk_create_ticket($db, $uid, (int)($in['category_id'] ?? 0), (string)($in['priority'] ?? ''), (string)($in['subject'] ?? ''), (string)($in['description'] ?? '')); }
    catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    wk_out(['id' => $id]);

case 'tickets':
    wk_sla_sweep($db);
    $wb = wb_enabled() ? wb_sync($db) : null;
    $w = ['1']; $args = [];
    $agentAny = $ctx['all'] || (bool)$ctx['teams'];
    $box = (string)($in['box'] ?? 'mine');
    wk_tkc_install($db);
    if ($box === 'collab') { $w[] = 't.id IN (SELECT ticket_id FROM g7_wk_ticket_collabs WHERE user_id = ?)'; $args[] = $uid; }   // donde colaboro
    elseif ($box === 'mine' || !$agentAny) { $w[] = '(t.requester_id = ? OR t.id IN (SELECT ticket_id FROM g7_wk_ticket_collabs WHERE user_id = ?))'; $args[] = $uid; $args[] = $uid; $box = 'mine'; }
    else {
        $teams = wk_agent_teams($ctx);
        if ($teams !== null) $w[] = '(t.team_id IN (' . wk_ints($teams) . ') OR t.assignee_id = ' . $uid . ')';
        if ($box === 'assigned') { $w[] = 't.assignee_id = ?'; $args[] = $uid; }
        if ($box === 'unassigned') $w[] = 't.assignee_id IS NULL';
        if ($box === 'base') $w[] = "t.origin = 'base'";
    }
    $view = ($in['view'] ?? '') === 'board' ? 'board' : 'list';   // la vista Tabla pide como la Lista
    $late = "(t.status IN ('abierto', 'en_curso') AND t.paused_at IS NULL AND (t.resolution_due < NOW() OR (t.first_response_at IS NULL AND t.first_response_due < NOW())))";
    $st = (string)($in['status'] ?? 'abiertos');
    if ($view === 'board') $w[] = "(t.status IN ('abierto', 'en_curso', 'esperando') OR (t.status = 'resuelto' AND t.resolved_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)))";
    elseif ($st === 'abiertos') $w[] = "t.status IN ('abierto', 'en_curso', 'esperando')";
    elseif ($st === 'vencidos') $w[] = $late;
    elseif (isset(wk_st('tickets')[$st])) { $w[] = 't.status = ?'; $args[] = $st; }
    if (!empty($in['category'])) { $w[] = 't.category_id = ?'; $args[] = (int)$in['category']; }
    if (!empty($in['priority'])) { $w[] = 't.priority = ?'; $args[] = (string)$in['priority']; }
    if (!empty($in['team'])) { $w[] = 't.team_id = ?'; $args[] = (int)$in['team']; }
    // Ingreso: hoy, últimos 7 o 30 días (fechas de PHP, como los plazos)
    $since = (int)($in['since'] ?? 0);
    if (in_array($since, [1, 7, 30], true)) { $w[] = 't.created_at >= ?'; $args[] = $since === 1 ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', time() - $since * 86400); }
    // Solo destacados (la estrella se guarda en el navegador de cada persona)
    if (isset($in['ids']) && is_array($in['ids'])) { $w[] = 't.id IN (' . wk_ints(array_slice($in['ids'], 0, 300)) . ')'; }
    $asg = (string)($in['assignee'] ?? '');
    if ($asg === 'none') $w[] = 't.assignee_id IS NULL'; elseif ((int)$asg) { $w[] = 't.assignee_id = ?'; $args[] = (int)$asg; }
    $org = (string)($in['origin'] ?? '');
    // Origen: web (Comercial › Demo) y sitio (Soporte de agencias) son tickets del base; el resto, la columna origin
    if ($org === 'web' || $org === 'sitio') { $w[] = "t.origin = 'base' AND t.base_info LIKE ?"; $args[] = '%"source":"' . $org . '"%'; }
    elseif (isset(WK_TK_ORIGINS[$org])) { $w[] = 't.origin = ?'; $args[] = $org; }
    // Buscador: T-0012 / 12 busca el número; si no, asunto, descripción y (en los del base) contacto o agencia
    $qq = wk_str($in['q'] ?? '', 100);
    if ($qq !== '') {
        if (preg_match('/^(?:t-?)?0*(\d{1,9})$/i', $qq, $m)) { $w[] = '(t.id = ? OR t.subject LIKE ?)'; $args[] = (int)$m[1]; $args[] = '%' . $qq . '%'; }
        else { $like = '%' . addcslashes($qq, '%_\\') . '%'; $w[] = '(t.subject LIKE ? OR t.description LIKE ?' . ($wb !== null ? ' OR t.base_info LIKE ?' : '') . ')'; array_push($args, $like, $like); if ($wb !== null) $args[] = $like; }
    }
    $prioIds = array_column($cfg['priorities'], 'id');
    $sorts = [
        'due' => "FIELD(t.status, 'abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'), t.resolution_due IS NULL, t.resolution_due, t.id DESC",
        'prio' => 'FIELD(t.priority, ' . implode(',', array_map([$db, 'quote'], array_reverse($prioIds))) . '), t.resolution_due IS NULL, t.resolution_due, t.id DESC',
        'updated' => 't.updated_at DESC, t.id DESC', 'created' => 't.created_at DESC, t.id DESC',
    ];
    $sort = isset($sorts[$in['sort'] ?? '']) ? $in['sort'] : 'due';
    $order = $sorts[$sort];
    // Vista Tabla: ordenar por cualquier columna, en los dos sentidos (los vacíos siempre al final)
    $cols = ['id' => 't.id', 'subject' => 't.subject', 'status' => "FIELD(t.status, 'abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado')",
        'prio' => 'FIELD(t.priority, ' . implode(',', array_map([$db, 'quote'], $prioIds)) . ')', 'due' => 't.resolution_due', 'first' => 't.first_response_due',
        'created' => 't.created_at', 'updated' => 't.updated_at', 'category' => 'c.name', 'team' => 'tm.name', 'assignee' => "COALESCE(NULLIF(ua.display_name, ''), ua.username)"];
    if (isset($in['dir'], $cols[$in['sort'] ?? '']) && in_array($in['dir'], ['asc', 'desc'], true)) { $e = $cols[$in['sort']]; $sort = $in['sort']; $order = "$e IS NULL, $e " . strtoupper($in['dir']) . ', t.id DESC'; }
    $per = $view === 'board' ? 300 : (in_array((int)($in['per'] ?? 0), [25, 50, 100], true) ? (int)$in['per'] : 50);
    $page = max(1, (int)($in['page'] ?? 1));
    $where = implode(' AND ', $w);
    $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets t WHERE $where"); $q->execute($args); $total = (int)$q->fetchColumn();
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $q = $db->prepare('SELECT t.id, t.subject, t.status, t.priority, t.category_id, t.team_id, t.requester_id, t.assignee_id, t.first_response_due, t.resolution_due, t.first_response_at, t.resolved_at,
            t.escalated, t.created_at, t.updated_at, t.origin, t.paused_at, t.task_id, c.name AS cat_name, c.color AS cat_color, tm.name AS team_name' . ($wb !== null ? ', t.base_id, t.base_info' : '') . "
        FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id LEFT JOIN g7_teams tm ON tm.id = t.team_id LEFT JOIN g7_users ua ON ua.id = t.assignee_id WHERE $where
        ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $q->execute($args);
    // Contadores de la bandeja del agente
    $cnt = null;
    if ($agentAny) {
        $tw = $ctx['all'] ? '1' : '(t.team_id IN (' . wk_ints($ctx['teams']) . ') OR t.assignee_id = ' . $uid . ')';
        $cnt = $db->query("SELECT SUM(t.status IN ('abierto','en_curso','esperando')) AS open, SUM(t.status IN ('abierto','en_curso','esperando') AND t.assignee_id IS NULL) AS unassigned,
            SUM(t.status IN ('abierto','en_curso','esperando') AND t.assignee_id = $uid) AS mine,
            SUM($late) AS breached, SUM(t.status IN ('abierto','en_curso','esperando') AND t.origin = 'base') AS base,
            SUM(t.resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS solved30,
            SUM(t.resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND t.resolved_at <= t.resolution_due) AS ontime30,
            AVG(CASE WHEN t.first_response_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END) AS frt
            FROM g7_wk_tickets t WHERE $tw")->fetch(PDO::FETCH_ASSOC);
        $cnt = array_map(fn($v) => $v === null ? null : (int)round((float)$v), $cnt);
    }
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {   // los del sitio base: de qué agencia o contacto vinieron, en vez de un usuario de Toolz
        $r['ext_from'] = null; $r['src'] = $r['origin'];
        if (!empty($r['base_info'])) { $info = json_decode((string)$r['base_info'], true) ?: []; $r['ext_from'] = wb_from($info); $r['src'] = ($info['source'] ?? '') === 'web' ? 'web' : 'sitio'; }
        $r['base_id'] = !empty($r['base_id']) ? (int)$r['base_id'] : null;
        unset($r['base_info']);
    }
    unset($r);
    // Los reportes del juego: el jugador y el juego, en vez de un usuario de Toolz (juegos/reportes_lib.php)
    $jrIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['origin'] === 'juego'));
    if ($jrIds) { require_once dirname(__DIR__) . '/juegos/reportes_lib.php'; jr_install($db); $jrFrom = jr_from_many($db, $jrIds); foreach ($rows as &$r) if (isset($jrFrom[(int)$r['id']])) $r['ext_from'] = $jrFrom[(int)$r['id']]; unset($r); }
    wk_out(['tickets' => array_map(fn($r) => ['id' => (int)$r['id'], 'requester_id' => (int)$r['requester_id'], 'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'team_id' => $r['team_id'] ? (int)$r['team_id'] : null,
                'task_id' => $r['task_id'] ? (int)$r['task_id'] : null, 'escalated' => (int)$r['escalated'], 'paused' => (bool)$r['paused_at']] + $r, $rows),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'view' => $view, 'box' => $box,
            'counts' => $cnt, 'now' => tk_now(), 'base' => $wb !== null && $agentAny ? wb_status($db) + ['sync' => $wb] : null]);

case 'ticket_get':
    wk_sla_sweep($db);
    $wb = wb_enabled();
    if ($wb) wb_sync($db);
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    wk_tk_mirror($db, $t['id']);   // por si algo entró sin pasar por la tarea
    if ($wb && !empty($t['base_info'])) {
        // Origen en el base y estado de lo que se mandó desde acá (pendiente o rechazado)
        $q = $db->prepare('SELECT m.id, m.user_id, m.body, m.internal, m.kind, m.created_at, m.ext_name, (o.id IS NOT NULL AND o.sent_at IS NULL AND o.dead = 0) AS pending, COALESCE(o.dead, 0) AS failed
            FROM g7_wk_ticket_msgs m LEFT JOIN g7_wk_base_out o ON o.op_key = m.base_rid WHERE m.ticket_id = ?' . ($t['agent'] ? '' : ' AND m.internal = 0') . ' ORDER BY m.id');
        $t['base'] = json_decode((string)$t['base_info'], true) ?: [];
        $t['base']['from'] = wb_from($t['base']);
        $t['base']['detached'] = empty($t['base_id']);   // el base empezó de cero: queda la historia, ya no se sincroniza
        $q2 = $db->prepare('SELECT COUNT(*) FROM g7_wk_base_out WHERE ticket_id = ? AND sent_at IS NULL AND dead = 0'); $q2->execute([$t['id']]);
        $t['base']['pending'] = (int)$q2->fetchColumn();
        $t['base']['sync'] = wb_status($db);
    } else $q = $db->prepare('SELECT id, user_id, body, internal, kind, created_at, ext_name FROM g7_wk_ticket_msgs WHERE ticket_id = ?' . ($t['agent'] ? '' : ' AND internal = 0') . ' ORDER BY id');
    unset($t['base_info']);
    // Reporte del juego: jugador, tipo y datos del juego (versión, plataforma…)
    $t['game'] = null;
    if ($t['origin'] === 'juego') { require_once dirname(__DIR__) . '/juegos/reportes_lib.php'; jr_install($db); $t['game'] = jr_ticket_card($db, $t['id']); }
    $q->execute([$t['id']]);
    $t['msgs'] = array_map(fn($m) => ['id' => (int)$m['id'], 'user_id' => $m['user_id'] ? (int)$m['user_id'] : null, 'internal' => (int)$m['internal']] + (isset($m['pending']) ? ['pending' => (int)$m['pending'], 'failed' => (int)$m['failed']] : []) + $m, $q->fetchAll(PDO::FETCH_ASSOC));
    $t['agents'] = $t['agent'] ? wk_team_people($db, $t['team_id']) : [];
    $t['paused'] = (bool)$t['paused_at'];
    $t['origin_name'] = WK_TK_ORIGINS[$t['origin']] ?? $t['origin'];
    // Su tarea (es el mismo elemento): tablero, estado, responsables y fecha
    $t['task'] = null;
    if ($t['task_id'] && ($x = wk_task_row($db, $t['task_id']))) {
        $b = wk_board($db, (int)($x['board_id'] ?: wk_general_id($db)));
        $sts = wk_statuses($db, $b, $cfg); $sn = array_column($sts, null, 'id')[$x['status']] ?? ['name' => $x['status'], 'color' => '#8b8b94'];
        $t['task'] = ['id' => (int)$x['id'], 'board_id' => (int)$b['id'], 'board_name' => $b['name'], 'status_name' => $sn['name'], 'status_color' => $sn['color'] ?? '#8b8b94',
            'assignees' => $x['assignees'], 'due_date' => $x['due_date'], 'done' => (bool)$x['completed_at'], 'visible' => $ctx['all'] || wk_task_visible($db, $ctx, (int)$x['id'])];
    }
    if ($t['form_id']) { $q3 = $db->prepare('SELECT fields FROM g7_wk_forms WHERE id = ?'); $q3->execute([$t['form_id']]); $t['form_fields'] = json_decode((string)$q3->fetchColumn(), true) ?: []; $t['answers'] = json_decode((string)$t['answers'], true) ?: []; }
    if (!empty($t['form_version_id'])) {   // pedido del Portal de Solicitudes: las respuestas con la estructura de su versión, ya en texto
        $q3 = $db->prepare('SELECT schema_json FROM g7_wk_form_versions WHERE id = ?'); $q3->execute([(int)$t['form_version_id']]);
        $sch = pt_clean_schema(json_decode((string)$q3->fetchColumn(), true) ?: []); $raw = $t['answers']; $t['form_fields'] = []; $t['answers'] = [];
        $catName = function (int $id) use ($db) { $q = $db->prepare('SELECT name FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); return (string)$q->fetchColumn(); };
        foreach (pt_items($sch) as $it) {
            if (!array_key_exists($it['id'], $raw) || ($it['type'] === 'hidden' && !$t['agent'])) continue;
            $txt = pt_answer_text($it, $raw[$it['id']], fn($u) => wk_user_name($db, $u), $catName); if ($txt === '') continue;
            $t['form_fields'][] = ['id' => $it['id'], 'label' => $it['label'] ?: PT_TYPES[$it['type']][0], 'type' => $it['type'] === 'url' ? 'url' : 'text'];
            $t['answers'][$it['id']] = $txt;
        }
        $t['portal'] = true;
    }
    $boards = $t['agent'] ? array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT b.id, b.name FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.name')->fetchAll(PDO::FETCH_ASSOC)) : [];
    try { require_once __DIR__ . '/tk_links_lib.php'; $t['links'] = wk_tk_links($db, $t['id'], $uid); } catch (Throwable $e) { $t['links'] = []; }   // clientes y contenidos vinculados
    // Colaboradores: quién está y si esta persona puede sumar o quitar (quien atiende y quien lo pidió)
    $t['collabs'] = wk_tkc_ids($db, $t['id']);
    $t['can_collab'] = $t['agent'] || (int)$t['requester_id'] === $uid;
    wk_out(['ticket' => $t, 'status' => wk_st('tickets'), 'now' => tk_now(), 'boards' => $boards]);

case 'ticket_msg':
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $body = wk_str($in['body'] ?? '', 20000); if ($body === '') wk_fail('Escribe algo');
    $internal = $t['agent'] && !empty($in['internal']);
    // Mismo camino que un comentario de la tarea: primera respuesta, estado y, si vino del base, la respuesta le llega a la agencia
    [$mid, $up] = wk_tk_post($db, $t, $uid, $body, $internal, $t['agent'], null, wk_me_name($me));
    wk_tk_mirror($db, $t['id']);   // queda también en los comentarios de su tarea
    if (isset($up['status'])) wk_task_from_tk($db, $t['id'], $uid);
    $t = wk_tk_row($db, $t['id']);
    // Menciones (@usuario): aviso propio; en una respuesta, quien no tenía acceso queda como colaborador
    $men = wk_tkc_mentions($db, $t, $uid, $body, $internal);
    $to = array_values(array_diff(wk_tk_people($db, $t, !$internal), $men['notified']));
    // Una nota interna no le llega a quien no la puede ver (quien lo pidió y los colaboradores que no lo atienden)
    if ($internal) $to = array_values(array_filter(array_diff($to, [(int)$t['requester_id']]), fn($u) => wk_tkc_agent($db, $t, (int)$u)));
    wk_tk_notify($db, 'tk_reply', $to, $t, $uid, ($internal ? 'Nota interna: ' : '') . mb_substr($body, 0, 200));
    wk_out(['ok' => true, 'mentioned' => count($men['notified']), 'skipped' => $men['skipped']]);

case 'ticket_collab':   // sumar o quitar colaboradores (quien atiende o quien lo pidió; cada uno puede salirse)
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $who = (int)($in['user_id'] ?? 0); $op = (string)($in['op'] ?? 'add');
    $can = $t['agent'] || (int)$t['requester_id'] === $uid;
    if ($op === 'remove') {
        if (!$can && $who !== $uid) wk_fail('Solo quien atiende el ticket o quien lo pidió quita colaboradores', 403);
        wk_tkc_remove($db, $t, $who, $uid);
    } else {
        if (!$can) wk_fail('Solo quien atiende el ticket o quien lo pidió suma colaboradores', 403);
        try { if (!wk_tkc_add($db, $t, $who, $uid)) wk_fail('Esa persona ya participa en el ticket'); } catch (RuntimeException $e) { wk_fail($e->getMessage()); }
    }
    wk_out(['ok' => true, 'collabs' => wk_tkc_ids($db, $t['id'])]);

case 'ticket_update':
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    $isReq = $uid === $t['requester_id'];
    $f = []; $ev = []; $now = tk_now(); $notifyAsg = null;
    if (isset($in['status']) && $in['status'] !== $t['status']) {
        $s = (string)$in['status'];
        if (!isset(wk_st('tickets')[$s])) wk_fail('Estado no válido');
        // Quien lo abrió solo puede darlo por resuelto/cerrado o reabrirlo
        if (!$t['agent'] && !($isReq && in_array($s, ['cerrado', 'en_curso'], true))) wk_fail('No puedes cambiar el estado de este ticket', 403);
        $f += wk_tk_status_fields($t, $s, $now);   // en "Esperando" el reloj del SLA se frena
        $ev[] = 'pasó a ' . wk_st('tickets')[$s][0];
    }
    if ($t['agent'] && array_key_exists('assignee_id', $in)) {
        $a = (int)$in['assignee_id'] ?: null;
        if ($a && $t['team_id'] && !in_array($a, wk_team_people($db, $t['team_id']), true) && !$ctx['all']) wk_fail('Solo se asigna a gente del departamento');
        if ($a !== $t['assignee_id']) { $f['assignee_id'] = $a; $ev[] = $a ? 'lo asignó a ' . wk_user_name($db, $a) : 'le sacó el responsable'; $notifyAsg = $a; }
    }
    if ($t['agent'] && isset($in['priority']) && $in['priority'] !== $t['priority'] && in_array($in['priority'], array_column($cfg['priorities'], 'id'), true)) {
        // Cambiar la prioridad recalcula los plazos desde que se abrió (más el tiempo en pausa); los de una tarea siguen su fecha
        $f['priority'] = $in['priority'];
        if ($t['origin'] !== 'tarea' && ($d = wk_tk_dues($db, $t, $in['priority']))) { $f += $d; $f['breach_flags'] = 0; }
        $ev[] = 'cambió la prioridad' . ($t['origin'] !== 'tarea' ? ' (plazos recalculados)' : '');
    }
    if ($t['agent'] && !empty($in['escalate'])) {
        $f['escalated'] = $t['escalated'] + 1; $f['escalated_at'] = $now;
        $ev[] = 'lo escaló (nivel ' . $f['escalated'] . ')' . (($n = wk_str($in['note'] ?? '', 500)) !== '' ? ': "' . $n . '"' : '');
        wk_tk_notify($db, 'tk_status', wk_team_people($db, $t['team_id'], true), $t, $uid, 'Ticket escalado' . ($n !== '' ? ': ' . $n : ''));
    }
    if (!$f) wk_out(['ok' => true]);
    wk_tk_set($db, $t['id'], $f);
    $txt = implode(' · ', $ev);
    wk_tk_log($db, $t['id'], $uid, $txt, false);
    if (isset($f['status']) && !empty($t['base_id'])) wb_after($db, $t, null, null, $f['status'], wk_me_name($me));   // el estado (y el cierre) vuelve al base
    wk_task_from_tk($db, $t['id'], $uid, array_key_exists('assignee_id', $f) ? $t['assignee_id'] : null);   // su tarea se pone al día
    $t2 = wk_tk_row($db, $t['id']);
    if ($notifyAsg) wk_tk_notify($db, 'assigned', [$notifyAsg], $t2, $uid, 'Te asignaron el ticket.');
    wk_tk_notify($db, 'tk_status', array_diff(wk_tk_people($db, $t2), [$notifyAsg]), $t2, $uid, $txt);
    wk_out(['ok' => true]);

case 'ticket_task':
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['agent']) wk_fail('Solo quien atiende el ticket puede crearle la tarea', 403);
    if ($t['task_id'] && wk_task_row($db, $t['task_id'])) wk_fail('Este ticket ya tiene una tarea');
    $tid = wk_tk_make_task($db, $t['id'], $uid);
    wk_tk_log($db, $t['id'], $uid, 'creó la tarea del ticket', true);
    wk_out(['task_id' => $tid, 'board_id' => (int)(wk_task_row($db, $tid)['board_id'] ?? 0)]);

case 'ticket_base_sync':   // "Sincronizar ahora" (Trabajo › Tickets, en el Toolz conectado al base)
    if (!($ctx['all'] || $ctx['teams'])) wk_fail('Solo quien atiende tickets puede sincronizar', 403);
    $r = wb_sync($db, true);
    if (!$r['ok']) wk_fail($r['error'] ?: 'No se pudo sincronizar con el sitio base', 502);
    wk_out($r + ['status' => wb_status($db)]);

case 'ticket_base_file':   // captura adjunta en el base, para verla desde el ticket
    $t = wk_ticket($db, $ctx, (int)($in['id'] ?? 0));
    if (!$t['agent'] || empty($t['base_id']) || !wb_enabled()) wk_fail('Ese archivo no existe', 404);
    $f = (string)($in['file'] ?? '');
    if (!in_array($f, (array)((json_decode((string)$t['base_info'], true) ?: [])['files'] ?? []), true)) wk_fail('Ese archivo no existe', 404);
    [$code, $body] = bc_call(['act' => 'tk_file', 'file' => $f], wk_me_name($me));
    $d = json_decode($body, true);
    if ($code !== 200 || empty($d['data'])) wk_fail((string)($d['error'] ?? 'El sitio base no devolvió la captura'), 502);
    if (!in_array($d['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) wk_fail('Ese archivo no es una imagen', 502);
    wk_out(['mime' => $d['mime'], 'data' => $d['data']]);

// ── Automatizaciones (fase 5) ────────────────────────────────
case 'autos':
    $boards = wk_auto_boards($db, $ctx);
    $ids = array_column($boards, 'id');
    $w = $ctx['role'] === 'admin' ? '1' : ($ids ? 'a.board_id IN (' . wk_ints($ids) . ')' : '0');
    $rows = $db->query("SELECT a.*, b.name AS board_name FROM g7_wk_automations a LEFT JOIN g7_wk_boards b ON b.id = a.board_id WHERE $w ORDER BY a.board_id IS NOT NULL, b.name, a.name")->fetchAll(PDO::FETCH_ASSOC);
    $autos = array_map(fn($a) => ['id' => (int)$a['id'], 'board_id' => $a['board_id'] ? (int)$a['board_id'] : null, 'active' => (int)$a['active'], 'runs' => (int)$a['runs'],
        'conditions' => json_decode($a['conditions'] ?: '[]', true) ?: [], 'actions' => json_decode($a['actions'] ?: '[]', true) ?: []] + $a, $rows);
    $bst = []; foreach ($boards as $b) $bst[$b['id']] = wk_statuses($db, $b, $cfg);
    wk_out(['autos' => $autos, 'boards' => array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name']], $boards), 'board_statuses' => (object)$bst,
        'global_statuses' => $cfg['statuses'], 'priorities' => $cfg['priorities'], 'labels' => $cfg['labels'],
        'triggers' => WK_TRIGGERS, 'cond_fields' => WK_COND_FIELDS, 'actions' => WK_ACTIONS, 'admin' => $ctx['role'] === 'admin',
        'all_boards' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $db->query('SELECT b.id, b.name FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.name')->fetchAll(PDO::FETCH_ASSOC))]);

case 'auto_save':
case 'auto_del':
case 'auto_toggle':
    $id = (int)($in['id'] ?? 0);
    $old = null;
    if ($id) { $q = $db->prepare('SELECT * FROM g7_wk_automations WHERE id = ?'); $q->execute([$id]); $old = $q->fetch(PDO::FETCH_ASSOC); if (!$old) wk_fail('Esa automatización no existe', 404);
        if (!wk_can_manage_auto($db, $ctx, $old['board_id'] ? (int)$old['board_id'] : null)) wk_fail('No puedes cambiar esta automatización', 403); }
    if ($act === 'auto_del') { $db->prepare('DELETE FROM g7_wk_automations WHERE id = ?')->execute([$id]); g7_audit('work_auto_delete', 'automatización #' . $id, ['name' => $old['name']], $uid); wk_out(['ok' => true]); }
    if ($act === 'auto_toggle') { $db->prepare('UPDATE g7_wk_automations SET active = ?, updated_at = ? WHERE id = ?')->execute([!empty($in['on']) ? 1 : 0, tk_now(), $id]); g7_audit('work_auto_toggle', 'automatización #' . $id, ['on' => !empty($in['on'])], $uid); wk_out(['ok' => true]); }
    $board = (int)($in['board_id'] ?? 0) ?: null;
    if (!wk_can_manage_auto($db, $ctx, $board)) wk_fail($board ? 'No administras ese tablero' : 'Solo los administradores arman reglas para todos los tableros', 403);
    $name = wk_str($in['name'] ?? '', 120); if ($name === '') wk_fail('Pon un nombre a la regla');
    $trig = (string)($in['trigger_key'] ?? ''); if (!isset(WK_TRIGGERS[$trig])) wk_fail('Elige cuándo se dispara');
    $opt = wk_str($in['trigger_opt'] ?? '', 80);
    if ($trig === 'due_soon') $opt = (string)max(0, min(30, (int)$opt ?: 1));
    elseif (!in_array($trig, ['status', 'priority'], true)) $opt = '';
    $conds = [];
    foreach (array_slice((array)($in['conditions'] ?? []), 0, 10) as $c) {
        if (!isset(WK_COND_FIELDS[$c['field'] ?? ''])) continue;
        $conds[] = ['field' => $c['field'], 'op' => ($c['op'] ?? '') === 'is_not' ? 'is_not' : 'is', 'value' => wk_str($c['value'] ?? '', 120)];
    }
    $acts = [];
    foreach (array_slice((array)($in['actions'] ?? []), 0, 10) as $x) {
        if (!isset(WK_ACTIONS[$x['type'] ?? ''])) continue;
        $to = (string)($x['to'] ?? '');
        $acts[] = ['type' => $x['type'], 'value' => wk_str($x['value'] ?? '', 1000), 'to' => preg_match('/^(assignees|creator|leads|user:\d+)$/', $to) ? $to : 'assignees'];
    }
    if (!$acts) wk_fail('Agrega al menos una acción');
    $vals = [$board, $name, $trig, $opt, json_encode($conds, JSON_UNESCAPED_UNICODE), json_encode($acts, JSON_UNESCAPED_UNICODE), !empty($in['active']) ? 1 : 0, tk_now()];
    if ($id) $db->prepare('UPDATE g7_wk_automations SET board_id = ?, name = ?, trigger_key = ?, trigger_opt = ?, conditions = ?, actions = ?, active = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_wk_automations (board_id, name, trigger_key, trigger_opt, conditions, actions, active, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, tk_now()]); $id = (int)$db->lastInsertId(); }
    g7_audit('work_auto_save', 'automatización #' . $id, ['name' => $name, 'trigger' => $trig], $uid);
    wk_out(['id' => $id]);

case 'auto_log':
    $boards = array_column(wk_auto_boards($db, $ctx), 'id');
    $w = $ctx['role'] === 'admin' ? '1' : ($boards ? 'a.board_id IN (' . wk_ints($boards) . ')' : '0');
    $args = [];
    if (!empty($in['id'])) { $w .= ' AND l.automation_id = ?'; $args[] = (int)$in['id']; }
    $q = $db->prepare("SELECT l.id, l.automation_id, l.task_id, l.trigger_key, l.ok, l.detail, l.created_at, a.name, t.title, COALESCE(t.board_id, " . wk_general_id($db) . ") AS board_id
        FROM g7_wk_auto_log l JOIN g7_wk_automations a ON a.id = l.automation_id LEFT JOIN g7_tasks t ON t.id = l.task_id WHERE $w ORDER BY l.id DESC LIMIT 200");
    $q->execute($args);
    wk_out(['log' => array_map(fn($r) => ['id' => (int)$r['id'], 'automation_id' => (int)$r['automation_id'], 'task_id' => $r['task_id'] ? (int)$r['task_id'] : null, 'ok' => (int)$r['ok'], 'board_id' => (int)$r['board_id']] + $r, $q->fetchAll(PDO::FETCH_ASSOC))]);

// ── Procesos (fase 5) ────────────────────────────────────────
case 'processes':
    $rows = $db->query('SELECT p.*, t.name AS team_name, (SELECT COUNT(*) FROM g7_wk_runs r WHERE r.process_id = p.id AND r.status = \'running\') AS running FROM g7_wk_processes p LEFT JOIN g7_teams t ON t.id = p.team_id ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $p) {
        $tid = $p['team_id'] ? (int)$p['team_id'] : null;
        $canStart = $p['active'] && $ctx['role'] !== 'solicitante' && ($p['starters'] === 'all' || wk_is_agent($ctx, $tid));
        $manage = wk_can_manage_process($ctx, $tid);
        if (!$canStart && !$manage) continue;
        $out[] = ['id' => (int)$p['id'], 'team_id' => $tid, 'active' => (int)$p['active'], 'running' => (int)$p['running'], 'steps' => json_decode($p['steps'], true) ?: [], 'can_start' => $canStart, 'can_manage' => $manage] + $p;
    }
    // Mis aprobaciones pendientes y ejecuciones que puedo ver
    $q = $db->prepare("SELECT s.id, s.run_id, s.step_key, s.started_at, r.title, r.steps, p.name AS process_name FROM g7_wk_run_steps s JOIN g7_wk_runs r ON r.id = s.run_id AND r.status = 'running' JOIN g7_wk_processes p ON p.id = r.process_id
        WHERE s.status = 'active' AND s.task_id IS NULL AND (s.assignee_id = ?" . ($ctx['all'] ? ' OR s.assignee_id IS NULL' : ($ctx['lead'] ? ' OR (s.assignee_id IS NULL AND p.team_id IN (' . wk_ints($ctx['lead']) . '))' : '')) . ') ORDER BY s.started_at');
    $q->execute([$uid]);
    $pend = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) { foreach (json_decode($s['steps'], true) ?: [] as $st) if ($st['key'] === $s['step_key'] && $st['type'] === 'approval') $pend[] = ['id' => (int)$s['id'], 'run_id' => (int)$s['run_id'], 'step' => $st['name'], 'title' => $s['title'], 'process_name' => $s['process_name'], 'started_at' => $s['started_at']]; }
    $teams = wk_agent_teams($ctx);
    $vis = $ctx['all'] ? '1' : '(r.started_by = ' . $uid . ' OR EXISTS (SELECT 1 FROM g7_wk_run_steps s WHERE s.run_id = r.id AND s.assignee_id = ' . $uid . ')' . ($teams ? ' OR p.team_id IN (' . wk_ints($teams) . ')' : '') . ($ctx['lead'] ? ' OR p.team_id IN (' . wk_ints($ctx['lead']) . ')' : '') . ')';
    $runs = $db->query("SELECT r.id, r.title, r.status, r.started_by, r.created_at, r.updated_at, r.finished_at, p.name AS process_name, p.color,
            (SELECT COUNT(*) FROM g7_wk_run_steps s WHERE s.run_id = r.id AND s.status <> 'active') AS n_done
        FROM g7_wk_runs r JOIN g7_wk_processes p ON p.id = r.process_id WHERE $vis ORDER BY r.status = 'running' DESC, r.updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    wk_out(['processes' => $out, 'pending' => $pend, 'runs' => array_map(fn($r) => ['id' => (int)$r['id'], 'started_by' => (int)$r['started_by'], 'n_done' => (int)$r['n_done']] + $r, $runs),
            'can_create' => $ctx['all'] || (bool)$ctx['lead'], 'step_types' => WK_STEP_TYPES, 'lead' => $ctx['lead']]);

case 'process_save':
case 'process_del':
    $id = (int)($in['id'] ?? 0); $old = null;
    if ($id) { $q = $db->prepare('SELECT * FROM g7_wk_processes WHERE id = ?'); $q->execute([$id]); $old = $q->fetch(PDO::FETCH_ASSOC); if (!$old) wk_fail('Ese proceso no existe', 404);
        if (!wk_can_manage_process($ctx, $old['team_id'] ? (int)$old['team_id'] : null)) wk_fail('No puedes cambiar este proceso', 403); }
    if ($act === 'process_del') {
        $q = $db->prepare('SELECT COUNT(*) FROM g7_wk_runs WHERE process_id = ?'); $q->execute([$id]);
        if ((int)$q->fetchColumn()) { $db->prepare('UPDATE g7_wk_processes SET active = 0, updated_at = ? WHERE id = ?')->execute([tk_now(), $id]); wk_out(['ok' => true, 'msg' => 'Tiene ejecuciones: quedó desactivado para no perder el historial']); }
        $db->prepare('DELETE FROM g7_wk_processes WHERE id = ?')->execute([$id]); g7_audit('work_process_delete', 'proceso #' . $id, ['name' => $old['name']], $uid); wk_out(['ok' => true]);
    }
    $team = (int)($in['team_id'] ?? 0) ?: null;
    if (!wk_can_manage_process($ctx, $team)) wk_fail('Los procesos los arman los jefes del departamento o la administración', 403);
    $name = wk_str($in['name'] ?? '', 120); if ($name === '') wk_fail('Pon un nombre al proceso');
    $steps = wk_clean_steps((array)($in['steps'] ?? []));
    if (!$steps) wk_fail('Agrega al menos un paso');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower($in['color']) : '#ff6600';
    $vals = [$name, wk_str($in['description'] ?? '', 1000), $team, json_encode($steps, JSON_UNESCAPED_UNICODE), ($in['starters'] ?? '') === 'team' ? 'team' : 'all', !empty($in['active']) ? 1 : 0, $color, tk_now()];
    if ($id) $db->prepare('UPDATE g7_wk_processes SET name = ?, description = ?, team_id = ?, steps = ?, starters = ?, active = ?, color = ?, updated_at = ? WHERE id = ?')->execute([...$vals, $id]);
    else { $db->prepare('INSERT INTO g7_wk_processes (name, description, team_id, steps, starters, active, color, updated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, tk_now()]); $id = (int)$db->lastInsertId(); }
    g7_audit('work_process_save', 'proceso #' . $id, ['name' => $name, 'steps' => count($steps)], $uid);
    wk_out(['id' => $id]);

case 'process_start':
    $q = $db->prepare('SELECT * FROM g7_wk_processes WHERE id = ? AND active = 1'); $q->execute([(int)($in['process_id'] ?? 0)]); $p = $q->fetch(PDO::FETCH_ASSOC);
    if (!$p) wk_fail('Ese proceso no existe o está desactivado', 404);
    if ($ctx['role'] === 'solicitante' || ($p['starters'] === 'team' && !wk_is_agent($ctx, $p['team_id'] ? (int)$p['team_id'] : null))) wk_fail('No puedes iniciar este proceso', 403);
    $title = wk_str($in['title'] ?? '', 200); if ($title === '') wk_fail('Pon un nombre para esta vez (ej.: "Alta de Juan Pérez")');
    $steps = json_decode($p['steps'], true) ?: [];
    if (!$steps) wk_fail('El proceso no tiene pasos');
    $now = tk_now();
    // Se guarda una copia de los pasos: si después editan el proceso, las ejecuciones en curso no cambian
    $db->prepare('INSERT INTO g7_wk_runs (process_id, title, notes, steps, started_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$p['id'], $title, wk_str($in['notes'] ?? '', 5000), $p['steps'], $uid, $now, $now]);
    $rid = (int)$db->lastInsertId();
    wk_run_activate($db, $rid, $steps[0]['key']);
    g7_audit('work_process_start', 'proceso #' . $p['id'], ['run' => $rid, 'title' => $title], $uid);
    wk_out(['id' => $rid]);

case 'run_get':
    $r = wk_run($db, $ctx, (int)($in['id'] ?? 0));
    $g = wk_general_id($db);
    $q = $db->prepare("SELECT s.*, t.title AS task_title, t.status AS task_status, t.completed_at AS task_done, COALESCE(t.board_id, $g) AS task_board FROM g7_wk_run_steps s LEFT JOIN g7_tasks t ON t.id = s.task_id WHERE s.run_id = ? ORDER BY s.id");
    $q->execute([$r['id']]);
    $r['done_steps'] = array_map(fn($s) => ['id' => (int)$s['id'], 'assignee_id' => $s['assignee_id'] ? (int)$s['assignee_id'] : null, 'task_id' => $s['task_id'] ? (int)$s['task_id'] : null,
        'decided_by' => $s['decided_by'] ? (int)$s['decided_by'] : null, 'task_board' => (int)$s['task_board']] + $s, $q->fetchAll(PDO::FETCH_ASSOC));
    wk_out(['run' => $r, 'step_types' => WK_STEP_TYPES]);

case 'run_decide':
    $q = $db->prepare("SELECT * FROM g7_wk_run_steps WHERE id = ? AND status = 'active'"); $q->execute([(int)($in['step_id'] ?? 0)]); $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s) wk_fail('Ese paso ya no está pendiente', 404);
    $r = wk_run($db, $ctx, (int)$s['run_id']);
    $def = null; foreach ($r['steps'] as $x) if ($x['key'] === $s['step_key']) $def = $x;
    if (!$def || $def['type'] !== 'approval') wk_fail('Ese paso no es una aprobación');
    // Decide la persona asignada; si no hay, los jefes del departamento o la administración
    $can = $s['assignee_id'] ? (int)$s['assignee_id'] === $uid : wk_can_manage_process($ctx, $r['team_id']);
    if (!$can && !$ctx['all']) wk_fail('Esta aprobación le toca a otra persona', 403);
    $ok = !empty($in['ok']);
    wk_run_step_close($db, ['id' => (int)$s['id'], 'run_id' => (int)$s['run_id'], 'step_key' => $s['step_key']], $ok ? 'approved' : 'rejected', $uid, wk_str($in['note'] ?? '', 1000));
    wk_notify($db, 'wk_run', [$r['started_by']], $r['id'], $uid, ($ok ? 'Aprobado: ' : 'Rechazado: ') . $def['name'] . ' · ' . $r['title']);
    wk_out(['ok' => true]);

case 'run_cancel':
    $r = wk_run($db, $ctx, (int)($in['id'] ?? 0));
    if (!$r['manage']) wk_fail('Solo quien lo inició o quien administra el proceso puede cancelarlo', 403);
    if ($r['status'] !== 'running') wk_fail('Ya terminó');
    $now = tk_now();
    $db->prepare("UPDATE g7_wk_run_steps SET status = 'skipped', done_at = ? WHERE run_id = ? AND status = 'active'")->execute([$now, $r['id']]);
    $db->prepare("UPDATE g7_wk_runs SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $r['id']]);
    g7_audit('work_run_cancel', 'ejecución #' . $r['id'], ['title' => $r['title']], $uid);
    wk_out(['ok' => true]);

// ── Informes (fase 7) ────────────────────────────────────────
case 'reports':
    // Alcance: admin/director todo; jefe su gente y sus departamentos; el resto, lo propio
    $from = wk_date($in['from'] ?? '') ?: date('Y-m-01'); $to = wk_date($in['to'] ?? '') ?: date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) > 731 * 86400) wk_fail('Elige como mucho dos años');
    $f0 = $from . ' 00:00:00'; $f1 = $to . ' 23:59:59';
    $people = wk_people_scope($db, $ctx);
    if ($people !== null && !$people) $people = [$uid];
    elseif ($people !== null && !in_array($uid, $people, true)) $people[] = $uid;
    $team = (int)($in['team'] ?? 0);
    if ($team) {
        if ($ctx['all'] || in_array($team, $ctx['lead'], true)) { $q = $db->prepare('SELECT user_id FROM g7_team_members WHERE team_id = ?'); $q->execute([$team]); $people = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) ?: [0]; }
        else wk_fail('No puedes ver los informes de ese departamento', 403);
    }
    $g = wk_general_id($db);
    $board = (int)($in['board'] ?? 0);
    if ($board && !wk_board_visible($db, $ctx, $board)) wk_fail('Ese tablero no existe', 404);
    $pw = $people === null ? '1' : 'a.user_id IN (' . wk_ints($people) . ')';
    $bw = $board ? " AND COALESCE(t.board_id, $g) = $board" : '';
    $vis = wk_task_where($db, $ctx);
    $today = date('Y-m-d');
    // 1) Por persona
    $q = $db->prepare("SELECT a.user_id,
            SUM(t.completed_at BETWEEN ? AND ?) AS done,
            SUM(t.completed_at BETWEEN ? AND ? AND t.due_date IS NOT NULL) AS done_due,
            SUM(t.completed_at BETWEEN ? AND ? AND t.due_date IS NOT NULL AND DATE(t.completed_at) <= t.due_date) AS ontime,
            AVG(CASE WHEN t.completed_at BETWEEN ? AND ? THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) END) AS lead_h,
            SUM(t.completed_at IS NULL) AS open, SUM(t.completed_at IS NULL AND t.due_date < ?) AS late
        FROM g7_task_assignees a JOIN g7_tasks t ON t.id = a.task_id AND t.archived = 0 AND t.parent_id IS NULL$bw JOIN g7_users u ON u.id = a.user_id AND u.active = 1
        WHERE $pw GROUP BY a.user_id");
    $q->execute([$f0, $f1, $f0, $f1, $f0, $f1, $f0, $f1, $today]);
    $byPerson = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byPerson[(int)$r['user_id']] = ['user_id' => (int)$r['user_id'], 'done' => (int)$r['done'], 'done_due' => (int)$r['done_due'], 'ontime' => (int)$r['ontime'], 'lead_h' => $r['lead_h'] === null ? null : (int)round($r['lead_h']), 'open' => (int)$r['open'], 'late' => (int)$r['late'], 'minutes' => 0];
    $q = $db->prepare("SELECT m.user_id, SUM(m.minutes) AS mi FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id$bw WHERE m.ended_at IS NOT NULL AND m.started_at BETWEEN ? AND ?" . ($people === null ? '' : ' AND m.user_id IN (' . wk_ints($people) . ')') . ' GROUP BY m.user_id');
    $q->execute([$f0, $f1]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $u = (int)$r['user_id']; $byPerson[$u] = ($byPerson[$u] ?? ['user_id' => $u, 'done' => 0, 'done_due' => 0, 'ontime' => 0, 'lead_h' => null, 'open' => 0, 'late' => 0]); $byPerson[$u]['minutes'] = (int)$r['mi']; }
    $byPerson = array_values($byPerson); usort($byPerson, fn($a, $b) => [$b['done'], $b['minutes']] <=> [$a['done'], $a['minutes']]);
    // 2) Por tablero (los que ve)
    $q = $db->prepare("SELECT COALESCE(t.board_id, $g) AS b, SUM(t.created_at BETWEEN ? AND ?) AS created, SUM(t.completed_at BETWEEN ? AND ?) AS done,
            SUM(t.completed_at IS NULL) AS open, SUM(t.completed_at IS NULL AND t.due_date < ?) AS late,
            AVG(CASE WHEN t.completed_at BETWEEN ? AND ? THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) END) AS lead_h
        FROM g7_tasks t WHERE t.archived = 0 AND t.parent_id IS NULL AND $vis$bw" . ($people === null ? '' : ' AND (EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND ' . $pw . ') OR t.created_by IN (' . wk_ints($people) . '))') . " GROUP BY COALESCE(t.board_id, $g)");
    $q->execute([$f0, $f1, $f0, $f1, $today, $f0, $f1]);
    $names = array_column($db->query('SELECT id, name FROM g7_wk_boards')->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
    $byBoard = array_map(fn($r) => ['board_id' => (int)$r['b'], 'name' => $names[$r['b']] ?? '#' . $r['b'], 'created' => (int)$r['created'], 'done' => (int)$r['done'], 'open' => (int)$r['open'], 'late' => (int)$r['late'], 'lead_h' => $r['lead_h'] === null ? null : (int)round($r['lead_h'])], $q->fetchAll(PDO::FETCH_ASSOC));
    usort($byBoard, fn($a, $b) => $b['open'] + $b['done'] <=> $a['open'] + $a['done']);
    // 3) Proyectos que ve (activos o cerrados en el período)
    $rows = $db->query("SELECT p.* FROM g7_wk_projects p WHERE p.archived = 0 AND " . wk_project_where($ctx) . ($team ? ' AND p.team_id = ' . $team : '') . " AND (p.status IN ('planificado', 'en_curso', 'en_pausa') OR p.closed_at >= '$f0') ORDER BY p.due_date IS NULL, p.due_date")->fetchAll(PDO::FETCH_ASSOC);
    $st = wk_project_stats($db, array_map(fn($r) => (int)$r['id'], $rows));
    $byProject = [];
    foreach ($rows as $r) { $p = wk_project($db, (int)$r['id']); $s = $st[$p['id']]; $byProject[] = ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status'], 'due_date' => $p['due_date'], 'owner_id' => $p['owner_id'], 'progress' => $s['progress'], 'done' => $s['done'], 'total' => $s['total'], 'late' => $s['late'], 'minutes' => $s['minutes'], 'budget' => $p['budget_min'], 'health' => wk_project_health($p, $s)['label']]; }
    // 4) Tickets por categoría (los del alcance de quien mira)
    $tw = $ctx['all'] ? '1' : '(t.requester_id = ' . $uid . ' OR t.assignee_id = ' . $uid . ($ctx['teams'] ? ' OR t.team_id IN (' . wk_ints($ctx['teams']) . ')' : '') . ')';
    if ($team) $tw .= ' AND t.team_id = ' . $team;
    $q = $db->prepare("SELECT COALESCE(c.name, 'Sin categoría') AS name, COUNT(*) AS created, SUM(t.resolved_at IS NOT NULL) AS solved,
            SUM(t.resolved_at IS NOT NULL AND t.resolution_due IS NOT NULL AND t.resolved_at <= t.resolution_due) AS ontime, SUM(t.resolved_at IS NOT NULL AND t.resolution_due IS NOT NULL) AS with_sla,
            AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at)) AS frt, AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) AS ttr,
            SUM(t.escalated > 0) AS escalated, SUM(t.status IN ('abierto', 'en_curso', 'esperando')) AS open
        FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id WHERE t.created_at BETWEEN ? AND ? AND $tw GROUP BY t.category_id ORDER BY created DESC");
    $q->execute([$f0, $f1]);
    $byCat = array_map(fn($r) => ['name' => $r['name'], 'created' => (int)$r['created'], 'solved' => (int)$r['solved'], 'ontime' => (int)$r['ontime'], 'with_sla' => (int)$r['with_sla'], 'frt' => $r['frt'] === null ? null : (int)round($r['frt']), 'ttr' => $r['ttr'] === null ? null : (int)round($r['ttr']), 'escalated' => (int)$r['escalated'], 'open' => (int)$r['open']], $q->fetchAll(PDO::FETCH_ASSOC));
    // 4b) Tickets por persona, por origen y por semana (sin los que nacieron como tarea en "por persona" y "por semana": esos ya están en las tareas)
    $orgSql = "CASE WHEN t.origin = 'base' AND t.base_info LIKE '%\"source\":\"web\"%' THEN 'web' WHEN t.origin = 'base' THEN 'sitio' ELSE t.origin END";
    $tkCols = "COUNT(*) AS created, SUM(t.resolved_at IS NOT NULL) AS solved,
            SUM(t.resolved_at IS NOT NULL AND t.resolution_due IS NOT NULL AND t.resolved_at <= t.resolution_due) AS ontime, SUM(t.resolved_at IS NOT NULL AND t.resolution_due IS NOT NULL) AS with_sla,
            AVG(TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at)) AS frt, AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) AS ttr,
            SUM(t.status IN ('abierto', 'en_curso', 'esperando')) AS open";
    $tkRow = fn($r) => ['created' => (int)$r['created'], 'solved' => (int)$r['solved'], 'ontime' => (int)$r['ontime'], 'with_sla' => (int)$r['with_sla'], 'frt' => $r['frt'] === null ? null : (int)round($r['frt']), 'ttr' => $r['ttr'] === null ? null : (int)round($r['ttr']), 'open' => (int)$r['open']];
    $q = $db->prepare("SELECT t.assignee_id, $tkCols FROM g7_wk_tickets t WHERE t.created_at BETWEEN ? AND ? AND t.origin <> 'tarea' AND $tw GROUP BY t.assignee_id ORDER BY created DESC");
    $q->execute([$f0, $f1]);
    $tkPeople = array_map(fn($r) => ['user_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null] + $tkRow($r), $q->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare("SELECT $orgSql AS org, $tkCols FROM g7_wk_tickets t WHERE t.created_at BETWEEN ? AND ? AND $tw GROUP BY org ORDER BY created DESC");
    $q->execute([$f0, $f1]);
    $tkOrigin = array_map(fn($r) => ['origin' => (string)$r['org']] + $tkRow($r), $q->fetchAll(PDO::FETCH_ASSOC));
    // Semanas (lunes a domingo) del período, como mucho las últimas 26: creados, resueltos y resueltos dentro del plazo
    $wk0 = max(strtotime('monday this week', strtotime($from)), strtotime('-25 weeks', strtotime('monday this week', strtotime($to))));   // con +1 week (no 7×86400): el cambio de hora no corre los lunes
    $weeks = [];
    for ($d = $wk0; $d <= strtotime($to); $d = strtotime('+1 week', $d)) $weeks[date('Y-m-d', $d)] = ['week' => date('Y-m-d', $d), 'created' => 0, 'solved' => 0, 'ontime' => 0, 'with_sla' => 0];
    $w0 = date('Y-m-d 00:00:00', $wk0);
    $q = $db->prepare("SELECT DATE(DATE_SUB(t.created_at, INTERVAL WEEKDAY(t.created_at) DAY)) AS w, COUNT(*) AS n FROM g7_wk_tickets t WHERE t.created_at BETWEEN ? AND ? AND t.origin <> 'tarea' AND $tw GROUP BY w");
    $q->execute([$w0, $f1]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($weeks[$r['w']])) $weeks[$r['w']]['created'] = (int)$r['n'];
    $q = $db->prepare("SELECT DATE(DATE_SUB(t.resolved_at, INTERVAL WEEKDAY(t.resolved_at) DAY)) AS w, COUNT(*) AS n, SUM(t.resolution_due IS NOT NULL) AS ws, SUM(t.resolution_due IS NOT NULL AND t.resolved_at <= t.resolution_due) AS ot
        FROM g7_wk_tickets t WHERE t.resolved_at BETWEEN ? AND ? AND t.origin <> 'tarea' AND $tw GROUP BY w");
    $q->execute([$w0, $f1]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($weeks[$r['w']])) { $weeks[$r['w']]['solved'] = (int)$r['n']; $weeks[$r['w']]['with_sla'] = (int)$r['ws']; $weeks[$r['w']]['ontime'] = (int)$r['ot']; }
    // 5) Solicitudes por formulario
    $rw = $ctx['all'] ? '1' : '(r.requester_id = ' . $uid . ($ctx['teams'] ? ' OR r.team_id IN (' . wk_ints($ctx['teams']) . ')' : '') . ')';
    if ($team) $rw .= ' AND r.team_id = ' . $team;
    $q = $db->prepare("SELECT COALESCE(f.name, 'Formulario borrado') AS name, COUNT(*) AS created, SUM(r.status = 'aprobada') AS approved, SUM(r.status = 'rechazada') AS rejected,
            SUM(r.status = 'convertida') AS converted, SUM(r.status IN ('nueva', 'en_revision')) AS pending, AVG(TIMESTAMPDIFF(HOUR, r.created_at, r.decided_at)) AS decide_h
        FROM g7_wk_requests r LEFT JOIN g7_wk_forms f ON f.id = r.form_id WHERE r.created_at BETWEEN ? AND ? AND $rw GROUP BY r.form_id ORDER BY created DESC");
    $q->execute([$f0, $f1]);
    $byForm = array_map(fn($r) => ['name' => $r['name'], 'created' => (int)$r['created'], 'approved' => (int)$r['approved'], 'rejected' => (int)$r['rejected'], 'converted' => (int)$r['converted'], 'pending' => (int)$r['pending'], 'decide_h' => $r['decide_h'] === null ? null : (int)round($r['decide_h'])], $q->fetchAll(PDO::FETCH_ASSOC));
    // 6) Horas por cliente
    $cliT = wk_cli_table($db); $hasCli = (bool)$cliT;
    $q = $db->prepare('SELECT ' . ($hasCli ? "COALESCE(c.name, 'Sin " . mb_strtolower(wk_cli_label($db)) . "')" : "'Sin " . mb_strtolower(wk_cli_label($db)) . "'") . " AS name, SUM(m.minutes) AS mi, COUNT(DISTINCT m.task_id) AS tasks, COUNT(DISTINCT m.user_id) AS people
        FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id$bw" . ($hasCli ? ' LEFT JOIN ' . $cliT . ' c ON c.id = t.client_id' : '') . "
        WHERE m.ended_at IS NOT NULL AND m.started_at BETWEEN ? AND ?" . ($people === null ? '' : ' AND m.user_id IN (' . wk_ints($people) . ')') . ' GROUP BY t.client_id ORDER BY mi DESC');
    $q->execute([$f0, $f1]);
    $byClient = array_map(fn($r) => ['name' => $r['name'], 'minutes' => (int)$r['mi'], 'tasks' => (int)$r['tasks'], 'people' => (int)$r['people']], $q->fetchAll(PDO::FETCH_ASSOC));
    $teams = $ctx['all'] ? $db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
        : ($ctx['lead'] ? $db->query('SELECT id, name FROM g7_teams WHERE active = 1 AND id IN (' . wk_ints($ctx['lead']) . ') ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : []);
    wk_out(['from' => $from, 'to' => $to, 'scope' => $people === null ? 'all' : (count($people) > 1 ? 'team' : 'me'),
        'people' => $byPerson, 'boards' => $byBoard, 'projects' => $byProject, 'tickets' => $byCat, 'requests' => $byForm, 'clients' => $byClient,
        'tk_people' => $tkPeople, 'tk_origin' => $tkOrigin, 'tk_weeks' => array_values($weeks),
        'teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $teams), 'project_status' => wk_st('projects')]);

default:
    wk_fail('Acción desconocida');
}
