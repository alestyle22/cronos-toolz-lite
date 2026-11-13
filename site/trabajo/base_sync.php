<?php
/**
 * trabajo/base_sync.php — Tickets del sitio base dentro de Trabajo › Tickets. Solo corre en el Toolz conectado al base
 * (el que tiene 'base_url' y 'base_sso_secret' en _private/site.php, hoy cronostoolz.com/toolz); en las agencias no hace nada.
 *
 * Entran (acción firmada tk_sync): Comercial › Demo (formulario de cronostoolz.com) y Soporte (página Soporte de cada agencia),
 * con sus respuestas y estados. Salen (tk_sync_push): las respuestas públicas, los cambios de estado y el cierre. Las notas internas nunca salen.
 * Idempotente:
 *   - cada ticket del base se crea una sola vez (g7_wk_tickets.base_id es único);
 *   - cada mensaje del base entra una sola vez (g7_wk_ticket_msgs.base_rid, único por ticket);
 *   - lo que sale va por una cola (g7_wk_base_out) en orden, con una clave por envío que el base no repite; si el base no responde, se reintenta.
 * Se sincroniza como mucho cada minuto cuando alguien usa Trabajo, desde trabajo/cron.php y con "Sincronizar ahora". Doc: toolz/BASE.md
 */
require_once __DIR__ . '/../base_client_lib.php';

/** Estados del base ↔ Trabajo. Un estado propio de Trabajo que no esté acá viaja como "en_curso". */
const WB_ST_IN = ['nuevo' => 'abierto', 'en_curso' => 'en_curso', 'esperando' => 'esperando', 'resuelto' => 'resuelto', 'cerrado' => 'cerrado'];
const WB_ST_OUT = ['abierto' => 'nuevo', 'en_curso' => 'en_curso', 'esperando' => 'esperando', 'resuelto' => 'resuelto', 'cerrado' => 'cerrado'];
/** Categoría de Trabajo según de dónde vino el ticket. Se crean solas; después se pueden renombrar o pasar a un departamento. */
const WB_CATS = ['web' => ['Comercial › Demo', '#a855f7'], 'sitio' => ['Soporte', '#3b82f6']];
const WB_EVERY = 60;   // segundos entre sincronizaciones automáticas

function wb_enabled(): bool { return function_exists('g7_site') && bc_configured(); }
function wb_cfg(PDO $db, string $k): ?string { $q = $db->prepare('SELECT cfg_value FROM g7_config WHERE cfg_key = ?'); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false ? null : (string)$v; }
function wb_cfg_set(PDO $db, string $k, string $v): void { $db->prepare('INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)')->execute([$k, $v]); }

/** Columnas y cola. Una sola vez por instalación. */
function wb_install(PDO $db): void {
    static $done = false; if ($done) return; $done = true;
    if (wb_cfg($db, 'work_base_schema') === '1') return;
    $db->exec('ALTER TABLE g7_wk_tickets ADD COLUMN IF NOT EXISTS base_id INT NULL, ADD COLUMN IF NOT EXISTS base_info TEXT NULL');
    $db->exec('ALTER TABLE g7_wk_tickets ADD UNIQUE KEY IF NOT EXISTS base_id (base_id)');
    $db->exec('ALTER TABLE g7_wk_ticket_msgs ADD COLUMN IF NOT EXISTS ext_name VARCHAR(160) NULL, ADD COLUMN IF NOT EXISTS base_rid VARCHAR(48) NULL');
    $db->exec('ALTER TABLE g7_wk_ticket_msgs ADD UNIQUE KEY IF NOT EXISTS base_rid (ticket_id, base_rid)');
    $db->exec("CREATE TABLE IF NOT EXISTS g7_wk_base_out (
        id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, base_id INT NOT NULL, op_key VARCHAR(48) NOT NULL, payload TEXT NOT NULL,
        user_name VARCHAR(100) NOT NULL DEFAULT '', tries INT NOT NULL DEFAULT 0, last_error VARCHAR(300) NOT NULL DEFAULT '',
        sent_at DATETIME NULL, dead TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
        UNIQUE KEY op_key (op_key), KEY ticket_id (ticket_id), KEY pending (sent_at, dead)
    ) DEFAULT CHARSET=utf8mb4");
    if (wb_cfg($db, 'work_base_inst') === null) wb_cfg_set($db, 'work_base_inst', bin2hex(random_bytes(3)));   // prefijo de las claves de envío
    wb_cfg_set($db, 'work_base_schema', '1');
}

