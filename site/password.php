<?php
/**
 * password.php — Cambiar la contraseña. Cuando vence (rotación del sitio), todas las páginas traen acá hasta cambiarla.
 * Sin código en línea (CSP estricta): la lógica está en assets/password.js y habla con password_api.php.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
$currentUser = requireLogin();
$db = getDB();
require_once __DIR__ . '/pass_lib.php';
$left = g7_pass_days_left($db, (int)$currentUser['id']);
$expired = $left !== null && $left <= 0;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Cambiar contraseña · <?= $h(g7_setting('platform_name') ?: g7_site('name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
body{margin:0;background:var(--g7-bg,#0b0b0d);color:var(--g7-text,#fff);font-family:var(--g7-font,'Montserrat',sans-serif)}
.pw{max-width:440px;margin:48px auto;padding:0 16px}
.pw-card{background:var(--g7-bg-2);border:1px solid var(--g7-border-2);border-radius:16px;padding:24px}
.pw h1{margin:0 0 6px;font-size:22px}.pw p{color:var(--g7-text-3);line-height:1.5;margin:0 0 16px;font-size:13.5px}
.pw-note{display:flex;gap:10px;padding:12px 14px;border-radius:12px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);margin-bottom:16px;font-size:13px;line-height:1.5}
.pw-note i{color:#f59e0b;margin-top:3px}
.pw label{display:block;font-size:12px;font-weight:700;color:var(--g7-text-3);margin:12px 0 6px}
.pw input{width:100%;box-sizing:border-box;height:42px;border-radius:10px;border:1px solid var(--g7-border-3);background:var(--g7-bg-3);color:var(--g7-text);padding:0 12px;font:inherit;font-size:14px}
.pw input:focus{outline:0;border-color:var(--g7-accent)}
.pw-btn{margin-top:18px;width:100%;height:44px;border:0;border-radius:10px;background:var(--g7-accent,#ff6600);color:#fff;font:inherit;font-weight:700;font-size:14px;cursor:pointer}
.pw-btn:disabled{opacity:.6;cursor:default}
.pw-msg{margin-top:12px;font-size:13px;min-height:18px}.pw-msg.bad{color:#f87171}.pw-msg.ok{color:#4ade80}
.pw small{color:var(--g7-text-4);font-size:12px}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="pw">
  <div class="pw-card">
    <h1><?= $expired ? 'Tu contraseña venció' : 'Cambiar contraseña' ?></h1>
    <?php if ($expired): ?><div class="pw-note"><i class="fa-solid fa-key"></i><span>Este sitio pide renovar las contraseñas cada <?= (int)g7_pass_days() ?> días. Elige una nueva para seguir.</span></div>
    <?php elseif ($left !== null): ?><p>Tu contraseña vence en <?= (int)$left ?> día<?= $left === 1 ? '' : 's' ?>.</p><?php endif; ?>
    <form id="pw-form" autocomplete="on">
      <label for="pw-cur">Contraseña actual</label><input type="password" id="pw-cur" autocomplete="current-password" required>
      <label for="pw-new">Contraseña nueva</label><input type="password" id="pw-new" autocomplete="new-password" minlength="<?= g7_pass_min() ?>" required>
      <small>Al menos <?= g7_pass_min() ?> caracteres y distinta de la actual. Mejor una frase larga que una palabra con símbolos.</small>
      <label for="pw-rep">Repite la nueva</label><input type="password" id="pw-rep" autocomplete="new-password" required>
      <button class="pw-btn" type="submit" id="pw-go">Guardar la contraseña nueva</button>
      <div class="pw-msg" id="pw-msg" role="status"></div>
    </form>
  </div>
</main>
<script src="/toolz/assets/password.js?v=<?= (int)@filemtime(__DIR__ . '/assets/password.js') ?>"></script>
</body>
</html>
