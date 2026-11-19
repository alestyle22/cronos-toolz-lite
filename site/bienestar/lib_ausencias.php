<?php
/**
 * bienestar/lib_ausencias.php — Vacaciones y licencias: días disponibles, pedidos, aprobación y calendario de ausencias.
 * Cronos ya tenía el calendario de ausencias del Portal de Solicitudes (g7_wk_dayoff): con él Trabajo descuenta la capacidad
 * de quien no está. Bienestar suma lo que faltaba (el pedido y su aprobación) y, al aprobar, anota la ausencia ahí mismo
 * y recalcula los departamentos. No hay información médica: los tipos son genéricos y se avisa que no se escriba.
 * Aprueban: un admin del sitio, o quien gestiona Bienestar en un equipo de la persona (nunca uno mismo). Doc: toolz/BIENESTAR.md
 */
const BN_LEAVE_TYPES = [
    'vacaciones' => ['label' => 'Vacaciones',       'icon' => 'fa-umbrella-beach', 'color' => '#06b6d4', 'counts' => true],
    'personal'   => ['label' => 'Día personal',     'icon' => 'fa-mug-hot',        'color' => '#a855f7', 'counts' => false],
    'estudio'    => ['label' => 'Examen o estudio', 'icon' => 'fa-graduation-cap', 'color' => '#3b82f6', 'counts' => false],
    'mudanza'    => ['label' => 'Mudanza',          'icon' => 'fa-truck-moving',   'color' => '#f59e0b', 'counts' => false],
    'familiar'   => ['label' => 'Cuidado familiar', 'icon' => 'fa-people-roof',    'color' => '#ec4899', 'counts' => false],
    'otra'       => ['label' => 'Otra licencia',    'icon' => 'fa-calendar-minus', 'color' => '#64748b', 'counts' => false],
];
const BN_LEAVE_STATUS = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', 'cancelled' => 'Cancelada'];

