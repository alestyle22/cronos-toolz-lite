/**
 * forms.js — Formularios de Promos armables por el admin (config en forms_lib.php).
 * Se monta sobre el formulario de siempre: envuelve selectFormType, saveItem, openDetail, openEditModal y updateCounters.
 *   - Campos del sistema: se renombran, ocultan, ordenan (CSS order dentro de .form-grid) y pueden ser obligatorios.
 *   - Campos nuevos (x_*): se dibujan acá y viajan en "extra" (JSON).
 *   - Categorías nuevas: pestañas propias; usan los campos del sistema de Paid/Redes más los que se prendan.
 */
(function () {
  'use strict';
  let cfg = window.PF_CONFIG || { types: [], category_options: [] };
  const $ = id => document.getElementById(id);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const grp = id => { const el = $(id); return el ? el.closest('.form-group') : null; };
  const KIND_LABEL = { text: 'Texto', textarea: 'Texto largo', number: 'Número', date: 'Fecha', url: 'Enlace', select: 'Lista de opciones', checkbox: 'Casilla (sí/no)', jira: 'Jira: vincular con ticket' };
  // Jira: el tipo aparece en la paleta y el campo en el formulario solo si Admin › Jira API tiene Promos prendido
  const JIRA_ON = !!window.PF_JIRA;
  const jiraOff = f => f.custom && f.kind === 'jira' && !JIRA_ON;
  const ICONS = ['fa-tag','fa-rocket','fa-dollar-sign','fa-share-nodes','fa-ellipsis','fa-puzzle-piece','fa-bolt','fa-envelope-open-text',
    'fa-star','fa-trophy','fa-gift','fa-calendar-days','fa-bullhorn','fa-video','fa-image','fa-mobile','fa-gamepad','fa-futbol',
    'fa-dice','fa-crown','fa-fire','fa-heart','fa-newspaper','fa-pen-nib','fa-palette','fa-globe','fa-briefcase','fa-users'];
  const BUILTIN_COLOR = { promo: '#ff6600', launch: '#a855f7', paid: '#22c55e', social: '#3b82f6', otro: '#f59e0b', custom: '#06b6d4', tarea: '#ec4899', mailings: '#14b8a6' };

  // Campos del sistema: dónde está su bloque en el formulario
  const SYS = {
    name:          { label: 'Nombre',                    g: () => grp('f-name') },
    project:       { label: 'Proyecto',                  g: t => t === 'promo' ? grp('f-project') : grp('f-paidso-project') },
    projects:      { label: 'Proyectos',                 g: () => document.querySelector('input[name="projects[]"]')?.closest('.form-group') },
    category:      { label: 'Categoría',                 g: () => grp('f-category') },
    multi:         { label: 'Múltiples plataformas',     g: () => $('multi-wrap'), noLabel: true, noReq: true },
    mailing:       { label: 'Mailing',                   g: () => grp('f-mailing-link') },
    landing:       { label: 'Landing',                   g: () => grp('f-landing') },
    media:         { label: 'Formato',                   g: () => grp('f-media-type') },
    otro_field:    { label: 'Campo personalizado',       g: () => grp('f-custom-label') },
    content_type:  { label: 'Tipo de contenido',         g: () => grp('f-content-type') },
    custom_date:   { label: 'Fecha',                     g: () => grp('f-custom-date') },
    resources:     { label: 'Recursos',                  g: () => grp('f-resources-list') },
    tarea_qty:     { label: 'Cantidad de tareas',        g: () => grp('f-tarea-qty') },
    tarea_desc:    { label: 'Descripción breve',         g: () => grp('f-tarea-desc') },
    mailings_qty:  { label: 'Cantidad de mailings',      g: () => grp('f-mailings-qty') },
    mailings_link: { label: 'Link o archivo del mailing', g: () => grp('f-mailings-link') },
    cover:         { label: 'Portada',                   g: () => grp('f-img') },
    graphics:      { label: 'Gráficas (URL)',            g: () => grp('f-graphics') },
    author:        { label: 'Autor',                     g: () => grp('f-author'), noReq: true },
    prod:          { label: 'Producción',                g: () => document.querySelector('#form-container .prod-section'), noLabel: true, noReq: true },
    cx:            { label: 'Complicaciones del pedido', g: () => $('f-cx'), noLabel: true, noReq: true },
  };
  const typeCfg = k => cfg.types.find(t => t.key === k);
  const fieldLabel = f => (f.label || '').trim() || (SYS[f.key] ? SYS[f.key].label : 'Campo');
  const parseExtra = p => { if (!p || !p.extra) return {}; if (typeof p.extra === 'object') return p.extra; try { return JSON.parse(p.extra) || {}; } catch (e) { return {}; } };

  // ── Estilos ──
  const css = document.createElement('style');
  css.textContent = `
.pf-off{display:none!important}
#form-container .type-tabs.pf-on{grid-template-columns:repeat(auto-fill,minmax(104px,1fr))}
.type-tab[hidden]{display:none!important}
.pf-req-star{color:#ef4444;margin-left:3px}
.pf-x .pf-check{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--g7-text-2);cursor:pointer;padding:8px 0}
.pf-x textarea{resize:vertical;min-height:62px;font-family:inherit}
#d-pf-extra{display:grid;grid-template-columns:1fr 1fr;gap:4px 14px;margin-bottom:12px}
#d-pf-extra .full{grid-column:1/-1}
#d-pf-extra .panel-value{white-space:pre-wrap;word-break:break-word}
#e-pf-extra{display:grid;grid-template-columns:1fr 1fr;gap:12px}
#e-pf-extra:empty{display:none}
#e-pf-extra .full{grid-column:1/-1}
.pf-jira{display:flex;flex-direction:column;gap:7px}
.pf-jira-chips{display:flex;flex-wrap:wrap;gap:6px}.pf-jira-chips:empty{display:none}
.pf-jira-chip{--c:#2684ff;display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:5px 6px 5px 10px;border-radius:8px;border:1px solid color-mix(in srgb,var(--c) 45%,transparent);border-left:3px solid var(--c);background:color-mix(in srgb,var(--c) 10%,var(--g7-bg-4));font-size:12px}
.pf-jira-chip b{color:#4c9aff;white-space:nowrap}.pf-jira-chip span{color:var(--g7-text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px}
.pf-jira-chip button{background:none;border:0;color:var(--g7-text-4);cursor:pointer;font-size:11px;padding:2px 4px;border-radius:5px}.pf-jira-chip button:hover{color:#ef4444;background:rgba(239,68,68,.1)}
.pf-jira-s{position:relative}
.pf-jira-s>i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#2684ff;font-size:13px;pointer-events:none}
.pf-jira-s .pf-jira-q{width:100%;padding-left:32px !important}
.pf-jira-res{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:50;max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding:6px;border-radius:10px;border:1px solid var(--g7-border-3);background:var(--g7-bg-2);box-shadow:0 14px 30px -12px rgba(0,0,0,.6)}
.pf-jira-res[hidden]{display:none}
.pf-jira-res button{--c:#64748b;display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:7px 9px;border-radius:7px;border:1px solid transparent;border-left:3px solid var(--c);background:var(--g7-bg-4);color:var(--g7-text);font:inherit;font-size:12px;cursor:pointer}
.pf-jira-res button:hover{border-color:#2684ff;background:rgba(38,132,255,.08)}
.pf-jira-res b{color:#4c9aff;white-space:nowrap}.pf-jira-res span{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pf-jira-res em{font-style:normal;font-size:10.5px;color:var(--g7-text-4);white-space:nowrap}
.pf-jira-na{font-size:11.5px;color:var(--g7-text-4);padding:6px}
#e-pf-extra label.pf-el{font-size:9px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:5px}
#e-pf-extra input:not([type=checkbox]),#e-pf-extra select,#e-pf-extra textarea{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:12px;outline:none;font-family:inherit}
.pf-admin-btn{background:rgba(var(--g7-accent-rgb),.1);border:1px solid rgba(var(--g7-accent-rgb),.28);color:var(--g7-accent);border-radius:7px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;transition:background .15s}
.pf-admin-btn:hover{background:rgba(var(--g7-accent-rgb),.2)}
.pf-ov{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:900;display:flex;align-items:center;justify-content:center;padding:14px}
.pf-box{background:var(--g7-bg-2);border:1px solid var(--g7-border-3);border-radius:16px;width:100%;max-width:1240px;height:min(92vh,860px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.45)}
.pf-head{padding:12px 18px;border-bottom:1px solid var(--g7-border-2);display:flex;align-items:center;gap:10px}
.pf-head h3{margin:0;font-size:15px;font-weight:700;flex:1;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.pf-head h3 small{font-size:11px;font-weight:500;color:var(--g7-text-4)}
.pf-x-btn{background:none;border:none;color:var(--g7-text-3);font-size:17px;cursor:pointer}
.pf-body{flex:1;display:grid;grid-template-columns:210px 1fr 270px;min-height:0}
.pf-side,.pf-panel{overflow-y:auto;padding:12px 10px;display:flex;flex-direction:column;gap:4px}
.pf-side{border-right:1px solid var(--g7-border-2)}
.pf-panel{border-left:1px solid var(--g7-border-2);padding:14px;gap:12px;background:var(--g7-bg-2)}
.pf-h4{font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.08em;margin:2px 4px 4px}
.pf-trow{display:flex;align-items:center;gap:8px;padding:8px;border-radius:9px;border:1px solid transparent;cursor:pointer;font-size:12.5px;color:var(--g7-text-2);background:none;text-align:left;width:100%}
.pf-trow:hover{background:var(--g7-bg-3)}
.pf-trow.on{background:var(--g7-bg-4);border-color:var(--g7-border-3);color:var(--g7-text)}
.pf-trow .pf-dot{width:24px;height:24px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0}
.pf-trow .pf-tl{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf-trow.off .pf-tl{opacity:.45;text-decoration:line-through}
.pf-mv{display:flex;flex-direction:column;opacity:0;transition:opacity .15s}
.pf-trow:hover .pf-mv,.pf-trow.on .pf-mv{opacity:1}
.pf-mv button{background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:9px;padding:1px 3px;line-height:1}
.pf-mv button:hover{color:var(--g7-text)}
.pf-add{margin-top:6px;background:none;border:1px dashed var(--g7-border-3);color:var(--g7-text-3);border-radius:9px;padding:8px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px}
.pf-add:hover{border-color:var(--g7-accent);color:var(--g7-accent)}
/* Lienzo: el formulario tal como se va a ver */
.pf-canvas{overflow-y:auto;padding:16px 22px 28px;background:radial-gradient(circle at 1px 1px,var(--g7-border-2) 1px,transparent 0) 0 0/18px 18px,var(--g7-bg-1, var(--g7-bg-2))}
.pf-cat{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 auto 12px;max-width:640px}
.pf-cat .pf-in{flex:1;min-width:160px;font-weight:700}
.pf-iconbtn{width:38px;height:36px;border-radius:8px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--pfc);cursor:pointer;font-size:14px}
.pf-color{width:42px;height:36px;padding:2px;border-radius:8px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);cursor:pointer}
.pf-icons{display:none;flex-wrap:wrap;gap:5px;max-width:640px;margin:0 auto 12px;padding:10px;border:1px solid var(--g7-border-3);border-radius:10px;background:var(--g7-bg-3)}
.pf-icons.open{display:flex}
.pf-icons button{width:32px;height:32px;border-radius:8px;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-3);cursor:pointer;font-size:13px}
.pf-icons button.on{border-color:var(--pfc);color:var(--pfc)}
.pf-cat-opts{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:11.5px;color:var(--g7-text-3);max-width:640px;margin:-4px auto 14px}
.pf-cat-opts label{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.pf-del{background:none;border:1px solid rgba(239,68,68,.3);color:#ef4444;border-radius:7px;padding:4px 10px;font-size:11px;cursor:pointer;margin-left:auto}
.pf-sheet{max-width:640px;margin:0 auto;background:var(--g7-bg-3);border:1px solid var(--g7-border-3);border-radius:16px;box-shadow:0 14px 40px rgba(0,0,0,.25);overflow:hidden}
.pf-sheet-h{padding:13px 18px;border-bottom:1px solid var(--g7-border-2);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.pf-sheet-h .pf-chip{margin-left:auto;font-size:11px;font-weight:700;padding:4px 10px;border-radius:8px;border:1px solid var(--pfc);color:var(--pfc);background:color-mix(in srgb,var(--pfc) 13%,transparent);display:inline-flex;gap:6px;align-items:center}
.pf-sheet-b{padding:16px 18px}
.pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;min-height:60px}
.pf-card{position:relative;border:1px solid transparent;border-radius:10px;padding:8px 9px 9px;cursor:grab;transition:border-color .15s,background .15s;min-width:0;user-select:none;-webkit-user-select:none}
.pf-card.full{grid-column:1/-1}
.pf-card:hover{border-color:var(--g7-border-3);background:color-mix(in srgb,var(--g7-bg-4) 45%,transparent)}
.pf-card.on{border-color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.06)}
.pf-card-h{display:flex;align-items:center;gap:6px;margin-bottom:5px;min-height:16px}
.pf-card-h b{font-size:9.5px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.08em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf-card-h b i{color:#ef4444;font-style:normal;margin-left:2px}
.pf-grip{color:var(--g7-text-5,var(--g7-text-4));font-size:11px;cursor:grab;touch-action:none;padding:2px 3px;margin:-2px 0 -2px -3px;opacity:.55}
.pf-card:hover .pf-grip,.pf-card.on .pf-grip{opacity:1}
.pf-tag{margin-left:auto;font-size:9px;font-weight:600;color:var(--g7-text-4);background:var(--g7-bg-4);border-radius:5px;padding:1px 6px;white-space:nowrap;opacity:0;transition:opacity .15s}
.pf-card:hover .pf-tag,.pf-card.on .pf-tag{opacity:1}
.pf-mk{height:34px;border-radius:8px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 11px;font-size:12px;color:var(--g7-text-4);overflow:hidden;white-space:nowrap}
.pf-mk.tall{height:62px;align-items:flex-start;padding-top:8px}
.pf-mk i{font-size:10px;opacity:.7}
.pf-mk.dash{border-style:dashed;justify-content:center;color:var(--g7-text-3)}
.pf-seg{display:flex;border:1px solid var(--g7-border-3);border-radius:8px;overflow:hidden;height:34px}
.pf-seg span{flex:1;display:flex;align-items:center;justify-content:center;font-size:11.5px;color:var(--g7-text-4)}
.pf-seg span:first-child{background:var(--g7-bg-5,var(--g7-bg-4));color:var(--g7-text);font-weight:700}
.pf-ck{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--g7-text-2);height:30px}
.pf-ck::before{content:'';width:15px;height:15px;border-radius:4px;border:1.5px solid var(--g7-border-3);background:var(--g7-bg-4);flex-shrink:0}
.pf-row2{display:flex;gap:8px}.pf-row2>*{flex:1}
.pf-fixed{cursor:pointer;margin-top:10px}
.pf-fixed .pf-qty{display:flex;gap:8px}.pf-fixed .pf-qty .pf-mk{flex:1}
.pf-sheet-f{padding:12px 18px;border-top:1px solid var(--g7-border-2);display:flex;gap:8px}
.pf-sheet-f span{height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.pf-sheet-f span:first-child{flex:1;border:1px solid var(--g7-border-3);color:var(--g7-text-3)}
.pf-sheet-f span:last-child{flex:3;background:var(--g7-accent);color:#000}
.pf-ph{border:2px dashed var(--g7-accent);border-radius:10px;background:rgba(var(--g7-accent-rgb),.07);min-height:58px}
.pf-ph.full{grid-column:1/-1}
.pf-ghost{position:fixed;z-index:1000;pointer-events:none;background:var(--g7-bg-3);border:1px solid var(--g7-accent)!important;box-shadow:0 18px 40px rgba(0,0,0,.4);transform:rotate(1.5deg);opacity:.95}
.pf-dragging,.pf-dragging *{cursor:grabbing!important}
/* Panel derecho */
.pf-pals{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.pf-pal{display:flex;align-items:center;gap:7px;padding:9px 9px;border:1px solid var(--g7-border-3);border-radius:9px;background:var(--g7-bg-3);font-size:11.5px;color:var(--g7-text-2);cursor:grab;user-select:none;touch-action:none;transition:border-color .15s}
.pf-pal:hover{border-color:var(--g7-accent);color:var(--g7-text)}
.pf-pal i{width:14px;text-align:center;color:var(--g7-accent);font-size:11px}
.pf-tray{border:1.5px dashed var(--g7-border-3);border-radius:11px;padding:8px;display:flex;flex-direction:column;gap:6px;min-height:64px;transition:border-color .15s,background .15s}
.pf-tray.hot{border-color:#ef4444;background:rgba(239,68,68,.06)}
.pf-tray .pf-card{background:var(--g7-bg-3);border-color:var(--g7-border-2);padding:7px 9px}
.pf-tray .pf-card .pf-mk,.pf-tray .pf-card .pf-seg,.pf-tray .pf-card .pf-ck,.pf-tray .pf-card .pf-row2,.pf-tray .pf-card .pf-qty{display:none}
.pf-tray .pf-card-h{margin:0}
.pf-tray-empty{font-size:11px;color:var(--g7-text-4);text-align:center;padding:12px 6px;line-height:1.4}
.pf-hint{font-size:11.5px;color:var(--g7-text-4);line-height:1.45;margin:0}
.pf-lbl{font-size:10px;font-weight:700;color:var(--g7-text-3);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:5px}
.pf-in{width:100%;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:8px 10px;font-size:13px;outline:none;font-family:inherit}
.pf-in:focus{border-color:var(--g7-accent)}
textarea.pf-in{resize:vertical;min-height:84px}
.pf-prop-h{display:flex;align-items:center;gap:8px}
.pf-prop-h b{font-size:13px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pf-back{background:none;border:none;color:var(--g7-text-3);cursor:pointer;font-size:12px;padding:4px}
.pf-sw{display:flex;border:1px solid var(--g7-border-3);border-radius:8px;overflow:hidden}
.pf-sw button{flex:1;background:none;border:none;color:var(--g7-text-3);padding:7px;font-size:12px;cursor:pointer}
.pf-sw button.on{background:var(--g7-bg-5,var(--g7-bg-4));color:var(--g7-text);font-weight:700}
.pf-tg{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--g7-text-2);cursor:pointer}
.pf-acts{display:flex;gap:8px;flex-wrap:wrap}
.pf-btn{border-radius:8px;padding:9px 14px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--g7-border-3);background:var(--g7-bg-4);color:var(--g7-text-2);display:inline-flex;align-items:center;gap:6px}
.pf-btn.pri{background:var(--g7-accent);border-color:var(--g7-accent);color:#000}
.pf-btn.red{color:#ef4444;border-color:rgba(239,68,68,.35);background:none}
.pf-btn:disabled{opacity:.6;cursor:default}
.pf-btn.ghost{background:none;border:none;color:var(--g7-text-4);font-weight:600;padding:9px 6px}
.pf-foot{padding:11px 18px;border-top:1px solid var(--g7-border-2);display:flex;align-items:center;gap:8px}
.pf-foot .pf-msg{flex:1;font-size:11.5px;color:#ef4444}
@media (max-width:980px){.pf-body{grid-template-columns:1fr 250px}.pf-side{grid-column:1/-1;flex-direction:row;overflow-x:auto;border-right:none;border-bottom:1px solid var(--g7-border-2);padding:8px}.pf-side .pf-h4,.pf-mv{display:none}.pf-trow{width:auto;flex-shrink:0}.pf-add{margin:0;flex-shrink:0}.pf-body{grid-template-rows:auto 1fr}}
@media (max-width:640px){.pf-ov{padding:0}.pf-box{border-radius:0;height:100dvh}.pf-body{display:block;overflow-y:auto}.pf-side{display:flex;min-height:58px}.pf-cat .pf-in{min-width:0}.pf-canvas{overflow:visible;padding:12px}.pf-panel{border-left:none;border-top:1px solid var(--g7-border-2);overflow:visible}.pf-grid{grid-template-columns:1fr}.pf-card.full{grid-column:auto}}
`;
  document.head.appendChild(css);
  const dyn = document.createElement('style'); document.head.appendChild(dyn);

  // ── Colores, etiquetas, pestañas, barra lateral ──
  function refreshTypes() {
    let rules = '';
    cfg.types.forEach(t => {
      TYPE_LABELS[t.key] = t.label;
      _typeColors[t.key] = t.color;
      if (!t.builtin) TYPE_CSS[t.key] = 'pf-tt-' + t.key;
      if (t.builtin && t.color === BUILTIN_COLOR[t.key]) return;
      const c = t.color, k = t.key;
      rules += `.type-tab[data-type="${k}"].active{--tc:${c};border-color:${c};color:${c};background:${c}22;box-shadow:0 0 14px ${c}33}
.item-card[data-type="${k}"]{box-shadow:inset 0 2px 0 ${c}b3!important}
.pf-tt-${k}{background:${c}1f;color:${c};border:1px solid ${c}55}
.item-card[data-type="${k}"] .pr-noimg{background:radial-gradient(120% 90% at 30% 20%,${c}26,transparent 65%),var(--g7-bg-3);color:${c}66}\n`;
    });
    dyn.textContent = rules;

    // Pestañas del formulario
    const tabs = document.querySelector('#form-container .type-tabs');
    if (tabs) {
      tabs.classList.add('pf-on');
      tabs.querySelectorAll('.type-tab').forEach(b => { if (!typeCfg(b.dataset.type)) b.remove(); });
      cfg.types.forEach(t => {
        let b = tabs.querySelector(`.type-tab[data-type="${t.key}"]`);
        if (!b) { b = document.createElement('button'); b.className = 'type-tab'; b.dataset.type = t.key; b.type = 'button'; b.setAttribute('data-on-click', 'selectFormType'); b.setAttribute('data-click-1', 's:' + t.key); b.setAttribute('data-click-2', 'this'); /* assets/g7-on.js (CSP estricta) */ }
        b.innerHTML = `<i class="fa-solid ${esc(t.icon)}"></i>${esc(t.label)}`;
        b.hidden = !!t.hidden;
        tabs.appendChild(b);
      });
      const act = tabs.querySelector('.type-tab.active');
      if (!act || act.hidden) { const first = tabs.querySelector('.type-tab:not([hidden])'); if (first) selectFormType(first.dataset.type, first); }
    }

    // Barra lateral y filtros: las categorías nuevas
    const anchor = $('t-otro');
    document.querySelectorAll('.pf-sb').forEach(b => b.remove());
    document.querySelectorAll('#filt-type option.pf-opt, #e-type option.pf-opt').forEach(o => o.remove());
    let last = anchor;
    cfg.types.filter(t => !t.builtin).forEach(t => {
      if (anchor) {
        const b = document.createElement('button');
        b.className = 'sb-btn pf-sb'; b.id = 't-' + t.key;
        b.onclick = () => setType(t.key, b);
        b.innerHTML = `<i class="fa-solid ${esc(t.icon)}" style="width:13px;font-size:11px;color:${t.color}"></i><span class="sb-label">${esc(t.label)}</span><span class="badge" id="cnt-t-${t.key}">0</span>`;
        if (typeFilter === t.key) b.classList.add('active');
        last.after(b); last = b;
      }
      [$('filt-type'), $('e-type')].forEach(sel => { if (!sel) return; const o = document.createElement('option'); o.className = 'pf-opt'; o.value = t.key; o.textContent = t.label; sel.appendChild(o); });
    });

    // Opciones de "Categoría"
    [$('f-category'), $('e-category')].forEach(sel => {
      if (!sel) return;
      const cur = sel.value;
      const opts = cfg.category_options.slice();
      if (cur && !opts.includes(cur)) opts.push(cur);
      sel.innerHTML = '<option value="">Seleccionar...</option>' + opts.map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('');
      sel.value = cur;
    });
  }

  // ── Aplicar la config al formulario ──
  const cache = {};
  function sysGroups() {
    const out = new Set();
    Object.entries(SYS).forEach(([k, s]) => { const g = s.g('promo'); if (g) out.add(g); if (k === 'project') { const g2 = s.g('paid'); if (g2) out.add(g2); } });
    return [...out];
  }
  function groupLabel(g) { return g.querySelector(':scope > label, :scope > .fc-lbl-row > label'); }
  function customInput(f, idp) {
    const id = idp + f.key, ph = f.kind === 'url' ? 'https://...' : '';
    if (f.kind === 'textarea') return `<textarea id="${id}" rows="3"></textarea>`;
    if (f.kind === 'select') return `<select id="${id}"><option value="">Seleccionar...</option>${(f.options || []).map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}</select>`;
    if (f.kind === 'checkbox') return `<label class="pf-check"><input type="checkbox" id="${id}"> ${esc(fieldLabel(f))}</label>`;
    if (f.kind === 'jira') return `<div class="pf-jira"><input type="hidden" id="${id}" data-jira="1"><div class="pf-jira-chips"></div>
      <div class="pf-jira-s"><i class="fa-brands fa-jira"></i><input type="search" class="pf-jira-q" placeholder="Busca un ticket por texto o clave (ej.: DIS-12)" autocomplete="off" aria-label="Buscar ticket de Jira"><div class="pf-jira-res" hidden></div></div></div>`;
    const type = { number: 'number', date: 'date', url: 'url' }[f.kind] || 'text';
    return `<input type="${type}" id="${id}" placeholder="${ph}"${f.kind === 'number' ? ' step="any"' : ''}>`;
  }
  const inVal = el => !el ? '' : el.type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || '').trim();
  const setVal = (el, v) => { if (!el) return; if (el.type === 'checkbox') el.checked = !!v && v !== '0'; else el.value = v ?? ''; if (el.dataset.jira) jiraChips(el); };

  // ── Campo Jira: buscador de tickets + chips. El valor (input oculto) son las claves separadas por coma ──
  const JIRA_INFO = {};   // clave → {summary, status, cat} de lo que ya se buscó
  const JIRA_C = { new: '#64748b', indeterminate: '#3b82f6', done: '#22c55e' };
  function jiraChips(el) {
    const box = el.parentElement.querySelector('.pf-jira-chips'); if (!box) return;
    const keys = el.value ? el.value.split(',').filter(Boolean) : [];
    box.innerHTML = keys.map(k => { const i = JIRA_INFO[k] || {}; return `<span class="pf-jira-chip" style="--c:${JIRA_C[i.cat] || '#2684ff'}"><b>${esc(k)}</b>${i.summary ? `<span>${esc(i.summary)}</span>` : ''}<button type="button" data-jx="${esc(k)}" aria-label="Quitar ${esc(k)}">✕</button></span>`; }).join('');
  }
  let jiraT = 0;
  async function jiraSearch(q, res) {
    res.hidden = false; res.innerHTML = '<div class="pf-jira-na">Buscando en Jira…</div>';
    try {
      const r = await fetch('/toolz/promos/jira_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'search', q }) });
      const j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || 'No se pudo buscar');
      j.issues.forEach(i => { JIRA_INFO[i.key] = i; });
      res.innerHTML = j.issues.length ? j.issues.map(i => `<button type="button" data-jk="${esc(i.key)}" style="--c:${JIRA_C[i.cat] || '#64748b'}"><b>${esc(i.key)}</b><span>${esc(i.summary)}</span><em>${esc(i.status)}</em></button>`).join('')
        : `<div class="pf-jira-na">${q ? 'No hay tickets con eso en los proyectos elegidos.' : 'No hay tickets en los proyectos elegidos.'}</div>`;
    } catch (e) { res.innerHTML = `<div class="pf-jira-na">${esc(e.message)}</div>`; }
  }
  document.addEventListener('input', e => {
    const q = e.target.closest && e.target.closest('.pf-jira-q'); if (!q) return;
    clearTimeout(jiraT); jiraT = setTimeout(() => jiraSearch(q.value.trim(), q.parentElement.querySelector('.pf-jira-res')), 350);
  });
  document.addEventListener('focusin', e => { const q = e.target.closest && e.target.closest('.pf-jira-q'); if (q && q.parentElement.querySelector('.pf-jira-res').hidden) jiraSearch(q.value.trim(), q.parentElement.querySelector('.pf-jira-res')); });
  document.addEventListener('click', e => {
    const pick = e.target.closest && e.target.closest('.pf-jira-res [data-jk]'), x = e.target.closest && e.target.closest('.pf-jira-chips [data-jx]');
    if (pick || x) {
      e.preventDefault();
      const w = (pick || x).closest('.pf-jira'), el = w.querySelector('input[data-jira]'), keys = new Set(el.value ? el.value.split(',') : []);
      if (pick) { keys.add(pick.dataset.jk); w.querySelector('.pf-jira-q').value = ''; w.querySelector('.pf-jira-res').hidden = true; } else keys.delete(x.dataset.jx);
      el.value = [...keys].join(','); jiraChips(el); return;
    }
    document.querySelectorAll('.pf-jira-res:not([hidden])').forEach(r => { if (!r.parentElement.contains(e.target)) r.hidden = true; });
  });

  function apply(type) {
    const grid = document.querySelector('#form-container .form-grid');
    if (!grid) return;
    grid.querySelectorAll('.pf-x').forEach(g => { const el = $('pfx-' + g.dataset.key); if (el) cache[g.dataset.key] = inVal(el); g.remove(); });
    sysGroups().forEach(g => {
      g.classList.remove('pf-off'); g.style.order = '';
      if (g.dataset.pfFull0 !== undefined) g.classList.toggle('full', g.dataset.pfFull0 === '1');
      const l = groupLabel(g);
      if (l && l.dataset.pfOrig !== undefined) { l.innerHTML = l.dataset.pfOrig; delete l.dataset.pfOrig; }
    });
    const vcw = $('video-count-wrap'); if (vcw) { vcw.style.order = ''; vcw.classList.remove('pf-off'); }
    const t = typeCfg(type);
    if (!t) return;
    const listed = new Set();
    let i = 0;
    t.fields.forEach(f => {
      i += 2;
      if (f.custom) {
        if (f.hidden || jiraOff(f)) return;
        const d = document.createElement('div');
        d.className = 'form-group pf-x' + ((f.width ? f.width === 'full' : f.kind === 'textarea' || f.kind === 'jira') ? ' full' : '');
        d.dataset.key = f.key; d.style.order = i;
        d.innerHTML = (f.kind === 'checkbox' ? '' : `<label for="pfx-${f.key}">${esc(fieldLabel(f))}${f.required ? '<span class="pf-req-star">*</span>' : ''}</label>`) + customInput(f, 'pfx-');
        grid.appendChild(d);
        setVal($('pfx-' + f.key), cache[f.key]);
        return;
      }
      const s = SYS[f.key]; const g = s && s.g(type);
      if (!g) return;
      listed.add(g);
      if (f.hidden) { g.classList.add('pf-off'); if (f.key === 'media' && vcw) vcw.classList.add('pf-off'); return; }
      if (!t.builtin) g.classList.remove('hidden');
      if (g.parentElement === grid) {
        g.style.order = i;
        if (f.width) { if (g.dataset.pfFull0 === undefined) g.dataset.pfFull0 = g.classList.contains('full') ? '1' : '0'; g.classList.toggle('full', f.width === 'full'); }
      }
      if (f.key === 'media' && vcw) { vcw.style.order = i + 1; if (!t.builtin) { vcw.classList.remove('hidden'); vcw.style.display = (typeof mediaType !== 'undefined' && mediaType === 'video') ? '' : 'none'; } }
      if (f.key === 'multi' && !t.builtin) { const a = grp('f-paidso-project'); if (a) a.after(g); }
      const l = !s.noLabel && groupLabel(g);
      if (l && (f.label || f.required)) {
        l.dataset.pfOrig = l.innerHTML;
        if (f.label) l.textContent = f.label;
        if (f.required) l.insertAdjacentHTML('beforeend', '<span class="pf-req-star">*</span>');
      }
    });
    // Categoría nueva: lo que no está en su lista no se muestra
    if (!t.builtin) {
      sysGroups().forEach(g => { if (!listed.has(g) && g !== grp('f-name')) g.classList.add('pf-off'); });
      if (vcw && !t.fields.some(f => f.key === 'media' && !f.hidden)) vcw.classList.add('pf-off');
    }
  }

  // ── Guardar: obligatorios + extra ──
  function sysValue(key, type) {
    const g = SYS[key] && SYS[key].g(type); if (!g) return '';
    if (key === 'landing' && typeof lmGet === 'function' && $('f-landing') && $('f-landing').tagName !== 'INPUT') return lmGet('f-landing').join('\n');
    if (key === 'cover') return $('f-img')?.files.length ? '1' : '';
    if (key === 'mailing') return ($('f-mailing-link')?.value || '').trim() || ($('f-mailing-file')?.files.length ? '1' : '');
    if (key === 'projects') return g.querySelector('input:checked') ? '1' : '';
    if (key === 'otro_field') return ($('f-custom-value')?.value || '').trim();
    if (key === 'resources') return [...g.querySelectorAll('.res-url,.res-name')].some(i => i.value.trim()) ? '1' : '';
    const el = g.querySelector('select, textarea, input:not([type=hidden]):not([type=checkbox]):not([type=file])');
    return el ? String(el.value || '').trim() : '1';
  }
  window.pfAppend = function (fd) {
    const t = typeCfg(formType); if (!t) return true;
    for (const f of t.fields) {
      if (f.hidden || !f.required || jiraOff(f)) continue;
      const v = f.custom ? inVal($('pfx-' + f.key)) : sysValue(f.key, formType);
      if (!v || (f.custom && f.kind === 'checkbox' && v !== '1')) { alert('Completa «' + fieldLabel(f) + '».'); return false; }
    }
    const extra = {};
    t.fields.forEach(f => { if (f.custom && !f.hidden && !jiraOff(f)) { const v = inVal($('pfx-' + f.key)); if (v) extra[f.key] = v; } });
    fd.set('extra', JSON.stringify(extra));
    if (!t.builtin) {
      const on = k => t.fields.some(f => f.key === k && !f.hidden);
      if (on('category')) fd.set('category', $('f-category').value);
      if (on('landing')) fd.set('landing', sysValue('landing', formType));
      if (on('mailing')) {
        fd.set('mailing_link', $('f-mailing-link').value);
        const mf = $('f-mailing-file')?.files[0];
        if (mf && typeof mailingMode !== 'undefined' && mailingMode === 'file') fd.set('mailing_file', mf);
      }
    }
    return true;
  };

  // ── Detalle y edición ──
  function extraRows(type) { const t = typeCfg(type); return t ? t.fields.filter(f => f.custom && !f.hidden && !jiraOff(f)) : []; }
  function showValue(f, v) {
    if (f.kind === 'checkbox') return 'Sí';
    if (f.kind === 'url' || /^https?:\/\//i.test(v)) return `<a href="${esc(v)}" target="_blank" rel="noopener" class="panel-link" style="display:inline-flex">${esc(v)}</a>`;
    if (f.kind === 'date') { const [y, m, d] = v.split('-'); return `${d}/${m}/${y}`; }
    return esc(v);
  }
  function renderDetailExtra(p) {
    let box = $('d-pf-extra');
    if (!box) { const a = $('d-custom-wrap'); if (!a) return; box = document.createElement('div'); box.id = 'd-pf-extra'; a.after(box); }
    const ex = parseExtra(p);
    const rows = extraRows(p.type).filter(f => ex[f.key] && f.kind !== 'jira');
    box.innerHTML = rows.map(f => `<div class="${f.kind === 'textarea' || f.kind === 'url' ? 'full' : ''}"><div class="panel-label">${esc(fieldLabel(f))}</div><div class="panel-value">${showValue(f, String(ex[f.key]))}</div></div>`).join('');
    box.style.display = rows.length ? '' : 'none';
  }
  function renderEditExtra(type, values) {
    let box = $('e-pf-extra');
    if (!box) { const a = $('e-cx'); if (!a) return; box = document.createElement('div'); box.id = 'e-pf-extra'; a.before(box); }
    box.innerHTML = extraRows(type).map(f => `<div class="${f.kind === 'textarea' || f.kind === 'jira' ? 'full' : ''}">${f.kind === 'checkbox' ? '' : `<label class="pf-el" for="pfe-${f.key}">${esc(fieldLabel(f))}</label>`}${customInput(f, 'pfe-')}</div>`).join('');
    extraRows(type).forEach(f => setVal($('pfe-' + f.key), values[f.key]));
  }
  window.pfEditPayload = function (payload) {
    const type = payload.type || (items.find(x => x.id == currentDetailId) || {}).type;
    const ex = {};
    extraRows(type).forEach(f => { const v = inVal($('pfe-' + f.key)); if (v) ex[f.key] = v; });
    payload.extra = ex;
  };
  window.pfEditSaved = function (payload) {
    const p = items.find(x => x.id == currentDetailId); if (!p) return;
    if (payload.type) p.type = payload.type;
    p.extra = JSON.stringify(payload.extra || {});
    renderDetailExtra(p);
  };

  // ── Envolver funciones del formulario ──
  const wrap = (name, fn) => { const orig = window[name]; if (typeof orig === 'function') window[name] = fn(orig); };
  wrap('selectFormType', o => function (type, btn) { o.apply(this, arguments); apply(type); });
  wrap('saveItem', o => async function () {
    const before = items.length;
    await o.apply(this, arguments);
    if (items.length > before) { Object.keys(cache).forEach(k => delete cache[k]); document.querySelectorAll('#form-container .pf-x input, #form-container .pf-x select, #form-container .pf-x textarea').forEach(el => setVal(el, '')); }
  });
  wrap('openDetail', o => function (id) { o.apply(this, arguments); const p = items.find(x => x.id == id); if (p) renderDetailExtra(p); });
  wrap('openEditModal', o => function () {
    o.apply(this, arguments);
    const p = items.find(x => x.id == currentDetailId); if (!p) return;
    const et = $('e-type');
    if (et && ![...et.options].some(op => op.value === p.type)) { const op = document.createElement('option'); op.value = p.type; op.textContent = TYPE_LABELS[p.type] || p.type; et.appendChild(op); et.value = p.type; }
    renderEditExtra(p.type, parseExtra(p));
  });
  wrap('updateCounters', o => function () {
    o.apply(this, arguments);
    cfg.types.filter(t => !t.builtin).forEach(t => { const el = $('cnt-t-' + t.key); if (el) el.textContent = items.filter(p => p.type === t.key).length; });
  });
  document.addEventListener('change', e => {
    if (e.target && e.target.id === 'e-type') {
      const vals = {}; document.querySelectorAll('#e-pf-extra [id^="pfe-"]').forEach(el => { vals[el.id.slice(4)] = inVal(el); });
      const p = items.find(x => x.id == currentDetailId);
      renderEditExtra(e.target.value, Object.assign({}, parseExtra(p), vals));
    }
  });

  // ── Editor visual (solo admin) ──
  // Centro: el formulario como se va a ver; los campos se arrastran para ordenarlos o mandarlos a "Ocultos".
  // Derecha: tipos de campo para arrastrar (o tocar) y, con un campo elegido, sus propiedades.
  let draft = null, sel = 0, selKey = null, iconsOpen = false, dnd = null, justDragged = false;
  const FIXED = ['prod', 'cx'];
  const KIND_ICON = { text: 'fa-font', textarea: 'fa-align-left', number: 'fa-hashtag', date: 'fa-calendar-days', url: 'fa-link', select: 'fa-list-ul', checkbox: 'fa-square-check', jira: 'fa-ticket' };
  const slug = s => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 24);
  const curT = () => draft.types[sel];
  const fByKey = k => curT().fields.find(f => f.key === k);
  // Ancho como en el formulario real: lo elegido, o el que trae el bloque
  function isFull(f, type) {
    if (f.width) return f.width === 'full';
    if (f.custom) return f.kind === 'textarea' || f.kind === 'jira';
    const g = SYS[f.key] && SYS[f.key].g(type);
    return g ? (g.dataset.pfFull0 !== undefined ? g.dataset.pfFull0 === '1' : g.classList.contains('full')) : false;
  }
  const mk = (txt, icon, cls) => `<div class="pf-mk ${cls || ''}"><span>${esc(txt)}</span>${icon ? `<i class="fa-solid ${icon}"></i>` : ''}</div>`;
  function mock(f, type) {
    if (f.custom) {
      switch (f.kind) {
        case 'textarea': return mk('', '', 'tall');
        case 'number': return mk('0');
        case 'date': return mk('dd/mm/aaaa', 'fa-calendar');
        case 'url': return mk('https://...');
        case 'select': return mk((f.options || [])[0] ? (f.options || []).join(' · ') : 'Seleccionar...', 'fa-chevron-down');
        case 'checkbox': return `<div class="pf-ck">${esc(fieldLabel(f))}</div>`;
        case 'jira': return mk(JIRA_ON ? 'Buscar ticket de Jira…' : 'Jira apagado: no se muestra', 'fa-magnifying-glass');
        default: return mk('');
      }
    }
    switch (f.key) {
      case 'name': return mk('Nombre del contenido');
      case 'project': return mk('Elige un proyecto', 'fa-chevron-down');
      case 'projects': return '<div class="pf-row2"><div class="pf-ck">Proyecto A</div><div class="pf-ck">Proyecto B</div><div class="pf-ck">Proyecto C</div></div>';
      case 'category': return mk(draft.category_options.join(' · ') || 'Seleccionar...', 'fa-chevron-down');
      case 'multi': return '<div class="pf-ck">Tarea de múltiples plataformas</div>';
      case 'mailing': return mk('URL del mailing', 'fa-envelope');
      case 'landing': return mk('URL de la landing', 'fa-globe');
      case 'media': return '<div class="pf-seg"><span>Imagen</span><span>Video</span></div>';
      case 'otro_field': return `<div class="pf-row2">${mk('Nombre del campo')}${mk('Valor...')}</div>`;
      case 'content_type': return mk('Seleccionar tipo...', 'fa-chevron-down');
      case 'custom_date': return mk('dd/mm/aaaa', 'fa-calendar');
      case 'resources': return mk('+ Agregar recurso', '', 'dash');
      case 'tarea_qty': case 'mailings_qty': return mk('1');
      case 'tarea_desc': return mk('Ej: Config DNS, ajuste banner...', '', 'tall');
      case 'mailings_link': return mk('URL del mailing...', 'fa-upload');
      case 'cover': return mk('Elegir imagen', 'fa-image');
      case 'graphics': return mk('Drive, Figma...');
      case 'author': return mk(window.ME || 'admin', 'fa-chevron-down');
      case 'prod': return `<div class="pf-qty">${mk('Gráficas   −  0  +')}${mk('Videos   −  0  +')}</div>`;
      case 'cx': return mk('¿Tuviste complicaciones con este pedido?   No · Sí');
    }
    return mk('');
  }
  function card(f, type, extra) {
    const s = SYS[f.key] || {};
    const tag = f.custom ? KIND_LABEL[f.kind] || 'Texto' : 'Del sistema';
    const lab = s.noLabel && !f.custom ? s.label : fieldLabel(f);
    return `<div class="pf-card${extra || ''}${selKey === f.key ? ' on' : ''}" data-k="${esc(f.key)}">
      <div class="pf-card-h"><span class="pf-grip" title="Arrastrar"><i class="fa-solid fa-grip-vertical"></i></span><b>${esc(lab)}${f.required ? '<i>*</i>' : ''}</b><span class="pf-tag">${tag}</span></div>
      ${mock(f, type)}</div>`;
  }

  function openEditor() {
    draft = JSON.parse(JSON.stringify(cfg));
    const cur = draft.types.findIndex(t => t.key === formType); sel = cur >= 0 ? cur : 0; selKey = null;
    let ov = $('pf-ed');
    if (!ov) {
      ov = document.createElement('div'); ov.id = 'pf-ed'; ov.className = 'pf-ov';
      ov.innerHTML = `<div class="pf-box" role="dialog" aria-modal="true" aria-labelledby="pf-ttl">
        <div class="pf-head"><h3 id="pf-ttl"><i class="fa-solid fa-sliders" style="color:var(--g7-accent)"></i> Formularios de Promos <small>arrastra los campos para acomodarlos · toca uno para editarlo</small></h3><button class="pf-x-btn" data-a="close" aria-label="Cerrar">✕</button></div>
        <div class="pf-body"><aside class="pf-side" id="pf-side"></aside><section class="pf-canvas" id="pf-canvas"></section><aside class="pf-panel" id="pf-panel"></aside></div>
        <div class="pf-foot"><button class="pf-btn ghost" data-a="reset">Volver a como venía</button><span class="pf-msg" id="pf-msg"></span><button class="pf-btn" data-a="close">Cancelar</button><button class="pf-btn pri" data-a="save" id="pf-save"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
      </div>`;
      document.body.appendChild(ov);
      ov.addEventListener('click', onClick);
      ov.addEventListener('input', onInput);
      ov.addEventListener('change', onInput);
      ov.addEventListener('pointerdown', onDown);
    }
    ov.style.display = 'flex';
    $('pf-msg').textContent = '';
    draw();
  }
  function draw() { drawSide(); drawCanvas(); drawPanel(); }
  function drawSide() {
    $('pf-side').innerHTML = '<div class="pf-h4">Categorías</div>' + draft.types.map((t, i) => `
      <div class="pf-trow${i === sel ? ' on' : ''}${t.hidden ? ' off' : ''}" data-a="sel" data-i="${i}" role="button" tabindex="0">
        <span class="pf-dot" style="background:${t.color}22;color:${t.color}"><i class="fa-solid ${esc(t.icon)}"></i></span>
        <span class="pf-tl">${esc(t.label)}</span>
        <span class="pf-mv"><button data-a="tup" data-i="${i}" title="Subir" aria-label="Subir">▲</button><button data-a="tdn" data-i="${i}" title="Bajar" aria-label="Bajar">▼</button></span>
      </div>`).join('') + `<button class="pf-add" data-a="tadd"><i class="fa-solid fa-plus"></i> Nueva categoría</button>`;
  }
  function drawCanvas() {
    const t = curT(); if (!t) { $('pf-canvas').innerHTML = ''; return; }
    const vis = t.fields.filter(f => !f.hidden && !FIXED.includes(f.key));
    const fixed = t.fields.filter(f => !f.hidden && FIXED.includes(f.key));
    $('pf-canvas').innerHTML = `
      <div class="pf-cat" style="--pfc:${t.color}">
        <button class="pf-iconbtn" data-a="icons" title="Cambiar ícono" aria-label="Cambiar ícono"><i class="fa-solid ${esc(t.icon)}"></i></button>
        <input class="pf-in" data-f="label" value="${esc(t.label)}" maxlength="40" aria-label="Nombre de la categoría" placeholder="Nombre de la categoría">
        <input type="color" class="pf-color" data-f="color" value="${t.color}" title="Color" aria-label="Color">
      </div>
      <div class="pf-icons${iconsOpen ? ' open' : ''}" style="--pfc:${t.color}">${ICONS.map(ic => `<button data-a="icon" data-ic="${ic}" class="${ic === t.icon ? 'on' : ''}" aria-label="${ic}"><i class="fa-solid ${ic}"></i></button>`).join('')}</div>
      <div class="pf-cat-opts"><label><input type="checkbox" data-f="hidden" ${t.hidden ? 'checked' : ''}> Ocultar esta categoría del formulario</label>
        ${t.builtin ? '' : '<button class="pf-del" data-a="tdel"><i class="fa-solid fa-trash-can"></i> Borrar categoría</button>'}</div>
      <div class="pf-sheet" style="--pfc:${t.color}">
        <div class="pf-sheet-h"><i class="fa-solid fa-plus-circle" style="color:var(--g7-accent)"></i> Nuevo contenido <span class="pf-chip"><i class="fa-solid ${esc(t.icon)}"></i>${esc(t.label)}</span></div>
        <div class="pf-sheet-b">
          <div class="pf-grid" id="pf-grid">${vis.map(f => card(f, t.key, isFull(f, t.key) ? ' full' : '')).join('')}</div>
          ${fixed.map(f => card(f, t.key, ' pf-fixed')).join('')}
        </div>
        <div class="pf-sheet-f"><span>Cancelar</span><span>Guardar contenido</span></div>
      </div>`;
  }
  function drawPanel() {
    const t = curT(); if (!t) { $('pf-panel').innerHTML = ''; return; }
    const f = selKey && fByKey(selKey);
    const hidden = t.fields.filter(x => x.hidden);
    const trayHtml = `<div class="pf-h4" style="margin:8px 0 0">Ocultos</div>
      <p class="pf-hint">Arrastra acá lo que no quieras pedir; para volver a mostrarlo, arrástralo al formulario.</p>
      <div class="pf-tray" id="pf-tray">${hidden.length ? hidden.map(x => card(x, t.key)).join('') : '<div class="pf-tray-empty">No hay campos ocultos</div>'}</div>`;
    if (f) {
      const s = SYS[f.key] || {}, inGrid = !FIXED.includes(f.key);
      const canReq = (f.custom || !s.noReq) && f.key !== 'name';
      const full = isFull(f, t.key);
      $('pf-panel').innerHTML = `
        <div class="pf-prop-h"><button class="pf-back" data-a="desel" aria-label="Volver"><i class="fa-solid fa-arrow-left"></i></button><b>${esc(fieldLabel(f))}</b><span class="pf-tag" style="opacity:1">${f.custom ? KIND_LABEL[f.kind] : 'Del sistema'}</span></div>
        ${s.noLabel && !f.custom ? '<p class="pf-hint">Este bloque no tiene título propio: se puede mostrar u ocultar.</p>' : `<div><label class="pf-lbl" for="pf-flabel">Título del campo</label><input class="pf-in" id="pf-flabel" data-ff="label" value="${esc(f.label || '')}" placeholder="${esc(f.custom ? 'Ej: Sede' : s.label)}" maxlength="60"></div>`}
        ${f.custom && f.kind === 'jira' ? `<p class="pf-hint">Busca tickets de los proyectos elegidos en Admin › Jira API. Al guardar, el contenido queda vinculado con los tickets elegidos (el vínculo se guarda en Cronos: en Jira no cambia nada).${JIRA_ON ? '' : ' <b>Ahora Jira está apagado para Promos, así que este campo no se muestra.</b>'}</p>` : ''}
        ${f.custom && f.kind === 'select' ? `<div><label class="pf-lbl" for="pf-fopts">Opciones</label><textarea class="pf-in" id="pf-fopts" data-ff="options" placeholder="Una por línea">${esc((f.options || []).join('\n'))}</textarea></div>` : ''}
        ${f.key === 'category' ? `<div><label class="pf-lbl" for="pf-cats">Opciones</label><textarea class="pf-in" id="pf-cats" data-cat="1" placeholder="Una por línea">${esc(draft.category_options.join('\n'))}</textarea><p class="pf-hint" style="margin-top:4px">Son las mismas en todas las categorías.</p></div>` : ''}
        ${inGrid ? `<div><span class="pf-lbl">Ancho</span><div class="pf-sw"><button data-a="w-half" class="${full ? '' : 'on'}">Media fila</button><button data-a="w-full" class="${full ? 'on' : ''}">Fila completa</button></div></div>` : ''}
        ${canReq ? `<label class="pf-tg"><input type="checkbox" data-ff="required" ${f.required ? 'checked' : ''}> Obligatorio</label>` : ''}
        <div class="pf-acts">
          ${f.key === 'name' ? '' : `<button class="pf-btn" data-a="feye"><i class="fa-solid ${f.hidden ? 'fa-eye' : 'fa-eye-slash'}"></i> ${f.hidden ? 'Mostrar' : 'Ocultar'}</button>`}
          ${f.custom ? '<button class="pf-btn red" data-a="fdel"><i class="fa-solid fa-trash-can"></i> Borrar</button>' : ''}
        </div>` + trayHtml;
      return;
    }
    $('pf-panel').innerHTML = `
      <div class="pf-h4" style="margin-left:0">Agregar campo</div>
      <p class="pf-hint">Arrástralo al formulario, o tócalo para sumarlo al final.</p>
      <div class="pf-pals">${Object.entries(KIND_LABEL).filter(([k]) => k !== 'jira' || JIRA_ON).map(([k, l]) => `<div class="pf-pal" data-kind="${k}" role="button" tabindex="0"><i class="fa-solid ${KIND_ICON[k]}"></i>${l}</div>`).join('')}</div>` + trayHtml;
  }

  // ── Arrastrar (pointer events: anda con mouse y con el dedo) ──
  function onDown(e) {
    if (e.button !== 0) return;
    const el = e.target.closest('#pf-ed .pf-card[data-k], #pf-ed .pf-pal[data-kind]');
    if (!el || (el.classList.contains('pf-fixed') && !el.closest('.pf-tray'))) return;
    if (e.pointerType !== 'mouse' && !e.target.closest('.pf-grip, .pf-pal')) return;
    dnd = { el, x: e.clientX, y: e.clientY, on: false };
  }
  function startDrag(e) {
    const el = dnd.el, r = el.getBoundingClientRect();
    dnd.on = true;
    dnd.key = el.dataset.k || null; dnd.kind = el.dataset.kind || null;
    dnd.fromGrid = !!el.closest('#pf-grid');
    const g = el.cloneNode(true); g.classList.add('pf-ghost'); g.classList.remove('on');
    g.style.width = Math.min(r.width, 320) + 'px'; document.body.appendChild(g);
    dnd.ghost = g; dnd.dx = Math.min(e.clientX - r.left, 300); dnd.dy = e.clientY - r.top;
    const ph = document.createElement('div');
    ph.className = 'pf-ph' + ((dnd.fromGrid && el.classList.contains('full')) || dnd.kind === 'textarea' ? ' full' : '');
    if (!dnd.fromGrid && dnd.key) { const f = fByKey(dnd.key); if (f && isFull(f, curT().key)) ph.classList.add('full'); }
    dnd.ph = ph;
    if (dnd.fromGrid) { el.after(ph); el.style.display = 'none'; }
    document.documentElement.classList.add('pf-dragging');
  }
  function moveDrag(e) {
    const { ghost, ph } = dnd;
    ghost.style.left = (e.clientX - dnd.dx) + 'px'; ghost.style.top = (e.clientY - dnd.dy) + 'px';
    const grid = $('pf-grid'), tray = $('pf-tray');
    const at = document.elementFromPoint(e.clientX, e.clientY);
    if (tray) tray.classList.toggle('hot', !!(at && at.closest('#pf-tray')) && dnd.key !== 'name' && !dnd.kind);
    if (at && at.closest('#pf-tray')) { if (ph.parentNode) ph.remove(); }
    else if (at && grid && at.closest('.pf-sheet')) {
      const tgt = at.closest('#pf-grid > .pf-card');
      if (tgt && tgt !== dnd.el) {
        const r = tgt.getBoundingClientRect();
        const before = tgt.classList.contains('full') || getComputedStyle(grid).gridTemplateColumns.split(' ').length < 2
          ? e.clientY < r.top + r.height / 2 : e.clientX < r.left + r.width / 2;
        if (before) tgt.before(ph); else tgt.after(ph);
      } else if (!at.closest('#pf-grid') || at === grid) {
        // Debajo de todo o en un hueco: al final
        const cards = [...grid.children].filter(c => c !== ph && c.style.display !== 'none');
        const lastC = cards[cards.length - 1];
        if (!lastC || e.clientY > lastC.getBoundingClientRect().bottom) grid.appendChild(ph);
        else if (!ph.parentNode) grid.appendChild(ph);
      }
    }
    // Desplazar el lienzo cerca de los bordes
    let cv = $('pf-canvas'); if (cv.scrollHeight <= cv.clientHeight + 1) cv = document.querySelector('#pf-ed .pf-body');
    const cr = cv.getBoundingClientRect();
    if (e.clientY < cr.top + 40) cv.scrollTop -= 12; else if (e.clientY > cr.bottom - 40) cv.scrollTop += 12;
  }
  function endDrag() {
    const t = curT(), { ph, ghost } = dnd, tray = $('pf-tray');
    ghost.remove(); document.documentElement.classList.remove('pf-dragging');
    const toTray = tray && tray.classList.contains('hot');
    if (toTray && dnd.key) { const f = fByKey(dnd.key); if (f) f.hidden = true; if (selKey === dnd.key) selKey = null; }
    else if (ph.parentNode) {
      let f = dnd.key ? fByKey(dnd.key) : null;
      if (dnd.kind) { f = newField(dnd.kind); selKey = f.key; }
      if (f) {
        f.hidden = false;
        if (!FIXED.includes(f.key)) {
          // Nuevo orden: lo que quedó en la grilla, después el resto en el orden que tenía
          const seq = [...$('pf-grid').children].filter(c => c !== dnd.el).map(c => c === ph ? f.key : c.dataset.k);
          const pos = new Map(seq.map((k, i) => [k, i]));
          const inSeq = t.fields.filter(x => pos.has(x.key)).sort((a, b) => pos.get(a.key) - pos.get(b.key));
          if (!inSeq.includes(f)) inSeq.splice(pos.get(f.key), 0, f);
          t.fields = inSeq.concat(t.fields.filter(x => !pos.has(x.key) && x !== f));
        }
      }
    }
    dnd = null; justDragged = true; setTimeout(() => { justDragged = false; }, 0);
    drawCanvas(); drawPanel();
  }
  document.addEventListener('pointermove', e => {
    if (!dnd) return;
    if (!dnd.on) { if (Math.hypot(e.clientX - dnd.x, e.clientY - dnd.y) < 6) return; startDrag(e); }
    e.preventDefault(); moveDrag(e);
  }, { passive: false });
  document.addEventListener('pointerup', () => { if (dnd && dnd.on) endDrag(); else dnd = null; });
  document.addEventListener('pointercancel', () => { if (dnd && dnd.on) endDrag(); else dnd = null; });

  function newField(kind) {
    const t = curT();
    const f = { key: 'x_' + Math.random().toString(36).slice(2, 8), label: '', custom: true, kind, required: false, hidden: false };
    if (kind === 'select') f.options = [];
    if (kind === 'jira') f.label = 'Ticket de Jira';
    t.fields.push(f);
    return f;
  }
  const swap = (a, i, k) => { if (k < 0 || k >= a.length) return false; [a[i], a[k]] = [a[k], a[i]]; return true; };
  function focusLabel() { const i = $('pf-flabel'); if (i) { i.focus(); i.select(); } }
  function onClick(e) {
    if (e.target.id === 'pf-ed') return closeEditor();
    if (justDragged) return;
    const c = e.target.closest('#pf-ed .pf-card[data-k]');
    if (c) { selKey = c.dataset.k; drawCanvas(); drawPanel(); return; }
    const pal = e.target.closest('.pf-pal[data-kind]');
    if (pal) {
      // Tocar un tipo: entra al final de la grilla, antes de Portada/Gráficas/Autor
      const f = newField(pal.dataset.kind), t = curT();
      t.fields.pop();
      let at = t.fields.findIndex(x => !x.hidden && ['cover', 'graphics', 'author', 'prod', 'cx'].includes(x.key));
      if (at < 0) at = t.fields.length;
      t.fields.splice(at, 0, f); selKey = f.key;
      drawCanvas(); drawPanel(); focusLabel(); return;
    }
    const b = e.target.closest('[data-a]'); if (!b) return;
    const a = b.dataset.a, T = draft.types, t = curT(), i = +b.dataset.i;
    if (a === 'close') return closeEditor();
    if (a === 'save') return save();
    if (a === 'reset') { if (confirm('¿Volver los formularios a como venían? Se borran las categorías y campos nuevos (los contenidos ya cargados no se tocan).')) save(true); return; }
    if (a === 'sel') { sel = i; selKey = null; iconsOpen = false; }
    else if (a === 'tup' || a === 'tdn') { e.stopPropagation(); if (swap(T, i, a === 'tup' ? i - 1 : i + 1)) sel = a === 'tup' ? i - 1 : i + 1; }
    else if (a === 'tadd') {
      const name = prompt('Nombre de la nueva categoría (ej: Eventos):'); if (!name || !name.trim()) return;
      let key = slug(name) || 'cat'; if (/^\d/.test(key)) key = 'c_' + key;
      const base = key; let n = 2; while (T.some(x => x.key === key) || ['all', 'x'].includes(key)) key = base + '_' + n++;
      const hiddenNew = ['multi', 'category', 'landing', 'mailing'];
      T.push({ key, label: name.trim().slice(0, 40), icon: 'fa-star', color: '#0ea5e9', hidden: false, builtin: false,
        fields: ['name', 'project', 'multi', 'media', 'category', 'landing', 'mailing', 'cover', 'graphics', 'author', 'prod', 'cx'].map(k => ({ key: k, hidden: hiddenNew.includes(k) })) });
      sel = T.length - 1; selKey = null;
    }
    else if (a === 'tdel') { if (!confirm(`¿Borrar la categoría «${t.label}»?`)) return; T.splice(sel, 1); sel = Math.max(0, sel - 1); selKey = null; }
    else if (a === 'icons') { iconsOpen = !iconsOpen; drawCanvas(); return; }
    else if (a === 'icon') { t.icon = b.dataset.ic; iconsOpen = false; }
    else if (a === 'desel') selKey = null;
    else if (a === 'feye') { const f = fByKey(selKey); if (f) f.hidden = !f.hidden; }
    else if (a === 'fdel') { const f = fByKey(selKey); if (!f || !confirm(`¿Borrar el campo «${fieldLabel(f)}»? Lo ya cargado en ese campo deja de mostrarse.`)) return; t.fields.splice(t.fields.indexOf(f), 1); selKey = null; }
    else if (a === 'w-half' || a === 'w-full') { const f = fByKey(selKey); if (f) f.width = a === 'w-full' ? 'full' : 'half'; }
    else return;
    draw();
  }
  function onInput(e) {
    const el = e.target, t = curT(); if (!t) return;
    if (el.dataset.f === 'label') { t.label = el.value; const r = document.querySelector('.pf-trow.on .pf-tl'); if (r) r.textContent = el.value; const ch = document.querySelector('.pf-chip'); if (ch) ch.lastChild.textContent = el.value; }
    else if (el.dataset.f === 'color') { t.color = el.value; if (e.type === 'change') draw(); }
    else if (el.dataset.f === 'hidden') { t.hidden = el.checked; drawSide(); }
    else if (el.dataset.ff) {
      const f = fByKey(selKey); if (!f) return;
      if (el.dataset.ff === 'label') f.label = el.value;
      else if (el.dataset.ff === 'required') f.required = el.checked;
      else if (el.dataset.ff === 'options') f.options = el.value.split(/\n|,/).map(s => s.trim()).filter(Boolean);
      drawCanvas();
    }
    else if (el.dataset.cat) { draft.category_options = el.value.split(/\n|,/).map(s => s.trim()).filter(Boolean); drawCanvas(); }
  }
  function closeEditor() { const ov = $('pf-ed'); if (ov) ov.style.display = 'none'; }
  async function save(reset) {
    const btn = $('pf-save'), msg = $('pf-msg');
    if (!reset) {
      const empty = draft.types.find(t => !String(t.label).trim()); if (empty) { msg.textContent = 'Hay una categoría sin nombre.'; return; }
      const noOpt = draft.types.flatMap(t => t.fields.map(f => [t, f])).find(([t, f]) => f.custom && !f.hidden && f.kind === 'select' && !f.options?.length);
      if (noOpt) { msg.textContent = `El campo «${fieldLabel(noOpt[1])}» de «${noOpt[0].label}» es una lista sin opciones.`; return; }
    }
    btn.disabled = true; msg.textContent = '';
    try {
      const r = await fetch(PR_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(reset ? { action: 'forms_save', reset: 1 } : { action: 'forms_save', config: draft }) });
      const d = await r.json();
      if (!d.ok) { msg.textContent = d.error || 'No se pudo guardar'; return; }
      cfg = window.PF_CONFIG = d.config;
      refreshTypes(); apply(formType); updateCounters(); renderItems();
      if (typeof promoToast === 'function') promoToast('✓ Formularios guardados');
      closeEditor();
    } catch (e) { msg.textContent = 'No se pudo guardar. Revisa la conexión.'; }
    finally { btn.disabled = false; }
  }
  window.pfOpenEditor = openEditor;
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('pf-ed')?.style.display === 'flex') closeEditor(); });

  // ── Arranque ──
  refreshTypes();
  apply(formType);
  updateCounters();
  renderItems();
  if (window.PF_ADMIN) {
    const head = document.querySelector('#form-container .fc-head');
    if (head) { const b = document.createElement('button'); b.type = 'button'; b.className = 'pf-admin-btn'; b.innerHTML = '<i class="fa-solid fa-sliders"></i> Armar formularios'; b.onclick = openEditor; head.querySelector('.fc-close')?.before(b); }
    const bar = document.querySelector('.inner-bar .view-toggle');
    if (bar) { const b = document.createElement('button'); b.type = 'button'; b.className = 'pf-admin-btn'; b.title = 'Elegir las categorías y los campos del formulario'; b.innerHTML = '<i class="fa-solid fa-sliders"></i> Formularios'; b.onclick = openEditor; bar.before(b); }
  }
})();
