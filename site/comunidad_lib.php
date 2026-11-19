<?php
/**
 * comunidad_lib.php — Lo que comparten comunidad.php y perfil.php (2026-11-14): avisos, reconocimientos (kudos),
 * feed paginado con filtros, menciones que avisan y los datos del perfil. Doc: toolz/COMUNIDAD.md
 */
if (!function_exists('pushNotif')) { function pushNotif($db,$toId,$fromId,$type,$targetId,$profileId,$body){
    if($toId==$fromId)return;
    // profile_id tiene clave foránea a g7_users: 0 ("sin perfil") la rompía y el aviso no se guardaba (kudos, menciones y respuestas). Va NULL.
    try{$db->prepare('INSERT INTO g7_notifications (user_id,from_id,type,target_id,profile_id,body) VALUES (?,?,?,?,?,?)')->execute([$toId,$fromId,$type,$targetId,$profileId?:null,mb_substr($body,0,280)]);}catch(Exception $e){}
} }

/* ── Comunidad 2 (2026-11-14): reconocimientos, edición, menciones que avisan, feed paginado y perfil completo. Doc: toolz/COMUNIDAD.md ── */
const CM_MAX_POST = 500;
const CM_KUDOS = ['crack' => ['🏆', 'Un crack'], 'salvavidas' => ['🛟', 'Me salvó'], 'creativo' => ['🎨', 'Creatividad'], 'rayo' => ['⚡', 'Rapidez'],
    'equipo' => ['🤝', 'Trabajo en equipo'], 'detalle' => ['🔍', 'Atención al detalle'], 'buenaonda' => ['🌞', 'Buena onda'], 'lider' => ['🧭', 'Liderazgo'],
    // Bienestar (2026-11-16): sus categorías son kudos de acá, así un reconocimiento es uno solo en Comunidad y en Bienestar
    'companerismo' => ['🫂', 'Compañerismo'], 'compromiso' => ['💪', 'Compromiso'], 'ayuda' => ['🙌', 'Ayuda'], 'positiva' => ['😊', 'Actitud positiva']];
