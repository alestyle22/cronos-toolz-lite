<?php
/**
 * trabajo/report_lib.php — Trabajo › Reports (trabajo/informes.php): the same reports as Statistics › Jira, with Cronos tasks.
 * It turns each task into a "row" with the shape the engine of jira_stats_lib.php expects (g7_jira_stats: pure calculations, without Jira)
 * and reuses the same screen (assets/stats-jira.js), styles and export (stats_jira_export.php). There is only one engine.
 *   key T-<id> · summary title · status/cat (board status: first = new, done = done, the rest = in progress)
 *   type board · project client or brand · components department of the board · labels task labels + origin of the ticket
 *   assignee primary owner (the first one assigned) · reporter whoever requested it (the ticket's requester or whoever created the task)
 *   created / resolved (completed_at) / due (due_date) · trans status changes from the activity (g7_task_activity "A → B")
 * Scope: the same as the reports that were already there (admin and director everything; manager their people and their departments; the rest, their own).
 * Doc: toolz/TRABAJO.md (Reports)
 */
require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/jira_stats_lib.php';

const WK_REP_MAX = 5000;   // tasks per report at most
const WK_REP_WORDS = ['issue' => 'tarea', 'issues' => 'tareas', 'Issues' => 'Tareas', 'none' => 'Ninguna tarea con esos filtros.', 'reporter' => 'Quién la pidió',
    'noReporter' => 'Sin dato', 'component' => 'Departamento', 'byComponent' => 'Por departamento', 'noComponent' => 'Sin departamento', 'type' => 'Tablero', 'types' => 'Tableros',
    'project' => 'Cliente o marca', 'reporterNote' => 'quién pidió cada tarea (el que la creó o el que abrió el ticket)'];

/** "What counts as delivered" in Trabajo (null = automatic: the done statuses + the ones named like approval or delivered). */
function wk_rep_cfg(): array {
    $c = json_decode((string)g7_cfg('work_stats_cfg', '{}'), true);
    $d = is_array($c['deliver'] ?? null) ? array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $c['deliver']), 'strlen'))) : null;
    return ['deliver' => $d];
}
function wk_rep_cfg_save(?array $deliver): array {
    $deliver = $deliver === null ? null : array_slice(array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $deliver), 'strlen'))), 0, 60);
    g7_cfg_set('work_stats_cfg', json_encode(['deliver' => $deliver], JSON_UNESCAPED_UNICODE));
    return wk_rep_cfg();
}

