<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php /* admin_ui.php — Secciones nuevas del panel admin. Lo incluye admin.php dentro de .adm-content. */ ?>
<style>
/* ── Base de las secciones nuevas ── */
.acc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:4px;flex-wrap:wrap}
.acc-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.acc-toolbar .adm-input,.acc-toolbar .adm-select{width:auto;min-width:150px}
.acc-muted{color:var(--g7-text-4);font-size:11px}
.acc-help{font-size:11px;color:var(--g7-text-4);line-height:1.5}
.acc-chip{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:var(--g7-bg-5);color:var(--g7-text-3);border:1px solid var(--g7-border-3);white-space:nowrap}
.acc-chip .dot{width:7px;height:7px;border-radius:50%}
.acc-chip.inherit{border-style:dashed;opacity:.85}
.acc-chips{display:flex;flex-wrap:wrap;gap:4px}
.acc-pill{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;white-space:nowrap}
.acc-pill.ok{background:rgba(34,197,94,.1);color:#22c55e}
.acc-pill.no{background:rgba(239,68,68,.1);color:#ef4444}
.acc-pill.admin{background:var(--g7-accent-muted);color:var(--g7-accent)}
.acc-av{width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#000}
.acc-user{display:flex;align-items:center;gap:9px;min-width:0}
.acc-user b{font-size:12px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.acc-user small{font-size:10px;color:var(--g7-text-4)}
.acc-row-click{cursor:pointer}
.acc-bar{height:4px;border-radius:3px;background:var(--g7-bg-5);overflow:hidden;width:70px;margin-top:4px}
.acc-bar i{display:block;height:100%;background:var(--g7-accent)}
.acc-empty{padding:28px;text-align:center;color:var(--g7-text-5);font-size:12px}
.acc-warn{display:flex;gap:10px;align-items:flex-start;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);color:#fbbf24;border-radius:10px;padding:10px 13px;font-size:11.5px;line-height:1.5;margin-bottom:14px}
.acc-info{display:flex;gap:10px;align-items:flex-start;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text-3);border-radius:10px;padding:10px 13px;font-size:11.5px;line-height:1.5;margin-bottom:14px}

/* Segmentado (modos, excepciones) */
.acc-seg{display:inline-flex;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;padding:2px;gap:2px}
.acc-seg button{background:none;border:none;color:var(--g7-text-4);font-size:10.5px;font-weight:700;padding:5px 9px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;font-family:var(--g7-font)}
.acc-seg button:hover{color:var(--g7-text)}
.acc-seg button.on{background:var(--g7-accent);color:#000}
.acc-seg button.on.m-off,.acc-seg button.on.e-deny{background:#ef4444;color:#fff}
.acc-seg button.on.m-admins{background:#a78bfa;color:#000}
.acc-seg button.on.m-superadmins{background:#fbbf24;color:#000}
.acc-seg button.on.m-teams{background:#60a5fa;color:#000}
.acc-seg button.on.e-allow{background:#22c55e;color:#000}
.acc-seg button.on.e-inherit{background:var(--g7-bg-5);color:var(--g7-text)}

/* Módulos */
.acc-mod-group{font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.1em;margin:18px 0 8px}
.acc-mod{display:grid;grid-template-columns:34px minmax(160px,1fr) auto 110px 34px;gap:12px;align-items:center;padding:10px 14px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:10px;margin-bottom:6px}
.acc-mod-ic{width:34px;height:34px;border-radius:9px;background:var(--g7-accent-muted);color:var(--g7-accent);display:flex;align-items:center;justify-content:center;font-size:13px}
.acc-mod-name{font-size:12.5px;font-weight:700}
.acc-mod-path{font-size:10px;color:var(--g7-text-5);font-family:ui-monospace,monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.acc-mod-count{font-size:11px;color:var(--g7-text-3);text-align:right}
@media(max-width:1100px){.acc-mod{grid-template-columns:34px 1fr 34px}.acc-mod .acc-seg,.acc-mod .acc-mod-count{grid-column:2/-1}}

/* Grillas de tarjetas */
.acc-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.acc-card{background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:14px 16px;cursor:pointer;transition:border-color .15s;position:relative;overflow:hidden}
.acc-card-sub{background:var(--g7-bg-2);border-style:dashed}
.acc-card:hover{border-color:var(--g7-accent-border)}
.acc-card-stripe{position:absolute;left:0;top:0;bottom:0;width:4px}
.acc-card h4{font-size:14px;font-weight:800;margin-bottom:3px;display:flex;align-items:center;gap:7px}
.acc-card p{font-size:11px;color:var(--g7-text-4);margin-bottom:10px;min-height:14px}
.acc-avs{display:flex;margin-bottom:10px}
.acc-avs>*{margin-right:-7px;border:2px solid var(--g7-bg-3)}
.acc-kv{display:flex;gap:14px;font-size:10px;color:var(--g7-text-4);margin-top:8px}
.acc-kv b{color:var(--g7-text);font-size:13px;display:block}

/* Matriz */
.acc-matrix-wrap{overflow:auto;max-height:calc(100vh - 230px);border:1px solid var(--g7-border-2);border-radius:12px;background:var(--g7-bg-3)}
.acc-matrix{border-collapse:separate;border-spacing:0;font-size:11px}
.acc-matrix th,.acc-matrix td{border-bottom:1px solid var(--g7-border);border-right:1px solid var(--g7-border);padding:0;text-align:center}
.acc-matrix thead th{position:sticky;top:0;background:var(--g7-bg-2);z-index:2;height:118px;vertical-align:bottom;padding:6px 2px;min-width:38px}
.acc-matrix thead th .vt{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;font-weight:700;color:var(--g7-text-3);white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
.acc-matrix thead th .vt i{transform:rotate(90deg);color:var(--g7-accent)}
.acc-matrix th.first,.acc-matrix td.first{position:sticky;left:0;background:var(--g7-bg-2);z-index:1;text-align:left;padding:7px 12px;min-width:190px}
.acc-matrix thead th.first{z-index:3}
.acc-cell{width:38px;height:34px;cursor:pointer;font-size:12px;transition:background .1s}
.acc-cell:hover{background:var(--g7-bg-5)}
.acc-cell.y{color:#22c55e}.acc-cell.n{color:#ef4444;opacity:.55}.acc-cell.a{color:var(--g7-accent);cursor:default;opacity:.6}
.acc-cell.ovr{box-shadow:inset 0 0 0 2px currentColor;opacity:1}
.acc-cell.core{cursor:default;color:var(--g7-text-5)}
.acc-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--g7-text-4);margin-bottom:10px;align-items:center}
.acc-legend span{display:inline-flex;align-items:center;gap:5px}

/* Drawer lateral */
.acc-drawer-bg{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:8800;opacity:0;pointer-events:none;transition:opacity .2s}
.acc-drawer-bg.open{opacity:1;pointer-events:auto}
.acc-drawer{position:fixed;top:0;right:0;bottom:0;width:min(620px,100vw);background:var(--g7-bg-2);border-left:1px solid var(--g7-border-3);z-index:8900;transform:translateX(100%);transition:transform .25s cubic-bezier(.2,.8,.2,1);display:flex;flex-direction:column}
.acc-drawer.open{transform:none}
.acc-dr-head{padding:16px 20px;border-bottom:1px solid var(--g7-border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.acc-dr-title{font-size:15px;font-weight:800;flex:1;min-width:0}
.acc-dr-title small{display:block;font-size:11px;font-weight:500;color:var(--g7-text-4);margin-top:2px}
.acc-dr-tabs{display:flex;gap:2px;padding:0 20px;border-bottom:1px solid var(--g7-border);flex-shrink:0;overflow-x:auto}
.acc-dr-tabs button{background:none;border:none;border-bottom:2px solid transparent;color:var(--g7-text-4);font-size:12px;font-weight:700;padding:11px 12px;cursor:pointer;white-space:nowrap;font-family:var(--g7-font)}
.acc-dr-tabs button.on{color:var(--g7-accent);border-bottom-color:var(--g7-accent)}
.acc-dr-tabs button:disabled{opacity:.35;cursor:not-allowed}
.acc-dr-body{flex:1;overflow-y:auto;padding:18px 20px}
.acc-dr-foot{padding:12px 20px;border-top:1px solid var(--g7-border);display:flex;gap:8px;align-items:center;flex-shrink:0}
.acc-field{margin-bottom:14px}
.acc-gen{margin-top:8px;padding:12px;border:1px solid var(--g7-border-3);border-radius:10px;background:var(--g7-bg-3)}
.acc-gen-pw{display:flex;gap:6px;align-items:center}.acc-gen-pw code{flex:1;font:600 15px var(--g7-font-mono,ui-monospace,monospace);letter-spacing:.04em;padding:8px 10px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;word-break:break-all;user-select:all}
.acc-gen-meter{height:5px;border-radius:5px;background:var(--g7-bg-5);margin-top:8px;overflow:hidden}.acc-gen-meter i{display:block;height:100%;border-radius:5px;transition:width .2s}
.acc-gen-len{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--g7-text-3)}.acc-gen-len input{flex:1;accent-color:var(--g7-accent)}
.acc-gen-opts{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:var(--g7-text-3)}.acc-gen-opts input{accent-color:var(--g7-accent)}
.acc-creds{position:fixed;inset:0;z-index:9500;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;padding:16px}.acc-creds.open{display:flex}
.acc-creds-box{width:min(520px,100%);background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:14px;padding:20px;box-shadow:0 30px 60px rgba(0,0,0,.5)}
.acc-creds-h{font-size:15px;font-weight:800;margin-bottom:4px}.acc-creds-h i{color:var(--g7-accent);margin-right:6px}
.uc-box{max-height:88vh;overflow:auto}.uc-any{margin-bottom:16px}.uc-row{gap:12px;flex-wrap:wrap}.uc-who{flex:1;min-width:200px}.uc-kinds{display:block;font-size:12px;margin-top:3px}.uc-list{margin-top:14px;border:1px solid var(--g7-border-3);border-radius:10px}
.uc-kind{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid var(--g7-border-3);font-size:13px}.uc-kind:last-child{border-bottom:0}.uc-kind>label{flex:1;cursor:pointer}.uc-kind>label i{width:18px;text-align:center;color:var(--g7-accent)}
.uc-note,.uc-items{flex-basis:100%;font-size:12px}.uc-items{max-height:190px;overflow:auto}.uc-items[hidden]{display:none}.uc-items label{display:block;padding:4px 0;cursor:pointer;overflow-wrap:anywhere}.uc-priv{display:block;margin-top:10px;font-size:13px;cursor:pointer}.uc-foot{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.acc-creds textarea{resize:vertical;font-size:13px;line-height:1.55}
.acc-field-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid var(--g7-border)}
.acc-field-row:last-child{border-bottom:none}
.acc-field-row .lbl{font-size:12.5px;font-weight:600}
.acc-field-row .ctl{flex-shrink:0;min-width:200px;max-width:320px;width:45%}
.acc-field-row .ctl.narrow{min-width:0;width:auto}
.acc-list-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--g7-border)}
.acc-list-row:last-child{border-bottom:none}
.acc-check{display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;user-select:none}
.acc-check input{accent-color:var(--g7-accent);width:15px;height:15px;cursor:pointer}
.acc-sub-h{font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin:18px 0 8px}
.acc-sub-h:first-child{margin-top:0}
.acc-danger{margin-top:24px;padding:14px;border:1px solid rgba(239,68,68,.25);border-radius:10px;background:rgba(239,68,68,.04)}
.acc-kw{display:inline-flex;align-items:center;gap:4px;font-family:ui-monospace,monospace;font-size:10.5px;background:var(--g7-bg-5);border:1px solid var(--g7-border-3);border-radius:6px;padding:2px 4px 2px 7px}
.acc-kw button{background:none;border:none;color:var(--g7-text-5);cursor:pointer;font-size:10px;padding:0 3px}
.acc-kw button:hover{color:#ef4444}
.acc-table-compact td,.acc-table-compact th{padding:8px 10px!important}
.acc-json{font-family:ui-monospace,monospace;font-size:10.5px;color:var(--g7-text-3);white-space:pre-wrap;word-break:break-all;background:var(--g7-bg-4);border-radius:6px;padding:6px 8px;margin-top:5px;display:none}
.acc-audit-row.open .acc-json{display:block}
.acc-audit-row{cursor:pointer}
.acc-dirty{display:none;font-size:10px;font-weight:700;color:#fbbf24}
.adm-card.dirty .acc-dirty{display:inline}
textarea.adm-input{resize:vertical;min-height:62px;line-height:1.45}

/* Responsive del layout del admin */
@media(max-width:860px){
  .adm-layout{grid-template-columns:1fr}
  .adm-nav{position:static;height:auto;flex-direction:row;flex-wrap:wrap;gap:4px;border-right:none;border-bottom:1px solid var(--g7-border)}
  .adm-nav>div:first-child,.adm-nav-sep{display:none}
  .adm-nav-item{width:auto}
  .adm-content{padding:18px 16px}
  .adm-stats{grid-template-columns:repeat(2,1fr)}
  .acc-field-row{flex-direction:column;align-items:stretch}
  .acc-field-row .ctl{width:100%;max-width:none}
  .acc-list-row{flex-wrap:wrap}
  .acc-pill{white-space:normal}
  .acc-seg{max-width:100%;overflow-x:auto}
  .acc-dr-head,.acc-dr-body,.acc-dr-foot{padding-left:16px;padding-right:16px}
  .acc-dr-tabs{padding:0 10px}
  .adm-table-wrap{overflow-x:auto}
  .adm-form-grid{grid-template-columns:1fr}
}
</style>

<!-- ── USUARIOS ── -->
<section class="adm-section" id="sec-users">
  <div class="acc-head">
    <div><div class="adm-title">Usuarios</div><div class="adm-sub">Cuentas, rol, equipos, marcas y accesos de cada persona</div></div>
    <button class="btn btn-primary" data-on-click="accUserOpen" data-click-1="n:0"><i class="fa-solid fa-user-plus"></i> Nuevo usuario</button>
  </div>
  <div class="acc-toolbar">
    <input class="adm-input" id="acc-u-q" placeholder="Buscar nombre, usuario o email…" data-on-input="accRenderUsers" style="min-width:240px">
    <select class="adm-select" id="acc-u-team" data-on-change="accRenderUsers"></select>
    <select class="adm-select" id="acc-u-brand" data-on-change="accRenderUsers"></select>
    <select class="adm-select" id="acc-u-status" data-on-change="accRenderUsers">
      <option value="active">Activos</option><option value="inactive">Inactivos</option><option value="">Todos</option>
    </select>
  </div>
  <div class="adm-table-wrap"><table class="adm-table">
    <thead><tr><th>Usuario</th><th>Rol</th><th>Equipos</th><th>Marcas</th><th>Accesos</th><th>Último acceso</th><th></th></tr></thead>
    <tbody id="acc-users-tbody"><tr><td colspan="7" class="acc-empty">Cargando…</td></tr></tbody>
  </table></div>
</section>

<!-- ── EQUIPOS ── -->
<section class="adm-section" id="sec-teams">
  <div class="acc-head">
    <div><div class="adm-title">Equipos</div><div class="adm-sub">Agrupan personas: comparten permisos sobre módulos y marcas</div></div>
    <button class="btn btn-primary" data-on-click="accTeamOpen" data-click-1="n:0"><i class="fa-solid fa-plus"></i> Nuevo equipo</button>
  </div>
  <div id="acc-teams-warn"></div>
  <div class="acc-cards" id="acc-teams-grid"></div>
</section>

<!-- ── MARCAS ── -->
<section class="adm-section" id="sec-brands">
  <div class="acc-head">
    <div><div class="adm-title">Marcas</div><div class="adm-sub">Separa el contenido por marca: cada persona ve solo las suyas</div></div>
    <button class="btn btn-primary" data-on-click="accBrandOpen" data-click-1="n:0"><i class="fa-solid fa-plus"></i> Nueva marca</button>
  </div>
  <div class="adm-card" id="acc-brand-settings-card">
    <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-sliders"></i> Separación por marca <span class="acc-dirty">· sin guardar</span></div>
      <button class="btn btn-primary btn-sm" data-on-click="accSaveSettingsCard" data-click-1="s:acc-brand-settings-card"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
    <div class="adm-card-body" id="acc-brand-settings"></div>
  </div>
  <div class="acc-cards" id="acc-brands-grid" style="margin-bottom:16px"></div>
  <div class="adm-card">
    <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-user-tag"></i> Quién ve cada marca</div>
      <span class="acc-muted">Tilda para asignar. Lo heredado de un equipo se edita en el equipo.</span></div>
    <div class="adm-card-body" style="padding:0;overflow:auto" id="acc-brand-access"></div>
  </div>
  <div class="adm-card">
    <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Cómo se reconoce la marca en los datos</div>
      <label class="acc-check"><input type="checkbox" id="acc-bv-only" data-on-change="accRenderBrandValues"> Solo valores sin marca</label></div>
    <div class="adm-card-body" style="padding:0">
      <div class="acc-info" style="margin:14px 18px"><i class="fa-solid fa-circle-info" style="margin-top:2px"></i><div>
        Cada marca tiene <b>palabras clave</b>. Un contenido pertenece a la marca si su proyecto o plataforma contiene alguna, sin importar mayúsculas, espacios ni signos.
        Ejemplo: la clave <code>mimarca</code> reconoce "Mi Marca PY", "mimarca.com.py" y "Mi Marca Honduras". Si un valor queda sin marca, asígnalo desde acá.</div></div>
      <div style="overflow:auto"><table class="adm-table acc-table-compact"><thead><tr><th>Valor en los datos</th><th>Dónde</th><th style="text-align:right">Registros</th><th>Marca detectada</th><th>Asignar</th></tr></thead>
      <tbody id="acc-bv-tbody"><tr><td colspan="5" class="acc-empty">Cargando…</td></tr></tbody></table></div>
    </div>
  </div>
</section>

<!-- ── MÓDULOS Y PERMISOS ── -->
<section class="adm-section" id="sec-modules">
  <div class="acc-head">
    <div><div class="adm-title">Módulos y permisos</div><div class="adm-sub">Quién puede entrar a cada herramienta y qué puede hacer adentro</div></div>
    <button class="btn btn-ghost" data-on-click="accCustomModuleOpen"><i class="fa-solid fa-puzzle-piece"></i> Agregar módulo</button>
  </div>
  <div class="acc-info"><i class="fa-solid fa-circle-info" style="margin-top:2px"></i><div>
    <b>Todos</b>: cualquier usuario activo. <b>Por equipos</b>: solo los equipos con permiso (se configuran en cada módulo o equipo). <?php if (g7_super_ok((int)$currentUser['id'])): ?><b>Solo superadmin</b>: los demás admins no entran y ni siquiera lo ven en esta lista. <?php endif; ?><b>Solo admins</b> y <b>Apagado</b>: nadie más; apagado además lo oculta del menú.
    Las <b>excepciones por usuario</b> le dan o le quitan acceso a una persona puntual, por encima de su equipo. Los admins globales entran a todo.</div></div>
  <div id="acc-modules-list"></div>
</section>

<!-- ── MATRIZ ── -->
<section class="adm-section" id="sec-matrix">
  <div class="acc-head"><div><div class="adm-title">Matriz de acceso</div><div class="adm-sub">Quién entra a qué, de un vistazo. Haz clic en una celda para crear o quitar una excepción.</div></div></div>
  <div class="acc-toolbar">
    <input class="adm-input" id="acc-m-q" placeholder="Filtrar personas…" data-on-input="accRenderMatrix">
    <select class="adm-select" id="acc-m-team" data-on-change="accRenderMatrix"></select>
    <label class="acc-check"><input type="checkbox" id="acc-m-restricted" data-on-change="accRenderMatrix"> Solo módulos restringidos</label>
    <label class="acc-check"><input type="checkbox" id="acc-m-admins" data-on-change="accRenderMatrix"> Mostrar admins</label>
  </div>
  <div class="acc-legend">
    <span><i class="fa-solid fa-check" style="color:#22c55e"></i> Entra</span>
    <span><i class="fa-solid fa-xmark" style="color:#ef4444"></i> No entra</span>
    <span><span style="width:14px;height:14px;border-radius:3px;box-shadow:inset 0 0 0 2px #22c55e;display:inline-block"></span> Excepción personal</span>
    <span>Clic: heredar → permitir → denegar</span>
  </div>
  <div class="acc-matrix-wrap" id="acc-matrix"></div>
</section>

<!-- ── CONFIGURACIÓN ── -->
<section class="adm-section" id="sec-config">
  <div class="adm-title">Configuración de la plataforma</div>
  <div class="adm-sub">Ajustes globales. La topbar, el logo y las notificaciones tienen su propia sección.</div>
  <div id="acc-settings"></div>
  <div class="adm-card" id="acc-raw-card">
    <div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-code"></i> Avanzado: todas las claves</div>
      <button class="btn btn-ghost btn-sm" data-on-click="accRawLoad" data-click-1="true"><i class="fa-solid fa-chevron-down"></i> Mostrar</button></div>
    <div class="adm-card-body" id="acc-raw" style="display:none"></div>
  </div>
</section>

<!-- ── AUDITORÍA ── -->
<section class="adm-section" id="sec-audit">
  <div class="acc-head"><div><div class="adm-title">Auditoría</div><div class="adm-sub">Cada cambio hecho desde este panel: quién, qué y cuándo</div></div>
    <button class="btn btn-ghost" data-on-click="accAuditLoad"><i class="fa-solid fa-rotate-right"></i> Actualizar</button></div>
  <div class="acc-toolbar">
    <input class="adm-input" id="acc-a-q" placeholder="Buscar…" data-on-keydown="accAuditLoad" data-keydown-key="Enter">
    <select class="adm-select" id="acc-a-user" data-on-change="accAuditLoad"></select>
  </div>
  <div class="adm-card"><div class="adm-card-body" id="acc-audit" style="padding:0 18px"></div></div>
</section>

<!-- ── RESPALDO ── -->
<section class="adm-section" id="sec-backup">
  <div class="adm-title">Respaldo de configuración</div>
  <div class="adm-sub">Guarda o restaura ajustes, marcas, equipos y permisos. No incluye contenido ni usuarios.</div>
  <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-download"></i> Exportar</div></div>
    <div class="adm-card-body"><p class="acc-help" style="margin-bottom:12px">Descarga un archivo JSON con toda la configuración actual. Conviene hacerlo antes de cambios grandes.</p>
      <button class="btn btn-primary" data-on-click="accExport"><i class="fa-solid fa-file-arrow-down"></i> Descargar respaldo</button></div></div>
  <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-upload"></i> Importar</div></div>
    <div class="adm-card-body">
      <input type="file" accept="application/json,.json" id="acc-import-file" data-on-change="accImportRead" data-change-1="this" class="adm-input" style="max-width:360px">
      <div id="acc-import-preview" style="margin-top:14px"></div>
    </div></div>
</section>

<!-- Drawer compartido -->
<div class="acc-drawer-bg" id="acc-drawer-bg" data-on-click="accDrawerClose"></div>
<aside class="acc-drawer" id="acc-drawer" aria-hidden="true">
  <div class="acc-dr-head"><div class="acc-dr-title" id="acc-dr-title"></div>
    <button class="adm-modal-close" data-on-click="accDrawerClose" title="Cerrar">✕</button></div>
  <div class="acc-dr-tabs" id="acc-dr-tabs"></div>
  <div class="acc-dr-body" id="acc-dr-body"></div>
  <div class="acc-dr-foot" id="acc-dr-foot"></div>
</aside>
