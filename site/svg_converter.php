<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/csp_lib.php'; g7_csp_enforce();   // CSP estricta en modo bloqueo (SEC-24)
$db = getDB();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try { $db->prepare("INSERT INTO g7_accesos_rapidos (user_id, tool, tool_name) VALUES (?,?,?)")->execute([$currentUser['id'], '/toolz/svg_converter.php', 'SVG → PNG']); } catch(Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SVG → PNG · <?= htmlspecialchars(g7_site('short')) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha384-3B6NwesSXE7YJlcLI9RpRqGf2p/EgVH8BgoKTaUrmKNDkHPStTQ3EyoYjCGXaOTS" crossorigin="anonymous">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js" integrity="sha384-+mbV2IY1Zk/X1p/nWllGySJSUN8uMs+gUAN10Or95UBH0fpj6GfKgPmgC5EXieXG" crossorigin="anonymous"></script>
<link rel="stylesheet" href="/toolz/g7-theme.css?v=4">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#111111;--paper:#0a0a0a;--paper2:#141414;--paper3:#1a1a1a;
  --accent:var(--g7-accent,var(--g7-accent));--accent2:var(--g7-accent,var(--g7-accent));--border:var(--g7-border-3);
  --text:var(--g7-text);--text2:var(--g7-text-2);--text3:var(--g7-text-4);
  --shadow:3px 3px 0 rgba(0,0,0,.5);
}
body{background:var(--paper);color:var(--text);font-family:'Syne',sans-serif;min-height:100vh}
#cw{display:none!important}
body::after{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");pointer-events:none;z-index:9999;opacity:.2}

/* HEADER */
.sv-hdr{background:var(--ink);color:var(--text);padding:12px 28px;display:flex;align-items:center;gap:14px;border-bottom:3px solid var(--accent);position:sticky;top:52px;z-index:100}
.sv-logo{font-size:18px;font-weight:800;letter-spacing:-.03em;display:flex;align-items:center;gap:8px}
.sv-logo .arr{color:var(--accent)}
.sv-tagline{font-family:'IBM Plex Mono',monospace;font-size:9px;color:var(--g7-text-4);letter-spacing:.12em;text-transform:uppercase;margin-left:auto}

/* LAYOUT */
.sv-wrap{display:grid;grid-template-columns:320px 1fr;min-height:calc(100vh - 52px - 47px)}

/* SIDEBAR */
.sv-sidebar{background:var(--paper2);border-right:2px solid var(--border);padding:24px 20px;display:flex;flex-direction:column;gap:20px;overflow-y:auto}

/* DROP ZONE */
.sv-drop{border:2px dashed var(--border);background:var(--paper);min-height:160px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;cursor:pointer;transition:all .2s;position:relative;text-align:center;padding:20px}
.sv-drop:hover,.sv-drop.over{border-color:var(--accent);border-style:solid;background:rgba(230,51,41,.03)}
.sv-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.sv-drop-icon{font-size:32px;color:var(--border);transition:color .2s}
.sv-drop:hover .sv-drop-icon,.sv-drop.over .sv-drop-icon{color:var(--accent)}
.sv-drop-lbl{font-size:12px;font-weight:700;color:var(--text3)}
.sv-drop-sub{font-family:'IBM Plex Mono',monospace;font-size:9px;color:var(--text3)}

/* LOADED FILE */
.sv-loaded{display:none;background:var(--ink);color:var(--text);padding:10px 12px;align-items:center;gap:8px;font-family:'IBM Plex Mono',monospace;font-size:10px}
.sv-loaded.show{display:flex}
.sv-loaded-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sv-clear{background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:13px;flex-shrink:0;padding:0 4px;transition:color .15s}
.sv-clear:hover{color:var(--accent)}

/* SVG PREVIEW */
.sv-svg-prev{display:none;background:var(--paper3);border:1px solid var(--border);min-height:120px;align-items:center;justify-content:center;overflow:hidden;padding:10px}
.sv-svg-prev.show{display:flex}
.sv-svg-prev svg,.sv-svg-prev img{max-width:100%;max-height:120px;object-fit:contain}

/* SECTION LABEL */
.sv-sl{font-family:'IBM Plex Mono',monospace;font-size:8px;text-transform:uppercase;letter-spacing:.18em;color:var(--text3);display:flex;align-items:center;gap:6px;margin-bottom:8px}
.sv-sl::after{content:'';flex:1;height:1px;background:var(--border)}

/* SIZES */
.sv-size-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:4px}
.sv-size-btn{background:var(--paper);border:2px solid var(--border);padding:8px 4px;font-family:'IBM Plex Mono',monospace;font-size:10px;cursor:pointer;text-align:center;transition:all .15s;line-height:1.3}
.sv-size-btn:hover{border-color:var(--ink)}
.sv-size-btn.on{background:var(--ink);color:var(--text);border-color:var(--ink)}
.sv-size-btn .px{font-size:12px;font-weight:700;display:block}
.sv-size-btn .use{font-size:8px;color:inherit;opacity:.6;display:block}

