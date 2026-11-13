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

/** Un issue de Jira resumido a lo que usan las cuentas (también para la caché). Con expand=changelog guarda los cambios de estado. */
function g7_jira_issue_row(array $raw): array {
    $f = $raw['fields'] ?? [];
    $t = fn($s) => $s ? date('Y-m-d H:i', strtotime((string)$s) ?: 0) : '';
    $trans = [];   // cambios de estado, en orden: [fecha, estado al que pasó]
    foreach ((array)($raw['changelog']['histories'] ?? []) as $h)
        foreach ((array)($h['items'] ?? []) as $it)
            if (($it['field'] ?? '') === 'status' && isset($it['toString'])) $trans[] = [$t($h['created'] ?? null), (string)$it['toString']];
    usort($trans, fn($a, $b) => $a[0] <=> $b[0]);
    return ['key' => (string)($raw['key'] ?? ''), 'summary' => (string)($f['summary'] ?? ''), 'status' => (string)($f['status']['name'] ?? ''),
        'cat' => (string)($f['status']['statusCategory']['key'] ?? 'new'), 'type' => (string)($f['issuetype']['name'] ?? 'Otro'),
        'priority' => (string)($f['priority']['name'] ?? 'Sin prioridad'), 'assignee' => (string)($f['assignee']['displayName'] ?? ''),
        'reporter' => (string)($f['reporter']['displayName'] ?? ''), 'creator' => (string)($f['creator']['displayName'] ?? ''),
        'components' => array_values(array_filter(array_map(fn($c) => (string)($c['name'] ?? ''), (array)($f['components'] ?? [])), 'strlen')),
        'labels' => array_values(array_map('strval', (array)($f['labels'] ?? []))),
        'project' => (string)($f['project']['key'] ?? ''), 'project_name' => (string)($f['project']['name'] ?? ($f['project']['key'] ?? '')),
        'created' => $t($f['created'] ?? null), 'resolved' => $t($f['resolutiondate'] ?? null), 'due' => substr((string)($f['duedate'] ?? ''), 0, 10),
        'trans' => $trans];
}

// ── Qué cuenta como "entregado" ─────────────────────────────
// Jira da por terminado un pedido recién en un estado de la categoría "Listo" (verde). Si el flujo tiene un paso como
// "Pendiente de aprobación" (casi siempre azul, "En curso"), un pedido entregado a tiempo y aprobado días después quedaría
// atrasado. Por eso se elige qué estados cuentan como entregado y la fecha de entrega es cuando llegó a uno de ellos.
const G7_JS_DELIVER_AUTO = '/aprob|approv|revisi[oó]n del cliente|entregad|delivered/i';

/** Estados elegidos en Estadísticas › Jira (null = automático: los verdes + los que se llaman como aprobación o entregado). */
function g7_jira_stats_cfg(): array {
    $c = json_decode((string)g7_cfg('jira_stats_cfg', '{}'), true);
    $d = is_array($c['deliver'] ?? null) ? array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $c['deliver']), 'strlen'))) : null;
    return ['deliver' => $d];
}
function g7_jira_stats_cfg_save(?array $deliver): array {
    g7_cfg_set('jira_stats_cfg', json_encode(['deliver' => $deliver === null ? null : array_slice(array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 80), $deliver), 'strlen'))), 0, 40)], JSON_UNESCAPED_UNICODE));
    return g7_jira_stats_cfg();
}

/**
 * Issues del rango (resumidos), de Jira o de la caché. Lanza G7JiraError.
 * Devuelve ['issues' => [...], 'truncated' => bool, 'at' => timestamp de cuando se trajeron].
 */
