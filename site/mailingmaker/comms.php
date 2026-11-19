<?php
// Generador de comunicaciones: antes era público. Ahora exige login y acceso al módulo Mailing Maker.
require_once __DIR__ . '/../auth.php';
$currentUser = requireLogin();
require_once __DIR__ . '/../csp_lib.php'; g7_csp_enforce();   // CSP estricta (SEC-24 fase 2): sin código en línea
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<script src="/toolz/assets/g7-on.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-on.js') ?>"></script><!-- eventos data-on-… (esta página no carga topbar.php) -->
<!-- movimiento, sonidos y juice de herramientas (MOVIMIENTO.md): esta página tiene su propia barra -->
<link rel="stylesheet" href="/toolz/assets/g7-juice.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-juice.css') ?>"><link rel="stylesheet" href="/toolz/assets/g7-tools.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-tools.css') ?>">
<script src="/toolz/assets/g7-juice.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-juice.js') ?>" defer></script><script src="/toolz/assets/g7-tools.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/g7-tools.js') ?>" defer></script>
<title>Generador de Mailings de Comunicación — <?= htmlspecialchars((string)(g7_pack('mailingmaker', [])['titulo'] ?? g7_site('short')), ENT_QUOTES, 'UTF-8') ?></title>
<style>
:root{
  --bg:#0a0a0a; --surface:rgba(255,255,255,0.04); --border:rgba(255,200,80,0.12);
  --text:#f0f0f0; --text2:#a0a0a0; --text3:#555;
  --green:#22c55e; --gold:#c9a84c; --accent:#ff4800; --red:#e74c3c;
  --r:10px; --rsm:6px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:#080808;color:var(--text);font-family:'Inter','Roboto Condensed',Arial,sans-serif;font-size:13px;}
.layout{display:flex;min-height:calc(100vh - 52px);}
.sidebar{width:380px;flex-shrink:0;background:#0d0d0d;border-right:1px solid var(--border);overflow-y:auto;}
.main{flex:1;padding:24px;overflow-y:auto;}

.header-bar{padding:14px 18px;background:linear-gradient(135deg,#0e0e0e,#181400);border-bottom:1px solid var(--border);}
.header-bar h1{font-size:13px;letter-spacing:1.2px;color:#fff;}
.header-bar p{font-size:11px;color:rgba(201,168,76,0.5);margin-top:3px;}

.sec{padding:14px 18px;border-bottom:1px solid var(--border);}
.sec-lbl{font-size:9px;font-weight:700;letter-spacing:2.5px;color:rgba(201,168,76,0.6);margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.sec-lbl small{font-size:9px;font-weight:400;color:var(--text3);letter-spacing:0;text-transform:none;}
.sec-lbl::after{content:'';flex:1;height:1px;background:rgba(201,168,76,0.15);}

.field{margin-bottom:10px;}
.field label{display:block;font-size:11px;color:var(--text2);margin-bottom:4px;}
.field input,.field textarea,.field select{
  width:100%;padding:8px 11px;background:rgba(255,255,255,0.04);
  border:1px solid rgba(255,200,80,0.15);border-radius:var(--rsm);
  color:var(--text);font-size:13px;font-family:inherit;outline:none;
}
.field input:focus,.field textarea:focus,.field select:focus{border-color:var(--gold);}
.field textarea{resize:vertical;min-height:60px;}

.row2{display:grid;grid-template-columns:1fr 1fr;gap:8px;}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;padding:8px 12px;
  border-radius:var(--rsm);border:1px solid rgba(255,200,80,0.15);font-size:12px;font-family:inherit;
  cursor:pointer;background:rgba(255,255,255,0.04);color:var(--text2);transition:all .15s;}
.btn:hover{background:rgba(255,255,255,0.1);color:var(--text);}
.btn-full{width:100%;}
.btn-sm{padding:5px 9px;font-size:11px;}
.btn-success{background:var(--green);color:#000;border-color:var(--green);font-weight:600;}
.btn-success:hover{background:#16a34a;color:#000;}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent);}

/* Plataformas */
.plat-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.plat-card{background:rgba(255,255,255,0.02);border:1px solid rgba(255,200,80,0.12);
  border-radius:var(--rsm);padding:8px 10px;cursor:pointer;display:flex;align-items:center;gap:8px;
  transition:all .15s;}
.plat-card:hover{border-color:rgba(255,255,255,0.2);background:rgba(255,255,255,0.05);}
.plat-card.active{border-color:var(--gold);background:rgba(201,168,76,0.08);}
.plat-radio{width:12px;height:12px;border-radius:50%;border:2px solid rgba(255,200,80,0.3);flex-shrink:0;}
.plat-card.active .plat-radio{border-color:var(--gold);background:var(--gold);}
.plat-name{font-size:11px;font-weight:500;}
.plat-tag{font-size:9px;color:var(--text3);}

/* Preview */
.preview-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;}
.preview-toolbar h2{font-size:12px;letter-spacing:2px;color:var(--text3);}
.preview-actions{display:flex;gap:6px;}

.preview-frame{background:#f7f8f9;border-radius:12px;padding:24px 0;display:flex;justify-content:center;
  box-shadow:0 20px 60px rgba(0,0,0,0.5),0 0 0 1px rgba(201,168,76,0.1);overflow:auto;}
.preview-mail{width:100%;max-width:520px;font-family:Arial,Helvetica,sans-serif;}

/* User data rows in sidebar */
.user-rows{display:flex;flex-direction:column;gap:4px;}
.user-row{display:flex;gap:5px;align-items:center;}
.user-row input{padding:5px 7px;font-size:11px;flex:1;}
.user-row input:first-child{max-width:90px;}
.user-row button{flex-shrink:0;width:22px;height:22px;background:rgba(220,50,50,0.1);
  border:1px solid rgba(220,50,50,0.3);border-radius:5px;color:#e74c3c;cursor:pointer;font-size:11px;}

/* Steps rows */
.steps-rows{display:flex;flex-direction:column;gap:5px;}
.step-row{display:flex;gap:5px;align-items:flex-start;}
.step-row .step-num{width:34px;flex-shrink:0;text-align:center;font-size:11px;color:var(--text3);padding-top:7px;}
.step-row input{flex:1;font-size:12px;}
.step-row button{flex-shrink:0;width:22px;height:22px;background:rgba(220,50,50,0.1);
  border:1px solid rgba(220,50,50,0.3);border-radius:5px;color:#e74c3c;cursor:pointer;font-size:11px;align-self:center;}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.82);z-index:999;
  align-items:center;justify-content:center;backdrop-filter:blur(4px);}
.modal-overlay.open{display:flex;}
.modal{background:#111;border:1px solid rgba(201,168,76,0.2);border-radius:14px;padding:20px;
  width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
.modal-head h3{font-size:14px;color:#fff;}

.m-tabs{display:flex;gap:0;border-bottom:1px solid rgba(201,168,76,0.18);margin-bottom:14px;}
.m-tab{padding:9px 18px;font-size:12px;cursor:pointer;color:var(--text2);background:none;border:none;
  border-bottom:2px solid transparent;margin-bottom:-1px;font-family:inherit;}
.m-tab.active{color:var(--gold);border-bottom-color:var(--gold);}

.m-panel{display:none;}
.m-panel.active{display:block;}

.gal-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;max-height:340px;overflow-y:auto;}
.gal-item{background:rgba(255,255,255,0.04);border:1px solid rgba(255,200,80,0.1);border-radius:6px;
  cursor:pointer;position:relative;overflow:hidden;aspect-ratio:1;}
.gal-item:hover{border-color:var(--gold);}
.gal-item.selected{border-color:var(--green);box-shadow:0 0 0 2px var(--green);}
.gal-item img{width:100%;height:100%;object-fit:cover;display:block;}
.gal-item .gal-del{position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;
  background:rgba(220,50,50,0.85);color:#fff;font-size:10px;border:none;cursor:pointer;display:flex;
  align-items:center;justify-content:center;opacity:0;transition:opacity .15s;}
.gal-item:hover .gal-del{opacity:1;}

.tpl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-height:360px;overflow-y:auto;}
.tpl-card{background:rgba(255,255,255,0.04);border:1px solid rgba(201,168,76,0.15);border-radius:8px;
  padding:8px;cursor:pointer;position:relative;}
.tpl-card:hover{border-color:var(--gold);}
.tpl-card .tpl-thumb{width:100%;aspect-ratio:.7;background:#fff;border-radius:5px;overflow:hidden;margin-bottom:6px;}
.tpl-card .tpl-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.tpl-card .tpl-name{font-size:11px;color:#fff;font-weight:600;line-height:1.2;}
.tpl-card .tpl-meta{font-size:9px;color:var(--text3);margin-top:2px;}
.tpl-card .tpl-del{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;
  background:rgba(220,50,50,0.85);color:#fff;font-size:10px;border:none;cursor:pointer;}

::-webkit-scrollbar{width:8px;height:8px;}
::-webkit-scrollbar-track{background:#0a0a0a;}
::-webkit-scrollbar-thumb{background:rgba(255,200,80,0.15);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:rgba(255,200,80,0.3);}
</style>
</head>
<body>
<header class="topbar"></header>

<div class="layout">
<aside class="sidebar">
  <div class="header-bar">
    <h1>📧 GENERADOR DE COMUNICACIÓN</h1>
    <p>Mails transaccionales y de comunicación</p>
  </div>

  <!-- Plataforma -->
  <div class="sec">
    <div class="sec-lbl">Plataforma</div>
    <div class="plat-grid" id="platGrid"><!-- las arma comms.js desde los datos de plataformas --></div>
  </div>

  <!-- Hero / Imagen -->
  <div class="sec">
    <div class="sec-lbl">Imagen Principal <small>opcional</small></div>
    <div id="heroImgPreview" style="margin-bottom:8px;"></div>
    <button class="btn btn-full" data-on-click="openImgModal">🖼 Seleccionar imagen</button>
  </div>

  <!-- Saludo + texto -->
  <div class="sec">
    <div class="sec-lbl">Encabezado</div>
    <div class="field">
      <label>Saludo (h2)</label>
      <input type="text" id="inpHello" value="¡Hola!" data-on-input="update">
    </div>
    <div class="field">
      <label>Texto principal <small style="color:var(--text3)">(acepta &lt;b&gt;, &lt;strong&gt;, ✅)</small></label>
      <textarea id="inpIntro" data-on-input="update"></textarea>
    </div>
  </div>

  <!-- Datos del usuario -->
  <div class="sec">
    <div class="sec-lbl">Datos del Usuario <small>opcional · caja gris</small></div>
    <div class="field">
      <label>Título de la caja</label>
      <input type="text" id="inpUserBoxTitle" value="Tus datos" data-on-input="update">
    </div>
    <div class="field">
      <label style="display:flex;justify-content:space-between;align-items:center;">
        <span>Filas (etiqueta · valor)</span>
        <button data-on-click="addUserRow" style="font-size:10px;padding:3px 8px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:5px;color:var(--green);cursor:pointer;font-family:inherit;">＋ Fila</button>
      </label>
      <div id="userRowsList" class="user-rows" style="margin-top:6px;"></div>
      <div style="font-size:9px;color:var(--text3);margin-top:4px;">Tip: <code>${PlayerId}</code>, <code>${UserName}</code> son placeholders del CRM.</div>
    </div>
    <label style="display:flex;gap:6px;align-items:center;font-size:11px;color:var(--text2);">
      <input type="checkbox" id="chkUserBox" checked data-on-change="update">
      Mostrar la caja de datos
    </label>
  </div>

  <!-- Bloque destacado -->
  <div class="sec">
    <div class="sec-lbl">Bloque Destacado <small>opcional · título + subtítulo</small></div>
    <label style="display:flex;gap:6px;align-items:center;font-size:11px;color:var(--text2);margin-bottom:8px;">
      <input type="checkbox" id="chkHighlight" checked data-on-change="update">
      Mostrar bloque destacado
    </label>
    <div class="field">
      <label>Título (h3)</label>
      <input type="text" id="inpHlTitle" value="💰 Hasta $600.000 en Bonos de Bienvenida" data-on-input="update">
    </div>
    <div class="field">
      <label>Subtítulo</label>
      <input type="text" id="inpHlText" value="Verificá tu correo y accedé a todas las promos 📩" data-on-input="update">
    </div>
  </div>

  <!-- Pasos -->
  <div class="sec">
    <div class="sec-lbl">Pasos numerados <small>opcional</small></div>
    <label style="display:flex;gap:6px;align-items:center;font-size:11px;color:var(--text2);margin-bottom:8px;">
      <input type="checkbox" id="chkSteps" checked data-on-change="update">
      Mostrar lista de pasos
    </label>
    <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
      <button data-on-click="addStep" style="font-size:10px;padding:3px 8px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:5px;color:var(--green);cursor:pointer;font-family:inherit;">＋ Paso</button>
    </div>
    <div id="stepsList" class="steps-rows"></div>
    <div style="font-size:9px;color:var(--text3);margin-top:6px;">Tip: acepta HTML (<code>&lt;a href="..."&gt;</code>) para incluir links.</div>
  </div>

  <!-- CTA -->
  <div class="sec">
    <div class="sec-lbl">CTA principal</div>
    <div class="field">
      <label>Texto del botón</label>
      <input type="text" id="inpCtaText" value="Depositá ahora" data-on-input="update">
    </div>
    <div class="field">
      <label>Link del botón</label>
      <div style="display:flex;gap:5px;">
        <input type="url" id="inpCtaUrl" placeholder="https://..." data-on-input="update" style="flex:1;">
        <button class="btn btn-sm" data-on-click="openLinkGen" data-click-1="s:inpCtaUrl" style="flex-shrink:0;border-color:rgba(255,72,0,0.3);color:var(--accent);">🔗</button>
      </div>
    </div>
  </div>

  <!-- Métodos de pago -->
  <div class="sec">
    <div class="sec-lbl">Métodos de pago <small>imagen final</small></div>
    <label style="display:flex;gap:6px;align-items:center;font-size:11px;color:var(--text2);">
      <input type="checkbox" id="chkPagos" checked data-on-change="update">
      Mostrar bloque de métodos de pago
    </label>
  </div>

  <!-- Links de plataforma -->
  <div class="sec">
    <div class="sec-lbl">Enlaces de la plataforma</div>
    <div class="field">
      <label>URL principal (logo)</label>
      <input type="url" id="linkLogo" data-on-input="update">
    </div>
    <div class="field">
      <label>URL del perfil</label>
      <input type="url" id="linkProfile" data-on-input="update">
    </div>
  </div>

  <!-- Exportar -->
  <div class="sec" style="background:rgba(34,197,94,0.04);">
    <div class="sec-lbl">Exportar</div>
    <button class="btn btn-full btn-success" data-on-click="downloadHTML" style="margin-bottom:6px;">⬇ Descargar HTML</button>
    <button class="btn btn-full" data-on-click="copyHTML" style="margin-bottom:6px;">📋 Copiar código</button>
    <button class="btn btn-full" data-on-click="saveTemplatePrompt">💾 Guardar como plantilla</button>
    <div id="cpSt" style="margin-top:6px;font-size:10px;text-align:center;"></div>
  </div>
</aside>

<main class="main">
  <div class="preview-toolbar">
    <h2>VISTA PREVIA</h2>
    <div class="preview-actions">
      <button class="btn btn-sm" data-on-click="update">🔄 Refrescar</button>
    </div>
  </div>
  <div class="preview-frame">
    <div class="preview-mail" id="previewMail"></div>
  </div>
</main>
</div>

<!-- ═══ MODAL IMAGEN ═══ -->
<div class="modal-overlay" id="imgModal">
  <div class="modal">
    <div class="modal-head">
      <h3>🖼 Seleccionar imagen</h3>
      <button class="btn btn-sm" data-on-click="closeImgModal">✕</button>
    </div>
    <div class="m-tabs">
      <button class="m-tab active" data-on-click="switchImgTab" data-click-1="s:upload" id="itab-upload">Subir nueva</button>
      <button class="m-tab" data-on-click="switchImgTab" data-click-1="s:gallery" id="itab-gallery">Galería guardada</button>
    </div>
    <div class="m-panel active" id="ipanel-upload">
      <div style="display:flex;flex-direction:column;gap:10px;">
        <label class="btn btn-full" style="cursor:pointer;">
          📁 Elegir archivo
          <input type="file" id="upFile" accept="image/*" style="display:none" data-on-change="doUpload" data-change-1="this">
        </label>
        <div id="upStatus" style="font-size:11px;color:var(--text3);text-align:center;"></div>
        <img id="upPreview" style="max-width:100%;border-radius:8px;display:none;">
      </div>
    </div>
    <div class="m-panel" id="ipanel-gallery">
      <div id="galGrid" class="gal-grid"></div>
      <div id="galEmpty" style="text-align:center;padding:30px;color:var(--text3);font-size:12px;display:none;">Galería vacía. Subí imágenes desde "Subir nueva".</div>
      <div style="margin-top:12px;display:flex;justify-content:flex-end;">
        <button class="btn btn-sm btn-success" data-on-click="applyGal" id="applyGalBtn" style="display:none;">Aplicar imagen seleccionada</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL PLANTILLAS ═══ -->
<div class="modal-overlay" id="tplModal">
  <div class="modal">
    <div class="modal-head">
      <h3>💾 Plantillas guardadas</h3>
      <button class="btn btn-sm" data-on-click="closeTplModal">✕</button>
    </div>
    <div id="tplGrid" class="tpl-grid"></div>
    <div id="tplEmpty" style="text-align:center;padding:30px;color:var(--text3);font-size:12px;display:none;">No hay plantillas guardadas. Usá "Guardar como plantilla" desde el sidebar.</div>
  </div>
</div>

<!-- ═══ MODAL GENERADOR DE LINKS ═══ -->
<div class="modal-overlay" id="linkModal">
  <div class="modal" style="width:540px;">
    <div class="modal-head">
      <h3>🔗 Generador de Links</h3>
      <button class="btn btn-sm" data-on-click="closeLinkGen">✕</button>
    </div>
    <div class="field">
      <label>Proyecto</label>
      <select id="lgProject" data-on-change="lgBuild"><!-- proyectos: MMP.lgProjects --></select>
    </div>
    <div class="field">
      <label>Acción</label>
      <select id="lgAction" data-on-change="lgBuild">
        <option value="/registration/register">Registrarse</option>
        <option value="/referafriend/invite">Invitar amigo</option>
        <option value="/?profile=1">Perfil</option>
        <option value="/?deposit=1">Depositar</option>
        <option value="/promotions">Promociones</option>
      </select>
    </div>
    <div class="field">
      <label>Resultado</label>
      <input type="text" id="lgResult" readonly style="font-family:monospace;font-size:11px;">
    </div>
    <div style="display:flex;gap:6px;justify-content:flex-end;">
      <button class="btn" data-on-click="closeLinkGen">Cancelar</button>
      <button class="btn btn-success" data-on-click="lgApply">Aplicar al campo</button>
    </div>
  </div>
</div>

<script src="/toolz/mailingmaker/marcas_js.php"></script><!-- datos de marcas del sitio (window.MM_PACK); vacío sin paquete -->
<script src="/toolz/mailingmaker/mm_pack.js?v=<?= (int)@filemtime(__DIR__ . '/mm_pack.js') ?>"></script>
<script src="/toolz/mailingmaker/comms.js?v=<?= (int)@filemtime(__DIR__ . '/comms.js') ?>"></script>
<?php require_once __DIR__ . '/brands_inc.php'; echo mm_brand_tags((int)$currentUser['id']); /* antes g7_brand_js(), que imprime un script en línea */ ?>
</body>
</html>