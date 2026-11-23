/* social/app.js — Social Studio · Content Planner (index.php). Habla con api.php; nada de código en línea (CSP): todo con
   addEventListener y delegación por data-act. Vistas: mes, semana, día y lista; panel "Sin fecha"; editor lateral (#p/<id>, #new);
   "Convertir en ticket"; configuración. Arrastrar: mouse directo; en pantallas táctiles, mantener apretado y arrastrar. */
'use strict';
const BOOT = window.SS_BOOT || {};
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DOW = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
const DOW_LONG = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
const S = { meta: null, view: 'month', cur: new Date(), items: [], backlog: [], f: { group: '', network: '', owner: '', status: '', q: '' }, showBacklog: false,
            dr: null, loadSeq: 0 };
const store = { get(k) { try { return localStorage.getItem('ss.' + k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem('ss.' + k, v); } catch (e) {} } };

// ── API ──────────────────────────────────────────────────
async function api(action, data = {}, quiet = false) {
  let d;
  try {
    const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-SS': '1' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar. Revisá tu conexión y probá de nuevo.' }; }
  if (d.error) { if (!quiet) toast(d.error, true); const e = new Error(d.error); e.api = true; throw e; }
  return d;
}
async function upload(postId, file) {
  const fd = new FormData(); fd.append('action', 'file_upload'); fd.append('id', postId); fd.append('file', file);
  const r = await fetch('api.php', { method: 'POST', headers: { 'X-SS': '1' }, credentials: 'same-origin', body: fd });
  const d = await r.json().catch(() => ({ error: r.status === 413 ? 'El archivo es más grande de lo que acepta el servidor.' : 'No se pudo subir el archivo.' }));
  if (d.error) { toast(d.error, true); throw new Error(d.error); }
  return d;
}

// ── Utilidades ───────────────────────────────────────────
let toastT = 0;
function toast(msg, bad = false) {
  const t = $('#ss-toast'); t.textContent = msg; t.className = 'ss-toast show' + (bad ? ' bad' : '');
  clearTimeout(toastT); toastT = setTimeout(() => { t.className = 'ss-toast'; }, 3800);
}
const pad = n => String(n).padStart(2, '0');
const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const parse = s => { const [y, m, d] = String(s).split('-').map(Number); return new Date(y, m - 1, d); };
const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
const monday = d => addDays(new Date(d.getFullYear(), d.getMonth(), d.getDate()), -((d.getDay() + 6) % 7));
const todayStr = () => ymd(new Date());
const fmtDay = s => { const d = parse(s); return DOW_LONG[(d.getDay() + 6) % 7] + ' ' + d.getDate() + ' de ' + MONTHS[d.getMonth()]; };
const fmtShort = s => { const d = parse(s); return d.getDate() + '/' + (d.getMonth() + 1); };
const fmtWhen = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.getDate() + ' ' + MONTHS[d.getMonth()].slice(0, 3) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); };
const can = a => !!(S.meta ? S.meta.can[a] : BOOT.can && BOOT.can[a]);
const st = id => S.meta.statuses.find(s => s.id === id) || { name: '—', color: '#94a3b8', kind: '' };
const person = id => S.meta.people.find(p => p.id === id);
const brand = id => S.meta.brands.find(b => b.id === id);
const client = id => S.meta.clients.find(c => c.id === id);
const groupOf = p => S.meta.group === 'client' ? client(p.client_id) : brand(p.brand_id);
const groupWord = () => S.meta.group === 'client' ? 'cliente' : 'marca';
const COLORS = ['#f472b6', '#60a5fa', '#34d399', '#fbbf24', '#a78bfa', '#f87171', '#22d3ee', '#fb923c'];
const initials = n => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
const av = id => { const p = person(id); return p ? `<span class="ss-av" style="--a:${COLORS[p.id % COLORS.length]}" title="${esc(p.name)}">${esc(initials(p.name))}</span>` : ''; };
const netIcon = k => { const n = S.meta.networks[k]; return n ? `<i class="fa-brands ${n.icon} ss-net" style="--n:${n.color}" title="${esc(n.name)}"></i>` : ''; };
const fmtName = p => p.format === 'personalizado' ? (p.format_custom || 'Personalizado') : (S.meta.formats[p.format] || p.format);
const pill = s => `<span class="ss-pill" style="--c:${esc(s.color)}">${esc(s.name)}</span>`;

// ── Rango de la vista ────────────────────────────────────
function range() {
  const c = S.cur;
  if (S.view === 'month') { const a = monday(new Date(c.getFullYear(), c.getMonth(), 1)); return [a, addDays(a, 41)]; }
  if (S.view === 'week') { const a = monday(c); return [a, addDays(a, 6)]; }
  if (S.view === 'day') return [new Date(c.getFullYear(), c.getMonth(), c.getDate()), new Date(c.getFullYear(), c.getMonth(), c.getDate())];
  return [new Date(c.getFullYear(), c.getMonth(), 1), new Date(c.getFullYear(), c.getMonth() + 1, 0)];
}
function periodLabel() {
  const c = S.cur;
  if (S.view === 'month' || S.view === 'list') return MONTHS[c.getMonth()] + ' ' + c.getFullYear();
  if (S.view === 'day') return fmtDay(ymd(c)) + ' ' + c.getFullYear();
  const [a, b] = range();
  return a.getMonth() === b.getMonth() ? `${a.getDate()} – ${b.getDate()} de ${MONTHS[a.getMonth()]} ${b.getFullYear()}` : `${a.getDate()} ${MONTHS[a.getMonth()].slice(0, 3)} – ${b.getDate()} ${MONTHS[b.getMonth()].slice(0, 3)} ${b.getFullYear()}`;
}
function shift(dir) {
  const c = S.cur;
  if (S.view === 'month' || S.view === 'list') S.cur = new Date(c.getFullYear(), c.getMonth() + dir, 1);
  else S.cur = addDays(c, S.view === 'week' ? 7 * dir : dir);
  load();
}

// ── Carga ────────────────────────────────────────────────
function filters() {
  const f = { network: S.f.network, owner: S.f.owner, status: S.f.status, q: S.f.q };
  if (S.f.group) f[S.meta.group === 'client' ? 'client' : 'brand'] = S.f.group;
  return f;
}
async function load() {
  const seq = ++S.loadSeq;
  const [a, b] = range();
  $('#ss-period').textContent = periodLabel();
  $$('.ss-seg button').forEach(x => x.classList.toggle('on', x.dataset.view === S.view));
  $('#ss-view').setAttribute('aria-busy', 'true');
  try {
    const [r1, r2] = await Promise.all([api('list', { filters: { ...filters(), from: ymd(a), to: ymd(b) } }), api('list', { filters: { ...filters(), undated: true } })]);
    if (seq !== S.loadSeq) return;
    S.items = r1.items; S.backlog = r2.items;
    render();
  } catch (e) {
    if (seq === S.loadSeq) $('#ss-view').innerHTML = `<div class="ss-empty"><div class="art"><i class="fa-solid fa-plug-circle-xmark"></i></div><h3>No se pudo cargar el calendario</h3><p>${esc(e.message)}</p><button type="button" class="ss-btn ss-btn-ghost" data-act="reload">Reintentar</button></div>`;
  } finally { $('#ss-view').setAttribute('aria-busy', 'false'); }
}
function render() {
  const v = $('#ss-view');
  if (S.view === 'month') v.innerHTML = renderMonth();
  else if (S.view === 'list') v.innerHTML = renderList();
  else { v.innerHTML = renderWeek(S.view === 'day' ? 1 : 7); const sc = $('.ss-wscroll', v); if (sc) { const h = $('[data-hour="8"]', v); if (h) sc.scrollTop = h.offsetTop - 60; } }
  renderBacklog();
}
const byDate = () => { const m = {}; for (const p of S.items) if (p.date) (m[p.date] = m[p.date] || []).push(p); return m; };

function chip(p) {
  const s = st(p.status_id), g = groupOf(p);
  const tk = p.tickets && p.tickets.total ? `<span class="ss-chip-tk${p.tickets.done === p.tickets.total ? ' ok' : ''}" title="Tickets resueltos"><i class="fa-solid fa-ticket"></i>${p.tickets.done}/${p.tickets.total}</span>` : '';
  return `<div class="ss-chip${s.kind === 'cancelled' ? ' cancel' : ''}" style="--c:${esc(s.color)}" data-post="${p.id}" tabindex="0" role="button" aria-label="${esc(p.title)} · ${esc(s.name)}">
    <div class="ss-chip-top">${g ? `<span class="ss-bdot" style="--b:${esc(g.color || '#71717a')}" title="${esc(g.name)}"></span>` : ''}${p.time ? `<span class="tm">${p.time}</span>` : ''}<span>${esc(fmtName(p))}</span>${tk}<span class="nets">${p.networks.map(netIcon).join('')}</span></div>
    <div class="ss-chip-t">${esc(p.title)}</div></div>`;
}
function emptyState(inView) {
  const anyFilter = S.f.group || S.f.network || S.f.owner || S.f.status || S.f.q;
  if (anyFilter) return `<div class="ss-empty"><div class="art"><i class="fa-solid fa-filter-circle-xmark"></i></div><h3>Nada con estos filtros</h3><p>Probá con otro período o limpiá los filtros.</p><button type="button" class="ss-btn ss-btn-ghost" data-act="clear">Limpiar filtros</button></div>`;
  return `<div class="ss-empty${inView && S.view !== 'list' ? ' cal' : ''}"><div class="art"><i class="fa-regular fa-calendar-plus"></i></div><h3>${inView ? 'No hay publicaciones en este período' : 'Todavía no hay publicaciones'}</h3><p>Cargá la primera idea: después le ponés fecha, redes y responsable, y la convertís en tickets para producirla.</p>${can('edit') ? '<button type="button" class="ss-btn" data-act="new"><i class="fa-solid fa-plus"></i> Nueva publicación</button>' : ''}</div>`;
}

function renderMonth() {
  const [a] = range(); const m = byDate(); const today = todayStr(); const month = S.cur.getMonth();
  let h = `<div class="ss-month"><div class="ss-dow">${DOW.map(d => `<span>${d}</span>`).join('')}</div><div class="ss-grid">`;
  for (let i = 0; i < 42; i++) {
    const d = addDays(a, i), k = ymd(d), list = m[k] || [];
    const show = list.slice(0, 4);
    h += `<div class="ss-day${d.getMonth() !== month ? ' out' : ''}${k === today ? ' today' : ''}" data-drop-date="${k}">
      <div class="ss-dhead"><button type="button" class="ss-dnum" data-act="goday" data-d="${k}" aria-label="Ver ${esc(fmtDay(k))}">${d.getDate()}</button>${can('edit') ? `<button type="button" class="ss-dadd" data-act="new" data-d="${k}" aria-label="Nueva publicación el ${esc(fmtDay(k))}"><i class="fa-solid fa-plus"></i></button>` : ''}</div>
      ${show.map(chip).join('')}${list.length > 4 ? `<button type="button" class="ss-more" data-act="goday" data-d="${k}">+${list.length - 4} más</button>` : ''}</div>`;
  }
  h += '</div></div>';
  return S.items.length ? h : h + emptyState(true);
}
function renderWeek(cols) {
  const [a] = range(); const today = todayStr(); const nowH = new Date().getHours();
  const days = Array.from({ length: cols }, (_, i) => ymd(addDays(a, i)));
  const m = byDate();
  let h = `<div class="ss-week"><div class="ss-wscroll"><div class="ss-wgrid" style="--cols:${cols}"><div class="ss-wh-corner"></div>`;
  for (const k of days) { const d = parse(k); h += `<div class="ss-wh${k === today ? ' today' : ''}"><span>${DOW[(d.getDay() + 6) % 7]}</span><b>${d.getDate()}</b></div>`; }
  h += '<div class="ss-hour allday">Sin hora</div>';
  for (const k of days) h += `<div class="ss-slot allday" data-drop-date="${k}" data-drop-time="">${(m[k] || []).filter(p => !p.time).map(chip).join('')}</div>`;
  for (let hr = 0; hr < 24; hr++) {
    h += `<div class="ss-hour" data-hour="${hr}">${pad(hr)}:00</div>`;
    for (const k of days) {
      const list = (m[k] || []).filter(p => p.time && Number(p.time.slice(0, 2)) === hr);
      h += `<div class="ss-slot${k === today && hr === nowH ? ' now' : ''}" data-drop-date="${k}" data-drop-time="${pad(hr)}:00"${can('edit') ? ` data-act="new-slot" data-d="${k}" data-t="${pad(hr)}:00"` : ''}>${list.map(chip).join('')}</div>`;
    }
  }
  return h + '</div></div></div>' + (S.items.length ? '' : emptyState(true));
}
function renderList() {
  if (!S.items.length) return emptyState(true);
  const m = byDate();
  return '<div class="ss-list">' + Object.keys(m).sort().map(k => `<section class="ss-lgroup"><h3>${esc(fmtDay(k))}</h3><div class="ss-lrows">${m[k].map(p => {
    const s = st(p.status_id), g = groupOf(p), o = person(p.owner_id);
    return `<button type="button" class="ss-lrow" data-post="${p.id}"><span class="tm">${p.time || '—'}</span>
      <span class="t">${esc(p.title)}<small>${esc(fmtName(p))}${p.tags.length ? ' · ' + p.tags.map(t => '#' + esc(t)).join(' ') : ''}</small></span>
      <span class="c hide-sm">${g ? `<span class="ss-bdot" style="--b:${esc(g.color || '#71717a')}"></span>${esc(g.name)}` : '<span style="opacity:.5">Sin ' + groupWord() + '</span>'}</span>
      <span class="c hide-sm">${p.networks.map(netIcon).join(' ') || '<span style="opacity:.5">Sin redes</span>'}</span>
      <span class="c hide-sm">${o ? av(o.id) + esc(o.name) : '<span style="opacity:.5">Sin responsable</span>'}</span>
      <span class="c">${pill(s)}</span></button>`;
  }).join('')}</div></section>`).join('') + '</div>';
}
function renderBacklog() {
  $('#ss-backlog-n').textContent = S.backlog.length;
  $('#ss-backlog').hidden = !S.showBacklog;
  $('.ss-backlog-btn').setAttribute('aria-pressed', S.showBacklog ? 'true' : 'false');
  $('#ss-backlog-list').innerHTML = S.backlog.length ? S.backlog.map(chip).join('') : '<div class="ss-empty sm"><p>Nada sin fecha. Las ideas nuevas sin día aparecen acá.</p></div>';
}

