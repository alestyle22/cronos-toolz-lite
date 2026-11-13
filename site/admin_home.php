<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php /* admin_home.php — Sección "Inicio y accesos" del panel Admin: bloques de accesos rápidos y secciones del Inicio. Lógica en admin_home.js, datos en admin_api.php (home_get / home_save). */ ?>
<style>
.hm-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.hm-head .adm-sub{margin-bottom:0}
.hm-act{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.hm-act .btn:disabled{opacity:.4;cursor:default}
.hm-dirty{font-size:11px;color:#fbbf24;display:none;align-items:center;gap:5px}
.hm-dirty.on{display:inline-flex}
.hm-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start}
@media (max-width:1180px){.hm-layout{grid-template-columns:1fr}}
.hm-card-sub{font-size:11.5px;color:var(--g7-text-4);margin:-4px 0 12px;line-height:1.5}
.hm-list{display:flex;flex-direction:column;gap:6px}
.hm-row{display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:10px;transition:border-color .15s,opacity .15s}
.hm-row:hover{border-color:var(--g7-border-2)}
.hm-row.off{opacity:.55}
.hm-row.dragging{opacity:.35;border-style:dashed}
.hm-tg{display:inline-flex;flex-shrink:0}
.hm-grip{color:var(--g7-text-5);cursor:grab;padding:4px 2px;font-size:12px;touch-action:none}
.hm-grip:active{cursor:grabbing}
.hm-ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
.hm-txt{flex:1;min-width:0}
.hm-txt b{display:block;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-txt small{display:block;font-size:10.5px;color:var(--g7-text-4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-tag{font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:6px;background:var(--g7-bg-5);color:var(--g7-text-3);white-space:nowrap}
.hm-tag.adm{background:rgba(167,139,250,.14);color:#a78bfa}
.hm-tag.tm{background:rgba(96,165,250,.14);color:#60a5fa}
.hm-mini{display:flex;flex-direction:column;gap:0}
.hm-mini button{background:none;border:0;color:var(--g7-text-5);cursor:pointer;font-size:9px;padding:1px 4px;line-height:1}
.hm-mini button:hover{color:var(--g7-accent)}
.hm-mini button:disabled{opacity:.25;cursor:default}
.hm-sec-t{flex:1;min-width:0}
.hm-sec-t b{font-size:12.5px}
.hm-sec-t small{display:block;font-size:10.5px;color:var(--g7-text-4);margin-top:1px}
.hm-sec-t .adm-input{margin-top:6px;height:30px;font-size:11.5px;max-width:300px}
.hm-subs{display:flex;gap:12px;flex-wrap:wrap;margin-top:7px}
.hm-subs label{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:var(--g7-text-3);cursor:pointer}
.hm-subs input{accent-color:var(--g7-accent)}
.hm-add{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.hm-empty{padding:22px;text-align:center;border:1px dashed var(--g7-border-3);border-radius:10px;color:var(--g7-text-4);font-size:12px}
.hm-line{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 0;border-top:1px solid var(--g7-border)}
.hm-line:first-child{border-top:0;padding-top:0}
.hm-line b{font-size:12.5px;display:block}
.hm-line small{font-size:11px;color:var(--g7-text-4)}

/* Vista previa: los mismos bloques del Inicio */
.hm-prev{position:sticky;top:70px}
.hm-prev-box{background:var(--g7-bg);border:1px solid var(--g7-border-2);border-radius:14px;padding:18px;max-height:calc(100vh - 140px);overflow:auto}
.hm-prev-h{font-size:13px;font-weight:600;display:flex;align-items:center;gap:7px;margin-bottom:12px}
.hm-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.hm-tc{border-radius:14px;overflow:hidden;color:var(--g7-text);display:flex;flex-direction:column;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);cursor:pointer;text-align:left;padding:0;font-family:inherit;transition:transform .2s,border-color .2s}
.hm-tc:hover{transform:translateY(-2px);border-color:var(--g7-accent-border)}
.hm-tc-art{height:84px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.hm-tc-art i{font-size:28px;position:relative;z-index:1}
.hm-tc-glow{position:absolute;inset:0;opacity:.35}
.hm-tc-badge{position:absolute;top:7px;right:7px;font:700 8.5px var(--g7-font-mono);border-radius:5px;padding:2px 5px;color:#000}
.hm-tc-b{padding:9px 11px 11px}
.hm-tc-b b{display:block;font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hm-tc-b small{display:block;font-size:10.5px;color:var(--g7-text-4);margin-top:2px;line-height:1.3}
.hm-order{margin-top:16px;display:flex;flex-direction:column;gap:4px}
.hm-order div{font-size:11px;color:var(--g7-text-4);display:flex;gap:7px;align-items:center}
.hm-order div i{width:14px;text-align:center;color:var(--g7-text-5)}
.hm-order div.on{color:var(--g7-text-2)}
.hm-order div.on i{color:var(--g7-accent)}

/* Editor de un bloque */
.hm-dlg{margin:max(16px,6vh) auto auto;width:min(720px,calc(100vw - 24px));max-height:calc(100dvh - 32px);background:var(--g7-bg-3);color:var(--g7-text);border:1px solid var(--g7-border-3);border-radius:16px;padding:0;overflow:hidden}
.hm-dlg::backdrop{background:rgba(0,0,0,.72)}
.hm-dlg-in{display:flex;flex-direction:column;max-height:calc(100dvh - 32px)}
.hm-dlg-h,.hm-dlg-f{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;border-bottom:1px solid var(--g7-border)}
.hm-dlg-f{border-bottom:0;border-top:1px solid var(--g7-border);justify-content:flex-end}
.hm-dlg-h b{font-size:14px}
.hm-dlg-b{padding:16px 18px;overflow:auto;display:grid;grid-template-columns:minmax(0,1fr) 170px;gap:18px}
@media (max-width:640px){.hm-dlg-b{grid-template-columns:1fr}.hm-dlg-b .hm-dprev{order:-1}}
.hm-f{margin-bottom:12px}
.hm-f .adm-input,.hm-f .adm-select{height:34px}
.hm-err{font-size:11px;color:#f87171;margin-top:4px;display:none}
.hm-f.bad .adm-input{border-color:#ef4444}
.hm-f.bad .hm-err{display:block}
.hm-icons{display:grid;grid-template-columns:repeat(auto-fill,minmax(34px,1fr));gap:4px;max-height:132px;overflow:auto;margin-top:6px;padding:6px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:9px}
.hm-icons button{height:32px;border-radius:7px;border:1px solid transparent;background:none;color:var(--g7-text-3);cursor:pointer;font-size:13px}
.hm-icons button:hover{color:var(--g7-text);background:var(--g7-bg-5)}
.hm-icons button.on{border-color:var(--g7-accent);color:var(--g7-accent);background:var(--g7-accent-muted)}
.hm-sw{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.hm-sw button{width:24px;height:24px;border-radius:7px;border:2px solid transparent;cursor:pointer}
.hm-sw button.on{border-color:var(--g7-text)}
.hm-sw input[type=color]{width:34px;height:28px;border:0;background:none;padding:0;cursor:pointer}
.hm-teams{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.hm-teams label{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--g7-text-2);background:var(--g7-bg-4);border:1px solid var(--g7-border-3);padding:5px 9px;border-radius:8px;cursor:pointer}
.hm-teams input{accent-color:var(--g7-accent)}
.hm-teams[hidden]{display:none}
.hm-chk{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:var(--g7-text-2);cursor:pointer}
.hm-chk input{accent-color:var(--g7-accent)}
.hm-dprev{position:sticky;top:0;align-self:start}
.hm-dprev .adm-label{margin-bottom:8px}
</style>

<!-- ── INICIO Y ACCESOS ── -->
<section class="adm-section" id="sec-home">
  <div class="hm-head">
    <div><div class="adm-title">Inicio y accesos</div><div class="adm-sub">Lo que ve cada persona al entrar: los accesos rápidos, qué secciones aparecen y en qué orden.</div></div>
    <div class="hm-act">
      <span class="hm-dirty" id="hm-dirty"><i class="fa-solid fa-circle" style="font-size:6px"></i> Cambios sin guardar</span>
      <a class="btn btn-ghost" href="/toolz/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Ver el Inicio</a>
      <button class="btn btn-ghost" type="button" id="hm-reset"><i class="fa-solid fa-rotate-left"></i> Volver a lo de fábrica</button>
      <button class="btn btn-primary" type="button" id="hm-save" disabled><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
    </div>
  </div>
  <div id="hm-body"><div class="hm-empty">Cargando…</div></div>
</section>

<dialog class="hm-dlg" id="hm-dlg" aria-labelledby="hm-dlg-t"></dialog>
<script src="/toolz/admin_home.js?v=<?= @filemtime(__DIR__ . '/admin_home.js') ?>" defer></script>
