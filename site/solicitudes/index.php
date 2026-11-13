<?php
/**
 * solicitudes/index.php — Portal de Solicitudes como módulo propio (fuera de Gestión de trabajo).
 * Es el mismo portal de trabajo/ (portal.js, portal_admin.js, portal_api.php): acá solo cambia la entrada y el permiso,
 * así quien no usa Trabajo también puede pedir y seguir sus pedidos. Doc: toolz/SOLICITUDES.md › "Portal aparte".
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once dirname(__DIR__) . '/trabajo/lib.php';
wk_install($db);
$T = dirname(__DIR__) . '/trabajo/';
$v = fn($f) => (int)@filemtime($T . $f);
$canWork = g7_can((int)$currentUser['id'], 'tareas');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Solicitudes · <?= htmlspecialchars(g7_setting('platform_name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../trabajo/trabajo.css?v=<?= $v('trabajo.css') ?>">
<link rel="stylesheet" href="../trabajo/portal.css?v=<?= $v('portal.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<div class="wk">
  <nav class="wk-nav" id="wk-nav" aria-label="Secciones del portal de solicitudes">
    <div class="wk-nav-h">Portal de <span>solicitudes</span></div>
    <a href="#solicitudes" data-sec="solicitudes" data-key="solicitudes"><i class="fa-solid fa-plus"></i><span>Nuevo pedido</span></a>
    <a href="#solicitudes/mias" data-sec="solicitudes" data-key="solicitudes/mias"><i class="fa-solid fa-paper-plane"></i><span>Mis solicitudes</span></a>
    <a href="#gestion" data-sec="gestion" hidden><i class="fa-solid fa-list-check"></i><span>Recibidas</span><b class="wk-badge" id="wk-n-pt" hidden></b></a>
    <a href="#portal-metricas" data-sec="portal-metricas" hidden><i class="fa-solid fa-chart-column"></i><span>Métricas</span></a>
    <a href="#portal-config" data-sec="portal-config" hidden><i class="fa-solid fa-sitemap"></i><span>Departamentos</span></a>
<?php if ($canWork): ?>    <a href="/toolz/trabajo/"><i class="fa-solid fa-table-columns"></i><span>Ir a Trabajo</span></a>
<?php endif; ?>    <div class="wk-nav-me" id="wk-me"></div>
  </nav>
  <main class="wk-main" id="wk-main" tabindex="-1"><div class="wk-sk"></div><div class="wk-sk"></div><div class="wk-sk"></div></main>
</div>
<dialog class="wk-dlg" id="wk-dlg"></dialog>
<div class="wk-toast" id="wk-toast" role="status" aria-live="polite"></div>
<script src="app.js?v=<?= (int)@filemtime(__DIR__ . '/app.js') ?>"></script>
<script src="../trabajo/form_render.js?v=<?= $v('form_render.js') ?>"></script>
<script src="../trabajo/portal.js?v=<?= $v('portal.js') ?>"></script>
<script src="../trabajo/form_editors.js?v=<?= $v('form_editors.js') ?>"></script>
<script src="../trabajo/portal_admin.js?v=<?= $v('portal_admin.js') ?>"></script>
</body>
</html>