// ── Filtros ──────────────────────────────────────────────
function setupFilters() {
  const M = S.meta;
  $('#ss-group-word').textContent = groupWord();
  const groups = M.group === 'client' ? M.clients : M.brands;
  const opt = (v, l, sel) => `<option value="${esc(v)}"${String(sel) === String(v) ? ' selected' : ''}>${esc(l)}</option>`;
  $('#ss-f-group').innerHTML = opt('', M.group === 'client' ? 'Todos los clientes' : 'Todas las marcas', S.f.group) + opt('none', 'Sin ' + groupWord(), S.f.group) + groups.map(g => opt(g.id, g.name, S.f.group)).join('');
  $('#ss-f-network').innerHTML = opt('', 'Todas las redes', S.f.network) + Object.entries(M.networks).map(([k, n]) => opt(k, n.name, S.f.network)).join('');
  $('#ss-f-owner').innerHTML = opt('', 'Todos los responsables', S.f.owner) + opt(M.me, 'Yo', S.f.owner) + opt('none', 'Sin responsable', S.f.owner) + M.people.filter(p => p.id !== M.me).map(p => opt(p.id, p.name, S.f.owner)).join('');
  $('#ss-f-status').innerHTML = opt('', 'Todos los estados', S.f.status) + M.statuses.filter(s => s.active).map(s => opt(s.id, s.name, S.f.status)).join('');
  syncFilterUi();
}
function syncFilterUi() {
  for (const k of ['group', 'network', 'owner', 'status']) $('#ss-f-' + k).classList.toggle('on', !!S.f[k]);
  $('#ss-clear').hidden = !(S.f.group || S.f.network || S.f.owner || S.f.status || S.f.q);
}

// ── Arrastrar y soltar ───────────────────────────────────
const D = { el: null, id: 0, x: 0, y: 0, on: false, ghost: null, over: null, timer: 0, touch: false, suppress: false };
function dragStart() {
  D.on = true; D.el.classList.add('dragging');
  D.ghost = D.el.cloneNode(true); D.ghost.classList.add('ss-ghost'); D.ghost.classList.remove('dragging'); document.body.appendChild(D.ghost);
  moveGhost(D.x, D.y);
}
function moveGhost(x, y) { if (D.ghost) { D.ghost.style.left = (x - 30) + 'px'; D.ghost.style.top = (y - 14) + 'px'; } }
function dropTarget(x, y) { const e = document.elementFromPoint(x, y); return e ? e.closest('[data-drop-date]') : null; }
function dragEnd(cancel) {
  clearTimeout(D.timer);
  if (D.on) {
    const t = D.over; D.over && D.over.classList.remove('drop');
    D.el.classList.remove('dragging'); D.ghost && D.ghost.remove();
    D.suppress = true; setTimeout(() => { D.suppress = false; }, 50);
    if (!cancel && t) dropOn(D.id, t);
  }
  Object.assign(D, { el: null, on: false, ghost: null, over: null });
}
async function dropOn(id, t) {
  const date = t.dataset.dropDate;
  const p = S.items.concat(S.backlog).find(x => x.id === id);
  const body = { id, date };
  if ('dropTime' in t.dataset) body.time = t.dataset.dropTime;
  if (p && p.date === (date || null) && (!('time' in body) || (p.time || '') === body.time)) return;
  try { await api('post_move', body); toast(date ? 'Movida al ' + fmtDay(date) + (body.time ? ' a las ' + body.time : '') : 'Quedó sin fecha'); }
  catch (e) {} finally { load(); }
}
document.addEventListener('pointerdown', e => {
  const c = e.target.closest('.ss-chip'); if (!c || !can('edit') || e.button > 0) return;
  Object.assign(D, { el: c, id: Number(c.dataset.post), x: e.clientX, y: e.clientY, on: false, touch: e.pointerType === 'touch' });
  if (D.touch) D.timer = setTimeout(() => { if (D.el) { dragStart(); navigator.vibrate && navigator.vibrate(15); } }, 380);
});
document.addEventListener('pointermove', e => {
  if (!D.el) return;
  if (!D.on) {
    const far = Math.hypot(e.clientX - D.x, e.clientY - D.y) > 6;
    if (D.touch) { if (far) { clearTimeout(D.timer); D.el = null; } return; }   // en táctil, moverse antes de tiempo es desplazar la página
    if (!far) return;
    dragStart();
  }
  moveGhost(e.clientX, e.clientY);
  const t = dropTarget(e.clientX, e.clientY);
  if (t !== D.over) { D.over && D.over.classList.remove('drop'); D.over = t; t && t.classList.add('drop'); }
});
document.addEventListener('touchmove', e => { if (D.on) e.preventDefault(); }, { passive: false });
document.addEventListener('pointerup', () => { if (D.el) dragEnd(false); });
document.addEventListener('pointercancel', () => { if (D.el) dragEnd(true); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { if (D.on) dragEnd(true); else if (!$('#ss-modal').hidden) closeModal(); else if (S.dr) closeDrawer(); } });