function g7_jira_fetch(array $projects, string $from, string $to, bool $fresh = false): array {
    $dir = __DIR__ . '/_private/cache';
    $file = $dir . '/jira_stats_v2_' . substr(md5(implode(',', $projects) . "|$from|$to"), 0, 20) . '.json';
    if (!$fresh && is_file($file) && filemtime($file) > time() - G7_JS_TTL) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && isset($c['issues'])) return $c;
    }
    $toNext = date('Y-m-d', strtotime($to . ' +1 day'));
    $jql = g7_jira_jql_in($projects) . " AND ((created >= \"$from\" AND created < \"$toNext\") OR (resolved >= \"$from\" AND resolved < \"$toNext\")"
         . " OR (duedate >= \"$from\" AND duedate <= \"$to\") OR (updated >= \"$from\" AND statusCategory = Done) OR statusCategory != Done) ORDER BY created DESC";
    $fields = ['summary', 'status', 'issuetype', 'priority', 'assignee', 'reporter', 'creator', 'components', 'labels', 'created', 'resolutiondate', 'duedate', 'project'];
    $issues = []; $next = ''; $truncated = false;
    do {
        $r = g7_jira_search($jql, $fields, 100, $next, 'changelog');
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
    return g7_jira_stats($f['issues'], $range['from'], $range['to'], time(), g7_jira_stats_cfg())
        + ['projects' => $projects, 'range' => $range, 'truncated' => $f['truncated'], 'site' => g7_jira_cfg()['site'], 'cached_at' => date('Y-m-d H:i:s', (int)$f['at'])];
}

/** Compatibilidad: informe de los últimos $days días a partir de issues tal como vienen de Jira. */
function g7_jira_report_build(array $issues, int $days, int $now): array {
    return g7_jira_stats(array_map('g7_jira_issue_row', $issues), date('Y-m-d', $now - ($days - 1) * 86400), date('Y-m-d', $now), $now);
}

/** Tramos del plazo que se dio (de creado a la fecha de entrega) y del tiempo de resolución. */
const G7_JS_LEAD = ['Vencido al crearse', 'El mismo día', '1 día', '2–3 días', '4–7 días', '8–14 días', '15–30 días', 'Más de 30 días'];
function g7_jira_lead_bucket(int $d): string { return $d < 0 ? G7_JS_LEAD[0] : ($d === 0 ? G7_JS_LEAD[1] : ($d === 1 ? G7_JS_LEAD[2] : ($d <= 3 ? G7_JS_LEAD[3] : ($d <= 7 ? G7_JS_LEAD[4] : ($d <= 14 ? G7_JS_LEAD[5] : ($d <= 30 ? G7_JS_LEAD[6] : G7_JS_LEAD[7])))))); }
function g7_jira_median(array $v): ?float { if (!$v) return null; sort($v); $n = count($v); return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1); }

/**
 * Las cuentas (separadas para poder probarlas sin Jira). $rows: issues resumidos (g7_jira_issue_row). Rango de días cerrado [$from, $to].
 * $opt['deliver']: estados que cuentan como entregado (null = automático). "Entregado" = estado verde o uno de esos; la fecha de
 * entrega es la última vez que llegó a esos estados desde uno de trabajo (si lo devolvieron, cuenta la entrega nueva).
 * "Atrasado" se mide contra hoy ($now): lo que sigue sin entregar con la fecha de entrega vencida.
 */
