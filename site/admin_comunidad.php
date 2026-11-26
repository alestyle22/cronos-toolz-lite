<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_comunidad.php — Admin › Comunidad: prender o apagar cada parte de la Comunidad (pregunta del día, encuestas, café,
   reconocimientos, tendencias, cumpleaños, aniversarios y las novedades automáticas). Lógica en admin_comunidad.js; acciones
   cm_parts_get / cm_parts_save en admin_api.php; las partes en comunidad_lib.php (CM_PARTS, cm_on). Doc: toolz/COMUNIDAD_PARTES.md */
?>
<style>
.cmp-grp{margin-bottom:18px}
.cmp-grp>h3{margin:0 0 8px;font-size:11px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.06em}
.cmp-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px}
.cmp-it{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid var(--g7-border-2);border-radius:12px;background:var(--g7-bg-4);cursor:pointer;transition:border-color .15s,opacity .15s}
.cmp-it:hover{border-color:var(--g7-border-3)}
.cmp-it.off{opacity:.62}
.cmp-it>i{flex:none;width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:rgba(var(--g7-accent-rgb),.12);color:var(--g7-accent);font-size:15px}
.cmp-it.off>i{background:var(--g7-bg-5,#2a2a2a);color:var(--g7-text-4)}
.cmp-t{flex:1;min-width:0}.cmp-t b{display:block;font-size:13.5px}.cmp-t small{display:block;margin-top:2px;font-size:12px;line-height:1.45;color:var(--g7-text-4)}
.cmp-sw{flex:none;position:relative;width:38px;height:22px;margin-top:6px}
.cmp-sw input{position:absolute;inset:0;margin:0;opacity:0;cursor:pointer}
.cmp-sw span{position:absolute;inset:0;border-radius:99px;background:var(--g7-border-3);transition:background .15s}
.cmp-sw span::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s}
.cmp-sw input:checked+span{background:var(--g7-accent)}
.cmp-sw input:checked+span::after{transform:translateX(16px)}
.cmp-sw input:focus-visible+span{outline:2px solid var(--g7-accent);outline-offset:2px}
.cmp-note{margin:4px 0 0;font-size:12px;color:var(--g7-text-4);line-height:1.5}
@media (prefers-reduced-motion:reduce){.cmp-sw span,.cmp-sw span::after,.cmp-it{transition:none}}
</style>
<section class="adm-section" id="sec-comunidad">
  <div class="adm-title">Comunidad</div>
  <div class="adm-sub">Elegí qué partes de la <a href="/toolz/comunidad.php" style="color:var(--g7-accent)">Comunidad</a> se usan en este sitio. Lo que apagues deja de publicarse, sale del feed y se esconden sus botones. Nada se borra: si lo volvés a prender, aparece de nuevo.</div>
  <div class="adm-card"><div class="adm-card-body" id="cmp-body">Cargando…</div></div>
</section>
<script src="/toolz/admin_comunidad.js?v=<?= @filemtime(__DIR__ . '/admin_comunidad.js') ?>" defer></script>
