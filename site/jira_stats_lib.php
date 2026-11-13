<?php
/**
 * jira_stats_lib.php — Estadísticas › Jira: informes de los proyectos elegidos en Admin › Jira API.
 * Una sola consulta JQL por rango de fechas (lo creado, resuelto o con entrega en el rango + todo lo abierto), paginada
 * hasta G7_JS_MAX issues. Los issues se guardan ya resumidos 10 minutos en _private/cache/ (no en g7_config: no entran)
 * y todas las cuentas se hacen acá: la página (stats_jira.php) y la exportación (stats_jira_export.php) usan lo mismo.
 * Doc: toolz/JIRA.md (Estadísticas)
 */
require_once __DIR__ . '/jira_use_lib.php';

const G7_JS_MAX = 2000;   // issues como máximo por informe (20 páginas de 100)
const G7_JS_TTL = 600;    // segundos en caché
const G7_JS_MAX_DAYS = 731;
// Situación de cada pedido en el informe (una sola por issue). Orden y textos de la página y de la exportación.
const G7_JS_SIT = [
    'on_time'     => ['Entregados a tiempo', '#22c55e', 'fa-circle-check'],
    'late_done'   => ['Entregados tarde',    '#f59e0b', 'fa-clock-rotate-left'],
    'overdue'     => ['Atrasados',           '#ef4444', 'fa-triangle-exclamation'],
    'in_progress' => ['En curso',            '#4c9aff', 'fa-person-digging'],
    'not_started' => ['Sin empezar',         '#94a3b8', 'fa-hourglass-start'],
    'done_before' => ['Resueltos antes del rango', '#64748b', 'fa-box-archive'],
];
// Rangos rápidos
const G7_JS_PRESETS = ['7' => '7 días', '30' => '30 días', '90' => '90 días', 'month' => 'Este mes', 'prev_month' => 'Mes pasado', 'quarter' => 'Este trimestre', 'year' => 'Este año', '365' => '12 meses'];

/** ¿Se muestra la sección? Jira conectado, "Estadísticas" prendido y al menos un proyecto. */
function g7_jira_stats_on(): bool { return function_exists('g7_jira_on') && g7_jira_on('stats'); }

/**
 * Rango pedido: ['from' => Y-m-d, 'to' => Y-m-d, 'preset' => clave o 'custom', 'label' => texto].
 * $q: los parámetros (jr = rango rápido, jf/jt = desde/hasta). Sin nada usa $fallbackDays (el período general de Estadísticas).
 */
function g7_jira_range(array $q, int $fallbackDays = 30, ?int $now = null): array {
    $now = $now ?? time(); $today = date('Y-m-d', $now);
    $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && date('Y-m-d', strtotime($d)) === $d;
    $jf = (string)($q['jf'] ?? ''); $jt = (string)($q['jt'] ?? ''); $pre = (string)($q['jr'] ?? '');
    if ($ok($jf) && $ok($jt)) {
        if ($jf > $jt) [$jf, $jt] = [$jt, $jf];
        if ($jt > $today) $jt = $today;
        if ($jf > $jt) $jf = $jt;
        if ((strtotime($jt) - strtotime($jf)) / 86400 > G7_JS_MAX_DAYS - 1) $jf = date('Y-m-d', strtotime($jt . ' -' . (G7_JS_MAX_DAYS - 1) . ' days'));
        $preset = 'custom';
    } else {
        $preset = isset(G7_JS_PRESETS[$pre]) ? $pre : (string)max(1, min(365, $fallbackDays));
        $jt = $today;
        switch ($preset) {
            case 'month':      $jf = date('Y-m-01', $now); break;
            case 'prev_month': $jf = date('Y-m-01', strtotime('first day of last month', $now)); $jt = date('Y-m-t', strtotime('first day of last month', $now)); break;
            case 'quarter':    $jf = date('Y-', $now) . sprintf('%02d', intdiv((int)date('n', $now) - 1, 3) * 3 + 1) . '-01'; break;
            case 'year':       $jf = date('Y-01-01', $now); break;
            default:           $jf = date('Y-m-d', strtotime($today . ' -' . ((int)$preset - 1) . ' days'));
        }
        if (!isset(G7_JS_PRESETS[$preset])) $preset = 'custom';
    }
    $fmt = fn($d) => (int)substr($d, 8, 2) . ' ' . ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)substr($d, 5, 2) - 1] . ' ' . substr($d, 0, 4);
    return ['from' => $jf, 'to' => $jt, 'preset' => $preset, 'days' => (int)round((strtotime($jt) - strtotime($jf)) / 86400) + 1,
        'label' => (G7_JS_PRESETS[$preset] ?? 'Del ' . $fmt($jf) . ' al ' . $fmt($jt)) . ($preset === 'custom' ? '' : ' (' . $fmt($jf) . ' – ' . $fmt($jt) . ')')];
}

