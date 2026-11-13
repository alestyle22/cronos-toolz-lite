<?php
/**
 * trabajo/tk_canned_lib.php — Respuestas rápidas y respuesta automática de los tickets (2026-11-11).
 *  - Respuestas rápidas: textos con variables que quien atiende inserta con un clic (botón del cuadro de respuesta).
 *    Cada una es para todas las áreas o para un área (equipo) y puede cambiar el estado al enviar (ej. "Solucionado" → resuelto).
 *  - Respuesta automática: al entrar un ticket nuevo se responde sola con el texto de su área (o el general). No cuenta como
 *    primera respuesta del SLA. Si el ticket vino del sitio base, también le llega a la agencia.
 * Variables: {saludo} (Buenos días / Buenas tardes / Buenas noches según la hora), {nombre}, {agente}, {codigo}, {asunto}.
 * Se guarda en g7_config: tk_canned (lista) y tk_autoreply. Lo editan admins y directores. Doc: toolz/TICKETS_RESPUESTAS.md
 */

const TK_CANNED_STATUS = ['', 'en_curso', 'esperando', 'resuelto', 'cerrado'];

function tk_canned_defaults(): array {
    $c = fn($id, $title, $body, $status) => ['id' => $id, 'title' => $title, 'body' => $body, 'status' => $status, 'team_id' => null];
    return [
        $c('recibido', 'Recibido', "{saludo} {nombre}, recibimos su comentario y ya estamos trabajando para solucionarlo. Le avisaremos por este medio apenas tengamos novedades.\n\nMuchas gracias.", 'en_curso'),
        $c('datos', 'Necesitamos más datos', "{saludo} {nombre}, para poder avanzar necesitamos un poco más de información:\n\n- \n\nQuedamos atentos a su respuesta.\n\nMuchas gracias.", 'esperando'),
        $c('proceso', 'Seguimos trabajando', "{saludo} {nombre}, le contamos que seguimos trabajando en su pedido ({codigo}). Apenas tengamos novedades le escribimos.\n\nMuchas gracias.", ''),
        $c('solucionado', 'Solucionado', "{saludo} {nombre}, le informamos que el problema ha sido solucionado. Si vuelve a ocurrir, puede responder este mensaje y lo retomamos.\n\nMuchas gracias.", 'resuelto'),
        $c('seguimiento', 'Sin respuesta', "{saludo} {nombre}, quedamos atentos a su respuesta para poder continuar con el ticket {codigo}.\n\nMuchas gracias.", ''),
        $c('cierre', 'Cierre', "{saludo} {nombre}, damos por cerrado este ticket. ¡Que tenga un excelente día!\n\nMuchas gracias.", 'cerrado'),
    ];
}

function tk_canned_cfg(PDO $db, string $k) {
    $q = $db->prepare('SELECT cfg_value FROM g7_config WHERE cfg_key = ?'); $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? null : json_decode((string)$v, true);
}
function tk_canned_put(PDO $db, string $k, $v): void {
    $db->prepare('INSERT INTO g7_config (cfg_key, cfg_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
function tk_canned_teams(PDO $db): array {
    try { return array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { return []; }
}

/** Todas las respuestas rápidas (si nunca se guardaron, las de fábrica). */
function tk_canned_all(PDO $db): array {
    $v = tk_canned_cfg($db, 'tk_canned');
    return is_array($v) ? $v : tk_canned_defaults();
}

/** Las de un área: las generales más las de ese equipo. */
function tk_canned_for(PDO $db, ?int $teamId): array {
    return array_values(array_filter(tk_canned_all($db), fn($c) => $c['team_id'] === null || ($teamId && (int)$c['team_id'] === $teamId)));
}

/** Valida y guarda la lista. Devuelve lo guardado. */
function tk_canned_save(PDO $db, array $in): array {
    $teams = tk_canned_teams($db); $out = []; $ids = [];
    foreach (array_slice($in, 0, 60) as $c) {
        if (!is_array($c)) continue;
        $title = mb_substr(trim(strip_tags((string)($c['title'] ?? ''))), 0, 60);
        $body = mb_substr(trim((string)($c['body'] ?? '')), 0, 4000);
        if ($title === '' && $body === '') continue;
        if ($title === '') throw new RuntimeException('Cada respuesta necesita un nombre.');
        if ($body === '') throw new RuntimeException('La respuesta "' . $title . '" está vacía.');
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($c['id'] ?? ''))) ?: 'r' . substr(md5($title . microtime()), 0, 8);
        while (isset($ids[$id])) $id .= 'x';
        $ids[$id] = 1;
        $team = (int)($c['team_id'] ?? 0);
        $out[] = ['id' => $id, 'title' => $title, 'body' => $body, 'status' => in_array($c['status'] ?? '', TK_CANNED_STATUS, true) ? $c['status'] : '',
            'team_id' => $team && in_array($team, $teams, true) ? $team : null];
    }
    tk_canned_put($db, 'tk_canned', $out);
    return $out;
}

/** Respuesta automática: general y por área. Por área: 'mode' = general (usa la general) | own (texto propio) | off. */
function tk_autoreply_get(PDO $db): array {
    $v = tk_canned_cfg($db, 'tk_autoreply');
    $d = ['on' => false, 'body' => "{saludo} {nombre}, recibimos su ticket {codigo}. Ya lo tiene nuestro equipo y le responderemos a la brevedad por este medio.\n\nMuchas gracias.", 'teams' => []];
    return is_array($v) ? $v + $d : $d;
}
function tk_autoreply_save(PDO $db, array $in): array {
    $teams = tk_canned_teams($db);
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 4000);
    if (!empty($in['on']) && $body === '') throw new RuntimeException('Escribe el texto de la respuesta automática.');
    $out = ['on' => !empty($in['on']), 'body' => $body, 'teams' => []];
    foreach ((array)($in['teams'] ?? []) as $tid => $t) {
        $tid = (int)$tid; if (!in_array($tid, $teams, true) || !is_array($t)) continue;
        $mode = in_array($t['mode'] ?? '', ['general', 'own', 'off'], true) ? $t['mode'] : 'general';
        if ($mode === 'general') continue;
        $tb = mb_substr(trim((string)($t['body'] ?? '')), 0, 4000);
        if ($mode === 'own' && $tb === '') throw new RuntimeException('Falta el texto de la respuesta automática de un área.');
        $out['teams'][$tid] = ['mode' => $mode, 'body' => $mode === 'own' ? $tb : ''];
    }
    tk_canned_put($db, 'tk_autoreply', $out);
    return $out;
}
/** Texto que corresponde a un área (o null si no hay respuesta automática). */
function tk_autoreply_text(PDO $db, ?int $teamId): ?string {
    $a = tk_autoreply_get($db);
    $t = $teamId ? ($a['teams'][$teamId] ?? $a['teams'][(string)$teamId] ?? null) : null;
    if ($t && $t['mode'] === 'off') return null;
    if ($t && $t['mode'] === 'own' && trim($t['body']) !== '') return $t['body'];
    return $a['on'] && trim($a['body']) !== '' ? $a['body'] : null;
}

