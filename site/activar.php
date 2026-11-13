<?php
/**
 * activar.php — Instalación nueva todavía sin conectar a la red Cronos (g7_activation_guard en auth.php).
 * Todo el sistema trae acá hasta que el sitio base la conecta. Al superadmin le muestra el código de conexión
 * (lleva la clave secreta del sitio: a nadie más). Cuando el base hace su primer pedido firmado, queda activada sola.
 * Sin código en línea (CSP estricta): el botón de copiar está en assets/activar.js.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();
if (!g7_activation_pending()) { header('Location: /toolz/'); exit; }   // ya está activada
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$user = getCurrentUser();
$isSuper = $user && function_exists('g7_is_super') && g7_is_super((int)$user['id']);
$code = ''; $central = '';
if ($isSuper) {
    require_once __DIR__ . '/_update/lib.php';
    $c = u_cfg(); $central = (string)$c['central_url'];
    if ($c['central_url'] && $c['secret'] && $c['id'])
        $code = base64_encode(json_encode(['id' => $c['id'], 'name' => $c['name'], 'url' => $c['url'], 'secret' => $c['secret']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$name = (string)(g7_setting('platform_name') ?: g7_site('name'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><meta name="robots" content="noindex">
<title>Activar la plataforma · <?= $h($name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
:root{--bg:#0b0b0d;--card:#141418;--ink:#f4f4f6;--mute:#a3a3ad;--line:#26262d;--acc:#ff6a00}
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif;padding:20px}
.card{width:min(560px,100%);background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px}
.ico{width:52px;height:52px;border-radius:14px;display:grid;place-items:center;background:rgba(255,106,0,.12);color:var(--acc);font-size:22px;margin-bottom:14px}
h1{margin:0 0 8px;font-size:22px}p{color:var(--mute);margin:0 0 14px}
ol{color:var(--mute);padding-left:20px;margin:0 0 16px}ol li{margin:4px 0}
textarea{width:100%;height:96px;resize:none;border-radius:10px;border:1px solid var(--line);background:#0e0e12;color:var(--ink);padding:10px;font:12px ui-monospace,Consolas,monospace}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
.btn{display:inline-flex;align-items:center;gap:8px;height:42px;padding:0 16px;border-radius:10px;border:1px solid var(--line);background:#1c1c22;color:var(--ink);font:inherit;font-weight:600;text-decoration:none;cursor:pointer}
.btn.pri{background:var(--acc);border-color:transparent;color:#fff}
.note{font-size:13px;color:#fbbf24}
</style>
</head>
<body>
<main class="card">
  <div class="ico"><i class="fa-solid fa-link"></i></div>
  <h1><?= $h($name) ?> todavía no está activada</h1>
  <p>Esta instalación forma parte de la red Cronos y se habilita cuando el sitio base la conecta. Hasta entonces no se puede usar.</p>
<?php if ($isSuper && $code): ?>
  <ol>
    <li>Copia este código de conexión. Es secreto: no lo compartas por canales públicos.</li>
    <li>En el sitio base (<?= $h($central) ?>), entra a <b>Red Cronos › Sitios y versiones › Agregar sitio</b> y pégalo.</li>
    <li>Cuando el base lo agregue, vuelve acá y toca <b>Comprobar</b>.</li>
  </ol>
  <textarea id="act-code" readonly><?= $h($code) ?></textarea>
  <div class="row"><button class="btn pri" type="button" id="act-copy"><i class="fa-regular fa-copy"></i> Copiar el código</button>
    <a class="btn" href="/toolz/activar.php"><i class="fa-solid fa-rotate"></i> Comprobar</a>
    <a class="btn" href="/toolz/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a></div>
<?php elseif ($isSuper): ?>
  <p class="note">Falta la dirección del sitio base o la clave de esta instalación en <code>_private/site.php</code> (central_url y secret). Sin eso no se puede armar el código de conexión: hay que volver a instalar o completarlo a mano.</p>
  <div class="row"><a class="btn" href="/toolz/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a></div>
<?php elseif ($user): ?>
  <p class="note">Pídele al superadmin de esta instalación que la conecte a la red Cronos.</p>
  <div class="row"><a class="btn" href="/toolz/activar.php"><i class="fa-solid fa-rotate"></i> Comprobar</a><a class="btn" href="/toolz/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a></div>
<?php else: ?>
  <p>Si eres el superadmin de esta instalación, entra para ver el código de conexión.</p>
  <div class="row"><a class="btn pri" href="/toolz/login.php"><i class="fa-solid fa-right-to-bracket"></i> Entrar</a></div>
<?php endif; ?>
</main>
<script src="/toolz/assets/activar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/activar.js') ?>"></script>
</body>
</html>
