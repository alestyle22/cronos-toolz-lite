<?php
/**
 * trabajo/link_lib.php — Ticket y tarea como un mismo elemento (2026-11-10). Doc: toolz/TRABAJO.md › "Ticket = tarea".
 *
 * - Todo ticket nuevo (Trabajo, sitio base, formulario del panel de solicitudes, chat) crea su tarea en el tablero de su departamento.
 * - Toda tarea nueva (no las subtareas) crea su ticket, de origen "tarea": sin primera respuesta y con la fecha de la tarea como plazo.
 * - Lo demás se sincroniza en los dos sentidos: título, prioridad, responsable, terminado/resuelto y el plazo.
 * - La conversación es una sola: cada comentario de la tarea es una respuesta pública del ticket y cada mensaje del ticket es un
 *   comentario de la tarea (las notas internas del ticket se ven en la tarea marcadas como internas). Vínculo: g7_wk_ticket_msgs.comment_id.
 * - "Esperando respuesta" frena el reloj del SLA: al salir, los plazos se corren lo que duró la pausa (paused_at / paused_min).
 * Los cambios que hace la sincronización no vuelven a disparar la sincronización (wk_link_busy).
 */

const WK_TK_ORIGINS = ['ticket' => 'Ticket interno', 'tarea' => 'Tarea', 'base' => 'Sitio base', 'form' => 'Panel de solicitudes', 'chat' => 'Chat (tarea rápida)', 'juego' => 'Juego', 'social' => 'Social Studio', 'blog' => 'Blog Studio'];   // blog: tarea editorial ⇄ ticket (blog/posts_lib.php, BLOG_STUDIO.md)   // social: "Convertir en ticket" de social/ (SOCIAL_STUDIO.md)   // juego: reportes desde el juego (juegos/reportes.php)
const WK_TK_OPEN = ['abierto', 'en_curso', 'esperando'];

