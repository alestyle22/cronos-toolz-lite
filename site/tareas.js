/* tareas.js — Tablero de tareas (sin Clientes, cada tarea es de una marca) (tareas.php). Habla con tareas_api.php (y tareas_files.php para adjuntos).
   Vistas: Lista (grupos por estado, marca, responsable o prioridad, tipo Monday), Mis tareas (lo mío por fecha),
   Kanban (arrastrar entre columnas) y Calendario (por fecha límite). Ficha: pasos, adjuntos, horas, repetición y plantillas.
   Los filtros (cliente, responsable, prioridad, etiqueta, texto) valen para las tres. */
'use strict';

const TK = {
  cfg: null, users: [], clients: [], me: 0, admin: false, prefs: null, today: '',
  tasks: [], arch: [], view: 'list', group: 'status', sel: new Set(),
  templates: [], repeats: {}, timer: null, nowOff: 0,
  f: { client: '', user: '', prio: '', label: '', q: '', arch: false },
  sort: { key: 'pos', dir: 1 }, closed: {},
  cal: { y: 0, m: 0, sel: '' },
  cur: null, draft: null, loading: false,
};
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DOW = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

async function api(action, data = {}) {
  const r = await fetch('/toolz/tareas_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, board: window.TK_BOARD || 0, ...data }) });
  let j;
  try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió algo inesperado. Prueba de nuevo.'); }
  if (!r.ok || j.error) throw new Error(j.error || 'No se pudo completar la acción');
  return j;
}
let toastT;
function toast(msg, err = false) {
  const t = $('tk-toast'); t.textContent = msg; t.classList.toggle('err', err); t.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), err ? 4200 : 2400);
}

// ── Datos auxiliares ─────────────────────────────────────────
const userOf = id => TK.users.find(u => u.id === id);
const clientOf = id => id ? TK.clients.find(c => c.id === id) : null;
const statusOf = id => TK.cfg.statuses.find(s => s.id === id) || { id, name: id, color: '#555' };
const prioOf = id => TK.cfg.priorities.find(p => p.id === id) || { id, name: id, color: '#555' };
const labelOf = id => TK.cfg.labels.find(l => l.id === id);
const isDone = st => !!statusOf(st).done;
const allTasks = () => TK.f.arch ? TK.arch : TK.tasks;
const findTask = id => TK.tasks.find(t => t.id === id) || TK.arch.find(t => t.id === id);
function upsert(t) {
  for (const k of ['tasks', 'arch']) TK[k] = TK[k].filter(x => x.id !== t.id);
  (t.archived ? TK.arch : TK.tasks).push(t);
}

