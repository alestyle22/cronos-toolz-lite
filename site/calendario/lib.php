<?php
/**
 * calendario/lib.php — Calendario personal: eventos privados de cada persona (con repetición y aviso) y sus tareas con fecha.
 * Tabla g7_cal_events (se crea sola). Los avisos van a la campana (g7_notifications tipo cal_remind) y los dispara notif.php.
 */
const CAL_REPEATS = ['' => 'No se repite', 'daily' => 'Todos los días', 'weekly' => 'Todas las semanas', 'monthly' => 'Todos los meses', 'yearly' => 'Todos los años'];
const CAL_COLORS = ['#ff6a00', '#3b82f6', '#22c55e', '#a855f7', '#ef4444', '#eab308', '#06b6d4', '#ec4899'];

function cal_install(PDO $db): void {
    if (function_exists('g7_cfg') && g7_cfg('cal_schema') === '2') return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_cal_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        notes TEXT,
        location VARCHAR(200) NOT NULL DEFAULT '',
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        all_day TINYINT(1) NOT NULL DEFAULT 0,
        color VARCHAR(9) NOT NULL DEFAULT '',
        repeat_rule VARCHAR(10) NOT NULL DEFAULT '',
        repeat_until DATE NULL,
        remind_min INT NULL,
        reminded_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user_start (user_id, start_at),
        KEY idx_remind (remind_min, reminded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Zona del evento (ZONAS_HORARIAS.md): start_at/end_at son la hora de pared en esa zona, como en iCalendar.
    // Vacía = eventos de antes: están en la zona de almacenamiento.
    try { $db->exec("ALTER TABLE g7_cal_events ADD COLUMN tz VARCHAR(64) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    if (function_exists('g7_cfg_set')) g7_cfg_set('cal_schema', '2');
}

/** Zona de un evento: la de quien lo creó (columna tz) o, en los de antes, la de almacenamiento. */
function cal_tz(array $e): DateTimeZone {
    $z = (string)($e['tz'] ?? '');
    return new DateTimeZone(function_exists('g7_tz_valid') && g7_tz_valid($z) ? $z : date_default_timezone_get());
}

/**
 * Un inicio o fin para mostrar a quien mira: los eventos con hora se pasan a su zona; los de todo el día quedan
 * con su fecha (un cumpleaños no se corre de día por estar en otro país).
 */
function cal_view(DateTimeImmutable $d, bool $allDay, ?DateTimeZone $view): string {
    return ($allDay || !$view ? $d : $d->setTimezone($view))->format('Y-m-d H:i:s');
}

/** Ocurrencias de un evento que tocan el rango [$from, $to). Devuelve pares [inicio, fin] como DateTimeImmutable (en la zona del evento). */
function cal_occurrences(array $e, DateTimeImmutable $from, DateTimeImmutable $to, int $max = 400): array {
    // Las repeticiones se calculan en la zona del evento: "todos los lunes a las 10" sigue a las 10 aunque cambie el horario de verano
    $ez = cal_tz($e);
    $s = new DateTimeImmutable($e['start_at'], $ez); $en = new DateTimeImmutable($e['end_at'], $ez); $len = $en->getTimestamp() - $s->getTimestamp();
    $rule = (string)$e['repeat_rule'];
    $until = $e['repeat_until'] ? (new DateTimeImmutable($e['repeat_until'], $ez))->setTime(23, 59, 59) : null;
    if ($rule === '') return ($s < $to && $en >= $from) ? [[$s, $en]] : [];
    $out = []; $n = 0;
    // Saltar directo cerca del rango en las repeticiones fijas (un evento diario de hace años no recorre todo)
    if ($rule === 'daily' || $rule === 'weekly') {
        $step = $rule === 'daily' ? 1 : 7;
        $days = (int)floor(($from->getTimestamp() - $len - $s->getTimestamp()) / 86400);
        if ($days > 0) $n = intdiv($days, $step);
    }
    for ($guard = 0; $guard < 5000 && count($out) < $max; $guard++, $n++) {
        if ($rule === 'daily') $o = $s->modify("+$n day");
        elseif ($rule === 'weekly') $o = $s->modify('+' . ($n * 7) . ' day');
        elseif ($rule === 'monthly') {   // el 31 no existe en todos los meses: ese mes no hay
            $m = $s->modify('first day of this month')->modify("+$n month");
            $o = $m->setDate((int)$m->format('Y'), (int)$m->format('m'), (int)$s->format('d'));
            if ($o->format('m') !== $m->format('m')) continue;
        }
        elseif ($rule === 'yearly') { $o = $s->setDate((int)$s->format('Y') + $n, (int)$s->format('m'), (int)$s->format('d')); if ($o->format('m-d') !== $s->format('m-d')) continue; }
        else break;
        if ($o >= $to || ($until && $o > $until)) break;
        $oe = $o->modify("+$len second");
        if ($oe >= $from) $out[] = [$o, $oe];
    }
    return $out;
}

/** Un evento listo para el navegador, con inicio y fin en la zona de quien mira ($view). */
function cal_event_out(array $e, DateTimeImmutable $s, DateTimeImmutable $en, ?DateTimeZone $view): array {
    $all = (bool)(int)$e['all_day']; $ez = cal_tz($e);
    return ['id' => (int)$e['id'], 'title' => $e['title'], 'notes' => (string)$e['notes'], 'location' => $e['location'], 'all_day' => (int)$e['all_day'], 'color' => $e['color'],
        'repeat_rule' => $e['repeat_rule'], 'repeat_until' => $e['repeat_until'], 'remind_min' => $e['remind_min'] === null ? null : (int)$e['remind_min'],
        'start' => cal_view($s, $all, $view), 'end' => cal_view($en, $all, $view),
        'base_start' => cal_view(new DateTimeImmutable($e['start_at'], $ez), $all, $view), 'base_end' => cal_view(new DateTimeImmutable($e['end_at'], $ez), $all, $view)];
}

/** Eventos de una persona en el rango, con cada repetición como una ocurrencia aparte. $from/$to son instantes; $view, la zona de quien mira. */
function cal_events(PDO $db, int $uid, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeZone $view = null): array {
    // Cada evento guarda la hora en su propia zona: el filtro de la consulta se agranda un día por lado y el exacto lo hace cal_occurrences()
    $st = $db->prepare("SELECT * FROM g7_cal_events WHERE user_id = ? AND start_at < ? AND (repeat_rule <> '' OR end_at >= ?) AND (repeat_until IS NULL OR repeat_until >= ?)");
    $st->execute([$uid, $to->modify('+1 day')->format('Y-m-d H:i:s'), $from->modify('-1 day')->format('Y-m-d H:i:s'), $from->modify('-1 day')->format('Y-m-d')]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) foreach (cal_occurrences($e, $from, $to) as [$s, $en]) {
        $out[] = cal_event_out($e, $s, $en, $view);
    }
    usort($out, fn($a, $b) => [$b['all_day'], $a['start']] <=> [$a['all_day'], $b['start']]);
    return $out;
}

/** Tareas de la persona con fecha de vencimiento en el rango (asignadas a ella, o creadas por ella sin nadie asignado). */
function cal_tasks(PDO $db, int $uid, DateTimeImmutable $from, DateTimeImmutable $to): array {
    try {
        $st = $db->prepare("SELECT t.id, t.title, t.due_date, t.status, t.completed_at FROM g7_tasks t
            WHERE t.archived = 0 AND t.due_date >= ? AND t.due_date < ?
              AND (EXISTS (SELECT 1 FROM g7_task_assignees a WHERE a.task_id = t.id AND a.user_id = ?)
                   OR (t.created_by = ? AND NOT EXISTS (SELECT 1 FROM g7_task_assignees a2 WHERE a2.task_id = t.id)))
            ORDER BY t.due_date, t.id LIMIT 500");
        $st->execute([$from->format('Y-m-d'), $to->format('Y-m-d'), $uid, $uid]);
        return array_map(fn($t) => ['id' => (int)$t['id'], 'title' => $t['title'], 'date' => $t['due_date'], 'done' => $t['completed_at'] !== null], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }   // sitio sin Tareas
}

/** Avisos de la persona que llegaron a su hora: uno por ocurrencia (reminded_at guarda la última avisada). Lo llama notif.php. */
function cal_run_reminders(PDO $db, int $uid): void {
    try {
        $st = $db->prepare('SELECT * FROM g7_cal_events WHERE user_id = ? AND remind_min IS NOT NULL AND (repeat_until IS NULL OR repeat_until >= CURDATE()) AND (repeat_rule <> \'\' OR start_at >= NOW() - INTERVAL 1 DAY)');
        $st->execute([$uid]);
    } catch (Throwable $e) { return; }   // la tabla todavía no existe
    $now = new DateTimeImmutable();
    $ins = $db->prepare("INSERT INTO g7_notifications (user_id, from_id, type, target_id, body, created_at) VALUES (?, NULL, 'cal_remind', ?, ?, NOW())");   // sin remitente: lo manda el sistema
    $mark = $db->prepare('UPDATE g7_cal_events SET reminded_at = ? WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $lead = (int)$e['remind_min'];
        foreach (cal_occurrences($e, $now->modify('-6 hour'), $now->modify('+' . ($lead + 1) . ' minute'), 5) as [$s]) {
            if ($s->modify("-$lead minute") > $now || $s < $now->modify('-6 hour')) continue;                  // todavía no es la hora, o ya pasó hace rato
            if ($e['reminded_at'] && new DateTimeImmutable($e['reminded_at']) >= $s) continue;                 // esta ya se avisó
            // El texto del aviso, en la zona de la persona; reminded_at, en la de almacenamiento
            $uz = new DateTimeZone(function_exists('g7_tz_user') ? g7_tz_user($uid) : date_default_timezone_get());
            $sv = $s->setTimezone($uz); $nv = $now->setTimezone($uz);
            $when = $e['all_day'] ? 'hoy' : ($sv->format('Y-m-d') === $nv->format('Y-m-d') ? 'hoy ' . $sv->format('H:i') : $sv->format('d/m H:i'));
            try { $ins->execute([$uid, (int)$e['id'], mb_substr($e['title'] . ' · ' . $when, 0, 300)]); } catch (Throwable $er) { return; }   // un aviso que falla no rompe la campana
            $stored = $s->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            $mark->execute([$stored, (int)$e['id']]);
            $e['reminded_at'] = $stored;
        }
    }
}