/** v8: columnas del vínculo, pausa del SLA, origen y formularios del panel. */
function wk_install_v8(PDO $db): void {
    $db->exec("ALTER TABLE g7_wk_tickets ADD COLUMN IF NOT EXISTS origin VARCHAR(10) NOT NULL DEFAULT 'ticket', ADD COLUMN IF NOT EXISTS paused_at DATETIME NULL,
        ADD COLUMN IF NOT EXISTS paused_min INT NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS form_id INT NULL, ADD COLUMN IF NOT EXISTS answers TEXT NULL,
        ADD COLUMN IF NOT EXISTS base_id INT NULL, ADD COLUMN IF NOT EXISTS base_info TEXT NULL");   // las del base existen en todas las instalaciones (vacías fuera del Toolz principal)
    $db->exec('ALTER TABLE g7_wk_tickets ADD INDEX IF NOT EXISTS task_id (task_id), ADD INDEX IF NOT EXISTS resolution_due (resolution_due), ADD INDEX IF NOT EXISTS updated_at (updated_at)');
    $db->exec("UPDATE g7_wk_tickets SET origin = 'base' WHERE base_id IS NOT NULL OR base_info IS NOT NULL");
    $db->exec('ALTER TABLE g7_wk_ticket_msgs ADD COLUMN IF NOT EXISTS comment_id INT NULL, ADD COLUMN IF NOT EXISTS ext_name VARCHAR(160) NULL');
    $db->exec('ALTER TABLE g7_wk_ticket_msgs ADD INDEX IF NOT EXISTS comment_id (comment_id)');
    $db->exec('ALTER TABLE g7_task_comments MODIFY user_id INT NULL');
    $db->exec("ALTER TABLE g7_task_comments ADD COLUMN IF NOT EXISTS internal TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS ext_name VARCHAR(160) NULL");
    $db->exec("ALTER TABLE g7_wk_forms ADD COLUMN IF NOT EXISTS category_id INT NULL, ADD COLUMN IF NOT EXISTS priority VARCHAR(40) NULL");
    $db->exec("INSERT INTO g7_config (cfg_key, cfg_value) VALUES ('work_schema', '8') ON DUPLICATE KEY UPDATE cfg_value = '8'");
}

function wk_link_busy(?bool $set = null): bool { static $b = 0; if ($set === true) $b++; elseif ($set === false) $b = max(0, $b - 1); return $b > 0; }
function wk_cfg_get(PDO $db, string $k, ?string $def = null): ?string { $q = $db->prepare('SELECT cfg_value FROM g7_config WHERE cfg_key = ?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? $def : (string)$v; }
function wk_cfg_put(PDO $db, string $k, string $v): void { $db->prepare('INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)')->execute([$k, $v]); }
function wk_tk_code(int $id): string { return 'T-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }

/** Tablero de cada departamento para las tareas de sus tickets: {team_id: board_id}, "0" = sin departamento. */
function wk_team_boards(PDO $db): array { return array_map('intval', json_decode((string)wk_cfg_get($db, 'work_team_boards', '{}'), true) ?: []); }
/** Tablero donde cae la tarea de un ticket: el elegido para el departamento, si no el primero del departamento, si no el General. */
function wk_tk_board(PDO $db, ?int $teamId): int {
    $map = wk_team_boards($db);
    $id = $map[(string)(int)$teamId] ?? 0;
    if ($id && ($b = wk_board($db, $id)) && !$b['archived']) return $id;
    if ($teamId) {
        $q = $db->prepare('SELECT id FROM g7_wk_boards WHERE team_id = ? AND archived = 0 ORDER BY is_default DESC, position, id LIMIT 1'); $q->execute([$teamId]);
        if ($id = (int)$q->fetchColumn()) return $id;
    }
    return wk_general_id($db);
}

/** Categoría de los tickets que nacen como tarea (se crea sola, sin plazos). */
function wk_task_cat(PDO $db): int {
    $id = (int)wk_cfg_get($db, 'work_task_cat', '0');
    if ($id) { $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); if ($q->fetchColumn()) return $id; }
    $pos = (int)$db->query('SELECT COALESCE(MAX(position), 0) FROM g7_wk_ticket_cats')->fetchColumn() + 1;
    $db->prepare("INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES ('Tareas', NULL, '#8b8b94', 1, ?)")->execute([$pos]);
    $id = (int)$db->lastInsertId();
    wk_cfg_put($db, 'work_task_cat', (string)$id);
    return $id;
}

function wk_tk_row(PDO $db, int $id): ?array { $q = $db->prepare('SELECT * FROM g7_wk_tickets WHERE id = ?'); $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
function wk_tk_of_task(PDO $db, int $taskId): ?array { $q = $db->prepare('SELECT * FROM g7_wk_tickets WHERE task_id = ? ORDER BY id LIMIT 1'); $q->execute([$taskId]); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
function wk_task_row(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM g7_tasks WHERE id = ?'); $q->execute([$id]); $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t) return null;
    $q = $db->prepare('SELECT user_id FROM g7_task_assignees WHERE task_id = ? ORDER BY user_id'); $q->execute([$id]);
    $t['assignees'] = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    return $t;
}
/** Evento visible en la conversación del ticket (no sale al base). */
function wk_tk_event(PDO $db, int $ticketId, ?int $by, string $body, bool $internal = false): void {
    $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, ?, ?, ?, 'event', ?)")->execute([$ticketId, $by ?: null, $body, $internal ? 1 : 0, tk_now()]);
}

/**
 * Campos que cambian cuando un ticket pasa a $s: fechas de resuelto/cerrado y la pausa del SLA en "Esperando".
 * Al salir de la pausa los plazos se corren lo que duró (solo tickets con SLA; las tareas no se pausan).
 */
function wk_tk_status_fields(array $t, string $s, string $at): array {
    $f = ['status' => $s];
    if ($s === 'resuelto') $f['resolved_at'] = $t['resolved_at'] ?: $at;
    if ($s === 'cerrado') { $f['closed_at'] = $at; $f['resolved_at'] = $t['resolved_at'] ?: $at; }
    if (in_array($s, WK_TK_OPEN, true)) { $f['resolved_at'] = null; $f['closed_at'] = null; }
    $paused = !empty($t['paused_at']);
    if ($s === 'esperando' && !$paused && ($t['origin'] ?? 'ticket') !== 'tarea') $f['paused_at'] = $at;
    if ($s !== 'esperando' && $paused) {
        $min = max(0, (int)round((strtotime($at) - strtotime($t['paused_at'])) / 60));
        $f['paused_at'] = null; $f['paused_min'] = (int)($t['paused_min'] ?? 0) + $min;
        $shift = fn($d) => $d ? date('Y-m-d H:i:s', strtotime($d) + $min * 60) : null;
        if (!$t['first_response_at'] && $t['first_response_due']) $f['first_response_due'] = $shift($t['first_response_due']);
        if ($t['resolution_due'] && !in_array($s, ['resuelto', 'cerrado'], true)) $f['resolution_due'] = $shift($t['resolution_due']);
        // Si con la pausa el plazo volvió a quedar por delante, puede volver a avisar
        $flags = (int)($t['breach_flags'] ?? 0);
        if (isset($f['first_response_due']) && $f['first_response_due'] > $at) $flags &= ~(1 | 4);
        if (isset($f['resolution_due']) && $f['resolution_due'] > $at) $flags &= ~(2 | 8);
        $f['breach_flags'] = $flags;
    }
    return $f;
}
function wk_tk_set(PDO $db, int $id, array $f): void {
    if (!$f) return;
    $f['updated_at'] = $f['updated_at'] ?? tk_now();
    $db->prepare('UPDATE g7_wk_tickets SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
    if (function_exists('pt_on_ticket_set')) pt_on_ticket_set($db, $id, $f);   // etapa del portal y carga por día (portal_lib.php)
}
/** Plazos de un ticket según su categoría y prioridad, contados desde que se abrió más el tiempo en pausa. */
function wk_tk_dues(PDO $db, array $t, string $priority): ?array {
    // Tickets del chat = tareas rápidas: un solo plazo propio (Tickets › Configuración), sin importar categoría ni prioridad
    if (($t['origin'] ?? '') === 'chat' && ($cm = wk_tk_chat_min($db))) {
        $c0 = strtotime($t['created_at']) + (int)($t['paused_min'] ?? 0) * 60;
        return ['first_response_due' => null, 'resolution_due' => date('Y-m-d H:i:s', $c0 + $cm * 60)];
    }
    $sla = wk_sla_for($db, $t['category_id'] ? (int)$t['category_id'] : null, $priority);
    if (!$sla) return null;
    $c0 = strtotime($t['created_at']) + (int)($t['paused_min'] ?? 0) * 60;
    return ['first_response_due' => date('Y-m-d H:i:s', $c0 + $sla['response'] * 60), 'resolution_due' => date('Y-m-d H:i:s', $c0 + $sla['resolution'] * 60)];
}
/** Plazo de los tickets que nacen en el chat (tareas rápidas), en minutos. 0 = usan los plazos de su categoría. CHAT_ACCIONES.md */
function wk_tk_chat_min(PDO $db): int {
    return max(0, min(500000, (int)wk_cfg_get($db, 'work_tk_chat_min', '240')));
}
/** Avisa al base de un cambio de estado (si el ticket vino de ahí). */
function wk_tk_base_status(PDO $db, array $t, string $status, ?int $by): void {
    if (empty($t['base_id'])) return;
    if (!function_exists('wb_after')) require_once __DIR__ . '/base_sync.php';
    wb_after($db, $t, null, null, $status, $by ? wk_user_name($db, $by) : 'Cronos');
}

/** Crea la tarea de un ticket que no tiene (tablero de su departamento, mismo título, prioridad, responsable y plazo). Devuelve su id. */
function wk_tk_make_task(PDO $db, int $ticketId, ?int $by = null): int {
    $t = wk_tk_row($db, $ticketId);
    if (!$t) return 0;
    if ($t['task_id'] && wk_task_row($db, (int)$t['task_id'])) return (int)$t['task_id'];
    // Tablero: el del formulario del panel si tiene uno (WK_TK_BOARD), si no el del departamento
    $forced = (int)($GLOBALS['WK_TK_BOARD'] ?? 0);
    $board = wk_board($db, $forced && ($fb = wk_board($db, $forced)) && !$fb['archived'] ? $forced : wk_tk_board($db, $t['team_id'] ? (int)$t['team_id'] : null));
    $cfg = tk_config($db);
    $sts = wk_statuses($db, $board, $cfg);
    $done = in_array($t['status'], ['resuelto', 'cerrado'], true);
    $doneSt = array_values(array_filter($sts, fn($s) => !empty($s['done'])))[0]['id'] ?? null;
    $st = $done && $doneSt ? $doneSt : $sts[0]['id'];
    $now = tk_now();
    $desc = (string)$t['description'];
    if (!empty($t['base_info']) && ($info = json_decode((string)$t['base_info'], true))) {
        if (!function_exists('wb_from')) require_once __DIR__ . '/base_sync.php';
        $desc = 'Ticket del sitio base · ' . wb_from($info) . "\n\n" . $desc;
    } elseif (($t['origin'] ?? '') === 'juego') {   // reporte del juego: de quién, el tipo y los datos del juego (juegos/reportes_lib.php)
        require_once dirname(__DIR__) . '/juegos/reportes_lib.php';
        $desc = jr_task_desc($db, $t);
    }
    $prio = in_array($t['priority'], array_column($cfg['priorities'], 'id'), true) ? $t['priority'] : $cfg['default_priority'];
    $creator = (int)$t['requester_id'] ?: ($by ?: null);
    $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_tasks WHERE status = ?'); $max->execute([$st]);
    wk_link_busy(true);
    try {
        $db->prepare('INSERT INTO g7_tasks (board_id, title, description, status, priority, due_date, labels, position, created_by, created_at, updated_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$board['id'], mb_substr($t['subject'], 0, 250), mb_substr($desc, 0, 20000), $st, $prio, $t['resolution_due'] ? substr($t['resolution_due'], 0, 10) : null, '[]',
                      (float)$max->fetchColumn() + 1, $creator, $now, $now, $done && $doneSt ? $now : null]);
        $tid = (int)$db->lastInsertId();
        if ($t['assignee_id']) $db->prepare('INSERT IGNORE INTO g7_task_assignees (task_id, user_id) VALUES (?, ?)')->execute([$tid, (int)$t['assignee_id']]);
        tk_log($db, $tid, $by, 'created', wk_tk_code($ticketId));
        $db->prepare('UPDATE g7_wk_tickets SET task_id = ? WHERE id = ?')->execute([$tid, $ticketId]);
        wk_task_events($db, null, ['id' => $tid, 'status' => $st, 'priority' => $prio, 'completed_at' => null], $t['assignee_id'] ? [(int)$t['assignee_id']] : [], $by);
        wk_tk_mirror($db, $ticketId);
    } finally { wk_link_busy(false); }
    return $tid;
}

/**
 * Lo que pasó en una tarea (lo llama wk_task_events): si es nueva, nace su ticket; si no, el ticket se pone al día.
 * Las subtareas no tienen ticket.
 */
function wk_tk_on_task(PDO $db, ?array $old, int $taskId, ?int $by): void {
    if (wk_link_busy()) return;
    try {
        if (!$old) { wk_tk_from_new_task($db, $taskId, $by); return; }
        wk_tk_from_task($db, $taskId, $by);
    } catch (Throwable $e) { error_log('[trabajo] ticket de la tarea ' . $taskId . ': ' . $e->getMessage()); }
}
function wk_tk_from_new_task(PDO $db, int $taskId, ?int $by): int {
    $x = wk_task_row($db, $taskId);
    if (!$x || $x['parent_id'] || wk_tk_of_task($db, $taskId)) return 0;
    $board = wk_board($db, (int)($x['board_id'] ?: wk_general_id($db)));
    $team = $board['team_id'] ?? null;
    $now = tk_now();
    $done = (bool)$x['completed_at'];
    $db->prepare('INSERT INTO g7_wk_tickets (subject, description, category_id, team_id, priority, status, requester_id, assignee_id, resolution_due, resolved_at, closed_at, task_id, origin, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, \'tarea\', ?, ?)')
       ->execute([mb_substr($x['title'], 0, 200), mb_substr((string)$x['description'], 0, 20000), wk_task_cat($db), $team, $x['priority'], $done ? 'resuelto' : 'abierto',
                  (int)$x['created_by'], $x['assignees'][0] ?? null, $x['due_date'] ? $x['due_date'] . ' 23:59:59' : null, $done ? $now : null, $taskId, $x['created_at'] ?: $now, $now]);
    $id = (int)$db->lastInsertId();
    wk_tk_event($db, $id, $by ?: ((int)$x['created_by'] ?: null), 'creó la tarea en ' . ($board['name'] ?? 'un tablero'));
    return $id;
}

/** El ticket sigue a su tarea: título, prioridad, responsable, terminado y, si nació como tarea, el plazo y el departamento. */
function wk_tk_from_task(PDO $db, int $taskId, ?int $by): void {
    $t = wk_tk_of_task($db, $taskId); $x = wk_task_row($db, $taskId);
    if (!$t || !$x) return;
    $f = []; $ev = []; $now = tk_now(); $cfg = tk_config($db);
    if (trim($x['title']) !== '' && mb_substr($x['title'], 0, 200) !== $t['subject']) { $f['subject'] = mb_substr($x['title'], 0, 200); $ev[] = 'cambió el título'; }
    if ($x['priority'] !== $t['priority'] && in_array($x['priority'], array_column($cfg['priorities'], 'id'), true)) {
        $f['priority'] = $x['priority']; $ev[] = 'cambió la prioridad a ' . tk_name_of($cfg['priorities'], $x['priority']);
        if ($t['origin'] !== 'tarea' && ($d = wk_tk_dues($db, $t, $x['priority']))) { $f += $d; $f['breach_flags'] = 0; }
    }
    $asg = $x['assignees'];
    $cur = $t['assignee_id'] ? (int)$t['assignee_id'] : null;
    if ($cur === null ? $asg : !in_array($cur, $asg, true)) {
        $new = $asg[0] ?? null;
        if ($new !== $cur) { $f['assignee_id'] = $new; $ev[] = $new ? 'lo asignó a ' . wk_user_name($db, $new) : 'le sacó el responsable'; }
    }
    $open = in_array($t['status'], WK_TK_OPEN, true);
    $status = null;
    if ($x['completed_at'] && $open) { $status = 'resuelto'; $ev[] = 'pasó a Resuelto (se terminó la tarea)'; }
    elseif (!$x['completed_at'] && !$open) { $status = 'en_curso'; $ev[] = 'pasó a En curso (se reabrió la tarea)'; }
    elseif (!$x['completed_at'] && $t['status'] === 'abierto') {
        // La tarea empezó a moverse (dejó el primer estado de su tablero): el ticket pasa a En curso
        $sts = wk_statuses($db, wk_board($db, (int)($x['board_id'] ?: wk_general_id($db))), $cfg);
        if ($x['status'] !== ($sts[0]['id'] ?? $x['status'])) { $status = 'en_curso'; $ev[] = 'pasó a En curso (la tarea está en ' . tk_name_of($sts, $x['status']) . ')'; }
    }
    if ($status) $f += wk_tk_status_fields($t + ['origin' => 'ticket'], $status, $now);
    if ($t['origin'] === 'tarea') {
        $due = $x['due_date'] ? $x['due_date'] . ' 23:59:59' : null;
        if ($due !== $t['resolution_due']) { $f['resolution_due'] = $due; $f['breach_flags'] = 0; $ev[] = $due ? 'cambió el plazo al ' . date('d/m/Y', strtotime($due)) : 'quitó el plazo'; }
        $b = wk_board($db, (int)($x['board_id'] ?: wk_general_id($db)));
        $team = $b['team_id'] ?? null;
        if ($team !== ($t['team_id'] ? (int)$t['team_id'] : null)) $f['team_id'] = $team;
    }
    if (!$f) return;
    wk_link_busy(true);
    try {
        wk_tk_set($db, (int)$t['id'], $f);
        // Si cambió el plazo de un ticket con SLA (p. ej. por la prioridad), la fecha de la tarea lo sigue
        if ($t['origin'] !== 'tarea' && !empty($f['resolution_due']) && substr($f['resolution_due'], 0, 10) !== $x['due_date'])
            $db->prepare('UPDATE g7_tasks SET due_date = ? WHERE id = ?')->execute([substr($f['resolution_due'], 0, 10), $taskId]);
        if ($ev) wk_tk_event($db, (int)$t['id'], $by, implode(' · ', $ev));
        if ($status) wk_tk_base_status($db, $t, $status, $by);
    } finally { wk_link_busy(false); }
}

/** La tarea sigue a su ticket: título, prioridad, responsable (suma al nuevo; saca al anterior si se pasa $prevAssignee), terminada y plazo. */
function wk_task_from_tk(PDO $db, int $ticketId, ?int $by, ?int $prevAssignee = null): void {
    $t = wk_tk_row($db, $ticketId);
    if (!$t || !$t['task_id'] || wk_link_busy()) return;
    $x = wk_task_row($db, (int)$t['task_id']);
    if (!$x) return;
    $cfg = tk_config($db); $now = tk_now(); $f = []; $log = [];
    if ($t['subject'] !== mb_substr($x['title'], 0, 200)) { $f['title'] = $t['subject']; $log[] = ['title', $t['subject']]; }
    if ($t['priority'] !== $x['priority']) { $f['priority'] = $t['priority']; $log[] = ['priority', tk_name_of($cfg['priorities'], $x['priority']) . ' → ' . tk_name_of($cfg['priorities'], $t['priority'])]; }
    if ($t['origin'] !== 'tarea') {
        $due = $t['resolution_due'] ? substr($t['resolution_due'], 0, 10) : null;
        if ($due !== $x['due_date']) { $f['due_date'] = $due; $log[] = ['due', $due ? date('d/m/Y', strtotime($due)) : 'sin fecha']; }
    }
    $board = wk_board($db, (int)($x['board_id'] ?: wk_general_id($db)));
    $sts = wk_statuses($db, $board, $cfg);
    $closed = in_array($t['status'], ['resuelto', 'cerrado'], true);
    if ($closed && !$x['completed_at']) {
        $d = array_values(array_filter($sts, fn($s) => !empty($s['done'])))[0]['id'] ?? null;
        if ($d) { $f['status'] = $d; $f['completed_at'] = $now; }
    } elseif (!$closed && $x['completed_at']) {
        $openSt = array_values(array_filter($sts, fn($s) => empty($s['done'])));
        $pick = $t['status'] === 'abierto' ? ($openSt[0] ?? null) : ($openSt[1] ?? $openSt[0] ?? null);
        if ($pick) { $f['status'] = $pick['id']; $f['completed_at'] = null; }
    }
    if (isset($f['status'])) {
        $max = $db->prepare('SELECT COALESCE(MAX(position), 0) FROM g7_tasks WHERE status = ?'); $max->execute([$f['status']]);
        $f['position'] = (float)$max->fetchColumn() + 1;
        $log[] = ['status', tk_name_of($sts, $x['status']) . ' → ' . tk_name_of($sts, $f['status'])];
    }
    $added = [];
    wk_link_busy(true);
    try {
        if ($f) {
            $f['updated_at'] = $now;
            $db->prepare('UPDATE g7_tasks SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $x['id']]);
        }
        $a = $t['assignee_id'] ? (int)$t['assignee_id'] : null;
        if ($a && !in_array($a, $x['assignees'], true)) { $db->prepare('INSERT IGNORE INTO g7_task_assignees (task_id, user_id) VALUES (?, ?)')->execute([$x['id'], $a]); $added[] = $a; }
        if ($prevAssignee && $prevAssignee !== $a && in_array($prevAssignee, $x['assignees'], true))
            $db->prepare('DELETE FROM g7_task_assignees WHERE task_id = ? AND user_id = ?')->execute([$x['id'], $prevAssignee]);
        if ($added || ($prevAssignee && $prevAssignee !== $a)) $log[] = ['assignees', ($added ? 'sumó a ' . wk_user_name($db, $added[0]) : 'sacó a ' . wk_user_name($db, (int)$prevAssignee)) . ' (desde el ticket)'];
        foreach ($log as [$k, $v]) tk_log($db, (int)$x['id'], $by, $k, $v);
        if ($f || $added) {
            $new = wk_task_row($db, (int)$x['id']);
            wk_task_events($db, $x, $new, $added, $by);
        }
    } finally { wk_link_busy(false); }
}

/** Mensajes del ticket que todavía no están en la conversación de la tarea (respuestas, notas internas, las que llegan del base). */
function wk_tk_mirror(PDO $db, int $ticketId): void {
    $t = wk_tk_row($db, $ticketId);
    if (!$t || !$t['task_id']) return;
    $q = $db->prepare("SELECT * FROM g7_wk_ticket_msgs WHERE ticket_id = ? AND kind = 'msg' AND comment_id IS NULL ORDER BY id"); $q->execute([$ticketId]);
    $ins = $db->prepare('INSERT INTO g7_task_comments (task_id, user_id, body, internal, ext_name, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $link = $db->prepare('UPDATE g7_wk_ticket_msgs SET comment_id = ? WHERE id = ?');
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $ins->execute([(int)$t['task_id'], $m['user_id'] ? (int)$m['user_id'] : null, mb_substr((string)$m['body'], 0, 20000), (int)$m['internal'], $m['ext_name'] ?? null, $m['created_at']]);
        $link->execute([(int)$db->lastInsertId(), $m['id']]); $n++;
    }
    if ($n) $db->prepare('UPDATE g7_tasks SET updated_at = ? WHERE id = ?')->execute([tk_now(), (int)$t['task_id']]);
}

/**
 * Respuesta en un ticket (desde Tickets o desde un comentario de su tarea): guarda el mensaje, frena el reloj de primera respuesta,
 * acomoda el estado y, si vino del base, la manda a la agencia. $agent = atiende el ticket. Devuelve [id del mensaje, campos cambiados].
 */
function wk_tk_post(PDO $db, array $t, int $uid, string $body, bool $internal, bool $agent, ?int $commentId = null, string $userName = ''): array {
    $now = tk_now();
    $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, comment_id) VALUES (?, ?, ?, ?, 'msg', ?, ?)")->execute([(int)$t['id'], $uid, $body, $internal ? 1 : 0, $now, $commentId]);
    $mid = (int)$db->lastInsertId();
    $up = [];
    $isReq = $uid === (int)$t['requester_id'];
    if (!$internal && !$isReq && $agent && !$t['first_response_at']) $up['first_response_at'] = $now;
    if (!$internal && $isReq && in_array($t['status'], ['esperando', 'resuelto'], true)) $up += wk_tk_status_fields($t, 'en_curso', $now);
    elseif (!$internal && !$isReq && $agent && $t['status'] === 'abierto') $up += wk_tk_status_fields($t, 'en_curso', $now);
    $up['updated_at'] = $now;
    wk_tk_set($db, (int)$t['id'], $up);
    if (!$internal && !empty($t['base_id'])) {
        if (!function_exists('wb_after')) require_once __DIR__ . '/base_sync.php';
        wb_after($db, $t, $mid, $body, $up['status'] ?? null, $userName ?: wk_user_name($db, $uid));
    }
    return [$mid, $up];
}

/** Comentario nuevo en una tarea: es una respuesta pública de su ticket (llega a la agencia si vino del base). */
function wk_tk_from_comment(PDO $db, int $taskId, int $commentId, int $uid, string $body): void {
    if (wk_link_busy()) return;
    $t = wk_tk_of_task($db, $taskId);
    if (!$t) return;
    try {
        $ctx = wk_ctx($db, $uid);
        $agent = wk_is_agent($ctx, $t['team_id'] ? (int)$t['team_id'] : null) || $uid !== (int)$t['requester_id'];
        wk_link_busy(true);
        try { [, $up] = wk_tk_post($db, $t, $uid, $body, false, $agent, $commentId); } finally { wk_link_busy(false); }
        if (isset($up['status'])) wk_task_from_tk($db, (int)$t['id'], $uid);
    } catch (Throwable $e) { error_log('[trabajo] comentario ' . $commentId . ' → ticket: ' . $e->getMessage()); }
}
/** Se editó o borró un comentario de la tarea: lo mismo en el mensaje del ticket (lo ya entregado al base queda allá). */
function wk_tk_comment_changed(PDO $db, int $commentId, ?string $body): void {
    try {
        if ($body === null) $db->prepare('DELETE FROM g7_wk_ticket_msgs WHERE comment_id = ?')->execute([$commentId]);
        else $db->prepare('UPDATE g7_wk_ticket_msgs SET body = ? WHERE comment_id = ?')->execute([$body, $commentId]);
    } catch (Throwable $e) {}
}
/** Se borró una tarea: el ticket queda (con su historia) sin tarea. */
function wk_tk_task_deleted(PDO $db, int $taskId, ?int $by): void {
    $t = wk_tk_of_task($db, $taskId);
    if (!$t) return;
    $db->prepare('UPDATE g7_wk_tickets SET task_id = NULL, updated_at = ? WHERE id = ?')->execute([tk_now(), $t['id']]);
    $db->prepare('UPDATE g7_wk_ticket_msgs SET comment_id = NULL WHERE ticket_id = ?')->execute([$t['id']]);
    wk_tk_event($db, (int)$t['id'], $by, 'borró la tarea del ticket', true);
}

/** Datos del ticket para mostrar en la tarea. */
function wk_tk_brief(PDO $db, int $taskId): ?array {
    $t = wk_tk_of_task($db, $taskId);
    if (!$t) return null;
    $st = wk_st('tickets')[$t['status']] ?? [$t['status'], '#8b8b94'];
    $from = '';
    if (!empty($t['base_info']) && ($info = json_decode((string)$t['base_info'], true))) { if (!function_exists('wb_from')) require_once __DIR__ . '/base_sync.php'; $from = wb_from($info); }
    return ['id' => (int)$t['id'], 'code' => wk_tk_code((int)$t['id']), 'status' => $t['status'], 'status_name' => $st[0], 'status_color' => $st[1], 'origin' => $t['origin'],
        'base' => !empty($t['base_id']), 'web' => $from !== '' && str_ends_with($from, '(web)'), 'from' => $from, 'paused' => (bool)$t['paused_at'],
        'first_response_due' => $t['first_response_due'], 'first_response_at' => $t['first_response_at'], 'resolution_due' => $t['resolution_due'], 'resolved_at' => $t['resolved_at']];
}

// ── Avisos de tickets (por las preferencias de Tareas: campana, email y navegador) ──
/** Avisa de un ticket con las preferencias de cada uno (evento tk_* o assigned). */
function wk_tk_notify(PDO $db, string $event, array $users, array $t, ?int $from, string $body = ''): void {
    tk_notify($db, $event, $users, ['id' => (int)$t['id'], 'title' => wk_tk_code((int)$t['id']) . ' · ' . $t['subject']], $from, $body, ['ticket' => (int)$t['id']]);
}
/** Gente "del" ticket: quien lo abrió, el responsable (o los jefes del departamento) y la gente de su tarea. */
function wk_tk_people(PDO $db, array $t, bool $withRequester = true): array {
    $out = $withRequester && (int)$t['requester_id'] ? [(int)$t['requester_id']] : [];
    $out = array_merge($out, $t['assignee_id'] ? [(int)$t['assignee_id']] : wk_team_people($db, $t['team_id'] ? (int)$t['team_id'] : null, true));
    if ($t['task_id']) $out = array_merge($out, tk_participants($db, (int)$t['task_id']));
    if (function_exists('wk_tkc_ids')) $out = array_merge($out, wk_tkc_ids($db, (int)$t['id']));   // colaboradores (tk_collab_lib.php)
    return array_values(array_unique(array_filter($out)));
}

/** Avisos antes de vencer el SLA (minutos configurables, flags 4 = respuesta y 8 = resolución) y el resumen diario. Lo llama wk_sla_sweep. */
function wk_tk_warn(PDO $db): void {
    $min = max(5, min(10080, (int)wk_cfg_get($db, 'work_tk_warn_min', '60')));
    $now = tk_now(); $lim = date('Y-m-d H:i:s', time() + $min * 60);
    $rows = $db->query("SELECT * FROM g7_wk_tickets WHERE status IN ('abierto', 'en_curso') AND origin <> 'tarea' AND paused_at IS NULL AND (
        (first_response_at IS NULL AND first_response_due BETWEEN '$now' AND '$lim' AND (breach_flags & 4) = 0) OR (resolution_due BETWEEN '$now' AND '$lim' AND (breach_flags & 8) = 0)) LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $t) {
        $flags = (int)$t['breach_flags']; $what = [];
        if (!$t['first_response_at'] && $t['first_response_due'] && $t['first_response_due'] <= $lim && !($flags & 4)) { $flags |= 4; $what[] = 'la primera respuesta vence a las ' . date('H:i', strtotime($t['first_response_due'])); }
        if ($t['resolution_due'] && $t['resolution_due'] <= $lim && !($flags & 8)) { $flags |= 8; $what[] = 'la resolución vence ' . (substr($t['resolution_due'], 0, 10) === date('Y-m-d') ? 'a las ' . date('H:i', strtotime($t['resolution_due'])) : 'el ' . date('d/m H:i', strtotime($t['resolution_due']))); }
        $db->prepare('UPDATE g7_wk_tickets SET breach_flags = ? WHERE id = ?')->execute([$flags, $t['id']]);
        $to = $t['assignee_id'] ? [(int)$t['assignee_id']] : wk_team_people($db, $t['team_id'] ? (int)$t['team_id'] : null);
        if ($what) wk_tk_notify($db, 'tk_due_soon', $to, $t, null, 'Por vencer: ' . implode(' y ', $what) . '.');
    }
    wk_tk_digest($db);
}

/** Resumen diario (una vez por día, a partir de la hora elegida; -1 = apagado): a los admins y a los jefes, con lo de su alcance. */
function wk_tk_digest(PDO $db, bool $force = false): int {
    $hour = (int)wk_cfg_get($db, 'work_tk_digest_hour', '9');
    if ($hour < 0 && !$force) return 0;
    $today = date('Y-m-d');
    if (!$force && ((int)date('G') < $hour || wk_cfg_get($db, 'work_tk_digest_day') === $today)) return 0;
    wk_cfg_put($db, 'work_tk_digest_day', $today);
    $who = array_map('intval', $db->query("SELECT id FROM g7_users WHERE active = 1 AND role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
    $who = array_merge($who, array_map('intval', $db->query("SELECT DISTINCT m.user_id FROM g7_team_members m JOIN g7_users u ON u.id = m.user_id AND u.active = 1 WHERE m.role = 'admin'")->fetchAll(PDO::FETCH_COLUMN)));
    $sent = 0; $now = tk_now(); $end = $today . ' 23:59:59';
    foreach (array_unique($who) as $u) {
        $ctx = wk_ctx($db, $u);
        $w = $ctx['all'] ? '1' : '(t.team_id IN (' . wk_ints($ctx['lead']) . ') OR t.assignee_id = ' . $u . ')';
        $rows = $db->query("SELECT t.* FROM g7_wk_tickets t WHERE t.status IN ('abierto', 'en_curso', 'esperando') AND t.origin <> 'tarea' AND $w ORDER BY t.resolution_due IS NULL, t.resolution_due LIMIT 400")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
        $late = array_filter($rows, fn($t) => $t['status'] !== 'esperando' && (($t['resolution_due'] && $t['resolution_due'] < $now) || (!$t['first_response_at'] && $t['first_response_due'] && $t['first_response_due'] < $now)));
        $todayDue = array_filter($rows, fn($t) => $t['resolution_due'] && $t['resolution_due'] >= $now && $t['resolution_due'] <= $end);
        $free = array_filter($rows, fn($t) => !$t['assignee_id']);
        $lines = [count($rows) . ' abiertos · ' . count($late) . ' con el SLA vencido · ' . count($todayDue) . ' vencen hoy · ' . count($free) . ' sin asignar.', ''];
        $pick = [];
        foreach ([[$late, 'SLA vencido'], [$todayDue, 'vence hoy'], [$free, 'sin asignar']] as [$list, $tag])
            foreach ($list as $t) $pick[(int)$t['id']] ??= wk_tk_code((int)$t['id']) . ' · ' . mb_substr($t['subject'], 0, 80) . ' (' . $tag . ')';
        $lines = array_merge($lines, array_slice(array_values($pick), 0, 12));
        tk_notify($db, 'tk_digest', [$u], ['id' => 0, 'title' => 'Resumen de tickets del ' . date('d/m')], null, implode("\n", $lines), ['ticket' => 0]);
        $sent++;
    }
    return $sent;
}