function parseD(s) { const [y, m, d] = s.slice(0, 10).split('-').map(Number); return Date.UTC(y, m - 1, d); }
const diffDays = (a, b) => Math.round((parseD(b) - parseD(a)) / 864e5);
const isoD = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const fmtShort = s => {
  const [y, m, d] = String(s || '').slice(0, 10).split('-').map(Number);
  if (!y || !m || !d) return '';   // fechas vacías o "0000-00-00" de Promos
  return d + ' ' + MONTHS[m - 1].slice(0, 3) + (y !== +TK.today.slice(0, 4) ? ' ' + y : '');
};
const fmtLong = s => fmtShort(s) + (s.length > 10 ? ', ' + s.slice(11, 16) : '');
function ago(dt) {
  if (!dt) return '';
  const d = (Date.now() - (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T')))) / 1000;
  if (d < 60) return 'recién';
  if (d < 3600) return 'hace ' + Math.floor(d / 60) + ' min';
  if (d < 86400) return 'hace ' + Math.floor(d / 3600) + ' h';
  if (d < 86400 * 7) return 'hace ' + Math.floor(d / 86400) + ' d';
  return fmtShort(dt);
}
function av(u, size = 26) {
  if (!u) return '';
  const s = `width:${size}px;height:${size}px`;
  if (u.avatar) return `<img class="tk-av" style="${s}" src="${esc(u.avatar)}" alt="${esc(u.name)}" title="${esc(u.name)}">`;
  const c = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'][(u.name.charCodeAt(0) || 0) % 6];
  return `<span class="tk-av" style="${s};background:${c}" title="${esc(u.name)}">${esc((u.name[0] || '?').toUpperCase())}</span>`;
}
function avs(ids, max = 3) {
  const us = ids.map(userOf).filter(Boolean);
  return `<span class="tk-avs">${us.slice(0, max).map(u => av(u)).join('')}${us.length > max ? `<span class="more">+${us.length - max}</span>` : ''}</span>`;
}
function dueHtml(t) {
  if (!t.due_date) return '';
  const done = isDone(t.status), d = diffDays(TK.today, t.due_date);
  const cls = done ? 'done' : d < 0 ? 'late' : d <= 1 ? 'soon' : '';
  const txt = d === 0 ? 'Hoy' : d === 1 ? 'Mañana' : d === -1 ? 'Ayer' : fmtShort(t.due_date);
  return `<span class="tk-due ${cls}" title="${done ? 'Terminada' : d < 0 ? 'Vencida' : 'Fecha límite'}: ${fmtShort(t.due_date)}"><i class="fa-regular fa-calendar"></i>${txt}</span>`;
}
const lbls = t => t.labels.map(labelOf).filter(Boolean).map(l => `<span class="tk-lbl" style="--c:${l.color}">${esc(l.name)}</span>`).join('');
function thumb(img) {
  if (!img) return '';
  if (/^imgs\//.test(img)) return '/toolz/promos/thumb.php?w=120&src=' + encodeURIComponent(img);
  return /^(https?:)?\/\//.test(img) || img.startsWith('/') ? img : '/toolz/promos/' + img;
}
function fmtText(s) {
  let h = esc(s);
  h = h.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  h = h.replace(/(^|[^\w.])@([A-Za-z0-9_.\-]{2,40})/g, (m, p, u) =>
    TK.users.some(x => x.username.toLowerCase() === u.toLowerCase()) ? `${p}<span class="tk-mention">@${u}</span>` : m);
  return h;
}

// ── Estado guardado (vista y filtros) ────────────────────────
function loadState() {
  try { const s = JSON.parse(localStorage.getItem('tk_state') || '{}'); Object.assign(TK.f, s.f || {}); TK.view = s.view || TK.view; TK.sort = s.sort || TK.sort; TK.closed = s.closed || {}; TK.group = s.group || TK.group; } catch (e) {}
  TK.f.q = ''; TK.f.arch = false;
  const p = new URLSearchParams(location.search);
  if (p.get('v')) TK.view = p.get('v');
  if (p.has('c')) TK.f.client = p.get('c');
  if (!['list', 'mine', 'kanban', 'calendar', 'table', 'gantt'].includes(TK.view)) TK.view = 'list';
  if (!GROUPS[TK.group]) TK.group = 'status';
}
function saveState() {
  try { localStorage.setItem('tk_state', JSON.stringify({ f: { client: TK.f.client, user: TK.f.user, prio: TK.f.prio, label: TK.f.label }, view: TK.view, sort: TK.sort, closed: TK.closed, group: TK.group })); } catch (e) {}
  const p = new URLSearchParams();
  if (window.TK_BOARD) p.set('b', window.TK_BOARD);
  p.set('v', TK.view);
  if (TK.f.client) p.set('c', TK.f.client);
  if (TK.cur) p.set('t', TK.cur.id);
  history.replaceState(null, '', location.pathname + '?' + p);
}

// ── Filtros ──────────────────────────────────────────────────
function visible() {
  const q = norm(TK.f.q), c = TK.f.client, u = TK.view === 'mine' ? '' : TK.f.user;
  return allTasks().filter(t => {
    if (c === 'none' ? t.client_id : (c && t.client_id !== +c)) return false;
    if (u === 'me' ? !t.assignees.includes(TK.me) : u === 'none' ? t.assignees.length : (u && !t.assignees.includes(+u))) return false;
    if (TK.f.prio && t.priority !== TK.f.prio) return false;
    if (TK.f.label && !t.labels.includes(TK.f.label)) return false;
    if (q && !norm(t.title + ' ' + (t.description || '') + ' ' + (clientOf(t.client_id)?.name || '')).includes(q)) return false;
    return true;
  });
}
const filtersOn = () => !!(TK.f.client || TK.f.user || TK.f.prio || TK.f.label || TK.f.q);
function sorted(arr) {
  const k = TK.sort.key, d = TK.sort.dir;
  const pi = id => TK.cfg.priorities.findIndex(p => p.id === id);
  return [...arr].sort((a, b) => {
    let r = 0;
    if (k === 'due') r = (a.due_date || '9999') < (b.due_date || '9999') ? -1 : (a.due_date || '9999') > (b.due_date || '9999') ? 1 : 0;
    else if (k === 'prio') r = pi(b.priority) - pi(a.priority);
    else if (k === 'upd') r = a.updated_at < b.updated_at ? 1 : a.updated_at > b.updated_at ? -1 : 0;
    else if (k === 'title') r = a.title.localeCompare(b.title, 'es');
    else if (k === 'client') r = (clientOf(a.client_id)?.name || '~').localeCompare(clientOf(b.client_id)?.name || '~', 'es');
    else r = a.position - b.position;
    return r * d || a.id - b.id;
  });
}
function fillFilters() {
  const opt = (v, l, sel) => `<option value="${esc(v)}"${String(sel) === String(v) ? ' selected' : ''}>${esc(l)}</option>`;
  if (TK.f.client && TK.f.client !== 'none' && !clientOf(+TK.f.client)) TK.f.client = '';
  $('f-client').innerHTML = opt('', 'Todas las marcas', TK.f.client) + opt('none', 'Sin marca', TK.f.client)
    + TK.clients.map(c => opt(c.id, c.name + (c.status === 'terminado' ? ' (inactiva)' : ''), TK.f.client)).join('');
  $('f-user').innerHTML = opt('', 'Todos los responsables', TK.f.user) + opt('me', 'Mis tareas', TK.f.user) + opt('none', 'Sin asignar', TK.f.user)
    + TK.users.map(u => opt(u.id, u.name, TK.f.user)).join('');
  if (TK.f.prio && !TK.cfg.priorities.some(p => p.id === TK.f.prio)) TK.f.prio = '';
  $('f-prio').innerHTML = opt('', 'Todas las prioridades', TK.f.prio) + TK.cfg.priorities.map(p => opt(p.id, p.name, TK.f.prio)).join('');
  if (TK.f.label && !labelOf(TK.f.label)) TK.f.label = '';
  $('f-label').hidden = !TK.cfg.labels.length;
  $('f-label').innerHTML = opt('', 'Todas las etiquetas', TK.f.label) + TK.cfg.labels.map(l => opt(l.id, l.name, TK.f.label)).join('');
}
/** Valores por defecto de una tarea nueva según los filtros activos. */
function newDefaults(extra = {}) {
  return {
    client_id: TK.f.client && TK.f.client !== 'none' ? +TK.f.client : null,
    assignees: TK.f.user === 'me' ? [TK.me] : (+TK.f.user ? [+TK.f.user] : []),
    priority: TK.f.prio || TK.cfg.default_priority,
    labels: TK.f.label ? [TK.f.label] : [],
    ...extra,
  };
}

// ── Render general ───────────────────────────────────────────
function render() {
  document.querySelectorAll('#tk-views button').forEach(b => b.classList.toggle('on', b.dataset.view === TK.view));
  $('f-group-w').hidden = TK.view !== 'list'; $('f-user').hidden = TK.view === 'mine';
  const list = visible();
  $('tk-count').textContent = list.length + (list.length === 1 ? ' tarea' : ' tareas');
  const box = $('tk-view');
  if (!list.length && (filtersOn() || TK.f.arch) && TK.view !== 'calendar') {
    const cl = +TK.f.client ? clientOf(+TK.f.client) : null;
    box.innerHTML = `<div class="tk-empty"><i class="fa-regular fa-folder-open"></i>
      <b>${TK.f.arch ? 'No hay tareas archivadas' + (filtersOn() ? ' con estos filtros' : '') : cl && !TK.f.user && !TK.f.prio && !TK.f.label && !TK.f.q ? esc(cl.name) + ' no tiene tareas todavía' : 'Ninguna tarea coincide con los filtros'}</b>
      ${TK.f.arch ? '' : 'Prueba con otros filtros o crea una tarea nueva.'}
      <div style="display:flex;gap:8px;justify-content:center;margin-top:16px">
        ${filtersOn() ? '<button class="tk-btn" type="button" data-act="clear-filters"><i class="fa-solid fa-filter-circle-xmark"></i> Ver todas</button>' : ''}
        ${TK.f.arch ? '' : `<button class="tk-btn primary" type="button" data-act="new">${cl ? 'Nueva tarea de ' + esc(cl.name) : '<i class="fa-solid fa-plus"></i> Nueva tarea'}</button>`}
      </div></div>`;
    return;
  }
  // La selección solo guarda tareas que siguen a la vista
  const ids = new Set(list.map(t => t.id)); for (const id of TK.sel) if (!ids.has(id)) TK.sel.delete(id);
  if (TK.view === 'kanban') renderKanban(list);
  else if (TK.view === 'calendar') renderCalendar(list);
  else if (TK.view === 'mine') renderMine(list);
  else renderList(list);
  drawBulk();
}

// ── LISTA ────────────────────────────────────────────────────
const LIST_COLS = [['', '<input type="checkbox" data-selall aria-label="Seleccionar todas las del grupo">'], ['title', 'Tarea'], ['client', 'Marca'], ['', 'Responsables'], ['', 'Estado'], ['prio', 'Prioridad'], ['due', 'Fecha límite'], ['upd', 'Actualizada']];
const GROUPS = { status: 'Estado', client: 'Marca', user: 'Responsable', prio: 'Prioridad' };
const PALETTE = ['#a78bfa', '#60a5fa', '#4ade80', '#f59e0b', '#ec4899', '#22d3ee', '#fb7185', '#ff6600'];
/** Grupos de la Lista según "Agrupar por". key es lo que cambia al arrastrar una fila a ese grupo. */
function listGroups(tasks) {
  const g = TK.group;
  if (g === 'client') {
    const ids = new Set(tasks.map(t => t.client_id || 0));
    const out = TK.clients.filter(c => ids.has(c.id)).map((c, i) => ({ key: String(c.id), name: c.name, color: c.color || PALETTE[c.id % PALETTE.length], rows: tasks.filter(t => t.client_id === c.id) }));
    out.push({ key: '0', name: 'Sin marca', color: '#8b8b94', rows: tasks.filter(t => !t.client_id) });
    return out;
  }
  if (g === 'user') {
    const ids = new Set(tasks.flatMap(t => t.assignees));
    const out = TK.users.filter(u => ids.has(u.id)).map(u => ({ key: String(u.id), name: u.name, user: u, color: PALETTE[u.id % PALETTE.length], rows: tasks.filter(t => t.assignees.includes(u.id)) }));
    out.push({ key: '0', name: 'Sin asignar', color: '#8b8b94', rows: tasks.filter(t => !t.assignees.length) });
    return out;
  }
  if (g === 'prio') return TK.cfg.priorities.map(p => ({ key: p.id, name: p.name, color: p.color, rows: tasks.filter(t => t.priority === p.id) }));
  return TK.cfg.statuses.map(s => ({ key: s.id, name: s.name, color: s.color, rows: tasks.filter(t => t.status === s.id) }));
}
/** Qué cambia en una tarea al pasarla del grupo "from" al "to" (agrupando por algo que no es el estado). */
function groupPatch(t, from, to) {
  if (TK.group === 'client') return { client_id: +to || null };
  if (TK.group === 'prio') return { priority: to };
  // Responsable: sale de la persona del grupo de origen y entra la del destino
  const rest = t.assignees.filter(x => x !== +from);
  return { assignees: +to ? [...new Set([...rest, +to])] : rest };
}
/** Valores de una tarea nueva creada desde "Agregar tarea" de un grupo. */
function groupDefaults(key) {
  return TK.group === 'client' ? { client_id: +key || null } : TK.group === 'user' ? { assignees: +key ? [+key] : [] } : TK.group === 'prio' ? { priority: key } : { status: key };
}
function groupSection(gr, ck, opts = {}) {
  const head = LIST_COLS.map(([k, l]) => `<div${k ? ` class="sortable" data-sort="${k}"` : ''}>${l}${k && TK.sort.key === k ? ` <i class="fa-solid fa-arrow-${TK.sort.dir > 0 ? 'down' : 'up'}-short"></i>` : ''}</div>`).join('');
  const rows = sorted(gr.rows);
  return `<section class="tk-group${TK.closed[ck] ? ' closed' : ''}" style="--c:${gr.color}" data-group="${esc(gr.key)}" data-ck="${esc(ck)}" data-name="${esc(gr.name)}"${TK.group === 'status' && !opts.nodrop ? ` data-status="${esc(gr.key)}"` : ''}${opts.nodrop ? ' data-nodrop' : ''}>
      <div class="tk-ghead"><button class="tk-icon" type="button" data-act="toggle-group" aria-label="Mostrar u ocultar ${esc(gr.name)}"><i class="fa-solid fa-chevron-down"></i></button>
        ${gr.user ? av(gr.user, 24) : ''}<span class="n">${esc(gr.name)}</span><span class="c">${rows.length} ${rows.length === 1 ? 'tarea' : 'tareas'}</span>${opts.extra || ''}</div>
      <div class="tk-table">
        <div class="tk-row tk-th" style="--c:${gr.color}">${head}</div>
        ${rows.map(rowHtml).join('')}
        ${TK.f.arch || opts.noadd ? '' : `<div class="tk-row tk-addrow" style="--c:${gr.color}"><div style="opacity:.35"></div><div style="grid-column:2/-1"><button type="button" data-act="col-add" data-group="${esc(gr.key)}" aria-label="Agregar tarea en ${esc(gr.name)}"><i class="fa-solid fa-plus"></i> Agregar tarea</button></div></div>`}
      </div></section>`;
}
function renderList(tasks) {
  $('tk-view').innerHTML = listGroups(tasks).map(gr => {
    // Estados y prioridades son pocos y fijos: se muestran aunque estén vacíos (para poder soltar tareas ahí).
    // Clientes y personas, solo los que tienen tareas (más "Sin cliente" / "Sin asignar")
    const fixed = TK.group === 'status' || TK.group === 'prio';
    if (!gr.rows.length && (TK.f.arch || (!fixed && gr.key !== '0'))) return '';
    return groupSection(gr, TK.group === 'status' ? gr.key : TK.group + ':' + gr.key);
  }).join('') || '<div class="tk-empty"><i class="fa-regular fa-folder-open"></i><b>No hay tareas para mostrar</b></div>';
}
// ── MIS TAREAS: lo que me toca, por fecha ────────────────────
function renderMine(tasks) {
  const mine = tasks.filter(t => t.assignees.includes(TK.me) && (TK.f.arch || !isDone(t.status)));
  const d = t => t.due_date ? diffDays(TK.today, t.due_date) : null;
  const B = [
    { key: 'late', name: 'Vencidas', color: '#ef4444', rows: mine.filter(t => d(t) !== null && d(t) < 0) },
    { key: 'today', name: 'Hoy', color: '#f59e0b', rows: mine.filter(t => d(t) === 0) },
    { key: 'week', name: 'Próximos 7 días', color: '#3b82f6', rows: mine.filter(t => d(t) > 0 && d(t) <= 7) },
    { key: 'later', name: 'Más adelante', color: '#8b8b94', rows: mine.filter(t => d(t) > 7) },
    { key: 'nodate', name: 'Sin fecha', color: '#52525b', rows: mine.filter(t => d(t) === null) },
  ].filter(b => b.rows.length);
  const mins = mine.reduce((a, t) => a + (t.minutes || 0), 0);
  $('tk-view').innerHTML = B.length ? `<div class="tk-mine-sum"><b>${mine.length}</b> tarea${mine.length === 1 ? '' : 's'} abierta${mine.length === 1 ? '' : 's'} a tu nombre${B[0].key === 'late' ? ` · <span style="color:#f87171">${B[0].rows.length} vencida${B[0].rows.length === 1 ? '' : 's'}</span>` : ''}${mins ? ` · ${fmtMin(mins)} cargadas` : ''}</div>`
      + B.map(b => groupSection(b, 'mine:' + b.key, { nodrop: true, noadd: true })).join('')
    : `<div class="tk-empty"><i class="fa-regular fa-circle-check"></i><b>No tienes tareas abiertas</b>Cuando te asignen una, aparece acá ordenada por fecha límite.<div style="margin-top:16px"><button class="tk-btn primary" type="button" data-act="new"><i class="fa-solid fa-plus"></i> Nueva tarea</button></div></div>`;
}
function rowHtml(t) {
  const st = statusOf(t.status), pr = prioOf(t.priority), c = clientOf(t.client_id);
  return `<div class="tk-row tk-tr${t.archived ? ' arch' : ''}${TK.sel.has(t.id) ? ' sel' : ''}" style="--c:${st.color}" data-id="${t.id}">
    <div><input type="checkbox" data-sel${TK.sel.has(t.id) ? ' checked' : ''} aria-label="Seleccionar ${esc(t.title)}"></div>
    <div><div class="tk-title" data-act="open" role="button" tabindex="0"><span class="tk-grip" title="Arrastrar a otro grupo" aria-hidden="true"><i class="fa-solid fa-grip-vertical"></i></span><span class="t">${esc(t.title)}</span><span class="lbls">${lbls(t)}</span>
      <span class="ic">${metaIcons(t)}${t.n_comments ? `<span class="tk-meta" title="Comentarios"><i class="fa-regular fa-comment"></i>${t.n_comments}</span>` : ''}${t.n_contents ? `<span class="tk-meta" title="Contenidos de Promos"><i class="fa-solid fa-layer-group"></i>${t.n_contents}</span>` : ''}</span></div></div>
    <div><button type="button" class="tk-cell-btn${c ? '' : ' ph'}" data-act="pick-client" aria-label="Marca">${c ? `<span class="tk-client-chip"><i class="fa-solid fa-tag"></i>${esc(c.name)}</span>` : '<i class="fa-solid fa-plus"></i>'}</button></div>
    <div><button type="button" class="tk-cell-btn${t.assignees.length ? '' : ' ph'}" data-act="pick-users" aria-label="Responsables">${t.assignees.length ? avs(t.assignees) : '<i class="fa-solid fa-user-plus"></i>'}</button></div>
    <div><button type="button" class="tk-cell-btn tk-cell-fill" style="--c:${st.color}" data-act="pick-status">${esc(st.name)}</button></div>
    <div><button type="button" class="tk-cell-btn tk-cell-fill" style="--c:${pr.color}" data-act="pick-prio">${esc(pr.name)}</button></div>
    <div><button type="button" class="tk-cell-btn${t.due_date ? '' : ' ph'}" data-act="pick-due" aria-label="Fecha límite">${t.due_date ? dueHtml(t) : '<i class="fa-regular fa-calendar-plus"></i>'}</button></div>
    <div><span class="tk-upd" title="${esc(t.updated_at)}">${ago(t.updated_at)}</span></div>
  </div>`;
}

// ── KANBAN ───────────────────────────────────────────────────
function renderKanban(tasks) {
  $('tk-view').innerHTML = `<div class="tk-board">${TK.cfg.statuses.map(s => {
    const cards = tasks.filter(t => t.status === s.id).sort((a, b) => a.position - b.position || a.id - b.id);
    return `<div class="tk-col" style="--c:${s.color}" data-status="${esc(s.id)}">
      <div class="tk-col-h"><span class="tk-pill dot" style="--c:${s.color};background:none;border:0;padding:0"></span><span class="n">${esc(s.name)}</span><span class="c">${cards.length}</span><span class="sp"></span>
        ${s.done && !TK.f.arch && cards.length ? `<button class="tk-icon" type="button" data-act="archive-done" title="Archivar todas las terminadas"><i class="fa-solid fa-box-archive"></i></button>` : ''}
        ${TK.f.arch ? '' : `<button class="tk-icon" type="button" data-act="col-add" title="Agregar tarea en ${esc(s.name)}"><i class="fa-solid fa-plus"></i></button>`}</div>
      <div class="tk-col-b" data-drop="${esc(s.id)}">${cards.map(cardHtml).join('')}</div>
      ${TK.f.arch ? '' : `<button class="tk-col-add" type="button" data-act="col-add"><i class="fa-solid fa-plus"></i> Agregar tarea</button>`}
    </div>`;
  }).join('')}</div>`;
}
function cardHtml(t) {
  const pr = prioOf(t.priority), c = clientOf(t.client_id);
  return `<div class="tk-card${t.archived ? ' arch' : ''}" data-id="${t.id}" data-act="open" tabindex="0">
    <span class="prio" style="--c:${pr.color}" title="Prioridad ${esc(pr.name)}"></span>
    ${t.labels.length ? `<div class="lbls">${lbls(t)}</div>` : ''}
    <div class="t">${t.repeat_rule ? '<i class="fa-solid fa-repeat tk-rep" title="Se repite"></i> ' : ''}${esc(t.title)}</div>
    ${c ? `<div class="row"><span class="tk-client-chip"><i class="fa-solid fa-tag"></i>${esc(c.name)}</span></div>` : ''}
    ${t.n_checks ? progHtml(t) : ''}
    <div class="row"><span class="tk-pill" style="--c:${pr.color};height:20px;font-size:10.5px;padding:0 7px">${esc(pr.name)}</span>${dueHtml(t)}
      ${t.n_comments ? `<span class="tk-meta"><i class="fa-regular fa-comment"></i>${t.n_comments}</span>` : ''}${t.n_contents ? `<span class="tk-meta"><i class="fa-solid fa-layer-group"></i>${t.n_contents}</span>` : ''}
      ${t.n_files ? `<span class="tk-meta" title="Adjuntos"><i class="fa-solid fa-paperclip"></i>${t.n_files}</span>` : ''}${t.minutes || TK.timer?.task_id === t.id ? `<span class="tk-meta${TK.timer?.task_id === t.id ? ' run' : ''}" title="Horas cargadas"><i class="fa-regular fa-clock"></i>${fmtMin(t.minutes || 0, true)}</span>` : ''}
      <span class="sp"></span>${avs(t.assignees, 3)}</div>
  </div>`;
}

// Arrastrar tarjetas entre columnas, filas de la lista entre grupos (y chips del calendario a otro día).
// Con eventos de puntero: con mouse arranca al mover; en el celular, manteniendo apretado medio segundo.
let DRAG = null, SUPPRESS_CLICK = false;
function dropIndex(body, y) {
  const cards = [...body.querySelectorAll(':scope > .tk-card:not(.dragging), :scope > .tk-tr:not(.dragging)')];
  return cards.find(c => { const r = c.getBoundingClientRect(); return y < r.top + r.height / 2; }) || null;
}
function setupDnD(box) {
  let pend = null;
  const target = (x, y) => {
    const t = document.elementFromPoint(x, y);
    if (!t) return {};
    // En la lista, el "cuerpo" es la tabla del grupo; si el grupo está cerrado, se suelta sobre su encabezado y va al final
    const g = t.closest('.tk-group:not([data-nodrop])');
    if (g) return { body: g.querySelector('.tk-table'), group: g, day: null };
    return { body: t.closest('.tk-col-b') || t.closest('.tk-col')?.querySelector('.tk-col-b'), day: t.closest('.tk-day') };
  };
  const cleanup = () => {
    DRAG.ghost.remove(); DRAG.el.classList.remove('dragging'); document.body.classList.remove('tk-dragging');
    box.querySelectorAll('.tk-drop-ph').forEach(x => x.remove()); box.querySelectorAll('.over').forEach(x => x.classList.remove('over'));
    DRAG = null;
  };
  const start = (x, y) => {
    const { el, id } = pend; pend = null;
    if (!el.isConnected) return;
    closePop();
    const r = el.getBoundingClientRect(), ghost = el.cloneNode(true);
    ghost.classList.add('tk-ghost'); ghost.style.width = r.width + 'px';
    document.body.appendChild(ghost);
    DRAG = { id, el, ghost, ox: x - r.left, oy: y - r.top, card: el.classList.contains('tk-card') || el.classList.contains('tk-tr'), row: el.classList.contains('tk-tr'), from: el.closest('.tk-group')?.dataset.group };
    el.classList.add('dragging'); document.body.classList.add('tk-dragging');
    if (navigator.vibrate) try { navigator.vibrate(15); } catch (e) {}
    move(x, y);
  };
  const move = (x, y) => {
    DRAG.ghost.style.transform = `translate(${x - DRAG.ox}px,${y - DRAG.oy}px) rotate(2deg)`;
    const { body, day, group } = target(x, y);
    const hot = group || body;
    box.querySelectorAll('.over').forEach(o => o !== hot && o !== day && o.classList.remove('over'));
    let ph = box.querySelector('.tk-drop-ph');
    if (DRAG.card && body && DRAG.row === !!group) {
      hot.classList.add('over');
      if (!ph) { ph = document.createElement('div'); ph.className = 'tk-drop-ph'; }
      if (group?.classList.contains('closed')) ph.remove();
      else {
        // En la lista, el lugar libre va antes de la fila "Agregar tarea"
        const before = dropIndex(body, y) || (group ? body.querySelector(':scope > .tk-addrow') : null);
        if (before) { if (ph.nextSibling !== before) body.insertBefore(ph, before); } else if (body.lastElementChild !== ph) body.appendChild(ph);
      }
    } else {
      ph?.remove();
      if (!DRAG.card && day) day.classList.add('over');
    }
    // Cerca de los bordes, el tablero y la página se desplazan solos
    const board = box.querySelector('.tk-board');
    if (board) { const br = board.getBoundingClientRect(); if (x < br.left + 40) board.scrollLeft -= 16; else if (x > br.right - 40) board.scrollLeft += 16; }
    if (y < 80) scrollBy(0, -14); else if (y > innerHeight - 50) scrollBy(0, 14);
  };
  const end = async (x, y) => {
    const id = DRAG.id, card = DRAG.card, from = DRAG.from, { body, day, group } = target(x, y);
    let order = null;
    if (card && body && DRAG.row === !!group) {
      order = [];
      [...body.children].forEach(ch => { if (ch.classList.contains('tk-drop-ph')) order.push(id); else if (ch.dataset.id && +ch.dataset.id !== id) order.push(+ch.dataset.id); });
      if (!order.includes(id)) order.push(id);
    }
    const st = group ? group.dataset.group : body?.dataset.drop;
    cleanup();
    SUPPRESS_CLICK = true; setTimeout(() => { SUPPRESS_CLICK = false; }, 80);
    const t = findTask(id); if (!t) return;
    if (order && group && TK.group !== 'status') {
      if (st !== from) { patch(id, groupPatch(t, from, st)); toast('Movida a ' + group.dataset.name); }
      return;
    }
    if (order) {
      const moved = t.status !== st;
      t.status = st; order.forEach((tid, i) => { const x2 = findTask(tid); if (x2) x2.position = i + 1; });
      render();
      try { const r = await api('move', { id, status: st, order }); TK.tasks = r.tasks; render(); if (moved) toast('Movida a ' + statusOf(st).name); }
      catch (err) { toast(err.message, true); reload(); }
    } else if (!card && day && t.due_date !== day.dataset.date) {
      patch(id, { due_date: day.dataset.date });
      toast('Nueva fecha: ' + fmtShort(day.dataset.date));
    }
  };
  box.addEventListener('pointerdown', e => {
    if (e.button !== 0 || TK.f.arch) return;
    // En la lista se arrastra desde la fila, salvo desde sus botones (cliente, estado, fecha…)
    if (e.target.closest('.tk-tr') && e.target.closest('button,input,a,select,.tk-cell-btn')) return;
    const el = e.target.closest('.tk-card,.tk-chip,.tk-tr'); if (!el || el.closest('[data-nodrop]')) return;
    pend = { el, id: +el.dataset.id, x: e.clientX, y: e.clientY, touch: e.pointerType !== 'mouse', t: 0 };
    if (pend.touch) { const p = pend; p.t = setTimeout(() => { if (pend === p) start(p.x, p.y); }, 450); }
  });
  window.addEventListener('pointermove', e => {
    if (DRAG) { e.preventDefault(); move(e.clientX, e.clientY); return; }
    if (!pend) return;
    const far = Math.hypot(e.clientX - pend.x, e.clientY - pend.y) > 6;
    if (pend.touch) { if (far) { clearTimeout(pend.t); pend = null; } return; }   // en el celular, mover sin esperar = desplazarse
    if (far) start(e.clientX, e.clientY);
  }, { passive: false });
  window.addEventListener('pointerup', e => { if (pend) clearTimeout(pend.t); pend = null; if (DRAG) end(e.clientX, e.clientY); });
  window.addEventListener('pointercancel', () => { if (pend) clearTimeout(pend.t); pend = null; if (DRAG) cleanup(); });
  box.addEventListener('touchmove', e => { if (DRAG) e.preventDefault(); }, { passive: false });
  box.addEventListener('contextmenu', e => { if (DRAG || pend?.touch) e.preventDefault(); });
  box.addEventListener('dragstart', e => e.preventDefault());   // sin el arrastre nativo de imágenes y textos
  box.addEventListener('click', e => { if (SUPPRESS_CLICK) { e.stopPropagation(); e.preventDefault(); SUPPRESS_CLICK = false; } }, true);
}

// ── CALENDARIO ───────────────────────────────────────────────
function renderCalendar(tasks) {
  const { y, m } = TK.cal;
  const first = new Date(y, m, 1), off = (first.getDay() + 6) % 7, dim = new Date(y, m + 1, 0).getDate();
  const cells = Math.ceil((off + dim) / 7) * 7;
  const byDay = {};
  tasks.forEach(t => { if (t.due_date) (byDay[t.due_date] = byDay[t.due_date] || []).push(t); });
  Object.values(byDay).forEach(a => a.sort((p, q) => isDone(p.status) - isDone(q.status) || TK.cfg.priorities.findIndex(x => x.id === q.priority) - TK.cfg.priorities.findIndex(x => x.id === p.priority)));
  let inMonth = 0, html = '';
  for (let i = 0; i < cells; i++) {
    const d = new Date(y, m, 1 - off + i), iso = isoD(d), list = byDay[iso] || [], out = d.getMonth() !== m;
    if (!out) inMonth += list.length;
    html += `<div class="tk-day${out ? ' out' : ''}${iso === TK.today ? ' today' : ''}${iso === TK.cal.sel ? ' sel' : ''}" data-date="${iso}" data-act="day">
      <div class="d"><span>${d.getDate()}</span></div>
      ${list.slice(0, 3).map(t => `<div class="tk-chip${isDone(t.status) ? ' done' : ''}" style="--c:${statusOf(t.status).color}" data-id="${t.id}" data-act="open" title="${esc(t.title)} · ${esc(statusOf(t.status).name)}">${esc(t.title)}</div>`).join('')}
      ${list.length > 3 ? `<div class="more">+${list.length - 3} más</div>` : ''}
      ${list.length ? `<div class="dots">${list.slice(0, 6).map(t => `<i style="--c:${statusOf(t.status).color}"></i>`).join('')}</div>` : ''}
    </div>`;
  }
  const sel = TK.cal.sel, selList = sel ? (byDay[sel] || []) : [];
  const noDate = tasks.filter(t => !t.due_date && !isDone(t.status));
  const mini = t => `<div class="tk-mini" data-id="${t.id}" data-act="open" style="--c:${statusOf(t.status).color}"><span class="bar"></span><span class="t">${esc(t.title)}</span>${t.assignees.length ? avs(t.assignees, 2) : ''}<span class="tk-pill" style="--c:${prioOf(t.priority).color};height:20px;font-size:10.5px;padding:0 7px">${esc(prioOf(t.priority).name)}</span></div>`;
  $('tk-view').innerHTML = `
    <div class="tk-cal-h">
      <h2>${MONTHS[m]} ${y}</h2>
      <button class="tk-icon" type="button" data-act="cal-prev" aria-label="Mes anterior"><i class="fa-solid fa-chevron-left"></i></button>
      <button class="tk-btn sm" type="button" data-act="cal-today">Hoy</button>
      <button class="tk-icon" type="button" data-act="cal-next" aria-label="Mes siguiente"><i class="fa-solid fa-chevron-right"></i></button>
      <span class="sp"></span><span class="tk-count">${inMonth} con fecha este mes · arrastra una tarea a otro día para cambiar su fecha</span>
    </div>
    <div class="tk-cal">${DOW.map(d => `<div class="dow">${d}</div>`).join('')}${html}</div>
    <div class="tk-cal-foot">
      <div class="tk-panel"><h3><i class="fa-regular fa-calendar-check"></i>${sel ? fmtShort(sel) + (sel === TK.today ? ' · hoy' : '') : 'Elige un día'}</h3>
        ${sel ? (selList.map(mini).join('') || '<div class="tk-note" style="padding:6px 2px 10px">Nada vence este día.</div>') : '<div class="tk-note">Toca un día del calendario para ver sus tareas.</div>'}
        ${sel && !TK.f.arch ? `<button class="tk-btn sm" type="button" data-act="cal-new" style="margin-top:8px"><i class="fa-solid fa-plus"></i> Tarea para este día</button>` : ''}</div>
      <div class="tk-panel"><h3><i class="fa-regular fa-calendar-xmark"></i>Sin fecha límite <span class="tk-count">${noDate.length}</span></h3>
        ${noDate.slice(0, 40).map(mini).join('') || '<div class="tk-note">Todas las tareas abiertas tienen fecha.</div>'}</div>
    </div>`;
}

// ── Menús emergentes ─────────────────────────────────────────
let POP = null;
function closePop() { if (POP) { POP.remove(); POP = null; } }
document.addEventListener('mousedown', e => { if (POP && !POP.contains(e.target)) closePop(); }, true);
// Al desplazarse la página o el panel, el menú acompaña a su botón (y se cierra si el botón sale de la vista)
function placePop() {
  if (!POP) return;
  const r = POP._anchor.getBoundingClientRect();
  if (!POP._anchor.isConnected || r.bottom < 0 || r.top > innerHeight) { closePop(); return; }
  const left = Math.max(8, Math.min(r.left, innerWidth - POP.offsetWidth - 8));
  let top = r.bottom + 6; if (top + POP.offsetHeight > innerHeight - 8) top = Math.max(8, r.top - POP.offsetHeight - 6);
  POP.style.left = left + 'px'; POP.style.top = top + 'px';
}
window.addEventListener('resize', placePop);
document.addEventListener('scroll', e => { if (POP && !POP.contains(e.target)) placePop(); }, true);
function openPop(anchor, html) {
  closePop();
  const p = document.createElement('div'); p.className = 'tk-pop'; p.innerHTML = html;
  document.body.appendChild(p);
  POP = p; p._anchor = anchor; placePop();
  return p;
}
/** Lista de opciones; multi = casillas que se guardan al tocar cada una. */
function choose(anchor, { items, selected = [], multi = false, search = false, empty = 'Sin opciones', footer = '' }, onChange) {
  let sel = [...selected], q = '';
  const p = openPop(anchor, `${search ? '<input class="f" placeholder="Buscar…" aria-label="Buscar">' : ''}<div class="ls"></div>${footer}`);
  const draw = () => {
    const list = items.filter(i => !q || norm(i.name).includes(norm(q)));
    p.querySelector('.ls').innerHTML = list.map(i => `<div class="tk-opt${i.danger ? ' danger' : ''}" data-v="${esc(i.id)}" role="option">${i.html || (i.color ? `<span class="sw" style="--c:${i.color}"></span>` : '') + `<span>${esc(i.name)}</span>`}${sel.includes(i.id) ? '<i class="fa-solid fa-check ck"></i>' : ''}</div>`).join('') || `<div class="hint">${empty}</div>`;
  };
  draw();
  p.addEventListener('click', e => {
    const o = e.target.closest('.tk-opt'); if (!o) return;
    const it = items.find(i => String(i.id) === o.dataset.v); if (!it) return;
    if (multi) { sel = sel.includes(it.id) ? sel.filter(x => x !== it.id) : [...sel, it.id]; draw(); onChange(sel); }
    else { closePop(); onChange(it.id); }
  });
  if (search) { const inp = p.querySelector('.f'); inp.focus(); inp.addEventListener('input', () => { q = inp.value; draw(); }); }
  return p;
}
const userItems = () => TK.users.map(u => ({ id: u.id, name: u.name + ' ' + u.username, html: av(u, 22) + `<span>${esc(u.name)}</span>` }));
const clientItems = () => [{ id: 0, name: 'Sin marca', html: '<i class="fa-solid fa-ban" style="width:12px;color:var(--g7-text-4)"></i><span>Sin marca</span>' }, ...TK.clients.map(c => ({ id: c.id, name: c.name, color: c.color }))];
function pickStatus(a, t, cb) { choose(a, { items: TK.cfg.statuses, selected: [t.status] }, cb); }
function pickPrio(a, t, cb) { choose(a, { items: TK.cfg.priorities, selected: [t.priority] }, cb); }
function pickUsers(a, t, cb) { choose(a, { items: userItems(), selected: t.assignees, multi: true, search: TK.users.length > 6 }, cb); }
function pickClient(a, t, cb) { choose(a, { items: clientItems(), selected: [t.client_id || 0], search: TK.clients.length > 6, empty: 'No hay marcas cargadas (Admin › Marcas)' }, v => cb(v || null)); }
function pickLabels(a, t, cb) { choose(a, { items: TK.cfg.labels, selected: t.labels, multi: true, empty: TK.admin ? 'Crea etiquetas en Configurar' : 'No hay etiquetas' }, cb); }
function pickDue(a, t, cb) {
  const add = n => { const d = new Date(parseD(TK.today)); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const dow = new Date(parseD(TK.today)).getUTCDay(), toMon = ((8 - dow) % 7) || 7, toFri = ((5 - dow + 7) % 7) || 7;
  const opts = [['Hoy', TK.today], ['Mañana', add(1)], ['Viernes', add(toFri)], ['Próximo lunes', add(toMon)], ['En una semana', add(7)]];
  const p = openPop(a, `<input class="f" type="date" value="${esc(t.due_date || '')}" style="color-scheme:dark" aria-label="Fecha límite">
    ${opts.map(([l, d]) => `<div class="tk-opt" data-d="${d}"><span>${l}</span><span class="ck" style="color:var(--g7-text-4)">${fmtShort(d)}</span></div>`).join('')}
    ${t.due_date ? '<div class="sep"></div><div class="tk-opt danger" data-d=""><i class="fa-solid fa-xmark"></i><span>Quitar fecha</span></div>' : ''}`);
  p.querySelector('input').addEventListener('change', e => { if (e.target.value) { closePop(); cb(e.target.value); } });
  p.addEventListener('click', e => { const o = e.target.closest('.tk-opt'); if (o) { closePop(); cb(o.dataset.d || null); } });
}

// ── Guardar cambios ──────────────────────────────────────────
async function patch(id, fields) {
  const t = findTask(id); if (!t) return;
  const before = JSON.parse(JSON.stringify(t));
  Object.assign(t, fields); render();
  if (TK.cur?.id === id) { Object.assign(TK.cur, fields); drawProps(); }
  try {
    const r = await api('save', { id, ...fields });
    upsert(r.task); render();
    if (TK.cur?.id === id) { Object.assign(TK.cur, r.task); drawProps(); savedMark(); }
  } catch (e) {
    Object.assign(t, before); render();
    if (TK.cur?.id === id) { Object.assign(TK.cur, before); drawProps(); }
    toast(e.message, true);
  }
}
async function reload() {
  try {
    const r = await api('list', { archived: TK.f.arch });
    if (TK.f.arch) TK.arch = r.tasks; else TK.tasks = r.tasks;
    render();
  } catch (e) { toast(e.message, true); }
}

// ── Clicks en las vistas ─────────────────────────────────────
function onViewClick(e) {
  const a = e.target.closest('[data-act]'); if (!a) return;
  const act = a.dataset.act, row = a.closest('[data-id]'), id = row ? +row.dataset.id : 0, t = id ? findTask(id) : null;
  switch (act) {
    case 'open': if (id) openTask(id); break;
    case 'toggle-group': { const g = a.closest('.tk-group'), k = g.dataset.ck; TK.closed[k] = !TK.closed[k]; g.classList.toggle('closed'); saveState(); break; }
    case 'pick-status': pickStatus(a, t, v => v !== t.status && patch(id, { status: v })); break;
    case 'pick-prio': pickPrio(a, t, v => v !== t.priority && patch(id, { priority: v })); break;
    case 'pick-users': pickUsers(a, t, v => patch(id, { assignees: v })); break;
    case 'pick-client': pickClient(a, t, v => v !== t.client_id && patch(id, { client_id: v })); break;
    case 'pick-due': pickDue(a, t, v => patch(id, { due_date: v })); break;
    case 'col-add': newTask(a.dataset.group !== undefined ? groupDefaults(a.dataset.group) : { status: a.closest('.tk-col').dataset.status }); break;
    case 'archive-done': archiveDone(); break;
    case 'cal-prev': case 'cal-next': { const d = new Date(TK.cal.y, TK.cal.m + (act === 'cal-next' ? 1 : -1), 1); TK.cal.y = d.getFullYear(); TK.cal.m = d.getMonth(); render(); break; }
    case 'cal-today': { const d = new Date(parseD(TK.today)); TK.cal.y = d.getUTCFullYear(); TK.cal.m = d.getUTCMonth(); TK.cal.sel = TK.today; render(); break; }
    case 'day': TK.cal.sel = a.dataset.date; render(); break;
    case 'cal-new': newTask({ due_date: TK.cal.sel }); break;
    case 'clear-filters': TK.f = { ...TK.f, client: '', user: '', prio: '', label: '', q: '' }; $('f-q').value = ''; fillFilters(); saveState(); render(); break;
    case 'new': newTask(); break;
    case 'sort': break;
  }
}
async function archiveDone() {
  if (!await ask('Archivar las terminadas', 'Las tareas de las columnas terminadas salen del tablero. Las vas a encontrar tildando "Archivadas".', 'Archivar')) return;
  try { const r = await api('archive_done'); toast(r.n + (r.n === 1 ? ' tarea archivada' : ' tareas archivadas')); TK.arch = []; reload(); }
  catch (e) { toast(e.message, true); }
}

// ── FICHA DE LA TAREA ────────────────────────────────────────
const D = () => $('tk-drawer');
function showDrawer() { D().classList.add('open'); D().setAttribute('aria-hidden', 'false'); $('tk-ov').classList.add('open'); document.body.style.overflow = 'hidden'; }
async function closeDrawer(force = false) {
  if (!force && TK.draft && TK.draft.title.trim() && !await ask('Descartar tarea', 'La tarea nueva todavía no se creó. ¿La descartas?', 'Descartar')) return;
  await flushText();
  D().classList.remove('open'); D().setAttribute('aria-hidden', 'true'); $('tk-ov').classList.remove('open'); document.body.style.overflow = '';
  TK.cur = null; TK.draft = null; closePop(); saveState();
}
async function openTask(id) {
  closePop(); await flushText();
  TK.draft = null;
  D().innerHTML = `<div class="tk-dh"><span class="sp"></span><button class="tk-icon" type="button" data-d="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div><div class="tk-db"><div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk" style="height:160px"></div></div></div>`;
  showDrawer();
  try { const r = await api('get', { id }); TK.cur = r.task; upsert(r.task); drawDrawer(); saveState(); }
  catch (e) { toast(e.message, true); closeDrawer(true); }
}
function newTask(extra = {}) {
  closePop(); TK.cur = null;
  TK.draft = { id: 0, title: '', description: '', status: TK.cfg.statuses[0].id, due_date: null, contents: [], contentObjs: [], checks: [], repeat_rule: '', ...newDefaults(extra) };
  drawDrawer(); showDrawer();
  setTimeout(() => D().querySelector('.tk-dtitle')?.focus(), 250);
}
const T = () => TK.cur || TK.draft;
function drawDrawer() {
  const t = T(), isNew = !TK.cur, canDel = TK.admin || (TK.cur && TK.cur.created_by === TK.me);
  D().innerHTML = `
    <div class="tk-dh">
      <button type="button" class="tk-pill dot" data-d="status" style="--c:${statusOf(t.status).color};cursor:pointer;height:30px;font-size:12.5px">${esc(statusOf(t.status).name)} <i class="fa-solid fa-chevron-down" style="font-size:9px"></i></button>
      ${t.archived ? '<span class="tk-pill" style="--c:#8b8b94">Archivada</span>' : ''}
      ${TK.cur?.ticket ? tkTicketChip(TK.cur.ticket) : ''}
      <span class="sp"></span><span class="saved" id="tk-saved"></span>
      ${isNew ? '' : `<button class="tk-icon" type="button" data-d="tpl-save" title="Guardar como plantilla"><i class="fa-regular fa-clone"></i></button>
      <button class="tk-icon" type="button" data-d="link" title="Copiar el link de la tarea"><i class="fa-solid fa-link"></i></button>
      <button class="tk-icon" type="button" data-d="archive" title="${t.archived ? 'Sacar del archivo' : 'Archivar'}"><i class="fa-solid fa-box-${t.archived ? 'open' : 'archive'}"></i></button>
      ${canDel ? '<button class="tk-icon" type="button" data-d="delete" title="Borrar la tarea"><i class="fa-regular fa-trash-can"></i></button>' : ''}`}
      <button class="tk-icon" type="button" data-d="close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="tk-db">
      <textarea class="tk-dtitle" rows="1" placeholder="Título de la tarea" aria-label="Título" maxlength="250">${esc(t.title)}</textarea>
      <div class="tk-props" id="tk-props"></div>
      <div class="tk-sec"><h4><i class="fa-solid fa-align-left"></i>Descripción</h4>
        <textarea class="tk-ta" id="tk-desc" placeholder="Qué hay que hacer, links, materiales… Usa @usuario para avisarle a alguien." aria-label="Descripción">${esc(t.description || '')}</textarea></div>
      <div class="tk-sec"><h4><i class="fa-regular fa-square-check"></i>Pasos <span class="n" id="tk-ckn"></span></h4><div id="tk-checks"></div></div>
      ${isNew ? '<div class="tk-sec"><h4><i class="fa-solid fa-paperclip"></i>Adjuntos y horas</h4><div class="tk-note">Crea la tarea para adjuntar archivos y cargar horas.</div></div>' : `
      <div class="tk-sec" id="tk-files-sec"><h4><i class="fa-solid fa-paperclip"></i>Adjuntos <span class="n" id="tk-fn"></span><span class="sp"></span><span id="tk-files-act"></span></h4><div id="tk-files"></div></div>
      <div class="tk-sec"><h4><i class="fa-regular fa-clock"></i>Horas <span class="n" id="tk-tmn"></span><span class="sp"></span><span id="tk-time-act"></span></h4><div id="tk-time"></div></div>`}
      <div class="tk-sec"><h4><i class="fa-solid fa-layer-group"></i>Contenidos de Promos <span class="n" id="tk-cn"></span><span class="sp"></span>
        <button class="tk-btn sm" type="button" data-d="content"><i class="fa-solid fa-link"></i> Vincular</button></h4><div class="tk-cont" id="tk-cont"></div></div>
      ${isNew ? '' : `<div class="tk-sec"><h4><i class="fa-regular fa-comments"></i>Comentarios <span class="n" id="tk-cmn"></span></h4><div id="tk-cms"></div>
        <div class="tk-cbox">${av(userOf(TK.me), 30)}<div class="w"><textarea class="tk-ta" id="tk-cm-new" placeholder="Escribe un comentario… (@ para mencionar, Ctrl+Enter para enviar)" aria-label="Comentario"></textarea>
        <button class="tk-btn primary sm" type="button" data-d="comment">Comentar</button></div></div>${tkTicketNote(TK.cur?.ticket)}</div>
      <div class="tk-sec"><details><summary><h4><i class="fa-solid fa-chevron-right" style="font-size:10px"></i>Historial</h4></summary><div class="tk-act" id="tk-act"></div></details></div>`}
    </div>
    ${isNew ? `<div class="tk-df"><button class="tk-btn" type="button" data-d="close">Cancelar</button><button class="tk-btn primary" type="button" data-d="create"><i class="fa-solid fa-check"></i> Crear tarea</button></div>` : ''}`;
  drawProps(); drawContent(); drawComments(); drawActivity(); drawChecks(); drawFiles(); drawTime();
  const ti = D().querySelector('.tk-dtitle'); autoGrow(ti);
  ti.addEventListener('input', () => { autoGrow(ti); textChanged('title', ti.value); });
  ti.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); isNew ? createDraft() : ti.blur(); } });
  ti.addEventListener('blur', () => flushText());
  const de = $('tk-desc');
  de.addEventListener('input', () => textChanged('description', de.value));
  de.addEventListener('blur', () => flushText());
  mentionAC(de);
  const cm = $('tk-cm-new');
  if (cm) { mentionAC(cm); cm.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); sendComment(); } }); }
}
function autoGrow(el) { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; }
function drawProps() {
  const box = $('tk-props'); if (!box) return;
  const t = T(), c = clientOf(t.client_id), pr = prioOf(t.priority), by = TK.cur ? userOf(TK.cur.created_by) : null;
  const sh = D().querySelector('[data-d="status"]');
  if (sh) { sh.style.setProperty('--c', statusOf(t.status).color); sh.innerHTML = `${esc(statusOf(t.status).name)} <i class="fa-solid fa-chevron-down" style="font-size:9px"></i>`; }
  box.innerHTML = `
    <div class="k"><i class="fa-solid fa-users"></i>Responsables</div>
    <div class="v"><button class="tk-pbtn" type="button" data-d="users">${t.assignees.length ? t.assignees.map(userOf).filter(Boolean).map(u => `<span style="display:inline-flex;align-items:center;gap:6px">${av(u, 22)}${esc(u.name)}</span>`).join('') : '<span class="ph">Asignar responsables</span>'}</button></div>
    <div class="k"><i class="fa-solid fa-flag"></i>Prioridad</div>
    <div class="v"><button class="tk-pbtn" type="button" data-d="prio"><span class="tk-pill dot" style="--c:${pr.color}">${esc(pr.name)}</span></button></div>
    <div class="k"><i class="fa-regular fa-calendar"></i>Fecha límite</div>
    <div class="v"><input class="tk-date" type="date" id="tk-due" value="${esc(t.due_date || '')}" aria-label="Fecha límite">${t.due_date ? dueHtml(t).replace(/<i[^>]*><\/i>/, '') : '<span class="tk-note">Opcional</span>'}</div>
    <div class="k"><i class="fa-solid fa-tag"></i>Marca</div>
    <div class="v"><button class="tk-pbtn" type="button" data-d="client">${c ? esc(c.name) : '<span class="ph">Sin marca</span>'}</button></div>
    ${TK.cfg.labels.length || t.labels.length ? `<div class="k"><i class="fa-solid fa-tag"></i>Etiquetas</div>
    <div class="v"><button class="tk-pbtn" type="button" data-d="labels">${t.labels.length ? lbls(t) : '<span class="ph">Agregar etiquetas</span>'}</button></div>` : ''}
    <div class="k"><i class="fa-solid fa-repeat"></i>Se repite</div>
    <div class="v"><button class="tk-pbtn" type="button" data-d="repeat">${t.repeat_rule ? esc(TK.repeats[t.repeat_rule] || t.repeat_rule) : '<span class="ph">No se repite</span>'}</button>${t.repeat_rule ? '<span class="tk-note">Al terminarla se crea la siguiente</span>' : ''}</div>
    ${by ? `<div class="k"><i class="fa-regular fa-clock"></i>Creada</div><div class="v tk-note">${esc(by.name)} · ${fmtLong(TK.cur.created_at)}${TK.cur.completed_at ? ' · terminada ' + fmtLong(TK.cur.completed_at) : ''}</div>` : ''}`;
  $('tk-due').addEventListener('change', e => dset('due_date', e.target.value || null));
}
function dset(field, value) {
  if (TK.draft) { TK.draft[field] = value; drawProps(); return; }
  if (TK.cur && JSON.stringify(TK.cur[field]) !== JSON.stringify(value)) patch(TK.cur.id, { [field]: value });
}
// Título y descripción se guardan solos, un momento después de dejar de escribir
let textT = null, textPending = {};
function textChanged(field, value) {
  if (TK.draft) { TK.draft[field] = value; return; }
  textPending[field] = value; $('tk-saved').textContent = 'Editando…';
  clearTimeout(textT); textT = setTimeout(flushText, 900);
}
async function flushText() {
  clearTimeout(textT);
  if (!TK.cur || !Object.keys(textPending).length) return;
  const f = textPending; textPending = {};
  if ('title' in f && !f.title.trim()) { toast('El título no puede quedar vacío', true); return; }
  const id = TK.cur.id;
  try {
    const r = await api('save', { id, ...f }); upsert(r.task); render();
    if (TK.cur?.id === id) { TK.cur.title = r.task.title; TK.cur.description = r.task.description; TK.cur.updated_at = r.task.updated_at; savedMark(); }
  } catch (e) { toast(e.message, true); }
}
function savedMark() { const s = $('tk-saved'); if (s) { s.textContent = 'Guardado'; setTimeout(() => { if (s.textContent === 'Guardado') s.textContent = ''; }, 1800); } }

