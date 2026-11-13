<?php
/**
 * chat/lib2.php — Etapas 2 a 6 del chat: menciones, hilos, actividad, preferencias, políticas,
 * vista previa de enlaces, envíos programados, recordatorios, retención y encuestas. Lo incluye lib.php.
 */

// ══ Menciones, actividad y auditoría ═══════════════════════════
const CH_ALL_WORDS = ['canal', 'todos', 'aqui', 'aquí', 'here', 'channel', 'everyone'];

/** Personas mencionadas con @usuario (o @todos/@canal: todos los miembros). Solo miembros de la conversación. */
function ch_parse_mentions(string $body, int $convId, int $author): array {
    if (!preg_match_all('/(?<![\w@.])@([\p{L}\p{N}_.\-]{2,50})/u', $body, $m)) return [];
    $q = ch_db()->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ?'); $q->execute([$convId]);
    $members = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $byName = [];
    foreach (ch_users() as $u) $byName[mb_strtolower($u['username'])] = $u['id'];
    $out = [];
    foreach ($m[1] as $w) {
        $w = mb_strtolower(rtrim($w, '.-'));
        if (in_array($w, CH_ALL_WORDS, true)) { $out = array_merge($out, $members); continue; }
        if (isset($byName[$w]) && in_array($byName[$w], $members, true)) $out[] = $byName[$w];
    }
    return array_values(array_diff(array_unique($out), [$author]));
}

/** Aviso en el centro de actividad de una persona (y un evento para que le llegue al instante). */
function ch_notify(int $uid, string $kind, ?int $conv, ?int $msg, ?int $actor, string $extra = ''): void {
    if ($uid <= 0 || $uid === $actor) return;
    ch_db()->prepare('INSERT INTO g7_ch_notifs (user_id, kind, conv_id, message_id, actor_id, extra) VALUES (?,?,?,?,?,?)')
           ->execute([$uid, $kind, $conv, $msg, $actor, mb_substr($extra, 0, 300)]);
    ch_event(0, 'notif', (int)ch_db()->lastInsertId(), $actor, $uid);
}

