<?php
/**
 * trabajo/builder.php — Constructor visual de formularios del Portal de Solicitudes (builder.js).
 * Arrastrar y soltar, propiedades, condiciones, pasos o completo, tema, vista previa desktop/mobile, borrador y versiones.
 * Los permisos los decide portal_api.php (form_edit / form_save / form_publish). Doc: toolz/SOLICITUDES.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/lib.php';
wk_install($db);
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
$pa = htmlspecialchars($ptAssets ?? '');   // solicitudes/builder.php carga los archivos desde trabajo/
$id = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Constructor de formularios · <?= htmlspecialchars(g7_setting('platform_name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&family=Roboto:wght@400;500;700&family=Lora:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $pa ?>trabajo.css?v=<?= $v('trabajo.css') ?>">
<link rel="stylesheet" href="<?= $pa ?>portal.css?v=<?= $v('portal.css') ?>">
<link rel="stylesheet" href="<?= $pa ?>builder.css?v=<?= $v('builder.css') ?>">
</head>
<body class="bd-page">
<?php include __DIR__ . '/../topbar.php'; ?>
<div class="bd" id="bd" data-id="<?= $id ?>">
  <header class="bd-top">
    <a class="pt-ic" id="bd-back" href="index.php#portal-config" title="Volver" aria-label="Volver"><i class="fa-solid fa-arrow-left"></i></a>
    <div class="bd-title"><input id="bd-name" maxlength="100" aria-label="Nombre del formulario" placeholder="Nombre del formulario"><small id="bd-status">Cargando…</small></div>
    <div class="bd-top-mid">
      <button type="button" class="pt-ic" id="bd-undo" title="Deshacer (Ctrl+Z)" aria-label="Deshacer"><i class="fa-solid fa-rotate-left"></i></button>
      <button type="button" class="pt-ic" id="bd-redo" title="Rehacer (Ctrl+Y)" aria-label="Rehacer"><i class="fa-solid fa-rotate-right"></i></button>
      <span class="bd-sep"></span>
      <div class="bd-seg" id="bd-mode" role="group" aria-label="Modalidad"><button type="button" data-m="full"><i class="fa-solid fa-file-lines"></i> Completo</button><button type="button" data-m="steps"><i class="fa-solid fa-shoe-prints"></i> Por pasos</button></div>
    </div>
    <div class="bd-top-a">
      <button type="button" class="wk-btn" id="bd-preview"><i class="fa-solid fa-eye"></i><span> Vista previa</span></button>
      <button type="button" class="wk-btn" id="bd-save"><i class="fa-regular fa-floppy-disk"></i><span> Guardar</span></button>
      <button type="button" class="pt-btn primary sm" id="bd-publish"><i class="fa-solid fa-rocket"></i> Publicar</button>
    </div>
  </header>
  <div class="bd-body">
    <aside class="bd-pal" id="bd-pal" aria-label="Elementos"></aside>
    <main class="bd-canvas" id="bd-canvas"><div class="bd-paper ptf" id="bd-paper"><div class="pt-sk"></div><div class="pt-sk"></div></div></main>
    <aside class="bd-props" id="bd-props" aria-label="Propiedades"></aside>
  </div>
</div>
<div class="bd-prev" id="bd-prev" hidden>
  <div class="bd-prev-bar"><b>Vista previa</b><div class="bd-seg" id="bd-dev"><button type="button" data-d="desktop" class="on"><i class="fa-solid fa-display"></i> Escritorio</button><button type="button" data-d="mobile"><i class="fa-solid fa-mobile-screen"></i> Celular</button></div><span class="sp"></span><span class="pt-muted" id="bd-prev-est"></span><button type="button" class="wk-btn" id="bd-prev-x"><i class="fa-solid fa-xmark"></i> Cerrar</button></div>
  <div class="bd-prev-stage"><div class="bd-device" id="bd-device"><div class="bd-device-in" id="bd-prev-body"></div></div></div>
</div>
<dialog class="wk-dlg" id="wk-dlg"></dialog>
<div class="wk-toast" id="wk-toast" role="status" aria-live="polite"></div>
<script src="<?= $pa ?>form_render.js?v=<?= $v('form_render.js') ?>"></script>
<script src="<?= $pa ?>form_editors.js?v=<?= $v('form_editors.js') ?>"></script>
<script src="<?= $pa ?>builder.js?v=<?= $v('builder.js') ?>"></script>
<link rel="stylesheet" href="<?= $pa ?>builder-juice.css?v=<?= $v('builder-juice.css') ?>">
<script src="<?= $pa ?>builder-juice.js?v=<?= $v('builder-juice.js') ?>"></script>
</body>
</html>
