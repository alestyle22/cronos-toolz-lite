<?php
/**
 * Reporte del mes de Producción, para imprimir o guardar como PDF.
 * Mismos números que Estadísticas › Producción (stats_produccion.php).
 * stats_reporte.php?mes=2026-10[&proyecto=mimarca.com]
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$currentUser = requireLogin();
$db = getDB();
g7_brand_scope_readonly((int)$currentUser['id']);
require_once __DIR__ . '/stats_produccion.php';

$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$mesDt = DateTime::createFromFormat('!Y-m', $mes);
$proyecto = g7_prod_canon((string)($_GET['proyecto'] ?? ''));
$pm = g7_prod_mes($db, $mesDt, $proyecto);
['data' => $data, 'tot' => $tot] = $pm;

$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$mesLabel = ucfirst($meses[(int)$mesDt->format('n') - 1]) . ' ' . $mesDt->format('Y');

// Landings del mes (por plataforma, unificada)
$ls = $db->prepare("SELECT platform, published_at FROM g7_landings WHERE created_at >= ? AND created_at < ?");
$ls->execute([$mesDt->format('Y-m-01'), (clone $mesDt)->modify('first day of next month')->format('Y-m-d')]);
$landByProj = []; $landTot = 0; $landPub = 0;
foreach ($ls->fetchAll() as $l) {
    $c = g7_prod_canon((string)$l['platform']);
    if ($proyecto !== '' && $c !== $proyecto) continue;
    $landByProj[$c] = ($landByProj[$c] ?? 0) + 1; $landTot++; if (!empty($l['published_at'])) $landPub++;
}

// Producción por diseñador (autor del contenido)
$porAutor = [];
foreach ($pm['contenidos'] as $c) {
    $a = $c['author'] ?: 'Sin autor';
    $porAutor[$a] ??= ['contenidos' => 0, 'graficas' => 0, 'videos' => 0, 'revisiones' => 0, 'rechazos' => 0, 'complicaciones' => 0];
    $porAutor[$a]['contenidos']++; $porAutor[$a]['graficas'] += $c['graficas']; $porAutor[$a]['videos'] += $c['videos'];
    if ($c['edicion'] === 'revision') $porAutor[$a]['revisiones']++;
    if ($c['edicion'] === 'rechazo') $porAutor[$a]['rechazos']++;
    if ($c['complicaciones']) $porAutor[$a]['complicaciones']++;
}
uasort($porAutor, fn($x, $y) => ($y['graficas'] + $y['videos']) <=> ($x['graficas'] + $x['videos']));

$fq = fn($n) => abs($n - round($n)) < 0.05 ? number_format(round($n), 0, ',', '.') : number_format($n, 1, ',', '.');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$typeLabels = ['promo' => 'Promo', 'launch' => 'Lanzamiento', 'paid' => 'Paid', 'social' => 'Redes', 'otro' => 'Otro', 'custom' => 'Custom', 'tarea' => 'Tarea rápida', 'mailings' => 'Mailings'];
$nRev = count(array_filter($pm['ediciones'], fn($e) => $e['tipo'] === 'revision'));
$nRech = count($pm['ediciones']) - $nRev;
$pctRech = $tot['total_contenidos'] ? round($tot['rechazos'] / $tot['total_contenidos'] * 100, 1) : 0;
$who = $currentUser['username'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reporte de producción · <?= $h($mesLabel) ?><?= $proyecto ? ' · ' . $h($proyecto) : '' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--ink:#16161a;--ink2:#4a4a55;--ink3:#80808c;--line:#e4e4ea;--soft:#f6f6f8;--acc:#e05500;--amber:#c27803;--red:#c81e1e}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Geist',system-ui,sans-serif;color:var(--ink);background:#eceef1;font-size:12px;line-height:1.45;-webkit-font-smoothing:antialiased}
.page{max-width:960px;margin:24px auto;background:#fff;padding:40px 44px;border-radius:12px;box-shadow:0 2px 24px rgba(0,0,0,.08)}
.bar{max-width:960px;margin:20px auto 0;display:flex;gap:8px;justify-content:flex-end}
.bar button,.bar a{font:600 12px 'Geist',sans-serif;border:1px solid #d4d4dc;background:#fff;color:var(--ink);border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none}
.bar button.primary{background:var(--acc);border-color:var(--acc);color:#fff}
header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;border-bottom:2px solid var(--ink);padding-bottom:14px;margin-bottom:22px}
h1{font-size:24px;font-weight:800;letter-spacing:-.02em}
h1 span{color:var(--acc)}
.meta{font-size:11px;color:var(--ink3);text-align:right}
h2{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin:26px 0 10px;color:var(--ink)}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}
.kpi{background:var(--soft);border-radius:9px;padding:11px 13px}
.kpi b{display:block;font-size:20px;font-weight:800;letter-spacing:-.01em;font-variant-numeric:tabular-nums}
.kpi span{font-size:10.5px;color:var(--ink2)}
table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
th{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink3);text-align:right;padding:6px 8px;border-bottom:1px solid var(--ink)}
td{padding:6px 8px;border-bottom:1px solid var(--line);text-align:right;vertical-align:top}
th:first-child,td:first-child,.l{text-align:left}
tfoot td{font-weight:800;border-top:1.5px solid var(--ink);border-bottom:none}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.reason{display:flex;justify-content:space-between;gap:10px;padding:4px 0;border-bottom:1px dotted var(--line)}
.reason b{font-variant-numeric:tabular-nums}
.sub{font-size:10.5px;font-weight:700;margin-bottom:6px}
.empty{color:var(--ink3);font-style:italic}
.tag-rev{color:var(--amber);font-weight:700}.tag-rech{color:var(--red);font-weight:700}
.muted{color:var(--ink3)}
footer{margin-top:28px;padding-top:10px;border-top:1px solid var(--line);font-size:10px;color:var(--ink3);display:flex;justify-content:space-between}
@media print{
  body{background:#fff}
  .bar{display:none}
  .page{margin:0;max-width:none;box-shadow:none;border-radius:0;padding:0}
  h2{break-after:avoid}
  tr,.kpi{break-inside:avoid}
  @page{size:A4;margin:14mm}
}
@media (max-width:720px){.page{padding:22px 16px}.kpis{grid-template-columns:repeat(2,1fr)}.grid3{grid-template-columns:1fr}header{flex-direction:column;align-items:flex-start}.meta{text-align:left}}
</style>
</head>
<body>
<div class="bar">
  <a href="stats.php?section=produccion&mes=<?= $h($mes) ?>&proyecto=<?= urlencode($proyecto) ?>">Volver a Estadísticas</a>
  <button class="primary" data-print>Imprimir o guardar PDF</button>
</div>
<div class="page">
  <header>
    <div>
      <div style="font-size:11px;font-weight:600;color:var(--ink3);margin-bottom:4px"><?= $h(g7_site('short')) ?> · Reporte de producción</div>
      <h1><?= $h($mesLabel) ?><?= $proyecto ? ' <span>· ' . $h($proyecto) . '</span>' : '' ?></h1>
    </div>
    <div class="meta">Generado el <?= date('d/m/Y H:i') ?><br>por <?= $h($who) ?></div>
  </header>

  <h2>Resumen</h2>
  <div class="kpis">
    <div class="kpi"><b><?= (int)$tot['total_contenidos'] ?></b><span>Contenidos cargados</span></div>
    <div class="kpi"><b><?= $fq($tot['total_assets']) ?></b><span>Assets (gráficas + videos)</span></div>
    <div class="kpi"><b><?= $fq($tot['graficas']) ?></b><span>Gráficas</span></div>
    <div class="kpi"><b><?= $fq($tot['videos']) ?></b><span>Videos</span></div>
    <div class="kpi"><b><?= $landTot ?></b><span>Landings (<?= $landPub ?> publicadas)</span></div>
    <div class="kpi"><b><?= $fq($tot['tareas']) ?></b><span>Tareas</span></div>
    <div class="kpi"><b><?= $fq($tot['qty_mailings_total']) ?></b><span>Mailings</span></div>
    <div class="kpi"><b><?= $nRev ?></b><span>Revisiones</span></div>
    <div class="kpi"><b><?= $nRech ?></b><span>Rechazos (<?= $pctRech ?>% de los contenidos)</span></div>
    <div class="kpi"><b><?= (int)$pm['conComplicaciones'] ?></b><span>Pedidos con complicaciones</span></div>
  </div>

  <h2>Producción por proyecto</h2>
  <?php if (!$data): ?><p class="empty">No hay contenidos cargados en este mes.</p><?php else: ?>
  <table>
    <thead><tr><th>Proyecto</th><th>Contenidos</th><th>Gráficas</th><th>Videos</th><th>Total</th><th>Tareas</th><th>Mailings</th><th>Landings</th><th>Revisiones</th><th>Rechazos</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r): ?>
      <tr>
        <td><?= $h($r['project'] ?: 'Sin proyecto') ?><?= $r['multi'] ? ' <span class="muted">(' . (int)$r['multi'] . ' compartidos)</span>' : '' ?></td>
        <td><?= (int)$r['total_contenidos'] ?></td><td><?= $fq($r['graficas']) ?></td><td><?= $fq($r['videos']) ?></td>
        <td><b><?= $fq($r['total_assets']) ?></b></td><td><?= $fq($r['tareas']) ?></td><td><?= $fq($r['qty_mailings_total']) ?></td>
        <td><?= (int)($landByProj[$r['project']] ?? 0) ?: '—' ?></td><td><?= $fq($r['revisiones']) ?></td><td><?= $fq($r['rechazos']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td><?= (int)$tot['total_contenidos'] ?></td><td><?= $fq($tot['graficas']) ?></td><td><?= $fq($tot['videos']) ?></td><td><?= $fq($tot['total_assets']) ?></td><td><?= $fq($tot['tareas']) ?></td><td><?= $fq($tot['qty_mailings_total']) ?></td><td><?= $landTot ?: '—' ?></td><td><?= $fq($tot['revisiones']) ?></td><td><?= $fq($tot['rechazos']) ?></td></tr></tfoot>
  </table>
  <p class="muted" style="margin-top:6px;font-size:10.5px">Los contenidos para varias plataformas reparten sus cantidades en partes iguales. Variantes del mismo sitio (por ejemplo «Mi Marca», «Mi Marca Rebrand» y «mimarca.com», si están unificadas) se suman juntas.</p>
  <?php endif; ?>

  <?php if ($porAutor): ?>
  <h2>Producción por diseñador</h2>
  <table>
    <thead><tr><th>Diseñador</th><th>Contenidos</th><th>Gráficas</th><th>Videos</th><th>Total</th><th>Revisiones</th><th>Rechazos</th><th>Con complicaciones</th></tr></thead>
    <tbody>
    <?php foreach ($porAutor as $a => $x): ?>
      <tr><td><?= $h($a) ?></td><td><?= $x['contenidos'] ?></td><td><?= $x['graficas'] ?></td><td><?= $x['videos'] ?></td><td><b><?= $x['graficas'] + $x['videos'] ?></b></td><td><?= $x['revisiones'] ?: '—' ?></td><td><?= $x['rechazos'] ?: '—' ?></td><td><?= $x['complicaciones'] ?: '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h2>Revisiones, rechazos y complicaciones</h2>
  <div class="grid3">
    <?php foreach ([['Cambios pedidos', 'revision', PR_EDIT_REASONS['revision'], 'tag-rev', $nRev], ['Motivos de rechazo', 'rechazo', PR_EDIT_REASONS['rechazo'], 'tag-rech', $nRech]] as [$tit, $k, $labels, $cls, $n]): ?>
    <div>
      <div class="sub <?= $cls ?>"><?= $tit ?> (<?= $n ?>)</div>
      <?php if (!$pm['motivos'][$k]): ?><div class="empty"><?= $n ? 'Sin motivos cargados' : 'Sin datos' ?></div><?php endif; ?>
      <?php foreach ($pm['motivos'][$k] as $mk => $mn): ?><div class="reason"><span><?= $h($labels[$mk] ?? $mk) ?></span><b><?= $mn ?></b></div><?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div>
      <div class="sub" style="color:var(--amber)">Complicaciones (<?= (int)$pm['conComplicaciones'] ?> de <?= (int)$tot['total_contenidos'] ?> pedidos)</div>
      <?php if (!$pm['complicaciones']): ?><div class="empty">Sin datos</div><?php endif; ?>
      <?php foreach ($pm['complicaciones'] as $ck => $cn): ?><div class="reason"><span><?= $h(PR_COMPLICATIONS[$ck] ?? $ck) ?></span><b><?= $cn ?></b></div><?php endforeach; ?>
    </div>
  </div>

  <?php if ($pm['ediciones']): ?>
  <h2>Detalle de revisiones y rechazos</h2>
  <table>
    <thead><tr><th>Fecha</th><th class="l">Contenido</th><th class="l">Proyecto</th><th class="l">Tipo</th><th class="l">Motivos</th><th class="l">Notas</th><th class="l">Registró</th></tr></thead>
    <tbody>
    <?php foreach ($pm['ediciones'] as $e): ?>
      <tr><td class="l"><?= date('d/m', strtotime($e['fecha'])) ?></td><td class="l"><?= $h($e['name']) ?></td><td class="l"><?= $h($e['project']) ?></td>
        <td class="l <?= $e['tipo'] === 'rechazo' ? 'tag-rech' : 'tag-rev' ?>"><?= $e['tipo'] === 'rechazo' ? 'Rechazo' : 'Revisión' ?></td>
        <td class="l"><?= $e['motivos'] ? $h(implode(', ', $e['motivos'])) : '<span class="muted">Sin motivo cargado</span>' ?></td>
        <td class="l"><?= $e['notas'] !== '' ? $h($e['notas']) : '—' ?></td><td class="l"><?= $h($e['registro']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($pm['contenidos']): ?>
  <h2>Contenidos del mes (<?= count($pm['contenidos']) ?>)</h2>
  <table>
    <thead><tr><th>Fecha</th><th class="l">Contenido</th><th class="l">Proyecto</th><th class="l">Tipo</th><th class="l">Diseñador</th><th>Gráf.</th><th>Videos</th><th class="l">Notas</th></tr></thead>
    <tbody>
    <?php foreach ($pm['contenidos'] as $c):
      $nota = [];
      if ($c['edicion']) $nota[] = $c['edicion'] === 'rechazo' ? 'Rechazo' : 'Revisión';
      if ($c['complicaciones']) $nota[] = 'Complicaciones: ' . implode(', ', $c['complicaciones']); ?>
      <tr><td class="l"><?= date('d/m', strtotime($c['fecha'])) ?></td><td class="l"><?= $h($c['name']) ?></td><td class="l"><?= $h($c['project']) ?></td>
        <td class="l"><?= $h($typeLabels[$c['type']] ?? $c['type']) ?></td><td class="l"><?= $h($c['author']) ?></td>
        <td><?= $c['graficas'] ?: '—' ?></td><td><?= $c['videos'] ?: '—' ?></td><td class="l muted"><?= $h(implode(' · ', $nota)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <footer><span><?= $h(g7_site('name')) ?> · Estadísticas › Producción</span><span><?= $h($mesLabel) ?></span></footer>
</div>
<script src="/toolz/assets/stats-reporte.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-reporte.js') ?>"></script>
</body>
</html>