function contentHtml(c, removable) {
  const img = thumb(c.img);
  const s = fmtShort(c.start_date), e = c.end_date !== c.start_date ? fmtShort(c.end_date) : '';
  const meta = [c.project, c.type || c.category, s + (s && e ? ' – ' + e : '')].filter(Boolean).map(esc).join(' · ');
  return `<div class="tk-ci" data-cid="${c.id}">${img ? `<img src="${esc(img)}" alt="" loading="lazy">` : '<span class="noimg"><i class="fa-regular fa-image"></i></span>'}
    <div class="b"><a href="/toolz/promos/?open=${c.id}" target="_blank" rel="noopener">${esc(c.name || 'Sin nombre')}</a><small>${meta}</small></div>
    ${removable ? `<button class="tk-icon" type="button" data-d="unlink" data-cid="${c.id}" title="Quitar el vínculo"><i class="fa-solid fa-xmark"></i></button>` : ''}</div>`;
}
// Miniatura que no carga (imagen borrada o no copiada): el ícono gris en lugar del ícono roto
document.addEventListener('error', e => {
  if (e.target.matches?.('.tk-ci img')) { const s = document.createElement('span'); s.className = 'noimg'; s.innerHTML = '<i class="fa-regular fa-image"></i>'; e.target.replaceWith(s); }
}, true);
function drawContent() {
  const box = $('tk-cont'); if (!box) return;
  const list = TK.cur ? TK.cur.content || [] : TK.draft.contentObjs;
  $('tk-cn').textContent = list.length || '';
  box.innerHTML = list.map(c => contentHtml(c, true)).join('') || '<div class="tk-note">Vincula las piezas de Promos relacionadas con esta tarea.</div>';
}
/** Ticket de la tarea (Trabajo › Tickets): código, estado y plazo. Ticket y tarea son el mismo elemento. */
function tkTicketChip(k) {
  const due = k.resolution_due && !k.resolved_at ? (window.G7Time && G7Time.parse(k.resolution_due) || new Date(k.resolution_due.replace(' ', 'T'))) : null, late = due && due < new Date();
  const tip = [k.from ? 'De ' + k.from : '', k.paused ? 'Plazo en pausa (esperando respuesta)' : due ? 'Plazo: ' + k.resolution_due.slice(0, 16) : ''].filter(Boolean).join(' · ');
  return `<a class="tk-tkchip${late && !k.paused ? ' late' : ''}" href="/toolz/trabajo/#tickets/${+k.id}" title="${esc(tip || 'Abrir el ticket')}" style="--c:${esc(k.status_color)}">
    <i class="fa-solid fa-${k.base ? 'satellite-dish' : 'ticket'}"></i>${esc(k.code)}<span>${esc(k.status_name)}</span>${k.paused ? '<i class="fa-solid fa-pause"></i>' : late ? '<i class="fa-solid fa-circle-exclamation"></i>' : ''}</a>`;
}
/** Quién ve los comentarios: son las respuestas públicas del ticket. */
function tkTicketNote(k) {
  if (!k) return '';
  const who = k.base ? (k.web ? 'Quedan guardados en el sitio base, pero el contacto de la web no los ve: escríbele por email.' : `<b>Los ve la agencia</b> (${esc(k.from)}) en su página Soporte, con tu nombre.`) : 'Los ve quien abrió el ticket.';
  return `<div class="tk-note tk-tknote"><i class="fa-solid fa-${k.base && !k.web ? 'eye' : 'circle-info'}"></i> Los comentarios son también las respuestas del ticket <a href="/toolz/trabajo/#tickets/${+k.id}">${esc(k.code)}</a>. ${who}</div>`;
}
function cmHtml(c, reply) {
  const ext = c.user_id === null;
  const u = ext ? { name: c.ext_name || 'Sitio base', username: '' } : userOf(c.user_id) || { name: 'Usuario', username: '' }, mine = !ext && (c.user_id === TK.me || TK.admin);
  const rx = (c.reactions || []).map(r => {
    const on = r.users.includes(TK.me), names = r.users.map(id => id === TK.me ? 'Tú' : (userOf(id)?.name || 'Alguien')).join(', ');
    return `<button class="tk-rx${on ? ' on' : ''}" type="button" data-d="react" data-e="${esc(r.e)}" title="${esc(names)}" aria-pressed="${on}" aria-label="${esc(r.e)} ${r.users.length}: ${esc(names)}">${esc(r.e)}<b>${r.users.length}</b></button>`;
  }).join('');
  return `<div class="tk-cm${reply ? ' reply' : ''}${c.internal ? ' internal' : ''}" data-cm="${c.id}">${ext ? `<span class="tk-av-ext" title="Llegó del sitio base"><i class="fa-solid fa-satellite-dish"></i></span>` : av(u, reply ? 24 : 30)}<div class="bd"><div class="hd"><b>${esc(u.name)}</b>${c.internal ? '<span class="tk-int" title="Nota interna del ticket: no sale de Trabajo"><i class="fa-solid fa-lock"></i> Nota interna</span>' : ''}<small title="${esc(c.created_at)}">${ago(c.created_at)}${c.edited_at ? ' · editado' : ''}</small><span class="sp"></span>
      ${mine ? `<button class="tk-icon" type="button" data-d="cm-edit" style="width:26px;height:26px" title="Editar"><i class="fa-solid fa-pen" style="font-size:11px"></i></button><button class="tk-icon" type="button" data-d="cm-del" style="width:26px;height:26px" title="Borrar"><i class="fa-regular fa-trash-can" style="font-size:11px"></i></button>` : ''}</div>
      <div class="tx">${fmtText(c.body)}</div>
      <div class="ft">${rx}<button class="tk-rx add" type="button" data-d="react-pick" title="Reaccionar" aria-label="Reaccionar"><i class="fa-regular fa-face-smile"></i><i class="fa-solid fa-plus"></i></button><button class="tk-cm-a" type="button" data-d="reply"><i class="fa-solid fa-reply"></i> Responder</button></div></div></div>`;
}
function drawComments() {
  const box = $('tk-cms'); if (!box || !TK.cur) return;
  // Si había una respuesta a medio escribir, se vuelve a abrir con su texto
  const open = box.querySelector('.tk-rbox'), keep = open && { parent: +open.dataset.parent, text: open.querySelector('textarea').value };
  const cs = TK.cur.comments || [], ids = new Set(cs.map(c => c.id));
  $('tk-cmn').textContent = cs.length || '';
  const roots = cs.filter(c => !c.parent_id || !ids.has(c.parent_id));
  box.innerHTML = roots.map(r => {
    const reps = cs.filter(c => c.parent_id === r.id);
    return `<div class="tk-thread" data-root="${r.id}">${cmHtml(r, false)}${reps.length ? `<div class="tk-replies">${reps.map(c => cmHtml(c, true)).join('')}</div>` : ''}</div>`;
  }).join('') || '<div class="tk-note" style="margin-bottom:14px">Todavía no hay comentarios.</div>';
  if (keep) { const b = box.querySelector(`.tk-cm[data-cm="${keep.parent}"] [data-d="reply"]`); if (b) openReply(b, keep.text); }
}
// Responder: el cuadro aparece al final del hilo. Si se responde una respuesta, se menciona a su autor.
function openReply(btn, text = null) {
  const th = btn.closest('.tk-thread'), cm = btn.closest('.tk-cm'), target = +cm.dataset.cm, root = +th.dataset.root;
  const c = TK.cur.comments.find(x => x.id === target), u = c ? userOf(c.user_id) : null;
  D().querySelectorAll('.tk-rbox').forEach(x => x.remove());
  let reps = th.querySelector('.tk-replies');
  if (!reps) { reps = document.createElement('div'); reps.className = 'tk-replies'; th.appendChild(reps); }
  const box = document.createElement('div'); box.className = 'tk-rbox'; box.dataset.parent = target;
  box.innerHTML = `${av(userOf(TK.me), 24)}<div class="w"><textarea class="tk-ta" placeholder="Responder a ${esc(u?.name || 'este comentario')}… (Ctrl+Enter para enviar)" aria-label="Respuesta"></textarea>
    <div class="a"><button class="tk-btn sm" type="button" data-d="reply-cancel">Cancelar</button><button class="tk-btn primary sm" type="button" data-d="reply-send">Responder</button></div></div>`;
  reps.appendChild(box);
  const ta = box.querySelector('textarea');
  ta.value = text ?? (target !== root && u && u.id !== TK.me ? '@' + u.username + ' ' : '');
  mentionAC(ta);
  ta.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); sendReply(box); }
    else if (e.key === 'Escape' && !POP) { e.preventDefault(); e.stopPropagation(); drawComments(); }
  });
  ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length);
  box.scrollIntoView({ block: 'nearest' });
}
async function sendReply(box) {
  const ta = box.querySelector('textarea'), body = ta.value.trim(); if (!body) { ta.focus(); return; }
  const btn = box.querySelector('[data-d="reply-send"]'); btn.disabled = true;
  try { const r = await api('comment_add', { task_id: TK.cur.id, parent_id: +box.dataset.parent, body }); box.remove(); TK.cur = r.task; upsert(r.task); drawComments(); drawActivity(); render(); }
  catch (e) { toast(e.message, true); btn.disabled = false; }
}
// Reacciones: se ven al instante y después se confirma con el servidor
async function toggleReact(cid, e) {
  const c = TK.cur?.comments.find(x => x.id === cid); if (!c) return;
  const r = c.reactions.find(x => x.e === e);
  if (r && r.users.includes(TK.me)) { r.users = r.users.filter(u => u !== TK.me); if (!r.users.length) c.reactions = c.reactions.filter(x => x !== r); }
  else if (r) r.users.push(TK.me); else c.reactions.push({ e, users: [TK.me] });
  drawComments();
  const id = TK.cur.id;
  try { const res = await api('react', { id: cid, emoji: e }); if (TK.cur?.id === id) { TK.cur = res.task; drawComments(); } }
  catch (err) { toast(err.message, true); try { const res = await api('get', { id }); if (TK.cur?.id === id) { TK.cur = res.task; drawComments(); } } catch (e2) {} }
}
function pickReaction(btn) {
  const cid = +btn.closest('.tk-cm').dataset.cm;
  const p = openPop(btn, `<div class="tk-rxpick" role="group" aria-label="Reacciones">${(TK.reactions || []).map(e => `<button type="button" data-e="${esc(e)}" aria-label="Reaccionar con ${esc(e)}">${esc(e)}</button>`).join('')}</div>`);
  p.addEventListener('click', ev => { const b = ev.target.closest('[data-e]'); if (!b) return; closePop(); toggleReact(cid, b.dataset.e); });
  p.querySelector('button')?.focus();
}
const ACT = { repeat: 'cambió la repetición:', repeat_next: 'la terminó y se creó la próxima para el', check: 'tildó el paso', files: '', created: 'creó la tarea', title: 'cambió el título a', status: 'movió la tarea:', priority: 'cambió la prioridad:', due: 'cambió la fecha límite:', client: 'cambió el cliente:', description: 'editó la descripción', assignees: '', content: '', archived: 'archivó la tarea', unarchived: 'la sacó del archivo' };
function drawActivity() {
  const box = $('tk-act'); if (!box || !TK.cur) return;
  box.innerHTML = (TK.cur.activity || []).map(a => `<div><span><b>${esc(userOf(+a.user_id)?.name || 'Toolz')}</b> ${esc(ACT[a.action] ?? a.action)} ${esc(a.detail)}</span><small>${ago(a.created_at)}</small></div>`).join('') || '<div>Sin movimientos.</div>';
}
async function createDraft() {
  const d = TK.draft; if (!d) return;
  if (!d.title.trim()) { toast('Pon un título', true); D().querySelector('.tk-dtitle').focus(); return; }
  const btn = D().querySelector('[data-d="create"]'); if (btn) btn.disabled = true;
  try {
    const r = await api('save', { title: d.title, description: d.description, status: d.status, priority: d.priority, due_date: d.due_date, client_id: d.client_id, labels: d.labels, assignees: d.assignees, contents: d.contents, repeat_rule: d.repeat_rule || '', checks: (d.checks || []).map(c => c.body), start_date: d.start_date || null, estimate_min: d.estimate_min || null });
    upsert(r.task); render(); toast('Tarea creada');
    TK.draft = null; openTask(r.task.id);
  } catch (e) { toast(e.message, true); if (btn) btn.disabled = false; }
}
async function sendComment() {
  const ta = $('tk-cm-new'), body = ta.value.trim(); if (!body || !TK.cur) return;
  const btn = D().querySelector('[data-d="comment"]'); btn.disabled = true;
  try { const r = await api('comment_add', { task_id: TK.cur.id, body }); ta.value = ''; TK.cur = r.task; upsert(r.task); drawComments(); drawActivity(); render(); }
  catch (e) { toast(e.message, true); }
  btn.disabled = false;
}
async function onDrawerClick(e) {
  const a = e.target.closest('[data-d]'); if (!a) return;
  const t = T(), act = a.dataset.d;
  switch (act) {
    case 'close': closeDrawer(); break;
    case 'create': createDraft(); break;
    case 'status': pickStatus(a, t, v => dset('status', v)); break;
    case 'prio': pickPrio(a, t, v => dset('priority', v)); break;
    case 'users': pickUsers(a, t, v => dset('assignees', v)); break;
    case 'client': pickClient(a, t, v => dset('client_id', v)); break;
    case 'labels': pickLabels(a, t, v => dset('labels', v)); break;
    case 'repeat': choose(a, { items: [{ id: '', name: 'No se repite' }, ...Object.entries(TK.repeats).map(([id, name]) => ({ id, name }))], selected: [t.repeat_rule || ''] }, v => dset('repeat_rule', v)); break;
    case 'tpl-save': tplSaveDialog(); break;
    default: onDrawerPlus(a, act, e); break;
    case 'comment': sendComment(); break;
    case 'content': contentPicker(); break;
    case 'unlink': {
      const cid = +a.dataset.cid;
      if (TK.draft) { TK.draft.contents = TK.draft.contents.filter(x => x !== cid); TK.draft.contentObjs = TK.draft.contentObjs.filter(x => x.id !== cid); drawContent(); break; }
      try { const r = await api('content_unlink', { task_id: TK.cur.id, content_id: cid }); TK.cur = r.task; upsert(r.task); drawContent(); render(); } catch (err) { toast(err.message, true); }
      break;
    }
    case 'link': {
      const url = location.origin + '/toolz/tareas.php?t=' + TK.cur.id;
      try { await navigator.clipboard.writeText(url); toast('Link copiado'); } catch (err) { toast(url); }
      break;
    }
    case 'archive': {
      try { const r = await api('archive', { id: TK.cur.id, on: !TK.cur.archived }); upsert(r.task); toast(r.task.archived ? 'Tarea archivada' : 'Volvió al tablero'); closeDrawer(true); render(); } catch (err) { toast(err.message, true); }
      break;
    }
    case 'delete': {
      if (!await ask('Borrar tarea', `"${TK.cur.title}" se borra con sus comentarios. No se puede deshacer.`, 'Borrar', true)) return;
      try { const id = TK.cur.id; await api('delete', { id }); TK.tasks = TK.tasks.filter(x => x.id !== id); TK.arch = TK.arch.filter(x => x.id !== id); closeDrawer(true); render(); toast('Tarea borrada'); } catch (err) { toast(err.message, true); }
      break;
    }
    case 'cm-edit': {
      const box = a.closest('.tk-cm'), cid = +box.dataset.cm, c = TK.cur.comments.find(x => x.id === cid);
      const tx = box.querySelector('.tx');
      tx.outerHTML = `<div class="w" style="display:flex;flex-direction:column;gap:6px;align-items:flex-end"><textarea class="tk-ta" style="min-height:70px">${esc(c.body)}</textarea><div style="display:flex;gap:6px"><button class="tk-btn sm" type="button" data-d="cm-cancel">Cancelar</button><button class="tk-btn primary sm" type="button" data-d="cm-save">Guardar</button></div></div>`;
      const ta = box.querySelector('textarea'); mentionAC(ta); ta.focus();
      break;
    }
    case 'cm-cancel': drawComments(); break;
    case 'reply': openReply(a); break;
    case 'reply-cancel': drawComments(); break;
    case 'reply-send': sendReply(a.closest('.tk-rbox')); break;
    case 'react': toggleReact(+a.closest('.tk-cm').dataset.cm, a.dataset.e); break;
    case 'react-pick': pickReaction(a); break;
    case 'cm-save': {
      const box = a.closest('.tk-cm');
      try { const r = await api('comment_edit', { id: +box.dataset.cm, body: box.querySelector('textarea').value }); TK.cur = r.task; drawComments(); } catch (err) { toast(err.message, true); }
      break;
    }
    case 'cm-del': {
      if (!await ask('Borrar comentario', 'El comentario se borra para todos.', 'Borrar', true)) return;
      try { const r = await api('comment_del', { id: +a.closest('.tk-cm').dataset.cm }); TK.cur = r.task; upsert(r.task); drawComments(); render(); } catch (err) { toast(err.message, true); }
      break;
    }
  }
}

