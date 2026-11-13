<?php
/**
 * calendario/api.php — Datos del calendario personal (JSON). Cada persona ve y toca solo sus eventos.
 * Acciones: list (from, to), save, delete. Doc: toolz/CALENDARIO.md
 */
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$me = getCurrentUser();
if (!$me) { http_response_code(401); echo '{"error":"La sesión venció: recarga la página."}'; exit; }
$uid = (int)$me['id'];
if (function_exists('g7_can') && !g7_can($uid, 'calendario')) { http_response_code(403); echo '{"error":"No tienes acceso al calendario."}'; exit; }
$db = getDB();
require_once __DIR__ . '/lib.php';
cal_install($db);
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? '');
function cal_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function cal_date($v): ?string { $v = (string)$v; $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v ? $v : null; }
function cal_time($v): ?string { $v = (string)$v; return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null; }
// El calendario se ve y se edita en la zona de quien mira (Mi perfil › Ubicación). ZONAS_HORARIAS.md
$viewTz = new DateTimeZone(g7_tz_user($uid));

if ($act === 'list') {
    $f = cal_date($in['from'] ?? ''); $t = cal_date($in['to'] ?? '');
    if (!$f || !$t || $t <= $f) cal_out(['error' => 'Rango inválido.'], 400);
    $from = new DateTimeImmutable($f, $viewTz); $to = new DateTimeImmutable($t, $viewTz);
    if ($to->getTimestamp() - $from->getTimestamp() > 100 * 86400) cal_out(['error' => 'El rango es demasiado largo.'], 400);
    cal_out(['events' => cal_events($db, $uid, $from, $to, $viewTz), 'tasks' => !empty($in['tasks']) ? cal_tasks($db, $uid, $from, $to) : []]);
}

if ($act === 'save') {
    $id = (int)($in['id'] ?? 0);
    $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 200));
    if ($title === '') cal_out(['error' => 'Pon un título.'], 400);
    $d1 = cal_date($in['date'] ?? ''); $d2 = cal_date($in['end_date'] ?? '') ?: $d1;
    if (!$d1) cal_out(['error' => 'Elige la fecha.'], 400);
    if ($d2 < $d1) cal_out(['error' => 'El final no puede ser antes del comienzo.'], 400);
    $allDay = !empty($in['all_day']);
    if ($allDay) { $start = "$d1 00:00:00"; $end = "$d2 23:59:59"; }
    else {
        $t1 = cal_time($in['start_time'] ?? '') ?: '09:00'; $t2 = cal_time($in['end_time'] ?? '');
        $start = "$d1 $t1:00"; $end = $t2 ? "$d2 $t2:00" : (new DateTimeImmutable($start, $viewTz))->modify('+1 hour')->format('Y-m-d H:i:s');
        if ($end <= $start) cal_out(['error' => 'La hora de fin tiene que ser después de la de inicio.'], 400);
    }
    if (strtotime($end) - strtotime($start) > 31 * 86400) cal_out(['error' => 'Un evento puede durar hasta 31 días.'], 400);
    $color = in_array($in['color'] ?? '', CAL_COLORS, true) ? $in['color'] : '';
    $rule = (string)($in['repeat_rule'] ?? ''); if (!array_key_exists($rule, CAL_REPEATS)) $rule = '';
    $until = $rule !== '' ? cal_date($in['repeat_until'] ?? '') : null;
    if ($until && $until < $d1) cal_out(['error' => 'La repetición tiene que terminar después del primer día.'], 400);
    $remind = ($in['remind_min'] ?? '') === '' || $in['remind_min'] === null ? null : (int)$in['remind_min'];
    if ($remind !== null && !in_array($remind, [0, 5, 10, 15, 30, 60, 120, 1440, 2880, 10080], true)) $remind = 30;
    // Se guarda la hora tal como la escribió la persona y su zona (tz): así las repeticiones respetan su horario de verano
    $row = [$title, trim(mb_substr((string)($in['notes'] ?? ''), 0, 5000)), trim(mb_substr((string)($in['location'] ?? ''), 0, 200)), $start, $end, $allDay ? 1 : 0, $color, $rule, $until, $remind, $viewTz->getName()];
    if ($id) {
        $st = $db->prepare('UPDATE g7_cal_events SET title = ?, notes = ?, location = ?, start_at = ?, end_at = ?, all_day = ?, color = ?, repeat_rule = ?, repeat_until = ?, remind_min = ?, tz = ?, reminded_at = NOW() WHERE id = ? AND user_id = ?');
        $st->execute([...$row, $id, $uid]);
        if (!$st->rowCount() && !$db->query('SELECT 1 FROM g7_cal_events WHERE id = ' . $id . ' AND user_id = ' . $uid)->fetchColumn()) cal_out(['error' => 'Ese evento ya no existe.'], 404);
    } else {
        $db->prepare('INSERT INTO g7_cal_events (title, notes, location, start_at, end_at, all_day, color, repeat_rule, repeat_until, remind_min, tz, reminded_at, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)')->execute([...$row, $uid]);
        $id = (int)$db->lastInsertId();
    }
    cal_out(['ok' => true, 'id' => $id]);
}

if ($act === 'get') {   // un evento suelto (para abrirlo desde un aviso)
    $st = $db->prepare('SELECT * FROM g7_cal_events WHERE id = ? AND user_id = ?'); $st->execute([(int)($in['id'] ?? 0), $uid]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) cal_out(['error' => 'Ese evento ya no existe.'], 404);
    $ez = cal_tz($e);
    cal_out(['event' => cal_event_out($e, new DateTimeImmutable($e['start_at'], $ez), new DateTimeImmutable($e['end_at'], $ez), $viewTz)]);
}

if ($act === 'delete') {
    $st = $db->prepare('DELETE FROM g7_cal_events WHERE id = ? AND user_id = ?'); $st->execute([(int)($in['id'] ?? 0), $uid]);
    cal_out(['ok' => true, 'deleted' => $st->rowCount()]);
}

cal_out(['error' => 'Acción desconocida.'], 400);
