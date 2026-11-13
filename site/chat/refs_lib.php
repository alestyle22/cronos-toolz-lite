<?php
/**
 * chat/refs_lib.php — Vínculos a clientes, contenidos, tareas y tickets dentro de los mensajes del chat (2026-11-11).
 * En el texto del mensaje van como [[tipo:id]] (por ejemplo [[tarea:45]]). Cada chat (burbuja y grande) los dibuja como
 * tarjetitas pidiendo ch_refs_resolve(): el título SOLO se devuelve si quien LEE puede ver ese elemento; si no, "Sin acceso".
 * Así un mensaje nunca muestra datos de algo que el lector no puede abrir. Doc: toolz/CHAT_VINCULOS.md
 */

const CH_REF_KINDS = [
    'cliente'   => ['label' => 'Cliente',   'icon' => 'fa-address-book', 'color' => '#3b82f6'],
    'contenido' => ['label' => 'Contenido', 'icon' => 'fa-layer-group',  'color' => '#a78bfa'],
    'tarea'     => ['label' => 'Tarea',     'icon' => 'fa-list-check',   'color' => '#22c55e'],
    'ticket'    => ['label' => 'Ticket',    'icon' => 'fa-ticket',       'color' => '#f97316'],
    // Jira (2026-11-11): [[jira:DIS-12]]. El id es la clave del ticket. Solo si Admin › Jira API › Usar Jira en Cronos tiene Chat prendido
    'jira'      => ['label' => 'Jira',      'icon' => 'fa-jira',         'color' => '#2684ff'],
];
const CH_REF_JIRA_KEY = '[A-Z][A-Z0-9_]{0,19}-\d{1,9}';

/** ¿Jira está prendido para el chat? (jira_use_lib.php) */
function ch_ref_jira_on(): bool {
    static $on = null;
    if ($on === null) { $f = dirname(__DIR__) . '/jira_use_lib.php'; $on = is_file($f) && (require_once $f) && function_exists('g7_jira_on') && g7_jira_on('chat'); }
    return $on;
}
/** Tarjetita de un ticket de Jira (issue de g7_jira_cached / g7_jira_find). */
function ch_ref_jira_card(array $i): array {
    return ['kind' => 'jira', 'id' => $i['key'], 'ok' => true] + CH_REF_KINDS['jira'] + ['title' => $i['summary'], 'sub' => trim($i['status'] . ($i['assignee'] ? ' · ' . $i['assignee'] : '')),
        'href' => $i['url'], 'code' => $i['key'], 'ext' => true];
}

/** ¿Qué tipos puede vincular/ver esta persona? (según los módulos y permisos del sitio) */
function ch_ref_kinds(int $uid): array {
    $can = fn(string $m) => !function_exists('g7_can') || g7_can($uid, $m);
    $has = fn(string $f) => !function_exists('g7_site_has') || g7_site_has($f);
    $k = [];
    if ($has('clientes') && $can('clientes')) $k[] = 'cliente';
    if ($can('promos')) $k[] = 'contenido';
    if ($can('tareas') && is_file(dirname(__DIR__) . '/trabajo/lib.php')) { $k[] = 'tarea'; $k[] = 'ticket'; }
    if (ch_ref_jira_on()) $k[] = 'jira';
    return $k;
}

/** Arma las condiciones SQL de visibilidad de cada tipo para esta persona (null = no puede ver ninguno). */
function ch_ref_scope(PDO $db, int $uid, string $kind): ?string {
    static $cache = [];
    if (array_key_exists("$uid:$kind", $cache)) return $cache["$uid:$kind"];
    $w = null;
    if (in_array($kind, ch_ref_kinds($uid), true)) {
        if ($kind === 'cliente') $w = '1';
        elseif ($kind === 'contenido') $w = function_exists('g7_brand_sql') && function_exists('g7_brand_scope') ? g7_brand_sql("CONCAT_WS(' ', c.project, c.projects)", g7_brand_scope($uid)) : '1';
        else {
            require_once dirname(__DIR__) . '/trabajo/lib.php';
            wk_install($db);
            $ctx = wk_ctx($db, $uid);
            if ($kind === 'tarea') $w = wk_task_where($db, $ctx, 'c');
            else $w = $ctx['all'] ? '1' : '(c.requester_id = ' . $uid . ' OR c.assignee_id = ' . $uid . ($ctx['teams'] ? ' OR c.team_id IN (' . implode(',', array_map('intval', $ctx['teams'])) . ')' : '') . ')';
        }
    }
    return $cache["$uid:$kind"] = $w;
}

/** Nombre legible de un estado: el del tablero para tareas (tk_config) y uno fijo para tickets. */
function ch_ref_status(string $kind, string $st): string {
    static $tk = null;
    if ($kind === 'tarea') {
        if ($tk === null) { $tk = []; try { if (!function_exists('tk_config')) require_once dirname(__DIR__) . '/tareas_lib.php'; foreach (tk_config(getDB())['statuses'] as $s) $tk[$s['id']] = $s['name']; } catch (Throwable $e) {} }
        if (isset($tk[$st])) return $tk[$st];
    }
    $fixed = ['abierto' => 'Abierto', 'en_curso' => 'En curso', 'esperando' => 'Esperando', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];
    return $fixed[$st] ?? ($st === '' ? '' : mb_strtoupper(mb_substr(str_replace('_', ' ', $st), 0, 1)) . mb_substr(str_replace('_', ' ', $st), 1));
}

