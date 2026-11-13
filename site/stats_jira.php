<?php
/**
 * stats_jira.php — Estadísticas › Jira (se incluye desde stats.php, que ya pidió login y CSP).
 * Rango de fechas a elección (jr = rango rápido, jf/jt = desde/hasta), proyectos (jp), resumen de pedidos por situación,
 * pestañas con gráficos (assets/stats-jira.js) y exportación con opciones (stats_jira_export.php, por stats.php?section=jira&export=…).
 * Las cuentas: g7_jira_report() en jira_stats_lib.php. Lo prende Admin › Jira API › Estadísticas. Doc: toolz/JIRA.md
 */
if (!isset($currentUser) || !function_exists('g7_jira_report')) { http_response_code(404); exit; }
$jsAll = g7_jira_features()['projects'];
$jsPick = array_values(array_intersect(array_filter(explode(',', (string)($_GET['jp'] ?? ''))), $jsAll));
$jsRange = g7_jira_range($_GET, $daysLimit);
$jsErr = ''; $J = null;
try { $J = g7_jira_report($jsPick, $jsRange, isset($_GET['fresh'])); }
catch (G7JiraError $e) { $jsErr = $e->getMessage(); }
catch (Throwable $e) { error_log('[stats jira] ' . $e->getMessage()); $jsErr = 'No se pudo armar el informe de Jira.'; }
$jh = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$jn = fn($v) => $v === null ? '—' : str_replace('.', ',', (string)$v);
$jsQ = fn(array $extra = [], array $drop = []) => '?' . http_build_query(array_diff_key(array_merge(['section' => 'jira'],
    $jsRange['preset'] === 'custom' ? ['jf' => $jsRange['from'], 'jt' => $jsRange['to']] : ['jr' => $jsRange['preset']], $jsPick ? ['jp' => implode(',', $jsPick)] : [], $extra), array_flip($drop)));
?>
<link rel="stylesheet" href="/toolz/assets/stats-jira.css?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-jira.css') ?>">
<div class="page-header"><div class="page-title">Jira <span>informes</span></div>
  <div class="page-sub"><?= $jh($jsRange['label']) ?> &middot; proyectos <?= $jh(implode(', ', $jsPick ?: $jsAll)) ?></div></div>

<form class="js-range" method="get" action="" id="js-range">
  <input type="hidden" name="section" value="jira"><?php if ($jsPick): ?><input type="hidden" name="jp" value="<?= $jh(implode(',', $jsPick)) ?>"><?php endif; ?>
  <div class="js-presets" role="group" aria-label="Rango rápido">
    <?php foreach (G7_JS_PRESETS as $k => $v): ?><a class="js-chip<?= $jsRange['preset'] === (string)$k ? ' on' : '' ?>" href="<?= $jh($jsQ(['jr' => $k], ['jf', 'jt'])) ?>"><?= $jh($v) ?></a><?php endforeach; ?>
  </div>
  <div class="js-dates">
    <label>Desde <input type="date" name="jf" value="<?= $jh($jsRange['from']) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <label>Hasta <input type="date" name="jt" value="<?= $jh($jsRange['to']) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <button type="submit" class="js-btn"><i class="fa-solid fa-calendar-check"></i> Aplicar</button>
  </div>
</form>
<div class="js-top">
  <a class="js-chip<?= $jsPick ? '' : ' on' ?>" href="<?= $jh($jsQ([], ['jp'])) ?>">Todos los proyectos</a>
  <?php foreach ($jsAll as $k): ?><a class="js-chip<?= $jsPick === [$k] ? ' on' : '' ?>" href="<?= $jh($jsQ(['jp' => $k])) ?>"><?= $jh($k) ?></a><?php endforeach; ?>
  <span class="js-sp"></span>
  <?php if ($J): ?><span class="js-mut">Datos de las <?= $jh(substr((string)$J['cached_at'], 11, 5)) ?></span><?php endif; ?>
  <a class="js-chip" href="<?= $jh($jsQ(['fresh' => 1])) ?>"><i class="fa-solid fa-rotate"></i> Actualizar</a>
  <?php if ($J): ?><button type="button" class="js-btn primary" id="js-export-open"><i class="fa-solid fa-file-export"></i> Exportar informe</button><?php endif; ?>
</div>
<?php if ($jsErr): ?>
  <div class="js-err"><i class="fa-solid fa-triangle-exclamation"></i> <?= $jh($jsErr) ?><br><small>Revisa la conexión en Admin › Jira API.</small></div>