function tk_greeting(?int $hour = null): string {
    $h = $hour ?? (int)date('G');
    return $h >= 5 && $h < 12 ? 'Buenos días' : ($h >= 12 && $h < 20 ? 'Buenas tardes' : 'Buenas noches');
}
/** Completa las variables. Lo que falta queda vacío y se limpian los espacios que sobran ("Buenos días , ..."). */
function tk_fill(string $tpl, array $v): string {
    $s = strtr($tpl, ['{saludo}' => $v['saludo'] ?? tk_greeting(), '{nombre}' => $v['nombre'] ?? '', '{agente}' => $v['agente'] ?? '', '{codigo}' => $v['codigo'] ?? '', '{asunto}' => $v['asunto'] ?? '']);
    return trim(preg_replace(['/[ \t]+,/', '/[ \t]{2,}/', '/\(\s*\)/'], [',', ' ', ''], $s));
}

/** Manda la respuesta automática de un ticket recién creado (si su área la tiene). Devuelve el id del mensaje o 0. */
function tk_autoreply_send(PDO $db, int $ticketId, ?int $hour = null): int {
    $q = $db->prepare('SELECT * FROM g7_wk_tickets WHERE id = ?'); $q->execute([$ticketId]); $t = $q->fetch(PDO::FETCH_ASSOC);
    if (!$t || in_array($t['status'], ['resuelto', 'cerrado'], true) || ($t['origin'] ?? '') === 'tarea') return 0;
    $tpl = tk_autoreply_text($db, $t['team_id'] ? (int)$t['team_id'] : null);
    if ($tpl === null) return 0;
    $name = '';
    if (!empty($t['base_info'])) { $i = json_decode((string)$t['base_info'], true) ?: []; $name = (string)($i['contact']['name'] ?? $i['site_name'] ?? ''); }
    elseif (($t['origin'] ?? '') === 'juego') { require_once dirname(__DIR__) . '/juegos/reportes_lib.php'; $name = jr_requester_name($db, (int)$t['id']); }   // reporte del juego: el nombre del jugador
    elseif ($t['requester_id']) { $u = $db->prepare("SELECT COALESCE(NULLIF(display_name,''), username) FROM g7_users WHERE id = ?"); $u->execute([(int)$t['requester_id']]); $name = (string)$u->fetchColumn(); }
    $name = explode(' ', trim($name))[0] ?? '';
    $body = tk_fill($tpl, ['saludo' => tk_greeting($hour), 'nombre' => $name, 'agente' => 'el equipo', 'codigo' => 'T-' . str_pad((string)$t['id'], 4, '0', STR_PAD_LEFT), 'asunto' => $t['subject']]);
    $db->prepare("INSERT INTO g7_wk_ticket_msgs (ticket_id, user_id, body, internal, kind, created_at) VALUES (?, NULL, ?, 0, 'auto', ?)")->execute([$ticketId, $body, date('Y-m-d H:i:s')]);
    $mid = (int)$db->lastInsertId();
    // Si vino del sitio base, la agencia también la recibe (sin tocar el SLA: no es una respuesta de una persona)
    if (!empty($t['base_id'])) { try { if (!function_exists('wb_after')) require_once __DIR__ . '/base_sync.php'; wb_after($db, $t, $mid, $body, null, 'Respuesta automática'); } catch (Throwable $e) {} }
    return $mid;
}