// ── Editor lateral ───────────────────────────────────────
function emptyPost(def = {}) {
  const idea = S.meta.statuses.find(s => s.kind === 'idea' && s.active) || S.meta.statuses.find(s => s.active);
  const g = S.f.group && S.f.group !== 'none' ? Number(S.f.group) : null;
  return { id: 0, title: '', description: '', idea: '', copy: '', hashtags: '', notes: '', date: def.date || '', time: def.time || '', brand_id: S.meta.group === 'brand' ? g : null,
           client_id: S.meta.group === 'client' ? g : null, networks: [], format: 'imagen', format_custom: '', owner_id: S.meta.me, status_id: idea.id, tags: [], links: [], refs: [], tickets: { total: 0, done: 0 } };
}
async function openPost(id) {
  if (S.dr && S.dr.dirty && !confirm('Hay cambios sin guardar. ¿Descartarlos?')) return;
  if (!id) return;
  showDrawer('<div class="ss-dr-body"><div class="ss-skel" style="grid-template-columns:1fr"><span style="height:48px"></span><span style="height:200px"></span><span style="height:120px"></span></div></div>');
  try {
    const d = await api('get', { id });
    S.dr = { post: d.post, files: d.files, tickets: d.tickets, history: d.history, tab: S.dr && S.dr.post && S.dr.post.id === id ? S.dr.tab : 'content', dirty: false };
    drawDrawer();
  } catch (e) { closeDrawer(true); }
}
function newPost(def) {
  if (S.dr && S.dr.dirty && !confirm('Hay cambios sin guardar. ¿Descartarlos?')) return;
  S.dr = { post: emptyPost(def), files: [], tickets: [], history: [], tab: 'content', dirty: false };
  showDrawer(''); drawDrawer();
  setTimeout(() => { const t = $('#ss-drawer [data-f="title"]'); t && t.focus(); }, 60);
}
function showDrawer(html) { $('#ss-drawer-wrap').hidden = false; $('#ss-drawer').innerHTML = html; document.body.style.overflow = 'hidden'; }
function closeDrawer(force) {
  if (!force && S.dr && S.dr.dirty && !confirm('Hay cambios sin guardar. ¿Descartarlos?')) return;
  S.dr = null; $('#ss-drawer-wrap').hidden = true; $('#ss-drawer').innerHTML = ''; document.body.style.overflow = '';
  if (/^#(p\/|new)/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search);
}
function drawDrawer() {
  const { post: p, tab } = S.dr; const ro = !can('edit'); const M = S.meta;
  const s = st(p.status_id);
  const stOpts = M.statuses.filter(x => x.active || x.id === p.status_id).map(x => `<option value="${x.id}"${x.id === p.status_id ? ' selected' : ''}>${esc(x.name)}</option>`).join('');
  const tabs = [['content', 'Contenido', 'fa-pen-nib'], ['files', 'Archivos', 'fa-paperclip', S.dr.files.length], ['tickets', 'Tickets', 'fa-ticket', S.dr.tickets.length], ['publish', 'Publicar', 'fa-paper-plane', (S.dr.targets || []).length], ['history', 'Historial', 'fa-clock-rotate-left']];
  $('#ss-drawer').innerHTML = `
    <div class="ss-dr-head"><div class="ttl">
      <input class="big" data-f="title" maxlength="200" placeholder="Título de la publicación" value="${esc(p.title)}" ${ro ? 'disabled' : ''} aria-label="Título" id="ss-drawer-h">
      <div class="ss-dr-sub">${pill(s)}${p.id ? `<span>#${p.id}</span><span>· creada por ${esc(p.created_by || '—')}</span>` : '<span>Nueva</span>'}${p.tickets.total ? `<span>· <i class="fa-solid fa-ticket"></i> ${p.tickets.done}/${p.tickets.total} tickets resueltos</span>` : ''}</div>
    </div><button type="button" class="ss-icon" data-act="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <nav class="ss-dr-tabs" role="tablist">${tabs.map(([k, l, i, n]) => `<button type="button" role="tab" data-tab="${k}" class="${tab === k ? 'on' : ''}"${!p.id && k !== 'content' ? ' disabled title="Guardá la publicación primero"' : ''}><i class="fa-solid ${i}"></i>${l}${n ? `<b>${n}</b>` : ''}</button>`).join('')}</nav>
    <div class="ss-dr-body" id="ss-dr-body">${tab === 'files' ? drFiles() : tab === 'tickets' ? drTickets() : tab === 'publish' ? drPublish() : tab === 'history' ? drHistory() : drContent(stOpts, ro)}</div>
    <div class="ss-dr-foot">
      ${p.id && can('edit') ? `<button type="button" class="ss-link danger" data-act="delete"><i class="fa-regular fa-trash-can"></i> Borrar</button><button type="button" class="ss-link" data-act="duplicate"><i class="fa-regular fa-copy"></i> Duplicar</button>` : ''}
      <span class="sp"></span>
      ${p.id && can('tickets') ? `<button type="button" class="ss-btn ss-btn-ghost" data-act="tickets"><i class="fa-solid fa-ticket"></i> Convertir en ticket</button>` : ''}
      ${ro ? '' : `<button type="button" class="ss-btn" data-act="save"><i class="fa-solid fa-check"></i> ${p.id ? 'Guardar' : 'Crear publicación'}</button>`}
    </div>`;
  if (location.hash !== (p.id ? '#p/' + p.id : '#new')) history.replaceState(null, '', p.id ? '#p/' + p.id : '#new');
  wireDrawer();
  if (tab === 'publish' && !S.dr.pubLoaded) loadPublish();
}
function drContent(stOpts, ro) {
  const p = S.dr.post, M = S.meta, dis = ro ? ' disabled' : '';
  const group = M.group === 'client'
    ? `<label class="ss-f"><span>Cliente</span><select data-f="client_id"${dis}><option value="">Sin cliente</option>${M.clients.map(c => `<option value="${c.id}"${c.id === p.client_id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>`
    : `<label class="ss-f"><span>Marca</span><select data-f="brand_id"${dis}><option value="">Sin marca</option>${M.brands.map(b => `<option value="${b.id}"${b.id === p.brand_id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>`;
  const owners = M.people.filter(x => can('assign') || x.id === M.me || x.id === p.owner_id);
  const area = (k, l, max, cls = '', hint = '') => `<label class="ss-f full"><span>${l}<small class="ss-count" data-count="${k}" data-max="${max}">${(p[k] || '').length}/${max}</small></span><textarea data-f="${k}" maxlength="${max}" class="${cls}"${dis} placeholder="${esc(hint)}">${esc(p[k])}</textarea></label>`;
  return `
  <div class="ss-sec"><h4><i class="fa-regular fa-calendar"></i> Planificación</h4><div class="ss-form">
    <label class="ss-f"><span>Estado</span><select data-f="status_id"${dis}>${stOpts}</select></label>
    ${group}
    <label class="ss-f"><span>Fecha<small>vacía = idea sin día</small></span><input type="date" data-f="date" value="${esc(p.date || '')}"${dis}></label>
    <label class="ss-f"><span>Hora<small>opcional</small></span><input type="time" data-f="time" value="${esc(p.time || '')}"${dis}></label>
    <label class="ss-f"><span>Formato</span><select data-f="format"${dis}>${Object.entries(M.formats).map(([k, l]) => `<option value="${k}"${k === p.format ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></label>
    <label class="ss-f" data-custom-fmt ${p.format === 'personalizado' ? '' : 'hidden'}><span>¿Cuál?</span><input data-f="format_custom" maxlength="60" value="${esc(p.format_custom)}" placeholder="Ej. Live, encuesta, GIF"${dis}></label>
    <label class="ss-f"><span>Responsable</span><select data-f="owner_id"${dis}><option value="">Sin responsable</option>${owners.map(o => `<option value="${o.id}"${o.id === p.owner_id ? ' selected' : ''}>${esc(o.name)}${o.id === M.me ? ' (yo)' : ''}</option>`).join('')}</select></label>
    <div class="ss-f full"><span>Redes</span><div class="ss-nets">${Object.entries(M.networks).map(([k, n]) => `<label style="--n:${n.color}"><input type="checkbox" name="nets" value="${k}"${p.networks.includes(k) ? ' checked' : ''}${dis}><i class="fa-brands ${n.icon}"></i>${esc(n.name)}</label>`).join('')}</div></div>
    <div class="ss-f full"><span>Etiquetas<small>Enter para agregar</small></span><div class="ss-tags-in" id="ss-tags">${p.tags.map(t => `<span class="ss-tag" data-tag="${esc(t)}">#${esc(t)}${ro ? '' : `<button type="button" data-act="untag" aria-label="Sacar ${esc(t)}">×</button>`}</span>`).join('')}${ro ? '' : '<input id="ss-tag-in" maxlength="30" placeholder="campaña, lanzamiento…" aria-label="Nueva etiqueta">'}</div></div>
  </div></div>
  <div class="ss-sec"><h4><i class="fa-regular fa-lightbulb"></i> Contenido</h4><div class="ss-form">
    ${area('idea', 'Idea', 5000, '', 'De qué va, el ángulo, por qué ahora')}
    ${area('description', 'Descripción y brief', 5000, '', 'Lo que tiene que saber quien la produce')}
    ${area('copy', 'Copy', 10000, 'tall', 'Texto de la publicación')}
    ${area('hashtags', 'Hashtags', 2000, '', '#ejemplo #otra')}
  </div></div>
  <div class="ss-sec"><h4><i class="fa-solid fa-link"></i> Enlaces</h4><div class="ss-rows" id="ss-links">${(p.links.length ? p.links : ro ? [] : [{ url: '', label: '' }]).map(l => linkRow(l, ro)).join('') || '<p class="ss-hint">Sin enlaces.</p>'}</div>
    ${ro ? '' : '<button type="button" class="ss-link" data-act="addlink"><i class="fa-solid fa-plus"></i> Agregar enlace</button>'}</div>
  <div class="ss-sec"><h4><i class="fa-regular fa-images"></i> Referencias</h4><div class="ss-refs" id="ss-refs">${p.refs.map((r, i) => refRow(r, i, ro)).join('') || '<p class="ss-hint">Inspiración, ejemplos o piezas de Promos. No se copian: queda el enlace.</p>'}</div>
    ${ro ? '' : `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"><input class="ss-in" id="ss-ref-url" placeholder="https://… (referencia)" style="flex:1;min-width:200px"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="addref"><i class="fa-solid fa-plus"></i> Agregar</button><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="promo"><i class="fa-solid fa-layer-group"></i> Elegir de Promos</button></div>`}</div>
  <div class="ss-sec"><h4><i class="fa-solid fa-lock"></i> Notas internas</h4>
    <div class="ss-note"><i class="fa-solid fa-eye-slash"></i><span>Solo las ve el equipo. No se publican ni van al cliente.</span></div>
    <div class="ss-form">${area('notes', 'Notas', 10000)}</div></div>`;
}
const linkRow = (l, ro) => `<div class="ss-lk"><input class="ss-in" data-link="url" value="${esc(l.url)}" placeholder="https://…"${ro ? ' disabled' : ''}><input class="ss-in lbl" data-link="label" value="${esc(l.label || '')}" placeholder="Nombre (opcional)"${ro ? ' disabled' : ''}>${ro ? '' : '<button type="button" class="ss-icon" data-act="rmlink" aria-label="Sacar enlace"><i class="fa-solid fa-xmark"></i></button>'}</div>`;
const refRow = (r, i, ro) => `<div class="ss-ref">${r.img ? `<img src="${esc(r.img)}" alt="" loading="lazy">` : `<span class="ic"><i class="fa-solid ${r.type === 'promo' ? 'fa-layer-group' : 'fa-globe'}"></i></span>`}
  <div class="x"><a href="${esc(r.url)}" target="_blank" rel="noopener noreferrer">${esc(r.label || r.url)}</a><small>${r.type === 'promo' ? 'Contenido de Promos' : esc(r.url)}</small></div>${ro ? '' : `<button type="button" class="ss-icon" data-act="rmref" data-i="${i}" aria-label="Sacar referencia"><i class="fa-solid fa-xmark"></i></button>`}</div>`;
function drFiles() {
  const f = S.dr.files, ro = !can('edit');
  const size = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  return `${ro ? '' : '<label class="ss-drop" id="ss-drop"><i class="fa-solid fa-cloud-arrow-up"></i>Arrastrá archivos acá o <u>elegilos</u><br><small>Imágenes, videos, PDF, documentos y fuentes de diseño, hasta 200 MB cada uno</small><input type="file" id="ss-file-in" multiple hidden></label><div class="ss-up" id="ss-up" hidden></div>'}
  ${f.length ? `<div class="ss-files">${f.map(x => `<div class="ss-file"><a class="th" href="${esc(x.url)}" target="_blank" rel="noopener">${x.image ? `<img src="${esc(x.url)}" alt="" loading="lazy">` : x.video ? `<video src="${esc(x.url)}" muted preload="metadata"></video>` : `<i class="fa-regular ${x.ext === 'pdf' ? 'fa-file-pdf' : 'fa-file'}"></i>`}</a>
    <div class="nm" title="${esc(x.name)}">${esc(x.name)}<small>${size(x.size)} · ${esc(x.who)}</small></div>${ro ? '' : `<button type="button" class="del" data-act="rmfile" data-id="${x.id}" aria-label="Borrar ${esc(x.name)}"><i class="fa-solid fa-trash"></i></button>`}</div>`).join('')}</div>`
    : '<div class="ss-empty sm"><p>Sin archivos todavía. Los tickets que crees desde acá van a enlazar estos archivos, sin copiarlos.</p></div>'}`;
}
function drTickets() {
  const t = S.dr.tickets, p = S.dr.post;
  const done = t.filter(x => x.done).length;
  const r = S.meta.rules;
  return `${t.length ? `<div class="ss-prod"><b>${done}/${t.length}</b><div class="bar"><i style="width:${t.length ? Math.round(done * 100 / t.length) : 0}%"></i></div><span class="ss-hint" style="margin:0">tickets resueltos</span></div>` : ''}
  <div class="ss-tks">${t.map(x => `<div class="ss-tk${x.gone ? ' gone' : ''}"><span class="k"><i class="fa-solid ${{ diseno: 'fa-palette', video: 'fa-video', copy: 'fa-pen-nib' }[x.kind] || 'fa-ticket'}"></i></span>
    <div class="x"><b>#${x.id} · ${esc(x.gone ? 'Ticket borrado en Trabajo' : x.subject)}</b><small>${esc(x.kind_name)}${x.team ? ' · ' + esc(x.team) : ''}${x.assignee ? ' · ' + esc(x.assignee) : ' · sin responsable'}${x.due ? ' · entrega ' + esc(fmtWhen(x.due)) : ''}</small></div>
    <div style="display:flex;gap:6px;align-items:center">${x.gone ? '' : `<span class="ss-pill" style="--c:${esc(x.status_color)}">${esc(x.status_name)}</span>`}${x.can_open ? `<a class="ss-icon" href="${esc(x.url)}" target="_blank" rel="noopener" aria-label="Abrir en Trabajo"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}${can('tickets') ? `<button type="button" class="ss-icon" data-act="unlink" data-id="${x.id}" aria-label="Desvincular"><i class="fa-solid fa-link-slash"></i></button>` : ''}</div></div>`).join('')}</div>
  ${t.length ? '' : `<div class="ss-empty sm"><div class="art" style="width:56px;height:56px;font-size:22px"><i class="fa-solid fa-ticket"></i></div><h3>Sin tickets</h3><p>Mandá a producir la pieza: se crean tickets reales en Trabajo (con su tarea) y su avance se ve acá.</p></div>`}
  ${can('tickets') ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><button type="button" class="ss-btn" data-act="tickets"><i class="fa-solid fa-ticket"></i> Convertir en ticket</button>
    <span style="display:flex;gap:6px;align-items:center"><input class="ss-in" id="ss-link-id" inputmode="numeric" placeholder="N.º de ticket" style="width:120px"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="link">Vincular existente</button></span></div>` : ''}
  <div class="ss-note" style="margin-top:14px"><i class="fa-solid fa-circle-info"></i><span>Cómo se refleja: ${r.on_create.on ? `al crear tickets pasa a "${esc(st(r.on_create.design).name)}" (o "${esc(st(r.on_create.video).name)}" si hay video)` : 'crear tickets no cambia el estado'}; ${r.on_done.on ? `cuando todos quedan resueltos pasa a "${esc(st(r.on_done.to).name)}"` : 'terminar los tickets no cambia el estado'}${r.on_reopen.on ? `; si se reabre uno estando en revisión vuelve a "${esc(st(r.on_reopen.to).name)}"` : ''}. Nunca se toca una publicación aprobada, programada, publicada o cancelada.</span></div>`;
}
function drHistory() {
  const ic = { created: 'fa-plus', edit: 'fa-pen', status: 'fa-flag', move: 'fa-calendar', ticket: 'fa-ticket', ticket_status: 'fa-ticket', rule: 'fa-wand-magic-sparkles', rule_skip: 'fa-hand', file: 'fa-paperclip', delete: 'fa-trash' };
  const h = S.dr.history;
  return h.length ? `<div class="ss-hist"><ul>${h.map(x => `<li class="${esc(x.kind)}"><i class="fa-solid ${ic[x.kind] || 'fa-circle'}"></i><div>${x.who ? `<b>${esc(x.who)}</b> ` : ''}${esc(x.body)}<small>${esc(fmtWhen(x.at))}</small></div></li>`).join('')}</ul></div>` : '<div class="ss-empty sm"><p>Sin movimientos.</p></div>';
}
function wireDrawer() {
  const dr = $('#ss-drawer');
  dr.querySelectorAll('[data-f], input[name="nets"], [data-link]').forEach(el => el.addEventListener('input', () => {
    S.dr.dirty = true;
    if (el.dataset.f === 'format') $('[data-custom-fmt]', dr).hidden = el.value !== 'personalizado';
    const c = el.dataset.f && $(`[data-count="${el.dataset.f}"]`, dr);
    if (c) { c.textContent = el.value.length + '/' + c.dataset.max; c.classList.toggle('warn', el.value.length > c.dataset.max * .9); }
  }));
  const tin = $('#ss-tag-in', dr);
  if (tin) tin.addEventListener('keydown', e => {
    if (e.key !== 'Enter' && e.key !== ',') { if (e.key === 'Backspace' && !tin.value) { const last = $$('[data-tag]', dr).pop(); if (last) { last.remove(); S.dr.dirty = true; } } return; }
    e.preventDefault(); const v = tin.value.replace(/^#/, '').trim().slice(0, 30); if (!v) return;
    if (!$$('[data-tag]', dr).some(x => x.dataset.tag.toLowerCase() === v.toLowerCase())) tin.insertAdjacentHTML('beforebegin', `<span class="ss-tag" data-tag="${esc(v)}">#${esc(v)}<button type="button" data-act="untag" aria-label="Sacar ${esc(v)}">×</button></span>`);
    tin.value = ''; S.dr.dirty = true;
  });
  const drop = $('#ss-drop', dr), fin = $('#ss-file-in', dr);
  if (drop && fin) {
    fin.addEventListener('change', () => sendFiles(Array.from(fin.files)));
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); sendFiles(Array.from(e.dataTransfer.files)); });
  }
}
/** Lee el formulario del editor (lo que no está en la pestaña Contenido queda como estaba). */
function collect() {
  const p = { ...S.dr.post }; const dr = $('#ss-drawer');
  const t = $('[data-f="title"]', dr); if (t) p.title = t.value;
  if (!$('[data-f="status_id"]', dr)) return p;
  for (const el of $$('[data-f]', dr)) {
    const k = el.dataset.f; if (k === 'title') continue;
    p[k] = ['status_id', 'owner_id', 'brand_id', 'client_id'].includes(k) ? (el.value ? Number(el.value) : null) : el.value;
  }
  p.networks = $$('input[name="nets"]:checked', dr).map(x => x.value);
  p.tags = $$('[data-tag]', dr).map(x => x.dataset.tag);
  const pend = $('#ss-tag-in', dr); if (pend && pend.value.trim()) p.tags.push(pend.value.replace(/^#/, '').trim());
  p.links = $$('.ss-lk', dr).map(r => ({ url: $('[data-link="url"]', r).value.trim(), label: $('[data-link="label"]', r).value.trim() })).filter(l => l.url);
  return p;
}
async function save() {
  const p = collect();
  if (!p.title.trim()) { toast('Ponele un título a la publicación.', true); $('[data-f="title"]').focus(); return; }
  const btn = $('[data-act="save"]'); btn && (btn.disabled = true);
  try {
    const d = await api('post_save', { id: p.id, post: p });
    const isNew = !p.id;
    S.dr = { ...S.dr, post: d.post, files: d.files, tickets: d.tickets, history: d.history, dirty: false };
    drawDrawer(); toast(isNew ? 'Publicación creada' : 'Cambios guardados'); load();
  } catch (e) { btn && (btn.disabled = false); }
}
async function sendFiles(list) {
  if (!list.length || !S.dr || !S.dr.post.id) return;
  const up = $('#ss-up'); up.hidden = false;
  for (let i = 0; i < list.length; i++) {
    up.textContent = `Subiendo ${i + 1} de ${list.length}: ${list[i].name}…`;
    try { const d = await upload(S.dr.post.id, list[i]); S.dr.files = d.files; } catch (e) {}
  }
  up.hidden = true; drawDrawer(); toast('Archivos listos');
}

// ── Convertir en ticket ──────────────────────────────────
let TK = null;
async function openTickets() {
  if (S.dr.dirty) { toast('Guardá los cambios antes de crear tickets.', true); return; }
  openModal('<div class="ss-dg-b"><div class="ss-skel" style="grid-template-columns:1fr"><span style="height:220px"></span></div></div>');
  try { TK = { opt: await api('ticket_options'), items: [] }; } catch (e) { closeModal(); return; }
  if (!TK.opt.cats.length) {
    $('#ss-dialog').innerHTML = `<div class="ss-dg-h"><h2 id="ss-dialog-h">Convertir en ticket</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="ss-dg-b"><div class="ss-empty"><div class="art"><i class="fa-solid fa-ticket"></i></div><h3>Faltan categorías de tickets</h3><p>Trabajo necesita al menos una categoría activa (Diseño, Video…). Se crean en Trabajo › Tickets › Configuración.</p><a class="ss-btn ss-btn-ghost" href="/toolz/trabajo/#tickets" target="_blank" rel="noopener">Ir a Trabajo</a></div></div>`;
    return;
  }
  addTicketItem(S.dr.post.format && /video|reel/.test(S.dr.post.format) ? 'video' : 'diseno');
}
function addTicketItem(kind) {
  const o = TK.opt;
  const guess = re => (o.teams.find(t => re.test(t.name)) || {}).id || '';
  const team = kind === 'video' ? guess(/video|audiovisual|edici/i) : kind === 'copy' ? guess(/copy|redac|contenido/i) : guess(/dise/i);
  TK.items.push({ kind, team_id: team, cat_id: '', assignee_id: '', priority: o.default_priority, due: S.dr.post.date && S.dr.post.date >= todayStr() ? S.dr.post.date : '', description: '', files: S.dr.files.map(f => f.id) });
  drawTickets();
}
function readTickets() {
  $$('.ss-tkform').forEach((f, i) => { const it = TK.items[i]; if (!it) return;
    $$('[data-tk]', f).forEach(el => { it[el.dataset.tk] = el.value; });
    it.files = $$('input[data-tkfile]:checked', f).map(x => Number(x.value)); });
}
function drawTickets() {
  const o = TK.opt, p = S.dr.post;
  const opt = (v, l, sel) => `<option value="${esc(v)}"${String(v) === String(sel) ? ' selected' : ''}>${esc(l)}</option>`;
  $('#ss-dialog').className = 'ss-dialog';
  $('#ss-dialog').innerHTML = `<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-ticket"></i> Convertir en ticket</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="ss-dg-b">
    <div class="ss-note"><i class="fa-solid fa-circle-info"></i><span>Se crean tickets reales en Trabajo, cada uno con su tarea en el tablero del departamento. Llevan los datos de "<b>${esc(p.title)}</b>", el enlace a la publicación y a los archivos elegidos (no se copian).</span></div>
    ${TK.items.map((it, i) => {
      const cats = o.cats.filter(c => !it.team_id || !c.team_id || String(c.team_id) === String(it.team_id));
      const members = it.team_id ? o.people.filter(x => x.teams.includes(Number(it.team_id))) : [];
      const others = o.people.filter(x => !members.includes(x));
      return `<div class="ss-tkform"><div class="ss-tkform-h"><b>Ticket ${i + 1}</b>${TK.items.length > 1 ? `<button type="button" class="ss-link danger" data-act="tkdel" data-i="${i}"><i class="fa-solid fa-xmark"></i> Quitar</button>` : ''}</div>
      <div class="ss-kinds" style="margin-bottom:10px">${Object.entries(o.kinds).map(([k, l]) => `<button type="button" class="${k === it.kind ? 'on' : ''}" data-act="tkkind" data-i="${i}" data-k="${k}">${esc(l)}</button>`).join('')}</div>
      <div class="ss-form">
        <label class="ss-f"><span>Departamento</span><select data-tk="team_id" data-i="${i}">${opt('', 'El de la categoría', it.team_id)}${o.teams.map(t => opt(t.id, t.name, it.team_id)).join('')}</select></label>
        <label class="ss-f"><span>Categoría</span><select data-tk="cat_id">${opt('', 'Elegí una categoría', it.cat_id)}${cats.map(c => opt(c.id, c.name, it.cat_id)).join('')}</select></label>
        <label class="ss-f"><span>Responsable</span><select data-tk="assignee_id">${opt('', 'Que lo tome el departamento', it.assignee_id)}${members.length ? `<optgroup label="Del departamento">${members.map(x => opt(x.id, x.name, it.assignee_id)).join('')}</optgroup><optgroup label="Otras personas">` : ''}${others.map(x => opt(x.id, x.name, it.assignee_id)).join('')}${members.length ? '</optgroup>' : ''}</select></label>
        <label class="ss-f"><span>Prioridad</span><select data-tk="priority">${o.priorities.map(x => opt(x.id, x.name, it.priority)).join('')}</select></label>
        <label class="ss-f"><span>Entrega<small>vacía = plazo de la categoría</small></span><input type="date" data-tk="due" min="${todayStr()}" value="${esc(it.due)}"></label>
        <label class="ss-f full"><span>Qué se pide</span><textarea data-tk="description" maxlength="5000" placeholder="Medidas, versiones, tono, lo que tiene que tener la pieza…">${esc(it.description)}</textarea></label>
        ${S.dr.files.length ? `<div class="ss-f full"><span>Archivos que se enlazan</span><div class="ss-fchecks">${S.dr.files.map(f => `<label><input type="checkbox" data-tkfile value="${f.id}"${it.files.includes(f.id) ? ' checked' : ''}>${esc(f.name)}</label>`).join('')}</div></div>` : ''}
      </div></div>`;
    }).join('')}
    ${TK.items.length < 10 ? '<button type="button" class="ss-link" data-act="tkadd"><i class="fa-solid fa-plus"></i> Agregar otro ticket (diseño, video, copy…)</button>' : ''}
  </div>
  <div class="ss-dg-f"><span class="sp">${TK.items.length} ticket${TK.items.length > 1 ? 's' : ''}</span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="tkcreate"><i class="fa-solid fa-check"></i> Crear ${TK.items.length > 1 ? 'tickets' : 'ticket'}</button></div>`;
  $$('[data-tk="team_id"]').forEach(el => el.addEventListener('change', () => { readTickets(); TK.items[Number(el.dataset.i)].cat_id = ''; drawTickets(); }));
}
async function createTickets() {
  readTickets();
  const bad = TK.items.findIndex(it => !it.cat_id);
  if (bad >= 0) { toast(`Ticket ${bad + 1}: elegí una categoría.`, true); return; }
  const btn = $('[data-act="tkcreate"]'); btn.disabled = true;
  try {
    const d = await api('tickets_create', { id: S.dr.post.id, items: TK.items.map(it => ({ ...it, cat_id: Number(it.cat_id), team_id: Number(it.team_id) || 0, assignee_id: Number(it.assignee_id) || 0 })) });
    closeModal();
    S.dr = { ...S.dr, post: d.post, files: d.files, tickets: d.tickets, history: d.history, tab: 'tickets', dirty: false };
    drawDrawer(); load();
    toast(d.tickets.length > 1 ? `Se crearon ${d.tickets.length} tickets en Trabajo` : `Se creó el ticket #${d.tickets[0]} en Trabajo`);
  } catch (e) { btn.disabled = false; }
}

// ── Promos como referencia ───────────────────────────────
function openPromo() {
  openModal(`<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-layer-group"></i> Elegir de Promos</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><label class="ss-search" style="max-width:none"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ss-promo-q" placeholder="Nombre del contenido" autocomplete="off"></label><div class="ss-refs" id="ss-promo-res" style="margin-top:12px"></div></div>`, 'sm');
  const q = $('#ss-promo-q'); let t = 0;
  const run = async () => {
    try {
      const d = await api('promo_search', { q: q.value });
      $('#ss-promo-res').innerHTML = d.items.length ? d.items.map(x => `<button type="button" class="ss-ref" data-act="pickpromo" data-id="${x.id}" data-name="${esc(x.name)}" data-img="${esc(x.img)}" style="cursor:pointer;text-align:left;color:inherit;font:inherit">${x.img ? `<img src="${esc(x.img)}" alt="">` : '<span class="ic"><i class="fa-solid fa-layer-group"></i></span>'}<div class="x"><b>${esc(x.name)}</b><small>${esc(x.project || '')}${x.date ? ' · ' + esc(x.date) : ''}</small></div></button>`).join('') : '<p class="ss-hint">Sin resultados.</p>';
    } catch (e) { $('#ss-promo-res').innerHTML = `<p class="ss-hint">${esc(e.message)}</p>`; }
  };
  q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 250); }); q.focus(); run();
}

