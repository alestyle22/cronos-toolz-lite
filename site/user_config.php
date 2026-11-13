<?php
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$isAdmin     = ($currentUser['role'] === 'admin');
$db          = getDB();
$me          = $currentUser['id'];

// ── SAVE PREFS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // Foto de perfil (antes se mandaba a users.php, que no tiene esta acción: el cambio nunca se guardaba)
    if (($_POST['action'] ?? '') === 'update_avatar') {
        $f = $_FILES['avatar_file'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) { echo json_encode(['error' => 'No llegó ninguna imagen']); exit; }
        if ($why = g7_upload_problem($f)) { echo json_encode(['error' => $why]); exit; }
        $ext = g7_upload_ext_or_die($f['name'], G7_EXT_IMG);
        $nm  = 'avatar_' . $me . '_' . time() . '.' . $ext;
        if ($why = g7_upload_store($f, __DIR__ . '/avatars/', $nm)) { echo json_encode(['error' => $why]); exit; }
        $url = '/toolz/avatars/' . $nm;
        $db->prepare('UPDATE g7_users SET avatar = ? WHERE id = ?')->execute([$url, $me]);
        echo json_encode(['status' => 'success', 'url' => $url]);
        exit;
    }
    $in  = json_decode(file_get_contents('php://input'), true) ?? [];
    // Ubicación y zona horaria (tz_lib.php): país, zona IANA, ciudad y si la ciudad se ve en el perfil
    if (($in['action'] ?? '') === 'save_location') {
        $err = g7_tz_save((int)$me, (string)($in['country'] ?? ''), (string)($in['timezone'] ?? ''), (string)($in['city'] ?? ''), !empty($in['city_public']));
        echo json_encode($err ? ['error' => $err] : ['ok' => true, 'tz' => g7_tz_user((int)$me)]);
        exit;
    }
    $key = trim($in['key'] ?? '');
    $val = $in['value'] ?? null;

    if (!$key || $val === null) { echo json_encode(['error'=>'invalid']); exit; }

    // Allowed keys
    $allowed = [
        'theme','accent_color','font_size','sidebar_compact',
        'notif_sound','notif_zumbido','notif_badges','notif_desktop',
        'chat_bubble_size','chat_show_avatars','chat_enter_send','chat_compact',
        'privacy_online','privacy_last_seen','privacy_read_receipts',
        'dashboard_density','dashboard_animations','home_layout',
    ];
    if (!in_array($key, $allowed)) { echo json_encode(['error'=>'key not allowed']); exit; }

    $db->prepare('INSERT INTO g7_user_prefs (user_id,pref_key,pref_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pref_value=?')
       ->execute([$me, $key, json_encode($val), json_encode($val)]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── LOAD PREFS ────────────────────────────────────────────
$rows = $db->prepare('SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=?');
$rows->execute([$me]);
$prefs = [];
foreach ($rows->fetchAll() as $r) {
    $prefs[$r['pref_key']] = json_decode($r['pref_value'], true);
}

function pref($key, $default, $prefs) {
    return array_key_exists($key, $prefs) ? $prefs[$key] : $default;
}

// Load avatar info
$meData = $db->prepare('SELECT display_name,avatar,email FROM g7_users WHERE id=?');
$meData->execute([$me]);
$meData = $meData->fetch();

// Ubicación y zona horaria: lo guardado y los países con sus zonas (para el selector)
g7_tz_install($db);
$tzMine = g7_tz_row((int)$me);
$tzData = ['countries' => g7_tz_countries(), 'names' => [], 'site' => g7_tz_site(), 'mine' => $tzMine];
foreach ($tzData['countries'] as $ids) foreach ($ids as $id) $tzData['names'][$id] = g7_tz_name($id);
?>
<!DOCTYPE html>
<?php $uc_theme=pref("theme","dark",$prefs);$uc_font=pref("font_size","medium",$prefs); ?><html lang="es" class="theme-<?= htmlspecialchars($uc_theme) ?> font-<?= htmlspecialchars($uc_font) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Mi configuración - <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:'Inter',sans-serif;min-height:100vh}

.cfg-layout{display:grid;grid-template-columns:220px 1fr;min-height:calc(100vh - 52px)}

/* SIDEBAR NAV */
.cfg-nav{background:var(--g7-bg-2);border-right:1px solid var(--g7-border-2);padding:16px 10px;position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto}
.cfg-nav-item{display:flex;align-items:center;gap:9px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;color:var(--g7-text-3);transition:all .15s;text-decoration:none;border:none;background:none;width:100%;text-align:left}
.cfg-nav-item:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.cfg-nav-item.active{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);border:1px solid rgba(var(--g7-accent-rgb),.2)}
.cfg-nav-item i{width:16px;text-align:center;font-size:13px}
.cfg-nav-sep{font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.08em;padding:12px 12px 4px;margin-top:4px}