/** Id de la categoría para tickets de la web o de las agencias (la crea si falta o si la borraron). */
function wb_cat(PDO $db, string $source): int {
    $k = 'work_base_cat_' . $source; $id = (int)wb_cfg($db, $k);
    if ($id) { $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$id]); if ($q->fetchColumn()) return $id; }
    [$name, $color] = WB_CATS[$source];
    $q = $db->prepare('SELECT id FROM g7_wk_ticket_cats WHERE name = ? LIMIT 1'); $q->execute([$name]); $id = (int)$q->fetchColumn();
    if (!$id) {
        $pos = (int)$db->query('SELECT COALESCE(MAX(position), 0) FROM g7_wk_ticket_cats')->fetchColumn() + 1;
        $db->prepare('INSERT INTO g7_wk_ticket_cats (name, team_id, color, active, position) VALUES (?, NULL, ?, 1, ?)')->execute([$name, $color, $pos]);
        $id = (int)$db->lastInsertId();
    }
    wb_cfg_set($db, $k, (string)$id);
    return $id;
}

function wb_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function wb_dt($v): string { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) && strtotime($v) ? $v : tk_now(); }

/** Lo que Trabajo guarda del ticket del base (solo para mostrar). Todo texto se escapa al mostrarlo; la URL solo si es http(s). */
function wb_info(array $bt, array $types = []): array {
    $c = (array)($bt['contact'] ?? []); $m = (array)($bt['meta'] ?? []); $type = wb_str($bt['type'] ?? '', 20);
    $url = wb_str($m['url'] ?? '', 300);
    return ['id' => (int)$bt['id'], 'source' => ($bt['source'] ?? '') === 'web' ? 'web' : 'sitio', 'site_id' => wb_str($bt['site_id'] ?? '', 40), 'site_name' => wb_str($bt['site_name'] ?? '', 80),
        'area' => wb_str($bt['area'] ?? '', 20), 'type' => $type, 'type_label' => wb_str($types[$type][1] ?? $type, 40), 'status' => wb_str($bt['status'] ?? '', 20),
        'contact' => ['name' => wb_str($c['name'] ?? '', 100), 'email' => wb_str($c['email'] ?? '', 120), 'phone' => wb_str($c['phone'] ?? '', 40), 'company' => wb_str($c['company'] ?? '', 120), 'team' => wb_str($c['team'] ?? '', 20)],
        'meta' => ['url' => preg_match('#^https?://#i', $url) ? $url : '', 'version' => wb_str($m['version'] ?? '', 20), 'ua' => wb_str($m['ua'] ?? '', 300)],
        'files' => array_values(array_filter(array_map('strval', (array)($bt['files'] ?? [])), fn($f) => preg_match('/^\d+_\d+_[a-f0-9]{8}\.(png|jpg|webp|gif)$/', $f)))];
}
/** "Laura Gómez · Agencia Norte" o "Sofía Paz · Paz & Asociados (web)". */
function wb_from(array $info): string {
    $c = $info['contact'] ?? []; $who = $c['name'] ?? '';
    if (($info['source'] ?? '') === 'web') return trim($who . ' · ' . (($c['company'] ?? '') ?: 'cronostoolz.com'), ' ·') . ' (web)';
    return trim($who . ' · ' . ($info['site_name'] ?? ''), ' ·');
}

/** Cambios de estado de Trabajo: las mismas fechas que pone ticket_update. */
function wb_status_fields(string $s, array $t, string $at): array {
    return wk_tk_status_fields($t, $s, $at);   // incluye la pausa del SLA en "Esperando" (trabajo/link_lib.php)
}