/** Proyectos válidos de la lista elegida en Admin (vacío = todos). */
function g7_jira_pick(array $projects): array {
    $all = g7_jira_features()['projects'];
    return array_values(array_intersect($projects ?: $all, $all)) ?: $all;
}

/** Un issue de Jira resumido a lo que usan las cuentas (también para la caché). */
function g7_jira_issue_row(array $raw): array {
    $f = $raw['fields'] ?? [];
    $t = fn($s) => $s ? date('Y-m-d H:i', strtotime((string)$s) ?: 0) : '';
    return ['key' => (string)($raw['key'] ?? ''), 'summary' => (string)($f['summary'] ?? ''), 'status' => (string)($f['status']['name'] ?? ''),
        'cat' => (string)($f['status']['statusCategory']['key'] ?? 'new'), 'type' => (string)($f['issuetype']['name'] ?? 'Otro'),
        'priority' => (string)($f['priority']['name'] ?? 'Sin prioridad'), 'assignee' => (string)($f['assignee']['displayName'] ?? ''),
        'project' => (string)($f['project']['key'] ?? ''), 'project_name' => (string)($f['project']['name'] ?? ($f['project']['key'] ?? '')),
        'created' => $t($f['created'] ?? null), 'resolved' => $t($f['resolutiondate'] ?? null), 'due' => substr((string)($f['duedate'] ?? ''), 0, 10)];
}

/**
 * Issues del rango (resumidos), de Jira o de la caché. Lanza G7JiraError.
 * Devuelve ['issues' => [...], 'truncated' => bool, 'at' => timestamp de cuando se trajeron].
 */
function g7_jira_fetch(array $projects, string $from, string $to, bool $fresh = false): array {
    $dir = __DIR__ . '/_private/cache';
    $file = $dir . '/jira_stats_' . substr(md5(implode(',', $projects) . "|$from|$to"), 0, 20) . '.json';
    if (!$fresh && is_file($file) && filemtime($file) > time() - G7_JS_TTL) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['issues'])) return $c;
    }
    $toNext = date('Y-m-d', strtotime($to . ' +1 day'));
    $jql = g7_jira_jql_in($projects) . " AND ((created >= \"$from\" AND created < \"$toNext\") OR (resolved >= \"$from\" AND resolved < \"$toNext\")"
         . " OR (duedate >= \"$from\" AND duedate <= \"$to\") OR statusCategory != Done) ORDER BY created DESC";
    $fields = ['summary', 'status', 'issuetype', 'priority', 'assignee', 'created', 'resolutiondate', 'duedate', 'project'];
    $issues = []; $next = ''; $truncated = false;
    do {
        $r = g7_jira_search($jql, $fields, 100, $next);
        foreach ((array)($r['issues'] ?? []) as $i) $issues[] = g7_jira_issue_row($i);
        $next = (string)($r['nextPageToken'] ?? '');
        if (count($issues) >= G7_JS_MAX && $next !== '') { $truncated = true; break; }
    } while ($next !== '' && empty($r['isLast']));
    $out = ['issues' => $issues, 'truncated' => $truncated, 'at' => time()];
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
    foreach (glob($dir . '/jira_stats_*.json') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);   // limpieza: lo de más de un día
    return $out;
}

/** Informe completo para la página y la exportación. Lanza G7JiraError. */
function g7_jira_report(array $projects, array $range, bool $fresh = false): array {
    $projects = g7_jira_pick($projects);
    $f = g7_jira_fetch($projects, $range['from'], $range['to'], $fresh);
    return g7_jira_stats($f['issues'], $range['from'], $range['to'], time())
        + ['projects' => $projects, 'range' => $range, 'truncated' => $f['truncated'], 'site' => g7_jira_cfg()['site'], 'cached_at' => date('Y-m-d H:i:s', (int)$f['at'])];
}

/** Compatibilidad: informe de los últimos $days días a partir de issues tal como vienen de Jira. */
function g7_jira_report_build(array $issues, int $days, int $now): array {
    return g7_jira_stats(array_map('g7_jira_issue_row', $issues), date('Y-m-d', $now - ($days - 1) * 86400), date('Y-m-d', $now), $now);
}

/**
 * Las cuentas (separadas para poder probarlas sin Jira). $rows: issues resumidos (g7_jira_issue_row). Rango de días cerrado [$from, $to].
 * "Atrasado" se mide contra hoy ($now): lo abierto con fecha de entrega vencida.
 */
