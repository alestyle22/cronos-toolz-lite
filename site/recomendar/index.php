<?php
/**
 * recomendar/index.php — "Recomienda Cronos": la agencia recomienda Cronos a otras agencias y gana con su primer pago.
 * Lo dibuja app.js con datos de api.php; sin código en línea (CSP estricta). Doc: toolz/AFILIADOS.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();   // también aplica el permiso del módulo 'recomendar'
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();   // la usa topbar.php
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
<title>Recomienda Cronos · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<script src="app.js?v=<?= $v('app.js') ?>" defer></script>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<main class="rc" id="rc">
  <header class="rc-hero">
    <div>
      <p class="rc-kick"><i class="fa-solid fa-handshake"></i> Programa de recomendados</p>
      <h1>Recomienda Cronos<span>.</span></h1>
      <p class="rc-sub" id="rc-rule">Cuando una agencia que recomendaste empieza a usar Cronos, ganas un porcentaje de su primer pago. Lo descuentas de tu plan o lo cobras.</p>
    </div>
  </header>
  <div id="rc-body"><div class="rc-loading">Cargando…</div></div>
</main>
<div class="rc-toast" id="rc-toast" role="status" aria-live="polite"></div>
</body>
</html>
