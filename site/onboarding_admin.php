<?php
/**
 * onboarding_admin.php — Panel del onboarding (solo administración): estadísticas, estado de cada persona, acciones
 * (forzar, pedir lo que falta, exigir 2FA, reiniciar, recordar) y configuración. API: onboarding_api.php (admin_*). Doc: toolz/ONBOARDING.md
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireAdmin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
require_once __DIR__ . '/onboarding_lib.php';
$db = getDB(); ob_install($db);
$isAdmin = true;
$v = fn($f) => (int)@filemtime(__DIR__ . '/assets/' . $f);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Onboarding · <?= htmlspecialchars((string)(g7_setting('platform_name') ?: g7_site('short'))) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/assets/onboarding.css?v=<?= $v('onboarding.css') ?>">
<link rel="stylesheet" href="/toolz/assets/onboarding-admin.css?v=<?= $v('onboarding-admin.css') ?>">
</head>
<body class="oba-page">
<?php include __DIR__ . '/topbar.php'; ?>
<div class="oba">
  <header class="oba-h"><div><a class="oba-back" href="/toolz/admin.php"><i class="fa-solid fa-arrow-left"></i> Administración</a><h1>Bienvenida y configuración inicial</h1>
    <p>Quién terminó de configurar su cuenta, quién no, y cómo es la bienvenida para las personas nuevas.</p></div>
    <a class="ob-btn sm" href="/toolz/bienvenida.php?ver=1" target="_blank" rel="noopener"><i class="fa-solid fa-eye"></i> Ver como lo ve una persona</a></header>
  <nav class="oba-tabs" id="oba-tabs"><button type="button" data-t="people" class="on"><i class="fa-solid fa-users"></i> Personas</button><button type="button" data-t="cfg"><i class="fa-solid fa-sliders"></i> Configuración</button></nav>
  <main id="oba-main"><div class="ob-card ob-loading"><div class="ob-sk"></div><div class="ob-sk short"></div></div></main>
</div>
<dialog class="oba-dlg" id="oba-dlg"></dialog>
<div class="ob-toast" id="ob-toast" role="status" aria-live="polite"></div>
<script src="/toolz/assets/onboarding-admin.js?v=<?= $v('onboarding-admin.js') ?>"></script>
</body>
</html>