function ch_audit(int $uid, string $action, ?int $conv = null, string $target = '', string $detail = ''): void {
    try {
        ch_db()->prepare('INSERT INTO g7_ch_audit (user_id, action, conv_id, target, detail, ip) VALUES (?,?,?,?,?,?)')
               ->execute([$uid, $action, $conv, mb_substr($target, 0, 200), $detail, function_exists('g7_client_ip') ? g7_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')]);
    } catch (Throwable $e) {}
}

/** Después de guardar un mensaje: menciones, seguidores del hilo y anuncios. */
function ch_after_send(int $msgId): void {
    $db = ch_db();
    $q = $db->prepare('SELECT * FROM g7_ch_messages WHERE id = ?'); $q->execute([$msgId]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) return;
    $author = (int)$m['user_id']; $conv = (int)$m['conv_id'];
    $mentioned = ch_parse_mentions((string)$m['body'], $conv, $author);
    if ($mentioned) {
        $st = $db->prepare('INSERT IGNORE INTO g7_ch_mentions (message_id, user_id) VALUES (?,?)');
        foreach ($mentioned as $u) { $st->execute([$msgId, $u]); ch_notify($u, 'mention', $conv, $msgId, $author, mb_substr($m['body'], 0, 200)); }
    }
    if ($m['parent_id']) {
        $root = (int)$m['parent_id'];
        // Quien responde y quien escribió el mensaje original siguen el hilo
        $db->prepare('INSERT IGNORE INTO g7_ch_follow (message_id, user_id) SELECT id, user_id FROM g7_ch_messages WHERE id = ?')->execute([$root]);
        $db->prepare('INSERT INTO g7_ch_follow (message_id, user_id, last_read_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_id = VALUES(last_read_id)')->execute([$root, $author, $msgId]);
        foreach ($mentioned as $u) $db->prepare('INSERT IGNORE INTO g7_ch_follow (message_id, user_id) VALUES (?,?)')->execute([$root, $u]);
        $f = $db->prepare('SELECT user_id FROM g7_ch_follow WHERE message_id = ? AND following = 1 AND user_id <> ?'); $f->execute([$root, $author]);
        foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $u) if (!in_array((int)$u, $mentioned, true)) ch_notify((int)$u, 'reply', $conv, $msgId, $author, mb_substr($m['body'], 0, 200));
    }
    if ($m['kind'] === 'announce') {
        $meta = json_decode((string)$m['meta'], true) ?: [];
        $mem = $db->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ? AND user_id <> ?'); $mem->execute([$conv, $author]);
        foreach ($mem->fetchAll(PDO::FETCH_COLUMN) as $u) if (!in_array((int)$u, $mentioned, true)) ch_notify((int)$u, 'announce', $conv, $msgId, $author, $meta['ann']['title'] ?? '');
    }
}

// ══ Preferencias de cada persona ═══════════════════════════════
const CH_PREFS_DEFAULT = ['browser' => true, 'sound' => true, 'quiet_on' => false, 'quiet_from' => '20:00', 'quiet_to' => '08:00', 'dm' => true, 'groups' => true, 'channels' => false];
function ch_prefs(int $uid): array {
    $q = ch_db()->prepare('SELECT data FROM g7_ch_prefs WHERE user_id = ?'); $q->execute([$uid]);
    $d = json_decode((string)$q->fetchColumn(), true);
    return array_merge(CH_PREFS_DEFAULT, is_array($d) ? array_intersect_key($d, CH_PREFS_DEFAULT) : []);
}

// ══ Políticas (admin) ══════════════════════════════════════════
const CH_POLICY_DEFAULT = ['max_mb' => 50, 'quota_mb' => 0, 'retention_days' => 0, 'blocked_ext' => [], 'rate_per_10s' => 20];
function ch_policy(): array {
    $d = json_decode((string)g7_cfg('chat_policy', ''), true);
    $p = array_merge(CH_POLICY_DEFAULT, is_array($d) ? array_intersect_key($d, CH_POLICY_DEFAULT) : []);
    $p['blocked_ext'] = array_values(array_filter(array_map('strval', (array)$p['blocked_ext'])));
    foreach (['max_mb', 'quota_mb', 'retention_days', 'rate_per_10s'] as $k) $p[$k] = max(0, (int)$p[$k]);
    if ($p['max_mb'] < 1) $p['max_mb'] = 1;
    if ($p['rate_per_10s'] < 3) $p['rate_per_10s'] = 3;
    return $p;
}

// ══ Vista previa de enlaces (con freno a direcciones internas) ═
function ch_safe_url(string $url): bool {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    $ips = @gethostbynamel($p['host']) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}
function ch_unfurl(string $url): ?array {
    $buf = '';
    // Las redirecciones se siguen a mano para revisar cada destino
    for ($i = 0; $i < 4; $i++) {
        if (!ch_safe_url($url)) return null;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ToolzChat/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: es,en;q=0.8'],
            CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$buf) { $buf .= $d; return strlen($buf) > 600000 ? 0 : strlen($d); }]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200 || stripos($type, 'html') === false) return null;
        break;
    }
    if ($buf === '') return null;
    $get = function (array $names) use ($buf): string {
        foreach ($names as $n) {
            $q = preg_quote($n, '/');
            if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']*)["\']/i', $buf, $m)
             || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $q . '["\']/i', $buf, $m))
                if (trim($m[1]) !== '') return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    };
    $title = $get(['og:title', 'twitter:title']);
    if ($title === '' && preg_match('/<title[^>]*>([^<]{1,300})/i', $buf, $m)) $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($title === '') return null;
    $img = $get(['og:image', 'twitter:image', 'twitter:image:src']);
    if ($img !== '' && !preg_match('#^https?://#i', $img)) { $p = parse_url($url); $img = $p['scheme'] . '://' . $p['host'] . '/' . ltrim($img, '/'); }
    if (!preg_match('#^https://#i', $img)) $img = '';   // solo imágenes por https (la intranet va por https)
    $host = preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
    return ['url' => $url, 'title' => mb_substr($title, 0, 160), 'desc' => mb_substr($get(['og:description', 'description', 'twitter:description']), 0, 300),
            'image' => mb_substr($img, 0, 500), 'site' => mb_substr($get(['og:site_name']) ?: $host, 0, 60)];
}

