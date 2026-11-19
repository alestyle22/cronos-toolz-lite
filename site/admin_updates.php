<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php /* admin_updates.php — Sección "Actualizaciones" del panel Admin. Lógica en admin_updates.js, acciones en admin_updates_api.php. */ ?>
<style>
.upd-row{display:grid;grid-template-columns:minmax(0,1.2fr) 110px minmax(0,1.6fr) auto;gap:12px;align-items:center;padding:12px 16px;border-top:1px solid var(--g7-border-3);font-size:12.5px}
.upd-row:first-child{border-top:none}
.upd-row b{display:block;font-size:13px}
.upd-row small{display:block;color:var(--g7-text-4);font-size:11px;margin-top:2px}
.upd-ver{font-family:var(--g7-font-mono,monospace);font-weight:600}
.upd-act{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.upd-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--g7-bg-5);color:var(--g7-text-3)}
.upd-tag.ok{background:rgba(34,197,94,.12);color:#22c55e}
.upd-tag.new{background:var(--g7-accent-muted);color:var(--g7-accent)}
.upd-tag.bad{background:rgba(239,68,68,.12);color:#ef4444}
.upd-rel{padding:12px 16px;border-top:1px solid var(--g7-border-3);font-size:12.5px;line-height:1.5}
.upd-rel:first-child{border-top:none}
.upd-rel b{font-family:var(--g7-font-mono,monospace)}
.upd-rel small{color:var(--g7-text-4);margin-left:8px;font-size:11px}
.upd-plan{padding:14px 16px;border-top:1px solid var(--g7-border-3);font-size:12.5px;line-height:1.6}
.upd-plan details{margin-top:6px}
.upd-plan summary{cursor:pointer;color:var(--g7-text-3)}
.upd-plan ul{margin:6px 0 0 18px;max-height:220px;overflow:auto;font-family:var(--g7-font-mono,monospace);font-size:11px;color:var(--g7-text-3)}
.upd-warn{color:#fbbf24}
.upd-err{grid-column:1/-1;color:#f87171;font-size:12px;padding:6px 10px;border-radius:8px;background:rgba(248,113,113,.08)}
.upd-log{padding:9px 16px;border-top:1px solid var(--g7-border-3);font-size:11.5px;color:var(--g7-text-3)}
.upd-log:first-child{border-top:none}
@media (max-width:760px){.upd-row{grid-template-columns:1fr}.upd-act{justify-content:flex-start}}
</style>
<section class="adm-section" id="sec-updates">
  <div class="adm-title">Actualizaciones</div>
  <div class="adm-sub">Instala versiones firmadas de Toolz. Antes de instalar se respalda lo que cambia y, si el sitio no responde bien después, vuelve solo a la versión anterior. Las claves, la base y los archivos subidos de cada sitio no se tocan.</div>
  <div id="upd-body"><div class="adm-card"><div class="upd-log">Cargando…</div></div></div>
</section>
<script src="/toolz/admin_updates.js?v=<?= (int)@filemtime(__DIR__ . '/admin_updates.js') ?>" defer></script>