// @menciones: al escribir "@" aparece la lista del equipo
function mentionAC(ta) {
  let items = [], hl = 0;
  const match = () => { const m = ta.value.slice(0, ta.selectionStart).match(/(?:^|\s)@([\w.\-]*)$/); return m ? m[1] : null; };
  const pick = u => {
    const pos = ta.selectionStart, before = ta.value.slice(0, pos).replace(/@([\w.\-]*)$/, '@' + u.username + ' ');
    ta.value = before + ta.value.slice(pos); ta.selectionStart = ta.selectionEnd = before.length;
    closePop(); ta.focus(); ta.dispatchEvent(new Event('input'));
  };
  const show = q => {
    items = TK.users.filter(u => !q || norm(u.username).startsWith(norm(q)) || norm(u.name).includes(norm(q))).slice(0, 8);
    if (!items.length) { closePop(); return; }
    hl = Math.min(hl, items.length - 1);
    const p = openPop(ta, items.map((u, i) => `<div class="tk-opt${i === hl ? ' hl' : ''}" data-i="${i}">${av(u, 22)}<span>${esc(u.name)}</span><span class="ck" style="color:var(--g7-text-4)">@${esc(u.username)}</span></div>`).join(''));
    p.dataset.mention = '1';
    p.addEventListener('mousedown', e => { const o = e.target.closest('.tk-opt'); if (o) { e.preventDefault(); pick(items[+o.dataset.i]); } });
  };
  ta.addEventListener('input', () => { const q = match(); if (q === null) { if (POP?.dataset.mention) closePop(); } else { hl = 0; show(q); } });
  ta.addEventListener('keydown', e => {
    if (!POP?.dataset.mention || !items.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); hl = (hl + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length; show(match() || ''); }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); pick(items[hl]); }
    else if (e.key === 'Escape') { e.stopPropagation(); closePop(); }
  });
  ta.addEventListener('blur', () => setTimeout(() => { if (POP?.dataset.mention && document.activeElement !== ta) closePop(); }, 120));
}

