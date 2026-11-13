<?php
/**
 * juegos/reportes_lib.php — Reportes desde el juego (2026-11-11). Doc: toolz/JUEGOS_REPORTES.md
 *
 * Un juego (por ejemplo NEIBOR) tiene el panel "Enviar reporte": el jugador manda un bug, una sugerencia o una consulta y ve
 * sus reportes con las respuestas públicas del equipo. Cada reporte es un ticket de Trabajo › Tickets (origen "juego") con su
 * tarea, como cualquier otro: el equipo responde desde Trabajo y las respuestas públicas vuelven al juego. Las notas internas y
 * los eventos nunca salen.
 *
 * - Claves del juego (g7_jr_keys): una o varias, cada una con nombre y, si está Juegos, el juego al que pertenece. Se guarda solo
 *   el hash (sha256) y un prefijo para encontrarla; la clave entera se muestra una sola vez, al crearla o rotarla.
 * - Jugadores (g7_jr_players): anónimos, por clave. El primer pedido con un id guarda sha256(token); después el token tiene que coincidir.
 * - Reportes (g7_jr_reports): el vínculo ticket ↔ jugador, el tipo, los datos del juego (versión, plataforma…) y hasta qué
 *   mensaje vio el jugador (para "unread").
 * - Límites (g7_jr_hits): ventanas por jugador y por IP (la IP se guarda como hash y se borra al día).
 * La API pública es juegos/reportes.php; la configuración, Trabajo › Tickets › Configuración (trabajo/api.php, acciones jr_*).
 */
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/trabajo/lib.php';