/** Fila → lo que dibuja la tarjetita. */
function ch_ref_card(string $kind, array $r): array {
    if (isset($r['status'])) $r['status'] = ch_ref_status($kind, (string)$r['status']);
    $base = ['kind' => $kind, 'id' => (int)$r['id'], 'ok' => true] + CH_REF_KINDS[$kind];
    switch ($kind) {
        case 'cliente': return $base + ['title' => (string)$r['name'], 'sub' => (string)($r['status'] ?? ''), 'href' => '/toolz/clientes.php?id=' . (int)$r['id']];
        case 'contenido':
            $sub = trim(($r['type'] ?? '') . ($r['project'] ? ' · ' . $r['project'] : '') . ($r['end_date'] ? ' · hasta ' . date('d/m', strtotime($r['end_date'])) : ''));
            return $base + ['title' => (string)$r['name'], 'sub' => $sub, 'href' => '/toolz/promos/'];
        case 'tarea':
            return $base + ['title' => (string)$r['title'], 'sub' => (string)($r['status'] ?? '') . ($r['due_date'] ? ' · vence ' . date('d/m', strtotime($r['due_date'])) : ''),
                'href' => '/toolz/tareas.php?' . ($r['board_id'] ? 'b=' . (int)$r['board_id'] . '&' : '') . 't=' . (int)$r['id'], 'code' => '#' . (int)$r['id']];
        case 'ticket':
            return $base + ['title' => (string)$r['subject'], 'sub' => (string)($r['status'] ?? ''), 'href' => '/toolz/trabajo/#tickets/' . (int)$r['id'],
                'code' => 'T-' . str_pad((string)(int)$r['id'], 4, '0', STR_PAD_LEFT)];
    }
    return $base;
}

function ch_ref_sql(string $kind): string {
    return [
        'cliente'   => 'SELECT c.id, c.name, c.status FROM g7_clients c',
        'contenido' => 'SELECT c.id, c.name, c.type, c.project, c.end_date FROM g7_content c',
        'tarea'     => 'SELECT c.id, c.title, c.status, c.due_date, c.board_id FROM g7_tasks c',
        'ticket'    => 'SELECT c.id, c.subject, c.status FROM g7_wk_tickets c',
    ][$kind];
}

/**
 * Resuelve una lista de "tipo:id" para quien lee. Devuelve un mapa "tipo:id" => tarjetita.
 * Lo que no existe o no puede ver vuelve como ['ok' => false] (sin título).
 */
function ch_refs_resolve(PDO $db, int $uid, array $refs): array {
    $want = [];
    $jira = [];
    foreach (array_slice($refs, 0, 60) as $r) {
        if (!is_string($r)) continue;
        if (preg_match('/^(cliente|contenido|tarea|ticket):(\d{1,9})$/', $r, $m)) $want[$m[1]][(int)$m[2]] = true;
        elseif (preg_match('/^jira:(' . CH_REF_JIRA_KEY . ')$/', $r, $m)) $jira[$m[1]] = true;
    }
    $out = [];
    // Jira: título y estado de la caché corta (g7_jira_cached). Con Jira apagado para el chat, "sin acceso"
    if ($jira) {
        foreach (array_keys($jira) as $k) $out["jira:$k"] = ['kind' => 'jira', 'id' => $k, 'ok' => false] + CH_REF_KINDS['jira'];
        if (ch_ref_jira_on() && in_array('jira', ch_ref_kinds($uid), true))
            foreach (g7_jira_cached($db, array_keys($jira)) as $k => $i) if ($i) $out["jira:$k"] = ch_ref_jira_card($i);
    }
    foreach ($want as $kind => $ids) {
        $ids = array_keys($ids);
        foreach ($ids as $id) $out["$kind:$id"] = ['kind' => $kind, 'id' => $id, 'ok' => false] + CH_REF_KINDS[$kind];
        $w = ch_ref_scope($db, $uid, $kind);
        if ($w === null) continue;
        try {
            $rows = $db->query(ch_ref_sql($kind) . ' WHERE c.id IN (' . implode(',', $ids) . ") AND ($w)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $out["$kind:" . (int)$r['id']] = ch_ref_card($kind, $r);
        } catch (Throwable $e) {}
    }
    return $out;
}

/** Busca para el selector "Vincular": por nombre/título o por número (#45, T-0003). Solo lo que esta persona puede ver. */
function ch_refs_search(PDO $db, int $uid, string $q, string $kind = ''): array {
    $q = trim(mb_substr($q, 0, 80));
    $kinds = $kind !== '' ? array_intersect([$kind], ch_ref_kinds($uid)) : ch_ref_kinds($uid);
    $num = preg_match('/^(?:#|t-?)?0*(\d{1,9})$/i', $q, $m) ? (int)$m[1] : 0;
    $col = ['cliente' => 'c.name', 'contenido' => 'c.name', 'tarea' => 'c.title', 'ticket' => 'c.subject'];
    $order = ['cliente' => 'c.name', 'contenido' => 'c.id DESC', 'tarea' => 'c.id DESC', 'ticket' => 'c.id DESC'];
    $out = [];
    foreach ($kinds as $k) {
        if ($k === 'jira') {   // en los proyectos elegidos; si Jira no responde, simplemente no aparece
            try { foreach (g7_jira_find(['q' => $q, 'max' => 6]) as $i) $out[] = ch_ref_jira_card($i); } catch (Throwable $e) {}
            continue;
        }
        $w = ch_ref_scope($db, $uid, $k);
        if ($w === null) continue;
        $cond = $q === '' ? '1' : ($num ? 'c.id = ' . $num : $col[$k] . ' LIKE ' . $db->quote('%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'));
        if ($k === 'tarea') $cond .= ' AND c.archived = 0';
        try {
            foreach ($db->query(ch_ref_sql($k) . " WHERE $cond AND ($w) ORDER BY {$order[$k]} LIMIT 6")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ch_ref_card($k, $r);
        } catch (Throwable $e) {}
    }
    return $out;
}
