<?php
/**
 * trabajo/informes.php — Trabajo › Reports: the same dashboard as Statistics › Jira, with the tasks of Trabajo (ticket = task).
 * Range (jr = quick range, jf/jt = from/to), department (d) and board (b). Metrics, summary by situation, tabs with charts
 * (assets/stats-jira.js), export (stats_jira_export.php with $XS) and "What counts as delivered?" (?deliver=1, admin or director).
 * The calculations: wk_rep_report() in report_lib.php over the same engine as Jira. No inline code (CSP). Doc: toolz/TRABAJO.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();   // also applies the permission of the 'tareas' module (Trabajo)
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/report_lib.php';
wk_install($db);
$uid = (int)$currentUser['id'];
$ctx = wk_ctx($db, $uid);
$canCfg = $ctx['all'];

// "What counts as delivered?" (admin or director): the same as in Jira, with its own configuration
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET['deliver'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canCfg || ($_SERVER['HTTP_X_JS'] ?? '') !== '1') { http_response_code(403); echo json_encode(['error' => 'Solo admin o dirección.']); exit; }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $cfg = wk_rep_cfg_save(($in['mode'] ?? '') === 'auto' ? null : array_map('strval', (array)($in['states'] ?? [])));
    g7_audit('trabajo.stats_deliver', '', $cfg);
    echo json_encode(['ok' => true] + $cfg); exit;
}

$F = wk_rep_filters($db, $ctx);
$team = (int)($_GET['d'] ?? 0); $board = (int)($_GET['b'] ?? 0);
if ($team && !in_array($team, array_column($F['teams'], 'id'), true)) $team = 0;
if ($board && !in_array($board, array_column($F['boards'], 'id'), true)) $board = 0;
$range = g7_jira_range($_GET, 30);
$err = ''; $J = null;
try { $J = wk_rep_report($db, $ctx, $range, ['team' => $team, 'board' => $board]); }
catch (Throwable $e) { error_log('[trabajo informes] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); $err = 'No se pudo armar el informe.'; }
$scopeTxt = ['all' => 'todo el equipo', 'team' => 'tu gente y tus departamentos', 'own' => 'lo tuyo'][$J['scope'] ?? 'own'];
$teamName = $team ? (array_column($F['teams'], 'name', 'id')[$team] ?? '') : ''; $boardName = $board ? (array_column($F['boards'], 'name', 'id')[$board] ?? '') : '';
$scopeLine = $scopeTxt . ($teamName ? ' · ' . $teamName : '') . ($boardName ? ' · tablero ' . $boardName : '');

if (isset($_GET['export']) && $J) {
    $currentUser['display_name'] = (string)$db->query('SELECT display_name FROM g7_users WHERE id = ' . $uid)->fetchColumn();
    $urls = $J['urls'];
    $XS = ['report' => $J, 'range' => $range, 'title' => 'Informe de Trabajo', 'scope_label' => 'Alcance', 'scope' => $scopeLine, 'file' => 'trabajo',
        'note' => 'datos de Cronos de las ' . date('H:i'), 'url' => fn(array $i) => g7_site_origin() . ($urls[$i['key']] ?? ''), 'Issues' => 'Tareas',
        'secs' => ['project' => 'Por ' . mb_strtolower(wk_cli_label($db)), 'type' => 'Por tablero', 'issues' => 'Lista de tareas', 'origin' => 'Origen (quién pidió, departamento, etiqueta)'],
        'cols' => ['type' => 'Tablero', 'project' => wk_cli_label($db), 'reporter' => 'Pidió', 'components' => 'Departamento', 'resolved' => 'Terminada', 'url' => 'Link a la tarea'],
        'dims' => ['project' => wk_cli_label($db), 'type' => 'Tablero', 'reporter' => 'Quién la pidió', 'component' => 'Departamento']];
    require dirname(__DIR__) . '/stats_jira_export.php';
    exit;
}

try {
    $_p = $db->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','font_size')");
    $_p->execute([$uid]); $_prefs = [];
    foreach ($_p->fetchAll() as $_r) $_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Throwable $e) { $_prefs = []; }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$num = fn($v) => $v === null ? '—' : str_replace('.', ',', (string)$v);
$Q = fn(array $extra = [], array $drop = []) => '?' . http_build_query(array_diff_key(array_merge(
    $range['preset'] === 'custom' ? ['jf' => $range['from'], 'jt' => $range['to']] : ['jr' => $range['preset']], $team ? ['d' => $team] : [], $board ? ['b' => $board] : [], $extra), array_flip($drop)));
$cli = wk_cli_label($db);
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= $h($_prefs['theme'] ?? 'dark') ?> font-<?= $h($_prefs['font_size'] ?? 'medium') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Informes de Trabajo · <?= $h(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="/toolz/assets/stats-jira.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/stats-jira.css') ?>">
<link rel="stylesheet" href="informes.css?v=<?= (int)@filemtime(__DIR__ . '/informes.css') ?>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous"></script>
</head>
<body>
<?php include dirname(__DIR__) . '/topbar.php'; ?>
<main class="wr">
<div class="page-header wr-head"><div>
    <a class="wr-back" href="/toolz/trabajo/#informes"><i class="fa-solid fa-arrow-left"></i> Trabajo · tablas de informes</a>
    <div class="page-title">Informes <span>de trabajo</span></div>
    <div class="page-sub"><?= $h($range['label']) ?> &middot; <?= $h($scopeLine) ?></div></div></div>

<form class="js-range" method="get" action="">
  <?php if ($team): ?><input type="hidden" name="d" value="<?= $team ?>"><?php endif; ?><?php if ($board): ?><input type="hidden" name="b" value="<?= $board ?>"><?php endif; ?>
  <div class="js-presets" role="group" aria-label="Rango rápido">
    <?php foreach (G7_JS_PRESETS as $k => $v): ?><a class="js-chip<?= $range['preset'] === (string)$k ? ' on' : '' ?>" href="<?= $h($Q(['jr' => $k], ['jf', 'jt'])) ?>"><?= $h($v) ?></a><?php endforeach; ?>
  </div>
  <div class="js-dates">
    <label>Desde <input type="date" name="jf" value="<?= $h($range['from']) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <label>Hasta <input type="date" name="jt" value="<?= $h($range['to']) ?>" max="<?= date('Y-m-d') ?>" required></label>
    <button type="submit" class="js-btn"><i class="fa-solid fa-calendar-check"></i> Aplicar</button>
  </div>
</form>
<form class="js-top wr-filters" method="get" action="">
  <?php if ($range['preset'] === 'custom'): ?><input type="hidden" name="jf" value="<?= $h($range['from']) ?>"><input type="hidden" name="jt" value="<?= $h($range['to']) ?>"><?php else: ?><input type="hidden" name="jr" value="<?= $h($range['preset']) ?>"><?php endif; ?>
  <?php if ($F['teams']): ?><label class="wr-sel"><i class="fa-solid fa-people-group"></i><select name="d" aria-label="Departamento" class="wr-auto"><option value="0"><?= $ctx['all'] ? 'Todos los departamentos' : 'Mis departamentos y mi gente' ?></option>
    <?php foreach ($F['teams'] as $t): ?><option value="<?= $t['id'] ?>"<?= $team === $t['id'] ? ' selected' : '' ?>><?= $h($t['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
  <label class="wr-sel"><i class="fa-solid fa-table-columns"></i><select name="b" aria-label="Tablero" class="wr-auto"><option value="0">Todos los tableros</option>
    <?php foreach ($F['boards'] as $b): ?><option value="<?= $b['id'] ?>"<?= $board === $b['id'] ? ' selected' : '' ?>><?= $h($b['name']) ?></option><?php endforeach; ?></select></label>
  <noscript><button type="submit" class="js-btn">Filtrar</button></noscript>
  <span class="js-sp"></span>
  <?php if ($J): ?><span class="js-mut">Datos de las <?= $h(date('H:i')) ?></span><button type="button" class="js-btn primary" id="js-export-open"><i class="fa-solid fa-file-export"></i> Exportar informe</button><?php endif; ?>
</form>
<?php if ($err): ?>
  <div class="js-err"><i class="fa-solid fa-triangle-exclamation"></i> <?= $h($err) ?></div>
<?php else: $k = $J['kpi']; ?>
  <?php if ($J['truncated']): ?><div class="js-note"><i class="fa-solid fa-circle-info"></i> Hay más de <?= WK_REP_MAX ?> tareas en el rango: el informe usa las <?= WK_REP_MAX ?> más nuevas. Achicá el rango o elegí un departamento o un tablero.</div><?php endif; ?>
  <?php if (!$J['issues']): ?>
  <div class="card wr-empty"><i class="fa-regular fa-chart-bar"></i><h3>No hay tareas en este período</h3><p>Probá con un rango más largo<?= $team || $board ? ' o sacá el filtro de departamento o tablero' : '' ?>. Acá cuentan las tareas de Trabajo (también las que nacieron de un ticket).</p></div>
  <?php endif; ?>
  <div class="metric-grid">
    <?php foreach ([
        ['Creadas', $k['created'], 'fa-plus', '#4c9aff', 'en el rango'],
        ['Entregadas', $k['resolved'], 'fa-check', '#22c55e', ($k['flow'] !== null ? $k['flow'] . '% de lo creado' : '') . ($k['awaiting'] ? ' · ' . $k['awaiting'] . ' esperan aprobación' : '')],
        ['A tiempo', $k['on_time_pct'] === null ? '—' : $k['on_time_pct'] . '%', 'fa-bullseye', '#14b8a6', 'de lo entregado en el rango'],
        ['Sin entregar hoy', $k['open'], 'fa-folder-open', '#a78bfa', $k['unassigned'] ? $k['unassigned'] . ' sin asignar' : 'todas asignadas'],
        ['Tiempo hasta entregar', $k['avg_days'] === null ? '—' : $num($k['avg_days']) . ' d', 'fa-stopwatch', '#f59e0b', $k['median_days'] !== null ? 'mediana ' . $num($k['median_days']) . ' d' : ''],
        ['Plazo que se da', $k['avg_lead'] === null ? '—' : $num($k['avg_lead']) . ' d', 'fa-hourglass-half', '#ec4899', $k['median_lead'] !== null ? 'mediana ' . $num($k['median_lead']) . ' d · de creada a la entrega' : 'sin fechas de entrega']] as [$l, $v, $ic, $c, $sub]): ?>
      <div class="mc" style="border-top:2px solid <?= $c ?>"><div class="mc-top"><div class="mc-icon" style="background:<?= $c ?>1a"><i class="fa-solid <?= $ic ?>" style="color:<?= $c ?>"></i></div></div>
        <div class="mc-val" style="color:<?= $c ?>"><?= $h($v) ?></div><div class="mc-label"><?= $h($l) ?><?= $sub !== '' ? '<br><span class="js-mut">' . $h($sub) . '</span>' : '' ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="card js-sum">
    <div class="card-title"><i class="fa-solid fa-clipboard-list"></i> Resumen de tareas <span class="js-mut">· tocá una para ver la lista</span></div>
    <p class="js-mut js-sum-help">Entregadas: las que llegaron a un estado de entrega dentro del rango (<?= $h(implode(', ', $J['deliver']['states']) ?: 'las columnas marcadas como terminadas') ?>), a tiempo o después de su fecha. La fecha que cuenta es la de la entrega, no la del cierre.
      <?php if ($canCfg): ?><button type="button" class="js-link" id="js-deliver-open">Elegir qué estados cuentan como entregado</button><?php endif; ?></p>
    <div class="js-sum-grid">
      <?php foreach (['on_time', 'late_done', 'overdue', 'in_progress', 'not_started'] as $s): [$lbl, $col, $ic] = G7_JS_SIT[$s]; ?>
        <button type="button" class="js-sit" data-sit="<?= $s ?>" style="--c:<?= $col ?>"><i class="fa-solid <?= $ic ?>"></i><b><?= (int)$k[$s] ?></b><span><?= $h(str_replace(['Entregados', 'Atrasados', 'Resueltos'], ['Entregadas', 'Atrasadas', 'Resueltas'], $lbl)) ?></span></button>
      <?php endforeach; ?>
    </div>
    <div class="js-sum-extra">
      <button type="button" class="js-pill" data-flag="due_soon"><i class="fa-solid fa-calendar-day"></i> <?= (int)$k['due_soon'] ?> vencen en los próximos 7 días</button>
      <button type="button" class="js-pill" data-flag="no_due"><i class="fa-regular fa-calendar-xmark"></i> <?= (int)$k['no_due'] ?> abiertas sin fecha de entrega</button>
      <button type="button" class="js-pill" data-flag="unassigned"><i class="fa-solid fa-user-slash"></i> <?= (int)$k['unassigned'] ?> abiertas sin responsable</button>
      <button type="button" class="js-pill" data-flag="awaiting"><i class="fa-solid fa-stamp"></i> <?= (int)$k['awaiting'] ?> entregadas esperando aprobación<?= $k['avg_approval'] !== null ? ' · la aprobación tarda ' . $num($k['avg_approval']) . ' d en promedio' : '' ?></button>
      <button type="button" class="js-pill" data-flag="due_weekend"><i class="fa-solid fa-calendar-week"></i> <?= (int)$k['due_weekend'] ?> con entrega en fin de semana</button>
      <button type="button" class="js-pill" data-flag="created_weekend"><i class="fa-solid fa-mug-saucer"></i> <?= (int)$k['created_weekend'] ?> pedidas un fin de semana</button>
    </div>
  </div>

  <div class="js-tabs" role="tablist" id="js-tabs">
    <button type="button" role="tab" data-tab="resumen" class="on"><i class="fa-solid fa-chart-column"></i> Resumen</button>
    <button type="button" role="tab" data-tab="entregas"><i class="fa-solid fa-truck-fast"></i> Entregas</button>
    <button type="button" role="tab" data-tab="personas"><i class="fa-solid fa-users"></i> Personas</button>
    <button type="button" role="tab" data-tab="tiempos"><i class="fa-solid fa-stopwatch"></i> Tiempos</button>
    <button type="button" role="tab" data-tab="plazos"><i class="fa-solid fa-hourglass-half"></i> Plazos y prioridad</button>
    <button type="button" role="tab" data-tab="calendario"><i class="fa-regular fa-calendar"></i> Calendario</button>
    <button type="button" role="tab" data-tab="origen"><i class="fa-solid fa-route"></i> Origen</button>
    <button type="button" role="tab" data-tab="explorar"><i class="fa-solid fa-sliders"></i> Explorar</button>
    <button type="button" role="tab" data-tab="lista"><i class="fa-solid fa-list"></i> Lista</button>
  </div>
  <div id="js-view" class="js-view"></div>
  <div id="js-data" hidden data-report="<?= $h(json_encode(['kpi' => $k, 'series' => $J['series'], 'gran' => $J['gran'], 'groups' => $J['groups'], 'res_hist' => $J['res_hist'],
      'issues' => $J['issues'], 'site' => '', 'urls' => $J['urls'], 'today' => $J['today'], 'from' => $J['from'], 'to' => $J['to'], 'sit' => G7_JS_SIT,
      'calendar' => $J['calendar'], 'lead_hist' => $J['lead_hist'], 'lead_buckets' => G7_JS_LEAD, 'deliver' => $J['deliver'], 'words' => WK_REP_WORDS + ['project' => $cli],
      'deliver_url' => '?deliver=1'], JSON_UNESCAPED_UNICODE)) ?>"></div>

  <dialog class="js-dlg" id="js-export">
    <form method="get" action="" class="js-dlg-in" id="js-export-form">
      <input type="hidden" name="export" value="1"><input type="hidden" name="jf" value="<?= $h($range['from']) ?>"><input type="hidden" name="jt" value="<?= $h($range['to']) ?>">
      <?php if ($team): ?><input type="hidden" name="d" value="<?= $team ?>"><?php endif; ?><?php if ($board): ?><input type="hidden" name="b" value="<?= $board ?>"><?php endif; ?>
      <h3><i class="fa-solid fa-file-export"></i> Exportar informe</h3>
      <p class="js-mut"><?= $h($range['label']) ?> · <?= $h($scopeLine) ?></p>
      <fieldset><legend>Formato</legend>
        <label class="js-opt"><input type="radio" name="fmt" value="csv" checked> <span><b>Excel (CSV)</b> Para abrir en Excel o Google Sheets.</span></label>
        <label class="js-opt"><input type="radio" name="fmt" value="pdf"> <span><b>PDF para imprimir</b> Con gráficos de barras; se guarda como PDF desde la ventana de impresión.</span></label>
      </fieldset>
      <fieldset><legend>Qué incluir</legend><div class="js-checks">
        <?php foreach (['kpi' => 'Indicadores', 'summary' => 'Resumen de tareas', 'series' => 'Evolución en el tiempo', 'person' => 'Por persona', 'project' => 'Por ' . mb_strtolower($cli),
            'type' => 'Por tablero', 'priority' => 'Por prioridad', 'status' => 'Por estado', 'times' => 'Tiempos de resolución', 'origin' => 'Origen (quién pidió, departamento, etiqueta)',
            'lead' => 'Plazos que se dan', 'matrix' => 'Días vs prioridad', 'calendar' => 'Tareas por día', 'weekend' => 'Fines de semana', 'issues' => 'Lista de tareas'] as $kk => $vv): ?>
          <label><input type="checkbox" name="sec[]" value="<?= $kk ?>"<?= in_array($kk, ['kpi', 'summary', 'person', 'issues'], true) ? ' checked' : '' ?>> <?= $h($vv) ?></label>
        <?php endforeach; ?></div>
        <div class="js-checks-a"><button type="button" class="js-link" data-all="sec[]">Todo</button> · <button type="button" class="js-link" data-none="sec[]">Nada</button></div>
      </fieldset>
      <fieldset id="js-ex-issues"><legend>Lista de tareas</legend>
        <label class="js-lbl">Cuáles
          <select name="only"><option value="">Todas las del informe</option>
            <?php foreach (G7_JS_SIT as $kk => [$vv]): ?><option value="<?= $kk ?>"><?= $h($vv) ?></option><?php endforeach; ?>
            <option value="open">Todas las abiertas</option><option value="done">Todas las entregadas en el rango</option><option value="due_soon">Vencen en los próximos 7 días</option>
            <option value="awaiting">Entregadas esperando aprobación</option><option value="due_weekend">Con entrega en fin de semana</option><option value="created_weekend">Pedidas un fin de semana</option></select></label>
        <div class="js-checks">
          <?php foreach (['key' => 'Clave', 'summary' => 'Título', 'situation' => 'Situación', 'status' => 'Estado', 'type' => 'Tablero', 'priority' => 'Prioridad', 'assignee' => 'Responsable',
              'project' => $cli, 'reporter' => 'Pidió', 'components' => 'Departamento', 'created' => 'Creada', 'due' => 'Fecha de entrega', 'lead' => 'Plazo dado (días)',
              'delivered_at' => 'Entregada', 'resolved' => 'Terminada', 'res_days' => 'Días hasta entregar', 'late' => 'Días de atraso', 'url' => 'Link a la tarea'] as $kk => $vv): ?>
            <label><input type="checkbox" name="col[]" value="<?= $kk ?>"<?= in_array($kk, ['url', 'res_days', 'reporter', 'components', 'lead', 'resolved'], true) ? '' : ' checked' ?>> <?= $h($vv) ?></label>
          <?php endforeach; ?></div>
        <label class="js-lbl">Ordenar por
          <select name="sort"><option value="">Situación (atrasadas primero)</option><option value="due">Fecha de entrega</option><option value="created">Creada</option><option value="delivered_at">Entregada</option><option value="assignee">Responsable</option></select></label>
      </fieldset>
      <div class="js-dlg-a"><button type="button" class="js-btn" data-close>Cancelar</button><button type="submit" class="js-btn primary"><i class="fa-solid fa-download"></i> Exportar</button></div>
    </form>
  </dialog>
  <?php if ($canCfg): ?>
  <dialog class="js-dlg" id="js-deliver">
    <form class="js-dlg-in" id="js-deliver-form">
      <h3><i class="fa-solid fa-stamp"></i> ¿Qué cuenta como entregado?</h3>
      <p class="js-mut">Una tarea se da por terminada cuando pasa a una columna marcada como terminada. Si tus tableros tienen un paso como «En revisión del cliente» o «Para aprobar», marcalo acá: la tarea cuenta como <b>entregada</b> desde que llega ahí, aunque la aprueben días después.</p>
      <label class="js-opt"><input type="radio" name="mode" value="auto"<?= $J['deliver']['auto'] ? ' checked' : '' ?>> <span><b>Automático</b> Las columnas terminadas y las que se llaman como «aprobación», «revisión del cliente» o «entregado».</span></label>
      <label class="js-opt"><input type="radio" name="mode" value="pick"<?= $J['deliver']['auto'] ? '' : ' checked' ?>> <span><b>Elegir los estados</b> Los que aparecen en este informe:</span></label>
      <div class="js-checks" id="js-deliver-list">
        <?php $cats = ['done' => 'terminada', 'indeterminate' => 'en curso', 'new' => 'primera columna', '' => 'en el historial'];
        foreach ($J['deliver']['seen'] as $st => $cat): ?>
          <label><input type="checkbox" name="st" value="<?= $h($st) ?>"<?= in_array($st, $J['deliver']['states'], true) ? ' checked' : '' ?><?= $cat === 'done' ? ' disabled' : '' ?>> <?= $h($st) ?> <small class="js-mut"><?= $h($cats[$cat] ?? '') ?></small></label>
        <?php endforeach; ?></div>
      <div class="js-dlg-a"><button type="button" class="js-btn" data-close>Cancelar</button><button type="submit" class="js-btn primary"><i class="fa-solid fa-floppy-disk"></i> Guardar y recalcular</button></div>
    </form>
  </dialog>
  <?php endif; ?>
  <script src="/toolz/assets/stats-jira.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/stats-jira.js') ?>"></script>
<?php endif; ?>
<script src="informes.js?v=<?= (int)@filemtime(__DIR__ . '/informes.js') ?>"></script>
</main>
</body>
</html>