/** Respuestas del base que Trabajo todavía no tiene. Devuelve [cuántas entraron, cuántas son de la agencia, primera respuesta del equipo]. */
function wb_replies(PDO $db, int $tid, array $bt, array $info): array {
    $ins = $db->prepare("INSERT IGNORE INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name, base_rid) VALUES (?, NULL, ?, 0, 'msg', ?, ?, ?)");
    $n = 0; $fromAgency = 0; $firstTeam = null;
    foreach (array_values((array)($bt['replies'] ?? [])) as $i => $r) {
        $rid = wb_str($r['rid'] ?? '', 48) ?: 'r' . (int)$bt['id'] . '_' . $i;
        $agency = ($r['by'] ?? '') !== 'cronos';
        if (!$agency && $firstTeam === null) $firstTeam = wb_dt($r['at'] ?? '');
        $name = $agency ? trim(wb_str($r['name'] ?? '', 100) ?: ($info['contact']['name'] ?? '')) . ($info['site_name'] ? ' · ' . $info['site_name'] : '')
                        : (wb_str($r['name'] ?? '', 100) ?: 'Cronos') . ' (desde el base)';
        $ins->execute([$tid, mb_substr((string)($r['text'] ?? ''), 0, 20000), wb_dt($r['at'] ?? ''), mb_substr($name, 0, 160), $rid]);
        if ($ins->rowCount()) { $n++; if ($agency) $fromAgency++; }
    }
    return [$n, $fromAgency, $firstTeam];
}

