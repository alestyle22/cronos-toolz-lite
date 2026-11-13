/* portal.js — Portal de Solicitudes (Trabajo › Solicitudes): departamentos, formulario por pasos o completo, cálculo en vivo,
   calendario de carga, envío y la ficha de cada solicitud. Habla con portal_api.php. Usa las piezas de trabajo.js
   ($, esc, api, toast, av, emptyBox, MON) y form_render.js (PTForm). Doc: toolz/SOLICITUDES.md */
'use strict';

const PT = { boot: null, form: null, key: null, cal: null };
const ptApi = (action, data = {}) => api(action, data, 'portal_api.php');
const ptFmt = (m, unit = 'h') => m === null || m === undefined ? '—' : unit === 'min' ? `${m} min` : PTFmt.fmtMin(m);
const ptDate = d => { if (!d) return '—'; const [y, m, dd] = d.slice(0, 10).split('-'); return `${+dd} ${MON[+m - 1]}${y !== String(new Date().getFullYear()) ? ' ' + y : ''}`; };
const ptDow = d => ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][new Date(d + 'T12:00:00').getDay()];
const ptPerson = id => (PT.boot?.people || []).find(p => p.id === id) || (W.users || []).find(u => u.id === id);
const ptStage = id => (PT.boot?.stages || []).find(s => s.id === id) || { id, name: id, color: '#8b8b94', icon: 'fa-circle' };
const ptPrio = id => (PT.boot?.priorities || []).find(p => p.id === id) || { id, name: id, color: '#8b8b94' };
const ptChip = s => `<span class="pt-stage" style="--c:${esc(s.color)}"><i class="fa-solid ${esc(s.icon)}"></i>${esc(s.name)}</span>`;
/** Links clicables en un texto ya escapado (p. ej. los que se pegan desde OpenCloud). */
const ptLinkify = h => h.replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]'"]/g, u => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);
const ptSk = n => Array.from({ length: n }, () => '<div class="pt-sk"></div>').join('');
let ptBootP = null;   // un solo pedido aunque lo pidan varios a la vez (el arranque de solicitudes/app.js y el de acá)
function ptLoadBoot(force = false) {
  if (force || !ptBootP) ptBootP = ptApi('boot').then(b => (PT.boot = b)).catch(e => { ptBootP = null; throw e; });
  return ptBootP;
}

// Secciones nuevas de Gestión de trabajo (el menú las muestra según el rol)
const ptOldRequests = SECS.solicitudes;   // el panel anterior: solo para el historial de solicitudes con aprobación (g7_wk_requests)
SECS.solicitudes = renderPortal;
SECS.gestion = (a, b) => window.renderManage ? renderManage(a, b) : Promise.resolve();
SECS['portal-config'] = (a, b) => window.renderPortalConfig ? renderPortalConfig(a, b) : Promise.resolve();
SECS['portal-metricas'] = a => window.renderPortalMetrics ? renderPortalMetrics(a) : Promise.resolve();
ptLoadBoot().then(b => {
  const agent = b.all || b.me.teams.length > 0;
  const cfg = b.admin || b.depts.some(d => ['admin', 'dept_admin', 'responsable'].includes(d.role));
  const show = (sec, on) => { const a = document.querySelector(`#wk-nav [data-sec="${sec}"]`); if (a) a.hidden = !on; };
  show('gestion', agent); show('portal-config', cfg); show('portal-metricas', agent);
  const pend = b.depts.filter(d => d.open !== null).reduce((s, d) => s + (d.open || 0), 0);
  const badge = $('wk-n-pt'); if (badge && pend) { badge.textContent = pend > 99 ? '99+' : pend; badge.hidden = false; }
  if (['gestion', 'portal-config', 'portal-metricas'].includes(location.hash.slice(1).split('/')[0])) route();
}).catch(() => {});

// ── Inicio del portal ────────────────────────────────────────
async function renderPortal(arg, arg2) {
  if (arg === 'd' && +arg2) return renderDept(+arg2);
  if (arg === 'f' && +arg2) return renderFill(+arg2);
  if (arg === 't' && +arg2) return renderPtTicket(+arg2, $('wk-main'));
  if (arg === 'mias') return renderMyPt();
  if (arg === 'historial' || arg === 'bandeja' || (+arg && !arg2)) return ptOldRequests(arg, arg2);   // solicitudes de antes del portal
  if (arg === 'nueva' && +arg2) { location.replace('#solicitudes/f/' + arg2); return; }   // links viejos del panel
  if (arg === 'formularios') { location.replace('#portal-config'); return; }
  const M = $('wk-main');
  M.innerHTML = `<div class="pt-hero-sk">${ptSk(1)}</div><div class="pt-grid">${ptSk(6)}</div>`;
  const b = await ptLoadBoot(true);
  const mine = await ptApi('tickets', { scope: 'mine', per: 6, sort: 'updated', dir: 'desc' });
  const approvals = b.depts.some(d => ['admin', 'dept_admin', 'responsable'].includes(d.role)) ? await ptApi('tickets', { scope: 'approval', per: 6 }) : null;
  const me = ptPerson(b.me.id);
  const cards = b.depts.filter(d => d.portal_on || d.role !== 'requester');
  M.innerHTML = `
    <div class="pt-hero">
      <div><span class="pt-kicker"><i class="fa-solid fa-inbox"></i> Portal de solicitudes</span>
        <h1>¿Qué necesitás${me ? ', ' + esc((me.name || '').split(' ')[0]) : ''}?</h1>
        <p>Elegí el departamento, completá el pedido y mirá al momento cuánto lleva y cuándo puede estar.</p></div>
      <div class="pt-hero-a">
        <label class="pt-search"><i class="fa-solid fa-magnifying-glass"></i><input id="pt-q" placeholder="Buscar un departamento o pedido…" autocomplete="off"></label>
        <div class="pt-hero-links">
          <a class="wk-btn" href="#solicitudes/mias"><i class="fa-solid fa-paper-plane"></i> Mis solicitudes</a>
          ${b.all || b.me.teams.length ? '<a class="wk-btn" href="#gestion"><i class="fa-solid fa-list-check"></i> Recibidas</a>' : ''}
          ${b.admin || b.depts.some(d => ['admin', 'dept_admin'].includes(d.role)) ? '<a class="wk-btn" href="#portal-config"><i class="fa-solid fa-sliders"></i> Configurar</a>' : ''}
          ${b.old_requests ? '<a class="wk-btn" href="#solicitudes/historial" title="Solicitudes con aprobación de antes del portal"><i class="fa-solid fa-clock-rotate-left"></i> Historial</a>' : ''}
        </div>
      </div>
    </div>
    ${approvals && approvals.total ? `<div class="pt-alert"><i class="fa-solid fa-stamp"></i><div><b>${approvals.total} solicitud${approvals.total > 1 ? 'es esperan' : ' espera'} tu aprobación</b><span>${approvals.rows.slice(0, 3).map(t => esc(t.code + ' · ' + t.subject)).join(' — ')}</span></div><a class="wk-btn sm" href="#gestion/aprobar">Revisar</a></div>` : ''}
    <h2 class="pt-h2">Departamentos <small>${cards.length}</small></h2>
    <div class="pt-grid" id="pt-depts">${cards.map((d, i) => ptDeptCard(d, i)).join('') || `<div class="pt-empty-big"><i class="fa-solid fa-building"></i><b>Todavía no hay departamentos en el portal</b><span>${b.admin ? 'Creá el primero en Configurar.' : 'Cuando administración los configure, aparecen acá.'}</span>${b.admin ? '<a class="wk-btn primary" href="#portal-config/nuevo"><i class="fa-solid fa-plus"></i> Crear departamento</a>' : ''}</div>`}</div>
    <div class="pt-h2-row"><h2 class="pt-h2">Tus últimas solicitudes</h2><a class="wk-link" href="#solicitudes/mias">Ver todas</a></div>
    <div class="pt-mylist">${mine.rows.length ? mine.rows.map(ptMyRow).join('') : `<div class="pt-empty"><i class="fa-regular fa-paper-plane"></i><b>No hiciste pedidos todavía</b><span>Elegí un departamento para empezar.</span></div>`}</div>`;
  $('pt-q').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    M.querySelectorAll('#pt-depts .pt-dept').forEach(c => { c.hidden = q && !c.dataset.q.includes(q); });
  });
  bindPtRows(M);
}
function ptDeptCard(d, i) {
  const load = d.open !== null && d.open !== undefined ? `<span class="pt-dept-open" title="Solicitudes abiertas"><i class="fa-solid fa-layer-group"></i>${d.open}</span>` : '';
  return `<a class="pt-dept" href="#solicitudes/d/${d.id}" style="--c:${esc(d.color)};--i:${i}" data-q="${esc((d.name + ' ' + d.description).toLowerCase())}">
    <span class="pt-dept-bn"${d.banner ? ` style="background-image:url('portal_file.php?img=${esc(d.banner)}')"` : ''}><span class="pt-dept-ic"><i class="fa-solid ${esc(d.icon)}"></i></span>${load}${!d.portal_on ? '<span class="pt-off">Oculto</span>' : ''}</span>
    <span class="pt-dept-b"><b>${esc(d.name)}</b><span>${esc(d.description || 'Pedidos al departamento')}</span>
    <em>${d.forms ? d.forms + ' formulario' + (d.forms > 1 ? 's' : '') : 'Sin formularios publicados'}<i class="fa-solid fa-arrow-right"></i></em></span></a>`;
}
function ptMyRow(t) {
  const s = ptStage(t.stage);
  return `<a class="pt-row" href="#solicitudes/t/${t.id}" style="--c:${esc(s.color)}">
    <span class="pt-row-ic"><i class="fa-solid ${esc(s.icon)}"></i></span>
    <span class="pt-row-m"><b>${esc(t.subject)}</b><small>${esc(t.code)} · ${esc((PT.boot.depts.find(d => d.id === t.team_id) || {}).name || '')} · pedida ${ago(t.created_at)}</small></span>
    ${t.committed_date ? `<span class="pt-row-d${t.late ? ' late' : ''}"><i class="fa-regular fa-calendar"></i>${ptDate(t.committed_date)}</span>` : ''}
    ${ptChip(s)}</a>`;
}
function bindPtRows() {}