/* CUSTOM SIZE */
.sv-custom-row{display:flex;gap:4px;align-items:center}
.sv-inp{background:var(--paper);border:2px solid var(--border);padding:7px 9px;font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--text);outline:none;width:100%;-moz-appearance:textfield;transition:border-color .15s}
.sv-inp::-webkit-outer-spin-button,.sv-inp::-webkit-inner-spin-button{-webkit-appearance:none}
.sv-inp:focus{border-color:var(--accent)}
.sv-inp-lbl{font-family:'IBM Plex Mono',monospace;font-size:9px;color:var(--text3);flex-shrink:0}

/* VARIANTS */
.sv-var-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px}
.sv-var-btn{background:var(--paper);border:2px solid var(--border);padding:8px;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:5px}
.sv-var-btn:hover{border-color:var(--ink)}
.sv-var-btn.on{background:var(--ink);color:var(--text);border-color:var(--ink)}
.sv-var-btn .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}

/* BACKGROUND */
.sv-bg-row{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
.sv-bg-sw{width:26px;height:26px;border:2px solid var(--border);cursor:pointer;transition:all .12s;flex-shrink:0}
.sv-bg-sw:hover,.sv-bg-sw.on{border-color:var(--accent);transform:scale(1.1)}
.sv-bg-sw.tr{background:repeating-conic-gradient(#bbb 0% 25%,#fff 0% 50%) 0/12px 12px}
.sv-bg-custom{width:30px;height:26px;border:2px solid var(--border);cursor:pointer;padding:0}

/* GENERATE BTN */
.sv-gen-btn{background:var(--accent);color:var(--g7-text);border:none;padding:13px;font-family:'Syne',sans-serif;font-size:14px;font-weight:800;cursor:pointer;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .15s;letter-spacing:.02em}
.sv-gen-btn:hover{background:#c4201a;transform:translateY(-1px);box-shadow:var(--shadow)}
.sv-gen-btn:disabled{background:var(--border);color:var(--text3);cursor:not-allowed;transform:none;box-shadow:none}

/* MAIN OUTPUT AREA */
.sv-main{padding:24px;overflow-y:auto}
.sv-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:400px;gap:14px;color:var(--text3)}
.sv-empty-icon{font-size:56px;opacity:.08}
.sv-empty-txt{font-family:'IBM Plex Mono',monospace;font-size:10px;letter-spacing:.15em;text-transform:uppercase}

/* RESULTS GRID */
.sv-results{display:none}
.sv-results.show{display:block}

/* VARIANT SECTION */
.sv-var-section{margin-bottom:28px}
.sv-var-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid var(--border)}
.sv-var-title .badge{font-family:'IBM Plex Mono',monospace;font-size:9px;padding:2px 8px;border:1px solid;font-weight:500}

/* SIZE CARDS */
.sv-cards{display:flex;flex-wrap:wrap;gap:12px}
.sv-card{background:var(--paper2);border:2px solid var(--border);padding:0;transition:all .15s;display:flex;flex-direction:column}
.sv-card:hover{border-color:var(--ink);box-shadow:var(--shadow)}
.sv-card-prev{background:repeating-conic-gradient(#d0ccc6 0% 25%,var(--paper3) 0% 50%) 0/14px 14px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.sv-card-info{padding:8px 10px;border-top:1px solid var(--border);display:flex;align-items:center;gap:6px}
.sv-card-size{font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:700;flex:1}
.sv-card-dl{background:var(--ink);border:none;color:var(--paper);padding:4px 10px;font-family:'IBM Plex Mono',monospace;font-size:9px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:4px;flex-shrink:0}
.sv-card-dl:hover{background:var(--accent)}

/* DOWNLOAD ALL ROW */
.sv-dl-row{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.sv-dl-btn{background:var(--ink);color:var(--paper);border:none;padding:10px 18px;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .15s}
.sv-dl-btn:hover{background:var(--accent);transform:translateY(-1px)}
.sv-dl-btn.outline{background:none;border:2px solid var(--ink);color:var(--ink)}
.sv-dl-btn.outline:hover{background:var(--ink);color:var(--paper);transform:translateY(-1px)}

/* FILE LIST */
.sv-file-list .sv-fl-item{display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--paper2);border-bottom:1px solid var(--border);font-family:'IBM Plex Mono',monospace;font-size:10px;transition:background .12s}
.sv-file-list .sv-fl-item:last-child{border-bottom:none}
.sv-file-list .sv-fl-item:hover{background:var(--paper3)}
.sv-fl-prev{width:28px;height:28px;object-fit:contain;flex-shrink:0;border:1px solid var(--border);background:#fff}
.sv-fl-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
.sv-fl-size{color:var(--text3);flex-shrink:0;font-size:9px}
.sv-fl-rm{background:none;border:none;color:var(--text3);cursor:pointer;font-size:11px;padding:0 4px;flex-shrink:0;transition:color .12s}
.sv-fl-rm:hover{color:var(--accent)}

/* TOAST */
.sv-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--ink);color:var(--paper);padding:9px 18px;font-family:'IBM Plex Mono',monospace;font-size:11px;z-index:9998;opacity:0;transition:all .3s;pointer-events:none;letter-spacing:.05em;border-left:3px solid var(--accent)}
.sv-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
/* Antes onmouseover/onmouseout (CSP estricta, SEC-24) */
.sv-clear-btn:hover{background:var(--ink)!important;color:var(--paper)!important}
.sv-add-lbl:hover{background:var(--accent)!important}
</style>
<link rel="stylesheet" href="/toolz/assets/g7-studio.css?v=<?= (int)@filemtime(__DIR__ . '/assets/g7-studio.css') ?>">
<link rel="stylesheet" href="/toolz/assets/svg-studio.css?v=<?= (int)@filemtime(__DIR__ . '/assets/svg-studio.css') ?>">
</head>
<body>
<?php include __DIR__ . '/topbar.php'; ?>

<!-- HEADER -->
<div class="sv-hdr">
  <div class="sv-logo"><i class="fa-solid fa-vector-square" style="color:var(--accent)"></i>SVG<span class="arr">→</span>PNG</div>
  <div class="sv-tagline">Conversor · <?= htmlspecialchars((string)g7_site('name')) ?></div>
</div>

<div class="sv-wrap">

<!-- ── SIDEBAR ── -->
<div class="sv-sidebar">

  <!-- Drop zone -->
  <div>
    <div class="sv-sl">Archivo SVG</div>
    <div class="sv-drop" id="drop" data-on-dragover="ev" data-dragover-1="event" data-dragover-2="s:over" data-on-drop="ev" data-drop-1="event" data-drop-2="s:drop">
      <i class="fa-solid fa-file-arrow-up sv-drop-icon"></i>
      <div class="sv-drop-lbl">Arrastra tu SVG o haz click</div>
      <div class="sv-drop-sub">.svg · hasta 10MB</div>
      <input type="file" id="file-in" accept=".svg,image/svg+xml" multiple>
    </div>
    <div class="sv-file-list" id="file-list" style="display:none;flex-direction:column;gap:0;max-height:220px;overflow-y:auto;border:2px solid var(--border)"></div>
    <div style="display:flex;gap:6px;margin-top:6px" id="file-actions" style="display:none">
      <button data-on-click="clearAll" class="sv-clear-btn" style="background:none;border:2px solid var(--border);color:var(--text2);padding:5px 10px;font-family:'IBM Plex Mono',monospace;font-size:9px;cursor:pointer;transition:all .15s">✕ Limpiar todo</button>
      <label style="background:var(--ink);color:var(--paper);border:none;padding:5px 10px;font-family:'IBM Plex Mono',monospace;font-size:9px;cursor:pointer;display:flex;align-items:center;gap:4px;transition:all .15s" class="sv-add-lbl">
        <i class="fa-solid fa-plus" style="font-size:9px"></i> Agregar más
        <input type="file" accept=".svg,image/svg+xml" multiple style="display:none" data-on-change="svAddMore" data-change-1="this">
      </label>
    </div>
  </div>

  <!-- Sizes -->
  <div>
    <div class="sv-sl">Tamaños de exportación</div>
    <div class="sv-size-grid" id="size-grid">
      <button class="sv-size-btn on" data-px="16"   data-on-click="toggleSize" data-click-1="this"><span class="px">16px</span><span class="use">Favicon</span></button>
      <button class="sv-size-btn on" data-px="32"   data-on-click="toggleSize" data-click-1="this"><span class="px">32px</span><span class="use">Favicon HD</span></button>
      <button class="sv-size-btn on" data-px="64"   data-on-click="toggleSize" data-click-1="this"><span class="px">64px</span><span class="use">Icono</span></button>
      <button class="sv-size-btn on" data-px="128"  data-on-click="toggleSize" data-click-1="this"><span class="px">128px</span><span class="use">App icon</span></button>
      <button class="sv-size-btn on" data-px="256"  data-on-click="toggleSize" data-click-1="this"><span class="px">256px</span><span class="use">Logo</span></button>
      <button class="sv-size-btn on" data-px="512"  data-on-click="toggleSize" data-click-1="this"><span class="px">512px</span><span class="use">Logo HD</span></button>
      <button class="sv-size-btn"    data-px="1024" data-on-click="toggleSize" data-click-1="this"><span class="px">1024px</span><span class="use">Alta res</span></button>
      <button class="sv-size-btn"    data-px="180"  data-on-click="toggleSize" data-click-1="this"><span class="px">180px</span><span class="use">iOS icon</span></button>
      <button class="sv-size-btn"    data-px="192"  data-on-click="toggleSize" data-click-1="this"><span class="px">192px</span><span class="use">PWA</span></button>
    </div>
    <div class="sv-custom-row" style="margin-top:6px">
      <span class="sv-inp-lbl">Custom:</span>
      <input class="sv-inp" type="number" id="custom-px" placeholder="px" min="1" max="4096" style="max-width:80px">
      <button data-on-click="addCustomSize" style="background:var(--ink);border:none;color:var(--paper);padding:7px 10px;font-family:'IBM Plex Mono',monospace;font-size:10px;cursor:pointer">+ Add</button>
    </div>
  </div>

  <!-- Variants -->
  <div>
    <div class="sv-sl">Versiones de color</div>
    <div class="sv-var-grid">
      <button class="sv-var-btn on" data-v="positive"  data-on-click="toggleVar" data-click-1="this"><span class="dot" style="background:linear-gradient(135deg,var(--g7-bg-5),#999)"></span> Original</button>
      <button class="sv-var-btn on" data-v="negative"  data-on-click="toggleVar" data-click-1="this"><span class="dot" style="background:linear-gradient(135deg,#fff,#aaa);border:1px solid #ccc"></span> Negativo</button>
      <button class="sv-var-btn on" data-v="black"     data-on-click="toggleVar" data-click-1="this"><span class="dot" style="background:#000"></span> Negro</button>
      <button class="sv-var-btn on" data-v="white"     data-on-click="toggleVar" data-click-1="this"><span class="dot" style="background:#fff;border:1px solid #ccc"></span> Blanco</button>
    </div>
  </div>

  <!-- Background -->
  <div>
    <div class="sv-sl">Fondo del PNG</div>
    <div class="sv-bg-row">
      <div class="sv-bg-sw tr on" data-bg="transparent" data-on-click="setBg" data-click-1="s:transparent" data-click-2="this" title="Transparente"></div>
      <div class="sv-bg-sw" data-bg="#ffffff" data-on-click="setBg" data-click-1="s:#ffffff" data-click-2="this" style="background:#fff" title="Blanco"></div>
      <div class="sv-bg-sw" data-bg="#000000" data-on-click="setBg" data-click-1="s:#000000" data-click-2="this" style="background:#000" title="Negro"></div>
      <div class="sv-bg-sw" data-bg="#f5f2eb" data-on-click="setBg" data-click-1="s:#f5f2eb" data-click-2="this" style="background:#f5f2eb" title="Crema"></div>
      <input type="color" class="sv-bg-custom" id="cbg" value="#ff6600" data-on-change="setBg" data-change-1="value" data-change-2="null" title="Personalizado">
      <span style="font-family:'IBM Plex Mono',monospace;font-size:9px;color:var(--text3)" id="bg-lbl">Transparente</span>
    </div>
  </div>

  <!-- Generate -->
  <button class="sv-gen-btn" id="gen-btn" data-on-click="generate" disabled>
    <i class="fa-solid fa-wand-magic-sparkles"></i> Generar exportaciones
  </button>
</div>

<!-- ── MAIN OUTPUT ── -->
<div class="sv-main">

  <!-- Empty state -->
  <div class="sv-empty" id="empty-state">
    <div class="sv-empty-icon"><i class="fa-solid fa-vector-square"></i></div>
    <div class="sv-empty-txt">Carga un SVG para comenzar</div>
    <div style="font-size:12px;color:var(--text3);margin-top:4px">Genera múltiples tamaños y variantes con un click</div>
  </div>

  <!-- Results -->
  <div class="sv-results" id="results">
    <div class="sv-dl-row" id="dl-row"></div>
    <div id="variants-container"></div>
  </div>

</div>
</div><!-- /sv-wrap -->

<div class="sv-toast" id="toast"></div>

<script src="/toolz/assets/g7-studio.js?v=<?= (int)@filemtime(__DIR__ . '/assets/g7-studio.js') ?>"></script>
<script src="/toolz/assets/svg-converter.js?v=<?= (int)@filemtime(__DIR__ . '/assets/svg-converter.js') ?>"></script>
</body>
</html>