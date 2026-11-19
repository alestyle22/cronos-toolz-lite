<?php
/**
 * bienvenida.php — Bienvenida y configuración inicial (onboarding). Pantalla de bienvenida y asistente de 4 pasos:
 * perfil, preferencias, seguridad (2FA con la app, la de totp_api.php) y confirmación. Todo lo guarda onboarding_api.php.
 * Llega acá quien tiene el onboarding pendiente (ob_enforce en auth.php) o desde un aviso. Doc: toolz/ONBOARDING.md
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta: sin código en línea (assets/onboarding.js)
require_once __DIR__ . '/onboarding_lib.php';
$db = getDB(); ob_install($db);
$isAdmin = ($currentUser['role'] === 'admin');
$next = (string)($_GET['next'] ?? '');
if (!preg_match('#^/toolz/[^\s\\\\]*$#', $next) || strpos($next, '//') !== false || strpos($next, 'bienvenida.php') !== false) $next = '/toolz/';
$site = g7_setting('platform_name') ?: g7_site('short');
$v = fn($f) => (int)@filemtime(__DIR__ . '/assets/' . $f);
// Sonido del onboarding: prendido salvo que la persona lo haya apagado en Mi configuración
$obSound = ob_pref($db, (int)$currentUser['id'], 'ui_sounds'); $obSound = ($obSound === null || !empty($obSound)) ? '1' : '0';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Bienvenida · <?= htmlspecialchars((string)$site) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/toolz/assets/onboarding.css?v=<?= $v('onboarding.css') ?>">
<link rel="stylesheet" href="/toolz/assets/onboarding-juice.css?v=<?= $v('onboarding-juice.css') ?>">
<link rel="stylesheet" href="/toolz/assets/profile-edit.css?v=<?= $v('profile-edit.css') ?>">
</head>
<body class="ob-page">
<?php include __DIR__ . '/topbar.php'; ?>
<div class="ob" id="ob" data-next="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
  <header class="ob-top">
    <span class="ob-brand"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars((string)$site) ?></span>
    <a class="ob-out" href="/toolz/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Salir</a>
  </header>
  <main class="ob-main" id="ob-main" aria-live="polite">
    <div class="ob-card ob-loading"><div class="ob-sk"></div><div class="ob-sk short"></div><div class="ob-sk"></div></div>
  </main>
</div>
<div class="ob-toast" id="ob-toast" role="status" aria-live="polite"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script src="/toolz/assets/profile-picker.js?v=<?= $v('profile-picker.js') ?>"></script>
<script src="/toolz/assets/onboarding.js?v=<?= $v('onboarding.js') ?>"></script>
<script src="/toolz/assets/onboarding-juice.js?v=<?= $v('onboarding-juice.js') ?>" data-ob-sound="<?= $obSound ?>"></script>
</body>
</html>
