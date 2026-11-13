<?php
/**
 * trabajo/index.php — Gestión de trabajo: Resumen, Mis tareas, Tableros y Configuración (roles).
 * Toda la lógica está en trabajo.js, que habla con api.php. Cada tablero se abre en /toolz/tareas.php?b=ID.
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDB();
require_once __DIR__ . '/lib.php';
wk_install($db);
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
// Jira (Admin › Jira API › Usar Jira en Cronos): la sección aparece solo si está prendida para Gestión de trabajo
require_once __DIR__ . '/../jira_use_lib.php';
$jiraOn = g7_jira_on('trabajo');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gestión de trabajo · <?= htmlspecialchars(g7_setting('platform_name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="trabajo.css?v=<?= $v('trabajo.css') ?>">
<link rel="stylesheet" href="portal.css?v=<?= $v('portal.css') ?>">
<link rel="stylesheet" href="../chat/refs.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/chat/refs.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<div class="wk">
  <nav class="wk-nav" id="wk-nav" aria-label="Secciones de Gestión de trabajo">
    <div class="wk-nav-h">Gestión de <span>trabajo</span></div>
    <a href="#resumen" data-sec="resumen"><i class="fa-solid fa-chart-simple"></i><span>Resumen</span></a>
    <a href="#mis-tareas" data-sec="mis-tareas"><i class="fa-solid fa-user-check"></i><span>Mis tareas</span><b class="wk-badge" id="wk-n-mine" hidden></b></a>
    <a href="#mis-solicitudes" data-sec="mis-solicitudes"><i class="fa-solid fa-paper-plane"></i><span>Mis solicitudes</span><b class="wk-badge" id="wk-n-myreq" hidden></b></a>
    <a href="#calendario" data-sec="calendario" id="wk-nav-cal" hidden><i class="fa-regular fa-calendar"></i><span>Calendario</span></a>
    <a href="#proyectos" data-sec="proyectos"><i class="fa-solid fa-diagram-project"></i><span>Proyectos</span></a>
    <a href="#solicitudes" data-sec="solicitudes"><i class="fa-solid fa-inbox"></i><span>Solicitudes</span></a>
    <a href="#gestion" data-sec="gestion" hidden><i class="fa-solid fa-list-check"></i><span>Recibidas</span><b class="wk-badge" id="wk-n-pt" hidden></b></a>
    <a href="#portal-metricas" data-sec="portal-metricas" hidden><i class="fa-solid fa-chart-column"></i><span>Métricas del portal</span></a>
    <a href="#portal-config" data-sec="portal-config" hidden><i class="fa-solid fa-sitemap"></i><span>Departamentos</span></a>
    <a href="#tickets" data-sec="tickets"><i class="fa-solid fa-ticket"></i><span>Tickets</span></a>
    <a href="#procesos" data-sec="procesos" id="wk-nav-proc" hidden><i class="fa-solid fa-diagram-next"></i><span>Procesos</span></a>
<?php if ($jiraOn): ?>    <a href="#jira" data-sec="jira"><i class="fa-brands fa-jira"></i><span>Jira</span></a>
<?php endif; ?>    <a href="#informes" data-sec="informes"><i class="fa-solid fa-chart-pie"></i><span>Informes</span></a>
    <a href="#tableros" data-sec="tableros" id="wk-nav-boards" hidden><i class="fa-solid fa-table-columns"></i><span>Tableros</span></a>
    <a href="#config" data-sec="config" id="wk-nav-cfg" hidden><i class="fa-solid fa-sliders"></i><span>Configuración</span></a>
    <div class="wk-nav-me" id="wk-me"></div>
  </nav>
  <main class="wk-main" id="wk-main" tabindex="-1"><div class="wk-sk"></div><div class="wk-sk"></div><div class="wk-sk"></div></main>
</div>
<dialog class="wk-dlg" id="wk-dlg"></dialog>
<div class="wk-toast" id="wk-toast" role="status" aria-live="polite"></div>
<script src="../chat/refs.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/chat/refs.js') ?>"></script>
<script src="../assets/calificar.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/calificar.js') ?>"></script>
<script src="trabajo.js?v=<?= $v('trabajo.js') ?>"></script>
<script src="form_render.js?v=<?= $v('form_render.js') ?>"></script>
<script src="portal.js?v=<?= $v('portal.js') ?>"></script>
<script src="form_editors.js?v=<?= $v('form_editors.js') ?>"></script>
<script src="portal_admin.js?v=<?= $v('portal_admin.js') ?>"></script>
<?php if ($jiraOn): ?><script src="jira.js?v=<?= $v('jira.js') ?>"></script><?php endif; ?>
</body>
</html>