// ── Ventanas ─────────────────────────────────────────────────
const DLG = () => $('tk-dlg');
function openDlg(html, wide = false) { const d = DLG(); d.style.width = wide ? 'min(820px,calc(100vw - 24px))' : ''; d.innerHTML = `<div class="tk-dlg-in">${html}</div>`; if (!d.open) d.showModal(); return d; }
function closeDlg() { if (DLG().open) DLG().close(); }
DLG().addEventListener('click', e => { if (e.target === DLG() || e.target.closest('[data-close]')) closeDlg(); });
function ask(title, text, ok = 'Aceptar', danger = false) {
  return new Promise(res => {
    const d = openDlg(`<div class="tk-dlg-h"><h3>${esc(title)}</h3></div><div class="tk-dlg-b" style="color:var(--g7-text-2);line-height:1.6">${esc(text)}</div>
      <div class="tk-dlg-f"><button class="tk-btn" type="button" data-a="no">Cancelar</button><button class="tk-btn ${danger ? 'danger' : 'primary'}" type="button" data-a="yes">${esc(ok)}</button></div>`);
    d.style.width = 'min(440px,calc(100vw - 24px))';
    const done = v => { d.removeEventListener('close', onClose); closeDlg(); res(v); };
    const onClose = () => res(false);
    d.addEventListener('close', onClose, { once: true });
    d.querySelector('[data-a="no"]').onclick = () => done(false);
    d.querySelector('[data-a="yes"]').onclick = () => done(true);
    d.querySelector('[data-a="yes"]').focus();
  });
}

// Vincular contenidos de Promos
function contentPicker() {
  const t = T(), cl = clientOf(t.client_id);
  let onlyClient = !!cl, timer;
  const linked = () => new Set((TK.cur ? (TK.cur.content || []).map(c => c.id) : TK.draft.contents));
  const d = openDlg(`<div class="tk-dlg-h"><h3>Vincular contenido de Promos</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div class="tk-search" style="max-width:none;flex:1"><i class="fa-solid fa-magnifying-glass"></i><input id="cp-q" type="search" placeholder="Buscar por nombre, proyecto o categoría…"></div>
        ${cl ? `<label class="tk-check"><input type="checkbox" id="cp-only" checked> Solo de ${esc(cl.name)}</label>` : ''}
      </div>
      <div class="tk-picker-list" id="cp-list"><div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk"></div></div></div>
    </div>
    <div class="tk-dlg-f"><span class="msg">Toca un contenido para vincularlo o desvincularlo.</span><button class="tk-btn primary" type="button" data-close>Listo</button></div>`, true);
  let items = [];
  const draw = () => {
    const on = linked();
    $('cp-list').innerHTML = items.map(c => contentHtml(c, false).replace('class="tk-ci"', `class="tk-ci${on.has(c.id) ? ' on' : ''}"`)).join('')
      || `<div class="tk-empty" style="padding:30px"><i class="fa-regular fa-folder-open"></i>${onlyClient ? 'Este cliente no tiene contenidos en Promos' : 'No se encontraron contenidos'}</div>`;
  };
  const load = async () => {
    try { const r = await api('content_search', { q: $('cp-q').value, client_id: t.client_id || 0, all: !onlyClient }); items = r.items; draw(); }
    catch (e) { toast(e.message, true); }
  };
  $('cp-q').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
  $('cp-only')?.addEventListener('change', e => { onlyClient = e.target.checked; load(); });
  $('cp-list').addEventListener('click', async e => {
    if (e.target.closest('a')) return;
    const el = e.target.closest('.tk-ci'); if (!el) return;
    const cid = +el.dataset.cid, c = items.find(x => x.id === cid), on = linked().has(cid);
    if (TK.draft) {
      if (on) { TK.draft.contents = TK.draft.contents.filter(x => x !== cid); TK.draft.contentObjs = TK.draft.contentObjs.filter(x => x.id !== cid); }
      else { TK.draft.contents.push(cid); TK.draft.contentObjs.push(c); }
      draw(); drawContent(); return;
    }
    try { const r = await api(on ? 'content_unlink' : 'content_link', { task_id: TK.cur.id, content_id: cid }); TK.cur = r.task; upsert(r.task); draw(); drawContent(); drawActivity(); render(); }
    catch (err) { toast(err.message, true); }
  });
  load(); setTimeout(() => $('cp-q').focus(), 50);
}

// Mis avisos (cada usuario)
function prefsDialog() {
  const ev = TK.cfg.notif.events, can = TK.cfg.notif.user_override, mail = TK.cfg.notif.email_enabled;
  const p = JSON.parse(JSON.stringify(TK.prefs));
  const perm = 'Notification' in window ? Notification.permission : 'unsupported';
  const permTxt = { granted: 'Permitidas en este navegador.', denied: 'Bloqueadas en este navegador: habilitalas desde el candado de la barra de direcciones.', default: 'Este navegador todavía no dio permiso.', unsupported: 'Este navegador no muestra notificaciones.' }[perm];
  const d = openDlg(`<div class="tk-dlg-h"><h3>Mis avisos de tareas</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b">
      ${can ? '' : '<p class="tk-note" style="margin:0 0 12px"><i class="fa-solid fa-lock"></i> Los avisos los define un administrador para todo el equipo.</p>'}
      <table class="tk-ntable"><thead><tr><th>Avisarme cuando…</th><th><i class="fa-regular fa-bell"></i> Campana</th><th><i class="fa-regular fa-envelope"></i> Email</th></tr></thead><tbody>
      ${Object.entries(ev).map(([k, e]) => `<tr><td>${esc(e.label)}${e.on ? '' : ' <span class="tk-note">(apagado por el admin)</span>'}</td>
        <td><input type="checkbox" data-k="${k}" data-c="app" ${p.events[k].app ? 'checked' : ''} ${!e.on || !can ? 'disabled' : ''} aria-label="Campana: ${esc(e.label)}"></td>
        <td><input type="checkbox" data-k="${k}" data-c="email" ${p.events[k].email ? 'checked' : ''} ${!e.on || !can || !mail ? 'disabled' : ''} aria-label="Email: ${esc(e.label)}"></td></tr>`).join('')}
      </tbody></table>
      ${mail ? '' : '<p class="tk-note">Los emails están apagados para todo el sistema.</p>'}
      <div class="tk-form"><div class="full" style="padding:14px;border-radius:12px;background:var(--g7-bg-3);border:1px solid var(--g7-border-2)">
        <label class="tk-sw"><input type="checkbox" id="pf-browser" ${p.browser ? 'checked' : ''}> Notificaciones del navegador (aparecen aunque estés en otra pestaña)</label>
        <div class="tk-note" style="margin-top:8px">${permTxt} ${perm === 'default' ? '<button class="tk-btn sm" type="button" id="pf-perm" style="margin-left:6px">Permitir</button>' : ''}</div>
        <div class="tk-note" style="margin-top:6px">El sonido de la campana es el del sistema (Configuración).</div>
      </div></div>
    </div>
    <div class="tk-dlg-f"><span class="msg" id="pf-msg"></span><button class="tk-btn" type="button" data-close>Cancelar</button><button class="tk-btn primary" type="button" id="pf-save">Guardar</button></div>`);
  $('pf-perm')?.addEventListener('click', async () => { const r = await Notification.requestPermission(); toast(r === 'granted' ? 'Notificaciones permitidas' : 'El navegador no dio permiso', r !== 'granted'); prefsDialog(); });
  $('pf-save').addEventListener('click', async () => {
    d.querySelectorAll('input[data-k]').forEach(i => { p.events[i.dataset.k][i.dataset.c] = i.checked; });
    p.browser = $('pf-browser').checked;
    try { const r = await api('prefs_save', p); TK.prefs = r.prefs; closeDlg(); toast('Avisos guardados'); }
    catch (e) { $('pf-msg').textContent = e.message; $('pf-msg').className = 'msg err'; }
  });
}