const JR_KINDS = ['bug' => ['Bug', 'Juego › Bugs', '#ef4444'], 'sugerencia' => ['Sugerencia', 'Juego › Sugerencias', '#22c55e'], 'consulta' => ['Consulta', 'Juego › Consultas', '#3b82f6']];
const JR_STATUS_LABEL = ['abierto' => 'Abierto', 'en_curso' => 'En curso', 'esperando' => 'Esperando tu respuesta', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
const JR_CTX_KEYS = ['version', 'platform', 'os', 'gpu', 'map', 'game_day', 'lang'];
const JR_CTX_LABEL = ['version' => 'Versión', 'platform' => 'Plataforma', 'os' => 'Sistema', 'gpu' => 'Placa de video', 'map' => 'Mapa', 'game_day' => 'Día de juego', 'lang' => 'Idioma'];
const JR_MAX_SUBJECT = 200;
const JR_MAX_BODY = 5000;
const JR_MAX_NAME = 60;
const JR_MAX_CTX = 200;
const JR_MAX_TICKETS = 50;
/** Límites: [cuántos, segundos]. "p" = por jugador, "ip" = por IP. */
const JR_LIMITS = [
    'create' => ['p' => [5, 600], 'ip' => [30, 3600]],
    'reply'  => ['p' => [20, 600], 'ip' => [120, 3600]],
    'read'   => ['p' => [60, 60], 'ip' => [600, 60]],      // list y seen
    'new_player' => ['ip' => [20, 3600]],                   // jugadores nuevos desde una misma IP
    'bad_key' => ['ip' => [30, 600]],                       // claves equivocadas desde una misma IP
];

function jr_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    wk_install($db);
    if (wk_cfg_get($db, 'jr_schema') === '1') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jr_keys (
        id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, game_id INT NULL, prefix CHAR(12) NOT NULL, key_hash CHAR(64) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, created_at DATETIME NOT NULL, rotated_at DATETIME NULL, last_used_at DATETIME NULL,
        UNIQUE KEY prefix (prefix)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jr_players (
        id INT AUTO_INCREMENT PRIMARY KEY, key_id INT NOT NULL, uuid CHAR(36) NOT NULL, token_hash CHAR(64) NOT NULL, name VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL,
        UNIQUE KEY key_uuid (key_id, uuid)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jr_reports (
        ticket_id INT PRIMARY KEY, key_id INT NOT NULL, player_id INT NOT NULL, kind VARCHAR(12) NOT NULL, context TEXT NULL,
        seen_msg_id INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        KEY player (player_id, created_at), KEY key_id (key_id)
    ) DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS g7_jr_hits (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, bucket VARCHAR(90) NOT NULL, at INT NOT NULL, KEY bucket (bucket, at)
    ) DEFAULT CHARSET=utf8mb4");
    wk_cfg_put($db, 'jr_schema', '1');
}

function jr_enabled(PDO $db): bool { return wk_cfg_get($db, 'jr_on', '0') === '1'; }

// ── Texto ──────────────────────────────────────────────────
/** Una línea: sin caracteres de control (los saltos pasan a espacio), espacios recortados. Devuelve null si pasa del largo. */
function jr_line($v, int $max): ?string {
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', (string)$v) ?? '') ?? '');
    return mb_strlen($s) > $max ? null : $s;
}
/** Texto largo: conserva saltos de línea y tabulaciones; quita el resto de los caracteres de control. Null si pasa del largo. */
function jr_text($v, int $max): ?string {
    if (!is_string($v)) return '';
    $s = str_replace(["\r\n", "\r"], "\n", $v);
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $s) ?? '';
    $s = trim(preg_replace("/\n{4,}/", "\n\n\n", $s));
    return mb_strlen($s) > $max ? null : $s;
}
/** Fecha guardada (hora de PHP del sitio, como tk_now) → UTC. */
function jr_utc(?string $local): ?string {
    if (!$local) return null;
    $ts = strtotime($local);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
}

// ── Claves ─────────────────────────────────────────────────
/** Clave nueva: "jrk_" + 48 hexadecimales. El prefijo (12 caracteres) sirve para encontrarla; el resto es secreto. */
function jr_key_make(): string { return 'jrk_' . bin2hex(random_bytes(24)); }
function jr_key_hash(string $key): string { return hash('sha256', 'jr-key|' . $key); }
/** Fila de la clave si es válida y está activa. Comparación en tiempo constante. */
function jr_key_find(PDO $db, string $key): ?array {
    if (!preg_match('/^jrk_[0-9a-f]{48}$/', $key)) { hash_equals(str_repeat('0', 64), jr_key_hash($key)); return null; }
    $q = $db->prepare('SELECT * FROM g7_jr_keys WHERE prefix = ?'); $q->execute([substr($key, 0, 12)]); $k = $q->fetch(PDO::FETCH_ASSOC);
    $ok = hash_equals($k ? (string)$k['key_hash'] : str_repeat('0', 64), jr_key_hash($key));
    return $k && $ok && (int)$k['active'] ? $k : null;
}
/** Crea una clave. Devuelve [id, clave entera] (la clave no se guarda: se muestra una sola vez). */
function jr_key_create(PDO $db, string $name, ?int $gameId, ?int $uid): array {
    for ($i = 0; $i < 5; $i++) {
        $key = jr_key_make();
        try {
            $db->prepare('INSERT INTO g7_jr_keys (name, game_id, prefix, key_hash, active, created_by, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
               ->execute([$name, $gameId, substr($key, 0, 12), jr_key_hash($key), $uid, tk_now()]);
            return [(int)$db->lastInsertId(), $key];
        } catch (PDOException $e) { if ($i === 4) throw $e; }   // prefijo repetido (muy improbable): otra
    }
    throw new RuntimeException('No se pudo crear la clave');
}
/** Rota una clave: la de antes deja de valer; los jugadores y sus reportes siguen (son de la misma fila). */
function jr_key_rotate(PDO $db, int $id): string {
    for ($i = 0; $i < 5; $i++) {
        $key = jr_key_make();
        try {
            $db->prepare('UPDATE g7_jr_keys SET prefix = ?, key_hash = ?, rotated_at = ? WHERE id = ?')->execute([substr($key, 0, 12), jr_key_hash($key), tk_now(), $id]);
            return $key;
        } catch (PDOException $e) { if ($i === 4) throw $e; }
    }
    throw new RuntimeException('No se pudo rotar la clave');
}
/** Nombre del juego de Juegos (si la clave está unida a uno y la tabla existe). */
function jr_game_name(PDO $db, ?int $gameId): string {
    if (!$gameId) return '';
    try { $q = $db->prepare('SELECT name FROM g7_jg_games WHERE id = ?'); $q->execute([$gameId]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; }
}
/** Juegos del módulo Juegos para elegir (vacío si Juegos no está activo). */
function jr_games(PDO $db): array {
    if (!function_exists('g7_jg_enabled') || !g7_jg_enabled()) return [];
    try { return array_map(fn($g) => ['id' => (int)$g['id'], 'name' => (string)$g['name']], $db->query('SELECT id, name FROM g7_jg_games WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC)); }
    catch (Throwable $e) { return []; }
}
/** Lista para la configuración (sin hashes). */
function jr_keys_list(PDO $db): array {
    $rows = $db->query('SELECT k.*, (SELECT COUNT(*) FROM g7_jr_players p WHERE p.key_id = k.id) AS players, (SELECT COUNT(*) FROM g7_jr_reports r WHERE r.key_id = k.id) AS reports FROM g7_jr_keys k ORDER BY k.id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name'], 'game_id' => $k['game_id'] ? (int)$k['game_id'] : null, 'game_name' => jr_game_name($db, $k['game_id'] ? (int)$k['game_id'] : null),
        'hint' => $k['prefix'] . '…', 'active' => (bool)$k['active'], 'created_at' => $k['created_at'], 'rotated_at' => $k['rotated_at'], 'last_used_at' => $k['last_used_at'],
        'players' => (int)$k['players'], 'reports' => (int)$k['reports']], $rows);
}
/** Nombre que ve el equipo para la clave: el del juego de Juegos o el de la clave. */
function jr_key_label(PDO $db, array $k): string { return jr_game_name($db, $k['game_id'] ? (int)$k['game_id'] : null) ?: (string)$k['name']; }

// ── Límites ────────────────────────────────────────────────
/** ¿Se puede? Si sí, anota el pedido. Devuelve 0 o los segundos que faltan. */
function jr_hit(PDO $db, string $bucket, int $max, int $win): int {
    $now = time();
    $q = $db->prepare('SELECT COUNT(*), MIN(at) FROM g7_jr_hits WHERE bucket = ? AND at > ?'); $q->execute([$bucket, $now - $win]);
    [$n, $first] = $q->fetch(PDO::FETCH_NUM);
    if ((int)$n >= $max) return max(1, (int)$first + $win - $now);
    $db->prepare('INSERT INTO g7_jr_hits (bucket, at) VALUES (?, ?)')->execute([$bucket, $now]);
    if (random_int(1, 200) === 1) $db->prepare('DELETE FROM g7_jr_hits WHERE at < ?')->execute([$now - 86400]);
    return 0;
}
function jr_ip_tag(string $ip): string { return substr(hash('sha256', 'jr-ip|' . $ip), 0, 24); }
/** Revisa los límites de una acción. Devuelve 0 o los segundos que faltan. */
function jr_limit(PDO $db, string $what, ?int $playerId, string $ip): int {
    $l = JR_LIMITS[$what];
    if (isset($l['ip']) && ($w = jr_hit($db, "$what:ip:" . jr_ip_tag($ip), $l['ip'][0], $l['ip'][1]))) return $w;
    if (isset($l['p']) && $playerId && ($w = jr_hit($db, "$what:p:$playerId", $l['p'][0], $l['p'][1]))) return $w;
    return 0;
}

// ── Jugadores ──────────────────────────────────────────────
/** Etiqueta del jugador: su nombre o "Jugador #ABCD" (primeros 4 caracteres de su id). */
function jr_player_label(array $p): string { return $p['name'] !== '' ? (string)$p['name'] : 'Jugador #' . strtoupper(substr((string)$p['uuid'], 0, 4)); }
/**
 * Jugador de la clave: lo crea en el primer pedido (guarda sha256 del token) o comprueba el token.
 * Devuelve [jugador|null, código de error]. Errores: 'token' (no coincide), 'limit' (demasiados jugadores nuevos desde la IP).
 */
function jr_player(PDO $db, int $keyId, string $uuid, string $token, ?string $name, string $ip): array {
    $hash = hash('sha256', $token);
    $q = $db->prepare('SELECT * FROM g7_jr_players WHERE key_id = ? AND uuid = ?'); $q->execute([$keyId, $uuid]); $p = $q->fetch(PDO::FETCH_ASSOC);
    $now = tk_now();
    if (!$p) {
        if ($w = jr_limit($db, 'new_player', null, $ip)) return [null, 'limit', $w];
        $db->prepare('INSERT IGNORE INTO g7_jr_players (key_id, uuid, token_hash, name, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$keyId, $uuid, $hash, (string)$name, $now, $now]);
        $q->execute([$keyId, $uuid]); $p = $q->fetch(PDO::FETCH_ASSOC);   // si dos pedidos llegaron juntos, vale el que entró primero
    }
    if (!$p || !hash_equals((string)$p['token_hash'], $hash)) return [null, 'token', 0];
    $f = ['last_seen_at' => $now];
    if ($name !== null && $name !== '' && $name !== $p['name']) $f['name'] = $name;
    $db->prepare('UPDATE g7_jr_players SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $p['id']]);
    return [array_merge($p, $f), '', 0];
}

// ── Categorías ─────────────────────────────────────────────
/** Categoría de Trabajo para un tipo de reporte. Se crea sola la primera vez (o si la borraron); después se puede renombrar. */
function jr_cat(PDO $db, string $kind): int {
    $k = 'jr_cat_' . $kind; $id = (int)wk_cfg_get($db, $k, '0');
    if ($id) { $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); if ($q->fetchColumn()) return $id; }
    [, $name, $color] = JR_KINDS[$kind];
    $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE name = ? LIMIT 1'); $q->execute([$name]); $id = (int)$q->fetchColumn();
    if (!$id) {
        $pos = (int)$db->query('SELECT COALESCE(MAX(position), 0) FROM g7_wk_ticket_cats')->fetchColumn() + 1;
        $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES (?, NULL, ?, 1, ?)')->execute([$name, $color, $pos]);
        $id = (int)$db->lastInsertId();
    }
    wk_cfg_put($db, $k, (string)$id);
    return $id;
}

// ── Reportes ───────────────────────────────────────────────
/** Datos del juego limpios: solo las claves conocidas, texto de una línea de hasta 200 caracteres (lo que sobra se corta). */
function jr_context($ctx): array {
    $out = [];
    if (!is_array($ctx)) return $out;
    foreach (JR_CTX_KEYS as $k) {
        if (!array_key_exists($k, $ctx) || is_array($ctx[$k]) || is_bool($ctx[$k]) || $ctx[$k] === null) continue;
        $v = jr_line(mb_substr((string)$ctx[$k], 0, JR_MAX_CTX * 2), JR_MAX_CTX * 2);
        $v = mb_substr((string)$v, 0, JR_MAX_CTX);
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
}
/** "Versión: 0.3.1 · Plataforma: Windows…" en líneas, para la tarea. */
function jr_context_lines(array $ctx): string {
    $l = []; foreach ($ctx as $k => $v) $l[] = (JR_CTX_LABEL[$k] ?? $k) . ': ' . $v;
    return implode("\n", $l);
}
/** "Laura · NEIBOR" para mostrar en Trabajo. */
function jr_from(PDO $db, array $p, array $k): string { return mb_substr(jr_player_label($p) . ' · ' . jr_key_label($db, $k), 0, 160); }

/** Crea el reporte: ticket (origen juego, sin usuario de Toolz), su tarea, aviso al departamento y respuesta automática. Devuelve el id del ticket. */
function jr_create(PDO $db, array $k, array $p, string $kind, string $subject, string $body, array $ctx): int {
    $cat = jr_cat($db, $kind);
    $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$cat]); $team = $q->fetchColumn() ?: null;
    $cfg = tk_config($db); $prio = $cfg['default_priority'];
    $sla = wk_sla_for($db, $cat, $prio);
    $now = tk_now(); $ts = strtotime($now);
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO g7_wk_tickets (subject, description, category_id, team_id, priority, status, requester_id, first_response_due, resolution_due, created_at, updated_at, origin)
            VALUES (?, ?, ?, ?, ?, 'abierto', 0, ?, ?, ?, ?, 'juego')")
           ->execute([$subject, $body, $cat, $team, $prio, $sla ? date('Y-m-d H:i:s', $ts + $sla['response'] * 60) : null, $sla ? date('Y-m-d H:i:s', $ts + $sla['resolution'] * 60) : null, $now, $now]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO g7_jr_reports (ticket_id, key_id, player_id, kind, context, created_at) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$id, (int)$k['id'], (int)$p['id'], $kind, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null, $now]);
        $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name) VALUES (?, NULL, ?, 0, 'event', ?, ?)")
           ->execute([$id, 'envió ' . (['bug' => 'un bug', 'sugerencia' => 'una sugerencia', 'consulta' => 'una consulta'][$kind]) . ' desde el juego (' . jr_key_label($db, $k) . ')', $now, jr_from($db, $p, $k)]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    // Lo mismo que un ticket nuevo de Trabajo: su tarea, el aviso al departamento y la respuesta automática del área
    try { wk_tk_make_task($db, $id, null); } catch (Throwable $e) { error_log('[jr] tarea del ticket ' . $id . ': ' . $e->getMessage()); }
    try { wk_tk_notify($db, 'tk_new', wk_team_people($db, $team ? (int)$team : null), wk_tk_row($db, $id), null, 'Llegó del juego: ' . jr_from($db, $p, $k) . ' · ' . mb_substr($body, 0, 160)); }
    catch (Throwable $e) { error_log('[jr] aviso del ticket ' . $id . ': ' . $e->getMessage()); }
    try { require_once dirname(__DIR__) . '/trabajo/tk_canned_lib.php'; tk_autoreply_send($db, $id); } catch (Throwable $e) {}
    // Lo que había al crearlo ya lo vio el jugador (incluida la respuesta automática)
    $db->prepare('UPDATE g7_jr_reports SET seen_msg_id = (SELECT COALESCE(MAX(id), 0) FROM g7_wk_ticket_msgs WHERE ticket_id = ?) WHERE ticket_id = ?')->execute([$id, $id]);
    return $id;
}

/** Reporte de un jugador (ticket + vínculo) o null si no es suyo. */
function jr_report_of(PDO $db, int $ticketId, int $playerId): ?array {
    $q = $db->prepare("SELECT t.*, r.kind, r.player_id, r.key_id, r.seen_msg_id FROM g7_jr_reports r JOIN g7_wk_tickets t ON t.id = r.ticket_id WHERE r.ticket_id = ? AND r.player_id = ? AND t.origin = 'juego'");
    $q->execute([$ticketId, $playerId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** El jugador responde: mensaje público, el ticket vuelve a En curso si estaba Esperando o Resuelto, su tarea se pone al día y se avisa. */
function jr_reply(PDO $db, array $t, array $k, array $p, string $body): void {
    $now = tk_now(); $id = (int)$t['id'];
    $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name) VALUES (?, NULL, ?, 0, 'msg', ?, ?)")->execute([$id, $body, $now, jr_from($db, $p, $k)]);
    $up = [];
    if (in_array($t['status'], ['esperando', 'resuelto'], true)) $up = wk_tk_status_fields($t, 'en_curso', $now);   // como cuando responde quien abrió el ticket
    $up['updated_at'] = $now;
    wk_tk_set($db, $id, $up);
    if (isset($up['status'])) $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name) VALUES (?, NULL, ?, 0, 'event', ?, ?)")
        ->execute([$id, 'respondió y el ticket pasó a ' . (wk_st('tickets')['en_curso'][0] ?? 'En curso'), $now, jr_from($db, $p, $k)]);
    wk_tk_mirror($db, $id);                                   // queda en los comentarios de su tarea
    if (isset($up['status'])) wk_task_from_tk($db, $id, null); // y la tarea se reabre si estaba terminada
    $t2 = wk_tk_row($db, $id);
    try { wk_tk_notify($db, 'tk_reply', wk_tk_people($db, $t2, false), $t2, null, 'Respondió ' . jr_from($db, $p, $k) . ': ' . mb_substr($body, 0, 160)); } catch (Throwable $e) {}
    jr_seen($db, $id);
}

/** El jugador vio el reporte: todo lo que hay hasta ahora queda leído. */
function jr_seen(PDO $db, int $ticketId): void {
    $db->prepare('UPDATE g7_jr_reports SET seen_msg_id = (SELECT COALESCE(MAX(id), 0) FROM g7_wk_ticket_msgs WHERE ticket_id = ?) WHERE ticket_id = ?')->execute([$ticketId, $ticketId]);
}

/**
 * Reportes del jugador con su conversación, como los ve el juego. Solo salen el texto del jugador y las respuestas públicas del equipo
 * (kind 'msg' con usuario, internal 0) y la respuesta automática; nunca las notas internas ni los eventos.
 */
function jr_tickets_out(PDO $db, array $p, ?int $only = null): array {
    $w = 'r.player_id = ?' . ($only ? ' AND r.ticket_id = ?' : '');
    $q = $db->prepare("SELECT t.id, t.subject, t.description, t.status, t.created_at, t.updated_at, r.kind, r.seen_msg_id FROM g7_jr_reports r JOIN g7_wk_tickets t ON t.id = r.ticket_id
        WHERE $w AND t.origin = 'juego' ORDER BY t.created_at DESC, t.id DESC LIMIT " . JR_MAX_TICKETS);
    $q->execute($only ? [(int)$p['id'], $only] : [(int)$p['id']]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $m = $db->query("SELECT m.id, m.ticket_id, m.user_id, m.body, m.kind, m.created_at, COALESCE(NULLIF(u.display_name, ''), u.username) AS uname
        FROM g7_wk_ticket_msgs m LEFT JOIN g7_users u ON u.id = m.user_id
        WHERE m.ticket_id IN (" . implode(',', $ids) . ") AND m.internal = 0 AND m.kind IN ('msg', 'auto') ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($m as $x) $by[(int)$x['ticket_id']][] = $x;
    $me = jr_player_label($p);
    $out = [];
    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $msgs = [['from' => 'player', 'name' => $me, 'body' => (string)$r['description'], 'at' => jr_utc($r['created_at'])]];
        $unread = false;
        foreach ($by[$tid] ?? [] as $x) {
            if ($x['kind'] === 'auto') { $msgs[] = ['from' => 'team', 'name' => 'Respuesta automática', 'body' => (string)$x['body'], 'at' => jr_utc($x['created_at'])]; continue; }
            $team = $x['user_id'] !== null;
            $msgs[] = ['from' => $team ? 'team' : 'player', 'name' => $team ? ((string)$x['uname'] ?: 'Equipo') : $me, 'body' => (string)$x['body'], 'at' => jr_utc($x['created_at'])];
            if ($team && (int)$x['id'] > (int)$r['seen_msg_id']) $unread = true;
        }
        $out[] = ['id' => $tid, 'kind' => $r['kind'], 'subject' => (string)$r['subject'], 'status' => $r['status'],
            'status_label' => JR_STATUS_LABEL[$r['status']] ?? (string)$r['status'], 'created_at' => jr_utc($r['created_at']), 'updated_at' => jr_utc($r['updated_at']),
            'unread' => $unread, 'messages' => $msgs];
    }
    return $out;
}

// ── Para Trabajo ───────────────────────────────────────────
/** "Laura · NEIBOR" de varios tickets del juego a la vez (lista de Tickets). [ticket_id => texto] */
function jr_from_many(PDO $db, array $ticketIds): array {
    $ticketIds = array_values(array_filter(array_map('intval', $ticketIds)));
    if (!$ticketIds) return [];
    $rows = $db->query('SELECT r.ticket_id, p.uuid, p.name, k.name AS key_name, k.game_id FROM g7_jr_reports r JOIN g7_jr_players p ON p.id = r.player_id JOIN g7_jr_keys k ON k.id = r.key_id WHERE r.ticket_id IN (' . implode(',', $ticketIds) . ')')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['ticket_id']] = jr_from($db, $r, ['name' => $r['key_name'], 'game_id' => $r['game_id']]);
    return $out;
}
/** Tarjeta "Del juego" del detalle del ticket: jugador, tipo, juego y los datos que mandó el juego. */
function jr_ticket_card(PDO $db, int $ticketId): ?array {
    $q = $db->prepare('SELECT r.*, p.uuid, p.name, p.created_at AS p_created, k.name AS key_name, k.game_id FROM g7_jr_reports r JOIN g7_jr_players p ON p.id = r.player_id JOIN g7_jr_keys k ON k.id = r.key_id WHERE r.ticket_id = ?');
    $q->execute([$ticketId]); $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $k = ['name' => $r['key_name'], 'game_id' => $r['game_id']];
    $q = $db->prepare('SELECT COUNT(*) FROM g7_jr_reports WHERE player_id = ?'); $q->execute([(int)$r['player_id']]);
    $ctx = json_decode((string)$r['context'], true) ?: [];
    return ['from' => jr_from($db, $r, $k), 'player' => jr_player_label($r), 'player_code' => strtoupper(substr((string)$r['uuid'], 0, 8)), 'game' => jr_key_label($db, $k), 'key_name' => (string)$r['key_name'],
        'kind' => $r['kind'], 'kind_label' => JR_KINDS[$r['kind']][0] ?? $r['kind'], 'player_reports' => (int)$q->fetchColumn(),
        'context' => array_map(fn($kk, $v) => ['label' => JR_CTX_LABEL[$kk] ?? $kk, 'value' => $v], array_keys($ctx), array_values($ctx))];
}
/** Descripción de la tarea de un reporte: de dónde vino, los datos del juego y el texto del jugador. */
function jr_task_desc(PDO $db, array $t): string {
    $c = jr_ticket_card($db, (int)$t['id']);
    if (!$c) return (string)$t['description'];
    $ctx = implode("\n", array_map(fn($x) => $x['label'] . ': ' . $x['value'], $c['context']));
    return $c['kind_label'] . ' del juego · ' . $c['from'] . ($ctx !== '' ? "\n" . $ctx : '') . "\n\n" . $t['description'];
}
/** Nombre de pila del jugador para la respuesta automática ({nombre}). */
function jr_requester_name(PDO $db, int $ticketId): string {
    $q = $db->prepare('SELECT p.name FROM g7_jr_reports r JOIN g7_jr_players p ON p.id = r.player_id WHERE r.ticket_id = ?'); $q->execute([$ticketId]);
    return (string)$q->fetchColumn();
}
