<?php
/**
 * chat/lib.php — Chat corporativo: base de datos, permisos y armado de mensajes.
 * Plan y decisiones: CHAT_PLAN.md (raíz de toolz_clone).
 *
 * Conversaciones (g7_ch_convs): dm (dos personas), group (grupo cerrado) y channel (canal en una categoría).
 * Todo cambio deja un evento en g7_ch_events; el navegador sincroniza desde el último evento que vio.
 */
require_once __DIR__ . '/../auth.php';

const CH_SCHEMA = 2;
const CH_FILES  = __DIR__ . '/../_private/chat';
const CH_TMP    = CH_FILES . '/_subidas';
const CH_EDIT_WINDOW = 0;          // 0 = se puede editar siempre
const CH_PAGE = 50;                // mensajes por tanda
require_once __DIR__ . '/../presence_lib.php';
define('CH_STATUSES', array_map(fn($s) => $s[0], G7_PRESENCE));   // los mismos estados que en Mi perfil

function ch_db(): PDO { static $db; return $db ??= getDB(); }

function ch_install(): void {
    $db = ch_db();
    $v = (int)g7_cfg('chat2_schema', 0);
    if ($v >= CH_SCHEMA) return;
    if ($v < 1) ch_install_v1($db);
    if ($v < 2) ch_install_v2($db);
    g7_cfg_set('chat2_schema', CH_SCHEMA);
}
function ch_install_v1(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_categories (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, sort INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_convs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind ENUM('dm','group','channel') NOT NULL,
        name VARCHAR(80) NOT NULL DEFAULT '', topic VARCHAR(300) NOT NULL DEFAULT '', avatar VARCHAR(300) NOT NULL DEFAULT '',
        category_id INT NULL, sort INT NOT NULL DEFAULT 0,
        visibility ENUM('public','private','restricted') NOT NULL DEFAULT 'public',
        announce TINYINT(1) NOT NULL DEFAULT 0, allowed_teams VARCHAR(500) NOT NULL DEFAULT '',
        dm_key VARCHAR(40) NULL, legacy VARCHAR(40) NULL,
        created_by INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_msg_id INT NOT NULL DEFAULT 0, last_msg_at DATETIME NULL, archived TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_dm (dm_key), UNIQUE KEY uq_legacy (legacy), KEY k_kind (kind), KEY k_cat (category_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_members (
        conv_id INT NOT NULL, user_id INT NOT NULL,
        role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_read_id INT NOT NULL DEFAULT 0,
        muted TINYINT(1) NOT NULL DEFAULT 0, favorite TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (conv_id, user_id), KEY k_user (user_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_messages (
        id INT AUTO_INCREMENT PRIMARY KEY, conv_id INT NOT NULL, user_id INT NOT NULL,
        body MEDIUMTEXT NOT NULL, kind VARCHAR(16) NOT NULL DEFAULT 'text',
        parent_id INT NULL, reply_to_id INT NULL, thread_count INT NOT NULL DEFAULT 0, thread_last_at DATETIME NULL,
        edited_at DATETIME NULL, deleted_at DATETIME NULL, pinned_at DATETIME NULL, pinned_by INT NULL,
        meta TEXT NULL, client_id VARCHAR(40) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_conv (conv_id, id), KEY k_parent (parent_id), KEY k_user (user_id), KEY k_client (user_id, client_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_files (
        id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NULL, conv_id INT NOT NULL, user_id INT NOT NULL,
        name VARCHAR(200) NOT NULL, stored VARCHAR(300) NOT NULL, size BIGINT NOT NULL DEFAULT 0, mime VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_conv (conv_id), KEY k_msg (message_id), KEY k_user (user_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_reactions (
        message_id INT NOT NULL, user_id INT NOT NULL, emoji VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (message_id, user_id, emoji)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, conv_id INT NOT NULL DEFAULT 0, kind VARCHAR(16) NOT NULL,
        ref_id INT NULL, user_id INT NULL, target_user INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_conv (conv_id, id), KEY k_target (target_user, id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_typing (
        conv_id INT NOT NULL, user_id INT NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (conv_id, user_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ch_migrate_legacy();
}
/** Etapas 2 a 6: hilos, menciones, guardados, actividad, encuestas, recordatorios, programados, auditoría. */
function ch_install_v2(PDO $db): void {
    $T = ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    try { $db->exec("ALTER TABLE g7_ch_members ADD COLUMN IF NOT EXISTS notify ENUM('all','mentions','none') NOT NULL DEFAULT 'all'"); } catch (Throwable $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_follow (message_id INT NOT NULL, user_id INT NOT NULL, following TINYINT(1) NOT NULL DEFAULT 1,
        last_read_id INT NOT NULL DEFAULT 0, PRIMARY KEY (message_id, user_id), KEY k_user (user_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_mentions (message_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (message_id, user_id), KEY k_user (user_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_saved (user_id INT NOT NULL, message_id INT NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '',
        category VARCHAR(60) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, message_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_notifs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, kind VARCHAR(16) NOT NULL,
        conv_id INT NULL, message_id INT NULL, actor_id INT NULL, extra VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL, KEY k_user (user_id, id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_prefs (user_id INT PRIMARY KEY, data TEXT NOT NULL)$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_poll_votes (message_id INT NOT NULL, opt TINYINT NOT NULL, user_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (message_id, opt, user_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_reminders (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, conv_id INT NULL, message_id INT NULL,
        text VARCHAR(500) NOT NULL DEFAULT '', remind_at DATETIME NOT NULL, shared TINYINT(1) NOT NULL DEFAULT 0,
        done_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_due (done_at, remind_at), KEY k_user (user_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_scheduled (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, conv_id INT NOT NULL,
        body MEDIUMTEXT NOT NULL, files VARCHAR(200) NOT NULL DEFAULT '', meta TEXT NULL, kind VARCHAR(16) NOT NULL DEFAULT 'text',
        send_at DATETIME NOT NULL, status ENUM('pending','sending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
        message_id INT NULL, error VARCHAR(200) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_due (status, send_at), KEY k_user (user_id))$T");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_ch_audit (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, action VARCHAR(40) NOT NULL,
        conv_id INT NULL, target VARCHAR(200) NOT NULL DEFAULT '', detail TEXT NULL, ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_time (created_at), KEY k_user (user_id))$T");
    // Quien escribió un mensaje con respuestas sigue su hilo
    $db->exec('INSERT IGNORE INTO g7_ch_follow (message_id, user_id) SELECT id, user_id FROM g7_ch_messages WHERE thread_count > 0');
}

// ── Personas ────────────────────────────────────────────────────
function ch_users(): array {
    static $u;
    if ($u !== null) return $u;
    $u = [];
    $teams = [];
    try {
        foreach (ch_db()->query("SELECT m.user_id, t.id, t.name, m.is_primary FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id AND t.active = 1 ORDER BY m.is_primary DESC, t.name") as $r)
            $teams[(int)$r['user_id']][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    } catch (Throwable $e) {}
    g7_tz_install(ch_db());   // país y zona de cada persona (tz_lib.php): el chat muestra su hora local
    foreach (ch_db()->query("SELECT id, username, display_name, avatar, position, email, phone, role, availability, focus_text, last_seen, active, country, timezone, city, city_public FROM g7_users") as $r) {
        $id = (int)$r['id'];
        $u[$id] = [
            'id' => $id, 'name' => trim((string)$r['display_name']) ?: $r['username'], 'username' => $r['username'],
            'avatar' => (string)$r['avatar'], 'position' => (string)$r['position'], 'email' => (string)$r['email'], 'phone' => (string)$r['phone'],
            'role' => $r['role'], 'active' => (int)$r['active'] === 1,
            'status' => isset(CH_STATUSES[$r['availability'] ?? '']) ? $r['availability'] : 'available',
            'status_text' => (string)($r['focus_text'] ?? ''), 'last_seen' => $r['last_seen'],
            'teams' => $teams[$id] ?? [], 'dept' => ($teams[$id][0]['name'] ?? ''),
            'loc' => g7_tz_public($r),   // {cc, tz, zone, city}: la ciudad solo si la persona eligió mostrarla
        ];
    }
    return $u;
}
/** En línea = algún pedido en los últimos 90 s */
function ch_online(?string $lastSeen): bool { return $lastSeen && strtotime($lastSeen) > time() - 90; }

function ch_is_admin(array $me): bool { return ($me['role'] ?? '') === 'admin'; }

// ── Permisos ────────────────────────────────────────────────────
function ch_conv(int $id): ?array {
    $q = ch_db()->prepare('SELECT * FROM g7_ch_convs WHERE id = ?'); $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function ch_member(int $convId, int $uid): ?array {
    $q = ch_db()->prepare('SELECT * FROM g7_ch_members WHERE conv_id = ? AND user_id = ?'); $q->execute([$convId, $uid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function ch_user_team_ids(int $uid): array { return array_column(ch_users()[$uid]['teams'] ?? [], 'id'); }

/** Puede entrar sin ser miembro: canal público, o restringido a uno de sus equipos. */
function ch_can_join(array $c, array $me): bool {
    if ($c['kind'] !== 'channel' || $c['archived']) return false;
    if ($c['visibility'] === 'public') return true;
    if ($c['visibility'] === 'restricted') {
        $allowed = array_filter(array_map('intval', explode(',', $c['allowed_teams'])));
        return ch_is_admin($me) || (bool)array_intersect($allowed, ch_user_team_ids((int)$me['id']));
    }
    return false;
}
/** Leer: miembros; los canales públicos/restringidos se pueden mirar antes de unirse. */
function ch_can_read(array $c, array $me): bool {
    return (bool)ch_member((int)$c['id'], (int)$me['id']) || ch_can_join($c, $me);
}
/** Administrar la conversación: dueño/admin de ella, o admin de la intranet en canales. */
function ch_can_manage(array $c, array $me): bool {
    if ($c['kind'] === 'dm') return false;
    if ($c['kind'] === 'channel' && ch_is_admin($me)) return true;
    $m = ch_member((int)$c['id'], (int)$me['id']);
    return $m && in_array($m['role'], ['owner', 'admin'], true);
}
function ch_can_post(array $c, array $me): bool {
    if ($c['archived'] || !ch_member((int)$c['id'], (int)$me['id'])) return false;
    return !$c['announce'] || ch_can_manage($c, $me);
}

// ── Eventos ─────────────────────────────────────────────────────
function ch_event(int $convId, string $kind, ?int $ref = null, ?int $uid = null, ?int $target = null): void {
    // Dentro de una transacción el evento espera al commit: si no, otro evento posterior se confirma antes, los navegadores
    // avanzan más allá de este y el mensaje no les llega nunca (o recién al recargar)
    static $queue = [], $hook = false;
    if ($kind !== '') $queue[] = [$convId, $kind, $ref, $uid, $target];
    if (ch_db()->inTransaction()) {
        if (!$hook) { $hook = true; register_shutdown_function(function () { try { if (!ch_db()->inTransaction()) ch_event(0, ''); } catch (Throwable $e) {} }); }
        return;
    }
    $todo = $queue; $queue = [];
    $st = ch_db()->prepare('INSERT INTO g7_ch_events (conv_id, kind, ref_id, user_id, target_user) VALUES (?,?,?,?,?)');
    foreach ($todo as $e) $st->execute($e);
}
function ch_last_event(): int { return (int)ch_db()->query('SELECT COALESCE(MAX(id),0) FROM g7_ch_events')->fetchColumn(); }

// ── Mensajes ────────────────────────────────────────────────────
function ch_clean_body(string $s): string {
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, 8000);
}
/** Arma mensajes para el navegador (con archivos, reacciones y la cita a la que responde). */
function ch_render(array $rows, int $uid): array {
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $files = $reacts = $quotes = [];
    foreach (ch_db()->query("SELECT id, message_id, name, size, mime FROM g7_ch_files WHERE message_id IN ($ids) ORDER BY id") as $f)
        $files[(int)$f['message_id']][] = ['id' => (int)$f['id'], 'name' => $f['name'], 'size' => (int)$f['size'], 'mime' => $f['mime'], 'url' => 'api.php?action=file&id=' . (int)$f['id']];
    foreach (ch_db()->query("SELECT message_id, emoji, user_id FROM g7_ch_reactions WHERE message_id IN ($ids) ORDER BY created_at") as $r)
        $reacts[(int)$r['message_id']][$r['emoji']][] = (int)$r['user_id'];
    $saved = $thr = $mentioned = [];
    try {
        foreach (ch_db()->query("SELECT message_id, note, category FROM g7_ch_saved WHERE user_id = $uid AND message_id IN ($ids)") as $sv) $saved[(int)$sv['message_id']] = ['note' => $sv['note'], 'category' => $sv['category']];
        foreach (ch_db()->query("SELECT message_id FROM g7_ch_mentions WHERE user_id = $uid AND message_id IN ($ids)") as $mm) $mentioned[(int)$mm['message_id']] = true;
        $roots = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => (int)$r['thread_count'] > 0));
        if ($roots) foreach (ch_db()->query('SELECT parent_id, user_id FROM g7_ch_messages WHERE deleted_at IS NULL AND parent_id IN (' . implode(',', $roots) . ') GROUP BY parent_id, user_id ORDER BY MAX(id) DESC') as $t) $thr[(int)$t['parent_id']][] = (int)$t['user_id'];
    } catch (Throwable $e) {}
    $rq = array_filter(array_map(fn($r) => (int)$r['reply_to_id'], $rows));
    if ($rq) foreach (ch_db()->query('SELECT id, user_id, body, deleted_at, kind FROM g7_ch_messages WHERE id IN (' . implode(',', $rq) . ')') as $q)
        $quotes[(int)$q['id']] = ['id' => (int)$q['id'], 'user_id' => (int)$q['user_id'], 'body' => $q['deleted_at'] ? '' : mb_substr($q['body'], 0, 200), 'deleted' => (bool)$q['deleted_at']];
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; $del = (bool)$r['deleted_at'];
        $re = [];
        foreach ($reacts[$id] ?? [] as $e => $us) $re[] = ['emoji' => $e, 'users' => $us, 'mine' => in_array($uid, $us, true)];
        $meta = json_decode((string)$r['meta'], true) ?: [];
        $out[] = [
            'id' => $id, 'conv_id' => (int)$r['conv_id'], 'user_id' => (int)$r['user_id'], 'kind' => $r['kind'],
            'body' => $del ? '' : $r['body'], 'deleted' => $del, 'edited' => (bool)$r['edited_at'],
            'created_at' => $r['created_at'], 'parent_id' => $r['parent_id'] ? (int)$r['parent_id'] : null,
            'thread_count' => (int)$r['thread_count'], 'thread_last_at' => $r['thread_last_at'],
            'pinned' => (bool)$r['pinned_at'], 'client_id' => $r['client_id'],
            'reply_to' => $r['reply_to_id'] ? ($quotes[(int)$r['reply_to_id']] ?? ['id' => (int)$r['reply_to_id'], 'deleted' => true, 'body' => '', 'user_id' => 0]) : null,
            'files' => $del ? [] : ($files[$id] ?? []), 'reactions' => $del ? [] : $re,
            'ref' => $del ? null : ($meta['ref'] ?? null),
            'preview' => $del ? null : ($meta['preview'] ?? null), 'fwd' => $del ? null : ($meta['fwd'] ?? null),
            'ann' => $del ? null : ($meta['ann'] ?? null), 'tasks' => $del ? [] : ($meta['tasks'] ?? []), 'tickets' => $del ? [] : ($meta['tickets'] ?? []),
            'poll' => (!$del && $r['kind'] === 'poll') ? ch_poll_state($id, $meta['poll'] ?? [], $uid) : null,
            'saved' => $saved[$id] ?? null, 'mentions_me' => isset($mentioned[$id]),
            'thread_users' => array_slice($thr[$id] ?? [], 0, 4),
        ];
    }
    return $out;
}
function ch_messages_by_ids(array $ids, int $uid): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $rows = ch_db()->query('SELECT * FROM g7_ch_messages WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return ch_render($rows, $uid);
}

/** Inserta un mensaje y actualiza la conversación (y el hilo si es respuesta). Devuelve el id. */
function ch_insert_message(int $convId, int $uid, string $body, array $opt = []): int {
    $db = ch_db();
    $db->prepare('INSERT INTO g7_ch_messages (conv_id, user_id, body, kind, parent_id, reply_to_id, meta, client_id, created_at) VALUES (?,?,?,?,?,?,?,?,COALESCE(?, NOW()))')
       ->execute([$convId, $uid, $body, $opt['kind'] ?? 'text', $opt['parent_id'] ?? null, $opt['reply_to_id'] ?? null,
                  isset($opt['meta']) ? json_encode($opt['meta'], JSON_UNESCAPED_UNICODE) : null, $opt['client_id'] ?? null, $opt['created_at'] ?? null]);
    $id = (int)$db->lastInsertId();
    if (!empty($opt['parent_id'])) {
        $db->prepare('UPDATE g7_ch_messages SET thread_count = thread_count + 1, thread_last_at = NOW() WHERE id = ?')->execute([$opt['parent_id']]);
        if (empty($opt['silent'])) ch_event($convId, 'upd', (int)$opt['parent_id'], $uid);
    } else {
        $db->prepare('UPDATE g7_ch_convs SET last_msg_id = ?, last_msg_at = COALESCE(?, NOW()) WHERE id = ?')->execute([$id, $opt['created_at'] ?? null, $convId]);
    }
    // Quien escribe ya leyó hasta acá
    $db->prepare('UPDATE g7_ch_members SET last_read_id = GREATEST(last_read_id, ?) WHERE conv_id = ? AND user_id = ?')->execute([$id, $convId, $uid]);
    if (empty($opt['silent'])) ch_event($convId, 'msg', $id, $uid);
    return $id;
}

// ── Resumen de conversaciones (lista del panel del medio) ───────
function ch_conv_summaries(int $uid, ?array $only = null): array {
    $db = ch_db();
    $where = $only !== null ? ' AND c.id IN (' . (implode(',', array_map('intval', $only)) ?: '0') . ')' : '';
    $rows = $db->query("SELECT c.*, m.role AS my_role, m.last_read_id, m.muted, m.favorite, m.notify
        FROM g7_ch_members m JOIN g7_ch_convs c ON c.id = m.conv_id WHERE m.user_id = " . (int)$uid . $where)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $unread = $others = $last = [];
    foreach ($db->query("SELECT m.conv_id, COUNT(x.id) n FROM g7_ch_members m JOIN g7_ch_messages x ON x.conv_id = m.conv_id AND x.id > m.last_read_id
                         AND x.user_id <> m.user_id AND x.parent_id IS NULL AND x.deleted_at IS NULL
                         WHERE m.user_id = " . (int)$uid . " AND m.conv_id IN ($ids) GROUP BY m.conv_id") as $r) $unread[(int)$r['conv_id']] = (int)$r['n'];
    foreach ($db->query("SELECT conv_id, user_id, last_read_id FROM g7_ch_members WHERE conv_id IN ($ids)") as $r) $others[(int)$r['conv_id']][] = (int)$r['user_id'];
    $lastIds = array_filter(array_map(fn($r) => (int)$r['last_msg_id'], $rows));
    if ($lastIds) foreach ($db->query('SELECT id, user_id, body, kind, deleted_at FROM g7_ch_messages WHERE id IN (' . implode(',', $lastIds) . ')') as $r)
        $last[(int)$r['id']] = ['user_id' => (int)$r['user_id'], 'body' => $r['deleted_at'] ? 'Mensaje eliminado' : mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 90), 'kind' => $r['kind']];
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $members = $others[$id] ?? [];
        $out[] = [
            'id' => $id, 'kind' => $r['kind'], 'name' => $r['name'], 'topic' => $r['topic'], 'avatar' => $r['avatar'],
            'category_id' => $r['category_id'] ? (int)$r['category_id'] : null, 'sort' => (int)$r['sort'],
            'visibility' => $r['visibility'], 'announce' => (bool)$r['announce'], 'archived' => (bool)$r['archived'],
            'allowed_teams' => array_values(array_filter(array_map('intval', explode(',', $r['allowed_teams'])))),
            'members' => $members, 'peer' => $r['kind'] === 'dm' ? (array_values(array_diff($members, [$uid]))[0] ?? $uid) : null,
            'my_role' => $r['my_role'], 'muted' => (bool)$r['muted'], 'favorite' => (bool)$r['favorite'], 'notify' => $r['notify'] ?? 'all',
            'last_read_id' => (int)$r['last_read_id'], 'unread' => $unread[$id] ?? 0,
            'last_msg_id' => (int)$r['last_msg_id'], 'last_msg_at' => $r['last_msg_at'], 'last' => $last[(int)$r['last_msg_id']] ?? null,
            'created_by' => (int)$r['created_by'],
        ];
    }
    return $out;
}

// ── Migración del chat viejo (g7_chat_*) ────────────────────────
// Se hace una sola vez. Las tablas viejas quedan como estaban.
function ch_migrate_legacy(): void {
    $db = ch_db();
    if (g7_cfg('chat2_migrated', '') === '1') return;
    // Todo o nada: si algo falla a la mitad, no quedan conversaciones duplicadas ni a medias
    $db->beginTransaction();
    try { ch_migrate_run($db); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); error_log('chat2 migración: ' . $e->getMessage()); throw $e; }
    g7_cfg_set('chat2_migrated', '1');
}
function ch_migrate_run(PDO $db): void {
    $users = ch_users();
    $active = array_keys(array_filter($users, fn($u) => $u['active']));
    // Categoría y canal general (todos los activos adentro)
    $db->exec("INSERT INTO g7_ch_categories (name, sort) VALUES ('Empresa', 0)");
    $catEmp = (int)$db->lastInsertId();
    $mk = function (string $kind, string $name, array $o = []) use ($db): int {
        $db->prepare('INSERT INTO g7_ch_convs (kind, name, topic, avatar, category_id, visibility, created_by, dm_key, legacy, created_at) VALUES (?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()))')
           ->execute([$kind, $name, $o['topic'] ?? '', $o['avatar'] ?? '', $o['cat'] ?? null, $o['vis'] ?? 'public', $o['by'] ?? 0, $o['dm'] ?? null, $o['legacy'] ?? null, $o['at'] ?? null]);
        return (int)$db->lastInsertId();
    };
    $addM = function (int $conv, array $uids, ?int $owner = null) use ($db) {
        $st = $db->prepare('INSERT IGNORE INTO g7_ch_members (conv_id, user_id, role) VALUES (?,?,?)');
        foreach (array_unique($uids) as $u) $st->execute([$conv, $u, $u === $owner ? 'owner' : 'member']);
    };
    $general = $mk('channel', 'general', ['topic' => 'Todo el equipo', 'cat' => $catEmp, 'legacy' => 'global']);
    $addM($general, $active);
    $anuncios = $mk('channel', 'anuncios', ['topic' => 'Comunicaciones de la empresa: solo publican los administradores', 'cat' => $catEmp]);
    $db->prepare('UPDATE g7_ch_convs SET announce = 1, sort = 1 WHERE id = ?')->execute([$anuncios]);
    $addM($anuncios, $active);

    $hasOld = (bool)$db->query("SHOW TABLES LIKE 'g7_chat_messages'")->fetch();
    if (!$hasOld) return;

    // Grupos
    $groupConv = [];
    try {
        foreach ($db->query('SELECT * FROM g7_chat_groups ORDER BY id') as $g) {
            $c = $mk('group', mb_substr($g['name'], 0, 80), ['topic' => mb_substr((string)$g['description'], 0, 300), 'avatar' => (string)$g['avatar_url'], 'by' => (int)$g['owner_id'], 'legacy' => 'g' . $g['id'], 'vis' => 'private', 'at' => $g['created_at']]);
            $mem = $db->prepare('SELECT user_id FROM g7_chat_group_members WHERE group_id = ?'); $mem->execute([$g['id']]);
            $addM($c, array_merge([(int)$g['owner_id']], array_map('intval', $mem->fetchAll(PDO::FETCH_COLUMN))), (int)$g['owner_id']);
            $groupConv[(int)$g['id']] = $c;
        }
    } catch (Throwable $e) {}
    // Equipos que usaron el chat: canal privado por equipo
    $teamConv = [];
    $catEq = null;
    // Mensajes
    $map = [];
    $dmConv = [];
    $ins = $db->prepare('INSERT INTO g7_ch_messages (conv_id, user_id, body, kind, reply_to_id, meta, created_at) VALUES (?,?,?,?,?,?,?)');
    $insF = $db->prepare('INSERT INTO g7_ch_files (message_id, conv_id, user_id, name, stored, size, mime, created_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($db->query('SELECT * FROM g7_chat_messages ORDER BY id') as $m) {
        $from = (int)$m['from_id']; $conv = null;
        if (!empty($m['group_id'])) $conv = $groupConv[(int)$m['group_id']] ?? null;
        elseif ($m['scope'] === 'team' && !empty($m['team_id'])) {
            $tid = (int)$m['team_id'];
            if (!isset($teamConv[$tid])) {
                $t = $db->prepare('SELECT name FROM g7_teams WHERE id = ?'); $t->execute([$tid]); $tn = $t->fetchColumn();
                if ($tn === false) continue;
                if ($catEq === null) { $db->exec("INSERT INTO g7_ch_categories (name, sort) VALUES ('Equipos', 1)"); $catEq = (int)$db->lastInsertId(); }
                $teamConv[$tid] = $mk('channel', mb_substr(mb_strtolower(preg_replace('/\s+/', '-', $tn)), 0, 80), ['cat' => $catEq, 'vis' => 'restricted', 'legacy' => 't' . $tid]);
                $db->prepare('UPDATE g7_ch_convs SET allowed_teams = ? WHERE id = ?')->execute([(string)$tid, $teamConv[$tid]]);
                $tm = $db->prepare('SELECT user_id FROM g7_team_members WHERE team_id = ?'); $tm->execute([$tid]);
                $addM($teamConv[$tid], array_map('intval', $tm->fetchAll(PDO::FETCH_COLUMN)));
            }
            $conv = $teamConv[$tid];
        } elseif (!empty($m['to_id'])) {
            $a = min($from, (int)$m['to_id']); $b = max($from, (int)$m['to_id']);
            $key = "$a:$b";
            if (!isset($dmConv[$key])) { $dmConv[$key] = $mk('dm', '', ['dm' => $key, 'by' => $from, 'vis' => 'private', 'at' => $m['created_at']]); $addM($dmConv[$key], [$a, $b]); }
            $conv = $dmConv[$key];
        } else $conv = $general;
        if (!$conv) continue;
        $meta = null;
        if (!empty($m['ref_type'])) {
            $rd = json_decode((string)$m['ref_data'], true) ?: [];
            $url = (string)($rd['url'] ?? '');
            if (!preg_match('#^(https?://|/toolz/)#i', $url)) $url = '';
            $meta = ['ref' => ['type' => mb_substr($m['ref_type'], 0, 20), 'title' => mb_substr((string)($rd['title'] ?? $rd['name'] ?? ''), 0, 160), 'url' => $url]];
        }
        $type = (string)$m['type'];
        $body = (string)$m['body'];
        if ($type !== 'text' && $m['file_url'] && trim($body) === trim((string)$m['file_name'])) $body = '';
        $ins->execute([$conv, $from, $body, 'text', isset($map[(int)$m['reply_to_id']]) ? $map[(int)$m['reply_to_id']] : null, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $m['created_at']]);
        $nid = (int)$db->lastInsertId();
        $map[(int)$m['id']] = $nid;
        if ($type !== 'text' && $m['file_url']) {
            $insF->execute([$nid, $conv, $from, mb_substr((string)($m['file_name'] ?: basename($m['file_url'])), 0, 200), 'legacy:' . $m['file_url'], (int)$m['file_size'], (string)$m['file_mime'], $m['created_at']]);
        }
    }
    try {
        $st = $db->prepare('INSERT IGNORE INTO g7_ch_reactions (message_id, user_id, emoji) VALUES (?,?,?)');
        foreach ($db->query('SELECT message_id, user_id, emoji FROM g7_chat_reactions') as $r)
            if (isset($map[(int)$r['message_id']])) $st->execute([$map[(int)$r['message_id']], (int)$r['user_id'], mb_substr($r['emoji'], 0, 16)]);
    } catch (Throwable $e) {}
    // Último mensaje de cada conversación; todo lo migrado queda leído
    $db->exec('UPDATE g7_ch_convs c JOIN (SELECT conv_id, MAX(id) mid, MAX(created_at) mat FROM g7_ch_messages GROUP BY conv_id) x ON x.conv_id = c.id SET c.last_msg_id = x.mid, c.last_msg_at = x.mat');
    $db->exec('UPDATE g7_ch_members m JOIN g7_ch_convs c ON c.id = m.conv_id SET m.last_read_id = c.last_msg_id');
}

// ── Archivos ────────────────────────────────────────────────────
function ch_max_bytes(): int { return ch_policy()['max_mb'] * 1024 * 1024; }
function ch_allowed_ext(string $name): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, array_merge(G7_EXT_IMG, G7_EXT_MEDIA, G7_EXT_DOCS), true) && !in_array($ext, ch_policy()['blocked_ext'], true);
}

require_once __DIR__ . '/lib2.php';