<?php else: $k = $J['kpi']; ?>
  <?php if ($J['truncated']): ?><div class="js-note"><i class="fa-solid fa-circle-info"></i> Hay más de <?= G7_JS_MAX ?> issues en el rango: el informe usa los <?= G7_JS_MAX ?> más nuevos. Achicá el rango o elegí un proyecto.</div><?php endif; ?>
  <div class="metric-grid">
    <?php foreach ([
        ['Creados', $k['created'], 'fa-plus', '#4c9aff', 'en el rango'],
        ['Resueltos', $k['resolved'], 'fa-check', '#22c55e', $k['flow'] !== null ? $k['flow'] . '% de lo creado' : ''],
        ['A tiempo', $k['on_time_pct'] === null ? '—' : $k['on_time_pct'] . '%', 'fa-bullseye', '#14b8a6', 'de lo entregado en el rango'],
        ['Abiertos hoy', $k['open'], 'fa-folder-open', '#a78bfa', $k['unassigned'] ? $k['unassigned'] . ' sin asignar' : 'todos asignados'],
        ['Tiempo de resolución', $k['avg_days'] === null ? '—' : $jn($k['avg_days']) . ' d', 'fa-stopwatch', '#f59e0b', $k['median_days'] !== null ? 'mediana ' . $jn($k['median_days']) . ' d' : '']] as [$l, $v, $ic, $c, $sub]): ?>
      <div class="mc" style="border-top:2px solid <?= $c ?>"><div class="mc-top"><div class="mc-icon" style="background:<?= $c ?>1a"><i class="fa-solid <?= $ic ?>" style="color:<?= $c ?>"></i></div></div>
        <div class="mc-val" style="color:<?= $c ?>"><?= $jh($v) ?></div><div class="mc-label"><?= $jh($l) ?><?= $sub !== '' ? '<br><span class="js-mut">' . $jh($sub) . '</span>' : '' ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="card js-sum">
    <div class="card-title"><i class="fa-solid fa-clipboard-list"></i> Resumen de pedidos <span class="js-mut">· tocá uno para ver la lista</span></div>
    <p class="js-mut js-sum-help">Entregados: lo resuelto dentro del rango, a tiempo o después de su fecha de entrega. Atrasados, en curso y sin empezar: cómo está hoy lo que sigue abierto.</p>
    <div class="js-sum-grid">
      <?php foreach (['on_time', 'late_done', 'overdue', 'in_progress', 'not_started'] as $s): [$lbl, $col, $ic] = G7_JS_SIT[$s]; ?>
        <button type="button" class="js-sit" data-sit="<?= $s ?>" style="--c:<?= $col ?>"><i class="fa-solid <?= $ic ?>"></i><b><?= (int)$k[$s] ?></b><span><?= $jh($lbl) ?></span></button>
      <?php endforeach; ?>
    </div>
    <div class="js-sum-extra">
      <button type="button" class="js-pill" data-flag="due_soon"><i class="fa-solid fa-calendar-day"></i> <?= (int)$k['due_soon'] ?> vencen en los próximos 7 días</button>
      <button type="button" class="js-pill" data-flag="no_due"><i class="fa-regular fa-calendar-xmark"></i> <?= (int)$k['no_due'] ?> abiertos sin fecha de entrega</button>
      <button type="button" class="js-pill" data-flag="unassigned"><i class="fa-solid fa-user-slash"></i> <?= (int)$k['unassigned'] ?> abiertos sin asignar</button>
    </div>
  </div>

  <div class="js-tabs" role="tablist" id="js-tabs">
    <button type="button" role="tab" data-tab="resumen" class="on"><i class="fa-solid fa-chart-column"></i> Resumen</button>
    <button type="button" role="tab" data-tab="entregas"><i class="fa-solid fa-truck-fast"></i> Entregas</button>
    <button type="button" role="tab" data-tab="personas"><i class="fa-solid fa-users"></i> Personas</button>
    <button type="button" role="tab" data-tab="tiempos"><i class="fa-solid fa-stopwatch"></i> Tiempos</button>
    <button type="button" role="tab" data-tab="explorar"><i class="fa-solid fa-sliders"></i> Explorar</button>
    <button type="button" role="tab" data-tab="lista"><i class="fa-solid fa-list"></i> Lista</button>
  </div>
  <div id="js-view" class="js-view"></div>
  <div id="js-data" hidden data-report="<?= $jh(json_encode(['kpi' => $k, 'series' => $J['series'], 'gran' => $J['gran'], 'groups' => $J['groups'], 'res_hist' => $J['res_hist'],
      'issues' => $J['issues'], 'site' => $J['site'], 'today' => $J['today'], 'from' => $J['from'], 'to' => $J['to'], 'sit' => G7_JS_SIT], JSON_UNESCAPED_UNICODE)) ?>"></div>

  <dialog class="js-dlg" id="js-export">
    <form method="get" action="" class="js-dlg-in" id="js-export-form">
      <input type="hidden" name="section" value="jira"><input type="hidden" name="export" value="1">
      <input type="hidden" name="jf" value="<?= $jh($jsRange['from']) ?>"><input type="hidden" name="jt" value="<?= $jh($jsRange['to']) ?>">
      <?php if ($jsPick): ?><input type="hidden" name="jp" value="<?= $jh(implode(',', $jsPick)) ?>"><?php endif; ?>
      <h3><i class="fa-solid fa-file-export"></i> Exportar informe</h3>
      <p class="js-mut"><?= $jh($jsRange['label']) ?> · <?= $jh(implode(', ', $jsPick ?: $jsAll)) ?></p>
      <fieldset><legend>Formato</legend>
        <label class="js-opt"><input type="radio" name="fmt" value="csv" checked> <span><b>Excel (CSV)</b> Para abrir en Excel o Google Sheets.</span></label>
        <label class="js-opt"><input type="radio" name="fmt" value="pdf"> <span><b>PDF para imprimir</b> Con gráficos de barras; se guarda como PDF desde la ventana de impresión.</span></label>
      </fieldset>
      <fieldset><legend>Qué incluir</legend><div class="js-checks">
        <?php foreach (['kpi' => 'Indicadores', 'summary' => 'Resumen de pedidos', 'series' => 'Evolución en el tiempo', 'person' => 'Por persona', 'project' => 'Por proyecto',
            'type' => 'Por tipo', 'priority' => 'Por prioridad', 'status' => 'Por estado', 'times' => 'Tiempos de resolución', 'issues' => 'Lista de issues'] as $kk => $vv): ?>
          <label><input type="checkbox" name="sec[]" value="<?= $kk ?>"<?= in_array($kk, ['kpi', 'summary', 'person', 'issues'], true) ? ' checked' : '' ?>> <?= $jh($vv) ?></label>
        <?php endforeach; ?></div>
        <div class="js-checks-a"><button type="button" class="js-link" data-all="sec[]">Todo</button> · <button type="button" class="js-link" data-none="sec[]">Nada</button></div>
      </fieldset>
      <fieldset id="js-ex-issues"><legend>Lista de issues</legend>
        <label class="js-lbl">Cuáles
          <select name="only"><option value="">Todos los del informe</option>
            <?php foreach (G7_JS_SIT as $kk => [$vv]): ?><option value="<?= $kk ?>"><?= $jh($vv) ?></option><?php endforeach; ?>
            <option value="open">Todos los abiertos</option><option value="done">Todos los entregados en el rango</option><option value="due_soon">Vencen en los próximos 7 días</option></select></label>
        <div class="js-checks">
          <?php foreach (['key' => 'Clave', 'summary' => 'Título', 'situation' => 'Situación', 'status' => 'Estado', 'type' => 'Tipo', 'priority' => 'Prioridad', 'assignee' => 'Responsable',
              'project' => 'Proyecto', 'created' => 'Creado', 'due' => 'Fecha de entrega', 'resolved' => 'Resuelto', 'res_days' => 'Días hasta resolver', 'late' => 'Días de atraso', 'url' => 'Link a Jira'] as $kk => $vv): ?>
            <label><input type="checkbox" name="col[]" value="<?= $kk ?>"<?= in_array($kk, ['url', 'project', 'res_days'], true) ? '' : ' checked' ?>> <?= $jh($vv) ?></label>
          <?php endforeach; ?></div>
        <label class="js-lbl">Ordenar por
          <select name="sort"><option value="">Situación (atrasados primero)</option><option value="due">Fecha de entrega</option><option value="created">Creado</option><option value="resolved">Resuelto</option><option value="assignee">Responsable</option><option value="key">Clave</option></select></label>
      </fieldset>
      <div class="js-dlg-a"><button type="button" class="js-btn" data-close>Cancelar</button><button type="submit" class="js-btn primary"><i class="fa-solid fa-download"></i> Exportar</button></div>
    </form>
  </dialog>
  <script src="/toolz/assets/stats-jira.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stats-jira.js') ?>"></script>
<?php endif; ?>
