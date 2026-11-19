<?php
/**
 * bienestar/lib_habitos.php — Hábitos saludables: personales y privados (solo los ve su dueño).
 * Frecuencia diaria (con días de la semana elegidos) o semanal (N días por semana). Racha, mejor racha, cumplimiento
 * de los últimos 30 días, calendario de 12 semanas e insignias. Recordatorio opcional a la campana. Doc: toolz/BIENESTAR.md
 */

/** ¿El hábito diario toca ese día? Bit 0 = lunes … bit 6 = domingo. */
function bn_habit_on(int $mask, string $day): bool { return (bool)($mask & (1 << ((int)date('N', strtotime($day)) - 1))); }

function bn_habit_get(PDO $db, int $uid, int $id): array {
    $q = $db->prepare('SELECT * FROM g7_bn_habits WHERE id = ? AND user_id = ?');
    $q->execute([$id, $uid]);
    $h = $q->fetch(PDO::FETCH_ASSOC);
    if (!$h) bn_fail('Ese hábito no está.', 404);   // también si es de otra persona: no se dice que existe
    return $h;
}

/** Racha actual y mejor racha. Diarios: días programados seguidos (hoy sin marcar todavía no corta). Semanales: semanas cumplidas. */
function bn_habit_streaks(array $h, array $log, string $today): array {
    $set = array_flip($log);
    $since = substr((string)$h['created_at'], 0, 10);
    if ($log && min($log) < $since) $since = min($log);
    if ($h['freq'] === 'weekly') {
        $per = []; foreach ($log as $d) { $w = bn_week(strtotime($d)); $per[$w] = ($per[$w] ?? 0) + 1; }
        $target = max(1, (int)$h['target']);
        $cur = 0; $t = strtotime($today);
        for ($i = 0; $i < 104; $i++) {
            $w = bn_week(strtotime("-$i weeks", $t));
            if (($per[$w] ?? 0) >= $target) $cur++;
            elseif ($i === 0) continue;   // la semana en curso todavía puede cumplirse
            else break;
        }
        $best = 0; $run = 0; $start = strtotime($since);
        for ($t2 = $start; date('Y-m-d', $t2) <= $today; $t2 = strtotime('+1 week', $t2)) {
            if (($per[bn_week($t2)] ?? 0) >= $target) { $run++; $best = max($best, $run); } else $run = 0;
        }
        return [$cur, max($best, $cur)];
    }
    $mask = (int)$h['days_mask'] ?: 127;
    $cur = 0;
    for ($i = 0; $i < 400; $i++) {
        $d = date('Y-m-d', strtotime("$today -$i days"));
        if ($d < $since) break;
        if (!bn_habit_on($mask, $d)) continue;
        if (isset($set[$d])) $cur++;
        elseif ($i === 0) continue;
        else break;
    }
    $best = 0; $run = 0;
    for ($d = $since; $d <= $today; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (!bn_habit_on($mask, $d)) continue;
        if (isset($set[$d])) { $run++; $best = max($best, $run); } elseif ($d !== $today) $run = 0;
    }
    return [$cur, max($best, $cur)];
}

/** Mis hábitos con su estado de hoy, rachas, cumplimiento y los días hechos de las últimas 12 semanas. */
function bn_habit_list(PDO $db, int $uid, ?int $now = null, bool $archived = false): array {
    $today = bn_today($now);
    $q = $db->prepare('SELECT * FROM g7_bn_habits WHERE user_id = ? AND archived = ? ORDER BY id');
    $q->execute([$uid, $archived ? 1 : 0]);
    $habits = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$habits) return [];
    $ids = implode(',', array_map(fn($h) => (int)$h['id'], $habits));
    $logs = [];
    foreach ($db->query("SELECT habit_id, day FROM g7_bn_habit_log WHERE habit_id IN ($ids) AND day >= '" . date('Y-m-d', strtotime("$today -400 days")) . "' ORDER BY day") as $r) $logs[(int)$r['habit_id']][] = $r['day'];
    $from84 = date('Y-m-d', strtotime("$today -83 days"));
    $out = [];
    foreach ($habits as $h) {
        $log = $logs[(int)$h['id']] ?? [];
        [$cur, $best] = bn_habit_streaks($h, $log, $today);
        $set = array_flip($log);
        $weekN = count(array_filter($log, fn($d) => bn_week(strtotime($d)) === bn_week(strtotime($today))));
        if ($h['freq'] === 'weekly') {
            $due = $weekN < (int)$h['target'] || isset($set[$today]);
            // cumplimiento: semanas cumplidas de las últimas 4 (sin contar la actual)
            $met = 0; for ($i = 1; $i <= 4; $i++) { $w = bn_week(strtotime("$today -$i weeks")); if (count(array_filter($log, fn($d) => bn_week(strtotime($d)) === $w)) >= (int)$h['target']) $met++; }
            $rate = (int)round($met / 4 * 100);
        } else {
            $due = bn_habit_on((int)$h['days_mask'] ?: 127, $today);
            $sched = 0; $hit = 0; $since = substr((string)$h['created_at'], 0, 10);
            for ($i = 0; $i < 30; $i++) { $d = date('Y-m-d', strtotime("$today -$i days")); if ($d < $since) break; if (!bn_habit_on((int)$h['days_mask'] ?: 127, $d)) continue; if ($d === $today && !isset($set[$d])) continue; $sched++; if (isset($set[$d])) $hit++; }
            $rate = $sched ? (int)round($hit / $sched * 100) : null;
        }
        $out[] = ['id' => (int)$h['id'], 'title' => $h['title'], 'icon' => $h['icon'], 'color' => $h['color'], 'freq' => $h['freq'], 'target' => (int)$h['target'],
            'days_mask' => (int)$h['days_mask'], 'goal' => $h['goal'], 'remind' => (bool)$h['remind'], 'remind_time' => $h['remind_time'],
            'due_today' => $due, 'done_today' => isset($set[$today]), 'week_count' => $weekN, 'streak' => $cur, 'best' => $best,
            'streak_unit' => $h['freq'] === 'weekly' ? 'semanas' : 'días', 'rate30' => $rate, 'total' => count($log),
            'log' => array_values(array_filter($log, fn($d) => $d >= $from84)), 'archived' => (bool)$h['archived']];
    }
    return $out;
}