// Configuración (admins): estados, prioridades, etiquetas y avisos
function configDialog(tab = 'statuses') {
  const C = JSON.parse(JSON.stringify(TK.cfg));
  const gone = { statuses: {}, priorities: {}, labels: {} };   // id → a dónde pasan sus tareas
  const counts = (key, field) => { const n = {}; [...TK.tasks, ...TK.arch].forEach(t => { (field === 'labels' ? t.labels : [t[field]]).forEach(v => n[v] = (n[v] || 0) + 1); }); return n; };
  const NAMES = { statuses: ['Estados', 'estado', 'status'], priorities: ['Prioridades', 'prioridad', 'priority'], labels: ['Etiquetas', 'etiqueta', 'labels'] };
  const d = openDlg(`<div class="tk-dlg-h"><h3>Configurar tareas</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b" id="cf-b"></div>
    <div class="tk-dlg-f"><span class="msg" id="cf-msg"></span><button class="tk-btn" type="button" data-close>Cancelar</button><button class="tk-btn primary" type="button" id="cf-save">Guardar cambios</button></div>`, true);
  const sync = () => {   // lo escrito en los campos pasa a C antes de redibujar
    d.querySelectorAll('[data-row]').forEach(r => {
      const [k, i] = r.dataset.row.split(':'), x = C[k][+i]; if (!x) return;
      x.name = r.querySelector('input[type=text]').value; x.color = r.querySelector('input[type=color]').value;
      const dn = r.querySelector('input[data-done]'); if (dn) x.done = dn.checked;
    });
    d.querySelectorAll('[data-gone]').forEach(s => { gone[s.dataset.k][s.dataset.gone] = s.value; });
    if (tab === 'notif') {
      d.querySelectorAll('input[data-ev]').forEach(i => { C.notif.events[i.dataset.ev][i.dataset.c] = i.checked; });
      C.notif.email_enabled = $('cf-mail').checked; C.notif.user_override = $('cf-over').checked;
      C.notif.from_name = $('cf-from').value; C.notif.due_days = +$('cf-days').value || 0;
      C.notif.created_notify = [...d.querySelectorAll('input[data-cn]:checked')].map(i => +i.value);
    }
    const dp = $('cf-defp'); if (dp) C.default_priority = dp.value;
  };
  const listEd = k => {
    const [title, one, field] = NAMES[k], n = counts(k, field);
    const rows = C[k].map((x, i) => `<div class="tk-er" data-row="${k}:${i}">
      <input type="color" value="${esc(x.color)}" aria-label="Color">
      <input type="text" value="${esc(x.name)}" maxlength="40" placeholder="Nombre" aria-label="Nombre">
      ${k === 'statuses' ? `<label class="tk-check" title="Las tareas en este estado cuentan como terminadas: no vencen ni avisan"><input type="checkbox" data-done ${x.done ? 'checked' : ''}> Terminada</label>` : ''}
      <span class="cnt">${x.id && n[x.id] ? n[x.id] + (n[x.id] === 1 ? ' tarea' : ' tareas') : ''}</span>
      <button class="tk-icon" type="button" data-mv="${k}:${i}:-1" aria-label="Subir" ${i ? '' : 'disabled'}><i class="fa-solid fa-arrow-up"></i></button>
      <button class="tk-icon" type="button" data-mv="${k}:${i}:1" aria-label="Bajar" ${i < C[k].length - 1 ? '' : 'disabled'}><i class="fa-solid fa-arrow-down"></i></button>
      <button class="tk-icon" type="button" data-rm="${k}:${i}" aria-label="Quitar"><i class="fa-regular fa-trash-can"></i></button></div>`).join('');
    const targets = C[k].filter(x => x.id);
    const goneRows = k === 'labels' ? '' : Object.entries(gone[k]).map(([id, to]) => `<div class="tk-er gone"><span class="nm">${esc(TK.cfg[k].find(x => x.id === id)?.name || id)}</span>
      <span class="tk-note">sus ${n[id]} ${n[id] === 1 ? 'tarea pasa' : 'tareas pasan'} a</span>
      <select data-gone="${esc(id)}" data-k="${k}">${targets.map(x => `<option value="${esc(x.id)}" ${x.id === to ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>
      <button class="tk-btn sm" type="button" data-undo="${k}:${esc(id)}">Deshacer</button></div>`).join('');
    return `<div class="tk-ed">${rows}${goneRows}</div>
      <button class="tk-btn sm" type="button" data-addrow="${k}" style="margin-top:10px"><i class="fa-solid fa-plus"></i> Agregar ${one}</button>
      ${k === 'statuses' ? '<p class="tk-note" style="margin-top:14px">El orden es el de las columnas del Kanban y los grupos de la Lista. Las tareas nuevas entran en el primer estado.</p>' : ''}
      ${k === 'priorities' ? `<p class="tk-note" style="margin-top:14px">De menor a mayor urgencia. Prioridad de las tareas nuevas:
        <select class="tk-sel" id="cf-defp" style="height:30px;margin-left:6px">${C.priorities.filter(x => x.id).map(x => `<option value="${esc(x.id)}" ${x.id === C.default_priority ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></p>` : ''}
      ${k === 'labels' ? '<p class="tk-note" style="margin-top:14px">Etiquetas libres para clasificar tareas (ej.: Diseño, Video, Redes). Si borras una, se quita de las tareas que la tenían.</p>' : ''}`;
  };
  const notifEd = () => {
    const N = C.notif;
    return `<table class="tk-ntable"><thead><tr><th>Evento</th><th>Activo</th><th><i class="fa-regular fa-bell"></i> Campana</th><th><i class="fa-regular fa-envelope"></i> Email</th></tr></thead><tbody>
      ${Object.entries(N.events).map(([k, e]) => `<tr><td>${esc(e.label)}</td><td><input type="checkbox" data-ev="${k}" data-c="on" ${e.on ? 'checked' : ''}></td>
        <td><input type="checkbox" data-ev="${k}" data-c="app" ${e.app ? 'checked' : ''}></td><td><input type="checkbox" data-ev="${k}" data-c="email" ${e.email ? 'checked' : ''}></td></tr>`).join('')}
      </tbody></table>
      <p class="tk-note">"Campana" y "Email" son los valores de cada usuario hasta que los cambie en Mis avisos. Un evento apagado no le avisa a nadie.</p>
      <div class="tk-form">
        <label class="tk-sw full"><input type="checkbox" id="cf-mail" ${N.email_enabled ? 'checked' : ''}> Mandar emails (con el correo del hosting)</label>
        <label class="tk-sw full"><input type="checkbox" id="cf-over" ${N.user_override ? 'checked' : ''}> Cada usuario puede elegir sus avisos</label>
        <label>Nombre del remitente<input type="text" id="cf-from" value="${esc(N.from_name)}" maxlength="60"></label>
        <label>Avisar "por vencer" con (días de anticipación)<input type="number" id="cf-days" min="0" max="14" value="${N.due_days}"></label>
        <div class="full"><div class="tk-note" style="margin-bottom:8px">Avisar de cada tarea nueva a <span>(si no marcas a nadie: a los administradores)</span></div>
          <div class="tk-ulist">${TK.users.map(u => `<label>${av(u, 22)}<input type="checkbox" data-cn value="${u.id}" ${N.created_notify.includes(u.id) ? 'checked' : ''}>${esc(u.name)}</label>`).join('')}</div></div>
        <div class="full"><button class="tk-btn sm" type="button" id="cf-test"><i class="fa-regular fa-paper-plane"></i> Mandarme un email de prueba</button></div>
      </div>`;
  };
  const draw = () => {
    $('cf-b').innerHTML = `<div class="tk-tabs">${[['statuses', 'Estados'], ['priorities', 'Prioridades'], ['labels', 'Etiquetas'], ['notif', 'Avisos']].map(([k, l]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${l}</button>`).join('')}</div>
      ${tab === 'notif' ? notifEd() : listEd(tab)}`;
  };
  draw();
  $('cf-b').addEventListener('click', async e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.tab) { sync(); tab = b.dataset.tab; draw(); return; }
    if (b.id === 'cf-test') {
      sync(); b.disabled = true;
      try { const r = await api('test_email'); toast(r.ok ? 'Email enviado a ' + r.email + ': revisa también el spam' : 'El servidor no pudo mandar el email', !r.ok); } catch (err) { toast(err.message, true); }
      b.disabled = false; return;
    }
    sync();
    if (b.dataset.addrow) { const k = b.dataset.addrow; C[k].push({ id: '', name: '', color: ['#a78bfa', '#ec4899', '#14b8a6', '#f97316', '#84cc16'][C[k].length % 5], ...(k === 'statuses' ? { done: false } : {}) }); draw(); d.querySelector(`[data-row="${k}:${C[k].length - 1}"] input[type=text]`).focus(); }
    else if (b.dataset.mv) { const [k, i, dir] = b.dataset.mv.split(':'), a = +i, z = a + +dir; [C[k][a], C[k][z]] = [C[k][z], C[k][a]]; draw(); }
    else if (b.dataset.rm) {
      const [k, i] = b.dataset.rm.split(':'), x = C[k][+i];
      if (C[k].length === 1 && k !== 'labels') { toast('Tiene que quedar al menos uno', true); return; }
      C[k].splice(+i, 1);
      const n = counts(k, NAMES[k][2]);
      if (x.id && n[x.id] && k !== 'labels') gone[k][x.id] = (C[k].find(y => y.id) || {}).id || '';
      draw();
    } else if (b.dataset.undo) {
      const [k, id] = b.dataset.undo.split(':');
      C[k].push(TK.cfg[k].find(x => x.id === id)); delete gone[k][id]; draw();
    }
  });
  $('cf-save').addEventListener('click', async () => {
    sync();
    const btn = $('cf-save'); btn.disabled = true;
    try {
      const r = await api('config_save', { config: C, remap: { statuses: gone.statuses, priorities: gone.priorities } });
      TK.cfg = r.config; TK.tasks = r.tasks; TK.prefs = r.prefs; TK.arch = [];
      if (TK.f.arch) await reload();
      fillFilters(); render(); closeDlg(); toast('Configuración guardada');
    } catch (e) { $('cf-msg').textContent = e.message; $('cf-msg').className = 'msg err'; btn.disabled = false; }
  });
}

// ════════════════════════════════════════════════════════════
// Pasos, adjuntos, horas, selección múltiple y plantillas (2026-10-30)
// ════════════════════════════════════════════════════════════
/** 95 → "1 h 35 min"; corto: "1:35 h". */
function fmtMin(m, short = false) {
  m = Math.max(0, Math.round(m || 0));
  const h = Math.floor(m / 60), r = m % 60;
  if (short) return h ? `${h}:${String(r).padStart(2, '0')} h` : `${r} min`;
  return h ? `${h} h${r ? ' ' + r + ' min' : ''}` : `${r} min`;
}
/** "2026-10-30 14:05:00" (hora del servidor) → milisegundos. */
const srvTime = s => new Date(String(s).replace(' ', 'T')).getTime();
const elapsedMin = startedAt => Math.max(0, (Date.now() + TK.nowOff - srvTime(startedAt)) / 60000);
function progHtml(t) {
  const p = Math.round(t.n_checks_done / t.n_checks * 100);
  return `<div class="tk-prog${p === 100 ? ' full' : ''}" title="${t.n_checks_done} de ${t.n_checks} pasos"><span style="width:${p}%"></span><b>${t.n_checks_done}/${t.n_checks}</b></div>`;
}
/** Íconos chicos de la fila de la Lista: repetición, pasos, adjuntos y horas. */
function metaIcons(t) {
  const run = TK.timer?.task_id === t.id;
  return (t.repeat_rule ? '<span class="tk-meta" title="Se repite"><i class="fa-solid fa-repeat"></i></span>' : '')
    + (t.n_checks ? `<span class="tk-meta${t.n_checks_done === t.n_checks ? ' ok' : ''}" title="Pasos hechos"><i class="fa-regular fa-square-check"></i>${t.n_checks_done}/${t.n_checks}</span>` : '')
    + (t.n_files ? `<span class="tk-meta" title="Adjuntos"><i class="fa-solid fa-paperclip"></i>${t.n_files}</span>` : '')
    + (t.minutes || run ? `<span class="tk-meta${run ? ' run' : ''}" title="${run ? 'Reloj andando' : 'Horas cargadas'}"><i class="fa-regular fa-clock"></i>${fmtMin(t.minutes || 0, true)}</span>` : '');
}

// ── Selección y cambios en bloque (Lista y Mis tareas) ───────
function onSelChange(e) {
  const cb = e.target;
  if (cb.matches('[data-sel]')) {
    const id = +cb.closest('[data-id]').dataset.id;
    cb.checked ? TK.sel.add(id) : TK.sel.delete(id);
    cb.closest('.tk-tr').classList.toggle('sel', cb.checked);
    drawBulk();
  } else if (cb.matches('[data-selall]')) {
    const g = cb.closest('.tk-group');
    g.querySelectorAll('.tk-tr[data-id]').forEach(r => { const id = +r.dataset.id; cb.checked ? TK.sel.add(id) : TK.sel.delete(id); });
    render();
  }
}
function drawBulk() {
  const b = $('tk-bulk'); if (!b) return;
  const n = TK.sel.size, on = n > 0 && (TK.view === 'list' || TK.view === 'mine');
  b.classList.toggle('on', on);
  if (!on) return;
  $('tk-bulk-n').textContent = n === 1 ? '1 tarea' : n + ' tareas';
  b.querySelector('[data-b="archive"]').innerHTML = TK.f.arch ? '<i class="fa-solid fa-box-open"></i> Sacar del archivo' : '<i class="fa-solid fa-box-archive"></i> Archivar';
}
async function bulk(payload, okMsg) {
  const ids = [...TK.sel];
  try {
    const r = await api('bulk', { ids, ...payload });
    TK.sel.clear();
    if (TK.f.arch || payload.op === 'archive' || payload.op === 'unarchive') { TK.arch = []; await reload(); if (!TK.f.arch) TK.tasks = r.tasks; }
    else TK.tasks = r.tasks;
    render();
    toast(r.skipped ? `${okMsg(r.n)}. ${r.skipped} no se borraron: solo quien las creó o un admin.` : okMsg(r.n), !!r.skipped);
  } catch (e) { toast(e.message, true); }
}
const nTareas = n => n + (n === 1 ? ' tarea' : ' tareas');
async function onBulkClick(e) {
  const a = e.target.closest('[data-b]'); if (!a) return;
  const k = a.dataset.b, fake = { status: '', priority: '', assignees: [], client_id: 0, labels: [], due_date: null };
  if (k === 'clear') { TK.sel.clear(); render(); return; }
  if (k === 'status') pickStatus(a, fake, v => bulk({ fields: { status: v } }, n => `${nTareas(n)} → ${statusOf(v).name}`));
  if (k === 'prio') pickPrio(a, fake, v => bulk({ fields: { priority: v } }, n => `${nTareas(n)} con prioridad ${prioOf(v).name}`));
  if (k === 'users') choose(a, { items: userItems(), search: TK.users.length > 6 }, v => bulk({ fields: { assignees_add: [v] } }, n => `${userOf(v)?.name} sumado a ${nTareas(n)}`));
  if (k === 'client') pickClient(a, fake, v => bulk({ fields: { client_id: v } }, n => `Marca cambiada en ${nTareas(n)}`));
  if (k === 'due') pickDue(a, fake, v => bulk({ fields: { due_date: v } }, n => `Fecha cambiada en ${nTareas(n)}`));
  if (k === 'archive') bulk({ op: TK.f.arch ? 'unarchive' : 'archive' }, n => TK.f.arch ? `${nTareas(n)} volvieron al tablero` : `${nTareas(n)} archivadas`);
  if (k === 'delete') {
    if (!await ask('Borrar tareas', `Se borran ${nTareas(TK.sel.size)} con sus comentarios, pasos y horas. No se puede deshacer.`, 'Borrar', true)) return;
    bulk({ op: 'delete' }, n => `${nTareas(n)} borradas`);
  }
}

// ── Pasos ────────────────────────────────────────────────────
function drawChecks() {
  const box = $('tk-checks'); if (!box) return;
  const t = T(), L = t.checks || [], done = L.filter(c => +c.done).length;
  $('tk-ckn').textContent = L.length ? `${done}/${L.length}` : '';
  box.innerHTML = `${L.length ? `<div class="tk-prog big${done === L.length ? ' full' : ''}"><span style="width:${Math.round(done / L.length * 100)}%"></span></div>` : ''}
    <div class="tk-cks">${L.map((c, i) => `<div class="tk-ck${+c.done ? ' done' : ''}" data-k="${TK.cur ? c.id : i}" draggable="true">
        <span class="h" title="Arrastrar para ordenar"><i class="fa-solid fa-grip-vertical"></i></span>
        <input type="checkbox" ${+c.done ? 'checked' : ''} aria-label="Hecho: ${esc(c.body)}">
        <span class="b" tabindex="0" role="button" title="Clic para editar">${esc(c.body)}</span>
        ${+c.done && c.done_by ? `<small>${esc(userOf(c.done_by)?.name || '')}</small>` : ''}
        <button class="tk-icon" type="button" data-ck-del aria-label="Borrar paso"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}</div>
    <div class="tk-ck-add"><i class="fa-solid fa-plus"></i><input id="tk-ck-new" placeholder="Agregar un paso y Enter" maxlength="300" aria-label="Nuevo paso"></div>`;
  const inp = $('tk-ck-new');
  inp.addEventListener('keydown', async e => {
    if (e.key !== 'Enter' || !inp.value.trim()) return;
    e.preventDefault();
    const body = inp.value.trim(); inp.value = '';
    if (TK.draft) { TK.draft.checks.push({ body, done: 0 }); drawChecks(); $('tk-ck-new').focus(); return; }
    try { const r = await api('check_add', { task_id: TK.cur.id, body }); setCur(r.task); drawChecks(); $('tk-ck-new').focus(); } catch (err) { toast(err.message, true); }
  });
  box.querySelectorAll('.tk-ck').forEach(el => {
    const k = +el.dataset.k;
    el.querySelector('input').addEventListener('change', async ev => {
      if (TK.draft) { TK.draft.checks[k].done = ev.target.checked ? 1 : 0; drawChecks(); return; }
      try { const r = await api('check_set', { id: k, done: ev.target.checked }); setCur(r.task); drawChecks(); drawActivity(); } catch (err) { toast(err.message, true); drawChecks(); }
    });
    el.querySelector('[data-ck-del]').addEventListener('click', async () => {
      if (TK.draft) { TK.draft.checks.splice(k, 1); drawChecks(); return; }
      try { const r = await api('check_del', { id: k }); setCur(r.task); drawChecks(); } catch (err) { toast(err.message, true); }
    });
    const b = el.querySelector('.b');
    const edit = () => {
      const cur = b.textContent, i2 = document.createElement('input'); i2.className = 'e'; i2.value = cur; i2.maxLength = 300;
      b.replaceWith(i2); i2.focus(); i2.select();
      let gone = false;
      const fin = async save => {
        if (gone) return; gone = true;
        const v = i2.value.trim();
        if (!save || !v || v === cur) { drawChecks(); return; }
        if (TK.draft) { TK.draft.checks[k].body = v; drawChecks(); return; }
        try { const r = await api('check_set', { id: k, body: v }); setCur(r.task); } catch (err) { toast(err.message, true); }
        drawChecks();
      };
      i2.addEventListener('keydown', ev => { if (ev.key === 'Enter') fin(true); if (ev.key === 'Escape') fin(false); });
      i2.addEventListener('blur', () => fin(true));
    };
    b.addEventListener('click', edit); b.addEventListener('keydown', ev => { if (ev.key === 'Enter') edit(); });
    // Ordenar arrastrando (arrastre nativo: el panel no usa el de las tarjetas)
    el.addEventListener('dragstart', ev => { if (!ev.target.closest('.tk-ck')) return; el.classList.add('dragging'); ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'ck'); });
    el.addEventListener('dragend', async () => {
      el.classList.remove('dragging');
      const keys = [...box.querySelectorAll('.tk-ck')].map(x => +x.dataset.k);
      if (TK.draft) { TK.draft.checks = keys.map(i => TK.draft.checks[i]); drawChecks(); return; }
      if (keys.join() === (TK.cur.checks || []).map(c => c.id).join()) return;
      try { const r = await api('check_order', { task_id: TK.cur.id, ids: keys }); setCur(r.task); drawChecks(); } catch (err) { toast(err.message, true); }
    });
    el.addEventListener('dragover', ev => {
      const drag = box.querySelector('.tk-ck.dragging'); if (!drag || drag === el) return;
      ev.preventDefault(); const r = el.getBoundingClientRect();
      el.parentNode.insertBefore(drag, ev.clientY < r.top + r.height / 2 ? el : el.nextSibling);
    });
  });
}
/** Detalle nuevo de la tarea abierta: lo guarda y actualiza la fila de la vista. */
function setCur(task) { TK.cur = task; upsert(task); render(); }

// ── Adjuntos ─────────────────────────────────────────────────
const TKF = '/toolz/tareas_files.php';
const CHUNK = 1536 << 10;   // 1,5 MB por parte: el hosting corta en 2 MB
let UPS = [];               // subidas en curso de la tarea abierta: {name, pct, err}
function fileIcon(f) {
  const e = (f.name.match(/\.([a-z0-9]+)$/i) || [])[1]?.toLowerCase() || '';
  if (/pdf/.test(f.mime)) return 'fa-file-pdf';
  if (/^(zip|rar|7z)$/.test(e)) return 'fa-file-zipper';
  if (/^(ttf|otf|woff2?)$/.test(e)) return 'fa-font';
  if (/^(ai|eps|psd|indd|cdr|xd|fig|sketch)$/.test(e)) return 'fa-pen-ruler';
  if (/video/.test(f.mime)) return 'fa-file-video';
  if (/image/.test(f.mime)) return 'fa-file-image';
  return 'fa-file';
}
const kb = b => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
function drawFiles() {
  const box = $('tk-files'); if (!box || !TK.cur) return;
  // Versiones (fase 6): se muestra la última de cada archivo; las anteriores quedan en su historial
  const ALL = TK.cur.files || [], c = clientOf(TK.cur.client_id);
  const rootOf = f => f.version_of || f.id;
  const F = Object.values(ALL.reduce((g, f) => { const k = rootOf(f); if (!g[k] || (f.version || 1) > (g[k].version || 1)) g[k] = f; return g; }, {})).sort((a, b) => b.id - a.id);
  const olderOf = f => ALL.filter(x => rootOf(x) === rootOf(f) && x.id !== f.id).sort((a, b) => (b.version || 1) - (a.version || 1));
  $('tk-fn').textContent = F.length || '';
  $('tk-files-act').innerHTML = `<button class="tk-btn sm" type="button" data-d="file-up"><i class="fa-solid fa-cloud-arrow-up"></i> Subir</button><input type="file" id="tk-file-in" multiple hidden>`;
  box.innerHTML = `${UPS.map(u => `<div class="tk-fi up"><span class="ic"><i class="fa-solid ${u.err ? 'fa-triangle-exclamation' : 'fa-spinner fa-spin'}"></i></span><div class="b"><b>${esc(u.name)}</b><small${u.err ? ' style="color:#f87171"' : ''}>${u.err ? esc(u.err) : u.pct + '%'}</small>${u.err ? '' : `<span class="bar"><span style="width:${u.pct}%"></span></span>`}</div></div>`).join('')}
    ${F.map(f => {
      const img = !f.gone && /^image\/(png|jpeg|webp|gif)$/.test(f.mime);
      const where = esc(userOf(+f.uploaded_by)?.name || '') + ' · ' + esc(fmtShort(f.created_at));
      return `<div class="tk-fi" data-f="${f.id}"><a class="ic" ${f.gone ? '' : `href="${TKF}?f=${f.id}" target="_blank" rel="noopener"`}>${img ? `<img src="${TKF}?f=${f.id}" alt="" loading="lazy">` : `<i class="fa-solid ${fileIcon(f)}"></i>`}</a>
        <div class="b"><b title="${esc(f.name)}">${esc(f.name)}${(f.version || 1) > 1 ? ` <span class="tk-ver">v${f.version}</span>` : ''}</b><small>${kb(f.size)} · ${where}</small>
          ${olderOf(f).length ? `<details class="tk-vers"><summary>${olderOf(f).length} ${olderOf(f).length > 1 ? 'versiones anteriores' : 'versión anterior'}</summary>${olderOf(f).map(o => `<div><a href="${TKF}?f=${o.id}" target="_blank" rel="noopener">v${o.version || 1} · ${esc(o.name)}</a> <small>${kb(o.size)} · ${esc(userOf(+o.uploaded_by)?.name || '')} · ${ago(o.created_at)}</small></div>`).join('')}</details>` : ''}</div>
        ${f.gone ? '' : `<button class="tk-icon" type="button" data-d="file-ver" title="Subir una versión nueva"><i class="fa-solid fa-code-branch"></i></button><a class="tk-icon" href="${TKF}?f=${f.id}&dl=1" title="Descargar"><i class="fa-solid fa-download"></i></a>`}
        <button class="tk-icon" type="button" data-d="file-del" title="${f.client_file_id ? 'Quitar de la tarea (queda en Materiales)' : 'Borrar el adjunto'}"><i class="fa-solid fa-xmark"></i></button></div>`;
    }).join('')}
    ${!F.length && !UPS.length ? `<div class="tk-fdrop" data-d="file-up"><i class="fa-solid fa-cloud-arrow-up"></i>Arrastra archivos acá o toca <b>Subir</b>.</div>` : ''}`;
  const inp = $('tk-file-in');
  inp.onchange = () => { if (inp.files.length) uploadFiles([...inp.files]); inp.value = ''; };
}
async function uploadFiles(files, versionOf = null) {
  const tid = TK.cur?.id; if (!tid) return;
  for (const f of files) {
    const u = { name: f.name, pct: 0, err: '' }; UPS.push(u); drawFiles();
    const tok = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
    const n = Math.max(1, Math.ceil(f.size / CHUNK));
    try {
      for (let i = 0; i < n; i++) {
        const fd = new FormData(); fd.append('action', 'up_chunk'); fd.append('token', tok); fd.append('index', i); fd.append('chunk', f.slice(i * CHUNK, (i + 1) * CHUNK), 'parte');
        let ok = false, last;
        for (let t = 1; t <= 3 && !ok; t++) {
          try { const r = await fetch(TKF, { method: 'POST', body: fd }); const j = await r.json().catch(() => ({ error: 'El servidor rechazó la parte (' + r.status + ')' })); if (j.error) throw new Error(j.error); ok = true; }
          catch (e) { last = e; if (t < 3) await new Promise(r => setTimeout(r, 700 * t)); }
        }
        if (!ok) throw last;
        u.pct = Math.round((i + 1) / n * 100); if (TK.cur?.id === tid) drawFiles();
      }
      const r = await fetch(TKF, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'up_done', token: tok, parts: n, size: f.size, name: f.name, task_id: tid, version_of: versionOf }) });
      const j = await r.json().catch(() => ({ error: 'El servidor respondió algo inesperado' }));
      if (j.error) throw new Error(j.error);
      UPS = UPS.filter(x => x !== u);
    } catch (e) {
      u.err = e.message || 'No se pudo subir';
      fetch(TKF, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'up_abort', token: tok }) }).catch(() => {});
      setTimeout(() => { UPS = UPS.filter(x => x !== u); if (TK.cur?.id === tid) drawFiles(); }, 6000);
    }
  }
  if (TK.cur?.id === tid) { try { const g = await api('get', { id: tid }); setCur(g.task); drawFiles(); drawActivity(); if (!UPS.some(u => u.err)) toast('Adjunto subido'); } catch (e) {} }
}
async function materialsPicker() {
  const c = clientOf(TK.cur.client_id); if (!c) return;
  const d = openDlg(`<div class="tk-dlg-h"><h3>Vincular archivos de ${esc(c.name)}</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b" id="mp-b"><div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk"></div></div></div>
    <div class="tk-dlg-f"><span class="msg" id="mp-msg"></span><button class="tk-btn" type="button" data-close>Cancelar</button><button class="tk-btn primary" type="button" id="mp-ok" disabled>Vincular</button></div>`);
  let r; try { r = await api('client_files', { client_id: c.id }); } catch (e) { $('mp-b').innerHTML = `<div class="tk-note">${esc(e.message)}</div>`; return; }
  const linked = new Set((TK.cur.files || []).map(f => f.client_file_id)), sel = new Set();
  const byF = {}; r.files.forEach(f => (byF[f.folder || ''] ||= []).push(f));
  $('mp-b').innerHTML = r.files.length ? Object.entries(byF).map(([fo, L]) => `<div class="tk-mp-f"><i class="fa-solid fa-folder"></i> ${esc(fo || c.name)}</div>${L.map(f => `
      <label class="tk-mp${linked.has(f.id) ? ' off' : ''}"><input type="checkbox" value="${f.id}" ${linked.has(f.id) ? 'disabled checked' : ''}><i class="fa-solid ${fileIcon(f)}"></i><span>${esc(f.name)}</span><small>${kb(f.size)}${linked.has(f.id) ? ' · ya vinculado' : ''}</small></label>`).join('')}`).join('')
    : `<div class="tk-note">${esc(c.name)} todavía no tiene archivos en Materiales. Subilos desde <a href="/toolz/stock.php?c=${c.id}" target="_blank" rel="noopener">su carpeta</a> o con <b>Subir</b> en la tarea.</div>`;
  d.querySelectorAll('.tk-mp input:not(:disabled)').forEach(i => i.addEventListener('change', () => { i.checked ? sel.add(+i.value) : sel.delete(+i.value); $('mp-ok').disabled = !sel.size; $('mp-msg').textContent = sel.size ? sel.size + ' elegido' + (sel.size === 1 ? '' : 's') : ''; }));
  $('mp-ok').addEventListener('click', async () => {
    try { const x = await api('file_link', { task_id: TK.cur.id, client_file_ids: [...sel] }); setCur(x.task); drawFiles(); drawActivity(); closeDlg(); toast('Archivos vinculados'); }
    catch (e) { $('mp-msg').textContent = e.message; $('mp-msg').className = 'msg err'; }
  });
}