/* CONTENT */
.cfg-content{padding:28px 32px;max-width:720px}
.cfg-section{display:none}
.cfg-section.active{display:block}
.cfg-section-title{font-size:20px;font-weight:800;margin-bottom:4px}
.cfg-section-sub{font-size:12px;color:var(--g7-text-4);margin-bottom:24px}

/* CARDS */
.cfg-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:14px}
.cfg-card-head{padding:14px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:8px}
.cfg-card-head i{color:var(--g7-accent);font-size:13px;width:16px;text-align:center}
.cfg-card-head span{font-size:12px;font-weight:700;color:var(--g7-text-2);text-transform:uppercase;letter-spacing:.06em}
.cfg-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 18px;border-bottom:1px solid var(--g7-border-2)}
.cfg-row:last-child{border-bottom:none}
.cfg-row-info{flex:1}
.cfg-row-label{font-size:13px;font-weight:600;margin-bottom:2px}
.cfg-row-desc{font-size:11px;color:var(--g7-text-4);line-height:1.4}

/* TOGGLE */
.cfg-toggle{position:relative;width:42px;height:24px;flex-shrink:0;cursor:pointer}
.cfg-toggle input{opacity:0;width:0;height:0;position:absolute}
.cfg-toggle-track{position:absolute;inset:0;background:var(--g7-bg-5);border-radius:12px;transition:background .2s}
.cfg-toggle input:checked + .cfg-toggle-track{background:var(--g7-accent)}
.cfg-toggle-thumb{position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s;pointer-events:none}
.cfg-toggle input:checked ~ .cfg-toggle-thumb{transform:translateX(18px)}

/* SELECT */
.cfg-select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:6px 10px;font-size:12px;outline:none;cursor:pointer;transition:border-color .15s;font-family:inherit}
.cfg-select:focus{border-color:var(--g7-accent)}

/* COLOR PICKER */
.cfg-color{display:flex;align-items:center;gap:8px}
.cfg-color input[type=color]{width:32px;height:28px;border:none;border-radius:6px;cursor:pointer;padding:2px;background:var(--g7-bg-4)}
.cfg-color-hex{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:5px 9px;font-size:12px;outline:none;width:90px}
.cfg-color-hex:focus{border-color:var(--g7-accent)}

/* COLOR SWATCHES */
.cfg-swatches{display:flex;gap:6px;flex-wrap:wrap}
.cfg-swatch{width:26px;height:26px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:all .15s}
.cfg-swatch.active{border-color:#fff;transform:scale(1.1)}
.cfg-swatch:hover{transform:scale(1.08)}

/* AVATAR */
.cfg-avatar-wrap{display:flex;align-items:center;gap:16px}
.cfg-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--g7-border-3);flex-shrink:0}
.cfg-avatar-placeholder{width:64px;height:64px;border-radius:50%;background:rgba(var(--g7-accent-rgb),.15);border:2px solid rgba(var(--g7-accent-rgb),.3);display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0}
.btn{border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.btn-primary{background:var(--g7-accent);color:#000}.btn-primary:hover{opacity:.85}
.btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-2)}.btn-ghost:hover{color:var(--g7-text)}
.btn-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444}