const CM_NOTIF_TYPES = ['comment', 'reply', 'mention', 'mention_post', 'post_reply', 'kudos', 'birthday', 'coffee', 'poll_vote'];
function cm_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    try { $cols = array_column($db->query('SHOW COLUMNS FROM g7_posts')->fetchAll(), 'Field'); } catch (Throwable $e) { return; }
    foreach (['kind' => "VARCHAR(16) NOT NULL DEFAULT 'post'", 'target_user_id' => 'INT NULL', 'badge' => 'VARCHAR(24) NULL', 'edited_at' => 'DATETIME NULL'] as $c => $def)
        if (!in_array($c, $cols, true)) try { $db->exec("ALTER TABLE g7_posts ADD COLUMN $c $def"); } catch (Throwable $e) {}
    // El esquema original dejaba el texto en 140 caracteres y el código permite 500 (CM_MAX_POST, publicaciones automáticas):
    // en MySQL estricto las más largas fallaban. Se agranda una sola vez.
    try {
        $t = $db->query("SHOW COLUMNS FROM g7_posts LIKE 'body'")->fetch(PDO::FETCH_ASSOC);
        if ($t && preg_match('/^varchar\((\d+)\)/i', (string)$t['Type'], $m) && (int)$m[1] < 1000) $db->exec('ALTER TABLE g7_posts MODIFY body TEXT NOT NULL');
    } catch (Throwable $e) {}
}
/** Reacciones, mi reacción, respuestas y datos de la persona reconocida, en pocas consultas. */
function cm_hydrate(PDO $db, array $posts, int $me): array {
    if (!$posts) return [];
    $ids = array_map(fn($p) => (int)$p['id'], $posts); $in = implode(',', $ids);
    $rx = []; $mine = []; $reps = []; $tg = [];
    try { foreach ($db->query("SELECT post_id,emoji,COUNT(*) cnt FROM g7_post_reactions WHERE post_id IN ($in) GROUP BY post_id,emoji")->fetchAll() as $r) $rx[$r['post_id']][] = ['emoji' => $r['emoji'], 'cnt' => (int)$r['cnt']]; } catch (Throwable $e) {}
    try { $q = $db->prepare("SELECT post_id,emoji FROM g7_post_reactions WHERE post_id IN ($in) AND user_id=?"); $q->execute([$me]); foreach ($q->fetchAll() as $r) $mine[$r['post_id']] = $r['emoji']; } catch (Throwable $e) {}
    try { foreach ($db->query("SELECT r.*,u.username,u.display_name,u.avatar FROM g7_post_replies r JOIN g7_users u ON u.id=r.user_id WHERE r.post_id IN ($in) ORDER BY r.created_at ASC")->fetchAll() as $r) $reps[$r['post_id']][] = $r; } catch (Throwable $e) {}
    $tids = array_filter(array_map(fn($p) => (int)($p['target_user_id'] ?? 0), $posts));
    if ($tids) try { foreach ($db->query('SELECT id,username,display_name,avatar FROM g7_users WHERE id IN (' . implode(',', array_unique($tids)) . ')')->fetchAll() as $u) $tg[$u['id']] = $u; } catch (Throwable $e) {}
    foreach ($posts as &$p) { $id = (int)$p['id']; $p['reactions'] = $rx[$id] ?? []; $p['my_reaction'] = $mine[$id] ?? false; $p['replies'] = $reps[$id] ?? []; $p['target'] = $tg[(int)($p['target_user_id'] ?? 0)] ?? null; $p['kind'] = $p['kind'] ?? 'post';
        $m = json_decode((string)($p['meta'] ?? ''), true); $p['meta'] = is_array($m) ? $m : null;
        if ($p['kind'] === 'poll') $p['poll'] = cm_poll_state($db, ['id' => $id, 'meta' => json_encode($p['meta'])], $me); }
    return $posts;
}
/** Página del feed: filtro (all|kudos|mentions|img|mine|pinned), búsqueda y "antes de" (id) para cargar más. */
function cm_feed(PDO $db, array $me, string $filter = 'all', string $q = '', int $before = 0, int $limit = 20): array {
    $w = []; $a = [];
    if ($before > 0) { $w[] = 'p.id < ?'; $a[] = $before; }
    if ($filter === 'kudos') $w[] = "p.kind = 'kudos'";
    elseif ($filter === 'img') $w[] = "p.img <> ''";
    elseif ($filter === 'polls') $w[] = "p.kind IN ('poll','qotd')";
    elseif ($filter === 'auto') $w[] = "p.kind = 'auto'";
    elseif ($filter === 'mine') { $w[] = '(p.user_id = ? OR p.target_user_id = ?)'; $a[] = $me['id']; $a[] = $me['id']; }
    elseif ($filter === 'mentions') { $w[] = '(p.body LIKE ? OR p.target_user_id = ?)'; $a[] = '%@' . $me['username'] . '%'; $a[] = $me['id']; }
    if ($q !== '') { $w[] = '(p.body LIKE ? OR u.display_name LIKE ? OR u.username LIKE ?)'; $like = '%' . $q . '%'; array_push($a, $like, $like, $like); }
    $order = ($before > 0 || $filter !== 'all' || $q !== '') ? 'p.created_at DESC, p.id DESC' : 'p.pinned DESC, p.created_at DESC, p.id DESC';
    $st = $db->prepare('SELECT p.*,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $order LIMIT " . ($limit + 1));
    $st->execute($a); $rows = $st->fetchAll();
    $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
    return ['posts' => cm_hydrate($db, $rows, (int)$me['id']), 'more' => $more];
}
/** Avisa a cada @usuario mencionado (menos a quien escribe y a quien ya se avisó por otra vía). */
function cm_notify_mentions(PDO $db, string $body, int $from, string $type, int $postId, array $skip = []): void {
    if (!preg_match_all('/@([A-Za-z0-9_.]+)/', $body, $m)) return;
    $q = $db->prepare('SELECT id FROM g7_users WHERE username=? AND active=1');
    foreach (array_unique($m[1]) as $un) { $q->execute([$un]); $id = (int)$q->fetchColumn(); if ($id && $id !== $from && !in_array($id, $skip, true)) pushNotif($db, $id, $from, $type, $postId, 0, $body); }
}

/* ── Reconocimientos, reacciones y respuestas compartidos (2026-11-16): los usan comunidad.php y Bienestar (bienestar/lib.php),
   así las reglas son unas solas. Errores: RuntimeException con el mensaje para la persona. ── */
/** Reglas de un reconocimiento: categoría válida, no a uno mismo, persona activa, 3 a 280 caracteres y hasta 3 por día a la misma persona. Devuelve la persona. */
function cm_kudos_check(PDO $db, int $from, int $to, string $badge, string $why): array {
    $why = trim($why);
    if (!isset(CM_KUDOS[$badge])) throw new RuntimeException('Elegí un reconocimiento.');
    if ($to === $from) throw new RuntimeException('No podés reconocerte a vos mismo 😄');
    $u = $db->prepare('SELECT id,username,display_name,avatar FROM g7_users WHERE id=? AND active=1'); $u->execute([$to]); $tu = $u->fetch(PDO::FETCH_ASSOC);
    if (!$tu) throw new RuntimeException('Esa persona no está en el equipo.');
    if (mb_strlen($why) < 3 || mb_strlen($why) > 280) throw new RuntimeException('Contá en pocas palabras por qué (3 a 280 caracteres).');
    $dup = $db->prepare("SELECT COUNT(*) FROM g7_posts WHERE kind='kudos' AND user_id=? AND target_user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"); $dup->execute([$from, $to]);
    $n = (int)$dup->fetchColumn();
    // los privados de Bienestar cuentan para el mismo tope
    try { $p = $db->prepare('SELECT COUNT(*) FROM g7_bn_kudos_private WHERE from_id=? AND to_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'); $p->execute([$from, $to]); $n += (int)$p->fetchColumn(); } catch (Throwable $e) {}
    if ($n >= 3) throw new RuntimeException('Ya le diste varios reconocimientos hoy. ¡Guardá alguno para mañana!');
    return $tu;
}
/** Publica un reconocimiento (kudos) en el muro, avisa a la persona y a los mencionados y lo anota en la actividad. Devuelve el id. */
function cm_give_kudos(PDO $db, int $from, int $to, string $badge, string $why): int {
    $tu = cm_kudos_check($db, $from, $to, $badge, $why);
    $why = trim($why);
    $db->prepare("INSERT INTO g7_posts (user_id,body,kind,target_user_id,badge) VALUES (?,?,'kudos',?,?)")->execute([$from, $why, $to, $badge]);
    $id = (int)$db->lastInsertId();
    pushNotif($db, $to, $from, 'kudos', $id, 0, CM_KUDOS[$badge][0] . ' ' . CM_KUDOS[$badge][1] . ' — ' . $why);
    cm_notify_mentions($db, $why, $from, 'mention_post', $id, [$to]);
    try { $db->prepare('INSERT INTO g7_activity (user_id,type,target_name,meta) VALUES (?,?,?,?)')->execute([$from, 'kudos', mb_substr(($tu['display_name'] ?: $tu['username']) . ' · ' . CM_KUDOS[$badge][1], 0, 60), 'kudos']); } catch (Throwable $e) {}
    return $id;
}
/** Pone, cambia o saca (si es la misma) la reacción de una persona en una publicación. */
function cm_react(PDO $db, int $uid, int $pid, string $emoji): array {
    $emoji = mb_substr(trim($emoji) ?: 'like', 0, 10);
    $ok = $db->prepare('SELECT id FROM g7_posts WHERE id=?'); $ok->execute([$pid]);
    if (!$ok->fetchColumn()) throw new RuntimeException('Esa publicación ya no está.');
    $existing = $db->prepare('SELECT emoji FROM g7_post_reactions WHERE post_id=? AND user_id=?'); $existing->execute([$pid, $uid]); $cur = $existing->fetchColumn();
    if ($cur === $emoji) { $db->prepare('DELETE FROM g7_post_reactions WHERE post_id=? AND user_id=?')->execute([$pid, $uid]); $new = null; }
    else { $db->prepare('INSERT INTO g7_post_reactions (post_id,user_id,emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji=VALUES(emoji)')->execute([$pid, $uid, $emoji]); $new = $emoji; }
    $r = $db->prepare('SELECT emoji,COUNT(*) as cnt FROM g7_post_reactions WHERE post_id=? GROUP BY emoji'); $r->execute([$pid]);
    return ['reactions' => $r->fetchAll(PDO::FETCH_ASSOC), 'my_reaction' => $new];
}
/** Responde una publicación (hasta 280 caracteres) y avisa a quien publicó y a los mencionados. Devuelve la respuesta. */
function cm_add_reply(PDO $db, int $uid, int $pid, string $body): array {
    $body = trim($body);
    if (!$pid || $body === '' || mb_strlen($body) > 280) throw new RuntimeException('invalid');
    $ow = $db->prepare('SELECT user_id FROM g7_posts WHERE id=?'); $ow->execute([$pid]); $owner = (int)$ow->fetchColumn();
    if (!$owner) throw new RuntimeException('Esa publicación ya no está.');
    $db->prepare('INSERT INTO g7_post_replies (post_id,user_id,body) VALUES (?,?,?)')->execute([$pid, $uid, $body]); $newId = $db->lastInsertId();
    pushNotif($db, $owner, $uid, 'post_reply', $pid, 0, $body);
    cm_notify_mentions($db, $body, $uid, 'mention_post', $pid, [$owner]);
    $row = $db->prepare('SELECT r.*,u.username,u.display_name,u.avatar FROM g7_post_replies r JOIN g7_users u ON u.id=r.user_id WHERE r.id=?'); $row->execute([$newId]);
    return $row->fetch(PDO::FETCH_ASSOC);
}

/** Estado, rango, logros destacados y reconocimientos de una persona (para su perfil). */
function cm_profile_extra(PDO $db, int $uid): array {
    $x = ['online' => false, 'availability' => 'available', 'mood' => '', 'focus' => '', 'last_seen' => null, 'level' => null, 'xp' => 0, 'pct' => 0, 'next' => null, 'medals' => 0, 'ach' => [], 'kudos' => [], 'kudos_n' => 0, 'kudos_list' => []];
    try { $s = $db->prepare('SELECT availability,mood,focus_text,last_seen FROM g7_users WHERE id=?'); $s->execute([$uid]); if ($r = $s->fetch()) { $x['availability'] = $r['availability'] ?: 'available'; $x['mood'] = (string)$r['mood']; $x['focus'] = (string)$r['focus_text']; $x['last_seen'] = $r['last_seen']; $x['online'] = $r['last_seen'] && time() - strtotime($r['last_seen']) < 300; } } catch (Throwable $e) {}
    try { if (is_file(__DIR__ . '/gamification.php')) { require_once __DIR__ . '/gamification.php'; if (function_exists('g7GetUserGamif')) { $g = g7GetUserGamif($db, $uid); $x['level'] = $g['level_data']; $x['xp'] = (int)$g['xp']; $x['pct'] = (int)$g['progress_pct']; $x['next'] = $g['next_level']; } } } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/achievements_lib.php'; $cat = g7_ach_catalog($db);
        $q = $db->prepare('SELECT ach_key,tier,unlocked_at FROM g7_achievements WHERE user_id=? ORDER BY tier DESC, unlocked_at DESC'); $q->execute([$uid]);
        foreach ($q->fetchAll() as $a) {
            if (!isset($cat[$a['ach_key']])) continue;
            $x['medals'] += (int)$a['tier'];
            if (count($x['ach']) < 12) { $c = $cat[$a['ach_key']]; $t = (int)$a['tier'];
                $x['ach'][] = ['name' => $c[2], 'icon' => $c[1], 'tier' => $t, 'color' => G7_ACH_TIERS[$t]['color'] ?? '#f5c542', 'tname' => count($c[4]) > 1 ? (G7_ACH_TIERS[$t]['name'] ?? '') : 'Logrado']; }
        }
    } catch (Throwable $e) {}
    try {
        $q = $db->prepare("SELECT badge,COUNT(*) n FROM g7_posts WHERE kind='kudos' AND target_user_id=? GROUP BY badge ORDER BY n DESC"); $q->execute([$uid]);
        foreach ($q->fetchAll() as $r) if (isset(CM_KUDOS[$r['badge']])) { $x['kudos'][] = ['badge' => $r['badge'], 'emoji' => CM_KUDOS[$r['badge']][0], 'name' => CM_KUDOS[$r['badge']][1], 'n' => (int)$r['n']]; $x['kudos_n'] += (int)$r['n']; }
        $q = $db->prepare("SELECT p.id,p.body,p.badge,p.created_at,u.id uid,u.username,u.display_name,u.avatar FROM g7_posts p JOIN g7_users u ON u.id=p.user_id WHERE p.kind='kudos' AND p.target_user_id=? ORDER BY p.created_at DESC LIMIT 12"); $q->execute([$uid]);
        $x['kudos_list'] = $q->fetchAll();
    } catch (Throwable $e) {}
    return $x;
}

