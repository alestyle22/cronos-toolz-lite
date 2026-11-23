<?php
/**
 * social/index.php — Social Studio: Content Planner (calendario editorial por marca o cliente) con "Convertir en ticket".
 * Todo lo dibuja app.js con datos de api.php. Sin código en línea (CSP estricta). Doc: toolz/SOCIAL_STUDIO.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();   // también aplica el permiso del módulo 'social' (g7_enforce_access)
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/lib.php';
ss_install($db);
$ctx = ss_ctx($db, (int)$currentUser['id']);
try {
    $_p = $db->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','font_size')");
    $_p->execute([$currentUser['id']]); $_prefs = [];
    foreach ($_p->fetchAll() as $_r) $_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Throwable $e) { $_prefs = []; }
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars((string)($_prefs['theme'] ?? 'dark')) ?> font-<?= htmlspecialchars((string)($_prefs['font_size'] ?? 'medium')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Social Studio · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<script type="application/json" data-global="SS_BOOT"><?= json_encode(['name' => $ctx['name'], 'can' => $ctx['can']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="app.js?v=<?= $v('app.js') ?>" defer></script>
<?php if ($ctx['can']['analytics']): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" integrity="sha384-DhxhYObIMeMNGyAG7iK11OHzBIKyEIeRL0ad1iFPAOwZB8iirUlTT0O/WJJUk8+o" crossorigin="anonymous" defer></script><script src="analytics.js?v=<?= $v('analytics.js') ?>" defer></script><?php endif; ?>
<?php if ($ctx['can']['reports']): ?><script src="reports.js?v=<?= $v('reports.js') ?>" defer></script><?php endif; ?>
<?php if ($ctx['can']['inbox']): ?><script src="inbox.js?v=<?= $v('inbox.js') ?>" defer></script><?php endif; ?>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<main class="ss" id="ss">
  <header class="ss-head">
    <div class="ss-head-txt">
      <p class="ss-kicker"><i class="fa-solid fa-share-nodes"></i> Social Studio</p>
      <h1>Content Planner</h1>
      <p class="ss-sub">Planificá las publicaciones de cada <span id="ss-group-word">marca</span>, mandalas a producir como tickets y seguí su avance en un solo lugar.</p>
    </div>
    <div class="ss-head-acts">
      <?php if ($ctx['can']['config']): ?><button type="button" class="ss-btn ss-btn-ghost" data-act="config"><i class="fa-solid fa-sliders"></i><span>Configurar</span></button><?php endif; ?>
      <?php if ($ctx['can']['edit']): ?><button type="button" class="ss-btn" data-act="new"><i class="fa-solid fa-plus"></i><span>Nueva publicación</span></button><?php endif; ?>
    </div>
  </header>

  <nav class="ss-sect" aria-label="Secciones de Social Studio">
    <a href="#" data-sect="planner" class="on"><i class="fa-regular fa-calendar"></i> Planner</a>
    <?php if ($ctx['can']['inbox']): ?><a href="#inbox" data-sect="inbox"><i class="fa-solid fa-inbox"></i> Inbox <b class="ss-sect-n" id="ss-inbox-n" hidden></b></a><?php endif; ?>
    <?php if ($ctx['can']['analytics']): ?><a href="#analytics" data-sect="analytics"><i class="fa-solid fa-chart-line"></i> Analytics</a><?php endif; ?>
    <?php if ($ctx['can']['reports']): ?><a href="#informes" data-sect="informes"><i class="fa-solid fa-file-lines"></i> Informes</a><?php endif; ?>
    <?php if (ss_plan_allows('social_profiles')): ?><a href="perfiles/" data-sect="perfiles"><i class="fa-solid fa-circle-user"></i> Perfiles</a><a href="perfiles/simulador.php" data-sect="simulador"><i class="fa-solid fa-mobile-screen-button"></i> Vista en redes</a><?php endif; ?>
    <a href="#cuentas" data-sect="cuentas"><i class="fa-solid fa-plug"></i> Cuentas</a>
  </nav>

  <div id="ss-planner">
  <section class="ss-bar" aria-label="Vista y filtros">
    <div class="ss-bar-row">
      <div class="ss-seg" role="tablist" aria-label="Vista">
        <button type="button" data-view="month" role="tab">Mes</button><button type="button" data-view="week" role="tab">Semana</button><button type="button" data-view="day" role="tab">Día</button><button type="button" data-view="list" role="tab">Lista</button>
      </div>
      <div class="ss-nav">
        <button type="button" class="ss-icon" data-act="prev" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
        <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="today">Hoy</button>
        <button type="button" class="ss-icon" data-act="next" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
        <h2 class="ss-period" id="ss-period"></h2>
      </div>
      <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm ss-backlog-btn" data-act="backlog" aria-pressed="false"><i class="fa-regular fa-lightbulb"></i><span>Sin fecha</span><b id="ss-backlog-n">0</b></button>
    </div>
    <div class="ss-bar-row ss-filters">
      <label class="ss-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ss-q" placeholder="Buscar título, copy, etiqueta…" autocomplete="off"></label>
      <select id="ss-f-group" aria-label="Marca o cliente"></select>
      <select id="ss-f-network" aria-label="Red"></select>
      <select id="ss-f-owner" aria-label="Responsable"></select>
      <select id="ss-f-status" aria-label="Estado"></select>
      <button type="button" class="ss-link" data-act="clear" hidden id="ss-clear"><i class="fa-solid fa-xmark"></i> Limpiar filtros</button>
    </div>
  </section>

  <div class="ss-body">
    <section id="ss-view" class="ss-view" aria-live="polite" aria-busy="true"><div class="ss-skel"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></section>
    <aside class="ss-backlog" id="ss-backlog" hidden aria-label="Publicaciones sin fecha">
      <div class="ss-backlog-h"><h3><i class="fa-regular fa-lightbulb"></i> Sin fecha</h3><button type="button" class="ss-icon" data-act="backlog" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <p class="ss-hint">Ideas y pendientes sin día. Arrastralas al calendario para programarlas, o soltá acá una publicación para sacarle la fecha.</p>
      <div class="ss-backlog-list" id="ss-backlog-list" data-drop-date=""></div>
    </aside>
  </div>
  </div>
  <section id="ss-accounts" class="ss-accounts" hidden aria-live="polite"></section>
  <section id="ss-inbox" class="ss-inbox" hidden></section>
  <section id="ss-analytics" class="ss-an" hidden></section>
  <section id="ss-reports" class="ss-rp" hidden></section>
</main>
<div class="ss-drawer-wrap" id="ss-drawer-wrap" hidden><div class="ss-drawer-back" data-act="close"></div><aside class="ss-drawer" id="ss-drawer" role="dialog" aria-modal="true" aria-labelledby="ss-drawer-h"></aside></div>
<div class="ss-modal" id="ss-modal" hidden><div class="ss-dialog" id="ss-dialog" role="dialog" aria-modal="true" aria-labelledby="ss-dialog-h"></div></div>
<div class="ss-toast" id="ss-toast" role="status" aria-live="polite"></div>
</body>
</html>
