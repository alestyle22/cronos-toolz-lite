<?php
/**
 * trabajo/entrega_lib.php — Entregas y calificaciones de los pedidos (2026-11-12). Doc: toolz/ENTREGAS_CALIFICACION.md
 *
 * Entrega: al cargar un contenido en Promos se puede elegir el ticket o la tarea que entrega. El contenido queda vinculado, en el
 *   ticket se publica la respuesta "Hola, ya están los elementos…" con sus enlaces y el ticket pasa a Resuelto (una tarea sin ticket
 *   recibe el comentario y pasa a terminada).
 * Calificación: con el ticket cerrado, quien lo pidió le pone de 1 a 5 estrellas (nota general), estrellas por cada punto que su
 *   departamento eligió calificar (g7_wk_rating_criteria) y un comentario opcional. Una vez por ticket.
 *   Los puntos los configuran los admins (todos los departamentos) y el jefe de cada departamento (los suyos).
 * Lo usan trabajo/api.php, promos/index.php y el Inicio (home_work).
 */
require_once __DIR__ . '/lib.php';

const WK_DELIVERY_TPL = "Hola, ya están los elementos. Los enlaces son:\n{enlaces}";

// Fuera de trabajo/api.php (Promos): la misma función con la que la API registra eventos en un ticket
if (!function_exists('wk_tk_log')) {
    function wk_tk_log(PDO $db, int $id, ?int $uid, string $body, bool $internal = true, string $kind = 'event'): int {
        $db->prepare('INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $uid, $body, $internal ? 1 : 0, $kind, tk_now()]);
        $mid = (int)$db->lastInsertId();
        $db->prepare('UPDATE g7_wk_tickets SET updated_at = ? WHERE id = ?')->execute([tk_now(), $id]);
        return $mid;
    }
}

function wk_ev_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    if (wk_cfg_get($db, 'work_ev_schema', '0') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_rating_criteria (
        id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, name VARCHAR(60) NOT NULL, position INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
        KEY team (team_id, active, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_ratings (
        ticket_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL, team_id INT NULL, assignee_id INT NULL, stars TINYINT NOT NULL,
        scores TEXT NULL, comment VARCHAR(1000) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        KEY team (team_id, created_at), KEY assignee (assignee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    wk_cfg_put($db, 'work_ev_schema', '1');
}

// ═════════════════════════ Calificaciones ═════════════════════════

/** Puntos que califica un departamento: [['id','name','active']]. */
function wk_ev_criteria(PDO $db, ?int $teamId, bool $activeOnly = true): array {
    wk_ev_install($db);
    if (!$teamId) return [];
    $q = $db->prepare('SELECT id, name, active FROM g7_wk_rating_criteria WHERE team_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY position, id');
    $q->execute([$teamId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'active' => (bool)$r['active']], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** ¿Puede configurar los puntos de ese departamento? Admins y directores, todos; el jefe, los suyos. */
function wk_ev_can_config(array $ctx, int $teamId): bool {
    return $ctx['all'] || in_array($teamId, $ctx['lead'], true);
}

/** Guarda la lista de puntos de un departamento (los quitados que ya tienen notas se apagan, no se borran). */
function wk_ev_criteria_save(PDO $db, int $teamId, array $items): array {
    wk_ev_install($db);
    $keep = [];
    foreach (array_slice($items, 0, 12) as $i => $it) {
        $name = mb_substr(trim(strip_tags((string)($it['name'] ?? ''))), 0, 60);
        if ($name === '') continue;
        $id = (int)($it['id'] ?? 0); $act = !isset($it['active']) || !empty($it['active']) ? 1 : 0;
        if ($id) {
            $q = $db->prepare('UPDATE g7_wk_rating_criteria SET name = ?, position = ?, active = ? WHERE id = ? AND team_id = ?');
            $q->execute([$name, $i + 1, $act, $id, $teamId]);
            if (!$q->rowCount() && !$db->query("SELECT 1 FROM g7_wk_rating_criteria WHERE id = $id AND team_id = $teamId")->fetchColumn()) $id = 0;
        }
        if (!$id) { $db->prepare('INSERT INTO g7_wk_rating_criteria (team_id, name, position, active) VALUES (?, ?, ?, ?)')->execute([$teamId, $name, $i + 1, $act]); $id = (int)$db->lastInsertId(); }
        $keep[] = $id;
    }
    foreach ($db->query("SELECT id FROM g7_wk_rating_criteria WHERE team_id = $teamId" . ($keep ? ' AND id NOT IN (' . wk_ints($keep) . ')' : ''))->fetchAll(PDO::FETCH_COLUMN) as $gone) {
        $used = (int)$db->query("SELECT COUNT(*) FROM g7_wk_ratings WHERE team_id = $teamId AND scores LIKE '%\"" . (int)$gone . "\"%'")->fetchColumn();
        if ($used) $db->exec('UPDATE g7_wk_rating_criteria SET active = 0 WHERE id = ' . (int)$gone);
        else $db->exec('DELETE FROM g7_wk_rating_criteria WHERE id = ' . (int)$gone);
    }
    return wk_ev_criteria($db, $teamId, false);
}

/** La calificación de un ticket (o null), con el nombre de cada punto. */
function wk_ev_get(PDO $db, int $ticketId): ?array {
    wk_ev_install($db);
    $q = $db->prepare('SELECT * FROM g7_wk_ratings WHERE ticket_id = ?'); $q->execute([$ticketId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $sc = json_decode((string)$r['scores'], true) ?: [];
    $names = [];
    if ($sc) foreach ($db->query('SELECT id, name FROM g7_wk_rating_criteria WHERE id IN (' . wk_ints(array_keys($sc)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) $names[(int)$c['id']] = $c['name'];
    $scores = [];
    foreach ($sc as $cid => $v) $scores[] = ['id' => (int)$cid, 'name' => $names[(int)$cid] ?? 'Punto quitado', 'stars' => (int)$v];
    return ['stars' => (int)$r['stars'], 'scores' => $scores, 'comment' => (string)$r['comment'], 'user_id' => (int)$r['user_id'], 'at' => $r['created_at']];
}

/** ¿Puede ver la calificación? Quien la puso, el responsable, su departamento, sus jefes y los admins. */
function wk_ev_can_see(array $ctx, array $t): bool {
    return $ctx['all'] || (int)$t['requester_id'] === $ctx['id'] || ($t['assignee_id'] && (int)$t['assignee_id'] === $ctx['id'])
        || ($t['team_id'] && in_array((int)$t['team_id'], $ctx['teams'], true));
}

/** Qué hace falta para calificar un ticket: puntos de su departamento y si ya se calificó. */
function wk_ev_form(PDO $db, array $t): array {
    return ['criteria' => wk_ev_criteria($db, $t['team_id'] ? (int)$t['team_id'] : null), 'rating' => wk_ev_get($db, (int)$t['id'])];
}

/**
 * Califica un ticket. Solo quien lo pidió, con el ticket cerrado y una sola vez. $scores = [id del punto => 1..5]
 * (todos los puntos activos del departamento son obligatorios). Devuelve la calificación guardada.
 */
function wk_ev_save(PDO $db, int $uid, int $ticketId, int $stars, array $scores, string $comment): array {
    wk_ev_install($db);
    $t = wk_tk_row($db, $ticketId);
    if (!$t) throw new RuntimeException('Ese ticket no existe');
    if ((int)$t['requester_id'] !== $uid) throw new RuntimeException('Solo quien hizo el pedido lo califica');
    if ($t['status'] !== 'cerrado') throw new RuntimeException('Se califica cuando el pedido está cerrado');
    if (wk_ev_get($db, $ticketId)) throw new RuntimeException('Este pedido ya está calificado');
    if ($stars < 1 || $stars > 5) throw new RuntimeException('Elige de 1 a 5 estrellas');
    $crit = wk_ev_criteria($db, $t['team_id'] ? (int)$t['team_id'] : null);
    $sc = [];
    foreach ($crit as $c) {
        $v = (int)($scores[$c['id']] ?? $scores[(string)$c['id']] ?? 0);
        if ($v < 1 || $v > 5) throw new RuntimeException('Califica también "' . $c['name'] . '"');
        $sc[(string)$c['id']] = $v;
    }
    $comment = mb_substr(trim(strip_tags($comment)), 0, 1000);
    $db->prepare('INSERT INTO g7_wk_ratings (ticket_id, user_id, team_id, assignee_id, stars, scores, comment, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$ticketId, $uid, $t['team_id'] ?: null, $t['assignee_id'] ?: null, $stars, $sc ? json_encode($sc) : null, $comment, tk_now()]);
    wk_tk_log($db, $ticketId, $uid, 'calificó el pedido con ' . $stars . ' de 5' . ($comment !== '' ? ': "' . mb_substr($comment, 0, 200) . '"' : ''), false);
    // Aviso a quien lo atendió (o al departamento)
    $to = array_values(array_diff($t['assignee_id'] ? [(int)$t['assignee_id']] : wk_team_people($db, $t['team_id'] ? (int)$t['team_id'] : null, true), [$uid]));
    if ($to) wk_tk_notify($db, 'tk_status', $to, $t, $uid, 'Calificación: ' . str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) . ($comment !== '' ? ' · ' . mb_substr($comment, 0, 140) : ''));
    return wk_ev_get($db, $ticketId);
}

/** Pedidos cerrados de una persona que todavía no calificó (los de los últimos $days días). */
function wk_ev_pending(PDO $db, int $uid, int $days = 30): array {
    wk_ev_install($db);
    $q = $db->prepare("SELECT t.id, t.subject, t.team_id, t.assignee_id, t.closed_at, tm.name AS team_name FROM g7_wk_tickets t LEFT JOIN g7_teams tm ON tm.id = t.team_id
        WHERE t.requester_id = ? AND t.status = 'cerrado' AND t.origin <> 'tarea' AND t.closed_at >= ? AND NOT EXISTS (SELECT 1 FROM g7_wk_ratings r WHERE r.ticket_id = t.id)
        ORDER BY t.closed_at DESC LIMIT 30");
    $q->execute([$uid, date('Y-m-d H:i:s', time() - $days * 86400)]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'code' => wk_tk_code((int)$r['id']), 'subject' => $r['subject'], 'team' => (string)$r['team_name'],
        'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null, 'closed_at' => $r['closed_at'],
        'criteria' => wk_ev_criteria($db, $r['team_id'] ? (int)$r['team_id'] : null)], $q->fetchAll(PDO::FETCH_ASSOC));
}

/** Promedios por departamento y por punto, y los últimos comentarios (de los departamentos que puede configurar o ver). */
function wk_ev_report(PDO $db, array $ctx, int $days = 90): array {
    wk_ev_install($db);
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    $teams = $ctx['all'] ? array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)) : array_values(array_unique(array_merge($ctx['lead'], $ctx['teams'])));
    $out = [];
    foreach ($teams as $tid) {
        $q = $db->prepare('SELECT stars, scores, comment, created_at, ticket_id FROM g7_wk_ratings WHERE team_id = ? AND created_at >= ? ORDER BY created_at DESC');
        $q->execute([$tid, $since]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $per = [];
        foreach ($rows as $r) foreach ((json_decode((string)$r['scores'], true) ?: []) as $cid => $v) { $per[$cid][] = (int)$v; }
        $crit = [];
        foreach (wk_ev_criteria($db, $tid, false) as $c) $crit[] = ['id' => $c['id'], 'name' => $c['name'], 'active' => $c['active'], 'avg' => isset($per[$c['id']]) ? round(array_sum($per[$c['id']]) / count($per[$c['id']]), 2) : null, 'n' => count($per[$c['id']] ?? [])];
        $out[] = ['team_id' => $tid, 'n' => count($rows), 'avg' => $rows ? round(array_sum(array_column($rows, 'stars')) / count($rows), 2) : null, 'criteria' => $crit,
            'can_config' => wk_ev_can_config($ctx, $tid),
            'comments' => array_slice(array_values(array_map(fn($r) => ['ticket_id' => (int)$r['ticket_id'], 'stars' => (int)$r['stars'], 'comment' => $r['comment'], 'at' => $r['created_at']], array_filter($rows, fn($r) => $r['comment'] !== ''))), 0, 8)];
    }
    return $out;
}

// ═════════════════════════ Entregas ═════════════════════════

/** Enlaces de un contenido de Promos, con su nombre: [['Imagen', url], …]. */
function wk_content_links(array $c): array {
    $abs = fn(string $u) => preg_match('#^https?://#i', $u) ? $u : g7_site_origin() . '/' . ltrim(strpos($u, '/') === 0 ? $u : 'toolz/promos/' . $u, '/');
    $out = [];
    if (trim((string)$c['img']) !== '') $out[] = ['Imagen', $abs(trim((string)$c['img']))];
    if (trim((string)$c['graphics']) !== '') $out[] = ['Gráficas', $abs(trim((string)$c['graphics']))];
    foreach (array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($c['landing'] ?? '')))) as $i => $l) $out[] = ['Landing' . ($i ? ' ' . ($i + 1) : ''), $abs($l)];
    if (trim((string)$c['mailing']) !== '') $out[] = ['Mailing', $abs(trim((string)$c['mailing']))];
    $out[] = ['Ver en Promos', g7_site_origin() . '/toolz/promos/?search=' . rawurlencode((string)$c['name'])];
    return $out;
}

/** Texto de la entrega con la plantilla de Tickets › Configuración ({enlaces}, {nombre}). */
function wk_delivery_text(PDO $db, array $c): string {
    $tpl = (string)wk_cfg_get($db, 'work_delivery_tpl', '') ?: WK_DELIVERY_TPL;
    if (strpos($tpl, '{enlaces}') === false) $tpl .= "\n{enlaces}";
    $links = implode("\n", array_map(fn($l) => '• ' . $l[0] . ': ' . $l[1], wk_content_links($c)));
    return strtr($tpl, ['{enlaces}' => $links, '{nombre}' => (string)$c['name']]);
}

// wk_tk_is_agent() vive en lib.php (una sola regla para Trabajo, el portal y las entregas)

/**
 * A qué se puede entregar: tickets abiertos que atiende (los suyos primero) y tareas abiertas asignadas a esa persona que no son de un
 * ticket. Con $q busca por número o texto. [['kind' => 'ticket'|'tarea', 'id', 'code', 'title', 'sub']]
 */
function wk_deliver_targets(PDO $db, int $uid, string $q = ''): array {
    $ctx = wk_ctx($db, $uid);
    $out = [];
    $w = ["t.status IN ('abierto','en_curso','esperando')", "t.origin <> 'tarea'"]; $a = [];
    if (!$ctx['all']) { $w[] = '(t.assignee_id = ? OR t.team_id IN (' . wk_ints($ctx['teams']) . '))'; $a[] = $uid; }
    if ($q !== '') {
        if (preg_match('/^(?:t-?)?0*(\d{1,9})$/i', $q, $m)) { $w[] = 't.id = ?'; $a[] = (int)$m[1]; }
        else { $w[] = 't.subject LIKE ?'; $a[] = '%' . addcslashes($q, '%_\\') . '%'; }
    }
    $st = $db->prepare('SELECT t.id, t.subject, t.requester_id, t.assignee_id FROM g7_wk_tickets t WHERE ' . implode(' AND ', $w) . ' ORDER BY (t.assignee_id = ' . $uid . ') DESC, t.updated_at DESC LIMIT 15');
    $st->execute($a);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['kind' => 'ticket', 'id' => (int)$r['id'], 'code' => wk_tk_code((int)$r['id']), 'title' => $r['subject'],
        'sub' => 'Pedido de ' . wk_user_name($db, (int)$r['requester_id'])];
    $w = ['x.user_id = ?', 't.completed_at IS NULL', 't.archived = 0', 'NOT EXISTS (SELECT 1 FROM g7_wk_tickets k WHERE k.task_id = t.id)']; $a = [$uid];
    if ($q !== '') { $w[] = 't.title LIKE ?'; $a[] = '%' . addcslashes($q, '%_\\') . '%'; }
    $st = $db->prepare('SELECT t.id, t.title FROM g7_tasks t JOIN g7_task_assignees x ON x.task_id = t.id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.updated_at DESC LIMIT 10');
    $st->execute($a);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['kind' => 'tarea', 'id' => (int)$r['id'], 'code' => 'Tarea', 'title' => $r['title'], 'sub' => 'Asignada a ti'];
    return $out;
}

/**
 * Entrega un contenido: lo vincula al ticket, publica la respuesta con los enlaces y lo pasa a Resuelto. Si es una tarea sin ticket,
 * le deja el comentario y la pasa a terminada. Devuelve ['kind', 'id', 'code'].
 */
function wk_deliver(PDO $db, int $uid, int $contentId, string $kind, int $id): array {
    wk_install($db);
    $q = $db->prepare('SELECT * FROM g7_content WHERE id = ?'); $q->execute([$contentId]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new RuntimeException('Ese contenido no existe');
    $ctx = wk_ctx($db, $uid);
    $msg = wk_delivery_text($db, $c);
    if ($kind === 'tarea') {
        $tk = wk_tk_of_task($db, $id);
        if ($tk) { $kind = 'ticket'; $id = (int)$tk['id']; }   // la tarea de un ticket: se entrega el ticket
    }
    if ($kind === 'ticket') {
        $t = wk_tk_row($db, $id);
        if (!$t) throw new RuntimeException('Ese ticket no existe');
        if (!wk_tk_is_agent($db, $ctx, $t)) throw new RuntimeException('Solo quien atiende el ticket puede entregarlo');
        require_once __DIR__ . '/tk_links_lib.php';
        wk_tk_links_install($db);
        $db->prepare('INSERT IGNORE INTO g7_wk_ticket_links (ticket_id, kind, ref_id, added_by, added_at) VALUES (?, ?, ?, ?, ?)')->execute([$id, 'contenido', $contentId, $uid, tk_now()]);
        wk_tk_post($db, $t, $uid, $msg, false, true, null, wk_user_name($db, $uid));   // respuesta visible para quien lo pidió
        wk_tk_mirror($db, $id);
        $t = wk_tk_row($db, $id);
        if (!in_array($t['status'], ['resuelto', 'cerrado'], true)) {
            wk_tk_set($db, $id, wk_tk_status_fields($t, 'resuelto', tk_now()) + ['updated_at' => tk_now()]);
            wk_tk_log($db, $id, $uid, 'entregó el contenido "' . mb_substr((string)$c['name'], 0, 120) . '" · pasó a Resuelto', false);
            if (!empty($t['base_id'])) { if (!function_exists('wb_after')) require_once __DIR__ . '/base_sync.php'; wb_after($db, $t, null, null, 'resuelto', wk_user_name($db, $uid)); }
        }
        wk_task_from_tk($db, $id, $uid);
        $t = wk_tk_row($db, $id);
        wk_tk_notify($db, 'tk_status', array_values(array_diff(wk_tk_people($db, $t), [$uid])), $t, $uid, 'Pedido entregado: ya puedes aprobarlo y cerrarlo');
        return ['kind' => 'ticket', 'id' => $id, 'code' => wk_tk_code($id)];
    }
    // Tarea sin ticket: comentario con los enlaces y pasa a terminada (solo quien la tiene asignada o puede verla)
    $x = wk_task_row($db, $id);
    if (!$x) throw new RuntimeException('Esa tarea no existe');
    $mine = (bool)$db->query('SELECT 1 FROM g7_task_assignees WHERE task_id = ' . $id . ' AND user_id = ' . $uid)->fetchColumn();
    if (!$mine && !wk_task_visible($db, $ctx, $id)) throw new RuntimeException('No puedes entregar esa tarea');
    $db->prepare('INSERT INTO g7_task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)')->execute([$id, $uid, $msg, tk_now()]);
    $b = wk_board($db, (int)($x['board_id'] ?: wk_general_id($db)));
    $sts = wk_statuses($db, $b, tk_config($db));
    $done = array_values(array_filter($sts, fn($s) => !empty($s['done'])))[0]['id'] ?? null;
    if ($done && !$x['completed_at']) {
        $db->prepare('UPDATE g7_tasks SET status = ?, updated_at = ? WHERE id = ?')->execute([$done, tk_now(), $id]);
        wk_sync_completed($db, $sts, 'id = ' . $id);
        tk_log($db, $id, $uid, 'status', 'entregó el contenido "' . mb_substr((string)$c['name'], 0, 120) . '"');
    }
    return ['kind' => 'tarea', 'id' => $id, 'code' => 'Tarea'];
}