/** Crea o actualiza en Trabajo un ticket que vino del base. $quiet = primera sincronización (tickets que ya existían): sin avisos. */
function wb_import(PDO $db, array $bt, array $types, bool $quiet): string {
    $bid = (int)($bt['id'] ?? 0); if ($bid <= 0) return '';
    $info = wb_info($bt, $types);
    $st = WB_ST_IN[$info['status']] ?? 'en_curso';
    $q = $db->prepare('SELECT * FROM g7_wk_tickets WHERE base_id = ?'); $q->execute([$bid]); $t = $q->fetch(PDO::FETCH_ASSOC);
    $now = tk_now();
    if (!$t) {
        $cat = wb_cat($db, $info['source']);
        $q = $db->prepare('SELECT team_id FROM g7_wk_ticket_cats WHERE id = ?'); $q->execute([$cat]); $team = $q->fetchColumn() ?: null;
        $cfg = tk_config($db);
        $prio = in_array($bt['priority'] ?? '', array_column($cfg['priorities'], 'id'), true) ? $bt['priority'] : $cfg['default_priority'];
        $created = wb_dt($bt['created_at'] ?? ''); $c0 = strtotime($created);
        $sla = wk_sla_for($db, $cat, $prio);
        $frd = $sla ? date('Y-m-d H:i:s', $c0 + $sla['response'] * 60) : null; $rd = $sla ? date('Y-m-d H:i:s', $c0 + $sla['resolution'] * 60) : null;
        $firstTeam = null; foreach ((array)($bt['replies'] ?? []) as $r) if (($r['by'] ?? '') === 'cronos') { $firstTeam = wb_dt($r['at'] ?? ''); break; }
        // Si llega con el plazo ya vencido (tickets de antes o el base estuvo sin conexión) no se escala en masa: queda marcado como vencido
        $flags = ($frd && !$firstTeam && $frd < $now ? 1 : 0) | ($rd && $rd < $now ? 2 : 0);
        $upd = wb_dt($bt['updated_at'] ?? '');
        $resolved = in_array($st, ['resuelto', 'cerrado'], true) ? $upd : null; $closed = $st === 'cerrado' ? $upd : null;
        $subject = wb_str($bt['title'] ?? '', 200) ?: 'Ticket #' . $bid . ' del base';
        $desc = mb_substr(trim((string)($bt['body'] ?? '')), 0, 20000) ?: $subject;
        $db->prepare('INSERT INTO g7_wk_tickets (subject, description, category_id, team_id, priority, status, requester_id, first_response_due, resolution_due, first_response_at, resolved_at, closed_at, breach_flags, created_at, updated_at, base_id, base_info, origin, paused_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'base\', ?)')
           ->execute([$subject, $desc, $cat, $team, $prio, $st, $frd, $rd, $firstTeam, $resolved, $closed, $flags, $created, $now, $bid, json_encode($info, JSON_UNESCAPED_UNICODE), $st === 'esperando' ? $now : null]);
        $id = (int)$db->lastInsertId();
        $from = $info['source'] === 'web' ? 'el formulario de demo de cronostoolz.com' : 'Soporte de ' . ($info['site_name'] ?: 'una agencia');
        $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name) VALUES (?, NULL, ?, 0, 'event', ?, ?)")
           ->execute([$id, 'abrió el ticket desde ' . $from . ' (#' . $bid . ' del base)', $created, mb_substr(wb_from($info), 0, 160)]);
        if ($flags) $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, NULL, ?, 1, 'event', ?)")
           ->execute([$id, 'Llegó a Trabajo con el plazo de SLA ya vencido: no se escaló.', $now]);
        wb_replies($db, $id, $bt, $info);
        // Su tarea (en el tablero del departamento) nace con los tickets abiertos; los que ya llegan cerrados quedan solo como historia
        if (in_array($st, ['abierto', 'en_curso', 'esperando'], true)) wk_tk_make_task($db, $id, null);
        if (!$quiet && in_array($st, ['abierto', 'en_curso', 'esperando'], true))
            wk_tk_notify($db, 'tk_new', wk_team_people($db, $team ? (int)$team : null), wk_tk_row($db, $id), null, 'Llegó del sitio base: ' . wb_from($info));
        wb_queue($db, $id, $bid, 'l' . $id, ['link' => $id], 'Trabajo');   // el base anota que se gestiona acá
        // Respuesta automática del área (trabajo/tk_canned_lib.php): después del aviso de vínculo, así le llega a la agencia en orden
        if (!$quiet && in_array($st, ['abierto', 'en_curso', 'esperando'], true)) { try { require_once __DIR__ . '/tk_canned_lib.php'; tk_autoreply_send($db, $id); } catch (Throwable $e) {} }
        return 'new';
    }
    $id = (int)$t['id']; $ch = false;
    if ((string)$t['base_info'] !== ($j = json_encode($info, JSON_UNESCAPED_UNICODE))) { $db->prepare('UPDATE g7_wk_tickets SET base_info = ? WHERE id = ?')->execute([$j, $id]); $ch = true; }
    [$n, $fromAgency, $firstTeam] = wb_replies($db, $id, $bt, $info);
    $f = [];
    if ($firstTeam && !$t['first_response_at']) $f['first_response_at'] = $firstTeam;   // alguien respondió desde el panel del base
    // El estado del base manda salvo que Trabajo tenga un cambio de estado todavía sin entregar (lo de acá es más nuevo)
    $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_base_out WHERE ticket_id = ? AND sent_at IS NULL AND dead = 0 AND payload LIKE '%\"status\"%'"); $q->execute([$id]);
    $local = WB_ST_OUT[$t['status']] ?? 'en_curso';
    if (!(int)$q->fetchColumn() && $local !== $info['status'] && $st !== $t['status']) {
        $f += wb_status_fields($st, $t, $now);
        $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at, ext_name) VALUES (?, NULL, ?, 0, 'event', ?, ?)")
           ->execute([$id, ($fromAgency ? 'respondió y el ticket pasó a ' : 'lo pasó a ') . (wk_st('tickets')[$st][0] ?? $st), $now, $fromAgency ? mb_substr(wb_from($info), 0, 160) : 'Panel del sitio base']);
    }
    if ($n || $f) {
        $f['updated_at'] = $now;
        $db->prepare('UPDATE g7_wk_tickets SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
        $ch = true;
        wk_tk_mirror($db, $id);                              // las respuestas de la agencia también quedan en la tarea
        if (isset($f['status'])) wk_task_from_tk($db, $id, null);   // y el estado: resuelto/cerrado termina la tarea
    }
    if ($fromAgency && !$quiet) {
        $t2 = wk_tk_row($db, $id);
        wk_tk_notify($db, 'tk_reply', wk_tk_people($db, $t2, false), $t2, null, 'Respondió ' . wb_from($info));
    }
    return $ch ? 'upd' : '';
}

