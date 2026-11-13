<?php if (!isset($db)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/**
 * chat/api2.php — Acciones de las etapas 2 a 5 (la incluye api.php antes de su switch, con las mismas variables:
 * $db, $uid, $me, $in, $act, $int, $users, $admin, $need, $name, $system). Si la acción no es de acá, sigue de largo.
 */
$msgRow = function (int $id) use ($db): array {
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ?'); $q->execute([$id]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m || $m['deleted_at']) fail('Ese mensaje ya no está', 404);
    return $m;
};
$one = fn(int $id) => ch_messages_by_ids([$id], $uid)[0] ?? null;
$when = function ($s): string {
    $t = strtotime((string)$s);
    if (!$t) fail('Elige una fecha y hora válidas');
    if ($t < time() - 60) fail('Esa fecha ya pasó');
    if ($t > time() + 366 * 86400) fail('Elige una fecha dentro del próximo año');
    return date('Y-m-d H:i:s', $t);
};

switch ($act) {

// ── Hilos ───────────────────────────────────────────────────────
case 'thread_follow': {
    $m = $msgRow($int('id'));
    $need((int)$m['conv_id'], 'member');
    $db->prepare('INSERT INTO g7_ch_follow (message_id, user_id, following) VALUES (?,?,?) ON DUPLICATE KEY UPDATE following = VALUES(following)')
       ->execute([$m['id'], $uid, empty($in['follow']) ? 0 : 1]);
    out(['ok' => true, 'following' => !empty($in['follow'])]);
}
case 'thread_state': {
    $m = $msgRow($int('id'));
    $need((int)$m['conv_id']);
    $f = $db->prepare('SELECT following FROM g7_ch_follow WHERE message_id = ? AND user_id = ?'); $f->execute([$m['id'], $uid]);
    $v = $f->fetchColumn();
    if ($last = $int('read')) $db->prepare('INSERT INTO g7_ch_follow (message_id, user_id, following, last_read_id) VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))')->execute([$m['id'], $uid, $last]);
    out(['ok' => true, 'following' => $v !== false && (int)$v === 1]);
}

// ── Vista previa de enlaces (la pide quien escribió, después de mandar) ──
case 'unfurl': {
    $m = $msgRow($int('id'));
    if ((int)$m['user_id'] !== $uid) fail('Pedido inválido', 403);
    $meta = json_decode((string)$m['meta'], true) ?: [];
    if (!preg_match('#\bhttps?://[^\s<>"\']+#i', $m['body'], $u)) out(['ok' => true]);
    $url = rtrim($u[0], '.,;:!?)]');
    if (isset($meta['preview']) && ($meta['preview']['url'] ?? '') === $url) out(['ok' => true]);
    $p = ch_unfurl($url);
    if (!$p) out(['ok' => true]);
    $meta['preview'] = $p;
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $m['id']]);
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}
case 'unfurl_hide': {
    $m = $msgRow($int('id'));
    if ((int)$m['user_id'] !== $uid) fail('Solo quien escribió el mensaje puede quitar la vista previa', 403);
    $meta = json_decode((string)$m['meta'], true) ?: []; unset($meta['preview']);
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([$meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $m['id']]);
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}

