<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$currentUser = requireAdmin(); // antes cualquier usuario logueado podía crear admins

$db = getDB();
$msg = '';
$error = '';
$creds = '';   // mensaje con los datos de acceso del usuario recién creado

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] === 'admin' ? 'admin' : 'user';
        if ($error = g7_super_denies((int)$currentUser['id'], 0, $role)) {
        } elseif ($error = lic_can_add('users', $db)) {   // tope de usuarios del plan
        } elseif ($username && $email && $password) {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare('INSERT INTO g7_users (username, email, password, role) VALUES (?,?,?,?)')
                   ->execute([$username, $email, $hash, $role]);
                $msg = 'Usuario <strong>' . htmlspecialchars($username) . '</strong> creado correctamente.';
                $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
                $creds = "Hola:\n\nYa tienes acceso a " . (g7_setting('platform_name') ?: g7_site('name')) . ".\n\nEntra en: " . g7_site_origin() . "/toolz/login.php\nUsuario: $username\nContraseña: $password\n\nCuando entres, cambiala en Mi perfil.";
            } catch (PDOException $e) {
                $error = 'El usuario o email ya existe.';
            }
        } else { $error = 'Completa todos los campos.'; }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id && ($error = g7_super_denies((int)$currentUser['id'], $id))) {
        } elseif ($id && g7_is_super($id)) {
            $error = 'Primero quitale la marca de superadmin.';
        } elseif ($id && $id !== (int)$currentUser['id']) {
            $db->prepare('UPDATE g7_users SET active = NOT active WHERE id = ?')->execute([$id]);
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id && ($error = g7_super_denies((int)$currentUser['id'], $id))) {
        } elseif ($id && g7_is_super($id)) {
            $error = 'Primero quitale la marca de superadmin.';
        } elseif ($id && $id !== (int)$currentUser['id']) {
            $db->prepare('DELETE FROM g7_users WHERE id = ?')->execute([$id]);
            $msg = 'Usuario eliminado.';
        }
    }

    if ($action === 'change_password') {
        $id  = (int)($_POST['user_id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if ($id && ($error = g7_super_denies((int)$currentUser['id'], $id))) {
        } elseif ($id && strlen($pwd) >= 6) {
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE g7_users SET password = ? WHERE id = ?')->execute([$hash, $id]);
            $msg = 'Contraseña actualizada.';
        } else { $error = 'La contraseña debe tener al menos 6 caracteres.'; }
    }
}