// ══ Envíos programados, recordatorios y retención ══════════════
// El hosting no tiene un proceso corriendo: lo vencido se despacha en la sincronización de cualquier persona
// conectada (con un candado, así lo hace una sola). Opcional: chat/cron.php desde el cron de cPanel.
function ch_dispatch(): void {
    $db = ch_db();
    // Corre en cada sincronización del chat: primero una consulta barata; el candado y la lista de usuarios, solo si hay algo para mandar
    if (!$db->query("SELECT 1 FROM g7_ch_scheduled WHERE status = 'pending' AND send_at <= NOW() LIMIT 1")->fetchColumn()) return;
    if (!(int)$db->query("SELECT GET_LOCK('ch_dispatch', 0)")->fetchColumn()) return;
    try {
        $users = ch_users();
        $due = $db->query("SELECT * FROM g7_ch_scheduled WHERE status = 'pending' AND send_at <= NOW() ORDER BY send_at LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($due as $s) {
            $db->prepare("UPDATE g7_ch_scheduled SET status = 'sending' WHERE id = ? AND status = 'pending'")->execute([$s['id']]);
            $c = ch_conv((int)$s['conv_id']); $u = $users[(int)$s['user_id']] ?? null;
            if (!$c || !$u || !$u['active'] || !ch_can_post($c, ['id' => (int)$s['user_id'], 'role' => $u['role']])) {
                $db->prepare("UPDATE g7_ch_scheduled SET status = 'failed', error = 'Ya no podías publicar en esa conversación' WHERE id = ?")->execute([$s['id']]);
                ch_notify((int)$s['user_id'], 'sched_fail', (int)$s['conv_id'], null, 0, mb_substr($s['body'], 0, 120));
                continue;
            }
            $meta = json_decode((string)$s['meta'], true) ?: null;
            $id = ch_insert_message((int)$s['conv_id'], (int)$s['user_id'], $s['body'], ['kind' => $s['kind'] ?: 'text', 'meta' => $meta]);
            if ($s['files'] !== '') $db->prepare('UPDATE g7_ch_files SET message_id = ? WHERE id IN (' . implode(',', array_map('intval', explode(',', $s['files']))) . ') AND user_id = ? AND message_id IS NULL')->execute([$id, $s['user_id']]);
            $db->prepare("UPDATE g7_ch_scheduled SET status = 'sent', message_id = ? WHERE id = ?")->execute([$id, $s['id']]);
            ch_after_send($id);
        }
        $rem = $db->query('SELECT * FROM g7_ch_reminders WHERE done_at IS NULL AND remind_at <= NOW() ORDER BY remind_at LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rem as $r) {
            $db->prepare('UPDATE g7_ch_reminders SET done_at = NOW() WHERE id = ? AND done_at IS NULL')->execute([$r['id']]);
            $c = $r['conv_id'] ? ch_conv((int)$r['conv_id']) : null;
            if ($r['shared'] && $c && !$c['archived']) {
                $id = ch_insert_message((int)$r['conv_id'], (int)$r['user_id'], $r['text'], ['kind' => 'reminder', 'reply_to_id' => $r['message_id'] ? (int)$r['message_id'] : null]);
                ch_after_send($id);
            } else ch_notify((int)$r['user_id'], 'reminder', $r['conv_id'] ? (int)$r['conv_id'] : null, $r['message_id'] ? (int)$r['message_id'] : null, 0, $r['text']);
        }
        // Retención de mensajes: una vez por hora como mucho
        $pol = ch_policy();
        if ($pol['retention_days'] > 0 && (int)g7_cfg('chat_retention_last', 0) < time() - 3600) {
            g7_cfg_set('chat_retention_last', (string)time());
            $old = $db->prepare("SELECT id FROM g7_ch_messages WHERE created_at < NOW() - INTERVAL ? DAY AND (deleted_at IS NULL OR body <> '') LIMIT 2000");
            $old->execute([$pol['retention_days']]);
            $ids = array_map('intval', $old->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $in = implode(',', $ids);
                foreach ($db->query("SELECT stored FROM g7_ch_files WHERE message_id IN ($in)") as $f) if (!str_starts_with($f['stored'], 'legacy:')) @unlink(CH_FILES . '/' . $f['stored']);
                $db->exec("DELETE FROM g7_ch_files WHERE message_id IN ($in)");
                $db->exec("DELETE FROM g7_ch_reactions WHERE message_id IN ($in)");
                $db->exec("UPDATE g7_ch_messages SET body = '', meta = NULL, deleted_at = COALESCE(deleted_at, NOW()), pinned_at = NULL WHERE id IN ($in)");
                ch_audit(0, 'retention', null, count($ids) . ' mensajes', 'Política: ' . $pol['retention_days'] . ' días');
            }
        }
    } finally { $db->query("SELECT RELEASE_LOCK('ch_dispatch')"); }
}

