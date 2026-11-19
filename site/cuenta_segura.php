<?php
/**
 * cuenta_segura.php — A dónde llevan los botones de los emails de seguridad (enlaces firmados de un solo uso, login_alert_lib.php).
 * No hace falta haber entrado: el enlace es la llave.
 *   review  (aviso de ingreso nuevo, 7 días): "Fui yo" o "No fui yo" (cierra esa sesión y borra los dispositivos de confianza)
 *   lock    (aviso de intentos fallidos o después de "No fui yo"): bloquear la cuenta
 *   unlock  (email de cuenta bloqueada, 24 h): elegir contraseña nueva y desbloquearla
 * Abrir el enlace no hace nada solo: la acción pide tocar un botón (POST), así los programas que revisan los enlaces de los
 * emails no la disparan. Doc: toolz/SEGURIDAD_LOGIN.md
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/login_alert_lib.php';
require_once __DIR__ . '/pass_lib.php';
if (!headers_sent()) {
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');   // el token va en la dirección: que no viaje a ningún otro sitio
    header('X-Robots-Tag: noindex');
    if (!defined('G7_CSP_ENFORCED')) define('G7_CSP_ENFORCED', 1);
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
}
$db = getDB(); g7_ld_install($db);
$raw = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$tok = g7_tok_find($db, $raw);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$site = (string)(g7_setting('platform_name') ?: g7_site('name'));
$acc = preg_match('/^#[0-9a-fA-F]{6}$/', (string)g7_setting('accent_color')) ? g7_setting('accent_color') : '#ff6600';
$user = null;
if ($tok) { $q = $db->prepare('SELECT * FROM g7_users WHERE id = ?'); $q->execute([(int)$tok['user_id']]); $user = $q->fetch(PDO::FETCH_ASSOC) ?: null; if (!$user) $tok = null; }

$view = 'invalid'; $msg = ''; $err = ''; $row = null; $next = '';
if ($tok) {
    $uid = (int)$tok['user_id']; $p = $tok['purpose']; $act = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)($_POST['act'] ?? '') : '';
    if ($p === 'review') {
        $q = $db->prepare('SELECT h.*, d.name dname FROM g7_login_history h LEFT JOIN g7_login_devices d ON d.user_id = h.user_id AND d.fp = h.fp WHERE h.id = ? AND h.user_id = ?');
        $q->execute([(int)$tok['ref_id'], $uid]); $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $view = $row ? 'review' : 'invalid';
        if ($row && $act === 'me' && g7_tok_use($db, (int)$tok['id'])) {
            $db->prepare("UPDATE g7_login_history SET confirmed = 'me' WHERE id = ?")->execute([(int)$row['id']]);
            $view = 'done'; $msg = 'Listo, gracias. Ese ingreso quedó marcado como tuyo.';
        } elseif ($row && $act === 'notme' && g7_tok_use($db, (int)$tok['id'])) {
            $db->prepare("UPDATE g7_login_history SET confirmed = 'not_me' WHERE id = ?")->execute([(int)$row['id']]);
            $q = $db->prepare("UPDATE g7_login_history SET ended_at = NOW(), ended_by = 'not_me' WHERE id = ? AND ended_at IS NULL"); $q->execute([(int)$row['id']]);
            $closed = $q->rowCount() > 0;
            g7_td_revoke($db, $uid);
            g7_audit('auth.not_me', 'user:' . $uid, ['id' => (int)$row['id'], 'device' => $row['label'], 'ip' => $row['ip'], 'via' => 'email'], $uid);
            $view = 'notme'; $msg = $closed ? 'Cerramos esa sesión: quien entró ya no puede seguir usando tu cuenta desde ahí.' : 'Esa sesión ya estaba cerrada.';
            $next = g7_tok_new($db, $uid, 'lock', 0, 1);   // para "Bloquear mi cuenta" en la misma pantalla (1 hora)
        }
    } elseif ($p === 'lock') {
        $view = 'lock';
        if ($act === 'lock' && g7_tok_use($db, (int)$tok['id'])) { g7_acct_lock($db, $uid, $uid, 'email'); $view = 'locked'; }
    } elseif ($p === 'unlock') {
        $view = g7_acct_locked($db, $uid) ? 'unlock' : 'invalid';
        if ($view === 'unlock' && $act === 'unlock') {
            $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
            $err = g7_pass_check_new($new, $conf, (string)$user['password']) ?? '';
            if ($err === '' && g7_tok_use($db, (int)$tok['id'])) {
                g7_pass_install($db);
                $db->prepare('UPDATE g7_users SET password = ?, pass_changed_at = NOW() WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
                g7_acct_unlock($db, $uid, $uid);
                g7_audit('auth.pass_change', 'desbloqueo', null, $uid);
                $view = 'done'; $msg = 'Tu cuenta quedó desbloqueada con la contraseña nueva. Ya puedes entrar.';
            }
        }
    }
}
$who = $user ? (string)(($user['display_name'] ?? '') ?: $user['username']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Seguridad de tu cuenta · <?= $h($site) ?></title>
<style>
:root{--acc:<?= $acc ?>}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px 16px;background:#0a0a0b;color:#e7e7ea;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:440px;background:#131316;border:1px solid #26262b;border-radius:16px;padding:26px}
.kick{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--acc);font-weight:700;margin-bottom:10px}
h1{font-size:20px;line-height:1.3;margin-bottom:8px}
p{font-size:14px;line-height:1.6;color:#b4b4bb;margin-bottom:12px}
.box{background:#1b1b1f;border:1px solid #2a2a30;border-radius:11px;padding:12px 14px;font-size:13.5px;line-height:1.7;margin:4px 0 16px;color:#e7e7ea}
.box small{color:#8b8b93}
.row{display:flex;gap:10px;flex-wrap:wrap}
form{display:contents}
button,.btn{flex:1;min-width:140px;border:0;border-radius:10px;padding:12px 16px;font:700 14px inherit;font-family:inherit;cursor:pointer;text-align:center;text-decoration:none;display:inline-block}
.pri{background:var(--acc);color:#fff}.dan{background:#dc2626;color:#fff}.gho{background:transparent;color:#e7e7ea;border:1px solid #33333a}
button:active,.btn:active{transform:translateY(1px)}
button:focus-visible,.btn:focus-visible,input:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
label{display:block;font-size:12.5px;color:#b4b4bb;margin:10px 0 6px}
input{width:100%;background:#0f0f12;border:1px solid #2e2e35;color:#e7e7ea;border-radius:10px;padding:11px 12px;font:inherit;font-size:14px}
.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:12px}
.ok{color:#86efac}
.foot{margin-top:16px;font-size:12px;color:#6b6b73;text-align:center}
.foot a{color:#9a9aa3}
</style>
</head>
<body>
<main class="card">
  <div class="kick"><?= $h($site) ?> · Seguridad</div>
<?php if ($view === 'invalid'): ?>
  <h1>Este enlace ya no sirve</h1>
  <p>Ya se usó, venció o no es correcto. Si quieres revisar tu cuenta, entra y ve a Mi perfil › Dónde iniciaste sesión.</p>
  <div class="row"><a class="btn pri" href="/toolz/login.php">Ir a entrar</a></div>

<?php elseif ($view === 'review'): $place = (string)$row['place']; ?>
  <h1>¿Fuiste vos, <?= $h($who) ?>?</h1>
  <p>Este es el ingreso del aviso que te mandamos:</p>
  <div class="box"><b><?= $h($row['dname'] ?: $row['label']) ?></b><?= $row['dname'] ? ' <small>(' . $h($row['label']) . ')</small>' : '' ?><br>
    <?= $place !== '' ? $h($place) . ($place !== 'Red local' ? ' <small>(aproximado)</small>' : '') . '<br>' : '' ?>IP <?= $h($row['ip']) ?><br><?= $h(date('d/m/Y H:i', strtotime((string)$row['created_at']))) ?></div>
  <?php if ($row['confirmed'] === 'not_me'): ?><p>Ya marcaste este ingreso como "No fui yo".</p><?php endif; ?>
  <div class="row">
    <form method="post"><input type="hidden" name="t" value="<?= $h($raw) ?>"><input type="hidden" name="act" value="me"><button class="<?= ($_GET['a'] ?? '') === 'me' ? 'pri' : 'gho' ?>" type="submit">Sí, fui yo</button></form>
    <form method="post"><input type="hidden" name="t" value="<?= $h($raw) ?>"><input type="hidden" name="act" value="notme"><button class="dan" type="submit">No fui yo</button></form>
  </div>
  <p style="margin:14px 0 0;font-size:12.5px">Con "No fui yo" cerramos esa sesión al momento y te pedimos el código de verificación de nuevo en todos tus dispositivos.</p>

<?php elseif ($view === 'notme'): ?>
  <h1 class="ok"><?= $h($msg) ?></h1>
  <p>Ahora, para que no pueda volver a entrar:</p>
  <p>1. <b>Cambia tu contraseña</b>: entra y ve a Mi perfil (al cambiarla se cierran todas las sesiones de otros dispositivos).<br>2. Activa la verificación en dos pasos.</p>
  <p>Si no puedes entrar o crees que alguien tiene tu contraseña, <b>bloquea tu cuenta</b>: se cierran todas las sesiones y nadie entra hasta que elijas una contraseña nueva desde tu email.</p>
  <div class="row">
    <a class="btn pri" href="/toolz/login.php">Entrar y cambiar la contraseña</a>
    <form method="post"><input type="hidden" name="t" value="<?= $h($next) ?>"><input type="hidden" name="act" value="lock"><button class="dan" type="submit">Bloquear mi cuenta</button></form>
  </div>

<?php elseif ($view === 'lock'): ?>
  <h1>¿Bloquear tu cuenta, <?= $h($who) ?>?</h1>
  <p>Vamos a cerrar todas las sesiones abiertas (también la tuya) y nadie va a poder entrar, ni con la contraseña correcta.</p>
  <p>Te mandamos un email para desbloquearla eligiendo una contraseña nueva. Si no te llega, un administrador puede liberarla.</p>
  <div class="row">
    <form method="post"><input type="hidden" name="t" value="<?= $h($raw) ?>"><input type="hidden" name="act" value="lock"><button class="dan" type="submit">Sí, bloquear mi cuenta</button></form>
    <a class="btn gho" href="/toolz/login.php">No, volver</a>
  </div>

<?php elseif ($view === 'locked'): ?>
  <h1 class="ok">Tu cuenta quedó bloqueada</h1>
  <p>Cerramos todas las sesiones y nadie puede entrar. Te mandamos un email con el enlace para elegir una contraseña nueva y desbloquearla (vale 24 horas).</p>

<?php elseif ($view === 'unlock'): ?>
  <h1>Desbloquear tu cuenta</h1>
  <p>Hola <?= $h($who) ?>, elige una contraseña nueva. Con eso la cuenta queda desbloqueada.</p>
  <?php if ($err): ?><div class="err" role="alert"><?= $h($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="t" value="<?= $h($raw) ?>"><input type="hidden" name="act" value="unlock">
    <label for="n">Contraseña nueva (al menos <?= g7_pass_min() ?> caracteres)</label><input id="n" type="password" name="new" autocomplete="new-password" required minlength="<?= g7_pass_min() ?>">
    <label for="c">Repítela</label><input id="c" type="password" name="confirm" autocomplete="new-password" required>
    <div class="row" style="margin-top:16px"><button class="pri" type="submit">Desbloquear</button></div>
  </form>

<?php else: ?>
  <h1 class="ok"><?= $h($msg) ?></h1>
  <div class="row" style="margin-top:6px"><a class="btn pri" href="/toolz/login.php">Ir a entrar</a></div>
<?php endif; ?>
  <div class="foot">¿Dudas? Habla con un administrador de <?= $h($site) ?>.</div>
</main>
</body>
</html>
