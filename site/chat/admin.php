<?php
/**
 * chat/admin.php — Administración del chat (solo admins): estadísticas reales, canales y grupos,
 * categorías, personas, políticas de archivos y retención, y registro de acciones. Habla con api.php (acciones admin_*).
 */
require_once __DIR__ . '/lib.php';
$currentUser = requireLogin();
require_once dirname(__DIR__) . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo: esta página no tiene código en línea (SEC-24)
$isAdmin = ($currentUser['role'] === 'admin');
if (!$isAdmin) { http_response_code(403); exit('Solo los administradores'); }
$db = getDB();
ch_install();
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
define('CH_PAGE_IS_CHAT', 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Administración del chat · <?= htmlspecialchars(g7_setting('platform_name')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="chat.css?v=<?= $v('chat.css') ?>">
<style>
body{overflow:auto}
.ca{max-width:1240px;margin:0 auto;padding:24px 22px 60px}
.ca-h{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.ca-h h1{margin:0;font-size:26px;font-weight:800;letter-spacing:-.5px}
.ca-h h1 span{color:var(--g7-accent)}
.ca-h p{margin:5px 0 0;color:var(--g7-text-3)}
.ca-tabs{display:flex;gap:2px;padding:3px;border-radius:11px;background:var(--g7-bg-2);border:1px solid var(--g7-border-2);margin-bottom:18px;overflow-x:auto}
.ca-tabs button{border:0;background:none;height:34px;padding:0 14px;border-radius:8px;font-weight:600;font-size:13px;color:var(--g7-text-3);white-space:nowrap}
.ca-tabs button.on{background:var(--g7-bg-4);color:var(--g7-text)}
.ca-tabs button i{margin-right:6px}
.ca-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.ca-k{padding:14px 16px;border:1px solid var(--g7-border-2);border-radius:14px;background:var(--g7-bg-2)}
.ca-k small{display:block;font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em}
.ca-k b{display:block;font-size:26px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums}
.ca-k span{font-size:12px;color:var(--g7-text-3)}
.ca-card{padding:16px 18px;border:1px solid var(--g7-border-2);border-radius:14px;background:var(--g7-bg-2);margin-bottom:14px}
.ca-card h3{margin:0 0 12px;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.ca-card h3 .sp{flex:1}
.ca-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ca-bars{display:flex;align-items:flex-end;gap:2px;height:150px;padding-top:6px}
.ca-bars div{flex:1;min-width:2px;background:var(--g7-accent);border-radius:3px 3px 0 0;opacity:.85;position:relative}
.ca-bars div:hover{opacity:1}
.ca-axis{display:flex;justify-content:space-between;font-size:10.5px;color:var(--g7-text-4);margin-top:6px}
.ca-hb{display:grid;grid-template-columns:140px 1fr 60px;gap:8px;align-items:center;font-size:12.5px;margin:6px 0}
.ca-hb .t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ca-hb .b{height:10px;border-radius:5px;background:var(--g7-bg-4);overflow:hidden}
.ca-hb .b i{display:block;height:100%;background:var(--g7-accent);border-radius:5px}
.ca-hb .n{text-align:right;font-variant-numeric:tabular-nums;color:var(--g7-text-2)}
table.ca-t{width:100%;border-collapse:collapse;font-size:13px}
.ca-t th{text-align:left;font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em;padding:8px 10px;border-bottom:1px solid var(--g7-border-2);white-space:nowrap}
.ca-t td{padding:9px 10px;border-bottom:1px solid var(--g7-border-2);vertical-align:middle}
.ca-t tr:hover td{background:var(--g7-bg-3)}
.ca-t .num{text-align:right;font-variant-numeric:tabular-nums}
.ca-t .muted{color:var(--g7-text-4)}
.ca-wrap{overflow-x:auto}
.ca-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:10px;background:var(--g7-bg-4);color:var(--g7-text-2)}
.ca-pill.pub{background:rgba(34,197,94,.12);color:#16a34a}.ca-pill.priv{background:rgba(239,68,68,.1);color:#ef4444}.ca-pill.res{background:rgba(245,158,11,.14);color:#d97706}.ca-pill.arc{background:var(--g7-bg-5)}
.ca-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.ca-bar .cc-in{height:34px;width:auto;min-width:160px}
.ca-pol{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.ca-ext{display:flex;flex-wrap:wrap;gap:6px}
.ca-ext label{display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:4px 9px;border:1px solid var(--g7-border-3);border-radius:8px;cursor:pointer}
.ca-ext input{accent-color:#ef4444}
.ca-help{font-size:12px;color:var(--g7-text-4);margin-top:4px;line-height:1.45}
@media (max-width:900px){.ca-grid{grid-template-columns:repeat(2,1fr)}.ca-2,.ca-pol{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/../topbar.php'; ?>
<div class="ca">
  <div class="ca-h">
    <div><h1>Administración del <span>chat</span></h1><p>Uso real, canales, políticas y registro de acciones.</p></div>
    <a class="cc-btn" href="./"><i class="fa-solid fa-arrow-left"></i> Volver al chat</a>
  </div>
  <div class="ca-tabs" role="tablist">
    <button class="on" data-tab="stats" role="tab"><i class="fa-solid fa-chart-column"></i>Resumen</button>
    <button data-tab="channels" role="tab"><i class="fa-solid fa-hashtag"></i>Canales y grupos</button>
    <button data-tab="cats" role="tab"><i class="fa-solid fa-layer-group"></i>Categorías</button>
    <button data-tab="people" role="tab"><i class="fa-solid fa-users"></i>Personas</button>
    <button data-tab="policy" role="tab"><i class="fa-solid fa-shield-halved"></i>Políticas</button>
    <button data-tab="audit" role="tab"><i class="fa-solid fa-clock-rotate-left"></i>Registro</button>
  </div>
  <div id="ca-body"><div class="cc-sk"></div><div class="cc-sk"></div><div class="cc-sk"></div></div>
</div>
<div class="cc-toasts" id="cc-toasts" role="status" aria-live="polite"></div>
<script src="admin.js?v=<?= $v('admin.js') ?>"></script>
</body>
</html>