// ══ Encuestas ══════════════════════════════════════════════════
function ch_poll_state(int $msgId, array $meta, int $uid): array {
    $q = ch_db()->prepare('SELECT opt, user_id FROM g7_ch_poll_votes WHERE message_id = ? ORDER BY created_at'); $q->execute([$msgId]);
    $votes = array_fill(0, count($meta['options'] ?? []), []);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $v) if (isset($votes[(int)$v['opt']])) $votes[(int)$v['opt']][] = (int)$v['user_id'];
    $all = [];
    foreach ($votes as $us) $all = array_merge($all, $us);
    return ['question' => $meta['question'] ?? '', 'options' => $meta['options'] ?? [], 'multi' => !empty($meta['multi']), 'anonymous' => !empty($meta['anonymous']),
            'closed' => !empty($meta['closed']), 'voters' => count(array_unique($all)),
            'counts' => array_map('count', $votes), 'mine' => array_keys(array_filter($votes, fn($us) => in_array($uid, $us, true))),
            'who' => !empty($meta['anonymous']) ? [] : $votes];
}

/** Tarea del módulo Tareas a partir de un mensaje. Devuelve [id, título]. */
function ch_make_task(int $uid, array $msg, array $in): array {
    // Gestión de trabajo: la tarea va al tablero elegido (que tiene que poder ver) y dispara sus automatizaciones
    require_once __DIR__ . '/../trabajo/lib.php';
    $db = ch_db();
    wk_install($db);
    $ctx = wk_ctx($db, $uid);
    if ($ctx['role'] === 'solicitante') throw new RuntimeException('No puedes crear tareas');
    $board = (int)($in['board_id'] ?? 0) ?: wk_general_id($db);
    if (!wk_board_visible($db, $ctx, $board)) throw new RuntimeException('Ese tablero no existe o no tienes acceso');
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 250);
    if ($title === '') throw new RuntimeException('La tarea necesita un título');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['due_date'] ?? '')) ? $in['due_date'] : null;
    $link = g7_site_origin() . '/toolz/chat/?m=' . (int)$msg['id'];
    $desc = trim((string)($in['description'] ?? ''));
    $desc = mb_substr(($desc !== '' ? $desc . "\n\n" : '') . 'Mensaje de origen: ' . $link, 0, 20000);
    $users = ch_users();
    $assignees = array_values(array_filter(array_unique(array_map('intval', (array)($in['assignees'] ?? []))), fn($u) => isset($users[$u]) && $users[$u]['active']));
    $id = wk_create_task($db, $uid, $board, $title, $desc, $assignees, $due, (string)($in['priority'] ?? ''));
    tk_log($db, $id, $uid, 'created', 'desde el chat');
    // Estado elegido (si existe en ese tablero y no lo cambió una automatización)
    $sts = wk_statuses($db, wk_board($db, $board));
    $st = (string)($in['status'] ?? '');
    if ($st !== '' && $st !== $sts[0]['id'] && in_array($st, array_column($sts, 'id'), true)) {
        $db->prepare('UPDATE g7_tasks SET status = ? WHERE id = ? AND status = ?')->execute([$st, $id, $sts[0]['id']]);
        wk_sync_completed($db, $sts, 'id = ' . $id);
    }
    return [$id, $title, $board];
}

/**
 * Quién pide y quién atiende un ticket que nace de un mensaje (acciones rápidas, CHAT_ACCIONES.md):
 *   - Mensaje de otra persona: lo pidió ella (solicitante) y lo atiendo yo (responsable).
 *   - Mensaje mío: lo pido yo; lo atiende la otra persona del mensaje directo, o la que elija en un grupo ($assignee).
 * El departamento es el equipo principal de quien atiende. Devuelve ['requester','assignee','team_id','team','category_id'].
 */