// ── Horas ────────────────────────────────────────────────────
function drawTime() {
  const box = $('tk-time'); if (!box || !TK.cur) return;
  const run = TK.timer?.task_id === TK.cur.id, L = TK.cur.time || [];
  const total = (TK.cur.minutes || 0) + (run ? elapsedMin(TK.timer.started_at) : 0);
  $('tk-tmn').textContent = total ? fmtMin(total) : '';
  $('tk-time-act').innerHTML = `<button class="tk-btn sm${run ? ' running' : ''}" type="button" data-d="${run ? 'time-stop' : 'time-start'}">${run ? `<i class="fa-solid fa-stop"></i> Detener · <span data-tick>${clock(TK.timer.started_at)}</span>` : '<i class="fa-solid fa-play"></i> Iniciar reloj'}</button>
    <button class="tk-btn sm" type="button" data-d="time-add"><i class="fa-solid fa-plus"></i> Cargar</button>`;
  const per = {}; L.filter(m => m.ended_at).forEach(m => per[m.user_id] = (per[m.user_id] || 0) + m.minutes);
  box.innerHTML = `${Object.keys(per).length > 1 ? `<div class="tk-tm-sum">${Object.entries(per).map(([u, m]) => `<span>${av(userOf(+u), 20)} ${esc(userOf(+u)?.name || '')} <b>${fmtMin(m)}</b></span>`).join('')}</div>` : ''}
    <div id="tk-tm-form"></div>
    ${L.length ? `<div class="tk-tm">${L.map(m => `<div class="tk-tm-r" data-m="${m.id}">${av(userOf(m.user_id), 22)}
        <span class="w">${esc(fmtShort(m.started_at))}${m.note ? ' · ' + esc(m.note) : ''}</span>
        <b>${m.ended_at ? fmtMin(m.minutes) : '<span class="tk-live">andando</span>'}</b>
        ${m.user_id === TK.me || TK.admin ? '<button class="tk-icon" type="button" data-d="time-del" title="Borrar"><i class="fa-solid fa-xmark"></i></button>' : '<span style="width:30px"></span>'}</div>`).join('')}</div>`
      : '<div class="tk-note">Todavía no hay horas. Inicia el reloj mientras trabajas o cargalas a mano.</div>'}`;
}
const clock = s => { const m = elapsedMin(s), h = Math.floor(m / 60), mm = Math.floor(m % 60), ss = Math.floor(m * 60 % 60); return (h ? h + ':' : '') + String(mm).padStart(h ? 2 : 1, '0') + ':' + String(ss).padStart(2, '0'); };
function timeForm() {
  const box = $('tk-tm-form'); if (!box) return;
  box.innerHTML = `<div class="tk-tm-f">
      <label>Horas<input type="number" id="tm-h" min="0" max="24" value="1"></label>
      <label>Minutos<input type="number" id="tm-m" min="0" max="59" step="5" value="0"></label>
      <label>Día<input type="date" id="tm-d" value="${TK.today}" style="color-scheme:dark"></label>
      <label class="w">Nota<input type="text" id="tm-n" maxlength="250" placeholder="Opcional: qué hiciste"></label>
      <div class="a"><button class="tk-btn sm" type="button" data-d="time-cancel">Cancelar</button><button class="tk-btn primary sm" type="button" data-d="time-save">Guardar</button></div></div>`;
  $('tm-h').focus(); $('tm-h').select();
}
async function setTimer(r) {
  TK.timer = r.timer; if (r.tasks) TK.tasks = r.tasks;
  if (TK.cur) { try { const g = await api('get', { id: TK.cur.id }); TK.cur = g.task; } catch (e) {} drawTime(); }
  drawTimerChip(); render();
}
function drawTimerChip() {
  const b = $('tk-timer'); if (!b) return;
  b.hidden = !TK.timer;
  if (!TK.timer) return;
  b.innerHTML = `<span class="dot"></span><span class="t">${esc(TK.timer.title)}</span><b data-tick>${clock(TK.timer.started_at)}</b><span class="stop" data-stop title="Detener el reloj" role="button" aria-label="Detener el reloj"><i class="fa-solid fa-stop"></i></span>`;
}

