<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php /* admin_clientes.php — Sección Clientes del panel Admin (cronos). La lógica está en admin_clientes.js y los datos en clientes_api.php. */ ?>
<style>
.cli-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.cli-search{flex:1;min-width:220px;position:relative}
.cli-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--g7-text-4);font-size:12px}
.cli-search .adm-input{padding-left:32px;height:36px}
.cli-chips{display:flex;gap:6px;flex-wrap:wrap}
.cli-chip{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);color:var(--g7-text-3);border-radius:999px;padding:6px 12px;font-size:11.5px;font-weight:600;cursor:pointer;transition:all .15s;display:inline-flex;gap:6px;align-items:center}
.cli-chip:hover{color:var(--g7-text);border-color:var(--g7-border-3)}
.cli-chip.on{background:var(--g7-accent-muted);border-color:var(--g7-accent-border);color:var(--g7-accent)}
.cli-chip b{font-weight:700;opacity:.75}
.cli-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.cli-card{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:var(--g7-radius-lg);padding:16px;cursor:pointer;display:flex;flex-direction:column;gap:12px;transition:border-color .15s,transform .15s}
.cli-card:hover{border-color:var(--g7-accent-border);transform:translateY(-2px)}
.cli-card-top{display:flex;gap:12px;align-items:center;min-width:0}
.cli-logo{width:48px;height:48px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#fff;overflow:hidden;background:var(--g7-bg-5)}
.cli-logo img{width:100%;height:100%;object-fit:contain;background:#fff}
.cli-logo.lg{width:64px;height:64px;font-size:24px;border-radius:16px}
.cli-av-wrap{position:relative;flex-shrink:0}
.cli-av-btn{position:absolute;right:-6px;bottom:-6px;width:28px;height:28px;border-radius:50%;border:2px solid var(--g7-bg-2);background:var(--g7-accent);color:#fff;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform .15s}
.cli-av-btn:hover{transform:scale(1.08)}
.cli-av-btn:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.cli-av-menu{position:absolute;top:calc(100% + 10px);left:0;z-index:30;min-width:170px;padding:6px;border-radius:12px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);box-shadow:0 12px 30px rgba(0,0,0,.45);display:flex;flex-direction:column}
.cli-av-menu button{display:flex;align-items:center;gap:9px;padding:8px 10px;border:none;background:none;border-radius:8px;cursor:pointer;text-align:left;font-size:12.5px;color:var(--g7-text-2)}
.cli-av-menu button:hover{background:var(--g7-bg-5);color:var(--g7-text)}
.cli-mat-link{display:flex;align-items:center;gap:14px;padding:14px 16px;margin-bottom:14px;border-radius:14px;border:1px solid var(--g7-accent-border);background:rgba(var(--g7-accent-rgb),.07);color:var(--g7-text);text-decoration:none;transition:background .15s}
.cli-mat-link:hover{background:rgba(var(--g7-accent-rgb),.12)}
.cli-mat-link>i{font-size:20px;color:var(--g7-accent)}
.cli-mat-link span{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.cli-mat-link small{color:var(--g7-text-3);font-size:11.5px}
.cli-name{font-size:14px;font-weight:700;color:var(--g7-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cli-meta{font-size:11.5px;color:var(--g7-text-4);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cli-card-foot{display:flex;justify-content:space-between;align-items:center;font-size:11px;color:var(--g7-text-4)}
.cli-st{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}
.cli-st::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.cli-st.potencial{color:#60a5fa;background:rgba(96,165,250,.12)}
.cli-st.activo{color:#4ade80;background:rgba(74,222,128,.12)}
.cli-st.pausado{color:#f59e0b;background:rgba(245,158,11,.12)}
.cli-st.terminado{color:var(--g7-text-3);background:rgba(255,255,255,.06)}
.cli-st.en_curso{color:var(--g7-accent);background:var(--g7-accent-muted)}
.cli-st.pendiente{color:#60a5fa;background:rgba(96,165,250,.12)}
.cli-st.vencida{color:#f87171;background:rgba(248,113,113,.12)}
.cli-empty{text-align:center;padding:60px 20px;border:1px dashed var(--g7-border-3);border-radius:var(--g7-radius-lg);color:var(--g7-text-3)}
.cli-empty i{font-size:30px;color:var(--g7-accent);margin-bottom:12px;display:block}
.cli-empty p{font-size:12.5px;margin:6px 0 16px}
.cli-back{background:none;border:0;color:var(--g7-text-3);font-size:12px;cursor:pointer;padding:0;margin-bottom:14px;display:inline-flex;gap:6px;align-items:center}
.cli-back:hover{color:var(--g7-text)}
.cli-head{display:flex;gap:16px;align-items:center;margin-bottom:18px;flex-wrap:wrap}
.cli-head-main{flex:1;min-width:200px}
.cli-head-main .adm-title{margin-bottom:6px}
.cli-head-act{display:flex;gap:8px;align-items:center}
.cli-head-act .btn:disabled{opacity:.4;cursor:default}
.cli-tabs{display:flex;gap:4px;border-bottom:1px solid var(--g7-border-2);margin-bottom:18px;overflow-x:auto}
.cli-tab{background:none;border:0;border-bottom:2px solid transparent;color:var(--g7-text-3);padding:9px 14px;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap;margin-bottom:-1px}
.cli-tab:hover{color:var(--g7-text)}
.cli-tab.on{color:var(--g7-accent);border-bottom-color:var(--g7-accent)}
.cli-tab:disabled{opacity:.35;cursor:not-allowed}
.cli-tab b{font-weight:600;opacity:.6;margin-left:4px}
.cli-pane{display:none}.cli-pane.on{display:block}
.cli-f{display:flex;flex-direction:column}
.cli-f.wide{grid-column:1/-1}
.cli-f textarea.adm-input{min-height:80px;resize:vertical;line-height:1.5}
.cli-req{color:var(--g7-accent)}
.cli-colors{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.cli-color{display:flex;align-items:center;gap:6px;background:var(--g7-bg-5);border:1px solid var(--g7-border-2);border-radius:10px;padding:4px 6px 4px 4px}
.cli-color input[type=color]{width:30px;height:30px;border:0;padding:0;background:none;cursor:pointer;border-radius:8px}
.cli-color input[type=text]{width:78px;background:transparent;border:0;color:var(--g7-text);font:600 12px var(--g7-font);outline:none;text-transform:uppercase}
.cli-color button{background:none;border:0;color:var(--g7-text-4);cursor:pointer;font-size:11px}
.cli-color button:hover{color:#ef4444}
.cli-row{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:var(--g7-radius);padding:14px;margin-bottom:10px}
.cli-row-grid{display:grid;grid-template-columns:2fr 1.2fr 1.2fr 1fr 1fr;gap:10px;align-items:end}
.cli-row-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px}
.cli-row-foot .adm-input{flex:1}
.cli-drop{border:1.5px dashed var(--g7-border-3);border-radius:var(--g7-radius-lg);padding:22px;text-align:center;color:var(--g7-text-3);font-size:12.5px;margin-bottom:16px;transition:all .15s}
.cli-drop.over{border-color:var(--g7-accent);background:var(--g7-accent-muted);color:var(--g7-text)}
.cli-drop i{font-size:22px;color:var(--g7-accent);display:block;margin-bottom:8px}
.cli-drop-act{display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;flex-wrap:wrap}
.cli-drop-act .adm-select{width:auto}
.cli-files{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
.cli-file{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:var(--g7-radius);overflow:hidden;display:flex;flex-direction:column}
.cli-file-th{height:110px;display:flex;align-items:center;justify-content:center;background:repeating-conic-gradient(#1a1a1a 0 25%,#111 0 50%) 0 0/16px 16px;color:var(--g7-text-4);font-size:26px}
.cli-file-th img{max-width:100%;max-height:100%;object-fit:contain}
.cli-file-b{padding:9px 10px;display:flex;flex-direction:column;gap:6px}
.cli-file-n{font-size:12px;font-weight:600;color:var(--g7-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cli-file-m{font-size:10.5px;color:var(--g7-text-4)}
.cli-file-a{display:flex;gap:4px;align-items:center}
.cli-file-a .adm-select{padding:3px 6px;font-size:11px;flex:1}
.cli-note{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:var(--g7-radius);padding:12px 14px;margin-bottom:8px}
.cli-note-h{display:flex;justify-content:space-between;font-size:11px;color:var(--g7-text-4);margin-bottom:6px}
.cli-note-b{font-size:12.5px;color:var(--g7-text-2);white-space:pre-wrap;line-height:1.55}
.cli-hint{font-size:12px;color:var(--g7-text-4);padding:24px;text-align:center;border:1px dashed var(--g7-border-3);border-radius:var(--g7-radius)}
/* Facturas con el formato de Cronos */
.cx-bar{background:#111;border:1px solid var(--g7-border-2);border-radius:14px;padding:14px 18px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.cx-bar-t{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-weight:700;font-size:13.5px;color:var(--g7-text)}
.cx-bar-t > span{color:#FF7F00;background:rgba(255,127,0,.1);padding:4px 10px;border-radius:6px;font-size:11.5px;border:1px solid rgba(255,127,0,.3)}
.cx-bar-t > span.cx-closed{color:#f87171;background:rgba(248,113,113,.1);border-color:rgba(248,113,113,.3)}
.cx-bar-a{display:flex;gap:8px;flex-wrap:wrap}
.cx-hist{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:10px;padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:8px;overflow-x:auto}
.cx-hl{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--g7-text-4);font-weight:700;white-space:nowrap}
.cx-tab{background:#1c1c1c;border:1px solid #2e2e2e;color:#ccc;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .15s}
.cx-drafts{border-style:dashed;border-color:var(--g7-accent-border,#555)}.cx-drafts .cx-tab{text-decoration:none}.cx-drafts .cx-small{margin:0 0 0 4px;white-space:nowrap}
.cx-tab:hover{border-color:#FF7F00;color:#fff}
.cx-tab.on{background:rgba(255,127,0,.15);border-color:#FF7F00;color:#FF7F00}
.cx-tab b{color:#10b981}.cx-tab.vencida{box-shadow:inset 0 -2px 0 #ef4444}
.cx-ws{display:grid;grid-template-columns:340px minmax(0,1fr);gap:20px;align-items:start}
.cx-side{background:#111;border:1px solid var(--g7-border-2);border-radius:14px;padding:18px;display:flex;flex-direction:column;gap:12px;position:sticky;top:70px}
.cx-side h3{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#FF7F00;font-weight:700;margin:0}
.cx-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cx-state{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--g7-text-3)}
.cx-calc{font-size:12px;color:var(--g7-text-3);margin-top:-4px;min-height:16px}
.cx-calc b{color:var(--g7-text)}
.cx-small{font-size:11.5px;color:var(--g7-text-4);text-decoration:none;margin-top:5px;display:inline-block}
a.cx-small:hover{color:var(--g7-accent)}
.cx-act{display:flex;gap:8px;align-items:center}
.cx-act .btn-primary{flex:1;justify-content:center}
.cx-paybox{border-top:1px solid var(--g7-border-2);padding-top:12px}
.cx-paybox h3{margin-bottom:8px}
.cx-payrow{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--g7-text-3);padding:4px 0}
.cx-payrow i{color:#4ade80}.cx-payrow b{color:var(--g7-text)}.cx-payrow span{flex:1}
.cx-partial{margin-top:10px;font-size:12px;color:var(--g7-text-3)}
.cx-partial summary{cursor:pointer}
.cx-prev{min-width:0}
.cx-prev-h{display:flex;justify-content:space-between;font-size:11.5px;color:var(--g7-text-4);margin-bottom:8px}
.cx-prev iframe{width:100%;border:0;display:block;min-height:600px;background:transparent}
@media (max-width:1100px){.cx-ws{grid-template-columns:1fr}.cx-side{position:static}}
.cx-dlg{margin:auto;width:min(760px,calc(100vw - 24px));max-height:calc(100dvh - 32px);background:var(--g7-bg-3,#111);color:var(--g7-text);border:1px solid var(--g7-border-3);border-radius:16px;padding:0;overflow:hidden}
.cx-dlg::backdrop{background:rgba(0,0,0,.7)}
.cx-dlg-in{display:flex;flex-direction:column;max-height:calc(100dvh - 32px)}
.cx-dlg-h,.cx-dlg-f{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--g7-border-2)}
.cx-dlg-f{border-bottom:0;border-top:1px solid var(--g7-border-2);justify-content:flex-end}
.cx-dlg-b{padding:6px 20px 20px;overflow:auto}
.cx-dlg-b h4{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#FF7F00;margin:18px 0 10px}
.cx-dlg-b h4 small{text-transform:none;letter-spacing:0;color:var(--g7-text-4);font-weight:500}
.cx-meth{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:10px;padding:10px;margin-bottom:10px;display:flex;flex-direction:column;gap:6px}
.cx-mitem{display:grid;grid-template-columns:1fr 1.6fr auto;gap:6px}
@media (max-width:900px){.cli-row-grid{grid-template-columns:1fr 1fr}.cli-row-grid .cli-f:first-child{grid-column:1/-1}}
</style>

<!-- ── CLIENTES ── -->
<section class="adm-section" id="sec-clientes">
  <div id="cli-list-view">
    <div class="cli-head">
      <div class="cli-head-main"><div class="adm-title"><?= htmlspecialchars(g7_term('client', 'p')) ?></div><div class="adm-sub" id="cli-count" style="margin-bottom:0">Cargando…</div></div>
      <?php if (is_file(__DIR__ . '/facturacion/index.php')): // la edición Lite no trae facturas ?><button class="btn btn-ghost" data-on-click="cliOpenBilling"><i class="fa-solid fa-sliders"></i> Datos de facturación</button><?php endif; ?>
      <button class="btn btn-primary" data-on-click="cliOpen" data-click-1="n:0"><i class="fa-solid fa-plus"></i> Nuevo <?= htmlspecialchars(g7_term('client', 's', true)) ?></button>
    </div>
    <div class="cli-bar">
      <label class="cli-search"><i class="fa-solid fa-magnifying-glass"></i><input class="adm-input" id="cli-q" placeholder="Buscar por nombre, contacto o email" data-on-input="cliRenderList"></label>
      <div class="cli-chips" id="cli-chips"></div>
    </div>
    <div class="cli-chips" id="cli-typechips" style="margin:-8px 0 16px"></div>
    <div id="cli-grid"></div>
  </div>
  <div id="cli-detail-view" hidden></div>
</section>
<?php require_once __DIR__ . '/custom_fields_lib.php'; cf_install(getDB()); ?>
<script type="application/json" data-global="CLI_META"><?= json_encode(['st' => st_list('clients'), 'svc' => st_list('services'), 'types' => cf_client_types(), 'fields' => cf_fields(getDB(), 'client', false),
  'hidden' => cf_client_hidden(), 'concepts' => g7_cfg_json('billing_concepts', [])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="/toolz/admin_clientes.js?v=<?= @filemtime(__DIR__ . '/admin_clientes.js') ?>" defer></script>