function g7_jira_stats(array $rows, string $from, string $to, int $now, array $opt = []): array {
    $today = date('Y-m-d', $now); $soon = date('Y-m-d', strtotime($today . ' +7 days'));
    $tFrom = strtotime($from . ' 00:00'); $tTo = strtotime($to . ' 23:59:59');
    $days = (int)round(($tTo - $tFrom) / 86400);
    $gran = $days > 120 ? 'month' : ($days > 45 ? 'week' : 'day');
    $bucket = fn(int $t) => $gran === 'month' ? date('Y-m', $t) : ($gran === 'week' ? date('o-\WW', $t) : date('Y-m-d', $t));
    $series = []; $cal = [];
    for ($t = $tFrom; $t <= $tTo; $t += 86400) {
        $series[$bucket($t)] ??= ['label' => $bucket($t), 'end' => 0, 'created' => 0, 'resolved' => 0, 'on_time' => 0, 'late_done' => 0, 'backlog' => 0];
        $series[$bucket($t)]['end'] = min($tTo, strtotime(date('Y-m-d', $t) . ' 23:59:59'));   // fin de cada tramo (para el pendiente acumulado)
        $cal[date('Y-m-d', $t)] = ['created' => 0, 'due' => 0, 'delivered' => 0];
    }
    // Estados que cuentan como entregado
    $auto = !isset($opt['deliver']) || $opt['deliver'] === null;
    $want = $auto ? [] : array_flip(array_map('mb_strtolower', $opt['deliver']));
    $isDeliver = fn(string $name, string $cat = '') => $cat === 'done' || ($auto ? (bool)preg_match(G7_JS_DELIVER_AUTO, $name) : isset($want[mb_strtolower($name)]));
    $seen = [];   // estados vistos (actuales o en el historial) y su categoría si se conoce

    $k = ['total' => 0, 'created' => 0, 'resolved' => 0, 'open' => 0, 'overdue' => 0, 'unassigned' => 0, 'due_soon' => 0, 'no_due' => 0,
          'on_time' => 0, 'late_done' => 0, 'in_progress' => 0, 'not_started' => 0, 'awaiting' => 0, 'due_weekend' => 0, 'created_weekend' => 0, 'with_due' => 0];
    $dims = ['person' => [], 'type' => [], 'priority' => [], 'project' => [], 'status' => [], 'reporter' => [], 'component' => [], 'label' => []];
    $resTimes = []; $hist = ['< 1 día' => 0, '1–3 días' => 0, '3–7 días' => 0, '1–2 semanas' => 0, '2–4 semanas' => 0, 'Más de 1 mes' => 0];
    $leads = []; $leadHist = array_fill_keys(G7_JS_LEAD, 0); $approval = [];
    $list = []; $stCat = [];
    foreach ($rows as $r) {
        $r += ['reporter' => '', 'creator' => '', 'components' => [], 'labels' => [], 'trans' => []];
        $c = $r['created'] ? strtotime($r['created']) : null; $rv = $r['resolved'] ? strtotime($r['resolved']) : null;
        if ($c && $c > $tTo) continue;   // creado después del rango: no es de este informe
        $seen[$r['status']] = $r['cat'];
        foreach ($r['trans'] as [, $st]) $seen[$st] ??= '';
        // ¿Entregado? Estado actual verde o de entrega. Fecha: la última vez que llegó a esos estados desde uno de trabajo.
        $delivered = $isDeliver($r['status'], $r['cat']);
        $dAt = null;
        if ($delivered) {
            $in = false;
            foreach ($r['trans'] as [$when, $st]) { $now2 = $isDeliver($st, $st === $r['status'] ? $r['cat'] : ''); if ($now2 && !$in) $dAt = strtotime($when); $in = $now2; }
            if (!$in) $dAt = null;   // el historial no termina en un estado de entrega (incompleto): se usa la resolución
            $dAt = $dAt ?: ($rv ?: null);
        }
        $done = $r['cat'] === 'done';
        $createdIn = $c && $c >= $tFrom; $dIn = $delivered && $dAt && $dAt >= $tFrom && $dAt <= $tTo;
        $dueIn = $r['due'] !== '' && $r['due'] >= $from && $r['due'] <= $to;
        if (!$createdIn && !$dIn && $delivered && !$dueIn) continue;   // entregado y sin nada en el rango
        $resDays = $delivered && $dAt && $c && $dAt >= $c ? round(($dAt - $c) / 86400, 1) : null;
        if ($delivered) {
            if ($dAt === null) $sit = $r['due'] === '' || $r['due'] >= $today ? 'on_time' : 'late_done';   // sin fecha de entrega conocida (raro)
            else $sit = !$dIn ? 'done_before' : ($r['due'] !== '' && date('Y-m-d', $dAt) > $r['due'] ? 'late_done' : 'on_time');
        } else $sit = $r['due'] !== '' && $r['due'] < $today ? 'overdue' : ($r['cat'] === 'indeterminate' ? 'in_progress' : 'not_started');
        $awaiting = $delivered && !$done;   // entregado y esperando aprobación (no está en un estado verde)
        $apDays = $done && $dAt && $rv && $rv > $dAt ? round(($rv - $dAt) / 86400, 1) : null;
        $lead = $r['due'] !== '' && $c ? (int)round((strtotime($r['due']) - strtotime(date('Y-m-d', $c))) / 86400) : null;
        $dueWk = $r['due'] !== '' && (int)date('N', strtotime($r['due'])) >= 6;
        $crWk = $c && (int)date('N', $c) >= 6;
        $k['total']++;
        if ($createdIn) { $k['created']++; $series[$bucket($c)]['created']++; $cal[date('Y-m-d', $c)]['created']++; if ($crWk) $k['created_weekend']++; }
        if ($dueIn) { $cal[$r['due']]['due']++; if ($dueWk) $k['due_weekend']++; }
        if ($lead !== null && $createdIn) { $k['with_due']++; $leads[] = $lead; $leadHist[g7_jira_lead_bucket($lead)]++; }
        if ($dIn) {
            $k['resolved']++; $k[$sit]++; $series[$bucket($dAt)]['resolved']++; $series[$bucket($dAt)][$sit]++; $cal[date('Y-m-d', $dAt)]['delivered']++;
            if ($resDays !== null) {
                $resTimes[] = $resDays;
                $hist[$resDays < 1 ? '< 1 día' : ($resDays < 3 ? '1–3 días' : ($resDays < 7 ? '3–7 días' : ($resDays < 14 ? '1–2 semanas' : ($resDays < 30 ? '2–4 semanas' : 'Más de 1 mes'))))]++;
            }
            if ($apDays !== null) $approval[] = $apDays;
        }
        if ($awaiting) $k['awaiting']++;
        if (!$delivered) {
            $k['open']++; $k[$sit]++;
            if ($r['assignee'] === '') $k['unassigned']++;
            if ($r['due'] === '') $k['no_due']++;
            elseif ($r['due'] >= $today && $r['due'] <= $soon) $k['due_soon']++;
        }
        // Pendiente al final de cada tramo: creado antes del fin y no entregado todavía en ese momento
        foreach ($series as &$s) if ($c && $c <= $s['end'] && (!$delivered || !$dAt || $dAt > $s['end'])) $s['backlog']++;
        unset($s);
        // Agrupaciones (Personas, Origen, Explorar y la exportación). Componentes y etiquetas: un issue cuenta en cada uno de los suyos.
        $by = ['person' => [$r['assignee'] ?: 'Sin asignar'], 'type' => [$r['type']], 'priority' => [$r['priority']], 'project' => [$r['project']], 'status' => [$r['status']],
            'reporter' => [$r['reporter'] ?: 'Sin informante'], 'component' => $r['components'] ?: ['Sin componente'], 'label' => $r['labels'] ?: ['Sin etiqueta']];
        foreach ($by as $dim => $names) foreach ($names as $name) {
            $g = &$dims[$dim][$name];
            $g ??= ['name' => $name, 'total' => 0, 'created' => 0, 'resolved' => 0, 'open' => 0, 'overdue' => 0, 'on_time' => 0, 'late_done' => 0, 'in_progress' => 0, 'not_started' => 0,
                'awaiting' => 0, 'due_weekend' => 0, 'res' => [], 'lead' => []];
            if ($dim === 'project') $g['label'] = $r['project_name'];
            $g['total']++;
            if ($createdIn) $g['created']++;
            if ($dIn) { $g['resolved']++; $g[$sit]++; if ($resDays !== null) $g['res'][] = $resDays; }
            if (!$delivered) { $g['open']++; $g[$sit]++; }
            if ($awaiting) $g['awaiting']++;
            if ($dueIn && $dueWk) $g['due_weekend']++;
            if ($lead !== null && $createdIn) $g['lead'][] = $lead;
            unset($g);
        }
        if (!$delivered) $stCat[$r['status']] = $r['cat'];
        $list[] = array_diff_key($r, ['trans' => 1]) + ['situation' => $sit, 'age' => $c ? (int)floor(($now - $c) / 86400) : null, 'res_days' => $resDays,
            'delivered_at' => $dAt ? date('Y-m-d H:i', $dAt) : '', 'awaiting' => $awaiting, 'approval_days' => $apDays, 'lead' => $lead,
            'due_weekend' => $dueWk, 'created_weekend' => (bool)$crWk,
            'late' => $sit === 'overdue' ? (int)floor(($now - strtotime($r['due'])) / 86400) : ($sit === 'late_done' && $dAt ? (int)floor(($dAt - strtotime($r['due'] . ' 23:59:59')) / 86400) + 1 : 0)];
    }
    $n = count($resTimes);
    $groups = [];
    foreach ($dims as $dim => $g) {
        $g = array_values(array_map(function ($x) use ($stCat) {
            $m = count($x['res']); $x['avg_days'] = $m ? round(array_sum($x['res']) / $m, 1) : null;
            $l = count($x['lead']); $x['avg_lead'] = $l ? round(array_sum($x['lead']) / $l, 1) : null; $x['median_lead'] = g7_jira_median($x['lead']);
            unset($x['res'], $x['lead']);
            $x['on_time_pct'] = $x['resolved'] ? (int)round($x['on_time'] / $x['resolved'] * 100) : null;
            if (isset($stCat[$x['name']])) $x['cat'] = $stCat[$x['name']];
            return $x;
        }, $g));
        usort($g, fn($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        $groups[$dim] = $g;
    }
    // Orden de la lista: atrasados primero (más días), después abiertos por antigüedad, después lo entregado más reciente
    $ord = ['overdue' => 0, 'in_progress' => 1, 'not_started' => 2, 'late_done' => 3, 'on_time' => 4, 'done_before' => 5];
    usort($list, fn($a, $b) => [$ord[$a['situation']], -$a['late'], -($a['age'] ?? 0), $b['delivered_at']] <=> [$ord[$b['situation']], -$b['late'], -($b['age'] ?? 0), $a['delivered_at']]);
    $openByAge = array_values(array_filter($list, fn($x) => in_array($x['situation'], ['overdue', 'in_progress', 'not_started'], true)));
    usort($openByAge, fn($a, $b) => ($b['age'] ?? 0) <=> ($a['age'] ?? 0));
    $byType = array_column(array_filter($groups['type'], fn($x) => $x['created'] > 0), 'created', 'name'); arsort($byType);
    $byPrio = array_column(array_filter($groups['priority'], fn($x) => $x['open'] > 0), 'open', 'name'); arsort($byPrio);
    ksort($seen);
    return [
        'kpi' => $k + ['avg_days' => $n ? round(array_sum($resTimes) / $n, 1) : null, 'median_days' => g7_jira_median($resTimes),
            'flow' => $k['created'] ? (int)round($k['resolved'] / $k['created'] * 100) : null,
            'on_time_pct' => $k['resolved'] ? (int)round($k['on_time'] / $k['resolved'] * 100) : null,
            'avg_lead' => $leads ? round(array_sum($leads) / count($leads), 1) : null, 'median_lead' => g7_jira_median($leads),
            'avg_approval' => $approval ? round(array_sum($approval) / count($approval), 1) : null],
        'from' => $from, 'to' => $to, 'gran' => $gran, 'weekly' => $gran !== 'day',
        'series' => array_values(array_map(function ($s) { unset($s['end']); return $s; }, $series)),
        'calendar' => $cal, 'lead_hist' => $leadHist,
        'groups' => $groups, 'res_hist' => $hist, 'issues' => $list, 'today' => $today,
        'deliver' => ['auto' => $auto, 'states' => array_values(array_filter(array_keys($seen), fn($s) => $isDeliver($s, $seen[$s]))), 'seen' => $seen],
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
