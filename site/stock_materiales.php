<?php
/**
 * stock.php — Materiales: una carpeta por cliente con sus logos, tipografías, manuales y fotos, como un drive.
 * (Antes era un Stock de imágenes; quedó en dev/stock.antes.php.) Lógica en materiales.js, datos en materiales_api.php.
 * ?c=ID abre la carpeta de un cliente, &p=Logos/Viejos una subcarpeta.
 */
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$db = getDB();
require_once __DIR__ . '/clientes_lib.php';
cli_install($db);

// Una sola vez: el menú y el bloque del Inicio pasan de "Stock" a "Materiales" (y el link del menú queda encendido)
if (g7_cfg('nav_materiales_v1') !== '1') {
    $nav = g7_cfg_json('nav_links', null);
    if (is_array($nav)) {
        $found = false;
        foreach ($nav as &$n) if (($n['href'] ?? '') === '/toolz/stock.php') { $n['label'] = 'Materiales'; $n['icon'] = 'fa-folder-open'; $n['enabled'] = true; $found = true; }
        unset($n);
        if (!$found) $nav[] = ['href' => '/toolz/stock.php', 'icon' => 'fa-folder-open', 'label' => 'Materiales', 'enabled' => true];
        g7_cfg_set('nav_links', json_encode($nav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $home = g7_cfg_json('home_config', []);
    if (!empty($home['tiles'])) {
        foreach ($home['tiles'] as &$t) if (($t['href'] ?? '') === '/toolz/stock.php' && ($t['name'] ?? '') === 'Stock') { $t['name'] = 'Materiales'; $t['desc'] = 'Archivos de clientes'; $t['icon'] = 'fa-folder-open'; }
        unset($t);
        g7_cfg_set('home_config', json_encode($home, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    g7_cfg_set('nav_materiales_v1', '1');
}
$v = fn($f) => @filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Materiales · <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3" crossorigin="anonymous">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;background:var(--g7-bg,#000);color:var(--g7-text,#fff);font-family:var(--g7-font,'Montserrat',sans-serif);font-size:13px;min-height:100dvh}
button,input,select{font:inherit;color:inherit}
.mt{max-width:1440px;margin:0 auto;padding:26px 24px 120px}
.mt-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.mt-head h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.6px;line-height:1.1}
.mt-head h1 span{color:var(--g7-accent)}
.mt-head p{margin:6px 0 0;color:var(--g7-text-3);font-size:13px;max-width:62ch}
.mt-search{position:relative;width:min(340px,100%)}
.mt-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:12px}
.mt-search input{width:100%;height:38px;border-radius:10px;border:1px solid var(--g7-border-3);background:var(--g7-bg-3);padding:0 12px 0 34px;outline:none;transition:border-color .15s}
.mt-search input:focus{border-color:var(--g7-accent)}

.mt-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border-radius:9px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-2);font-weight:600;font-size:12.5px;cursor:pointer;white-space:nowrap;text-decoration:none;transition:border-color .15s,color .15s,transform .1s}
.mt-btn:hover{border-color:var(--g7-accent-border);color:var(--g7-text)}
.mt-btn:active{transform:translateY(1px)}
.mt-btn:focus-visible,.mt-ic:focus-visible,.mt-card:focus-visible,.mt-fold:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.mt-btn.primary{background:linear-gradient(#ff7700,#e65500);border-color:transparent;color:#fff;box-shadow:0 6px 18px rgba(255,102,0,.28);font-weight:700}
.mt-btn.primary:hover{filter:brightness(1.08)}
.mt-btn.danger{color:#f87171}
.mt-btn.danger:hover{border-color:rgba(239,68,68,.5)}
.mt-btn:disabled{opacity:.45;cursor:not-allowed}
.mt-ic{width:30px;height:30px;border-radius:8px;border:1px solid transparent;background:none;color:var(--g7-text-3);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s,color .15s}
.mt-ic:hover{background:var(--g7-bg-5);color:var(--g7-text)}
.mt-ic.danger:hover{color:#f87171}

/* Clientes */
.mt-clients{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.mt-card{display:flex;flex-direction:column;gap:14px;padding:16px;border-radius:16px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);cursor:pointer;text-align:left;transition:border-color .15s,transform .15s,background .15s}
.mt-card:hover{border-color:var(--g7-accent-border);transform:translateY(-2px);background:var(--g7-bg-4)}
.mt-card-top{display:flex;align-items:center;gap:12px;min-width:0}
.mt-av{width:46px;height:46px;border-radius:13px;flex:none;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#fff;overflow:hidden;background:var(--g7-bg-5)}
.mt-av img{width:100%;height:100%;object-fit:cover}
.mt-av.sm{width:30px;height:30px;border-radius:9px;font-size:13px}
.mt-card-name{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-card-meta{color:var(--g7-text-3);font-size:11.5px;margin-top:2px}
.mt-card-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--g7-text-3);font-size:11.5px}
.mt-card-foot i{color:#fbbf24}
.mt-st{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 8px;border-radius:999px;background:var(--g7-bg-5);color:var(--g7-text-3)}
.mt-st.activo{background:rgba(34,197,94,.12);color:#4ade80}
.mt-st.pausado{background:rgba(245,158,11,.12);color:#fbbf24}
.mt-st.terminado{opacity:.6}

/* Carpeta abierta */
.mt-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.mt-crumbs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0;font-size:14px}
.mt-crumbs button{background:none;border:none;padding:4px 6px;border-radius:7px;cursor:pointer;color:var(--g7-text-3);font-weight:600;display:inline-flex;align-items:center;gap:8px}
.mt-crumbs button:hover{background:var(--g7-bg-4);color:var(--g7-text)}
.mt-crumbs button.cur{color:var(--g7-text);font-weight:800;cursor:default;background:none}
.mt-crumbs .sep{color:var(--g7-text-5);font-size:10px}
.mt-actions{display:flex;gap:8px;flex-wrap:wrap}
.mt-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--g7-text-4);margin:22px 0 10px}
.mt-folders{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
.mt-fold{display:flex;align-items:center;gap:12px;padding:12px 10px 12px 14px;border-radius:12px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);cursor:pointer;min-width:0;transition:border-color .15s,background .15s}
.mt-fold:hover{border-color:var(--g7-accent-border);background:var(--g7-bg-4)}
.mt-fold.drop{border-color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.1)}
.mt-fold>i{font-size:22px;color:#fbbf24;flex:none}
.mt-fold-b{min-width:0;flex:1}
.mt-fold-n{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-fold-m{color:var(--g7-text-4);font-size:11px;margin-top:1px}
.mt-files{display:grid;grid-template-columns:repeat(auto-fill,minmax(178px,1fr));gap:12px}
.mt-file{position:relative;border-radius:14px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);overflow:hidden;display:flex;flex-direction:column;transition:border-color .15s}
.mt-file:hover{border-color:var(--g7-border-3)}
.mt-file.sel{border-color:var(--g7-accent);box-shadow:0 0 0 1px var(--g7-accent)}
.mt-th{aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;
  background:var(--g7-bg-2) repeating-conic-gradient(rgba(var(--g7-ov),.04) 0 25%,transparent 0 50%) 0 0/16px 16px;border:none;width:100%;padding:0}
.mt-th img{max-width:100%;max-height:100%;object-fit:contain}
.mt-th>i{font-size:34px;color:var(--g7-text-4)}
.mt-th .font{font-size:30px;color:var(--g7-text);padding:10px;text-align:center;line-height:1.15;overflow:hidden}
.mt-th .font small{display:block;font-size:12px;color:var(--g7-text-3);margin-top:6px;letter-spacing:.04em}
.mt-ext{position:absolute;top:8px;left:8px;font-size:9.5px;font-weight:800;letter-spacing:.06em;padding:3px 7px;border-radius:6px;background:rgba(0,0,0,.65);color:#fff;text-transform:uppercase}
.mt-chk{position:absolute;top:7px;right:7px;width:22px;height:22px;border-radius:7px;border:1.5px solid rgba(255,255,255,.55);background:rgba(0,0,0,.45);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;opacity:0;transition:opacity .15s}
.mt-file:hover .mt-chk,.mt-file.sel .mt-chk,.mt-selmode .mt-chk{opacity:1}
.mt-file.sel .mt-chk{background:var(--g7-accent);border-color:var(--g7-accent)}
.mt-fb{padding:10px 10px 8px 12px;display:flex;flex-direction:column;gap:2px;min-width:0}
.mt-fn{font-weight:600;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-fm{color:var(--g7-text-4);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mt-fa{display:flex;gap:2px;margin-top:4px}
.mt-empty{border:1.5px dashed var(--g7-border-3);border-radius:16px;padding:42px 20px;text-align:center;color:var(--g7-text-3)}
.mt-empty i{font-size:30px;color:var(--g7-text-4);display:block;margin-bottom:10px}
.mt-empty b{display:block;color:var(--g7-text);font-size:14px;margin-bottom:4px}
.mt-hint{color:var(--g7-text-3);padding:18px 0}

/* Arrastrar archivos */
.mt-dropzone{position:fixed;inset:0;z-index:300;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);backdrop-filter:blur(3px)}
.mt-dropzone.on{display:flex}
.mt-dropzone div{border:2px dashed var(--g7-accent);border-radius:22px;padding:48px 60px;text-align:center;background:var(--g7-bg-3);font-size:15px;font-weight:700}
.mt-dropzone i{font-size:36px;color:var(--g7-accent);display:block;margin-bottom:12px}
.mt-dropzone small{display:block;font-weight:500;color:var(--g7-text-3);margin-top:6px}

/* Barra de selección */
.mt-selbar{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:150;display:none;align-items:center;gap:10px;padding:10px 12px 10px 18px;border-radius:14px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);box-shadow:0 14px 40px rgba(0,0,0,.5)}
.mt-selbar.on{display:flex}
.mt-selbar b{font-size:13px;margin-right:6px;white-space:nowrap}

/* Subidas */
.mt-up{position:fixed;right:20px;bottom:20px;z-index:160;width:min(360px,calc(100vw - 32px));border-radius:16px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);box-shadow:0 18px 50px rgba(0,0,0,.55);display:none;overflow:hidden}
.mt-up.on{display:block}
.mt-up-h{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--g7-border-2);font-weight:700}
.mt-up-l{max-height:280px;overflow:auto;padding:6px 0}
.mt-up-i{padding:7px 14px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3px 10px;align-items:center}
.mt-up-i .n{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px}
.mt-up-i .s{font-size:11px;color:var(--g7-text-3)}
.mt-up-i .s.err{color:#f87171}
.mt-up-i .s.ok{color:#4ade80}
.mt-up-i .bar{grid-column:1/-1;height:4px;border-radius:4px;background:var(--g7-bg-5);overflow:hidden}
.mt-up-i .bar span{display:block;height:100%;width:0;background:var(--g7-accent);transition:width .2s}

/* Vista previa y diálogos */
.mt-modal{position:fixed;inset:0;z-index:400;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.8);padding:24px}
.mt-modal.on{display:flex}
.mt-prev{max-width:min(1100px,100%);max-height:100%;display:flex;flex-direction:column;gap:12px;align-items:center}
.mt-prev img{max-width:100%;max-height:calc(100dvh - 140px);object-fit:contain;border-radius:10px;background:repeating-conic-gradient(rgba(255,255,255,.06) 0 25%,transparent 0 50%) 0 0/18px 18px}
.mt-prev iframe{width:min(1000px,92vw);height:calc(100dvh - 140px);border:none;border-radius:10px;background:#fff}
.mt-prev .font-big{background:var(--g7-bg-3);border-radius:14px;padding:30px;max-width:900px;width:92vw;color:var(--g7-text)}
.mt-prev .font-big div{font-size:56px;line-height:1.1;word-break:break-word}
.mt-prev .font-big p{font-size:20px;margin:16px 0 0;color:var(--g7-text-2)}
.mt-prev-bar{display:flex;align-items:center;gap:10px;color:#fff;font-weight:600;flex-wrap:wrap;justify-content:center}
.mt-dlg{background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;padding:20px;width:min(420px,100%);display:flex;flex-direction:column;gap:12px}
.mt-dlg h3{margin:0;font-size:16px}
.mt-dlg input,.mt-dlg select{height:38px;border-radius:9px;border:1px solid var(--g7-border-3);background:var(--g7-bg-2);padding:0 12px;outline:none;width:100%}
.mt-dlg input:focus,.mt-dlg select:focus{border-color:var(--g7-accent)}
.mt-dlg .row{display:flex;justify-content:flex-end;gap:8px;margin-top:4px}
.mt-dlg .err{color:#f87171;font-size:12px;min-height:0}
.mt-toast{position:fixed;left:50%;top:76px;transform:translateX(-50%);z-index:500;padding:10px 16px;border-radius:10px;background:var(--g7-bg-5);border:1px solid var(--g7-border-3);font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.4)}
.mt-toast.err{border-color:rgba(239,68,68,.5);color:#fca5a5}
@media (max-width:640px){.mt{padding:20px 16px 120px}.mt-head h1{font-size:24px}.mt-files{grid-template-columns:repeat(2,minmax(0,1fr))}.mt-actions .mt-btn span{display:none}}
</style>
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="mt" id="mt">
  <div class="mt-head">
    <div>
      <h1>Materiales <span>de clientes</span></h1>
      <p>Una carpeta por cliente con sus logos, tipografías, manuales y fotos. Arrastra archivos o carpetas enteras para subirlos.</p>
    </div>
    <label class="mt-search"><i class="fa-solid fa-magnifying-glass"></i><input id="mt-q" type="search" placeholder="Buscar un archivo en todos los clientes" autocomplete="off"></label>
  </div>
  <div id="mt-view"><div class="mt-hint"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</div></div>
</main>

<div class="mt-dropzone" id="mt-drop"><div><i class="fa-solid fa-cloud-arrow-up"></i>Suelta para subir a <span id="mt-drop-to"></span><small>Archivos o carpetas enteras</small></div></div>
<div class="mt-selbar" id="mt-selbar"><b id="mt-seln"></b>
  <button class="mt-btn" data-on-click="mtMoveSel"><i class="fa-solid fa-folder-tree"></i> Mover</button>
  <button class="mt-btn danger" data-on-click="mtDeleteSel"><i class="fa-solid fa-trash"></i> Borrar</button>
  <button class="mt-ic" data-on-click="mtClearSel" title="Quitar selección"><i class="fa-solid fa-xmark"></i></button></div>
<div class="mt-up" id="mt-up"><div class="mt-up-h"><span id="mt-up-t">Subiendo</span><button class="mt-ic" data-on-click="mtUpClose" title="Cerrar"><i class="fa-solid fa-xmark"></i></button></div><div class="mt-up-l" id="mt-up-l"></div></div>
<div class="mt-modal" id="mt-modal" data-on-click="mtModalBg" data-click-1="this" data-click-2="event"></div>
<input type="file" id="mt-in-files" multiple hidden>
<input type="file" id="mt-in-dir" webkitdirectory multiple hidden>
<script src="/toolz/materiales.js?v=<?= $v('materiales.js') ?>"></script>
</body>
</html>
