<?php
/**
 * mood_lib.php — "¿Cómo estás hoy?" del Inicio (2026-11-11). Botón en el saludo que abre un modal (assets/home-mood.js).
 * Cada persona marca una carita (1 a 5) y, si quiere, motivos rápidos. Una respuesta cada 24 horas.
 * Privacidad: cada uno ve solo lo suyo. Los admins ven un PROMEDIO ANÓNIMO del equipo, y solo de los días
 * en que respondieron al menos MOOD_MIN_GROUP personas (con menos se podría deducir quién contestó qué).
 * Nunca se expone quién respondió qué. Doc: toolz/ANIMO.md
 */

const MOOD_MIN_GROUP = 3;
const MOOD_EVERY = 86400;   // una respuesta cada 24 horas

const MOOD_LEVELS = [
    1 => ['label' => 'Muy mal',   'icon' => 'fa-face-sad-tear',   'color' => '#ef4444'],
    2 => ['label' => 'Mal',       'icon' => 'fa-face-frown',      'color' => '#f97316'],
    3 => ['label' => 'Normal',    'icon' => 'fa-face-meh',        'color' => '#eab308'],
    4 => ['label' => 'Bien',      'icon' => 'fa-face-smile',      'color' => '#22c55e'],
    5 => ['label' => 'Excelente', 'icon' => 'fa-face-grin-stars', 'color' => '#8b5cf6'],
];

const MOOD_REASONS = [
    'motivado'   => ['label' => 'Motivado',      'icon' => 'fa-rocket'],
    'contento'   => ['label' => 'Contento',      'icon' => 'fa-sun'],
    'descansado' => ['label' => 'Descansado',    'icon' => 'fa-bed'],
    'equipo'     => ['label' => 'Buen equipo',   'icon' => 'fa-users'],
    'trabajo'    => ['label' => 'Mucho trabajo', 'icon' => 'fa-briefcase'],
    'cansancio'  => ['label' => 'Cansancio',     'icon' => 'fa-battery-quarter'],
    'estres'     => ['label' => 'Estrés',        'icon' => 'fa-bolt'],
    'personal'   => ['label' => 'Algo personal', 'icon' => 'fa-heart'],
];

