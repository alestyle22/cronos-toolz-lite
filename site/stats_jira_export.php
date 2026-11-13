<?php
/**
 * stats_jira_export.php — Exporta el informe de Estadísticas › Jira con las opciones elegidas (stats_jira.php › Exportar informe).
 * Se carga desde stats.php (?section=jira&export=1&…): ya pidió login, CSP y el permiso de Estadísticas.
 * fmt=csv: Excel (UTF-8 con BOM y ";", como lo abre Excel en español). fmt=pdf: página para imprimir o guardar como PDF.
 * sec[]: kpi, summary, series, person, project, type, priority, status, times, issues. Lista: only (situación o filtro), col[], sort.
 * Usa el mismo informe y la misma caché que la página (g7_jira_report). Doc: toolz/JIRA.md
 */
if (!isset($currentUser) || !function_exists('g7_jira_report')) { http_response_code(404); exit; }
$all = g7_jira_features()['projects'];
$pick = array_values(array_intersect(array_filter(explode(',', (string)($_GET['jp'] ?? ''))), $all));
$range = g7_jira_range($_GET, 30);
try { $J = g7_jira_report($pick, $range); }
catch (Throwable $e) { http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); echo $e instanceof G7JiraError ? $e->getMessage() : 'No se pudo armar el informe de Jira.'; exit; }

const JX_SECS = ['kpi' => 'Indicadores', 'summary' => 'Resumen de pedidos', 'series' => 'Evolución en el tiempo', 'person' => 'Por persona', 'project' => 'Por proyecto',
    'type' => 'Por tipo', 'priority' => 'Por prioridad', 'status' => 'Por estado', 'times' => 'Tiempos de resolución', 'issues' => 'Lista de issues'];
const JX_COLS = ['key' => 'Clave', 'summary' => 'Título', 'situation' => 'Situación', 'status' => 'Estado', 'type' => 'Tipo', 'priority' => 'Prioridad', 'assignee' => 'Responsable',
    'project' => 'Proyecto', 'created' => 'Creado', 'due' => 'Fecha de entrega', 'resolved' => 'Resuelto', 'res_days' => 'Días hasta resolver', 'late' => 'Días de atraso', 'url' => 'Link a Jira'];
$secs = array_values(array_intersect(array_keys(JX_SECS), (array)($_GET['sec'] ?? []))) ?: ['kpi', 'summary', 'person', 'issues'];
$cols = array_values(array_intersect(array_keys(JX_COLS), (array)($_GET['col'] ?? []))) ?: ['key', 'summary', 'situation', 'status', 'assignee', 'due'];
$fmt = ($_GET['fmt'] ?? '') === 'pdf' ? 'pdf' : 'csv';
$only = (string)($_GET['only'] ?? ''); $sort = (string)($_GET['sort'] ?? '');
$k = $J['kpi']; $n = fn($v) => $v === null ? '' : str_replace('.', ',', (string)$v);
$projTxt = implode(', ', $J['projects']);

// ── Contenido (lo mismo para los dos formatos): [título, encabezados, filas, barra (índice de columna a dibujar en el PDF o null)] ──
$blocks = [];
if (in_array('kpi', $secs, true)) $blocks[] = ['Indicadores', ['Indicador', 'Valor'], [
    ['Creados en el rango', $k['created']], ['Resueltos en el rango', $k['resolved']], ['Resueltos sobre creados', $k['flow'] === null ? '' : $k['flow'] . '%'],
    ['Entregados a tiempo', $k['on_time_pct'] === null ? '' : $k['on_time_pct'] . '%'], ['Abiertos hoy', $k['open']], ['Abiertos sin asignar', $k['unassigned']],
    ['Atrasados hoy', $k['overdue']], ['Días promedio para resolver', $n($k['avg_days'])], ['Mediana de días para resolver', $n($k['median_days'])]], null];
