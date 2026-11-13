/* tareas2.js — Gestión de trabajo, fase 2, sobre el tablero de tareas.js (se carga después y envuelve sus funciones).
   Agrega: vista Tabla (campos personalizados, exportar CSV), vista Gantt (arrastrar fechas, dependencias),
   subtareas, "bloqueada por", inicio y estimado, campos personalizados en la ficha y mover a otro tablero. */
'use strict';

Object.assign(ACT, { project: 'cambió el proyecto:', start: 'cambió el inicio:', estimate: 'cambió el estimado:', dep: 'agregó "bloqueada por":', dep_del: 'quitó una dependencia', field: 'cambió', board: 'la movió de tablero:' });
TK.tsort = { key: '', dir: 1 };
TK.gz = 'day';
const fieldsOf = () => TK.fields || [];
const DAY = 864e5;
const isoOf = ms => new Date(ms).toISOString().slice(0, 10);

/** Texto para mostrar el valor de un campo. */
function fieldText(f, v) {
  if (v === undefined || v === null || v === '') return '';
  if (f.type === 'select') return (f.options.find(o => o.id === v) || {}).name || '';
  if (f.type === 'person') return userOf(+v)?.name || '';
  if (f.type === 'check') return v === '1' ? 'Sí' : '';
  if (f.type === 'date') return v.split('-').reverse().join('/');
  return String(v);
}
/** Control para editar un campo (ficha y tabla). */
function fieldInput(f, v, cls = 'tk-fin') {
  v = v ?? '';
  const a = `class="${cls}" data-fid="${f.id}" aria-label="${esc(f.name)}"`;
  if (f.type === 'select') return `<select ${a}><option value="">—</option>${f.options.map(o => `<option value="${esc(o.id)}"${o.id === v ? ' selected' : ''}>${esc(o.name)}</option>`).join('')}</select>`;
  if (f.type === 'person') return `<select ${a}><option value="">—</option>${TK.users.map(u => `<option value="${u.id}"${String(u.id) === String(v) ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select>`;
  if (f.type === 'check') return `<input type="checkbox" ${a}${v === '1' ? ' checked' : ''}>`;
  if (f.type === 'date') return `<input type="date" ${a} value="${esc(v)}">`;
  if (f.type === 'number') return `<input type="text" inputmode="decimal" ${a} value="${esc(v)}" placeholder="—">`;
  if (f.type === 'url') return `<span class="tk-furl"><input type="url" ${a} value="${esc(v)}" placeholder="https://…">${v ? `<a href="${esc(v)}" target="_blank" rel="noopener" title="Abrir"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}</span>`;
  return `<input type="text" ${a} value="${esc(v)}" placeholder="—">`;
}
async function saveField(taskId, fid, value) {
  try {
    const r = await api('field_set', { task_id: taskId, field_id: fid, value });
    upsert(r.task);
    if (TK.cur?.id === taskId) { TK.cur.fv = r.task.fv; TK.cur.updated_at = r.task.updated_at; }
    savedMark?.();
  } catch (e) { toast(e.message, true); render(); if (TK.cur?.id === taskId) drawProps(); }
}

// ── Lo que cambia en las vistas de tareas.js ─────────────────
// Las subtareas se ven dentro de su tarea (y en Mis tareas), no sueltas en el tablero
const _visible = visible;
visible = function () { const l = _visible(); return TK.view === 'mine' ? l : l.filter(t => !t.parent_id); };
const _metaIcons = metaIcons;
metaIcons = function (t) {
  return (t.n_blockers ? `<span class="tk-meta blk" title="Bloqueada: depende de ${t.n_blockers} tarea${t.n_blockers > 1 ? 's' : ''} abierta${t.n_blockers > 1 ? 's' : ''}"><i class="fa-solid fa-lock"></i>${t.n_blockers}</span>` : '')
    + (t.n_sub ? `<span class="tk-meta${t.n_sub_done === t.n_sub ? ' ok' : ''}" title="Subtareas terminadas"><i class="fa-solid fa-diagram-subtask"></i>${t.n_sub_done}/${t.n_sub}</span>` : '')
    + _metaIcons(t);
};
const _render = render;
render = function () {
  _render();
  $('f-group-w').hidden = TK.view !== 'list';
  if (TK.view !== 'table' && TK.view !== 'gantt') return;
  const box = $('tk-view');
  if (box.querySelector('.tk-empty') && !box.querySelector('.tk-group')) return;   // "ninguna coincide": lo deja tareas.js
  const list = visible();
  if (TK.view === 'table') renderTable(list); else renderGantt(list);
  drawBulk();
};
const _drawBulk = drawBulk;
drawBulk = function () {
  // La barra de selección también vale en la Tabla
  if (TK.view !== 'table') return _drawBulk();
  const v = TK.view; TK.view = 'list'; _drawBulk(); TK.view = v;
};

// ── TABLA ────────────────────────────────────────────────────
function tableCols() {
  const c = [
    { k: 'title', n: 'Tarea', w: 280, v: t => t.title },
    { k: 'status', n: 'Estado', w: 130, v: t => statusOf(t.status).name },
    { k: 'prio', n: 'Prioridad', w: 110, v: t => prioOf(t.priority).name, s: t => TK.cfg.priorities.findIndex(p => p.id === t.priority) },
    { k: 'users', n: 'Responsables', w: 130, v: t => t.assignees.map(id => userOf(id)?.name || '').join(', ') },
    { k: 'client', n: window.TK_CLI || 'Cliente', w: 140, v: t => clientOf(t.client_id)?.name || '' },
    { k: 'start', n: 'Inicio', w: 128, v: t => t.start_date || '' },
    { k: 'due', n: 'Fecha límite', w: 128, v: t => t.due_date || '' },
    { k: 'est', n: 'Estimado', w: 92, v: t => t.estimate_min ? +(t.estimate_min / 60).toFixed(2) : '', s: t => t.estimate_min || 0 },
    { k: 'min', n: 'Horas', w: 84, v: t => t.minutes ? +(t.minutes / 60).toFixed(2) : '', s: t => t.minutes || 0 },
    { k: 'sub', n: 'Subtareas', w: 90, v: t => t.n_sub ? `${t.n_sub_done}/${t.n_sub}` : '', s: t => t.n_sub ? t.n_sub_done / t.n_sub : -1 },
  ];
  for (const f of fieldsOf()) c.push({ k: 'f' + f.id, n: f.name, w: f.type === 'check' ? 80 : 140, f, v: t => fieldText(f, t.fv?.[f.id]), s: t => f.type === 'number' ? +(t.fv?.[f.id] ?? -Infinity) : fieldText(f, t.fv?.[f.id]) });
  c.push({ k: 'upd', n: 'Actualizada', w: 110, v: t => t.updated_at, s: t => t.updated_at });
  return c;
}
function renderTable(list) {
  const cols = tableCols();
  const { key, dir } = TK.tsort;
  const col = cols.find(c => c.k === key);
  const rows = col ? [...list].sort((a, b) => { const x = (col.s || col.v)(a), y = (col.s || col.v)(b); return (x < y ? -1 : x > y ? 1 : 0) * dir || a.id - b.id; }) : sorted(list);
  const all = rows.length && rows.every(t => TK.sel.has(t.id));
  const cell = (t, c) => {
    const st = statusOf(t.status), pr = prioOf(t.priority), cl = clientOf(t.client_id);
    switch (c.k) {
      case 'title': return `<div class="tk-title" data-act="open" role="button" tabindex="0"><span class="t">${esc(t.title)}</span><span class="ic">${metaIcons(t)}</span></div>`;
      case 'status': return `<button type="button" class="tk-cell-btn tk-cell-fill" style="--c:${st.color}" data-act="pick-status">${esc(st.name)}</button>`;
      case 'prio': return `<button type="button" class="tk-cell-btn tk-cell-fill" style="--c:${pr.color}" data-act="pick-prio">${esc(pr.name)}</button>`;
      case 'users': return `<button type="button" class="tk-cell-btn${t.assignees.length ? '' : ' ph'}" data-act="pick-users" aria-label="Responsables">${t.assignees.length ? avs(t.assignees) : '<i class="fa-solid fa-user-plus"></i>'}</button>`;
      case 'client': return `<button type="button" class="tk-cell-btn${cl ? '' : ' ph'}" data-act="pick-client" aria-label="Cliente">${cl ? `<span class="tk-client-chip">${esc(cl.name)}</span>` : '<i class="fa-solid fa-plus"></i>'}</button>`;
      case 'start': return `<input class="tk-fin" type="date" data-tf="start_date" value="${esc(t.start_date || '')}" aria-label="Inicio">`;
      case 'due': return `<input class="tk-fin${t.due_date && !isDone(t.status) && t.due_date < TK.today ? ' late' : ''}" type="date" data-tf="due_date" value="${esc(t.due_date || '')}" aria-label="Fecha límite">`;
      case 'est': return `<input class="tk-fin num" type="text" inputmode="decimal" data-tf="estimate_min" value="${t.estimate_min ? +(t.estimate_min / 60).toFixed(2) : ''}" placeholder="— h" aria-label="Estimado en horas">`;
      case 'min': return `<span class="tk-num${t.estimate_min && t.minutes > t.estimate_min ? ' over' : ''}">${t.minutes ? fmtMin(t.minutes, true) : '—'}</span>`;
      case 'sub': return t.n_sub ? `<span class="tk-num">${t.n_sub_done}/${t.n_sub}</span>` : '<span class="tk-num muted">—</span>';
      case 'upd': return `<span class="tk-upd" title="${esc(t.updated_at)}">${ago(t.updated_at)}</span>`;
      default: return fieldInput(c.f, t.fv?.[c.f.id]);
    }
  };
  const tmpl = '36px ' + cols.map(c => c.w + 'px').join(' ');
  $('tk-view').innerHTML = `<div class="tk-xbar"><span class="tk-count">${rows.length} ${rows.length === 1 ? 'tarea' : 'tareas'} · ${fieldsOf().length ? fieldsOf().length + ' campo' + (fieldsOf().length > 1 ? 's' : '') + ' propio' + (fieldsOf().length > 1 ? 's' : '') : 'sin campos propios'}</span><span class="grow"></span>
      ${TK.board?.can_manage ? `<a class="tk-btn sm" href="/toolz/trabajo/#tableros/campos/${TK.board.id}"><i class="fa-solid fa-table-cells"></i> Campos del tablero</a>` : ''}
      <button class="tk-btn sm" type="button" data-x="csv"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button></div>
    <div class="tk-xt-w" data-nodrop><div class="tk-xt" style="--cols:${tmpl}" role="table">
      <div class="tk-xr tk-xh" role="row"><div><input type="checkbox" data-tselall${all ? ' checked' : ''} aria-label="Seleccionar todas"></div>
        ${cols.map(c => `<div role="columnheader" class="sortable" data-tsort="${c.k}">${esc(c.n)}${key === c.k ? ` <i class="fa-solid fa-arrow-${dir > 0 ? 'down' : 'up'}-short"></i>` : ''}</div>`).join('')}</div>
      ${rows.map(t => `<div class="tk-xr tk-tr${TK.sel.has(t.id) ? ' sel' : ''}" role="row" data-id="${t.id}" style="--c:${statusOf(t.status).color}"><div><input type="checkbox" data-sel${TK.sel.has(t.id) ? ' checked' : ''} aria-label="Seleccionar ${esc(t.title)}"></div>
        ${cols.map(c => `<div role="cell" class="c-${c.k.startsWith('f') && c.f ? 'f' : c.k}">${cell(t, c)}</div>`).join('')}</div>`).join('')}
      ${TK.f.arch ? '' : `<div class="tk-xr tk-xadd"><div></div><div style="grid-column:2/-1"><button type="button" data-act="new"><i class="fa-solid fa-plus"></i> Agregar tarea</button></div></div>`}
    </div></div>`;
}
function exportCsv() {
  const cols = tableCols().filter(c => c.k !== 'upd');
  const list = visible();
  const q = v => { v = String(v ?? ''); return /[";\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
  const lines = [['ID', ...cols.map(c => c.n), 'Creada', 'Terminada'].map(q).join(';')];
  for (const t of list) lines.push([t.id, ...cols.map(c => c.v(t)), t.created_at, t.completed_at || ''].map(q).join(';'));
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${(TK.board?.name || 'tareas').replace(/[^\wáéíóúñ -]/gi, '').trim() || 'tareas'} ${TK.today}.csv`;
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  toast(`${list.length} tareas exportadas`);
}

// ── GANTT ────────────────────────────────────────────────────
const GZ = { day: 34, week: 14, month: 5 };
function ganttSpan(t) {
  let s = t.start_date || t.due_date, e = t.due_date || t.start_date;
  if (!s) return null;
  if (s > e) [s, e] = [e, s];
  return { s: parseD(s), e: parseD(e) };
}
function renderGantt(list) {
  const W = GZ[TK.gz] || 34;
  const dated = list.filter(t => ganttSpan(t)).sort((a, b) => ganttSpan(a).s - ganttSpan(b).s || a.id - b.id);
  const undated = list.filter(t => !ganttSpan(t));
  const today = parseD(TK.today);
  let min = today - 7 * DAY, max = today + 35 * DAY;
  for (const t of dated) { const g = ganttSpan(t); min = Math.min(min, g.s - 3 * DAY); max = Math.max(max, g.e + 10 * DAY); }
  const days = Math.round((max - min) / DAY) + 1;
  TK.gmin = min; TK.gW = W;
  // Encabezado: meses y, según el zoom, días o semanas
  let months = '', cells = '';
  for (let i = 0; i < days;) {
    const d = new Date(min + i * DAY), m = d.getUTCMonth(), y = d.getUTCFullYear();
    let n = 0; while (i + n < days && new Date(min + (i + n) * DAY).getUTCMonth() === m) n++;
    months += `<div style="width:${n * W}px">${n * W > 60 ? MONTHS[m] + ' ' + y : ''}</div>`;
    i += n;
  }
  for (let i = 0; i < days; i++) {
    const d = new Date(min + i * DAY), dow = (d.getUTCDay() + 6) % 7, ms = min + i * DAY;
    const lbl = TK.gz === 'day' ? d.getUTCDate() : TK.gz === 'week' && dow === 0 ? d.getUTCDate() : '';
    cells += `<div class="${dow >= 5 ? 'we' : ''}${ms === today ? ' td' : ''}" style="width:${W}px">${lbl}</div>`;
  }
  const RH = 38;
  const rowIdx = new Map(dated.map((t, i) => [t.id, i]));
  const bars = dated.map((t, i) => {
    const g = ganttSpan(t), st = statusOf(t.status), done = isDone(t.status);
    const late = !done && t.due_date && t.due_date < TK.today;
    const left = (g.s - min) / DAY * W, width = ((g.e - g.s) / DAY + 1) * W;
    return `<div class="tk-gbar${done ? ' done' : ''}${late ? ' late' : ''}${TK.f.arch ? ' ro' : ''}" data-gid="${t.id}" style="--c:${st.color};top:${i * RH + 7}px;left:${left}px;width:${width}px"
        title="${esc(t.title)} · ${esc(st.name)}${t.start_date ? ' · inicio ' + t.start_date.split('-').reverse().join('/') : ''}${t.due_date ? ' · vence ' + t.due_date.split('-').reverse().join('/') : ''}">
      <span class="h l" data-gh="l"></span><span class="tx">${esc(t.title)}</span><span class="h r" data-gh="r"></span></div>`;
  }).join('');
  // Flechas de dependencia: del final de la que bloquea al inicio de la bloqueada
  let arrows = '';
  for (const t of dated) for (const d of t.deps || []) {
    if (!rowIdx.has(d)) continue;
    const a = dated[rowIdx.get(d)], ga = ganttSpan(a), gb = ganttSpan(t);
    const x1 = ((ga.e - min) / DAY + 1) * W, y1 = rowIdx.get(d) * RH + RH / 2, x2 = (gb.s - min) / DAY * W, y2 = rowIdx.get(t.id) * RH + RH / 2;
    const mx = Math.max(x1 + 8, Math.min(x2 - 8, x1 + 8));
    arrows += `<path class="${x2 < x1 ? 'bad' : ''}" d="M${x1} ${y1} H${mx} V${y2} H${x2 - 2}" marker-end="url(#tk-arr)"/>`;
  }
  const h = Math.max(dated.length * RH, 120);
  $('tk-view').innerHTML = `<div class="tk-xbar"><div class="tk-seg" role="group" aria-label="Zoom">
      ${[['day', 'Días'], ['week', 'Semanas'], ['month', 'Meses']].map(([k, n]) => `<button type="button" data-x="gz" data-z="${k}" class="${TK.gz === k ? 'on' : ''}">${n}</button>`).join('')}</div>
      <button class="tk-btn sm" type="button" data-x="gtoday"><i class="fa-solid fa-location-crosshairs"></i> Hoy</button>
      <span class="grow"></span><span class="tk-count">Arrastra una barra para moverla; sus bordes cambian el inicio y la fecha límite.</span></div>
    <div class="tk-gantt">
      <div class="tk-gl"><div class="tk-gh0">Tarea</div>${dated.map(t => `<div class="tk-glr" data-id="${t.id}"><span class="tk-dot" style="--c:${statusOf(t.status).color}"></span><span class="t" data-act="open" role="button" tabindex="0">${esc(t.title)}</span>${t.n_blockers ? '<i class="fa-solid fa-lock" title="Bloqueada"></i>' : ''}</div>`).join('')}</div>
      <div class="tk-gr" id="tk-gr"><div style="width:${days * W}px">
        <div class="tk-gm">${months}</div><div class="tk-gd">${cells}</div>
        <div class="tk-gb" style="height:${h}px">
          <div class="tk-gtoday" style="left:${(today - min) / DAY * W + W / 2}px"></div>
          ${dated.map((t, i) => `<div class="tk-grow" style="top:${i * RH}px"></div>`).join('')}
          <svg class="tk-garr" width="${days * W}" height="${h}" aria-hidden="true"><defs><marker id="tk-arr" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto"><path d="M0 0L8 4L0 8z"/></marker></defs>${arrows}</svg>
          ${bars}
        </div></div></div>
    </div>
    ${dated.length ? '' : '<div class="tk-note" style="padding:14px 4px">Ninguna tarea tiene inicio ni fecha límite todavía. Pon fechas en la ficha o en la Tabla para verlas acá.</div>'}
    ${undated.length ? `<details class="tk-gund"><summary>${undated.length} tarea${undated.length > 1 ? 's' : ''} sin fechas</summary>${undated.map(t => `<div class="tk-glr" data-id="${t.id}"><span class="tk-dot" style="--c:${statusOf(t.status).color}"></span><span class="t" data-act="open" role="button" tabindex="0">${esc(t.title)}</span></div>`).join('')}</details>` : ''}`;
  const gr = $('tk-gr');
  if (TK.gscroll === undefined || TK.gscrollZ !== TK.gz) { gr.scrollLeft = Math.max(0, (today - min) / DAY * W - 140); TK.gscrollZ = TK.gz; }
  else gr.scrollLeft = TK.gscroll;
  gr.addEventListener('scroll', () => { TK.gscroll = gr.scrollLeft; });
}
// Arrastrar barras: mover entera o estirar un borde, de a un día
let GDRAG = null;
document.addEventListener('pointerdown', e => {
  const bar = e.target.closest('.tk-gbar'); if (!bar || bar.classList.contains('ro') || e.button !== 0) return;
  const t = findTask(+bar.dataset.gid); if (!t) return;
  e.preventDefault();
  GDRAG = { bar, t, mode: e.target.dataset.gh || 'm', x0: e.clientX, left: bar.offsetLeft, width: bar.offsetWidth, moved: false };
  try { bar.setPointerCapture(e.pointerId); } catch (err) {}
});
document.addEventListener('pointermove', e => {
  if (!GDRAG) return;
  const W = TK.gW, dd = Math.round((e.clientX - GDRAG.x0) / W);
  if (Math.abs(e.clientX - GDRAG.x0) > 3) GDRAG.moved = true;
  const { bar, mode, left, width } = GDRAG;
  if (mode === 'm') bar.style.left = left + dd * W + 'px';
  else if (mode === 'r') bar.style.width = Math.max(W, width + dd * W) + 'px';
  else { const d = Math.min(dd, Math.round(width / W) - 1); bar.style.left = left + d * W + 'px'; bar.style.width = width - d * W + 'px'; }
  GDRAG.dd = dd;
});
document.addEventListener('pointerup', () => {
  if (!GDRAG) return;
  const { t, mode, moved, bar } = GDRAG; const dd = GDRAG.dd || 0; GDRAG = null;
  if (!moved || !dd) { if (!moved) openTask(t.id); else render(); return; }
  const g = ganttSpan(t), sh = ms => isoOf(ms + dd * DAY);
  const p = {};
  if (mode === 'm') { if (t.start_date) p.start_date = sh(parseD(t.start_date)); if (t.due_date) p.due_date = sh(parseD(t.due_date)); }
  else if (mode === 'r') { const ne = Math.max(g.s, g.e + dd * DAY); p.due_date = isoOf(ne); if (!t.start_date) p.start_date = isoOf(g.s); }
  else { const ns = Math.min(g.e, g.s + dd * DAY); p.start_date = isoOf(ns); if (!t.due_date) p.due_date = isoOf(g.e); }
  bar.style.opacity = '.6';
  patch(t.id, p);
});

// ── FICHA: inicio, estimado, tablero, campos, subtareas y dependencias ──
const _drawProps = drawProps;
drawProps = function () {
  _drawProps();
  const box = $('tk-props'); if (!box) return;
  const t = T();
  const est = t.estimate_min ? +(t.estimate_min / 60).toFixed(2) : '';
  let h = `<div class="k"><i class="fa-solid fa-play"></i>Inicio</div>
    <div class="v"><input class="tk-date" type="date" id="tk-start" value="${esc(t.start_date || '')}" aria-label="Inicio"><span class="tk-note">Para el Gantt (opcional)</span></div>
    <div class="k"><i class="fa-solid fa-hourglass-half"></i>Estimado</div>
    <div class="v"><input class="tk-date" style="width:90px" type="text" inputmode="decimal" id="tk-est" value="${est}" placeholder="0" aria-label="Estimado en horas"><span class="tk-note">horas${t.minutes && t.estimate_min ? ` · cargadas ${fmtMin(t.minutes, true)}${t.minutes > t.estimate_min ? ' <b style="color:#f87171">(se pasó)</b>' : ''}` : ''}</span></div>`;
  if (TK.cur && !TK.cur.parent_id && (TK.boards || []).length > 1 && TK.role !== 'Solicitante')
    h += `<div class="k"><i class="fa-solid fa-table-columns"></i>Tablero</div><div class="v"><select class="tk-fin" id="tk-move" aria-label="Mover a otro tablero">${TK.boards.map(b => `<option value="${b.id}"${b.id === (TK.cur.board_id || TK.board.id) ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></div>`;
  if (TK.cur && !TK.cur.parent_id && ((TK.projects || []).length || TK.cur.project_id)) {
    const pj = (TK.projects || []).find(x => x.id === TK.cur.project_id);
    h += `<div class="k"><i class="fa-solid fa-diagram-project"></i>Proyecto</div><div class="v"><select class="tk-fin wide" id="tk-proj" aria-label="Proyecto"><option value="0">Sin proyecto</option>${(TK.projects || []).map(x => `<option value="${x.id}"${x.id === TK.cur.project_id ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}${TK.cur.project_id && !pj ? `<option value="${TK.cur.project_id}" selected>(proyecto cerrado)</option>` : ''}</select>${TK.cur.project_id ? `<a class="tk-note" href="/toolz/trabajo/#proyectos/${TK.cur.project_id}" title="Abrir el proyecto"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}</div>`;
    if (pj && pj.milestones.length) h += `<div class="k"><i class="fa-solid fa-flag-checkered"></i>Hito</div><div class="v"><select class="tk-fin wide" id="tk-ms" aria-label="Hito"><option value="0">Sin hito</option>${pj.milestones.map(m => `<option value="${m.id}"${m.id === TK.cur.milestone_id ? ' selected' : ''}>${esc(m.name)}${m.done ? ' (cumplido)' : ''}</option>`).join('')}</select></div>`;
  }
  if (TK.cur?.parent) h += `<div class="k"><i class="fa-solid fa-turn-up"></i>Subtarea de</div><div class="v"><button class="tk-pbtn" type="button" data-x="open" data-tid="${TK.cur.parent.id}">${esc(TK.cur.parent.title)}</button></div>`;
  if (TK.cur) for (const f of fieldsOf()) h += `<div class="k"><i class="fa-solid ${{ text: 'fa-font', number: 'fa-hashtag', date: 'fa-calendar-day', select: 'fa-list', check: 'fa-square-check', person: 'fa-user', url: 'fa-link' }[f.type] || 'fa-font'}"></i>${esc(f.name)}</div><div class="v">${fieldInput(f, TK.cur.fv?.[f.id], 'tk-fin wide')}</div>`;
  box.insertAdjacentHTML('beforeend', h);
  $('tk-proj')?.addEventListener('change', e => patch(TK.cur.id, { project_id: +e.target.value || null, milestone_id: null }));
  $('tk-ms')?.addEventListener('change', e => patch(TK.cur.id, { project_id: TK.cur.project_id, milestone_id: +e.target.value || null }));
  $('tk-start').addEventListener('change', e => dset('start_date', e.target.value || null));
  $('tk-est').addEventListener('change', e => { const n = parseFloat(e.target.value.replace(',', '.')); dset('estimate_min', n > 0 ? Math.round(n * 60) : null); });
  $('tk-move')?.addEventListener('change', async e => {
    const to = +e.target.value, b = TK.boards.find(x => x.id === to);
    if (!await ask('Mover de tablero', `"${TK.cur.title}" pasa a ${b.name}${TK.cur.n_sub ? ' con sus subtareas' : ''}. Si ese tablero no tiene el mismo estado, queda en su primer estado. Los campos propios de este tablero no viajan.`, 'Mover')) { drawProps(); return; }
    try { await api('move_board', { id: TK.cur.id, to }); toast('Tarea movida a ' + b.name); TK.tasks = TK.tasks.filter(x => x.id !== TK.cur.id); closeDrawer(true); render(); }
    catch (err) { toast(err.message, true); drawProps(); }
  });
};

const _drawDrawer = drawDrawer;
drawDrawer = function () {
  _drawDrawer();
  if (!TK.cur) return;
  const sec = $('tk-checks')?.closest('.tk-sec'); if (!sec) return;
  sec.insertAdjacentHTML('afterend', `${TK.cur.parent_id ? '' : '<div class="tk-sec"><h4><i class="fa-solid fa-diagram-subtask"></i>Subtareas <span class="n" id="tk-subn"></span></h4><div id="tk-subs"></div></div>'}
    <div class="tk-sec"><h4><i class="fa-solid fa-link-slash"></i>Dependencias <span class="sp"></span><button class="tk-btn sm" type="button" data-x="dep-add"><i class="fa-solid fa-plus"></i> Bloqueada por…</button></h4><div id="tk-deps"></div></div>`);
  drawSubs(); drawDeps();
};
function subRow(s) {
  const st = statusOf(s.status), done = !!s.completed_at;
  return `<div class="tk-sub${done ? ' done' : ''}" data-tid="${s.id}"><button class="tk-ck${done ? ' on' : ''}" type="button" data-x="sub-tick" aria-label="${done ? 'Volver a abrir' : 'Terminar'}"><i class="fa-solid fa-check"></i></button>
    <span class="t" data-x="open" data-tid="${s.id}" role="button" tabindex="0">${esc(s.title)}</span>${s.assignees.length ? avs(s.assignees, 2) : ''}
    ${s.due_date ? `<span class="tk-note">${s.due_date.split('-').reverse().slice(0, 2).join('/')}</span>` : ''}<span class="tk-pill dot" style="--c:${st.color}">${esc(st.name)}</span></div>`;
}
function drawSubs() {
  const box = $('tk-subs'); if (!box) return;
  const subs = TK.cur.subtasks || [];
  $('tk-subn').textContent = subs.length ? `${subs.filter(s => s.completed_at).length}/${subs.length}` : '';
  box.innerHTML = subs.map(subRow).join('') + `<div class="tk-subadd"><input class="tk-fin wide" id="tk-sub-new" placeholder="Nueva subtarea y Enter" maxlength="250" aria-label="Nueva subtarea"></div>`;
  $('tk-sub-new').addEventListener('keydown', async e => {
    if (e.key !== 'Enter' || !e.target.value.trim()) return;
    e.preventDefault();
    const title = e.target.value.trim(); e.target.disabled = true;
    try { await api('save', { title, parent_id: TK.cur.id, priority: TK.cur.priority, assignees: TK.cur.assignees }); await refreshCur(); setTimeout(() => $('tk-sub-new')?.focus(), 30); }
    catch (err) { toast(err.message, true); e.target.disabled = false; }
  });
}
function depRow(d, kind) {
  const done = !!d.completed_at;
  const other = d.board_id !== TK.board.id ? ` <span class="tk-note">· ${esc(d.board_name || '')}</span>` : '';
  return `<div class="tk-sub${done ? ' done' : ''}" data-tid="${d.id}"><i class="fa-solid ${done ? 'fa-circle-check' : kind === 'b' ? 'fa-lock' : 'fa-hourglass'}" style="color:${done ? '#22c55e' : kind === 'b' ? '#f87171' : 'var(--g7-text-4)'};width:20px;text-align:center"></i>
    <span class="t" data-x="open" data-tid="${d.id}" data-board="${d.board_id}" role="button" tabindex="0">${esc(d.title)}${other}</span>
    ${kind === 'b' ? `<button class="tk-icon" type="button" data-x="dep-del" aria-label="Quitar dependencia" title="Quitar dependencia"><i class="fa-solid fa-xmark"></i></button>` : ''}</div>`;
}
function drawDeps() {
  const box = $('tk-deps'); if (!box) return;
  const b = TK.cur.blockers || [], k = TK.cur.blocking || [];
  const open = b.filter(x => !x.completed_at).length;
  box.innerHTML = (b.length ? `<div class="tk-note" style="margin-bottom:4px">${open ? `<b style="color:#f87171">Bloqueada:</b> no se puede terminar hasta que terminen ${open === 1 ? 'esta' : 'estas'}` : 'Depende de (ya terminadas)'}</div>${b.map(x => depRow(x, 'b')).join('')}` : '')
    + (k.length ? `<div class="tk-note" style="margin:10px 0 4px">Esperan a esta tarea</div>${k.map(x => depRow(x, 'k')).join('')}` : '')
    || '<div class="tk-note">Sin dependencias. Usa "Bloqueada por…" si esta tarea no puede terminarse antes que otra.</div>';
}
async function refreshCur() {
  const r = await api('get', { id: TK.cur.id });
  TK.cur = r.task; upsert(r.task);
  drawProps(); drawSubs(); drawDeps(); drawActivity(); render();
  const l = await api('list', { archived: TK.f.arch }); if (TK.f.arch) TK.arch = l.tasks; else TK.tasks = l.tasks; render();
}
function depPicker(anchor) {
  openPop(anchor, `<input class="f" type="search" id="tk-dep-q" placeholder="Buscar tarea…" aria-label="Buscar tarea" autocomplete="off"><div class="ls" id="tk-dep-l"><div class="tk-note" style="padding:10px">Buscando…</div></div>`);
  const load = async q => {
    try {
      const r = await api('dep_search', { task_id: TK.cur.id, q });
      const have = new Set((TK.cur.blockers || []).map(x => x.id));
      $('tk-dep-l').innerHTML = r.items.filter(x => !have.has(x.id) && x.id !== TK.cur.parent_id).map(x => `<button type="button" class="tk-opt" data-dep="${x.id}"><span class="lbl">${esc(x.title)}${x.board_id !== TK.board.id ? ` <small style="color:var(--g7-text-4)">· ${esc(x.board_name || '')}</small>` : ''}</span>${x.completed_at ? '<i class="fa-solid fa-check" style="color:#22c55e"></i>' : ''}</button>`).join('') || '<div class="tk-note" style="padding:10px">No hay tareas con ese nombre.</div>';
    } catch (e) { $('tk-dep-l').innerHTML = `<div class="tk-note" style="padding:10px">${esc(e.message)}</div>`; }
  };
  load('');
  let qt; $('tk-dep-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => load(e.target.value), 200); });
  $('tk-dep-q').focus();
  $('tk-dep-l').addEventListener('click', async e => {
    const b = e.target.closest('[data-dep]'); if (!b) return;
    closePop();
    try { const r = await api('dep_add', { task_id: TK.cur.id, depends_on: +b.dataset.dep }); TK.cur = r.task; upsert(r.task); drawDeps(); drawActivity(); toast('Dependencia agregada'); reload(); }
    catch (err) { toast(err.message, true); }
  });
}

// ── Eventos nuevos ───────────────────────────────────────────
function bootPhase2() {
  // Vistas nuevas
  const seg = $('tk-views');
  seg.insertAdjacentHTML('beforeend', '<button type="button" data-view="table"><i class="fa-solid fa-table-cells"></i> Tabla</button><button type="button" data-view="gantt"><i class="fa-solid fa-bars-staggered"></i> Gantt</button>');
  seg.querySelectorAll('[data-view="table"],[data-view="gantt"]').forEach(b => b.addEventListener('click', () => { TK.view = b.dataset.view; closePop(); saveState(); render(); }));
  const box = $('tk-view');
  box.addEventListener('click', e => {
    const x = e.target.closest('[data-x]'), s = e.target.closest('[data-tsort]');
    if (s) { const k = s.dataset.tsort; TK.tsort = TK.tsort.key === k ? (TK.tsort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 }) : { key: k, dir: 1 }; render(); return; }
    if (!x) return;
    if (x.dataset.x === 'csv') exportCsv();
    else if (x.dataset.x === 'gz') { TK.gz = x.dataset.z; TK.gscroll = undefined; render(); }
    else if (x.dataset.x === 'gtoday') { TK.gscroll = undefined; TK.gscrollZ = null; render(); }
  });
  box.addEventListener('change', e => {
    const el = e.target, row = el.closest('[data-id]'); if (!row) return;
    const id = +row.dataset.id;
    if (el.dataset.fid) { saveField(id, +el.dataset.fid, el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value); return; }
    const f = el.dataset.tf; if (!f) return;
    if (f === 'estimate_min') { const n = parseFloat(el.value.replace(',', '.')); patch(id, { estimate_min: n > 0 ? Math.round(n * 60) : null }); }
    else patch(id, { [f]: el.value || null });
  });
  // El "seleccionar todas" de la Tabla está en el encabezado, que no tiene data-id
  box.addEventListener('change', e => { if (e.target.matches('[data-tselall]') && !e.target.closest('[data-id]')) { visible().forEach(t => e.target.checked ? TK.sel.add(t.id) : TK.sel.delete(t.id)); render(); } });
  // Ficha
  // Versión nueva de un adjunto
  D().addEventListener('click', e => {
    const b = e.target.closest('[data-d="file-ver"]'); if (!b || !TK.cur) return;
    const fid = +b.closest('[data-f]').dataset.f, f = (TK.cur.files || []).find(x => x.id === fid); if (!f) return;
    const inp = document.createElement('input'); inp.type = 'file';
    inp.onchange = () => { if (inp.files[0]) uploadFiles([inp.files[0]], f.version_of || f.id); };
    inp.click();
  });
  D().addEventListener('change', e => { if (e.target.dataset.fid && TK.cur) saveField(TK.cur.id, +e.target.dataset.fid, e.target.type === 'checkbox' ? (e.target.checked ? '1' : '') : e.target.value); });
  D().addEventListener('click', async e => {
    const x = e.target.closest('[data-x]'); if (!x || !TK.cur) return;
    const tid = +(x.dataset.tid || x.closest('[data-tid]')?.dataset.tid || 0);
    if (x.dataset.x === 'open') {
      const bd = +(x.dataset.board || 0);
      if (bd && bd !== TK.board.id) { location.href = `/toolz/tareas.php?b=${bd}&t=${tid}`; return; }
      openTask(tid);
    } else if (x.dataset.x === 'dep-add') depPicker(x);
    else if (x.dataset.x === 'dep-del') {
      try { const r = await api('dep_del', { task_id: TK.cur.id, depends_on: tid }); TK.cur = r.task; upsert(r.task); drawDeps(); drawActivity(); reload(); } catch (err) { toast(err.message, true); }
    } else if (x.dataset.x === 'sub-tick') {
      const s = TK.cur.subtasks.find(v => v.id === tid); if (!s) return;
      const st = TK.cfg.statuses.find(v => !!v.done === !s.completed_at);
      if (!st) return;
      try { await api('save', { id: tid, status: st.id }); await refreshCur(); } catch (err) { toast(err.message, true); }
    }
  });
}
bootPhase2();