function mood_install(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS g7_mood (
        user_id INT NOT NULL,
        day DATE NOT NULL,
        mood TINYINT NOT NULL,
        reasons VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, day),
        KEY day (day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Solo motivos conocidos, sin repetir, como máximo 3. */
function mood_clean_reasons($in): array {
    $out = [];
    foreach ((array)$in as $r) if (is_string($r) && isset(MOOD_REASONS[$r]) && !in_array($r, $out, true)) $out[] = $r;
    return array_slice($out, 0, 3);
}

/** Momento (timestamp) de la última respuesta de la persona, o null. */
function mood_last_at(PDO $db, int $uid): ?int {
    mood_install($db);
    $st = $db->prepare('SELECT MAX(updated_at) FROM g7_mood WHERE user_id = ?');
    $st->execute([$uid]);
    $v = $st->fetchColumn();
    return $v ? strtotime((string)$v) : null;
}

/** Guarda el ánimo. Una respuesta cada 24 horas: si respondió hace menos, se rechaza. */
function mood_set(PDO $db, int $uid, int $mood, $reasons = [], ?int $now = null): array {
    if ($uid <= 0) throw new RuntimeException('Tu sesión venció: vuelve a entrar.');
    if (!isset(MOOD_LEVELS[$mood])) throw new RuntimeException('Elige una de las caritas.');
    mood_install($db);
    $now = $now ?? time();
    $last = mood_last_at($db, $uid);
    if ($last !== null && $now - $last < MOOD_EVERY) {
        $h = (int)ceil((MOOD_EVERY - ($now - $last)) / 3600);
        throw new RuntimeException('Ya respondiste. Puedes volver a responder en ' . ($h === 1 ? '1 hora' : "$h horas") . '.');
    }
    $day = date('Y-m-d', $now);
    $rs = mood_clean_reasons($reasons);
    $st = $db->prepare('INSERT INTO g7_mood (user_id, day, mood, reasons, updated_at) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE mood = VALUES(mood), reasons = VALUES(reasons), updated_at = VALUES(updated_at)');
    $st->execute([$uid, $day, $mood, implode(',', $rs), date('Y-m-d H:i:s', $now)]);
    return ['day' => $day, 'mood' => $mood, 'reasons' => $rs];
}

/** Lo mío: la respuesta vigente (de las últimas 24 h), si ya puedo responder, cuántas horas faltan y los últimos $days días. */
function mood_mine(PDO $db, int $uid, int $days = 7, ?int $now = null): array {
    mood_install($db);
    $now = $now ?? time();
    $today = date('Y-m-d', $now);
    $from = date('Y-m-d', strtotime("$today -" . ($days - 1) . ' days'));
    $st = $db->prepare('SELECT day, mood, reasons, updated_at FROM g7_mood WHERE user_id = ? AND day BETWEEN ? AND ? ORDER BY day');
    $st->execute([$uid, $from, $today]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
        $by[$r['day']] = ['mood' => (int)$r['mood'], 'reasons' => $r['reasons'] === '' ? [] : explode(',', $r['reasons']), 'at' => strtotime($r['updated_at'])];
    $week = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("$today -$i days")); $week[] = ['day' => $d, 'mood' => $by[$d]['mood'] ?? null]; }
    $last = mood_last_at($db, $uid);
    $wait = $last !== null ? max(0, MOOD_EVERY - ($now - $last)) : 0;
    $cur = null;
    if ($wait > 0) foreach ($by as $r) if ($r['at'] === $last) $cur = ['mood' => $r['mood'], 'reasons' => $r['reasons']];
    return ['current' => $cur, 'can' => $wait === 0, 'wait_h' => (int)ceil($wait / 3600), 'week' => $week];
}

/**
 * Promedio anónimo del equipo para admins. Por día: promedio y cantidad de respuestas, solo si respondieron
 * al menos $min personas (si no, el día va vacío y no dice cuántas hubo). Motivos: conteo de la semana,
 * solo con los días que cumplen el mínimo. Nunca devuelve usuarios.
 */
function mood_team(PDO $db, int $days = 7, ?int $now = null, int $min = MOOD_MIN_GROUP): array {
    mood_install($db);
    $today = date('Y-m-d', $now ?? time());
    $from = date('Y-m-d', strtotime("$today -" . ($days - 1) . ' days'));
    $st = $db->prepare('SELECT day, mood, reasons FROM g7_mood WHERE day BETWEEN ? AND ?');
    $st->execute([$from, $today]);
    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['day'];
        $agg[$d]['n'] = ($agg[$d]['n'] ?? 0) + 1;
        $agg[$d]['sum'] = ($agg[$d]['sum'] ?? 0) + (int)$r['mood'];
        foreach ($r['reasons'] === '' ? [] : explode(',', $r['reasons']) as $k) if (isset(MOOD_REASONS[$k])) $agg[$d]['rs'][$k] = ($agg[$d]['rs'][$k] ?? 0) + 1;
    }
    $week = []; $reasons = []; $sum = 0; $n = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("$today -$i days")); $a = $agg[$d] ?? ['n' => 0];
        if ($a['n'] >= $min) {
            $week[] = ['day' => $d, 'avg' => round($a['sum'] / $a['n'], 1), 'n' => $a['n']];
            $sum += $a['sum']; $n += $a['n'];
            foreach ($a['rs'] ?? [] as $k => $c) $reasons[$k] = ($reasons[$k] ?? 0) + $c;
        } else $week[] = ['day' => $d, 'avg' => null, 'n' => null];
    }
    arsort($reasons);
    $t = end($week);
    return ['today' => $t['avg'] !== null ? $t : null, 'avg' => $n ? round($sum / $n, 1) : null, 'week' => $week,
        'reasons' => array_slice($reasons, 0, 4, true), 'min' => $min];
}

/** Nivel (1 a 5) más cercano a un promedio, para elegir carita y color. */
function mood_level_of(?float $avg): ?int {
    return $avg === null ? null : max(1, min(5, (int)round($avg)));
}