if (in_array('summary', $secs, true)) {
    $rows = []; foreach (['on_time', 'late_done', 'overdue', 'in_progress', 'not_started'] as $s) $rows[] = [G7_JS_SIT[$s][0], (int)$k[$s]];
    $rows[] = ['Vencen en los próximos 7 días', $k['due_soon']]; $rows[] = ['Abiertos sin fecha de entrega', $k['no_due']];
    $blocks[] = ['Resumen de pedidos', ['Situación', 'Cantidad'], $rows, 1];
}
if (in_array('series', $secs, true)) $blocks[] = ['Evolución ' . ['day' => 'por día', 'week' => 'por semana', 'month' => 'por mes'][$J['gran']],
    ['Período', 'Creados', 'Resueltos', 'A tiempo', 'Tarde', 'Pendientes al cierre'],
    array_map(fn($s) => [$s['label'], $s['created'], $s['resolved'], $s['on_time'], $s['late_done'], $s['backlog']], $J['series']), 1];
foreach (['person' => 'Persona', 'project' => 'Proyecto', 'type' => 'Tipo', 'priority' => 'Prioridad', 'status' => 'Estado'] as $dim => $lbl) {
    if (!in_array($dim, $secs, true)) continue;
    $blocks[] = [JX_SECS[$dim], [$lbl, 'Total', 'Creados', 'Abiertos', 'Atrasados', 'En curso', 'Sin empezar', 'Entregados', 'A tiempo', 'Tarde', '% a tiempo', 'Días prom.'],
        array_map(fn($g) => [$dim === 'project' && ($g['label'] ?? '') !== '' && $g['label'] !== $g['name'] ? $g['name'] . ' · ' . $g['label'] : $g['name'], $g['total'], $g['created'], $g['open'], $g['overdue'],
            $g['in_progress'], $g['not_started'], $g['resolved'], $g['on_time'], $g['late_done'], $g['on_time_pct'] === null ? '' : $g['on_time_pct'] . '%', $n($g['avg_days'])], $J['groups'][$dim]), 1];
}
if (in_array('times', $secs, true)) {
    $blocks[] = ['Tiempos de resolución (lo entregado en el rango)', ['Tardó', 'Issues'], array_map(fn($a, $b) => [$a, $b], array_keys($J['res_hist']), $J['res_hist']), 1];
    $blocks[] = ['Días promedio por tipo', ['Tipo', 'Entregados', 'Días prom.'], array_map(fn($g) => [$g['name'], $g['resolved'], $n($g['avg_days'])], array_values(array_filter($J['groups']['type'], fn($g) => $g['avg_days'] !== null))), null];
}
if (in_array('issues', $secs, true)) {
    $soon = date('Y-m-d', strtotime($J['today'] . ' +7 days'));
    $list = array_values(array_filter($J['issues'], function ($i) use ($only, $J, $soon) {
        if ($only === '') return true;
        if ($only === 'open') return $i['cat'] !== 'done';
        if ($only === 'done') return in_array($i['situation'], ['on_time', 'late_done'], true);
        if ($only === 'due_soon') return $i['cat'] !== 'done' && $i['due'] !== '' && $i['due'] >= $J['today'] && $i['due'] <= $soon;
        return $i['situation'] === $only;
    }));
    if (in_array($sort, ['due', 'created', 'resolved', 'assignee', 'key'], true))
        usort($list, fn($a, $b) => [$a[$sort] === '' ? '~' : $a[$sort], $a['key']] <=> [$b[$sort] === '' ? '~' : $b[$sort], $b['key']]);
    $cell = fn($i, $c) => match ($c) {
        'situation' => G7_JS_SIT[$i['situation']][0], 'assignee' => $i['assignee'] ?: 'Sin asignar', 'project' => $i['project'],
        'res_days' => $n($i['res_days']), 'late' => $i['late'] ?: '', 'url' => $J['site'] . '/browse/' . rawurlencode($i['key']),
        default => (string)$i[$c],
    };
    $blocks[] = ['Lista de issues' . ($only !== '' ? ' · ' . (G7_JS_SIT[$only][0] ?? ['open' => 'abiertos', 'done' => 'entregados en el rango', 'due_soon' => 'vencen en 7 días'][$only] ?? '') : '') . ' (' . count($list) . ')',
        array_map(fn($c) => JX_COLS[$c], $cols), array_map(fn($i) => array_map(fn($c) => $cell($i, $c), $cols), $list), null];
}
$file = 'jira_' . $range['from'] . '_' . $range['to'];