/* PREVIEW BAR */
.cfg-theme{display:flex;flex-direction:column;gap:6px;align-items:stretch;background:none;border:2px solid transparent;border-radius:12px;padding:4px;cursor:pointer;transition:border-color .15s,transform .15s;font-family:inherit}
.cfg-theme:hover{transform:translateY(-1px)}
.cfg-theme.active{border-color:var(--t-acc)}
.cfg-theme-prev{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:6px 18px;gap:4px;width:86px;height:52px;padding:7px;border-radius:9px;background:var(--t-bg);border:1px solid rgba(var(--g7-ov),.1)}
.cfg-theme-prev i{grid-column:1/3;border-radius:3px;background:var(--t-acc)}
.cfg-theme-prev b{border-radius:4px;background:var(--t-card)}
.cfg-theme-name{font-size:11px;font-weight:600;color:var(--g7-text-2);text-align:center}
.cfg-theme.active .cfg-theme-name{color:var(--g7-text)}
#cfg-accent-preview{height:3px;border-radius:2px;background:var(--g7-accent);margin-bottom:20px;transition:background .2s}

/* TOAST */
#cfg-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(60px);background:#22c55e;color:#000;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;transition:transform .3s;z-index:9999;pointer-events:none}

@media(max-width:700px){.cfg-layout{grid-template-columns:1fr}.cfg-nav{display:none}}

