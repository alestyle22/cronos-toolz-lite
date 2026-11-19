<?php
/**
 * Cronos Mobile — Inicio. Un solo pedido con lo que la persona tiene pendiente, para que la pantalla abra rápido en el
 * teléfono. Solo lee; cada bloque respeta el permiso del módulo (si no tiene Chat, no hay bloque de chat, etc.).
 * Las fechas van en la zona de almacenamiento (me.site.tz_storage), salvo los eventos del calendario, que ya vienen en
 * la zona de la persona (tz_user), como en la web.
 */

function mb_q(PDO $db, string $sql, array $args = []): array {
    try { $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function mb_home(PDO $db, int $uid): array {
    $out = ['now' => date('Y-m-d H:i:s')];
    $u = mb_q($db, 'SELECT display_name, username, first_name, mood FROM g7_users WHERE id = ?', [$uid])[0] ?? [];
    $out['first_name'] = (string)(($u['first_name'] ?? '') ?: explode(' ', (string)(($u['display_name'] ?? '') ?: ($u['username'] ?? '')))[0]);

    // Chat: no leídos (mensajes raíz de otros, sin borrar, en conversaciones no silenciadas) y las conversaciones con novedades
    if (g7_can($uid, 'chat')) {
        $rows = mb_q($db, "SELECT c.id, c.kind, c.name, c.avatar, c.last_msg_at, m.muted,
                (SELECT COUNT(*) FROM g7_ch_messages x WHERE x.conv_id = c.id AND x.id > m.last_read_id AND x.user_id <> ? AND x.parent_id IS NULL AND x.deleted_at IS NULL) AS unread,
                (SELECT x.body FROM g7_ch_messages x WHERE x.conv_id = c.id AND x.parent_id IS NULL AND x.deleted_at IS NULL ORDER BY x.id DESC LIMIT 1) AS last_body,
                (SELECT x.user_id FROM g7_ch_messages x WHERE x.conv_id = c.id AND x.parent_id IS NULL AND x.deleted_at IS NULL ORDER BY x.id DESC LIMIT 1) AS last_user,
                (SELECT mm.user_id FROM g7_ch_members mm WHERE mm.conv_id = c.id AND mm.user_id <> ? LIMIT 1) AS peer
            FROM g7_ch_members m JOIN g7_ch_convs c ON c.id = m.conv_id AND c.archived = 0
            WHERE m.user_id = ? ORDER BY c.last_msg_at DESC LIMIT 60", [$uid, $uid, $uid]);
        $total = 0; $convs = [];
        $names = [];
        foreach ($rows as $r) {
            $n = (int)$r['unread'];
            if (!(int)$r['muted']) $total += $n;
            if ($n > 0 && count($convs) < 4) $convs[] = $r;
        }
        $ids = array_filter(array_merge(array_column($convs, 'peer'), array_column($convs, 'last_user')));
        if ($ids) foreach (mb_q($db, 'SELECT id, display_name, username, avatar FROM g7_users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $p) $names[(int)$p['id']] = $p;
        $nm = fn($id) => isset($names[(int)$id]) ? (string)($names[(int)$id]['display_name'] ?: $names[(int)$id]['username']) : '';
        $out['chat'] = ['unread' => $total, 'convs' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'kind' => $r['kind'],
            'name' => $r['kind'] === 'dm' ? $nm($r['peer']) : (string)$r['name'],
            'avatar' => $r['kind'] === 'dm' ? mb_url($names[(int)$r['peer']]['avatar'] ?? '') : mb_chat_avatar((string)$r['avatar']),
            'unread' => (int)$r['unread'], 'muted' => (bool)(int)$r['muted'],
            'last' => mb_substr(preg_replace('/\s+/', ' ', (string)$r['last_body']), 0, 90), 'last_user' => $nm($r['last_user']), 'last_at' => $r['last_msg_at'],
        ], $convs)];
    }

    // Trabajo: tickets asignados a mí y mis pedidos abiertos; tareas asignadas sin terminar
    if (g7_can($uid, 'tareas')) {
        $open = "('abierto','en_curso','esperando')";
        $assigned = mb_q($db, "SELECT t.id, t.subject, t.status, t.priority, t.resolution_due, t.updated_at, tm.name AS team_name, u.display_name AS requester
            FROM g7_wk_tickets t LEFT JOIN g7_teams tm ON tm.id = t.team_id LEFT JOIN g7_users u ON u.id = t.requester_id
            WHERE t.assignee_id = ? AND t.status IN $open ORDER BY t.resolution_due IS NULL, t.resolution_due, t.updated_at DESC LIMIT 5", [$uid]);
        $cnt = mb_q($db, "SELECT SUM(assignee_id = ?) AS mine, SUM(requester_id = ? AND origin <> 'tarea') AS asked,
            SUM(assignee_id = ? AND resolution_due IS NOT NULL AND resolution_due < NOW()) AS late FROM g7_wk_tickets WHERE status IN $open", [$uid, $uid, $uid])[0] ?? [];
        $st = function_exists('st_pairs') ? (st_pairs('tickets') ?: []) : [];
        $out['tickets'] = ['assigned' => (int)($cnt['mine'] ?? 0), 'requested' => (int)($cnt['asked'] ?? 0), 'late' => (int)($cnt['late'] ?? 0),
            'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'code' => 'T-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT)] + $r, $assigned)];

        $tasks = mb_q($db, "SELECT t.id, t.title, t.board_id, t.due_date, t.status, t.priority, b.name AS board_name, b.color AS board_color, b.statuses AS bst
            FROM g7_tasks t JOIN g7_task_assignees x ON x.task_id = t.id AND x.user_id = ? LEFT JOIN g7_wk_boards b ON b.id = t.board_id
            WHERE t.completed_at IS NULL AND t.archived = 0 ORDER BY t.due_date IS NULL, t.due_date, t.updated_at DESC LIMIT 6", [$uid]);
        $gst = [];
        if (is_file(dirname(__DIR__) . '/tareas_lib.php')) { require_once dirname(__DIR__) . '/tareas_lib.php'; try { $gst = tk_config($db)['statuses'] ?? []; } catch (Throwable $e) {} }
        $nTasks = mb_q($db, 'SELECT COUNT(*) n FROM g7_tasks t JOIN g7_task_assignees x ON x.task_id = t.id AND x.user_id = ? WHERE t.completed_at IS NULL AND t.archived = 0', [$uid])[0]['n'] ?? 0;
        $out['tasks'] = ['count' => (int)$nTasks, 'items' => array_map(function ($r) use ($gst) {
            $list = $r['bst'] ? (json_decode($r['bst'], true) ?: $gst) : $gst;
            $s = null; foreach ($list as $x) if (($x['id'] ?? '') === $r['status']) { $s = $x; break; }
            return ['id' => (int)$r['id'], 'title' => $r['title'], 'board_id' => (int)$r['board_id'], 'board' => (string)($r['board_name'] ?: 'General'),
                'board_color' => (string)$r['board_color'], 'due_date' => $r['due_date'], 'status' => $r['status'], 'priority' => $r['priority'],
                'status_name' => (string)($s['name'] ?? $r['status']), 'status_color' => (string)($s['color'] ?? '#888888')];
        }, $tasks)];
    }

    // Calendario: lo que viene en los próximos 7 días (en la zona de la persona)
    if (g7_can($uid, 'calendario') && is_file(dirname(__DIR__) . '/calendario/lib.php')) {
        require_once dirname(__DIR__) . '/calendario/lib.php';
        try {
            $tz = new DateTimeZone(function_exists('g7_tz_user') ? g7_tz_user($uid) : date_default_timezone_get());
            $from = new DateTimeImmutable('now', $tz); $to = $from->modify('+7 days');
            $ev = cal_events($db, $uid, $from, $to, $tz);
            $out['events'] = ['tz' => $tz->getName(), 'items' => array_slice(array_map(fn($e) => ['id' => $e['id'], 'title' => $e['title'], 'start' => $e['start'], 'end' => $e['end'],
                'all_day' => (bool)$e['all_day'], 'location' => (string)$e['location'], 'color' => (string)$e['color']], $ev), 0, 5)];
        } catch (Throwable $e) {}
    }

    // Campana: avisos sin leer
    $n = mb_q($db, 'SELECT COUNT(*) n FROM g7_notifications WHERE user_id = ? AND read_at IS NULL', [$uid])[0]['n'] ?? 0;
    $out['notifications'] = ['unread' => (int)$n];

    // Ánimo del día (mood_api.php): si ya respondió en las últimas 24 h
    if (is_file(dirname(__DIR__) . '/mood_lib.php')) {
        require_once dirname(__DIR__) . '/mood_lib.php';
        try { mood_install($db); $last = mood_last_at($db, $uid); $out['mood'] = ['answered' => $last !== null && $last > time() - 86400]; } catch (Throwable $e) {}
    }
    return $out;
}