// ── Fijar ───────────────────────────────────────────────────────
case 'pin': {
    $m = $msgRow($int('id'));
    $c = $need((int)$m['conv_id'], 'member');
    if ($c['announce'] && !ch_can_manage($c, $me)) fail('En este canal solo fijan los administradores', 403);
    $on = !empty($in['pinned']);
    $db->prepare('UPDATE g7_ch_messages SET pinned_at = ' . ($on ? 'NOW()' : 'NULL') . ', pinned_by = ? WHERE id = ?')->execute([$on ? $uid : null, $m['id']]);
    if ($on) { $system((int)$c['id'], 'fijó un mensaje'); ch_notify((int)$m['user_id'], 'pin', (int)$c['id'], (int)$m['id'], $uid, mb_substr($m['body'], 0, 200)); }
    ch_event((int)$c['id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}

// ── Guardados ───────────────────────────────────────────────────
case 'save': {
    $m = $msgRow($int('id'));
    $need((int)$m['conv_id']);
    if (array_key_exists('saved', $in) && empty($in['saved'])) {
        $db->prepare('DELETE FROM g7_ch_saved WHERE user_id = ? AND message_id = ?')->execute([$uid, $m['id']]);
    } else {
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500); $cat = mb_substr(trim(strip_tags((string)($in['category'] ?? ''))), 0, 60);
        $db->prepare('INSERT INTO g7_ch_saved (user_id, message_id, note, category) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note = VALUES(note), category = VALUES(category)')
           ->execute([$uid, $m['id'], $note, $cat]);
    }
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}
case 'saved_list': {
    $rows = $db->query("SELECT m.*, s.created_at AS saved_at FROM g7_ch_saved s JOIN g7_ch_messages m ON m.id = s.message_id WHERE s.user_id = $uid ORDER BY s.created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    // Solo lo que todavía puede leer
    $rows = array_values(array_filter($rows, function ($r) use ($me) { $c = ch_conv((int)$r['conv_id']); return $c && ch_can_read($c, $me); }));
    $list = ch_render($rows, $uid);
    foreach ($list as $i => &$x) $x['saved_at'] = $rows[$i]['saved_at'];
    $cats = $db->query("SELECT DISTINCT category FROM g7_ch_saved WHERE user_id = $uid AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    out(['ok' => true, 'messages' => $list, 'categories' => $cats]);
}

// ── Reenviar ────────────────────────────────────────────────────
case 'forward': {
    $m = $msgRow($int('id'));
    $src = $need((int)$m['conv_id']);
    $dst = $need($int('conv'), 'post');
    $comment = ch_clean_body((string)($in['comment'] ?? ''));
    $fwd = ['id' => (int)$m['id'], 'user_id' => (int)$m['user_id'], 'conv_id' => (int)$src['id'],
            'conv_name' => $src['kind'] === 'channel' ? '#' . $src['name'] : ($src['kind'] === 'group' ? $src['name'] : 'Mensaje directo'),
            'body' => mb_substr((string)$m['body'], 0, 4000), 'created_at' => $m['created_at']];
    $db->beginTransaction();
    $id = ch_insert_message((int)$dst['id'], $uid, $comment, ['meta' => ['fwd' => $fwd]]);
    // Los archivos se comparten sin copiarlos en disco
    $db->prepare('INSERT INTO g7_ch_files (message_id, conv_id, user_id, name, stored, size, mime) SELECT ?, ?, ?, name, stored, size, mime FROM g7_ch_files WHERE message_id = ?')
       ->execute([$id, $dst['id'], $uid, $m['id']]);
    $db->commit();
    ch_after_send($id);
    out(['ok' => true, 'message' => $one($id)]);
}

// ── Actividad ───────────────────────────────────────────────────
case 'notifs': {
    $before = $int('before');
    $rows = $db->query("SELECT * FROM g7_ch_notifs WHERE user_id = $uid" . ($before ? " AND id < $before" : '') . ' ORDER BY id DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    $unread = (int)$db->query("SELECT COUNT(*) FROM g7_ch_notifs WHERE user_id = $uid AND read_at IS NULL")->fetchColumn();
    out(['ok' => true, 'unread' => $unread, 'more' => count($rows) === 40, 'items' => array_map(fn($n) => ['id' => (int)$n['id'], 'kind' => $n['kind'], 'conv_id' => $n['conv_id'] ? (int)$n['conv_id'] : null,
        'message_id' => $n['message_id'] ? (int)$n['message_id'] : null, 'actor_id' => (int)$n['actor_id'], 'extra' => $n['extra'], 'created_at' => $n['created_at'], 'read' => (bool)$n['read_at']], $rows)]);
}
case 'notif_read': {
    if (!empty($in['all'])) $db->prepare('UPDATE g7_ch_notifs SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$uid]);
    else $db->prepare('UPDATE g7_ch_notifs SET read_at = NOW() WHERE user_id = ? AND id = ? AND read_at IS NULL')->execute([$uid, $int('id')]);
    out(['ok' => true]);
}
case 'read_all': {
    // Marcar todo como leído (conversaciones y actividad)
    $db->prepare('UPDATE g7_ch_members m JOIN g7_ch_convs c ON c.id = m.conv_id SET m.last_read_id = c.last_msg_id WHERE m.user_id = ?')->execute([$uid]);
    $db->prepare('UPDATE g7_ch_notifs SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$uid]);
    out(['ok' => true, 'convs' => ch_conv_summaries($uid)]);
}

// ── Preferencias ────────────────────────────────────────────────
case 'prefs': {
    if (isset($in['prefs']) && is_array($in['prefs'])) {
        $p = ch_prefs($uid);
        foreach (CH_PREFS_DEFAULT as $k => $d) if (array_key_exists($k, $in['prefs'])) {
            $v = $in['prefs'][$k];
            $p[$k] = is_bool($d) ? (bool)$v : (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v) ? (string)$v : $d);
        }
        $db->prepare('REPLACE INTO g7_ch_prefs (user_id, data) VALUES (?,?)')->execute([$uid, json_encode($p)]);
    }
    out(['ok' => true, 'prefs' => ch_prefs($uid)]);
}
case 'notify_level': {
    $c = $need($int('conv'), 'member');
    $lv = in_array($in['level'] ?? '', ['all', 'mentions', 'none'], true) ? $in['level'] : 'all';
    $db->prepare('UPDATE g7_ch_members SET notify = ? WHERE conv_id = ? AND user_id = ?')->execute([$lv, $c['id'], $uid]);
    out(['ok' => true, 'conv' => ch_conv_summaries($uid, [(int)$c['id']])[0] ?? null]);
}

// ── Búsqueda global ─────────────────────────────────────────────
case 'search': {
    $q = trim((string)($in['q'] ?? ''));
    $res = ['ok' => true, 'messages' => [], 'people' => [], 'channels' => [], 'files' => []];
    // Conversaciones que puede leer: las suyas y los canales abiertos a él
    $readable = array_map('intval', $db->query("SELECT conv_id FROM g7_ch_members WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($db->query("SELECT * FROM g7_ch_convs WHERE kind = 'channel' AND archived = 0 AND visibility <> 'private'") as $c) if (ch_can_join($c, $me)) $readable[] = (int)$c['id'];
    $readable = array_values(array_unique($readable)) ?: [0];
    $where = ['m.conv_id IN (' . implode(',', $readable) . ')', 'm.deleted_at IS NULL', "m.kind <> 'system'"]; $args = [];
    $words = array_slice(array_filter(preg_split('/\s+/u', $q)), 0, 6);
    foreach ($words as $w) { $where[] = 'm.body LIKE ?'; $args[] = '%' . addcslashes($w, '%_\\') . '%'; }
    if ($u = $int('user')) { $where[] = 'm.user_id = ?'; $args[] = $u; }
    if ($c = $int('conv')) { $where[] = 'm.conv_id = ?'; $args[] = $c; }
    if ($t = $int('team')) { $where[] = 'm.user_id IN (SELECT user_id FROM g7_team_members WHERE team_id = ?)'; $args[] = $t; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? ''))) { $where[] = 'm.created_at >= ?'; $args[] = $in['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? ''))) { $where[] = 'm.created_at <= ?'; $args[] = $in['to'] . ' 23:59:59'; }
    $has = (string)($in['has'] ?? '');
    if ($has === 'link') $where[] = "m.body REGEXP 'https?://'";
    elseif (in_array($has, ['file', 'img', 'docs', 'media'], true)) {
        $fw = ['file' => '1=1', 'img' => "f.mime LIKE 'image/%'", 'media' => "(f.mime LIKE 'video/%' OR f.mime LIKE 'audio/%')", 'docs' => "f.mime NOT LIKE 'image/%' AND f.mime NOT LIKE 'video/%' AND f.mime NOT LIKE 'audio/%'"][$has];
        $where[] = "EXISTS (SELECT 1 FROM g7_ch_files f WHERE f.message_id = m.id AND $fw)";
    }
    if ($words || $int('user') || $int('conv') || $int('team') || $has || !empty($in['from']) || !empty($in['to'])) {
        $off = max(0, $int('offset'));
        $st = $db->prepare('SELECT m.* FROM g7_ch_messages m WHERE ' . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT 41 OFFSET $off");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $res['more'] = count($rows) > 40;
        $res['messages'] = ch_render(array_slice($rows, 0, 40), $uid);
        $cs = [];
        foreach ($res['messages'] as $m) $cs[$m['conv_id']] = 1;
        $res['conv_names'] = [];
        foreach (array_keys($cs) as $cid) { $c = ch_conv($cid); $res['conv_names'][$cid] = $c['kind'] === 'channel' ? '#' . $c['name'] : ($c['kind'] === 'group' ? $c['name'] : 'Mensaje directo'); }
    }
    if ($q !== '' && !$int('user') && !$int('conv')) {
        $ql = mb_strtolower($q);
        foreach ($users as $u) if ($u['active'] && (str_contains(mb_strtolower($u['name']), $ql) || str_contains(mb_strtolower($u['username']), $ql) || str_contains(mb_strtolower($u['dept'] . ' ' . $u['position']), $ql))) $res['people'][] = $u['id'];
        $res['people'] = array_slice($res['people'], 0, 12);
        foreach ($db->query("SELECT * FROM g7_ch_convs WHERE kind <> 'dm' AND archived = 0") as $c) {
            if (!in_array((int)$c['id'], $readable, true)) continue;
            if (str_contains(mb_strtolower($c['name'] . ' ' . $c['topic']), $ql)) $res['channels'][] = ['id' => (int)$c['id'], 'kind' => $c['kind'], 'name' => $c['name'], 'topic' => $c['topic'], 'visibility' => $c['visibility']];
        }
        $fs = $db->prepare('SELECT f.id, f.name, f.size, f.mime, f.user_id, f.conv_id, f.message_id, f.created_at FROM g7_ch_files f JOIN g7_ch_messages m ON m.id = f.message_id AND m.deleted_at IS NULL WHERE f.conv_id IN (' . implode(',', $readable) . ') AND f.name LIKE ? ORDER BY f.id DESC LIMIT 20');
        $fs->execute(['%' . addcslashes($q, '%_\\') . '%']);
        $res['files'] = array_map(fn($f) => ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'], 'user_id' => (int)$f['user_id'], 'conv_id' => (int)$f['conv_id'], 'message_id' => (int)$f['message_id'], 'created_at' => $f['created_at'], 'url' => 'api.php?action=file&id=' . (int)$f['id']], $fs->fetchAll(PDO::FETCH_ASSOC));
    }
    out($res);
}

// ── Encuestas ───────────────────────────────────────────────────
case 'poll_create': {
    $c = $need($int('conv'), 'post');
    $qq = mb_substr(trim(strip_tags((string)($in['question'] ?? ''))), 0, 300);
    $opts = array_values(array_unique(array_filter(array_map(fn($o) => mb_substr(trim(strip_tags((string)$o)), 0, 120), (array)($in['options'] ?? [])), 'strlen')));
    if ($qq === '') fail('Escribe la pregunta');
    if (count($opts) < 2) fail('La encuesta necesita al menos dos opciones');
    if (count($opts) > 12) fail('Hasta 12 opciones');
    $meta = ['poll' => ['question' => $qq, 'options' => $opts, 'multi' => !empty($in['multi']), 'anonymous' => !empty($in['anonymous']), 'closed' => false]];
    $id = ch_insert_message((int)$c['id'], $uid, '', ['kind' => 'poll', 'meta' => $meta]);
    out(['ok' => true, 'message' => $one($id)]);
}
case 'poll_vote': {
    $m = $msgRow($int('id'));
    if ($m['kind'] !== 'poll') fail('Ese mensaje no es una encuesta');
    $need((int)$m['conv_id'], 'member');
    $meta = json_decode((string)$m['meta'], true) ?: [];
    if (!empty($meta['poll']['closed'])) fail('La encuesta ya está cerrada');
    $n = count($meta['poll']['options'] ?? []);
    $opts = array_values(array_unique(array_filter(array_map('intval', (array)($in['opts'] ?? [])), fn($o) => $o >= 0 && $o < $n)));
    if (empty($meta['poll']['multi'])) $opts = array_slice($opts, 0, 1);
    $db->beginTransaction();
    $db->prepare('DELETE FROM g7_ch_poll_votes WHERE message_id = ? AND user_id = ?')->execute([$m['id'], $uid]);
    $st = $db->prepare('INSERT INTO g7_ch_poll_votes (message_id, opt, user_id) VALUES (?,?,?)');
    foreach ($opts as $o) $st->execute([$m['id'], $o, $uid]);
    $db->commit();
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}
case 'poll_close': {
    $m = $msgRow($int('id'));
    $c = $need((int)$m['conv_id'], 'member');
    if ((int)$m['user_id'] !== $uid && !ch_can_manage($c, $me)) fail('Solo quien creó la encuesta la puede cerrar', 403);
    $meta = json_decode((string)$m['meta'], true) ?: [];
    $meta['poll']['closed'] = empty($in['reopen']);
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $m['id']]);
    ch_event((int)$m['conv_id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'message' => $one((int)$m['id'])]);
}

// ── Recordatorios ───────────────────────────────────────────────
case 'remind_create': {
    $at = $when($in['at'] ?? '');
    $mid = $int('message_id') ?: null; $conv = $int('conv') ?: null;
    $text = mb_substr(trim(strip_tags((string)($in['text'] ?? ''))), 0, 500);
    if ($mid) { $m = $msgRow($mid); $need((int)$m['conv_id']); $conv = (int)$m['conv_id']; if ($text === '') $text = mb_substr(preg_replace('/\s+/', ' ', $m['body']), 0, 200) ?: 'Revisar este mensaje'; }
    if ($text === '') fail('Escribe qué hay que recordar');
    $shared = !empty($in['shared']);
    if ($shared) { if (!$conv) fail('Elige en qué conversación avisar'); $need($conv, 'post'); }
    elseif ($conv) $need($conv);
    $db->prepare('INSERT INTO g7_ch_reminders (user_id, conv_id, message_id, text, remind_at, shared) VALUES (?,?,?,?,?,?)')->execute([$uid, $conv, $mid, $text, $at, $shared ? 1 : 0]);
    out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}
case 'remind_list': {
    $rows = $db->query("SELECT * FROM g7_ch_reminders WHERE user_id = $uid AND (done_at IS NULL OR done_at > NOW() - INTERVAL 7 DAY) ORDER BY done_at IS NOT NULL, remind_at LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'text' => $r['text'], 'at' => $r['remind_at'], 'shared' => (bool)$r['shared'], 'conv_id' => $r['conv_id'] ? (int)$r['conv_id'] : null,
        'message_id' => $r['message_id'] ? (int)$r['message_id'] : null, 'done' => (bool)$r['done_at']], $rows)]);
}
case 'remind_delete': {
    $db->prepare('DELETE FROM g7_ch_reminders WHERE id = ? AND user_id = ?')->execute([$int('id'), $uid]);
    out(['ok' => true]);
}

// ── Mensajes programados ────────────────────────────────────────
case 'sched_create':
case 'sched_update': {
    $c = $need($int('conv'), 'post');
    $at = $when($in['at'] ?? '');
    $body = ch_clean_body((string)($in['body'] ?? ''));
    $files = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['files'] ?? []))))), 0, 10);
    if ($files) {
        $ok = $db->query('SELECT COUNT(*) FROM g7_ch_files WHERE id IN (' . implode(',', $files) . ") AND user_id = $uid AND message_id IS NULL AND conv_id = " . (int)$c['id'])->fetchColumn();
        if ((int)$ok !== count($files)) fail('Algún archivo ya no está disponible');
    }
    if ($body === '' && !$files) fail('El mensaje está vacío');
    if ($act === 'sched_update') {
        $u = $db->prepare("UPDATE g7_ch_scheduled SET body = ?, send_at = ?, conv_id = ? WHERE id = ? AND user_id = ? AND status = 'pending'");
        $u->execute([$body, $at, $c['id'], $int('id'), $uid]);
        if (!$u->rowCount()) fail('Ese mensaje ya se mandó o se canceló');
        out(['ok' => true]);
    }
    $db->prepare('INSERT INTO g7_ch_scheduled (user_id, conv_id, body, files, send_at) VALUES (?,?,?,?,?)')->execute([$uid, $c['id'], $body, implode(',', $files), $at]);
    out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}
case 'sched_list': {
    $rows = $db->query("SELECT * FROM g7_ch_scheduled WHERE user_id = $uid AND (status IN ('pending','failed') OR send_at > NOW() - INTERVAL 7 DAY) ORDER BY status <> 'pending', send_at LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'conv_id' => (int)$r['conv_id'], 'body' => $r['body'], 'at' => $r['send_at'], 'status' => $r['status'], 'error' => $r['error'],
        'files' => $r['files'] === '' ? 0 : count(explode(',', $r['files'])), 'message_id' => $r['message_id'] ? (int)$r['message_id'] : null], $rows)]);
}
case 'sched_cancel': {
    $u = $db->prepare("UPDATE g7_ch_scheduled SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'"); $u->execute([$int('id'), $uid]);
    if (!$u->rowCount()) fail('Ese mensaje ya se mandó');
    out(['ok' => true]);
}

