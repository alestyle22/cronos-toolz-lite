<?php
/**
 * activar_2fa.php — Guía paso a paso para activar la verificación en dos pasos (TOTP).
 * Llega acá quien entra sin tenerla cuando el sitio se la exige (g7_totp_enforce en auth.php). Al terminar vuelve a la página
 * que quería abrir (?next=). Usa totp_api.php (state, start, confirm). Doc: toolz/SEGURIDAD_2FA.md
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta: sin código en línea (assets/activar-2fa.js)
require_once __DIR__ . '/totp_lib.php';
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDB(); g7_totp_install($db);
$uid = (int)$currentUser['id'];

// Solo direcciones de este sitio como destino final
$next = (string)($_GET['next'] ?? '');
if (!preg_match('#^/toolz/[^\s\\\\]*$#', $next) || strpos($next, '//') !== false || strpos($next, 'activar_2fa.php') !== false) $next = '/toolz/';
if (g7_totp_enabled($db, $uid)) { header('Location: ' . $next); exit; }
$required = g7_totp_required_for($uid);
$site = g7_setting('platform_name') ?: g7_site('short');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Activa la verificación en dos pasos · <?= htmlspecialchars($site) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<style>
*{box-sizing:border-box}
body{margin:0;background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',system-ui,sans-serif;min-height:100vh}
.tfg{max-width:600px;margin:0 auto;padding:32px 16px 60px}
.tfg-steps{display:flex;gap:6px;margin-bottom:22px;list-style:none;padding:0}
.tfg-steps li{flex:1;display:flex;flex-direction:column;gap:6px;font-size:11px;color:var(--g7-text-4);font-weight:600}
.tfg-steps li::before{content:'';height:4px;border-radius:2px;background:var(--g7-bg-4);transition:background .3s}
.tfg-steps li.done::before,.tfg-steps li.on::before{background:var(--g7-accent)}
.tfg-steps li.on{color:var(--g7-text)}
.tfg-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:16px;padding:28px 26px}
.tfg-step{display:none;animation:tfgIn .25s ease-out}
.tfg-step.on{display:block}
.tfg-ico{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;background:rgba(var(--g7-accent-rgb),.12);color:var(--g7-accent);margin-bottom:16px}
.tfg h1{font-size:22px;font-weight:800;margin:0 0 8px;letter-spacing:-.01em}
.tfg p{font-size:14px;line-height:1.6;color:var(--g7-text-2);margin:0 0 12px}
.tfg .muted{color:var(--g7-text-4);font-size:13px}
.tfg-why{display:grid;gap:10px;margin:18px 0}
.tfg-why div{display:flex;gap:12px;align-items:flex-start;font-size:13px;color:var(--g7-text-2);line-height:1.5}
.tfg-why i{color:var(--g7-accent);width:18px;text-align:center;margin-top:3px}
.tfg-apps{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:16px 0}
.tfg-app{border:1px solid var(--g7-border-3);border-radius:12px;padding:12px 14px;background:var(--g7-bg-2)}
.tfg-app b{display:block;font-size:13px;margin-bottom:6px}
.tfg-app a{font-size:12px;color:var(--g7-accent);text-decoration:none;margin-right:10px}
.tfg-app a:hover{text-decoration:underline}
.tfg-qrwrap{display:flex;gap:20px;align-items:center;flex-wrap:wrap;margin:16px 0}
.tfg-qr{background:#fff;padding:12px;border-radius:12px;min-width:204px;min-height:204px;display:flex;align-items:center;justify-content:center;color:#333;font-size:12px}
.tfg-key{flex:1;min-width:200px}
.tfg-key code{display:block;font:15px/1.6 var(--g7-font-mono,ui-monospace,monospace);background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:8px;padding:8px 10px;user-select:all;word-break:break-all;margin:6px 0}
.tfg-open{display:none}
@media (hover:none) and (pointer:coarse){.tfg-open{display:inline-flex}}
.tfg-code{display:flex;gap:8px;margin:18px 0 8px}
.tfg-code input{width:48px;height:58px;text-align:center;font-size:24px;font-weight:700;border-radius:10px;border:1px solid var(--g7-border-3);background:var(--g7-bg-2);color:var(--g7-text);font-family:inherit;transition:border-color .15s}
.tfg-code input:focus{outline:none;border-color:var(--g7-accent);box-shadow:0 0 0 3px rgba(var(--g7-accent-rgb),.18)}
.tfg-err{color:#ef4444;font-size:13px;min-height:20px;margin:6px 0}
.tfg-codes{columns:2;font:15px/2 var(--g7-font-mono,ui-monospace,monospace);background:var(--g7-bg-2);border:1px dashed var(--g7-border-3);border-radius:12px;padding:12px 18px;margin:14px 0}
.tfg-check{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:var(--g7-text-2);margin:14px 0;cursor:pointer}
.tfg-check input{margin-top:3px;accent-color:var(--g7-accent);width:16px;height:16px}
.tfg-actions{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:22px;flex-wrap:wrap}
.tfg-actions .right{display:flex;gap:8px;margin-left:auto}
.tfg .btn{border:none;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:inherit;text-decoration:none;transition:opacity .15s,transform .15s}
.tfg .btn:disabled{opacity:.45;cursor:not-allowed}
.tfg .btn-primary{background:var(--g7-accent);color:#000}
.tfg .btn-primary:not(:disabled):hover{transform:translateY(-1px)}
.tfg .btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2)}
.tfg-warn{display:flex;gap:10px;align-items:flex-start;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);color:var(--g7-text-2);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:16px}
.tfg-warn i{color:#fbbf24;margin-top:2px}
.tfg-done{text-align:center}
.tfg-done .tfg-ico{margin:0 auto 16px;background:rgba(34,197,94,.12);color:#22c55e}
@keyframes tfgIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.tfg-step{animation:none}.tfg-steps li::before{transition:none}}
@media (max-width:520px){.tfg-card{padding:22px 16px}.tfg-code input{width:42px;height:52px;font-size:20px}.tfg-steps li span{display:none}.tfg-codes{columns:1}}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="tfg" id="tfg" data-next="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
  <ol class="tfg-steps" aria-label="Pasos">
    <li data-s="1"><span>Inicio</span></li><li data-s="2"><span>La app</span></li><li data-s="3"><span>Escanear</span></li><li data-s="4"><span>Código</span></li><li data-s="5"><span>Respaldo</span></li>
  </ol>
  <div class="tfg-card">

    <section class="tfg-step" data-step="1">
      <div class="tfg-ico"><i class="fa-solid fa-shield-halved"></i></div>
      <h1>Protejamos tu cuenta</h1>
      <?php if ($required): ?><div class="tfg-warn"><i class="fa-solid fa-circle-info"></i><span>En <?= htmlspecialchars($site) ?> la verificación en dos pasos es obligatoria. Te lleva unos 2 minutos y después sigues donde estabas.</span></div><?php endif; ?>
      <p>Con la verificación en dos pasos, para entrar no alcanza con tu contraseña: también se pide un código de 6 números que genera una app en tu teléfono y cambia cada 30 segundos.</p>
      <div class="tfg-why">
        <div><i class="fa-solid fa-lock"></i><span>Si alguien consigue tu contraseña, igual no puede entrar sin tu teléfono.</span></div>
        <div><i class="fa-solid fa-mobile-screen"></i><span>Solo necesitas una app gratuita. No hace falta señal ni internet para ver el código.</span></div>
        <div><i class="fa-solid fa-key"></i><span>Al final te damos códigos de respaldo por si algún día pierdes el teléfono.</span></div>
      </div>
      <div class="tfg-actions"><div class="right"><button class="btn btn-primary" data-go="2">Empezar <i class="fa-solid fa-arrow-right"></i></button></div></div>
    </section>

    <section class="tfg-step" data-step="2">
      <div class="tfg-ico"><i class="fa-solid fa-download"></i></div>
      <h1>Instala una app de autenticación</h1>
      <p>Descarga en tu teléfono cualquiera de estas apps (son gratis). Si ya usas una, o tu gestor de contraseñas genera códigos, puedes usar esa.</p>
      <div class="tfg-apps">
        <div class="tfg-app"><b>Google Authenticator</b><a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" rel="noopener">Android</a><a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank" rel="noopener">iPhone</a></div>
        <div class="tfg-app"><b>Microsoft Authenticator</b><a href="https://play.google.com/store/apps/details?id=com.azure.authenticator" target="_blank" rel="noopener">Android</a><a href="https://apps.apple.com/app/microsoft-authenticator/id983156458" target="_blank" rel="noopener">iPhone</a></div>
        <div class="tfg-app"><b>Authy</b><a href="https://play.google.com/store/apps/details?id=com.authy.authy" target="_blank" rel="noopener">Android</a><a href="https://apps.apple.com/app/twilio-authy/id494168017" target="_blank" rel="noopener">iPhone</a></div>
      </div>
      <p class="muted">Cuando la tengas abierta, sigue al próximo paso.</p>
      <div class="tfg-actions"><button class="btn btn-ghost" data-go="1"><i class="fa-solid fa-arrow-left"></i> Atrás</button><div class="right"><button class="btn btn-primary" data-go="3">Ya tengo la app <i class="fa-solid fa-arrow-right"></i></button></div></div>
    </section>

    <section class="tfg-step" data-step="3">
      <div class="tfg-ico"><i class="fa-solid fa-qrcode"></i></div>
      <h1>Escanea este código</h1>
      <p>En la app toca <b>+</b> (o "Agregar cuenta") y elige <b>Escanear código QR</b>. Apunta la cámara a este código.</p>
      <div class="tfg-qrwrap">
        <div class="tfg-qr" id="tfg-qr">Preparando…</div>
        <div class="tfg-key">
          <span class="muted">¿No puedes escanear? Elige "Ingresar clave" en la app y escribe esta:</span>
          <code id="tfg-secret">…</code>
          <a class="btn btn-ghost tfg-open" id="tfg-open" href="#"><i class="fa-solid fa-mobile-screen-button"></i> Abrir en la app de este teléfono</a>
        </div>
      </div>
      <div class="tfg-err" id="tfg-err3" role="alert"></div>
      <div class="tfg-actions"><button class="btn btn-ghost" data-go="2"><i class="fa-solid fa-arrow-left"></i> Atrás</button><div class="right"><button class="btn btn-primary" data-go="4" id="tfg-scanned" disabled>Ya lo escaneé <i class="fa-solid fa-arrow-right"></i></button></div></div>
    </section>

    <section class="tfg-step" data-step="4">
      <div class="tfg-ico"><i class="fa-solid fa-hashtag"></i></div>
      <h1>Escribe el código de la app</h1>
      <p>La app ahora muestra una cuenta nueva con un código de 6 números. Escríbelo acá antes de que cambie.</p>
      <div class="tfg-code" id="tfg-code" role="group" aria-label="Código de 6 números">
        <?php for ($i = 0; $i < 6; $i++): ?><input inputmode="numeric" maxlength="1" autocomplete="<?= $i ? 'off' : 'one-time-code' ?>" aria-label="Número <?= $i + 1 ?>"><?php endfor; ?>
      </div>
      <div class="tfg-err" id="tfg-err4" role="alert"></div>
      <p class="muted">Si te dice que no coincide, revisa que la hora del teléfono esté en automático y prueba con el código que se ve ahora.</p>
      <div class="tfg-actions"><button class="btn btn-ghost" data-go="3"><i class="fa-solid fa-arrow-left"></i> Atrás</button><div class="right"><button class="btn btn-primary" id="tfg-confirm" disabled>Verificar <i class="fa-solid fa-check"></i></button></div></div>
    </section>

    <section class="tfg-step" data-step="5">
      <div class="tfg-ico"><i class="fa-solid fa-life-ring"></i></div>
      <h1>Guarda tus códigos de respaldo</h1>
      <p>Si pierdes el teléfono, cada uno de estos códigos te deja entrar <b>una sola vez</b>. Guárdalos en un lugar seguro: tu gestor de contraseñas, un archivo o un papel. <b>No se vuelven a mostrar.</b></p>
      <div class="tfg-codes" id="tfg-codes"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost" id="tfg-copy"><i class="fa-regular fa-copy"></i> Copiar</button>
        <button class="btn btn-ghost" id="tfg-dl"><i class="fa-solid fa-download"></i> Descargar .txt</button>
      </div>
      <label class="tfg-check"><input type="checkbox" id="tfg-saved"> Ya guardé mis códigos de respaldo en un lugar seguro.</label>
      <div class="tfg-actions"><div class="right"><button class="btn btn-primary" id="tfg-finish" disabled>Terminar <i class="fa-solid fa-arrow-right"></i></button></div></div>
    </section>

    <section class="tfg-step tfg-done" data-step="6">
      <div class="tfg-ico"><i class="fa-solid fa-circle-check"></i></div>
      <h1>¡Listo! Tu cuenta está protegida</h1>
      <p>La próxima vez que entres te vamos a pedir el código de la app. Puedes ver o renovar tus códigos de respaldo en Mi perfil › Seguridad.</p>
      <div class="tfg-actions" style="justify-content:center"><a class="btn btn-primary" id="tfg-continue" href="<?= htmlspecialchars($next, ENT_QUOTES) ?>">Continuar <i class="fa-solid fa-arrow-right"></i></a></div>
    </section>

  </div>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script src="/toolz/assets/activar-2fa.js?v=<?= (int)@filemtime(__DIR__ . '/assets/activar-2fa.js') ?>"></script>
</body>
</html>