// ── Excel (CSV) ──────────────────────────────────────────────
if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '.csv"');
    header('Cache-Control: no-store');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    // Una celda que empieza con = + - @ se toma como fórmula en Excel: se le antepone un apóstrofo
    $safe = fn(array $r) => array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v, $r);
    fputcsv($o, ['Informe de Jira', $range['label']], ';');
    fputcsv($o, ['Proyectos', $projTxt], ';');
    fputcsv($o, ['Generado', date('Y-m-d H:i') . ' por ' . ($currentUser['display_name'] ?: $currentUser['username'])], ';');
    foreach ($blocks as [$title, $head, $rows]) {
        fputcsv($o, [], ';'); fputcsv($o, [$title], ';'); fputcsv($o, $head, ';');
        foreach ($rows as $r) fputcsv($o, $safe($r), ';');
    }
    fclose($o);
    exit;
}

// ── PDF (página para imprimir) ───────────────────────────────
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h('Informe de Jira · ' . $range['label']) ?></title>
<style>
*{box-sizing:border-box}body{font-family:'Inter',Segoe UI,Arial,sans-serif;color:#111;background:#fff;margin:0;padding:28px;font-size:11.5px}
.bar{display:flex;gap:8px;justify-content:flex-end;margin-bottom:14px}.bar button{border:1px solid #ccc;background:#f4f4f5;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer}
.bar button.p{background:#111;color:#fff;border-color:#111}
h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;margin:22px 0 8px;padding-bottom:5px;border-bottom:2px solid #111;page-break-after:avoid}
.sub{color:#555;margin:0 0 2px}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.kpi{border:1px solid #ddd;border-radius:8px;padding:10px}.kpi b{display:block;font-size:20px}.kpi span{color:#555}
table{width:100%;border-collapse:collapse;page-break-inside:auto}tr{page-break-inside:avoid}
th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.3px;color:#555;border-bottom:1px solid #999;padding:5px 6px}
td{border-bottom:1px solid #e5e5e5;padding:5px 6px;vertical-align:top}td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.b{display:flex;align-items:center;gap:6px}.b i{display:block;height:8px;background:#4c9aff;border-radius:3px;min-width:1px}
@media print{body{padding:0}.bar{display:none}@page{margin:14mm}}
</style></head><body>
<div class="bar"><button type="button" id="jx-close">Cerrar</button><button type="button" class="p" id="jx-print">Imprimir o guardar como PDF</button></div>
<h1>Informe de Jira</h1>
<p class="sub"><b><?= $h($range['label']) ?></b> · proyectos <?= $h($projTxt) ?></p>
<p class="sub">Generado el <?= $h(date('d/m/Y H:i')) ?> por <?= $h($currentUser['display_name'] ?: $currentUser['username']) ?> · datos de Jira de las <?= $h(substr((string)$J['cached_at'], 11, 5)) ?></p>
<?php foreach ($blocks as [$title, $head, $rows, $barCol]): ?>
  <h2><?= $h($title) ?></h2>
  <?php if ($title === 'Indicadores'): ?>
    <div class="kpis"><?php foreach ($rows as [$l, $v]): ?><div class="kpi"><b><?= $h($v === '' ? '—' : $v) ?></b><span><?= $h($l) ?></span></div><?php endforeach; ?></div>
  <?php elseif (!$rows): ?><p class="sub">Sin datos en el rango.</p>
  <?php else: $mx = $barCol !== null ? max(1, ...array_map(fn($r) => (float)$r[$barCol], $rows)) : 1; ?>
    <table><tr><?php foreach ($head as $i => $c): ?><th<?= $i ? ' style="text-align:right"' : '' ?>><?= $h($c) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $i => $v): ?>
      <?php if ($i === $barCol): ?><td class="n"><span class="b" style="justify-content:flex-end"><i style="width:<?= round((float)$v / $mx * 120) ?>px"></i><?= $h($v) ?></span></td>
      <?php elseif ($title !== '' && str_starts_with($title, 'Lista') && ($head[$i] ?? '') === 'Link a Jira'): ?><td><?= $h($v) ?></td>
      <?php else: ?><td<?= $i && (is_int($v) || preg_match('/^[\d,%]+$/', (string)$v)) ? ' class="n"' : '' ?>><?= $h($v) ?></td><?php endif; ?>
    <?php endforeach; ?></tr><?php endforeach; ?></table>
  <?php endif; ?>
<?php endforeach; ?>
<script src="/toolz/assets/stats-jira-print.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-jira-print.js') ?>"></script>
</body></html>
<?php exit;