// ── Mensaje → tarea (módulo Tareas) ─────────────────────────────
case 'task_cfg': {
    // Tableros que puede usar (con sus estados) y categorías de tickets
    require_once __DIR__ . '/../trabajo/lib.php';
    wk_install($db);
    $cfg = tk_config($db);
    $wctx = wk_ctx($db, $uid);
    $boards = [];
    if ($wctx['role'] !== 'solicitante') foreach ($db->query('SELECT b.id FROM g7_wk_boards b WHERE b.archived = 0 AND ' . wk_board_where($wctx) . ' ORDER BY b.is_default DESC, b.position, b.name')->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $b = wk_board($db, (int)$bid);
        $boards[] = ['id' => $b['id'], 'name' => $b['name'], 'statuses' => array_map(fn($s) => ['id' => $s['id'], 'name' => $s['name']], wk_statuses($db, $b, $cfg))];
    }
    $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], $db->query('SELECT id, name FROM g7_wk_ticket_cats WHERE active = 1 ORDER BY position, name')->fetchAll(PDO::FETCH_ASSOC));
    // Con un mensaje: quién pide, quién atiende y en qué departamento (para el formulario de "Abrir un ticket")
    $roles = null;
    if ($int('message')) {
        $m = $msgRow($int('message')); $cv = $need((int)$m['conv_id'], 'member');
        $r = ch_ticket_roles($uid, $m, $cv, $int('assignee'));
        $roles = ['requester' => $r['requester'], 'assignee' => $r['assignee'], 'team' => $r['team'], 'category_id' => $r['category_id'], 'mine' => (int)$m['user_id'] === $uid, 'kind' => $cv['kind']];
    }
    out(['ok' => true, 'roles' => $roles, 'boards' => $boards, 'statuses' => $boards ? $boards[0]['statuses'] : [], 'cats' => $cats,
         'priorities' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $cfg['priorities']), 'default_priority' => $cfg['default_priority'],
         'can' => !function_exists('g7_can') || g7_can($uid, 'tareas', 'create') || g7_can($uid, 'tareas')]);
}
case 'ticket_from_msg': {
    $m = $msgRow($int('id'));
    $c = $need((int)$m['conv_id'], 'member');
    if (function_exists('g7_can') && !g7_can($uid, 'tareas')) fail('No tienes acceso a Gestión de trabajo', 403);
    try { [$tkid, $subject] = ch_make_ticket($uid, $m, $in, $c); } catch (RuntimeException $e) { fail($e->getMessage()); }
    $meta = json_decode((string)$m['meta'], true) ?: [];
    $meta['tickets'][] = ['id' => $tkid, 'title' => $subject];
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $m['id']]);
    ch_event((int)$c['id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'ticket_id' => $tkid, 'url' => '/toolz/trabajo/#tickets/' . $tkid, 'message' => $one((int)$m['id'])]);
}
// ── Acciones rápidas (/ticket, /tarea): el texto se publica como mensaje y queda enlazado. CHAT_ACCIONES.md ──
case 'quick_ticket':
case 'quick_task': {
    $c = $need($int('conv'), 'post');
    if (function_exists('g7_can') && !g7_can($uid, 'tareas')) fail('No tienes acceso a Gestión de trabajo', 403);
    $body = ch_clean_body((string)($in['body'] ?? ''));
    $title = ch_subject_from($body, $act === 'quick_task' ? 250 : 200);
    if ($title === '') fail($act === 'quick_task' ? 'Escribe la tarea: /tarea preparar la presentación' : 'Escribe el pedido: /ticket arreglar el banner de la home');
    // Quién atiende: en un mensaje directo, la otra persona; en un grupo o canal, la elegida (tiene que ser miembro)
    $asg = $int('assignee');
    if ($c['kind'] !== 'dm' && !$asg) fail('Elige quién lo atiende');
    if ($asg && ($asg === $uid || !ch_member((int)$c['id'], $asg))) fail('Esa persona no está en la conversación');
    $cid = mb_substr(preg_replace('/[^\w\-]/', '', (string)($in['client_id'] ?? '')), 0, 40) ?: null;
    // Silencioso hasta que existan el ticket o la tarea: si falla, el mensaje se borra y nadie lo vio
    $id = ch_insert_message((int)$c['id'], $uid, $body, ['client_id' => $cid, 'silent' => true]);
    $m = $msgRow($id);
    try {
        if ($act === 'quick_ticket') {
            [$tkid, $subject, $roles] = ch_make_ticket($uid, $m, ['subject' => $title, 'description' => $body, 'assignee' => $asg], $c);
            if (!$roles['assignee']) throw new RuntimeException('En tus notas no hay a quién asignarlo: usa un mensaje directo o un grupo');
            $key = 'tickets'; $item = ['id' => $tkid, 'title' => $subject];
            $res = ['ticket_id' => $tkid, 'assignee' => $roles['assignee'], 'team' => $roles['team']];
        } else {
            $to = $asg ?: (int)$db->query('SELECT user_id FROM g7_ch_members WHERE conv_id = ' . (int)$c['id'] . ' AND user_id <> ' . $uid . ' LIMIT 1')->fetchColumn();
            if (!$to) throw new RuntimeException('En tus notas no hay a quién asignarla: usa un mensaje directo o un grupo');
            [$tid, $ttl, $tb] = ch_make_task($uid, $m, ['title' => $title, 'assignees' => [$to]]);
            $key = 'tasks'; $item = ['id' => $tid, 'title' => $ttl, 'board' => $tb];
            $res = ['task_id' => $tid, 'assignee' => $to];
        }
    } catch (Throwable $e) {
        // Sin ticket o tarea no queda el mensaje suelto
        $db->prepare('DELETE FROM g7_ch_messages WHERE id = ?')->execute([$id]);
        $db->prepare('UPDATE g7_ch_convs SET last_msg_id = (SELECT MAX(id) FROM g7_ch_messages WHERE conv_id = ? AND parent_id IS NULL) WHERE id = ?')->execute([$c['id'], $c['id']]);
        fail($e instanceof RuntimeException ? $e->getMessage() : 'No se pudo crear: ' . $e->getMessage());
    }
    $meta = json_decode((string)$m['meta'], true) ?: [];
    $meta[$key][] = $item;
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $id]);
    ch_event((int)$c['id'], 'msg', $id, $uid);
    ch_after_send($id);
    out(['ok' => true, 'message' => $one($id)] + $res);
}
// Nota interna en un ticket (/nota o el menú de un mensaje). No se publica en el chat.
case 'note_targets': {
    $c = $need($int('conv'), 'member');
    if (function_exists('g7_can') && !g7_can($uid, 'tareas')) fail('No tienes acceso a Gestión de trabajo', 403);
    out(['ok' => true, 'tickets' => ch_note_targets($uid, (int)$c['id'])]);
}
case 'ticket_note': {
    if (function_exists('g7_can') && !g7_can($uid, 'tareas')) fail('No tienes acceso a Gestión de trabajo', 403);
    $body = trim((string)($in['body'] ?? ''));
    if ($int('message')) {   // desde el menú de un mensaje: el mensaje con su enlace, si no se escribió otra cosa
        $m = $msgRow($int('message')); $need((int)$m['conv_id'], 'member');
        if ($body === '') $body = trim((string)$m['body']);
        $body .= "\n\nDesde el chat: " . g7_site_origin() . '/toolz/chat/?m=' . (int)$m['id'];
    }
    try { $t = ch_ticket_note($uid, $int('ticket'), $body); } catch (RuntimeException $e) { fail($e->getMessage()); }
    out(['ok' => true, 'ticket_id' => (int)$t['id'], 'code' => wk_tk_code((int)$t['id']), 'subject' => $t['subject']]);
}
case 'task_from_msg': {
    $m = $msgRow($int('id'));
    $c = $need((int)$m['conv_id'], 'member');
    if (function_exists('g7_can') && !g7_can($uid, 'tareas')) fail('No tienes acceso al módulo Tareas', 403);
    try { [$tid, $title, $tb] = ch_make_task($uid, $m, $in); } catch (RuntimeException $e) { fail($e->getMessage()); }
    $meta = json_decode((string)$m['meta'], true) ?: [];
    $meta['tasks'][] = ['id' => $tid, 'title' => $title, 'board' => $tb];
    $db->prepare('UPDATE g7_ch_messages SET meta = ? WHERE id = ?')->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $m['id']]);
    ch_event((int)$c['id'], 'upd', (int)$m['id'], $uid);
    out(['ok' => true, 'task_id' => $tid, 'url' => '/toolz/tareas.php?b=' . $tb . '&t=' . $tid, 'message' => $one((int)$m['id'])]);
}

