<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_bienestar.php — Admin › Bienestar: privacidad (mínimo de personas, ánimo visible para todos, rankings), categorías de
   reconocimiento y moderación del muro, hábitos sugeridos, días de vacaciones (por defecto y por persona), avisos por defecto y
   la auditoría del módulo. Retos, encuestas y programas se gestionan en la propia pantalla (Bienestar › Gestión), donde también
   entran los admins de equipo. Lógica en admin_bienestar.js; acciones en bienestar/api.php (adm_*). Doc: toolz/BIENESTAR.md */
?>
<style>
.bw-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.bw-tab{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);color:var(--g7-text-3);border-radius:999px;padding:7px 14px;font:600 12.5px inherit;font-family:inherit;cursor:pointer}
.bw-tab.on{background:rgba(var(--g7-accent-rgb),.12);border-color:rgba(var(--g7-accent-rgb),.4);color:var(--g7-accent)}
.bw-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;align-items:start}
.bw-f{display:grid;gap:5px;margin-bottom:14px}
.bw-f>label,.bw-f>span{font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em}
.bw-f input[type=number],.bw-f input[type=text],.bw-f select{background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:9px 11px;font:inherit;font-size:13px;max-width:280px}
.bw-help{font-size:12px;color:var(--g7-text-4);line-height:1.5;margin:0}
.bw-chk{display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 0;cursor:pointer}
.bw-chk input{accent-color:var(--g7-accent);width:16px;height:16px}
.bw-cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px}
.bw-cats label{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;background:var(--g7-bg-4);border:1px solid var(--g7-border-2);font-size:13px;cursor:pointer}
.bw-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.bw-kpi{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:12px;padding:12px}
.bw-kpi b{display:block;font-size:22px;font-weight:800}
.bw-kpi small{font-size:11.5px;color:var(--g7-text-4)}
.bw-table{width:100%;border-collapse:collapse;font-size:12.5px}
.bw-table th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--g7-text-4);padding:8px 10px;border-bottom:1px solid var(--g7-border-2)}
.bw-table td{padding:8px 10px;border-bottom:1px solid var(--g7-border-2);vertical-align:middle}
.bw-table input[type=number]{width:80px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:7px;padding:6px 8px;font:inherit}
.bw-tpl{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--g7-border-2);font-size:13px}
.bw-tpl i.ic{width:32px;height:32px;display:grid;place-items:center;border-radius:10px}
.bw-tpl .t{flex:1;min-width:0}
.bw-tpl small{display:block;color:var(--g7-text-4);font-size:11.5px}
.bw-kudo{padding:10px 0;border-bottom:1px solid var(--g7-border-2);font-size:13px;display:flex;gap:10px;align-items:flex-start}
.bw-kudo .t{flex:1;min-width:0}
.bw-kudo small{color:var(--g7-text-4)}
.bw-sticky{position:sticky;bottom:0;background:var(--g7-bg-2);padding:12px 0;border-top:1px solid var(--g7-border-2);display:flex;gap:8px;align-items:center}
</style>
<section class="adm-section" id="sec-bienestar">
  <div class="adm-title">Bienestar</div>
  <div class="adm-sub">Reglas de privacidad, categorías de reconocimiento, hábitos sugeridos, vacaciones y avisos del módulo Bienestar. Los retos, encuestas y programas se arman en <a href="/toolz/bienestar/#gestion" style="color:var(--g7-accent)">Bienestar › Gestión</a>.</div>
  <div class="bw-tabs" role="tablist">
    <button class="bw-tab on" data-tab="resumen" role="tab"><i class="fa-solid fa-chart-simple"></i> Resumen</button>
    <button class="bw-tab" data-tab="reglas" role="tab"><i class="fa-solid fa-user-shield"></i> Privacidad y reglas</button>
    <button class="bw-tab" data-tab="kudos" role="tab"><i class="fa-solid fa-award"></i> Reconocimientos</button>
    <button class="bw-tab" data-tab="habitos" role="tab"><i class="fa-solid fa-seedling"></i> Hábitos sugeridos</button>
    <button class="bw-tab" data-tab="vacaciones" role="tab"><i class="fa-solid fa-umbrella-beach"></i> Vacaciones</button>
    <button class="bw-tab" data-tab="auditoria" role="tab"><i class="fa-solid fa-clock-rotate-left"></i> Auditoría</button>
  </div>
  <div id="bw-body"><div class="adm-card"><div class="adm-card-body">Cargando…</div></div></div>
</section>
<script src="/toolz/admin_bienestar.js?v=<?= @filemtime(__DIR__ . '/admin_bienestar.js') ?>" defer></script>