/**
 * Informe de Bienestar (Estadísticas › Bienestar, solo admins). Todo anónimo: cada número sale de al menos $min
 * PERSONAS DISTINTAS; lo que no llega se devuelve vacío. Nunca devuelve usuarios.
 *  - days: por día, promedio, respuestas y cuántas hubo de cada carita (solo días con >= $min personas)
 *  - kpi: promedio del período y del anterior, participación media, % de respuestas bajas (1-2) y altas (4-5)
 *  - dow: promedio por día de la semana (lunes a domingo)
 *  - reasons / reasons_low / reasons_high: motivos en general, en días malos (1-2) y buenos (4-5)
 *  - teams: promedio por equipo (g7_teams), si el equipo tiene >= $min personas que respondieron
 *  - insights: frases automáticas para leer de un vistazo
 */
function mood_report(PDO $db, int $days = 30, ?int $now = null, int $min = MOOD_MIN_GROUP): array {
    mood_install($db);
    $now = $now ?? time();
    $to = date('Y-m-d', $now);
    $from = date('Y-m-d', strtotime("$to -" . ($days - 1) . ' days'));
    $pFrom = date('Y-m-d', strtotime("$from -$days days"));
    $pTo = date('Y-m-d', strtotime("$from -1 day"));
    $rows = function (string $a, string $b) use ($db) {
        $st = $db->prepare('SELECT user_id, day, mood, reasons FROM g7_mood WHERE day BETWEEN ? AND ?');
        $st->execute([$a, $b]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $cur = $rows($from, $to);
    $prev = $rows($pFrom, $pTo);
    $people = fn(array $rs) => count(array_unique(array_column($rs, 'user_id')));
    $avg = fn(array $rs) => $rs ? round(array_sum(array_map(fn($r) => (int)$r['mood'], $rs)) / count($rs), 2) : null;
    $okGroup = fn(array $rs) => $people($rs) >= $min;

    // Por día
    $byDay = [];
    foreach ($cur as $r) $byDay[$r['day']][] = $r;
    $daysOut = []; $qual = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("$to -$i days"));
        $rs = $byDay[$d] ?? [];
        if ($okGroup($rs)) {
            $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            foreach ($rs as $r) $dist[(int)$r['mood']]++;
            $daysOut[] = ['day' => $d, 'avg' => $avg($rs), 'n' => count($rs), 'dist' => $dist];
            array_push($qual, ...$rs);
        } else $daysOut[] = ['day' => $d, 'avg' => null, 'n' => null, 'dist' => null];
    }
    // Participación: respuestas por día respecto de las personas activas (solo el promedio del período, no por día)
    $active = 0;
    try { $active = (int)$db->query('SELECT COUNT(*) FROM g7_users WHERE active = 1')->fetchColumn(); } catch (Throwable $e) {}
    $active = max($active, $people($cur));   // personas que ya no están activas también cuentan en el período
    $part = $active && $cur && $okGroup($cur) ? min(100, (int)round(count($cur) / ($days * $active) * 100)) : null;
    $share = fn(array $rs, callable $f) => $rs ? (int)round(count(array_filter($rs, $f)) / count($rs) * 100) : null;
    $prevAvg = $okGroup($prev) ? $avg($prev) : null;
    $curAvg = $okGroup($qual) ? $avg($qual) : null;
    $kpi = [
        'avg' => $curAvg, 'prev_avg' => $prevAvg, 'delta' => $curAvg !== null && $prevAvg !== null ? round($curAvg - $prevAvg, 2) : null,
        'answers' => $okGroup($cur) ? count($cur) : null, 'people' => $okGroup($cur) ? $people($cur) : null, 'active' => $active, 'participation' => $part,
        'low' => $curAvg !== null ? $share($qual, fn($r) => $r['mood'] <= 2) : null,
        'high' => $curAvg !== null ? $share($qual, fn($r) => $r['mood'] >= 4) : null,
        'days_ok' => count(array_filter($daysOut, fn($d) => $d['avg'] !== null)),
    ];
    // Día de la semana (1 = lunes)
    $dw = [];
    foreach ($cur as $r) $dw[(int)date('N', strtotime($r['day']))][] = $r;
    $dow = [];
    foreach ([1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'] as $k => $name)
        $dow[] = ['dow' => $k, 'name' => $name, 'avg' => $okGroup($dw[$k] ?? []) ? $avg($dw[$k]) : null];
    // Motivos: en general, en días malos y en días buenos
    $cnt = function (array $rs) {
        $c = [];
        foreach ($rs as $r) foreach ($r['reasons'] === '' ? [] : explode(',', $r['reasons']) as $k) if (isset(MOOD_REASONS[$k])) $c[$k] = ($c[$k] ?? 0) + 1;
        arsort($c);
        return $c;
    };
    $low = array_values(array_filter($cur, fn($r) => $r['mood'] <= 2));
    $high = array_values(array_filter($cur, fn($r) => $r['mood'] >= 4));
    $reasons = $okGroup($cur) ? $cnt($cur) : [];
    $rLow = $okGroup($low) ? $cnt($low) : [];
    $rHigh = $okGroup($high) ? $cnt($high) : [];
    // Equipos (Admin › Equipos)
    $teams = [];
    try {
        $mem = [];
        foreach ($db->query('SELECT m.team_id, m.user_id, t.name, t.color FROM g7_team_members m JOIN g7_teams t ON t.id = m.team_id WHERE t.active = 1') as $m) {
            $mem[(int)$m['team_id']]['name'] = $m['name'];
            $mem[(int)$m['team_id']]['color'] = $m['color'];
            $mem[(int)$m['team_id']]['users'][(int)$m['user_id']] = 1;
        }
        foreach ($mem as $t) {
            $rs = array_values(array_filter($cur, fn($r) => isset($t['users'][(int)$r['user_id']])));
            $teams[] = ['name' => $t['name'], 'color' => $t['color'], 'avg' => $okGroup($rs) ? $avg($rs) : null, 'size' => count($t['users'])];
        }
        usort($teams, fn($a, $b) => [$a['avg'] === null, $a['avg'] ?? 0] <=> [$b['avg'] === null, $b['avg'] ?? 0]);
    } catch (Throwable $e) { $teams = []; }
    // Frases automáticas
    $ins = [];
    $fmt = fn($v) => str_replace('.', ',', (string)round($v, 1));
    if ($curAvg === null) $ins[] = ['info', 'Todavía hay pocas respuestas para mostrar el ánimo del equipo. Se muestra cuando responden ' . $min . ' personas o más.'];
    else {
        if ($kpi['delta'] !== null && abs($kpi['delta']) >= 0.3)
            $ins[] = [$kpi['delta'] > 0 ? 'good' : 'warn', 'El ánimo ' . ($kpi['delta'] > 0 ? 'mejoró' : 'bajó') . ' ' . $fmt(abs($kpi['delta'])) . ' puntos respecto del período anterior.'];
        if ($kpi['low'] !== null && $kpi['low'] >= 30) $ins[] = ['warn', $kpi['low'] . '% de las respuestas fueron "Mal" o "Muy mal". Puede ser un buen momento para hablar con el equipo.'];
        elseif ($kpi['high'] !== null && $kpi['high'] >= 60) $ins[] = ['good', $kpi['high'] . '% de las respuestas fueron "Bien" o "Excelente".'];
        $dq = array_values(array_filter($dow, fn($d) => $d['avg'] !== null));
        if (count($dq) >= 3) {
            usort($dq, fn($a, $b) => $a['avg'] <=> $b['avg']);
            $w = $dq[0]; $b = $dq[count($dq) - 1];
            if ($b['avg'] - $w['avg'] >= 0.4) $ins[] = ['info', 'Los ' . mb_strtolower($w['name']) . ' son los días más bajos (' . $fmt($w['avg']) . ') y los ' . mb_strtolower($b['name']) . ' los mejores (' . $fmt($b['avg']) . ').'];
        }
        if ($rLow) $ins[] = ['warn', 'En los días malos el motivo más elegido es "' . MOOD_REASONS[array_key_first($rLow)]['label'] . '".'];
        if ($rHigh) $ins[] = ['good', 'En los días buenos el motivo más elegido es "' . MOOD_REASONS[array_key_first($rHigh)]['label'] . '".'];
        if ($part !== null && $part < 40) $ins[] = ['info', 'Responde en promedio el ' . $part . '% del equipo por día. Cuantas más respuestas, más fiel es el promedio.'];
    }
    return ['from' => $from, 'to' => $to, 'days' => $daysOut, 'kpi' => $kpi, 'dow' => $dow, 'reasons' => $reasons, 'reasons_low' => $rLow,
        'reasons_high' => $rHigh, 'teams' => $teams, 'insights' => $ins, 'min' => $min];
}