// ── Anuncios ────────────────────────────────────────────────────
case 'announce': {
    $c = $need($int('conv'), 'post');
    if ($c['kind'] !== 'channel' || !ch_can_manage($c, $me)) fail('Los anuncios los publican los administradores de un canal', 403);
    $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 160);
    if ($title === '') fail('El anuncio necesita un título');
    $body = ch_clean_body((string)($in['body'] ?? ''));
    $img = $int('image_file');
    $files = array_slice(array_values(array_unique(array_filter(array_map('intval', (array)($in['files'] ?? []))))), 0, 10);
    $all = array_values(array_unique(array_filter(array_merge([$img], $files))));
    if ($all) {
        $ok = $db->query('SELECT COUNT(*) FROM g7_ch_files WHERE id IN (' . implode(',', $all) . ") AND user_id = $uid AND message_id IS NULL AND conv_id = " . (int)$c['id'])->fetchColumn();
        if ((int)$ok !== count($all)) fail('Algún archivo ya no está disponible');
    }
    $meta = ['ann' => ['title' => $title, 'image' => $img ? 'api.php?action=file&id=' . $img : '']];
    if (!empty($in['at'])) {
        $at = $when($in['at']);
        $db->prepare("INSERT INTO g7_ch_scheduled (user_id, conv_id, body, files, meta, kind, send_at) VALUES (?,?,?,?,?,'announce',?)")
           ->execute([$uid, $c['id'], $body, implode(',', $all), json_encode($meta, JSON_UNESCAPED_UNICODE), $at]);
        out(['ok' => true, 'scheduled' => true]);
    }
    $db->beginTransaction();
    $id = ch_insert_message((int)$c['id'], $uid, $body, ['kind' => 'announce', 'meta' => $meta]);
    if ($all) $db->prepare('UPDATE g7_ch_files SET message_id = ? WHERE id IN (' . implode(',', $all) . ') AND user_id = ? AND message_id IS NULL')->execute([$id, $uid]);
    $db->commit();
    ch_after_send($id);
    out(['ok' => true, 'message' => $one($id)]);
}
case 'announcements': {
    $mine = array_map('intval', $db->query("SELECT conv_id FROM g7_ch_members WHERE user_id = $uid")->fetchAll(PDO::FETCH_COLUMN)) ?: [0];
    $rows = $db->query("SELECT * FROM g7_ch_messages WHERE kind = 'announce' AND deleted_at IS NULL AND conv_id IN (" . implode(',', $mine) . ') ORDER BY id DESC LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
    out(['ok' => true, 'messages' => ch_render($rows, $uid)]);
}
}
