<?php
/**
 * social/perfiles/simulador.php — Vista en redes (Profile Studio): cómo se ven la foto de perfil y la portada en
 * Facebook, Instagram, X, LinkedIn, YouTube y TikTok, en computadora, tablet y celular, con zonas seguras y aviso
 * de medidas. Las imágenes se eligen del dispositivo (no se suben: quedan en el navegador) o de los diseños de
 * Profile Studio de la persona. Lógica en simulador.js. Doc: toolz/SOCIAL_STUDIO.md › Vista en redes
 */
require_once __DIR__ . '/../../auth.php';
$currentUser = requireLogin();   // módulo 'social'
require_once dirname(__DIR__, 2) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once dirname(__DIR__) . '/lib.php';
ss_install($db);
$planOk = ss_plan_allows('social_profiles');
$E = dirname(__DIR__, 2) . '/portal/editor';
$v = fn($f) => (int)@filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Vista en redes · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/toolz/portal/editor/editor.css?v=<?= (int)@filemtime($E . '/editor.css') ?>">
<link rel="stylesheet" href="simulador.css?v=<?= $v('simulador.css') ?>">
<?php require_once dirname(__DIR__, 2) . '/editor_studio.php'; g7_editor_studio_head($db, $currentUser, 'profile'); ?>
<?php if ($planOk): ?><script src="simulador.js?v=<?= $v('simulador.js') ?>" defer></script><?php endif; ?>
</head>
<body class="pbe sim-body">
<?php g7_editor_studio_menu($db, $currentUser); ?>
<?php if (!$planOk): ?><main class="pbl"><div class="pbl-empty"><p><b>Vista en redes es parte de Profile Studio, que no está en tu plan.</b><br>Para sumarlo, mejorá el plan desde Admin › Licencia.</p></div></main>
<?php else: ?>
<header class="pbe-top">
  <a class="pbe-icon" href="/toolz/social/perfiles/" title="Volver a Profile Studio" aria-label="Volver"><i class="fa-solid fa-arrow-left"></i></a>
  <div class="pbs-id" aria-hidden="true"><i class="fa-solid fa-mobile-screen-button"></i></div>
  <div class="pbe-title"><b class="pbe-name sim-name">Vista en redes</b><span class="pbe-kind">Tu foto y tu portada como se ven en cada red</span></div>
  <span class="pbe-sep"></span>
  <nav class="sim-nets" id="sim-nets" aria-label="Red"></nav>
  <span class="pbe-grow"></span>
  <div class="pbe-dev" role="group" aria-label="Dispositivo" id="sim-dev"><button type="button" class="on" data-dev="pc" aria-label="Computadora"><i class="fa-solid fa-desktop"></i></button><button type="button" data-dev="tab" aria-label="Tablet"><i class="fa-solid fa-tablet-screen-button"></i></button><button type="button" data-dev="mob" aria-label="Celular"><i class="fa-solid fa-mobile-screen"></i></button></div>
  <button type="button" class="pbe-icon" id="sim-theme" title="Modo claro u oscuro de la red" aria-label="Modo claro u oscuro"><i class="fa-solid fa-circle-half-stroke"></i></button>
  <button type="button" class="pbe-icon" id="sim-safe" title="Mostrar zonas seguras" aria-label="Zonas seguras" aria-pressed="false"><i class="fa-regular fa-square"></i></button>
</header>
<main class="pbe-main sim-main">
  <aside class="pbe-left sim-left" id="sim-left"></aside>
  <section class="pbe-stage sim-stage" id="sim-stage" aria-label="Vista previa"><div class="sim-fit" id="sim-fit"><div class="sim-device" id="sim-device"></div></div><div class="sim-cap" id="sim-cap"></div></section>
</main>
<div class="pbe-toast" id="sim-toast" role="status" aria-live="polite" hidden></div>
<input type="file" id="sim-file" accept="image/*" hidden>
<?php endif; ?>
</body>
</html>