/** Crea o edita un hábito propio. */
function bn_habit_save(PDO $db, int $uid, array $in, ?int $now = null): int {
    $id = (int)($in['id'] ?? 0);
    if ($id) bn_habit_get($db, $uid, $id);
    elseif ((int)$db->query('SELECT COUNT(*) FROM g7_bn_habits WHERE archived = 0 AND user_id = ' . $uid)->fetchColumn() >= 20) bn_fail('Puedes tener hasta 20 hábitos activos. Archiva alguno para sumar otro.');
    $title = bn_str($in['title'] ?? '', 80, 2, 'El nombre del hábito');
    $freq = ($in['freq'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
    $target = $freq === 'weekly' ? max(1, min(7, (int)($in['target'] ?? 3))) : 1;
    $mask = $freq === 'daily' ? (int)($in['days_mask'] ?? 127) & 127 : 127;
    if ($freq === 'daily' && !$mask) bn_fail('Elige al menos un día de la semana.');
    $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($in['remind_time'] ?? '')) ? (string)$in['remind_time'] : '09:00';
    $tpl = (int)($in['tpl_id'] ?? 0) ?: null;
    if ($tpl) { $q = $db->prepare('SELECT id FROM g7_bn_habit_tpl WHERE id = ? AND active = 1'); $q->execute([$tpl]); if (!$q->fetchColumn()) $tpl = null; }
    $vals = [$title, bn_icon($in['icon'] ?? ''), bn_color($in['color'] ?? ''), $freq, $target, $mask, bn_str($in['goal'] ?? '', 120, 0, 'El objetivo'), empty($in['remind']) ? 0 : 1, $time];
    if ($id) $db->prepare('UPDATE g7_bn_habits SET title = ?, icon = ?, color = ?, freq = ?, target = ?, days_mask = ?, goal = ?, remind = ?, remind_time = ? WHERE id = ? AND user_id = ?')->execute([...$vals, $id, $uid]);
    else { $db->prepare('INSERT INTO g7_bn_habits (title, icon, color, freq, target, days_mask, goal, remind, remind_time, user_id, tpl_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, $uid, $tpl, date('Y-m-d H:i:s', bn_now($now))]); $id = (int)$db->lastInsertId(); }
    return $id;
}
function bn_habit_archive(PDO $db, int $uid, int $id, bool $archive = true): void {
    bn_habit_get($db, $uid, $id);
    $db->prepare('UPDATE g7_bn_habits SET archived = ? WHERE id = ? AND user_id = ?')->execute([$archive ? 1 : 0, $id, $uid]);
}
function bn_habit_delete(PDO $db, int $uid, int $id): void {
    bn_habit_get($db, $uid, $id);
    $db->prepare('DELETE FROM g7_bn_habit_log WHERE habit_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM g7_bn_habits WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}
/** Marca o desmarca un día. Se puede corregir hasta 6 días atrás (no días futuros). Devuelve el hábito actualizado y las insignias nuevas. */
function bn_habit_check(PDO $db, int $uid, int $id, ?string $day, bool $done, ?int $now = null): array {
    $h = bn_habit_get($db, $uid, $id);
    if ((int)$h['archived']) bn_fail('Ese hábito está archivado.');
    $today = bn_today($now);
    $day = $day === null || $day === '' ? $today : bn_date($day);
    if (!$day) bn_fail('Fecha inválida.');
    if ($day > $today) bn_fail('No puedes marcar días que todavía no llegaron.');
    if ($day < date('Y-m-d', strtotime("$today -6 days"))) bn_fail('Solo puedes corregir la última semana.');
    if ($done) $db->prepare('INSERT IGNORE INTO g7_bn_habit_log (habit_id, day) VALUES (?, ?)')->execute([$id, $day]);
    else $db->prepare('DELETE FROM g7_bn_habit_log WHERE habit_id = ? AND day = ?')->execute([$id, $day]);
    $list = array_values(array_filter(bn_habit_list($db, $uid, $now), fn($x) => $x['id'] === $id));
    $cur = $list[0] ?? null;
    $new = [];
    if ($done && $cur) {
        if (bn_badge($db, $uid, 'habit_first', 0, $now)) $new[] = 'habit_first';
        if ($h['freq'] === 'daily') {
            if ($cur['streak'] >= 7 && bn_badge($db, $uid, 'streak_7', $id, $now)) $new[] = 'streak_7';
            if ($cur['streak'] >= 30 && bn_badge($db, $uid, 'streak_30', $id, $now)) $new[] = 'streak_30';
        } elseif ($cur['streak'] >= 4 && bn_badge($db, $uid, 'weekly_4', $id, $now)) $new[] = 'weekly_4';
    }
    return ['habit' => $cur, 'badges' => array_map(fn($c) => ['code' => $c, 'emoji' => BN_BADGES[$c][0], 'label' => BN_BADGES[$c][1]], $new)];
}
/** Recordatorio a la campana: un solo aviso por día con los hábitos con recordatorio que faltan, pasada su hora. */
function bn_habit_remind(PDO $db, int $uid, ?int $now = null): int {
    $now = bn_now($now); $today = date('Y-m-d', $now); $hm = date('H:i', $now);
    $q = $db->prepare('SELECT id, remind_time FROM g7_bn_habits WHERE user_id = ? AND archived = 0 AND remind = 1 AND (reminded_on IS NULL OR reminded_on < ?)');
    $q->execute([$uid, $today]);
    $cand = array_filter($q->fetchAll(PDO::FETCH_ASSOC), fn($r) => $r['remind_time'] <= $hm);
    if (!$cand) return 0;
    $ids = array_map(fn($r) => (int)$r['id'], $cand);
    $pend = array_values(array_filter(bn_habit_list($db, $uid, $now), fn($h) => in_array($h['id'], $ids, true) && $h['due_today'] && !$h['done_today']));
    $db->prepare('UPDATE g7_bn_habits SET reminded_on = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$today]);
    if (!$pend) return 0;
    $names = array_map(fn($h) => $h['title'], array_slice($pend, 0, 3));
    $body = (count($pend) === 1 ? 'Hoy te queda: ' : 'Hoy te quedan ' . count($pend) . ' hábitos: ') . implode(', ', $names) . (count($pend) > 3 ? '…' : '');
    return bn_notify($db, [$uid], null, 'bn_habit', 0, $body, 'habits');
}

// ── Hábitos sugeridos (Admin › Bienestar) ──
function bn_tpl_list(PDO $db, bool $all = false): array {
    $rows = $db->query('SELECT * FROM g7_bn_habit_tpl' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'hint' => $r['hint'], 'icon' => $r['icon'], 'color' => $r['color'], 'freq' => $r['freq'], 'target' => (int)$r['target'], 'active' => (bool)$r['active']], $rows);
}
function bn_tpl_save(PDO $db, array $ctx, array $in): int {
    bn_need_admin($ctx);
    $id = (int)($in['id'] ?? 0);
    $freq = ($in['freq'] ?? 'daily') === 'weekly' ? 'weekly' : 'daily';
    $vals = [bn_str($in['title'] ?? '', 80, 2, 'El nombre'), bn_str($in['hint'] ?? '', 200, 0, 'La ayuda'), bn_icon($in['icon'] ?? ''), bn_color($in['color'] ?? ''), $freq,
        $freq === 'weekly' ? max(1, min(7, (int)($in['target'] ?? 3))) : 1, empty($in['active']) && isset($in['active']) ? 0 : 1];
    if ($id) { $db->prepare('UPDATE g7_bn_habit_tpl SET title = ?, hint = ?, icon = ?, color = ?, freq = ?, target = ?, active = ? WHERE id = ?')->execute([...$vals, $id]); }
    else { $db->prepare('INSERT INTO g7_bn_habit_tpl (title, hint, icon, color, freq, target, active, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$vals, (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM g7_bn_habit_tpl')->fetchColumn()]); $id = (int)$db->lastInsertId(); }
    bn_audit($ctx, 'habit_tpl_save', (string)$id, ['title' => $vals[0]]);
    return $id;
}
function bn_tpl_delete(PDO $db, array $ctx, int $id): void {
    bn_need_admin($ctx);
    $db->prepare('DELETE FROM g7_bn_habit_tpl WHERE id = ?')->execute([$id]);   // los hábitos creados desde la sugerencia quedan (son de cada persona)
    bn_audit($ctx, 'habit_tpl_delete', (string)$id);
}
