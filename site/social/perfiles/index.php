<?php
/**
 * social/perfiles/index.php — Profile Studio (Social Studio › Perfiles): profile pictures for each network.
 * Its own page because background removal runs in the browser (Transformers.js + BiRefNet lite, MIT) and needs the CSP profile 'ml'
 * (wasm and blob workers); the rest of Social Studio keeps the strict CSP. Everything is drawn by app.js with data from ../api.php (pf_*).
 * Doc: toolz/SOCIAL_STUDIO.md (Profile Studio)
 */
require_once __DIR__ . '/../../auth.php';
$currentUser = requireLogin();   // module 'social' (g7_enforce_access)
require_once dirname(__DIR__, 2) . '/csp_lib.php'; g7_csp_enforce('ml');
$db = getDB();
require_once dirname(__DIR__) . '/lib.php';
ss_install($db);
$ctx = ss_ctx($db, (int)$currentUser['id']);
$planOk = ss_plan_allows('social_profiles');
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
<title>Profile Studio · <?= htmlspecialchars((string)g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<script type="application/json" data-global="PF_BOOT"><?= json_encode(['name' => $ctx['name']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="/toolz/assets/g7-imgprep.js?v=<?= (int)@filemtime(dirname(__DIR__, 2) . '/assets/g7-imgprep.js') ?>" defer></script>
<script src="render.js?v=<?= $v('render.js') ?>" defer></script>
<?php if ($planOk): ?><script src="app.js?v=<?= $v('app.js') ?>" defer></script><?php endif; ?>
</head>
<body class="pf-body">
<?php include dirname(__DIR__, 2) . '/topbar.php'; ?>
<?php // La piel de las apps de estudio (editor-studio.css/.js › Profile Studio). Después de topbar.php, que ya cargó g7-juice y el resto de lo común.
require_once dirname(__DIR__, 2) . '/editor_studio.php'; g7_editor_studio_head($db, $currentUser, 'profile'); ?>
<?php if (!$planOk): ?><main class="pf"><div class="pf-empty" style="margin-top:40px"><i class="fa-solid fa-lock"></i><p><b>Profile Studio no está en tu plan.</b><br>Para sumarlo, mejorá el plan desde Admin › Licencia.</p><p><a class="pf-btn pf-btn-ghost" href="/toolz/social/">Volver a Social Studio</a></p></div></main>
<?php else: ?><main class="pf" id="pf"><div class="pf-loading"><i class="fa-solid fa-spinner fa-spin"></i></div></main><?php endif; ?>
<div class="pf-toast" id="pf-toast" role="status" aria-live="polite"></div>
<div class="pf-modal" id="pf-modal" hidden><div class="pf-dialog" id="pf-dialog" role="dialog" aria-modal="true"></div></div>
<input type="file" id="pf-file" accept="image/jpeg,image/png,image/webp" hidden>
</body>
</html>
