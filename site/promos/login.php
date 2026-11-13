<?php
require_once 'auth.php';
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)

// Already logged in?
$user = getCurrentUser();
if ($user) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if ($u && $p) {
        $result = login($u, $p);
        if (is_array($result) && !empty($result['need_2fa'])) { header('Location: /toolz/login.php?code=1'); exit; }   // el código se pide en el login principal
        if ($result) { header('Location: index.php'); exit; }
        else $error = 'Usuario o contraseña incorrectos.';
    } else {
        $error = 'Completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - <?= htmlspecialchars(g7_site('short')) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #080808; color: #e4e4e7; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
.card { background: #111; border: 1px solid #27272a; border-radius: 16px; padding: 36px 32px; width: 100%; max-width: 380px; }
.logo { width: 36px; height: 36px; background: #ff6600; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; color: #000; margin: 0 auto 20px; }
h1 { font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 6px; }
.sub { font-size: 12px; color: #71717a; text-align: center; margin-bottom: 28px; }
.group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
label { font-size: 10px; font-weight: 700; color: #71717a; text-transform: uppercase; letter-spacing: .08em; }
input { background: #18181b; border: 1px solid #27272a; color: #e4e4e7; border-radius: 8px; padding: 10px 12px; font-size: 13px; outline: none; width: 100%; }
input:focus { border-color: #ff6600; }
.btn { width: 100%; background: #ff6600; color: #000; border: none; border-radius: 8px; padding: 11px; font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; cursor: pointer; margin-top: 6px; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.error { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.2); color: #f87171; border-radius: 7px; padding: 10px 12px; font-size: 12px; margin-bottom: 16px; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">G</div>
  <h1><?= htmlspecialchars((string)g7_site('short')) ?></h1>
  <p class="sub">Content Manager — ingresa para continuar</p>
  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <div class="group">
      <label>Usuario o email</label>
      <input type="text" name="username" autocomplete="username" required>
    </div>
    <div class="group">
      <label>Contraseña</label>
      <input type="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn" type="submit">Ingresar</button>
  </form>
</div>
</body>
</html>