// ── Informe de horas ─────────────────────────────────────────
async function hoursDialog(from, to, user = '') {
  const t0 = new Date(parseD(TK.today));
  if (!from) { from = isoD(new Date(t0.getUTCFullYear(), t0.getUTCMonth(), 1)); to = isoD(new Date(t0.getUTCFullYear(), t0.getUTCMonth() + 1, 0)); }
  const d = openDlg(`<div class="tk-dlg-h"><h3><i class="fa-regular fa-clock"></i> Horas</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b"><div class="tk-hr-bar">
      <button class="tk-icon" type="button" id="hr-prev" aria-label="Mes anterior"><i class="fa-solid fa-chevron-left"></i></button>
      <input type="date" id="hr-from" value="${from}" style="color-scheme:dark"><span>a</span><input type="date" id="hr-to" value="${to}" style="color-scheme:dark">
      <button class="tk-icon" type="button" id="hr-next" aria-label="Mes siguiente"><i class="fa-solid fa-chevron-right"></i></button>
      ${TK.admin ? `<select class="tk-sel" id="hr-user"><option value="">Todo el equipo</option>${TK.users.map(u => `<option value="${u.id}"${String(u.id) === String(user) ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select>` : ''}
      <span class="grow"></span><button class="tk-btn sm" type="button" id="hr-csv" disabled><i class="fa-solid fa-file-csv"></i> Descargar CSV</button></div>
      <div id="hr-body"><div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk"></div></div></div></div>`, true);
  const reopen = (f, t) => hoursDialog(f, t, $('hr-user')?.value || '');
  const shift = k => { const a = new Date(from + 'T00:00:00'); const s = new Date(a.getFullYear(), a.getMonth() + k, 1); reopen(isoD(s), isoD(new Date(s.getFullYear(), s.getMonth() + 1, 0))); };
  $('hr-prev').onclick = () => shift(-1); $('hr-next').onclick = () => shift(1);
  ['hr-from', 'hr-to', 'hr-user'].forEach(id => $(id)?.addEventListener('change', () => reopen($('hr-from').value, $('hr-to').value)));
  let r; try { r = await api('time_report', { from, to, user }); } catch (e) { $('hr-body').innerHTML = `<div class="tk-note">${esc(e.message)}</div>`; return; }
  const R = r.rows, tot = R.reduce((a, x) => a + x.minutes, 0);
  const sum = key => { const o = {}; R.forEach(x => { const k = key(x); o[k] = (o[k] || 0) + x.minutes; }); return Object.entries(o).sort((a, b) => b[1] - a[1]); };
  const byC = sum(x => x.client_id || 0), byU = sum(x => x.user_id);
  const bar = m => `<span class="tk-hbar"><span style="width:${tot ? Math.round(m / tot * 100) : 0}%"></span></span>`;
  $('hr-body').innerHTML = !R.length ? `<div class="tk-empty" style="padding:36px 16px"><i class="fa-regular fa-clock"></i><b>Sin horas en estas fechas</b>${r.all ? '' : 'Se muestran solo las tuyas. '}Cargalas desde la ficha de cada tarea.</div>` : `
    <div class="tk-hr-tot"><b>${fmtMin(tot)}</b><span>${r.all && !user ? 'del equipo' : user ? 'de ' + esc(userOf(+user)?.name || '') : 'tuyas'} entre el ${fmtShort(r.from)} y el ${fmtShort(r.to)}</span></div>
    <div class="tk-hr-grid">
      <div><h4>Por marca</h4>${byC.map(([c, m]) => `<div class="tk-hr-r"><span>${c === '0' ? '<i>Sin marca</i>' : esc(clientOf(+c)?.name || '#' + c)}</span>${bar(m)}<b>${fmtMin(m, true)}</b></div>`).join('')}</div>
      <div><h4>Por persona</h4>${byU.map(([u, m]) => `<div class="tk-hr-r"><span>${av(userOf(+u), 20)} ${esc(userOf(+u)?.name || '')}</span>${bar(m)}<b>${fmtMin(m, true)}</b></div>`).join('')}</div>
    </div>
    <h4 class="tk-hr-h4">Detalle</h4>
    <div class="tk-hr-list">${R.map(x => `<div class="tk-hr-row"><span class="d">${esc(fmtShort(x.started_at))}</span>${av(userOf(x.user_id), 20)}<a href="?t=${x.task_id}" data-open="${x.task_id}">${esc(x.title)}</a><span class="c">${esc(clientOf(x.client_id)?.name || '')}${x.note ? ' · ' + esc(x.note) : ''}</span><b>${fmtMin(x.minutes, true)}</b></div>`).join('')}</div>`;
  d.querySelectorAll('[data-open]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); closeDlg(); openTask(+a.dataset.open); }));
  const csv = $('hr-csv'); csv.disabled = !R.length;
  csv.onclick = () => {
    const q = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
    const lines = [['Fecha', 'Persona', 'Marca', 'Tarea', 'Minutos', 'Horas', 'Nota'].map(q).join(';'),
      ...R.map(x => [x.started_at.slice(0, 10), userOf(x.user_id)?.name, clientOf(x.client_id)?.name || '', x.title, x.minutes, (x.minutes / 60).toFixed(2).replace('.', ','), x.note].map(q).join(';'))];
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = `horas_${r.from}_${r.to}.csv`; document.body.appendChild(a); a.click(); a.remove();
  };
}

// ── Plantillas ───────────────────────────────────────────────
function tplMenu(anchor) {
  const items = TK.templates.map(t => ({ id: t.id, name: t.name, html: `<i class="fa-regular fa-clone" style="width:14px;color:var(--g7-text-4)"></i><span>${esc(t.name)}</span><span class="ck" style="color:var(--g7-text-4)">${(t.data.checks || []).length ? (t.data.checks.length + ' pasos') : ''}${t.data.repeat_rule ? ' · <i class="fa-solid fa-repeat"></i>' : ''}</span>` }));
  const p = choose(anchor, { items, empty: 'Todavía no hay plantillas', footer: '<div class="sep"></div><div class="tk-opt" data-manage><i class="fa-solid fa-gear" style="width:14px"></i><span>Administrar plantillas</span></div>' }, id => newFromTpl(TK.templates.find(t => t.id === id)));
  p.querySelector('[data-manage]').addEventListener('click', e => { e.stopPropagation(); closePop(); tplManage(); });
}
function newFromTpl(tp) {
  if (!tp) return;
  const d = tp.data || {}, due = d.due_in ? (() => { const x = new Date(parseD(TK.today)); x.setUTCDate(x.getUTCDate() + d.due_in); return x.toISOString().slice(0, 10); })() : null;
  const def = newDefaults();
  newTask({ title: d.title || tp.name, description: d.description || '', priority: d.priority || def.priority, labels: (d.labels || []).filter(labelOf),
    assignees: (d.assignees || []).length ? d.assignees.filter(userOf) : def.assignees, repeat_rule: d.repeat_rule || '', due_date: due,
    checks: (d.checks || []).map(b => ({ body: b, done: 0 })) });
  setTimeout(() => { const ti = D().querySelector('.tk-dtitle'); if (ti) { ti.focus(); ti.setSelectionRange(ti.value.length, ti.value.length); } }, 260);
}
function tplSaveDialog(tp = null) {
  const t = tp ? null : T(), base = tp ? tp.data : null;
  const name = tp ? tp.name : t.title;
  const dueIn = tp ? (base.due_in || 0) : (t.due_date ? Math.max(0, diffDays(TK.today, t.due_date)) : 0);
  openDlg(`<div class="tk-dlg-h"><h3>${tp ? 'Editar plantilla' : 'Guardar como plantilla'}</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b"><p class="tk-note" style="margin:0">${tp ? '' : 'Guarda el título, la descripción, la prioridad, las etiquetas, los responsables, la repetición y los pasos (sin tildar). La marca y los adjuntos no: se eligen al usarla.'}</p>
      <div class="tk-form"><label class="full">Nombre de la plantilla<input type="text" id="tp-name" maxlength="80" value="${esc(name)}"></label>
      <label>Vence a los … días de crearla<input type="number" id="tp-due" min="0" max="365" value="${dueIn}"><span class="tk-note" style="font-weight:400">0 = sin fecha</span></label></div></div>
    <div class="tk-dlg-f"><span class="msg" id="tp-msg"></span><button class="tk-btn" type="button" data-close>Cancelar</button><button class="tk-btn primary" type="button" id="tp-ok">Guardar</button></div>`);
  $('tp-name').focus(); $('tp-name').select();
  $('tp-ok').addEventListener('click', async () => {
    const data = tp ? { ...base } : { title: t.title, description: t.description || '', priority: t.priority, labels: t.labels, assignees: t.assignees, repeat_rule: t.repeat_rule || '', checks: (t.checks || []).map(c => c.body) };
    data.due_in = +$('tp-due').value || 0;
    try { const r = await api('tpl_save', { id: tp ? tp.id : 0, name: $('tp-name').value, data }); TK.templates = r.templates; closeDlg(); toast(tp ? 'Plantilla guardada' : 'Plantilla creada: la encuentras en la flecha de "Nueva tarea"'); if (tp) tplManage(); }
    catch (e) { $('tp-msg').textContent = e.message; $('tp-msg').className = 'msg err'; }
  });
}
function tplManage() {
  const can = tp => TK.admin || tp.created_by === TK.me;
  const d = openDlg(`<div class="tk-dlg-h"><h3>Plantillas</h3><button class="tk-icon" type="button" data-close aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="tk-dlg-b">${TK.templates.length ? TK.templates.map(tp => `<div class="tk-tpl" data-tp="${tp.id}">
        <div class="b"><b>${esc(tp.name)}</b><small>${(tp.data.checks || []).length} pasos${tp.data.repeat_rule ? ' · ' + esc(TK.repeats[tp.data.repeat_rule] || '') : ''}${tp.data.due_in ? ' · vence a los ' + tp.data.due_in + ' días' : ''}</small>
          ${(tp.data.checks || []).length ? `<div class="ck">${tp.data.checks.slice(0, 6).map(c => `<span>${esc(c)}</span>`).join('')}${tp.data.checks.length > 6 ? `<span>+${tp.data.checks.length - 6}</span>` : ''}</div>` : ''}</div>
        <button class="tk-btn sm primary" type="button" data-use>Usar</button>
        ${can(tp) ? '<button class="tk-icon" type="button" data-edit title="Cambiar nombre y vencimiento"><i class="fa-solid fa-pen"></i></button><button class="tk-icon" type="button" data-del title="Borrar"><i class="fa-regular fa-trash-can"></i></button>' : ''}</div>`).join('')
      : '<div class="tk-note">Todavía no hay plantillas. Abre una tarea armada (con sus pasos) y toca <i class="fa-regular fa-clone"></i> arriba para guardarla.</div>'}</div>
    <div class="tk-dlg-f"><span class="msg">Para cambiar los pasos de una plantilla: crea una tarea con ella, ajustala y vuelve a guardarla.</span><button class="tk-btn" type="button" data-close>Cerrar</button></div>`);
  d.querySelectorAll('.tk-tpl').forEach(el => {
    const tp = TK.templates.find(x => x.id === +el.dataset.tp);
    el.querySelector('[data-use]').onclick = () => { closeDlg(); newFromTpl(tp); };
    el.querySelector('[data-edit]')?.addEventListener('click', () => tplSaveDialog(tp));
    el.querySelector('[data-del]')?.addEventListener('click', async () => {
      if (!await ask('Borrar plantilla', `"${tp.name}" se borra. Las tareas creadas con ella no cambian.`, 'Borrar', true)) { tplManage(); return; }
      try { const r = await api('tpl_del', { id: tp.id }); TK.templates = r.templates; toast('Plantilla borrada'); } catch (e) { toast(e.message, true); }
      tplManage();
    });
  });
}

// ── Clicks nuevos de la ficha ────────────────────────────────
async function onDrawerPlus(a, act, e) {
  switch (act) {
    case 'file-up': $('tk-file-in')?.click(); break;
    case 'file-pick': materialsPicker(); break;
    case 'file-del': {
      const f = (TK.cur.files || []).find(x => x.id === +a.closest('[data-f]').dataset.f); if (!f) return;
      if (!f.client_file_id && !await ask('Borrar adjunto', `"${f.name}" se borra. No se puede deshacer.`, 'Borrar', true)) return;
      try { const r = await api('file_unlink', { id: f.id }); setCur(r.task); drawFiles(); toast(f.client_file_id ? 'Quitado de la tarea (sigue en Materiales)' : 'Adjunto borrado'); } catch (err) { toast(err.message, true); }
      break;
    }
    case 'time-start': try { const r = await api('time_start', { task_id: TK.cur.id }); await setTimer(r); toast(r.stopped && r.stopped !== TK.cur.id ? 'Reloj iniciado (se detuvo el de la otra tarea)' : 'Reloj iniciado'); } catch (err) { toast(err.message, true); } break;
    case 'time-stop': try { const r = await api('time_stop'); await setTimer(r); toast('Horas guardadas'); } catch (err) { toast(err.message, true); } break;
    case 'time-add': timeForm(); break;
    case 'time-cancel': $('tk-tm-form').innerHTML = ''; break;
    case 'time-save': {
      const min = (+$('tm-h').value || 0) * 60 + (+$('tm-m').value || 0);
      try { const r = await api('time_add', { task_id: TK.cur.id, minutes: min, date: $('tm-d').value, note: $('tm-n').value }); TK.tasks = r.tasks; TK.cur = r.task; drawTime(); render(); toast(fmtMin(min) + ' cargadas'); }
      catch (err) { toast(err.message, true); }
      break;
    }
    case 'time-del': {
      if (!await ask('Borrar horas', 'Se borra este registro de horas.', 'Borrar', true)) return;
      try { const r = await api('time_del', { id: +a.closest('[data-m]').dataset.m }); TK.tasks = r.tasks; TK.cur = r.task; TK.timer = r.timer; drawTime(); drawTimerChip(); render(); } catch (err) { toast(err.message, true); }
      break;
    }
  }
}

function bootPlus() {
  // Barra de selección múltiple
  const b = document.createElement('div'); b.className = 'tk-bulk'; b.id = 'tk-bulk'; b.setAttribute('role', 'toolbar'); b.setAttribute('aria-label', 'Cambiar las tareas elegidas');
  b.innerHTML = `<b id="tk-bulk-n"></b>
    <button class="tk-btn sm" type="button" data-b="status"><i class="fa-solid fa-circle-half-stroke"></i> Estado</button>
    <button class="tk-btn sm" type="button" data-b="users"><i class="fa-solid fa-user-plus"></i> Responsable</button>
    <button class="tk-btn sm" type="button" data-b="prio"><i class="fa-solid fa-flag"></i> Prioridad</button>
    <button class="tk-btn sm" type="button" data-b="due"><i class="fa-regular fa-calendar"></i> Fecha</button>
    <button class="tk-btn sm" type="button" data-b="client"><i class="fa-solid fa-tag"></i> Marca</button>
    <button class="tk-btn sm" type="button" data-b="archive"></button>
    <button class="tk-btn sm danger" type="button" data-b="delete"><i class="fa-regular fa-trash-can"></i> Borrar</button>
    <button class="tk-icon" type="button" data-b="clear" aria-label="Quitar la selección" title="Quitar la selección"><i class="fa-solid fa-xmark"></i></button>`;
  document.body.appendChild(b); b.addEventListener('click', onBulkClick);
  // Adjuntos: arrastrar archivos sobre la ficha
  D().addEventListener('dragover', e => { if (TK.cur && [...(e.dataTransfer?.types || [])].includes('Files')) { e.preventDefault(); $('tk-files-sec')?.classList.add('over'); } });
  D().addEventListener('dragleave', e => { if (!D().contains(e.relatedTarget)) $('tk-files-sec')?.classList.remove('over'); });
  D().addEventListener('drop', e => { if (!TK.cur || !e.dataTransfer?.files?.length) return; e.preventDefault(); $('tk-files-sec')?.classList.remove('over'); uploadFiles([...e.dataTransfer.files]); });
  // Plantillas, horas y reloj
  $('tk-tpl-btn').addEventListener('click', e => tplMenu(e.currentTarget));
  $('tk-hours-btn').addEventListener('click', () => hoursDialog());
  $('tk-timer').addEventListener('click', async e => {
    if (e.target.closest('[data-stop]')) { try { const r = await api('time_stop', { detail: true }); await setTimer(r); toast('Horas guardadas'); } catch (err) { toast(err.message, true); } return; }
    if (TK.timer) openTask(TK.timer.task_id);
  });
  drawTimerChip();
  setInterval(() => { if (!TK.timer) return; document.querySelectorAll('[data-tick]').forEach(x => x.textContent = clock(TK.timer.started_at)); }, 1000);
}

// ── Arranque ─────────────────────────────────────────────────
async function boot() {
  const open = +new URLSearchParams(location.search).get('t');   // antes de que saveState reescriba la dirección
  loadState();
  try {
    const r = await api('boot');
    Object.assign(TK, { cfg: r.config, users: r.users, clients: r.clients, me: r.me, admin: r.admin, prefs: r.prefs, tasks: r.tasks, today: r.today, reactions: r.reactions || [],
      repeats: r.repeats || {}, templates: r.templates || [], timer: r.timer || null, nowOff: r.now ? srvTime(r.now) - Date.now() : 0,
      board: r.board || null, boards: r.boards || [], fields: r.fields || [], role: r.role || '', projects: r.projects || [] });
  } catch (e) {
    $('tk-view').innerHTML = `<div class="tk-empty"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudo cargar el tablero</b>${esc(e.message)}</div>`;
    return;
  }
  const t0 = new Date(parseD(TK.today)); TK.cal.y = t0.getUTCFullYear(); TK.cal.m = t0.getUTCMonth(); TK.cal.sel = TK.today;
  fillFilters(); render(); saveState();
  if (open) openTask(open);

  document.querySelectorAll('#tk-views button').forEach(b => b.addEventListener('click', () => { TK.view = b.dataset.view; closePop(); saveState(); render(); }));
  $('f-group').value = TK.group;
  $('f-group').addEventListener('change', e => { TK.group = e.target.value; saveState(); render(); });
  bootPlus();
  const onF = (id, key) => $(id).addEventListener('change', e => { TK.f[key] = e.target.value; saveState(); render(); });
  onF('f-client', 'client'); onF('f-user', 'user'); onF('f-prio', 'prio'); onF('f-label', 'label');
  let qT; $('f-q').addEventListener('input', e => { clearTimeout(qT); qT = setTimeout(() => { TK.f.q = e.target.value; render(); }, 150); });
  $('f-arch').addEventListener('change', async e => { TK.f.arch = e.target.checked; if (TK.f.arch) { $('tk-view').innerHTML = '<div class="tk-loading"><div class="tk-sk"></div><div class="tk-sk"></div></div>'; await reload(); } else render(); });
  $('tk-new-btn').addEventListener('click', () => newTask(TK.view === 'calendar' && TK.cal.sel ? { due_date: TK.cal.sel } : {}));
  $('tk-prefs-btn').addEventListener('click', prefsDialog);
  $('tk-cfg-btn')?.addEventListener('click', () => configDialog());
  const box = $('tk-view');
  box.addEventListener('click', onViewClick);
  box.addEventListener('change', onSelChange);
  box.addEventListener('click', e => { const s = e.target.closest('.tk-th [data-sort]'); if (!s) return; const k = s.dataset.sort; TK.sort = TK.sort.key === k ? (TK.sort.dir > 0 ? { key: k, dir: -1 } : { key: 'pos', dir: 1 }) : { key: k, dir: 1 }; saveState(); render(); });
  box.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.matches('[data-act="open"]')) { const r = e.target.closest('[data-id]'); if (r) openTask(+r.dataset.id); }
  });
  box.addEventListener('dblclick', e => { const d = e.target.closest('.tk-day'); if (d && !e.target.closest('.tk-chip') && !TK.f.arch) newTask({ due_date: d.dataset.date }); });
  setupDnD(box);
  D().addEventListener('click', onDrawerClick);
  $('tk-ov').addEventListener('click', () => closeDrawer());
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (POP) { closePop(); return; }
    if (DLG().open) return;
    if (D().classList.contains('open')) closeDrawer();
  });
  // Atajo: N = nueva tarea (fuera de campos de texto)
  document.addEventListener('keydown', e => {
    if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.altKey && !e.target.closest('input,textarea,select,[contenteditable]') && !DLG().open && !D().classList.contains('open')) { e.preventDefault(); newTask(); }
  });
  // Lo que cambian los demás aparece solo (cada 40 s, si la pestaña está a la vista y no estás arrastrando)
  setInterval(async () => {
    if (document.hidden || DRAG || POP || DLG().open || TK.loading) return;
    TK.loading = true;
    try {
      const r = await api('list', { archived: TK.f.arch });
      const sig = a => a.map(t => t.id + ':' + t.updated_at + ':' + t.position + ':' + t.n_comments).join('|');
      const cur = TK.f.arch ? TK.arch : TK.tasks;
      if (sig(r.tasks) !== sig(cur)) {
        if (TK.f.arch) TK.arch = r.tasks; else TK.tasks = r.tasks;
        render();
        if (TK.cur && !D().contains(document.activeElement)) {
          const n = r.tasks.find(t => t.id === TK.cur.id);
          if (n && n.updated_at !== TK.cur.updated_at) { const g = await api('get', { id: TK.cur.id }); if (TK.cur?.id === g.task.id && !D().contains(document.activeElement)) { TK.cur = g.task; drawDrawer(); } }
        }
      }
    } catch (e) {}
    TK.loading = false;
  }, 40000);
  window.tkOpen = id => openTask(id);   // la campana de arriba abre la tarea sin recargar
}
boot();
