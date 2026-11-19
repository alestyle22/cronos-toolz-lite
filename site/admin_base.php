<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_base.php — Grupo "Red Cronos" del Admin: el sitio base (sitios y versiones, licencias, planes, banners, tickets y estadísticas)
   manejado con la interfaz de Toolz. Solo superadmin y solo donde _private/site.php tiene base_url y base_sso_secret (cronostoolz.com/toolz).
   Lógica en admin_base.js; los pedidos van por base_api.php, que los firma en el servidor. Respaldo: el panel propio del base por base_sso.php. Doc: toolz/BASE.md */
if (!g7_site('base_url') || strlen((string)g7_site('base_sso_secret')) < 32 || !g7_is_super((int)$currentUser['id'])) return;
require_once __DIR__ . '/base_client_lib.php';
$bzHead = function (string $title, string $sub, string $extra = '') {
    return '<div class="bz-head"><div><div class="adm-title">' . $title . '</div><div class="adm-sub" style="margin-bottom:0">' . $sub . '</div></div><div class="bz-head-act">' . $extra
        . '<button class="btn btn-ghost btn-sm" type="button" data-on-click="bzLoad" data-click-1="true"><i class="fa-solid fa-rotate"></i> Actualizar</button>'
        . '<a class="btn btn-ghost btn-sm" href="/toolz/base_sso.php" target="_blank" rel="noopener" title="El panel propio del sitio base, por si algo de acá no anda"><i class="fa-solid fa-up-right-from-square"></i> Abrir el panel del base</a></div></div>';
};
?>
<style>
.bz-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.bz-head-act{display:flex;gap:8px;flex-wrap:wrap}
.bz-msg{padding:12px 16px;font-size:12.5px;line-height:1.55;color:var(--g7-text-3)}
.bz-warn{color:#fbbf24}.bz-bad{color:#ef4444}.bz-muted{color:var(--g7-text-4);font-size:11.5px}
.bz-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px;background:var(--g7-bg-5);color:var(--g7-text-3);white-space:nowrap}
.bz-tag.ok{background:rgba(34,197,94,.12);color:#22c55e}.bz-tag.new{background:var(--g7-accent-muted);color:var(--g7-accent)}
.bz-tag.bad{background:rgba(239,68,68,.12);color:#ef4444}.bz-tag.warn{background:rgba(251,191,36,.13);color:#fbbf24}
.bz-tag.comercial{background:rgba(96,165,250,.13);color:#60a5fa}.bz-tag.soporte{background:rgba(167,139,250,.13);color:#a78bfa}
.bz-row{display:grid;grid-template-columns:minmax(0,1.6fr) 90px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 18px;border-top:1px solid var(--g7-border-3);font-size:12.5px}
.bz-row:first-child{border-top:none}.bz-row b{font-size:13px}.bz-row small{display:block;color:var(--g7-text-4);font-size:11px;margin-top:2px;word-break:break-all}
.bz-ver{font-family:var(--g7-font-mono,monospace);font-weight:600}
.bz-act{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;align-items:center}
.bz-use{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;font-size:11.5px;color:var(--g7-text-4)}.bz-use b{color:var(--g7-text);font-weight:600;font-size:12px}.bz-use .over,.bz-use .over b{color:#ef4444}
.bz-plan{padding:14px 18px;font-size:12.5px;line-height:1.6}.bz-plan details{margin-top:6px}.bz-plan summary{cursor:pointer;color:var(--g7-text-3)}
.bz-plan ul{margin:6px 0 0 18px;max-height:220px;overflow:auto;font-family:var(--g7-font-mono,monospace);font-size:11px;color:var(--g7-text-3)}
.bz-prog{margin-top:14px}.bz-prog-track{height:8px;border-radius:8px;background:var(--g7-bg-5);overflow:hidden}.bz-prog-track i{display:block;height:100%;width:0;background:var(--g7-accent);border-radius:8px;transition:width .6s ease}
.bz-prog-txt{display:flex;justify-content:space-between;font-size:11.5px;color:var(--g7-text-3);margin-top:6px}
.bz-steps{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.bz-steps span{font-size:11px;padding:3px 9px;border-radius:20px;background:var(--g7-bg-5);color:var(--g7-text-4)}.bz-steps span.on{background:var(--g7-accent-muted);color:var(--g7-accent)}.bz-steps span.done{color:#22c55e}
.bz-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.bz-f{display:grid;gap:4px}.bz-f.span2{grid-column:span 2}.bz-f.span4{grid-column:1/-1}
textarea.adm-input{resize:vertical;min-height:60px;line-height:1.45}
.bz-mods{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.bz-mods label{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:var(--g7-text-3);background:var(--g7-bg-4);border:1px solid var(--g7-border-3);padding:5px 11px;border-radius:20px;cursor:pointer;user-select:none}
.bz-mods input{accent-color:var(--g7-accent);margin:0}.bz-mods label.on{border-color:var(--g7-accent-border);background:var(--g7-accent-muted);color:var(--g7-accent)}
.bz-foot{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}
.bz-chk{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:var(--g7-text-2);cursor:pointer}.bz-chk input{accent-color:var(--g7-accent)}
.bz-bn{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 18px;border-top:1px solid var(--g7-border-3)}.bz-bn:first-child{border-top:none}
.bz-prev{display:flex;align-items:center;gap:14px;border-radius:12px;padding:14px 16px;border:1px solid var(--g7-accent-border);background:var(--g7-accent-muted)}
.bz-prev.aviso{border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.08)}.bz-prev.promo{border-color:rgba(168,85,247,.4);background:linear-gradient(110deg,rgba(168,85,247,.16),var(--g7-accent-muted))}
.bz-prev img{width:96px;height:58px;object-fit:cover;border-radius:8px;flex-shrink:0}.bz-prev b{display:block;font-size:13.5px}.bz-prev span{display:block;font-size:12px;color:var(--g7-text-3)}
.bz-prev .bb{margin-left:auto;background:var(--g7-accent);color:#000;font-weight:700;font-size:11.5px;padding:7px 12px;border-radius:8px;white-space:nowrap}.bz-prev.aviso .bb{background:#f59e0b}.bz-prev.promo .bb{background:#a855f7;color:#fff}
.bz-chips{display:flex;gap:6px;flex-wrap:wrap;padding:14px 18px 0}.bz-chips .sep{width:1px;background:var(--g7-border-3);margin:0 4px}
.bz-chip{border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-3);border-radius:20px;padding:5px 12px;font-size:11.5px;font-weight:600;cursor:pointer}
.bz-chip.on{background:var(--g7-accent);color:#000;border-color:var(--g7-accent)}.bz-chip small{opacity:.7;margin-left:3px}
.bz-filters{display:flex;gap:8px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--g7-border)}.bz-filters .adm-select{width:auto;min-width:160px}.bz-filters .adm-input{flex:1;min-width:200px;width:auto}
.bz-tk{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:12px;align-items:center;padding:11px 18px;border-top:1px solid var(--g7-border-3);cursor:pointer;font-size:12.5px}
.bz-tk:hover,.bz-tk.on{background:var(--g7-bg-4)}.bz-tk .id{font-family:var(--g7-font-mono,monospace);color:var(--g7-text-4)}
.bz-tk small{display:block;color:var(--g7-text-4);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.bz-tk.unread .tt::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-right:6px;vertical-align:1px}
.bz-tkd{padding:16px 18px;border-top:1px solid var(--g7-border-3);background:var(--g7-bg-2)}
.bz-body{white-space:pre-wrap;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:10px;padding:12px;font-size:12.5px;line-height:1.55}
.bz-shots{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.bz-shots img{max-width:220px;max-height:140px;border-radius:8px;border:1px solid var(--g7-border-3);cursor:zoom-in;background:var(--g7-bg-5)}
.bz-kv{display:grid;grid-template-columns:110px 1fr;gap:4px 12px;font-size:12px;margin-top:12px}.bz-kv span:nth-child(odd){color:var(--g7-text-4)}.bz-kv a{color:var(--g7-accent)}
.bz-msgx{border-radius:10px;padding:10px 12px;margin-top:8px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);font-size:12.5px}.bz-msgx.cronos{background:var(--g7-accent-muted);border-color:var(--g7-accent-border)}
.bz-msgx small{display:block;color:var(--g7-text-4);font-size:11px;margin-bottom:3px}.bz-msgx div{white-space:pre-wrap}
.bz-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:16px}.bz-kpis .adm-stat-val{font-size:24px;white-space:nowrap}
.bz-charts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px 18px}.bz-charts h4{font-size:12px;margin:0 0 8px;color:var(--g7-text-2)}.bz-charts svg{width:100%;height:auto;display:block}
.bz-n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.adm-table td small{display:block;color:var(--g7-text-4);font-size:11px}
.bz-badge{margin-left:auto;background:#ef4444;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;font-weight:700;line-height:16px}
.bz-tblw{overflow-x:auto}
.bz-tl{display:grid;grid-template-columns:96px minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 18px;border-top:1px solid var(--g7-border-3);font-size:12.5px}.bz-tl:first-child{border-top:none}
.bz-tl img,.bz-tl .ph{width:96px;height:54px;border-radius:8px;object-fit:cover;background:var(--g7-bg-5);display:flex;align-items:center;justify-content:center;color:var(--g7-accent);font-size:18px}
.bz-tl b{font-size:13px}.bz-tl small{display:block;color:var(--g7-text-4);font-size:11.5px;margin-top:2px}
.bz-tframe{margin-top:12px;border:1px solid var(--g7-border-3);border-radius:10px;overflow:hidden}.bz-tframe iframe{width:100%;height:520px;border:0;display:block;background:#fff}
@media (max-width:760px){.bz-tl{grid-template-columns:64px minmax(0,1fr)}.bz-tl img,.bz-tl .ph{width:64px;height:40px}.bz-tl .bz-act{grid-column:1/-1;justify-content:flex-start}}
@media (max-width:1000px){.bz-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.bz-charts{grid-template-columns:1fr}}
@media (max-width:760px){.bz-row{grid-template-columns:1fr}.bz-act{justify-content:flex-start}.bz-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bz-bn{grid-template-columns:1fr}.bz-tk{grid-template-columns:44px minmax(0,1fr)}.bz-tk>div:last-child{grid-column:2;text-align:left!important}}
</style>
<section class="adm-section" id="sec-base-sitios">
  <?= $bzHead('Sitios de la red', 'Las instalaciones de tus clientes: versión, estado y uso. Desde acá se revisan e instalan las versiones, de a un sitio por vez.') ?>
  <div id="bz-sitios"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-licencias">
  <?= $bzHead('Licencias', 'Plan, vencimiento y topes de cada agencia. Al guardar se le entrega al sitio; si no responde, se reintenta solo.') ?>
  <div id="bz-licencias"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-planes">
  <?= $bzHead('Planes', 'Topes y herramientas de cada plan. Los cambios les llegan a las agencias que lo usan.') ?>
  <div id="bz-planes"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-banners">
  <?= $bzHead('Banners de la red', 'Avisos arriba del Inicio o del Admin de las agencias. Se mandan solos al guardar.') ?>
  <div id="bz-banners"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-herramientas">
  <?= $bzHead('Catálogo de herramientas', 'Herramientas web que las agencias ven en Biblioteca › Catálogo Cronos y suman a su Biblioteca. Solo ven las publicadas.') ?>
  <div id="bz-herramientas"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-tickets">
  <?= $bzHead('Tickets', 'Soporte de las agencias y consultas comerciales de cronostoolz.com.') ?>
  <div id="bz-tickets"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<section class="adm-section" id="sec-base-red">
  <?= $bzHead('Estadísticas de la red', 'Salud y uso de cada agencia, y cómo evoluciona la red mes a mes. Solo totales: los datos de sus clientes no salen de su sitio.') ?>
  <div id="bz-red"><div class="adm-card"><div class="bz-msg">Cargando…</div></div></div>
</section>
<script type="application/json" data-global="BZ_CSRF"><?= json_encode(bc_csrf(), JSON_HEX_TAG | JSON_HEX_AMP) ?></script><script src="/toolz/assets/json-globals.js?v=1"></script>
<script src="/toolz/admin_base.js?v=<?= @filemtime(__DIR__ . '/admin_base.js') ?>" defer></script>
