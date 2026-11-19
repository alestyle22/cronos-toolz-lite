<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_mails.php — Admin › Mails: los emails que Cronos manda a las personas del equipo (seguridad, tareas, tickets y
   Portal de Solicitudes), con sus textos editables, el diseño común y el registro de enviados. Lógica en admin_mails.js;
   acciones en mails_api.php; plantillas en mail_tpl_lib.php. Doc: toolz/MAILS.md */
?>
<style>
.ml-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.ml-tab{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);color:var(--g7-text-3);border-radius:999px;padding:7px 14px;font:600 12.5px inherit;font-family:inherit;cursor:pointer}
.ml-tab.on{background:rgba(var(--g7-accent-rgb),.12);border-color:rgba(var(--g7-accent-rgb),.4);color:var(--g7-accent)}
.ml-grid{display:grid;grid-template-columns:minmax(220px,280px) minmax(0,1fr);gap:16px;align-items:start}
.ml-list{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;position:sticky;top:80px;max-height:calc(100vh - 110px);overflow-y:auto}
.ml-g{padding:10px 14px 6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--g7-text-5);background:var(--g7-bg-2);border-bottom:1px solid var(--g7-border-2)}
.ml-it{display:flex;justify-content:space-between;gap:8px;align-items:center;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid var(--g7-border-2);color:var(--g7-text-2);padding:9px 14px;font:500 12.5px inherit;font-family:inherit;cursor:pointer}
.ml-it:hover{background:var(--g7-bg-4)}
.ml-it.on{background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);box-shadow:inset 3px 0 0 var(--g7-accent)}
.ml-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:2px 6px;border-radius:5px;background:rgba(var(--g7-accent-rgb),.15);color:var(--g7-accent);flex-shrink:0}
.ml-f{display:grid;gap:5px;margin-bottom:12px}
.ml-f label{font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em}
.ml-f input,.ml-f textarea,.ml-f select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:9px 11px;font:inherit;font-size:13px;width:100%}
.ml-f textarea{min-height:96px;resize:vertical;line-height:1.5}
.ml-f input:focus,.ml-f textarea:focus{outline:none;border-color:var(--g7-accent)}
.ml-vars{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 14px}
.ml-var{background:var(--g7-bg-4);border:1px dashed var(--g7-border-3);color:var(--g7-text-3);border-radius:6px;padding:3px 8px;font:12px var(--g7-font-mono,monospace);cursor:pointer}
.ml-var:hover{border-color:var(--g7-accent);color:var(--g7-accent)}
.ml-acts{display:flex;gap:8px;flex-wrap:wrap}
.ml-prev{border:1px solid var(--g7-border-2);border-radius:12px;overflow:hidden;background:var(--g7-bg-2)}
.ml-subj{padding:10px 14px;font-size:12.5px;border-bottom:1px solid var(--g7-border-2);color:var(--g7-text-3)}
.ml-subj b{color:var(--g7-text)}
.ml-prev iframe{display:block;width:100%;height:520px;border:0;background:#000}
.ml-meta{font-size:12px;color:var(--g7-text-4);margin:-4px 0 14px;line-height:1.5}
.ml-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:16px;align-items:start}
.ml-log{width:100%;border-collapse:collapse;font-size:12.5px}
.ml-log th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--g7-text-4);padding:8px 10px;border-bottom:1px solid var(--g7-border-2)}
.ml-log td{padding:8px 10px;border-bottom:1px solid var(--g7-border-2);vertical-align:top}
.ml-ok{color:#22c55e}.ml-bad{color:#ef4444}
.ml-others{margin-top:16px}
.ml-others div{padding:9px 14px;border-bottom:1px solid var(--g7-border-2);font-size:12px;line-height:1.5}
.ml-others b{display:block;color:var(--g7-text-2)}
.ml-others small{color:var(--g7-text-4)}
@media (max-width:1400px){.ml-two{grid-template-columns:1fr}}
@media (max-width:1100px){.ml-grid{grid-template-columns:1fr}.ml-list{position:static;max-height:280px}.ml-others{max-height:none!important}}
@media (max-width:820px){.ml-prev iframe{height:440px}.ml-log .ml-hide{display:none}}
</style>
<section class="adm-section" id="sec-mails">
  <div class="adm-title">Mails</div>
  <div class="adm-sub">Los emails que la plataforma manda a las personas del equipo: avisos de seguridad, de tareas, de tickets y del Portal de Solicitudes. Cambia sus textos, el diseño y revisa qué se mandó.</div>
  <div class="ml-tabs" role="tablist">
    <button class="ml-tab on" data-tab="tpl" role="tab"><i class="fa-solid fa-envelope-open-text"></i> Plantillas</button>
    <button class="ml-tab" data-tab="look" role="tab"><i class="fa-solid fa-palette"></i> Diseño</button>
    <button class="ml-tab" data-tab="log" role="tab"><i class="fa-solid fa-paper-plane"></i> Enviados</button>
  </div>
  <div id="ml-body"><div class="adm-card"><div class="adm-card-body">Cargando…</div></div></div>
</section>
<script src="/toolz/admin_mails.js?v=<?= @filemtime(__DIR__ . '/admin_mails.js') ?>" defer></script>
