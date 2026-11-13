<?php
/**
 * clientes.php — Sección Clientes de cronos, aparte del panel Admin.
 * Reusa la sección de siempre (admin_clientes.php + admin_clientes.js, datos en clientes_api.php).
 * Quién entra se decide en Admin › Módulos y permisos › Clientes (por defecto, solo admins). ?id=N abre un cliente.
 */
require_once __DIR__ . '/auth.php';
if (!g7_site_has('clientes')) { http_response_code(404); exit; }
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$db = getDB();
g7_nav_add_once('nav_clientes_added', ['href' => '/toolz/clientes.php', 'icon' => 'fa-address-book', 'label' => 'Clientes', 'enabled' => true], '/toolz/tareas.php');
if (is_file(__DIR__ . '/facturacion/index.php')) g7_nav_add_once('nav_facturacion_added', ['href' => '/toolz/facturacion/', 'icon' => 'fa-file-invoice-dollar', 'label' => 'Facturación', 'enabled' => true], '/toolz/clientes.php');
try {
    $_cp = $db->prepare("SELECT pref_key,pref_value FROM g7_user_prefs WHERE user_id=? AND pref_key IN ('theme','font_size')");
    $_cp->execute([$currentUser['id']]);
    $_cp_prefs = [];
    foreach ($_cp->fetchAll() as $_r) $_cp_prefs[$_r['pref_key']] = json_decode($_r['pref_value'], true);
} catch (Exception $e) { $_cp_prefs = []; }
$_cp_theme = $_cp_prefs['theme'] ?? 'dark';
$_cp_font  = $_cp_prefs['font_size'] ?? 'medium';
?>
<!DOCTYPE html>
<html lang="es" class="theme-<?= htmlspecialchars($_cp_theme) ?> font-<?= htmlspecialchars($_cp_font) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars(g7_term('client', 'p')) ?> · <?= htmlspecialchars(g7_setting('platform_name') ?: g7_site('name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha384-h/hnnw1Bi4nbpD6kE7nYfCXzovi622sY5WBxww8ARKwpdLj5kUWjRuyiXaD1U2JT" crossorigin="anonymous">
<link rel="stylesheet" href="/toolz/g7-theme.css?v=spc2">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--g7-bg);color:var(--g7-text);font-family:var(--g7-font);min-height:100dvh}
.cp-wrap{max-width:1320px;margin:0 auto;padding:30px 28px 72px}
/* Lo que la sección usa del panel Admin */
/* CONTENT */
.adm-content{padding:28px 32px;min-width:0}
.adm-section{display:none}.adm-section.active{display:block}
.adm-title{font-size:20px;font-weight:800;margin-bottom:4px}
.adm-sub{font-size:12px;color:var(--g7-text-4);margin-bottom:24px}

/* STATS ROW */
.adm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.adm-stat{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:16px;text-align:center}
.adm-stat-val{font-size:28px;font-weight:800;color:var(--g7-accent)}
.adm-stat-label{font-size:10px;color:var(--g7-text-4);margin-top:3px}

/* TABLE */
.adm-table-wrap{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:20px}
.adm-table{width:100%;border-collapse:collapse}
.adm-table th{padding:10px 14px;font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border);text-align:left}
.adm-table td{padding:11px 14px;font-size:12px;border-bottom:1px solid var(--g7-border);vertical-align:middle}
.adm-table tr:last-child td{border-bottom:none}
.adm-table tr:hover td{background:var(--g7-bg-4)}

/* BADGE */
.badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.badge-admin{background:var(--g7-accent-muted);color:var(--g7-accent);border:1px solid var(--g7-accent-border)}
.badge-member{background:var(--g7-bg-5);color:var(--g7-text-4)}
.badge-active{background:rgba(34,197,94,.1);color:#22c55e}
.badge-inactive{background:rgba(239,68,68,.1);color:#ef4444}

/* BUTTONS */
.btn{border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s;white-space:nowrap}
.btn-primary{background:var(--g7-accent);color:#000}.btn-primary:hover{opacity:.85}
.btn-ghost{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3)}.btn-ghost:hover{color:var(--g7-text)}
.btn-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#ef4444}.btn-danger:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:4px 10px;font-size:11px}
.btn-icon{padding:5px 8px;background:var(--g7-bg-5);border:1px solid var(--g7-border-3);border-radius:7px;cursor:pointer;color:var(--g7-text-3);transition:all .15s}.btn-icon:hover{color:var(--g7-text)}

/* FORM */
.adm-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.adm-label{font-size:9px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:4px}
.adm-input{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none;transition:border-color .15s;font-family:var(--g7-font)}
.adm-input:focus{border-color:var(--g7-accent)}
.adm-select{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none;cursor:pointer}
.adm-select:focus{border-color:var(--g7-accent)}

/* CARD */
.adm-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;margin-bottom:16px}
.adm-card-head{padding:13px 18px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;justify-content:space-between}
.adm-card-title{font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px}
.adm-card-title i{color:var(--g7-accent)}
.adm-card-body{padding:16px 18px}
/* TOGGLE */
.adm-toggle{position:relative;width:40px;height:22px;cursor:pointer;flex-shrink:0}
.adm-toggle input{opacity:0;width:0;height:0;position:absolute}
.adm-toggle-track{position:absolute;inset:0;background:var(--g7-border-3);border-radius:11px;transition:background .2s}
.adm-toggle input:checked + .adm-toggle-track{background:var(--g7-accent)}
.adm-toggle-thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;pointer-events:none}
.adm-toggle input:checked ~ .adm-toggle-thumb{transform:translateX(18px)}
/* TOAST */
#adm-toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(60px);background:var(--g7-success);color:#000;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;transition:transform .3s;z-index:9999;pointer-events:none}

/* En su propia página: la sección siempre visible y un título con más presencia */
.adm-section{display:block}
#cli-list-view .cli-head-main .adm-title{font-size:28px;font-weight:800;letter-spacing:-.03em;line-height:1.1}
#cli-list-view .cli-head-main .adm-title::after{content:".";color:var(--g7-accent)}
@media (max-width:640px){.cp-wrap{padding:22px 16px 56px}.adm-form-grid{grid-template-columns:1fr}.cli-head-act{flex-wrap:wrap}}
</style>
<script src="/toolz/assets/g7-imgprep.js?v=1"></script>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="cp-wrap">
<?php include __DIR__ . '/admin_clientes.php'; ?>
</main>
<div id="adm-toast"></div>
<script src="/toolz/clientes-page.js?v=<?= @filemtime(__DIR__ . '/clientes-page.js') ?>"></script>
</body>
</html>
