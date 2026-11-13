<?php if (!isset($db)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/**
 * chat/api_admin.php — Acciones del panel de administración del chat (solo admins de la intranet).
 * La incluye api.php antes de su switch. Todo cambio queda en g7_ch_audit.
 */
if (!str_starts_with($act, 'admin_')) return;
if (!$admin) fail('Solo los administradores', 403);

switch ($act) {

case 'admin_overview': {
    $days = max(7, min(365, $int('days') ?: 30));
    $since = date('Y-m-d 00:00:00', strtotime("-$days days"));
    $q = function (string $sql, array $a = []) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s; };
    $msgs = "kind NOT IN ('system') AND created_at >= ?";
    $tot = [
        'messages' => (int)$q("SELECT COUNT(*) FROM g7_ch_messages WHERE $msgs", [$since])->fetchColumn(),
        'senders' => (int)$q("SELECT COUNT(DISTINCT user_id) FROM g7_ch_messages WHERE $msgs", [$since])->fetchColumn(),
        'active_7' => (int)$db->query("SELECT COUNT(*) FROM g7_users WHERE active = 1 AND last_seen >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
        'users' => (int)$db->query('SELECT COUNT(*) FROM g7_users WHERE active = 1')->fetchColumn(),
        'channels' => (int)$db->query("SELECT COUNT(*) FROM g7_ch_convs WHERE kind = 'channel' AND archived = 0")->fetchColumn(),
        'groups' => (int)$db->query("SELECT COUNT(*) FROM g7_ch_convs WHERE kind = 'group' AND archived = 0")->fetchColumn(),
        'files' => (int)$q('SELECT COUNT(*) FROM g7_ch_files WHERE created_at >= ?', [$since])->fetchColumn(),
        'files_mb' => round((int)$q('SELECT COALESCE(SUM(size),0) FROM g7_ch_files WHERE created_at >= ? AND stored NOT LIKE \'legacy:%\'', [$since])->fetchColumn() / 1048576, 1),
        'storage_mb' => round((int)$db->query("SELECT COALESCE(SUM(size),0) FROM g7_ch_files WHERE stored NOT LIKE 'legacy:%'")->fetchColumn() / 1048576, 1),
    ];
    $daily = [];
    foreach ($q("SELECT DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT user_id) u FROM g7_ch_messages WHERE $msgs GROUP BY DATE(created_at) ORDER BY d", [$since]) as $r) $daily[$r['d']] = ['n' => (int)$r['n'], 'u' => (int)$r['u']];
    $series = [];
    for ($t = strtotime($since); $t <= time(); $t += 86400) { $d = date('Y-m-d', $t); $series[] = ['d' => $d, 'n' => $daily[$d]['n'] ?? 0, 'u' => $daily[$d]['u'] ?? 0]; }
    $weekly = [];
    foreach ($db->query("SELECT YEARWEEK(created_at, 3) w, MIN(DATE(created_at)) d, COUNT(*) n, COUNT(DISTINCT user_id) u FROM g7_ch_messages WHERE kind <> 'system' AND created_at >= NOW() - INTERVAL 26 WEEK GROUP BY YEARWEEK(created_at, 3) ORDER BY w") as $r)
        $weekly[] = ['d' => $r['d'], 'n' => (int)$r['n'], 'u' => (int)$r['u']];
    $top = [];
    foreach ($q("SELECT c.id, c.name, c.visibility, COUNT(m.id) n, COUNT(DISTINCT m.user_id) u FROM g7_ch_messages m JOIN g7_ch_convs c ON c.id = m.conv_id AND c.kind = 'channel' WHERE m.kind <> 'system' AND m.created_at >= ? GROUP BY c.id ORDER BY n DESC LIMIT 10", [$since]) as $r)
        $top[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'visibility' => $r['visibility'], 'n' => (int)$r['n'], 'u' => (int)$r['u']];
    // Participación por departamento (equipo principal de cada persona): personas que escribieron sobre el total del equipo
    $dept = [];
    try {
        foreach ($q("SELECT t.name, COUNT(DISTINCT tm.user_id) members, COUNT(DISTINCT m.user_id) writers, COUNT(m.id) n
                     FROM g7_teams t JOIN g7_team_members tm ON tm.team_id = t.id AND tm.is_primary = 1 JOIN g7_users u ON u.id = tm.user_id AND u.active = 1
                     LEFT JOIN g7_ch_messages m ON m.user_id = tm.user_id AND m.kind <> 'system' AND m.created_at >= ?
                     WHERE t.active = 1 GROUP BY t.id ORDER BY n DESC", [$since]) as $r)
            $dept[] = ['name' => $r['name'], 'members' => (int)$r['members'], 'writers' => (int)$r['writers'], 'n' => (int)$r['n']];
    } catch (Throwable $e) {}
    $kinds = [];
    foreach ($q("SELECT c.kind, COUNT(m.id) n FROM g7_ch_messages m JOIN g7_ch_convs c ON c.id = m.conv_id WHERE m.kind <> 'system' AND m.created_at >= ? GROUP BY c.kind", [$since]) as $r) $kinds[$r['kind']] = (int)$r['n'];
    out(['ok' => true, 'days' => $days, 'totals' => $tot, 'daily' => $series, 'weekly' => $weekly, 'top' => $top, 'depts' => $dept, 'kinds' => $kinds]);
}

case 'admin_users': {
    $rows = $db->query("SELECT u.id, u.last_seen, u.active,
        (SELECT COUNT(*) FROM g7_ch_messages m WHERE m.user_id = u.id AND m.kind <> 'system' AND m.created_at >= NOW() - INTERVAL 30 DAY) n30,
        (SELECT MAX(created_at) FROM g7_ch_messages m WHERE m.user_id = u.id AND m.kind <> 'system') last_msg,
        (SELECT COUNT(*) FROM g7_ch_members x WHERE x.user_id = u.id) convs,
        (SELECT COALESCE(SUM(size),0) FROM g7_ch_files f WHERE f.user_id = u.id AND f.stored NOT LIKE 'legacy:%') bytes
        FROM g7_users u ORDER BY u.active DESC, u.last_seen DESC")->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'users' => array_map(fn($r) => ['id' => (int)$r['id'], 'active' => (bool)$r['active'], 'last_seen' => $r['last_seen'], 'n30' => (int)$r['n30'], 'last_msg' => $r['last_msg'], 'convs' => (int)$r['convs'], 'mb' => round($r['bytes'] / 1048576, 1)], $rows)]);
}

case 'admin_channels': {
    $rows = $db->query("SELECT c.*, (SELECT COUNT(*) FROM g7_ch_members m WHERE m.conv_id = c.id) members,
        (SELECT COUNT(*) FROM g7_ch_messages x WHERE x.conv_id = c.id AND x.kind <> 'system') msgs,
        (SELECT user_id FROM g7_ch_members m WHERE m.conv_id = c.id AND m.role = 'owner' LIMIT 1) owner
        FROM g7_ch_convs c WHERE c.kind IN ('channel','group') ORDER BY c.archived, c.kind DESC, c.name")->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'categories' => $db->query('SELECT id, name, sort FROM g7_ch_categories ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC),
        'channels' => array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'name' => $r['name'], 'topic' => $r['topic'], 'visibility' => $r['visibility'], 'announce' => (bool)$r['announce'],
            'allowed_teams' => array_values(array_filter(array_map('intval', explode(',', $r['allowed_teams'])))), 'category_id' => $r['category_id'] ? (int)$r['category_id'] : null,
            'archived' => (bool)$r['archived'], 'members' => (int)$r['members'], 'msgs' => (int)$r['msgs'], 'owner' => $r['owner'] ? (int)$r['owner'] : null,
            'last_msg_at' => $r['last_msg_at'], 'created_at' => $r['created_at'], 'sort' => (int)$r['sort']], $rows)]);
}

case 'admin_members': {
    $c = ch_conv($int('conv'));
    if (!$c || $c['kind'] === 'dm') fail('Esa conversación no existe', 404);
    $m = $db->prepare('SELECT user_id, role, joined_at FROM g7_ch_members WHERE conv_id = ? ORDER BY FIELD(role,"owner","admin","member"), joined_at'); $m->execute([$c['id']]);
    out(['ok' => true, 'members' => array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'role' => $x['role'], 'joined_at' => $x['joined_at']], $m->fetchAll(PDO::FETCH_ASSOC))]);
}