/** El base empezó de cero: los tickets que había quedan en Trabajo (con su historia) pero ya no se sincronizan. */
function wb_detach(PDO $db): void {
    $db->exec("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) SELECT id, NULL, 'El sitio base empezó una lista de tickets nueva: este ticket ya no se sincroniza.', 1, 'event', NOW() FROM g7_wk_tickets WHERE base_id IS NOT NULL");
    $db->exec("UPDATE g7_wk_base_out SET dead = 1, last_error = 'El sitio base empezó una lista de tickets nueva.' WHERE sent_at IS NULL AND dead = 0");
    $db->exec('UPDATE g7_wk_tickets SET base_id = NULL WHERE base_id IS NOT NULL');
}

/** Anota un envío al base. La clave es única: anotarlo dos veces no lo duplica. */
function wb_queue(PDO $db, int $ticketId, int $baseId, string $key, array $payload, string $user): string {
    $key = 'wk' . (wb_cfg($db, 'work_base_inst') ?? '0') . $key;
    $db->prepare('INSERT IGNORE INTO g7_wk_base_out (ticket_id, base_id, op_key, payload, user_name, created_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$ticketId, $baseId, $key, json_encode($payload, JSON_UNESCAPED_UNICODE), mb_substr($user, 0, 100), tk_now()]);
    return $key;
}

/** Manda lo pendiente, en orden. Si el base no responde, para y queda para la próxima. Devuelve cuántos se entregaron. */
function wb_flush(PDO $db, int $max = 30): int {
    $rows = $db->query('SELECT * FROM g7_wk_base_out WHERE sent_at IS NULL AND dead = 0 ORDER BY id LIMIT ' . (int)$max)->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    foreach ($rows as $o) {
        [$code, $body] = bc_call(['act' => 'tk_sync_push', 'id' => (int)$o['base_id'], 'key' => $o['op_key']] + (json_decode($o['payload'], true) ?: []), $o['user_name'] ?: 'Cronos');
        $d = json_decode($body, true);
        if ($code === 200 && !empty($d['ok'])) { $db->prepare('UPDATE g7_wk_base_out SET sent_at = ?, tries = tries + 1, last_error = \'\' WHERE id = ?')->execute([tk_now(), $o['id']]); $sent++; continue; }
        $err = mb_substr((string)($d['error'] ?? "El base respondió $code."), 0, 300);
        if ($code === 409) {   // el base lo rechazó (p. ej. borraron el ticket allá): no se reintenta, queda a la vista
            $db->prepare('UPDATE g7_wk_base_out SET dead = 1, tries = tries + 1, last_error = ? WHERE id = ?')->execute([$err, $o['id']]);
            $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, NULL, ?, 1, 'event', ?)")->execute([(int)$o['ticket_id'], 'No se pudo enviar al base: ' . $err, tk_now()]);
            continue;
        }
        $db->prepare('UPDATE g7_wk_base_out SET tries = tries + 1, last_error = ? WHERE id = ?')->execute([$err, $o['id']]);
        break;   // sin conexión o firma rechazada: se reintenta después, sin cambiar el orden
    }
    return $sent;
}

/**
 * Trae lo nuevo del base y manda lo pendiente. Como mucho cada WB_EVERY segundos salvo $force. Nunca tira error: lo devuelve en 'error'.
 * La revisión hasta la que se trajo queda en g7_config 'work_base_rev' y avanza ticket por ticket (si uno falla, se reintenta desde ahí).
 */