function ch_ticket_roles(int $uid, array $msg, array $conv, int $assignee = 0): array {
    require_once __DIR__ . '/../trabajo/lib.php';
    $users = ch_users();
    $author = (int)$msg['user_id'];
    if ($author !== $uid && isset($users[$author])) { $req = $author; $asg = $uid; }
    else {
        $req = $uid;
        if ($conv['kind'] === 'dm') {
            $q = ch_db()->prepare('SELECT user_id FROM g7_ch_members WHERE conv_id = ? AND user_id <> ? LIMIT 1'); $q->execute([(int)$conv['id'], $uid]);
            $asg = (int)$q->fetchColumn();   // 0 en "mis notas" (DM conmigo)
        }
        else $asg = $assignee && $assignee !== $uid && isset($users[$assignee]) && $users[$assignee]['active'] && ch_member((int)$conv['id'], $assignee) ? $assignee : 0;
    }
    $team = $asg ? ($users[$asg]['teams'][0] ?? null) : null;
    return ['requester' => $req, 'assignee' => $asg ?: null, 'team_id' => $team ? (int)$team['id'] : null, 'team' => $team ? $team['name'] : '',
            'category_id' => ch_team_category($team ? (int)$team['id'] : null)];
}

/** Categoría de tickets del equipo (la primera activa); sin una propia, la primera activa de todas. */
function ch_team_category(?int $teamId): int {
    $db = ch_db();
    $skip = (int)wk_cfg_get($db, 'work_task_cat', '0');   // "Tareas": la de los tickets que nacen como tarea, no se elige sola
    if ($teamId) { $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE active = 1 AND team_id = ? AND id <> ? ORDER BY position, name LIMIT 1'); $q->execute([$teamId, $skip]); if ($c = $q->fetchColumn()) return (int)$c; }
    $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE active = 1 AND id <> ? ORDER BY team_id IS NOT NULL, position, name LIMIT 1'); $q->execute([$skip]);
    return (int)$q->fetchColumn();
}

/**
 * Ticket desde un mensaje del chat: el mensaje queda como descripción con el enlace al original.
 * Solicitante, responsable y departamento según ch_ticket_roles(); la categoría la puede cambiar quien lo abre.
 * Devuelve [id, asunto, roles].
 */
function ch_make_ticket(int $uid, array $msg, array $in, ?array $conv = null): array {
    require_once __DIR__ . '/../trabajo/lib.php';
    $db = ch_db();
    wk_install($db);
    $roles = $conv ? ch_ticket_roles($uid, $msg, $conv, (int)($in['assignee'] ?? 0)) : ['requester' => $uid, 'assignee' => null, 'team_id' => null, 'team' => '', 'category_id' => 0];
    $cat = (int)($in['category_id'] ?? 0) ?: $roles['category_id'];
    if (!$cat) throw new RuntimeException('No hay categorías de tickets activas: créalas en Trabajo › Tickets › Configuración');
    $link = g7_site_origin() . '/toolz/chat/?m=' . (int)$msg['id'];
    $desc = trim((string)($in['description'] ?? ''));
    if ($desc === '') $desc = trim((string)$msg['body']) ?: 'Pedido desde el chat';
    $desc .= "\n\nMensaje de origen: " . $link;
    $id = wk_create_ticket($db, $roles['requester'], $cat, (string)($in['priority'] ?? ''), (string)($in['subject'] ?? ''), $desc, ['origin' => 'chat']);
    // Responsable y departamento: el de quien lo atiende (aunque la categoría sea de otro equipo).
    // Plazo: el de los tickets del chat (tareas rápidas, Tickets › Configuración), no el de la categoría.
    $f = [];
    if ($roles['assignee']) $f['assignee_id'] = $roles['assignee'];
    if ($roles['team_id']) $f['team_id'] = $roles['team_id'];
    $t0 = wk_tk_row($db, $id); if (wk_tk_chat_min($db) && ($d = wk_tk_dues($db, $t0, (string)$t0['priority']))) $f += $d;
    if ($f) {
        wk_tk_set($db, $id, $f);
        wk_task_from_tk($db, $id, $uid);   // la tarea del ticket queda con el mismo responsable y la fecha del plazo
        if ($roles['assignee'] && $roles['assignee'] !== $uid) wk_tk_notify($db, 'assigned', [$roles['assignee']], wk_tk_row($db, $id), $uid, 'Pedido desde el chat');
    }
    return [$id, mb_substr(trim((string)($in['subject'] ?? '')), 0, 200), $roles];
}

/**
 * Nota interna en un ticket desde el chat (/nota o el menú de un mensaje). Solo quien lo atiende: su departamento, el responsable
 * o la gente de su tarea, igual que en Trabajo. Queda en el ticket y en los comentarios de su tarea; avisa a la gente del ticket
 * menos a quien lo pidió (es interna). Devuelve el ticket.
 */
function ch_ticket_note(int $uid, int $ticketId, string $body): array {
    require_once __DIR__ . '/../trabajo/lib.php';
    $db = ch_db();
    wk_install($db);
    $body = mb_substr(trim($body), 0, 20000);
    if ($body === '') throw new RuntimeException('Escribe la nota: /nota T-0012 ya hablé con el cliente');
    $t = wk_tk_row($db, $ticketId);
    if (!$t) throw new RuntimeException('Ese ticket no existe');
    if (!ch_ticket_agent($db, $uid, $t)) throw new RuntimeException('Solo quien atiende el ticket puede agregarle notas internas');
    wk_tk_post($db, $t, $uid, $body, true, true, null, wk_user_name($db, $uid));
    wk_tk_mirror($db, $ticketId);
    $t = wk_tk_row($db, $ticketId);
    $to = array_values(array_diff(wk_tk_people($db, $t, false), [$uid, (int)$t['requester_id']]));
    wk_tk_notify($db, 'tk_reply', $to, $t, $uid, 'Nota interna: ' . mb_substr($body, 0, 200));
    return $t;
}

/** ¿Atiende el ticket? (departamento, responsable o gente de su tarea; mismo criterio que trabajo/api.php wk_ticket) */
function ch_ticket_agent(PDO $db, int $uid, array $t): bool {
    $ctx = wk_ctx($db, $uid);
    if (wk_is_agent($ctx, $t['team_id'] ? (int)$t['team_id'] : null) || ($t['assignee_id'] && (int)$t['assignee_id'] === $uid)) return true;
    if (!$t['task_id']) return false;
    $q = $db->prepare('SELECT 1 FROM g7_task_assignees WHERE task_id = ? AND user_id = ?'); $q->execute([(int)$t['task_id'], $uid]);
    return (bool)$q->fetchColumn();
}

/**
 * Tickets a los que se les puede agregar una nota desde una conversación: primero los enlazados en sus mensajes,
 * después los abiertos que atiende esta persona. Solo los que atiende. [['id','code','subject','status']]
 */
function ch_note_targets(int $uid, int $convId): array {
    require_once __DIR__ . '/../trabajo/lib.php';
    $db = ch_db();
    wk_install($db);
    $ids = [];
    $q = $db->prepare("SELECT meta FROM g7_ch_messages WHERE conv_id = ? AND deleted_at IS NULL AND meta LIKE '%\"tickets\"%' ORDER BY id DESC LIMIT 200"); $q->execute([$convId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mj) foreach ((json_decode((string)$mj, true)['tickets'] ?? []) as $tk) $ids[(int)$tk['id']] = 1;
    $q = $db->prepare("SELECT id FROM g7_wk_tickets WHERE assignee_id = ? AND status IN ('abierto','en_curso') ORDER BY updated_at DESC LIMIT 15"); $q->execute([$uid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[(int)$i] = 1;
    $out = [];
    foreach (array_keys($ids) as $i) {
        $t = wk_tk_row($db, $i);
        if (!$t || !ch_ticket_agent($db, $uid, $t)) continue;
        $out[] = ['id' => (int)$t['id'], 'code' => wk_tk_code((int)$t['id']), 'subject' => $t['subject'], 'status' => $t['status']];
        if (count($out) >= 20) break;
    }
    return $out;
}

/** Asunto de un ticket o título de una tarea a partir del texto de un mensaje: la primera línea, sin marcas de formato. */
function ch_subject_from(string $body, int $max = 200): string {
    $l = trim(explode("\n", trim($body))[0] ?? '');
    $l = trim(preg_replace(['/\[\[[a-z]+:\d+\]\]/', '/[*_~`>]+/'], ['', ''], $l));
    return mb_substr($l, 0, $max);
}