case 'admin_member_set': {
    // Sumar, sacar o cambiar el rol de alguien en un canal o grupo (también privados)
    $c = ch_conv($int('conv'));
    if (!$c || $c['kind'] === 'dm' || $c['archived']) fail('Esa conversación no existe', 404);
    $who = $int('user_id');
    if (!isset($users[$who])) fail('Esa persona no existe');
    $op = (string)($in['op'] ?? '');
    $cur = ch_member((int)$c['id'], $who);
    if ($op === 'add') {
        if ($cur) out(['ok' => true]);
        $db->prepare('INSERT INTO g7_ch_members (conv_id, user_id, last_read_id) VALUES (?,?,?)')->execute([$c['id'], $who, max(0, (int)$c['last_msg_id'] - 20)]);
        $system((int)$c['id'], 'sumó a ' . $name($who));
        ch_event((int)$c['id'], 'conv', null, $uid, $who); ch_notify($who, 'invite', (int)$c['id'], null, $uid, $c['name']);
    } elseif ($op === 'remove') {
        if (!$cur) out(['ok' => true]);
        $db->prepare('DELETE FROM g7_ch_members WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $who]);
        if ($cur['role'] === 'owner') {
            $n = $db->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ? ORDER BY role = "admin" DESC, joined_at LIMIT 1'); $n->execute([$c['id']]);
            if ($next = $n->fetchColumn()) $db->prepare('UPDATE g7_ch_members SET role = "owner" WHERE conv_id = ? AND user_id = ?')->execute([$c['id'], $next]);
        }
        $system((int)$c['id'], 'sacó a ' . $name($who));
        ch_event((int)$c['id'], 'gone', null, $uid, $who);
    } elseif (in_array($op, ['admin', 'member', 'owner'], true)) {
        if (!$cur) fail('Esa persona no está en la conversación');
        if ($op === 'owner') $db->prepare("UPDATE g7_ch_members SET role = 'admin' WHERE conv_id = ? AND role = 'owner'")->execute([$c['id']]);
        $db->prepare('UPDATE g7_ch_members SET role = ? WHERE conv_id = ? AND user_id = ?')->execute([$op, $c['id'], $who]);
        $system((int)$c['id'], ['admin' => 'hizo administrador a ', 'member' => 'le sacó la administración a ', 'owner' => 'le pasó la conversación a '][$op] . $name($who));
        ch_event((int)$c['id'], 'conv', null, $uid);
    } else fail('Pedido inválido');
    ch_audit($uid, 'admin_member_' . $op, (int)$c['id'], $name($who));
    out(['ok' => true]);
}

case 'admin_conv_restore': {
    $c = ch_conv($int('conv'));
    if (!$c || !$c['archived']) fail('Esa conversación no está borrada');
    $db->prepare('UPDATE g7_ch_convs SET archived = 0 WHERE id = ?')->execute([$c['id']]);
    $db->prepare("INSERT IGNORE INTO g7_ch_members (conv_id, user_id, role) VALUES (?,?,'owner')")->execute([$c['id'], $uid]);
    ch_audit($uid, 'conv_restore', (int)$c['id'], $c['name']);
    out(['ok' => true]);
}

case 'admin_policy': {
    if (isset($in['policy']) && is_array($in['policy'])) {
        $p = ch_policy(); $old = $p;
        foreach (['max_mb' => [1, 200], 'quota_mb' => [0, 100000], 'retention_days' => [0, 3650], 'rate_per_10s' => [3, 100]] as $k => [$lo, $hi])
            if (isset($in['policy'][$k])) $p[$k] = max($lo, min($hi, (int)$in['policy'][$k]));
        if (isset($in['policy']['blocked_ext'])) {
            $all = array_merge(G7_EXT_IMG, G7_EXT_MEDIA, G7_EXT_DOCS);
            $p['blocked_ext'] = array_values(array_intersect($all, array_map('strtolower', (array)$in['policy']['blocked_ext'])));
        }
        if ($p['retention_days'] > 0 && $p['retention_days'] < 30 && empty($in['confirm_short'])) fail('Una retención menor a 30 días borra mucho: confirmala', 409);
        g7_cfg_set('chat_policy', json_encode($p));
        $diff = [];
        foreach ($p as $k => $v) if ($old[$k] !== $v) $diff[] = "$k: " . json_encode($old[$k]) . ' → ' . json_encode($v);
        if ($diff) ch_audit($uid, 'policy', null, 'Políticas del chat', implode('; ', $diff));
    }
    out(['ok' => true, 'policy' => ch_policy(), 'all_ext' => array_merge(G7_EXT_IMG, G7_EXT_MEDIA, G7_EXT_DOCS)]);
}

case 'admin_audit': {
    $where = ['1=1']; $args = [];
    if ($a = (string)($in['filter'] ?? '')) { $where[] = 'action LIKE ?'; $args[] = $a . '%'; }
    if ($u = $int('user')) { $where[] = 'user_id = ?'; $args[] = $u; }
    $off = max(0, $int('offset'));
    $st = $db->prepare('SELECT * FROM g7_ch_audit WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 101 OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $names = [];
    foreach ($rows as $r) if ($r['conv_id'] && !isset($names[$r['conv_id']]) && ($c = ch_conv((int)$r['conv_id']))) $names[$r['conv_id']] = ($c['kind'] === 'channel' ? '#' : '') . ($c['name'] ?: 'mensaje directo');
    out(['ok' => true, 'more' => count($rows) > 100, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'action' => $r['action'], 'conv' => $names[$r['conv_id']] ?? '',
        'target' => $r['target'], 'detail' => $r['detail'], 'ip' => $r['ip'], 'at' => $r['created_at']], array_slice($rows, 0, 100))]);
}
}
