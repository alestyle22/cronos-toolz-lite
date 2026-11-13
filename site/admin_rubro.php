<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_rubro.php — Admin › Rubro, Campos de la ficha y Estados. Lógica en admin_rubro.js; acciones en rubro_api.php. Doc: toolz/RUBROS.md
   Textos nuevos en español neutro. Solo admins (admin.php ya lo exige). */
?>
<style>
.rb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.rb-card{background:var(--g7-bg-4);border:1.5px solid var(--g7-border-2);border-radius:12px;padding:14px;cursor:pointer;text-align:left;color:var(--g7-text);display:flex;flex-direction:column;gap:6px;transition:border-color .15s}
.rb-card:hover{border-color:var(--g7-border-3)}.rb-card.on{border-color:var(--rb,var(--g7-accent));box-shadow:0 0 0 1px var(--rb,var(--g7-accent)) inset}
.rb-card .ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;color:#fff;background:var(--rb,var(--g7-accent))}
.rb-card b{font-size:13.5px}.rb-card small{font-size:11.5px;color:var(--g7-text-4);line-height:1.45}
.rb-tag{display:inline-flex;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;background:var(--g7-accent-muted);color:var(--g7-accent)}
.rb-parts{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:6px}
.rb-part{display:flex;gap:10px;align-items:flex-start;background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:10px;padding:10px 12px;font-size:12.5px;cursor:pointer}
.rb-part input{accent-color:var(--g7-accent);margin-top:2px}.rb-part b{display:block;font-size:12.5px}.rb-part small{display:block;color:var(--g7-text-4);font-size:11.5px;line-height:1.45;margin-top:2px}
.rb-part.same{opacity:.55}
.rb-row{display:grid;grid-template-columns:200px 1fr 1fr 110px;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--g7-border)}.rb-row:first-child{border-top:0}
.rb-row>span{font-size:12px;color:var(--g7-text-3)}
.rb-fl{display:grid;grid-template-columns:24px minmax(0,1.4fr) 140px minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 0;border-top:1px solid var(--g7-border);font-size:12.5px}.rb-fl:first-child{border-top:0}
.rb-fl .h{cursor:grab;color:var(--g7-text-5)}.rb-fl small{color:var(--g7-text-4)}.rb-fl.drag{opacity:.4}
.rb-chips{display:flex;flex-wrap:wrap;gap:6px}
.rb-chip{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:20px;padding:5px 11px;cursor:pointer;color:var(--g7-text-3);user-select:none}
.rb-chip input{accent-color:var(--g7-accent);margin:0}.rb-chip.on{border-color:var(--g7-accent-border);background:var(--g7-accent-muted);color:var(--g7-accent)}
.rb-st{display:grid;grid-template-columns:24px 34px minmax(0,1fr) auto;gap:8px;align-items:center;padding:6px 0}
.rb-st input[type=color]{width:30px;height:30px;border:0;background:none;padding:0;cursor:pointer}
.rb-lock{font-size:11px;color:var(--g7-text-4)}
.rb-msg{font-size:12.5px;color:var(--g7-text-3);line-height:1.55}
.rb-warn{background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.3);border-radius:10px;padding:10px 12px;font-size:12.5px;margin-bottom:14px}
@media (max-width:760px){.rb-row{grid-template-columns:1fr 1fr}.rb-row>span{grid-column:1/-1}.rb-fl{grid-template-columns:24px 1fr auto}.rb-fl>:nth-child(3),.rb-fl>:nth-child(4){display:none}}
</style>
<section class="adm-section" id="sec-rubro">
  <div class="adm-title">Rubro</div>
  <div class="adm-sub">El tipo de negocio de esta plataforma: cambia nombres, estados, campos, contenidos, Inicio y herramientas. Cambiar de rubro nunca borra datos.</div>
  <div id="rb-body"><div class="adm-card"><div class="adm-card-body rb-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-campos">
  <div class="adm-title">Campos de la ficha</div>
  <div class="adm-sub">Los datos que se piden en cada ficha: tipos, campos propios y campos que no se usan.</div>
  <div id="rb-fields"><div class="adm-card"><div class="adm-card-body rb-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-estados">
  <div class="adm-title">Estados</div>
  <div class="adm-sub">Los estados de cada lista: nombre, color y orden. Los que tienen un candado se usan para calcular otras cosas: se pueden renombrar, pero no borrar.</div>
  <div id="rb-states"><div class="adm-card"><div class="adm-card-body rb-msg">Cargando…</div></div></div>
</section>
<script src="/toolz/admin_rubro.js?v=<?= @filemtime(__DIR__ . '/admin_rubro.js') ?>" defer></script>
