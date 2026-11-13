<?php
require_once __DIR__ . '/auth.php';
$user = getCurrentUser();
if ($user) { header('Location: /toolz/'); exit; }

$error = '';
$step2 = false;   // segundo paso: pide el código de la app (verificación en dos pasos)
if (!empty($_GET['code']) && !empty($_SESSION['g7_2fa'])) $step2 = true;   // viene de otro login (Promos) con el código pendiente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $r = g7_login_2fa((string)$_POST['code']);
    if (is_array($r)) { header('Location: /toolz/'); exit; }
    if ($r === 'expired') $error = 'Pasaron más de 5 minutos. Vuelve a escribir tu usuario y contraseña.';
    else { $step2 = true; $error = $r === 'locked' ? 'Demasiados códigos incorrectos. Prueba de nuevo en 15 minutos.' : 'El código no es correcto. Revisa la hora del teléfono o usa un código de respaldo.'; }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u && $p) {
        $result = login($u, $p);
        if (is_array($result) && !empty($result['need_2fa'])) $step2 = true;
        elseif ($result) { header('Location: /toolz/'); exit; }
        else {
            $wait = g7_login_locked($u);
            $error = $wait > 0
                ? "Demasiados intentos fallidos. Prueba de nuevo en $wait " . ($wait === 1 ? 'minuto' : 'minutos') . '.'
                : 'Usuario o contraseña incorrectos.';
        }
    } else { $error = 'Completa todos los campos.'; }
}
// Textos y logo configurables en Admin › Configuración › Pantalla de login
$lgName  = g7_setting('platform_name') ?: g7_site('name');
$lgTitle = trim((string)g7_setting('login_title')) ?: $lgName;
$lgSub   = (string)g7_setting('login_subtitle');
$lgMsg   = trim((string)g7_setting('login_message'));
$lgLogo  = trim((string)g7_setting('login_logo_url')) ?: (trim((string)g7_cfg('logo_url', '')) ?: g7_site('logo'));
$lgAcc   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)g7_setting('accent_color')) ? g7_setting('accent_color') : '#ff6600';
?>
<?php
// CSP estricta en modo BLOQUEO (fase 2, SEC-24): login.php no tiene scripts ni eventos en línea; cualquier script inyectado no corre.
if (!headers_sent()) header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ingresar · <?= htmlspecialchars($lgName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<style>
:root{--acc:<?= $lgAcc ?>}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:#0a0a0b;color:#e7e7ea;font-family:'Geist','Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;position:relative;overflow-x:hidden}
body::before{content:"";position:fixed;inset:0;pointer-events:none;
  background:radial-gradient(620px 360px at 50% -6%,color-mix(in srgb,var(--acc) 22%,transparent),transparent 70%),radial-gradient(500px 300px at 85% 110%,rgba(255,255,255,.04),transparent 70%)}
body::after{content:"";position:fixed;inset:0;pointer-events:none;opacity:.035;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E")}
.wrap{position:relative;z-index:1;width:100%;max-width:392px;animation:in .5s cubic-bezier(.2,.8,.2,1) both}
@keyframes in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.logo{display:flex;justify-content:center;margin-bottom:28px}
.logo img{height:46px;max-width:240px;object-fit:contain}
.card{background:rgba(20,20,22,.78);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.07);border-radius:20px;padding:30px 28px 26px;box-shadow:0 30px 80px rgba(0,0,0,.45),inset 0 1px 0 rgba(255,255,255,.04)}
h1{font-size:22px;font-weight:700;letter-spacing:-.03em;text-align:center}
.sub{font-size:13.5px;color:#8b8b93;text-align:center;margin-top:6px}
form{margin-top:26px;display:flex;flex-direction:column;gap:14px}
label{display:block;font-size:12.5px;font-weight:500;color:#b4b4bb;margin-bottom:7px}
.field{position:relative;display:flex;align-items:center}
.field>i{position:absolute;left:13px;font-size:13px;color:#5d5d66;pointer-events:none;transition:color .15s}
input{width:100%;height:46px;background:#0f0f11;border:1px solid #26262b;border-radius:11px;padding:0 42px 0 38px;color:#ececf0;font:500 14px inherit;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s}
input::placeholder{color:#55555d}
input:focus{border-color:color-mix(in srgb,var(--acc) 70%,transparent);box-shadow:0 0 0 4px color-mix(in srgb,var(--acc) 16%,transparent)}
.field:focus-within>i{color:var(--acc)}
.eye{position:absolute;right:6px;width:34px;height:34px;border:none;background:none;color:#6b6b74;border-radius:8px;cursor:pointer}
.eye:hover{color:#ececf0;background:#1b1b1f}
.btn{margin-top:6px;height:46px;border:none;border-radius:11px;background:var(--acc);color:#0a0a0a;font:600 14px inherit;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:filter .15s,transform .1s}
.btn:hover{filter:brightness(1.08)}
.btn:active{transform:translateY(1px)}
.btn[disabled]{opacity:.7;cursor:progress}
.error{display:flex;gap:10px;align-items:flex-start;margin-top:22px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);color:#fca5a5;border-radius:11px;padding:11px 13px;font-size:13px;line-height:1.45}
.error i{margin-top:2px}
.msg{margin-top:20px;font-size:12.5px;color:#7a7a83;text-align:center;line-height:1.55;white-space:pre-line}
.foot{text-align:center;margin-top:22px;font-size:11.5px;color:#4d4d55}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
@media (prefers-reduced-motion:reduce){.wrap{animation:none}}
</style>
<script src="/toolz/assets/login.js?v=1" defer></script>
</head>
<body>
<main class="wrap">
  <div class="logo"><img src="<?= htmlspecialchars($lgLogo) ?>" alt="<?= htmlspecialchars($lgName) ?>"></div>
  <div class="card">
    <h1><?= htmlspecialchars($lgTitle) ?></h1>
    <?php if ($lgSub !== ''): ?><p class="sub"><?= htmlspecialchars($lgSub) ?></p><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
    <?php if ($step2): ?>
    <form method="POST" data-busy="Verificando…">
      <p class="sub" style="margin-top:0">Escribe el código de 6 números de tu app de autenticación. Si no tienes el teléfono, usa uno de tus códigos de respaldo.</p>
      <div>
        <label for="c">Código</label>
        <div class="field"><i class="fa-solid fa-shield-halved"></i><input id="c" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus placeholder="123456"></div>
      </div>
      <button class="btn" type="submit"><span>Verificar</span><i class="fa-solid fa-arrow-right"></i></button>
      <a href="/toolz/login.php" style="text-align:center;font-size:12.5px;color:#8b8b93;text-decoration:none">Volver a empezar</a>
    </form>
    <?php else: ?>
    <form method="POST" data-busy="Ingresando…">
      <div>
        <label for="u">Usuario o email</label>
        <div class="field"><i class="fa-regular fa-user"></i><input id="u" type="text" name="username" autocomplete="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
      </div>
      <div>
        <label for="p">Contraseña</label>
        <div class="field"><i class="fa-solid fa-lock"></i><input id="p" type="password" name="password" autocomplete="current-password" required>
          <button type="button" class="eye" aria-label="Mostrar contraseña" data-toggle-pass="p"><i class="fa-regular fa-eye"></i></button></div>
      </div>
      <button class="btn" type="submit"><span>Ingresar</span><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <?php endif; ?>
    <?php if ($lgMsg !== ''): ?><p class="msg"><?= htmlspecialchars($lgMsg) ?></p><?php endif; ?>
  </div>
  <?php if ($lgTitle !== $lgName): ?><p class="foot"><?= htmlspecialchars($lgName) ?></p><?php endif; ?>
</main>
</body>
</html>
