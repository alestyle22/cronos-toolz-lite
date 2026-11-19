<?php
/**
 * bienestar/index.php — Pantalla Bienestar: portada con resumen y subsecciones (ánimo, hábitos, retos, encuestas,
 * reconocimientos, carga, ausencias y, para quien gestiona, Gestión). Todo lo dibuja app.js con datos de api.php.
 * Sin código en línea (CSP estricta). Doc: toolz/BIENESTAR.md
 */
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();   // también aplica el permiso del módulo 'bienestar' (g7_enforce_access)
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();
$db = getDB();
require_once __DIR__ . '/lib.php';
bn_install($db);
$ctx = bn_ctx($db, (int)$currentUser['id']);
try {
    $_p = $db->prepare("SELECT pref_key, pref_value FROM g7_user_prefs WHERE user_id = ? AND pref_key IN ('theme','font_size')");
    $_p->execute([$currentUser['id']]); $_prefs = [];
    foreach ($_p->fetchAll() as $_r) $_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Throwable $e) { $_prefs = []; }
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
$tabs = ['inicio' => ['Inicio', 'fa-house'], 'animo' => ['Ánimo', 'fa-face-smile'], 'habitos' => ['Hábitos', 'fa-seedling'], 'retos' => ['Retos', 'fa-person-running'],
    'encuestas' => ['Encuestas', 'fa-square-poll-vertical'], 'reconocimientos' => ['Reconocimientos', 'fa-award'], 'carga' => ['Carga', 'fa-scale-balanced'], 'ausencias' => ['Ausencias', 'fa-umbrella-beach']];
if ($ctx['manage']) $tabs['gestion'] = ['Gestión', 'fa-sliders'];
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars((string)($_prefs['theme'] ?? 'dark')) ?> font-<?= htmlspecialchars((string)($_prefs['font_size'] ?? 'medium')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Bienestar · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<script type="application/json" data-global="BN_BOOT"><?= json_encode(['name' => $ctx['name'], 'manage' => $ctx['manage'], 'admin' => $ctx['admin']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="app.js?v=<?= $v('app.js') ?>" defer></script>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<main class="bn" id="bn">
  <header class="bn-hero">
    <div class="bn-hero-txt">
      <p class="bn-kicker" id="bn-date"></p>
      <h1>Hola, <?= htmlspecialchars(explode(' ', $ctx['name'])[0]) ?></h1>
      <p class="bn-sub">Tu espacio para cuidarte, sumar buenos hábitos y agradecer al equipo. Lo tuyo es privado: nadie ve tus respuestas.</p>
    </div>
    <div class="bn-hero-acts">
      <button type="button" class="bn-btn bn-btn-ghost" id="bn-prefs"><i class="fa-regular fa-bell"></i> Avisos</button>
      <button type="button" class="bn-btn" id="bn-thank"><i class="fa-solid fa-award"></i> Reconocer a alguien</button>
    </div>
    <div class="bn-hero-art" aria-hidden="true"><span></span><span></span><span></span></div>
  </header>
  <nav class="bn-tabs" id="bn-tabs" role="tablist" aria-label="Secciones de Bienestar">
    <?php foreach ($tabs as $k => [$l, $i]): ?><a href="#<?= $k ?>" class="bn-tab" data-tab="<?= $k ?>" role="tab"><i class="fa-solid <?= $i ?>"></i><span><?= $l ?></span><?php if ($k === 'gestion'): ?><b class="bn-dot" id="bn-dot-gestion" hidden></b><?php endif; ?></a><?php endforeach; ?>
  </nav>
  <section id="bn-view" class="bn-view" aria-live="polite" aria-busy="true"><div class="bn-loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</div></section>
</main>
<div class="bn-modal" id="bn-modal" hidden><div class="bn-dialog" id="bn-dialog" role="dialog" aria-modal="true" aria-labelledby="bn-dialog-h"></div></div>
<div class="bn-toast" id="bn-toast" role="status" aria-live="polite"></div>
</body>
</html>