/**
 * Trabajo de una persona para su perfil: pedidos que hizo (abiertos y terminados), tickets que atiende, solicitudes y tareas.
 * Con los permisos de QUIEN MIRA: un ticket se lista si quien mira lo pidió, lo atiende, colabora, es de uno de sus equipos
 * o si es admin/director; el resto solo se cuenta (hidden). Sin acceso a Gestión de trabajo, no se muestra nada.
 */
function cm_profile_work(PDO $db, int $viewer, int $uid): ?array {
    if (!is_file(__DIR__ . '/trabajo/lib.php')) return null;
    if (function_exists('g7_can') && !g7_can($viewer, 'tareas')) return null;
    try { require_once __DIR__ . '/trabajo/lib.php'; $ctx = wk_ctx($db, $viewer); } catch (Throwable $e) { return null; }
    $collab = true; try { $db->query('SELECT 1 FROM g7_wk_ticket_collabs LIMIT 1'); } catch (Throwable $e) { $collab = false; }
    $vis = $ctx['all'] ? '1' : '(t.requester_id = ' . $viewer . ' OR t.assignee_id = ' . $viewer . ' OR t.team_id IN (' . wk_ints($ctx['teams']) . ')'
        . ($collab ? ' OR EXISTS (SELECT 1 FROM g7_wk_ticket_collabs c WHERE c.ticket_id = t.id AND c.user_id = ' . $viewer . ')' : '') . ')';
    $ST = ['abierto' => ['Abierto', '#60a5fa'], 'en_curso' => ['En curso', '#f59e0b'], 'esperando' => ['Esperando', '#a78bfa'], 'resuelto' => ['Resuelto', '#22c55e'], 'cerrado' => ['Cerrado', '#71717a']];
    $row = fn($r) => ['id' => (int)$r['id'], 'code' => 'T-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT), 'subject' => (string)$r['subject'], 'status' => $ST[$r['status']][0] ?? $r['status'], 'color' => $ST[$r['status']][1] ?? '#8b8b94',
        'priority' => (string)$r['priority'], 'when' => $r['resolved_at'] ?: ($r['closed_at'] ?: $r['updated_at']), 'created' => $r['created_at'], 'who' => (string)($r['who'] ?? ''), 'late' => (bool)($r['late'] ?? false), 'href' => '/toolz/trabajo/#tickets/' . (int)$r['id']];
    $late = "(t.status IN ('abierto','en_curso') AND t.paused_at IS NULL AND t.resolution_due < NOW())";
    $list = function (string $where, string $order, int $lim, string $whoCol) use ($db, $vis, $row, $late) {
        $base = "FROM g7_wk_tickets t LEFT JOIN g7_users u ON u.id = t.$whoCol WHERE $where";
        $total = (int)$db->query("SELECT COUNT(*) $base")->fetchColumn();
        $seen = (int)$db->query("SELECT COUNT(*) $base AND $vis")->fetchColumn();
        $rows = $db->query("SELECT t.*, COALESCE(NULLIF(u.display_name,''),u.username) who, $late late $base AND $vis ORDER BY $order LIMIT $lim")->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'hidden' => $total - $seen, 'items' => array_map($row, $rows)];
    };
    $out = ['made_open' => null, 'made_done' => null, 'serving' => null, 'requests' => null, 'tasks' => null, 'stats' => []];
    try {
        $open = "t.status IN ('abierto','en_curso','esperando')";
        $done = "t.status IN ('resuelto','cerrado')";
        $out['made_open'] = $list("t.requester_id = $uid AND t.origin <> 'tarea' AND $open", 't.updated_at DESC', 8, 'assignee_id');
        $out['made_done'] = $list("t.requester_id = $uid AND t.origin <> 'tarea' AND $done", 'COALESCE(t.resolved_at,t.closed_at,t.updated_at) DESC', 8, 'assignee_id');
        $out['serving'] = $list("t.assignee_id = $uid AND $open", "FIELD(t.status,'en_curso','abierto','esperando'), t.resolution_due IS NULL, t.resolution_due", 8, 'requester_id');
        $out['stats']['resolved'] = (int)$db->query("SELECT COUNT(*) FROM g7_wk_tickets t WHERE t.assignee_id = $uid AND $done")->fetchColumn();
        $out['stats']['resolved_30'] = (int)$db->query("SELECT COUNT(*) FROM g7_wk_tickets t WHERE t.assignee_id = $uid AND $done AND COALESCE(t.resolved_at,t.closed_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $out['stats']['made'] = (int)$db->query("SELECT COUNT(*) FROM g7_wk_tickets t WHERE t.requester_id = $uid AND t.origin <> 'tarea'")->fetchColumn();
        $ot = $db->query("SELECT SUM(t.resolved_at <= t.resolution_due) ok, COUNT(*) n FROM g7_wk_tickets t WHERE t.assignee_id = $uid AND t.resolved_at IS NOT NULL AND t.resolution_due IS NOT NULL")->fetch();
        $out['stats']['on_time'] = ($ot && (int)$ot['n'] > 0) ? (int)round(100 * (int)$ot['ok'] / (int)$ot['n']) : null;
    } catch (Throwable $e) {}
    // Solicitudes (formularios de Trabajo): las ve quien las hizo, quien las atiende o un admin/director/jefe del equipo
    try {
        $rv = $ctx['all'] ? '1' : '(r.requester_id = ' . $viewer . ' OR r.assignee_id = ' . $viewer . ' OR r.team_id IN (' . wk_ints($ctx['lead']) . '))';
        $RS = ['nueva' => ['Nueva', '#60a5fa'], 'pendiente' => ['Pendiente', '#60a5fa'], 'en_revision' => ['En revisión', '#f59e0b'], 'aprobada' => ['Aprobada', '#22c55e'], 'rechazada' => ['Rechazada', '#ef4444'], 'convertida' => ['En curso', '#a78bfa'], 'cerrada' => ['Cerrada', '#71717a']];
        $total = (int)$db->query("SELECT COUNT(*) FROM g7_wk_requests r WHERE r.requester_id = $uid")->fetchColumn();
        $seen = (int)$db->query("SELECT COUNT(*) FROM g7_wk_requests r WHERE r.requester_id = $uid AND $rv")->fetchColumn();
        $rows = $db->query("SELECT r.id, r.title, r.status, r.created_at, f.name form FROM g7_wk_requests r LEFT JOIN g7_wk_forms f ON f.id = r.form_id WHERE r.requester_id = $uid AND $rv ORDER BY r.created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
        $out['requests'] = ['total' => $total, 'hidden' => $total - $seen, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'form' => (string)($r['form'] ?? ''), 'status' => $RS[$r['status']][0] ?? ucfirst((string)$r['status']), 'color' => $RS[$r['status']][1] ?? '#8b8b94', 'created' => $r['created_at'], 'href' => '/toolz/trabajo/#solicitudes/' . (int)$r['id']], $rows)];
    } catch (Throwable $e) {}
    try { require_once __DIR__ . '/trabajo/user_work_lib.php'; $out['tasks'] = wk_user_work($db, $viewer, $uid, 8); } catch (Throwable $e) {}
    return $out;
}