// ── Mis solicitudes ──────────────────────────────────────────
async function renderMyPt() {
  const M = $('wk-main');
  await ptLoadBoot();
  W.ptMy = W.ptMy || { open: '1', q: '' };
  const draw = async () => {
    const r = await ptApi('tickets', { scope: 'mine', per: 200, sort: 'updated', dir: 'desc', open: W.ptMy.open, q: W.ptMy.q });
    $('pt-mylist').innerHTML = r.rows.length ? r.rows.map(ptMyRow).join('') : `<div class="pt-empty"><i class="fa-regular fa-folder-open"></i><b>Nada por acá</b><span>${W.ptMy.q ? 'Probá con otra búsqueda.' : 'Cuando hagas un pedido, lo seguís desde acá.'}</span></div>`;
  };
  M.innerHTML = `<div class="pt-crumbs"><a href="#solicitudes">Portal</a><i class="fa-solid fa-chevron-right"></i><span>Mis solicitudes</span></div>
    <div class="wk-h"><div><h1>Mis solicitudes<span>.</span></h1><p>Todo lo que pediste por el portal, con su etapa y su fecha de entrega.</p></div>
    <div class="wk-h-a"><a class="wk-btn primary" href="#solicitudes"><i class="fa-solid fa-plus"></i> Nuevo pedido</a></div></div>
    <div class="pt-bar"><div class="wk-tabs" id="pt-my-tabs">${[['1', 'Abiertas'], ['0', 'Cerradas'], ['', 'Todas']].map(([k, l]) => `<button type="button" data-k="${k}" class="${W.ptMy.open === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <label class="pt-search sm"><i class="fa-solid fa-magnifying-glass"></i><input id="pt-my-q" placeholder="Buscar…" value="${esc(W.ptMy.q)}"></label></div>
    <div class="pt-mylist" id="pt-mylist">${ptSk(4)}</div>`;
  M.querySelectorAll('#pt-my-tabs button').forEach(b => b.addEventListener('click', () => { W.ptMy.open = b.dataset.k; M.querySelectorAll('#pt-my-tabs button').forEach(x => x.classList.toggle('on', x === b)); draw(); }));
  let tq; $('pt-my-q').addEventListener('input', e => { clearTimeout(tq); tq = setTimeout(() => { W.ptMy.q = e.target.value; draw(); }, 250); });
  await draw();
}

// ── Página del departamento ──────────────────────────────────
async function renderDept(id) {
  const M = $('wk-main');
  await ptLoadBoot();
  const r = await ptApi('dept_home', { team_id: id });
  const d = r.dept;
  const roots = r.cats.filter(c => !c.parent_id);
  const lbl = p => p === null ? 'gray' : p <= d.green_pct ? 'green' : p <= d.yellow_pct ? 'yellow' : 'red';
  M.innerHTML = `<div class="pt-crumbs"><a href="#solicitudes">Portal</a><i class="fa-solid fa-chevron-right"></i><span>${esc(d.name)}</span></div>
    <div class="pt-dhero" style="--c:${esc(d.color)}"${d.banner ? ` data-bn="1"` : ''}>
      ${d.banner ? `<span class="pt-dhero-img" style="background-image:url('portal_file.php?img=${esc(d.banner)}')"></span>` : ''}
      <span class="pt-dhero-ic"><i class="fa-solid ${esc(d.icon)}"></i></span>
      <div><h1>${esc(d.name)}</h1><p>${esc(d.description || '')}</p></div>
      ${['admin', 'dept_admin'].includes(r.role) ? `<a class="wk-btn sm pt-dhero-cfg" href="#portal-config/${d.id}"><i class="fa-solid fa-sliders"></i> Configurar</a>` : ''}
    </div>
    <div class="pt-dcols">
      <div>
        <h2 class="pt-h2">¿Qué querés pedir?</h2>
        <div class="pt-forms">${r.forms.map((f, i) => `<a class="pt-form" href="#solicitudes/f/${f.id}" style="--c:${esc(f.color)};--i:${i}"><span class="pt-form-ic"><i class="fa-solid ${esc(f.icon)}"></i></span><span><b>${esc(f.name)}</b><small>${esc(f.description || 'Completá el formulario')}</small></span><i class="fa-solid fa-arrow-right go"></i></a>`).join('')
          || `<div class="pt-empty"><i class="fa-regular fa-file-lines"></i><b>Este departamento todavía no publicó formularios</b>${['admin', 'dept_admin'].includes(r.role) ? `<a class="wk-btn sm" href="#portal-config/${d.id}/formularios">Crear uno</a>` : ''}</div>`}</div>
        ${roots.length ? `<h2 class="pt-h2">Lo que hace <small>${d.show_estimate ? 'con su tiempo base' : ''}</small></h2>
          <div class="pt-catlist">${roots.map(c => { const kids = r.cats.filter(x => x.parent_id === c.id); const fl = c.form_id && r.forms.some(f => f.id === c.form_id); return `<${fl ? `a href="#solicitudes/f/${c.form_id}"` : 'div'} class="pt-catc${fl ? ' link' : ''}" style="--c:${esc(c.color)}"><span class="ic">${c.image ? `<span style="background-image:url('portal_file.php?img=${esc(c.image)}')"></span>` : `<i class="fa-solid ${esc(c.icon || 'fa-shapes')}"></i>`}</span><div><b>${esc(c.name)}</b>${c.description ? `<small>${esc(c.description)}</small>` : ''}
            ${kids.length ? `<ul>${kids.map(k => `<li><span>${esc(k.name)}</span>${d.show_estimate && k.base_min ? `<em>${PTFmt.fmtMin(k.base_min)}</em>` : ''}</li>`).join('')}</ul>` : (d.show_estimate && c.base_min ? `<em class="one">${PTFmt.fmtMin(c.base_min)}</em>` : '')}${fl ? '<span class="pt-catgo">Pedir <i class="fa-solid fa-arrow-right"></i></span>' : ''}</div></${fl ? 'a' : 'div'}>`; }).join('')}</div>` : ''}
      </div>
      <aside class="pt-side">
        <div class="wk-card"><h3><i class="fa-solid fa-gauge-high"></i> Disponibilidad <small>próximos 14 días</small></h3>
          <div class="pt-strip">${r.load.map(x => `<span class="${x.work ? lbl(x.pct) : 'gray'}" data-tip="${esc(ptDow(x.day) + ' ' + ptDate(x.day) + ' · ' + (x.work ? (x.pct ?? 0) + ' % ocupado' : 'no laborable'))}"><i style="--h:${x.work ? Math.min(100, x.pct || 2) : 0}%"></i><b>${ptDow(x.day)[0].toUpperCase()}</b></span>`).join('')}</div>
          <div class="pt-legend"><span class="green">Libre</span><span class="yellow">Ajustado</span><span class="red">Lleno</span><span class="gray">No laborable</span></div></div>
      </aside>
    </div>`;
}

// ── Llenar un formulario ─────────────────────────────────────
async function renderFill(formId) {
  const M = $('wk-main');
  M.innerHTML = `<div class="pt-fill">${ptSk(5)}</div>`;
  await ptLoadBoot();
  const r = await ptApi('form_get', { form_id: formId });
  const steps = r.schema.mode === 'steps';
  const st = PT.form = { r, formId, step: 0, date: r.draft?.date || null, est: null, cal: null, key: 'pt' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10), dirty: false, sending: false };
  const theme = r.theme || {};
  M.innerHTML = `<div class="pt-crumbs"><a href="#solicitudes">Portal</a><i class="fa-solid fa-chevron-right"></i><a href="#solicitudes/d/${r.dept.id}">${esc(r.dept.name)}</a><i class="fa-solid fa-chevron-right"></i><span>${esc(r.form.name)}</span></div>
    <div class="pt-fill" data-bg="${esc(theme.bg_style || 'plain')}">
      <div class="pt-fill-main">
        <div class="pt-fcard" id="pt-fcard">
          ${theme.cover ? `<div class="pt-cover" style="background-image:url('${esc(theme.cover)}');height:${theme.cover_h || 140}px"></div>` : `<div class="pt-cover plain" style="--c:${esc(theme.accent || r.form.color)}"></div>`}
          <div class="pt-fhead"><span class="pt-fhead-ic" style="--c:${esc(theme.accent || r.form.color)}"><i class="fa-solid ${esc(r.form.icon)}"></i></span><div><h1>${esc(r.form.name)}</h1>${r.form.description ? `<p>${esc(r.form.description)}</p>` : ''}</div></div>
          ${steps ? '<div class="pt-steps" id="pt-steps"></div>' : ''}
          <div id="pt-fbody"></div>
          <div class="pt-date-block" id="pt-date"${steps ? ' hidden' : ''}></div>
          <div class="pt-review" id="pt-review" hidden></div>
          <div class="pt-fnav" id="pt-fnav"></div>
        </div>
      </div>
      <aside class="pt-fill-side" id="pt-est"></aside>
    </div>`;
  const uploader = (file, onP) => ptUpload(file, onP);
  st.form = new PTForm($('pt-fbody'), { schema: r.schema, theme, cats: r.cats, members: r.members, people: PT.boot.people, priorities: PT.boot.priorities,
    answers: r.draft?.answers || {}, title: r.draft?.title || '', uploader, showEstimate: r.dept.show_estimate, onChange: () => { st.dirty = true; ptEstimateSoon(); } });
  // en el modo completo, el fondo y la tipografía del tema van a toda la tarjeta
  $('pt-fcard').classList.add('ptf'); st.form.el.classList.remove('ptf');
  const fc = $('pt-fcard'); ['--ptf-accent', '--ptf-bg', '--ptf-card', '--ptf-text', '--ptf-radius', '--ptf-gap', '--ptf-font'].forEach(k => { const v = st.form.el.style.getPropertyValue(k); if (v) fc.style.setProperty(k, v); });
  fc.dataset.btn = st.form.el.dataset.btn;
  if (r.draft) toast('Seguís desde tu borrador');
  if (steps) st.step = Math.min(r.draft?.step || 0, ptStepList().length - 1);
  ptDrawStep();
  ptEstimate();
  window.onbeforeunload = () => st.dirty && !st.sent ? true : undefined;
}
/** Pasos: las secciones visibles + Fecha + Resumen (modo por pasos). */
function ptStepList() {
  const st = PT.form;
  const secs = st.form.activeSections().map(i => ({ kind: 'sec', i, title: st.r.schema.sections[i].title || 'Paso', icon: st.r.schema.sections[i].icon }));
  return [...secs, { kind: 'date', title: 'Fecha', icon: 'fa-calendar-days' }, { kind: 'review', title: 'Resumen', icon: 'fa-list-check' }];
}
function ptDrawStep(dir = 0) {
  const st = PT.form, steps = st.r.schema.mode === 'steps';
  const nav = $('pt-fnav');
  if (!steps) {
    st.form.render(null);
    ptDrawDate();
    nav.innerHTML = `<button type="button" class="wk-btn" id="pt-draft"><i class="fa-regular fa-floppy-disk"></i> Guardar y seguir después</button><span class="sp"></span><span class="pt-est-mini" id="pt-est-mini"></span><button type="button" class="pt-btn primary" id="pt-send"><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button>`;
  } else {
    const list = ptStepList(); st.step = Math.max(0, Math.min(st.step, list.length - 1));
    const cur = list[st.step];
    $('pt-steps').innerHTML = `<div class="pt-prog"><i style="--p:${Math.round(st.step * 100 / (list.length - 1))}%"></i></div><ol>${list.map((s, i) => `<li class="${i < st.step ? 'done' : i === st.step ? 'on' : ''}"><button type="button" data-go="${i}"${i > st.step ? ' disabled' : ''}><span>${i < st.step ? '<i class="fa-solid fa-check"></i>' : i + 1}</span><em>${esc(s.title)}</em></button></li>`).join('')}</ol>`;
    $('pt-steps').querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => { st.step = +b.dataset.go; ptDrawStep(-1); }));
    const body = $('pt-fbody'), date = $('pt-date'), rev = $('pt-review');
    body.hidden = cur.kind !== 'sec'; date.hidden = cur.kind !== 'date'; rev.hidden = cur.kind !== 'review';
    if (cur.kind === 'sec') {
      const sec = st.r.schema.sections[cur.i];
      st.form.render(cur.i, `<header class="ptf-step-h"><span class="ptf-sec-ic"><i class="fa-solid ${esc(sec.icon || 'fa-circle-dot')}"></i></span><div><h3>${esc(sec.title || 'Paso ' + (st.step + 1))}</h3>${sec.desc ? `<p>${esc(sec.desc)}</p>` : ''}</div></header>`);
    }
    if (cur.kind === 'date') ptDrawDate();
    if (cur.kind === 'review') ptDrawReview();
    const pane = cur.kind === 'sec' ? body : cur.kind === 'date' ? date : rev;
    if (dir) pane.animate?.([{ opacity: 0, transform: `translateX(${dir * 18}px)` }, { opacity: 1, transform: 'none' }], { duration: 260, easing: 'cubic-bezier(.2,.7,.2,1)' });
    const last = st.step === list.length - 1;
    nav.innerHTML = `${st.step ? '<button type="button" class="wk-btn" id="pt-back"><i class="fa-solid fa-arrow-left"></i> Volver</button>' : ''}<button type="button" class="wk-btn ghost" id="pt-draft"><i class="fa-regular fa-floppy-disk"></i> Guardar y seguir después</button><span class="sp"></span><span class="pt-est-mini" id="pt-est-mini"></span>
      ${last ? '<button type="button" class="pt-btn primary" id="pt-send"><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button>' : `<button type="button" class="pt-btn primary" id="pt-next">Continuar <i class="fa-solid fa-arrow-right"></i></button>`}`;
    $('pt-back')?.addEventListener('click', () => { st.step--; ptDrawStep(-1); });
    $('pt-next')?.addEventListener('click', () => {
      if (cur.kind === 'sec') { const e = st.form.validate(cur.i); if (Object.keys(e).length) { ptShake(st.form.firstError()); return; } }
      if (cur.kind === 'date' && !ptDateOk()) return;
      st.step++; ptDrawStep(1); window.scrollTo({ top: Math.max(0, $('pt-fcard').offsetTop - 70), behavior: 'smooth' });
    });
  }
  $('pt-draft').addEventListener('click', ptSaveDraft);
  $('pt-send')?.addEventListener('click', () => ptSubmit());
  ptMini();
}
function ptShake(el) { if (!el) return; el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.animate?.([{ transform: 'translateX(0)' }, { transform: 'translateX(-6px)' }, { transform: 'translateX(6px)' }, { transform: 'translateX(0)' }], { duration: 300 }); el.querySelector('input,textarea,select,button')?.focus({ preventScroll: true }); }
function ptDateOk() {
  const st = PT.form;
  if (st.est && st.est.has_work && !st.date) { toast('Elegí en el calendario la fecha en que lo necesitás', true); $('pt-date').querySelector('.pt-cal')?.animate?.([{ boxShadow: '0 0 0 0 rgba(239,68,68,.5)' }, { boxShadow: '0 0 0 8px rgba(239,68,68,0)' }], { duration: 600 }); return false; }
  return true;
}
async function ptSaveDraft() {
  const st = PT.form;
  try { await ptApi('draft_save', { form_id: st.formId, answers: st.form.values(), title: st.form.title, step: st.step, date: st.date }); st.dirty = false; toast('Borrador guardado: podés seguir cuando quieras'); }
  catch (e) { toast(e.message, true); }
}
/** Sube un archivo en partes de 1 MB. */
async function ptUpload(file, onP) {
  const s = await ptApi('upload_start', { name: file.name, size: file.size });
  const step = s.chunk || 1048576;
  for (let off = 0; off < file.size; off += step) {
    const buf = new Uint8Array(await file.slice(off, off + step).arrayBuffer());
    let bin = ''; for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
    await ptApi('upload_chunk', { token: s.token, offset: off, data: btoa(bin) });
    onP && onP(Math.min(100, (off + buf.length) * 100 / file.size));
  }
  return ptApi('upload_done', { token: s.token });
}

// Cálculo en vivo (en el servidor, con unos milisegundos de espera para no llamar en cada tecla)
let ptEstT;
function ptEstimateSoon() { clearTimeout(ptEstT); ptEstT = setTimeout(ptEstimate, 380); }
async function ptEstimate() {
  const st = PT.form; if (!st) return;
  const box = $('pt-est'); if (!box) return;
  box.classList.add('loading');
  let r;
  try { r = await ptApi('estimate', { form_id: st.formId, answers: st.form.values() }); } catch (e) { box.classList.remove('loading'); return; }
  const changed = !st.est || st.est.work_min !== r.work_min;
  st.est = r; box.classList.remove('loading');
  const unit = r.unit;
  box.innerHTML = `<div class="pt-estc">
    <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Tu pedido</h3>
    ${r.show ? `<div class="pt-est-big"><span>Tiempo de trabajo</span><b class="${changed ? 'pop' : ''}">${r.has_work ? ptFmt(r.work_min, unit) : '—'}</b></div>
      ${r.lines.length ? `<ul class="pt-est-lines">${r.lines.map(([t, op, v]) => `<li class="op-${op}"><span>${esc(t)}</span><em>${op === 'mult' ? (v >= 0 ? '+' : '') + ptFmt(v, unit) : op === 'clamp' ? '= ' + ptFmt(v, unit) : op === 'base' || op === 'replace' ? ptFmt(v, unit) : '+' + ptFmt(v, unit)}</em></li>`).join('')}</ul>` : '<p class="pt-muted">Elegí qué necesitás para ver el cálculo.</p>'}`
      : '<p class="pt-muted">El departamento calcula el tiempo cuando recibe el pedido.</p>'}
    <div class="pt-est-row"><span><i class="fa-regular fa-calendar-check"></i> Entrega posible</span><b>${r.earliest ? ptDow(r.earliest) + ' ' + ptDate(r.earliest) : r.has_work ? 'Sin lugar cercano' : '—'}</b></div>
    ${r.show && r.calendar_days ? `<div class="pt-est-row"><span><i class="fa-regular fa-hourglass-half"></i> Tiempo calendario</span><b>${r.calendar_days} día${r.calendar_days > 1 ? 's' : ''}</b></div>` : ''}
    <div class="pt-est-row"><span><i class="fa-solid fa-flag"></i> Fecha pedida</span><b>${st.date ? ptDow(st.date) + ' ' + ptDate(st.date) : '<em>Sin elegir</em>'}</b></div>
    <p class="pt-est-note"><i class="fa-solid fa-shield-halved"></i> Lo calcula el sistema con la configuración del departamento.</p></div>`;
  ptMini();
  if (changed && st.cal) ptCalLoad();
}

/** Resumen corto del cálculo junto a los botones (en pantallas chicas el panel queda abajo). */
function ptMini() {
  const r = PT.form?.est, mini = $('pt-est-mini'); if (!mini) return;
  mini.innerHTML = r && r.has_work ? `<i class="fa-solid fa-wand-magic-sparkles"></i>${r.show ? ptFmt(r.work_min, r.unit) + ' · ' : ''}${r.earliest ? 'entrega posible ' + ptDow(r.earliest) + ' ' + ptDate(r.earliest) : ''}` : '';
}

// ── Fecha con el calendario de carga ─────────────────────────
function ptDrawDate() {
  const st = PT.form, box = $('pt-date');
  const today = PT.boot.today;
  st.calMonth = st.calMonth || (st.date || st.est?.earliest || today).slice(0, 7);
  box.innerHTML = `<header class="ptf-step-h"><span class="ptf-sec-ic"><i class="fa-solid fa-calendar-days"></i></span><div><h3>¿Para cuándo lo necesitás?</h3><p>Los colores muestran cómo está la carga del departamento si lo pedís para ese día.</p></div></header>
    <div class="pt-datewrap"><div class="pt-cal" id="pt-cal"></div><div class="pt-daydet" id="pt-daydet"><div class="pt-daydet-empty"><i class="fa-regular fa-hand-pointer"></i>Tocá un día para ver la capacidad y elegirlo.</div></div></div>
    <div class="pt-legend"><span class="green">Hay lugar</span><span class="yellow">Ajustado</span><span class="red">Lleno (sobrecarga)</span><span class="gray">No disponible</span></div>`;
  st.cal = true;
  ptCalLoad();
}
async function ptCalLoad(day = PT.form?.date) {
  const st = PT.form; const el = $('pt-cal'); if (!st || !el) return;
  const [y, m] = st.calMonth.split('-').map(Number);
  const from = `${st.calMonth}-01`, last = new Date(y, m, 0).getDate(), to = `${st.calMonth}-${String(last).padStart(2, '0')}`;
  el.classList.add('loading');
  let r;
  try { r = await ptApi('calendar', { form_id: st.formId, answers: st.form.values(), from, to, day: day && day.slice(0, 7) === st.calMonth ? day : null }); }
  catch (e) { el.classList.remove('loading'); el.innerHTML = `<div class="pt-empty">${esc(e.message)}</div>`; return; }
  st.calData = r;
  ptCalDraw(el, r, st.calMonth, st.date, d => { st.date = d; st.dirty = true; ptCalLoad(d); ptEstimate(); }, mm => { st.calMonth = mm; ptCalLoad(); });
  if (r.detail) ptDayDetail($('pt-daydet'), r, d => { st.date = d; st.calMonth = d.slice(0, 7); ptCalLoad(d); ptEstimate(); });
}
/** Grilla de un mes con colores. onPick(día), onMonth('aaaa-mm'). */
function ptCalDraw(el, r, month, selected, onPick, onMonth) {
  const [y, m] = month.split('-').map(Number);
  const first = new Date(y, m - 1, 1).getDay(), pad = (first + 6) % 7;
  const prev = new Date(y, m - 2, 1), next = new Date(y, m, 1);
  const mm = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  const why = { past: 'Ya pasó', off: 'No laborable', lead: 'Antes de la anticipación mínima', max: 'Demasiado lejos', out: '' };
  el.classList.remove('loading');
  el.innerHTML = `<div class="pt-cal-h"><button type="button" class="pt-ic" data-m="${mm(prev)}" aria-label="Mes anterior"${mm(prev) < PT.boot.today.slice(0, 7) ? ' disabled' : ''}><i class="fa-solid fa-chevron-left"></i></button><b>${['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][m - 1]} ${y}</b><button type="button" class="pt-ic" data-m="${mm(next)}" aria-label="Mes siguiente"><i class="fa-solid fa-chevron-right"></i></button></div>
    <div class="pt-cal-g">${['L', 'M', 'X', 'J', 'V', 'S', 'D'].map(x => `<span class="dw">${x}</span>`).join('')}${'<span></span>'.repeat(pad)}${r.days.map(d => {
      const n = +d.day.slice(8), dis = d.color === 'gray';
      const tip = dis ? (d.closed && d.why === 'off' ? d.closed : why[d.why] || 'No disponible') : `${d.pct_plan ?? d.pct} % ocupado con tu pedido · ${PTFmt.fmtMin(d.free)} libres`;
      return `<button type="button" class="pt-day ${d.color}${d.day === selected ? ' sel' : ''}${d.day === PT.boot.today ? ' today' : ''}" data-day="${d.day}"${dis ? ' disabled' : ''} data-tip="${esc(tip)}"><span>${n}</span>${!dis && d.cap ? `<i style="--h:${Math.min(100, d.pct_plan ?? 0)}%"></i>` : ''}</button>`;
    }).join('')}</div>`;
  el.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => onMonth(b.dataset.m)));
  el.querySelectorAll('[data-day]').forEach(b => b.addEventListener('click', () => onPick(b.dataset.day)));
}
/** Detalle del día elegido: capacidad, comprometido, disponible, horas nuevas, % proyectado, pendientes y alternativas. */
function ptDayDetail(box, r, onAlt, opts = {}) {
  const d = r.detail; if (!d) return;
  const color = { green: 'Hay lugar', yellow: 'Ajustado', red: 'Lleno: quedaría con sobrecarga', gray: 'No disponible' }[d.color];
  const plan = Object.entries(d.plan || {});
  box.innerHTML = `<div class="pt-dd ${esc(d.color)}">
    <div class="pt-dd-h"><b>${ptDow(d.day)} ${ptDate(d.day)}</b><span class="pt-dd-st">${color}</span></div>
    <div class="pt-dd-meter"><i class="c" style="--w:${d.cap ? Math.min(100, d.committed * 100 / d.cap) : 0}%"></i><i class="n" style="--l:${d.cap ? Math.min(100, d.committed * 100 / d.cap) : 0}%;--w:${d.cap ? Math.max(0, Math.min(100 - d.committed * 100 / d.cap, (d.plan?.[d.day] || 0) * 100 / d.cap)) : 0}%"></i></div>
    <dl class="pt-dd-kv">
      <div><dt>Capacidad</dt><dd>${PTFmt.fmtMin(d.cap)}</dd></div>
      <div><dt>Comprometido</dt><dd>${PTFmt.fmtMin(d.committed)}</dd></div>
      <div><dt>Disponible</dt><dd>${PTFmt.fmtMin(d.free)}</dd></div>
      <div><dt>${opts.ticket ? 'Lo que falta' : 'Tu pedido'}</dt><dd>${PTFmt.fmtMin(r.work_min)}</dd></div>
      <div class="wide"><dt>Ocupación proyectada <small>(comprometido + ${opts.ticket ? 'esto' : 'tu pedido'}) ÷ capacidad</small></dt><dd class="pct">${d.pct ?? '—'} %</dd></div>
      <div class="wide"><dt>Solicitudes ese día</dt><dd>${d.tickets || 0}</dd></div>
    </dl>
    ${plan.length > 1 ? `<div class="pt-dd-plan"><span>El trabajo se reparte así:</span>${plan.map(([day, m]) => `<em>${ptDow(day)} ${+day.slice(8)} · ${PTFmt.fmtMin(m)}</em>`).join('')}</div>` : ''}
    ${d.over ? `<div class="pt-dd-warn"><i class="fa-solid fa-triangle-exclamation"></i><span>No entra completo: quedarían <b>${PTFmt.fmtMin(d.over)}</b> de sobrecarga. ${r.overload === 'leads' && !opts.manager ? 'Este departamento solo acepta sobrecarga de sus responsables: elegí otra fecha.' : r.overload === 'reason' ? 'Podés pedirlo igual contando el motivo.' : 'Podés pedirlo igual: queda registrado.'}</span></div>` : ''}
    ${r.alternatives && r.alternatives.length ? `<div class="pt-dd-alt"><span>${d.over || d.color === 'yellow' ? 'Fechas con lugar:' : 'Otras fechas con lugar:'}</span>${r.alternatives.map(a => `<button type="button" class="pt-alt ${a.color}" data-alt="${a.day}"><i class="fa-regular fa-calendar"></i>${ptDow(a.day)} ${ptDate(a.day)} <small>${a.pct} %</small></button>`).join('')}</div>` : ''}
    ${r.people && r.people.length ? `<div class="pt-dd-people">${r.people.map(p => `<span class="${p.off ? 'off' : ''}" title="${esc(p.off || '')}">${av(ptPerson(p.id), 20)}<b>${esc(p.name.split(' ')[0])}</b><em>${p.off ? esc(p.off) : PTFmt.fmtMin(Math.max(0, p.cap - p.committed)) + ' libres'}</em></span>`).join('')}</div>` : ''}
  </div>`;
  box.querySelectorAll('[data-alt]').forEach(b => b.addEventListener('click', () => onAlt(b.dataset.alt)));
}

// ── Resumen y envío ──────────────────────────────────────────
function ptDrawReview() {
  const st = PT.form, s = st.form.summary(), e = st.est || {};
  $('pt-review').innerHTML = `<header class="ptf-step-h"><span class="ptf-sec-ic"><i class="fa-solid fa-list-check"></i></span><div><h3>Revisá tu pedido</h3><p>Si algo no está bien, volvé al paso y cambialo.</p></div></header>
    <div class="pt-rev"><div class="pt-rev-t"><span>Título</span><b>${esc(st.form.title || '—')}</b></div>
    ${s.map(g => `<div class="pt-rev-g"><h4>${esc(g.title || '')}</h4><dl>${g.rows.map(([l, v, c]) => `<div><dt>${esc(l)}</dt><dd>${c ? `<i class="sw" style="background:${esc(c)}"></i>` : ''}${esc(v)}</dd></div>`).join('')}</dl></div>`).join('')}
    <div class="pt-rev-g"><h4>Entrega</h4><dl><div><dt>Fecha pedida</dt><dd>${st.date ? ptDow(st.date) + ' ' + ptDate(st.date) : '—'}</dd></div>${e.show && e.has_work ? `<div><dt>Tiempo de trabajo</dt><dd>${ptFmt(e.work_min, e.unit)}</dd></div>` : ''}</dl></div></div>`;
}
async function ptSubmit(extra = {}) {
  const st = PT.form; if (st.sending) return;
  const errs = st.form.validate(null);
  if (Object.keys(errs).length) {
    if (st.r.schema.mode === 'steps') { const list = ptStepList(); const id = Object.keys(errs)[0]; const k = list.findIndex(s => s.kind === 'sec' && (id === '__title' ? s.i === 0 : PTCond.items({ sections: [st.r.schema.sections[s.i]] }).some(x => x.it.id === id))); if (k >= 0) { st.step = k; ptDrawStep(-1); setTimeout(() => { st.form.validate(st.r.schema.sections.length ? list[k].i : null); ptShake(st.form.firstError()); }, 50); } }
    else ptShake(st.form.firstError());
    toast('Revisá los campos marcados', true); return;
  }
  if (!ptDateOk()) { if (st.r.schema.mode === 'steps') { st.step = ptStepList().findIndex(s => s.kind === 'date'); ptDrawStep(-1); } return; }
  const btn = $('pt-send'); st.sending = true; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando…';
  try {
    const r = await ptApi('submit', { form_id: st.formId, key: st.key, title: st.form.title, answers: st.form.values(), date: st.date, ...extra });
    st.sent = true; st.dirty = false; window.onbeforeunload = null;
    ptDone(r);
  } catch (e) {
    st.sending = false; btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Enviar pedido';
    const d = e.data || {};
    if (d.errors) { st.form.setErrors(d.errors); if (d.errors.__date) toast(d.errors.__date + ': ' + e.message, true); else toast(e.message, true); ptShake(st.form.firstError()); return; }
    if (d.overload) return ptOverloadDialog(e.message, d);
    toast(e.message, true);
  }
}
/** Fecha llena: alternativas o mandar igual (con motivo si el departamento lo pide). */
function ptOverloadDialog(msg, d) {
  const st = PT.form, dlg = $('wk-dlg');
  dlg.innerHTML = `<div class="wk-dlg-in pt-ovl"><span class="pt-ovl-ic"><i class="fa-solid fa-triangle-exclamation"></i></span><h3>Ese día ya está lleno</h3><p>${esc(msg)}</p>
    ${d.alternatives?.length ? `<div class="pt-ovl-alts"><span>Estas fechas tienen lugar:</span>${d.alternatives.map(a => `<button type="button" class="pt-alt ${a.color}" data-alt="${a.day}"><i class="fa-regular fa-calendar"></i>${ptDow(a.day)} ${ptDate(a.day)} <small>${a.pct} %</small></button>`).join('')}</div>` : ''}
    ${!d.blocked && d.need_reason ? `<label class="pt-lbl">¿Por qué lo necesitás ese día?</label><textarea class="wk-in pt-ta" id="pt-ovl-r" rows="3" maxlength="500" placeholder="Ej.: es para un lanzamiento que no se puede mover"></textarea>` : ''}
    <div class="acts"><button type="button" class="wk-btn" data-x>Elegir otra fecha</button>${d.blocked ? '' : '<button type="button" class="wk-btn danger" id="pt-ovl-go"><i class="fa-solid fa-paper-plane"></i> Pedirlo igual</button>'}</div></div>`;
  dlg.showModal();
  dlg.querySelector('[data-x]').addEventListener('click', () => { dlg.close(); if (st.r.schema.mode === 'steps') { st.step = ptStepList().findIndex(s => s.kind === 'date'); ptDrawStep(-1); } else $('pt-date').scrollIntoView({ behavior: 'smooth' }); });
  dlg.querySelectorAll('[data-alt]').forEach(b => b.addEventListener('click', () => { dlg.close(); st.date = b.dataset.alt; st.calMonth = st.date.slice(0, 7); toast('Elegiste el ' + ptDate(st.date)); if (st.r.schema.mode === 'steps') { st.step = ptStepList().length - 1; ptDrawStep(1); } else ptCalLoad(st.date); ptEstimate(); }));
  $('pt-ovl-go')?.addEventListener('click', () => {
    const reason = $('pt-ovl-r')?.value.trim() || '';
    if (d.need_reason && !reason) { $('pt-ovl-r').focus(); toast('Contá el motivo', true); return; }
    dlg.close(); ptSubmit({ accept_overload: true, overload_reason: reason });
  });
}
function ptDone(r) {
  const st = PT.form;
  $('wk-main').innerHTML = `<div class="pt-done"><div class="pt-done-ic"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></div>
    <h1>¡Pedido enviado!</h1><p>Tu solicitud <b>${esc(r.code)}</b> ya está en la cola de <b>${esc(st.r.dept.name)}</b>${r.stage === 'revision' ? ' y espera aprobación' : ''}.</p>
    <div class="pt-done-kv">${st.r.dept.show_estimate && r.est_min ? `<div><span>Tiempo de trabajo</span><b>${ptFmt(r.est_min, st.r.dept.est_unit)}</b></div>` : ''}${r.date ? `<div><span>Fecha pedida</span><b>${ptDow(r.date)} ${ptDate(r.date)}</b></div>` : ''}${r.over ? `<div class="warn"><span>Sobrecarga</span><b>${PTFmt.fmtMin(r.over)}</b></div>` : ''}</div>
    <div class="pt-done-a"><a class="pt-btn primary" href="#solicitudes/t/${r.id}"><i class="fa-solid fa-eye"></i> Ver mi solicitud</a><a class="wk-btn" href="#solicitudes/d/${st.r.dept.id}">Hacer otro pedido</a></div></div>`;
  PT.form = null;
}

// ── Ficha de una solicitud (quien pide y quien atiende) ──────
async function renderPtTicket(id, box, inDrawer = false) {
  if (!inDrawer) box.innerHTML = `<div class="pt-tk">${ptSk(4)}</div>`;
  await ptLoadBoot();
  const t = await ptApi('ticket', { id });
  const s = ptStage(t.stage), p = ptPrio(t.priority), dept = PT.boot.depts.find(d => d.id === t.team_id) || { name: '', color: '#ff6600' };
  const flow = ['nuevo', 'aprobado', 'progreso', 'en_revision', 'completado'];
  const at = t.stage === 'revision' ? 0 : t.stage === 'info' || t.stage === 'pausado' ? Math.max(1, flow.indexOf('progreso')) : flow.indexOf(t.stage);
  const ffiles = t.files.filter(f => !f.msg_id && !f.field_id);
  const people = id => ptPerson(id);
  box.innerHTML = `${inDrawer ? '' : `<div class="pt-crumbs"><a href="#solicitudes">Portal</a><i class="fa-solid fa-chevron-right"></i>${t.is_req ? '<a href="#solicitudes/mias">Mis solicitudes</a>' : '<a href="#gestion">Recibidas</a>'}<i class="fa-solid fa-chevron-right"></i><span>${esc(t.code)}</span></div>`}
  <div class="pt-tk" style="--c:${esc(s.color)}">
    <header class="pt-tk-h"><div class="pt-tk-hm"><span class="pt-code">${esc(t.code)}</span>${ptChip(s)}<span class="pt-prio" style="--c:${esc(p.color)}"><i class="fa-solid fa-flag"></i>${esc(p.name)}</span>${t.overload ? '<span class="pt-flag over" title="' + esc(t.overload_note || '') + '"><i class="fa-solid fa-fire"></i>Sobrecarga</span>' : ''}${t.late ? '<span class="pt-flag late"><i class="fa-solid fa-clock"></i>Atrasada</span>' : ''}${t.approval === 'pending' ? '<span class="pt-flag appr"><i class="fa-solid fa-stamp"></i>Espera aprobación</span>' : ''}</div>
      <h1>${esc(t.subject)}</h1>
      <p class="pt-tk-sub"><span style="--c:${esc(dept.color)}" class="pt-dot"></span>${esc(dept.name)} · pedida por ${esc(people(t.requester_id)?.name || '—')} ${ago(t.created_at)}</p>
      ${t.stage !== 'cancelado' ? `<ol class="pt-flow">${flow.map((f, i) => `<li class="${i < at ? 'done' : i === at ? 'on' : ''}"><span>${i < at ? '<i class="fa-solid fa-check"></i>' : `<i class="fa-solid ${esc(ptStage(f).icon)}"></i>`}</span><em>${esc(ptStage(f).name)}</em></li>`).join('')}</ol>` : '<div class="pt-cancel"><i class="fa-solid fa-ban"></i> Esta solicitud se canceló.</div>'}
      ${t.stage === 'info' && t.is_req ? '<div class="pt-alert info"><i class="fa-solid fa-circle-question"></i><div><b>Te piden información</b><span>Respondé abajo y la solicitud sigue.</span></div></div>' : ''}
    </header>
    <div class="pt-tk-cols">
      <div class="pt-tk-main">
        ${t.form ? `<div class="wk-card pt-answers"><h3><i class="fa-solid fa-file-lines"></i> Lo que se pidió <small>${esc(t.form.name)} · versión ${t.form.version}</small></h3><div id="pt-ans"></div>${ffiles.length ? `<div class="pt-atts">${ffiles.map(ptFileChip).join('')}</div>` : ''}</div>` : `<div class="wk-card"><h3>Descripción</h3><p class="pt-pre">${esc(t.description)}</p></div>`}
        <div class="wk-card pt-conv"><h3><i class="fa-solid fa-comments"></i> Conversación</h3><div class="pt-msgs" id="pt-msgs">${ptMsgs(t)}</div>
          ${t.stage !== 'cancelado' || t.agent ? `<div class="pt-compose${t.agent ? '' : ' req'}" id="pt-compose">
            ${t.agent ? `<div class="pt-mode"><button type="button" class="on" data-int="0"><i class="fa-solid fa-reply"></i> Respuesta</button><button type="button" data-int="1"><i class="fa-solid fa-lock"></i> Nota interna</button></div>` : ''}
            <textarea class="wk-in pt-ta" id="pt-msg" rows="3" placeholder="${t.agent ? 'Escribí una respuesta… (@ para mencionar)' : 'Escribí un mensaje para el equipo…'}"></textarea>
            <div class="pt-compose-a"><label class="wk-btn sm"><i class="fa-solid fa-paperclip"></i> Adjuntar<input type="file" id="pt-msg-f" multiple hidden></label><span id="pt-msg-files" class="pt-msg-files"></span><span class="sp"></span><button type="button" class="pt-btn primary sm" id="pt-msg-send"><i class="fa-solid fa-paper-plane"></i> Enviar</button></div></div>` : ''}
        </div>
      </div>
      <aside class="pt-tk-side">
        <div class="wk-card pt-kv"><h3><i class="fa-solid fa-circle-info"></i> Datos</h3><dl>
          <div><dt>Responsable</dt><dd>${t.assignee_id ? av(people(t.assignee_id), 20) + ' ' + esc(people(t.assignee_id)?.name || '') : '<em>Sin asignar</em>'}</dd></div>
          <div><dt>Pedida para</dt><dd>${t.requested_date ? ptDow(t.requested_date) + ' ' + ptDate(t.requested_date) : '—'}</dd></div>
          <div><dt>Comprometida</dt><dd class="${t.late ? 'late' : ''}">${t.committed_date ? ptDow(t.committed_date) + ' ' + ptDate(t.committed_date) : '—'}</dd></div>
          ${t.est_min !== null && (t.agent || t.est_detail) ? `<div><dt>Estimado</dt><dd>${PTFmt.fmtMin(t.est_min)}</dd></div>` : ''}
          ${t.agent ? `<div><dt>Real</dt><dd>${PTFmt.fmtMin(t.real_min)}${t.est_min ? `<span class="pt-mini" style="--p:${Math.min(100, Math.round(t.real_min * 100 / t.est_min))}%"></span>` : ''}</dd></div>` : ''}
          ${t.completed_at ? `<div><dt>Completada</dt><dd>${ptDate(t.completed_at)}</dd></div>` : ''}
        </dl>${t.est_detail && t.est_detail.lines?.length ? `<details class="pt-estd"><summary>Cómo se calculó</summary><ul>${t.est_detail.lines.map(([l, op, v]) => `<li><span>${esc(l)}</span><em>${op === 'mult' ? ((v >= 0 ? '+' : '') + PTFmt.fmtMin(v)) : (op === 'base' || op === 'replace' || op === 'clamp' ? '' : '+') + PTFmt.fmtMin(v)}</em></li>`).join('')}</ul></details>` : ''}</div>
        ${t.agent || t.next.length || t.can_approve ? `<div class="wk-card pt-acts" id="pt-acts"></div>` : ''}
        ${t.agent ? `<div class="wk-card"><h3><i class="fa-solid fa-chart-gantt"></i> Carga por día ${t.load.length ? '' : '<small>no ocupa capacidad</small>'}</h3>${t.load.length ? `<div class="pt-loadbars">${t.load.map(l => `<span class="${l.over ? 'over' : ''}" data-tip="${esc(ptDow(l.day) + ' ' + ptDate(l.day) + ' · ' + PTFmt.fmtMin(l.min) + (l.over ? ' (sobrecarga)' : ''))}"><i style="--h:${Math.min(100, l.min * 100 / Math.max(...t.load.map(x => x.min)))}%"></i><b>${+l.day.slice(8)}</b></span>`).join('')}</div>` : ''}</div>
          <div class="wk-card"><h3><i class="fa-regular fa-clock"></i> Horas reales <small>${PTFmt.fmtMin(t.real_min)}</small></h3>
            <form class="pt-time" id="pt-time"><input class="wk-in" type="number" min="1" max="1440" placeholder="min" id="pt-time-m" required><input class="wk-in" type="date" id="pt-time-d" max="${PT.boot.today}" value="${PT.boot.today}"><input class="wk-in" id="pt-time-n" placeholder="Qué hiciste (opcional)" maxlength="300"><button class="wk-btn sm" type="submit"><i class="fa-solid fa-plus"></i></button></form>
            <ul class="pt-timelist">${t.time.map(x => `<li>${av(people(x.user_id), 18)}<span>${PTFmt.fmtMin(x.min)} <small>${ptDate(x.day)}${x.note ? ' · ' + esc(x.note) : ''}</small></span>${x.user_id === PT.boot.me.id || t.manage ? `<button type="button" data-tdel="${x.id}" aria-label="Borrar"><i class="fa-solid fa-xmark"></i></button>` : ''}</li>`).join('') || '<li class="pt-muted">Sin horas cargadas</li>'}</ul></div>
          ${t.task_id ? `<a class="wk-btn sm pt-tasklink" href="/toolz/tareas.php?t=${t.task_id}"><i class="fa-solid fa-table-columns"></i> Abrir su tarea</a>` : ''}` : ''}
      </aside>
    </div>
  </div>`;
  if (t.form) {
    // Respuestas en forma de ficha (con la estructura de la versión con la que nació)
    const cats = (await ptApi('dept_home', { team_id: t.team_id }).catch(() => ({ cats: [] }))).cats || [];
    const f = new PTForm(document.createElement('div'), { schema: t.form.schema, theme: {}, cats, people: PT.boot.people, members: t.members || [], priorities: PT.boot.priorities, answers: t.form.answers, titleField: false });
    const vis = f.vis();
    const groups = t.form.schema.sections.map(sec => ({ title: sec.title, rows: PTCond.items({ sections: [sec] }).map(x => x.it).filter(it => vis[it.id] && t.form.answers[it.id] !== undefined && t.form.answers[it.id] !== '' && !(Array.isArray(t.form.answers[it.id]) && !t.form.answers[it.id].length)) })).filter(g => g.rows.length);
    const val = it => {
      const v = t.form.answers[it.id];
      if (it.type === 'file' || it.type === 'image') return `<div class="pt-atts">${t.files.filter(x => x.field_id === it.id).map(ptFileChip).join('') || esc(v.map(x => x.name).join(', '))}</div>`;
      if (it.type === 'color') return `<i class="sw" style="background:${esc(v)}"></i>${esc(v)}`;
      if (it.type === 'hidden') return `<span class="pt-hid"><i class="fa-solid fa-eye-slash"></i> ${esc(v)}</span>`;
      if (it.type === 'catpick') { const c = cats.find(x => String(x.id) === String(v)); return c ? `<span class="pt-catv" style="--c:${esc(c.color)}"><i class="fa-solid ${esc(c.icon || 'fa-shapes')}"></i>${esc(c.name)}</span>` : esc(v); }
      const one = f.summary().flatMap(g => g.rows).find(r => r[0] === (it.label || '')); return esc(one ? one[1] : Array.isArray(v) ? v.join(', ') : v);
    };
    $('pt-ans').innerHTML = groups.map(g => `<div class="pt-ansg">${groups.length > 1 && g.title ? `<h4>${esc(g.title)}</h4>` : ''}<dl>${g.rows.map(it => `<div class="${it.type === 'textarea' || it.type === 'file' || it.type === 'image' ? 'wide' : ''}"><dt>${esc(it.label || '')}</dt><dd>${val(it)}</dd></div>`).join('')}</dl></div>`).join('') || '<p class="pt-muted">Sin respuestas.</p>';
  }
  ptBindTicket(t, box, inDrawer);
}
function ptFileChip(f) { return `<a class="pt-file" href="portal_file.php?f=${f.id}" target="_blank" rel="noopener">${/^(png|jpe?g|webp|gif)$/.test(f.ext) ? `<img src="portal_file.php?f=${f.id}" alt="" loading="lazy">` : `<i class="fa-solid fa-file"></i>`}<span>${esc(f.name)}</span><small>${PTFmt.fmtSize(f.size)}</small>${f.internal ? '<i class="fa-solid fa-lock" title="Interno"></i>' : ''}</a>`; }
function ptMsgs(t) {
  if (!t.msgs.length) return '<div class="pt-muted">Sin mensajes todavía.</div>';
  return t.msgs.map(m => {
    const files = t.files.filter(f => f.msg_id === m.id);
    if (m.kind === 'event') return `<div class="pt-ev${m.internal ? ' int' : ''}"><i class="fa-solid ${m.internal ? 'fa-lock' : 'fa-circle-dot'}"></i><span><b>${esc(m.user_id ? ptPerson(m.user_id)?.name || '' : 'Sistema')}</b> ${esc(m.body)}</span><time>${ago(m.at)}</time></div>`;
    const mine = m.user_id === PT.boot.me.id;
    return `<div class="pt-msg${mine ? ' me' : ''}${m.internal ? ' int' : ''}">${av(ptPerson(m.user_id), 28)}<div class="pt-bub"><div class="pt-bub-h"><b>${esc(m.ext || ptPerson(m.user_id)?.name || '')}</b>${m.internal ? '<span class="pt-int"><i class="fa-solid fa-lock"></i> Nota interna</span>' : ''}<time>${ago(m.at)}</time></div><p>${ptLinkify(esc(m.body)).replace(/\n/g, '<br>')}</p>${files.length ? `<div class="pt-atts">${files.map(ptFileChip).join('')}</div>` : ''}</div></div>`;
  }).join('');
}
function ptBindTicket(t, box, inDrawer) {
  const reload = () => renderPtTicket(t.id, box, inDrawer).then(() => { if (window.ptManageRefresh) ptManageRefresh(); });
  // Conversación
  let internal = false, files = [];
  box.querySelectorAll('.pt-mode button').forEach(b => b.addEventListener('click', () => { internal = b.dataset.int === '1'; box.querySelectorAll('.pt-mode button').forEach(x => x.classList.toggle('on', x === b)); $('pt-compose').classList.toggle('int', internal); }));
  $('pt-msg-f')?.addEventListener('change', async e => {
    const list = [...e.target.files]; e.target.value = '';
    for (const f of list) { const rec = { name: f.name, uploading: true }; files.push(rec); drawF(); try { Object.assign(rec, await ptUpload(f, p => { rec.pct = p; drawF(); }), { uploading: false }); } catch (er) { files = files.filter(x => x !== rec); toast(er.message, true); } drawF(); }
  });
  const drawF = () => { const el = $('pt-msg-files'); if (el) el.innerHTML = files.map((f, i) => `<span class="pt-chipf">${f.uploading ? `<i class="fa-solid fa-spinner fa-spin"></i>` : '<i class="fa-solid fa-paperclip"></i>'}${esc(f.name)}<button type="button" data-fx="${i}"><i class="fa-solid fa-xmark"></i></button></span>`).join(''); el?.querySelectorAll('[data-fx]').forEach(b => b.addEventListener('click', () => { files.splice(+b.dataset.fx, 1); drawF(); })); };
  const send = async () => {
    const body = $('pt-msg').value.trim();
    if (!body && !files.length) return;
    if (files.some(f => f.uploading)) { toast('Esperá que terminen de subir los archivos', true); return; }
    $('pt-msg-send').disabled = true;
    try { await ptApi('ticket_msg', { id: t.id, body, internal, files: files.map(f => ({ token: f.token, name: f.name })) }); toast(internal ? 'Nota interna guardada' : 'Mensaje enviado'); reload(); }
    catch (e) { toast(e.message, true); $('pt-msg-send').disabled = false; }
  };
  $('pt-msg-send')?.addEventListener('click', send);
  $('pt-msg')?.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(); });
  // Horas
  $('pt-time')?.addEventListener('submit', async e => { e.preventDefault(); try { await ptApi('ticket_time', { id: t.id, minutes: +$('pt-time-m').value, day: $('pt-time-d').value, note: $('pt-time-n').value }); toast('Horas cargadas'); reload(); } catch (er) { toast(er.message, true); } });
  box.querySelectorAll('[data-tdel]').forEach(b => b.addEventListener('click', async () => { try { await ptApi('ticket_time', { id: t.id, del: +b.dataset.tdel }); reload(); } catch (er) { toast(er.message, true); } }));
  // Acciones
  const A = $('pt-acts'); if (!A) return;
  const members = (t.members || []).filter(m => m.works);
  A.innerHTML = `<h3><i class="fa-solid fa-bolt"></i> Acciones</h3>
    ${t.can_approve ? `<div class="pt-appr"><button type="button" class="pt-btn ok" id="pt-ap-ok"><i class="fa-solid fa-check"></i> Aprobar</button><button type="button" class="pt-btn bad" id="pt-ap-no"><i class="fa-solid fa-xmark"></i> Rechazar</button></div>` : ''}
    ${t.next.length ? `<div class="pt-next"><span>Pasar a</span>${t.next.map(n => { const s = ptStage(n); return `<button type="button" class="pt-nx" data-st="${esc(n)}" style="--c:${esc(s.color)}"><i class="fa-solid ${esc(s.icon)}"></i>${esc(s.name)}</button>`; }).join('')}</div>` : ''}
    ${t.manage ? `<label class="pt-lbl">Responsable</label><div class="pt-asg"><select class="wk-in" id="pt-asg"><option value="">Sin asignar</option>${members.map(m => `<option value="${m.id}"${m.id === t.assignee_id ? ' selected' : ''}>${esc(m.name)}</option>`).join('')}</select><button type="button" class="wk-btn sm" id="pt-asg-auto" title="Elegir según disponibilidad, capacidad y especialidad"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto</button></div><div id="pt-asg-why" class="pt-why"></div>
      <label class="pt-lbl">Fecha comprometida y estimación</label><div class="pt-dates"><button type="button" class="wk-btn sm" id="pt-cd"><i class="fa-regular fa-calendar"></i> ${t.committed_date ? ptDate(t.committed_date) : 'Elegir'}</button><span class="pt-estin"><input class="wk-in" type="number" min="0" max="100000" id="pt-est" value="${t.est_min ?? ''}" placeholder="min"><button type="button" class="wk-btn sm" id="pt-est-save">Guardar</button></span></div>` : ''}`;
  const doStage = async (stage, note = '') => { try { await ptApi('ticket_stage', { id: t.id, stage, note }); toast('Listo: pasó a ' + ptStage(stage).name); reload(); } catch (e) { toast(e.message, true); } };
  A.querySelectorAll('[data-st]').forEach(b => b.addEventListener('click', () => {
    const st = b.dataset.st;
    if (st === 'info' || st === 'cancelado' || st === 'pausado') return ptAsk(st === 'info' ? '¿Qué información falta?' : st === 'cancelado' ? '¿Por qué se cancela? (opcional)' : '¿Por qué se pausa? (opcional)', st === 'info', note => doStage(st, note));
    doStage(st);
  }));
  $('pt-ap-ok')?.addEventListener('click', () => ptApproveDialog(t, true, reload));
  $('pt-ap-no')?.addEventListener('click', () => ptApproveDialog(t, false, reload));
  $('pt-asg')?.addEventListener('change', async e => { try { await ptApi('ticket_assign', { id: t.id, user: +e.target.value || 0 }); toast('Responsable actualizado'); reload(); } catch (er) { toast(er.message, true); e.target.value = t.assignee_id || ''; } });
  $('pt-asg-auto')?.addEventListener('click', async () => {
    try {
      const pv = await ptApi('auto_preview', { id: t.id });
      $('pt-asg-why').innerHTML = `<ul>${pv.ranking.map((r, i) => `<li class="${r.skip ? 'skip' : i === 0 ? 'win' : ''}">${av(ptPerson(r.uid), 18)}<b>${esc(r.name)}</b><span>${r.skip ? esc(r.skip) : `${r.skill ? '<i class="fa-solid fa-star" title="Especialidad"></i> ' : ''}${PTFmt.fmtMin(r.free)} libres · ${r.pending} pendientes`}</span></li>`).join('')}</ul>${pv.uid ? `<button type="button" class="pt-btn primary sm" id="pt-asg-go">Asignar a ${esc(ptPerson(pv.uid)?.name || '')}</button>` : `<p class="pt-muted">${esc(pv.why)}</p>`}`;
      $('pt-asg-go')?.addEventListener('click', async () => { try { const r = await ptApi('ticket_assign', { id: t.id, user: 'auto' }); toast('Asignado: ' + (ptPerson(r.assigned.uid)?.name || '')); reload(); } catch (er) { toast(er.message, true); } });
    } catch (er) { toast(er.message, true); }
  });
  $('pt-est-save')?.addEventListener('click', async () => { try { const r = await ptApi('ticket_dates', { id: t.id, est_min: +$('pt-est').value || 0 }); toast(r.overload ? 'Estimación guardada: queda con sobrecarga' : 'Estimación guardada y carga recalculada'); reload(); } catch (e) { toast(e.message, true); } });
  $('pt-cd')?.addEventListener('click', () => ptDateDialog(t, reload));
}
function ptAsk(title, required, done) {
  const dlg = $('wk-dlg');
  dlg.innerHTML = `<div class="wk-dlg-in"><h3>${esc(title)}</h3><textarea class="wk-in pt-ta" id="pt-ask" rows="3" maxlength="1000"></textarea><div class="acts"><button type="button" class="wk-btn" data-x>Volver</button><button type="button" class="pt-btn primary" id="pt-ask-ok">Confirmar</button></div></div>`;
  dlg.showModal(); $('pt-ask').focus();
  dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
  $('pt-ask-ok').addEventListener('click', () => { const v = $('pt-ask').value.trim(); if (required && !v) { toast('Completá el texto', true); return; } dlg.close(); done(v); });
}
function ptApproveDialog(t, ok, reload) {
  const dlg = $('wk-dlg'), members = (t.members || []).filter(m => m.works);
  dlg.innerHTML = `<div class="wk-dlg-in"><h3>${ok ? 'Aprobar la solicitud' : 'Rechazar la solicitud'}</h3>
    <label class="pt-lbl">${ok ? 'Comentario (opcional)' : 'Motivo del rechazo'}</label><textarea class="wk-in pt-ta" id="pt-ap-n" rows="3" maxlength="1000"></textarea>
    ${ok && t.manage ? `<label class="pt-lbl">Asignar a</label><select class="wk-in" id="pt-ap-u"><option value="">Dejar sin asignar</option><option value="auto">Automático (según disponibilidad)</option>${members.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</select>` : ''}
    <div class="acts"><button type="button" class="wk-btn" data-x>Volver</button><button type="button" class="pt-btn ${ok ? 'ok' : 'bad'}" id="pt-ap-go">${ok ? 'Aprobar' : 'Rechazar'}</button></div></div>`;
  dlg.showModal();
  dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
  $('pt-ap-go').addEventListener('click', async () => {
    const note = $('pt-ap-n').value.trim(); if (!ok && !note) { toast('Contá el motivo', true); return; }
    const u = $('pt-ap-u')?.value || '';
    try { await ptApi('ticket_approve', { id: t.id, decision: ok ? 'approve' : 'reject', note, ...(u ? { user: u === 'auto' ? 'auto' : +u } : {}) }); dlg.close(); toast(ok ? 'Aprobada' : 'Rechazada'); reload(); }
    catch (e) { toast(e.message, true); }
  });
}
/** Cambiar la fecha comprometida con el calendario de carga (sin contar lo que ya ocupa este ticket). */
function ptDateDialog(t, reload) {
  const dlg = $('wk-dlg'); let month = (t.committed_date || PT.boot.today).slice(0, 7), pick = t.committed_date;
  dlg.innerHTML = `<div class="wk-dlg-in pt-dlg-wide"><h3>Fecha comprometida</h3><div class="pt-datewrap"><div class="pt-cal" id="pt-dc"></div><div class="pt-daydet" id="pt-dd"></div></div>
    <label class="pt-lbl">Motivo del cambio (queda en el historial)</label><input class="wk-in" id="pt-dr" maxlength="500">
    <div class="acts"><button type="button" class="wk-btn" data-x>Volver</button><span class="sp"></span><button type="button" class="pt-btn primary" id="pt-dgo" disabled>Guardar la fecha</button></div></div>`;
  dlg.showModal();
  const load = async () => {
    const [y, m] = month.split('-').map(Number);
    const r = await ptApi('calendar', { team_id: t.team_id, ticket_id: t.id, from: month + '-01', to: `${month}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`, day: pick && pick.slice(0, 7) === month ? pick : null });
    ptCalDraw($('pt-dc'), r, month, pick, d => { pick = d; $('pt-dgo').disabled = false; load(); }, mm => { month = mm; load(); });
    if (r.detail) ptDayDetail($('pt-dd'), r, d => { pick = d; month = d.slice(0, 7); $('pt-dgo').disabled = false; load(); }, { ticket: true, manager: true });
  };
  load();
  dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
  $('pt-dgo').addEventListener('click', async () => { try { const r = await ptApi('ticket_dates', { id: t.id, committed_date: pick, reason: $('pt-dr').value }); dlg.close(); toast(r.overload ? 'Fecha guardada: queda con sobrecarga' : 'Fecha guardada y carga recalculada'); reload(); } catch (e) { toast(e.message, true); } });
}
