<?php
/**
 * calendario/index.php — Mi calendario: eventos personales (privados) y las tareas con fecha de cada persona.
 * Vistas Mes, Semana y Agenda. La lógica está en cal.js (habla con api.php). Doc: toolz/CALENDARIO.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$db = getDB();
require_once __DIR__ . '/lib.php';
cal_install($db);
try {
    $_p = $db->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','font_size')");
    $_p->execute([$currentUser['id']]); $_prefs = [];
    foreach ($_p->fetchAll() as $_r) $_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Throwable $e) { $_prefs = []; }
$hasTasks = isset(g7_modules()['tareas']) && g7_can((int)$currentUser['id'], 'tareas');
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars((string)($_prefs['theme'] ?? 'dark')) ?> font-<?= htmlspecialchars((string)($_prefs['font_size'] ?? 'medium')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Mi calendario · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="cal.css?v=<?= $v('cal.css') ?>">
<script type="application/json" data-global="CAL"><?= json_encode(['tasks' => $hasTasks, 'colors' => CAL_COLORS, 'repeats' => CAL_REPEATS, 'name' => (string)(($currentUser['display_name'] ?? '') ?: ($currentUser['username'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="cal.js?v=<?= $v('cal.js') ?>" defer></script>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<main class="cal">
  <header class="cal-head">
    <div><h1>Mi calendario</h1><p class="cal-sub">Tus eventos son privados: solo tú los ves.</p></div>
    <button class="cal-new" type="button" id="cal-new"><i class="fa-solid fa-plus"></i> Evento</button>
  </header>
  <div class="cal-bar">
    <div class="cal-nav">
      <button class="cal-btn" type="button" id="cal-today">Hoy</button>
      <button class="cal-ico" type="button" id="cal-prev" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
      <button class="cal-ico" type="button" id="cal-next" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
      <h2 id="cal-label" aria-live="polite"></h2>
    </div>
    <div class="cal-tools">
      <?php if ($hasTasks): ?><label class="cal-chk"><input type="checkbox" id="cal-show-tasks"> Mis tareas</label><?php endif; ?>
      <div class="cal-seg" role="tablist" aria-label="Vista">
        <button type="button" data-view="month" role="tab">Mes</button><button type="button" data-view="week" role="tab">Semana</button><button type="button" data-view="agenda" role="tab">Agenda</button>
      </div>
    </div>
  </div>
  <section id="cal-body" class="cal-body" aria-busy="true"><div class="cal-empty"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</div></section>
</main>
<div class="cal-modal" id="cal-modal" hidden>
  <form class="cal-dialog" id="cal-form" role="dialog" aria-modal="true" aria-labelledby="cal-f-h" novalidate></form>
</div>
<div class="cal-toast" id="cal-toast" role="status" aria-live="polite"></div>
</body>
</html>
