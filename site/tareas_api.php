<?php
/**
 * tareas_api.php — API del tablero de tareas (tareas.php / tareas.js). Todo por POST JSON {action, ...}.
 * Cualquiera con acceso al módulo Tareas crea y edita tareas; la configuración es solo de admins.
 * En un sitio sin Clientes cada tarea es de una MARCA (g7_brands): la columna se sigue llamando client_id para compartir el código.
 * Con la separación por marcas prendida, cada persona ve las tareas de sus marcas y las que no tienen marca.
 */
require_once __DIR__ . '/auth.php';
$me = getCurrentUser();
header('Content-Type: application/json; charset=utf-8');
if (!$me) { http_response_code(403); echo json_encode(['error' => 'Sesión vencida: vuelve a ingresar']); exit; }
$db = getDB();
require_once __DIR__ . '/tareas_lib.php';
tk_install($db);

$uid = (int)$me['id'];
$isAdmin = ($me['role'] ?? '') === 'admin';
$TK_SCOPE = g7_brand_scope($uid);   // null = todas las marcas

function tk_out(array $v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; }
function tk_fail(string $m, int $c = 400): void { http_response_code($c); tk_out(['error' => $m]); }
function tk_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function tk_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null; }
function tk_color($v, string $def = '#8b8b94'): string { return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? strtolower($v) : $def; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) tk_fail('Pedido inválido', 405);
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
$cfg = tk_config($db);

// Gestión de trabajo: cada pedido viene de un tablero (sin tablero = General) y solo se ve lo permitido (trabajo/lib.php)
require_once __DIR__ . '/trabajo/lib.php';
wk_install($db);
$ctx = wk_ctx($db, $uid);
$board = (int)($in['board'] ?? 0) ?: wk_general_id($db);
$boardRow = wk_board($db, $board);
if (!$boardRow) tk_fail('Ese tablero no existe', 404);
$boardOk = wk_board_visible($db, $ctx, $board);   // si no, solo ve las tareas suyas de ese tablero
$globalStatuses = $cfg['statuses'];
$cfg['statuses'] = wk_statuses($db, $boardRow, $cfg);   // cada tablero puede tener sus propios estados
$GLOBALS['TK_WSCOPE'] = wk_task_where($db, $ctx);
$GLOBALS['TK_BOARD'] = 'COALESCE(t.board_id, ' . wk_general_id($db) . ') = ' . $board;