$users = $db->query('SELECT id, username, email, role, active, created_at, last_login FROM g7_users ORDER BY created_at DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Usuarios - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<script src="/toolz/assets/g7-on.js?v=<?= (int)@filemtime(__DIR__ . '/assets/g7-on.js') ?>"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--g7-bg); color: var(--g7-text); font-family: 'Inter', sans-serif; min-height: 100vh; }
.topbar { padding: 14px 24px; border-bottom: 1px solid var(--g7-border-3); display: flex; align-items: center; gap: 12px; }
.logo-ic { width: 28px; height: 28px; background: var(--g7-accent); border-radius: 6px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #000; }
.topbar h1 { font-size: 14px; font-weight: 700; flex: 1; }
.back-btn { padding: 7px 14px; background: var(--g7-bg-4); border: 1px solid var(--g7-border-3); color: var(--g7-text-2); border-radius: 7px; font-size: 11px; text-decoration: none; transition: all .15s; }
.back-btn:hover { color: var(--g7-text); border-color: var(--g7-border-3); }
.logout-btn { padding: 7px 14px; background: none; border: 1px solid var(--g7-border-3); color: var(--g7-text-3); border-radius: 7px; font-size: 11px; cursor: pointer; transition: all .15s; }
.logout-btn:hover { color: #ef4444; border-color: rgba(239,68,68,.4); }
.content { max-width: 900px; margin: 0 auto; padding: 28px 24px; }
.section-title { font-size: 13px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.card { background: var(--g7-bg-3); border: 1px solid var(--g7-border-3); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full { grid-column: 1/-1; }
label { font-size: 9px; font-weight: 700; color: var(--g7-text-3); text-transform: uppercase; letter-spacing: .08em; }
input, select { background: var(--g7-bg-4); border: 1px solid var(--g7-border-3); color: var(--g7-text); border-radius: 7px; padding: 9px 11px; font-size: 12px; outline: none; width: 100%; }
input:focus, select:focus { border-color: var(--g7-accent); }
.btn-primary { background: var(--g7-accent); color: #000; border: none; border-radius: 7px; padding: 10px 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; cursor: pointer; transition: opacity .15s; margin-top: 6px; }
.btn-primary:hover { opacity: .85; }
.alert { border-radius: 7px; padding: 10px 14px; font-size: 12px; margin-bottom: 18px; }
.alert-ok  { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.2);  color: #4ade80; }
.alert-err { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.2);  color: #f87171; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 9px; font-weight: 700; color: var(--g7-text-4); text-transform: uppercase; letter-spacing: .08em; padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--g7-border-3); }
td { padding: 10px; font-size: 12px; border-bottom: 1px solid var(--g7-border-2); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
.role-badge { font-size: 9px; font-weight: 700; border-radius: 4px; padding: 2px 6px; text-transform: uppercase; }
.role-admin { background: rgba(var(--g7-accent-rgb),.12); color: var(--g7-accent); }
.role-user  { background: var(--g7-bg-5); color: var(--g7-text-3); }
.status-on  { color: #4ade80; font-size: 10px; }
.status-off { color: var(--g7-text-4); font-size: 10px; }
.action-btn { background: none; border: 1px solid var(--g7-border-3); color: var(--g7-text-3); border-radius: 5px; padding: 4px 8px; font-size: 10px; cursor: pointer; transition: all .15s; margin-right: 4px; }
.action-btn:hover { border-color: var(--g7-border-3); color: var(--g7-text); }
.action-btn.danger:hover { border-color: rgba(239,68,68,.4); color: #ef4444; }
.me-tag { font-size: 9px; background: rgba(var(--g7-accent-rgb),.1); color: var(--g7-accent); border-radius: 4px; padding: 1px 5px; margin-left: 5px; }
/* pwd modal */
.modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 50; display: flex; align-items: center; justify-content: center; }
.modal-card { background: var(--g7-bg-3); border: 1px solid var(--g7-border-3); border-radius: 12px; padding: 24px; width: 100%; max-width: 360px; }
.modal-card h3 { font-size: 13px; font-weight: 700; margin-bottom: 16px; }
.modal-close { float: right; background: none; border: none; color: var(--g7-text-3); font-size: 16px; cursor: pointer; margin-top: -4px; }
.hidden { display: none; }
</style>
</head>
<body>

<div class="topbar">
  <div class="logo-ic">G</div>
  <h1>Gestión de usuarios</h1>
  <a href="/toolz/" class="back-btn"><i class="fa-solid fa-arrow-left" style="margin-right:5px"></i>Volver al CMS</a>
  <form method="POST" action="/toolz/logout.php" style="margin:0">
    <button class="logout-btn" type="submit"><i class="fa-solid fa-right-from-bracket" style="margin-right:5px"></i>Salir</button>
  </form>
</div>

<div class="content">

  <?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($creds): ?>
  <div class="card" style="margin-bottom:20px">
    <div style="font-weight:700;margin-bottom:4px"><i class="fa-solid fa-paper-plane" style="color:var(--g7-accent);margin-right:6px"></i>Datos de acceso para mandarle</div>
    <div style="font-size:12px;color:var(--g7-text-4);margin-bottom:10px">Cópialo ahora: la contraseña no se vuelve a mostrar.</div>
    <textarea id="u-creds" readonly rows="8" style="width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:10px;font:13px/1.55 inherit;resize:vertical"><?= htmlspecialchars($creds) ?></textarea>
    <button class="btn-primary" type="button" style="margin-top:10px" data-on-click="uCopyCreds" data-click-1="this"><i class="fa-regular fa-copy" style="margin-right:6px"></i>Copiar mensaje</button>
  </div>
  <?php endif; ?>

  <!-- CREATE USER -->
  <div class="section-title"><i class="fa-solid fa-user-plus" style="color:var(--g7-accent)"></i> Nuevo usuario</div>
  <div class="card">
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="form-grid">
        <div class="form-group">
          <label>Usuario</label>
          <input type="text" name="username" required>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" required>
        </div>
        <div class="form-group">
          <label>Contraseña</label>
          <div style="display:flex;gap:6px"><input type="password" name="password" id="u-pw" minlength="6" required style="flex:1">
            <button type="button" class="btn-primary" style="white-space:nowrap" data-on-click="uGen" title="Genera una contraseña segura y la muestra"><i class="fa-solid fa-wand-magic-sparkles" style="margin-right:5px"></i>Generar</button></div>
        </div>
        <div class="form-group">
          <label>Rol</label>
          <select name="role">
            <option value="user">Usuario</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <button class="btn-primary" type="submit"><i class="fa-solid fa-plus" style="margin-right:6px"></i>Crear usuario</button>
    </form>
  </div>

  <!-- USER LIST -->
  <div class="section-title"><i class="fa-solid fa-users" style="color:var(--g7-accent)"></i> Usuarios (<?= count($users) ?>)</div>
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead>
        <tr>
          <th>Usuario</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Estado</th>
          <th>Último login</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): $isMe = $u['id'] === $currentUser['id']; ?>
        <tr>
          <td>
            <?= htmlspecialchars($u['username']) ?>
            <?php if ($isMe): ?><span class="me-tag">tú</span><?php endif; ?>
          </td>
          <td style="color:var(--g7-text-3)"><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="role-badge <?= $u['role']==='admin'?'role-admin':'role-user' ?>"><?= $u['role'] ?></span></td>
          <td>
            <?php if ($u['active']): ?>
              <span class="status-on"><i class="fa-solid fa-circle" style="font-size:7px;margin-right:4px"></i>Activo</span>
            <?php else: ?>
              <span class="status-off"><i class="fa-solid fa-circle" style="font-size:7px;margin-right:4px"></i>Inactivo</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--g7-text-3)"><?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : '---' ?></td>
          <td>
            <?php if (!$isMe): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="action-btn" type="submit" title="<?= $u['active']?'Desactivar':'Activar' ?>">
                  <i class="fa-solid <?= $u['active']?'fa-ban':'fa-check' ?>"></i>
                </button>
              </form>
            <?php endif; ?>
            <button class="action-btn" data-on-click="openPwd" data-click-1="n:<?= (int)$u['id'] ?>" data-click-2="j:<?= htmlspecialchars(json_encode((string)$u['username']), ENT_QUOTES) ?>" title="Cambiar contraseña">
              <i class="fa-solid fa-key"></i>
            </button>
            <?php if (!$isMe): ?>
              <form method="POST" style="display:inline" data-on-submit="uConfirmDel" data-submit-1="s:<?= htmlspecialchars($u['username']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="action-btn danger" type="submit" title="Eliminar">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-bg hidden" id="pwd-modal">
  <div class="modal-card">
    <h3><button class="modal-close" data-on-click="closePwd">✕</button>Cambiar contraseña</h3>
    <p style="font-size:12px;color:var(--g7-text-3);margin-bottom:14px">Usuario: <span id="pwd-username" style="color:var(--g7-text);font-weight:600"></span></p>
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="user_id" id="pwd-user-id">
      <div class="form-group" style="margin-bottom:12px">
        <label>Nueva contraseña</label>
        <input type="password" name="new_password" minlength="6" required>
      </div>
      <button class="btn-primary" type="submit" style="width:100%">Guardar contraseña</button>
    </form>
  </div>
</div>

<script src="/toolz/assets/users-page.js?v=<?= (int)@filemtime(__DIR__ . '/assets/users-page.js') ?>"></script>
</body>
</html>
