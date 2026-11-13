<?php if (!isset($currentUser)) { http_response_code(404); exit; } /* fragmento: solo se incluye desde su página */ ?>
<?php
/* admin_integraciones.php — Admin › Integraciones: Google Calendar, WhatsApp, ARCA, portales inmobiliarios y YouTube.
   Cada agencia carga sus cuentas y claves (se guardan en _private/integrations.json y nunca vuelven al navegador).
   Lógica en admin_integraciones.js; acciones en integraciones/api.php. Doc: toolz/INTEGRACIONES.md */
?>
<style>
.in-grid{display:grid;gap:14px}
.in-card .adm-card-head{display:flex;align-items:center;gap:12px}
.in-ic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:17px;flex-shrink:0;color:#fff}
.in-h{flex:1;min-width:0}.in-h small{display:block;color:var(--g7-text-4);font-size:12px;line-height:1.45;margin-top:2px;font-weight:400}
.in-pill{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px;border:1px solid var(--g7-border-2);white-space:nowrap}
.in-pill.ok{color:#22c55e;border-color:rgba(34,197,94,.4)}.in-pill.warn{color:#f59e0b;border-color:rgba(245,158,11,.4)}.in-pill.off{color:var(--g7-text-4)}
.in-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 14px}
.in-fields .full{grid-column:1/-1}
.in-fields textarea{font-family:ui-monospace,Consolas,monospace;font-size:11.5px;min-height:70px}
.in-saved{font-size:11px;color:#22c55e;font-weight:600;margin-left:6px}.in-clear{font-size:11px;color:var(--g7-text-4);margin-left:6px;cursor:pointer;text-decoration:underline;background:none;border:0;padding:0}
.in-help{display:block;font-size:11px;color:var(--g7-text-4);font-weight:400;margin-top:3px;line-height:1.4}
.in-ck{display:flex;gap:9px;align-items:center;font-size:12.5px;cursor:pointer}.in-ck input{accent-color:var(--g7-accent)}
.in-extra{background:var(--g7-bg-4);border:1px solid var(--g7-border-2);border-radius:10px;padding:10px 12px;font-size:12px;display:grid;gap:7px;margin-top:12px}
.in-copy{display:flex;gap:6px;align-items:center}.in-copy code{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:var(--g7-bg-2);border:1px solid var(--g7-border);border-radius:7px;padding:5px 8px;font-size:11.5px}
.in-act{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}
.in-st{display:grid;grid-template-columns:auto 1fr;gap:3px 10px;font-size:11.5px;color:var(--g7-text-3);margin-top:12px;border-top:1px solid var(--g7-border);padding-top:10px}
.in-st b{font-weight:600;color:var(--g7-text-4)}.in-st .err{color:#f87171}
.in-msg{font-size:12px;border-radius:9px;padding:8px 11px;margin-top:10px}.in-msg.ok{background:rgba(34,197,94,.1);color:#22c55e}.in-msg.bad{background:rgba(248,113,113,.1);color:#f87171}
.in-adv summary{cursor:pointer;font-size:12px;color:var(--g7-text-4);margin-top:8px}
.in-pend{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.in-pend div{border:1px dashed var(--g7-border-2);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--g7-text-4);line-height:1.45}.in-pend b{display:block;color:var(--g7-text-2);margin-bottom:3px}
.in-log{width:100%;border-collapse:collapse;font-size:11.5px}.in-log td{padding:5px 6px;border-top:1px solid var(--g7-border);vertical-align:top}.in-log td:first-child{white-space:nowrap;color:var(--g7-text-4)}
/* Mercado de integraciones */
.mk-top{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px;color:var(--g7-text-3)}
.mk-big{font-size:22px;color:var(--g7-text);font-weight:800}
.mk-chips{display:flex;gap:6px;flex-wrap:wrap}
.mk-chip{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:999px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);color:var(--g7-text-3);font-weight:600;font-size:12px;cursor:pointer;transition:border-color .15s,color .15s}
.mk-chip small{color:var(--g7-text-4);font-weight:700}
.mk-chip:hover{color:var(--g7-text)}.mk-chip.on{border-color:var(--g7-accent-border);background:var(--g7-accent-muted);color:var(--g7-text)}
.mk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.mk-tile{display:grid;grid-template-columns:40px 1fr auto;gap:4px 12px;align-items:center;text-align:left;padding:14px;border-radius:14px;border:1px solid var(--g7-border-2);background:var(--g7-bg-3);color:var(--g7-text);font:inherit;cursor:pointer;transition:border-color .2s,transform .15s,box-shadow .2s;animation:mkIn .3s both;animation-delay:calc(var(--i) * 25ms)}
.mk-tile:hover:not(:disabled){border-color:var(--g7-accent-border);transform:translateY(-2px);box-shadow:0 10px 24px -14px rgba(0,0,0,.6)}
.mk-tile:active:not(:disabled){transform:translateY(0) scale(.99)}
.mk-tile:focus-visible{outline:2px solid var(--g7-accent);outline-offset:2px}
.mk-tile.soon{opacity:.6;cursor:default}
.mk-ic{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;color:#fff;font-size:18px}
.mk-t{min-width:0}.mk-t b{display:block;font-size:13.5px}.mk-t small{color:var(--g7-text-4);font-size:11px}
.mk-d{grid-column:1/-1;color:var(--g7-text-3);font-size:12px;line-height:1.45;margin-top:6px}
.mk-go{grid-column:1/-1;color:var(--g7-accent);font-size:12px;font-weight:700;margin-top:4px}
.in-card.mk-flash{animation:mkFlash 1.2s}
@keyframes mkIn{from{opacity:0;transform:translateY(6px)}}
@keyframes mkFlash{0%,40%{box-shadow:0 0 0 2px var(--g7-accent)}100%{box-shadow:0 0 0 2px transparent}}
@media (prefers-reduced-motion:reduce){.mk-tile,.in-card.mk-flash{animation:none}.mk-tile{transition:none}}
@media (max-width:760px){.in-fields{grid-template-columns:1fr}}
</style>
<section class="adm-section" id="sec-integraciones">
  <div class="adm-title">Integraciones</div>
  <div class="adm-sub">El mercado de integraciones de esta agencia: medios de pago, productividad, comunicación, factura electrónica, IA y más. Cada una usa las cuentas propias de la agencia; las claves se guardan en el servidor y no se vuelven a mostrar.</div>
  <div id="in-body"><div class="adm-card"><div class="adm-card-body">Cargando…</div></div></div>
</section>
<script src="/toolz/admin_integraciones.js?v=<?= @filemtime(__DIR__ . '/admin_integraciones.js') ?>" defer></script>
