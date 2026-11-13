<?php
/**
 * trabajo/portal_metrics.php — Métricas del Portal de Solicitudes (tablero filtrable con comparación de períodos).
 * Alcance: administración ve todo; el resto, los departamentos de los que es parte. Todo sale de los tickets reales.
 */

function pt_metrics(PDO $db, array $ctx, array $in): array {
    $to = (isset($in['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['to'])) ? $in['to'] : pt_today();
    $from = (isset($in['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['from'])) ? $in['from'] : date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) $from = date('Y-m-d', strtotime($to . ' -366 days'));
    $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pFrom = date('Y-m-d', strtotime($from . " -$len days")); $pTo = date('Y-m-d', strtotime($from . ' -1 day'));
    // Departamentos del alcance
    $teams = $ctx['all'] ? array_map('intval', $db->query('SELECT id FROM g7_teams WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)) : $ctx['teams'];
    if (!empty($in['team_id'])) { $want = (int)$in['team_id']; if (!in_array($want, $teams, true)) return ['error' => 'No ves las métricas de ese departamento']; $teams = [$want]; }
    $tw = 't.team_id IN (' . wk_ints($teams) . ') AND t.form_version_id IS NOT NULL';
    if (!empty($in['category_id'])) $tw .= ' AND t.category_id = ' . (int)$in['category_id'];
    if (!empty($in['assignee_id'])) $tw .= ' AND t.assignee_id = ' . (int)$in['assignee_id'];
    $today = pt_today();
    $kpi = function (string $a, string $b) use ($db, $tw, $today) {
        $r = $db->query("SELECT
            SUM(DATE(t.created_at) BETWEEN '$a' AND '$b') AS created,
            SUM(t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0) AS completed,
            SUM(t.cancelled = 1 AND DATE(t.updated_at) BETWEEN '$a' AND '$b') AS cancelled,
            AVG(CASE WHEN t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0 THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.completed_at) END) AS avg_res_min,
            SUM(CASE WHEN t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0 THEN t.est_min END) AS est_done,
            SUM(CASE WHEN t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0 THEN t.real_min END) AS real_done,
            SUM(t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0 AND t.committed_date IS NOT NULL) AS with_date,
            SUM(t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$a' AND '$b' AND t.cancelled = 0 AND t.committed_date IS NOT NULL AND DATE(t.completed_at) <= t.committed_date) AS on_time,
            SUM(t.overload = 1 AND DATE(t.created_at) BETWEEN '$a' AND '$b') AS overload
            FROM g7_wk_tickets t WHERE $tw")->fetch(PDO::FETCH_ASSOC);
        $o = array_map(fn($v) => $v === null ? null : (float)$v, $r);
        $o['on_time_pct'] = $o['with_date'] ? round($o['on_time'] * 100 / $o['with_date'], 1) : null;
        $o['accuracy_pct'] = $o['real_done'] && $o['est_done'] ? round($o['est_done'] * 100 / $o['real_done'], 1) : null;   // 100 = estimado igual a lo real
        $o['avg_res_h'] = $o['avg_res_min'] !== null ? round($o['avg_res_min'] / 60, 1) : null;
        return $o;
    };
    $cur = $kpi($from, $to); $prev = $kpi($pFrom, $pTo);
    // Estado actual (no depende del período)
    $now = $db->query("SELECT SUM(t.stage = 'nuevo') AS nuevos, SUM(t.approval = 'pending') AS approval, SUM(t.status IN ('abierto', 'en_curso', 'esperando')) AS pending,
        SUM(t.status = 'en_curso') AS progress, SUM(t.status = 'esperando') AS waiting,
        SUM(t.status IN ('abierto', 'en_curso', 'esperando') AND t.committed_date < '$today') AS late,
        SUM(t.status IN ('abierto', 'en_curso', 'esperando') AND t.assignee_id IS NULL) AS unassigned
        FROM g7_wk_tickets t WHERE $tw")->fetch(PDO::FETCH_ASSOC);
    $now = array_map('intval', $now);
    // Serie: creadas y completadas por día (o por semana si el período es largo)
    $byWeek = $len > 45;
    $key = $byWeek ? "DATE_FORMAT(DATE_SUB(%s, INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')" : "DATE(%s)";
    $k1 = sprintf($key, 't.created_at', 't.created_at'); $k2 = sprintf($key, 't.completed_at', 't.completed_at');
    $series = [];
    foreach ($db->query("SELECT $k1 k, COUNT(*) n FROM g7_wk_tickets t WHERE $tw AND DATE(t.created_at) BETWEEN '$from' AND '$to' GROUP BY k")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) $series[$k]['created'] = (int)$n;
    foreach ($db->query("SELECT $k2 k, COUNT(*) n FROM g7_wk_tickets t WHERE $tw AND t.cancelled = 0 AND t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$from' AND '$to' GROUP BY k")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) $series[$k]['completed'] = (int)$n;
    $pts = [];
    for ($ts = strtotime($byWeek ? date('Y-m-d', strtotime($from . ' -' . (date('N', strtotime($from)) - 1) . ' days')) : $from); $ts <= strtotime($to); $ts += $byWeek ? 7 * 86400 : 86400) {
        $k = date('Y-m-d', $ts); $pts[] = ['k' => $k, 'created' => $series[$k]['created'] ?? 0, 'completed' => $series[$k]['completed'] ?? 0];
    }
    // Por departamento: ocupación de los próximos 14 días (carga / capacidad) y del período (horas reales / capacidad)
    $depts = [];
    $n14 = date('Y-m-d', strtotime('+13 days'));
    foreach ($teams as $tm) {
        $d = pt_dept($db, $tm); if (!$d) continue;
        $capF = pt_capacity($db, $d, $today, $n14); $comF = pt_committed($db, $tm, $today, $n14);
        $cF = array_sum(array_column($capF, 'usable')); $lF = array_sum(array_column($comF, 'min'));
        $pEnd = min($to, $today);
        $capP = $from <= $pEnd ? pt_capacity($db, $d, $from, $pEnd) : [];
        $q = $db->prepare('SELECT COALESCE(SUM(x.minutes), 0) FROM g7_wk_tk_time x JOIN g7_wk_tickets t ON t.id = x.ticket_id WHERE t.team_id = ? AND x.day BETWEEN ? AND ?'); $q->execute([$tm, $from, $pEnd]);
        $realP = (int)$q->fetchColumn(); $cP = array_sum(array_column($capP, 'cap'));
        $q = $db->prepare("SELECT COUNT(*) FROM g7_wk_tickets WHERE team_id = ? AND form_version_id IS NOT NULL AND status IN ('abierto', 'en_curso', 'esperando')"); $q->execute([$tm]);
        $depts[] = ['id' => $tm, 'name' => $d['name'], 'color' => $d['color'], 'icon' => $d['icon'], 'open' => (int)$q->fetchColumn(),
                    'next_cap' => $cF, 'next_load' => $lF, 'next_pct' => $cF ? round($lF * 100 / $cF, 1) : null, 'period_cap' => $cP, 'period_real' => $realP, 'period_pct' => $cP ? round($realP * 100 / $cP, 1) : null,
                    'days' => array_map(fn($day) => ['day' => $day, 'work' => $capF[$day]['work'], 'pct' => $capF[$day]['usable'] ? (int)round(($comF[$day]['min'] ?? 0) * 100 / $capF[$day]['usable']) : null], array_keys($capF))];
    }
    // Productividad por integrante (lo completado en el período)
    $people = $db->query("SELECT t.assignee_id uid, COUNT(*) done, SUM(t.est_min) est, SUM(t.real_min) realm,
            SUM(t.committed_date IS NOT NULL AND DATE(t.completed_at) <= t.committed_date) on_time, SUM(t.committed_date IS NOT NULL) with_date
        FROM g7_wk_tickets t WHERE $tw AND t.assignee_id IS NOT NULL AND t.cancelled = 0 AND t.completed_at IS NOT NULL AND DATE(t.completed_at) BETWEEN '$from' AND '$to' GROUP BY t.assignee_id")->fetchAll(PDO::FETCH_ASSOC);
    $open = $db->query("SELECT t.assignee_id uid, COUNT(*) n, SUM(GREATEST(COALESCE(t.est_min, 0) - t.real_min, 0)) left_min FROM g7_wk_tickets t WHERE $tw AND t.assignee_id IS NOT NULL AND t.status IN ('abierto', 'en_curso', 'esperando') GROUP BY t.assignee_id")->fetchAll(PDO::FETCH_ASSOC);
    $q = $db->prepare("SELECT x.user_id, SUM(x.minutes) FROM g7_wk_tk_time x JOIN g7_wk_tickets t ON t.id = x.ticket_id WHERE $tw AND x.day BETWEEN ? AND ? GROUP BY x.user_id"); $q->execute([$from, $to]);
    $logged = array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    $pp = [];
    foreach ($people as $r) $pp[(int)$r['uid']] = ['uid' => (int)$r['uid'], 'done' => (int)$r['done'], 'est' => (int)$r['est'], 'real' => (int)$r['realm'], 'on_time_pct' => $r['with_date'] ? round($r['on_time'] * 100 / $r['with_date'], 1) : null, 'open' => 0, 'left' => 0];
    foreach ($open as $r) { $u = (int)$r['uid']; $pp[$u] = ($pp[$u] ?? ['uid' => $u, 'done' => 0, 'est' => 0, 'real' => 0, 'on_time_pct' => null]) + ['open' => 0, 'left' => 0]; $pp[$u]['open'] = (int)$r['n']; $pp[$u]['left'] = (int)$r['left_min']; }
    foreach ($logged as $u => $m) { $pp[$u] = ($pp[$u] ?? ['uid' => $u, 'done' => 0, 'est' => 0, 'real' => 0, 'on_time_pct' => null, 'open' => 0, 'left' => 0]); $pp[$u]['logged'] = $m; }
    foreach ($pp as &$p) { $p['name'] = wk_user_name($db, $p['uid']); $p['logged'] = $p['logged'] ?? 0; } unset($p);
    usort($pp, fn($a, $b) => $b['done'] <=> $a['done'] ?: $b['logged'] <=> $a['logged']);
    // Por categoría y por etapa
    $byCat = $db->query("SELECT c.name, c.color, COUNT(*) n, SUM(t.est_min) est FROM g7_wk_tickets t LEFT JOIN g7_wk_ticket_cats c ON c.id = t.category_id WHERE $tw AND DATE(t.created_at) BETWEEN '$from' AND '$to' GROUP BY t.category_id ORDER BY n DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $byStage = $db->query("SELECT t.stage, COUNT(*) n FROM g7_wk_tickets t WHERE $tw AND t.status IN ('abierto', 'en_curso', 'esperando') GROUP BY t.stage")->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['from' => $from, 'to' => $to, 'prev' => ['from' => $pFrom, 'to' => $pTo], 'by_week' => $byWeek, 'kpi' => $cur, 'kpi_prev' => $prev, 'now' => $now, 'series' => $pts,
            'depts' => $depts, 'people' => array_values($pp), 'by_cat' => array_map(fn($r) => ['name' => $r['name'] ?: 'Sin categoría', 'color' => $r['color'] ?: '#8b8b94', 'n' => (int)$r['n'], 'est' => (int)$r['est']], $byCat),
            'by_stage' => array_map('intval', $byStage), 'teams' => $teams];
}