// ── Configuración ────────────────────────────────────────
let CF = null;
function openConfig() {
  const M = S.meta;
  CF = { group: M.group, statuses: M.statuses.map(s => ({ ...s })), rules: JSON.parse(JSON.stringify(M.rules)) };
  openModal(''); drawConfig();
}
function drawConfig() {
  const M = S.meta;
  const kindName = k => M.kinds[k] || k;
  const stSel = (val, attr) => `<select ${attr}>${CF.statuses.filter(s => s.active && s.id && !['approved', 'scheduled', 'published', 'cancelled'].includes(s.kind)).map(s => `<option value="${s.id}"${s.id === val ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}</select>`;
  const r = CF.rules;
  $('#ss-dialog').className = 'ss-dialog';
  $('#ss-dialog').innerHTML = `<div class="ss-dg-h"><h2 id="ss-dialog-h"><i class="fa-solid fa-sliders"></i> Configurar Social Studio</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="ss-dg-b">
    <div class="ss-sec"><h4>Cómo se organiza</h4><div class="ss-radio">
      ${M.group_options.brand ? `<label><input type="radio" name="ss-group" value="brand"${CF.group === 'brand' ? ' checked' : ''}><span><b>Por marca</b>Usa las marcas de Cronos: cada persona ve solo las publicaciones de sus marcas.</span></label>` : ''}
      ${M.group_options.client ? `<label><input type="radio" name="ss-group" value="client"${CF.group === 'client' ? ' checked' : ''}><span><b>Por cliente</b>Usa las fichas de Clientes. Lo ve todo el que tiene acceso a Social Studio.</span></label>` : ''}
    </div>${Object.keys(M.group_options).length < 2 ? `<p class="ss-hint">${M.group_options.brand ? 'Para organizar por cliente, el sitio tiene que tener Clientes.' : 'Para organizar por marca, cargá marcas en Admin › Marcas.'}</p>` : ''}</div>
    <div class="ss-sec"><h4>Estados</h4><p class="ss-hint" style="margin:0 0 8px">Renombralos, cambiales el color y el orden, o sumá estados propios. No se borran: se desactivan. Aprobar, programar y publicar piden su permiso.</p>
      <div class="ss-sts">${CF.statuses.map((s, i) => `<div class="ss-st${s.active ? '' : ' off'}"><span class="mv"><button type="button" data-act="stup" data-i="${i}" aria-label="Subir"${i ? '' : ' disabled'}>▲</button><button type="button" data-act="stdown" data-i="${i}" aria-label="Bajar"${i < CF.statuses.length - 1 ? '' : ' disabled'}>▼</button></span>
        <input type="color" class="ss-in" data-st="color" data-i="${i}" value="${esc(s.color)}" aria-label="Color" style="padding:2px;height:32px"><input class="ss-in" data-st="name" data-i="${i}" value="${esc(s.name)}" maxlength="40" aria-label="Nombre">
        <span class="kind">${s.kind === 'custom' ? 'Propio' : 'Tipo: ' + esc(kindName(s.kind))}</span>
        <label class="ss-link" style="gap:6px"><input type="checkbox" data-st="active" data-i="${i}"${s.active ? ' checked' : ''}> Activo</label><span></span></div>`).join('')}</div>
      <button type="button" class="ss-link" data-act="stadd"><i class="fa-solid fa-plus"></i> Agregar estado propio</button></div>
    <div class="ss-sec"><h4>Reglas con los tickets</h4><p class="ss-hint" style="margin:0 0 8px">El estado editorial siempre manda: las reglas solo avanzan, y nunca tocan una publicación aprobada, programada, publicada o cancelada (queda anotado en su historial).</p>
      <div class="ss-rule"><input type="checkbox" data-rule="on_create"${r.on_create.on ? ' checked' : ''} aria-label="Activar"><div><p>Al <b>crear tickets</b> desde una idea o pendiente, pasa a</p><div class="sel">${stSel(r.on_create.design, 'data-rsel="on_create.design"')} <span>o, si hay un ticket de video,</span> ${stSel(r.on_create.video, 'data-rsel="on_create.video"')}</div></div></div>
      <div class="ss-rule"><input type="checkbox" data-rule="on_done"${r.on_done.on ? ' checked' : ''} aria-label="Activar"><div><p>Cuando <b>todos sus tickets</b> quedan resueltos o cerrados, pasa a</p><div class="sel">${stSel(r.on_done.to, 'data-rsel="on_done.to"')}</div></div></div>
      <div class="ss-rule"><input type="checkbox" data-rule="on_reopen"${r.on_reopen.on ? ' checked' : ''} aria-label="Activar"><div><p>Si un ticket se <b>reabre</b> mientras la publicación está en revisión, vuelve a</p><div class="sel">${stSel(r.on_reopen.to, 'data-rsel="on_reopen.to"')}</div></div></div>
    </div>
    ${M.planify ? `<div class="ss-sec"><h4>Importar desde Planify</h4><p class="ss-hint" style="margin:0 0 8px">Copia las publicaciones de Planify a Social Studio. Planify queda como está; lo que ya se importó no se repite.</p><div id="ss-pf"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="pfload"><i class="fa-solid fa-file-import"></i> Ver proyectos de Planify</button></div></div>` : ''}
  </div>
  <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button><button type="button" class="ss-btn" data-act="cfsave"><i class="fa-solid fa-check"></i> Guardar</button></div>`;
}
function readConfig() {
  const g = $('input[name="ss-group"]:checked'); if (g) CF.group = g.value;
  $$('[data-st]').forEach(el => { const s = CF.statuses[Number(el.dataset.i)]; s[el.dataset.st] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
  $$('[data-rule]').forEach(el => { CF.rules[el.dataset.rule].on = el.checked; });
  $$('[data-rsel]').forEach(el => { const [a, b] = el.dataset.rsel.split('.'); CF.rules[a][b] = Number(el.value); });
}
async function saveConfig() {
  readConfig();
  try {
    S.meta = await api('config_save', { group: CF.group, statuses: CF.statuses.map(s => ({ id: s.id || 0, name: s.name, color: s.color, active: s.active })), rules: CF.rules });
    S.f.group = ''; setupFilters(); closeModal(); toast('Configuración guardada'); load();
  } catch (e) {}
}
async function planifyLoad() {
  const box = $('#ss-pf');
  try {
    const d = await api('planify_projects');
    const M = S.meta, list = M.group === 'client' ? M.clients : M.brands;
    box.innerHTML = d.items.map(x => `<div class="ss-pf"><span><b>${esc(x.name)}</b> · ${x.posts} publicaciones${x.imported ? ` (${x.imported} ya importadas)` : ''}</span>
      <select class="ss-in" data-pf="${x.id}"><option value="">Sin ${groupWord()}</option>${list.map(g => `<option value="${g.id}"${g.name.toLowerCase() === x.name.toLowerCase() ? ' selected' : ''}>${esc(g.name)}</option>`).join('')}</select></div>`).join('')
      + '<button type="button" class="ss-btn ss-btn-sm" data-act="pfimport" style="margin-top:6px"><i class="fa-solid fa-file-import"></i> Importar</button>';
  } catch (e) {}
}
async function planifyImport() {
  const map = {}; const key = S.meta.group === 'client' ? 'client_id' : 'brand_id';
  $$('[data-pf]').forEach(el => { map[el.dataset.pf] = { [key]: Number(el.value) || 0 }; });
  try { const d = await api('planify_import', { map }); toast(d.imported ? `Se importaron ${d.imported} publicaciones` : 'No había nada nuevo para importar'); planifyLoad(); load(); } catch (e) {}
}

// ── Diálogo ──────────────────────────────────────────────
function openModal(html, size = '') { $('#ss-modal').hidden = false; $('#ss-dialog').className = 'ss-dialog' + (size ? ' ' + size : ''); $('#ss-dialog').innerHTML = html; }
function closeModal() { $('#ss-modal').hidden = true; $('#ss-dialog').innerHTML = ''; }

// ── Clics ────────────────────────────────────────────────
document.addEventListener('click', async e => {
  if (D.suppress) { e.preventDefault(); return; }
  const tabB = e.target.closest('[data-tab]');
  if (tabB && S.dr && !tabB.disabled) { if (S.dr.tab === 'content') S.dr.post = collect(); S.dr.tab = tabB.dataset.tab; drawDrawer(); return; }
  const vb = e.target.closest('[data-view]');
  if (vb) { S.view = vb.dataset.view; store.set('view', S.view); load(); return; }
  const a = e.target.closest('[data-act]');
  const chipEl = e.target.closest('[data-post]');
  if (chipEl && !(a && chipEl.contains(a))) { openPost(Number(chipEl.dataset.post)); return; }   // la tarjeta gana sobre la franja que la contiene
  if (!a) { if (e.target.id === 'ss-modal') closeModal(); return; }
  const act = a.dataset.act;
  switch (act) {
    case 'new': newPost({ date: a.dataset.d || '' }); break;
    case 'new-slot': newPost({ date: a.dataset.d, time: a.dataset.t }); break;
    case 'prev': shift(-1); break;
    case 'next': shift(1); break;
    case 'today': S.cur = new Date(); load(); break;
    case 'goday': S.cur = parse(a.dataset.d); S.view = 'day'; load(); break;
    case 'backlog': S.showBacklog = !S.showBacklog; store.set('backlog', S.showBacklog ? '1' : ''); renderBacklog(); break;
    case 'clear': S.f = { group: '', network: '', owner: '', status: '', q: '' }; $('#ss-q').value = ''; setupFilters(); load(); break;
    case 'reload': load(); break;
    case 'close': closeDrawer(); break;
    case 'save': save(); break;
    case 'delete':
      if (!confirm('¿Borrar esta publicación? Sus tickets siguen en Trabajo.')) return;
      try { await api('post_delete', { id: S.dr.post.id }); closeDrawer(true); toast('Publicación borrada'); load(); } catch (err) {} break;
    case 'duplicate':
      try { const d = await api('post_duplicate', { id: S.dr.post.id }); S.dr.dirty = false; await openPost(d.id); toast('Copia creada (sin fecha)'); load(); } catch (err) {} break;
    case 'untag': a.parentElement.remove(); S.dr.dirty = true; break;
    case 'addlink': $('#ss-links').insertAdjacentHTML('beforeend', linkRow({ url: '', label: '' }, false)); $$('#ss-links .ss-lk').pop().querySelector('input').focus(); S.dr.dirty = true; break;
    case 'rmlink': a.closest('.ss-lk').remove(); S.dr.dirty = true; break;
    case 'addref': {
      const u = $('#ss-ref-url').value.trim(); if (!/^https?:\/\//i.test(u)) { toast('Pegá un enlace que empiece con https://', true); return; }
      S.dr.post = collect(); S.dr.post.refs = [...S.dr.post.refs, { type: 'url', url: u, label: '' }]; S.dr.dirty = true; drawDrawer(); break;
    }
    case 'rmref': S.dr.post = collect(); S.dr.post.refs = S.dr.post.refs.filter((_, i) => i !== Number(a.dataset.i)); S.dr.dirty = true; drawDrawer(); break;
    case 'promo': openPromo(); break;
    case 'pickpromo': S.dr.post = collect(); S.dr.post.refs = [...S.dr.post.refs, { type: 'promo', id: Number(a.dataset.id), label: a.dataset.name, url: '/toolz/promos/', img: a.dataset.img }]; S.dr.dirty = true; closeModal(); drawDrawer(); break;
    case 'rmfile':
      if (!confirm('¿Borrar este archivo? Los tickets que lo enlazan dejan de poder abrirlo.')) return;
      try { const d = await api('file_delete', { id: Number(a.dataset.id) }); S.dr.files = d.files; drawDrawer(); } catch (err) {} break;
    case 'tickets': openTickets(); break;
    case 'tkadd': readTickets(); addTicketItem('diseno'); break;
    case 'tkdel': readTickets(); TK.items.splice(Number(a.dataset.i), 1); drawTickets(); break;
    case 'tkkind': readTickets(); TK.items[Number(a.dataset.i)].kind = a.dataset.k; drawTickets(); break;
    case 'tkcreate': createTickets(); break;
    case 'link': {
      const n = Number($('#ss-link-id').value.replace(/\D/g, '')); if (!n) { toast('Escribí el número del ticket.', true); return; }
      try { const d = await api('ticket_link', { id: S.dr.post.id, ticket_id: n }); S.dr = { ...S.dr, ...d, post: d.post }; drawDrawer(); load(); toast('Ticket vinculado'); } catch (err) {} break;
    }
    case 'unlink':
      if (!confirm('¿Desvincular este ticket? El ticket sigue en Trabajo.')) return;
      try { const d = await api('ticket_unlink', { id: S.dr.post.id, ticket_id: Number(a.dataset.id) }); S.dr = { ...S.dr, ...d, post: d.post }; drawDrawer(); load(); } catch (err) {} break;
    case 'config': openConfig(); break;
    case 'stup': case 'stdown': { readConfig(); const i = Number(a.dataset.i), j = i + (act === 'stup' ? -1 : 1); [CF.statuses[i], CF.statuses[j]] = [CF.statuses[j], CF.statuses[i]]; drawConfig(); break; }
    case 'stadd': readConfig(); CF.statuses.push({ id: 0, kind: 'custom', name: 'Nuevo estado', color: '#8b5cf6', active: 1 }); drawConfig(); break;
    case 'cfsave': saveConfig(); break;
    case 'pfload': planifyLoad(); break;
    case 'pfimport': planifyImport(); break;
    case 'mclose': closeModal(); break;
  }
});
document.addEventListener('keydown', e => {
  if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('ss-chip')) { e.preventDefault(); openPost(Number(e.target.dataset.post)); }
  if ((e.ctrlKey || e.metaKey) && e.key === 's' && S.dr && can('edit')) { e.preventDefault(); save(); }
});
window.addEventListener('beforeunload', e => { if (S.dr && S.dr.dirty) { e.preventDefault(); e.returnValue = ''; } });
window.addEventListener('hashchange', route);
function route() {
  const acc = location.hash.startsWith('#cuentas'), inb = location.hash.startsWith('#inbox') && !!$('#ss-inbox') && typeof openInbox === 'function';
  const an = location.hash.startsWith('#analytics') && typeof openAnalytics === 'function';
  const rp = location.hash.startsWith('#informes') && typeof openReports === 'function';
  const sect = acc ? 'cuentas' : inb ? 'inbox' : an ? 'analytics' : rp ? 'informes' : 'planner';
  $('#ss-planner').hidden = sect !== 'planner'; $('#ss-accounts').hidden = sect !== 'cuentas'; if ($('#ss-inbox')) $('#ss-inbox').hidden = sect !== 'inbox';
  if ($('#ss-analytics')) $('#ss-analytics').hidden = sect !== 'analytics';
  if ($('#ss-reports')) $('#ss-reports').hidden = sect !== 'informes';
  document.body.classList.toggle('ss-in-inbox', inb);
  document.body.classList.toggle('ss-not-planner', sect !== 'planner');   // the "Content Planner" header only in the Planner
  $$('.ss-sect a').forEach(x => x.classList.toggle('on', x.dataset.sect === sect));
  if (sect !== 'planner' && S.dr) closeDrawer(true);
  if (sect !== 'inbox' && typeof inboxStop === 'function') inboxStop();
  if (acc) { loadAccounts(); return; }
  if (inb) { openInbox(); return; }
  if (an) { openAnalytics(); return; }
  if (rp) { openReports(); return; }
  const m =location.hash.match(/^#p\/(\d+)/);
  if (m && (!S.dr || S.dr.post.id !== Number(m[1]))) openPost(Number(m[1]));
  else if (location.hash === '#new' && !S.dr && can('edit')) newPost({});
}

// ── Arranque ─────────────────────────────────────────────
(async function init() {
  const v = store.get('view'); if (['month', 'week', 'day', 'list'].includes(v)) S.view = v;
  if (window.matchMedia('(max-width: 760px)').matches && !v) S.view = 'list';
  S.showBacklog = store.get('backlog') === '1';
  try { S.meta = await api('meta'); } catch (e) { $('#ss-view').innerHTML = `<div class="ss-empty"><div class="art"><i class="fa-solid fa-plug-circle-xmark"></i></div><h3>No se pudo abrir Social Studio</h3><p>${esc(e.message)}</p></div>`; return; }
  setupFilters();
  for (const k of ['group', 'network', 'owner', 'status']) $('#ss-f-' + k).addEventListener('change', e => { S.f[k] = e.target.value; syncFilterUi(); load(); });
  let qt = 0; $('#ss-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { S.f.q = e.target.value.trim(); syncFilterUi(); load(); }, 300); });
  await load();
  route();
})();

// ══ Stage 2 · Accounts and publishing to networks ═══════════════════
const ACC = { data: null, pick: null, tab: store.get('acctab') || 'meta', tt: {} };
const PROV_NAME = { meta: 'Meta', tiktok: 'TikTok', linkedin: 'LinkedIn', x: 'X', youtube: 'YouTube' };
const ST_ACC = { ok: ['Conectada', '#22c55e'], expired: ['Venció: renovar', '#f59e0b'], error: ['Con error', '#ef4444'], disconnected: ['Desconectada', '#71717a'], revoked: ['Revocada', '#ef4444'] };
const ST_TGT = { scheduled: ['Programada', '#14b8a6'], processing: ['La red la está procesando', '#3b82f6'], publishing: ['Publicando…', '#3b82f6'], published: ['Publicada', '#22c55e'], error: ['No se publicó', '#ef4444'] };
const isLocal = () => /^(localhost|127\.|\[::1\])/.test(location.hostname);
const copyBox = (label, val, id) => `<label class="ss-f full"><span>${label}</span><span class="ss-copy"><input class="ss-in" readonly value="${esc(val)}" id="${id}"><button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-act="acc-copy" data-for="${id}"><i class="fa-regular fa-copy"></i> Copiar</button></span></label>`;
const netOf = a => a.network || (a.kind === 'ig_business' ? 'instagram' : 'facebook');
const accPic = a => { const n = S.meta.networks[netOf(a)] || { icon: 'fa-share-nodes', color: '#94a3b8' };
  return a.picture ? `<span class="ss-acc-pw"><img class="ss-acc-pic" src="${esc(a.picture)}" alt="" loading="lazy" referrerpolicy="no-referrer"><i class="fa-brands ${n.icon} ss-acc-badge" style="--n:${n.color}"></i></span>` : `<span class="ss-acc-pic ph"><i class="fa-brands ${n.icon}" style="color:${n.color}"></i></span>`; };
const accName = a => a.username ? '@' + a.username : a.name;

async function loadAccounts() {
  const box = $('#ss-accounts');
  if (!ACC.data) box.innerHTML = '<div class="ss-skel" style="grid-template-columns:1fr"><span style="height:150px"></span><span style="height:220px"></span></div>';
  try { ACC.data = await api('accounts'); drawAccounts(); }
  catch (e) { box.innerHTML = `<div class="ss-empty"><div class="art"><i class="fa-solid fa-plug-circle-xmark"></i></div><h3>No se pudieron cargar las cuentas</h3><p>${esc(e.message)}</p></div>`; return; }
  const m = location.hash.match(/^#cuentas\/elegir\/(\d+)/);
  if (m) openPicker(Number(m[1]));
}
const CAP_ST = { true: ['fa-circle-check', '#22c55e', ''], setup: ['fa-gear', '#f59e0b', 'Falta configurar'], off: ['fa-circle-pause', '#71717a', 'Apagado'], no: ['fa-ban', '#ef4444', 'La red no lo permite'], approval: ['fa-hourglass-half', '#f59e0b', 'Espera aprobación'] };
// ── Central connection (hub_lib.php) ──
const centralNote = name => { const H = ACC.data.hub || {};
  return `<div class="ss-note"><i class="fa-solid fa-tower-broadcast"></i><span>La app de ${esc(name)} es la de la <b>conexión central</b>${H.name ? ' de ' + esc(H.name) : ''}: acá no hay que configurar nada. Cada cuenta se conecta con el permiso de quien la administra.</span></div>`; };
function hubCard(C) {
  const H = ACC.data.hub || { role: 'local' };
  if (H.role === 'client') {
    return `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-tower-broadcast"></i> Conexión central</h3><span class="ss-pill" style="--c:${H.ok ? '#22c55e' : '#ef4444'}">${H.ok ? 'Conectada' : 'Sin respuesta'}</span></div>
      <p class="ss-hint" style="margin:0">Este sitio usa las apps de las redes de <b>${esc(H.name || H.url)}</b> (${esc(H.url)}). No hace falta crear apps en Meta, TikTok, LinkedIn, X ni YouTube.</p>
      ${H.ok ? '' : `<div class="ss-note warn" style="margin-top:10px"><i class="fa-solid fa-triangle-exclamation"></i><span>${esc(H.error || 'La conexión central no respondió.')}</span></div>`}
      ${H.admin ? '<div class="ss-row-btns"><button type="button" class="ss-link" data-act="acc-hub-leave"><i class="fa-solid fa-link-slash"></i> Dejar de usar la conexión central</button></div>' : ''}</section>`;
  }
  if (H.role === 'hub') {
    const sites = H.sites || [];
    return `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-tower-broadcast"></i> Conexión central: este sitio</h3><span class="ss-pill" style="--c:#22c55e">Activa</span></div>
      <p class="ss-hint" style="margin:0 0 12px">Los sitios autorizados conectan sus cuentas con las apps de este sitio. Las claves de las apps nunca salen de acá: los sitios solo reciben el permiso de cada cuenta.</p>
      ${H.admin ? `${sites.length ? `<div class="ss-accs">${sites.map(x => `<article class="ss-acc${x.active ? '' : ' off'}"><span class="ss-acc-pic ph"><i class="fa-solid fa-building"></i></span><div class="x"><b>${esc(x.name)}</b><small>${esc(x.origin)}</small>
          <div class="meta"><span class="ss-pill" style="--c:${x.active ? '#22c55e' : '#71717a'}">${x.active ? 'Autorizado' : 'Pausado'}</span><span class="ss-tag">${x.routes} páginas con avisos</span>${x.last_seen_at ? `<span class="ss-hint" style="margin:0">último pedido ${esc(fmtWhen(x.last_seen_at))}</span>` : '<span class="ss-hint" style="margin:0">todavía no se conectó</span>'}</div></div>
          <div class="acts"><div class="row"><button type="button" class="ss-icon" data-act="acc-hub-site" data-id="${x.id}" data-op="${x.active ? 'off' : 'on'}" title="${x.active ? 'Pausar' : 'Reactivar'}" aria-label="${x.active ? 'Pausar' : 'Reactivar'}"><i class="fa-solid ${x.active ? 'fa-pause' : 'fa-play'}"></i></button>
          <button type="button" class="ss-icon" data-act="acc-hub-site" data-id="${x.id}" data-op="del" title="Quitar" aria-label="Quitar"><i class="fa-solid fa-trash"></i></button></div></div></article>`).join('')}</div>` : '<p class="ss-hint">Todavía no autorizaste ningún sitio.</p>'}
        <div class="ss-form" style="margin-top:12px"><label class="ss-f"><span>Nombre del sitio</span><input class="ss-in" id="hub-name" placeholder="Agencia Ejemplo"></label>
          <label class="ss-f"><span>Dirección<small>sin /toolz</small></span><input class="ss-in" id="hub-origin" placeholder="https://agencia.com"></label></div>
        <div class="ss-row-btns"><button type="button" class="ss-btn" data-act="acc-hub-add"><i class="fa-solid fa-plus"></i> Autorizar sitio</button><button type="button" class="ss-link" data-act="acc-hub-role" data-on="0"><i class="fa-solid fa-power-off"></i> Dejar de ser la conexión central</button></div>
        <div id="hub-code"></div>` : ''}</section>`;
  }
  if (!H.admin) return '';
  return `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-tower-broadcast"></i> Conexión central de Cronos</h3></div>
    <p class="ss-hint" style="margin:0 0 12px">Con la conexión central, este sitio usa las apps de las redes de Cronos y no hace falta crearlas. Pegá el código de conexión que te dieron.</p>
    <label class="ss-f full"><span>Código de conexión</span><textarea class="ss-in" id="hub-join-code" rows="2" autocomplete="off" spellcheck="false"></textarea></label>
    <div class="ss-row-btns"><button type="button" class="ss-btn" data-act="acc-hub-join"><i class="fa-solid fa-link"></i> Conectar a la central</button></div>
    <details class="ss-steps"><summary>Avanzado: que este sitio sea la conexión central</summary><p class="ss-hint">Solo para el sitio de Cronos que tiene las apps de todas las redes. Los demás sitios se conectan a él con un código.</p>
      <button type="button" class="ss-btn ss-btn-ghost" data-act="acc-hub-role" data-on="1"><i class="fa-solid fa-tower-broadcast"></i> Ser la conexión central</button></details></section>`;
}
function netBody(N, C) {
  if (N.central) return `${centralNote(N.name)}<ul class="ss-caps">${N.caps.map(([name, st, txt]) => { const x = CAP_ST[String(st)] || CAP_ST.off;
    return `<li><i class="fa-solid ${x[0]}" style="color:${x[1]}"></i><div><b>${esc(name)}</b>${x[2] ? ` <span class="ss-pill" style="--c:${x[1]}">${esc(x[2])}</span>` : ''}<small>${esc(txt)}</small></div></li>`; }).join('')}</ul>
    ${N.limits.length ? `<div class="ss-note warn"><i class="fa-solid fa-scale-balanced"></i><span><b>Reglas de ${esc(N.name)}</b><br>${N.limits.map(esc).join('<br>')}</span></div>` : ''}`;
  const caps = `<ul class="ss-caps">${N.caps.map(([name, st, txt]) => { const x = CAP_ST[String(st)] || CAP_ST.off;
    return `<li><i class="fa-solid ${x[0]}" style="color:${x[1]}"></i><div><b>${esc(name)}</b>${x[2] ? ` <span class="ss-pill" style="--c:${x[1]}">${esc(x[2])}</span>` : ''}<small>${esc(txt)}</small></div></li>`; }).join('')}</ul>`;
  const limits = N.limits.length ? `<div class="ss-note warn"><i class="fa-solid fa-scale-balanced"></i><span><b>Reglas de ${esc(N.name)}</b><br>${N.limits.map(esc).join('<br>')}</span></div>` : '';
  const extra = (N.fields || []).map(f => f.type === 'check'
    ? `<label class="ss-check full"><input type="checkbox" data-nx="${esc(f.id)}"${N.extra[f.id] ? ' checked' : ''}${N.can_edit ? '' : ' disabled'}> ${esc(f.label)}</label>`
    : `<label class="ss-f"><span>${esc(f.label)}</span><input class="ss-in" data-nx="${esc(f.id)}" value="${esc(N.extra[f.id] || '')}" placeholder="${esc(f.placeholder || '')}"${N.can_edit ? '' : ' disabled'}></label>`).join('');
  const form = N.can_edit ? `<div class="ss-form">
      <label class="ss-f"><span>ID de la app<small>Client ID${N.id === 'tiktok' ? ' (Client key)' : ''}</small></span><input class="ss-in" id="nc-id" value="${esc(N.client_id)}" autocomplete="off"></label>
      <label class="ss-f"><span>Secreto de la app<small>${N.secret_saved ? 'guardado y cifrado' : 'no se muestra nunca'}</small></span><input class="ss-in" id="nc-secret" type="password" autocomplete="off" placeholder="${N.secret_saved ? '•••••••• (vacío = no cambiarlo)' : 'Client secret'}"></label>
      <label class="ss-check full"><input type="checkbox" id="nc-pub"${N.publish ? ' checked' : ''}> Publicar en ${esc(N.name)} desde Social Studio</label>
      <label class="ss-check full"><input type="checkbox" id="nc-an"${N.analytics ? ' checked' : ''}> Métricas</label>
      <label class="ss-check full"><input type="checkbox" id="nc-inbox"${N.inbox ? ' checked' : ''}> Inbox (donde la red lo permite)</label>
      ${extra}
      ${copyBox('URI de redireccionamiento (se pega igual en la app de ' + esc(N.name) + ')', N.redirect_uri, 'nc-redir')}
    </div><div class="ss-row-btns"><button type="button" class="ss-btn" data-act="acc-net-save" data-p="${N.id}"><i class="fa-solid fa-check"></i> Guardar app</button><a class="ss-link" href="${esc(N.console)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir la consola de ${esc(N.name)}</a></div>`
    : `<p class="ss-hint">${N.ready ? 'La configuró un administrador del sitio.' : 'La tiene que cargar un administrador del sitio.'}</p>`;
  const steps = `<details class="ss-steps"><summary>Qué hay que hacer en ${esc(N.name)} (una sola vez)</summary><ol>${N.steps.map(x => `<li>${esc(x)}</li>`).join('')}</ol>
    <p class="ss-hint">Permisos que se piden: ${N.scopes.map(x => `<code title="${esc(x.label)}">${esc(x.id.replace('https://www.googleapis.com/auth/', ''))}</code>`).join(' ')}</p></details>`;
  return `<p class="ss-hint" style="margin:0 0 12px">API oficial de ${esc(N.name)} con la app de tu agencia. Nunca se pide la contraseña: cada cuenta se conecta con el permiso que da quien la administra.</p>
    ${!N.https && !isLocal() ? `<div class="ss-note warn"><i class="fa-solid fa-triangle-exclamation"></i><span>${esc(N.name)} exige https para conectar cuentas.</span></div>` : ''}
    ${caps}${limits}${form}${steps}`;
}
function drawAccounts() {
  const { meta: M, items, can: C } = ACC.data;
  const groups = S.meta.group === 'client' ? S.meta.clients : S.meta.brands;
  const gSel = a => `<select class="ss-in" data-acc-group="${a.id}" aria-label="${S.meta.group === 'client' ? 'Cliente' : 'Marca'}"${C.accounts ? '' : ' disabled'}><option value="">Sin ${groupWord()}</option>${groups.map(g => `<option value="${g.id}"${g.id === (S.meta.group === 'client' ? a.client_id : a.brand_id) ? ' selected' : ''}>${esc(g.name)}</option>`).join('')}</select>`;
  const form = M.can_edit ? `<div class="ss-form">
      <label class="ss-f"><span>App ID</span><input class="ss-in" id="mc-app" inputmode="numeric" value="${esc(M.app_id)}" placeholder="123456789012345"></label>
      <label class="ss-f"><span>App Secret<small>${M.secret_saved ? 'guardado y cifrado' : 'no se muestra nunca'}</small></span><input class="ss-in" id="mc-secret" type="password" autocomplete="off" placeholder="${M.secret_saved ? '•••••••• (vacío = no cambiarlo)' : '32 caracteres'}"></label>
      <label class="ss-f"><span>Versión de la API<small>avanzado</small></span><input class="ss-in" id="mc-ver" value="${esc(M.version)}" placeholder="v27.0"></label>
      <label class="ss-f"><span>ID de configuración<small>solo con Login for Business</small></span><input class="ss-in" id="mc-cfg" inputmode="numeric" value="${esc(M.config_id)}" placeholder="opcional"></label>
      <label class="ss-check full"><input type="checkbox" id="mc-pub"${M.publish ? ' checked' : ''}> Publicar en Facebook e Instagram desde Social Studio</label>
      <label class="ss-check full"><input type="checkbox" id="mc-wh"${M.webhooks ? ' checked' : ''}> Recibir avisos de Meta (webhooks), para el Inbox</label>
      <label class="ss-check full"><input type="checkbox" id="mc-inbox"${M.inbox ? ' checked' : ''}> Inbox: leer y responder mensajes directos y comentarios (pide los permisos de mensajería)</label>
      <label class="ss-check full"><input type="checkbox" id="mc-an"${M.analytics ? ' checked' : ''}> Métricas: leer las estadísticas de páginas y cuentas (pide los permisos de insights)</label>
      <label class="ss-check full"><input type="checkbox" id="mc-ha"${M.human_agent ? ' checked' : ''}> Meta aprobó la etiqueta "agente humano" (responder hasta 7 días después, en vez de 24 h)</label>
      ${copyBox('URI de redireccionamiento de OAuth válida (va en Facebook Login › Configuración)', M.redirect_uri, 'mc-redir')}
      ${copyBox('URL de devolución de llamada del webhook', M.webhook_uri, 'mc-wh-url')}
      ${copyBox('Token de verificación del webhook', M.verify || '(se genera al guardar)', 'mc-verify')}
    </div>
    <div class="ss-row-btns"><button type="button" class="ss-btn" data-act="acc-cfg-save"><i class="fa-solid fa-check"></i> Guardar app</button>${M.secret_saved ? '<button type="button" class="ss-link" data-act="acc-new-verify"><i class="fa-solid fa-rotate"></i> Nuevo token de verificación</button>' : ''}</div>`
    : `<p class="ss-hint">${M.ready ? 'La configuró un administrador del sitio.' : 'La tiene que cargar un administrador del sitio.'}</p>`;
  const steps = `<details class="ss-steps"><summary>Qué hay que hacer en Meta (una sola vez)</summary><ol>
      <li>En <b>developers.facebook.com</b>, crear una app de tipo <b>Business</b> con la cuenta comercial de la agencia.</li>
      <li>Agregar <b>Facebook Login for Business</b> (o Facebook Login) y pegar la <b>URI de redireccionamiento</b>. Con Login for Business, armar una configuración con los permisos de abajo y copiar su ID.</li>
      <li>Agregar <b>Webhooks</b>: objeto <i>Page</i>, la URL y el token de arriba, campo <i>feed</i>. Los campos de Instagram (comentarios, menciones) se eligen en el producto Instagram del panel de la app.</li>
      <li>Pedir en <b>App Review</b> el acceso avanzado de: ${M.scopes.map(x => `<code title="${esc(x.label)}">${esc(x.id)}</code>`).join(' ')}. Hasta que Meta lo apruebe, solo se conectan cuentas de personas con un rol en la app (administradores o testers).</li>
      <li>Completar la <b>verificación del negocio</b> y pasar la app a <b>Live</b>.</li></ol>
    ${M.later.length ? `<p class="ss-hint">Llegan con las próximas etapas (habrá que renovar las conexiones para aceptar los permisos nuevos):</p>
    <ul class="ss-later">${M.later.map(([n, sg, sc]) => `<li><b>${esc(n)}</b> · ${esc(sg)} · <small>${esc(sc)}</small></li>`).join('')}</ul>` : ''}</details>`;
  const metaBody = M.central ? centralNote('Meta') : `<p class="ss-hint" style="margin:0 0 12px">Social Studio usa la API oficial de Meta con la app de tu agencia. Nunca se pide la contraseña de Facebook ni de Instagram: cada cuenta se conecta con el permiso que da quien la administra.</p>
    ${!M.https && !isLocal() ? '<div class="ss-note warn"><i class="fa-solid fa-triangle-exclamation"></i><span>Meta exige https para conectar cuentas y recibir avisos.</span></div>' : ''}
    ${form}${steps}`;
  const nets = ACC.data.nets || [];
  const ready = { meta: M.ready }; nets.forEach(n => { ready[n.id] = n.ready; });
  const tabs = [{ id: 'meta', name: 'Meta', icon: 'fa-meta', color: '#0866ff', ready: M.ready }, ...nets];
  if (!tabs.some(t => t.id === ACC.tab)) ACC.tab = 'meta';
  const cur = tabs.find(t => t.id === ACC.tab);
  const appCard = `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-key"></i> Apps de las redes</h3></div>
    <div class="ss-net-tabs" role="tablist">${tabs.map(t => `<button type="button" role="tab" class="ss-net-tab${t.id === ACC.tab ? ' on' : ''}" data-act="acc-tab" data-tab="${t.id}" aria-selected="${t.id === ACC.tab}"><i class="fa-brands ${t.icon}" style="color:${t.color === '#a1a1aa' ? 'var(--g7-text)' : t.color}"></i> ${esc(t.name)}<span class="dot" style="--c:${t.ready ? '#22c55e' : '#f59e0b'}" title="${t.ready ? 'Configurada' : 'Falta configurar'}"></span></button>`).join('')}</div>
    <div class="ss-net-body"><div class="ss-card-h" style="margin:4px 0 10px"><h3 style="font-size:15px">${ACC.tab === 'meta' ? 'App de Meta' : 'App de ' + esc(cur.name)}</h3><span class="ss-pill" style="--c:${cur.ready ? '#22c55e' : '#f59e0b'}">${cur.ready ? 'Configurada' : 'Falta configurar'}</span></div>
    ${ACC.tab === 'meta' ? metaBody : netBody(cur, C)}</div></section>`;
  const card = a => {
    const s = ST_ACC[a.status] || [a.status, '#94a3b8'];
    const pn = PROV_NAME[a.provider] || a.provider;
    const tools = C.accounts ? `<div class="row">${a.connected ? `<button type="button" class="ss-icon" data-act="acc-check" data-id="${a.id}" title="Verificar con ${esc(pn)}" aria-label="Verificar con ${esc(pn)}"><i class="fa-solid fa-stethoscope"></i></button>` : ''}
        ${ready[a.provider] ? `<a class="ss-icon" href="oauth.php?start=1&amp;p=${esc(a.provider)}" title="Renovar la conexión" aria-label="Renovar la conexión"><i class="fa-solid fa-rotate"></i></a>` : ''}
        <button type="button" class="ss-icon" data-act="acc-log" data-id="${a.id}" title="Registro de llamadas" aria-label="Registro"><i class="fa-solid fa-list"></i></button>
        ${a.url ? `<a class="ss-icon" href="${esc(a.url)}" target="_blank" rel="noopener" title="Abrir en la red" aria-label="Abrir en la red"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}
        <button type="button" class="ss-icon" data-act="acc-off" data-id="${a.id}" data-c="${a.connected ? 1 : 0}" data-p="${esc(pn)}" title="${a.connected ? 'Desconectar' : 'Sacar de la lista'}" aria-label="${a.connected ? 'Desconectar' : 'Sacar de la lista'}"><i class="fa-solid ${a.connected ? 'fa-link-slash' : 'fa-trash'}"></i></button></div>` : '';
    const outPlan = PL.networks && !PL.networks.includes(netOf(a));
    return `<article class="ss-acc${a.connected && !outPlan ? '' : ' off'}">${accPic(a)}<div class="x"><b>${esc(accName(a))}</b><small>${esc(a.kind_name)}${a.username && a.name ? ' · ' + esc(a.name) : ''}${outPlan ? ' · <b>en pausa: tu plan no incluye esta red</b>' : ''}</small>
      <div class="meta"><span class="ss-pill" style="--c:${s[1]}">${esc(s[0])}</span>${a.subscribed ? '<span class="ss-tag" title="La red avisa las novedades"><i class="fa-solid fa-bell"></i> Avisos</span>' : ''}<span class="ss-tag" title="${esc(a.scopes.join(', '))}">${a.scopes.length} permisos</span>${a.last_check_at ? `<span class="ss-hint" style="margin:0">verificada ${esc(fmtWhen(a.last_check_at))}</span>` : ''}</div>
      ${a.status_msg ? `<p class="ss-hint">${esc(a.status_msg)}</p>` : ''}</div><div class="acts">${gSel(a)}${tools}</div></article>`;
  };
  const order = ['facebook', 'instagram', 'tiktok', 'linkedin', 'x', 'youtube'];
  const groupsByNet = order.map(n => [n, items.filter(a => netOf(a) === n)]).filter(([, l]) => l.length);
  const list = items.length ? groupsByNet.map(([n, l]) => `<h4 class="ss-acc-group">${netIcon(n)} ${esc(S.meta.networks[n] ? S.meta.networks[n].name : n)} <small>${l.length}</small></h4><div class="ss-accs">${l.map(card).join('')}</div>`).join('')
    : `<div class="ss-empty"><div class="art"><i class="fa-solid fa-plug"></i></div><h3>Todavía no hay cuentas conectadas</h3><p>${tabs.some(t => t.ready) ? 'Conectá las cuentas de las redes con los botones de arriba. Vas a elegir cuáles y de qué marca o cliente es cada una.' : 'Primero hay que cargar la app de alguna red (abajo). Mientras tanto, el Planner funciona igual.'}</p></div>`;
  const PL = S.meta.plan || {};
  const provOk = id => !PL.networks || (id === 'meta' ? PL.networks.includes('facebook') || PL.networks.includes('instagram') : PL.networks.includes(id));
  const full = PL.accounts != null && PL.used >= PL.accounts;
  const planLine = PL.limited ? `<div class="ss-note${full ? ' warn' : ''}"><i class="fa-solid fa-id-card"></i><span><b>Tu plan</b>: ${PL.accounts != null ? `${PL.used} de ${PL.accounts} cuentas conectadas` : 'cuentas sin tope'}${PL.networks ? ' · redes: ' + PL.networks.map(n => esc(S.meta.networks[n] ? S.meta.networks[n].name : n)).join(', ') : ''}${full ? '. Para conectar más, mejorá el plan desde Admin › Licencia.' : ''}</span></div>` : '';
  const connect = C.accounts ? tabs.filter(t => t.ready && provOk(t.id) && !full).map(t => `<a class="ss-btn${t.id === 'meta' ? '' : ' ss-btn-ghost'}" href="oauth.php?start=1&amp;p=${t.id}"><i class="fa-brands ${t.id === 'meta' ? 'fa-facebook' : t.icon}"></i> ${t.id === 'meta' ? 'Conectar con Meta' : 'Conectar ' + esc(t.name)}</a>`).join('') : '';
  $('#ss-accounts').innerHTML = `<section class="ss-card"><div class="ss-card-h"><h3><i class="fa-solid fa-plug"></i> Cuentas conectadas</h3><div class="ss-row-btns" style="margin:0">${connect}</div></div>${list}
    ${planLine}<p class="ss-hint"><i class="fa-solid fa-lock"></i> El permiso de cada cuenta se guarda cifrado en el servidor y nunca llega al navegador. Se renueva solo cuando la red lo permite.</p></section>${hubCard(C)}${appCard}`;
  $$('[data-acc-group]').forEach(el => el.addEventListener('change', async () => {
    const body = { id: Number(el.dataset.accGroup) }; body[S.meta.group === 'client' ? 'client_id' : 'brand_id'] = Number(el.value) || 0;
    try { ACC.data.items = (await api('account_update', body)).items; toast('Listo'); } catch (e) { drawAccounts(); }
  }));
}
async function openPicker(id) {
  openModal('<div class="ss-dg-b"><div class="ss-skel" style="grid-template-columns:1fr"><span style="height:200px"></span></div></div>');
  let d; try { d = await api('meta_pending', { id }); } catch (e) { closeModal(); history.replaceState(null, '', '#cuentas'); return; }
  const groups = S.meta.group === 'client' ? S.meta.clients : S.meta.brands;
  const sel = (c, i) => `<select class="ss-in" data-pick-g="${i}" aria-label="${groupWord()}"><option value="">Sin ${groupWord()}</option>${groups.map(g => `<option value="${g.id}"${c.existing && g.id === (S.meta.group === 'client' ? c.existing.client_id : c.existing.brand_id) ? ' selected' : ''}>${esc(g.name)}</option>`).join('')}</select>`;
  $('#ss-dialog').innerHTML = `<div class="ss-dg-h"><h2 id="ss-dialog-h">${d.provider === 'meta' ? '<i class="fa-brands fa-meta" style="color:#0866ff"></i>' : netIcon(d.cands[0] ? d.cands[0].network : d.provider)} Elegí las cuentas</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="ss-dg-b"><p class="ss-hint" style="margin:0 0 10px">${esc(d.provider_name)} dio permiso como <b>${esc(d.user)}</b>. Marcá qué se conecta y de qué ${groupWord()} es cada una. Lo que no marques no se guarda.</p>
      ${d.missing.length ? `<div class="ss-note warn"><i class="fa-solid fa-triangle-exclamation"></i><span>No se aceptaron en ${esc(d.provider_name)} estos permisos: ${d.missing.map(x => '<code>' + esc(x) + '</code>').join(' ')}. Se puede conectar igual, pero esas funciones no van a andar hasta renovar la conexión aceptándolos.</span></div>` : ''}
      ${d.plan && d.plan.accounts != null ? `<div class="ss-note"><i class="fa-solid fa-id-card"></i><span>Tu plan incluye ${d.plan.accounts} cuentas conectadas; hoy usás ${d.plan.used}. Renovar una que ya está conectada no suma.</span></div>` : ''}
      ${d.cands.length ? `<div class="ss-picks">${d.cands.map((c, i) => `<label class="ss-pick${c.plan_ok === false ? ' off' : ''}"><input type="checkbox" data-pick="${i}"${c.existing && c.plan_ok !== false ? ' checked' : ''}${c.plan_ok === false ? ' disabled' : ''}>${accPic(c)}<span class="x"><b>${esc(accName(c))}</b><small>${esc(c.kind_name)}${c.existing ? ' · ya conectada: se renueva' : ''}${c.can_post ? '' : ' · tu rol en la página no permite publicar'}${c.note ? ' · ' + esc(c.note) : ''}${c.plan_ok === false ? ' · tu plan no incluye esta red' : ''}</small></span>${sel(c, i)}</label>`).join('')}</div>`
        : `<div class="ss-empty sm"><p>${esc(d.provider_name)} no devolvió ninguna cuenta. Revisá que quien dio el permiso administre la cuenta o página y que la haya elegido en la pantalla de ${esc(d.provider_name)}.</p></div>`}</div>
    <div class="ss-dg-f"><span class="sp"></span><button type="button" class="ss-btn ss-btn-ghost" data-act="mclose">Cancelar</button>${d.cands.length ? '<button type="button" class="ss-btn" data-act="acc-pick-save"><i class="fa-solid fa-check"></i> Conectar las elegidas</button>' : ''}</div>`;
  ACC.pick = { id, cands: d.cands };
}
async function savePicker() {
  const picks = [];
  $$('[data-pick]').forEach(el => {
    if (!el.checked) return;
    const c = ACC.pick.cands[Number(el.dataset.pick)];
    picks.push({ kind: c.kind, ext_id: c.ext_id, [S.meta.group === 'client' ? 'client_id' : 'brand_id']: Number($(`[data-pick-g="${el.dataset.pick}"]`).value) || 0 });
  });
  if (!picks.length) { toast('Marcá al menos una cuenta.', true); return; }
  try {
    const d = await api('meta_save', { id: ACC.pick.id, picks });
    ACC.data.items = d.items; closeModal(); history.replaceState(null, '', '#cuentas'); drawAccounts();
    toast(d.saved > 1 ? `Se conectaron ${d.saved} cuentas` : 'Cuenta conectada');
  } catch (e) {}
}
async function accountLog(id) {
  openModal('<div class="ss-dg-b"><div class="ss-skel" style="grid-template-columns:1fr"><span style="height:160px"></span></div></div>', 'sm');
  try {
    const d = await api('account_log', { id });
    $('#ss-dialog').innerHTML = `<div class="ss-dg-h"><h2 id="ss-dialog-h">Registro de la cuenta</h2><button type="button" class="ss-icon" data-act="mclose" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
      <div class="ss-dg-b">${d.items.length ? `<div class="ss-hist"><ul>${d.items.map(x => `<li class="${x.ok ? '' : 'rule_skip'}"><i class="fa-solid ${x.ok ? 'fa-check' : 'fa-xmark'}"></i><div>${esc(x.action)} · ${esc(x.msg)}<small>${esc(fmtWhen(x.at))}${x.http ? ' · HTTP ' + x.http : ''}</small></div></li>`).join('')}</ul></div>` : '<p class="ss-hint">Sin llamadas registradas.</p>'}</div>`;
  } catch (e) { closeModal(); }
}

// ── Publish tab of the editor ──────────────────────────
function drPublish() {
  const P = S.dr.pub;
  if (!P) return '<div class="ss-skel" style="grid-template-columns:1fr"><span style="height:160px"></span></div>';
  const p = S.dr.post, kind = st(p.status_id).kind;
  const approved = ['approved', 'scheduled', 'published'].includes(kind);
  const busy = new Set(P.targets.filter(t => ['published', 'processing', 'publishing'].includes(t.status)).map(t => t.account_id));
  const free = P.accounts.filter(a => !busy.has(a.id));
  const when = p.date && p.time ? fmtDay(p.date) + ' a las ' + p.time : '';
  const nets = p.networks.length ? p.networks.map(n => S.meta.networks[n] ? S.meta.networks[n].name : n).join(' o ') : 'las redes de esta publicación';
  const tgt = t => { const s = ST_TGT[t.status] || [t.status, '#94a3b8'];
    return `<div class="ss-tk"><span class="k">${netIcon(t.network || (t.kind === 'ig_business' ? 'instagram' : 'facebook'))}</span><div class="x"><b>${esc(t.name)}</b><small>${t.status === 'scheduled' && t.run_at ? 'para el ' + esc(fmtWhen(t.run_at)) : t.published_at ? esc(fmtWhen(t.published_at)) : esc(t.kind_name)}${t.error ? ' · ' + esc(t.error) : ''}</small></div>
      <div class="row"><span class="ss-pill" style="--c:${s[1]}">${esc(s[0])}</span>${t.permalink ? `<a class="ss-icon" href="${esc(t.permalink)}" target="_blank" rel="noopener" aria-label="Ver publicada"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}${['scheduled', 'error'].includes(t.status) && can('schedule') ? `<button type="button" class="ss-icon" data-act="pub-cancel" data-id="${t.id}" aria-label="Cancelar envío"><i class="fa-solid fa-xmark"></i></button>` : ''}</div></div>`; };
  if (S.meta.plan && S.meta.plan.publish === false) return `<div class="ss-empty"><div class="art"><i class="fa-solid fa-lock"></i></div><h3>Publicar en las redes no está en tu plan</h3><p>Podés planificar, aprobar y llevar el calendario igual. Para publicar y programar desde Social Studio, mejorá el plan desde Admin › Licencia.</p></div>`;
  const notReady = [...new Set(free.map(a => a.provider).filter(pr => P.ready_by && P.ready_by[pr] === false))].map(pr => PROV_NAME[pr] || pr);
  return `${notReady.length ? `<div class="ss-note warn"><i class="fa-solid fa-triangle-exclamation"></i><span>Falta configurar la app de ${esc(notReady.join(' y '))} (Social Studio › Cuentas).</span></div>` : ''}
  ${isLocal() && free.some(a => a.provider === 'meta') ? '<div class="ss-note warn"><i class="fa-solid fa-flask"></i><span>Entorno local: Meta no puede descargar archivos de este equipo, así que publicar imágenes o videos en Facebook e Instagram solo funciona en el sitio publicado (https).</span></div>' : ''}
  ${P.targets.length ? `<div class="ss-sec"><h4><i class="fa-solid fa-paper-plane"></i> Envíos</h4><div class="ss-tks">${P.targets.map(tgt).join('')}</div></div>` : ''}
  <div class="ss-sec"><h4><i class="fa-solid fa-share-nodes"></i> Publicar en</h4>
    ${approved ? '' : `<div class="ss-note"><i class="fa-solid fa-circle-info"></i><span>Primero tiene que estar <b>aprobada</b>. Ahora está en "${esc(st(p.status_id).name)}".</span></div>`}
    ${free.length ? `<div class="ss-picks">${free.map(a => { const rd = !P.ready_by || P.ready_by[a.provider] !== false;
      return `<label class="ss-pick${rd ? '' : ' off'}"><input type="checkbox" data-pub="${a.id}"${rd ? ' checked' : ''}${approved && rd ? '' : ' disabled'}>${accPic(a)}<span class="x"><b>${esc(accName(a))}</b><small>${esc(a.kind_name)}${rd ? '' : ' · falta configurar la app de ' + esc(PROV_NAME[a.provider] || a.provider)}</small></span></label>`; }).join('')}</div>`
      : `<div class="ss-empty sm"><p>${P.accounts.length ? 'Ya se mandó a todas las cuentas que corresponden.' : `No hay cuentas conectadas de ${esc(nets)}${groupOf(p) ? ' para ' + esc(groupOf(p).name) : ''}. Se conectan en Social Studio › Cuentas.`}</p></div>`}
    ${approved ? pubOpts(free) : ''}
    ${free.length && approved ? `<div class="ss-row-btns">${can('publish') ? '<button type="button" class="ss-btn" data-act="pub-now"><i class="fa-solid fa-paper-plane"></i> Publicar ahora</button>' : ''}
      ${can('schedule') ? `<button type="button" class="ss-btn ss-btn-ghost" data-act="pub-schedule"${when ? '' : ' disabled'}><i class="fa-regular fa-clock"></i> ${when ? 'Programar para el ' + esc(when) : 'Programar (falta fecha y hora)'}</button>` : ''}
      ${!can('publish') && !can('schedule') ? '<p class="ss-hint">Publicar o programar pide su permiso (lo da un admin).</p>' : ''}</div>` : ''}
    <p class="ss-hint" style="margin-top:12px">Sale el copy con los hashtags y los archivos de la pestaña Archivos, según el formato y lo que acepta cada red: Instagram (imagen, carrusel, reel, historia), Facebook (texto, imagen, carrusel, video), TikTok (video, o fotos con dominio verificado), LinkedIn (texto, una imagen o un video), X (texto de 280, hasta 4 imágenes o un video), YouTube (video, con el título de la publicación). Lo programado lo publica el servidor a la hora indicada.</p></div>`;
}
/** Options that TikTok and YouTube ask to choose by hand (TikTok: no default privacy, music notice, commercial content). */
function pubOpts(free) {
  const P = S.dr.pub, o = S.dr.pubOpts || (S.dr.pubOpts = { tiktok: {}, youtube: {} });
  const rd = pr => !P.ready_by || P.ready_by[pr] !== false;
  const tt = free.filter(a => a.provider === 'tiktok' && rd('tiktok')), yt = free.filter(a => a.provider === 'youtube' && rd('youtube'));
  let out = '';
  if (tt.length) {
    const cr = tt.map(a => ACC.tt[a.id]).filter(Boolean);
    const allowed = cr.length ? Object.keys(P.tt_privacy).filter(k => cr.every(c => c.privacy.includes(k))) : Object.keys(P.tt_privacy);
    const noComments = cr.some(c => c.comment_disabled);
    out += `<div class="ss-pubopt"><h5><i class="fa-brands fa-tiktok"></i> TikTok ${cr.length ? '· publica como <b>' + esc(cr.map(c => c.nickname).join(', ')) + '</b>' : ''}</h5>
      ${cr.length && allowed.length === 1 && allowed[0] === 'SELF_ONLY' ? '<div class="ss-note warn"><i class="fa-solid fa-lock"></i><span>Mientras TikTok no audite la app, solo se puede publicar en privado ("Solo yo").</span></div>' : ''}
      <label class="ss-f"><span>¿Quién la puede ver?<small>TikTok pide elegirlo cada vez</small></span><select class="ss-in" data-po="tiktok.privacy"><option value="">Elegí una opción…</option>${allowed.map(k => `<option value="${k}"${o.tiktok.privacy === k ? ' selected' : ''}>${esc(P.tt_privacy[k])}</option>`).join('')}</select></label>
      <label class="ss-check"><input type="checkbox" data-po="tiktok.allow_comments"${o.tiktok.allow_comments && !noComments ? ' checked' : ''}${noComments ? ' disabled' : ''}> Permitir comentarios${noComments ? ' (la cuenta los tiene apagados en TikTok)' : ''}</label>
      <label class="ss-f"><span>Contenido comercial</span><select class="ss-in" data-po="tiktok.brand"><option value="none">No es contenido comercial</option><option value="own"${o.tiktok.brand === 'own' ? ' selected' : ''}>Promociona mi propia marca</option><option value="branded"${o.tiktok.brand === 'branded' ? ' selected' : ''}>Contenido de marca (de un tercero, pago)</option></select></label>
      <label class="ss-check"><input type="checkbox" data-po="tiktok.music_ok"${o.tiktok.music_ok ? ' checked' : ''}> Acepto la <a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener">Confirmación de uso de música</a>${o.tiktok.brand === 'branded' ? ' y la <a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener">Política de contenido de marca</a>' : ''} de TikTok</label>
      <p class="ss-hint">Cuando sale, TikTok puede tardar unos minutos en procesarla.</p></div>`;
  }
  if (yt.length) {
    out += `<div class="ss-pubopt"><h5><i class="fa-brands fa-youtube" style="color:#ff0033"></i> YouTube</h5>
      <label class="ss-f"><span>Privacidad del video</span><select class="ss-in" data-po="youtube.privacy"><option value="">Elegí una opción…</option>${Object.entries(P.yt_privacy).map(([k, v]) => `<option value="${k}"${o.youtube.privacy === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
      <div class="ss-f"><span>¿Es para chicos?<small>lo exige YouTube</small></span><div class="ss-radios"><label><input type="radio" name="yt-kids" data-po="youtube.kids" value="0"${o.youtube.kids === false ? ' checked' : ''}> No</label><label><input type="radio" name="yt-kids" data-po="youtube.kids" value="1"${o.youtube.kids === true ? ' checked' : ''}> Sí, es para chicos</label></div></div>
      <p class="ss-hint">El título del video es el título de la publicación; la descripción, el copy.</p></div>`;
  }
  return out;
}
function readPubOpts() {
  const o = S.dr.pubOpts || (S.dr.pubOpts = { tiktok: {}, youtube: {} });
  $$('[data-po]').forEach(el => {
    const [n, k] = el.dataset.po.split('.');
    if (el.type === 'radio') { if (el.checked) o[n][k] = el.value === '1'; }
    else o[n][k] = el.type === 'checkbox' ? el.checked : el.value;
  });
  return o;
}
document.addEventListener('change', e => { if (e.target.closest && e.target.closest('[data-po]') && S.dr) { readPubOpts(); if (e.target.dataset.po === 'tiktok.brand') $('#ss-dr-body').innerHTML = drPublish(); } });
async function loadPublish() {
  if (!S.dr || !S.dr.post.id) return;
  const pid = S.dr.post.id;
  try {
    const d = await api('publish_options', { id: pid });
    if (!S.dr || S.dr.post.id !== pid) return;
    S.dr.pub = d; S.dr.targets = d.targets; S.dr.pubLoaded = true;
    // TikTok: what the account allows today (nickname, privacy options) — TikTok asks to show it before publishing
    await Promise.all(d.accounts.filter(a => a.provider === 'tiktok' && !ACC.tt[a.id]).map(a => api('tt_creator', { id: a.id }, true).then(c => { ACC.tt[a.id] = c; }).catch(() => {})));
    if (S.dr.tab === 'publish') $('#ss-dr-body').innerHTML = drPublish();
  } catch (e) { if (S.dr) { S.dr.pubLoaded = true; $('#ss-dr-body').innerHTML = `<div class="ss-empty sm"><p>${esc(e.message)}</p></div>`; } }
}
async function doPublish(mode) {
  if (S.dr.dirty) { toast('Guardá los cambios antes de publicar.', true); return; }
  const ids = $$('[data-pub]:checked').map(x => Number(x.dataset.pub));
  if (!ids.length) { toast('Elegí al menos una cuenta.', true); return; }
  if (mode === 'now' && !confirm('¿Publicar ahora en ' + ids.length + (ids.length > 1 ? ' cuentas' : ' cuenta') + '? Sale a la red de verdad.')) return;
  const btns = $$('[data-act="pub-now"],[data-act="pub-schedule"]'); btns.forEach(b => { b.disabled = true; });
  try {
    const d = await api('publish', { id: S.dr.post.id, accounts: ids, mode, opts: readPubOpts() });
    S.dr = { ...S.dr, post: d.post, files: d.files, tickets: d.tickets, history: d.history, targets: d.targets, pub: { ...S.dr.pub, targets: d.targets }, dirty: false };
    drawDrawer(); load();
    const pub = d.targets.filter(t => t.status === 'published').length, err = d.targets.filter(t => t.status === 'error').length;
    toast(mode === 'schedule' ? 'Quedó programada' : err ? 'Alguna cuenta dio error: mirá el detalle' : pub ? 'Publicada' : 'La red la está procesando: se completa sola');
  } catch (e) { btns.forEach(b => { b.disabled = false; }); }
}

document.addEventListener('click', async e => {
  const a = e.target.closest('[data-act]');
  if (!a || !a.dataset.act.startsWith('acc-') && !a.dataset.act.startsWith('pub-')) return;
  const id = Number(a.dataset.id || 0);
  switch (a.dataset.act) {
    case 'acc-copy': { const i = $('#' + a.dataset.for); i.select(); try { await navigator.clipboard.writeText(i.value); } catch (err) { document.execCommand('copy'); } toast('Copiado'); break; }
    case 'acc-cfg-save': case 'acc-new-verify': {
      const cfg = { app_id: $('#mc-app').value.trim(), app_secret: $('#mc-secret').value.trim(), version: $('#mc-ver').value.trim(), config_id: $('#mc-cfg').value.trim(),
        publish: $('#mc-pub').checked, webhooks: $('#mc-wh').checked, inbox: $('#mc-inbox').checked, analytics: $('#mc-an').checked, human_agent: $('#mc-ha').checked, new_verify: a.dataset.act === 'acc-new-verify' };
      if (cfg.new_verify && !confirm('Se genera un token nuevo y hay que pegarlo otra vez en Webhooks de Meta. ¿Seguir?')) return;
      try { ACC.data.meta = (await api('meta_cfg_save', { cfg })).meta; drawAccounts(); toast('App de Meta guardada'); } catch (err) {}
      break;
    }
    case 'acc-check': try { ACC.data.items = (await api('account_check', { id })).items; drawAccounts(); toast('Verificada'); } catch (err) { loadAccounts(); } break;
    case 'acc-tab': ACC.tab = a.dataset.tab; store.set('acctab', ACC.tab); drawAccounts(); break;
    case 'acc-hub-join': try { await api('hub_join', { code: $('#hub-join-code').value.trim() }); toast('Conectado a la central'); loadAccounts(); } catch (err) {} break;
    case 'acc-hub-leave': if (!confirm('¿Dejar de usar la conexión central? Las cuentas conectadas por ella dejan de renovarse y hay que cargar las apps propias.')) return;
      try { await api('hub_leave'); toast('Listo'); loadAccounts(); } catch (err) {} break;
    case 'acc-hub-role': { const on = a.dataset.on === '1';
      if (!confirm(on ? '¿Que este sitio sea la conexión central? Los sitios que autorices van a usar sus apps de las redes.' : '¿Dejar de ser la conexión central? Los sitios autorizados dejan de poder conectar y renovar cuentas.')) return;
      try { ACC.data.hub = (await api('hub_role', { on })).hub; drawAccounts(); toast('Listo'); } catch (err) {} break; }
    case 'acc-hub-add': try {
        const d = await api('hub_site_add', { name: $('#hub-name').value, origin: $('#hub-origin').value }); ACC.data.hub = d.hub; drawAccounts();
        $('#hub-code').innerHTML = `<div class="ss-note warn" style="margin-top:12px"><i class="fa-solid fa-key"></i><span>Copiá este código y pegalo en ese sitio (Social Studio › Cuentas › Conexión central). <b>Se muestra una sola vez</b>: si lo perdés, quitá el sitio y autorizalo de nuevo.</span></div>${copyBox('Código de conexión', d.code, 'hub-new-code')}`;
      } catch (err) {} break;
    case 'acc-hub-site': { const op = a.dataset.op;
      if (op === 'del' && !confirm('¿Quitar este sitio? Deja de poder conectar y renovar cuentas por la central.')) return;
      try { ACC.data.hub = (await api('hub_site_set', op === 'del' ? { id, delete: 1 } : { id, active: op === 'on' ? 1 : 0 })).hub; drawAccounts(); toast('Listo'); } catch (err) {} break; }
    case 'acc-net-save': {
      const extra = {}; $$('[data-nx]').forEach(el => { extra[el.dataset.nx] = el.type === 'checkbox' ? el.checked : el.value.trim(); });
      const cfg = { client_id: $('#nc-id').value.trim(), client_secret: $('#nc-secret').value.trim(), publish: $('#nc-pub').checked, analytics: $('#nc-an').checked, inbox: $('#nc-inbox').checked, extra };
      try { const d = await api('net_cfg_save', { provider: a.dataset.p, cfg }); ACC.data.nets = ACC.data.nets.map(n => n.id === d.net.id ? d.net : n); drawAccounts(); toast('App de ' + d.net.name + ' guardada'); } catch (err) {}
      break;
    }
    case 'acc-off': {
      const conn = a.dataset.c === '1';
      if (!confirm(conn ? `¿Desconectar esta cuenta? Se borra el permiso guardado y se cancelan sus envíos programados. La cuenta en ${a.dataset.p || 'la red'} no cambia.` : '¿Sacarla de la lista?')) return;
      try { ACC.data.items = (await api('account_disconnect', { id, forget: !conn })).items; drawAccounts(); toast(conn ? 'Cuenta desconectada' : 'Listo'); } catch (err) {}
      break;
    }
    case 'acc-log': accountLog(id); break;
    case 'acc-pick-save': savePicker(); break;
    case 'pub-now': doPublish('now'); break;
    case 'pub-schedule': doPublish('schedule'); break;
    case 'pub-cancel':
      if (!confirm('¿Cancelar este envío?')) return;
      try { const d = await api('publish_cancel', { id: S.dr.post.id, target_id: id }); S.dr.targets = d.targets; S.dr.pub.targets = d.targets; drawDrawer(); toast('Cancelado'); } catch (err) {}
      break;
  }
});