/* ══════════ Comunidad 3 (2026-11-14): feed automático, encuestas, pregunta del día, café virtual, cumpleaños ══════════ */
const CM_QOTD = [
    '¿Cuál fue la mejor herramienta que descubriste este año?', '¿Qué canción te pone en modo productivo?', '¿Cuál es tu atajo de teclado favorito?',
    'Si pudieras sumar una persona famosa al equipo, ¿quién sería?', '¿Qué hábito te cambió la forma de trabajar?', '¿Cuál fue tu primer trabajo?',
    '¿Café, mate o té?', '¿Qué serie recomendarías para el fin de semana?', '¿Qué te gustaría aprender este mes?',
    '¿Cuál es el mejor consejo que te dieron?', '¿Qué app no puede faltar en tu celular?', '¿Playa o montaña?',
    '¿Qué proyecto del equipo te dio más orgullo?', '¿Cuál es tu comida de "día difícil"?', '¿Qué superpoder usarías en el trabajo?',
    '¿Qué libro, podcast o video te voló la cabeza?', 'Contá un dato curioso tuyo que nadie del equipo sepa.', '¿Cuál es tu lugar favorito para trabajar?',
    '¿Qué juego (de mesa o de compu) recomendás?', '¿Qué te hace reír sí o sí?', '¿Mañana o noche: cuándo rendís más?',
    '¿Qué emoji usás más?', '¿A qué ciudad te mudarías mañana?', '¿Qué cosa chiquita te alegra el día?',
    '¿Qué le dirías a tu "yo" del primer día en el equipo?', '¿Cuál es tu película para ver mil veces?', '¿Qué tarea te encanta y a nadie más?',
    '¿Perro, gato u otra mascota?', '¿Qué receta te sale perfecta?', '¿Qué deporte mirás o practicás?',
    '¿Cuál fue el mejor viaje que hiciste?', '¿Qué tecnología te gustaría que exista ya?', 'Recomendá un artista o banda que poca gente conozca.',
    '¿Cuál es tu ritual antes de arrancar a trabajar?', '¿Qué palabra o frase decís todo el tiempo?', '¿Qué aprendiste esta semana?',
    '¿Qué le agradecés hoy a alguien del equipo?', '¿Qué meme te representa hoy?', '¿Cuál es tu sueño a cinco años?', '¿Qué harías con un día libre sorpresa?',
];
const CM_COFFEE_Q = ['¿Qué estás escuchando últimamente?', '¿Cuál es tu plan ideal de fin de semana?', '¿Qué te gustaría aprender este año?', '¿Qué es lo mejor de tu trabajo?',
    '¿Qué hacías antes de entrar al equipo?', '¿Qué serie o peli recomendás?', '¿Cuál fue tu último viaje?', '¿Qué hobby tenés que pocos conocen?'];