/** Departments and boards the person can filter by (the departments: all of them if they see everything; if not, the ones they lead). */
function wk_rep_filters(PDO $db, array $ctx): array {
    $teams = $ctx['all'] ? $db->query('SELECT id, name FROM g7_teams WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)
        : ($ctx['lead'] ? $db->query('SELECT id, name FROM g7_teams WHERE active = 1 AND id IN (' . wk_ints($ctx['lead']) . ') ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : []);
    $boards = $db->query('SELECT b.id, b.name FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.position, b.name')->fetchAll(PDO::FETCH_ASSOC);
    return ['teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], $teams), 'boards' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $boards)];
}

/**
 * Tasks of the report turned into rows. $f: team (department), board. Returns ['rows' => [...], 'urls' => [key => link], 'scope' => all|team|own, 'truncated' => bool].
 * It brings what can count in the range: created up to the end of the range and (open, or closed from the start of the range, or with a due date in the range).
 */
function wk_rep_rows(PDO $db, array $ctx, string $from, string $to, array $f = []): array {
    $cfg = tk_config($db); $g = wk_general_id($db);
    $w = ['t.archived = 0', 't.parent_id IS NULL', wk_task_where($db, $ctx), 't.created_at <= ?', '(t.completed_at IS NULL OR t.completed_at >= ? OR t.due_date BETWEEN ? AND ?)'];
    $a = [$to . ' 23:59:59', $from . ' 00:00:00', $from, $to];
    $uid = (int)$ctx['id'];
    $people = wk_people_scope($db, $ctx);
    $scope = $people === null ? 'all' : ($ctx['lead'] ? 'team' : 'own');
    if ($people !== null) {
        $mine = "(t.created_by = $uid OR EXISTS (SELECT 1 FROM g7_task_assignees x WHERE x.task_id = t.id AND x.user_id = $uid))";
        $w[] = $ctx['lead'] ? "($mine OR EXISTS (SELECT 1 FROM g7_task_assignees x WHERE x.task_id = t.id AND x.user_id IN (" . wk_ints($people ?: [0]) . "))"
            . " OR EXISTS (SELECT 1 FROM g7_wk_boards bb WHERE bb.id = COALESCE(t.board_id, $g) AND bb.team_id IN (" . wk_ints($ctx['lead']) . ')))' : $mine;
    }
    if (!empty($f['board'])) { $w[] = "COALESCE(t.board_id, $g) = ?"; $a[] = (int)$f['board']; }
    if (!empty($f['team'])) {
        $tid = (int)$f['team'];
        $w[] = "(EXISTS (SELECT 1 FROM g7_wk_boards bb WHERE bb.id = COALESCE(t.board_id, $g) AND bb.team_id = $tid)"
             . " OR EXISTS (SELECT 1 FROM g7_task_assignees x JOIN g7_team_members tm ON tm.user_id = x.user_id AND tm.team_id = $tid WHERE x.task_id = t.id))";
    }
    $q = $db->prepare('SELECT t.id, t.title, t.status, t.priority, t.due_date, t.client_id, t.labels, t.created_by, t.created_at, t.completed_at, COALESCE(t.board_id, ' . $g . ') AS board
                       FROM g7_tasks t WHERE ' . implode(' AND ', $w) . ' ORDER BY t.created_at DESC LIMIT ' . (WK_REP_MAX + 1));
    $q->execute($a);
    $tasks = $q->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($tasks) > WK_REP_MAX;
    if ($truncated) array_pop($tasks);
    if (!$tasks) return ['rows' => [], 'urls' => [], 'scope' => $scope, 'truncated' => false];
    $ids = array_map(fn($t) => (int)$t['id'], $tasks); $in = implode(',', $ids);
    // Catalogs
    $users = []; foreach ($db->query("SELECT id, COALESCE(NULLIF(display_name,''), username) AS n FROM g7_users") as $u) $users[(int)$u['id']] = $u['n'];
    $boards = []; foreach ($db->query('SELECT b.id, b.name, b.statuses, tm.name AS team FROM g7_wk_boards b LEFT JOIN g7_teams tm ON tm.id = b.team_id') as $b) {
        $st = $b['statuses'] ? (json_decode($b['statuses'], true) ?: $cfg['statuses']) : $cfg['statuses'];
        $boards[(int)$b['id']] = ['name' => $b['name'], 'team' => (string)$b['team'], 'st' => $st];
    }
    $prio = array_column($cfg['priorities'], 'name', 'id');
    $labels = array_column((array)($cfg['labels'] ?? []), 'name', 'id');
    $cliTable = wk_cli_table($db); $cliNames = [];
    if ($cliTable) foreach ($db->query("SELECT id, name FROM $cliTable") as $c) $cliNames[(int)$c['id']] = $c['name'];
    $assignee = [];
    foreach ($db->query("SELECT task_id, user_id FROM g7_task_assignees WHERE task_id IN ($in) ORDER BY task_id, user_id") as $r) $assignee[(int)$r['task_id']] ??= (int)$r['user_id'];
    $ticket = [];
    try { foreach ($db->query("SELECT task_id, requester_id, origin FROM g7_wk_tickets WHERE task_id IN ($in)") as $r) $ticket[(int)$r['task_id']] = $r; } catch (Throwable $e) {}
    $trans = [];
    foreach ($db->query("SELECT task_id, detail, created_at FROM g7_task_activity WHERE action = 'status' AND task_id IN ($in) ORDER BY created_at, id") as $r) {
        $p = explode('→', (string)$r['detail']);
        if (count($p) === 2) $trans[(int)$r['task_id']][] = [substr((string)$r['created_at'], 0, 16), trim($p[1])];
    }
    $origins = defined('WK_TK_ORIGINS') ? WK_TK_ORIGINS : [];
    $cliWord = wk_cli_label($db);
    $rows = []; $urls = [];
    foreach ($tasks as $t) {
        $id = (int)$t['id']; $b = $boards[(int)$t['board']] ?? ['name' => 'General', 'team' => '', 'st' => $cfg['statuses']];
        $stName = $t['status']; $cat = 'indeterminate';
        foreach ($b['st'] as $i => $s) if (($s['id'] ?? '') === $t['status']) { $stName = (string)($s['name'] ?? $t['status']); $cat = !empty($s['done']) ? 'done' : ($i === 0 ? 'new' : 'indeterminate'); break; }
        if ($t['completed_at'] && $cat !== 'done') $cat = 'done';   // closed by hand outside the columns: it counts as done
        $tk = $ticket[$id] ?? null;
        $lab = [];
        foreach ((array)(json_decode((string)$t['labels'], true) ?: []) as $l) $lab[] = (string)($labels[$l] ?? $l);
        if ($tk && isset($origins[$tk['origin']]) && $tk['origin'] !== 'tarea') $lab[] = 'Origen: ' . $origins[$tk['origin']];
        $who = $tk && $tk['requester_id'] ? ($users[(int)$tk['requester_id']] ?? '') : ($users[(int)$t['created_by']] ?? '');
        $key = 'T-' . $id;
        $rows[] = ['key' => $key, 'summary' => (string)$t['title'], 'status' => $stName, 'cat' => $cat, 'type' => $b['name'],
            'priority' => (string)($prio[$t['priority']] ?? ($t['priority'] ?: 'Sin prioridad')), 'assignee' => isset($assignee[$id]) ? ($users[$assignee[$id]] ?? '') : '',
            'reporter' => $who, 'creator' => $users[(int)$t['created_by']] ?? '', 'components' => [$b['team'] !== '' ? $b['team'] : 'Sin departamento'], 'labels' => array_values(array_unique($lab)),
            'project' => $t['client_id'] && isset($cliNames[(int)$t['client_id']]) ? $cliNames[(int)$t['client_id']] : 'Sin ' . mb_strtolower($cliWord), 'project_name' => '',
            'created' => substr((string)$t['created_at'], 0, 16), 'resolved' => $t['completed_at'] ? substr((string)$t['completed_at'], 0, 16) : '', 'due' => (string)($t['due_date'] ?? ''),
            'trans' => $trans[$id] ?? []];
        $urls[$key] = '/toolz/tareas.php?b=' . (int)$t['board'] . '&t=' . $id;
    }
    return ['rows' => $rows, 'urls' => $urls, 'scope' => $scope, 'truncated' => $truncated];
}

/** Full report (the same structure as g7_jira_report) for the page and the export. */
function wk_rep_report(PDO $db, array $ctx, array $range, array $f = [], ?int $now = null): array {
    $now = $now ?? time();
    $r = wk_rep_rows($db, $ctx, $range['from'], $range['to'], $f);
    $J = g7_jira_stats($r['rows'], $range['from'], $range['to'], $now, ['deliver' => wk_rep_cfg()['deliver']]);
    $J['urls'] = $r['urls']; $J['scope'] = $r['scope']; $J['truncated'] = $r['truncated']; $J['site'] = ''; $J['cached_at'] = date('Y-m-d H:i:s', $now);
    return $J;
}