function wb_sync(PDO $db, bool $force = false): array {
    $res = ['ok' => false, 'skipped' => false, 'new' => 0, 'updated' => 0, 'sent' => 0, 'error' => ''];
    if (!wb_enabled()) { $res['error'] = 'Este sitio no está conectado a un sitio base.'; return $res; }
    $lock = false;
    try {
        wb_install($db);
        if (!$force && time() - (int)wb_cfg($db, 'work_base_sync_at') < WB_EVERY) { $res['skipped'] = true; $res['ok'] = true; return $res; }
        wb_cfg_set($db, 'work_base_sync_at', (string)time());
        if (!($lock = (bool)$db->query("SELECT GET_LOCK('wk_bsync', 0)")->fetchColumn())) { $res['skipped'] = true; $res['ok'] = true; return $res; }
        $res['sent'] = wb_flush($db);
        $cur = wb_cfg($db, 'work_base_rev'); $quiet = $cur === null; $since = $cur === null ? -1 : (int)$cur; $again = false;
        for ($page = 0; $page < 20; $page++) {
            [$code, $body] = bc_call(['act' => 'tk_sync', 'since' => $since, 'limit' => 100], 'Trabajo');
            $d = json_decode($body, true);
            if ($code !== 200 || !is_array($d) || !isset($d['tickets'])) throw new RuntimeException((string)(is_array($d) ? ($d['error'] ?? '') : '') ?: "El base respondió $code.");
            $ep = (string)($d['epoch'] ?? ''); $known = wb_cfg($db, 'work_base_epoch');
            if (!$again && $ep !== '' && $known !== null && $known !== $ep) {
                // El base empezó una lista nueva (los números se repiten): lo de antes queda en Trabajo sin vínculo y se trae todo de nuevo
                wb_detach($db); wb_cfg_set($db, 'work_base_epoch', $ep); wb_cfg_set($db, 'work_base_rev', '-1');
                $since = -1; $quiet = true; $again = true; continue;
            }
            if ($ep !== '' && $known === null) wb_cfg_set($db, 'work_base_epoch', $ep);
            if (!$again && $since > (int)($d['max'] ?? $since)) { $since = -1; $again = true; continue; }   // restauraron un respaldo del base: se repasa todo (no duplica)
            foreach ($d['tickets'] as $bt) {
                $r = wb_import($db, (array)$bt, (array)($d['types'] ?? []), $quiet);
                if ($r === 'new') $res['new']++; elseif ($r === 'upd') $res['updated']++;
                $since = max($since, (int)($bt['rev'] ?? 0)); wb_cfg_set($db, 'work_base_rev', (string)$since);
            }
            $since = max($since, (int)($d['rev'] ?? $since)); wb_cfg_set($db, 'work_base_rev', (string)$since);
            if (empty($d['more'])) break;
        }
        $res['sent'] += wb_flush($db);   // los vínculos de los tickets recién llegados
        $res['ok'] = true;
        wb_cfg_set($db, 'work_base_sync_ok', tk_now()); wb_cfg_set($db, 'work_base_sync_err', '');
    } catch (Throwable $e) {
        $res['error'] = mb_substr($e->getMessage(), 0, 300);
        try { wb_cfg_set($db, 'work_base_sync_err', tk_now() . ' · ' . $res['error']); } catch (Throwable $e2) {}
    } finally {
        if ($lock) try { $db->query("SELECT RELEASE_LOCK('wk_bsync')"); } catch (Throwable $e) {}
    }
    return $res;
}

/**
 * Después de una respuesta pública o un cambio de estado en Trabajo, sobre un ticket que vino del base: se anota en la cola y se intenta
 * mandar enseguida. $msgId = mensaje público (su clave queda en base_rid, así al volver del base no se duplica). Las notas internas no llaman acá.
 */
function wb_after(PDO $db, array $t, ?int $msgId, ?string $text, ?string $status, string $user): void {
    if (empty($t['base_id']) || !wb_enabled()) return;
    try {
        wb_install($db);
        $p = [];
        if ($msgId && $text !== null && $text !== '') $p['text'] = $text;
        if ($status !== null) $p['status'] = WB_ST_OUT[$status] ?? 'en_curso';
        if (!$p) return;
        $key = wb_queue($db, (int)$t['id'], (int)$t['base_id'], isset($p['text']) ? 'm' . $msgId : 's' . (int)$t['id'] . '_' . bin2hex(random_bytes(4)), $p, $user);
        if (isset($p['text'])) $db->prepare('UPDATE g7_wk_ticket_msgs SET base_rid = ? WHERE id = ?')->execute([$key, $msgId]);
        wb_flush($db, 10);
    } catch (Throwable $e) {}   // si falla, queda en la cola y lo manda la próxima sincronización
}

/** Estado de la conexión para la pantalla de Tickets. */
function wb_status(PDO $db): array {
    if (!wb_enabled()) return ['on' => false];
    wb_install($db);
    $pend = (int)$db->query('SELECT COUNT(*) FROM g7_wk_base_out WHERE sent_at IS NULL AND dead = 0')->fetchColumn();
    return ['on' => true, 'last_ok' => wb_cfg($db, 'work_base_sync_ok') ?? '', 'error' => wb_cfg($db, 'work_base_sync_err') ?? '', 'pending' => $pend];
}