const CM_AUTO = [   // tipos de publicación automática: ícono, color, texto
    'content' => ['fa-layer-group', '#ff6600'], 'ach' => ['fa-medal', '#f5c542'], 'rank' => ['fa-ranking-star', '#a78bfa'],
    'landing' => ['fa-rocket', '#22c55e'], 'member' => ['fa-hand-sparkles', '#60a5fa'], 'birthday' => ['fa-cake-candles', '#ec4899'], 'anniv' => ['fa-champagne-glasses', '#f59e0b'],
];

function cm_install3(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    cm_install($db);
    try { $cols = array_column($db->query('SHOW COLUMNS FROM g7_posts')->fetchAll(), 'Field'); if (!in_array('meta', $cols, true)) $db->exec('ALTER TABLE g7_posts ADD COLUMN meta TEXT NULL'); } catch (Throwable $e) {}
    try { $cols = array_column($db->query('SHOW COLUMNS FROM g7_users')->fetchAll(), 'Field');
        if (!in_array('birthday', $cols, true)) $db->exec('ALTER TABLE g7_users ADD COLUMN birthday DATE NULL');
        if (!in_array('joined_at', $cols, true)) $db->exec('ALTER TABLE g7_users ADD COLUMN joined_at DATE NULL'); } catch (Throwable $e) {}
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS g7_cm_state (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS g7_cm_votes (post_id INT NOT NULL, user_id INT NOT NULL, opt TINYINT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (post_id, user_id, opt), KEY (post_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS g7_cm_coffee (id INT AUTO_INCREMENT PRIMARY KEY, week CHAR(8) NOT NULL, a INT NOT NULL, b INT NOT NULL, question VARCHAR(255) NOT NULL DEFAULT \'\', done_a TINYINT NOT NULL DEFAULT 0, done_b TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (week), KEY (a), KEY (b))');
    } catch (Throwable $e) {}
}
function cm_state(PDO $db, string $k, ?string $set = null): ?string {
    if ($set !== null) { $db->prepare('INSERT INTO g7_cm_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $set]); return $set; }
    $q = $db->prepare('SELECT v FROM g7_cm_state WHERE k = ?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? null : (string)$v;
}
/** Autor de las publicaciones "del sistema" (pregunta del día): la primera persona admin. */
function cm_system_user(PDO $db): int { static $id = null; return $id ??= (int)$db->query("SELECT id FROM g7_users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetchColumn(); }
function cm_auto_post(PDO $db, int $uid, string $kind, string $badge, string $body, string $img = '', array $meta = [], ?int $target = null, ?string $at = null): int {
    $db->prepare('INSERT INTO g7_posts (user_id, body, img, kind, badge, target_user_id, meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$uid, mb_substr($body, 0, 500), $img, $kind, $badge, $target, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $at ?: date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}
/** Semana ISO, ej. "2026-W46". */
function cm_week(?int $t = null): string { return date('o-\WW', $t ?? time()); }

/**
 * Lo que la Comunidad arma sola (cada ~2 minutos, cuando alguien abre Comunidad o su feed):
 * contenidos nuevos, medallas de Oro/Diamante, rangos, landings publicadas, gente nueva, pregunta del día,
 * cumpleaños/aniversarios del día y las parejas del café de la semana. La primera vez solo marca dónde está (no inunda el feed).
 */
function cm_auto_sync(PDO $db, bool $force = false): void {
    cm_install3($db);
    $now = time();
    if (!$force && (int)cm_state($db, 'sync_at') > $now - 120) return;
    cm_state($db, 'sync_at', (string)$now);
    $first = cm_state($db, 'init') === null;
    $uidByName = function (string $un) use ($db) { static $c = []; if (!array_key_exists($un, $c)) { $q = $db->prepare('SELECT id FROM g7_users WHERE username = ?'); $q->execute([$un]); $c[$un] = (int)$q->fetchColumn(); } return $c[$un]; };
    $TYPE = ['promo' => 'una promo', 'launch' => 'un lanzamiento', 'paid' => 'piezas de pauta', 'social' => 'piezas de redes', 'mailings' => 'un mailing', 'otro' => 'un contenido'];
    // 1) contenidos nuevos
    try {
        $last = (int)(cm_state($db, 'last_content') ?? 0);
        if ($first || !$last) cm_state($db, 'last_content', (string)(int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_content')->fetchColumn());
        else {
            $q = $db->prepare("SELECT id, name, type, author, img, created_at FROM g7_content WHERE id > ? AND type <> 'tarea' ORDER BY id LIMIT 20"); $q->execute([$last]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $last = max($last, (int)$c['id']); $u = $uidByName((string)$c['author']); if (!$u) continue;
                $img = (string)$c['img']; if ($img && !preg_match('#^(/|https?://)#', $img)) $img = '/toolz/promos/' . $img;
                cm_auto_post($db, $u, 'auto', 'content', 'Subió ' . ($TYPE[$c['type']] ?? 'un contenido') . ': «' . $c['name'] . '»', $img, ['href' => '/toolz/promos/?search=' . rawurlencode((string)$c['name']), 'cta' => 'Ver en Promos']);
            }
            cm_state($db, 'last_content', (string)$last);
        }
    } catch (Throwable $e) {}
    // 2) medallas altas y rangos
    try {
        $lastAt = cm_state($db, 'last_ach_at');
        if ($first || !$lastAt) cm_state($db, 'last_ach_at', (string)($db->query('SELECT COALESCE(MAX(unlocked_at), NOW()) FROM g7_achievements')->fetchColumn()));
        else {
            require_once __DIR__ . '/achievements_lib.php'; $cat = g7_ach_catalog($db);
            $q = $db->prepare("SELECT user_id, ach_key, tier, unlocked_at FROM g7_achievements WHERE unlocked_at > ? AND (tier >= 3 OR ach_key LIKE 'rank\\_%') ORDER BY unlocked_at LIMIT 30"); $q->execute([$lastAt]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $lastAt = max($lastAt, (string)$a['unlocked_at']); $c = $cat[$a['ach_key']] ?? null; if (!$c) continue;
                if (str_starts_with((string)$a['ach_key'], 'rank_')) cm_auto_post($db, (int)$a['user_id'], 'auto', 'rank', 'Subió de rango: ' . $c[2], '', ['href' => '/toolz/logros.php', 'cta' => 'Ver logros']);
                else { $t = (int)$a['tier']; cm_auto_post($db, (int)$a['user_id'], 'auto', 'ach', 'Ganó la medalla «' . $c[2] . '» en ' . (G7_ACH_TIERS[$t]['name'] ?? 'Oro'), '', ['icon' => $c[1], 'color' => G7_ACH_TIERS[$t]['color'] ?? '#f5c542', 'href' => '/toolz/logros.php', 'cta' => 'Ver logros']); }
            }
            cm_state($db, 'last_ach_at', $lastAt);
        }
    } catch (Throwable $e) {}
    // 3) landings publicadas
    try {
        $lastL = cm_state($db, 'last_landing_at');
        if ($first || !$lastL) cm_state($db, 'last_landing_at', (string)($db->query('SELECT COALESCE(MAX(published_at), NOW()) FROM g7_landings')->fetchColumn()));
        else {
            $q = $db->prepare('SELECT id, name, created_by, public_url, published_at FROM g7_landings WHERE published_at > ? ORDER BY published_at LIMIT 10'); $q->execute([$lastL]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $l) { $lastL = max($lastL, (string)$l['published_at']); if ((int)$l['created_by']) cm_auto_post($db, (int)$l['created_by'], 'auto', 'landing', 'Publicó la landing «' . $l['name'] . '»', '', ['href' => (string)$l['public_url'] ?: '/toolz/landing-gen/', 'cta' => 'Ver landing']); }
            cm_state($db, 'last_landing_at', $lastL);
        }
    } catch (Throwable $e) {}
    // 4) gente nueva
    try {
        $lastU = (int)(cm_state($db, 'last_user') ?? 0);
        if ($first || !$lastU) cm_state($db, 'last_user', (string)(int)$db->query('SELECT COALESCE(MAX(id),0) FROM g7_users')->fetchColumn());
        else {
            $q = $db->prepare('SELECT id, display_name, username, position FROM g7_users WHERE id > ? AND active = 1 ORDER BY id LIMIT 10'); $q->execute([$lastU]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) { $lastU = max($lastU, (int)$u['id']); cm_auto_post($db, (int)$u['id'], 'auto', 'member', '¡Se sumó al equipo' . ($u['position'] ? ' como ' . $u['position'] : '') . '! Dale la bienvenida 👋', '', ['href' => '/toolz/perfil.php?u=' . (int)$u['id'], 'cta' => 'Ver su perfil']); }
            cm_state($db, 'last_user', (string)$lastU);
        }
    } catch (Throwable $e) {}
    // 5) pregunta del día
    $today = date('Y-m-d');
    try {
        if (cm_state($db, 'qotd_date') !== $today) {
            cm_state($db, 'qotd_date', $today);
            $n = count(CM_QOTD); $i = (int)floor($now / 86400) % $n;
            $id = cm_auto_post($db, cm_system_user($db), 'qotd', '', CM_QOTD[$i], '', ['date' => $today]);
            cm_state($db, 'qotd_post', (string)$id);
        }
    } catch (Throwable $e) {}
    // 6) cumpleaños y aniversarios (una vez por día)
    try {
        if (cm_state($db, 'bday_date') !== $today) {
            cm_state($db, 'bday_date', $today);
            $md = date('m-d');
            $q = $db->prepare("SELECT id, display_name, username FROM g7_users WHERE active = 1 AND birthday IS NOT NULL AND DATE_FORMAT(birthday, '%m-%d') = ?"); $q->execute([$md]);
            $all = $db->query('SELECT id FROM g7_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $nm = $u['display_name'] ?: $u['username'];
                $pid = cm_auto_post($db, (int)$u['id'], 'auto', 'birthday', '¡Hoy es el cumpleaños de ' . $nm . '! 🎂 Dejale tu saludo acá abajo.', '', ['href' => '/toolz/perfil.php?u=' . (int)$u['id'] . '#muro', 'cta' => 'Ir a su muro']);
                foreach ($all as $to) if ((int)$to !== (int)$u['id']) pushNotif($db, (int)$to, (int)$u['id'], 'birthday', $pid, 0, '🎂 Hoy cumple años ' . $nm);
            }
            $q = $db->prepare("SELECT id, display_name, username, COALESCE(joined_at, DATE(created_at)) j FROM g7_users WHERE active = 1 AND DATE_FORMAT(COALESCE(joined_at, DATE(created_at)), '%m-%d') = ? AND YEAR(COALESCE(joined_at, DATE(created_at))) < YEAR(CURDATE())"); $q->execute([$md]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) { $y = (int)date('Y') - (int)substr((string)$u['j'], 0, 4);
                cm_auto_post($db, (int)$u['id'], 'auto', 'anniv', 'Cumple ' . $y . ($y === 1 ? ' año' : ' años') . ' en el equipo 🥂 ¡Felicitalo!', '', ['href' => '/toolz/perfil.php?u=' . (int)$u['id'], 'cta' => 'Ver su perfil']); }
        }
    } catch (Throwable $e) {}
    // 7) café de la semana
    try { if (cm_state($db, 'coffee_week') !== cm_week()) cm_coffee_pair($db); } catch (Throwable $e) {}
    if ($first) cm_state($db, 'init', date('c'));
}

/** Arma las parejas del café de la semana: gente activa (entró en los últimos 30 días), al azar y, si se puede, de distinto puesto. */
function cm_coffee_pair(PDO $db): void {
    $wk = cm_week(); cm_state($db, 'coffee_week', $wk);
    if ((int)$db->query("SELECT COUNT(*) FROM g7_cm_coffee WHERE week = " . $db->quote($wk))->fetchColumn()) return;
    $ppl = $db->query("SELECT id, COALESCE(position,'') pos FROM g7_users WHERE active = 1 AND last_seen > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    if (count($ppl) < 2) return;
    mt_srand(crc32($wk)); shuffle($ppl);
    $pairs = [];
    while (count($ppl) >= 2) {
        $a = array_shift($ppl); $k = 0;
        foreach ($ppl as $i => $p) if ($p['pos'] !== $a['pos']) { $k = $i; break; }
        $b = $ppl[$k]; array_splice($ppl, $k, 1); $pairs[] = [$a['id'], $b['id']];
    }
    if ($ppl && $pairs) $pairs[] = [$ppl[0]['id'], $pairs[0][0]];   // impar: se suma a la primera pareja
    $ins = $db->prepare('INSERT INTO g7_cm_coffee (week, a, b, question) VALUES (?, ?, ?, ?)');
    foreach ($pairs as [$a, $b]) {
        $qq = CM_COFFEE_Q[mt_rand(0, count(CM_COFFEE_Q) - 1)];
        $ins->execute([$wk, (int)$a, (int)$b, $qq]); $cid = (int)$db->lastInsertId();
        pushNotif($db, (int)$a, (int)$b, 'coffee', $cid, (int)$b, '☕ Tu café de la semana. Para romper el hielo: ' . $qq);
        pushNotif($db, (int)$b, (int)$a, 'coffee', $cid, (int)$a, '☕ Tu café de la semana. Para romper el hielo: ' . $qq);
    }
}
/** Mi café de esta semana (o null). */
function cm_coffee_mine(PDO $db, int $uid): ?array {
    try {
        $q = $db->prepare('SELECT * FROM g7_cm_coffee WHERE week = ? AND (a = ? OR b = ?) ORDER BY id LIMIT 1'); $q->execute([cm_week(), $uid, $uid]); $c = $q->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $other = (int)$c['a'] === $uid ? (int)$c['b'] : (int)$c['a'];
        $u = $db->prepare('SELECT id, username, display_name, avatar, position FROM g7_users WHERE id = ?'); $u->execute([$other]); $o = $u->fetch(PDO::FETCH_ASSOC) ?: null;
        $mineDone = (int)$c['a'] === $uid ? (int)$c['done_a'] : (int)$c['done_b']; $theirs = (int)$c['a'] === $uid ? (int)$c['done_b'] : (int)$c['done_a'];
        return ['id' => (int)$c['id'], 'question' => $c['question'], 'with' => $o, 'done' => (bool)$mineDone, 'their_done' => (bool)$theirs];
    } catch (Throwable $e) { return null; }
}
/** "Hoy en el equipo": pregunta del día, cumpleaños de hoy y de los próximos 14 días, y mi café de la semana. Lo usan comunidad.php y el chat. */
function cm_today(PDO $db, int $uid): array {
    $t = ['qotd' => null, 'bdays' => [], 'soon' => [], 'coffee' => cm_coffee_mine($db, $uid)];
    try { $qp = (int)(cm_state($db, 'qotd_post') ?? 0); if ($qp) { $q = $db->prepare('SELECT p.id, p.body, (SELECT COUNT(*) FROM g7_post_replies r WHERE r.post_id = p.id) n, (SELECT COUNT(*) FROM g7_post_replies r WHERE r.post_id = p.id AND r.user_id = ?) mine FROM g7_posts p WHERE p.id = ?'); $q->execute([$uid, $qp]); $t['qotd'] = $q->fetch(PDO::FETCH_ASSOC) ?: null; } } catch (Throwable $e) {}
    try { foreach ($db->query("SELECT id, COALESCE(NULLIF(display_name,''),username) name, avatar, birthday, DATEDIFF(DATE(CONCAT(YEAR(CURDATE()) + (DATE_FORMAT(birthday,'%m-%d') < DATE_FORMAT(CURDATE(),'%m-%d')), '-', DATE_FORMAT(birthday,'%m-%d'))), CURDATE()) d FROM g7_users WHERE active = 1 AND birthday IS NOT NULL HAVING d <= 14 ORDER BY d")->fetchAll(PDO::FETCH_ASSOC) as $b) { $b['id'] = (int)$b['id']; $b['d'] = (int)$b['d']; $b['when'] = $b['d'] === 0 ? 'Hoy' : ($b['d'] === 1 ? 'Mañana' : 'En ' . $b['d'] . ' días'); unset($b['birthday']); if ($b['d'] === 0) $t['bdays'][] = $b; else $t['soon'][] = $b; } } catch (Throwable $e) {}
    return $t;
}
/** Encuesta: opciones (meta) con votos y el mío. */
function cm_poll_state(PDO $db, array $post, int $me): array {
    $meta = json_decode((string)($post['meta'] ?? ''), true) ?: [];
    $opts = array_values(array_slice((array)($meta['options'] ?? []), 0, 6)); $cnt = array_fill(0, count($opts), 0); $mine = [];
    try { $q = $db->prepare('SELECT opt, COUNT(*) n FROM g7_cm_votes WHERE post_id = ? GROUP BY opt'); $q->execute([(int)$post['id']]); foreach ($q->fetchAll() as $r) if (isset($cnt[(int)$r['opt']])) $cnt[(int)$r['opt']] = (int)$r['n'];
        $q = $db->prepare('SELECT opt FROM g7_cm_votes WHERE post_id = ? AND user_id = ?'); $q->execute([(int)$post['id'], $me]); $mine = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $q = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM g7_cm_votes WHERE post_id = ?'); $q->execute([(int)$post['id']]); $voters = (int)$q->fetchColumn(); } catch (Throwable $e) { $voters = 0; }
    return ['options' => $opts, 'counts' => $cnt, 'mine' => $mine, 'voters' => $voters, 'multi' => !empty($meta['multi'])];
}