/* UBICACIÓN Y ZONA HORARIA */
.g7-flag{border-radius:2px;box-shadow:0 0 0 1px rgba(127,127,127,.25);vertical-align:-1px}
.tz-card{overflow:visible}
.cfg-row[hidden],.tz-pop[hidden]{display:none}
.tz-combo{position:relative}
.tz-combo-btn{display:inline-flex;align-items:center;gap:8px;min-width:220px;justify-content:space-between;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 11px;font-size:12px;font-family:inherit;cursor:pointer;transition:border-color .15s}
.tz-combo-btn:hover,.tz-combo-btn[aria-expanded=true]{border-color:var(--g7-accent)}
.tz-combo-btn>span{display:inline-flex;align-items:center;gap:8px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tz-combo-btn>i{font-size:10px;color:var(--g7-text-4)}
.tz-pop{position:absolute;right:0;top:calc(100% + 6px);z-index:50;width:280px;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.35);padding:8px;animation:tzIn .14s ease-out}
.tz-search{width:100%;margin-bottom:6px;cursor:text}
.tz-list{list-style:none;max-height:260px;overflow-y:auto}
.tz-list li{display:flex;align-items:center;gap:9px;padding:7px 9px;border-radius:7px;font-size:12px;cursor:pointer;color:var(--g7-text-2)}
.tz-list li small{margin-left:auto;font-size:10px;color:var(--g7-text-5)}
.tz-list li:hover,.tz-list li.on{background:var(--g7-bg-4);color:var(--g7-text)}
.tz-list li[aria-selected=true]{color:var(--g7-accent)}
.tz-list .tz-empty{cursor:default;color:var(--g7-text-4)}
.tz-opt{font-size:10px;font-weight:500;color:var(--g7-text-5);margin-left:4px}
.tz-auto{font-size:12px;color:var(--g7-text-3);display:inline-flex;align-items:center;gap:6px}
.tz-auto i{color:#22c55e}
.tz-preview{background:linear-gradient(135deg,rgba(var(--g7-accent-rgb),.08),transparent 70%),var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:18px 20px;margin-bottom:10px}
.tz-preview-main{display:flex;align-items:center;gap:12px}
.tz-preview-flag .fi{font-size:22px}
.tz-preview-time{font-size:34px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.tz-preview-sub{font-size:12px;color:var(--g7-text-4);margin-top:4px}
.tz-device{font-size:12px;color:var(--g7-text-3);background:var(--g7-bg-3);border:1px dashed var(--g7-border-3);border-radius:10px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.tz-device[hidden]{display:none}
.tz-device button{margin-left:auto}
.tz-actions{display:flex;gap:8px;justify-content:flex-end;margin-bottom:12px;flex-wrap:wrap}
.tz-note{font-size:11px;color:var(--g7-text-4);line-height:1.5}
.tz-note i{color:var(--g7-accent);margin-right:4px}
@keyframes tzIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){.tz-pop{animation:none}}
@media(max-width:700px){.cfg-content{padding:20px 16px}.cfg-row{flex-wrap:wrap}.tz-card .cfg-row-info{flex-basis:100%}.tz-card .cfg-toggle{margin-left:auto}.tz-combo,.tz-combo-btn,#tz-city,#tz-zone-wrap,#tz-zone-wrap select{width:100%}.tz-pop{left:0;right:auto;width:100%}.tz-preview-time{font-size:28px}}
</style>
<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<div class="cfg-layout">
  <!-- SIDEBAR NAV -->
  <nav class="cfg-nav">
    <div class="cfg-nav-sep">Cuenta</div>
    <button class="cfg-nav-item active" data-on-click="cfgShow" data-click-1="s:perfil" data-click-2="this">
      <i class="fa-solid fa-user"></i> Mi perfil
    </button>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:ubicacion" data-click-2="this">
      <i class="fa-solid fa-earth-americas"></i> Ubicación y zona horaria
    </button>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:seguridad" data-click-2="this">
      <i class="fa-solid fa-shield-halved"></i> Seguridad
    </button>
<?php $uc_gcal = is_file(__DIR__ . '/integraciones/gcal.php') && (is_file(__DIR__ . '/_private/integrations.php') || is_file(__DIR__ . '/_private/integrations.json')) && (require_once __DIR__ . '/integraciones/gcal.php') && int_ready('google_calendar'); ?>
<?php if ($uc_gcal): ?>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:google" data-click-2="this">
      <i class="fa-brands fa-google"></i> Google Calendar
    </button>
<?php endif; ?>

    <div class="cfg-nav-sep">Personalización</div>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:apariencia" data-click-2="this">
      <i class="fa-solid fa-palette"></i> Apariencia
    </button>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:chat" data-click-2="this">
      <i class="fa-solid fa-comment"></i> Chat
    </button>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:notificaciones" data-click-2="this">
      <i class="fa-solid fa-bell"></i> Notificaciones
    </button>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:privacidad" data-click-2="this">
      <i class="fa-solid fa-lock"></i> Privacidad
    </button>

    <div class="cfg-nav-sep">Sistema</div>
    <button class="cfg-nav-item" data-on-click="cfgShow" data-click-1="s:dashboard" data-click-2="this">
      <i class="fa-solid fa-gauge"></i> Dashboard
    </button>
  </nav>

  <!-- CONTENT -->
  <div class="cfg-content">
    <div id="cfg-accent-preview"></div>

    <!-- ── PERFIL ── -->
    <section class="cfg-section active" id="sec-perfil">
      <div class="cfg-section-title">Mi perfil</div>
      <div class="cfg-section-sub">Tu información personal visible para el equipo</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-image-portrait"></i><span>Avatar y nombre</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Foto de perfil</div>
            <div class="cfg-row-desc">Visible en el chat y la topbar</div>
          </div>
          <div class="cfg-avatar-wrap">
            <?php if($meData['avatar']): ?>
            <img src="<?= htmlspecialchars($meData['avatar']) ?>" class="cfg-avatar" id="cfg-avatar-img">
            <?php else: ?>
            <div class="cfg-avatar-placeholder" id="cfg-avatar-img"><?= strtoupper(substr($meData['display_name']?:$currentUser['username'],0,1)) ?></div>
            <?php endif; ?>
            <label class="btn btn-ghost" style="cursor:pointer">
              <i class="fa-solid fa-upload"></i> Subir
              <input type="file" accept="image/*" style="display:none" data-on-change="cfgUploadAvatar" data-change-1="this">
            </label>
          </div>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Nombre para mostrar</div>
            <div class="cfg-row-desc">Cómo te ven los demás en el chat</div>
          </div>
          <div style="display:flex;gap:7px">
            <input class="cfg-select" id="cfg-display-name" value="<?= htmlspecialchars($meData['display_name']?:$currentUser['username']) ?>" style="width:160px" placeholder="Tu nombre">
            <button class="btn btn-primary" data-on-click="cfgSaveName"><i class="fa-solid fa-check"></i></button>
          </div>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Contraseña</div>
            <div class="cfg-row-desc">Cambia tu contraseña de acceso</div>
          </div>
          <button class="btn btn-ghost" data-on-click="cfgShowPasswordModal"><i class="fa-solid fa-key"></i> Cambiar</button>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-circle-info"></i><span>Información de cuenta</span></div>
        <div class="cfg-row">
          <div class="cfg-row-label">Usuario</div>
          <span style="font-size:12px;color:var(--g7-text-3)">@<?= htmlspecialchars($currentUser['username']) ?></span>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-label">Email</div>
          <span style="font-size:12px;color:var(--g7-text-3)"><?= htmlspecialchars($meData['email']??'—') ?></span>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-label">Rol</div>
          <span style="font-size:12px;padding:2px 9px;border-radius:5px;<?= $isAdmin ? 'background:rgba(255,102,0,.1);color:#ff6600' : 'background:#18181b;color:#71717a' ?>"><?= $isAdmin ? 'Administrador' : 'Miembro' ?></span>
        </div>
      </div>
    </section>

    <!-- ── UBICACIÓN Y ZONA HORARIA (tz_lib.php, assets/user-tz.js) ── -->
    <section class="cfg-section" id="sec-ubicacion">
      <div class="cfg-section-title">Ubicación y zona horaria</div>
      <div class="cfg-section-sub">Con tu zona, todas las fechas y horas se te muestran en tu hora local, y el equipo ve qué hora es donde estás.</div>

      <div class="cfg-card tz-card">
        <div class="cfg-card-head"><i class="fa-solid fa-earth-americas"></i><span>Dónde estás</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">País</div>
            <div class="cfg-row-desc">Busca por nombre o código</div>
          </div>
          <div class="tz-combo" id="tz-combo">
            <button type="button" class="tz-combo-btn" id="tz-country-btn" aria-haspopup="listbox" aria-expanded="false"><span id="tz-country-label">Elige tu país</span><i class="fa-solid fa-chevron-down"></i></button>
            <div class="tz-pop" id="tz-pop" hidden>
              <input type="search" class="cfg-select tz-search" id="tz-search" placeholder="Buscar país…" autocomplete="off" aria-label="Buscar país">
              <ul class="tz-list" id="tz-list" role="listbox" aria-label="Países"></ul>
            </div>
          </div>
        </div>
        <div class="cfg-row" id="tz-zone-row" hidden>
          <div class="cfg-row-info">
            <div class="cfg-row-label">Zona horaria</div>
            <div class="cfg-row-desc" id="tz-zone-desc">Ese país tiene varias: elige la tuya</div>
          </div>
          <div id="tz-zone-wrap"></div>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Ciudad <span class="tz-opt">opcional</span></div>
            <div class="cfg-row-desc">Solo se ve en tu perfil si lo activas abajo</div>
          </div>
          <input class="cfg-select" id="tz-city" maxlength="100" style="width:200px" placeholder="Ej: Rosario">
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Mostrar mi ciudad en mi perfil</div>
            <div class="cfg-row-desc">El país, la zona y tu hora local se ven siempre en el chat y en tu perfil</div>
          </div>
          <label class="cfg-toggle"><input type="checkbox" id="tz-city-public"><span class="cfg-toggle-track"></span><span class="cfg-toggle-thumb"></span></label>
        </div>
      </div>

      <div class="tz-preview" id="tz-preview" aria-live="polite">
        <div class="tz-preview-main"><span class="tz-preview-flag" id="tz-prev-flag"></span><span class="tz-preview-time" id="tz-prev-time">--:--</span></div>
        <div class="tz-preview-sub" id="tz-prev-sub"></div>
      </div>
      <div class="tz-device" id="tz-device" hidden></div>

      <div class="tz-actions">
        <button class="btn btn-ghost" id="tz-clear" type="button"><i class="fa-solid fa-rotate-left"></i> Usar la zona del sitio</button>
        <button class="btn btn-primary" id="tz-save" type="button"><i class="fa-solid fa-check"></i> Guardar</button>
      </div>
      <p class="tz-note"><i class="fa-solid fa-circle-info"></i> Cambiar tu zona no modifica ninguna fecha guardada: solo cómo se te muestran. Los cambios de horario de verano se aplican solos.</p>
      <script type="application/json" id="tz-data"><?= json_encode($tzData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </section>

    <!-- ── APARIENCIA ── -->
    <section class="cfg-section" id="sec-apariencia">
      <div class="cfg-section-title">Apariencia</div>
      <div class="cfg-section-sub">Personaliza los colores y el aspecto de la plataforma</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-moon"></i><span>Tema</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Tema de la plataforma</div>
            <div class="cfg-row-desc">Afecta todos los colores del sistema</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php
            // Mismos temas que g7-theme.css y que el "Tema por defecto" del admin
            $themes=[
              'dark'     =>['label'=>'Oscuro',     'bg'=>'#0a0a0a','card'=>'#18181b','accent'=>'#ff6600'],
              'midnight' =>['label'=>'Medianoche', 'bg'=>'#060810','card'=>'#141a2a','accent'=>'#60a5fa'],
              'violet'   =>['label'=>'Violeta',    'bg'=>'#08050f','card'=>'#1a1326','accent'=>'#a855f7'],
              'green'    =>['label'=>'Verde',      'bg'=>'#040d08','card'=>'#0f2016','accent'=>'#22c55e'],
              'rose'     =>['label'=>'Rosa',       'bg'=>'#0f050a','card'=>'#24101a','accent'=>'#f43f5e'],
              'light'    =>['label'=>'Claro',      'bg'=>'#f8f8f8','card'=>'#e8e8ec','accent'=>'#e05500'],
            ];
            $currentTheme = pref('theme', function_exists('g7_setting') ? (g7_setting('default_theme') ?: 'dark') : 'dark', $prefs);
            if (!isset($themes[$currentTheme])) $currentTheme = 'dark';
            foreach($themes as $key=>$t):
            ?>
            <button type="button" class="cfg-theme <?= $currentTheme===$key ? 'active' : '' ?>" data-on-click="cfgSetTheme" data-click-1="s:<?= $key ?>" data-click-2="s:<?= $t['accent'] ?>" id="theme-btn-<?= $key ?>"
              style="--t-bg:<?= $t['bg'] ?>;--t-card:<?= $t['card'] ?>;--t-acc:<?= $t['accent'] ?>;--t-fg:<?= $key==='light' ? '#222' : '#e4e4e7' ?>">
              <span class="cfg-theme-prev"><i></i><b></b><b></b></span>
              <span class="cfg-theme-name"><?= $t['label'] ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-droplet"></i><span>Color de acento</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Color principal</div>
            <div class="cfg-row-desc">Afecta botones, badges y elementos activos</div>
          </div>
          <div class="cfg-color">
            <input type="color" id="cfg-accent-color" value="<?= htmlspecialchars(pref('accent_color','#ff6600',$prefs)) ?>"
              data-on-input="cfgAccentFromColor" data-input-1="this">
            <input class="cfg-color-hex" id="cfg-accent-hex" value="<?= htmlspecialchars(pref('accent_color','#ff6600',$prefs)) ?>"
              data-on-input="cfgAccentFromHex" data-input-1="this">
            <button class="btn btn-primary" data-on-click="cfgSaveAccent">
              <i class="fa-solid fa-check"></i>
            </button>
          </div>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Presets</div>
          </div>
          <div class="cfg-swatches">
            <?php
            $swatches = ['#ff6600','#3b82f6','#22c55e','#a855f7','#ef4444','#eab308','#06b6d4','#f97316'];
            $current = pref('accent_color','#ff6600',$prefs);
            foreach($swatches as $c):
            ?>
            <div class="cfg-swatch <?= $c===$current?'active':'' ?>" style="background:<?= $c ?>" data-on-click="cfgSetAccent" data-click-1="s:<?= $c ?>" title="<?= $c ?>"></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-text-height"></i><span>Tipografía</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Tamaño de fuente</div>
            <div class="cfg-row-desc">Tamaño base del texto en la plataforma</div>
          </div>
          <select class="cfg-select" data-on-change="cfgSave" data-change-1="s:font_size" data-change-2="value">
            <?php foreach(['small'=>'Pequeño','medium'=>'Mediano','large'=>'Grande'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= pref('font_size','medium',$prefs)===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-sidebar"></i><span>Layout</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Sidebar compacto</div>
            <div class="cfg-row-desc">Muestra solo íconos en el menú lateral</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('sidebar_compact',false,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:sidebar_compact" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Animaciones</div>
            <div class="cfg-row-desc">Transiciones y efectos de interfaz</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('dashboard_animations',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:dashboard_animations" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>
    </section>

    <!-- ── CHAT ── -->
    <section class="cfg-section" id="sec-chat">
      <div class="cfg-section-title">Chat</div>
      <div class="cfg-section-sub">Ajusta cómo se ve y se comporta el chat</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-message"></i><span>Burbujas</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Tamaño de burbujas</div>
            <div class="cfg-row-desc">Tamaño del texto en los mensajes</div>
          </div>
          <select class="cfg-select" data-on-change="cfgSave" data-change-1="s:chat_bubble_size" data-change-2="value">
            <?php foreach(['small'=>'Compacto','medium'=>'Normal','large'=>'Grande'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= pref('chat_bubble_size','medium',$prefs)===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Mostrar avatares</div>
            <div class="cfg-row-desc">Foto de perfil junto a cada mensaje</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('chat_show_avatars',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:chat_show_avatars" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Vista compacta</div>
            <div class="cfg-row-desc">Menor separación entre mensajes</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('chat_compact',false,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:chat_compact" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-keyboard"></i><span>Comportamiento</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Enter para enviar</div>
            <div class="cfg-row-desc">Enter envía el mensaje. Shift+Enter hace salto de línea</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('chat_enter_send',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:chat_enter_send" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>
    </section>

    <!-- ── NOTIFICACIONES ── -->
    <section class="cfg-section" id="sec-notificaciones">
      <div class="cfg-section-title">Notificaciones</div>
      <div class="cfg-section-sub">Controla cómo y cuándo te notificamos</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-volume-high"></i><span>Sonidos</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Sonido de mensajes</div>
            <div class="cfg-row-desc">Bip al recibir un mensaje nuevo en el chat</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('notif_sound',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:notif_sound" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Sacudida de pantalla en zumbidos</div>
            <div class="cfg-row-desc">Agitar la pantalla al recibir un zumbido</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('notif_zumbido',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:notif_zumbido" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-bell"></i><span>Alertas visuales</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Badges de no leídos</div>
            <div class="cfg-row-desc">Contador rojo en el ícono del chat</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('notif_badges',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:notif_badges" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Notificaciones del navegador</div>
            <div class="cfg-row-desc">Alertas fuera del navegador (requiere permiso)</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <span id="cfg-notif-perm" style="font-size:10px;color:var(--g7-text-4)"></span>
            <label class="cfg-toggle">
              <input type="checkbox" id="cfg-notif-desktop" <?= pref('notif_desktop',false,$prefs)?'checked':'' ?>
                data-on-change="cfgToggleDesktopNotif" data-change-1="checked">
              <div class="cfg-toggle-track"></div>
              <div class="cfg-toggle-thumb"></div>
            </label>
          </div>
        </div>
      </div>
    </section>

    <!-- ── PRIVACIDAD ── -->
    <section class="cfg-section" id="sec-privacidad">
      <div class="cfg-section-title">Privacidad</div>
      <div class="cfg-section-sub">Controla qué información ven los demás de ti</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-eye"></i><span>Visibilidad</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Mostrar estado en línea</div>
            <div class="cfg-row-desc">El punto verde visible en el chat para los demás</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('privacy_online',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:privacy_online" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Mostrar última conexión</div>
            <div class="cfg-row-desc">Otros pueden ver cuándo estuviste activo</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('privacy_last_seen',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:privacy_last_seen" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Confirmaciones de lectura</div>
            <div class="cfg-row-desc">Los ticks ✓✓ que indican que leíste un mensaje</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('privacy_read_receipts',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:privacy_read_receipts" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>
    </section>

    <!-- ── DASHBOARD ── -->
    <section class="cfg-section" id="sec-dashboard">
      <div class="cfg-section-title">Dashboard</div>
      <div class="cfg-section-sub">Ajusta la densidad y comportamiento del panel principal</div>

      <div class="cfg-card">
        <div class="cfg-card-head"><i class="fa-solid fa-table-cells"></i><span>Densidad</span></div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Densidad de elementos</div>
            <div class="cfg-row-desc">Espacio entre tarjetas y elementos de la UI</div>
          </div>
          <select class="cfg-select" data-on-change="cfgSave" data-change-1="s:dashboard_density" data-change-2="value">
            <?php foreach(['compact'=>'Compacto','normal'=>'Normal','comfortable'=>'Cómodo'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= pref('dashboard_density','normal',$prefs)===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="cfg-row">
          <div class="cfg-row-info">
            <div class="cfg-row-label">Animaciones de interfaz</div>
            <div class="cfg-row-desc">Desactivar para mejor rendimiento</div>
          </div>
          <label class="cfg-toggle">
            <input type="checkbox" <?= pref('dashboard_animations',true,$prefs)?'checked':'' ?>
              data-on-change="cfgSave" data-change-1="s:dashboard_animations" data-change-2="checked">
            <div class="cfg-toggle-track"></div>
            <div class="cfg-toggle-thumb"></div>
          </label>
        </div>
      </div>
    </section>
<?php if ($uc_gcal) include __DIR__ . '/integraciones/perfil_google.php'; ?>
<?php include __DIR__ . '/perfil_seguridad.php'; ?>

  </div><!-- cfg-content -->
</div><!-- cfg-layout -->

<!-- PASSWORD MODAL -->
<div id="cfg-pw-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9000;display:none;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:14px;width:100%;max-width:360px;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:14px;font-weight:700"><i class="fa-solid fa-key" style="color:var(--g7-accent);margin-right:7px"></i>Cambiar contraseña</span>
      <button data-on-click="cfgClosePw" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:17px">✕</button>
    </div>
    <div style="padding:16px 18px;display:flex;flex-direction:column;gap:10px">
      <div>
        <label style="font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px">Contraseña actual</label>
        <input type="password" id="cfg-pw-cur" class="cfg-select" style="width:100%" placeholder="••••••••">
      </div>
      <div>
        <label style="font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px">Nueva contraseña</label>
        <input type="password" id="cfg-pw-new" class="cfg-select" style="width:100%" placeholder="••••••••">
      </div>
      <div>
        <label style="font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px">Confirmar</label>
        <input type="password" id="cfg-pw-confirm" class="cfg-select" style="width:100%" placeholder="••••••••">
      </div>
      <div id="cfg-pw-error" style="color:#ef4444;font-size:11px;display:none"></div>
    </div>
    <div style="padding:12px 18px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px">
      <button data-on-click="cfgClosePw" class="btn btn-ghost" style="flex:1">Cancelar</button>
      <button data-on-click="cfgChangePassword" class="btn btn-primary" style="flex:2"><i class="fa-solid fa-check"></i> Cambiar</button>
    </div>
  </div>
</div>

<div id="cfg-toast"></div>

<script src="/toolz/assets/user-config.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/user-config.js') ?>"></script>
<script src="/toolz/assets/user-tz.js?v=<?= (int)@filemtime(G7_SITE_ROOT . '/assets/user-tz.js') ?>"></script>
</body>
</html>