function bn_leave_types(): array {
    $on = json_decode((string)bn_cfg('bn_leave_types', ''), true);
    $out = [];
    foreach (BN_LEAVE_TYPES as $k => $t) if (!is_array($on) || in_array($k, $on, true) || $k === 'vacaciones') $out[$k] = $t;
    return $out;
}
function bn_dayoff_ok(PDO $db): bool {
    static $ok = null;
    if ($ok === null) { try { $db->query('SELECT 1 FROM g7_wk_dayoff LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
/** Feriados de todos (cargados en Solicitudes › Disponibilidad) entre dos fechas. */
function bn_holidays(PDO $db, string $from, string $to): array {
    if (!bn_dayoff_ok($db)) return [];
    $q = $db->prepare("SELECT date_from, date_to, note FROM g7_wk_dayoff WHERE kind = 'feriado' AND team_id IS NULL AND user_id IS NULL AND date_from <= ? AND date_to >= ?");
    $q->execute([$to, $from]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) for ($d = max($r['date_from'], $from); $d <= min($r['date_to'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) $out[$d] = $r['note'] ?: 'Feriado';
    return $out;
}
/** Días hábiles (lunes a viernes, sin feriados) entre dos fechas. */
function bn_workdays(PDO $db, string $from, string $to): int {
    $h = bn_holidays($db, $from, $to); $n = 0;
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) if ((int)date('N', strtotime($d)) <= 5 && !isset($h[$d])) $n++;
    return $n;
}
function bn_leave_balance(PDO $db, int $uid, int $year): array {
    $q = $db->prepare('SELECT days FROM g7_bn_leave_balance WHERE user_id = ? AND year = ?'); $q->execute([$uid, $year]);
    $v = $q->fetchColumn();
    $total = $v === false ? bn_vac_days() : (int)$v;
    $q = $db->prepare("SELECT status, COALESCE(SUM(days), 0) d FROM g7_bn_leave WHERE user_id = ? AND type = 'vacaciones' AND YEAR(date_from) = ? AND status IN ('approved', 'pending') GROUP BY status");
    $q->execute([$uid, $year]);
    $s = ['approved' => 0, 'pending' => 0]; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $s[$r['status']] = (int)$r['d'];
    return ['year' => $year, 'total' => $total, 'used' => $s['approved'], 'pending' => $s['pending'], 'available' => max(0, $total - $s['approved'] - $s['pending']), 'custom' => $v !== false];
}
function bn_leave_row(PDO $db, int $id): array {
    $q = $db->prepare('SELECT l.*, u.username, u.display_name, u.avatar FROM g7_bn_leave l JOIN g7_users u ON u.id = l.user_id WHERE l.id = ?'); $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) bn_fail('Ese pedido no está.', 404);
    return $r;
}
function bn_leave_view(array $r, bool $full = true): array {
    $t = BN_LEAVE_TYPES[$r['type']] ?? BN_LEAVE_TYPES['otra'];
    return ['id' => (int)$r['id'], 'user' => ['id' => (int)$r['user_id'], 'name' => (string)(($r['display_name'] ?? '') ?: ($r['username'] ?? '')), 'avatar' => (string)($r['avatar'] ?? '')],
        'type' => $r['type'], 'type_label' => $t['label'], 'icon' => $t['icon'], 'color' => $t['color'], 'date_from' => $r['date_from'], 'date_to' => $r['date_to'], 'days' => (int)$r['days'],
        'note' => $full ? $r['note'] : '', 'status' => $r['status'], 'status_label' => BN_LEAVE_STATUS[$r['status']] ?? $r['status'],
        'decision_note' => $full ? $r['decision_note'] : '', 'decided_at' => $r['decided_at'], 'created_at' => $r['created_at']];
}
/** ¿$ctx puede aprobar los pedidos de $uid? Admin del sitio, o quien gestiona un equipo de la persona. Nunca los propios. */
function bn_leave_can_decide(PDO $db, array $ctx, int $uid): bool {
    if ($uid === $ctx['uid'] || !$ctx['manage']) return false;
    if ($ctx['scope'] === null) return true;
    $q = $db->prepare('SELECT team_id FROM g7_team_members WHERE user_id = ?'); $q->execute([$uid]);
    return (bool)array_intersect(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), $ctx['scope']);
}
/** A quiénes avisar de un pedido: admins del sitio y quienes gestionan Bienestar en algún equipo de la persona. */
function bn_leave_approvers(PDO $db, int $uid): array {
    $ids = array_map('intval', $db->query("SELECT id FROM g7_users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN));
    $q = $db->prepare('SELECT DISTINCT m2.user_id FROM g7_team_members m JOIN g7_team_members m2 ON m2.team_id = m.team_id JOIN g7_users u ON u.id = m2.user_id AND u.active = 1 WHERE m.user_id = ?');
    $q->execute([$uid]);
    foreach (array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) as $c) if (!in_array($c, $ids, true) && $c !== $uid) {
        try { if (bn_leave_can_decide($db, bn_ctx($db, $c), $uid)) $ids[] = $c; } catch (Throwable $e) {}
    }
    return array_values(array_diff($ids, [$uid]));
}
function bn_leave_request(PDO $db, array $ctx, array $in, ?int $now = null): int {
    $types = bn_leave_types();
    $type = (string)($in['type'] ?? '');
    if (!isset($types[$type])) bn_fail('Elige el tipo de ausencia.');
    $f = bn_date($in['date_from'] ?? ''); $t = bn_date($in['date_to'] ?? '') ?: $f;
    if (!$f) bn_fail('Elige desde qué día.');
    if ($t < $f) bn_fail('El último día no puede ser antes del primero.');
    $today = bn_today($now);
    if ($f < date('Y-m-d', strtotime("$today -30 days"))) bn_fail('Solo puedes cargar ausencias de los últimos 30 días.');
    if ((strtotime($t) - strtotime($f)) / 86400 >= 60) bn_fail('Un pedido puede abarcar hasta 60 días. Para algo más largo, habla con RR. HH.');
    $days = bn_workdays($db, $f, $t);
    if ($days < 1) bn_fail('En esas fechas no hay días hábiles.');
    $q = $db->prepare("SELECT COUNT(*) FROM g7_bn_leave WHERE user_id = ? AND status IN ('pending', 'approved') AND date_from <= ? AND date_to >= ?");
    $q->execute([$ctx['uid'], $t, $f]);
    if ((int)$q->fetchColumn()) bn_fail('Ya tienes un pedido en esas fechas.');
    if ($types[$type]['counts']) {
        $b = bn_leave_balance($db, $ctx['uid'], (int)substr($f, 0, 4));
        if ($days > $b['available']) bn_fail('Te ' . ($b['available'] === 1 ? 'queda 1 día' : 'quedan ' . $b['available'] . ' días') . ' de vacaciones en ' . $b['year'] . ' y el pedido es de ' . $days . '.');
    }
    $note = bn_str($in['note'] ?? '', 200, 0, 'El comentario');
    $db->prepare('INSERT INTO g7_bn_leave (user_id, type, date_from, date_to, days, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$ctx['uid'], $type, $f, $t, $days, $note, date('Y-m-d H:i:s', bn_now($now))]);
    $id = (int)$db->lastInsertId();
    bn_notify($db, bn_leave_approvers($db, $ctx['uid']), $ctx['uid'], 'bn_leave', $id, 'Pidió ' . mb_strtolower($types[$type]['label']) . ': ' . date('d/m', strtotime($f)) . ($t !== $f ? ' al ' . date('d/m', strtotime($t)) : '') . " ($days " . ($days === 1 ? 'día' : 'días') . ')', 'leave');
    return $id;
}
/** Anota la ausencia aprobada en el calendario de Trabajo y recalcula los departamentos de la persona. */
function bn_leave_to_dayoff(PDO $db, array $r, int $by): ?int {
    if (!bn_dayoff_ok($db)) return null;
    $kind = $r['type'] === 'vacaciones' ? 'vacaciones' : 'ausencia';
    $db->prepare('INSERT INTO g7_wk_dayoff (team_id, user_id, date_from, date_to, kind, minutes, note, created_by, created_at) VALUES (NULL, ?, ?, ?, ?, NULL, ?, ?, ?)')
       ->execute([(int)$r['user_id'], $r['date_from'], $r['date_to'], $kind, 'Bienestar · ' . (BN_LEAVE_TYPES[$r['type']]['label'] ?? 'Ausencia'), $by, date('Y-m-d H:i:s')]);
    $id = (int)$db->lastInsertId();
    bn_leave_recalc($db, (int)$r['user_id']);
    return $id;
}
function bn_leave_recalc(PDO $db, int $uid): void {
    try {
        $f = dirname(__DIR__) . '/trabajo/lib.php';   // trae tareas_lib.php y portal_lib.php (pt_recalc_team)
        if (!is_file($f)) return;
        if (!function_exists('pt_recalc_team')) require_once $f;
        $q = $db->prepare('SELECT team_id FROM g7_team_members WHERE user_id = ?'); $q->execute([$uid]);
        foreach (array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)) as $t) pt_recalc_team($db, $t);
    } catch (Throwable $e) {}   // sin Solicitudes en este sitio (o sin tickets) no hay nada que recalcular
}
function bn_leave_decide(PDO $db, array $ctx, int $id, bool $approve, string $note = '', ?int $now = null): void {
    $r = bn_leave_row($db, $id);
    if (!bn_leave_can_decide($db, $ctx, (int)$r['user_id'])) bn_fail((int)$r['user_id'] === $ctx['uid'] ? 'No puedes aprobar tus propios pedidos.' : 'No puedes decidir sobre ese pedido.', 403);
    if ($r['status'] !== 'pending') bn_fail('Ese pedido ya se resolvió.');
    $note = bn_str($note, 200, 0, 'El comentario');
    if ($approve && $r['type'] === 'vacaciones') {
        $b = bn_leave_balance($db, (int)$r['user_id'], (int)substr($r['date_from'], 0, 4));
        if ($b['used'] + (int)$r['days'] > $b['total']) bn_fail('No le alcanzan los días de vacaciones. Ajusta su saldo en Admin › Bienestar o rechaza el pedido.');
    }
    $up = $db->prepare("UPDATE g7_bn_leave SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND status = 'pending'");
    $up->execute([$approve ? 'approved' : 'rejected', $ctx['uid'], date('Y-m-d H:i:s', bn_now($now)), $note, $id]);
    if (!$up->rowCount()) bn_fail('Ese pedido ya se resolvió.');
    if ($approve && ($d = bn_leave_to_dayoff($db, $r, $ctx['uid']))) $db->prepare('UPDATE g7_bn_leave SET dayoff_id = ? WHERE id = ?')->execute([$d, $id]);
    $lbl = BN_LEAVE_TYPES[$r['type']]['label'] ?? 'Ausencia';
    bn_notify($db, [(int)$r['user_id']], $ctx['uid'], 'bn_leave', $id, ($approve ? 'Aprobó' : 'Rechazó') . ' tu pedido de ' . mb_strtolower($lbl) . ' del ' . date('d/m', strtotime($r['date_from'])) . ($note !== '' ? ": $note" : '.'), 'leave');
    bn_audit($ctx, $approve ? 'leave_approve' : 'leave_reject', (string)$id, ['user' => (int)$r['user_id'], 'type' => $r['type'], 'from' => $r['date_from'], 'to' => $r['date_to']]);
}
/** La persona cancela su pedido: pendiente, o aprobado que todavía no empezó (se saca del calendario de Trabajo). */
function bn_leave_cancel(PDO $db, array $ctx, int $id, ?int $now = null): void {
    $r = bn_leave_row($db, $id);
    if ((int)$r['user_id'] !== $ctx['uid']) bn_fail('Ese pedido no está.', 404);
    if (!in_array($r['status'], ['pending', 'approved'], true)) bn_fail('Ese pedido ya no se puede cancelar.');
    if ($r['status'] === 'approved' && $r['date_from'] <= bn_today($now)) bn_fail('La ausencia ya empezó: para cambiarla, habla con quien la aprobó.');
    $db->prepare("UPDATE g7_bn_leave SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    if ($r['dayoff_id'] && bn_dayoff_ok($db)) { $db->prepare('DELETE FROM g7_wk_dayoff WHERE id = ?')->execute([(int)$r['dayoff_id']]); bn_leave_recalc($db, $ctx['uid']); }
    if ($r['status'] === 'approved' && $r['decided_by']) bn_notify($db, [(int)$r['decided_by']], $ctx['uid'], 'bn_leave', $id, 'Canceló su ' . mb_strtolower(BN_LEAVE_TYPES[$r['type']]['label'] ?? 'ausencia') . ' del ' . date('d/m', strtotime($r['date_from'])) . '.', 'leave');
}
function bn_leave_mine(PDO $db, array $ctx, ?int $now = null): array {
    $q = $db->prepare('SELECT l.*, u.username, u.display_name, u.avatar FROM g7_bn_leave l JOIN g7_users u ON u.id = l.user_id WHERE l.user_id = ? ORDER BY l.date_from DESC, l.id DESC LIMIT 60');
    $q->execute([$ctx['uid']]);
    $y = (int)date('Y', bn_now($now));
    return ['items' => array_map('bn_leave_view', $q->fetchAll(PDO::FETCH_ASSOC)), 'balance' => bn_leave_balance($db, $ctx['uid'], $y), 'next_balance' => bn_leave_balance($db, $ctx['uid'], $y + 1),
        'types' => bn_leave_types(), 'today' => bn_today($now)];
}
function bn_leave_to_decide(PDO $db, array $ctx): array {
    if (!$ctx['manage']) return [];
    $rows = $db->query("SELECT l.*, u.username, u.display_name, u.avatar FROM g7_bn_leave l JOIN g7_users u ON u.id = l.user_id WHERE l.status = 'pending' ORDER BY l.date_from LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) if (bn_leave_can_decide($db, $ctx, (int)$r['user_id'])) {
        $v = bn_leave_view($r);
        if ($r['type'] === 'vacaciones') $v['balance'] = bn_leave_balance($db, (int)$r['user_id'], (int)substr($r['date_from'], 0, 4));
        $out[] = $v;
    }
    return $out;
}
/**
 * Calendario de ausencias de un mes: las mías, las de mis compañeros de equipo y (si gestiono) las de mi alcance.
 * A los compañeros se les muestra "Vacaciones" o "Ausente", nunca el tipo de licencia ni el comentario.
 */
function bn_leave_calendar(PDO $db, array $ctx, string $month): array {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) bn_fail('Mes inválido.');
    $from = "$month-01"; $to = date('Y-m-t', strtotime($from));
    $q = $db->prepare("SELECT l.*, u.username, u.display_name, u.avatar FROM g7_bn_leave l JOIN g7_users u ON u.id = l.user_id AND u.active = 1
        WHERE l.date_from <= ? AND l.date_to >= ? AND (l.status = 'approved' OR (l.status = 'pending' AND l.user_id = ?)) ORDER BY l.date_from");
    $q->execute([$to, $from, $ctx['uid']]);
    $mates = []; if ($ctx['teams']) $mates = array_flip(bn_users_of($db, $ctx['teams']));
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int)$r['user_id']; $self = $u === $ctx['uid'];
        $full = $self || bn_leave_can_decide($db, $ctx, $u);
        if (!$self && !$full && !isset($mates[$u])) continue;
        $v = bn_leave_view($r, $self);
        if (!$full) { $v['type'] = $r['type'] === 'vacaciones' ? 'vacaciones' : 'ausente'; $v['type_label'] = $r['type'] === 'vacaciones' ? 'Vacaciones' : 'Ausente'; $v['icon'] = $r['type'] === 'vacaciones' ? 'fa-umbrella-beach' : 'fa-user-clock'; $v['color'] = $r['type'] === 'vacaciones' ? '#06b6d4' : '#64748b'; $v['days'] = 0; }
        $v['self'] = $self;
        $out[] = $v;
    }
    return ['month' => $month, 'items' => $out, 'holidays' => bn_holidays($db, $from, $to)];
}
function bn_leave_summary(PDO $db, int $uid, ?int $now = null): array {
    $today = bn_today($now);
    $q = $db->prepare("SELECT * FROM g7_bn_leave WHERE user_id = ? AND status = 'approved' AND date_to >= ? ORDER BY date_from LIMIT 1"); $q->execute([$uid, $today]);
    $next = $q->fetch(PDO::FETCH_ASSOC);
    $q = $db->prepare("SELECT COUNT(*) FROM g7_bn_leave WHERE user_id = ? AND status = 'pending'"); $q->execute([$uid]);
    return ['balance' => bn_leave_balance($db, $uid, (int)date('Y', bn_now($now))), 'pending' => (int)$q->fetchColumn(), 'next' => $next ? bn_leave_view($next + ['username' => '', 'display_name' => '', 'avatar' => '']) : null];
}
// ── Admin › Bienestar: saldos ──
function bn_leave_balances(PDO $db, array $ctx, int $year): array {
    bn_need_admin($ctx);
    $out = [];
    foreach ($db->query('SELECT id, username, display_name, avatar FROM g7_users WHERE active = 1 ORDER BY COALESCE(NULLIF(display_name, \'\'), username)')->fetchAll(PDO::FETCH_ASSOC) as $u)
        $out[] = ['id' => (int)$u['id'], 'name' => (string)($u['display_name'] ?: $u['username']), 'avatar' => (string)$u['avatar']] + bn_leave_balance($db, (int)$u['id'], $year);
    return $out;
}
function bn_leave_balance_set(PDO $db, array $ctx, int $uid, int $year, ?int $days): void {
    bn_need_admin($ctx);
    if ($year < 2000 || $year > 2100) bn_fail('Año inválido.');
    $q = $db->prepare('SELECT id FROM g7_users WHERE id = ?'); $q->execute([$uid]);
    if (!$q->fetchColumn()) bn_fail('Esa persona no está.', 404);
    if ($days === null) $db->prepare('DELETE FROM g7_bn_leave_balance WHERE user_id = ? AND year = ?')->execute([$uid, $year]);
    else {
        if ($days < 0 || $days > 365) bn_fail('Pon entre 0 y 365 días.');
        $db->prepare('INSERT INTO g7_bn_leave_balance (user_id, year, days, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE days = VALUES(days), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)')->execute([$uid, $year, $days, $ctx['uid']]);
    }
    bn_audit($ctx, 'leave_balance', (string)$uid, ['year' => $year, 'days' => $days]);
}
