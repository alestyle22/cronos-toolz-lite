/* portal_admin.js — Portal de Solicitudes, la parte de quien atiende y configura: Recibidas (tabla, kanban, calendario,
   por departamento, por responsable, filtros guardados), Configuración de cada departamento y Métricas. Doc: toolz/SOLICITUDES.md */
'use strict';

const PT_ICONS = ['fa-building', 'fa-palette', 'fa-code', 'fa-bullhorn', 'fa-video', 'fa-calculator', 'fa-users', 'fa-headset', 'fa-pen-nib', 'fa-camera', 'fa-image', 'fa-shapes',
  'fa-film', 'fa-music', 'fa-chart-line', 'fa-briefcase', 'fa-gear', 'fa-truck', 'fa-cart-shopping', 'fa-scale-balanced', 'fa-graduation-cap', 'fa-lightbulb', 'fa-rocket', 'fa-globe',
  'fa-mobile-screen', 'fa-print', 'fa-envelope', 'fa-microphone', 'fa-wand-magic-sparkles', 'fa-layer-group', 'fa-inbox', 'fa-file-lines', 'fa-bolt', 'fa-heart', 'fa-star', 'fa-shield-halved'];
const PT_COLORS = ['#ff6600', '#ef4444', '#f59e0b', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#ec4899', '#64748b'];
const ptIconPick = (cur, name) => `<div class="pt-icons" data-icons="${name}">${PT_ICONS.map(i => `<button type="button" data-i="${i}" class="${i === cur ? 'on' : ''}" aria-label="${i}"><i class="fa-solid ${i}"></i></button>`).join('')}</div>`;
const ptColorPick = (cur, name) => `<div class="pt-colors" data-colors="${name}">${PT_COLORS.map(c => `<button type="button" data-c="${c}" style="--c:${c}" class="${c === cur ? 'on' : ''}" aria-label="${c}"></button>`).join('')}<input type="color" value="${esc(cur || '#ff6600')}" aria-label="Otro color"></div>`;
function ptBindPickers(root, state) {
  root.querySelectorAll('[data-icons]').forEach(g => g.querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => { state[g.dataset.icons] = b.dataset.i; g.querySelectorAll('[data-i]').forEach(x => x.classList.toggle('on', x === b)); })));
  root.querySelectorAll('[data-colors]').forEach(g => {
    g.querySelectorAll('[data-c]').forEach(b => b.addEventListener('click', () => { state[g.dataset.colors] = b.dataset.c; g.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === b)); }));
    g.querySelector('input[type=color]').addEventListener('input', e => { state[g.dataset.colors] = e.target.value; g.querySelectorAll('[data-c]').forEach(x => x.classList.remove('on')); });
  });
}
const ptHm = m => { m = +m || 0; return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`; };
const ptMinOf = s => { const [h, m] = String(s || '').split(':').map(Number); return (h || 0) * 60 + (m || 0); };

// ── Recibidas ────────────────────────────────────────────────
const PTM = { view: 'table', scope: 'dept', f: { open: '1' }, sort: 'date', dir: 'asc', month: null, rows: [], cats: [] };
async function renderManage(arg) {
  await ptLoadBoot();
  if (arg === 'aprobar') PTM.scope = 'approval';
  if (+arg) { setTimeout(() => ptDrawer(+arg), 50); }
  const b = PT.boot, M = $('wk-main');
  const depts = b.depts.filter(d => d.role !== 'requester');
  M.innerHTML = `<div class="wk-h"><div><h1>Solicitudes recibidas<span>.</span></h1><p>Lo que entra por el portal a tus departamentos: aprobá, asigná, mové de etapa y mirá la carga.</p></div>
    <div class="wk-h-a"><a class="wk-btn" href="#portal-metricas"><i class="fa-solid fa-chart-pie"></i> Métricas</a></div></div>
    <div class="pt-mbar">
      <div class="wk-tabs" id="ptm-views">${[['table', 'fa-table-list', 'Tabla'], ['kanban', 'fa-table-columns', 'Kanban'], ['cal', 'fa-calendar-days', 'Calendario'], ['dept', 'fa-building', 'Por departamento'], ['person', 'fa-user-group', 'Por responsable']].map(([k, i, l]) => `<button type="button" data-v="${k}" class="${PTM.view === k ? 'on' : ''}"><i class="fa-solid ${i}"></i> ${l}</button>`).join('')}</div>
      <div class="wk-tabs" id="ptm-scope">${[['dept', 'Mis departamentos'], ['assigned', 'Asignadas a mí'], ['approval', 'Para aprobar']].map(([k, l]) => `<button type="button" data-s="${k}" class="${PTM.scope === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    </div>
    <div class="pt-filters" id="ptm-f">
      <label class="pt-search sm"><i class="fa-solid fa-magnifying-glass"></i><input data-f="q" placeholder="Buscar (T-0012, título…)" value="${esc(PTM.f.q || '')}"></label>
      <select class="wk-in sm" data-f="team_id"><option value="">Todos los departamentos</option>${depts.map(d => `<option value="${d.id}"${+PTM.f.team_id === d.id ? ' selected' : ''}>${esc(d.name)}</option>`).join('')}</select>
      <select class="wk-in sm" data-f="stage"><option value="">Todas las etapas</option>${b.stages.map(s => `<option value="${esc(s.id)}"${PTM.f.stage === s.id ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}</select>
      <select class="wk-in sm" data-f="priority"><option value="">Cualquier prioridad</option>${b.priorities.map(p => `<option value="${esc(p.id)}"${PTM.f.priority === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>
      <select class="wk-in sm" data-f="assignee_id"><option value="">Cualquier responsable</option><option value="0"${PTM.f.assignee_id === '0' ? ' selected' : ''}>Sin asignar</option>${b.people.map(p => `<option value="${p.id}"${String(PTM.f.assignee_id) === String(p.id) ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>
      <select class="wk-in sm" data-f="open"><option value="1"${PTM.f.open === '1' ? ' selected' : ''}>Abiertas</option><option value="0"${PTM.f.open === '0' ? ' selected' : ''}>Cerradas</option><option value=""${PTM.f.open === '' ? ' selected' : ''}>Todas</option></select>
      <button type="button" class="pt-tog${PTM.f.late ? ' on' : ''}" data-t="late"><i class="fa-solid fa-clock"></i> Atrasadas</button>
      <button type="button" class="pt-tog${PTM.f.overload ? ' on' : ''}" data-t="overload"><i class="fa-solid fa-fire"></i> Sobrecarga</button>
      <span class="sp"></span>
      <div class="pt-saved"><button type="button" class="wk-btn sm" id="ptm-saved"><i class="fa-regular fa-bookmark"></i> Filtros</button><div class="pt-pop" id="ptm-saved-pop" hidden></div></div>
    </div>
    <div id="ptm-body" class="pt-mbody">${ptSk(5)}</div>
    <div class="pt-drawer" id="pt-drawer" hidden><div class="pt-drawer-bg" data-x></div><div class="pt-drawer-p" role="dialog" aria-modal="true"><button type="button" class="pt-drawer-x" data-x aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button><div id="pt-drawer-b"></div></div></div>`;
  M.querySelectorAll('#ptm-views [data-v]').forEach(x => x.addEventListener('click', () => { PTM.view = x.dataset.v; M.querySelectorAll('#ptm-views [data-v]').forEach(y => y.classList.toggle('on', y === x)); ptManageRefresh(); }));
  M.querySelectorAll('#ptm-scope [data-s]').forEach(x => x.addEventListener('click', () => { PTM.scope = x.dataset.s; M.querySelectorAll('#ptm-scope [data-s]').forEach(y => y.classList.toggle('on', y === x)); ptManageRefresh(); }));
  let tq;
  M.querySelectorAll('#ptm-f [data-f]').forEach(x => x.addEventListener(x.tagName === 'INPUT' ? 'input' : 'change', () => { clearTimeout(tq); tq = setTimeout(() => { PTM.f[x.dataset.f] = x.value; ptManageRefresh(); }, x.tagName === 'INPUT' ? 260 : 0); }));
  M.querySelectorAll('#ptm-f [data-t]').forEach(x => x.addEventListener('click', () => { PTM.f[x.dataset.t] = PTM.f[x.dataset.t] ? '' : '1'; x.classList.toggle('on', !!PTM.f[x.dataset.t]); ptManageRefresh(); }));
  M.querySelectorAll('#pt-drawer [data-x]').forEach(x => x.addEventListener('click', ptDrawerClose));
  $('ptm-saved').addEventListener('click', ptSavedPop);
  await ptManageRefresh();
}
function ptQuery() {
  const q = { scope: PTM.scope, sort: PTM.sort, dir: PTM.dir, per: 500 };
  for (const [k, v] of Object.entries(PTM.f)) if (v !== '' && v !== undefined && v !== null) q[k] = k === 'stage' ? [v] : v;
  if (PTM.view === 'kanban' && !PTM.f.stage) q.open = PTM.f.open === '0' ? '0' : '';
  if (PTM.view === 'cal') { const m = PTM.month || PT.boot.today.slice(0, 7); const [y, mm] = m.split('-').map(Number); q.from = m + '-01'; q.to = `${m}-${String(new Date(y, mm, 0).getDate()).padStart(2, '0')}`; delete q.open; }
  return q;
}
async function ptManageRefresh() {
  const body = $('ptm-body'); if (!body) return;
  body.classList.add('loading');
  const r = await ptApi('tickets', ptQuery());
  PTM.rows = r.rows; PTM.cats = r.cats;
  body.classList.remove('loading');
  ({ table: ptViewTable, kanban: ptViewKanban, cal: ptViewCal, dept: ptViewDept, person: ptViewPerson })[PTM.view](body, r);
  body.querySelectorAll('[data-open]').forEach(x => x.addEventListener('click', e => { if (e.target.closest('a,button:not([data-open])') && !e.target.closest('[data-open]').isSameNode(e.target.closest('[data-open]'))) return; if (e.ctrlKey || e.metaKey) { window.open('#solicitudes/t/' + x.dataset.open); return; } ptDrawer(+x.dataset.open); }));
}
const ptCat = id => PTM.cats.find(c => c.id === id);
const ptDeptOf = id => PT.boot.depts.find(d => d.id === id) || { name: '—', color: '#8b8b94' };
function ptEmpty(body) { body.innerHTML = `<div class="pt-empty-big"><i class="fa-regular fa-folder-open"></i><b>No hay solicitudes con estos filtros</b><span>Probá sacar algún filtro o cambiar de vista.</span></div>`; }
function ptViewTable(body, r) {
  if (!r.rows.length) return ptEmpty(body);
  const th = (k, l) => `<th${k ? ` data-sort="${k}" class="${PTM.sort === k ? 'on ' + PTM.dir : ''}"` : ''}>${l}${k ? '<i class="fa-solid fa-sort"></i>' : ''}</th>`;
  body.innerHTML = `<div class="pt-tablewrap"><table class="pt-table"><thead><tr>${th('id', 'Solicitud')}${th('', 'Departamento')}${th('', 'Etapa')}${th('priority', 'Prioridad')}${th('', 'Responsable')}${th('date', 'Entrega')}${th('est', 'Estimado / real')}${th('created', 'Ingreso')}</tr></thead><tbody>
    ${r.rows.map((t, i) => { const s = ptStage(t.stage), p = ptPrio(t.priority), c = ptCat(t.category_id), d = ptDeptOf(t.team_id), a = t.assignee_id ? ptPerson(t.assignee_id) : null;
      return `<tr data-open="${t.id}" style="--pc:${esc(p.color)};--i:${Math.min(i, 20)}" class="${t.late ? 'late' : ''}${['completado', 'cancelado'].includes(t.stage) ? ' done' : ''}"><td><div class="pt-t1"><span class="pt-code">${esc(t.code)}</span><b>${esc(t.subject)}</b>${c ? `<small style="--c:${esc(c.color)}"><i class="fa-solid ${esc(c.icon || 'fa-tag')}"></i>${esc(c.name)}</small>` : ''}</div></td>
        <td><span class="pt-dot" style="--c:${esc(d.color)}"></span>${esc(d.name)}</td><td>${ptChip(s)}</td><td><span class="pt-prio" style="--c:${esc(p.color)}"><i class="fa-solid fa-flag"></i>${esc(p.name)}</span></td>
        <td>${a ? av(a, 22) + ' <span class="pt-nm">' + esc(a.name.split(' ')[0]) + '</span>' : '<em class="pt-muted">Sin asignar</em>'}</td>
        <td>${t.committed_date ? `<span class="pt-due${t.late ? ' late' : ''}">${ptDow(t.committed_date)} ${ptDate(t.committed_date)}</span>` : '—'}${t.overload ? ' <i class="fa-solid fa-fire pt-fire" title="Sobrecarga"></i>' : ''}${t.approval === 'pending' ? ' <i class="fa-solid fa-stamp pt-appr-ic" title="Espera aprobación"></i>' : ''}</td>
        <td>${t.est_min !== null ? `<span class="pt-er"><b>${PTFmt.fmtMin(t.est_min)}</b><span class="pt-mini" style="--p:${t.est_min ? Math.min(100, Math.round(t.real_min * 100 / t.est_min)) : 0}%"></span><small>${PTFmt.fmtMin(t.real_min)}</small></span>` : '—'}</td>
        <td class="pt-muted">${ago(t.created_at)}</td></tr>`; }).join('')}</tbody></table></div><div class="pt-foot">${r.total} solicitud${r.total === 1 ? '' : 'es'}</div>`;
  body.querySelectorAll('[data-sort]').forEach(h => h.addEventListener('click', () => { if (PTM.sort === h.dataset.sort) PTM.dir = PTM.dir === 'asc' ? 'desc' : 'asc'; else { PTM.sort = h.dataset.sort; PTM.dir = h.dataset.sort === 'date' ? 'asc' : 'desc'; } ptManageRefresh(); }));
}
function ptCard(t) {
  const p = ptPrio(t.priority), a = t.assignee_id ? ptPerson(t.assignee_id) : null, c = ptCat(t.category_id);
  return `<div class="pt-kcard" data-open="${t.id}" data-id="${t.id}" data-stage="${esc(t.stage)}" style="--pc:${esc(p.color)}" tabindex="0">
    <div class="pt-kcard-h"><span class="pt-code">${esc(t.code)}</span>${t.overload ? '<i class="fa-solid fa-fire pt-fire" title="Sobrecarga"></i>' : ''}${t.approval === 'pending' ? '<i class="fa-solid fa-stamp pt-appr-ic" title="Espera aprobación"></i>' : ''}</div>
    <b>${esc(t.subject)}</b>${c ? `<small style="--c:${esc(c.color)}"><i class="fa-solid ${esc(c.icon || 'fa-tag')}"></i>${esc(c.name)}</small>` : ''}
    <div class="pt-kcard-f">${t.committed_date ? `<span class="pt-due${t.late ? ' late' : ''}"><i class="fa-regular fa-calendar"></i>${ptDate(t.committed_date)}</span>` : '<span></span>'}${t.est_min ? `<span class="pt-muted">${PTFmt.fmtMin(t.est_min)}</span>` : ''}${a ? av(a, 22) : '<span class="pt-noav" title="Sin asignar"><i class="fa-solid fa-user-plus"></i></span>'}</div></div>`;
}
function ptViewKanban(body, r) {
  const cols = PT.boot.stages.filter(s => PTM.f.open === '0' ? ['completado', 'cancelado'].includes(s.id) : PTM.f.open === '1' ? !['completado', 'cancelado'].includes(s.id) : true);
  body.innerHTML = `<div class="pt-kanban">${cols.map(s => { const list = r.rows.filter(t => t.stage === s.id); return `<section class="pt-kcol" data-col="${esc(s.id)}" style="--c:${esc(s.color)}"><header><i class="fa-solid ${esc(s.icon)}"></i><b>${esc(s.name)}</b><span>${list.length}</span></header><div class="pt-kcol-b">${list.map(ptCard).join('') || '<div class="pt-kempty">Sin solicitudes</div>'}</div></section>`; }).join('')}</div>`;
  // Arrastrar entre columnas (eventos de puntero: anda con mouse, lápiz y dedo). Solo transiciones permitidas.
  let drag = null;
  body.querySelectorAll('.pt-kcard').forEach(c => c.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    const start = { x: e.clientX, y: e.clientY };
    const move = ev => {
      if (!drag && Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < 6) return;
      if (!drag) {
        const rc = c.getBoundingClientRect();
        drag = { c, from: c.dataset.stage, ghost: c.cloneNode(true), dx: start.x - rc.left, dy: start.y - rc.top };
        drag.ghost.classList.add('pt-ghost'); drag.ghost.style.width = rc.width + 'px'; document.body.appendChild(drag.ghost); c.classList.add('dragging');
        const ok = PT.boot.trans[drag.from] || [];
        body.querySelectorAll('.pt-kcol').forEach(col => col.classList.toggle('no', col.dataset.col !== drag.from && !ok.includes(col.dataset.col)));
      }
      drag.ghost.style.transform = `translate(${ev.clientX - drag.dx}px,${ev.clientY - drag.dy}px) rotate(2deg)`;
      body.querySelectorAll('.pt-kcol').forEach(col => { const rc = col.getBoundingClientRect(); col.classList.toggle('over', ev.clientX > rc.left && ev.clientX < rc.right && ev.clientY > rc.top && ev.clientY < rc.bottom); });
    };
    let fin = false;
    const up = async () => {
      if (fin) return; fin = true;
      removeEventListener('pointermove', move); removeEventListener('pointerup', up); removeEventListener('mouseup', up);
      if (!drag) return;
      const col = body.querySelector('.pt-kcol.over'); const to = col?.dataset.col; const d = drag; drag = null;
      d.ghost.remove(); d.c.classList.remove('dragging'); body.querySelectorAll('.pt-kcol').forEach(x => x.classList.remove('over', 'no'));
      c.addEventListener('click', ev => ev.stopImmediatePropagation(), { once: true, capture: true });
      if (!to || to === d.from) return;
      if (!(PT.boot.trans[d.from] || []).includes(to)) { toast(`No se puede pasar de ${ptStage(d.from).name} a ${ptStage(to).name}`, true); return; }
      const go = async note => { try { await ptApi('ticket_stage', { id: +d.c.dataset.id, stage: to, note }); toast('Pasó a ' + ptStage(to).name); ptManageRefresh(); } catch (er) { toast(er.message, true); } };
      if (to === 'info') ptAsk('¿Qué información falta?', true, go); else if (to === 'aprobado' && d.c.querySelector('.pt-appr-ic')) ptDrawer(+d.c.dataset.id); else go('');
    };
    addEventListener('pointermove', move); addEventListener('pointerup', up); addEventListener('mouseup', up);
  }));
}
function ptViewCal(body) {
  const m = PTM.month || PT.boot.today.slice(0, 7); PTM.month = m;
  const [y, mm] = m.split('-').map(Number), first = (new Date(y, mm - 1, 1).getDay() + 6) % 7, days = new Date(y, mm, 0).getDate();
  const shift = k => { const d = new Date(y, mm - 1 + k, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; };
  let h = `<div class="pt-mcal-h"><button type="button" class="pt-ic" data-m="${shift(-1)}"><i class="fa-solid fa-chevron-left"></i></button><b>${['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][mm - 1]} ${y}</b><button type="button" class="pt-ic" data-m="${shift(1)}"><i class="fa-solid fa-chevron-right"></i></button><button type="button" class="wk-btn sm" data-m="${PT.boot.today.slice(0, 7)}">Hoy</button></div><div class="pt-mcal">${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(x => `<span class="dw">${x}</span>`).join('')}${'<span class="pad"></span>'.repeat(first)}`;
  for (let d = 1; d <= days; d++) {
    const day = `${m}-${String(d).padStart(2, '0')}`, list = PTM.rows.filter(t => (t.committed_date || t.created_at.slice(0, 10)) === day);
    h += `<div class="pt-mday${day === PT.boot.today ? ' today' : ''}"><span class="n">${d}</span>${list.slice(0, 4).map(t => { const s = ptStage(t.stage); return `<button type="button" class="pt-mchip${t.late ? ' late' : ''}" data-open="${t.id}" style="--c:${esc(s.color)}" title="${esc(t.code + ' · ' + t.subject)}">${esc(t.subject)}</button>`; }).join('')}${list.length > 4 ? `<em>+${list.length - 4} más</em>` : ''}</div>`;
  }
  body.innerHTML = h + '</div>';
  body.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => { PTM.month = b.dataset.m; ptManageRefresh(); }));
}
function ptViewDept(body, r) {
  if (!r.rows.length) return ptEmpty(body);
  const groups = {}; r.rows.forEach(t => (groups[t.team_id] = groups[t.team_id] || []).push(t));
  body.innerHTML = `<div class="pt-groups">${Object.entries(groups).map(([id, list]) => { const d = ptDeptOf(+id); const by = {}; list.forEach(t => by[t.stage] = (by[t.stage] || 0) + 1);
    return `<section class="pt-group" style="--c:${esc(d.color)}"><header><span class="pt-gic"><i class="fa-solid ${esc(d.icon || 'fa-building')}"></i></span><b>${esc(d.name)}</b><span class="pt-muted">${list.length}</span><span class="sp"></span><span class="pt-stack">${PT.boot.stages.filter(s => by[s.id]).map(s => `<i style="--c:${esc(s.color)};flex:${by[s.id]}" data-tip="${esc(s.name + ': ' + by[s.id])}"></i>`).join('')}</span></header><div class="pt-group-b">${list.map(ptCard).join('')}</div></section>`; }).join('')}</div>`;
}
function ptViewPerson(body, r) {
  if (!r.rows.length) return ptEmpty(body);
  const groups = {}; r.rows.forEach(t => (groups[t.assignee_id || 0] = groups[t.assignee_id || 0] || []).push(t));
  const keys = Object.keys(groups).sort((a, b) => (+a === 0) - (+b === 0) || groups[b].length - groups[a].length);
  body.innerHTML = `<div class="pt-people">${keys.map(k => { const list = groups[k], p = +k ? ptPerson(+k) : null, left = list.reduce((s, t) => s + Math.max(0, (t.est_min || 0) - t.real_min), 0);
    return `<section class="pt-pcol"><header>${p ? av(p, 30) : '<span class="pt-noav big"><i class="fa-solid fa-user-plus"></i></span>'}<div><b>${esc(p ? p.name : 'Sin asignar')}</b><small>${list.length} solicitud${list.length > 1 ? 'es' : ''} · ${PTFmt.fmtMin(left)} por hacer</small></div></header><div class="pt-pcol-b">${list.map(ptCard).join('')}</div></section>`; }).join('')}</div>`;
}
async function ptDrawer(id) {
  const d = $('pt-drawer'); if (!d) { location.hash = 'solicitudes/t/' + id; return; }
  d.hidden = false; document.body.classList.add('pt-noscroll');
  requestAnimationFrame(() => d.classList.add('on'));
  $('pt-drawer-b').innerHTML = `<div class="pt-tk">${ptSk(4)}</div>`;
  try { await renderPtTicket(id, $('pt-drawer-b'), true); } catch (e) { $('pt-drawer-b').innerHTML = emptyBox('fa-triangle-exclamation', esc(e.message)); }
  const esc_ = e => { if (e.key === 'Escape' && !$('wk-dlg').open) { ptDrawerClose(); removeEventListener('keydown', esc_); } }; addEventListener('keydown', esc_);
}
function ptDrawerClose() { const d = $('pt-drawer'); if (!d) return; d.classList.remove('on'); document.body.classList.remove('pt-noscroll'); setTimeout(() => { d.hidden = true; }, 220); }
function ptSavedPop() {
  const pop = $('ptm-saved-pop'); pop.hidden = !pop.hidden; if (pop.hidden) return;
  const list = PT.boot.filters || [];
  pop.innerHTML = `${list.length ? `<ul>${list.map((f, i) => `<li><button type="button" data-use="${i}"><i class="fa-regular fa-bookmark"></i>${esc(f.name)}</button><button type="button" data-del="${i}" aria-label="Borrar"><i class="fa-solid fa-xmark"></i></button></li>`).join('')}</ul>` : '<p class="pt-muted">Todavía no guardaste filtros.</p>'}
    <form id="ptm-save"><input class="wk-in sm" placeholder="Nombre del filtro" maxlength="60" required><button class="wk-btn sm" type="submit">Guardar actual</button></form>`;
  const save = async l => { const r = await ptApi('filters_save', { filters: l }); PT.boot.filters = r.filters; ptSavedPop(); ptSavedPop(); };
  pop.querySelectorAll('[data-use]').forEach(b => b.addEventListener('click', () => { const f = list[+b.dataset.use]; PTM.f = Object.assign({ open: '1' }, f.q.f || {}); PTM.view = f.q.view || PTM.view; PTM.scope = f.q.scope || PTM.scope; pop.hidden = true; renderManage(); }));
  pop.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { const l = [...list]; l.splice(+b.dataset.del, 1); save(l); }));
  $('ptm-save').addEventListener('submit', e => { e.preventDefault(); save([...list, { name: e.target.querySelector('input').value, q: { f: PTM.f, view: PTM.view, scope: PTM.scope } }]).then(() => toast('Filtro guardado')); });
}

// ── Configuración de departamentos ───────────────────────────
async function renderPortalConfig(arg, tab) {
  await ptLoadBoot(true);
  const M = $('wk-main'), b = PT.boot;
  if (arg === 'etapas') return ptStagesPage();
  if (+arg) return ptDeptConfig(+arg, tab || 'general');
  const list = b.depts.filter(d => ['admin', 'dept_admin', 'responsable'].includes(d.role));
  M.innerHTML = `<div class="wk-h"><div><h1>Configurar el portal<span>.</span></h1><p>Cada departamento define quién le puede pedir, sus horarios y capacidad, sus categorías con tiempos y sus formularios.</p></div>
    <div class="wk-h-a">${b.admin ? '<a class="wk-btn" href="#portal-config/etapas"><i class="fa-solid fa-diagram-next"></i> Etapas</a><button type="button" class="wk-btn primary" id="pt-new-dept"><i class="fa-solid fa-plus"></i> Nuevo departamento</button>' : ''}</div></div>
    <div class="pt-grid">${list.map((d, i) => `<a class="pt-dept cfg" href="#portal-config/${d.id}" style="--c:${esc(d.color)};--i:${i}"><span class="pt-dept-bn"${d.banner ? ` style="background-image:url('portal_file.php?img=${esc(d.banner)}')"` : ''}><span class="pt-dept-ic"><i class="fa-solid ${esc(d.icon)}"></i></span>${!d.portal_on ? '<span class="pt-off">Oculto</span>' : ''}</span><span class="pt-dept-b"><b>${esc(d.name)}</b><span>${d.forms} formulario${d.forms === 1 ? '' : 's'} · ${d.open ?? 0} abiertas</span><em>Configurar<i class="fa-solid fa-arrow-right"></i></em></span></a>`).join('') || '<div class="pt-empty-big"><i class="fa-solid fa-building"></i><b>No configurás ningún departamento</b></div>'}</div>`;
  $('pt-new-dept')?.addEventListener('click', ptNewDept);
  if (arg === 'nuevo' && b.admin) ptNewDept();
}
function ptNewDept() {
  const dlg = $('wk-dlg'), st = { icon: 'fa-building', color: '#ff6600' };
  dlg.innerHTML = `<div class="wk-dlg-in"><h3>Nuevo departamento</h3><label class="pt-lbl">Nombre</label><input class="wk-in" id="pt-nd-n" maxlength="100" placeholder="Ej.: Diseño, Audiovisual, RR. HH."><label class="pt-lbl">Descripción</label><input class="wk-in" id="pt-nd-d" maxlength="500" placeholder="Qué le pueden pedir">
    <label class="pt-lbl">Ícono</label>${ptIconPick(st.icon, 'icon')}<label class="pt-lbl">Color</label>${ptColorPick(st.color, 'color')}
    <p class="pt-muted">Es un equipo como los de Admin › Equipos: después sumás a su gente y la configurás acá.</p>
    <div class="acts"><button type="button" class="wk-btn" data-x>Cancelar</button><button type="button" class="pt-btn primary" id="pt-nd-go">Crear</button></div></div>`;
  dlg.showModal(); ptBindPickers(dlg, st); $('pt-nd-n').focus();
  dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
  $('pt-nd-go').addEventListener('click', async () => { try { const r = await ptApi('dept_create', { name: $('pt-nd-n').value, description: $('pt-nd-d').value, icon: st.icon, color: st.color }); dlg.close(); toast('Departamento creado'); location.hash = 'portal-config/' + r.id + '/disponibilidad'; } catch (e) { toast(e.message, true); } });
}
async function ptDeptConfig(id, tab) {
  const M = $('wk-main');
  M.innerHTML = ptSk(4);
  const r = await ptApi('dept_get', { team_id: id });
  const d = r.dept, can = r.can_config;
  const tabs = [['general', 'fa-id-card', 'General'], ['disponibilidad', 'fa-clock', 'Horarios y capacidad'], ['equipo', 'fa-users', 'Equipo'], ['ausencias', 'fa-plane', 'Ausencias y feriados'],
    ['categorias', 'fa-shapes', 'Categorías y tiempos'], ['formularios', 'fa-file-lines', 'Formularios'], ['automatizaciones', 'fa-robot', 'Automatizaciones'], ['historial', 'fa-clock-rotate-left', 'Historial']];
  M.innerHTML = `<div class="pt-crumbs"><a href="#portal-config">Configurar</a><i class="fa-solid fa-chevron-right"></i><span>${esc(d.name)}</span></div>
    <div class="pt-cfg-h" style="--c:${esc(d.color)}"><span class="pt-dhero-ic"><i class="fa-solid ${esc(d.icon)}"></i></span><div><h1>${esc(d.name)}</h1><p>${can ? 'Todo lo que cambies acá se aplica al momento. Lo que mueve la capacidad recalcula la carga de las solicitudes abiertas.' : 'Solo lectura: lo configuran administración o la jefatura del departamento.'}</p></div><a class="wk-btn sm" href="#solicitudes/d/${d.id}"><i class="fa-solid fa-eye"></i> Ver como quien pide</a></div>
    <div class="pt-cfg"><nav class="pt-cfg-nav">${tabs.map(([k, i, l]) => `<a href="#portal-config/${id}/${k}" class="${k === tab ? 'on' : ''}"><i class="fa-solid ${i}"></i>${l}</a>`).join('')}</nav><div class="pt-cfg-b" id="pt-cfg-b"></div></div>`;
  const B = $('pt-cfg-b');
  const reload = () => ptDeptConfig(id, tab);
  ({ general: ptCfgGeneral, disponibilidad: ptCfgHours, equipo: ptCfgTeam, ausencias: ptCfgOffs, categorias: ptCfgCats, formularios: ptCfgForms, automatizaciones: ptCfgAutos, historial: ptCfgAudit }[tab] || ptCfgGeneral)(B, r, reload);
  if (!can) B.querySelectorAll('input,select,textarea,button:not(.pt-ro-ok)').forEach(x => { x.disabled = true; });
}
const ptSave = async (B, team_id, dept, msg = 'Guardado') => { try { const r = await ptApi('dept_save', { team_id, dept }); toast(r.recalc ? `${msg}: se recalcularon ${r.recalc} solicitudes` : msg); return true; } catch (e) { toast(e.message, true); return false; } };
function ptCfgGeneral(B, r, reload) {
  const d = r.dept, st = { icon: d.icon, color: d.color, banner: d.banner };
  const teams = (W.teams || []).filter(t => t.id !== d.id);
  B.innerHTML = `<div class="wk-card"><h3>Identidad</h3>
    <div class="pt-2c"><div><label class="pt-lbl">Nombre</label><input class="wk-in" id="g-name" value="${esc(d.name)}" maxlength="100"></div><div><label class="pt-lbl">Mostrar en el portal</label><label class="pt-sw"><input type="checkbox" id="g-on"${d.portal_on ? ' checked' : ''}><span></span>Visible para quien pide</label></div></div>
    <label class="pt-lbl">Descripción</label><textarea class="wk-in pt-ta" id="g-desc" rows="2" maxlength="2000">${esc(d.description)}</textarea>
    <label class="pt-lbl">Ícono</label>${ptIconPick(d.icon, 'icon')}<label class="pt-lbl">Color</label>${ptColorPick(d.color, 'color')}
    <label class="pt-lbl">Banner</label><div class="pt-banner"><span id="g-bn"${d.banner ? ` style="background-image:url('portal_file.php?img=${esc(d.banner)}')"` : ''}>${d.banner ? '' : 'Sin banner'}</span><label class="wk-btn sm"><i class="fa-solid fa-upload"></i> Subir imagen<input type="file" accept="image/png,image/jpeg,image/webp" id="g-bnf" hidden></label>${d.banner ? '<button type="button" class="wk-btn sm" id="g-bnx">Quitar</button>' : ''}</div></div>
    <div class="wk-card"><h3>Quién puede pedir</h3>
      <div class="pt-radios">${[['all', 'Todos', 'Cualquier persona con usuario'], ['teams', 'Algunos equipos', 'Solo la gente de los equipos que elijas'], ['people', 'Algunas personas', 'Solo las personas que elijas']].map(([k, l, s]) => `<label class="pt-radio${d.visibility === k ? ' on' : ''}"><input type="radio" name="g-vis" value="${k}"${d.visibility === k ? ' checked' : ''}><b>${l}</b><small>${s}</small></label>`).join('')}</div>
      <div id="g-req-t" class="pt-checks"${d.visibility === 'teams' ? '' : ' hidden'}>${teams.map(t => `<label><input type="checkbox" value="${t.id}"${d.requesters.teams.includes(t.id) ? ' checked' : ''}>${esc(t.name)}</label>`).join('')}</div>
      <div id="g-req-p" class="pt-checks"${d.visibility === 'people' ? '' : ' hidden'}>${PT.boot.people.map(p => `<label><input type="checkbox" value="${p.id}"${d.requesters.users.includes(p.id) ? ' checked' : ''}>${esc(p.name)}</label>`).join('')}</div>
      <p class="pt-muted">La gente del departamento y administración siempre pueden pedir.</p></div>
    <div class="wk-card"><h3>Cómo se atiende</h3>
      <label class="pt-sw"><input type="checkbox" id="g-appr"${d.approval ? ' checked' : ''}><span></span>Los pedidos necesitan aprobación antes de empezar</label>
      <label class="pt-sw"><input type="checkbox" id="g-auto"${d.auto_assign ? ' checked' : ''}><span></span>Asignación automática según disponibilidad, capacidad y especialidad</label>
      <label class="pt-sw"><input type="checkbox" id="g-est"${d.show_estimate ? ' checked' : ''}><span></span>Mostrar la estimación de tiempo a quien pide</label>
      <div class="pt-2c"><div><label class="pt-lbl">Unidad</label><select class="wk-in" id="g-unit"><option value="h"${d.est_unit === 'h' ? ' selected' : ''}>Horas</option><option value="min"${d.est_unit === 'min' ? ' selected' : ''}>Minutos</option></select></div><div><label class="pt-lbl">Orden en el portal</label><input class="wk-in" type="number" id="g-pos" value="${d.position}" min="0" max="999"></div></div></div>
    <div class="pt-save"><button type="button" class="pt-btn primary" id="g-save"><i class="fa-solid fa-check"></i> Guardar</button></div>`;
  ptBindPickers(B, st);
  B.querySelectorAll('[name=g-vis]').forEach(x => x.addEventListener('change', () => { $('g-req-t').hidden = x.value !== 'teams'; $('g-req-p').hidden = x.value !== 'people'; B.querySelectorAll('.pt-radio').forEach(l => l.classList.toggle('on', l.contains(x) && x.checked)); }));
  $('g-bnf').addEventListener('change', async e => { const f = e.target.files[0]; if (!f) return; try { const n = await ptImgUp(f, r.dept.id); st.banner = n; $('g-bn').style.backgroundImage = `url('portal_file.php?img=${n}')`; $('g-bn').textContent = ''; } catch (er) { toast(er.message, true); } });
  $('g-bnx')?.addEventListener('click', () => { st.banner = ''; $('g-bn').style.backgroundImage = ''; $('g-bn').textContent = 'Sin banner'; });
  $('g-save').addEventListener('click', async () => {
    const vis = B.querySelector('[name=g-vis]:checked').value;
    if (await ptSave(B, r.dept.id, { name: $('g-name').value, portal_on: $('g-on').checked, description: $('g-desc').value, icon: st.icon, color: st.color, banner: st.banner, visibility: vis,
      requesters: { teams: [...$('g-req-t').querySelectorAll('input:checked')].map(x => +x.value), users: [...$('g-req-p').querySelectorAll('input:checked')].map(x => +x.value) },
      approval: $('g-appr').checked, auto_assign: $('g-auto').checked, show_estimate: $('g-est').checked, est_unit: $('g-unit').value, position: +$('g-pos').value })) { PT.boot = null; reload(); }
  });
}
async function ptImgUp(file, team) {
  if (file.size > 3 * 1048576) throw new Error('La imagen tiene que pesar menos de 3 MB');
  const buf = new Uint8Array(await file.arrayBuffer()); let bin = ''; for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
  return (await ptApi('image_up', { team_id: team, data: btoa(bin) })).name;
}
/** Editor de horario semanal: rangos por día. */
function ptHoursEditor(hours, name) {
  return `<div class="pt-hours" data-hours="${name}">${Object.entries(PT_DAYS_ES).map(([k, l]) => { const rs = hours[k] || []; return `<div class="pt-hday${rs.length ? '' : ' off'}" data-d="${k}"><label class="pt-sw sm"><input type="checkbox"${rs.length ? ' checked' : ''}><span></span><b>${l}</b></label><div class="pt-ranges">${rs.map(([a, b]) => ptRange(a, b)).join('')}</div><button type="button" class="pt-ic" data-add aria-label="Agregar rango"><i class="fa-solid fa-plus"></i></button><em class="pt-muted">${PTFmt.fmtMin(rs.reduce((s, [a, b]) => s + ptMinOf(b) - ptMinOf(a), 0))}</em></div>`; }).join('')}</div>`;
}
const PT_DAYS_ES = { 1: 'Lunes', 2: 'Martes', 3: 'Miércoles', 4: 'Jueves', 5: 'Viernes', 6: 'Sábado', 7: 'Domingo' };
const ptRange = (a, b) => `<span class="pt-range"><input type="time" value="${a}" step="900"><i>a</i><input type="time" value="${b}" step="900"><button type="button" data-rm aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></span>`;
function ptBindHours(root) {
  const sum = d => { const m = [...d.querySelectorAll('.pt-range')].reduce((s, r) => { const [a, b] = r.querySelectorAll('input'); return s + Math.max(0, ptMinOf(b.value) - ptMinOf(a.value)); }, 0); d.querySelector('em').textContent = PTFmt.fmtMin(m); };
  root.querySelectorAll('.pt-hday').forEach(d => {
    const bindR = () => { d.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { b.parentElement.remove(); if (!d.querySelector('.pt-range')) { d.querySelector('input[type=checkbox]').checked = false; d.classList.add('off'); } sum(d); }); d.querySelectorAll('input[type=time]').forEach(i => i.onchange = () => sum(d)); };
    d.querySelector('[data-add]').addEventListener('click', () => { const last = [...d.querySelectorAll('.pt-range')].pop(); const a = last ? last.querySelectorAll('input')[1].value : '09:00'; const b = ptHm(Math.min(24 * 60, ptMinOf(a) + 240)); d.querySelector('.pt-ranges').insertAdjacentHTML('beforeend', ptRange(a, b)); d.querySelector('input[type=checkbox]').checked = true; d.classList.remove('off'); bindR(); sum(d); });
    d.querySelector('input[type=checkbox]').addEventListener('change', e => { d.classList.toggle('off', !e.target.checked); if (e.target.checked && !d.querySelector('.pt-range')) d.querySelector('[data-add]').click(); if (!e.target.checked) { d.querySelector('.pt-ranges').innerHTML = ''; sum(d); } });
    bindR();
  });
}
function ptReadHours(root) {
  const out = {}; root.querySelectorAll('.pt-hday').forEach(d => { out[d.dataset.d] = d.querySelector('input[type=checkbox]').checked ? [...d.querySelectorAll('.pt-range')].map(r => [...r.querySelectorAll('input')].map(i => i.value)) : []; }); return out;
}
function ptCfgHours(B, r, reload) {
  const d = r.dept;
  const maxCap = Math.max(1, ...r.next.map(x => Math.max(x.cap, x.committed)));
  B.innerHTML = `<div class="wk-card"><h3>Horario laborable</h3><p class="pt-muted">Las horas de cada integrante salen de acá (salvo que tenga horario propio en Equipo). Los días sin horario se ven en gris.</p>${ptHoursEditor(d.hours, 'dept')}</div>
    <div class="wk-card"><h3>Capacidad y márgenes</h3><div class="pt-3c">
      <div><label class="pt-lbl">Tope diario del departamento <small>(min, 0 = suma del equipo)</small></label><input class="wk-in" type="number" id="h-cap" value="${d.cap_day_min}" min="0"></div>
      <div><label class="pt-lbl">Margen de seguridad <small>%</small></label><input class="wk-in" type="number" id="h-safe" value="${d.safety_pct}" min="0" max="90"></div>
      <div><label class="pt-lbl">Reserva para urgencias <small>%</small></label><input class="wk-in" type="number" id="h-res" value="${d.reserve_pct}" min="0" max="90"></div>
      <div><label class="pt-lbl">Anticipación mínima <small>horas</small></label><input class="wk-in" type="number" id="h-min" value="${d.min_lead_h}" min="0"></div>
      <div><label class="pt-lbl">Anticipación máxima <small>días</small></label><input class="wk-in" type="number" id="h-max" value="${d.max_lead_d}" min="1" max="730"></div>
      <div><label class="pt-lbl">Colores <small>verde hasta / amarillo hasta %</small></label><div class="pt-2i"><input class="wk-in" type="number" id="h-g" value="${d.green_pct}" min="1"><input class="wk-in" type="number" id="h-y" value="${d.yellow_pct}" min="1"></div></div></div>
      <label class="pt-lbl">Si piden para un día lleno</label><div class="pt-radios">${[['warn', 'Advertir y dejar enviar', 'Queda registrada la sobrecarga'], ['reason', 'Pedir un motivo', 'Quien pide cuenta por qué lo necesita ese día'], ['leads', 'Solo responsables', 'Los demás tienen que elegir otra fecha']].map(([k, l, s]) => `<label class="pt-radio${d.overload === k ? ' on' : ''}"><input type="radio" name="h-ov" value="${k}"${d.overload === k ? ' checked' : ''}><b>${l}</b><small>${s}</small></label>`).join('')}</div>
      <p class="pt-muted"><i class="fa-solid fa-circle-info"></i> Las fechas llenas nunca se bloquean del todo: se advierte, se sugieren alternativas y se registra la sobrecarga.</p></div>
    <div class="wk-card"><h3>Excepciones por fecha</h3><p class="pt-muted">Un día con otra capacidad total (por ejemplo, un sábado de entrega). 0 = cerrado.</p>
      <form class="pt-inline" id="h-exc"><input class="wk-in" type="date" required><input class="wk-in" type="number" min="0" placeholder="Minutos" required><input class="wk-in" placeholder="Nota" maxlength="200"><button class="wk-btn sm" type="submit"><i class="fa-solid fa-plus"></i> Agregar</button></form>
      <ul class="pt-list">${r.day_caps.map(x => `<li><i class="fa-regular fa-calendar"></i><b>${ptDow(x.day)} ${ptDate(x.day)}</b><span>${+x.minutes ? PTFmt.fmtMin(+x.minutes) : 'Cerrado'}${x.note ? ' · ' + esc(x.note) : ''}</span><button type="button" class="pt-ic" data-dcx="${x.day}" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></li>`).join('') || '<li class="pt-muted">Sin excepciones</li>'}</ul></div>
    <div class="wk-card"><h3>Los próximos 14 días</h3><div class="pt-next14">${r.next.map(x => `<div class="${x.work ? '' : 'off'}" data-tip="${esc(ptDow(x.day) + ' ' + ptDate(x.day) + (x.work ? ' · capacidad ' + PTFmt.fmtMin(x.cap) + ' · comprometido ' + PTFmt.fmtMin(x.committed) : ' · ' + (x.closed || 'no laborable')))}"><span class="bar"><i class="cap" style="--h:${x.cap * 100 / maxCap}%"></i><i class="com${x.committed > x.cap ? ' over' : ''}" style="--h:${Math.min(100, x.committed * 100 / maxCap)}%"></i></span><b>${ptDow(x.day)}</b><small>${+x.day.slice(8)}</small></div>`).join('')}</div>
      <div class="pt-legend"><span class="cap">Capacidad</span><span class="com">Comprometido</span></div></div>
    <div class="pt-save"><button type="button" class="pt-btn primary" id="h-save"><i class="fa-solid fa-check"></i> Guardar y recalcular</button></div>`;
  ptBindHours(B);
  B.querySelectorAll('[name=h-ov]').forEach(x => x.addEventListener('change', () => B.querySelectorAll('.pt-radio').forEach(l => l.classList.toggle('on', l.contains(x) && x.checked))));
  $('h-save').addEventListener('click', async () => { if (await ptSave(B, d.id, { hours: ptReadHours(B), cap_day_min: +$('h-cap').value, safety_pct: +$('h-safe').value, reserve_pct: +$('h-res').value, min_lead_h: +$('h-min').value, max_lead_d: +$('h-max').value, green_pct: +$('h-g').value, yellow_pct: +$('h-y').value, overload: B.querySelector('[name=h-ov]:checked').value })) reload(); });
  $('h-exc').addEventListener('submit', async e => { e.preventDefault(); const [day, min, note] = [...e.target.querySelectorAll('input')].map(i => i.value); try { await ptApi('daycap_save', { team_id: d.id, day, minutes: +min, note }); toast('Excepción guardada'); reload(); } catch (er) { toast(er.message, true); } });
  B.querySelectorAll('[data-dcx]').forEach(b => b.addEventListener('click', async () => { await ptApi('daycap_save', { team_id: d.id, day: b.dataset.dcx, minutes: null }); reload(); }));
}
function ptCfgTeam(B, r, reload) {
  const d = r.dept, cats = r.cats.filter(c => c.active);
  const others = PT.boot.people.filter(p => !r.members.some(m => m.id === p.id));
  B.innerHTML = `<div class="wk-card"><h3>Integrantes <small>${r.members.length}</small></h3>
    <p class="pt-muted">Quién produce, con qué dedicación y en qué es especialista. Los responsables aprueban, asignan y cambian fechas.</p>
    <div class="pt-members">${r.members.map(m => `<div class="pt-mem" data-u="${m.id}">
      <div class="pt-mem-h">${av(ptPerson(m.id), 34)}<div><b>${esc(m.name)}</b><small>${m.lead ? 'Jefe del departamento' : m.is_resp ? 'Responsable' : 'Miembro'}</small></div><span class="sp"></span>${r.can_config && !m.lead ? `<button type="button" class="pt-ic" data-rm="${m.id}" title="Sacar del departamento"><i class="fa-solid fa-user-minus"></i></button>` : ''}</div>
      <div class="pt-mem-b">
        <label class="pt-sw sm"><input type="checkbox" data-k="works"${m.works ? ' checked' : ''}><span></span>Toma trabajo</label>
        <label class="pt-sw sm"><input type="checkbox" data-k="is_resp"${m.is_resp ? ' checked' : ''}><span></span>Responsable</label>
        <label class="pt-pct">Dedicación <input class="wk-in sm" type="number" min="0" max="100" data-k="cap_pct" value="${m.cap_pct}"> %</label>
        <label class="pt-sw sm"><input type="checkbox" data-k="own"${m.hours ? ' checked' : ''}><span></span>Horario propio</label>
      </div>
      <div class="pt-mem-hours"${m.hours ? '' : ' hidden'}>${ptHoursEditor(m.hours || d.hours, 'm' + m.id)}</div>
      ${cats.length ? `<div class="pt-skills"><span>Especialidad:</span>${cats.map(c => `<label class="pt-skill${m.skills.includes(c.id) ? ' on' : ''}" style="--c:${esc(c.color)}"><input type="checkbox" value="${c.id}"${m.skills.includes(c.id) ? ' checked' : ''}>${esc(c.name)}</label>`).join('')}</div>` : ''}
      <div class="pt-mem-a"><button type="button" class="wk-btn sm" data-save="${m.id}"><i class="fa-solid fa-check"></i> Guardar</button></div></div>`).join('')}</div>
    ${r.can_config ? `<form class="pt-inline" id="t-add"><select class="wk-in"><option value="">Sumar a alguien…</option>${others.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>${PT.boot.admin ? '<label class="pt-sw sm"><input type="checkbox"><span></span>Como jefe</label>' : ''}<button class="wk-btn sm" type="submit"><i class="fa-solid fa-user-plus"></i> Sumar</button></form>` : ''}</div>`;
  ptBindHours(B);
  B.querySelectorAll('.pt-mem').forEach(c => {
    c.querySelector('[data-k=own]').addEventListener('change', e => { c.querySelector('.pt-mem-hours').hidden = !e.target.checked; });
    c.querySelectorAll('.pt-skill input').forEach(x => x.addEventListener('change', () => x.parentElement.classList.toggle('on', x.checked)));
  });
  B.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', async () => {
    const c = b.closest('.pt-mem'), own = c.querySelector('[data-k=own]').checked;
    try { const x = await ptApi('member_save', { team_id: d.id, user_id: +b.dataset.save, works: c.querySelector('[data-k=works]').checked, is_resp: c.querySelector('[data-k=is_resp]').checked, cap_pct: +c.querySelector('[data-k=cap_pct]').value, own_hours: own, hours: own ? ptReadHours(c.querySelector('.pt-mem-hours')) : null, skills: [...c.querySelectorAll('.pt-skill input:checked')].map(i => +i.value) }); toast(x.recalc ? `Guardado: se recalcularon ${x.recalc} solicitudes` : 'Guardado'); }
    catch (e) { toast(e.message, true); }
  }));
  B.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', async () => { if (!confirm('¿Sacar a esta persona del departamento?')) return; try { await ptApi('member_remove', { team_id: d.id, user_id: +b.dataset.rm }); reload(); } catch (e) { toast(e.message, true); } }));
  $('t-add')?.addEventListener('submit', async e => { e.preventDefault(); const u = +e.target.querySelector('select').value; if (!u) return; try { await ptApi('member_add', { team_id: d.id, user_id: u, role: e.target.querySelector('input[type=checkbox]')?.checked ? 'admin' : 'member' }); toast('Sumado al departamento'); reload(); } catch (er) { toast(er.message, true); } });
}
function ptCfgOffs(B, r, reload) {
  const d = r.dept, K = PT.boot.off_kinds;
  const who = o => o.user_id ? (ptPerson(o.user_id)?.name || 'Persona') : o.team_id ? d.name + ' (todo el departamento)' : 'Todos los departamentos';
  B.innerHTML = `<div class="wk-card"><h3>Cargar una ausencia, vacaciones, bloqueo o feriado</h3>
    <form class="pt-off-f" id="o-f"><div class="pt-3c"><div><label class="pt-lbl">Para</label><select class="wk-in" id="o-who"><option value="u">Una persona</option><option value="t">Todo el departamento (bloqueo)</option>${PT.boot.admin ? '<option value="all">Todos los departamentos (feriado)</option>' : ''}</select></div>
      <div id="o-uw"><label class="pt-lbl">Persona</label><select class="wk-in" id="o-u">${r.members.map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</select></div>
      <div><label class="pt-lbl">Tipo</label><select class="wk-in" id="o-k">${Object.entries(K).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></div>
      <div><label class="pt-lbl">Desde</label><input class="wk-in" type="date" id="o-from" required></div><div><label class="pt-lbl">Hasta</label><input class="wk-in" type="date" id="o-to"></div>
      <div><label class="pt-lbl">Parcial <small>(minutos menos por día, vacío = día entero)</small></label><input class="wk-in" type="number" id="o-min" min="1" max="1440"></div></div>
      <label class="pt-lbl">Nota</label><input class="wk-in" id="o-note" maxlength="200"><div class="pt-save"><button class="pt-btn primary" type="submit"><i class="fa-solid fa-plus"></i> Agregar</button></div></form></div>
    <div class="wk-card"><h3>Próximas y recientes</h3><ul class="pt-list">${r.offs.map(o => `<li class="k-${esc(o.kind)}"><i class="fa-solid ${{ feriado: 'fa-flag', vacaciones: 'fa-umbrella-beach', ausencia: 'fa-user-clock', bloqueo: 'fa-ban' }[o.kind] || 'fa-calendar'}"></i><b>${esc(who(o))}</b><span>${esc(K[o.kind] || o.kind)} · ${ptDate(o.from)}${o.to !== o.from ? ' al ' + ptDate(o.to) : ''}${o.minutes ? ' · ' + PTFmt.fmtMin(o.minutes) + ' menos' : ''}${o.note ? ' · ' + esc(o.note) : ''}</span><button type="button" class="pt-ic" data-ox="${o.id}" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button></li>`).join('') || '<li class="pt-muted">Nada cargado</li>'}</ul></div>`;
  const sync = () => { const v = $('o-who').value; $('o-uw').hidden = v !== 'u'; if (v === 'all') $('o-k').value = 'feriado'; else if (v === 't') $('o-k').value = 'bloqueo'; };
  $('o-who').addEventListener('change', sync); sync();
  B.querySelector('#o-f').addEventListener('submit', async e => {
    e.preventDefault(); const v = $('o-who').value;
    try { const x = await ptApi('off_save', { user_id: v === 'u' ? +$('o-u').value : null, team_id: v === 't' ? d.id : null, kind: $('o-k').value, from: $('o-from').value, to: $('o-to').value || $('o-from').value, minutes: $('o-min').value || null, note: $('o-note').value });
      toast(x.affected ? `Guardado: ${x.affected} solicitudes tienen entrega en esas fechas, revisalas` : 'Guardado y carga recalculada'); reload(); }
    catch (er) { toast(er.message, true); }
  });
  B.querySelectorAll('[data-ox]').forEach(b => b.addEventListener('click', async () => { try { await ptApi('off_del', { id: +b.dataset.ox }); reload(); } catch (e) { toast(e.message, true); } }));
}
function ptCfgCats(B, r, reload) {
  const d = r.dept;
  const tree = (pid, lvl) => r.cats.filter(c => (c.parent_id || null) === pid).map(c => `<div class="pt-cat lvl${lvl}${c.active ? '' : ' off'}" style="--c:${esc(c.color)}">
      <span class="ic">${c.image ? `<span style="background-image:url('portal_file.php?img=${esc(c.image)}')"></span>` : `<i class="fa-solid ${esc(c.icon || 'fa-shapes')}"></i>`}</span>
      <div class="m"><b>${esc(c.name)}</b><small>${lvl === 0 ? 'Categoría' : lvl === 1 ? 'Subcategoría' : 'Variante'}${c.base_min ? ' · base ' + PTFmt.fmtMin(c.base_min) : ''}${c.min_min ? ' · mín ' + PTFmt.fmtMin(c.min_min) : ''}${c.max_min ? ' · máx ' + PTFmt.fmtMin(c.max_min) : ''}${c.rules.length ? ' · ' + c.rules.length + ' regla' + (c.rules.length > 1 ? 's' : '') : ''}${c.active ? '' : ' · desactivada'}</small></div>
      ${lvl < 2 && r.can_config ? `<button type="button" class="wk-btn sm" data-add="${c.id}"><i class="fa-solid fa-plus"></i> ${lvl === 0 ? 'Subcategoría' : 'Variante'}</button>` : ''}<button type="button" class="pt-ic" data-ed="${c.id}" aria-label="Editar"><i class="fa-solid fa-pen"></i></button></div>${tree(c.id, lvl + 1)}`).join('');
  B.innerHTML = `<div class="wk-card"><h3>Categorías, subcategorías y variantes <span class="sp"></span>${r.can_config ? '<button type="button" class="wk-btn sm primary" data-add="0"><i class="fa-solid fa-plus"></i> Categoría</button>' : ''}</h3>
    <p class="pt-muted">Cada una puede tener su tiempo base, un mínimo y un máximo, y reglas (sumar, reemplazar, multiplicar, por rangos, alternativas o con condición). En los formularios se eligen con el elemento "Categorías visuales".</p>
    <div class="pt-cats">${tree(null, 0) || '<div class="pt-empty"><i class="fa-solid fa-shapes"></i><b>Sin categorías</b><span>Ejemplo: Placas → simple 1 h, estándar 2 h, avanzada 3 h, animada 4 h, adaptación 30 min.</span></div>'}</div></div>`;
  B.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', () => ptCatDialog(r, { parent_id: +b.dataset.add || null }, reload)));
  B.querySelectorAll('[data-ed]').forEach(b => b.addEventListener('click', () => ptCatDialog(r, r.cats.find(c => c.id === +b.dataset.ed), reload)));
}
function ptCatDialog(r, c, reload) {
  const d = r.dept, dlg = $('wk-dlg'), parent = c.parent_id ? r.cats.find(x => x.id === c.parent_id) : null;
  const st = { icon: c.icon || parent?.icon || 'fa-shapes', color: c.color || parent?.color || d.color, image: c.image || '', rules: JSON.parse(JSON.stringify(c.rules || [])), components: JSON.parse(JSON.stringify(c.components || [])) };
  dlg.innerHTML = `<div class="wk-dlg-in pt-dlg-wide"><h3>${c.id ? 'Editar' : 'Nueva'} ${parent ? (parent.parent_id ? 'variante' : 'subcategoría') + ' de ' + esc(parent.name) : 'categoría'}</h3>
    <div class="pt-2c"><div><label class="pt-lbl">Nombre</label><input class="wk-in" id="c-n" value="${esc(c.name || '')}" maxlength="80"></div><div><label class="pt-lbl">Descripción corta</label><input class="wk-in" id="c-d" value="${esc(c.description || '')}" maxlength="500"></div></div>
    <div class="pt-3c"><div><label class="pt-lbl">Tiempo base <small>min</small></label><input class="wk-in" type="number" id="c-b" min="0" value="${c.base_min || 0}"></div><div><label class="pt-lbl">Mínimo <small>min</small></label><input class="wk-in" type="number" id="c-mn" min="0" value="${c.min_min || 0}"></div><div><label class="pt-lbl">Máximo <small>min</small></label><input class="wk-in" type="number" id="c-mx" min="0" value="${c.max_min || 0}"></div></div>
    <label class="pt-lbl">Ícono</label>${ptIconPick(st.icon, 'icon')}<label class="pt-lbl">Color</label>${ptColorPick(st.color, 'color')}
    <label class="pt-lbl">Imagen <small>(opcional, reemplaza al ícono)</small></label><div class="pt-banner sm"><span id="c-img"${st.image ? ` style="background-image:url('portal_file.php?img=${esc(st.image)}')"` : ''}></span><label class="wk-btn sm"><i class="fa-solid fa-upload"></i> Subir<input type="file" accept="image/png,image/jpeg,image/webp" id="c-imgf" hidden></label><button type="button" class="wk-btn sm" id="c-imgx">Quitar</button></div>
    <label class="pt-lbl">Componentes <small>(lo que incluye; informativo)</small></label><div id="c-comps"></div><button type="button" class="wk-btn sm" id="c-comp-add"><i class="fa-solid fa-plus"></i> Componente</button>
    <label class="pt-lbl">Reglas de tiempo</label><div id="c-rules"></div><button type="button" class="wk-btn sm" id="c-rule-add"><i class="fa-solid fa-plus"></i> Regla</button>
    <div class="pt-2c"><div><label class="pt-lbl">Formulario asociado</label><select class="wk-in" id="c-f"><option value="">Ninguno</option>${r.forms.filter(f => !f.is_template).map(f => `<option value="${f.id}"${c.form_id === f.id ? ' selected' : ''}>${esc(f.name)}</option>`).join('')}</select></div><div><label class="pt-lbl">Estado</label><label class="pt-sw"><input type="checkbox" id="c-a"${c.active === 0 ? '' : ' checked'}><span></span>Activa</label></div></div>
    <div class="acts">${c.id ? '<button type="button" class="wk-btn danger" id="c-del"><i class="fa-solid fa-trash"></i></button>' : ''}<span class="sp"></span><button type="button" class="wk-btn" data-x>Cancelar</button><button type="button" class="pt-btn primary" id="c-go">Guardar</button></div></div>`;
  dlg.showModal(); ptBindPickers(dlg, st);
  const drawComps = () => { $('c-comps').innerHTML = st.components.map((x, i) => `<div class="pt-comp"><input class="wk-in sm" value="${esc(x.name)}" data-ci="${i}" data-k="name" placeholder="Ej.: Bocetos"><input class="wk-in sm" type="number" min="0" value="${x.min}" data-ci="${i}" data-k="min"><span>min</span><button type="button" class="pt-ic" data-cx="${i}"><i class="fa-solid fa-xmark"></i></button></div>`).join(''); $('c-comps').querySelectorAll('[data-ci]').forEach(i => i.addEventListener('input', () => { st.components[+i.dataset.ci][i.dataset.k] = i.dataset.k === 'min' ? +i.value : i.value; })); $('c-comps').querySelectorAll('[data-cx]').forEach(b => b.addEventListener('click', () => { st.components.splice(+b.dataset.cx, 1); drawComps(); })); };
  drawComps();
  $('c-comp-add').addEventListener('click', () => { st.components.push({ name: '', min: 0 }); drawComps(); });
  const drawRules = () => { ptRulesEditor($('c-rules'), st.rules, [], drawRules); };
  drawRules();
  $('c-rule-add').addEventListener('click', () => { st.rules.push({ name: '', op: 'add', v: 30, when: null }); drawRules(); });
  $('c-imgf').addEventListener('change', async e => { const f = e.target.files[0]; if (!f) return; try { st.image = await ptImgUp(f, d.id); $('c-img').style.backgroundImage = `url('portal_file.php?img=${st.image}')`; } catch (er) { toast(er.message, true); } });
  $('c-imgx').addEventListener('click', () => { st.image = ''; $('c-img').style.backgroundImage = ''; });
  dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
  $('c-del')?.addEventListener('click', async () => { if (!confirm('¿Borrar esta categoría?')) return; try { const x = await ptApi('cat_del', { team_id: d.id, id: c.id }); dlg.close(); toast(x.msg || 'Borrada'); reload(); } catch (e) { toast(e.message, true); } });
  $('c-go').addEventListener('click', async () => {
    try { await ptApi('cat_save', { team_id: d.id, cat: { id: c.id || 0, parent_id: c.parent_id || null, name: $('c-n').value, description: $('c-d').value, base_min: +$('c-b').value, min_min: +$('c-mn').value, max_min: +$('c-mx').value, icon: st.icon, color: st.color, image: st.image, rules: st.rules, components: st.components.filter(x => x.name), form_id: +$('c-f').value || null, active: $('c-a').checked, position: c.position || 0 } }); dlg.close(); toast('Categoría guardada'); reload(); }
    catch (e) { toast(e.message, true); }
  });
}
function ptCfgForms(B, r, reload) {
  const d = r.dept, forms = r.forms.filter(f => !f.is_template), tpls = r.forms.filter(f => f.is_template);
  B.innerHTML = `<div class="wk-card"><h3>Formularios <span class="sp"></span>${r.can_config ? `<button type="button" class="wk-btn sm" id="f-new-t"><i class="fa-regular fa-copy"></i> Desde plantilla</button><button type="button" class="wk-btn sm primary" id="f-new"><i class="fa-solid fa-plus"></i> Nuevo</button>` : ''}</h3>
    <div class="pt-flist">${forms.map(f => `<div class="pt-fitem" style="--c:${esc(f.color)}"><span class="ic"><i class="fa-solid ${esc(f.icon)}"></i></span><div class="m"><b>${esc(f.name)}</b><small>${f.version ? 'Versión ' + f.version + ' publicada' : 'Borrador sin publicar'}${f.dirty && f.version ? ' · <em>cambios sin publicar</em>' : ''}${f.version && !f.active ? ' · desactivado' : ''}</small></div>
      <a class="wk-btn sm primary" href="builder.php?id=${f.id}"><i class="fa-solid fa-pen-ruler"></i> Editar</a>${r.can_config ? `<button type="button" class="pt-ic" data-dup="${f.id}" title="Duplicar"><i class="fa-regular fa-copy"></i></button><button type="button" class="pt-ic" data-tpl="${f.id}" title="Guardar como plantilla"><i class="fa-regular fa-bookmark"></i></button>${f.version ? `<button type="button" class="pt-ic" data-act="${f.id}" data-on="${f.active ? 0 : 1}" title="${f.active ? 'Desactivar' : 'Activar'}"><i class="fa-solid ${f.active ? 'fa-eye-slash' : 'fa-eye'}"></i></button>` : ''}<button type="button" class="pt-ic" data-del="${f.id}" title="Borrar"><i class="fa-solid fa-trash"></i></button>` : ''}</div>`).join('') || '<div class="pt-empty"><i class="fa-regular fa-file-lines"></i><b>Sin formularios</b><span>Armá el primero con el constructor.</span></div>'}</div></div>
    ${tpls.length ? `<div class="wk-card"><h3>Plantillas del departamento</h3><div class="pt-flist">${tpls.map(f => `<div class="pt-fitem tpl" style="--c:${esc(f.color)}"><span class="ic"><i class="fa-solid ${esc(f.icon)}"></i></span><div class="m"><b>${esc(f.name)}</b><small>Plantilla</small></div><a class="wk-btn sm" href="builder.php?id=${f.id}">Editar</a><button type="button" class="pt-ic" data-tpl="${f.id}" data-off="1" title="Dejar de ser plantilla"><i class="fa-solid fa-bookmark"></i></button><button type="button" class="pt-ic" data-del="${f.id}" title="Borrar"><i class="fa-solid fa-trash"></i></button></div>`).join('')}</div></div>` : ''}`;
  const mk = async (from = 0, name = '') => { try { const x = await ptApi('form_new', { team_id: d.id, from, name }); location.href = 'builder.php?id=' + x.id; } catch (e) { toast(e.message, true); } };
  $('f-new')?.addEventListener('click', () => mk());
  $('f-new-t')?.addEventListener('click', async () => {
    const all = (await ptApi('templates').catch(() => ({ rows: tpls }))).rows || tpls;
    const dlg = $('wk-dlg'); dlg.innerHTML = `<div class="wk-dlg-in"><h3>Nuevo desde plantilla</h3>${all.length ? `<div class="pt-flist">${all.map(t => `<button type="button" class="pt-fitem btn" data-t="${t.id}"><span class="ic"><i class="fa-regular fa-bookmark"></i></span><div class="m"><b>${esc(t.name)}</b></div></button>`).join('')}</div>` : '<p class="pt-muted">No hay plantillas todavía: guardá un formulario como plantilla con el botón del marcador.</p>'}<div class="acts"><button type="button" class="wk-btn" data-x>Cerrar</button></div></div>`;
    dlg.showModal(); dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close()); dlg.querySelectorAll('[data-t]').forEach(b => b.addEventListener('click', () => { dlg.close(); mk(+b.dataset.t); }));
  });
  B.querySelectorAll('[data-dup]').forEach(b => b.addEventListener('click', () => mk(+b.dataset.dup)));
  B.querySelectorAll('[data-tpl]').forEach(b => b.addEventListener('click', async () => { try { if (b.dataset.off) await ptApi('form_state', { id: +b.dataset.tpl, op: 'template', on: 0 }); else { await ptApi('form_new', { team_id: d.id, from: +b.dataset.tpl, template: 1 }); toast('Se guardó una copia como plantilla'); } reload(); } catch (e) { toast(e.message, true); } }));
  B.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => { await ptApi('form_state', { id: +b.dataset.act, op: 'active', on: +b.dataset.on }); reload(); }));
  B.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => { if (!confirm('¿Borrar este formulario? Si ya tiene solicitudes, se desactiva.')) return; try { const x = await ptApi('form_state', { id: +b.dataset.del, op: 'delete' }); toast(x.msg || 'Borrado'); reload(); } catch (e) { toast(e.message, true); } }));
}
const PT_EVENTS = { created: 'Entra una solicitud', approved: 'Se aprueba', rejected: 'Se rechaza', stage: 'Cambia de etapa', date: 'Cambia la fecha', comment: 'Hay un comentario', due_soon: 'Se entrega mañana', late: 'Está atrasada' };
function ptCfgAutos(B, r, reload) {
  const d = r.dept;
  B.innerHTML = `<div class="wk-card"><h3>Automatizaciones <span class="sp"></span>${r.can_config ? '<button type="button" class="wk-btn sm primary" id="a-new"><i class="fa-solid fa-plus"></i> Regla</button>' : ''}</h3>
    <p class="pt-muted">Cuando pasa algo y se cumplen las condiciones, se hacen las acciones. Las acciones no disparan otras reglas.</p>
    <div class="pt-flist">${r.autos.map(a => `<div class="pt-fitem${a.active ? '' : ' off'}"><span class="ic"><i class="fa-solid fa-robot"></i></span><div class="m"><b>${esc(a.name)}</b><small>Cuando ${esc((PT_EVENTS[a.event] || a.event).toLowerCase())} · ${a.actions.length} acción${a.actions.length > 1 ? 'es' : ''} · corrió ${a.runs} ${a.runs === 1 ? 'vez' : 'veces'}</small></div><button type="button" class="wk-btn sm" data-ed="${a.id}">Editar</button></div>`).join('') || '<div class="pt-empty"><i class="fa-solid fa-robot"></i><b>Sin reglas</b><span>Ejemplo: cuando entra una solicitud urgente, asignarla a la responsable y avisar a la jefa.</span></div>'}</div></div>`;
  const fields = [{ id: 'priority', label: 'Prioridad', options: PT.boot.priorities.map(p => ({ v: p.id, l: p.name })) }, { id: 'category', label: 'Categoría', options: r.cats.map(c => ({ v: String(c.id), l: c.name })) },
    { id: 'stage', label: 'Etapa', options: PT.boot.stages.map(s => ({ v: s.id, l: s.name })) }, { id: 'est_min', label: 'Estimación (min)', type: 'number' }, { id: 'overload', label: 'Con sobrecarga', type: 'checkbox' }, { id: 'form', label: 'Formulario', options: r.forms.map(f => ({ v: String(f.id), l: f.name })) }];
  const edit = a => {
    const st = a ? JSON.parse(JSON.stringify(a)) : { name: '', event: 'created', cond: null, actions: [{ a: 'assign', v: 'auto' }], active: 1 };
    const dlg = $('wk-dlg');
    const drawActs = () => { $('a-acts').innerHTML = st.actions.map((x, i) => `<div class="pt-act"><select class="wk-in sm" data-i="${i}" data-k="a">${[['assign', 'Asignar a'], ['stage', 'Pasar a la etapa'], ['priority', 'Cambiar prioridad'], ['notify', 'Avisar a'], ['note', 'Agregar nota interna']].map(([k, l]) => `<option value="${k}"${x.a === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
      ${x.a === 'assign' ? `<select class="wk-in sm" data-i="${i}" data-k="v"><option value="auto"${x.v === 'auto' ? ' selected' : ''}>Automático</option>${r.members.filter(m => m.works).map(m => `<option value="${m.id}"${+x.v === m.id ? ' selected' : ''}>${esc(m.name)}</option>`).join('')}</select>` : ''}
      ${x.a === 'stage' ? `<select class="wk-in sm" data-i="${i}" data-k="v">${PT.boot.stages.map(s => `<option value="${s.id}"${x.v === s.id ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}</select>` : ''}
      ${x.a === 'priority' ? `<select class="wk-in sm" data-i="${i}" data-k="v">${PT.boot.priorities.map(p => `<option value="${p.id}"${x.v === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>` : ''}
      ${x.a === 'notify' ? `<select class="wk-in sm" data-i="${i}" data-k="to">${[['leads', 'Jefes del departamento'], ['requester', 'Quien pidió'], ['assignee', 'El responsable']].map(([k, l]) => `<option value="${k}"${x.to === k ? ' selected' : ''}>${l}</option>`).join('')}${r.members.map(m => `<option value="${m.id}"${String(x.to) === String(m.id) ? ' selected' : ''}>${esc(m.name)}</option>`).join('')}</select><input class="wk-in sm" data-i="${i}" data-k="msg" value="${esc(x.msg || '')}" placeholder="Mensaje">` : ''}
      ${x.a === 'note' ? `<input class="wk-in sm" data-i="${i}" data-k="v" value="${esc(x.v || '')}" placeholder="Texto de la nota">` : ''}<button type="button" class="pt-ic" data-x2="${i}"><i class="fa-solid fa-xmark"></i></button></div>`).join('');
      $('a-acts').querySelectorAll('[data-i]').forEach(e => e.addEventListener('change', () => { const x = st.actions[+e.dataset.i]; x[e.dataset.k] = e.value; if (e.dataset.k === 'a') { x.v = e.value === 'assign' ? 'auto' : e.value === 'stage' ? 'progreso' : e.value === 'priority' ? 'alta' : ''; drawActs(); } }));
      $('a-acts').querySelectorAll('[data-x2]').forEach(e => e.addEventListener('click', () => { st.actions.splice(+e.dataset.x2, 1); drawActs(); })); };
    dlg.innerHTML = `<div class="wk-dlg-in pt-dlg-wide"><h3>${a ? 'Editar' : 'Nueva'} automatización</h3><div class="pt-2c"><div><label class="pt-lbl">Nombre</label><input class="wk-in" id="a-n" value="${esc(st.name)}" maxlength="120"></div><div><label class="pt-lbl">Cuando</label><select class="wk-in" id="a-e">${Object.entries(PT_EVENTS).map(([k, l]) => `<option value="${k}"${st.event === k ? ' selected' : ''}>${l}</option>`).join('')}</select></div></div>
      <label class="pt-lbl">Condiciones</label><div class="pt-condbox" id="a-c"></div><label class="pt-lbl">Acciones</label><div id="a-acts"></div><button type="button" class="wk-btn sm" id="a-add"><i class="fa-solid fa-plus"></i> Acción</button>
      <label class="pt-sw"><input type="checkbox" id="a-on"${st.active ? ' checked' : ''}><span></span>Activa</label>
      <div class="acts">${a ? '<button type="button" class="wk-btn danger" id="a-del"><i class="fa-solid fa-trash"></i></button>' : ''}<span class="sp"></span><button type="button" class="wk-btn" data-x>Cancelar</button><button type="button" class="pt-btn primary" id="a-go">Guardar</button></div></div>`;
    dlg.showModal();
    ptCondEditor($('a-c'), st.cond, fields, c => { st.cond = c; }, 'siempre');
    drawActs();
    $('a-add').addEventListener('click', () => { st.actions.push({ a: 'notify', to: 'leads', msg: '' }); drawActs(); });
    dlg.querySelector('[data-x]').addEventListener('click', () => dlg.close());
    $('a-del')?.addEventListener('click', async () => { await ptApi('auto_del', { id: a.id }); dlg.close(); reload(); });
    $('a-go').addEventListener('click', async () => { try { await ptApi('auto_save', { team_id: d.id, auto: { id: a?.id || 0, name: $('a-n').value, event: $('a-e').value, cond: st.cond, actions: st.actions, active: $('a-on').checked } }); dlg.close(); toast('Regla guardada'); reload(); } catch (e) { toast(e.message, true); } });
  };
  $('a-new')?.addEventListener('click', () => edit(null));
  B.querySelectorAll('[data-ed]').forEach(b => b.addEventListener('click', () => edit(r.autos.find(a => a.id === +b.dataset.ed))));
}
async function ptCfgAudit(B, r) {
  B.innerHTML = `<div class="wk-card"><h3>Historial</h3><div id="au">${ptSk(3)}</div></div>`;
  const x = await ptApi('audit', { team_id: r.dept.id });
  const L = { dept_create: 'creó el departamento', dept_save: 'cambió la configuración', member_save: 'cambió un integrante', member_add: 'sumó a alguien', member_remove: 'sacó a alguien', off_save: 'cargó una ausencia o feriado', cat_save: 'guardó una categoría', form_new: 'creó un formulario', form_publish: 'publicó un formulario', submit: 'hizo una solicitud', overload: 'aceptó sobrecarga', stage: 'cambió una etapa', approve: 'aprobó', reject: 'rechazó', assign: 'asignó', dates: 'cambió fecha o estimación' };
  $('au').innerHTML = x.rows.length ? `<ul class="pt-audit">${x.rows.map(a => `<li>${av(ptPerson(a.user_id), 22)}<span><b>${esc(ptPerson(a.user_id)?.name || 'Sistema')}</b> ${esc(L[a.action] || a.action)}${a.ticket_id ? ` <a href="#solicitudes/t/${a.ticket_id}">T-${String(a.ticket_id).padStart(4, '0')}</a>` : ''}${a.detail && (a.detail.reason || a.detail.note) ? ` <em>"${esc(a.detail.reason || a.detail.note)}"</em>` : ''}</span><time>${ago(a.at)}</time></li>`).join('')}</ul>` : '<p class="pt-muted">Sin movimientos.</p>';
}
async function ptStagesPage() {
  const M = $('wk-main'), b = PT.boot;
  const st = { stages: JSON.parse(JSON.stringify(b.stages)), trans: JSON.parse(JSON.stringify(b.trans)) };
  const BASE = { abierto: 'Abierto', en_curso: 'En curso', esperando: 'Esperando', resuelto: 'Resuelto', cerrado: 'Cerrado' };
  const draw = () => {
    M.innerHTML = `<div class="pt-crumbs"><a href="#portal-config">Configurar</a><i class="fa-solid fa-chevron-right"></i><span>Etapas</span></div>
      <div class="wk-h"><div><h1>Etapas y transiciones<span>.</span></h1><p>Cada etapa se apoya en uno de los 5 estados principales del ticket, que siguen mandando para los plazos, la tarea y el sitio base.</p></div></div>
      <div class="wk-card"><h3>Etapas</h3><div class="pt-stages">${st.stages.map((s, i) => `<div class="pt-stg" style="--c:${esc(s.color)}"><span class="ic"><i class="fa-solid ${esc(s.icon)}"></i></span><input class="wk-in sm" data-s="${i}" data-k="name" value="${esc(s.name)}" maxlength="40"><input type="color" data-s="${i}" data-k="color" value="${esc(s.color)}"><span class="pt-base">${BASE[s.status]}</span></div>`).join('')}</div></div>
      <div class="wk-card"><h3>Transiciones <small>desde la fila, hacia la columna</small></h3><div class="pt-tablewrap"><table class="pt-trans"><thead><tr><th></th>${st.stages.map(s => `<th title="${esc(s.name)}"><i class="fa-solid ${esc(s.icon)}" style="color:${esc(s.color)}"></i></th>`).join('')}</tr></thead><tbody>${st.stages.map(f => `<tr><th>${esc(f.name)}</th>${st.stages.map(t => `<td>${f.id === t.id ? '—' : `<input type="checkbox" data-f="${f.id}" data-t="${t.id}"${(st.trans[f.id] || []).includes(t.id) ? ' checked' : ''}>`}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>
      <div class="pt-save"><button type="button" class="pt-btn primary" id="s-save"><i class="fa-solid fa-check"></i> Guardar</button></div>`;
    M.querySelectorAll('[data-s]').forEach(x => x.addEventListener('change', () => { st.stages[+x.dataset.s][x.dataset.k] = x.value; }));
    M.querySelectorAll('[data-f]').forEach(x => x.addEventListener('change', () => { const l = st.trans[x.dataset.f] = st.trans[x.dataset.f] || []; if (x.checked) l.push(x.dataset.t); else l.splice(l.indexOf(x.dataset.t), 1); }));
    $('s-save').addEventListener('click', async () => { try { const r = await ptApi('stages_save', st); PT.boot.stages = r.stages; PT.boot.trans = r.trans; toast('Etapas guardadas'); } catch (e) { toast(e.message, true); } });
  };
  draw();
}

// ── Métricas ─────────────────────────────────────────────────
const PTX = { per: 30, team_id: '' };
async function renderPortalMetrics() {
  await ptLoadBoot();
  const M = $('wk-main'), b = PT.boot;
  const to = PT.boot.today, from = new Date(new Date(to + 'T12:00:00') - (PTX.per - 1) * 864e5).toISOString().slice(0, 10);
  M.innerHTML = `<div class="wk-h"><div><h1>Métricas del portal<span>.</span></h1><p>Tickets, tiempos, capacidad y cumplimiento. Comparado con el período anterior del mismo largo.</p></div>
    <div class="wk-h-a"><div class="wk-tabs" id="mx-per">${[[7, '7 días'], [30, '30 días'], [90, '90 días'], [365, '12 meses']].map(([k, l]) => `<button type="button" data-p="${k}" class="${PTX.per === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <select class="wk-in sm" id="mx-t"><option value="">Todos mis departamentos</option>${b.depts.filter(d => d.role !== 'requester').map(d => `<option value="${d.id}"${+PTX.team_id === d.id ? ' selected' : ''}>${esc(d.name)}</option>`).join('')}</select></div></div>
    <div id="mx">${ptSk(6)}</div>`;
  M.querySelectorAll('#mx-per [data-p]').forEach(x => x.addEventListener('click', () => { PTX.per = +x.dataset.p; renderPortalMetrics(); }));
  $('mx-t').addEventListener('change', e => { PTX.team_id = e.target.value; renderPortalMetrics(); });
  const r = await ptApi('metrics', { from, to, team_id: PTX.team_id || null });
  if (r.error) { $('mx').innerHTML = emptyBox('fa-lock', esc(r.error)); return; }
  const k = r.kpi, p = r.kpi_prev, n = r.now;
  const delta = (a, b2, good = 1) => { if (a === null || b2 === null || b2 === undefined) return ''; if (!b2) return a ? '<span class="pt-delta">nuevo</span>' : ''; const x = Math.round((a - b2) * 1000 / b2) / 10; const up = x > 0; const c = !x || !good ? '#64748b' : (up ? good > 0 : good < 0) ? '#22c55e' : '#ef4444'; return `<span class="pt-delta" style="--c:${c}">${up ? '+' : ''}${String(x).replace('.', ',')}% <i class="fa-solid fa-caret-${up ? 'up' : x < 0 ? 'down' : 'right'}"></i></span>`; };
  const kpi = (l, v, sub, c, ic, d = '') => `<div class="pt-kpi" style="--c:${c}"><span class="ic"><i class="fa-solid ${ic}"></i></span><div><span>${l}</span><b>${v}</b><small>${sub} ${d}</small></div></div>`;
  const maxS = Math.max(1, ...r.series.map(x => Math.max(x.created, x.completed)));
  const W2 = 640, H = 180, step = r.series.length > 1 ? W2 / (r.series.length - 1) : W2;
  const line = key => r.series.map((x, i) => `${i ? 'L' : 'M'}${(i * step).toFixed(1)},${(H - x[key] * (H - 14) / maxS).toFixed(1)}`).join(' ');
  const stages = b.stages.filter(s => r.by_stage[s.id]); const tot = stages.reduce((s, x) => s + r.by_stage[x.id], 0) || 1;
  let acc = 0; const donut = stages.map(s => { const v = r.by_stage[s.id] / tot; const a0 = acc * 2 * Math.PI, a1 = (acc += v) * 2 * Math.PI; const big = v > .5 ? 1 : 0; const P = a => [60 + 50 * Math.sin(a), 60 - 50 * Math.cos(a)]; const [x0, y0] = P(a0), [x1, y1] = P(a1 - 1e-4); return `<path d="M${x0},${y0} A50,50 0 ${big} 1 ${x1},${y1}" stroke="${s.color}" stroke-width="18" fill="none" data-tip="${esc(s.name + ': ' + r.by_stage[s.id])}"/>`; }).join('');
  const maxCat = Math.max(1, ...r.by_cat.map(c => c.n));
  $('mx').innerHTML = `<div class="pt-kpis">
      ${kpi('Solicitudes nuevas', k.created || 0, 'en el período', '#3b82f6', 'fa-inbox', delta(k.created, p.created, 0))}
      ${kpi('Completadas', k.completed || 0, 'en el período', '#22c55e', 'fa-circle-check', delta(k.completed, p.completed, 1))}
      ${kpi('Pendientes', n.pending, `${n.progress} en curso · ${n.waiting} esperando`, '#f59e0b', 'fa-layer-group')}
      ${kpi('Atrasadas', n.late, `${n.unassigned} sin responsable · ${n.approval} por aprobar`, '#ef4444', 'fa-clock')}
      ${kpi('Resolución promedio', k.avg_res_h !== null ? (k.avg_res_h < 48 ? String(k.avg_res_h).replace('.', ',') + ' h' : Math.round(k.avg_res_h / 24) + ' días') : '—', 'desde que se pide', '#8b5cf6', 'fa-stopwatch', delta(k.avg_res_h, p.avg_res_h, -1))}
      ${kpi('A tiempo', k.on_time_pct !== null ? String(k.on_time_pct).replace('.', ',') + ' %' : '—', 'completadas antes de la fecha', '#14b8a6', 'fa-bullseye', delta(k.on_time_pct, p.on_time_pct, 1))}</div>
    <div class="pt-mgrid">
      <div class="wk-card span2"><h3><i class="fa-solid fa-chart-line"></i> Nuevas y completadas <small>por ${r.by_week ? 'semana' : 'día'}</small></h3><svg class="pt-chart" viewBox="-4 -4 ${W2 + 8} ${H + 8}" preserveAspectRatio="none"><path d="${line('created')}" class="l1"/><path d="${line('completed')}" class="l2"/>${r.series.map((x, i) => `<rect x="${i * step - step / 2}" y="0" width="${Math.max(step, 4)}" height="${H}" fill="transparent" data-tip="${esc(ptDate(x.k) + ': ' + x.created + ' nuevas · ' + x.completed + ' completadas')}"/>`).join('')}</svg><div class="pt-legend"><span class="l1">Nuevas</span><span class="l2">Completadas</span></div></div>
      <div class="wk-card"><h3><i class="fa-solid fa-chart-pie"></i> Abiertas por etapa</h3>${stages.length ? `<div class="pt-donut"><svg viewBox="0 0 120 120">${donut}<text x="60" y="66" text-anchor="middle">${tot}</text></svg><ul>${stages.map(s => `<li><i style="background:${esc(s.color)}"></i>${esc(s.name)}<b>${r.by_stage[s.id]}</b></li>`).join('')}</ul></div>` : '<p class="pt-muted">Nada abierto.</p>'}</div>
      <div class="wk-card"><h3><i class="fa-solid fa-scale-balanced"></i> Estimado contra real <small>completadas</small></h3><div class="pt-er-big"><div><span>Estimado</span><b>${PTFmt.fmtMin(k.est_done || 0)}</b></div><div><span>Real</span><b>${PTFmt.fmtMin(k.real_done || 0)}</b></div></div>${k.real_done ? `<div class="pt-er-bar"><i style="--w:${Math.min(100, (k.est_done || 0) * 100 / Math.max(k.est_done || 0, k.real_done))}%" class="e"></i><i style="--w:${Math.min(100, k.real_done * 100 / Math.max(k.est_done || 0, k.real_done))}%" class="r"></i></div><p class="pt-muted">${k.accuracy_pct >= 100 ? 'Se estimó de más' : 'Llevó más de lo estimado'}: lo estimado es el ${String(k.accuracy_pct).replace('.', ',')} % de lo real.</p>` : '<p class="pt-muted">Sin horas reales cargadas en lo completado.</p>'}</div>
      <div class="wk-card span2"><h3><i class="fa-solid fa-gauge-high"></i> Capacidad y ocupación por departamento</h3><div class="pt-occ">${r.depts.map(d => `<div class="pt-occ-r" style="--c:${esc(d.color)}"><span class="nm"><i class="fa-solid ${esc(d.icon)}"></i>${esc(d.name)}</span><span class="v"><b>${d.next_pct ?? '—'}%</b><small>próx. 14 días · ${PTFmt.fmtMin(d.next_load)} de ${PTFmt.fmtMin(d.next_cap)}</small></span><span class="v"><b>${d.period_pct ?? '—'}%</b><small>período (horas reales)</small></span><span class="heat">${d.days.map(x => `<i class="${!x.work ? 'gray' : x.pct === null ? 'gray' : x.pct > 100 ? 'red' : x.pct > 70 ? 'yellow' : 'green'}" data-tip="${esc(ptDow(x.day) + ' ' + ptDate(x.day) + ' · ' + (x.work ? (x.pct ?? 0) + ' %' : 'no laborable'))}"></i>`).join('')}</span></div>`).join('')}</div></div>
      <div class="wk-card"><h3><i class="fa-solid fa-shapes"></i> Por categoría</h3>${r.by_cat.length ? `<ul class="pt-bars">${r.by_cat.map(c => `<li><span>${esc(c.name)}</span><i style="--w:${c.n * 100 / maxCat}%;--c:${esc(c.color)}"></i><b>${c.n}</b></li>`).join('')}</ul>` : '<p class="pt-muted">Sin datos.</p>'}</div>
      <div class="wk-card span3"><h3><i class="fa-solid fa-user-group"></i> Productividad por integrante</h3>${r.people.length ? `<div class="pt-tablewrap"><table class="pt-table compact"><thead><tr><th>Persona</th><th>Completadas</th><th>Estimado</th><th>Horas cargadas</th><th>A tiempo</th><th>Abiertas</th><th>Por hacer</th></tr></thead><tbody>${r.people.map(x => `<tr><td>${av(ptPerson(x.uid), 22)} ${esc(x.name)}</td><td><b>${x.done}</b></td><td>${PTFmt.fmtMin(x.est)}</td><td>${PTFmt.fmtMin(x.logged)}</td><td>${x.on_time_pct !== null ? String(x.on_time_pct).replace('.', ',') + ' %' : '—'}</td><td>${x.open}</td><td>${PTFmt.fmtMin(x.left)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="pt-muted">Sin datos en el período.</p>'}</div>
    </div>`;
}
window.renderManage = renderManage; window.renderPortalConfig = renderPortalConfig; window.renderPortalMetrics = renderPortalMetrics; window.ptManageRefresh = ptManageRefresh;