function g7_jira_stats(array $rows, string $from, string $to, int $now): array {
    $today = date('Y-m-d', $now); $soon = date('Y-m-d', strtotime($today . ' +7 days'));
    $tFrom = strtotime($from . ' 00:00'); $tTo = strtotime($to . ' 23:59:59');
    $days = (int)round(($tTo - $tFrom) / 86400);
    $gran = $days > 120 ? 'month' : ($days > 45 ? 'week' : 'day');
    $bucket = fn(int $t) => $gran === 'month' ? date('Y-m', $t) : ($gran === 'week' ? date('o-\WW', $t) : date('Y-m-d', $t));
    $series = [];
    for ($t = $tFrom; $t <= $tTo; $t += 86400) $series[$bucket($t)] ??= ['label' => $bucket($t), 'end' => 0, 'created' => 0, 'resolved' => 0, 'on_time' => 0, 'late_done' => 0, 'backlog' => 0];
    // Fin de cada tramo (para el pendiente acumulado)
    for ($t = $tFrom; $t <= $tTo; $t += 86400) $series[$bucket($t)]['end'] = min($tTo, strtotime(date('Y-m-d', $t) . ' 23:59:59'));

    $k = ['total' => 0, 'created' => 0, 'resolved' => 0, 'open' => 0, 'overdue' => 0, 'unassigned' => 0, 'due_soon' => 0, 'no_due' => 0,
          'on_time' => 0, 'late_done' => 0, 'in_progress' => 0, 'not_started' => 0];
    $dims = ['person' => [], 'type' => [], 'priority' => [], 'project' => [], 'status' => []];
    $resTimes = []; $hist = ['< 1 día' => 0, '1–3 días' => 0, '3–7 días' => 0, '1–2 semanas' => 0, '2–4 semanas' => 0, 'Más de 1 mes' => 0];
    $list = []; $stCat = [];
    foreach ($rows as $r) {
        $c = $r['created'] ? strtotime($r['created']) : null; $rv = $r['resolved'] ? strtotime($r['resolved']) : null;
        if ($c && $c > $tTo) continue;   // creado después del rango: no es de este informe
        $done = $r['cat'] === 'done';
        $createdIn = $c && $c >= $tFrom; $resolvedIn = $done && $rv && $rv >= $tFrom && $rv <= $tTo;
        $dueIn = $r['due'] !== '' && $r['due'] >= $from && $r['due'] <= $to;
        if (!$createdIn && !$resolvedIn && $done && !$dueIn) continue;   // terminado y sin nada en el rango
        $resDays = $done && $rv && $c && $rv >= $c ? round(($rv - $c) / 86400, 1) : null;
        if ($done) $sit = !$resolvedIn ? 'done_before' : ($r['due'] !== '' && date('Y-m-d', $rv) > $r['due'] ? 'late_done' : 'on_time');
        else $sit = $r['due'] !== '' && $r['due'] < $today ? 'overdue' : ($r['cat'] === 'indeterminate' ? 'in_progress' : 'not_started');
        $k['total']++;
        if ($createdIn) { $k['created']++; $series[$bucket($c)]['created']++; }
        if ($resolvedIn) {
            $k['resolved']++; $k[$sit]++; $series[$bucket($rv)]['resolved']++; $series[$bucket($rv)][$sit]++;
            if ($resDays !== null) {
                $resTimes[] = $resDays;
                $hist[$resDays < 1 ? '< 1 día' : ($resDays < 3 ? '1–3 días' : ($resDays < 7 ? '3–7 días' : ($resDays < 14 ? '1–2 semanas' : ($resDays < 30 ? '2–4 semanas' : 'Más de 1 mes'))))]++;
            }
        }
        if (!$done) {
            $k['open']++; $k[$sit]++;
            if ($r['assignee'] === '') $k['unassigned']++;
            if ($r['due'] === '') $k['no_due']++;
            elseif ($r['due'] >= $today && $r['due'] <= $soon) $k['due_soon']++;
        }
        // Pendiente al final de cada tramo: creado antes del fin y no resuelto todavía en ese momento
        foreach ($series as &$s) if ($c && $c <= $s['end'] && (!$done || !$rv || $rv > $s['end'])) $s['backlog']++;
        unset($s);
        // Agrupaciones (para Personas, Explorar y la exportación)
        foreach (['person' => $r['assignee'] ?: 'Sin asignar', 'type' => $r['type'], 'priority' => $r['priority'], 'project' => $r['project'], 'status' => $r['status']] as $dim => $name) {
            $g = &$dims[$dim][$name];
            $g ??= ['name' => $name, 'total' => 0, 'created' => 0, 'resolved' => 0, 'open' => 0, 'overdue' => 0, 'on_time' => 0, 'late_done' => 0, 'in_progress' => 0, 'not_started' => 0, 'res' => []];
            if ($dim === 'project') $g['label'] = $r['project_name'];
            $g['total']++;
            if ($createdIn) $g['created']++;
            if ($resolvedIn) { $g['resolved']++; $g[$sit]++; if ($resDays !== null) $g['res'][] = $resDays; }
            if (!$done) { $g['open']++; $g[$sit]++; }
            unset($g);
        }
        if (!$done) $stCat[$r['status']] = $r['cat'];
        $list[] = $r + ['situation' => $sit, 'age' => $c ? (int)floor(($now - $c) / 86400) : null, 'res_days' => $resDays,
            'late' => $sit === 'overdue' ? (int)floor(($now - strtotime($r['due'])) / 86400) : ($sit === 'late_done' ? (int)floor(($rv - strtotime($r['due'] . ' 23:59:59')) / 86400) + 1 : 0)];
    }
    sort($resTimes);
    $n = count($resTimes);
    $med = $n ? ($n % 2 ? $resTimes[intdiv($n, 2)] : ($resTimes[$n / 2 - 1] + $resTimes[$n / 2]) / 2) : null;
    $groups = [];
    foreach ($dims as $dim => $g) {
        $g = array_values(array_map(function ($x) use ($stCat) {
            $m = count($x['res']); $x['avg_days'] = $m ? round(array_sum($x['res']) / $m, 1) : null; unset($x['res']);
            $x['on_time_pct'] = $x['resolved'] ? (int)round($x['on_time'] / $x['resolved'] * 100) : null;
            if (isset($stCat[$x['name']])) $x['cat'] = $stCat[$x['name']];
            return $x;
        }, $g));
        usort($g, fn($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        $groups[$dim] = $g;
    }
    // Orden de la lista: atrasados primero (más días), después abiertos por antigüedad, después lo resuelto más reciente
    $ord = ['overdue' => 0, 'in_progress' => 1, 'not_started' => 2, 'late_done' => 3, 'on_time' => 4, 'done_before' => 5];
    usort($list, fn($a, $b) => [$ord[$a['situation']], -$a['late'], -($a['age'] ?? 0), $b['resolved']] <=> [$ord[$b['situation']], -$b['late'], -($b['age'] ?? 0), $a['resolved']]);
    $openByAge = array_values(array_filter($list, fn($x) => $x['cat'] !== 'done'));
    usort($openByAge, fn($a, $b) => ($b['age'] ?? 0) <=> ($a['age'] ?? 0));
    $byType = array_column(array_filter($groups['type'], fn($x) => $x['created'] > 0), 'created', 'name'); arsort($byType);
    $byPrio = array_column(array_filter($groups['priority'], fn($x) => $x['open'] > 0), 'open', 'name'); arsort($byPrio);
    return [
        'kpi' => $k + ['avg_days' => $n ? round(array_sum($resTimes) / $n, 1) : null, 'median_days' => $med === null ? null : round($med, 1),
            'flow' => $k['created'] ? (int)round($k['resolved'] / $k['created'] * 100) : null,
            'on_time_pct' => $k['resolved'] ? (int)round($k['on_time'] / $k['resolved'] * 100) : null],
        'from' => $from, 'to' => $to, 'gran' => $gran, 'weekly' => $gran !== 'day',
        'series' => array_values(array_map(function ($s) { unset($s['end']); return $s; }, $series)),
        'groups' => $groups, 'res_hist' => $hist, 'issues' => $list, 'today' => $today,
        // Lo de antes (tests y quien lo use)
        'by_type' => $byType, 'by_priority' => $byPrio,
        'by_status' => array_values(array_map(fn($x) => ['name' => $x['name'], 'cat' => $x['cat'] ?? 'new', 'n' => $x['open']], array_filter($groups['status'], fn($x) => $x['open'] > 0))),
        'people' => array_slice(array_map(fn($p) => ['name' => $p['name'], 'open' => $p['open'], 'resolved' => $p['resolved'], 'overdue' => $p['overdue'], 'avg_days' => $p['avg_days']], $groups['person']), 0, 15),
        'projects_rows' => array_map(fn($p) => ['key' => $p['name'], 'name' => $p['label'] ?? $p['name'], 'created' => $p['created'], 'resolved' => $p['resolved'], 'open' => $p['open']], $groups['project']),
        'oldest' => array_slice($openByAge, 0, 8),
        'overdue_list' => array_slice(array_values(array_filter($list, fn($x) => $x['situation'] === 'overdue')), 0, 8),
        'total' => count($rows),
    ];
}