/** Tareas con responsables y contadores (sin archivadas, salvo que se pidan). */
function tk_tasks(PDO $db, bool $archived = false, ?int $id = null): array {
    global $TK_SCOPE;
    $where = ($id ? 't.id = ' . (int)$id : 't.archived = ' . ($archived ? 1 : 0) . ' AND ' . $GLOBALS['TK_BOARD']) . ' AND ' . $GLOBALS['TK_WSCOPE'];
    if ($TK_SCOPE !== null) $where .= ' AND (t.client_id IS NULL' . ($TK_SCOPE ? ' OR t.client_id IN (' . implode(',', array_map('intval', $TK_SCOPE)) . ')' : '') . ')';
    $rows = $db->query("SELECT t.*,
            (SELECT COUNT(*) FROM g7_task_comments c WHERE c.task_id = t.id) AS n_comments,
            (SELECT COUNT(*) FROM g7_task_content x WHERE x.task_id = t.id) AS n_contents,
            (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id) AS n_checks,
            (SELECT COUNT(*) FROM g7_task_checks k WHERE k.task_id = t.id AND k.done = 1) AS n_checks_done,
            (SELECT COUNT(*) FROM g7_task_files f WHERE f.task_id = t.id) AS n_files,
            (SELECT COALESCE(SUM(m.minutes), 0) FROM g7_task_time m WHERE m.task_id = t.id AND m.ended_at IS NOT NULL) AS minutes,
            (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg,
            (SELECT COUNT(*) FROM g7_tasks s WHERE s.parent_id = t.id) AS n_sub,
            (SELECT COUNT(*) FROM g7_tasks s WHERE s.parent_id = t.id AND s.completed_at IS NOT NULL) AS n_sub_done,
            (SELECT GROUP_CONCAT(d.depends_on) FROM g7_wk_task_deps d WHERE d.task_id = t.id) AS deps,
            (SELECT COUNT(*) FROM g7_wk_task_deps d JOIN g7_tasks b ON b.id = d.depends_on WHERE d.task_id = t.id AND b.completed_at IS NULL AND b.archived = 0) AS n_blockers
        FROM g7_tasks t WHERE $where ORDER BY t.position, t.id")->fetchAll(PDO::FETCH_ASSOC);
    // Valores de los campos personalizados, de una sola vez
    $fv = [];
    if ($rows) foreach ($db->query('SELECT task_id, field_id, value FROM g7_wk_field_values WHERE task_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $v) $fv[(int)$v['task_id']][(int)$v['field_id']] = $v['value'];
    foreach ($rows as &$r) {
        foreach (['id', 'board_id', 'parent_id', 'project_id', 'milestone_id', 'estimate_min', 'n_sub', 'n_sub_done', 'n_blockers', 'client_id', 'created_by', 'n_comments', 'n_contents', 'archived', 'n_checks', 'n_checks_done', 'n_files', 'minutes', 'repeat_from', 'repeat_done'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        $r['position'] = (float)$r['position'];
        $r['labels'] = json_decode($r['labels'] ?: '[]', true) ?: [];
        $r['assignees'] = $r['asg'] ? array_map('intval', explode(',', $r['asg'])) : [];
        $r['deps'] = $r['deps'] ? array_map('intval', explode(',', $r['deps'])) : [];
        $r['fv'] = (object)($fv[$r['id']] ?? []);
        unset($r['asg']);
    }
    return $rows;
}
function tk_task(PDO $db, int $id): array {
    $t = tk_tasks($db, false, $id);
    if (!$t) tk_fail('Esa tarea no existe', 404);
    return $t[0];
}
function tk_templates(PDO $db): array {
    $rows = $db->query('SELECT id, name, data, created_by FROM g7_task_templates ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'created_by' => (int)$r['created_by'], 'data' => json_decode($r['data'], true) ?: []], $rows);
}
function tk_valid_users(PDO $db, $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if (!$ids) return [];
    return array_map('intval', $db->query('SELECT id FROM g7_users WHERE active = 1 AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN));
}
function tk_client_name(PDO $db, ?int $id): string {
    if (!$id) return 'sin marca';
    try { $q = $db->prepare('SELECT name FROM g7_brands WHERE id = ?'); $q->execute([$id]); return (string)$q->fetchColumn() ?: '#' . $id; }
    catch (Throwable $e) { return '#' . $id; }
}
function tk_user_name(PDO $db, int $id): string {
    $q = $db->prepare("SELECT COALESCE(NULLIF(display_name, ''), username) FROM g7_users WHERE id = ?"); $q->execute([$id]);
    return (string)$q->fetchColumn() ?: '#' . $id;
}

/**
 * Crea o modifica una tarea con los campos que vengan (los que no vienen no se tocan),
 * deja el historial y avisa a quien corresponda. Devuelve la tarea.
 */
function tk_apply(PDO $db, array $cfg, int $uid, array $in): array {
    $id = (int)($in['id'] ?? 0);
    $old = $id ? tk_task($db, $id) : null;
    $f = [];
    if (array_key_exists('title', $in)) { $f['title'] = tk_str($in['title'], 250); if ($f['title'] === '') tk_fail('La tarea necesita un título'); }
    elseif (!$old) tk_fail('La tarea necesita un título');
    if (array_key_exists('description', $in)) $f['description'] = tk_str($in['description'], 20000);
    if (array_key_exists('status', $in) || !$old) {
        $s = (string)($in['status'] ?? '');
        $f['status'] = in_array($s, tk_status_ids($cfg), true) ? $s : $cfg['statuses'][0]['id'];
    }
    if (array_key_exists('priority', $in) || !$old) {
        $p = (string)($in['priority'] ?? '');
        $f['priority'] = in_array($p, array_column($cfg['priorities'], 'id'), true) ? $p : $cfg['default_priority'];
    }
    if (array_key_exists('due_date', $in)) $f['due_date'] = tk_date($in['due_date']);
    if (array_key_exists('repeat_rule', $in)) $f['repeat_rule'] = isset(TK_REPEATS[$in['repeat_rule'] ?? '']) ? $in['repeat_rule'] : '';
    if (array_key_exists('client_id', $in)) {
        $c = (int)$in['client_id'];
        if ($c) {
            $q = $db->prepare('SELECT id FROM g7_brands WHERE id = ?'); $q->execute([$c]); if (!$q->fetch()) tk_fail('Esa marca no existe');
            global $TK_SCOPE; if ($TK_SCOPE !== null && !in_array($c, $TK_SCOPE, true)) tk_fail('No tienes acceso a esa marca', 403);
        }
        $f['client_id'] = $c ?: null;
    }
    if (!$old && !empty($in['board_id'])) $f['board_id'] = (int)$in['board_id'];
    if (array_key_exists('start_date', $in)) $f['start_date'] = tk_date($in['start_date']);
    // Proyecto e hito (fase 3): el proyecto lo tiene que ver quien edita; el hito tiene que ser de ese proyecto
    if (array_key_exists('project_id', $in) || array_key_exists('milestone_id', $in)) {
        $pid = array_key_exists('project_id', $in) ? (int)$in['project_id'] : (int)($old['project_id'] ?? 0);
        if ($pid && !wk_project_visible($db, $GLOBALS['ctx'], $pid)) tk_fail('Ese proyecto no existe o no tienes acceso', 404);
        $mid = array_key_exists('milestone_id', $in) ? (int)$in['milestone_id'] : (($old['project_id'] ?? 0) === $pid ? (int)($old['milestone_id'] ?? 0) : 0);
        if ($mid) { $q = $db->prepare('SELECT 1 FROM g7_wk_milestones WHERE id = ? AND project_id = ?'); $q->execute([$mid, $pid]); if (!$q->fetch()) $mid = 0; }
        $f['project_id'] = $pid ?: null; $f['milestone_id'] = $pid && $mid ? $mid : null;
    }
    if (array_key_exists('estimate_min', $in)) { $e = (int)$in['estimate_min']; $f['estimate_min'] = $e > 0 ? min($e, 100000) : null; }
    if (!$old && !empty($in['parent_id'])) {
        // Subtarea: queda en el tablero de su tarea principal (un solo nivel)
        $p = tk_task($db, (int)$in['parent_id']);
        if ($p['parent_id']) tk_fail('Una subtarea no puede tener subtareas');
        $f['parent_id'] = $p['id']; $f['board_id'] = $p['board_id']; $f['project_id'] = $p['project_id']; $f['milestone_id'] = $p['milestone_id'];
    }
    if (array_key_exists('labels', $in)) {
        $ok = array_column($cfg['labels'], 'id');
        $f['labels'] = json_encode(array_values(array_intersect(array_unique((array)$in['labels']), $ok)));
    }
    $now = tk_now();
    if (isset($f['status'])) {
        $isDone = in_array($f['status'], tk_status_ids($cfg, true), true);
        if (!$old || $old['status'] !== $f['status']) $f['completed_at'] = $isDone ? $now : null;
        // No se termina mientras alguna tarea de la que depende siga abierta
        if ($old && $isDone && $old['status'] !== $f['status'] && ($bl = wk_open_blockers($db, $id)))
            tk_fail('Está bloqueada por: ' . implode(', ', array_map(fn($b) => '"' . $b['title'] . '"', $bl)) . '. Termina esas primero o quita la dependencia.');
    }

    if (!$old) {
        $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_tasks WHERE status = ?'); $max->execute([$f['status']]);
        $f += ['description' => '', 'due_date' => null, 'client_id' => null, 'labels' => '[]'];
        $f['position'] = (float)$max->fetchColumn() + 1;
        $f['created_by'] = $uid; $f['created_at'] = $now; $f['updated_at'] = $now;
        $cols = array_keys($f);
        $db->prepare('INSERT INTO g7_tasks (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($f));
        $id = (int)$db->lastInsertId();
        tk_log($db, $id, $uid, 'created');
    } elseif ($f) {
        $f['updated_at'] = $now;
        if (isset($f['status']) && $f['status'] !== $old['status']) {   // al cambiar de columna va al final
            $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_tasks WHERE status = ?'); $max->execute([$f['status']]);
            $f['position'] = (float)$max->fetchColumn() + 1;
        }
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($f)));
        $db->prepare("UPDATE g7_tasks SET $set WHERE id = ?")->execute([...array_values($f), $id]);
    }

    // Responsables
    $added = [];
    if (array_key_exists('assignees', $in)) {
        $new = tk_valid_users($db, $in['assignees']);
        $prev = $old['assignees'] ?? [];
        $added = array_values(array_diff($new, $prev));
        $removed = array_values(array_diff($prev, $new));
        if ($added)   { $ins = $db->prepare('INSERT IGNORE INTO g7_task_assignees (task_id, user_id) VALUES (?, ?)'); foreach ($added as $a) $ins->execute([$id, $a]); }
        if ($removed) $db->prepare('DELETE FROM g7_task_assignees WHERE task_id = ? AND user_id IN (' . implode(',', $removed) . ')')->execute([$id]);
        if ($old && ($added || $removed)) {
            $parts = [];
            if ($added) $parts[] = 'sumó a ' . implode(', ', array_map(fn($u) => tk_user_name($db, $u), $added));
            if ($removed) $parts[] = 'sacó a ' . implode(', ', array_map(fn($u) => tk_user_name($db, $u), $removed));
            tk_log($db, $id, $uid, 'assignees', implode(' y ', $parts));
        }
        if ($added || $removed) $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([$now, $id]);
    }
    // Pasos de la lista (al crear desde una plantilla o con la ficha nueva)
    if (!$old && !empty($in['checks'])) {
        $ins = $db->prepare('INSERT INTO g7_task_checks (task_id, body, position) VALUES (?, ?, ?)');
        foreach (array_slice((array)$in['checks'], 0, 100) as $i => $b) { $b = tk_str(is_array($b) ? ($b['body'] ?? '') : $b, 300); if ($b !== '') $ins->execute([$id, $b, $i + 1]); }
    }
    // Contenidos de Promos elegidos al crear
    if (!$old && !empty($in['contents'])) {
        $ins = $db->prepare('INSERT IGNORE INTO g7_task_content (task_id, content_id) VALUES (?, ?)');
        foreach (array_slice(array_unique(array_map('intval', (array)$in['contents'])), 0, 50) as $c) if ($c > 0) $ins->execute([$id, $c]);
    }

    $task = tk_task($db, $id);
    // Historial de cambios
    if ($old) {
        if (isset($f['title']) && $f['title'] !== $old['title']) tk_log($db, $id, $uid, 'title', $f['title']);
        if (isset($f['status']) && $f['status'] !== $old['status']) tk_log($db, $id, $uid, 'status', tk_name_of($cfg['statuses'], $old['status']) . ' → ' . tk_name_of($cfg['statuses'], $f['status']));
        if (isset($f['priority']) && $f['priority'] !== $old['priority']) tk_log($db, $id, $uid, 'priority', tk_name_of($cfg['priorities'], $old['priority']) . ' → ' . tk_name_of($cfg['priorities'], $f['priority']));
        if (array_key_exists('due_date', $f) && $f['due_date'] !== $old['due_date']) tk_log($db, $id, $uid, 'due', $f['due_date'] ? date('d/m/Y', strtotime($f['due_date'])) : 'sin fecha');
        if (array_key_exists('client_id', $f) && $f['client_id'] !== $old['client_id']) tk_log($db, $id, $uid, 'client', tk_client_name($db, $f['client_id']));
        if (isset($f['description']) && $f['description'] !== (string)$old['description']) tk_log($db, $id, $uid, 'description');
        if (array_key_exists('project_id', $f) && $f['project_id'] !== $old['project_id']) tk_log($db, $id, $uid, 'project', $f['project_id'] ? (wk_project($db, $f['project_id'])['name'] ?? '') : 'sin proyecto');
        if (array_key_exists('start_date', $f) && $f['start_date'] !== $old['start_date']) tk_log($db, $id, $uid, 'start', $f['start_date'] ? date('d/m/Y', strtotime($f['start_date'])) : 'sin inicio');
        if (array_key_exists('estimate_min', $f) && $f['estimate_min'] !== $old['estimate_min']) tk_log($db, $id, $uid, 'estimate', $f['estimate_min'] ? round($f['estimate_min'] / 60, 1) . ' h' : 'sin estimado');
        if (isset($f['repeat_rule']) && $f['repeat_rule'] !== (string)$old['repeat_rule']) tk_log($db, $id, $uid, 'repeat', $f['repeat_rule'] ? TK_REPEATS[$f['repeat_rule']][0] : 'ya no se repite');
    }
    // Tarea que se repite: al terminarla nace la siguiente (una sola vez, aunque la muevan de nuevo)
    if (isset($f['status']) && $task['repeat_rule'] && !$task['repeat_done'] && in_array($f['status'], tk_status_ids($cfg, true), true) && (!$old || $old['status'] !== $f['status'])) {
        tk_spawn_next($db, $cfg, $uid, $task);
        $task = tk_task($db, $id);
    }
    // Avisos
    if ($added) tk_notify($db, 'assigned', $added, $task, $uid);
    if (!$old) {
        $to = $cfg['notif']['created_notify'] ?: array_map('intval', $db->query("SELECT id FROM g7_users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
        tk_notify($db, 'created', array_diff($to, $added), $task, $uid);
    } elseif (isset($f['status']) && $f['status'] !== $old['status']) {
        tk_notify($db, 'status', array_diff(tk_participants($db, $id), $added), $task, $uid, tk_name_of($cfg['statuses'], $f['status']));
    }
    if (isset($f['description'])) {
        $was = $old ? tk_mentions($db, (string)$old['description']) : [];
        $men = array_diff(tk_mentions($db, $f['description']), $was, $added);
        if ($men) tk_notify($db, 'mention', $men, $task, $uid, mb_substr($f['description'], 0, 200));
    }
    // Automatizaciones y procesos (fase 5): si una regla cambió la tarea, se devuelve como quedó
    wk_task_events($db, $old, $task, $added, $uid);
    $task = tk_task($db, $id);
    return $task;
}

/** Crea la próxima vuelta de una tarea que se repite: mismos datos y pasos sin tildar, fecha corrida según la regla. */
function tk_spawn_next(PDO $db, array $cfg, int $uid, array $t): void {
    $step = TK_REPEATS[$t['repeat_rule']][1];
    $base = $t['due_date'] ?: date('Y-m-d');
    $next = date('Y-m-d', strtotime($base . ' ' . $step));
    while ($next < date('Y-m-d')) $next = date('Y-m-d', strtotime($next . ' ' . $step));   // si quedó muy atrás, salta a la próxima fecha futura
    $q = $db->prepare('SELECT body FROM g7_task_checks WHERE task_id = ? ORDER BY position, id'); $q->execute([$t['id']]);
    $new = tk_apply($db, $cfg, $uid, [
        'title' => $t['title'], 'description' => (string)$t['description'], 'status' => $cfg['statuses'][0]['id'], 'priority' => $t['priority'],
        'due_date' => $next, 'client_id' => $t['client_id'], 'board_id' => $t['board_id'], 'labels' => $t['labels'], 'assignees' => $t['assignees'],
        'repeat_rule' => $t['repeat_rule'], 'checks' => $q->fetchAll(PDO::FETCH_COLUMN),
    ]);
    $db->prepare('UPDATE g7_tasks SET repeat_from = ? WHERE id = ?')->execute([$t['repeat_from'] ?: $t['id'], $new['id']]);
    $db->prepare('UPDATE g7_tasks SET repeat_done = 1 WHERE id = ?')->execute([$t['id']]);
    tk_log($db, (int)$t['id'], $uid, 'repeat_next', date('d/m/Y', strtotime($next)));
}

/** Detalle completo: comentarios, historial y contenidos vinculados. */
function tk_detail(PDO $db, int $id): array {
    $t = tk_task($db, $id);
    $q = $db->prepare('SELECT id, user_id, parent_id, body, created_at, edited_at, internal, ext_name FROM g7_task_comments WHERE task_id = ? ORDER BY id'); $q->execute([$id]);
    $t['comments'] = array_map(fn($c) => ['id' => (int)$c['id'], 'user_id' => $c['user_id'] === null ? null : (int)$c['user_id'], 'internal' => (int)$c['internal'], 'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null, 'reactions' => []] + $c, $q->fetchAll(PDO::FETCH_ASSOC));
    // Reacciones agrupadas por emoji, en el orden en que aparecieron
    $rx = [];
    $q = $db->prepare('SELECT r.comment_id, r.emoji, r.user_id FROM g7_task_comment_reactions r JOIN g7_task_comments c ON c.id = r.comment_id WHERE c.task_id = ? ORDER BY r.created_at, r.user_id'); $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rx[(int)$r['comment_id']][$r['emoji']][] = (int)$r['user_id'];
    foreach ($t['comments'] as &$c) foreach ($rx[$c['id']] ?? [] as $e => $us) $c['reactions'][] = ['e' => $e, 'users' => $us];
    unset($c);
    $q = $db->prepare('SELECT user_id, action, detail, created_at FROM g7_task_activity WHERE task_id = ? ORDER BY id DESC LIMIT 60'); $q->execute([$id]);
    $t['activity'] = $q->fetchAll(PDO::FETCH_ASSOC);
    $t['content'] = tk_content_rows($db, 'id IN (SELECT content_id FROM g7_task_content WHERE task_id = ' . $id . ')', [], 50);
    $q = $db->prepare('SELECT id, body, done, position, done_by, done_at FROM g7_task_checks WHERE task_id = ? ORDER BY position, id'); $q->execute([$id]);
    $t['checks'] = array_map(fn($r) => ['id' => (int)$r['id'], 'done' => (int)$r['done'], 'done_by' => $r['done_by'] ? (int)$r['done_by'] : null] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    $t['files'] = tk_files($db, $id);
    $q = $db->prepare('SELECT id, user_id, started_at, ended_at, minutes, note FROM g7_task_time WHERE task_id = ? ORDER BY started_at DESC LIMIT 100'); $q->execute([$id]);
    $t['time'] = array_map(fn($r) => ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'minutes' => (int)$r['minutes']] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    // Fase 2: subtareas, dependencias (las dos direcciones) y tarea principal
    $scope = $GLOBALS['TK_WSCOPE'];
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.due_date, t.completed_at, (SELECT GROUP_CONCAT(a.user_id) FROM g7_task_assignees a WHERE a.task_id = t.id) AS asg
        FROM g7_tasks t WHERE t.parent_id = ? AND $scope ORDER BY t.position, t.id"); $q->execute([$id]);
    $t['subtasks'] = array_map(fn($r) => ['id' => (int)$r['id'], 'assignees' => $r['asg'] ? array_map('intval', explode(',', $r['asg'])) : []] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    $g = wk_general_id($db);
    $dep = fn($sql) => array_map(fn($r) => ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id']] + $r, $sql->fetchAll(PDO::FETCH_ASSOC));
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.completed_at, COALESCE(t.board_id, $g) AS board_id, b.name AS board_name FROM g7_wk_task_deps d JOIN g7_tasks t ON t.id = d.depends_on
        LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g) WHERE d.task_id = ? AND $scope ORDER BY t.completed_at IS NOT NULL, t.id"); $q->execute([$id]);
    $t['blockers'] = $dep($q);
    $q = $db->prepare("SELECT t.id, t.title, t.status, t.completed_at, COALESCE(t.board_id, $g) AS board_id, b.name AS board_name FROM g7_wk_task_deps d JOIN g7_tasks t ON t.id = d.task_id
        LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g) WHERE d.depends_on = ? AND $scope ORDER BY t.id"); $q->execute([$id]);
    $t['blocking'] = $dep($q);
    $t['ticket'] = wk_tk_brief($db, $id);   // su ticket (Trabajo › Tickets): código, estado y plazos
    $t['parent'] = null;
    if ($t['parent_id']) { $q = $db->prepare("SELECT t.id, t.title FROM g7_tasks t WHERE t.id = ? AND $scope"); $q->execute([$t['parent_id']]); $p = $q->fetch(PDO::FETCH_ASSOC); if ($p) $t['parent'] = ['id' => (int)$p['id'], 'title' => $p['title']]; }
    return $t;
}
/** Adjuntos de una tarea (viven siempre en _private/tareas/<id>/). */
function tk_files(PDO $db, int $id): array {
    $q = $db->prepare("SELECT f.id, NULL AS client_file_id, f.name, f.mime, f.size, NULL AS folder, NULL AS client_id, f.uploaded_by, f.created_at, 0 AS gone, f.version_of, f.version
        FROM g7_task_files f WHERE f.task_id = ? ORDER BY f.id DESC");
    try { $q->execute([$id]); } catch (Throwable $e) { return []; }
    return array_map(fn($r) => ['id' => (int)$r['id'], 'client_file_id' => $r['client_file_id'] ? (int)$r['client_file_id'] : null, 'size' => (int)$r['size'], 'version_of' => $r['version_of'] ? (int)$r['version_of'] : null, 'version' => (int)$r['version'],
        'client_id' => $r['client_id'] ? (int)$r['client_id'] : null, 'gone' => (int)$r['gone']] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
}
/** Reloj andando de una persona (o null). */
function tk_timer(PDO $db, int $uid): ?array {
    $q = $db->prepare('SELECT m.id, m.task_id, m.started_at, t.title FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id WHERE m.user_id = ? AND m.ended_at IS NULL ORDER BY m.id DESC LIMIT 1');
    $q->execute([$uid]); $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? ['id' => (int)$r['id'], 'task_id' => (int)$r['task_id'], 'started_at' => $r['started_at'], 'title' => $r['title']] : null;
}
/** Para el reloj de una persona: guarda los minutos (al menos 1). */
function tk_timer_stop(PDO $db, int $uid): ?int {
    $t = tk_timer($db, $uid); if (!$t) return null;
    $min = max(1, (int)round((time() - strtotime($t['started_at'])) / 60));
    $db->prepare('UPDATE g7_task_time SET ended_at = ?, minutes = ? WHERE id = ?')->execute([tk_now(), $min, $t['id']]);
    return $t['task_id'];
}
function tk_content_rows(PDO $db, string $where, array $args, int $limit): array {
    try {
        $q = $db->prepare("SELECT id, type, name, project, category, start_date, end_date, img, created_at FROM g7_content WHERE $where ORDER BY COALESCE(start_date, DATE(created_at)) DESC, id DESC LIMIT $limit");
        $q->execute($args);
        return array_map(fn($r) => ['id' => (int)$r['id']] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

switch ($act) {

case 'boot':
    tk_run_reminders($db);
    $users = $db->query("SELECT id, username, COALESCE(NULLIF(display_name, ''), username) AS name, avatar, role FROM g7_users WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$u) $u['id'] = (int)$u['id']; unset($u);
    // Las "clientes" del tablero son las marcas (con su color); las inactivas se ven como finalizadas
    $clients = [];
    foreach (g7_brands(true) as $b) {
        if ($TK_SCOPE !== null && !in_array((int)$b['id'], $TK_SCOPE, true)) continue;
        $clients[] = ['id' => (int)$b['id'], 'name' => $b['name'], 'status' => $b['active'] ? 'activo' : 'terminado', 'color' => $b['color'] ?: null];
    }
    $board_out = ['id' => $board, 'name' => $boardRow['name'], 'color' => $boardRow['color'], 'icon' => $boardRow['icon'], 'is_default' => $boardRow['is_default'],
                  'archived' => $boardRow['archived'], 'visible' => $boardOk, 'can_manage' => wk_can_manage_board($db, $ctx, $boardRow)];
    $board_out['own_statuses'] = (bool)$boardRow['statuses'];
    $boards = $db->query('SELECT b.id, b.name, b.color FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($ctx) . ' ORDER BY b.is_default DESC, b.position, b.name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($boards as &$bb) $bb['id'] = (int)$bb['id']; unset($bb);
    // Proyectos que ve (para vincular tareas), con sus hitos abiertos
    $projects = $db->query("SELECT p.id, p.name, p.color FROM g7_wk_projects p WHERE p.archived = 0 AND p.status NOT IN ('terminado', 'cancelado') AND " . wk_project_where($ctx) . ' ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $ms = [];
    if ($projects) foreach ($db->query('SELECT id, project_id, name, due_date, done_at FROM g7_wk_milestones WHERE project_id IN (' . wk_ints(array_column($projects, 'id')) . ') ORDER BY position, due_date IS NULL, due_date, id')->fetchAll(PDO::FETCH_ASSOC) as $m)
        $ms[(int)$m['project_id']][] = ['id' => (int)$m['id'], 'name' => $m['name'], 'due_date' => $m['due_date'], 'done' => (bool)$m['done_at']];
    foreach ($projects as &$pp) { $pp['id'] = (int)$pp['id']; $pp['milestones'] = $ms[$pp['id']] ?? []; } unset($pp);
    tk_out(['projects' => $projects, 'fields' => wk_fields($db, $board), 'boards' => $boards, 'field_types' => WK_FIELD_TYPES, 'board' => $board_out, 'role' => $ctx['label'], 'config' => $cfg, 'users' => $users, 'clients' => $clients, 'me' => $uid, 'admin' => $isAdmin, 'reactions' => TK_REACTIONS,
            'prefs' => tk_user_prefs($db, $uid, $cfg), 'tasks' => tk_tasks($db), 'today' => date('Y-m-d'),
            'repeats' => array_map(fn($r) => $r[0], TK_REPEATS), 'templates' => tk_templates($db), 'timer' => tk_timer($db, $uid), 'now' => tk_now()]);

case 'list':
    tk_out(['tasks' => tk_tasks($db, !empty($in['archived']))]);

case 'get':
    tk_out(['task' => tk_detail($db, (int)($in['id'] ?? 0))]);

case 'save':
    if (empty($in['id'])) {
        if (!$boardOk || $ctx['role'] === 'solicitante') tk_fail('No puedes crear tareas en este tablero', 403);
        $in['board_id'] = $board;
    } else {
        // Una tarea de otro tablero (p. ej. tildada desde Mis tareas) se valida con los estados de su propio tablero
        $q = $db->prepare('SELECT board_id FROM g7_tasks WHERE id = ?'); $q->execute([(int)$in['id']]);
        $tb = (int)$q->fetchColumn() ?: wk_general_id($db);
        if ($tb !== $board && ($tbRow = wk_board($db, $tb))) $cfg['statuses'] = wk_statuses($db, $tbRow, ['statuses' => $globalStatuses]);
    }
    $t = tk_apply($db, $cfg, $uid, $in);
    tk_out(['task' => $t]);

case 'move':
    // Kanban: la tarea pasa a "status" y la columna queda en el orden de "order"
    $id = (int)($in['id'] ?? 0);
    $t = tk_task($db, $id);
    if (($in['status'] ?? $t['status']) !== $t['status']) $t = tk_apply($db, $cfg, $uid, ['id' => $id, 'status' => $in['status']]);
    $up = $db->prepare('UPDATE g7_tasks t SET t.position = ? WHERE t.id = ? AND t.status = ? AND ' . $GLOBALS['TK_BOARD']);
    foreach (array_values(array_map('intval', (array)($in['order'] ?? []))) as $i => $tid) $up->execute([$i + 1, $tid, $t['status']]);
    tk_out(['task' => tk_task($db, $id), 'tasks' => tk_tasks($db)]);

case 'archive':
    $id = (int)($in['id'] ?? 0); tk_task($db, $id);
    $on = !empty($in['on']) ? 1 : 0;
    $db->prepare('UPDATE g7_tasks SET archived = ?, updated_at = ? WHERE id = ?')->execute([$on, tk_now(), $id]);
    tk_log($db, $id, $uid, $on ? 'archived' : 'unarchived');
    tk_out(['task' => tk_task($db, $id)]);

case 'archive_done':
    // Archiva todo lo terminado (botón de la columna Finalizado)
    $done = tk_status_ids($cfg, true);
    if (!$done) tk_out(['n' => 0]);
    $inQ = implode(',', array_fill(0, count($done), '?'));
    $q = $db->prepare("UPDATE g7_tasks t SET t.archived = 1 WHERE t.archived = 0 AND t.status IN ($inQ) AND {$GLOBALS['TK_BOARD']} AND {$GLOBALS['TK_WSCOPE']}"); $q->execute($done);
    tk_out(['n' => $q->rowCount()]);

case 'delete':
    $id = (int)($in['id'] ?? 0); $t = tk_task($db, $id);
    if (!$isAdmin && (int)$t['created_by'] !== $uid) tk_fail('Solo quien creó la tarea o un admin pueden borrarla', 403);
    $db->prepare('DELETE r FROM g7_task_comment_reactions r JOIN g7_task_comments c ON c.id = r.comment_id WHERE c.task_id = ?')->execute([$id]);
    // Los adjuntos que viven en Materiales quedan allá (son del cliente); los propios de la tarea se borran
    $dir = __DIR__ . '/_private/tareas/' . $id;
    if (is_dir($dir)) { foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $p) @unlink("$dir/$p"); @rmdir($dir); }
    foreach (['g7_task_assignees', 'g7_task_content', 'g7_task_comments', 'g7_task_activity', 'g7_task_reminders', 'g7_task_checks', 'g7_task_files', 'g7_task_time'] as $tb) $db->prepare("DELETE FROM $tb WHERE task_id = ?")->execute([$id]);
    $db->prepare('DELETE FROM g7_tasks WHERE id = ?')->execute([$id]);
    wk_task_deleted($db, $id, $uid);
    try { $db->prepare("DELETE FROM g7_notifications WHERE type LIKE 'task\\_%' AND target_id = ?")->execute([$id]); } catch (Throwable $e) {}
    tk_out(['ok' => true]);

case 'comment_add':
    $id = (int)($in['task_id'] ?? 0); $t = tk_task($db, $id);
    $body = tk_str($in['body'] ?? '', 5000);
    if ($body === '') tk_fail('Escribe algo');
    // Respuesta: queda colgada del comentario principal del hilo (un solo nivel)
    $root = null; $replyTo = [];
    if (!empty($in['parent_id'])) {
        $q = $db->prepare('SELECT id, user_id, parent_id FROM g7_task_comments WHERE id = ? AND task_id = ?'); $q->execute([(int)$in['parent_id'], $id]);
        $p = $q->fetch(PDO::FETCH_ASSOC);
        if (!$p) tk_fail('El comentario que quieres responder ya no existe', 404);
        $root = $p['parent_id'] ? (int)$p['parent_id'] : (int)$p['id'];
        $replyTo[] = (int)$p['user_id'];
        if ($root !== (int)$p['id']) { $q = $db->prepare('SELECT user_id FROM g7_task_comments WHERE id = ?'); $q->execute([$root]); $replyTo[] = (int)$q->fetchColumn(); }
    }
    $db->prepare('INSERT INTO g7_task_comments (task_id, user_id, parent_id, body, created_at) VALUES (?, ?, ?, ?, ?)')->execute([$id, $uid, $root, $body, tk_now()]);
    wk_tk_from_comment($db, $id, (int)$db->lastInsertId(), $uid, $body);   // es también una respuesta pública de su ticket
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
    $men = tk_mentions($db, $body);
    tk_notify($db, 'mention', $men, $t, $uid, mb_substr($body, 0, 200));
    $replyTo = array_diff($replyTo, $men);
    tk_notify($db, 'reply', $replyTo, $t, $uid, mb_substr($body, 0, 200));
    tk_notify($db, 'comment', array_diff(tk_participants($db, $id), $men, $replyTo), $t, $uid, mb_substr($body, 0, 200));
    wk_auto_run($db, 'comment', $id);
    tk_out(['task' => tk_detail($db, $id)]);

case 'comment_edit':
case 'comment_del':
    $q = $db->prepare('SELECT * FROM g7_task_comments WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) tk_fail('Ese comentario no existe', 404);
    tk_task($db, (int)$c['task_id']);
    if ($c['user_id'] === null) tk_fail('Ese mensaje llegó del sitio base: no se puede cambiar', 403);
    if ((int)$c['user_id'] !== $uid && !$isAdmin) tk_fail('Solo puedes cambiar tus comentarios', 403);
    if ($act === 'comment_del') {
        $db->prepare('DELETE r FROM g7_task_comment_reactions r JOIN g7_task_comments c ON c.id = r.comment_id WHERE c.id = ? OR c.parent_id = ?')->execute([$c['id'], $c['id']]);
        foreach ($db->query('SELECT id FROM g7_task_comments WHERE id = ' . (int)$c['id'] . ' OR parent_id = ' . (int)$c['id'])->fetchAll(PDO::FETCH_COLUMN) as $gone) wk_tk_comment_changed($db, (int)$gone, null);
        $db->prepare('DELETE FROM g7_task_comments WHERE id = ? OR parent_id = ?')->execute([$c['id'], $c['id']]);
    } else {
        $body = tk_str($in['body'] ?? '', 5000);
        if ($body === '') tk_fail('El comentario no puede quedar vacío');
        $db->prepare('UPDATE g7_task_comments SET body = ?, edited_at = ? WHERE id = ?')->execute([$body, tk_now(), $c['id']]);
        wk_tk_comment_changed($db, (int)$c['id'], $body);
        $men = array_diff(tk_mentions($db, $body), tk_mentions($db, $c['body']));
        if ($men) tk_notify($db, 'mention', $men, tk_task($db, (int)$c['task_id']), $uid, mb_substr($body, 0, 200));
    }
    tk_out(['task' => tk_detail($db, (int)$c['task_id'])]);

case 'react':
    // Pone o saca una reacción propia en un comentario
    $q = $db->prepare('SELECT * FROM g7_task_comments WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) tk_fail('Ese comentario no existe', 404);
    tk_task($db, (int)$c['task_id']);
    $e = (string)($in['emoji'] ?? '');
    if (!in_array($e, TK_REACTIONS, true)) tk_fail('Reacción no válida');
    $del = $db->prepare('DELETE FROM g7_task_comment_reactions WHERE comment_id = ? AND user_id = ? AND emoji = ?'); $del->execute([$c['id'], $uid, $e]);
    if (!$del->rowCount()) {
        $db->prepare('INSERT IGNORE INTO g7_task_comment_reactions (comment_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)')->execute([$c['id'], $uid, $e, tk_now()]);
        // Poner y sacar reacciones no llena la campana: un aviso sin leer por persona y tarea alcanza
        $q = $db->prepare("SELECT 1 FROM g7_notifications WHERE user_id = ? AND from_id = ? AND type = 'task_reaction' AND target_id = ? AND read_at IS NULL LIMIT 1");
        $q->execute([(int)$c['user_id'], $uid, (int)$c['task_id']]);
        if (!$q->fetchColumn()) tk_notify($db, 'reaction', [(int)$c['user_id']], tk_task($db, (int)$c['task_id']), $uid, $e . ' a "' . mb_substr($c['body'], 0, 120) . '"');
    }
    tk_out(['task' => tk_detail($db, (int)$c['task_id'])]);

case 'content_search':
    // Contenidos de Promos: los del cliente de la tarea (project/projects = nombre del cliente) o todos
    $q = tk_str($in['q'] ?? '', 80);
    $where = ["(estado IS NULL OR estado = 'activo')"]; $args = [];
    $client = (int)($in['client_id'] ?? 0);
    if ($client && empty($in['all'])) {
        $name = tk_client_name($db, $client);
        $where[] = '(project = ? OR projects LIKE ?)'; $args[] = $name; $args[] = '%' . json_encode($name, JSON_UNESCAPED_UNICODE) . '%';
    }
    if ($q !== '') { $where[] = '(name LIKE ? OR project LIKE ? OR category LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    tk_out(['items' => tk_content_rows($db, implode(' AND ', $where), $args, 40)]);

case 'content_link':
case 'content_unlink':
    $id = (int)($in['task_id'] ?? 0); tk_task($db, $id);
    $cid = (int)($in['content_id'] ?? 0);
    if ($act === 'content_link') {
        if (!tk_content_rows($db, 'id = ?', [$cid], 1)) tk_fail('Ese contenido no existe');
        $db->prepare('INSERT IGNORE INTO g7_task_content (task_id, content_id) VALUES (?, ?)')->execute([$id, $cid]);
        tk_log($db, $id, $uid, 'content', 'vinculó "' . (tk_content_rows($db, 'id = ?', [$cid], 1)[0]['name'] ?? '') . '"');
    } else {
        $db->prepare('DELETE FROM g7_task_content WHERE task_id = ? AND content_id = ?')->execute([$id, $cid]);
    }
    tk_out(['task' => tk_detail($db, $id)]);

case 'prefs_save':
    $p = ['browser' => !empty($in['browser']), 'events' => []];
    foreach (TK_EVENTS as $k => $_) $p['events'][$k] = ['app' => !empty($in['events'][$k]['app']), 'email' => !empty($in['events'][$k]['email'])];
    $db->prepare("INSERT INTO g7_user_prefs (user_id, pref_key, pref_value) VALUES (?, 'tasks_notif', ?) ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)")
       ->execute([$uid, json_encode($p)]);
    tk_out(['prefs' => tk_user_prefs($db, $uid)]);

case 'test_email':
    $to = $db->prepare('SELECT email FROM g7_users WHERE id = ?'); $to->execute([$uid]); $email = (string)$to->fetchColumn();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) tk_fail('Tu usuario no tiene un email válido (Mi perfil)');
    $ok = tk_mail_send($email, 'Prueba de avisos de ' . g7_site('short'), tk_mail_html('Así se ven los avisos', 'Si te llegó este email, los avisos por correo funcionan.', '', tk_task_url(0), 'Email de prueba'), $cfg['notif']['from_name'] ?: (string)g7_site('name'));
    tk_out(['ok' => $ok, 'email' => $email]);

case 'config_save':
    if (!$isAdmin) tk_fail('Solo los administradores cambian la configuración', 403);
    $c = (array)($in['config'] ?? []);
    $clean = function (array $list, bool $withDone) {
        $out = []; $seen = [];
        foreach ($list as $x) {
            $name = tk_str($x['name'] ?? '', 40);
            if ($name === '') continue;
            $id = preg_replace('/[^a-z0-9_]/', '', (string)($x['id'] ?? ''));
            if ($id === '' ) $id = tk_slug($name);
            $base = $id; $n = 2; while (isset($seen[$id])) $id = $base . '_' . $n++;
            $seen[$id] = true;
            $row = ['id' => $id, 'name' => $name, 'color' => tk_color($x['color'] ?? '')];
            if ($withDone) $row['done'] = !empty($x['done']);
            $out[] = $row;
        }
        return array_slice($out, 0, 20);
    };
    $statuses = $clean((array)($c['statuses'] ?? []), true);
    $priorities = $clean((array)($c['priorities'] ?? []), false);
    $labels = $clean((array)($c['labels'] ?? []), false);
    if (!$statuses) tk_fail('Tiene que quedar al menos un estado');
    if (!$priorities) tk_fail('Tiene que quedar al menos una prioridad');
    $n = (array)($c['notif'] ?? []);
    $events = [];
    foreach (TK_EVENTS as $k => $_) $events[$k] = ['on' => !empty($n['events'][$k]['on']), 'app' => !empty($n['events'][$k]['app']), 'email' => !empty($n['events'][$k]['email'])];
    $new = [
        'statuses' => $statuses, 'priorities' => $priorities, 'labels' => $labels,
        'default_priority' => in_array($c['default_priority'] ?? '', array_column($priorities, 'id'), true) ? $c['default_priority'] : $priorities[0]['id'],
        'notif' => [
            'events' => $events, 'user_override' => !empty($n['user_override']), 'email_enabled' => !empty($n['email_enabled']),
            'from_name' => tk_str($n['from_name'] ?? g7_site('name'), 60) ?: (string)g7_site('name'), 'due_days' => max(0, min(14, (int)($n['due_days'] ?? 1))),
            'created_notify' => tk_valid_users($db, $n['created_notify'] ?? []),
        ],
    ];
    // Lo que se borró: las tareas pasan a donde dijo el admin (o al primero de la lista)
    $remap = function (string $col, array $oldList, array $newList, array $map) use ($db) {
        $ids = array_column($newList, 'id');
        foreach (array_diff(array_column($oldList, 'id'), $ids) as $gone) {
            $to = in_array($map[$gone] ?? '', $ids, true) ? $map[$gone] : $ids[0];
            $db->prepare("UPDATE g7_tasks SET $col = ? WHERE $col = ?")->execute([$to, $gone]);
        }
    };
    if ($boardRow['statuses']) {
        // Este tablero tiene estados propios: lo que se editó en "Estados" es suyo; los generales quedan como estaban
        wk_set_board_statuses($db, $boardRow, $statuses, (array)($in['remap']['statuses'] ?? []), ['statuses' => $globalStatuses]);
        $new['statuses'] = $globalStatuses;
    } else {
        $gw = wk_global_status_where($db);
        foreach (array_diff(array_column($globalStatuses, 'id'), array_column($statuses, 'id')) as $gone) {
            $to = in_array($in['remap']['statuses'][$gone] ?? '', array_column($statuses, 'id'), true) ? $in['remap']['statuses'][$gone] : $statuses[0]['id'];
            $db->prepare("UPDATE g7_tasks SET status = ? WHERE status = ? AND $gw")->execute([$to, $gone]);
        }
        wk_sync_completed($db, $statuses, $gw);
    }
    $remap('priority', $cfg['priorities'], $priorities, (array)($in['remap']['priorities'] ?? []));
    $goneLabels = array_diff(array_column($cfg['labels'], 'id'), array_column($labels, 'id'));
    if ($goneLabels) {
        $up = $db->prepare('UPDATE g7_tasks SET labels = ? WHERE id = ?');
        foreach ($db->query("SELECT id, labels FROM g7_tasks WHERE labels <> '[]'")->fetchAll(PDO::FETCH_ASSOC) as $r)
            $up->execute([json_encode(array_values(array_diff(json_decode($r['labels'], true) ?: [], $goneLabels))), $r['id']]);
    }
    $db->prepare("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('tasks_config', ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)")
       ->execute([json_encode($new, JSON_UNESCAPED_UNICODE)]);
    if (function_exists('g7_audit')) g7_audit('tasks_config', 'tareas');
    $out = tk_config($db);
    $out['statuses'] = wk_statuses($db, wk_board($db, $board), $out);
    tk_out(['config' => $out, 'tasks' => tk_tasks($db), 'prefs' => tk_user_prefs($db, $uid)]);

// ── Lista de pasos ───────────────────────────────────────────
case 'check_add':
    $tid = (int)($in['task_id'] ?? 0); tk_task($db, $tid);
    $b = tk_str($in['body'] ?? '', 300); if ($b === '') tk_fail('Escribe el paso');
    $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_task_checks WHERE task_id = ?'); $max->execute([$tid]);
    $db->prepare('INSERT INTO g7_task_checks (task_id, body, position) VALUES (?, ?, ?)')->execute([$tid, $b, (int)$max->fetchColumn() + 1]);
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), $tid]);
    tk_out(['task' => tk_detail($db, $tid)]);

case 'check_set':
    $q = $db->prepare('SELECT * FROM g7_task_checks WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $k = $q->fetch(PDO::FETCH_ASSOC);
    if (!$k) tk_fail('Ese paso ya no existe', 404);
    tk_task($db, (int)$k['task_id']);
    if (array_key_exists('done', $in)) {
        $d = !empty($in['done']) ? 1 : 0;
        $db->prepare('UPDATE g7_task_checks SET done = ?, done_by = ?, done_at = ? WHERE id = ?')->execute([$d, $d ? $uid : null, $d ? tk_now() : null, $k['id']]);
        if ($d) tk_log($db, (int)$k['task_id'], $uid, 'check', $k['body']);
    }
    if (array_key_exists('body', $in)) { $b = tk_str($in['body'], 300); if ($b === '') tk_fail('El paso no puede quedar vacío'); $db->prepare('UPDATE g7_task_checks SET body = ? WHERE id = ?')->execute([$b, $k['id']]); }
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), $k['task_id']]);
    tk_out(['task' => tk_detail($db, (int)$k['task_id'])]);

case 'check_del':
    $q = $db->prepare('SELECT task_id FROM g7_task_checks WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $tid = (int)$q->fetchColumn();
    if (!$tid) tk_fail('Ese paso ya no existe', 404);
    tk_task($db, $tid);
    $db->prepare('DELETE FROM g7_task_checks WHERE id = ?')->execute([(int)$in['id']]);
    tk_out(['task' => tk_detail($db, $tid)]);

case 'check_order':
    $tid = (int)($in['task_id'] ?? 0); tk_task($db, $tid);
    $up = $db->prepare('UPDATE g7_task_checks SET position = ? WHERE id = ? AND task_id = ?');
    foreach (array_values(array_map('intval', (array)($in['ids'] ?? []))) as $i => $kid) $up->execute([$i + 1, $kid, $tid]);
    tk_out(['task' => tk_detail($db, $tid)]);

// ── Adjuntos (subir va por tareas_files.php; acá vincular y quitar) ──
case 'client_files':
    // Sin Materiales por marca: los adjuntos viven en la tarea
    tk_out(['files' => []]);

case 'file_link':
    $tid = (int)($in['task_id'] ?? 0); $t = tk_task($db, $tid);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['client_file_ids'] ?? []))))), 0, 100);
    $chk = $db->prepare('SELECT id, name FROM g7_client_files WHERE id = ? AND client_id = ?');
    $has = $db->prepare('SELECT 1 FROM g7_task_files WHERE task_id = ? AND client_file_id = ?');
    $ins = $db->prepare('INSERT INTO g7_task_files (task_id, client_file_id, uploaded_by, created_at) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($ids as $fid) {
        $chk->execute([$fid, (int)$t['client_id']]); $f = $chk->fetch(PDO::FETCH_ASSOC); if (!$f) continue;
        $has->execute([$tid, $fid]); if ($has->fetch()) continue;
        $ins->execute([$tid, $fid, $uid, tk_now()]); $n++;
    }
    if ($n) tk_log($db, $tid, $uid, 'files', $n === 1 ? 'vinculó un archivo de Materiales' : "vinculó $n archivos de Materiales");
    tk_out(['task' => tk_detail($db, $tid)]);

case 'file_unlink':
    // Quita el adjunto de la tarea. Si era propio de la tarea, se borra; si está en Materiales, queda allá
    $q = $db->prepare('SELECT * FROM g7_task_files WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $f = $q->fetch(PDO::FETCH_ASSOC);
    if (!$f) tk_fail('Ese adjunto ya no existe', 404);
    tk_task($db, (int)$f['task_id']);
    if (!$f['client_file_id'] && $f['stored'] !== '') @unlink(__DIR__ . '/_private/tareas/' . (int)$f['task_id'] . '/' . basename($f['stored']));
    $db->prepare('DELETE FROM g7_task_files WHERE id = ?')->execute([$f['id']]);
    // Si era la primera versión de un archivo con más versiones, la siguiente pasa a ser la primera
    if (!$f['version_of']) {
        $q = $db->prepare('SELECT MIN(id) FROM g7_task_files WHERE version_of = ?'); $q->execute([$f['id']]);
        if ($nr = (int)$q->fetchColumn()) { $db->prepare('UPDATE g7_task_files SET version_of = NULL WHERE id = ?')->execute([$nr]); $db->prepare('UPDATE g7_task_files SET version_of = ? WHERE version_of = ?')->execute([$nr, $f['id']]); }
    }
    tk_out(['task' => tk_detail($db, (int)$f['task_id'])]);

// ── Horas ────────────────────────────────────────────────────
case 'time_start':
    $tid = (int)($in['task_id'] ?? 0); tk_task($db, $tid);
    $stopped = tk_timer_stop($db, $uid);   // un solo reloj por persona
    $db->prepare('INSERT INTO g7_task_time (task_id, user_id, started_at, created_at) VALUES (?, ?, ?, ?)')->execute([$tid, $uid, tk_now(), tk_now()]);
    tk_out(['timer' => tk_timer($db, $uid), 'now' => tk_now(), 'stopped' => $stopped, 'tasks' => tk_tasks($db)]);

case 'time_stop':
    $tid = tk_timer_stop($db, $uid);
    tk_out(['timer' => null, 'task_id' => $tid, 'tasks' => tk_tasks($db), 'task' => $tid && !empty($in['detail']) ? tk_detail($db, $tid) : null]);

case 'time_add':
    $tid = (int)($in['task_id'] ?? 0); tk_task($db, $tid);
    $min = (int)($in['minutes'] ?? 0); if ($min < 1 || $min > 24 * 60) tk_fail('Pon entre 1 minuto y 24 horas');
    $day = tk_date($in['date'] ?? '') ?: date('Y-m-d');
    $start = $day . ' ' . (preg_match('/^\d{2}:\d{2}$/', (string)($in['time'] ?? '')) ? $in['time'] : '09:00') . ':00';
    $db->prepare('INSERT INTO g7_task_time (task_id, user_id, started_at, ended_at, minutes, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$tid, $uid, $start, date('Y-m-d H:i:s', strtotime($start) + $min * 60), $min, tk_str($in['note'] ?? '', 250), tk_now()]);
    tk_out(['task' => tk_detail($db, $tid), 'tasks' => tk_tasks($db)]);

case 'time_del':
    $q = $db->prepare('SELECT * FROM g7_task_time WHERE id = ?'); $q->execute([(int)($in['id'] ?? 0)]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) tk_fail('Ese registro ya no existe', 404);
    tk_task($db, (int)$m['task_id']);
    if (!$isAdmin && (int)$m['user_id'] !== $uid) tk_fail('Solo puedes borrar tus propias horas', 403);
    $db->prepare('DELETE FROM g7_task_time WHERE id = ?')->execute([$m['id']]);
    tk_out(['task' => tk_detail($db, (int)$m['task_id']), 'tasks' => tk_tasks($db), 'timer' => tk_timer($db, $uid)]);

case 'time_report':
    // Horas cargadas entre dos fechas. Los admins ven las de todos; el resto, las suyas
    $from = tk_date($in['from'] ?? '') ?: date('Y-m-01'); $to = tk_date($in['to'] ?? '') ?: date('Y-m-t');
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $who = '';
    if (!$isAdmin) { $who = ' AND m.user_id = ?'; $args[] = $uid; }
    elseif (!empty($in['user'])) { $who = ' AND m.user_id = ?'; $args[] = (int)$in['user']; }
    $q = $db->prepare("SELECT m.id, m.task_id, m.user_id, m.started_at, m.minutes, m.note, t.title, t.client_id
        FROM g7_task_time m JOIN g7_tasks t ON t.id = m.task_id WHERE m.ended_at IS NOT NULL AND m.started_at BETWEEN ? AND ?$who ORDER BY m.started_at DESC");
    $q->execute($args);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'task_id' => (int)$r['task_id'], 'user_id' => (int)$r['user_id'], 'minutes' => (int)$r['minutes'],
        'client_id' => $r['client_id'] ? (int)$r['client_id'] : null] + $r, $q->fetchAll(PDO::FETCH_ASSOC));
    tk_out(['rows' => $rows, 'from' => $from, 'to' => $to, 'all' => $isAdmin]);

// ── Varias tareas a la vez ───────────────────────────────────
case 'bulk':
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? []))))), 0, 300);
    if (!$ids) tk_fail('No elegiste tareas');
    $op = (string)($in['op'] ?? 'set'); $n = 0; $skipped = 0;
    foreach ($ids as $id) {
        $q = $db->prepare('SELECT t.id, t.created_by, t.archived FROM g7_tasks t WHERE t.id = ? AND ' . $GLOBALS['TK_WSCOPE']); $q->execute([$id]); $t = $q->fetch(PDO::FETCH_ASSOC);
        if (!$t) continue;
        if ($op === 'archive' || $op === 'unarchive') {
            $db->prepare('UPDATE g7_tasks SET archived = ?, updated_at = ? WHERE id = ?')->execute([$op === 'archive' ? 1 : 0, tk_now(), $id]);
            tk_log($db, $id, $uid, $op === 'archive' ? 'archived' : 'unarchived'); $n++;
        } elseif ($op === 'delete') {
            if (!$isAdmin && (int)$t['created_by'] !== $uid) { $skipped++; continue; }
            $db->prepare('DELETE r FROM g7_task_comment_reactions r JOIN g7_task_comments c ON c.id = r.comment_id WHERE c.task_id = ?')->execute([$id]);
            $dir = __DIR__ . '/_private/tareas/' . $id;
            if (is_dir($dir)) { foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $p) @unlink("$dir/$p"); @rmdir($dir); }
            foreach (['g7_task_assignees', 'g7_task_content', 'g7_task_comments', 'g7_task_activity', 'g7_task_reminders', 'g7_task_checks', 'g7_task_files', 'g7_task_time'] as $tb) $db->prepare("DELETE FROM $tb WHERE task_id = ?")->execute([$id]);
            $db->prepare('DELETE FROM g7_tasks WHERE id = ?')->execute([$id]);
            wk_task_deleted($db, $id, $uid);
            try { $db->prepare("DELETE FROM g7_notifications WHERE type LIKE 'task\\_%' AND target_id = ?")->execute([$id]); } catch (Throwable $e) {}
            $n++;
        } else {
            $f = array_intersect_key((array)($in['fields'] ?? []), array_flip(['status', 'priority', 'due_date', 'client_id', 'labels']));
            // Responsables: "add" suma a los que ya tiene; "set" reemplaza
            if (isset($in['fields']['assignees_add'])) {
                $q = $db->prepare('SELECT user_id FROM g7_task_assignees WHERE task_id = ?'); $q->execute([$id]);
                $f['assignees'] = array_values(array_unique(array_merge(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), array_map('intval', (array)$in['fields']['assignees_add']))));
            }
            if (array_key_exists('assignees', (array)($in['fields'] ?? []))) $f['assignees'] = (array)$in['fields']['assignees'];
            if (!$f) tk_fail('No hay nada para cambiar');
            tk_apply($db, $cfg, $uid, ['id' => $id] + $f); $n++;
        }
    }
    tk_out(['n' => $n, 'skipped' => $skipped, 'tasks' => tk_tasks($db)]);

// ── Plantillas ───────────────────────────────────────────────
case 'tpl_save':
    $name = tk_str($in['name'] ?? '', 80); if ($name === '') tk_fail('Pon un nombre para la plantilla');
    $d = (array)($in['data'] ?? []);
    $data = [
        'title' => tk_str($d['title'] ?? '', 250), 'description' => tk_str($d['description'] ?? '', 20000),
        'priority' => in_array($d['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $d['priority'] : $cfg['default_priority'],
        'labels' => array_values(array_intersect((array)($d['labels'] ?? []), array_column($cfg['labels'], 'id'))),
        'assignees' => tk_valid_users($db, $d['assignees'] ?? []),
        'checks' => array_values(array_filter(array_map(fn($b) => tk_str(is_array($b) ? ($b['body'] ?? '') : $b, 300), array_slice((array)($d['checks'] ?? []), 0, 100)), 'strlen')),
        'repeat_rule' => isset(TK_REPEATS[$d['repeat_rule'] ?? '']) ? $d['repeat_rule'] : '',
        'due_in' => max(0, min(365, (int)($d['due_in'] ?? 0))),   // días desde que se crea (0 = sin fecha)
    ];
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $q = $db->prepare('SELECT created_by FROM g7_task_templates WHERE id = ?'); $q->execute([$id]); $own = $q->fetchColumn();
        if ($own === false) tk_fail('Esa plantilla ya no existe', 404);
        if (!$isAdmin && (int)$own !== $uid) tk_fail('Solo quien la creó o un admin pueden cambiarla', 403);
        $db->prepare('UPDATE g7_task_templates SET name = ?, data = ? WHERE id = ?')->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE), $id]);
    } else $db->prepare('INSERT INTO g7_task_templates (name, data, created_by, created_at) VALUES (?, ?, ?, ?)')->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE), $uid, tk_now()]);
    tk_out(['templates' => tk_templates($db)]);

case 'tpl_del':
    $id = (int)($in['id'] ?? 0);
    $q = $db->prepare('SELECT created_by FROM g7_task_templates WHERE id = ?'); $q->execute([$id]); $own = $q->fetchColumn();
    if ($own === false) tk_fail('Esa plantilla ya no existe', 404);
    if (!$isAdmin && (int)$own !== $uid) tk_fail('Solo quien la creó o un admin pueden borrarla', 403);
    $db->prepare('DELETE FROM g7_task_templates WHERE id = ?')->execute([$id]);
    tk_out(['templates' => tk_templates($db)]);

// ── Fase 2: subtareas, dependencias, campos y mover de tablero ──
case 'move_board':
    // Pasa una tarea (con sus subtareas) a otro tablero. El estado se acomoda a los estados del destino.
    $id = (int)($in['id'] ?? 0); $t = tk_task($db, $id);
    if ($ctx['role'] === 'solicitante') tk_fail('No puedes mover tareas', 403);
    if ($t['parent_id']) tk_fail('Las subtareas viajan con su tarea principal');
    $to = wk_board($db, (int)($in['to'] ?? 0));
    if (!$to || $to['archived'] || !wk_board_visible($db, $ctx, $to['id'])) tk_fail('Ese tablero no existe o no tienes acceso', 404);
    $from = (int)($t['board_id'] ?: wk_general_id($db));
    if ($to['id'] === $from) tk_out(['task' => tk_task($db, $id)]);
    $toSt = wk_statuses($db, $to, tk_config($db));
    $ids = array_merge([$id], array_map('intval', $db->query('SELECT id FROM g7_tasks WHERE parent_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN)));
    $firstDone = array_values(array_filter($toSt, fn($s) => !empty($s['done'])))[0]['id'] ?? $toSt[0]['id'];
    $fieldsTo = array_column(wk_fields($db, $to['id']), 'id');
    foreach ($ids as $x) {
        $q = $db->prepare('SELECT status, completed_at FROM g7_tasks WHERE id = ?'); $q->execute([$x]); $r = $q->fetch(PDO::FETCH_ASSOC);
        $st = in_array($r['status'], array_column($toSt, 'id'), true) ? $r['status'] : ($r['completed_at'] ? $firstDone : $toSt[0]['id']);
        $db->prepare('UPDATE g7_tasks SET board_id = ?, status = ?, updated_at = ? WHERE id = ?')->execute([$to['id'], $st, tk_now(), $x]);
        // Los campos son de cada tablero: los valores del tablero de origen no tienen dónde ir
        $db->prepare('DELETE FROM g7_wk_field_values WHERE task_id = ?' . ($fieldsTo ? ' AND field_id NOT IN (' . wk_ints($fieldsTo) . ')' : ''))->execute([$x]);
    }
    wk_sync_completed($db, $toSt, 'id IN (' . wk_ints($ids) . ')');
    tk_log($db, $id, $uid, 'board', $boardRow['id'] === $from ? $boardRow['name'] . ' → ' . $to['name'] : '→ ' . $to['name']);
    wk_tk_from_task($db, $id, $uid);   // el ticket de una tarea cambia de departamento con su tablero
    tk_out(['ok' => true, 'board' => $to['id']]);

case 'dep_search':
    // Tareas que puede elegir como "bloqueada por": las que ve, abiertas o no, menos ella misma
    $id = (int)($in['task_id'] ?? 0); tk_task($db, $id);
    $q = tk_str($in['q'] ?? '', 80);
    $g = wk_general_id($db);
    $st = $db->prepare("SELECT t.id, t.title, t.status, t.completed_at, COALESCE(t.board_id, $g) AS board_id, b.name AS board_name FROM g7_tasks t
        LEFT JOIN g7_wk_boards b ON b.id = COALESCE(t.board_id, $g)
        WHERE t.archived = 0 AND t.id <> ? AND t.title LIKE ? AND {$GLOBALS['TK_WSCOPE']}
        ORDER BY t.completed_at IS NOT NULL, (COALESCE(t.board_id, $g) = ?) DESC, t.updated_at DESC LIMIT 30");
    $st->execute([$id, '%' . $q . '%', $board]);
    tk_out(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'board_id' => (int)$r['board_id']] + $r, $st->fetchAll(PDO::FETCH_ASSOC))]);

case 'dep_add':
case 'dep_del':
    $id = (int)($in['task_id'] ?? 0); $t = tk_task($db, $id);
    $on = (int)($in['depends_on'] ?? 0);
    if ($act === 'dep_del') {
        $db->prepare('DELETE FROM g7_wk_task_deps WHERE task_id = ? AND depends_on = ?')->execute([$id, $on]);
        tk_log($db, $id, $uid, 'dep_del', '#' . $on);
        tk_out(['task' => tk_detail($db, $id)]);
    }
    $b = tk_task($db, $on);   // también la tiene que poder ver
    if ($on === $id) tk_fail('Una tarea no puede depender de sí misma');
    if (wk_dep_reaches($db, $on, $id)) tk_fail('Eso armaría un círculo: "' . $b['title'] . '" ya depende de esta tarea');
    $db->prepare('INSERT IGNORE INTO g7_wk_task_deps (task_id, depends_on, created_by, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $on, $uid, tk_now()]);
    tk_log($db, $id, $uid, 'dep', $b['title']);
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
    tk_out(['task' => tk_detail($db, $id)]);

case 'field_set':
    $id = (int)($in['task_id'] ?? 0); $t = tk_task($db, $id);
    $fid = (int)($in['field_id'] ?? 0);
    $f = null; foreach (wk_fields($db, (int)($t['board_id'] ?: wk_general_id($db))) as $x) if ($x['id'] === $fid) $f = $x;
    if (!$f) tk_fail('Ese campo no es de este tablero', 404);
    $v = wk_field_value($db, $f, $in['value'] ?? '');
    if ($v === null) tk_fail('Valor no válido para "' . $f['name'] . '"');
    if ($v === '') $db->prepare('DELETE FROM g7_wk_field_values WHERE task_id = ? AND field_id = ?')->execute([$id, $fid]);
    else $db->prepare('INSERT INTO g7_wk_field_values (task_id, field_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$id, $fid, $v]);
    $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
    tk_log($db, $id, $uid, 'field', $f['name'] . ': ' . ($v === '' ? 'vacío' : ($f['type'] === 'select' ? (array_column($f['options'], 'name', 'id')[$v] ?? $v) : ($f['type'] === 'person' ? tk_user_name($db, (int)$v) : ($f['type'] === 'check' ? 'sí' : $v)))));
    tk_out(['task' => tk_task($db, $id)]);

default:
    tk_fail('Acción desconocida');
}
