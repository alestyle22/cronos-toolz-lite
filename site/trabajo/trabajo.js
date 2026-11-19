/* trabajo.js — Gestión de trabajo (trabajo/index.php). Habla con trabajo/api.php; tildar una tarea va por tareas_api.php.
   Secciones por la dirección: #resumen, #mis-tareas (#mis-tareas/ID = de otra persona), #tableros, #config. */
'use strict';

const W = { me: null, admin: false, teamView: false, users: [], teams: [], cfg: null, roles: {}, vis: {}, general: 0, today: '', boards: null };
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MON = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const COLORS = ['#ff6600', '#ef4444', '#f59e0b', '#22c55e', '#14b8a6', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#8b8b94'];
const ICONS = ['fa-table-columns', 'fa-briefcase', 'fa-bullhorn', 'fa-palette', 'fa-code', 'fa-headset', 'fa-chart-line', 'fa-camera', 'fa-pen-nib', 'fa-users', 'fa-cart-shopping', 'fa-gear'];

async function api(action, data = {}, url = 'api.php') {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) });
  let j;
  try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió algo inesperado. Prueba de nuevo.'); }
  if (r.status === 401) { location.href = '/toolz/login.php'; throw new Error(j.error); }
  if (!r.ok || j.error) { const e = new Error(j.error || 'No se pudo completar la acción'); e.data = j; e.status = r.status; throw e; }   // e.data: detalle (errores por campo, alternativas…)
  return j;
}
let toastT;
function toast(msg, err = false) {
  const t = $('wk-toast'); t.textContent = msg; t.classList.toggle('err', err); t.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), err ? 4200 : 2400);
}

// ── Piezas ───────────────────────────────────────────────────
const userOf = id => W.users.find(u => u.id === id);
/** Estado de una tarea: se busca en los estados de su tablero (cada uno puede tener los suyos). */
function statusOf(id, board) {
  const own = board && W.boardStatuses?.[board];
  return (own && own.find(s => s.id === id)) || W.cfg.statuses.find(s => s.id === id)
    || Object.values(W.boardStatuses || {}).flat().find(s => s.id === id) || { id, name: id, color: '#8b8b94' };
}
const prioOf = id => W.cfg.priorities.find(p => p.id === id) || { id, name: id, color: '#8b8b94' };
const taskUrl = (t, board) => `/toolz/tareas.php?b=${+(board || t.board_id || W.general)}&t=${+(t.id || t.task_id)}`;
// Avatar que no carga (antes onerror en línea; CSP estricta): pasa a la inicial sobre naranja, del mismo tamaño.
// El evento error no sube: se escucha en captura.
document.addEventListener('error', e => {
  const t = e.target;
  if (!t || t.tagName !== 'IMG' || !t.hasAttribute('data-wk-ini')) return;
  t.replaceWith(Object.assign(document.createElement('span'), { className: 'wk-av', textContent: t.getAttribute('data-wk-ini'), style: `width:${t.style.width};height:${t.style.height};background:#ff8a3d` }));
}, true);
function av(u, size = 26) {
  if (!u) return `<span class="wk-av" style="width:${size}px;height:${size}px;background:var(--g7-bg-5)"></span>`;
  if (u.avatar) return `<img class="wk-av" data-g7-user="${+u.id || ''}" style="width:${size}px;height:${size}px" src="${esc(u.avatar)}" alt="" data-wk-ini="${esc((u.name || '?')[0].toUpperCase())}">`;
  const hue = (u.id * 47) % 360;
  return `<span class="wk-av" data-g7-user="${+u.id || ''}" style="width:${size}px;height:${size}px;background:hsl(${hue} 70% 65%)">${esc((u.name || '?')[0].toUpperCase())}</span>`;
}
const dayMs = s => { const [y, m, d] = s.slice(0, 10).split('-').map(Number); return Date.UTC(y, m - 1, d); };
function dueHtml(d, done = false) {
  if (!d) return '<span class="wk-due none">Sin fecha</span>';
  const diff = Math.round((dayMs(d) - dayMs(W.today)) / 864e5);
  const txt = diff === 0 ? 'Hoy' : diff === 1 ? 'Mañana' : diff === -1 ? 'Ayer' : `${+d.slice(8, 10)} ${MON[+d.slice(5, 7) - 1]}`;
  const cls = done ? '' : diff < 0 ? 'late' : diff <= 1 ? 'soon' : '';
  return `<span class="wk-due ${cls}"><i class="fa-regular fa-calendar"></i>${txt}</span>`;
}
function ago(dt) {
  const s = (Date.now() - (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T'))).getTime()) / 1000;
  if (s < 60) return 'recién';
  if (s < 3600) return `hace ${Math.floor(s / 60)} min`;
  if (s < 86400) return `hace ${Math.floor(s / 3600)} h`;
  if (s < 86400 * 7) return `hace ${Math.floor(s / 86400)} d`;
  return `${+dt.slice(8, 10)} ${MON[+dt.slice(5, 7) - 1]}`;
}
const fmtMin = m => !m ? '0 h' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ' ' + (m % 60) + ' min' : ''}`;
const pct = (a, b) => b ? Math.round(a * 100 / b) : 0;
const ACT = {
  created: d => 'creó la tarea' + (d ? ' ' + d : ''), status: d => `cambió el estado: ${d}`, priority: d => `cambió la prioridad: ${d}`,
  due: d => `cambió la fecha: ${d}`, title: () => 'cambió el título', description: () => 'editó la descripción', assignees: d => d,
  check: d => `tildó el paso "${d}"`, archived: () => 'archivó la tarea', unarchived: () => 'sacó la tarea del archivo', content: d => d,
  files: d => d, repeat: d => `repetición: ${d}`, repeat_next: d => `creó la próxima vuelta (${d})`, client: d => `cambió el cliente: ${d}`,
};
const emptyBox = (icon, txt) => `<div class="wk-empty"><i class="fa-solid ${icon}"></i>${txt}</div>`;

// Tooltip de los gráficos: cada barra trae su texto en data-tip
let TIP = null;
document.addEventListener('pointerover', e => {
  const el = e.target.closest('[data-tip]');
  if (!el) { TIP?.remove(); TIP = null; return; }
  if (!TIP) { TIP = document.createElement('div'); TIP.className = 'wk-tip'; document.body.appendChild(TIP); }
  TIP.innerHTML = el.dataset.tip;
});
document.addEventListener('pointermove', e => { if (TIP) { TIP.style.left = Math.min(e.clientX + 14, innerWidth - TIP.offsetWidth - 8) + 'px'; TIP.style.top = (e.clientY + 14) + 'px'; } });

// ── Navegación ───────────────────────────────────────────────
const SECS = { resumen: renderSummary, 'mis-tareas': renderMine, 'mis-solicitudes': renderMyRequests, calendario: renderCalendar, proyectos: renderProjects, solicitudes: renderRequests, tickets: renderTickets, procesos: renderProcesses, informes: renderReports, tableros: renderBoards, config: renderConfig };
function route() {
  const [sec, arg, arg2] = (location.hash.slice(1) || 'resumen').split('/');
  if (sec === 'tableros' && arg === 'campos' && +arg2) { history.replaceState(null, '', '#tableros'); setTimeout(() => boardDialog(+arg2, 'fields'), 400); }
  const fn = SECS[sec] && !$(`wk-nav`).querySelector(`[data-sec="${sec}"]`).hidden ? SECS[sec] : renderSummary;
  const cur = SECS[sec] === fn ? sec : 'resumen';
  document.querySelectorAll('#wk-nav a').forEach(a => { const on = a.dataset.sec === cur; a.classList.toggle('on', on); on ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'); });
  $('sm-tip')?.classList.remove('on');   // tooltip del Resumen que quedó visible
  $('wk-main').dataset.sec = cur;   // color propio de cada sección (trabajo.css, "Trabajo v2")
  $('wk-main').innerHTML = '<div class="wk-sk"></div><div class="wk-sk"></div><div class="wk-sk"></div>';
  fn(arg === 'campos' ? '' : arg, arg2).catch(e => { $('wk-main').innerHTML = emptyBox('fa-triangle-exclamation', `<b>No se pudo cargar</b><br>${esc(e.message)}`); });
}

// ── RESUMEN ──────────────────────────────────────────────────
async function renderSummary() {
  const r = await api('summary');
  const m = r.mine, me = userOf(W.me.id), T = r.team;
  W.smPer = W.smPer || 'days';
  // Lo tuyo: franja compacta (con equipo) o las tarjetas grandes (sin equipo)
  const mineChip = (c, icon, label, n, go) => `<button type="button" class="sm-me" style="--c:${c}" data-go="${go}"><i class="fa-solid ${icon}"></i><b>${n}</b><span>${label}</span></button>`;
  const mineStrip = `<div class="sm-mine"><span class="sm-mine-h"><i class="fa-solid fa-user"></i> Lo tuyo</span>
      ${mineChip('#ef4444', 'fa-circle-exclamation', 'vencidas', m.late || 0, 'mis-tareas')}${mineChip('#f59e0b', 'fa-sun', 'vencen hoy', m.today || 0, 'mis-tareas')}
      ${mineChip('#06b6d4', 'fa-calendar-week', 'próximos 7 días', m.week || 0, 'mis-tareas')}${mineChip('#3b82f6', 'fa-list', 'abiertas', m.open || 0, 'mis-tareas')}
      ${mineChip('#22c55e', 'fa-circle-check', 'terminadas en 7 días', m.done7 || 0, 'mis-tareas')}${mineChip('#8b5cf6', 'fa-clock', 'esta semana', fmtMin(m.minutes_week), 'mis-tareas')}</div>`;
  let h = `<div class="wk-h"><div><h1>Hola, ${esc((me?.name || '').split(' ')[0])}<span>.</span></h1>
      <p>${T ? (T.scope === 'all' ? 'Cómo viene todo el equipo' : 'Cómo viene tu departamento') + ' y lo tuyo.' : 'Lo tuyo primero.'} Todos los números salen de las tareas reales.</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="#mis-tareas"><i class="fa-solid fa-user-check"></i> Mis tareas</a>${W.canBoards ? `<a class="wk-btn primary" href="/toolz/tareas.php?b=${W.general}"><i class="fa-solid fa-plus"></i> Nueva tarea</a>` : ''}</div></div>`;
  if (T) h += mineStrip + smTeamHtml(T, r);
  else h += `<div class="sm-kpis">
      ${smKpi('Vencidas', m.late || 0, m.late ? 'Ponte al día' : 'Nada atrasado', null, '#ef4444', 'fa-circle-exclamation', 'mis-tareas', m.late > 0)}
      ${smKpi('Vencen hoy', m.today || 0, 'Para hoy', null, '#f59e0b', 'fa-sun', 'mis-tareas')}
      ${smKpi('Abiertas', m.open || 0, `${m.week || 0} vencen en 7 días · ${m.nodate || 0} sin fecha`, null, '#3b82f6', 'fa-list', 'mis-tareas')}
      ${smKpi('Terminadas', m.done7 || 0, `Últimos 7 días · ${fmtMin(m.minutes_week)} esta semana`, null, '#22c55e', 'fa-circle-check', 'mis-tareas')}</div>
    <div class="sm-grid sm-2" style="margin-top:16px">${smNextCard(r, m)}${smActCard(r)}</div>`;
  if (T) h += `<div class="sm-grid sm-2" style="margin-top:16px">${smActCard(r)}${smLoadCard(T)}</div>`;
  if (r.boards.length && W.canBoards) h += `<div class="wk-sec"><h2>Tableros</h2><span class="sp"></span><a class="wk-link" href="#tableros">Ver todos</a></div><div class="wk-boards">${r.boards.slice(0, 4).map(boardCard).join('')}</div>`;
  $('wk-main').innerHTML = h;
  const M = $('wk-main');
  M.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => { location.hash = b.dataset.go; }));
  M.querySelectorAll('tr[data-user],[data-user-go]').forEach(tr => tr.addEventListener('click', () => { location.hash = 'mis-tareas/' + (tr.dataset.user || tr.dataset.userGo); }));
  M.querySelectorAll('[data-per]').forEach(s => s.addEventListener('change', () => { W.smPer = s.value; renderSummary(); }));
  smBindTips(M);
  setBadge(m.late + m.today);
}

// ── Piezas del Resumen (estilos sm-* en trabajo.css) ──
const smNum = n => (+n || 0).toLocaleString('es-AR');
const smHrs = h => h === null || h === undefined ? '—' : h < 48 ? h + ' h' : Math.round(h / 24) + ' días';
/** Variación contra el período anterior. $good = si subir es bueno (creadas: neutro, terminadas: bueno, tiempo: malo). */
function smDelta(cur, prev, good = 1) {
  if (prev === null || prev === undefined || cur === null || cur === undefined) return '';
  if (!prev) return cur ? `<span class="sm-delta" style="--c:#64748b">nuevo</span>` : '';
  const p = Math.round((cur - prev) / prev * 1000) / 10, up = p > 0;
  const c = !p || !good ? '#64748b' : (up ? good > 0 : good < 0) ? '#22c55e' : '#ef4444';
  return `<span class="sm-delta" style="--c:${c}">${up ? '+' : ''}${String(p).replace('.', ',')}%<i class="fa-solid fa-caret-${up ? 'up' : p < 0 ? 'down' : 'right'}"></i></span>`;
}
function smKpi(label, val, sub, delta, c, icon, go = '', alert = false) {
  return `<${go ? `button type="button" data-go="${go}"` : 'div'} class="sm-kpi${alert ? ' alert' : ''}" style="--c:${c}"><div class="h"><span>${label}</span><i class="fa-solid ${icon}"></i></div>
    <div class="v"><b>${val}</b>${delta || ''}</div><small>${sub}</small></${go ? 'button' : 'div'}>`;
}
/** Selector de período (Días / Semanas / Meses), igual en los dos gráficos. */
const smPerSel = () => `<select class="wk-in sm-sel" data-per aria-label="Período">${[['days', 'Últimos 14 días'], ['weeks', 'Últimas 8 semanas'], ['months', 'Últimos 12 meses']].map(([k, n]) => `<option value="${k}"${W.smPer === k ? ' selected' : ''}>${n}</option>`).join('')}</select>`;
function smSeries(T) {
  if (W.smPer === 'months') return T.months.map(x => ({ label: MON[+x.month.slice(5, 7) - 1], tip: MON[+x.month.slice(5, 7) - 1] + ' ' + x.month.slice(0, 4), created: x.created, done: x.done }));
  if (W.smPer === 'weeks') return T.weeks.map(x => ({ label: +x.week.slice(8, 10) + ' ' + MON[+x.week.slice(5, 7) - 1], tip: 'Semana del ' + +x.week.slice(8, 10) + ' ' + MON[+x.week.slice(5, 7) - 1], created: x.created, done: x.done }));
  return T.days.map(x => ({ label: +x.day.slice(8, 10) + '', tip: +x.day.slice(8, 10) + ' ' + MON[+x.day.slice(5, 7) - 1], created: x.created, done: x.done }));
}
/** Escala "linda" para los ejes: 1, 2 o 5 por potencia de 10. */
function smNice(max) { if (max <= 4) return 4; const p = 10 ** Math.floor(Math.log10(max)), n = max / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p; }
/** Área suave (curva por los puntos) con tooltip por punto. */
function smArea(pts) {
  const W0 = 640, H0 = 230, top = 14, bot = 8, n = pts.length, max = smNice(Math.max(1, ...pts.map(p => p.v)));
  const X = i => n === 1 ? W0 / 2 : i * W0 / (n - 1), Y = v => H0 - bot - v / max * (H0 - top - bot);
  const P = pts.map((p, i) => [X(i), Y(p.v)]);
  let d = `M${P[0][0]},${P[0][1]}`;
  for (let i = 0; i < P.length - 1; i++) {
    const p0 = P[i - 1] || P[i], p1 = P[i], p2 = P[i + 1], p3 = P[i + 2] || p2;
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6], c2 = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
    d += ` C${c1[0].toFixed(1)},${Math.min(H0 - bot, c1[1]).toFixed(1)} ${c2[0].toFixed(1)},${Math.min(H0 - bot, c2[1]).toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  const hits = pts.map((p, i) => `<rect x="${(X(i) - W0 / n / 2).toFixed(1)}" y="0" width="${(W0 / n).toFixed(1)}" height="${H0}" fill="transparent" data-tip="${esc(p.tip)}<br><b>${p.v}</b> ${p.v === 1 ? 'terminada' : 'terminadas'}" data-x="${(X(i) / W0 * 100).toFixed(2)}" data-y="${(Y(p.v) / H0 * 100).toFixed(2)}"></rect>`).join('');
  const grid = [0.25, 0.5, 0.75].map(f => `<line x1="0" x2="${W0}" y1="${(top + f * (H0 - top - bot)).toFixed(1)}" y2="${(top + f * (H0 - top - bot)).toFixed(1)}" class="g"></line>`).join('');
  return `<div class="sm-area"><svg viewBox="0 0 ${W0} ${H0}" preserveAspectRatio="none" role="img" aria-label="Tareas terminadas por período">
      <defs><linearGradient id="smFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--sm-a);stop-opacity:.45"></stop><stop offset="1" style="stop-color:var(--sm-a);stop-opacity:.04"></stop></linearGradient></defs>
      ${grid}<path class="fill" d="${d} L${W0},${H0} L0,${H0} Z"></path><path class="ln" d="${d}" vector-effect="non-scaling-stroke"></path>${hits}</svg>
    <span class="sm-dot"></span><div class="sm-axis">${pts.map((p, i) => `<span${n > 8 && i % 2 ? ' class="odd"' : ''}>${esc(p.label)}</span>`).join('')}</div></div>`;
}
/** Medidor por segmentos (semicírculo) con un arco por prioridad. */
function smGauge(rows, total) {
  const cx = 120, cy = 118, R = 92, sw = 26, gap = .045, live = rows.filter(x => x.n > 0);
  const pt = a => `${(cx + R * Math.cos(a)).toFixed(2)},${(cy + R * Math.sin(a)).toFixed(2)}`;
  let a = Math.PI, arcs = '';
  const span = Math.PI - gap * Math.max(0, live.length - 1);
  live.forEach((x, i) => { const a1 = a + span * x.n / total;
    arcs += `<path d="M${pt(a)} A${R},${R} 0 0 1 ${pt(a1)}" stroke="${esc(x.color)}" stroke-width="${sw}" stroke-linecap="butt" fill="none" pathLength="100" style="animation-delay:${i * .12}s" data-tip="${esc(x.name)}: <b>${x.n}</b> (${pct(x.n, total)}%)"></path>`; a = a1 + gap; });
  return `<div class="sm-gauge"><svg viewBox="0 0 240 140" role="img" aria-label="Abiertas por prioridad">
      <path d="M${pt(Math.PI)} A${R},${R} 0 0 1 ${pt(2 * Math.PI)}" class="trk" stroke-width="${sw}" fill="none" stroke-linecap="butt"></path>${arcs}</svg>
    <div class="c"><b>${smNum(total)}</b><span>abiertas</span></div></div>
    <div class="sm-legend">${rows.map(x => `<span><i style="background:${esc(x.color)}"></i>${esc(x.name)} <b>${x.n}</b></span>`).join('')}</div>`;
}
/** Torta con el porcentaje en cada porción. */
function smPie(rows, total) {
  const cx = 110, cy = 110, R = 100; let a = -Math.PI / 2, out = '';
  const live = rows.filter(x => x.n > 0);
  live.forEach((x, i) => {
    const f = x.n / total, a1 = a + f * 2 * Math.PI, mid = (a + a1) / 2;
    const p = t => `${(cx + R * Math.cos(t)).toFixed(2)},${(cy + R * Math.sin(t)).toFixed(2)}`;
    const path = live.length === 1 ? `M${cx},${cy - R} A${R},${R} 0 1 1 ${cx - .01},${cy - R} Z` : `M${cx},${cy} L${p(a)} A${R},${R} 0 ${f > .5 ? 1 : 0} 1 ${p(a1)} Z`;
    out += `<path d="${path}" fill="${esc(x.color)}" style="animation-delay:${i * .08}s" data-tip="${esc(x.name)}: <b>${x.n}</b> (${pct(x.n, total)}%)"></path>`;
    if (f >= .06) out += `<text x="${(cx + R * .64 * Math.cos(mid)).toFixed(1)}" y="${(cy + R * .64 * Math.sin(mid)).toFixed(1)}">${String(Math.round(f * 1000) / 10).replace('.', ',')}%</text>`;
    a = a1;
  });
  return `<div class="sm-pie"><svg viewBox="0 0 220 220" role="img" aria-label="Abiertas por estado">${out}</svg></div>
    <div class="sm-legend center">${rows.map(x => `<span><i style="background:${esc(x.color)}"></i>${esc(x.name)}</span>`).join('')}</div>`;
}
/** Barras apiladas: terminadas (abajo, color fuerte) y el resto de lo creado (arriba, claro). */
function smBars(pts) {
  const max = smNice(Math.max(1, ...pts.map(p => Math.max(p.created, p.done))));
  const ticks = [1, .75, .5, .25, 0].map(f => `<span>${smNum(Math.round(max * f))}</span>`).join('');
  return `<div class="sm-bars"><div class="y">${ticks}</div><div class="plot">${[1, .75, .5, .25, 0].map(f => `<i class="gl" style="bottom:${f * 100}%"></i>`).join('')}
    ${pts.map((p, i) => { const top = Math.max(p.created, p.done);
      return `<div class="col" data-tip="${esc(p.tip)}<br>Creadas: <b>${p.created}</b> · Terminadas: <b>${p.done}</b>"><div class="stk" style="height:${top / max * 100}%;animation-delay:${i * .03}s"><span class="a" style="flex:${Math.max(0, top - p.done)}"></span><span class="b" style="flex:${p.done}"></span></div><small${pts.length > 8 && i % 2 ? ' class="odd"' : ''}>${esc(p.label)}</small></div>`; }).join('')}</div></div>`;
}
function smNextCard(r, m) {
  const when = d => { if (!d) return ['Sin', 'fecha', '']; const late = d < W.today, today = d === W.today; return [String(+d.slice(8, 10)).padStart(2, '0'), MON[+d.slice(5, 7) - 1], late ? 'late' : today ? 'today' : '']; };
  return `<div class="sm-card"><div class="sm-ch"><h3>Lo próximo</h3><a class="wk-btn sm" href="#mis-tareas">Ver todo</a></div>
    ${r.next.length ? `<div class="sm-recent">${r.next.map((t, i) => { const [d1, d2, cls] = when(t.due_date), s = statusOf(t.status, t.board_id);
      return `<a class="sm-rec" href="${taskUrl(t)}" style="--c:${esc(prioOf(t.priority).color)};--i:${i}" title="Prioridad ${esc(prioOf(t.priority).name)}"><span class="d ${cls}"><b>${d1}</b><small>${d2}</small></span><span class="bar"></span>
        <span class="x"><b>${esc(t.title)}</b><small>${esc(t.board_name || 'General')}</small></span>${tkChip(s.color, esc(s.name))}<i class="fa-solid fa-arrow-right go"></i></a>`; }).join('')}</div>`
      : emptyBox('fa-mug-hot', 'No tienes tareas abiertas asignadas.')}
    ${m.delegated ? `<div class="wk-help" style="margin-top:10px">Además creaste ${m.delegated} tarea${m.delegated > 1 ? 's' : ''} abierta${m.delegated > 1 ? 's' : ''} que tiene${m.delegated > 1 ? 'n' : ''} otra gente.</div>` : ''}</div>`;
}
function smActCard(r) {
  return `<div class="sm-card"><div class="sm-ch"><h3>Novedades en tus tareas</h3></div>
    ${r.activity.length ? `<div class="sm-acts">${r.activity.map(a => { const u = a.user_id ? userOf(a.user_id) : null;
      return `<a class="wk-act" href="${taskUrl(a)}">${u ? av(u) : '<span class="wk-av" style="background:var(--g7-bg-5);color:var(--g7-text-3)"><i class="fa-solid fa-robot"></i></span>'}
        <span class="x"><b>${esc(u ? u.name : 'Automático')}</b> <span>${esc((ACT[a.action] || (d => d || a.action))(a.detail))}</span> · <b class="tt">${esc(a.title)}</b><small>${ago(a.created_at)}</small></span></a>`; }).join('')}</div>`
      : emptyBox('fa-inbox', 'Cuando alguien cambie o comente una tarea tuya, aparece acá.')}</div>`;
}
function smLoadCard(T) {
  const hot = W.cfg.priorities[W.cfg.priorities.length - 1], maxOpen = Math.max(1, ...T.load.map(l => l.open));
  return `<div class="sm-card"><div class="sm-ch"><h3>Carga por persona</h3><small>Toca una fila para ver sus tareas</small></div>
    ${T.load.length ? `<div class="wk-wrap"><table class="wk-t"><thead><tr><th>Persona</th><th class="num">Abiertas</th><th class="num">Vencidas</th><th class="num">${esc(hot.name)}</th><th class="num">Horas 30 d</th></tr></thead><tbody>
      ${T.load.map(l => { const u = userOf(l.user_id); return `<tr data-user="${l.user_id}"><td><span class="who">${av(u)}${esc(u?.name || '#' + l.user_id)}</span></td>
        <td class="num">${l.open}<span class="wk-load"><i style="width:${l.open / maxOpen * 100}%"></i></span></td><td class="num ${l.late ? 'late' : 'muted'}">${l.late}</td>
        <td class="num ${l.hot ? '' : 'muted'}">${l.hot}</td><td class="num muted">${fmtMin(l.minutes30)}</td></tr>`; }).join('')}
    </tbody></table></div>` : emptyBox('fa-user-clock', 'Todavía nadie tiene tareas asignadas.')}</div>`;
}
function smTeamHtml(T, r) {
  const X = T.totals, C = T.cmp || {}, S = smSeries(T);
  const doneSum = S.reduce((a, p) => a + p.done, 0), createdSum = S.reduce((a, p) => a + p.created, 0);
  const perName = { days: 'en 14 días', weeks: 'en 8 semanas', months: 'en 12 meses' }[W.smPer];
  const pr = W.cfg.priorities.map(p => ({ name: p.name, color: p.color, n: T.by_prio[p.id] || 0 }));
  const st = W.cfg.statuses.filter(s => !s.done).map(s => ({ name: s.name, color: s.color, n: T.by_status[s.id] || 0 }));
  for (const [id, n] of Object.entries(T.by_status)) if (!W.cfg.statuses.some(s => s.id === id)) { const s = statusOf(id); st.push({ name: s.name, color: s.color, n }); }
  const stTot = st.reduce((a, x) => a + x.n, 0);
  const ontime = X.withdue30 ? pct(X.ontime30, X.withdue30) : null;
  // Cumplimiento: cuatro números con ícono y barra
  const ful = (label, val, of, c, icon, note) => { const p = of ? pct(val, of) : 0;
    return `<div class="sm-ful" style="--c:${c}"><span class="ic"><i class="fa-solid ${icon}"></i></span><div><small>${label}</small><b>${of ? p + '%' : '—'}</b><span class="track"><i style="width:${p}%"></i></span><em>${note}</em></div></div>`; };
  const top = T.load.filter(l => l.done30 > 0).sort((a, b) => b.done30 - a.done30).slice(0, 5);
  return `<div class="sm-kpis">
      ${smKpi('Tareas creadas', smNum(C.new30 ?? X.new30), 'Últimos 30 días, contra los 30 anteriores', smDelta(C.new30, C.new_prev, 0), '#3b82f6', 'fa-square-plus')}
      ${smKpi('Abiertas', smNum(X.open), X.late ? `${smNum(X.late)} vencidas · ${smNum(X.unassigned)} sin responsable` : `Nada vencido · ${smNum(X.unassigned)} sin responsable`, '', X.late ? '#ef4444' : '#f59e0b', 'fa-folder-open', '', X.late > 0)}
      ${smKpi('Terminadas', smNum(C.done30 ?? X.done30), 'Últimos 30 días, contra los 30 anteriores', smDelta(C.done30, C.done_prev, 1), '#22c55e', 'fa-circle-check')}
      ${smKpi('Tiempo de cierre', smHrs(C.lead30), 'Promedio de 30 días, contra los 30 anteriores', smDelta(C.lead30, C.lead_prev, -1), '#8b5cf6', 'fa-stopwatch')}</div>
    <div class="sm-grid sm-main" style="margin-top:16px">
      <div class="sm-card"><div class="sm-ch"><h3>Tareas terminadas</h3>${smPerSel()}</div>
        <div class="sm-big"><b>${smNum(doneSum)}</b><span>${perName} · tiempo de cierre promedio ${smHrs(X.lead_h)}</span></div>${smArea(S.map(p => ({ v: p.done, label: p.label, tip: p.tip })))}</div>
      <div class="sm-card"><div class="sm-ch"><h3>Abiertas por prioridad</h3></div>${X.open ? smGauge(pr, X.open) : emptyBox('fa-check', 'No hay tareas abiertas.')}</div>
    </div>
    <div class="sm-grid sm-3" style="margin-top:16px">
      <div class="sm-card"><div class="sm-ch"><h3>Cumplimiento</h3><small>últimos 30 días</small></div><div class="sm-fuls">
        ${ful('A tiempo', X.ontime30, X.withdue30, '#22c55e', 'fa-thumbs-up', X.withdue30 ? `${X.ontime30} de ${X.withdue30} con fecha` : 'Sin terminadas con fecha')}
        ${ful('Vencidas', X.late, X.open, '#ef4444', 'fa-fire', 'de lo abierto')}
        ${ful('Sin responsable', X.unassigned, X.open, '#f59e0b', 'fa-user-slash', 'de lo abierto')}
        ${ful('Sin fecha', C.nodate || 0, X.open, '#06b6d4', 'fa-calendar-xmark', 'de lo abierto')}</div></div>
      <div class="sm-card"><div class="sm-ch"><h3>Terminadas por persona</h3><small>30 días</small></div>
        ${top.length ? `<div class="sm-people">${top.map(l => { const u = userOf(l.user_id); return `<button type="button" class="sm-person" data-user-go="${l.user_id}">${av(u, 40)}<span class="x"><b>${esc(u?.name || '#' + l.user_id)}</b><small>${l.open} abierta${l.open === 1 ? '' : 's'}${l.late ? ` · <em>${l.late} vencida${l.late === 1 ? '' : 's'}</em>` : ''}</small></span><span class="n"><b>${l.done30}</b><small>terminada${l.done30 === 1 ? '' : 's'}</small></span></button>`; }).join('')}</div>`
          : emptyBox('fa-trophy', 'Nadie terminó tareas en los últimos 30 días.')}</div>
      ${smNextCard(r, r.mine)}
    </div>
    <div class="sm-grid sm-side" style="margin-top:16px">
      <div class="sm-card"><div class="sm-ch"><h3>Abiertas por estado</h3></div>${stTot ? smPie(st, stTot) : emptyBox('fa-check', 'No hay tareas abiertas.')}</div>
      <div class="sm-card"><div class="sm-ch"><h3>Creadas y terminadas</h3>${smPerSel()}</div>
        <div class="sm-split"><div class="sm-avg"><div><span><i style="background:var(--sm-b2)"></i>Creadas</span><b>${smNum(createdSum)}</b><small>${perName}</small></div>
          <div><span><i style="background:var(--sm-b1)"></i>Terminadas</span><b>${smNum(doneSum)}</b><small>${createdSum ? pct(doneSum, createdSum) + '% de lo creado' : perName}</small></div></div>${smBars(S)}</div></div>
    </div>`;
}
/** Tooltip único para los gráficos del Resumen (elementos con data-tip). */
function smBindTips(M) {
  let tip = $('sm-tip'); tip?.classList.remove('on');
  if (!tip) { tip = document.createElement('div'); tip.id = 'sm-tip'; tip.className = 'sm-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
  M.querySelectorAll('.sm-card [data-tip]').forEach(el => {
    el.addEventListener('mouseenter', () => { tip.innerHTML = el.dataset.tip; tip.classList.add('on');
      const area = el.closest('.sm-area'), dot = area?.querySelector('.sm-dot');
      if (dot && el.dataset.x) { dot.style.left = el.dataset.x + '%'; dot.style.top = el.dataset.y + '%'; dot.classList.add('on'); } });
    el.addEventListener('mousemove', e => { tip.style.left = Math.min(innerWidth - tip.offsetWidth - 8, e.clientX + 14) + 'px'; tip.style.top = (e.clientY - tip.offsetHeight - 12) + 'px'; });
    el.addEventListener('mouseleave', () => { tip.classList.remove('on'); el.closest('.sm-area')?.querySelector('.sm-dot')?.classList.remove('on'); });
  });
}

// ── MIS TAREAS ───────────────────────────────────────────────
async function renderMine(arg) {
  const who = +arg || 0;
  const r = await api('mine', { user: who });
  const u = userOf(r.user), own = r.user === W.me.id;
  const in7 = dayMs(W.today) + 7 * 864e5;
  const G = [
    ['late', 'Vencidas', t => !t.completed_at && t.due_date && dayMs(t.due_date) < dayMs(W.today)],
    ['today', 'Hoy', t => !t.completed_at && t.due_date === W.today],
    ['week', 'Próximos 7 días', t => !t.completed_at && t.due_date && dayMs(t.due_date) > dayMs(W.today) && dayMs(t.due_date) <= in7],
    ['later', 'Más adelante', t => !t.completed_at && t.due_date && dayMs(t.due_date) > in7],
    ['none', 'Sin fecha', t => !t.completed_at && !t.due_date],
    ['done', 'Terminadas en los últimos 7 días', t => !!t.completed_at],
  ];
  const canTick = own;
  const row = t => `<a class="wk-row${t.completed_at ? ' done' : ''}" href="${t.ticket_id ? '#tickets/' + t.ticket_id : taskUrl(t)}" data-id="${t.id}" data-board="${t.board_id}">
      ${canTick && t.done_status ? `<button class="wk-chk${t.completed_at ? ' on' : ''}" type="button" data-tick="${t.id}" title="${t.completed_at ? 'Volver a abrir' : 'Marcar como terminada'}" aria-label="${t.completed_at ? 'Volver a abrir' : 'Marcar como terminada'}"><i class="fa-solid fa-check"></i></button>` : `<span class="wk-dot" style="--c:${esc(prioOf(t.priority).color)}"></span>`}
      <span class="t"><b>${esc(t.title)}</b><span>${t.ticket_id ? `<span title="Es la tarea de un ticket: abre el ticket"><i class="fa-solid fa-ticket"></i> ${tkCode(t.ticket_id)}</span>` : ''}<span class="wk-bchip"><span class="wk-dot" style="--c:${esc(t.board_color || '#888')}"></span>${esc(t.board_name || 'General')}</span>
        ${t.parent_title ? `<span title="Subtarea de"><i class="fa-solid fa-turn-up fa-rotate-90"></i> ${esc(t.parent_title)}</span>` : ''}${t.client_name ? `<span><i class="fa-solid fa-briefcase"></i> ${esc(t.client_name)}</span>` : ''}${t.n_checks ? `<span><i class="fa-regular fa-square-check"></i> ${t.n_checks_done}/${t.n_checks}</span>` : ''}
        <span style="color:${esc(prioOf(t.priority).color)}"><i class="fa-solid fa-flag"></i> ${esc(prioOf(t.priority).name)}</span></span></span>
      <span class="wk-pill" style="--c:${esc(statusOf(t.status, t.board_id).color)}">${esc(statusOf(t.status, t.board_id).name)}</span>${dueHtml(t.due_date, !!t.completed_at)}</a>`;
  let body = '';
  for (const [k, name, f] of G) {
    const list = r.tasks.filter(f);
    if (!list.length) continue;
    body += `<div class="wk-group ${k === 'late' ? 'late' : ''}">${name} <span class="n">${list.length}</span></div><div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${list.map(row).join('')}</div></div>`;
  }
  const people = W.teamView ? W.users.filter(x => x.id !== W.me.id) : [];
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>${own ? 'Mis tareas' : 'Tareas de ' + esc(u?.name || '')}<span>.</span></h1>
      <p>${own ? 'Lo que tienes asignado en todos los tableros, ordenado por fecha límite. Tilda para terminar.' : 'Lo que tiene asignado en los tableros que puedes ver.'}</p></div>
      <div class="wk-h-a">${people.length ? `<select class="wk-in" id="wk-who" style="width:auto;height:36px" aria-label="Ver las tareas de"><option value="">Mis tareas</option>${people.map(p => `<option value="${p.id}"${p.id === r.user ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>` : ''}</div></div>
    ${body || emptyBox('fa-mug-hot', own ? 'No tienes tareas asignadas. Cuando te asignen una, aparece acá.' : 'No tiene tareas asignadas.')}`;
  $('wk-who')?.addEventListener('change', e => { location.hash = e.target.value ? 'mis-tareas/' + e.target.value : 'mis-tareas'; });
  if (own) setBadge(r.tasks.filter(t => !t.completed_at && t.due_date && dayMs(t.due_date) <= dayMs(W.today)).length);
  $('wk-main').querySelectorAll('[data-tick]').forEach(b => b.addEventListener('click', async e => {
    e.preventDefault(); e.stopPropagation();
    const t = r.tasks.find(x => x.id === +b.dataset.tick);
    b.disabled = true;
    try {
      await api('save', { id: t.id, board: t.board_id, status: t.completed_at ? t.open_status : t.done_status }, '/toolz/tareas_api.php');
      toast(t.completed_at ? 'La tarea volvió a estar abierta' : 'Tarea terminada');
      renderMine(arg);
    } catch (err) { b.disabled = false; toast(err.message, true); }
  }));
}
// ── Mis solicitudes: lo que pidió esta persona (todos sus tickets), con lo importante de cada uno ──
function setReqBadge(n) { const b = $('wk-n-myreq'); if (!b) return; b.hidden = !n; b.textContent = n; b.title = `${n} esperan tu respuesta`; }
const MR = { status: 'abiertos', q: '' };
async function renderMyRequests() {
  const r = await api('my_requests', { status: MR.status, q: MR.q });
  W.tkStatus = { ...(W.tkStatus || {}), ...r.status };
  const S = r.summary || {}; setReqBadge(S.waiting || 0);
  const hrs = m => m == null ? '—' : m < 60 ? m + ' min' : m < 2880 ? Math.round(m / 60) + ' h' : Math.round(m / 1440) + ' días';
  const tabs = [['abiertos', 'Abiertas', S.open], ['en_curso', 'En curso', S.progress], ['esperando', 'Esperan tu respuesta', S.waiting], ['vencidos', 'Atrasadas', S.late], ['resueltos', 'Resueltas', null], ['sin_calificar', 'Sin calificar', S.unrated], ['todos', 'Todas', S.total]];
  const who = t => t.assignee_id ? `Lo atiende <b>${esc(userOf(t.assignee_id)?.name || 'alguien del equipo')}</b>` : '<span class="mr-un">Todavía sin asignar</span>';
  const card = (t, i) => {
    const s = tkSt(t.status), p = prioOf(t.priority), done = ['resuelto', 'cerrado'].includes(t.status);
    const lastBy = t.last ? (t.last.user_id === W.me.id ? 'Tú' : (userOf(t.last.user_id)?.name || 'El equipo')) : '';
    return `<a class="mr-card${t.status === 'esperando' ? ' wait' : ''}${t.late ? ' late' : ''}" href="#tickets/${t.id}" style="--c:${esc(p.color)};--i:${Math.min(i, 15)}">
      <div class="mr-top"><span class="tk-code">${tkCode(t.id)}</span>${t.form_name ? `<span class="mr-form" style="--fc:${esc(t.form_color || '#888')}"><i class="fa-solid ${esc(t.form_icon || 'fa-inbox')}"></i>${esc(t.form_name)}</span>` : ''}
        <span class="sp"></span><span class="wk-pill" style="--c:${s.color}">${esc(s.name)}</span></div>
      <b class="mr-subj">${esc(t.subject)}</b>
      <div class="mr-meta"><span title="Prioridad"><i class="fa-solid fa-flag" style="color:${esc(p.color)}"></i>${esc(p.name)}</span>
        ${t.cat_name ? `<span><i class="fa-solid fa-tag" style="color:${esc(t.cat_color || '#888')}"></i>${esc(t.cat_name)}</span>` : ''}
        ${t.team_name ? `<span><i class="fa-solid fa-people-group"></i>${esc(t.team_name)}</span>` : ''}
        <span><i class="fa-solid fa-user"></i>${who(t)}</span>
        <span title="${esc(t.created_at)}"><i class="fa-regular fa-calendar"></i>Pedido ${ago(t.created_at)}</span>
        ${done ? `<span><i class="fa-solid fa-check"></i>Resuelto ${ago(t.resolved_at || t.closed_at || t.updated_at)}</span>` : slaChip(t.resolution_due, t.resolved_at, 'Plazo', r.now, t.paused)}
        ${t.escalated ? '<span class="mr-esc"><i class="fa-solid fa-arrow-up-wide-short"></i>Escalado</span>' : ''}</div>
      ${t.status === 'esperando' ? '<div class="mr-alert"><i class="fa-solid fa-reply"></i>El equipo espera tu respuesta para seguir.</div>' : ''}
      ${t.status === 'resuelto' ? `<div class="mr-act"><span><i class="fa-solid fa-box-open"></i>Te lo entregaron: revísalo.</span><button class="tk-b success" type="button" data-ev="approve" data-id="${t.id}"><i class="fa-solid fa-check-double"></i> Aprobar y cerrar</button><button class="tk-b" type="button" data-ev="changes" data-id="${t.id}">Pedir cambios</button></div>`
        : t.status === 'cerrado' && t.origin !== 'tarea' && window.G7Rate ? `<div class="mr-act">${t.rating ? `<button class="ev-show" type="button" data-ev="see" data-id="${t.id}">${G7Rate.stars(t.rating)}<span>Tu calificación</span></button>` : `<span><i class="fa-solid fa-star"></i>¿Cómo te fue?</span><button class="tk-b success" type="button" data-ev="rate" data-id="${t.id}"><i class="fa-solid fa-star"></i> Calificar</button>`}</div>` : ''}
      ${t.last ? `<div class="mr-last${t.answered && !done ? ' new' : ''}"><i class="fa-regular fa-comment"></i><span><b>${esc(lastBy)}</b> · ${ago(t.last.at)}${t.msgs > 1 ? ` · ${t.msgs} mensajes` : ''}<br>${esc(t.last.text)}</span></div>`
        : `<div class="mr-last"><i class="fa-regular fa-comment"></i><span>${t.first_response_at ? 'Sin mensajes.' : 'Todavía nadie respondió.'}</span></div>`}
    </a>`;
  };
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Mis solicitudes<span>.</span></h1><p>Todo lo que pediste: en qué estado está, quién lo atiende, cuándo vence y la última respuesta.</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="#solicitudes"><i class="fa-solid fa-inbox"></i> Pedir con un formulario</a><a class="wk-btn primary" href="#tickets"><i class="fa-solid fa-plus"></i> Nuevo ticket</a></div></div>
    <div class="mr-kpis">
      <div class="mr-kpi"><small>Abiertas</small><b>${S.open || 0}</b></div>
      <div class="mr-kpi${S.waiting ? ' warn' : ''}"><small>Esperan tu respuesta</small><b>${S.waiting || 0}</b></div>
      <div class="mr-kpi${S.late ? ' bad' : ''}"><small>Atrasadas</small><b>${S.late || 0}</b></div>
      <div class="mr-kpi"><small>Resueltas en 30 días</small><b>${S.solved30 || 0}</b></div>
      <div class="mr-kpi"><small>Tiempo de resolución</small><b>${hrs(S.res_min)}</b><span>promedio, últimos 90 días</span></div></div>
    <div class="mr-bar"><div class="wk-tabs">${tabs.map(([k, l, n]) => `<a href="#mis-solicitudes" data-mr="${k}" class="${MR.status === k ? 'on' : ''}">${l}${n ? ` <small>${n}</small>` : ''}</a>`).join('')}</div>
      <input class="wk-in" id="mr-q" type="search" placeholder="Buscar por número o texto" value="${esc(MR.q)}" aria-label="Buscar en mis solicitudes"></div>
    ${r.tickets.length ? `<div class="mr-list">${r.tickets.map(card).join('')}</div>`
      : emptyBox('fa-paper-plane', MR.q ? 'Nada coincide con esa búsqueda.' : MR.status === 'abiertos' ? 'No tienes nada pendiente. Cuando pidas algo (un ticket o un formulario) lo vas a ver acá.' : MR.status === 'sin_calificar' ? '¡Al día! Calificaste todos tus pedidos cerrados.' : MR.status === 'en_curso' ? 'Nadie está trabajando todavía en lo que pediste.' : 'No hay solicitudes en este grupo.')}`;
  const M = $('wk-main');
  M.querySelectorAll('[data-mr]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); MR.status = a.dataset.mr; renderMyRequests(); }));
  let tq; $('mr-q').addEventListener('input', e => { clearTimeout(tq); tq = setTimeout(() => { MR.q = e.target.value; renderMyRequests().then(() => { const q = $('mr-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 350); });
}
function setBadge(n) { const b = $('wk-n-mine'); b.hidden = !n; b.textContent = n; b.title = `${n} vencidas o para hoy`; }

// ── TABLEROS ─────────────────────────────────────────────────
function boardCard(b) {
  const team = b.team_name ? `<span class="wk-dot" style="--c:${esc(b.team_color || '#888')}"></span>${esc(b.team_name)}` : 'Sin departamento';
  const vis = { public: 'fa-globe', team: 'fa-people-group', members: 'fa-lock' }[b.visibility] || 'fa-globe';
  return `<a class="wk-board" style="--c:${esc(b.color)}" href="/toolz/tareas.php?b=${b.id}">
      <div class="top"><span class="ic"><i class="fa-solid ${esc(b.icon)}"></i></span><span class="nm"><b>${esc(b.name)}</b><span>${team} · <i class="fa-solid ${vis}" title="${esc(W.vis[b.visibility] || '')}"></i></span></span></div>
      <p>${esc(b.description)}</p>
      <div class="st"><span><b>${b.open}</b> abiertas</span><span class="${b.late ? 'late' : ''}"><b>${b.late}</b> vencidas</span><span><b>${b.done30}</b> terminadas 30 d</span></div>
      ${b.can_manage ? `<button class="ed" type="button" data-edit="${b.id}" title="Editar tablero" aria-label="Editar tablero ${esc(b.name)}"><i class="fa-solid fa-pen"></i></button>` : ''}</a>`;
}
async function renderBoards(arg) {
  const arch = arg === 'archivados';
  const r = await api('boards', { archived: arch });
  W.boards = r.boards;
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Tableros<span>.</span></h1><p>Cada tablero tiene sus tareas y su gente. Lo ven todos, solo su departamento o solo sus miembros.</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="#tableros${arch ? '' : '/archivados'}"><i class="fa-solid ${arch ? 'fa-arrow-left' : 'fa-box-archive'}"></i> ${arch ? 'Volver' : 'Archivados'}</a>
      ${arch ? '' : '<button class="wk-btn primary" type="button" id="wk-new-board"><i class="fa-solid fa-plus"></i> Nuevo tablero</button>'}</div></div>
    ${r.boards.length ? `<div class="wk-boards">${r.boards.map(boardCard).join('')}</div>` : emptyBox('fa-table-columns', arch ? 'No hay tableros archivados.' : 'No ves ningún tablero todavía.')}`;
  $('wk-new-board')?.addEventListener('click', () => boardDialog());
}
document.addEventListener('click', e => {
  const b = e.target.closest('[data-edit]');
  if (!b) return;
  e.preventDefault(); e.stopPropagation();
  boardDialog(+b.dataset.edit);
});

const DLG = () => $('wk-dlg');
function openDlg(html) { const d = DLG(); d.innerHTML = `<div class="wk-dlg-in">${html}</div>`; if (!d.open) d.showModal(); return d; }
function closeDlg() { if (DLG().open) DLG().close(); }

async function boardDialog(id = 0, tab = 'general') {
  let b = { name: '', description: '', color: COLORS[0], icon: ICONS[0], team_id: W.me.lead[0] || W.me.teams[0] || null, visibility: 'public', members: [], can_manage: true, is_default: 0 };
  let globalSt = W.cfg.statuses;
  if (id) { try { const g = await api('board_get', { id }); b = g.board; globalSt = g.global_statuses; } catch (e) { toast(e.message, true); return; } }
  const teams = W.teams.filter(t => W.me.role === 'admin' || W.me.role === 'director' || W.me.teams.includes(t.id) || t.id === b.team_id);
  const d = openDlg(`<h3>${id ? 'Editar tablero' : 'Nuevo tablero'}</h3>
    ${id && b.can_manage ? `<div class="wk-tabs"><button type="button" data-tab="general" class="on">General</button><button type="button" data-tab="statuses">Estados</button><button type="button" data-tab="fields">Campos</button></div>` : ''}
    <div data-pane="statuses" hidden></div><div data-pane="fields" hidden></div>
    <div data-pane="general">
    <label class="wk-f"><span>Nombre</span><input class="wk-in" id="bd-name" maxlength="80" value="${esc(b.name)}" placeholder="Ej.: Diseño, Redes, Soporte…"></label>
    <label class="wk-f"><span>Descripción</span><textarea class="wk-in" id="bd-desc" maxlength="500" rows="2" placeholder="Para qué es este tablero (opcional)">${esc(b.description)}</textarea></label>
    <div class="wk-fr">
      <label class="wk-f"><span>Departamento</span><select class="wk-in" id="bd-team"><option value="0">Sin departamento</option>${teams.map(t => `<option value="${t.id}"${t.id === b.team_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Quién lo ve</span><select class="wk-in" id="bd-vis"${b.is_default ? ' disabled' : ''}>${Object.entries(W.vis).map(([k, v]) => `<option value="${k}"${k === b.visibility ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
    </div>
    <div class="wk-f"><span>Color</span><div class="wk-colors" id="bd-colors">${COLORS.map(c => `<button type="button" style="--c:${c}" data-c="${c}" class="${c === b.color ? 'on' : ''}" aria-label="Color ${c}"></button>`).join('')}</div></div>
    <div class="wk-f"><span>Ícono</span><div class="wk-icons" id="bd-icons">${ICONS.map(i => `<button type="button" data-i="${i}" class="${i === b.icon ? 'on' : ''}" aria-label="${i}"><i class="fa-solid ${i}"></i></button>`).join('')}</div></div>
    ${id ? `<div class="wk-f"><span>Miembros</span><div id="bd-mem"></div>
      <div style="display:flex;gap:8px;margin-top:8px"><select class="wk-in" id="bd-add"><option value="">Sumar a alguien…</option>${W.users.filter(u => !b.members.some(m => m.user_id === u.id)).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select></div>
      <div class="wk-help">El <b>Responsable</b> administra el tablero (nombre, miembros y archivo). Los miembros lo ven aunque sea privado.</div></div>` : ''}
    </div>
    <div class="wk-err" id="bd-err"></div>
    <div class="acts" id="bd-acts">${id && !b.is_default ? '<button class="wk-btn danger" type="button" id="bd-arch"><i class="fa-solid fa-box-archive"></i> ' + (b.archived ? 'Restaurar' : 'Archivar') + '</button>' + (b.archived ? '<button class="wk-btn danger" type="button" id="bd-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : '') : ''}<span class="sp"></span>
      <button class="wk-btn" type="button" id="bd-cancel">Cancelar</button><button class="wk-btn primary" type="button" id="bd-ok">${id ? 'Guardar' : 'Crear tablero'}</button></div>`);
  let color = b.color, icon = b.icon;
  if (id && b.can_manage) {
    // Estados propios o los generales
    const sp = d.querySelector('[data-pane="statuses"]'); const stList = JSON.parse(JSON.stringify(b.statuses || globalSt));
    sp.innerHTML = b.is_default ? '<div class="wk-help">El tablero General usa los estados generales. Se cambian en el tablero, botón <b>Configurar</b> (administradores).</div>'
      : `<label class="wk-chkl" style="margin-bottom:10px"><input type="checkbox" id="st-own"${b.statuses ? ' checked' : ''}> Este tablero tiene sus propios estados</label>
        <div id="st-list"${b.statuses ? '' : ' hidden'}></div><div class="wk-help" id="st-gen"${b.statuses ? ' hidden' : ''}>Usa los estados generales: ${globalSt.map(s => esc(s.name)).join(' · ')}</div>
        <div class="wk-help">Si quitas un estado, sus tareas pasan al primero de la lista. Los estados marcados como "Terminado" cuentan como tarea terminada.</div>
        <div style="display:flex;justify-content:flex-end;margin-top:10px"><button class="wk-btn primary sm" type="button" id="st-save">Guardar estados</button></div>`;
    if (!b.is_default) {
      statusEditor(sp.querySelector('#st-list'), stList);
      sp.querySelector('#st-own').addEventListener('change', e => { sp.querySelector('#st-list').hidden = !e.target.checked; sp.querySelector('#st-gen').hidden = e.target.checked; });
      sp.querySelector('#st-save').addEventListener('click', async () => {
        try { await api('board_statuses', { id, own: sp.querySelector('#st-own').checked, statuses: stList }); W.boardStatuses[id] = sp.querySelector('#st-own').checked ? stList : globalSt; toast('Estados guardados'); }
        catch (e) { toast(e.message, true); }
      });
    }
    fieldEditor(d.querySelector('[data-pane="fields"]'), id, b.fields || []);
    const show = t => { d.querySelectorAll('[data-tab]').forEach(x => x.classList.toggle('on', x.dataset.tab === t)); d.querySelectorAll('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== t; }); d.querySelector('#bd-acts').hidden = t !== 'general'; };
    d.querySelectorAll('[data-tab]').forEach(x => x.addEventListener('click', () => show(x.dataset.tab)));
    show(tab);
  }
  d.querySelector('#bd-colors').addEventListener('click', e => { const x = e.target.closest('[data-c]'); if (!x) return; color = x.dataset.c; d.querySelectorAll('#bd-colors button').forEach(y => y.classList.toggle('on', y === x)); });
  d.querySelector('#bd-icons').addEventListener('click', e => { const x = e.target.closest('[data-i]'); if (!x) return; icon = x.dataset.i; d.querySelectorAll('#bd-icons button').forEach(y => y.classList.toggle('on', y === x)); });
  d.querySelector('#bd-cancel').addEventListener('click', closeDlg);
  const err = m => { d.querySelector('#bd-err').textContent = m; };
  const drawMem = mem => {
    b.members = mem;
    d.querySelector('#bd-mem').innerHTML = mem.length ? mem.map(m => { const u = userOf(m.user_id); return `<div class="wk-mem">${av(u)}<span class="n">${esc(u?.name || '#' + m.user_id)}</span>
      <select data-role="${m.user_id}" aria-label="Rol en el tablero"><option value="member"${m.role === 'member' ? ' selected' : ''}>Miembro</option><option value="manager"${m.role === 'manager' ? ' selected' : ''}>Responsable</option></select>
      <button class="x" type="button" data-rm="${m.user_id}" title="Sacar del tablero" aria-label="Sacar del tablero"><i class="fa-solid fa-xmark"></i></button></div>`; }).join('') : '<div class="wk-help">Todavía no tiene miembros.</div>';
  };
  const member = async (user_id, op) => { try { drawMem((await api('board_member', { board_id: id, user_id, op })).members); } catch (e) { err(e.message); } };
  if (id) {
    drawMem(b.members);
    d.querySelector('#bd-mem').addEventListener('change', e => { const s = e.target.closest('[data-role]'); if (s) member(+s.dataset.role, s.value); });
    d.querySelector('#bd-mem').addEventListener('click', e => { const x = e.target.closest('[data-rm]'); if (x) member(+x.dataset.rm, 'remove'); });
    d.querySelector('#bd-add').addEventListener('change', e => { if (!e.target.value) return; member(+e.target.value, 'add'); e.target.querySelector(`option[value="${e.target.value}"]`).remove(); e.target.value = ''; });
  }
  d.querySelector('#bd-ok').addEventListener('click', async () => {
    try {
      const r = await api('board_save', { id, name: d.querySelector('#bd-name').value, description: d.querySelector('#bd-desc').value, team_id: +d.querySelector('#bd-team').value,
        visibility: d.querySelector('#bd-vis').value, color, icon });
      closeDlg(); toast(id ? 'Tablero guardado' : 'Tablero creado');
      if (!id) location.href = '/toolz/tareas.php?b=' + r.id; else route();
    } catch (e) { err(e.message); }
  });
  d.querySelector('#bd-arch')?.addEventListener('click', async () => {
    try { await api('board_archive', { id, on: !b.archived }); closeDlg(); toast(b.archived ? 'Tablero restaurado' : 'Tablero archivado: sus tareas siguen guardadas'); route(); } catch (e) { err(e.message); }
  });
  d.querySelector('#bd-del')?.addEventListener('click', async () => {
    if (!confirm('¿Borrar este tablero? Solo se puede si no tiene tareas.')) return;
    try { await api('board_delete', { id }); closeDlg(); toast('Tablero borrado'); route(); } catch (e) { err(e.message); }
  });
  if (tab === 'general') d.querySelector('#bd-name').focus();
}
// ── Estados y campos de un tablero (fase 2) ──────────────────
function statusEditor(box, list) {
  // Lista editable: nombre, color, "terminado", ordenar y quitar
  const draw = () => {
    box.innerHTML = list.map((s, i) => `<div class="wk-st" data-i="${i}"><input type="color" value="${esc(s.color)}" data-k="color" aria-label="Color">
      <input class="wk-in" value="${esc(s.name)}" maxlength="40" data-k="name" aria-label="Nombre del estado">
      <label class="wk-chkl" title="Las tareas en este estado cuentan como terminadas"><input type="checkbox" data-k="done"${s.done ? ' checked' : ''}> Terminado</label>
      <button type="button" class="x" data-mv="-1" aria-label="Subir"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button>
      <button type="button" class="x" data-mv="1" aria-label="Bajar"${i < list.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-arrow-down"></i></button>
      <button type="button" class="x" data-rm aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('')
      + '<button class="wk-btn sm" type="button" data-addst><i class="fa-solid fa-plus"></i> Agregar estado</button>';
  };
  box.addEventListener('input', e => { const r = e.target.closest('[data-i]'); if (!r) return; const s = list[+r.dataset.i], k = e.target.dataset.k; s[k] = k === 'done' ? e.target.checked : e.target.value; });
  box.addEventListener('change', e => { const r = e.target.closest('[data-i]'); if (r && e.target.dataset.k === 'done') list[+r.dataset.i].done = e.target.checked; });
  box.addEventListener('click', e => {
    const r = e.target.closest('[data-i]');
    if (e.target.closest('[data-addst]')) { list.push({ id: '', name: 'Nuevo estado', color: COLORS[list.length % COLORS.length], done: false }); draw(); box.querySelector(`[data-i="${list.length - 1}"] [data-k="name"]`).select(); return; }
    if (!r) return;
    const i = +r.dataset.i, mv = e.target.closest('[data-mv]');
    if (mv) { const j = i + +mv.dataset.mv; [list[i], list[j]] = [list[j], list[i]]; draw(); }
    else if (e.target.closest('[data-rm]')) { if (list.length > 1) { list.splice(i, 1); draw(); } else toast('Tiene que quedar al menos un estado', true); }
  });
  draw();
}
function fieldEditor(box, boardId, fields) {
  const types = W.fieldTypes || {};
  const draw = () => {
    box.innerHTML = (fields.length ? fields.map((f, i) => `<div class="wk-fld" data-fid="${f.id}"><i class="fa-solid fa-grip-vertical" style="color:var(--g7-text-4)"></i>
        <span class="n"><b>${esc(f.name)}</b><small>${esc(types[f.type] || f.type)}${f.type === 'select' ? ': ' + esc(f.options.map(o => o.name).join(', ')) : ''}</small></span>
        <button type="button" class="x" data-up="${i}" aria-label="Subir"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button>
        <button type="button" class="x" data-ed="${f.id}" aria-label="Editar"><i class="fa-solid fa-pen"></i></button>
        <button type="button" class="x" data-del="${f.id}" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button></div>`).join('') : '<div class="wk-help">Este tablero no tiene campos propios todavía.</div>')
      + `<div class="wk-fform" id="wk-fform"></div><button class="wk-btn sm" type="button" data-newf><i class="fa-solid fa-plus"></i> Agregar campo</button>`;
  };
  const form = (f = { id: 0, name: '', type: 'text', options: [] }) => {
    const ff = box.querySelector('#wk-fform');
    ff.innerHTML = `<div class="wk-fr"><label class="wk-f"><span>Nombre del campo</span><input class="wk-in" id="ff-name" maxlength="60" value="${esc(f.name)}" placeholder="Ej.: Canal, Presupuesto, Link de entrega"></label>
      <label class="wk-f"><span>Tipo</span><select class="wk-in" id="ff-type">${Object.entries(types).map(([k, v]) => `<option value="${k}"${k === f.type ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label></div>
      <label class="wk-f" id="ff-opts-w"${f.type === 'select' ? '' : ' hidden'}><span>Opciones (una por línea)</span><textarea class="wk-in" id="ff-opts" rows="4">${esc(f.options.map(o => o.name).join('\n'))}</textarea></label>
      ${f.id ? '<div class="wk-help">Si cambias el tipo, se borran los valores que ya tenía cargados.</div>' : ''}
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px"><button class="wk-btn sm" type="button" data-fcancel>Cancelar</button><button class="wk-btn sm primary" type="button" data-fsave="${f.id}">${f.id ? 'Guardar campo' : 'Agregar'}</button></div>`;
    ff.querySelector('#ff-type').addEventListener('change', e => { ff.querySelector('#ff-opts-w').hidden = e.target.value !== 'select'; });
    ff.querySelector('#ff-name').focus();
    ff._orig = f;
  };
  const save = async r => { fields = r.fields; draw(); };
  box.addEventListener('click', async e => {
    try {
      if (e.target.closest('[data-newf]')) return form();
      if (e.target.closest('[data-fcancel]')) { box.querySelector('#wk-fform').innerHTML = ''; return; }
      const ed = e.target.closest('[data-ed]'); if (ed) return form(fields.find(f => f.id === +ed.dataset.ed));
      const del = e.target.closest('[data-del]');
      if (del) { const f = fields.find(x => x.id === +del.dataset.del); if (!confirm(`¿Borrar el campo "${f.name}"? Se pierden sus valores en todas las tareas.`)) return; return save(await api('field_del', { board_id: boardId, id: f.id })); }
      const up = e.target.closest('[data-up]');
      if (up) { const i = +up.dataset.up; const ids = fields.map(f => f.id); [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]]; return save(await api('field_order', { board_id: boardId, ids })); }
      const sv = e.target.closest('[data-fsave]');
      if (sv) {
        const ff = box.querySelector('#wk-fform'), orig = ff._orig || { options: [] };
        const names = ff.querySelector('#ff-opts').value.split('\n').map(x => x.trim()).filter(Boolean);
        const options = names.map(n => ({ id: (orig.options.find(o => o.name === n) || {}).id || '', name: n }));
        return save(await api('field_save', { board_id: boardId, id: +sv.dataset.fsave, name: ff.querySelector('#ff-name').value, type: ff.querySelector('#ff-type').value, options }));
      }
    } catch (err) { toast(err.message, true); }
  });
  draw();
}

// ── INFORMES (fase 7) ────────────────────────────────────────
W.rep = { from: '', to: '', team: '', board: '' };
function repTable(id, title, icon, cols, rows, note = '') {
  // cols: [titulo, fn(row) => texto, num?]
  return `<div class="wk-card wk-rep" id="rep-${id}"><h3><i class="fa-solid ${icon}"></i>${title}<span class="sp"></span>${rows.length ? `<button class="wk-btn sm wk-noprint" type="button" data-csv="${id}"><i class="fa-solid fa-file-csv"></i> CSV</button>` : ''}</h3>
    ${rows.length ? `<div class="wk-wrap"><table class="wk-t" style="cursor:default"><thead><tr>${cols.map(c => `<th${c[2] ? ' class="num"' : ''}>${esc(c[0])}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(r => `<tr style="cursor:default">${cols.map(c => `<td${c[2] ? ' class="num"' : ''}>${c[3] ? c[3](r) : esc(c[1](r))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : `<div class="wk-help">${note || 'Sin datos en este período.'}</div>`}</div>`;
}
async function renderReports() {
  const R = W.rep;
  if (!R.from) { const d = new Date(dayMs(W.today)); R.from = iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1))); R.to = W.today; }
  if (!W.boards) try { W.boards = (await api('boards')).boards; } catch (e) { W.boards = []; }
  const r = await api('reports', { from: R.from, to: R.to, team: +R.team || 0, board: +R.board || 0 });
  const h = m => m === null || m === undefined ? '—' : m < 60 ? m + ' min' : m < 1440 ? (m / 60).toFixed(1).replace('.0', '') + ' h' : (m / 1440).toFixed(1).replace('.0', '') + ' d';
  const hh = x => x === null ? '—' : x < 48 ? x + ' h' : Math.round(x / 24) + ' d';
  const P = r.project_status || {};
  const sections = {
    people: ['Por persona', 'fa-users', [['Persona', x => userOf(x.user_id)?.name || '#' + x.user_id], ['Terminadas', x => x.done, 1], ['A tiempo', x => x.done_due ? pct(x.ontime, x.done_due) + '%' : '—', 1], ['Tiempo de cierre', x => hh(x.lead_h), 1], ['Abiertas hoy', x => x.open, 1], ['Vencidas hoy', x => x.late, 1], ['Horas', x => fmtMin(x.minutes), 1]], r.people],
    boards: ['Por tablero', 'fa-table-columns', [['Tablero', x => x.name], ['Creadas', x => x.created, 1], ['Terminadas', x => x.done, 1], ['Abiertas hoy', x => x.open, 1], ['Vencidas hoy', x => x.late, 1], ['Tiempo de cierre', x => hh(x.lead_h), 1]], r.boards],
    projects: ['Proyectos', 'fa-diagram-project', [['Proyecto', x => x.name], ['Estado', x => (P[x.status] || [x.status])[0]], ['Avance', x => x.progress + '%', 1], ['Tareas', x => x.done + '/' + x.total, 1], ['Vencidas', x => x.late, 1], ['Horas', x => fmtMin(x.minutes) + (x.budget ? ' de ' + fmtMin(x.budget) : ''), 1], ['Entrega', x => x.due_date ? x.due_date.split('-').reverse().join('/') : '—'], ['Salud', x => x.health]], r.projects],
    tickets: ['Tickets por categoría', 'fa-ticket', [['Categoría', x => x.name], ['Abiertos en el período', x => x.created, 1], ['Resueltos', x => x.solved, 1], ['Dentro del SLA', x => x.with_sla ? pct(x.ontime, x.with_sla) + '%' : '—', 1], ['1ª respuesta (prom.)', x => h(x.frt), 1], ['Resolución (prom.)', x => h(x.ttr), 1], ['Escalados', x => x.escalated, 1], ['Siguen abiertos', x => x.open, 1]], r.tickets],
    tk_origin: ['Tickets por origen', 'fa-satellite-dish', [['Origen', x => (TK_SRC[x.origin] || [x.origin])[0]], ['Abiertos en el período', x => x.created, 1], ['Resueltos', x => x.solved, 1], ['Dentro del plazo', x => x.with_sla ? pct(x.ontime, x.with_sla) + '%' : '—', 1], ['1ª respuesta (prom.)', x => h(x.frt), 1], ['Resolución (prom.)', x => h(x.ttr), 1], ['Siguen abiertos', x => x.open, 1]], r.tk_origin || []],
    tk_people: ['Tickets por responsable', 'fa-headset', [['Responsable', x => x.user_id ? userOf(x.user_id)?.name || '#' + x.user_id : 'Sin asignar'], ['Abiertos en el período', x => x.created, 1], ['Resueltos', x => x.solved, 1], ['Dentro del SLA', x => x.with_sla ? pct(x.ontime, x.with_sla) + '%' : '—', 1], ['1ª respuesta (prom.)', x => h(x.frt), 1], ['Resolución (prom.)', x => h(x.ttr), 1], ['Siguen abiertos', x => x.open, 1]], r.tk_people || []],
    tk_weeks: ['Tickets por semana', 'fa-chart-column', [['Semana del', x => x.week.split('-').reverse().slice(0, 2).join('/')], ['Abiertos', x => x.created, 1], ['Resueltos', x => x.solved, 1], ['Dentro del SLA', x => x.with_sla ? pct(x.ontime, x.with_sla) + '%' : '—', 1],
      ['', x => '', 0, x => { const mx = Math.max(1, ...(r.tk_weeks || []).map(y => Math.max(y.created, y.solved))); return `<span class="wk-wbar" title="${x.created} abiertos · ${x.solved} resueltos"><i style="width:${Math.round(x.created * 100 / mx)}%"></i><i class="s" style="width:${Math.round(x.solved * 100 / mx)}%"></i></span>`; }]],
      (r.tk_weeks || []).some(x => x.created || x.solved) ? r.tk_weeks : []],
    requests: ['Solicitudes por formulario', 'fa-inbox', [['Formulario', x => x.name], ['Recibidas', x => x.created, 1], ['Aprobadas', x => x.approved, 1], ['Rechazadas', x => x.rejected, 1], ['En marcha', x => x.converted, 1], ['Sin resolver', x => x.pending, 1], ['Hasta decidir (prom.)', x => hh(x.decide_h), 1]], r.requests],
    clients: ['Horas por ' + W.cli.toLowerCase(), 'fa-briefcase', [[W.cli, x => x.name], ['Horas', x => fmtMin(x.minutes), 1], ['Tareas', x => x.tasks, 1], ['Personas', x => x.people, 1]], r.clients],
  };
  const tot = { done: r.people.reduce((a, x) => a + x.done, 0), min: r.clients.reduce((a, x) => a + x.minutes, 0), tk: r.tickets.reduce((a, x) => a + x.created, 0), rq: r.requests.reduce((a, x) => a + x.created, 0) };
  const scopeTxt = r.scope === 'all' ? 'todo el equipo' : r.scope === 'team' ? 'tu departamento' : 'lo tuyo';
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Informes<span>.</span></h1><p>Del ${R.from.split('-').reverse().join('/')} al ${R.to.split('-').reverse().join('/')} · ${scopeTxt}. "Abiertas hoy" y "Vencidas hoy" son la foto de este momento.</p></div>
      <div class="wk-h-a wk-noprint"><a class="wk-btn" href="/toolz/trabajo/informes.php?jf=${R.from}&jt=${R.to}${+R.team ? '&d=' + R.team : ''}${+R.board ? '&b=' + R.board : ''}" style="border-color:var(--g7-accent);color:var(--g7-accent)"><i class="fa-solid fa-chart-pie"></i> Tablero de informes</a><button class="wk-btn" type="button" id="rp-print"><i class="fa-solid fa-file-pdf"></i> PDF / imprimir</button></div></div>
    <div class="wk-bar wk-noprint">
      ${[['mes', 'Este mes'], ['30', 'Últimos 30 días'], ['trim', 'Este trimestre'], ['anio', 'Este año']].map(([k, n]) => `<button class="wk-btn sm" type="button" data-rng="${k}">${n}</button>`).join('')}
      <input class="wk-in" type="date" id="rp-from" value="${R.from}" aria-label="Desde" style="min-width:0;width:150px"><span>a</span><input class="wk-in" type="date" id="rp-to" value="${R.to}" aria-label="Hasta" style="min-width:0;width:150px">
      ${r.teams.length ? `<select class="wk-in" id="rp-team" aria-label="Departamento"><option value="">${W.me.role === 'admin' || W.me.role === 'director' ? 'Todos los departamentos' : 'Mi alcance'}</option>${r.teams.map(t => `<option value="${t.id}"${+R.team === t.id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select>` : ''}
      <select class="wk-in" id="rp-board" aria-label="Tablero"><option value="">Todos los tableros</option>${(W.boards || []).map(b => `<option value="${b.id}"${+R.board === b.id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></div>
    <div class="wk-kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
      <div class="wk-kpi"><small><i class="fa-solid fa-circle-check"></i>Tareas terminadas</small><b>${tot.done}</b><span>asignaciones terminadas en el período</span></div>
      <div class="wk-kpi"><small><i class="fa-solid fa-clock"></i>Horas cargadas</small><b>${fmtMin(tot.min)}</b><span>en el período</span></div>
      <div class="wk-kpi"><small><i class="fa-solid fa-ticket"></i>Tickets</small><b>${tot.tk}</b><span>abiertos en el período</span></div>
      <div class="wk-kpi"><small><i class="fa-solid fa-inbox"></i>Solicitudes</small><b>${tot.rq}</b><span>recibidas en el período</span></div></div>
    ${Object.entries(sections).map(([k, [t, ic, cols, rows]]) => repTable(k, t, ic, cols, rows)).join('')}`;
  const go = () => renderReports().catch(e => toast(e.message, true));
  $('rp-from').addEventListener('change', e => { R.from = e.target.value; go(); });
  $('rp-to').addEventListener('change', e => { R.to = e.target.value; go(); });
  $('rp-team')?.addEventListener('change', e => { R.team = e.target.value; go(); });
  $('rp-board').addEventListener('change', e => { R.board = e.target.value; go(); });
  $('wk-main').querySelectorAll('[data-rng]').forEach(b => b.addEventListener('click', () => {
    const d = new Date(dayMs(W.today)), y = d.getUTCFullYear(), m = d.getUTCMonth();
    R.to = W.today;
    R.from = b.dataset.rng === 'mes' ? iso(new Date(Date.UTC(y, m, 1))) : b.dataset.rng === '30' ? addDays(W.today, -29) : b.dataset.rng === 'trim' ? iso(new Date(Date.UTC(y, m - m % 3, 1))) : iso(new Date(Date.UTC(y, 0, 1)));
    go();
  }));
  $('rp-print').addEventListener('click', () => window.print());
  $('wk-main').querySelectorAll('[data-csv]').forEach(b => b.addEventListener('click', () => {
    const [t, , cols, rows] = sections[b.dataset.csv];
    const q = v => { v = String(v ?? ''); return /[";\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    const lines = [cols.map(c => q(c[0])).join(';'), ...rows.map(x => cols.map(c => q(c[1](x))).join(';'))];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    a.download = `${t} ${R.from} a ${R.to}.csv`; document.body.appendChild(a); a.click(); a.remove();
  }));
}

// ── PROCESOS Y AUTOMATIZACIONES (fase 5) ─────────────────────
const STEP_IC = { task: 'fa-list-check', approval: 'fa-stamp', notify: 'fa-bell' };
const RUN_ST = { running: ['En curso', '#3b82f6'], done: ['Terminado', '#22c55e'], cancelled: ['Cancelado', '#8b8b94'] };
const RS_ST = { active: ['En curso', '#3b82f6'], done: ['Hecho', '#22c55e'], approved: ['Aprobado', '#22c55e'], rejected: ['Rechazado', '#ef4444'], skipped: ['Cancelado', '#8b8b94'] };
const asgLabel = a => a === 'starter' ? 'Quien lo inicia' : a === 'leads' ? 'Jefe del departamento' : (userOf(+String(a).slice(5))?.name || 'Alguien');
function procTabs(on, pending) {
  return `<div class="wk-tabs"><a class="${on === 'list' ? 'on' : ''}" href="#procesos">Procesos</a><a class="${on === 'runs' ? 'on' : ''}" href="#procesos/ejecuciones">Ejecuciones</a>
    <a class="${on === 'auto' ? 'on' : ''}" href="#procesos/auto">Automatizaciones</a><a class="${on === 'log' ? 'on' : ''}" href="#procesos/historial">Historial de reglas</a></div>`;
}
async function renderProcesses(arg, arg2) {
  if (arg === 'editar') return renderProcessEdit(arg2 === 'nuevo' ? 0 : +arg2);
  if (arg === 'run') return renderRun(+arg2);
  if (arg === 'auto') return arg2 ? renderAutoEdit(arg2 === 'nueva' ? 0 : +arg2) : renderAutos();
  if (arg === 'historial') return renderAutoLog();
  const r = await api('processes');
  W.stepTypes = r.step_types;
  const runsOnly = arg === 'ejecuciones';
  const runRow = x => { const s = RUN_ST[x.status] || RUN_ST.running; return `<a class="wk-row" href="#procesos/run/${x.id}"><span class="wk-dot" style="--c:${esc(x.color)}"></span>
    <span class="t"><b>${esc(x.title)}</b><span>${esc(x.process_name)} · inició ${esc(userOf(x.started_by)?.name || '')} ${ago(x.created_at)} · ${x.n_done} paso${x.n_done === 1 ? '' : 's'} hecho${x.n_done === 1 ? '' : 's'}</span></span>
    <span class="wk-pill" style="--c:${s[1]}">${s[0]}</span><span class="wk-due">${ago(x.updated_at)}</span></a>`; };
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Procesos<span>.</span></h1><p>Recorridos de varios pasos (tareas, aprobaciones y avisos) que se repiten: altas, compras, entregas. Cada paso arranca solo cuando termina el anterior.</p></div>
      <div class="wk-h-a">${r.can_create ? '<a class="wk-btn primary" href="#procesos/editar/nuevo"><i class="fa-solid fa-plus"></i> Nuevo proceso</a>' : ''}</div></div>
    ${procTabs(runsOnly ? 'runs' : 'list')}
    ${r.pending.length ? `<div class="wk-card wk-pend"><h3><i class="fa-solid fa-stamp"></i>Te toca aprobar<span class="sp"></span><small>${r.pending.length}</small></h3><div class="wk-rows">${r.pending.map(p => `<a class="wk-row" href="#procesos/run/${p.run_id}"><span class="wk-dot" style="--c:#f59e0b"></span><span class="t"><b>${esc(p.step)} · ${esc(p.title)}</b><span>${esc(p.process_name)} · ${ago(p.started_at)}</span></span><span></span><span class="wk-link">Revisar</span></a>`).join('')}</div></div>` : ''}
    ${runsOnly ? (r.runs.length ? `<div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${r.runs.map(runRow).join('')}</div></div>` : emptyBox('fa-diagram-next', 'No hay ejecuciones todavía.'))
    : (r.processes.length ? `<div class="wk-forms">${r.processes.map(p => `<div class="wk-formc${p.active ? '' : ' off'}" style="--c:${esc(p.color)}"><span class="wk-fic"><i class="fa-solid fa-diagram-next"></i></span>
        <span style="flex:1;min-width:0"><b>${esc(p.name)}</b><small>${esc(p.team_name || 'Administración')} · ${p.steps.length} paso${p.steps.length === 1 ? '' : 's'}${p.running ? ` · ${p.running} en curso` : ''}${p.active ? '' : ' · desactivado'}</small>
        <span class="wk-pacts">${p.can_start ? `<button class="wk-btn sm primary" type="button" data-start="${p.id}"><i class="fa-solid fa-play"></i> Iniciar</button>` : ''}${p.can_manage ? `<a class="wk-btn sm" href="#procesos/editar/${p.id}"><i class="fa-solid fa-pen"></i> Editar</a>` : ''}</span></span></div>`).join('')}</div>`
      : emptyBox('fa-diagram-next', r.can_create ? 'Todavía no hay procesos. Arma el primero con "Nuevo proceso".' : 'Todavía no hay procesos. Los arman los jefes de departamento.'))}
    ${!runsOnly && r.runs.filter(x => x.status === 'running').length ? `<div class="wk-sec"><h2>En curso</h2></div><div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${r.runs.filter(x => x.status === 'running').slice(0, 10).map(runRow).join('')}</div></div>` : ''}`;
  $('wk-main').querySelectorAll('[data-start]').forEach(b => b.addEventListener('click', () => {
    const p = r.processes.find(x => x.id === +b.dataset.start);
    const d = openDlg(`<h3>Iniciar: ${esc(p.name)}</h3>${p.description ? `<p class="wk-help" style="margin-top:-6px">${esc(p.description)}</p>` : ''}
      <label class="wk-f"><span>¿Para qué es esta vez?</span><input class="wk-in" id="ps-title" maxlength="200" placeholder="Ej.: Alta de Lucía Gómez"></label>
      <label class="wk-f"><span>Notas (opcional)</span><textarea class="wk-in" id="ps-notes" rows="3"></textarea></label>
      <div class="wk-help">Primer paso: <b>${esc(p.steps[0]?.name || '')}</b> (${esc(W.stepTypes[p.steps[0]?.type] || '')}, ${esc(asgLabel(p.steps[0]?.assignee))}).</div>
      <div class="wk-err" id="ps-err"></div><div class="acts"><button class="wk-btn" type="button" id="ps-c">Cancelar</button><button class="wk-btn primary" type="button" id="ps-ok"><i class="fa-solid fa-play"></i> Iniciar</button></div>`);
    d.querySelector('#ps-c').addEventListener('click', closeDlg);
    d.querySelector('#ps-ok').addEventListener('click', async () => {
      try { const x = await api('process_start', { process_id: p.id, title: d.querySelector('#ps-title').value, notes: d.querySelector('#ps-notes').value }); closeDlg(); toast('Proceso iniciado'); location.hash = 'procesos/run/' + x.id; }
      catch (e) { d.querySelector('#ps-err').textContent = e.message; }
    });
    d.querySelector('#ps-title').focus();
  }));
}
async function renderRun(id) {
  const r0 = await api('run_get', { id });
  const r = r0.run, st = RUN_ST[r.status] || RUN_ST.running;
  W.stepTypes = r0.step_types;
  const inst = k => r.done_steps.filter(s => s.step_key === k);
  const node = (s, i) => {
    const ins = inst(s.key), last = ins[ins.length - 1], rs = last ? RS_ST[last.status] : null;
    const mineApproval = last && last.status === 'active' && s.type === 'approval' && (last.assignee_id === W.me.id || (!last.assignee_id && (W.admin || r.manage)));
    return `<div class="wk-node ${last ? last.status : 'pending'}"><span class="ic"><i class="fa-solid ${STEP_IC[s.type]}"></i></span>
      <div class="b"><div class="h"><b>${i + 1}. ${esc(s.name)}</b>${rs ? `<span class="wk-pill" style="--c:${rs[1]}">${rs[0]}</span>` : '<span class="wk-help">Todavía no</span>'}</div>
        <small>${esc(W.stepTypes?.[s.type] || s.type)} · ${last?.assignee_id ? esc(userOf(last.assignee_id)?.name || '') : esc(asgLabel(s.assignee))}${last?.done_at ? ' · ' + ago(last.done_at) : last ? ' · desde ' + ago(last.started_at) : ''}</small>
        ${s.description ? `<p>${esc(s.description)}</p>` : ''}
        ${last?.task_id ? `<a class="wk-link" href="/toolz/tareas.php?b=${last.task_board}&t=${last.task_id}"><i class="fa-solid fa-list-check"></i> ${esc(last.task_title || 'Ver la tarea')}${last.task_done ? ' (terminada)' : ''}</a>` : ''}
        ${last?.note ? `<div class="wk-note">${last.decided_by ? '<b>' + esc(userOf(last.decided_by)?.name || '') + ':</b> ' : ''}${esc(last.note)}</div>` : ''}
        ${mineApproval ? `<div class="wk-appr"><textarea class="wk-in" rows="2" data-note="${last.id}" placeholder="Comentario (opcional)"></textarea><div style="display:flex;gap:8px"><button class="wk-btn primary sm" type="button" data-dec="${last.id}" data-ok="1"><i class="fa-solid fa-check"></i> Aprobar</button><button class="wk-btn danger sm" type="button" data-dec="${last.id}" data-ok="0"><i class="fa-solid fa-xmark"></i> Rechazar</button></div></div>` : ''}
        ${s.type === 'approval' ? `<div class="wk-branch"><span><i class="fa-solid fa-check"></i> Si aprueba → ${s.next ? esc(r.steps.find(x => x.key === s.next)?.name || '') : 'termina'}</span><span><i class="fa-solid fa-xmark"></i> Si rechaza → ${s.next_no ? esc(r.steps.find(x => x.key === s.next_no)?.name || '') : 'termina'}</span></div>`
          : s.next && r.steps[i + 1]?.key !== s.next ? `<div class="wk-branch"><span><i class="fa-solid fa-share"></i> Sigue en → ${esc(r.steps.find(x => x.key === s.next)?.name || '')}</span></div>` : ''}</div></div>`;
  };
  $('wk-main').innerHTML = `<a class="wk-link" href="#procesos/ejecuciones"><i class="fa-solid fa-arrow-left"></i> Ejecuciones</a>
    <div class="wk-h" style="margin-top:8px"><div><h1>${esc(r.title)}<span>.</span></h1><p><span class="wk-pill" style="--c:${st[1]}">${st[0]}</span> · ${esc(r.process_name)} · inició <b>${esc(userOf(r.started_by)?.name || '')}</b> ${ago(r.created_at)}</p></div>
      <div class="wk-h-a">${r.manage && r.status === 'running' ? '<button class="wk-btn danger" type="button" id="run-cancel"><i class="fa-solid fa-ban"></i> Cancelar</button>' : ''}</div></div>
    ${r.notes ? `<p class="wk-pdesc">${esc(r.notes)}</p>` : ''}
    <div class="wk-flow">${r.steps.map(node).join('<div class="wk-arrow"><i class="fa-solid fa-arrow-down"></i></div>')}</div>`;
  $('wk-main').querySelectorAll('[data-dec]').forEach(b => b.addEventListener('click', async () => {
    const ok = b.dataset.ok === '1';
    try { await api('run_decide', { step_id: +b.dataset.dec, ok, note: $('wk-main').querySelector(`[data-note="${b.dataset.dec}"]`).value }); toast(ok ? 'Aprobado' : 'Rechazado'); renderRun(id); }
    catch (e) { toast(e.message, true); }
  }));
  $('run-cancel')?.addEventListener('click', async () => { if (!confirm('¿Cancelar esta ejecución? Las tareas que ya se crearon quedan en sus tableros.')) return; try { await api('run_cancel', { id }); toast('Ejecución cancelada'); renderRun(id); } catch (e) { toast(e.message, true); } });
}
// Constructor visual de procesos: diagrama de pasos a la izquierda, propiedades del paso elegido a la derecha
async function renderProcessEdit(id) {
  const r = await api('processes');
  W.stepTypes = r.step_types;
  const src = id ? r.processes.find(p => p.id === id && p.can_manage) : { id: 0, name: '', description: '', team_id: W.me.role === 'admin' || W.me.role === 'director' ? null : r.lead[0], color: COLORS[0], starters: 'all', active: 1, steps: [] };
  if (!src) { $('wk-main').innerHTML = emptyBox('fa-lock', 'No puedes editar ese proceso.'); return; }
  const P = JSON.parse(JSON.stringify(src));
  if (!W.boards) try { W.boards = (await api('boards')).boards; } catch (e) { W.boards = []; }
  let sel = 0;
  const newKey = () => 's' + Math.random().toString(36).slice(2, 8);
  if (!P.steps.length) P.steps.push({ key: newKey(), type: 'task', name: 'Primer paso', description: '', assignee: 'starter', board: 0, due_days: 0, next: '', next_no: '' });
  const teams = W.teams.filter(t => W.me.role === 'admin' || W.me.role === 'director' || r.lead.includes(t.id));
  $('wk-main').innerHTML = `<a class="wk-link" href="#procesos"><i class="fa-solid fa-arrow-left"></i> Procesos</a>
    <div class="wk-h" style="margin-top:8px"><div><h1>${P.id ? 'Editar proceso' : 'Nuevo proceso'}<span>.</span></h1><p>Cada paso apunta al siguiente; las aprobaciones tienen dos caminos. Las ejecuciones en curso siguen con la versión con la que arrancaron.</p></div>
      <div class="wk-h-a">${P.id ? '<button class="wk-btn danger" type="button" id="pe-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : ''}<button class="wk-btn primary" type="button" id="pe-save"><i class="fa-solid fa-check"></i> Guardar</button></div></div>
    <div class="wk-card" style="margin-bottom:14px"><div class="wk-fr" style="grid-template-columns:2fr 1fr 1fr 1fr">
      <label class="wk-f"><span>Nombre</span><input class="wk-in" id="pe-name" maxlength="120" value="${esc(P.name)}" placeholder="Ej.: Alta de empleado"></label>
      <label class="wk-f"><span>Departamento</span><select class="wk-in" id="pe-team">${W.me.role === 'admin' || W.me.role === 'director' ? '<option value="0">Administración</option>' : ''}${teams.map(t => `<option value="${t.id}"${t.id === P.team_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Lo pueden iniciar</span><select class="wk-in" id="pe-starters"><option value="all"${P.starters === 'all' ? ' selected' : ''}>Todos</option><option value="team"${P.starters === 'team' ? ' selected' : ''}>Solo el departamento</option></select></label>
      <label class="wk-f"><span>Estado</span><select class="wk-in" id="pe-active"><option value="1"${P.active ? ' selected' : ''}>Activo</option><option value="0"${P.active ? '' : ' selected'}>Desactivado</option></select></label></div>
      <label class="wk-f" style="margin:0"><span>Descripción</span><input class="wk-in" id="pe-desc" maxlength="1000" value="${esc(P.description || '')}" placeholder="Para qué sirve (lo ve quien lo inicia)"></label></div>
    <div class="wk-2" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1fr)"><div class="wk-card"><h3><i class="fa-solid fa-diagram-next"></i>Diagrama</h3><div class="wk-flow edit" id="pe-flow"></div>
      <div class="wk-addf" style="justify-content:center">${Object.entries(W.stepTypes).map(([k, n]) => `<button class="wk-btn sm" type="button" data-addstep="${k}"><i class="fa-solid ${STEP_IC[k]}"></i> + ${esc(n)}</button>`).join('')}</div></div>
      <div class="wk-card wk-prev" id="pe-props"></div></div>`;
  const stepOpts = (cur, self) => `<option value="">Termina el proceso</option>${P.steps.map((x, i) => x.key === self ? '' : `<option value="${x.key}"${x.key === cur ? ' selected' : ''}>${i + 1}. ${esc(x.name)}</option>`).join('')}`;
  const drawFlow = () => {
    $('pe-flow').innerHTML = P.steps.map((s, i) => `<div class="wk-node edit${i === sel ? ' sel' : ''}" data-i="${i}" tabindex="0" role="button"><span class="ic"><i class="fa-solid ${STEP_IC[s.type]}"></i></span>
      <div class="b"><div class="h"><b>${i + 1}. ${esc(s.name)}</b>${i === 0 ? '<span class="wk-role">Inicio</span>' : ''}</div><small>${esc(W.stepTypes[s.type])} · ${esc(asgLabel(s.assignee))}${s.type === 'task' && s.due_days ? ` · ${s.due_days} día${s.due_days > 1 ? 's' : ''}` : ''}</small>
        ${s.type === 'approval' ? `<div class="wk-branch"><span><i class="fa-solid fa-check"></i> ${s.next ? esc(P.steps.find(x => x.key === s.next)?.name || '') : 'termina'}</span><span><i class="fa-solid fa-xmark"></i> ${s.next_no ? esc(P.steps.find(x => x.key === s.next_no)?.name || '') : 'termina'}</span></div>`
          : (s.next || '') !== (P.steps[i + 1]?.key || '') ? `<div class="wk-branch"><span><i class="fa-solid fa-share"></i> ${s.next ? 'sigue en ' + esc(P.steps.find(x => x.key === s.next)?.name || '') : 'termina'}</span></div>` : ''}</div>
      <span class="mv"><button type="button" data-up="${i}" aria-label="Subir"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button><button type="button" data-down="${i}" aria-label="Bajar"${i < P.steps.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-arrow-down"></i></button></span></div>`).join('<div class="wk-arrow"><i class="fa-solid fa-arrow-down"></i></div>');
  };
  const drawProps = () => {
    const s = P.steps[sel]; if (!s) { $('pe-props').innerHTML = ''; return; }
    $('pe-props').innerHTML = `<h3><i class="fa-solid ${STEP_IC[s.type]}"></i>Paso ${sel + 1}<span class="sp"></span>${P.steps.length > 1 ? '<button class="wk-btn sm danger" type="button" id="sp-del"><i class="fa-solid fa-trash"></i></button>' : ''}</h3>
      <label class="wk-f"><span>Tipo</span><select class="wk-in" data-p="type">${Object.entries(W.stepTypes).map(([k, n]) => `<option value="${k}"${k === s.type ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Nombre</span><input class="wk-in" data-p="name" maxlength="150" value="${esc(s.name)}"></label>
      <label class="wk-f"><span>${s.type === 'approval' ? 'Qué hay que revisar' : s.type === 'notify' ? 'Mensaje' : 'Detalle de la tarea'}</span><textarea class="wk-in" data-p="description" rows="3" maxlength="1000">${esc(s.description || '')}</textarea></label>
      <label class="wk-f"><span>${s.type === 'approval' ? 'Quién aprueba' : s.type === 'notify' ? 'A quién avisar' : 'Responsable'}</span><select class="wk-in" data-p="assignee"><option value="starter"${s.assignee === 'starter' ? ' selected' : ''}>Quien inicia el proceso</option><option value="leads"${s.assignee === 'leads' ? ' selected' : ''}>Jefe del departamento</option>${W.users.map(u => `<option value="user:${u.id}"${s.assignee === 'user:' + u.id ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
      ${s.type === 'task' ? `<div class="wk-fr"><label class="wk-f"><span>Tablero</span><select class="wk-in" data-p="board"><option value="0">General</option>${(W.boards || []).filter(b => !b.is_default).map(b => `<option value="${b.id}"${b.id === +s.board ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>
        <label class="wk-f"><span>Plazo (días)</span><input class="wk-in" type="number" min="0" max="365" data-p="due_days" value="${+s.due_days || ''}" placeholder="Sin plazo"></label></div>` : ''}
      <label class="wk-f"><span>${s.type === 'approval' ? 'Si aprueba, sigue en' : 'Después sigue en'}</span><select class="wk-in" data-p="next">${stepOpts(s.next, s.key)}</select></label>
      ${s.type === 'approval' ? `<label class="wk-f"><span>Si rechaza, sigue en</span><select class="wk-in" data-p="next_no">${stepOpts(s.next_no, s.key)}</select></label>` : ''}
      <div class="wk-help">${s.type === 'task' ? 'Se crea una tarea; cuando la terminan, el proceso avanza solo.' : s.type === 'approval' ? 'La persona elegida aprueba o rechaza desde Procesos (le llega un aviso).' : 'Se manda el aviso y el proceso sigue enseguida.'}</div>`;
    $('pe-props').querySelectorAll('[data-p]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => {
      const k = el.dataset.p; s[k] = k === 'board' || k === 'due_days' ? +el.value || 0 : el.value;
      if (k === 'type') { if (s.type !== 'approval') s.next_no = ''; drawProps(); }
      drawFlow();
    }));
    $('sp-del')?.addEventListener('click', () => {
      const k = s.key; P.steps.splice(sel, 1);
      for (const x of P.steps) { if (x.next === k) x.next = ''; if (x.next_no === k) x.next_no = ''; }
      sel = Math.max(0, sel - 1); drawFlow(); drawProps();
    });
  };
  $('pe-flow').addEventListener('click', e => {
    const up = e.target.closest('[data-up]'), dn = e.target.closest('[data-down]');
    if (up || dn) { const i = +(up || dn).dataset[up ? 'up' : 'down'], j = up ? i - 1 : i + 1; [P.steps[i], P.steps[j]] = [P.steps[j], P.steps[i]]; sel = j; drawFlow(); drawProps(); return; }
    const n = e.target.closest('[data-i]'); if (n) { sel = +n.dataset.i; drawFlow(); drawProps(); }
  });
  $('wk-main').querySelectorAll('[data-addstep]').forEach(b => b.addEventListener('click', () => {
    const t = b.dataset.addstep, k = newKey(), last = P.steps[P.steps.length - 1];
    P.steps.push({ key: k, type: t, name: { task: 'Nueva tarea', approval: 'Aprobación', notify: 'Aviso' }[t], description: '', assignee: t === 'approval' ? 'leads' : 'starter', board: 0, due_days: 0, next: '', next_no: '' });
    if (last && !last.next) last.next = k;   // el paso nuevo queda conectado al último
    sel = P.steps.length - 1; drawFlow(); drawProps();
  }));
  $('pe-save').addEventListener('click', async () => {
    try {
      const x = await api('process_save', { id: P.id, name: $('pe-name').value, description: $('pe-desc').value, team_id: +$('pe-team').value, starters: $('pe-starters').value, active: $('pe-active').value === '1', color: P.color, steps: P.steps });
      toast('Proceso guardado'); location.hash = 'procesos';
    } catch (e) { toast(e.message, true); }
  });
  $('pe-del')?.addEventListener('click', async () => { if (!confirm('¿Borrar este proceso?')) return; try { const x = await api('process_del', { id: P.id }); toast(x.msg || 'Proceso borrado'); location.hash = 'procesos'; } catch (e) { toast(e.message, true); } });
  drawFlow(); drawProps();
}
// Automatizaciones: "Cuando… si… entonces…"
async function renderAutos() {
  const r = await api('autos');
  W.autoMeta = r;
  const sentence = a => {
    const st = a.board_id ? r.board_statuses[a.board_id] || r.global_statuses : r.global_statuses;
    let t = esc(r.triggers[a.trigger_key] || a.trigger_key);
    if (a.trigger_key === 'status' && a.trigger_opt) t += ' a <b>' + esc((st.find(s => s.id === a.trigger_opt) || {}).name || a.trigger_opt) + '</b>';
    if (a.trigger_key === 'priority' && a.trigger_opt) t += ' a <b>' + esc((r.priorities.find(p => p.id === a.trigger_opt) || {}).name || a.trigger_opt) + '</b>';
    if (a.trigger_key === 'due_soon') t += ` (${a.trigger_opt || 1} día${(+a.trigger_opt || 1) > 1 ? 's' : ''} antes)`;
    return `Cuando ${t.charAt(0).toLowerCase() + t.slice(1)}${a.conditions.length ? ', si se cumplen ' + a.conditions.length + ' condición' + (a.conditions.length > 1 ? 'es' : '') : ''} → ${a.actions.map(x => esc(r.actions[x.type] || x.type).toLowerCase()).join(', ')}`;
  };
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Automatizaciones<span>.</span></h1><p>Reglas que hacen trabajo solas: cuando pasa algo en una tarea, si se cumplen las condiciones, se hacen las acciones. Todo queda en el historial.</p></div>
      <div class="wk-h-a">${r.boards.length || r.admin ? '<a class="wk-btn primary" href="#procesos/auto/nueva"><i class="fa-solid fa-plus"></i> Nueva regla</a>' : ''}</div></div>
    ${procTabs('auto')}
    ${r.autos.length ? `<div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${r.autos.map(a => `<div class="wk-row wk-auto${a.active ? '' : ' off'}"><label class="wk-switch" title="${a.active ? 'Activa' : 'Pausada'}"><input type="checkbox" data-tog="${a.id}"${a.active ? ' checked' : ''} aria-label="Activa"><i></i></label>
        <a class="t" href="#procesos/auto/${a.id}" style="text-decoration:none;color:inherit"><b>${esc(a.name)}</b><span>${sentence(a)}</span></a>
        <span class="wk-pill" style="--c:${a.board_id ? '#3b82f6' : '#a855f7'}">${esc(a.board_name || 'Todos los tableros')}</span>
        <span class="wk-due" title="Veces que corrió">${a.runs} ${a.runs === 1 ? 'vez' : 'veces'}${a.last_run_at ? ' · ' + ago(a.last_run_at) : ''}</span></div>`).join('')}</div></div>`
      : emptyBox('fa-gears', r.boards.length || r.admin ? 'Todavía no hay reglas. Ejemplo: "cuando una tarea pasa a En revisión, avisar al jefe del departamento".' : 'Las reglas las arman quienes administran un tablero.')}`;
  $('wk-main').querySelectorAll('[data-tog]').forEach(c => c.addEventListener('change', async () => { try { await api('auto_toggle', { id: +c.dataset.tog, on: c.checked }); toast(c.checked ? 'Regla activa' : 'Regla pausada'); } catch (e) { toast(e.message, true); c.checked = !c.checked; } }));
}
async function renderAutoEdit(id) {
  const r = W.autoMeta || await api('autos'); W.autoMeta = r;
  const a = id ? r.autos.find(x => x.id === id) : { id: 0, name: '', board_id: r.boards[0]?.id || null, trigger_key: 'status', trigger_opt: '', conditions: [], actions: [{ type: 'notify', value: '', to: 'assignees' }], active: 1 };
  if (!a) { $('wk-main').innerHTML = emptyBox('fa-lock', 'No puedes editar esa regla.'); return; }
  const A = JSON.parse(JSON.stringify(a));
  const sts = () => A.board_id ? r.board_statuses[A.board_id] || r.global_statuses : r.global_statuses;
  const valueInput = (kind, v, attrs) => {
    const sel = (opts) => `<select class="wk-in" ${attrs}>${opts.map(([k, n]) => `<option value="${esc(k)}"${String(k) === String(v) ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>`;
    if (kind === 'status' || kind === 'set_status') return sel(sts().map(s => [s.id, s.name]));
    if (kind === 'priority' || kind === 'set_priority') return sel(r.priorities.map(p => [p.id, p.name]));
    if (kind === 'assignee' || kind === 'assign') return sel(W.users.map(u => [u.id, u.name]));
    if (kind === 'label' || kind === 'add_label') return r.labels.length ? sel(r.labels.map(l => [l.id, l.name])) : '<span class="wk-help">No hay etiquetas (se crean en Tareas › Configurar).</span>';
    if (kind === 'move_board') return sel(r.all_boards.filter(b => b.id !== A.board_id).map(b => [b.id, b.name]));
    if (kind === 'unassigned' || kind === 'unassign' || kind === 'archive') return '';
    if (kind === 'set_due') return `<input class="wk-in" type="number" min="0" max="365" ${attrs} value="${esc(v)}" placeholder="Días">`;
    if (kind === 'client' || kind === 'project') return `<input class="wk-in" type="number" ${attrs} value="${esc(v)}" placeholder="Número (id)">`;
    return `<input class="wk-in" ${attrs} value="${esc(v)}" placeholder="${kind === 'comment' ? 'Texto del comentario' : kind === 'notify' ? 'Mensaje del aviso' : kind === 'subtask' ? 'Título de la subtarea' : 'Texto'}">`;
  };
  const draw = () => {
    $('wk-main').innerHTML = `<a class="wk-link" href="#procesos/auto"><i class="fa-solid fa-arrow-left"></i> Automatizaciones</a>
      <div class="wk-h" style="margin-top:8px"><div><h1>${A.id ? 'Editar regla' : 'Nueva regla'}<span>.</span></h1></div>
        <div class="wk-h-a">${A.id ? '<button class="wk-btn danger" type="button" id="ae-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : ''}<button class="wk-btn primary" type="button" id="ae-save"><i class="fa-solid fa-check"></i> Guardar</button></div></div>
      <div class="wk-card wk-rule">
        <div class="wk-fr"><label class="wk-f"><span>Nombre</span><input class="wk-in" id="ae-name" maxlength="120" value="${esc(A.name)}" placeholder="Ej.: Avisar al jefe cuando algo pasa a revisión"></label>
          <label class="wk-f"><span>Tablero</span><select class="wk-in" id="ae-board">${r.admin ? `<option value="0"${!A.board_id ? ' selected' : ''}>Todos los tableros</option>` : ''}${r.boards.map(b => `<option value="${b.id}"${b.id === A.board_id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label></div>
        <div class="wk-step"><b class="lbl">Cuando</b><select class="wk-in" id="ae-trig">${Object.entries(r.triggers).map(([k, n]) => `<option value="${k}"${k === A.trigger_key ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
          ${A.trigger_key === 'status' ? `<span>a</span><select class="wk-in" id="ae-opt"><option value="">cualquier estado</option>${sts().map(s => `<option value="${s.id}"${s.id === A.trigger_opt ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}</select>` : ''}
          ${A.trigger_key === 'priority' ? `<span>a</span><select class="wk-in" id="ae-opt"><option value="">cualquier prioridad</option>${r.priorities.map(p => `<option value="${p.id}"${p.id === A.trigger_opt ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>` : ''}
          ${A.trigger_key === 'due_soon' ? `<input class="wk-in" id="ae-opt" type="number" min="0" max="30" value="${esc(A.trigger_opt || 1)}" style="width:80px"><span>días antes</span>` : ''}</div>
        <div class="wk-step"><b class="lbl">Si</b><div class="wk-lines">${A.conditions.map((c, i) => `<div class="wk-line" data-ci="${i}"><select class="wk-in" data-c="field">${Object.entries(r.cond_fields).map(([k, n]) => `<option value="${k}"${k === c.field ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
            <select class="wk-in" data-c="op" style="width:auto"><option value="is"${c.op !== 'is_not' ? ' selected' : ''}>${c.field === 'title' ? 'contiene' : 'es'}</option><option value="is_not"${c.op === 'is_not' ? ' selected' : ''}>${c.field === 'title' ? 'no contiene' : 'no es'}</option></select>
            ${valueInput(c.field, c.value, 'data-c="value"')}<button class="x" type="button" data-crm="${i}" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('') || '<span class="wk-help">Siempre (sin condiciones).</span>'}
            <button class="wk-btn sm" type="button" id="ae-cadd"><i class="fa-solid fa-plus"></i> Condición</button></div></div>
        <div class="wk-step"><b class="lbl">Entonces</b><div class="wk-lines">${A.actions.map((x, i) => `<div class="wk-line" data-ai="${i}"><select class="wk-in" data-a="type">${Object.entries(r.actions).map(([k, n]) => `<option value="${k}"${k === x.type ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
            ${x.type === 'notify' ? `<select class="wk-in" data-a="to" style="width:auto"><option value="assignees"${x.to === 'assignees' ? ' selected' : ''}>a los responsables</option><option value="creator"${x.to === 'creator' ? ' selected' : ''}>a quien la creó</option><option value="leads"${x.to === 'leads' ? ' selected' : ''}>al jefe del departamento</option>${W.users.map(u => `<option value="user:${u.id}"${x.to === 'user:' + u.id ? ' selected' : ''}>a ${esc(u.name)}</option>`).join('')}</select>` : ''}
            ${valueInput(x.type, x.value, 'data-a="value"')}<button class="x" type="button" data-arm="${i}" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}
            <button class="wk-btn sm" type="button" id="ae-aadd"><i class="fa-solid fa-plus"></i> Acción</button></div></div>
        <label class="wk-chkl" style="margin-top:6px"><input type="checkbox" id="ae-active"${A.active ? ' checked' : ''}> Activa</label>
        <div class="wk-help">Las acciones de una regla no disparan otras reglas (así nunca se arma un bucle). Los avisos y comentarios dicen que los hizo una automatización.</div></div>`;
    const sync = () => {
      A.name = $('ae-name').value; A.active = $('ae-active').checked;
      A.conditions = [...document.querySelectorAll('[data-ci]')].map(l => ({ field: l.querySelector('[data-c="field"]').value, op: l.querySelector('[data-c="op"]').value, value: l.querySelector('[data-c="value"]')?.value || '' }));
      A.actions = [...document.querySelectorAll('[data-ai]')].map(l => ({ type: l.querySelector('[data-a="type"]').value, to: l.querySelector('[data-a="to"]')?.value || 'assignees', value: l.querySelector('[data-a="value"]')?.value || '' }));
      A.trigger_opt = $('ae-opt')?.value || '';
    };
    $('ae-board').addEventListener('change', e => { sync(); A.board_id = +e.target.value || null; draw(); });
    $('ae-trig').addEventListener('change', e => { sync(); A.trigger_key = e.target.value; A.trigger_opt = ''; draw(); });
    $('wk-main').querySelectorAll('[data-c="field"],[data-a="type"]').forEach(s => s.addEventListener('change', () => { sync(); draw(); }));
    $('ae-cadd').addEventListener('click', () => { sync(); A.conditions.push({ field: 'priority', op: 'is', value: r.priorities[r.priorities.length - 1].id }); draw(); });
    $('ae-aadd').addEventListener('click', () => { sync(); A.actions.push({ type: 'notify', to: 'assignees', value: '' }); draw(); });
    $('wk-main').querySelectorAll('[data-crm]').forEach(b => b.addEventListener('click', () => { sync(); A.conditions.splice(+b.dataset.crm, 1); draw(); }));
    $('wk-main').querySelectorAll('[data-arm]').forEach(b => b.addEventListener('click', () => { sync(); A.actions.splice(+b.dataset.arm, 1); draw(); }));
    $('ae-save').addEventListener('click', async () => {
      sync();
      try { await api('auto_save', { id: A.id, board_id: A.board_id || 0, name: A.name, trigger_key: A.trigger_key, trigger_opt: A.trigger_opt, conditions: A.conditions, actions: A.actions, active: A.active }); W.autoMeta = null; toast('Regla guardada'); location.hash = 'procesos/auto'; }
      catch (e) { toast(e.message, true); }
    });
    $('ae-del')?.addEventListener('click', async () => { if (!confirm('¿Borrar esta regla?')) return; try { await api('auto_del', { id: A.id }); W.autoMeta = null; toast('Regla borrada'); location.hash = 'procesos/auto'; } catch (e) { toast(e.message, true); } });
  };
  draw();
}
async function renderAutoLog() {
  const r = await api('auto_log');
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Historial de reglas<span>.</span></h1><p>Cada vez que una automatización corrió: en qué tarea y qué hizo (las últimas 200).</p></div></div>
    ${procTabs('log')}
    ${r.log.length ? `<div class="wk-card"><div class="wk-wrap"><table class="wk-t" style="cursor:default"><thead><tr><th>Cuándo</th><th>Regla</th><th>Tarea</th><th>Resultado</th></tr></thead><tbody>
      ${r.log.map(l => `<tr style="cursor:default"><td class="muted" style="white-space:nowrap">${ago(l.created_at)}</td><td>${esc(l.name)}</td><td>${l.task_id ? `<a class="wk-link" href="/toolz/tareas.php?b=${l.board_id}&t=${l.task_id}">${esc(l.title || '#' + l.task_id)}</a>` : '—'}</td>
        <td${l.ok ? '' : ' class="late"'}>${l.ok ? '' : '<i class="fa-solid fa-triangle-exclamation"></i> '}${esc(l.detail)}</td></tr>`).join('')}</tbody></table></div></div>` : emptyBox('fa-clock-rotate-left', 'Las reglas todavía no corrieron.')}`;
}

// ── SOLICITUDES (fase 4) ─────────────────────────────────────
const rqSt = k => { const s = (W.reqStatus || {})[k] || [k, '#8b8b94']; return { name: s[0], color: s[1] }; };
const tkSt = k => { const s = (W.tkStatus || {})[k] || [k, '#8b8b94']; return { name: s[0], color: s[1] }; };
const tkCode = id => 'T-' + String(id).padStart(4, '0');
function convHtml(msgs, agent) {
  return msgs.map(m => {
    const u = m.user_id ? userOf(m.user_id) : null, ext = !m.user_id && m.ext_name ? m.ext_name : '';
    if (m.kind === 'event') return `<div class="wk-ev${m.internal ? ' int' : ''}"><i class="fa-solid ${m.user_id || ext ? 'fa-circle-dot' : 'fa-robot'}"></i><span><b>${esc(u?.name || ext || 'Automático')}</b> ${esc(m.body)}</span><small>${ago(m.created_at)}</small></div>`;
    const sync = m.failed ? '<span class="wk-role" style="color:#f87171" title="El sitio base lo rechazó">No llegó al base</span>' : m.pending ? '<span class="wk-role" title="Se reintenta solo">Pendiente de enviar al base</span>' : '';
    return `<div class="wk-msg${m.internal ? ' int' : ''}">${ext ? `<span class="wk-av" style="width:30px;height:30px;background:var(--g7-bg-5)"><i class="fa-solid fa-building" style="font-size:12px"></i></span>` : av(u, 30)}<div class="b"><div class="h"><b>${esc(u?.name || ext)}</b>${m.internal ? '<span class="wk-role">Nota interna</span>' : ''}${sync}<small>${ago(m.created_at)}</small></div><div class="t">${esc(m.body)}</div></div></div>`;
  }).join('');
}
function replyBox(agent, help = '') {
  return `<form class="wk-reply" id="wk-reply"><textarea class="wk-in" name="body" rows="3" placeholder="Escribe un mensaje…" aria-label="Mensaje"></textarea>
    <div class="row">${agent ? '<label class="wk-chkl"><input type="checkbox" name="internal"> Nota interna (solo la ve el equipo que atiende)</label>' : ''}<span style="flex:1"></span><button class="wk-btn primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar</button></div>${help ? `<div class="wk-help">${help}</div>` : ''}</form>`;
}
function bindReply(action, id, done) {
  $('wk-reply')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, body = f.body.value.trim(); if (!body) return;
    f.querySelector('button').disabled = true;
    try { await api(action, { id, body, internal: !!f.internal?.checked }); done(); }
    catch (err) { toast(err.message, true); f.querySelector('button').disabled = false; }
  });
}
// ── PANEL DE SOLICITUDES (2026-11-10) ─────────────────────────
// Cada departamento publica sus formularios; lo que se pide entra directo como ticket (con su SLA) y su tarea.
// Las solicitudes de antes (con aprobación) quedan en "Historial".
async function renderRequests(arg, arg2) {
  if (arg === 'nueva') return renderRequestNew(+arg2);
  if (arg === 'formularios') return renderForms(+arg2 || 0, arg2 === 'nuevo');
  if (arg === 'historial' || arg === 'bandeja') return renderOldRequests(arg2 === 'bandeja' || arg === 'bandeja' ? 'inbox' : 'mine');
  if (+arg) return renderRequest(+arg);
  const [fr, mine] = await Promise.all([api('forms'), api('my_form_tickets')]);
  W.tkStatus = { ...(W.tkStatus || {}), ...mine.status };
  const forms = fr.forms.filter(f => f.active);
  const groups = [{ id: null, name: 'Administración' }, ...fr.teams].map(t => ({ ...t, forms: forms.filter(f => (f.team_id || null) === t.id) })).filter(g => g.forms.length);
  const row = t => { const s = tkSt(t.status);
    return `<a class="wk-row" href="#tickets/${t.id}"><span class="wk-fic" style="--c:${esc(t.form_color || '#888')}"><i class="fa-solid ${esc(t.form_icon || 'fa-inbox')}"></i></span>
      <span class="t"><b>${tkCode(t.id)} · ${esc(t.subject)}</b><span>${esc(t.form_name || '')}${t.team_name ? ' · ' + esc(t.team_name) : ''}${t.assignee_id ? ' · lo atiende ' + esc(userOf(t.assignee_id)?.name || '') : ' · todavía sin responsable'}
        ${['resuelto', 'cerrado'].includes(t.status) ? '' : slaChip(t.resolution_due, t.resolved_at, 'Plazo', mine.now, t.paused)}</span></span>
      <span class="wk-pill" style="--c:${s.color}">${esc(s.name)}</span><span class="wk-due">${ago(t.updated_at)}</span></a>`; };
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Panel de solicitudes<span>.</span></h1><p>Pídele algo a cualquier departamento con su formulario. El pedido entra como ticket, con plazo de respuesta, y como tarea en el tablero del departamento: lo sigues desde acá.</p></div>
      ${fr.can_forms ? '<div class="wk-h-a"><a class="wk-btn" href="#solicitudes/formularios"><i class="fa-solid fa-pen-ruler"></i> Editar formularios</a></div>' : ''}</div>
    ${requestTabs('panel', fr)}
    ${groups.length ? `<div class="wk-depts">${groups.map(g => `<section class="wk-dept"><h2><i class="fa-solid fa-people-group"></i>${esc(g.name)}<small>${g.forms.length} formulario${g.forms.length === 1 ? '' : 's'}</small></h2>
        <div class="wk-forms">${g.forms.map(f => `<a class="wk-formc" href="#solicitudes/nueva/${f.id}" style="--c:${esc(f.color)}"><span class="wk-fic"><i class="fa-solid ${esc(f.icon)}"></i></span><span><b>${esc(f.name)}</b>${f.description ? `<small>${esc(f.description)}</small>` : ''}</span></a>`).join('')}</div></section>`).join('')}</div>`
      : emptyBox('fa-inbox', fr.can_forms ? 'Todavía no hay formularios. Arma el primero con "Editar formularios".' : 'Todavía no hay formularios para pedir. Los arman los jefes de cada departamento.')}
    <div class="wk-sec"><h2>Lo que pediste</h2><small>${mine.tickets.length || ''}</small></div>
    ${mine.tickets.length ? `<div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${mine.tickets.map(row).join('')}</div></div>` : emptyBox('fa-paper-plane', 'Todavía no pediste nada desde el panel.')}`;
}
function requestTabs(on, fr) {
  return `<div class="wk-tabs"><a class="${on === 'panel' ? 'on' : ''}" href="#solicitudes">Panel</a>${fr.can_forms ? `<a class="${on === 'forms' ? 'on' : ''}" href="#solicitudes/formularios">Formularios</a>` : ''}${fr.old_requests ? `<a class="${on === 'old' ? 'on' : ''}" href="#solicitudes/historial">Historial</a>` : ''}</div>`;
}
/** Solicitudes de antes del panel (con aprobación): quedan para consultar. */
async function renderOldRequests(box) {
  const [fr, rr] = await Promise.all([api('forms'), api('requests', { box, status: W.rqFilter || '' })]);
  W.reqStatus = rr.status;
  const row = r => { const s = rqSt(r.status), u = userOf(r.requester_id);
    return `<a class="wk-row" href="#solicitudes/${r.id}"><span class="wk-fic" style="--c:${esc(r.form_color || '#888')}"><i class="fa-solid ${esc(r.form_icon || 'fa-inbox')}"></i></span>
      <span class="t"><b>${esc(r.title)}</b><span>${esc(r.form_name || '')}${r.team_name ? ' · ' + esc(r.team_name) : ''}${box === 'inbox' ? ' · pidió ' + esc(u?.name || '') : ''}${r.assignee_id ? ' · atiende ' + esc(userOf(r.assignee_id)?.name || '') : ''}${r.n_msgs ? ` · <i class="fa-regular fa-comment"></i> ${r.n_msgs}` : ''}</span></span>
      <span class="wk-pill" style="--c:${s.color}">${esc(s.name)}</span><span class="wk-due">${ago(r.updated_at)}</span></a>`; };
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Historial de solicitudes<span>.</span></h1><p>Las solicitudes de antes del panel, con su paso de aprobación. Las nuevas entran como tickets.</p></div></div>
    ${requestTabs('old', fr)}
    <div class="wk-bar">${rr.agent ? `<div class="wk-tabs" style="margin:0"><a class="${box === 'mine' ? 'on' : ''}" href="#solicitudes/historial">Las que pedí</a><a class="${box === 'inbox' ? 'on' : ''}" href="#solicitudes/historial/bandeja">Las del departamento</a></div>` : ''}<span style="flex:1"></span>
      <select class="wk-in" id="rq-f" style="width:auto;height:32px" aria-label="Filtrar por estado"><option value="">Todas</option><option value="abiertas"${W.rqFilter === 'abiertas' ? ' selected' : ''}>Sin resolver</option>${Object.entries(rr.status).map(([k, s]) => `<option value="${k}"${W.rqFilter === k ? ' selected' : ''}>${esc(s[0])}</option>`).join('')}</select></div>
    ${rr.requests.length ? `<div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${rr.requests.map(row).join('')}</div></div>` : emptyBox('fa-inbox', 'No hay solicitudes acá.')}`;
  $('rq-f').addEventListener('change', e => { W.rqFilter = e.target.value; route(); });
}
function answerInput(f, dis = false) {
  const a = `name="${esc(f.id)}" id="rq-${esc(f.id)}"${f.required ? ' required' : ''}${dis ? ' disabled tabindex="-1"' : ''}`;
  if (f.type === 'textarea') return `<textarea class="wk-in" ${a} rows="4"></textarea>`;
  if (f.type === 'select') return `<select class="wk-in" ${a}><option value="">Elige…</option>${(f.options || []).map(o => `<option>${esc(o)}</option>`).join('')}</select>`;
  if (f.type === 'person') return `<select class="wk-in" ${a}><option value="">Elige…</option>${W.users.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select>`;
  if (f.type === 'check') return `<label class="wk-chkl"><input type="checkbox" ${a} value="1"> Sí</label>`;
  return `<input class="wk-in" type="${{ number: 'text', date: 'date', url: 'url' }[f.type] || 'text'}"${f.type === 'number' ? ' inputmode="decimal"' : ''} ${a}>`;
}
async function renderRequestNew(formId) {
  const fr = await api('forms');
  const f = fr.forms.find(x => x.id === formId && x.active);
  if (!f) { $('wk-main').innerHTML = emptyBox('fa-inbox', 'Ese formulario no existe o está desactivado. <a class="wk-link" href="#solicitudes">Volver</a>'); return; }
  $('wk-main').innerHTML = `<a class="wk-link" href="#solicitudes"><i class="fa-solid fa-arrow-left"></i> Panel de solicitudes</a>
    <div class="wk-h" style="margin-top:8px"><div><h1><span class="wk-fic big" style="--c:${esc(f.color)}"><i class="fa-solid ${esc(f.icon)}"></i></span>${esc(f.name)}<span>.</span></h1><p>${esc(f.description || '')}${' Lo atiende ' + esc(f.team_name || 'Administración') + '.'}</p></div></div>
    <form class="wk-card wk-rqform" id="rq-form" novalidate>
      <label class="wk-f"><span>Título del pedido *</span><input class="wk-in" name="__title" maxlength="200" required placeholder="En pocas palabras, qué necesitas"></label>
      ${f.fields.map(fd => fd.type === 'section' ? `<h3 class="wk-fsec">${esc(fd.label)}</h3>${fd.help ? `<div class="wk-help">${esc(fd.help)}</div>` : ''}` : `<div class="wk-f"><span>${esc(fd.label)}${fd.required ? ' *' : ''}</span>${answerInput(fd)}${fd.help ? `<div class="wk-help">${esc(fd.help)}</div>` : ''}</div>`).join('')}
      ${f.priority ? '' : `<label class="wk-f"><span>Prioridad</span><select class="wk-in" name="__prio" style="max-width:220px">${W.cfg.priorities.map(p => `<option value="${p.id}"${p.id === (W.cfg.default_priority || 'media') ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>`}
      <div class="wk-err" id="rq-err"></div>
      <div class="acts" style="display:flex;justify-content:flex-end;gap:8px"><a class="wk-btn" href="#solicitudes">Cancelar</a><button class="wk-btn primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button></div>
    </form>`;
  $('rq-form').addEventListener('submit', async e => {
    e.preventDefault();
    const fm = e.target, answers = {};
    for (const fd of f.fields) { if (fd.type === 'section') continue; const el = fm.elements[fd.id]; answers[fd.id] = fd.type === 'check' ? (el.checked ? '1' : '') : el.value; }
    fm.querySelector('[type=submit]').disabled = true;
    try { const r = await api('form_submit', { form_id: f.id, title: fm.__title.value, priority: fm.__prio?.value || '', answers }); toast('Pedido enviado: ' + tkCode(r.id)); location.hash = 'tickets/' + r.id; }
    catch (err) { $('rq-err').textContent = err.message; fm.querySelector('[type=submit]').disabled = false; }
  });
}
// Editor de formularios a la vista (como el de Promos): se edita sobre el formulario tal como lo ve quien pide
const FORM_ICONS = ['fa-inbox', 'fa-palette', 'fa-code', 'fa-bullhorn', 'fa-cart-shopping', 'fa-laptop', 'fa-money-bill', 'fa-truck', 'fa-user-plus', 'fa-calendar-days', 'fa-file-signature', 'fa-wrench', 'fa-headset', 'fa-camera', 'fa-print', 'fa-key', 'fa-plane', 'fa-scale-balanced', 'fa-chart-line', 'fa-envelope'];
async function renderForms(id, isNew) {
  const fr = await api('forms');
  if (!fr.can_forms) { $('wk-main').innerHTML = emptyBox('fa-lock', 'Los formularios los arman los jefes de departamento y la administración.'); return; }
  const list = fr.forms.filter(f => f.can_manage);
  const f = isNew ? { id: 0, name: 'Nuevo formulario', description: '', team_id: fr.all ? null : fr.lead[0], icon: 'fa-inbox', color: COLORS[0], fields: [], board_id: null, assignee_id: null, category_id: null, priority: null, active: 1 } : list.find(x => x.id === id);
  if (!f) {
    const groups = [{ id: null, name: 'Administración' }, ...fr.teams].map(t => ({ ...t, forms: list.filter(x => (x.team_id || null) === t.id) })).filter(g => g.forms.length);
    $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Formularios<span>.</span></h1><p>Cada departamento arma los formularios con los que le piden cosas. Se editan sobre la vista que ve quien pide.</p></div><div class="wk-h-a"><a class="wk-btn primary" href="#solicitudes/formularios/nuevo"><i class="fa-solid fa-plus"></i> Nuevo formulario</a></div></div>
      ${requestTabs('forms', fr)}
      ${groups.length ? groups.map(g => `<div class="wk-sec"><h2>${esc(g.name)}</h2></div><div class="wk-forms">${g.forms.map(x => `<a class="wk-formc${x.active ? '' : ' off'}" href="#solicitudes/formularios/${x.id}" style="--c:${esc(x.color)}"><span class="wk-fic"><i class="fa-solid ${esc(x.icon)}"></i></span><span><b>${esc(x.name)}</b><small>${x.fields.filter(y => y.type !== 'section').length} campo(s)${x.active ? '' : ' · desactivado'}</small></span></a>`).join('')}</div>`).join('') : emptyBox('fa-inbox', 'No hay formularios todavía.')}`;
    return;
  }
  const F = JSON.parse(JSON.stringify(f)), types = fr.types;
  const teams = fr.all ? fr.teams : fr.teams.filter(t => fr.lead.includes(t.id));
  let nid = 0, drag = -1, iconOpen = false;
  const fid = () => 'n' + Date.now().toString(36) + (++nid);
  $('wk-main').innerHTML = `<a class="wk-link" href="#solicitudes/formularios"><i class="fa-solid fa-arrow-left"></i> Formularios</a>
    <div class="wk-h" style="margin-top:8px"><div><h1>${F.id ? 'Editar formulario' : 'Nuevo formulario'}<span>.</span></h1><p>Haz clic en cualquier texto para cambiarlo. Arrastra los campos para ordenarlos.</p></div>
      <div class="wk-h-a">${F.id ? '<button class="wk-btn danger" type="button" id="fm-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : ''}<button class="wk-btn primary" type="button" id="fm-save"><i class="fa-solid fa-check"></i> Guardar</button></div></div>
    <div class="wk-wy">
      <div class="wk-card wk-wy-canvas" id="fm-canvas"></div>
      <aside class="wk-card wk-wy-side"><h3><i class="fa-solid fa-sliders"></i>Ajustes</h3>
        <label class="wk-f"><span>Departamento que lo atiende</span><select class="wk-in" id="fm-team">${fr.all ? '<option value="0">Administración</option>' : ''}${teams.map(t => `<option value="${t.id}"${t.id === F.team_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
        <label class="wk-f"><span>Categoría del ticket (sus plazos)</span><select class="wk-in" id="fm-cat"></select></label>
        <label class="wk-f"><span>Prioridad</span><select class="wk-in" id="fm-prio"><option value="">La elige quien pide</option>${W.cfg.priorities.map(p => `<option value="${p.id}"${p.id === F.priority ? ' selected' : ''}>Siempre ${esc(p.name)}</option>`).join('')}</select></label>
        <label class="wk-f"><span>Responsable de entrada</span><select class="wk-in" id="fm-asg"><option value="0">Nadie: lo toma el departamento</option>${W.users.map(u => `<option value="${u.id}"${u.id === F.assignee_id ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
        <label class="wk-f"><span>Tablero de la tarea</span><select class="wk-in" id="fm-board"><option value="0">El del departamento</option>${fr.boards.map(b => `<option value="${b.id}"${b.id === F.board_id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>
        <label class="wk-chkl" style="margin-top:6px"><input type="checkbox" id="fm-active"${F.active ? ' checked' : ''}> Publicado en el panel</label>
        <div class="wk-help" style="margin-top:10px">Al enviarlo nace un ticket con los plazos de su categoría y su tarea en el tablero elegido. Las respuestas quedan en la descripción.</div></aside></div>`;
  const drawCats = () => {
    const team = +$('fm-team').value || null, cs = fr.cats.filter(c => (c.team_id || null) === team);
    $('fm-cat').innerHTML = `<option value="0">Automática (la primera del departamento)</option>${cs.map(c => `<option value="${c.id}"${c.id === F.category_id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}${fr.cats.filter(c => (c.team_id || null) !== team).map(c => `<option value="${c.id}"${c.id === F.category_id ? ' selected' : ''}>${esc(c.name)} (otro departamento)</option>`).join('')}`;
  };
  const addBar = `<div class="wk-wy-add"><span>Agregar</span>${Object.entries(types).map(([k, n]) => `<button class="wk-btn sm" type="button" data-addf="${k}"><i class="fa-solid ${{ section: 'fa-heading', text: 'fa-font', textarea: 'fa-align-left', number: 'fa-hashtag', date: 'fa-calendar', select: 'fa-list-ul', check: 'fa-square-check', person: 'fa-user', url: 'fa-link' }[k] || 'fa-plus'}"></i> ${esc(n)}</button>`).join('')}</div>`;
  const fieldHtml = (fd, i) => fd.type === 'section'
    ? `<div class="wk-wy-f sec" data-i="${i}"><div class="wk-wy-tools"><span class="h" draggable="true" title="Arrastrar para ordenar" data-drag><i class="fa-solid fa-grip-vertical"></i></span><span class="ty">${esc(types.section)}</span><span class="sp"></span><button type="button" data-rm title="Quitar"><i class="fa-regular fa-trash-can"></i></button></div>
        <input class="wk-wy-in sec" data-k="label" value="${esc(fd.label)}" maxlength="120" placeholder="Título de la sección" aria-label="Título de la sección">
        <input class="wk-wy-in help" data-k="help" value="${esc(fd.help || '')}" maxlength="200" placeholder="Texto debajo del título (opcional)" aria-label="Texto de la sección"></div>`
    : `<div class="wk-wy-f" data-i="${i}"><div class="wk-wy-tools"><span class="h" draggable="true" title="Arrastrar para ordenar" data-drag><i class="fa-solid fa-grip-vertical"></i></span>
        <select class="ty" data-k="type" aria-label="Tipo de campo">${Object.entries(types).filter(([k]) => k !== 'section').map(([k, n]) => `<option value="${k}"${k === fd.type ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
        <span class="sp"></span><button type="button" data-req class="${fd.required ? 'on' : ''}" title="${fd.required ? 'Obligatorio: clic para que sea opcional' : 'Opcional: clic para que sea obligatorio'}" aria-pressed="${!!fd.required}"><i class="fa-solid fa-asterisk"></i> ${fd.required ? 'Obligatorio' : 'Opcional'}</button><button type="button" data-rm title="Quitar"><i class="fa-regular fa-trash-can"></i></button></div>
      <div class="wk-wy-lab"><input class="wk-wy-in lab" data-k="label" value="${esc(fd.label)}" maxlength="120" placeholder="Escribe la pregunta" aria-label="Pregunta">${fd.required ? '<b>*</b>' : ''}</div>
      ${fd.type === 'select' ? `<div class="wk-wy-opts">${(fd.options || []).map((o, j) => `<span class="o"><i class="fa-regular fa-circle"></i><input class="wk-wy-in" data-opt="${j}" value="${esc(o)}" maxlength="60" aria-label="Opción ${j + 1}"><button type="button" data-optrm="${j}" title="Quitar opción"><i class="fa-solid fa-xmark"></i></button></span>`).join('')}<button class="wk-btn sm" type="button" data-optadd><i class="fa-solid fa-plus"></i> Opción</button></div>` : answerInput(fd, true)}
      <input class="wk-wy-in help" data-k="help" value="${esc(fd.help || '')}" maxlength="200" placeholder="Agregar una ayuda (opcional)" aria-label="Ayuda"></div>`;
  const draw = (focus) => {
    $('fm-canvas').innerHTML = `<div class="wk-wy-head" style="--c:${esc(F.color)}"><button class="wk-fic big" type="button" id="fm-ic" title="Cambiar ícono y color" style="--c:${esc(F.color)}"><i class="fa-solid ${esc(F.icon)}"></i></button>
        <div style="flex:1;min-width:0"><input class="wk-wy-in title" id="fm-name" value="${esc(F.name)}" maxlength="100" placeholder="Nombre del formulario" aria-label="Nombre del formulario">
        <input class="wk-wy-in desc" id="fm-desc" value="${esc(F.description)}" maxlength="500" placeholder="Para qué sirve (lo ve quien pide)" aria-label="Descripción"></div></div>
      <div class="wk-wy-pick" id="fm-pick"${iconOpen ? '' : ' hidden'}><div class="wk-colors">${COLORS.map(c => `<button type="button" style="--c:${c}" data-c="${c}" class="${c === F.color ? 'on' : ''}" aria-label="Color ${c}"></button>`).join('')}</div>
        <div class="wk-icons" style="margin-top:8px">${FORM_ICONS.map(i => `<button type="button" data-ic="${i}" class="${i === F.icon ? 'on' : ''}" aria-label="${i}"><i class="fa-solid ${i}"></i></button>`).join('')}</div></div>
      <div class="wk-f wk-wy-fixed"><span>Título del pedido *</span><input class="wk-in" disabled placeholder="En pocas palabras, qué necesitas"><div class="wk-help">Siempre se pide: es el asunto del ticket.</div></div>
      <div id="fm-fields">${F.fields.map(fieldHtml).join('') || '<div class="wk-help wk-wy-empty">Sin campos: solo se pide un título. Agrega preguntas con los botones de abajo.</div>'}</div>
      ${addBar}
      ${F.priority ? '' : `<div class="wk-f wk-wy-fixed"><span>Prioridad</span><select class="wk-in" disabled style="max-width:220px"><option>${esc(prioOf(W.cfg.default_priority || 'media').name)}</option></select></div>`}
      <div style="display:flex;justify-content:flex-end"><button class="wk-btn primary" type="button" disabled><i class="fa-solid fa-paper-plane"></i> Enviar pedido</button></div>`;
    if (focus !== undefined) $('fm-canvas').querySelector(`[data-i="${focus}"] [data-k="label"]`)?.focus();
  };
  const C = $('fm-canvas');
  C.addEventListener('input', e => {
    const el = e.target;
    if (el.id === 'fm-name') { F.name = el.value; return; }
    if (el.id === 'fm-desc') { F.description = el.value; return; }
    const r = el.closest('[data-i]'); if (!r) return; const fd = F.fields[+r.dataset.i];
    if (el.dataset.opt !== undefined) { fd.options[+el.dataset.opt] = el.value; return; }
    if (el.dataset.k === 'label' || el.dataset.k === 'help') fd[el.dataset.k] = el.value;
  });
  C.addEventListener('change', e => { const el = e.target, r = el.closest('[data-i]'); if (!r || el.dataset.k !== 'type') return; const fd = F.fields[+r.dataset.i]; fd.type = el.value; if (fd.type === 'select' && !(fd.options || []).length) fd.options = ['Opción 1', 'Opción 2']; draw(); });
  C.addEventListener('click', e => {
    if (e.target.closest('#fm-ic')) { iconOpen = !iconOpen; $('fm-pick').hidden = !iconOpen; return; }
    const c = e.target.closest('[data-c]'); if (c) { F.color = c.dataset.c; draw(); return; }
    const ic = e.target.closest('[data-ic]'); if (ic) { F.icon = ic.dataset.ic; draw(); return; }
    const add = e.target.closest('[data-addf]');
    if (add) { const k = add.dataset.addf; F.fields.push({ id: fid(), label: '', type: k, required: false, help: '', options: k === 'select' ? ['Opción 1', 'Opción 2'] : [] }); draw(F.fields.length - 1); return; }
    const r = e.target.closest('[data-i]'); if (!r) return; const i = +r.dataset.i, fd = F.fields[i];
    if (e.target.closest('[data-rm]')) { F.fields.splice(i, 1); draw(); return; }
    if (e.target.closest('[data-req]')) { fd.required = !fd.required; draw(); return; }
    if (e.target.closest('[data-optadd]')) { fd.options.push('Opción ' + (fd.options.length + 1)); draw(); C.querySelector(`[data-i="${i}"] [data-opt="${fd.options.length - 1}"]`)?.select(); return; }
    const orm = e.target.closest('[data-optrm]'); if (orm) { if (fd.options.length > 1) { fd.options.splice(+orm.dataset.optrm, 1); draw(); } else toast('Una lista necesita al menos una opción', true); }
  });
  // Ordenar arrastrando desde el asa
  C.addEventListener('dragstart', e => { const h = e.target.closest('[data-drag]'); if (!h) return; drag = +h.closest('[data-i]').dataset.i; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(drag)); h.closest('[data-i]').classList.add('drag'); });
  C.addEventListener('dragover', e => { if (drag < 0) return; const r = e.target.closest('[data-i]'); if (!r) return; e.preventDefault(); C.querySelectorAll('.wk-wy-f.over').forEach(x => x.classList.remove('over')); r.classList.add('over'); });
  C.addEventListener('drop', e => { if (drag < 0) return; const r = e.target.closest('[data-i]'); e.preventDefault(); const to = r ? +r.dataset.i : F.fields.length - 1; const [m] = F.fields.splice(drag, 1); F.fields.splice(to, 0, m); drag = -1; draw(); });
  C.addEventListener('dragend', () => { drag = -1; C.querySelectorAll('.drag,.over').forEach(x => x.classList.remove('drag', 'over')); });
  $('fm-team').addEventListener('change', () => { F.category_id = null; drawCats(); });
  $('fm-save').addEventListener('click', async () => {
    if (!F.name.trim()) { toast('Ponle un nombre al formulario', true); $('fm-name').focus(); return; }
    const bad = F.fields.findIndex(fd => !fd.label.trim());
    if (bad >= 0) { toast('Todas las preguntas necesitan un texto', true); C.querySelector(`[data-i="${bad}"] [data-k="label"]`)?.focus(); return; }
    try {
      const r = await api('form_save', { id: F.id, name: F.name, description: F.description, team_id: +$('fm-team').value, category_id: +$('fm-cat').value, priority: $('fm-prio').value,
        assignee_id: +$('fm-asg').value, board_id: +$('fm-board').value, active: $('fm-active').checked, icon: F.icon, color: F.color,
        fields: F.fields.map(fd => ({ ...fd, id: /^n/.test(fd.id) ? '' : fd.id, options: (fd.options || []).map(o => o.trim()).filter(Boolean) })) });
      toast('Formulario guardado'); if (F.id) renderForms(F.id); else location.hash = 'solicitudes/formularios/' + r.id;
    } catch (e) { toast(e.message, true); }
  });
  $('fm-del')?.addEventListener('click', async () => {
    if (!confirm('¿Borrar este formulario?')) return;
    try { const r = await api('form_del', { id: F.id }); toast(r.msg || 'Formulario borrado'); location.hash = 'solicitudes/formularios'; } catch (e) { toast(e.message, true); }
  });
  drawCats(); draw();
}

async function renderRequest(id) {
  const r0 = await api('request_get', { id });
  const r = r0.request; W.reqStatus = r0.status;
  const s = rqSt(r.status), req = userOf(r.requester_id);
  const ans = r.form_fields.map(fd => { let v = r.answers[fd.id] ?? ''; if (v === '') return ''; if (fd.type === 'person') v = userOf(+v)?.name || v; if (fd.type === 'check') v = 'Sí';
    return `<div class="k">${esc(fd.label)}</div><div class="v">${fd.type === 'url' ? `<a class="wk-link" href="${esc(v)}" target="_blank" rel="noopener">${esc(v)}</a>` : esc(v)}</div>`; }).join('');
  const closed = ['rechazada', 'cerrada', 'convertida'].includes(r.status);
  $('wk-main').innerHTML = `<a class="wk-link" href="#solicitudes${r.agent && r.requester_id !== W.me.id ? '/bandeja' : ''}"><i class="fa-solid fa-arrow-left"></i> Solicitudes</a>
    <div class="wk-h" style="margin-top:8px"><div><h1>${esc(r.title)}<span>.</span></h1>
      <p><span class="wk-pill" style="--c:${s.color}">${esc(s.name)}</span> · #${r.id} · ${esc(r.form_name || '')} · pidió <b>${esc(req?.name || '')}</b> ${ago(r.created_at)}</p></div></div>
    <div class="wk-2" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr)">
      <div><div class="wk-card"><h3><i class="fa-solid fa-list"></i>Lo que pidió</h3><div class="wk-kv">${ans || '<div class="wk-help">Sin datos extra.</div>'}</div>
        ${r.decision_note ? `<div class="wk-note"><b>Decisión:</b> ${esc(r.decision_note)}</div>` : ''}
        ${r.task_id ? `<a class="wk-btn" style="margin-top:12px" href="/toolz/tareas.php?t=${r.task_id}"><i class="fa-solid fa-list-check"></i> Ver la tarea</a>` : ''}${r.project_id ? `<a class="wk-btn" style="margin-top:12px" href="#proyectos/${r.project_id}"><i class="fa-solid fa-diagram-project"></i> Ver el proyecto</a>` : ''}</div>
        <div class="wk-card" style="margin-top:14px"><h3><i class="fa-regular fa-comments"></i>Conversación</h3><div class="wk-conv">${convHtml(r.msgs, r.agent)}</div>${replyBox(r.agent)}</div></div>
      <div>${r.agent ? `<div class="wk-card"><h3><i class="fa-solid fa-gavel"></i>Atender</h3>
          <label class="wk-f"><span>Estado</span><select class="wk-in" id="rq-st">${Object.entries(W.reqStatus).filter(([k]) => k !== 'convertida' || r.status === 'convertida').map(([k, v]) => `<option value="${k}"${k === r.status ? ' selected' : ''}${k === 'convertida' ? ' disabled' : ''}>${esc(v[0])}</option>`).join('')}</select></label>
          <label class="wk-f" id="rq-note-w" hidden><span>Motivo (lo ve quien pidió)</span><textarea class="wk-in" id="rq-note" rows="2" maxlength="1000"></textarea></label>
          <label class="wk-f"><span>Responsable</span><select class="wk-in" id="rq-asg"><option value="0">Sin responsable</option>${W.users.map(u => `<option value="${u.id}"${u.id === r.assignee_id ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label>
          <label class="wk-f"><span>Prioridad</span><select class="wk-in" id="rq-pr">${W.cfg.priorities.map(p => `<option value="${p.id}"${p.id === r.priority ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
          <button class="wk-btn primary" type="button" id="rq-save">Guardar</button></div>
        ${!r.task_id && !r.project_id && r.status !== 'rechazada' ? `<div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-wand-magic-sparkles"></i>Convertir</h3>
          <label class="wk-f"><span>En una tarea del tablero</span><select class="wk-in" id="rq-board">${r0.boards.map(b => `<option value="${b.id}"${b.id === r.form_board ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>
          <label class="wk-f"><span>Fecha límite (opcional)</span><input class="wk-in" type="date" id="rq-due"></label>
          <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="wk-btn primary" type="button" id="rq-task"><i class="fa-solid fa-list-check"></i> Crear tarea</button><button class="wk-btn" type="button" id="rq-proj"><i class="fa-solid fa-diagram-project"></i> Crear proyecto</button></div>
          <div class="wk-help">Las respuestas del formulario pasan a la descripción. Quien pidió recibe el aviso.</div></div>` : ''}`
        : `<div class="wk-card"><h3><i class="fa-solid fa-circle-info"></i>Estado</h3><p class="wk-help" style="margin:0">${r.status === 'nueva' ? 'Tu pedido llegó. Te avisamos cuando alguien lo tome.' : r.status === 'convertida' ? 'Tu pedido ya está en marcha.' : 'Sigue la conversación acá: te llega un aviso con cada novedad.'}</p>
          ${!closed ? '<button class="wk-btn" type="button" id="rq-cancel" style="margin-top:12px"><i class="fa-solid fa-xmark"></i> Ya no lo necesito</button>' : ''}</div>`}</div></div>`;
  const reload = () => renderRequest(id);
  bindReply('request_msg', id, reload);
  $('rq-st')?.addEventListener('change', e => { $('rq-note-w').hidden = !['aprobada', 'rechazada'].includes(e.target.value); });
  $('rq-save')?.addEventListener('click', async () => {
    try { await api('request_update', { id, status: $('rq-st').value, note: $('rq-note').value, assignee_id: +$('rq-asg').value, priority: $('rq-pr').value }); toast('Solicitud actualizada'); reload(); }
    catch (e) { toast(e.message, true); }
  });
  $('rq-task')?.addEventListener('click', async () => {
    try { const x = await api('request_convert', { id, to: 'task', board_id: +$('rq-board').value, due_date: $('rq-due').value, assignee_id: +$('rq-asg')?.value || 0 }); toast('Tarea creada'); reload(); }
    catch (e) { toast(e.message, true); }
  });
  $('rq-proj')?.addEventListener('click', async () => {
    if (!confirm('¿Crear un proyecto con esta solicitud?')) return;
    try { const x = await api('request_convert', { id, to: 'project' }); toast('Proyecto creado'); location.hash = 'proyectos/' + x.project_id; }
    catch (e) { toast(e.message, true); }
  });
  $('rq-cancel')?.addEventListener('click', async () => {
    if (!confirm('¿Cerrar tu solicitud?')) return;
    try { await api('request_msg', { id, body: 'Ya no lo necesito: se puede cerrar.' }); toast('Avisamos al departamento'); reload(); } catch (e) { toast(e.message, true); }
  });
}
// ── TICKETS (fase 4) ─────────────────────────────────────────
function slaChip(due, doneAt, label, now, paused = false) {
  if (!due) return '';
  if (doneAt) return `<span class="wk-sla ok" title="${label}: cumplido"><i class="fa-solid fa-check"></i>${label}</span>`;
  if (paused) return `<span class="wk-sla paused" title="${label}: el reloj está frenado mientras se espera respuesta"><i class="fa-solid fa-pause"></i>${label} en pausa</span>`;
  const ms = new Date(due.replace(' ', 'T')) - new Date(now.replace(' ', 'T'));
  const abs = Math.abs(ms) / 6e4, txt = abs < 60 ? Math.round(abs) + ' min' : abs < 1440 ? Math.round(abs / 60) + ' h' : Math.round(abs / 1440) + ' d';
  return `<span class="wk-sla ${ms < 0 ? 'late' : ms < 36e5 ? 'soon' : ''}" title="${label} · vence ${due.slice(0, 16).replace('T', ' ')}"><i class="fa-regular fa-clock"></i>${label} ${ms < 0 ? 'vencido hace ' + txt : 'en ' + txt}</span>`;
}
// Filtros de la lista de Tickets: quedan mientras se navega por Trabajo
W.tkF = W.tkF || { status: 'abiertos', category: 0, priority: '', assignee: '', origin: '', team: 0, q: '', sort: 'due', dir: '', page: 1, since: 0, star: false,
  view: ['table', 'list', 'board'].includes(tkPref('view')) ? tkPref('view') : 'table', per: [25, 50, 100].includes(tkPref('per')) ? tkPref('per') : 50 };
const TK_SRC = { web: ['Demo web', 'fa-globe', '#a855f7'], sitio: ['Soporte de agencia', 'fa-satellite-dish', '#3b82f6'], ticket: ['Ticket interno', 'fa-ticket', '#64748b'], tarea: ['Tarea', 'fa-list-check', '#f97316'], form: ['Panel de solicitudes', 'fa-inbox', '#14b8a6'], chat: ['Chat', 'fa-comments', '#ec4899'], juego: ['Juego', 'fa-gamepad', '#22c55e'], social: ['Social Studio', 'fa-share-nodes', '#ec4899'] };
const TK_JR_XT = { label: 'Jugador', icon: 'fa-gamepad', color: '#22c55e', title: 'Jugador (escribió desde el juego)' };
const srcChip = t => { const s = TK_SRC[t.src] || TK_SRC.ticket; return `<i class="fa-solid ${s[1]}" title="${esc(s[0])}" style="opacity:.7"></i>`; };
// ── Piezas de Tickets v2 (íconos y colores; estilos tk-* en trabajo.css) ──
const tkSrcOf = t => TK_SRC[t.src || (t.base ? (t.base.source === 'web' ? 'web' : 'sitio') : t.origin)] || TK_SRC.ticket;   // en el detalle no viene src
const tkSrcIc = (t, sm = false) => { const s = tkSrcOf(t); return `<span class="tk-src${sm ? ' sm' : ''}" style="--s:${s[2]}" title="${esc(s[0])}"><i class="fa-solid ${s[1]}"></i></span>`; };
const tkChip = (c, html, cls = '', title = '') => `<span class="tk-chip ${cls}" style="--c:${esc(c)}"${title ? ` title="${esc(title)}"` : ''}>${html}</span>`;
const tkStChip = k => { const s = tkSt(k); return tkChip(s.color, esc(s.name), 'dot'); };
const tkPrioChip = id => { const p = prioOf(id); return tkChip(p.color, `<i class="fa-solid fa-flag"></i>${esc(p.name)}`, '', 'Prioridad'); };
const tkCatChip = t => t.cat_name ? tkChip(t.cat_color || '#888', `<i class="fa-solid fa-tag"></i>${esc(t.cat_name)}`, '', 'Categoría') : '';
const tkEscChip = t => t.escalated ? tkChip('#ef4444', `<i class="fa-solid fa-arrow-up-wide-short"></i>Escalado${t.escalated > 1 ? ' ' + t.escalated : ''}`, '', 'Escalado a los jefes del departamento') : '';
const tkLeft = ms => { const a = Math.abs(ms) / 6e4, x = a < 60 ? Math.round(a) + ' min' : a < 1440 ? Math.round(a / 60) + ' h' : Math.round(a / 1440) + ' d'; return ms < 0 ? 'vencido hace ' + x : 'quedan ' + x; };
const tkDt = d => new Date(String(d).replace(' ', 'T'));
const tkDue = d => { const x = String(d); return x.slice(8, 10) + '/' + x.slice(5, 7) + ' ' + x.slice(11, 16); };
/** Plazo como chip de color: vencido (rojo), vence pronto (ámbar), en pausa (violeta), cumplido (verde). */
function tkSla(due, doneAt, label, now, paused = false) {
  if (!due) return '';
  if (doneAt) return tkChip('#22c55e', `<i class="fa-solid fa-check"></i>${label}`, '', label + ': cumplido');
  if (paused) return tkChip('#8b5cf6', `<i class="fa-solid fa-pause"></i>${label} en pausa`, '', 'El reloj está frenado mientras se espera respuesta');
  const ms = tkDt(due) - tkDt(now);
  return tkChip(ms < 0 ? '#ef4444' : ms < 36e5 ? '#f59e0b' : '#64748b', `<i class="fa-regular fa-clock"></i>${label} · ${tkLeft(ms)}`, '', label + ' · vence ' + tkDue(due));
}
/** Barra de un plazo: cuánto del tiempo ya pasó, con el color según cómo viene. */
function tkMeter(label, icon, start, due, doneAt, now, paused) {
  if (!due) return '';
  const t0 = +tkDt(start), t1 = +tkDt(due), tn = +tkDt(doneAt || now), late = tn > t1;
  const p = Math.max(0, Math.min(1, (tn - t0) / Math.max(1, t1 - t0)));
  const c = doneAt ? (late ? '#ef4444' : '#22c55e') : paused ? '#8b5cf6' : late ? '#ef4444' : p > .75 ? '#f59e0b' : '#22c55e';
  const txt = doneAt ? (late ? 'Fuera de plazo' : 'A tiempo') : paused ? 'En pausa' : tkLeft(t1 - tn).replace(/^./, m => m.toUpperCase());
  return `<div class="tk-meter" style="--c:${c};--w:${doneAt || late ? 100 : Math.max(3, Math.round(p * 100))}%"><div class="l"><span><i class="fa-solid ${icon}"></i>${label}</span><b class="tk-ink">${txt}</b></div>
    <div class="track"><div class="fill"></div></div><div class="due">${doneAt ? 'Cumplido ' + ago(doneAt) + ' · plazo ' + tkDue(due) : 'Vence ' + tkDue(due)}</div></div>`;
}
function tkEmpty(filtered) {
  return `<div class="tk-empty"><div class="ic"><i class="fa-solid ${filtered ? 'fa-filter-circle-xmark' : 'fa-mug-hot'}"></i></div><b>${filtered ? 'No hay tickets con estos filtros' : 'Todo al día'}</b>
    <p>${filtered ? 'Prueba con otros filtros o límpialos para ver todo.' : 'No hay tickets acá por ahora.'}</p>${filtered ? '<button class="wk-btn" type="button" data-tkclear><i class="fa-solid fa-xmark"></i> Limpiar filtros</button>' : ''}</div>`;
}
/** Día legible para separar la conversación: Hoy, Ayer o "12 nov". */
function tkDayLabel(d) {
  const day = String(d).slice(0, 10), y = new Date(tkDt(W.today + ' 00:00:00') - 864e5).toISOString().slice(0, 10);
  return day === W.today ? 'Hoy' : day === y ? 'Ayer' : tkDay(day);
}
/** Un evento ("pasó a En curso · lo asignó a Pablo") en piezas con ícono y color: el estado nuevo con su color, asignación, prioridad, escalado. */
function tkEvParts(body) {
  const names = Object.entries(W.tkStatus || {});
  return String(body).split(' · ').map(part => {
    const m = part.match(/^(.*?)pasó a (.+)$/);
    if (m) { const st = names.find(([, v]) => v[0] === m[2].trim()); const c = st ? st[1][1] : '#64748b';
      return `<span class="tk-evp" style="--c:${esc(c)}"><i class="fa-solid ${st ? TK_ST_IC[st[0]] || 'fa-circle-dot' : 'fa-circle-dot'}"></i>${esc(m[1])}pasó a ${tkChip(c, esc(m[2]), 'dot')}</span>`; }
    const ic = /asign/.test(part) ? ['fa-user-check', '#8b5cf6'] : /sacó el responsable/.test(part) ? ['fa-user-slash', '#94a3b8'] : /prioridad/.test(part) ? ['fa-flag', '#f59e0b']
      : /escaló|escalado|vencido|venció/i.test(part) ? ['fa-arrow-up-wide-short', '#ef4444'] : /abrió|creó/.test(part) ? ['fa-plus', '#22c55e'] : /tarea/.test(part) ? ['fa-list-check', '#f97316'] : ['fa-circle-dot', '#94a3b8'];
    return `<span class="tk-evp" style="--c:${ic[1]}"><i class="fa-solid ${ic[0]}"></i>${esc(part)}</span>`;
  }).join('');
}
/** Conversación tipo chat: separadores por día, mensajes seguidos del mismo autor agrupados, lo propio a la derecha,
 *  la agencia en azul, las notas internas en ámbar y los eventos con el color de lo que cambió. */
function tkConvHtml(msgs, xt = { label: 'Agencia', icon: 'fa-building', color: '#3b82f6', title: 'Agencia o contacto externo' }) {
  let lastDay = '', prev = null;
  return msgs.map(m => {
    let out = '';
    const day = String(m.created_at).slice(0, 10);
    if (day !== lastDay) { out += `<div class="tk-day"><span>${tkDayLabel(m.created_at)}</span></div>`; lastDay = day; prev = null; }
    const u = m.user_id ? userOf(m.user_id) : null, ext = !m.user_id && m.ext_name ? m.ext_name : '';
    const hm = String(m.created_at).slice(11, 16), who = u?.name || ext || 'Automático';
    if (m.kind === 'event') { prev = null;
      return out + `<div class="tk-ev2${m.internal ? ' int' : ''}" title="${esc(m.created_at)}">${u ? av(u, 20) : `<span class="tk-evav"><i class="fa-solid ${ext ? xt.icon : 'fa-robot'}"></i></span>`}<span class="tx"><b>${esc(who)}</b> ${tkEvParts(m.body)}${m.internal ? ' <i class="fa-solid fa-lock" title="Solo lo ve el equipo"></i>' : ''}</span><small>${hm}</small></div>`; }
    if (m.kind === 'auto') { prev = null;   // respuesta automática del área (tk_canned_lib.php)
      return out + `<div class="tk-msg auto"><span class="tk-xav" title="Respuesta automática"><i class="fa-solid fa-robot"></i></span><div class="bub"><div class="h"><b>Respuesta automática</b>${tkChip('#8b5cf6', '<i class="fa-solid fa-bolt"></i>Automática')}</div><div class="t">${esc(m.body)}</div><div class="ft"><time datetime="${esc(m.created_at)}">${hm}</time></div></div></div>`; }
    const key = (m.user_id || ext) + '|' + m.internal, cont = prev && prev.key === key && tkDt(m.created_at) - tkDt(prev.at) < 10 * 6e4;
    prev = { key, at: m.created_at };
    const sync = m.failed ? tkChip('#ef4444', '<i class="fa-solid fa-triangle-exclamation"></i>No llegó al base', '', 'El sitio base lo rechazó') : m.pending ? tkChip('#f59e0b', '<i class="fa-solid fa-hourglass-half"></i>Pendiente de enviar al base', '', 'Se reintenta solo') : '';
    const cls = m.internal ? ' int' : ext ? ' ext' : m.user_id === W.me.id ? ' me' : '';
    return out + `<div class="tk-msg${cls}${cont ? ' cont' : ''}">${cont ? '<span class="tk-avsp"></span>' : ext ? `<span class="tk-xav" title="${esc(xt.title)}"><i class="fa-solid ${xt.icon}"></i></span>` : av(u, 32)}
      <div class="bub">${cont ? '' : `<div class="h"><b>${esc(u?.name || ext)}</b>${m.internal ? tkChip('#f59e0b', '<i class="fa-solid fa-lock"></i>Nota interna') : ext ? tkChip(xt.color, `<i class="fa-solid ${xt.icon}"></i>${esc(xt.label)}`) : ''}${sync}</div>`}
        <div class="t">${tkRich(m.body)}</div><div class="ft">${cont && sync ? sync : ''}<time datetime="${esc(m.created_at)}" title="${esc(m.created_at)}">${hm}</time>${m.user_id === W.me.id && !m.internal ? '<i class="fa-solid fa-check-double" title="Enviado"></i>' : ''}</div></div></div>`;
  }).join('');
}
/** Para escribir: respuesta pública o nota interna; "Enviar" con opciones para cambiar el estado al mismo tiempo. */
function tkReplyBox(agent, help) {
  const thenOpts = agent ? Object.entries(W.tkStatus).filter(([k]) => k !== 'abierto').map(([k, v]) => `<button type="button" role="menuitem" data-then="${k}" style="--c:${esc(v[1])}"><i class="fa-solid ${TK_ST_IC[k] || 'fa-circle-dot'}"></i>Enviar y pasar a ${esc(v[0])}</button>`).join('') : '';
  return `<form class="tk-compose" id="wk-reply">
    ${agent ? `<div class="row top"><div class="tk-seg" role="group" aria-label="Tipo de mensaje"><button type="button" class="on" data-mode="pub"><i class="fa-solid fa-reply"></i> Respuesta</button><button type="button" data-mode="int"><i class="fa-solid fa-lock"></i> Nota interna</button></div>
      <input type="checkbox" name="internal" hidden></div>` : ''}
    <textarea name="body" rows="3" placeholder="Escribe una respuesta…" aria-label="Mensaje"></textarea>
    <div class="row">${agent ? '<button type="button" class="tk-b" data-canned aria-haspopup="menu" title="Respuestas pre armadas"><i class="fa-solid fa-bolt"></i> Respuestas rápidas</button><span class="tk-then" hidden></span>' : ''}${window.CCRefs ? '<button type="button" class="tk-b" data-tkref data-refpk-open title="Vincular un contenido, cliente, tarea o ticket" aria-label="Vincular un contenido, cliente, tarea o ticket"><i class="fa-solid fa-link"></i> Vincular</button>' : ''}<span class="kb"><kbd>Ctrl</kbd> + <kbd>Enter</kbd> envía</span><span style="flex:1"></span>
      <div class="tk-send"><button class="tk-b primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar</button>${thenOpts ? `<button class="tk-b primary more" type="button" aria-label="Enviar y cambiar el estado" aria-haspopup="menu"><i class="fa-solid fa-chevron-up"></i></button><div class="tk-sendm" role="menu" hidden>${thenOpts}</div>` : ''}</div></div>
    ${help ? `<div class="wk-help" data-help style="margin-top:8px">${help}</div>` : ''}</form>`;
}
/** Texto de una respuesta: escapado, con @menciones resaltadas y [[tipo:id]] como tarjetitas (chat/refs.js). */
function tkRich(body) {
  let h = esc(body);
  h = h.replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]'"]/g, u => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);   // links clicables (p. ej. los de OpenCloud)
  h = h.replace(/(^|[^\w.])@([A-Za-z0-9_.\-]{2,40})/g, (m0, pre, un) => { const u = W.users.find(x => (x.username || '').toLowerCase() === un.toLowerCase()); return u ? `${pre}<span class="tk-at" title="${esc(u.name)}">@${esc(u.name)}</span>` : m0; });
  return window.CCRefs ? CCRefs.fmt(h) : h;
}
/** Colaboradores del ticket: los ven y responden, reciben los avisos; no ven las notas internas (tk_collab_lib.php). */
function tkCollabCard(t) {
  const ids = t.collabs || [], me = W.me.id;
  const others = W.users.filter(u => !ids.includes(u.id) && u.id !== t.requester_id && u.id !== t.assignee_id);
  return `<div class="wk-card" id="tk-collab" style="margin-top:14px;--c:#14b8a6"><h3><i class="fa-solid fa-user-group"></i>Colaboradores<span class="sp"></span><small>${ids.length || ''}</small></h3>
    <div class="tk-cols">${ids.length ? ids.map(id => { const u = userOf(id) || { id, name: 'Usuario' };
      return `<span class="tk-col">${av(u, 24)}<span>${esc(u.name)}${id === me ? ' <small>(tú)</small>' : ''}</span>${t.can_collab || id === me ? `<button type="button" data-colrm="${id}" title="${id === me ? 'Dejar de colaborar' : 'Quitar'}" aria-label="${id === me ? 'Dejar de colaborar' : 'Quitar a ' + esc(u.name)}"><i class="fa-solid fa-xmark"></i></button>` : ''}</span>`; }).join('')
      : '<p class="wk-help" style="margin:0">Suma a quien tenga que seguir este pedido: lo ve, responde y le llegan los avisos. También puedes mencionar con @ en una respuesta.</p>'}</div>
    ${t.can_collab ? `<select class="wk-in" id="tk-coladd" style="margin-top:10px" aria-label="Sumar colaborador"><option value="">Sumar colaborador…</option>${others.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select>` : ''}</div>`;
}
function bindTkCollab(t, reload) {
  const box = $('tk-collab'); if (!box) return;
  const save = async (op, user_id) => {
    try { await api('ticket_collab', { id: t.id, op, user_id }); toast(op === 'add' ? 'Colaborador sumado' : user_id === W.me.id ? 'Ya no colaboras en este ticket' : 'Colaborador quitado');
      if (op === 'remove' && user_id === W.me.id && !t.agent && t.requester_id !== W.me.id) { location.hash = '#tickets'; return; } reload(); }
    catch (e) { toast(e.message, true); }
  };
  $('tk-coladd')?.addEventListener('change', e => { if (+e.target.value) save('add', +e.target.value); });
  box.addEventListener('click', e => { const b = e.target.closest('[data-colrm]'); if (b) save('remove', +b.dataset.colrm); });
}
/** @ en el escritor: lista de personas para mencionar (inserta @usuario). */
function bindTkMention(ta) {
  let box = null, st = null;
  const close = () => { box?.remove(); box = null; st = null; };
  const draw = () => {
    if (!box) { box = document.createElement('div'); box.className = 'tk-mpop'; box.setAttribute('role', 'listbox'); ta.parentNode.style.position = 'relative'; ta.parentNode.appendChild(box);
      box.addEventListener('mousedown', e => { const b = e.target.closest('[data-mi]'); if (b) { e.preventDefault(); pick(+b.dataset.mi); } }); }
    box.innerHTML = st.opts.map((u, i) => `<button type="button" role="option" data-mi="${i}" class="${i === st.i ? 'on' : ''}" aria-selected="${i === st.i}">${av(u, 22)}<span><b>${esc(u.name)}</b><small>@${esc(u.username)}</small></span></button>`).join('');
    box.style.top = (ta.offsetTop + ta.offsetHeight + 4) + 'px'; box.style.left = ta.offsetLeft + 'px';
  };
  const pick = i => {
    const u = st.opts[i], end = ta.selectionStart, ins = '@' + u.username + ' ';
    ta.value = ta.value.slice(0, st.start) + ins + ta.value.slice(end);
    const p = st.start + ins.length; ta.setSelectionRange(p, p); ta.focus(); close();
  };
  ta.addEventListener('input', () => {
    const m = /(^|\s)@([\p{L}\p{N}_.\-]*)$/u.exec(ta.value.slice(0, ta.selectionStart));
    if (!m) return close();
    const q = m[2].toLowerCase();
    const opts = W.users.filter(u => u.id !== W.me.id && ((u.name || '').toLowerCase().includes(q) || (u.username || '').toLowerCase().includes(q))).slice(0, 6);
    if (!opts.length) return close();
    st = { start: ta.selectionStart - m[2].length - 1, opts, i: Math.min(st ? st.i : 0, opts.length - 1) }; draw();
  });
  ta.addEventListener('keydown', e => {
    if (!st) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); st.i = (st.i + (e.key === 'ArrowDown' ? 1 : -1) + st.opts.length) % st.opts.length; draw(); }
    else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopImmediatePropagation(); pick(st.i); }
    else if (e.key === 'Escape') { e.stopPropagation(); close(); }
  }, true);
  ta.addEventListener('blur', () => setTimeout(close, 150));
}
function bindTkCompose(help, id, done) {
  const f = $('wk-reply'); if (!f) return;
  bindTkMention(f.body);
  // Vincular un contenido (o cliente, tarea, ticket) en el texto: queda [[tipo:id]] y se ve como tarjetita
  f.querySelector('[data-tkref]')?.addEventListener('click', e => CCRefs.picker(e.currentTarget, api, tok => {
    const ta = f.body, s = ta.selectionStart ?? ta.value.length, pre = ta.value.slice(0, s), ins = (pre && !/\s$/.test(pre) ? ' ' : '') + tok + ' ';
    ta.value = pre + ins + ta.value.slice(ta.selectionEnd ?? s); const p = s + ins.length; ta.focus(); ta.setSelectionRange(p, p);
  }, { only: ['contenido', 'cliente', 'tarea', 'ticket'], sub: 'Contenidos, clientes, tareas o tickets' }));
  const menu = f.querySelector('.tk-sendm'), more = f.querySelector('.more');
  f.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => {
    const int = b.dataset.mode === 'int';
    f.querySelectorAll('[data-mode]').forEach(x => x.classList.toggle('on', x === b));
    f.classList.toggle('int', int); if (f.internal) f.internal.checked = int; if (more) more.hidden = int;
    f.body.placeholder = int ? 'Nota para el equipo: no la ve quien abrió el ticket…' : 'Escribe una respuesta…';
    const h = f.querySelector('[data-help]'); if (h) h.innerHTML = int ? '<i class="fa-solid fa-lock"></i> Solo la ve el equipo que atiende. Nunca sale de Trabajo.' : help;
    f.body.focus();
  }));
  const send = async then => {
    const body = f.body.value.trim(); if (!body) { f.body.focus(); return; }
    const btns = f.querySelectorAll('.tk-send button'); btns.forEach(b => { b.disabled = true; });
    const internal = !!f.internal?.checked;
    try {
      const rs = await api('ticket_msg', { id, body, internal });
      if (rs.skipped && rs.skipped.length) toast(`${rs.skipped.join(', ')} no ve${rs.skipped.length > 1 ? 'n' : ''} las notas internas: no se le${rs.skipped.length > 1 ? 's' : ''} avisó`, true);
      if (then && !internal) { await api('ticket_update', { id, status: then }); toast('Enviado · pasó a ' + tkSt(then).name); } else toast(internal ? 'Nota guardada' : 'Enviado');
      done();
    } catch (err) { toast(err.message, true); btns.forEach(b => { b.disabled = false; }); }
  };
  // Respuesta rápida: la escribe en el cuadro y, si corresponde, al enviar cambia el estado (se puede quitar)
  const thenChip = f.querySelector('.tk-then');
  const setThen = st => { f.dataset.then = st || ''; if (!thenChip) return; thenChip.hidden = !st; thenChip.innerHTML = st ? `<i class="fa-solid fa-arrow-right"></i> Al enviar pasa a <b>${esc(tkSt(st).name)}</b> <button type="button" aria-label="No cambiar el estado" title="No cambiar el estado"><i class="fa-solid fa-xmark"></i></button>` : ''; };
  thenChip?.addEventListener('click', e => { if (e.target.closest('button')) setThen(''); });
  f.querySelector('[data-canned]')?.addEventListener('click', e => {
    if (!W.tkCur) return;
    tkCannedMenu(e.currentTarget, W.tkCur, (text, st) => {
      const cur = f.body.value.trim(); f.body.value = cur ? cur + '\n\n' + text : text;
      f.body.dispatchEvent(new Event('input')); f.body.focus(); f.body.setSelectionRange(f.body.value.length, f.body.value.length);
      if (!f.internal?.checked) setThen(st);
    });
  });
  f.addEventListener('submit', e => { e.preventDefault(); send(f.internal?.checked ? '' : (f.dataset.then || '')); });
  f.body.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(f.internal?.checked ? '' : (f.dataset.then || '')); } });
  f.body.addEventListener('input', () => { f.body.style.height = 'auto'; f.body.style.height = Math.min(320, f.body.scrollHeight) + 'px'; });
  if (more && menu) {
    const close = () => { menu.hidden = true; more.setAttribute('aria-expanded', 'false'); document.removeEventListener('click', away, true); };
    const away = e => { if (!menu.contains(e.target) && e.target !== more) close(); };
    more.addEventListener('click', () => { if (!menu.hidden) return close(); menu.hidden = false; more.setAttribute('aria-expanded', 'true'); menu.querySelector('button')?.focus(); setTimeout(() => document.addEventListener('click', away, true)); });
    menu.addEventListener('keydown', e => { if (e.key === 'Escape') { close(); more.focus(); } });
    menu.querySelectorAll('[data-then]').forEach(b => b.addEventListener('click', () => { close(); send(b.dataset.then); }));
  }
}
/** Volver a dibujar después de una acción (cambiar estado, mover en el tablero, tomarlo…) sin que parezca una recarga:
 *  sin las animaciones de entrada de la vista y con el scroll donde estaba (la página y las columnas del tablero). */
async function wkQuiet(draw) {
  const M = $('wk-main'), sc = document.scrollingElement, y = sc ? sc.scrollTop : 0;
  const boxes = el => [...el.querySelectorAll('.wk-tkdrop, [data-col], .tkt-wrap, .wk-tkboard, .tk-board')];
  const keep = boxes(M).map(b => [b.scrollTop, b.scrollLeft]);
  M.classList.add('wk-quiet');
  try { await draw(); }
  finally {
    if (sc) sc.scrollTop = y;
    boxes(M).forEach((b, i) => { if (keep[i]) { b.scrollTop = keep[i][0]; b.scrollLeft = keep[i][1]; } });
    setTimeout(() => M.classList.remove('wk-quiet'), 60);
  }
}
async function renderTickets(arg) {
  if (arg === 'config') return renderTicketCfg();
  if (arg === 'calificaciones') return renderRatingsCfg();
  if (+arg) return renderTicket(+arg);
  const meta = await api('tk_meta'); W.tkStatus = meta.status;
  const F = W.tkF, box = W.tkBox || (meta.agent ? 'team' : 'mine'), table = F.view === 'table', board = F.view === 'board';
  const stars = tkStars();
  const req = { box, view: board ? 'board' : 'list', status: F.status, category: +F.category || 0, priority: F.priority, assignee: F.assignee, origin: F.origin, team: +F.team || 0, q: F.q, sort: F.sort, page: F.page, since: +F.since || 0, per: +F.per || 50 };
  if (table && F.dir) req.dir = F.dir;
  if (F.star) req.ids = [...stars];
  const r = await api('tickets', req);
  const c = r.counts;
  const who = t => t.ext_from || userOf(t.requester_id)?.name || '';
  const row = (t, i) => { const done = ['resuelto', 'cerrado'].includes(t.status), late = !done && !t.paused && t.resolution_due && t.resolution_due < r.now;
    return `<a class="tk-row${done ? ' done' : ''}${late ? ' late' : ''}" href="#tickets/${t.id}" style="--c:${esc(prioOf(t.priority).color)};--i:${Math.min(i, 20)}" title="Prioridad ${esc(prioOf(t.priority).name)}">
      ${tkSrcIc(t)}
      <span class="mid"><span class="top"><span class="tk-code">${tkCode(t.id)}</span><span class="tt">${esc(t.subject)}</span></span>
        <span class="tk-meta">${tkEscChip(t)}${tkCatChip(t)}${r.box !== 'mine' && who(t) ? `<span class="who"><i class="fa-regular fa-user"></i>${esc(who(t))}</span>` : ''}
          ${done ? '' : tkSla(t.first_response_due, t.first_response_at, 'Respuesta', r.now, t.paused) + tkSla(t.resolution_due, t.resolved_at, t.origin === 'tarea' ? 'Fecha' : 'Resolución', r.now, t.paused)}</span></span>
      <span class="end"><span class="asg">${t.assignee_id ? av(userOf(t.assignee_id), 26) : '<span class="none" title="Sin asignar"><i class="fa-solid fa-user"></i></span>'}</span>${tkStChip(t.status)}<span class="when" title="Última actividad">${ago(t.updated_at)}</span></span></a>`; };
  const card = (t, i) => `<a class="tk-card" href="#tickets/${t.id}" draggable="${meta.agent ? 'true' : 'false'}" data-tk="${t.id}" style="--c:${esc(prioOf(t.priority).color)};--i:${Math.min(i, 12)}">
      <span class="top">${tkSrcIc(t, true)}<span class="tk-code">${tkCode(t.id)}</span>${tkEscChip(t)}</span>
      <b>${esc(t.subject)}</b>
      <span class="m">${esc(t.cat_name || '')}${r.box !== 'mine' && who(t) ? ' · ' + esc(who(t)) : ''}</span>
      <span class="f">${t.assignee_id ? av(userOf(t.assignee_id), 22) : '<i class="m">sin asignar</i>'}${['resuelto', 'cerrado'].includes(t.status) ? '' : tkSla(t.resolution_due, t.resolved_at, t.origin === 'tarea' ? 'Fecha' : 'Plazo', r.now, t.paused)}</span></a>`;
  const cols = ['abierto', 'en_curso', 'esperando', 'resuelto'];
  const fmtM = m => m === null ? '—' : m < 60 ? m + ' min' : (m / 60).toFixed(1).replace('.0', '') + ' h';
  const opt = (v, cur, txt) => `<option value="${esc(v)}"${String(cur) === String(v) ? ' selected' : ''}>${esc(txt)}</option>`;
  const fsel = (id, icon, label, html) => `<label class="tkt-f" title="${esc(label)}"><i class="fa-solid ${icon}"></i><select id="${id}" aria-label="${esc(label)}">${html}</select><i class="fa-solid fa-chevron-down cv"></i></label>`;
  const tabs = [['mine', 'Los que abro', 'fa-paper-plane'], ['collab', 'Donde colaboro', 'fa-user-group'], ...(meta.agent ? [['team', 'Del departamento', 'fa-people-group'], ['assigned', 'Asignados a mí', 'fa-user-check'], ['unassigned', 'Sin asignar', 'fa-user-slash']] : []), ...(meta.agent && meta.base_on ? [['base', 'Del base', 'fa-satellite-dish']] : [])];
  const filtered = !!(F.q || F.assignee || F.origin || F.priority || +F.category || +F.team || +F.since || F.star || (!board && F.status !== 'abiertos'));
  const kpi = (c0, icon, label, val, sub, attrs = '', on = false, extra = '') => `<${attrs ? 'button type="button"' : 'div'} class="tk-kpi${on ? ' on' : ''}${extra}" style="--c:${c0}" ${attrs}><span class="ic"><i class="fa-solid ${icon}"></i></span><span class="tx"><small>${label}</small><b>${val}</b><span>${sub}</span></span></${attrs ? 'button' : 'div'}>`;
  const pager = `<div class="tkt-foot"><span>${r.total ? `${(r.page - 1) * (+F.per || 50) + 1}–${(r.page - 1) * (+F.per || 50) + r.tickets.length} de <b>${smNum(r.total)}</b> ticket${r.total === 1 ? '' : 's'}` : ''}</span><span style="flex:1"></span>
      ${!board ? `<label class="tkt-per">Por página <select id="tk-per">${[25, 50, 100].map(n => opt(n, +F.per || 50, n)).join('')}</select></label>` : ''}
      ${r.pages > 1 ? `<button class="wk-btn sm" type="button" data-pg="${r.page - 1}"${r.page <= 1 ? ' disabled' : ''} aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></button><span>${r.page} / ${r.pages}</span><button class="wk-btn sm" type="button" data-pg="${r.page + 1}"${r.page >= r.pages ? ' disabled' : ''} aria-label="Página siguiente"><i class="fa-solid fa-chevron-right"></i></button>` : ''}</div>`;
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Tickets<span>.</span></h1><p>Cada ticket es también una tarea en el tablero de su departamento: lo que se comenta en uno se ve en el otro. Los plazos (SLA) se frenan mientras se espera respuesta y, si se vencen, se escalan a los jefes del departamento.</p></div>
      <div class="wk-h-a">${meta.admin || (W.me.lead || []).length || W.me.role === 'director' ? '<a class="wk-btn" href="#tickets/calificaciones"><i class="fa-solid fa-star"></i> Calificaciones</a>' : ''}${meta.admin ? '<a class="wk-btn" href="#tickets/config"><i class="fa-solid fa-sliders"></i> Configuración</a>' : ''}<button class="wk-btn primary" type="button" id="tk-new"><i class="fa-solid fa-plus"></i> Nuevo ticket</button></div></div>
    ${c ? `<div class="tk-kpis">
      ${kpi('#3b82f6', 'fa-ticket', 'Abiertos', c.open || 0, 'del departamento', 'data-box="team"', r.box === 'team' && F.status === 'abiertos')}
      ${kpi('#f59e0b', 'fa-user-slash', 'Sin asignar', c.unassigned || 0, 'para tomar', 'data-box="unassigned"', r.box === 'unassigned')}
      ${kpi('#8b5cf6', 'fa-user-check', 'Míos', c.mine || 0, 'asignados a mí', 'data-box="assigned"', r.box === 'assigned')}
      ${kpi('#ef4444', 'fa-fire', 'SLA vencido', c.breached || 0, 'abiertos fuera de plazo', 'data-st="vencidos"', F.status === 'vencidos', c.breached ? ' alert' : '')}
      <div class="tk-kpi" style="--c:#22c55e"><span class="ic"><i class="fa-solid fa-bullseye"></i></span><span class="tx"><small>Resueltos a tiempo</small><b>${c.solved30 ? pct(c.ontime30, c.solved30) + '%' : '—'}</b><span>${c.solved30 || 0} resuelto${c.solved30 === 1 ? '' : 's'} en 30 días</span></span>${c.solved30 ? `<span class="bar" style="width:${pct(c.ontime30, c.solved30)}%"></span>` : ''}</div>
      <div class="tk-kpi" style="--c:#06b6d4"><span class="ic"><i class="fa-solid fa-bolt"></i></span><span class="tx"><small>Primera respuesta</small><b>${fmtM(c.frt)}</b><span>promedio, 30 días</span></span></div></div>` : ''}
    <div class="wk-bar" style="margin-top:16px">
      <div class="wk-tabs tk-tabs" style="margin:0">${tabs.map(([k, n, ic]) => `<button type="button" data-box="${k}" class="${r.box === k ? 'on' : ''}"><i class="fa-solid ${ic}"></i>${n}${k === 'base' && c?.base ? ` <b class="wk-badge">${c.base}</b>` : ''}</button>`).join('')}</div>
      <span style="flex:1"></span>
      <div class="wk-tabs" style="margin:0" role="group" aria-label="Vista">${[['table', 'Tabla', 'fa-table'], ['list', 'Lista', 'fa-list'], ['board', 'Tablero', 'fa-table-columns']].map(([k, n, ic]) => `<button type="button" data-view="${k}" class="${F.view === k ? 'on' : ''}" title="${n}"><i class="fa-solid ${ic}"></i> ${n}</button>`).join('')}</div></div>
    <div class="tkt-bar">
      <label class="tkt-search"><i class="fa-solid fa-magnifying-glass"></i><input id="tk-q" type="search" value="${esc(F.q)}" placeholder="Buscar asunto, texto, agencia o T-0012" aria-label="Buscar tickets"><kbd title="Atajo">/</kbd></label>
      ${board ? '' : fsel('tk-fs', 'fa-circle-half-stroke', 'Estado', opt('abiertos', F.status, 'Sin resolver') + opt('vencidos', F.status, 'SLA vencido') + Object.entries(meta.status).map(([k, s]) => opt(k, F.status, s[0])).join('') + opt('todos', F.status, 'Todos'))}
      ${fsel('tk-fd', 'fa-calendar-plus', 'Ingreso', opt(0, +F.since || 0, 'Cualquier ingreso') + opt(1, +F.since || 0, 'Ingresaron hoy') + opt(7, +F.since || 0, 'Últimos 7 días') + opt(30, +F.since || 0, 'Últimos 30 días'))}
      ${fsel('tk-fp', 'fa-flag', 'Prioridad', opt('', F.priority, 'Cualquier prioridad') + W.cfg.priorities.map(p => opt(p.id, F.priority, p.name)).join(''))}
      ${meta.agent ? fsel('tk-fa', 'fa-user', 'Responsable', opt('', F.assignee, 'Cualquier responsable') + opt('none', F.assignee, 'Sin asignar') + W.users.map(u => opt(u.id, F.assignee, u.name)).join('')) : ''}
      ${fsel('tk-fo', 'fa-satellite-dish', 'Origen', opt('', F.origin, 'Todo origen') + Object.entries(TK_SRC).filter(([k]) => meta.base_on || !['web', 'sitio'].includes(k)).map(([k, s]) => opt(k, F.origin, s[0])).join(''))}
      ${fsel('tk-fc', 'fa-tag', 'Categoría', opt(0, F.category, 'Toda categoría') + meta.cats.map(x => opt(x.id, F.category, x.name)).join(''))}
      ${meta.agent && meta.teams.length > 1 ? fsel('tk-ft', 'fa-people-group', 'Departamento', opt(0, F.team, 'Todo departamento') + meta.teams.map(t => opt(t.id, F.team, t.name)).join('')) : ''}
      ${table || board ? '' : fsel('tk-so', 'fa-arrow-down-wide-short', 'Orden', opt('due', F.sort, 'Vencimiento') + opt('prio', F.sort, 'Prioridad') + opt('updated', F.sort, 'Última actividad') + opt('created', F.sort, 'Más nuevos'))}
      <button type="button" class="tkt-f btn${F.star ? ' on' : ''}" id="tk-star" title="Solo los que destacaste con la estrella" aria-pressed="${F.star ? 'true' : 'false'}"><i class="fa-${F.star ? 'solid' : 'regular'} fa-star"></i>Destacados${stars.size ? ` <b>${stars.size}</b>` : ''}</button>
      ${filtered ? '<button class="tkt-f btn clear" type="button" id="tk-clear"><i class="fa-solid fa-xmark"></i>Limpiar</button>' : ''}
      <span style="flex:1"></span>
      ${table ? '<button type="button" class="tkt-f btn" id="tk-cols"><i class="fa-solid fa-table-columns"></i>Columnas</button><button type="button" class="tkt-f btn icon" id="tk-dense" title="Filas compactas o cómodas" aria-label="Densidad de las filas"><i class="fa-solid fa-' + (tkPref('dense') ? 'bars' : 'grip-lines') + '"></i></button>' : ''}</div>
    ${r.base ? baseBar(r.base) : ''}
    ${board ? `<div class="wk-tkboard">${cols.map(k => { const list = r.tickets.filter(t => t.status === k), s = tkSt(k);
        return `<section class="wk-tkcol tk-col" data-col="${k}" style="--c:${s.color}"><h3><span class="wk-dot" style="--c:${s.color}"></span>${esc(s.name)}<small>${list.length}</small></h3><div class="wk-tkdrop">${list.map(card).join('') || '<div class="wk-help" style="padding:8px">Sin tickets.</div>'}</div></section>`; }).join('')}</div>
        ${r.total > r.tickets.length ? `<div class="wk-help">Se muestran ${r.tickets.length} de ${r.total}: usa los filtros para acotar.</div>` : ''}<div class="wk-help">Los resueltos se ven 14 días. ${meta.agent ? 'Arrastra una tarjeta para cambiarle el estado.' : ''}</div>`
      : !r.tickets.length ? tkEmpty(filtered)
      : table ? tkTable(r, meta, F, stars) + pager
      : `<div class="tk-list">${r.tickets.map(row).join('')}</div>${pager}`}`;
  const M = $('wk-main'), go = (ch = {}) => { Object.assign(F, { page: 1 }, ch); renderTickets(); };
  M.querySelectorAll('[data-box]').forEach(b => b.addEventListener('click', () => { W.tkBox = b.dataset.box; if (b.classList.contains('tk-kpi')) F.status = 'abiertos'; go(); }));
  M.querySelectorAll('[data-st]').forEach(b => b.addEventListener('click', () => { W.tkBox = 'team'; go({ status: b.dataset.st, view: board ? 'table' : F.view }); }));
  M.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => { tkPref('view', b.dataset.view); go({ view: b.dataset.view }); }));
  M.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { F.page = +b.dataset.pg; renderTickets(); }));
  [['tk-fs', 'status'], ['tk-fa', 'assignee'], ['tk-fo', 'origin'], ['tk-fp', 'priority'], ['tk-fc', 'category'], ['tk-ft', 'team'], ['tk-so', 'sort'], ['tk-fd', 'since']].forEach(([id, k]) => $(id)?.addEventListener('change', e => go({ [k]: e.target.value })));
  $('tk-per')?.addEventListener('change', e => { tkPref('per', +e.target.value); go({ per: +e.target.value }); });
  let qt; $('tk-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => go({ q: e.target.value.trim() }), 350); });
  $('tk-q').addEventListener('keydown', e => { if (e.key === 'Enter') { clearTimeout(qt); go({ q: e.target.value.trim() }); } if (e.key === 'Escape') e.target.blur(); });
  if (F.q && document.activeElement === document.body) { const q = $('tk-q'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
  M.querySelectorAll('#tk-clear,[data-tkclear]').forEach(b => b.addEventListener('click', () => go({ status: 'abiertos', category: 0, priority: '', assignee: '', origin: '', team: 0, q: '', since: 0, star: false })));
  $('tk-star').addEventListener('click', () => go({ star: !F.star }));
  $('tk-new').addEventListener('click', () => ticketDialog(meta.cats.filter(x => x.active && x.id !== meta.task_cat)));
  $('tk-cols')?.addEventListener('click', () => tkColsDialog(meta));
  $('tk-dense')?.addEventListener('click', () => { tkPref('dense', !tkPref('dense')); renderTickets(); });
  if (table) bindTkTable(r, meta, F);
  if (board && meta.agent) bindTkBoard();
  bindBaseSync(() => renderTickets());
}

// ── Vista Tabla de Tickets (estilos tkt-*) ──
/** Preferencias de la tabla en este navegador: columnas, densidad, vista, tamaño de página y destacados. */
function tkPref(k, v) {
  let p = {}; try { p = JSON.parse(localStorage.getItem('wk_tk_prefs') || '{}') || {}; } catch (e) {}
  if (v === undefined) return p[k];
  p[k] = v; try { localStorage.setItem('wk_tk_prefs', JSON.stringify(p)); } catch (e) {}
  return v;
}
function tkStars() { return new Set((tkPref('stars') || []).map(Number)); }
function tkToggleStar(id) { const s = tkStars(); s.has(id) ? s.delete(id) : s.add(id); tkPref('stars', [...s].slice(-300)); return s.has(id); }
/** Fecha corta "12 nov" (o "12 nov 2025" si no es de este año). */
const tkDay = d => { if (!d) return ''; const y = d.slice(0, 4); return `${+d.slice(8, 10)} ${MON[+d.slice(5, 7) - 1]}${y !== W.today.slice(0, 4) ? ' ' + y : ''}`; };
/** Fecha límite con indicador: vencido (rojo), vence hoy (ámbar), en pausa, cumplido o cuánto falta. */
function tkDueCell(t, now) {
  const done = ['resuelto', 'cerrado'].includes(t.status), due = t.resolution_due;
  if (!due) return done ? tkChip('#22c55e', '<i class="fa-solid fa-check"></i>Resuelto') : '<span class="tkt-mute">Sin fecha límite</span>';
  const ms = tkDt(due) - tkDt(now), day = due.slice(0, 10), today = now.slice(0, 10);
  let chip;
  if (done) chip = t.resolved_at && t.resolved_at <= due ? tkChip('#22c55e', '<i class="fa-solid fa-check"></i>A tiempo') : tkChip('#f97316', '<i class="fa-solid fa-check"></i>Fuera de plazo');
  else if (t.paused) chip = tkChip('#8b5cf6', '<i class="fa-solid fa-pause"></i>En pausa');
  else if (ms < 0) chip = tkChip('#ef4444', `<i class="fa-solid fa-fire"></i>Vencido ${tkLeft(ms).replace('vencido hace ', '')}`, 'solid', 'Venció ' + tkDue(due));
  else if (day === today) chip = tkChip('#f59e0b', `<i class="fa-solid fa-hourglass-half"></i>Vence hoy ${due.slice(11, 16)}`);
  else chip = tkChip(ms < 864e5 * 2 ? '#eab308' : '#64748b', `<i class="fa-regular fa-clock"></i>En ${tkLeft(ms).replace('quedan ', '')}`, '', 'Vence ' + tkDue(due));
  return `<span class="tkt-due"><b>${tkDay(day)}</b>${chip}</span>`;
}
/** Antigüedad desde el ingreso: verde reciente, ámbar más de 3 días, rojo más de una semana (solo los abiertos). */
function tkAgeCell(t, now) {
  const days = Math.floor((tkDt(now) - tkDt(t.created_at)) / 864e5), done = ['resuelto', 'cerrado'].includes(t.status);
  const c = done ? '#94a3b8' : days >= 7 ? '#ef4444' : days >= 3 ? '#f59e0b' : '#22c55e';
  return `<span class="tkt-age" style="--c:${c}" title="Ingresó ${esc(t.created_at.slice(0, 16))}${done ? '' : ' · ' + (days ? days + ' día' + (days === 1 ? '' : 's') + ' abierto' : 'hoy')}"><i></i><b>${tkDay(t.created_at.slice(0, 10))}</b></span>`;
}
/** Columnas de la tabla. fixed = siempre visible. sort = columna que entiende la API. */
const TK_COLS = [
  { k: 'ticket', n: 'Ticket', fixed: true, sort: 'subject' },
  { k: 'from', n: 'Solicitante' },
  { k: 'status', n: 'Estado', sort: 'status' },
  { k: 'prio', n: 'Prioridad', sort: 'prio' },
  { k: 'due', n: 'Fecha límite', sort: 'due' },
  { k: 'first', n: '1.ª respuesta', sort: 'first', off: true },
  { k: 'created', n: 'Ingreso', sort: 'created' },
  { k: 'assignee', n: 'Responsable', sort: 'assignee' },
  { k: 'category', n: 'Categoría', sort: 'category' },
  { k: 'team', n: 'Departamento', sort: 'team', off: true },
  { k: 'origin', n: 'Origen', off: true },
  { k: 'task', n: 'Tarea', off: true },
  { k: 'updated', n: 'Actividad', sort: 'updated', off: true },
];
function tkColsOn() {
  const saved = tkPref('cols'), byK = Object.fromEntries(TK_COLS.map(c => [c.k, c]));
  if (!saved || !Array.isArray(saved.order)) return TK_COLS.filter(c => !c.off);
  const order = saved.order.filter(k => byK[k]).concat(TK_COLS.map(c => c.k).filter(k => !saved.order.includes(k)));
  return order.map(k => byK[k]).filter(c => c.fixed || !(saved.hidden || []).includes(c.k) && !(c.off && !saved.order.includes(c.k)));
}
function tkTable(r, meta, F, stars) {
  const cols = tkColsOn(), sel = meta.agent, now = r.now;
  const who = t => t.ext_from || userOf(t.requester_id)?.name || '';
  const sortTh = c => { const on = c.sort && F.dir && F.sort === c.sort;
    return `<th${c.sort ? ` class="s${on ? ' on' : ''}" data-sort="${c.sort}" tabindex="0" aria-sort="${on ? (F.dir === 'asc' ? 'ascending' : 'descending') : 'none'}"` : ''} data-col="${c.k}">${c.n}${c.sort ? `<i class="fa-solid fa-${on ? (F.dir === 'asc' ? 'arrow-up' : 'arrow-down') : 'sort'}"></i>` : ''}</th>`; };
  const cell = (c, t) => {
    const done = ['resuelto', 'cerrado'].includes(t.status), s = tkSt(t.status), p = prioOf(t.priority), src = tkSrcOf(t);
    // Quien atiende puede cambiarlo tocándolo (tkEdit); el resto lo ve como texto
    const ed = (k, html, tip) => sel ? `<button type="button" class="tkt-edit" data-edit="${k}" data-id="${t.id}" aria-haspopup="listbox" title="${tip}">${html}<i class="fa-solid fa-chevron-down cv"></i></button>` : html;
    switch (c.k) {
      case 'ticket': return `<td class="tkt-t"><a href="#tickets/${t.id}" class="tkt-link">${tkSrcIc(t, true)}<span class="x"><span class="tk-code">${tkCode(t.id)}</span><b title="${esc(t.subject)}">${esc(t.subject)}</b>${t.escalated ? ' <i class="fa-solid fa-arrow-up-wide-short tkt-esc" title="Escalado"></i>' : ''}</span></a></td>`;
      case 'from': { const u = t.ext_from ? null : userOf(t.requester_id);
        return `<td><span class="tkt-who" title="${esc(who(t))}">${t.ext_from ? `<span class="tk-xav sm"><i class="fa-solid ${t.origin === 'juego' ? 'fa-gamepad' : 'fa-building'}"></i></span>` : av(u, 24)}<span>${esc(who(t)) || '<span class="tkt-mute">—</span>'}</span></span></td>`; }
      case 'status': return `<td>${ed('status', `<span class="tkt-st" style="--c:${esc(s.color)}" title="${esc(s.name)}"><i class="fa-solid ${TK_ST_IC[t.status] || 'fa-circle-dot'}"></i>${esc(s.name.length > 12 ? s.name.split(' ')[0] : s.name)}</span>`, 'Cambiar el estado')}</td>`;
      case 'prio': return `<td>${ed('prio', `<span class="tkt-prio" style="--c:${esc(p.color)}"><i class="fa-solid fa-flag"></i>${esc(p.name)}</span>`, 'Cambiar la prioridad')}</td>`;
      case 'due': return `<td>${tkDueCell(t, now)}</td>`;
      case 'first': return `<td>${t.origin === 'tarea' ? '<span class="tkt-mute">—</span>' : t.first_response_at ? tkChip('#22c55e', '<i class="fa-solid fa-check"></i>Respondido') : done ? '<span class="tkt-mute">—</span>' : tkSla(t.first_response_due, null, 'Vence', now, t.paused) || '<span class="tkt-mute">—</span>'}</td>`;
      case 'created': return `<td>${tkAgeCell(t, now)}</td>`;
      case 'assignee': { const u = t.assignee_id ? userOf(t.assignee_id) : null;
        return `<td>${ed('assignee', u ? `<span class="tkt-who" title="${esc(u.name)}">${av(u, 24)}<span>${esc(u.name.split(' ')[0])}</span></span>` : '<span class="tkt-who none"><span class="tkt-nobody"><i class="fa-solid fa-user-plus"></i></span><span>Nadie</span></span>', u ? 'Cambiar el responsable' : 'Asignar a alguien')}</td>`; }
      case 'category': return `<td>${t.cat_name ? tkChip(t.cat_color || '#888', esc(t.cat_name)) : '<span class="tkt-mute">—</span>'}</td>`;
      case 'team': return `<td>${t.team_name ? `<span class="tkt-team"><i class="fa-solid fa-people-group"></i>${esc(t.team_name)}</span>` : '<span class="tkt-mute">—</span>'}</td>`;
      case 'origin': return `<td><span class="tkt-org" style="--c:${src[2]}"><i class="fa-solid ${src[1]}"></i>${esc(src[0])}</span></td>`;
      case 'task': return `<td>${t.task_id ? `<a class="tkt-ic" href="/toolz/tareas.php?t=${t.task_id}" title="Abrir su tarea" data-stop><i class="fa-solid fa-list-check"></i></a>` : '<span class="tkt-mute">—</span>'}</td>`;
      case 'updated': return `<td><span class="tkt-mute" title="${esc(t.updated_at)}">${ago(t.updated_at)}</span></td>`;
    }
    return '<td></td>';
  };
  const rows = r.tickets.map((t, i) => { const done = ['resuelto', 'cerrado'].includes(t.status), late = !done && !t.paused && t.resolution_due && t.resolution_due < now, star = stars.has(t.id);
    return `<tr class="tkt-r${done ? ' done' : ''}${late ? ' late' : ''}${t.status === 'cerrado' ? ' closed' : ''}" data-id="${t.id}" style="--c:${esc(prioOf(t.priority).color)};--i:${Math.min(i, 25)}">
      ${sel ? `<td class="tkt-ck"><input type="checkbox" data-ck="${t.id}" aria-label="Elegir ${tkCode(t.id)}"></td>` : '<td class="tkt-ck"></td>'}
      ${cols.map(c => cell(c, t)).join('')}
      <td class="tkt-act"><button type="button" class="tkt-ic star${star ? ' on' : ''}" data-star="${t.id}" title="${star ? 'Quitar de destacados' : 'Destacar'}" aria-pressed="${star}"><i class="fa-${star ? 'solid' : 'regular'} fa-star"></i></button><button type="button" class="tkt-ic" data-menu="${t.id}" title="Más acciones" aria-label="Más acciones"><i class="fa-solid fa-ellipsis"></i></button></td></tr>`; }).join('');
  return `<div class="tkt-bulk" id="tk-bulk" hidden><b id="tk-bulk-n">0</b> elegidos
      <button type="button" class="wk-btn sm" data-bulk="take"><i class="fa-solid fa-hand"></i> Tomarlos</button>
      <label class="tkt-f"><i class="fa-solid fa-circle-half-stroke"></i><select id="tk-bulk-st" aria-label="Cambiar estado"><option value="">Cambiar estado…</option>${Object.entries(meta.status).map(([k, s]) => `<option value="${k}">${esc(s[0])}</option>`).join('')}</select><i class="fa-solid fa-chevron-down cv"></i></label>
      <button type="button" class="wk-btn sm" data-bulk="star"><i class="fa-solid fa-star"></i> Destacar</button>
      <span style="flex:1"></span><button type="button" class="wk-btn sm" data-bulk="none"><i class="fa-solid fa-xmark"></i> Quitar selección</button></div>
    <div class="tkt-wrap${tkPref('dense') ? ' dense' : ''}"><table class="tkt">
      <thead><tr><th class="tkt-ck">${sel ? '<input type="checkbox" id="tk-ckall" aria-label="Elegir todos">' : ''}</th>${cols.map(sortTh).join('')}<th class="tkt-act" aria-label="Acciones"></th></tr></thead>
      <tbody>${rows}</tbody></table></div>
    <div class="tkt-legend"><span><i style="background:#ef4444"></i>Vencido</span><span><i style="background:#f59e0b"></i>Vence hoy</span><span><i style="background:#8b5cf6"></i>En pausa</span><span><i style="background:#22c55e"></i>A tiempo · ingreso reciente</span><span><i class="ln"></i>La franja de la izquierda es la prioridad</span></div>`;
}
function bindTkTable(r, meta, F) {
  const M = $('wk-main'), ids = () => [...M.querySelectorAll('[data-ck]:checked')].map(x => +x.dataset.ck);
  const upd = async (id, data) => api('ticket_update', { id, ...data });
  // Ordenar tocando el encabezado: primero descendente, después ascendente, después el orden de siempre
  M.querySelectorAll('th[data-sort]').forEach(th => { const go = () => { const k = th.dataset.sort;
      const next = F.sort === k && F.dir === 'desc' ? 'asc' : F.sort === k && F.dir === 'asc' ? '' : 'desc';
      Object.assign(F, { sort: next ? k : 'due', dir: next, page: 1 }); renderTickets(); };
    th.addEventListener('click', go); th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }); });
  // Toda la fila abre el ticket (menos casillas, estrella, menú y enlaces)
  M.querySelectorAll('tr.tkt-r').forEach(tr => tr.addEventListener('click', e => {
    if (e.target.closest('input,button,a,label,[data-stop]')) return;
    if (e.ctrlKey || e.metaKey) { open('#tickets/' + tr.dataset.id, '_blank'); return; }
    location.hash = 'tickets/' + tr.dataset.id; }));
  const bulk = () => { const n = ids().length; $('tk-bulk').hidden = !n; $('tk-bulk-n').textContent = n;
    M.querySelectorAll('tr.tkt-r').forEach(tr => tr.classList.toggle('sel', !!tr.querySelector('[data-ck]:checked'))); const all = $('tk-ckall'); if (all) { const c = M.querySelectorAll('[data-ck]').length; all.checked = n === c && c > 0; all.indeterminate = n > 0 && n < c; } };
  M.querySelectorAll('[data-ck]').forEach(x => x.addEventListener('change', bulk));
  $('tk-ckall')?.addEventListener('change', e => { M.querySelectorAll('[data-ck]').forEach(x => { x.checked = e.target.checked; }); bulk(); });
  const runBulk = async (label, fn) => { const list = ids(); if (!list.length) return; let ok = 0, err = '';
    for (const id of list) { try { await fn(id); ok++; } catch (e) { err = e.message; } }
    toast(err ? `${label}: ${ok} de ${list.length}. ${err}` : `${label}: ${ok} ticket${ok === 1 ? '' : 's'}`, !!err); wkQuiet(renderTickets); };
  M.querySelector('[data-bulk="take"]')?.addEventListener('click', () => runBulk('Tomados', id => { const t = r.tickets.find(x => x.id === id); return upd(id, { assignee_id: W.me.id, ...(t?.status === 'abierto' ? { status: 'en_curso' } : {}) }); }));
  $('tk-bulk-st')?.addEventListener('change', e => { const st = e.target.value; if (st) runBulk('Pasaron a ' + tkSt(st).name, id => upd(id, { status: st })); });
  M.querySelector('[data-bulk="star"]')?.addEventListener('click', () => { const s = tkStars(); ids().forEach(id => s.add(id)); tkPref('stars', [...s].slice(-300)); toast('Destacados'); renderTickets(); });
  M.querySelector('[data-bulk="none"]')?.addEventListener('click', () => { M.querySelectorAll('[data-ck]').forEach(x => { x.checked = false; }); bulk(); });
  M.querySelectorAll('[data-star]').forEach(b => b.addEventListener('click', () => { const on = tkToggleStar(+b.dataset.star);
    b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); b.title = on ? 'Quitar de destacados' : 'Destacar'; b.innerHTML = `<i class="fa-${on ? 'solid' : 'regular'} fa-star"></i>`;
    const n = tkStars().size, s = $('tk-star'); if (s) s.innerHTML = `<i class="fa-${F.star ? 'solid' : 'regular'} fa-star"></i>Destacados${n ? ` <b>${n}</b>` : ''}`; }));
  M.querySelectorAll('[data-menu]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); tkRowMenu(b, r.tickets.find(x => x.id === +b.dataset.menu), meta); }));
  M.querySelectorAll('[data-edit]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); tkEdit(b, r.tickets.find(x => x.id === +b.dataset.id), meta); }));
}
/**
 * Editar en la tabla: tocar el estado, la prioridad o el responsable abre un selector en el lugar y guarda enseguida (ticket_update).
 * Solo para quien atiende tickets. Teclado: flechas para moverse, Enter para elegir, Escape para cerrar; en personas se puede escribir para buscar.
 */
function tkEdit(anchor, t, meta, onDone = renderTickets) {
  document.querySelector('.tkt-menu')?.remove();
  if (!t) return;
  const kind = anchor.dataset.edit;
  let items = [], cur, title, search = false;
  if (kind === 'status') {
    title = 'Estado'; cur = t.status;
    items = Object.entries(W.tkStatus).map(([k, s]) => ({ v: k, c: s[1], icon: TK_ST_IC[k] || 'fa-circle-dot', n: s[0] }));
  } else if (kind === 'prio') {
    title = 'Prioridad'; cur = t.priority;
    items = W.cfg.priorities.slice().reverse().map(p => ({ v: p.id, c: p.color, icon: 'fa-flag', n: p.name }));
  } else {
    title = 'Responsable'; cur = t.assignee_id ? String(t.assignee_id) : '0'; search = true;
    const team = (meta.team_people || {})[t.team_id] || [], inTeam = new Set(team);
    const pool = t.team_id && !meta.assign_any ? W.users.filter(u => inTeam.has(u.id)) : W.users;
    const mine = pool.filter(u => inTeam.has(u.id)), rest = pool.filter(u => !inTeam.has(u.id));
    const pers = (u, grp) => ({ v: String(u.id), u, n: u.name + (u.id === W.me.id ? ' (yo)' : ''), grp });
    items = [{ v: '0', c: '#94a3b8', icon: 'fa-user-slash', n: 'Sin asignar' }];
    const me = pool.find(u => u.id === W.me.id);
    if (me && cur !== String(W.me.id)) items.push({ ...pers(me, ''), n: 'Asignármelo a mí', hint: true });
    items.push(...mine.map(u => pers(u, t.team_name ? 'Del departamento · ' + t.team_name : 'Del departamento')), ...rest.map(u => pers(u, mine.length ? 'Otras personas' : '')));
  }
  const m = document.createElement('div'); m.className = 'tkt-menu tkt-pick'; m.setAttribute('role', 'dialog'); m.setAttribute('aria-label', 'Cambiar ' + title.toLowerCase());
  const optHtml = list => { let g = null;
    return list.map((x, i) => { const head = x.grp !== undefined && x.grp !== g && x.grp ? `<div class="grp">${esc(x.grp)}</div>` : ''; if (x.grp !== undefined) g = x.grp;
      const on = x.v === cur && !x.hint;
      return head + `<button type="button" role="option" aria-selected="${on}" data-v="${esc(x.v)}" data-i="${i}" class="${on ? 'on' : ''}${x.hint ? ' hint' : ''}" style="--c:${esc(x.c || 'var(--g7-text-4)')}">
        ${x.u ? av(x.u, 22) : `<i class="fa-solid ${x.icon}"></i>`}<span>${esc(x.n)}</span>${on ? '<i class="fa-solid fa-check ok"></i>' : ''}</button>`; }).join('') || '<div class="none">Nadie coincide.</div>'; };
  m.innerHTML = `<div class="hd">${title} · ${tkCode(t.id)}</div>${search ? '<label class="srch"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Buscar persona…" aria-label="Buscar persona"></label>' : ''}<div class="opts" role="listbox">${optHtml(items)}</div>`;
  document.body.appendChild(m);
  const place = () => { const b = anchor.getBoundingClientRect(); const h = m.offsetHeight;
    m.style.left = Math.max(8, Math.min(innerWidth - m.offsetWidth - 8, b.left)) + 'px';
    m.style.top = (b.bottom + 4 + h > innerHeight - 8 ? Math.max(8, b.top - h - 4) : b.bottom + 4) + 'px'; };
  place();
  const box = m.querySelector('.opts'), q = m.querySelector('input');
  const opts = () => [...box.querySelectorAll('[data-v]')];
  (q || box.querySelector('.on') || opts()[0])?.focus();
  anchor.setAttribute('aria-expanded', 'true');
  const close = () => { m.remove(); anchor.setAttribute('aria-expanded', 'false'); document.removeEventListener('click', away, true); document.removeEventListener('keydown', keys, true); removeEventListener('scroll', onScroll, true); };
  const onScroll = e => { if (!m.contains(e.target)) close(); };   // si se mueve la página, el selector quedaría fuera de lugar
  const away = e => { if (!m.contains(e.target)) close(); };
  const keys = e => {
    if (e.key === 'Escape') { e.preventDefault(); close(); anchor.focus(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); const o = opts(), i = o.indexOf(document.activeElement); const n = e.key === 'ArrowDown' ? (i + 1) % o.length : i <= 0 ? o.length - 1 : i - 1; o[n]?.focus(); }
    if (e.key === 'Enter' && document.activeElement === q) { e.preventDefault(); opts()[0]?.click(); }
  };
  setTimeout(() => { document.addEventListener('click', away, true); document.addEventListener('keydown', keys, true); addEventListener('scroll', onScroll, true); });
  q?.addEventListener('input', () => { const s = q.value.trim().toLowerCase(); box.innerHTML = optHtml(s ? items.filter(x => x.v === '0' ? 'sin asignar'.includes(s) : x.n.toLowerCase().includes(s) && !x.hint) : items); bindOpts(); });
  const bindOpts = () => opts().forEach(o => o.addEventListener('click', async () => {
    const v = o.dataset.v; close();
    if (v === cur) return;
    const data = kind === 'status' ? { status: v } : kind === 'prio' ? { priority: v } : { assignee_id: +v };
    if (kind === 'assignee' && +v && t.status === 'abierto') data.status = 'en_curso';   // como "Tomarlo": al asignarlo empieza
    anchor.classList.add('saving'); anchor.disabled = true;
    try {
      await api('ticket_update', { id: t.id, ...data });
      toast(kind === 'status' ? `${tkCode(t.id)} pasó a ${tkSt(v).name}` : kind === 'prio' ? `${tkCode(t.id)}: prioridad ${prioOf(v).name}${prioOf(v).id !== t.priority ? ' (plazos recalculados)' : ''}` : +v ? `${tkCode(t.id)} asignado a ${userOf(+v)?.name || ''}` : `${tkCode(t.id)} quedó sin responsable`);
      wkQuiet(onDone);
    } catch (err) { toast(err.message, true); anchor.classList.remove('saving'); anchor.disabled = false; }
  }));
  bindOpts();
}
const TK_ST_IC = { abierto: 'fa-circle-dot', en_curso: 'fa-spinner', esperando: 'fa-hourglass-half', resuelto: 'fa-circle-check', cerrado: 'fa-lock' };
/** Menú "…" de una fila: abrir, tomarlo, cambiar el estado y copiar el enlace. */
function tkRowMenu(btn, t, meta) {
  document.querySelector('.tkt-menu')?.remove();
  if (!t) return;
  const m = document.createElement('div'); m.className = 'tkt-menu'; m.setAttribute('role', 'menu');
  const item = (act, icon, txt, c = '') => `<button type="button" role="menuitem" data-m="${act}"${c ? ` style="--c:${c}"` : ''}><i class="fa-solid ${icon}"></i>${txt}</button>`;
  m.innerHTML = item('open', 'fa-arrow-up-right-from-square', 'Abrir') + item('tab', 'fa-window-restore', 'Abrir en otra pestaña')
    + (meta.agent && t.assignee_id !== W.me.id ? item('take', 'fa-hand', 'Tomarlo') : '')
    + (meta.agent ? '<hr>' + Object.entries(W.tkStatus).filter(([k]) => k !== t.status).map(([k, s]) => item('st:' + k, k === 'cerrado' ? 'fa-lock' : k === 'resuelto' ? 'fa-circle-check' : 'fa-circle-dot', 'Pasar a ' + esc(s[0]), s[1])).join('') : '')
    + '<hr>' + item('link', 'fa-link', 'Copiar enlace');
  document.body.appendChild(m);
  const b = btn.getBoundingClientRect(); m.style.top = Math.min(innerHeight - m.offsetHeight - 8, b.bottom + 4) + 'px'; m.style.left = Math.max(8, b.right - m.offsetWidth) + 'px';
  m.querySelector('button')?.focus();
  const close = () => { m.remove(); document.removeEventListener('click', away, true); document.removeEventListener('keydown', esc_); };
  const away = e => { if (!m.contains(e.target)) close(); }, esc_ = e => { if (e.key === 'Escape') { close(); btn.focus(); } };
  setTimeout(() => { document.addEventListener('click', away, true); document.addEventListener('keydown', esc_); });
  m.addEventListener('click', async e => { const a = e.target.closest('[data-m]')?.dataset.m; if (!a) return; close();
    if (a === 'open') location.hash = 'tickets/' + t.id;
    else if (a === 'tab') open('#tickets/' + t.id, '_blank');
    else if (a === 'link') { try { await navigator.clipboard.writeText(location.origin + location.pathname + '#tickets/' + t.id); toast('Enlace copiado'); } catch (err) { toast('No se pudo copiar', true); } }
    else { try { await api('ticket_update', a === 'take' ? { id: t.id, assignee_id: W.me.id, ...(t.status === 'abierto' ? { status: 'en_curso' } : {}) } : { id: t.id, status: a.slice(3) });
      toast(a === 'take' ? 'Ahora lo atiendes tú' : `${tkCode(t.id)} pasó a ${tkSt(a.slice(3)).name}`); wkQuiet(renderTickets); } catch (err) { toast(err.message, true); } }
  });
}
/** Elegir y ordenar las columnas de la tabla (se guarda en este navegador). */
function tkColsDialog(meta) {
  let order = tkColsOn().map(c => c.k).concat(TK_COLS.map(c => c.k).filter(k => !tkColsOn().some(c => c.k === k)));
  let hidden = new Set(TK_COLS.map(c => c.k).filter(k => !tkColsOn().some(c => c.k === k)));
  const byK = Object.fromEntries(TK_COLS.map(c => [c.k, c]));
  const d = openDlg(`<h3><i class="fa-solid fa-table-columns" style="color:var(--g7-accent)"></i> Columnas de la tabla</h3><p class="wk-help" style="margin:0 0 12px">Elige qué ver y en qué orden. Se guarda en este navegador.</p><div class="tkt-cols" id="tc-list"></div>
    <div class="acts"><button class="wk-btn" type="button" id="tc-reset"><i class="fa-solid fa-rotate-left"></i> Restablecer</button><span style="flex:1"></span><button class="wk-btn" type="button" id="tc-cancel">Cancelar</button><button class="wk-btn primary" type="button" id="tc-ok">Guardar</button></div>`);
  const draw = () => { d.querySelector('#tc-list').innerHTML = order.map((k, i) => { const c = byK[k];
    return `<div class="tkt-colrow${hidden.has(k) ? ' off' : ''}"><label><input type="checkbox" data-k="${k}"${hidden.has(k) ? '' : ' checked'}${c.fixed ? ' disabled' : ''}> ${esc(c.n)}${c.fixed ? ' <small>(siempre)</small>' : ''}</label>
      <button type="button" class="tkt-ic" data-up="${i}" aria-label="Subir ${esc(c.n)}"${i ? '' : ' disabled'}><i class="fa-solid fa-chevron-up"></i></button><button type="button" class="tkt-ic" data-down="${i}" aria-label="Bajar ${esc(c.n)}"${i < order.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-chevron-down"></i></button></div>`; }).join('');
    d.querySelectorAll('[data-k]').forEach(x => x.addEventListener('change', () => { x.checked ? hidden.delete(x.dataset.k) : hidden.add(x.dataset.k); draw(); }));
    d.querySelectorAll('[data-up]').forEach(x => x.addEventListener('click', () => { const i = +x.dataset.up; [order[i - 1], order[i]] = [order[i], order[i - 1]]; draw(); d.querySelector(`[data-up="${i - 1}"]`)?.focus(); }));
    d.querySelectorAll('[data-down]').forEach(x => x.addEventListener('click', () => { const i = +x.dataset.down; [order[i + 1], order[i]] = [order[i], order[i + 1]]; draw(); d.querySelector(`[data-down="${i + 1}"]`)?.focus(); })); };
  draw();
  d.querySelector('#tc-cancel').addEventListener('click', closeDlg);
  d.querySelector('#tc-reset').addEventListener('click', () => { tkPref('cols', null); closeDlg(); renderTickets(); });
  d.querySelector('#tc-ok').addEventListener('click', () => { tkPref('cols', { order, hidden: [...hidden] }); closeDlg(); toast('Columnas guardadas'); renderTickets(); });
}
// Atajo "/" para buscar en Tickets (Ctrl + K ya es el buscador de Toolz)
document.addEventListener('keydown', e => { if (e.key === '/' && !e.target.closest('input,textarea,select,[contenteditable]') && $('tk-q')) { e.preventDefault(); $('tk-q').focus(); } });
/** Tablero de tickets: arrastrar una tarjeta a otra columna le cambia el estado (y a su tarea). */
function bindTkBoard() {
  let drag = 0;
  const M = $('wk-main');
  M.querySelectorAll('[data-tk]').forEach(c => {
    c.addEventListener('dragstart', e => { drag = +c.dataset.tk; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(drag)); c.classList.add('drag'); });
    c.addEventListener('dragend', () => { c.classList.remove('drag'); M.querySelectorAll('.wk-tkcol.over').forEach(x => x.classList.remove('over')); });
  });
  M.querySelectorAll('[data-col]').forEach(col => {
    col.addEventListener('dragover', e => { if (!drag) return; e.preventDefault(); col.classList.add('over'); });
    col.addEventListener('dragleave', e => { if (!col.contains(e.relatedTarget)) col.classList.remove('over'); });
    col.addEventListener('drop', async e => {
      e.preventDefault(); col.classList.remove('over');
      const id = drag; drag = 0; if (!id) return;
      const card = M.querySelector(`[data-tk="${id}"]`);
      if (card && card.closest('[data-col]') === col) return;
      col.querySelector('.wk-tkdrop').prepend(card);
      try { await api('ticket_update', { id, status: col.dataset.col }); toast(`${tkCode(id)} pasó a ${tkSt(col.dataset.col).name}`); }
      catch (err) { toast(err.message, true); }
      wkQuiet(renderTickets);
    });
  });
}
/** Tickets del sitio base (solo en el Toolz conectado): cuándo se sincronizó, qué falta mandar y "Sincronizar ahora". */
function baseBar(b) {
  const err = b.error ? `<span style="color:#f87171"><i class="fa-solid fa-triangle-exclamation"></i> ${esc(b.error)}</span>` : '';
  return `<div class="tk-banner"><span class="tk-src" style="--s:#3b82f6"><i class="fa-solid fa-satellite-dish"></i></span>
    <span class="tx">Los tickets de <b>Comercial › Demo</b> (web) y <b>Soporte</b> (agencias) llegan del sitio base. Las respuestas públicas y los cambios de estado vuelven a la agencia; las notas internas no salen de acá.
    <small>${b.last_ok ? '<i class="fa-solid fa-circle-check" style="color:#22c55e"></i> Última sincronización ' + ago(b.last_ok) : 'Todavía no se sincronizó'}${b.pending ? ' · <b>' + b.pending + '</b> envío(s) pendiente(s)' : ''}</small> ${err}</span>
    <button class="wk-btn" type="button" id="tk-bsync"><i class="fa-solid fa-rotate"></i> Sincronizar ahora</button></div>`;
}
function bindBaseSync(done) {
  $('tk-bsync')?.addEventListener('click', async e => {
    e.currentTarget.disabled = true;
    try { const r = await api('ticket_base_sync'); toast(r.new || r.updated || r.sent ? `Listo: ${r.new} nuevo(s), ${r.updated} actualizado(s), ${r.sent} enviado(s)` : 'Todo al día'); done(); }
    catch (err) { toast(err.message, true); e.currentTarget.disabled = false; }
  });
}
function baseCard(t) {
  const b = t.base, c = b.contact || {}, m = b.meta || {}, web = b.source === 'web';
  const kv = [['Origen', web ? 'Formulario de demo de cronostoolz.com' : 'Soporte de ' + esc(b.site_name || b.site_id)], ['Tipo', esc(b.type_label || b.type)],
    ['Persona', esc(c.name || '—') + (c.email ? ` · <a href="mailto:${esc(c.email)}">${esc(c.email)}</a>` : '')],
    c.company ? ['Empresa', esc(c.company) + (c.team ? ' · equipo de ' + esc(c.team) : '')] : null, c.phone ? ['Teléfono', esc(c.phone)] : null,
    m.url && /^https?:\/\//i.test(m.url) ? ['Página', `<a href="${esc(m.url)}" target="_blank" rel="noopener noreferrer">${esc(m.url)}</a>`] : null,
    m.version ? ['Versión', esc(m.version)] : null, m.ua ? ['Navegador', esc(m.ua)] : null, ['En el base', '#' + (+b.id)]].filter(Boolean);
  const files = (b.files || []).map((f, i) => `<button class="wk-btn" type="button" data-bfile="${esc(f)}"><i class="fa-regular fa-image"></i> Captura ${i + 1}</button>`).join(' ');
  return `<div class="wk-card" style="margin-top:14px;--c:#3b82f6"><h3><i class="fa-solid fa-satellite-dish"></i>Del sitio base</h3>
    <div class="wk-kv">${kv.map(([k, v]) => `<div class="k">${k}</div><div class="v">${v}</div>`).join('')}</div>
    ${files ? `<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">${files}</div><div id="tt-shot" style="margin-top:8px"></div>` : ''}
    <p class="wk-help" style="margin:10px 0 0">${b.pending ? `<b>${b.pending}</b> envío(s) al base pendiente(s): se reintentan solos. ` : ''}${b.sync?.error ? `<span style="color:#f87171">${esc(b.sync.error)}</span>` : ''}</p>
    ${t.agent && !b.detached ? '<button class="wk-btn" type="button" id="tk-bsync" style="margin-top:6px"><i class="fa-solid fa-rotate"></i> Sincronizar ahora</button>' : ''}</div>`;
}
/** Reporte enviado desde el juego (juegos/reportes.php): jugador, tipo, juego y los datos que mandó el juego. */
function gameCard(t) {
  const g = t.game, kv = [['Jugador', esc(g.player) + ` <small class="tkt-mute">#${esc(g.player_code)}</small>`], ['Tipo', esc(g.kind_label)], ['Juego', esc(g.game) + (g.key_name && g.key_name !== g.game ? ` <small class="tkt-mute">(clave ${esc(g.key_name)})</small>` : '')],
    ...(g.context || []).map(c => [esc(c.label), esc(c.value)]), ['Sus reportes', String(+g.player_reports)]];
  return `<div class="wk-card" style="margin-top:14px;--c:#22c55e"><h3><i class="fa-solid fa-gamepad"></i>Del juego</h3>
    <div class="wk-kv">${kv.map(([k, v]) => `<div class="k">${k}</div><div class="v">${v}</div>`).join('')}</div>
    <p class="wk-help" style="margin:10px 0 0">El jugador ve las respuestas públicas y el estado en el juego. Las notas internas y los eventos no le llegan.</p></div>`;
}
function ticketDialog(cats) {
  if (!cats.length) { toast('No hay categorías activas para abrir tickets', true); return; }
  const d = openDlg(`<h3>Nuevo ticket</h3>
    <div class="wk-fr"><label class="wk-f"><span>Categoría</span><select class="wk-in" id="td-cat">${cats.map(c => `<option value="${c.id}">${esc(c.name)}${c.team_name ? ' · ' + esc(c.team_name) : ''}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Prioridad</span><select class="wk-in" id="td-pr">${W.cfg.priorities.map(p => `<option value="${p.id}"${p.id === (W.cfg.default_priority || 'media') ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label></div>
    <label class="wk-f"><span>Asunto</span><input class="wk-in" id="td-sub" maxlength="200" placeholder="Ej.: No puedo entrar al correo"></label>
    <label class="wk-f"><span>Qué pasa</span><textarea class="wk-in" id="td-desc" rows="5" maxlength="20000" placeholder="Cuenta qué pasa, desde cuándo y qué probaste"></textarea></label>
    <div class="wk-help">Se crea también su tarea en el tablero del departamento que lo atiende.</div>
    <div class="wk-err" id="td-err"></div>
    <div class="acts"><button class="wk-btn" type="button" id="td-cancel">Cancelar</button><button class="wk-btn primary" type="button" id="td-ok">Abrir ticket</button></div>`);
  d.querySelector('#td-cancel').addEventListener('click', closeDlg);
  d.querySelector('#td-ok').addEventListener('click', async () => {
    try { const r = await api('ticket_create', { category_id: +d.querySelector('#td-cat').value, priority: d.querySelector('#td-pr').value, subject: d.querySelector('#td-sub').value, description: d.querySelector('#td-desc').value });
      closeDlg(); toast('Ticket abierto'); location.hash = 'tickets/' + r.id; }
    catch (e) { d.querySelector('#td-err').textContent = e.message; }
  });
  d.querySelector('#td-sub').focus();
}
/** Escalar: diálogo propio (antes era el cuadro del navegador). */
function escalateDialog(done) {
  const d = openDlg(`<h3>Escalar el ticket</h3><p class="wk-help" style="margin:0 0 10px">Les llega a los jefes del departamento. El motivo queda en la conversación del ticket.</p>
    <label class="wk-f"><span>Motivo</span><textarea class="wk-in" id="te-note" rows="3" maxlength="500" placeholder="Ej.: necesito acceso al servidor de la agencia"></textarea></label>
    <div class="acts"><button class="wk-btn" type="button" id="te-cancel">Cancelar</button><button class="wk-btn danger" type="button" id="te-ok"><i class="fa-solid fa-arrow-up-wide-short"></i> Escalar</button></div>`);
  d.querySelector('#te-cancel').addEventListener('click', closeDlg);
  d.querySelector('#te-ok').addEventListener('click', () => { const n = d.querySelector('#te-note').value; closeDlg(); done(n); });
  d.querySelector('#te-note').focus();
}
/** Respuestas de un formulario del panel de solicitudes, con las etiquetas de sus campos. */
function answersHtml(fields, ans) {
  const val = (f, v) => f.type === 'check' ? (v ? 'Sí' : 'No') : f.type === 'person' ? esc(userOf(+v)?.name || '') : f.type === 'url' && /^https?:\/\//i.test(v) ? `<a href="${esc(v)}" target="_blank" rel="noopener noreferrer">${esc(v)}</a>` : esc(v);
  const rows = fields.filter(f => ans[f.id] !== undefined && ans[f.id] !== '' && f.type !== 'section').map(f => `<div class="k">${esc(f.label)}</div><div class="v">${val(f, ans[f.id])}</div>`);
  return rows.length ? `<div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-inbox"></i>Lo que pidió</h3><div class="wk-kv">${rows.join('')}</div></div>` : '';
}
// Vinculados: clientes y contenidos del ticket (trabajo/tk_links_lib.php). Las tarjetitas son las del chat (chat/refs.js / refs.css):
// el nombre solo se ve si quien mira tiene acceso. Pueden vincular quien lo atiende y quien lo pidió.
function tkLinkChip(l) {
  return `<span class="tk-lnk">${l.ok ? `<a class="cc-ref" data-ok="1" href="${esc(l.href)}" style="--rc:${esc(l.color)}" title="${esc(l.label + ': ' + l.title)}"><i class="fa-solid ${esc(l.icon)}"></i><span>${esc(l.title)}</span>${l.sub ? `<small>${esc(l.sub)}</small>` : ''}</a>`
    : `<span class="cc-ref na" style="--rc:#71717a"><i class="fa-solid fa-lock"></i><span>${esc(l.label)} sin acceso</span></span>`}<button type="button" class="tk-lnk-x" data-tklink-rm="${esc(l.kind)}:${+l.id}" title="Quitar el vínculo" aria-label="Quitar el vínculo"><i class="fa-solid fa-xmark"></i></button></span>`;
}
function tkLinksCard(t) {
  const ls = t.links || [];
  return `<div class="wk-card" id="tk-links" style="margin-top:14px;--c:#3b82f6"><h3><i class="fa-solid fa-link"></i>Vinculados<span class="sp"></span><small>${ls.length || ''}</small></h3>
    <div class="tk-lnks">${ls.length ? ls.map(tkLinkChip).join('') : '<p class="wk-help" style="margin:0">Todavía no hay clientes ni contenidos vinculados.</p>'}</div>
    ${window.CCRefs ? `<button type="button" class="tk-b" style="margin-top:12px" data-tklink-add data-refpk-open><i class="fa-solid fa-plus"></i> Vincular cliente o contenido</button>` : ''}</div>`;
}
function bindTkLinks(id) {
  const box = $('tk-links'); if (!box) return;
  const save = async data => {
    try { const d = await api('tk_link', { id, ...data }); box.outerHTML = tkLinksCard({ links: d.links }); bindTkLinks(id); toast(data.op === 'remove' ? 'Vínculo quitado' : 'Vinculado'); }
    catch (e) { toast(e.message, true); }
  };
  box.addEventListener('click', e => {
    const rm = e.target.closest('[data-tklink-rm]');
    if (rm) { const [kind, ref] = rm.dataset.tklinkRm.split(':'); save({ op: 'remove', kind, ref: +ref }); return; }
    const add = e.target.closest('[data-tklink-add]');
    if (add) CCRefs.picker(add, api, tok => { const m = tok.match(/^\[\[(\w+):(\d+)\]\]$/); if (m) save({ kind: m[1], ref: +m[2] }); }, { only: ['cliente', 'contenido'], sub: 'Clientes o contenidos relacionados con este ticket' });
  });
}

// ── Respuestas rápidas y respuesta automática (trabajo/tk_canned_lib.php) ─────────────
// Variables: {saludo} según la hora de quien responde, {nombre} de quien abrió el ticket, {agente}, {codigo}, {asunto}
const TK_CANNED_ST = { '': 'No cambia el estado', en_curso: 'Pasa a En curso', esperando: 'Pasa a Esperando', resuelto: 'Pasa a Resuelto', cerrado: 'Pasa a Cerrado' };
function tkGreeting(h = new Date().getHours()) { return h >= 5 && h < 12 ? 'Buenos días' : h >= 12 && h < 20 ? 'Buenas tardes' : 'Buenas noches'; }
function tkCannedVars(t) {
  const req = t.base ? (t.base.contact?.name || t.base.site_name || t.base.from || '') : t.game ? (/^Jugador #/.test(t.game.player) ? '' : t.game.player) : (userOf(t.requester_id)?.name || '');
  return { saludo: tkGreeting(), nombre: String(req).trim().split(/\s+/)[0] || '', agente: String(W.me?.name || '').trim().split(/\s+/)[0] || '', codigo: tkCode(t.id), asunto: t.subject || '' };
}
function tkFill(tpl, v) {
  return String(tpl).replace(/\{(saludo|nombre|agente|codigo|asunto)\}/g, (_, k) => v[k] || '').replace(/[ \t]+,/g, ',').replace(/[ \t]{2,}/g, ' ').replace(/\(\s*\)/g, '').trim();
}
function tkCannedMenu(anchor, t, onPick) {
  document.querySelector('.tk-cpk')?.remove();
  const meta = W.tkMeta || {}, v = tkCannedVars(t), team = t.team_id;
  const list = (meta.canned || []).filter(c => !c.team_id || c.team_id === team);
  const teamName = id => (meta.teams || []).find(x => x.id === id)?.name || 'Área';
  const el = document.createElement('div'); el.className = 'cc-pk tk-cpk';
  el.innerHTML = `<div class="cc-pk-h"><b><i class="fa-solid fa-bolt"></i> Respuestas rápidas</b><small>Se completan con ${esc(v.saludo.toLowerCase())}, el nombre y el código. Puedes editarlas antes de enviar.</small></div>
    <input class="cc-pk-q" type="search" placeholder="Buscar respuesta" aria-label="Buscar respuesta" autocomplete="off">
    <div class="cc-pk-list">${list.length ? list.map(c => `<button type="button" class="cc-pk-it tk-cpk-it" data-cid="${esc(c.id)}" style="--rc:${c.status ? esc(tkSt(c.status).color) : '#64748b'}"><i class="fa-solid ${c.team_id ? 'fa-people-group' : 'fa-message'}"></i><span><b>${esc(c.title)}${c.team_id ? ` <em>${esc(teamName(c.team_id))}</em>` : ''}</b><small>${esc(tkFill(c.body, v).replace(/\s+/g, ' ').slice(0, 110))}</small>${c.status ? `<small class="st">${esc(TK_CANNED_ST[c.status])}</small>` : ''}</span></button>`).join('') : '<div class="cc-pk-empty">Todavía no hay respuestas rápidas.</div>'}</div>
    ${meta.canned_edit ? '<button type="button" class="tk-cpk-edit" data-cedit><i class="fa-solid fa-pen"></i> Editar respuestas y respuesta automática</button>' : ''}`;
  document.body.appendChild(el);
  const r = anchor.getBoundingClientRect(); el.style.left = Math.max(8, Math.min(r.left, innerWidth - el.offsetWidth - 8)) + 'px';
  el.style.top = (r.top - el.offsetHeight - 8 > 8 ? r.top - el.offsetHeight - 8 : Math.min(innerHeight - el.offsetHeight - 8, r.bottom + 8)) + 'px';
  const close = () => { el.remove(); document.removeEventListener('mousedown', away, true); document.removeEventListener('keydown', esck, true); };
  const away = e => { if (!el.contains(e.target) && e.target !== anchor && !anchor.contains(e.target)) close(); };
  const esck = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
  setTimeout(() => { document.addEventListener('mousedown', away, true); document.addEventListener('keydown', esck, true); }, 0);
  const q = el.querySelector('.cc-pk-q');
  q.addEventListener('input', () => { const s = q.value.trim().toLowerCase(); el.querySelectorAll('.tk-cpk-it').forEach(b => { b.hidden = s && !b.textContent.toLowerCase().includes(s); }); });
  el.addEventListener('click', e => {
    const it = e.target.closest('[data-cid]');
    if (it) { const c = list.find(x => x.id === it.dataset.cid); if (c) onPick(tkFill(c.body, v), c.status || ''); close(); return; }
    if (e.target.closest('[data-cedit]')) { close(); tkCannedEditor(); }
  });
  q.focus();
}
function tkCannedEditor() {
  const meta = W.tkMeta || {}, teams = meta.teams || [];
  let rows = (meta.canned || []).map(c => ({ ...c }));
  const ar = JSON.parse(JSON.stringify(meta.autoreply || { on: false, body: '', teams: {} }));
  const teamOpts = sel => `<option value="0"${!sel ? ' selected' : ''}>Todas las áreas</option>` + teams.map(t => `<option value="${t.id}"${+sel === t.id ? ' selected' : ''}>${esc(t.name)}</option>`).join('');
  const stOpts = sel => Object.entries(TK_CANNED_ST).map(([k, l]) => `<option value="${k}"${sel === k ? ' selected' : ''}>${esc(l)}</option>`).join('');
  const rowHtml = (c, i) => `<div class="tk-ced-row" data-i="${i}"><div class="tk-ced-top"><input class="wk-in" data-k="title" maxlength="60" placeholder="Nombre (ej.: Solucionado)" value="${esc(c.title || '')}">
      <select class="wk-in" data-k="team_id" aria-label="Área">${teamOpts(c.team_id)}</select><select class="wk-in" data-k="status" aria-label="Estado al enviar">${stOpts(c.status || '')}</select>
      <button type="button" class="wk-btn icon" data-del="${i}" title="Borrar" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button></div>
    <textarea class="wk-in" data-k="body" rows="3" maxlength="4000" placeholder="{saludo} {nombre}, …">${esc(c.body || '')}</textarea></div>`;
  const arTeam = t => { const x = ar.teams?.[t.id] || { mode: 'general', body: '' };
    return `<div class="tk-ced-ar" data-team="${t.id}"><div class="tk-ced-top"><b>${esc(t.name)}</b><select class="wk-in" data-ar="mode"><option value="general"${x.mode === 'general' ? ' selected' : ''}>Usa la general</option><option value="own"${x.mode === 'own' ? ' selected' : ''}>Texto propio</option><option value="off"${x.mode === 'off' ? ' selected' : ''}>Sin respuesta automática</option></select></div>
      <textarea class="wk-in" data-ar="body" rows="3" maxlength="4000" ${x.mode === 'own' ? '' : 'hidden'} placeholder="{saludo} {nombre}, recibimos…">${esc(x.body || '')}</textarea></div>`; };
  const draw = () => {
    const d = openDlg(`<h3><i class="fa-solid fa-bolt" style="color:var(--g7-accent)"></i> Respuestas de los tickets</h3>
      <p class="wk-help" style="margin:0 0 12px">Variables: <code>{saludo}</code> (Buenos días, Buenas tardes o Buenas noches según la hora), <code>{nombre}</code> de quien abrió el ticket, <code>{agente}</code>, <code>{codigo}</code> y <code>{asunto}</code>.</p>
      <div class="tk-ced-sec"><h4>Respuestas rápidas</h4><div id="tk-ced-list">${rows.map(rowHtml).join('') || '<p class="wk-help">No hay respuestas. Agrega la primera.</p>'}</div>
        <button type="button" class="wk-btn" id="tk-ced-add"><i class="fa-solid fa-plus"></i> Agregar respuesta</button></div>
      <div class="tk-ced-sec"><h4>Respuesta automática</h4><p class="wk-help" style="margin:0 0 8px">Se manda sola cuando entra un ticket nuevo. No cuenta como primera respuesta del plazo (SLA). Si vino del sitio base, también le llega a la agencia.</p>
        <label class="tk-ced-on"><input type="checkbox" id="tk-ar-on" ${ar.on ? 'checked' : ''}> Responder automáticamente los tickets nuevos</label>
        <textarea class="wk-in" id="tk-ar-body" rows="3" maxlength="4000">${esc(ar.body || '')}</textarea>
        ${teams.length ? `<h4 style="margin-top:14px">Por área</h4>${teams.map(arTeam).join('')}` : ''}</div>
      <div class="acts"><button class="wk-btn" type="button" id="tk-ced-x">Cancelar</button><button class="wk-btn primary" type="button" id="tk-ced-ok"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>`);
    d.classList.add('tk-ced'); d.addEventListener('close', () => d.classList.remove('tk-ced'), { once: true });   // la ventana es compartida: el ancho vuelve al normal
    const collect = () => {
      rows = [...d.querySelectorAll('.tk-ced-row')].map(r => { const o = { id: rows[+r.dataset.i]?.id || '' }; r.querySelectorAll('[data-k]').forEach(x => { o[x.dataset.k] = x.dataset.k === 'team_id' ? (+x.value || null) : x.value; }); return o; });
      ar.on = d.querySelector('#tk-ar-on').checked; ar.body = d.querySelector('#tk-ar-body').value; ar.teams = {};
      d.querySelectorAll('.tk-ced-ar').forEach(x => { ar.teams[x.dataset.team] = { mode: x.querySelector('[data-ar="mode"]').value, body: x.querySelector('[data-ar="body"]').value }; });
    };
    d.querySelector('#tk-ced-add').addEventListener('click', () => { collect(); rows.push({ id: '', title: '', body: '{saludo} {nombre}, \n\nMuchas gracias.', status: '', team_id: null }); draw(); });
    d.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { collect(); rows.splice(+b.dataset.del, 1); draw(); }));
    d.querySelectorAll('[data-ar="mode"]').forEach(s => s.addEventListener('change', () => { s.closest('.tk-ced-ar').querySelector('[data-ar="body"]').hidden = s.value !== 'own'; }));
    d.querySelector('#tk-ced-x').addEventListener('click', closeDlg);
    d.querySelector('#tk-ced-ok').addEventListener('click', async () => {
      collect();
      try { const r = await api('tk_canned_save', { canned: rows, autoreply: ar }); W.tkMeta = { ...(W.tkMeta || {}), canned: r.canned, autoreply: r.autoreply }; closeDlg(); toast('Respuestas guardadas'); }
      catch (e) { toast(e.message, true); }
    });
  };
  draw();
}

async function renderTicket(id) {
  const r0 = await api('ticket_get', { id });
  const t = r0.ticket; W.tkStatus = r0.status; W.tkCur = t;
  if (t.agent && !W.tkMeta) { try { W.tkMeta = await api('tk_meta'); } catch (e) { W.tkMeta = {}; } }
  const meta = W.tkMeta || {};
  const s = tkSt(t.status), done = ['resuelto', 'cerrado'].includes(t.status), isReq = t.requester_id === W.me.id, x = t.task, src = tkSrcOf(t);
  const help = t.game ? 'La respuesta le llega al jugador dentro del juego, en su lista de reportes, con tu nombre. También queda en los comentarios de su tarea. Las notas internas no salen de Trabajo.'
    : !t.base ? 'Lo que escribas acá aparece también en los comentarios de su tarea.' : t.base.detached ? 'Este ticket ya no está vinculado al sitio base: la respuesta queda solo en Trabajo.' : t.base.source === 'web'
    ? 'Vino de la web: la respuesta queda guardada en el base, pero el contacto no la ve. Escríbele por email' + (t.base.contact?.email ? ` (<a href="mailto:${esc(t.base.contact.email)}?subject=${encodeURIComponent('Tu consulta en Cronos')}">${esc(t.base.contact.email)}</a>)` : '') + '.'
    : 'La respuesta le llega a la agencia en su página Soporte, con tu nombre, y queda en los comentarios de su tarea. Las notas internas no salen de Trabajo.';
  const reqU = userOf(t.requester_id), whoName = t.base ? t.base.from : t.game ? t.game.from : reqU?.name || '';
  const nMsgs = t.msgs.filter(m => m.kind !== 'event').length, asg = t.assignee_id ? userOf(t.assignee_id) : null, p = prioOf(t.priority);
  // Recorrido de estados: los anteriores quedan marcados, el actual lleno y con su color
  const order = Object.keys(W.tkStatus), cur = order.indexOf(t.status);
  const steps = `<div class="tk-steps${t.agent ? '' : ' ro'}" role="group" aria-label="Estado del ticket">${order.map((k, i) => { const v = W.tkStatus[k];
    return `<button type="button" class="tk-step${i < cur ? ' past' : ''}${i === cur ? ' on' : ''}" style="--c:${esc(v[1])}" data-tst="${k}"${i === cur ? ' aria-current="step"' : ''}${t.agent ? '' : ' disabled'} title="${t.agent && i !== cur ? 'Pasar a ' + esc(v[0]) : esc(v[0])}">
      <span class="dot"><i class="fa-solid ${i < cur ? 'fa-check' : TK_ST_IC[k] || 'fa-circle-dot'}"></i></span><span class="lb">${esc(v[0])}</span></button>`; }).join('')}</div>`;
  const selBtn = (k, label, html) => `<div class="tk-field"><span class="lb">${label}</span>${t.agent ? `<button type="button" class="tk-sel" data-edit="${k}" aria-haspopup="listbox">${html}<i class="fa-solid fa-chevron-down cv"></i></button>` : `<span class="tk-sel ro">${html}</span>`}</div>`;
  $('wk-main').innerHTML = `<div class="tk-detail" style="--st:${esc(s.color)}">
    <nav class="tk-crumb" aria-label="Ubicación"><a href="#tickets"><i class="fa-solid fa-arrow-left"></i> Tickets</a><i class="fa-solid fa-chevron-right sep"></i><span>${tkCode(t.id)}</span></nav>
    <div class="tk-hero" style="--c:${s.color}"><div class="r1">${tkSrcIc(t)}<div class="tit">
      <div class="kick"><span class="tk-code">${tkCode(t.id)}</span><span>${esc(src[0])}</span><span>·</span><span>${t.origin === 'tarea' ? 'creada' : 'abierto'} ${ago(t.created_at)}</span></div>
      <h1>${esc(t.subject)}</h1></div>
      <div class="tk-hero-a">${t.agent && t.assignee_id !== W.me.id && !done ? '<button class="tk-b primary" type="button" id="tt-take"><i class="fa-solid fa-hand"></i> Tomarlo</button>' : ''}${t.agent && !done && t.origin !== 'tarea' ? '<button class="tk-b danger" type="button" id="tt-esc"><i class="fa-solid fa-arrow-up-wide-short"></i> Escalar</button>' : ''}
        <button class="tk-b icon" type="button" id="tt-link" title="Copiar enlace" aria-label="Copiar enlace"><i class="fa-solid fa-link"></i></button></div></div>
      <div class="chips">${tkChip(s.color, esc(s.name), 'solid dot')}${tkPrioChip(t.priority)}${tkCatChip(t)}${tkEscChip(t)}${t.paused ? tkChip('#8b5cf6', '<i class="fa-solid fa-pause"></i>Plazos en pausa') : ''}</div>
      <div class="by">${t.base ? '<span class="tk-xav"><i class="fa-solid fa-building"></i></span>' : t.game ? '<span class="tk-xav"><i class="fa-solid fa-gamepad"></i></span>' : av(reqU, 26)}<span>${t.origin === 'tarea' ? 'Creó la tarea' : 'Lo abrió'} <b>${esc(whoName)}</b></span>
        <span>·</span>${asg ? `${av(asg, 26)}<span>Lo atiende <b>${esc(asg.name)}</b></span>` : '<span><i class="fa-solid fa-user-slash"></i> Sin responsable</span>'}</div>
      ${steps}</div>
    <div class="wk-2 tk-cols2">
      <div><div class="wk-card" style="--c:#64748b"><h3><i class="fa-solid fa-align-left"></i>Descripción</h3><div class="wk-pdesc" style="margin:0">${esc(t.description)}</div></div>
        ${t.form_fields ? answersHtml(t.form_fields, t.answers || {}) : ''}
        <div class="wk-card tk-chat" style="margin-top:14px;--c:var(--g7-accent)"><h3><i class="fa-regular fa-comments"></i>Conversación<span class="sp"></span><small>${nMsgs} mensaje${nMsgs === 1 ? '' : 's'}</small></h3>
          <div class="tk-conv">${tkConvHtml(t.msgs, t.game ? TK_JR_XT : undefined)}</div>${t.status !== 'cerrado' ? tkReplyBox(t.agent, help) : '<div class="tk-closed"><i class="fa-solid fa-lock"></i> El ticket está cerrado.' + (t.base ? ' Al reabrirlo, la agencia puede volver a escribir.' : t.game ? ' El jugador ya no puede responderlo: si el problema sigue, tiene que enviar un reporte nuevo.' : '') + (t.agent ? ' <button class="tk-b sm" type="button" data-tst="en_curso"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>' : '') + '</div>'}</div></div>
      <div>${t.agent ? `<div class="wk-card" style="--c:#f97316"><h3><i class="fa-solid fa-headset"></i>Atender</h3>
          ${selBtn('assignee', 'Responsable', asg ? `${av(asg, 26)}<span>${esc(asg.name)}${asg.id === W.me.id ? ' <small>(yo)</small>' : ''}</span>` : '<span class="tkt-nobody"><i class="fa-solid fa-user-plus"></i></span><span class="mute">Sin asignar</span>')}
          ${selBtn('prio', 'Prioridad', `<span class="tk-flag" style="--c:${esc(p.color)}"><i class="fa-solid fa-flag"></i></span><span>${esc(p.name)}</span>`)}
          ${selBtn('status', 'Estado', `<span class="tk-flag" style="--c:${esc(s.color)}"><i class="fa-solid ${TK_ST_IC[t.status] || 'fa-circle-dot'}"></i></span><span>${esc(s.name)}</span>`)}
          <div class="tk-field"><span class="lb">Categoría</span><span class="tk-sel ro">${tkCatChip(t) || '<span class="mute">—</span>'}</span></div>
          <p class="wk-help" style="margin:10px 0 0"><i class="fa-solid fa-bolt" style="color:var(--g7-accent)"></i> Los cambios se guardan solos.</p></div>`
        : `<div class="wk-card" style="--c:#3b82f6"><h3><i class="fa-solid fa-circle-info"></i>Tu ticket</h3>${asg ? `<p class="wk-help" style="margin:0 0 12px">Lo atiende <b>${esc(asg.name)}</b>.</p>` : '<p class="wk-help" style="margin:0 0 12px">Todavía nadie lo tomó.</p>'}
          <div style="display:flex;gap:8px;flex-wrap:wrap">${isReq && t.status === 'resuelto' ? `<button class="tk-b success" type="button" data-ev="approve" data-id="${t.id}"><i class="fa-solid fa-check-double"></i> Aprobar y cerrar</button><button class="tk-b" type="button" data-ev="changes" data-id="${t.id}"><i class="fa-solid fa-pen-to-square"></i> Pedir cambios</button>`
            : isReq && t.status !== 'cerrado' ? '<button class="tk-b success" type="button" data-req-st="cerrado"><i class="fa-solid fa-check"></i> Ya se resolvió, cerrar</button>' : ''}
          ${isReq && t.status === 'cerrado' ? '<button class="tk-b" type="button" data-req-st="en_curso"><i class="fa-solid fa-rotate-left"></i> Sigue pasando, reabrir</button>' : ''}</div></div>`}
        <div id="tt-ev"></div>
        ${tkLinksCard(t)}
        ${tkCollabCard(t)}
        <div class="wk-card" style="margin-top:14px;--c:#22c55e"><h3><i class="fa-regular fa-clock"></i>${t.origin === 'tarea' ? 'Plazo' : 'Plazos (SLA)'}</h3><div class="tk-meters">
          ${t.origin === 'tarea' || (!t.first_response_due) || (done && !t.first_response_at) ? '' : tkMeter('Primera respuesta', 'fa-reply', t.created_at, t.first_response_due, t.first_response_at, r0.now, t.paused)}
          ${t.resolution_due ? tkMeter(t.origin === 'tarea' ? 'Fecha de la tarea' : 'Resolución', 'fa-flag-checkered', t.created_at, t.resolution_due, t.resolved_at, r0.now, t.paused) : `<div class="wk-help" style="margin:0">${t.resolved_at ? '<i class="fa-solid fa-check" style="color:#22c55e"></i> Resuelto ' + ago(t.resolved_at) : 'Sin plazo de resolución.'}</div>`}
          ${t.paused_min ? `<div class="wk-help" style="margin:0"><i class="fa-solid fa-pause" style="color:#8b5cf6"></i> ${t.paused_min < 60 ? t.paused_min + ' min' : (t.paused_min / 60).toFixed(1).replace('.0', '') + ' h'} en pausa esperando respuesta.${t.paused ? ' Al volver a En curso, los plazos se corren lo que duró la pausa.' : ''}</div>` : t.paused ? '<div class="wk-help" style="margin:0"><i class="fa-solid fa-pause" style="color:#8b5cf6"></i> El reloj está frenado mientras se espera respuesta.</div>' : ''}</div></div>
        <div class="wk-card" style="margin-top:14px;--c:#f97316"><h3><i class="fa-solid fa-list-check"></i>Su tarea</h3>${x ? `<div class="wk-kv"><div class="k">Tablero</div><div class="v">${esc(x.board_name)}</div>
            <div class="k">Estado</div><div class="v"><span class="wk-pill" style="--c:${esc(x.status_color)}">${esc(x.status_name)}</span></div>
            <div class="k">Responsables</div><div class="v">${x.assignees.length ? x.assignees.map(u => esc(userOf(u)?.name || '')).join(', ') : '—'}</div>
            ${x.due_date ? `<div class="k">Fecha</div><div class="v">${dueHtml(x.due_date, x.done)}</div>` : ''}</div>
            ${x.visible ? `<a class="tk-b" style="margin-top:12px" href="${taskUrl({ id: x.id, board_id: x.board_id })}"><i class="fa-solid fa-up-right-from-square"></i> Abrir la tarea</a>` : ''}
            <p class="wk-help" style="margin:10px 0 0">Es el mismo elemento: terminar la tarea resuelve el ticket y resolver el ticket termina la tarea.</p>`
          : t.agent ? `<p class="wk-help" style="margin:0 0 10px">Este ticket no tiene tarea (es de antes o se borró).</p><button class="tk-b" type="button" id="tt-task"><i class="fa-solid fa-plus"></i> Crear su tarea</button>` : '<p class="wk-help" style="margin:0">Sin tarea.</p>'}</div>
        ${t.base ? baseCard(t) : ''}${t.game ? gameCard(t) : ''}</div></div></div>`;
  const reload = () => renderTicket(id), M = $('wk-main');
  bindTkCompose(help, id, reload);
  bindTkLinks(id);
  bindTkCollab(t, reload);
  if (window.CCRefs) CCRefs.hydrate($('wk-main'), api);   // tarjetitas de los [[tipo:id]] de las respuestas
  bindBaseSync(reload);
  M.querySelector('.tk-conv')?.lastElementChild?.scrollIntoView?.({ block: 'nearest' });
  M.querySelectorAll('[data-bfile]').forEach(b => b.addEventListener('click', async () => {
    try { const f = await api('ticket_base_file', { id, file: b.dataset.bfile }); const box = $('tt-shot'); box.textContent = '';
      const img = document.createElement('img'); img.alt = 'Captura adjunta'; img.style.cssText = 'max-width:100%;border-radius:8px;border:1px solid var(--g7-bd,#333)';
      img.src = 'data:' + f.mime + ';base64,' + f.data; box.appendChild(img); }
    catch (e) { toast(e.message, true); }
  }));
  const upd = async (data, ok) => { try { await api('ticket_update', { id, ...data }); toast(ok); wkQuiet(reload); } catch (e) { toast(e.message, true); } };
  M.querySelectorAll('[data-tst]').forEach(b => b.addEventListener('click', () => { if (b.dataset.tst !== t.status) upd({ status: b.dataset.tst }, 'Pasó a ' + tkSt(b.dataset.tst).name); }));
  M.querySelectorAll('.tk-sel[data-edit]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); tkEdit(b, t, meta, reload); }));
  $('tt-take')?.addEventListener('click', () => upd({ assignee_id: W.me.id, status: t.status === 'abierto' ? 'en_curso' : t.status }, 'Ahora lo atiendes tú'));
  $('tt-esc')?.addEventListener('click', () => escalateDialog(n => upd({ escalate: true, note: n }, 'Ticket escalado')));
  $('tt-link')?.addEventListener('click', async () => { try { await navigator.clipboard.writeText(location.origin + location.pathname + '#tickets/' + id); toast('Enlace copiado'); } catch (e) { toast('No se pudo copiar', true); } });
  $('tt-task')?.addEventListener('click', async () => { try { await api('ticket_task', { id }); toast('Tarea creada'); reload(); } catch (e) { toast(e.message, true); } });
  M.querySelectorAll('[data-req-st]').forEach(b => b.addEventListener('click', () => b.dataset.reqSt === 'cerrado' && window.G7Rate
    ? G7Rate.approve(id, { onClosed: () => toast('Ticket cerrado'), onDone: () => { toast('¡Gracias por calificar!'); reload(); } }).then(reload)
    : upd({ status: b.dataset.reqSt }, b.dataset.reqSt === 'cerrado' ? 'Ticket cerrado' : 'Ticket reabierto')));
  // Calificación: pedirla si toca, o mostrarla a quien puede verla (entrega_lib.php)
  if (window.G7Rate && ['resuelto', 'cerrado'].includes(t.status) && t.origin !== 'tarea') api('ev_get', { id }).then(e => {
    const box = $('tt-ev'); if (!box || (!e.can_rate && !e.rating)) return;
    box.innerHTML = `<div class="wk-card" style="margin-top:14px;--c:#f59e0b"><h3><i class="fa-solid fa-star"></i>Calificación</h3>${e.rating
      ? `<button class="ev-show" type="button" data-ev="see" data-id="${id}">${G7Rate.stars(e.rating.stars)}<span>${e.rating.comment ? esc(e.rating.comment.slice(0, 120)) : 'Ver detalle'}</span></button>`
      : `<p class="wk-help" style="margin:0 0 10px">Tu opinión ayuda al equipo a mejorar.</p><button class="tk-b success" type="button" data-ev="rate" data-id="${id}"><i class="fa-solid fa-star"></i> Calificar el pedido</button>`}</div>`;
  }).catch(() => {});
}
// Aprobar, pedir cambios, calificar y ver la calificación (assets/calificar.js): botones en el detalle y en Mis solicitudes
document.addEventListener('click', e => {
  const b = e.target.closest('[data-ev]'); if (!b || !window.G7Rate || !b.closest('#wk-main')) return;
  e.preventDefault(); e.stopPropagation();
  const id = +b.dataset.id, again = () => route();
  if (b.dataset.ev === 'approve') G7Rate.approve(id, { onClosed: () => toast('Pedido aprobado y cerrado'), onDone: () => { toast('¡Gracias por calificar!'); again(); } }).then(again);
  else if (b.dataset.ev === 'changes') G7Rate.changes(id, { onDone: () => { toast('Pedido devuelto al equipo'); again(); } });
  else G7Rate.open(id, { onDone: () => { toast('¡Gracias por calificar!'); again(); } });
}, true);

/** Tickets › Calificaciones: qué califica cada departamento (admins: todos; jefes: los suyos) y los promedios de los últimos 90 días. */
async function renderRatingsCfg() {
  let r; try { r = await api('ev_config'); } catch (e) { $('wk-main').innerHTML = emptyBox('fa-lock', e.message); return; }
  const avg = v => v == null ? '<span class="mute">—</span>' : `${G7Rate ? G7Rate.stars(Math.round(v)) : ''} <b>${String(v).replace('.', ',')}</b>`;
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Calificaciones<span>.</span></h1><p>Cuando un pedido se cierra, quien lo pidió le pone de 1 a 5 estrellas y, si el departamento quiere, califica también algunos puntos (por ejemplo Rapidez y Calidad). Promedios de los últimos 90 días.</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="#tickets"><i class="fa-solid fa-arrow-left"></i> Tickets</a></div></div>
    ${r.teams.length ? r.teams.map(t => `<div class="wk-card ev-team" style="margin-top:14px" data-team="${t.team_id}"><h3><i class="fa-solid fa-people-group"></i>${esc(t.team)}<span style="flex:1"></span><small class="mute">${t.n} ${t.n === 1 ? 'calificación' : 'calificaciones'}</small></h3>
      <div class="ev-grid"><div class="ev-avg"><small>General</small><div>${avg(t.avg)}</div></div>${t.criteria.filter(c => c.active || c.n).map(c => `<div class="ev-avg"><small>${esc(c.name)}${c.active ? '' : ' (apagado)'}</small><div>${avg(c.avg)}</div></div>`).join('')}</div>
      ${t.can_config ? `<div class="ev-crit"><b>Qué se califica además de la nota general</b><div class="ev-list">${t.criteria.map(c => `<div class="ev-it" data-id="${c.id}"><input class="wk-in" maxlength="60" value="${esc(c.name)}" aria-label="Punto"><label class="ev-on"><input type="checkbox"${c.active ? ' checked' : ''}> Activo</label><button class="wk-btn sm" type="button" data-up title="Subir" aria-label="Subir"><i class="fa-solid fa-arrow-up"></i></button><button class="wk-btn sm" type="button" data-rm title="Quitar" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}</div>
        <div style="display:flex;gap:8px;margin-top:8px"><button class="wk-btn sm" type="button" data-add><i class="fa-solid fa-plus"></i> Agregar punto</button><span style="flex:1"></span><button class="wk-btn primary sm" type="button" data-save><i class="fa-solid fa-check"></i> Guardar</button></div>
        <div class="wk-help">Ej: Rapidez, Calidad, Comunicación. Sin puntos, solo se pide la nota general. Un punto quitado que ya tiene notas queda apagado para no perderlas.</div></div>` : ''}
      ${t.comments.length ? `<div class="ev-cms"><b>Últimos comentarios</b>${t.comments.map(c => `<a href="#tickets/${c.ticket_id}" class="ev-cm">${G7Rate ? G7Rate.stars(c.stars) : ''}<span>${esc(c.comment)}</span><small>${tkCode(c.ticket_id)} · ${ago(c.at)}</small></a>`).join('')}</div>` : ''}
    </div>`).join('') : emptyBox('fa-star', 'No hay departamentos para mostrar.')}`;
  const row = (c = { id: 0, name: '', active: true }) => `<div class="ev-it" data-id="${c.id}"><input class="wk-in" maxlength="60" value="${esc(c.name)}" placeholder="Ej: Rapidez" aria-label="Punto"><label class="ev-on"><input type="checkbox" checked> Activo</label><button class="wk-btn sm" type="button" data-up title="Subir" aria-label="Subir"><i class="fa-solid fa-arrow-up"></i></button><button class="wk-btn sm" type="button" data-rm title="Quitar" aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`;
  $('wk-main').querySelectorAll('.ev-team').forEach(card => {
    const list = card.querySelector('.ev-list'); if (!list) return;
    card.querySelector('[data-add]').addEventListener('click', () => { list.insertAdjacentHTML('beforeend', row()); list.lastElementChild.querySelector('input').focus(); });
    list.addEventListener('click', e => {
      const it = e.target.closest('.ev-it'); if (!it) return;
      if (e.target.closest('[data-rm]')) it.remove();
      if (e.target.closest('[data-up]') && it.previousElementSibling) it.parentNode.insertBefore(it, it.previousElementSibling);
    });
    card.querySelector('[data-save]').addEventListener('click', async () => {
      const items = [...list.querySelectorAll('.ev-it')].map(it => ({ id: +it.dataset.id, name: it.querySelector('input.wk-in').value.trim(), active: it.querySelector('input[type=checkbox]').checked })).filter(x => x.name);
      try { await api('ev_criteria_save', { team_id: +card.dataset.team, items }); toast('Puntos guardados'); renderRatingsCfg(); } catch (e) { toast(e.message, true); }
    });
  });
}
/** Configuración de tickets (admins): categorías con su departamento y sus plazos, tablero de cada departamento, avisos y resumen diario. */
async function renderTicketCfg() {
  const m = await api('tk_meta');
  if (!m.admin) { $('wk-main').innerHTML = emptyBox('fa-lock', 'Solo los administradores configuran los tickets.'); return; }
  let nk = 0;
  const cats = m.cats.map(c => ({ ...c, key: 'c' + c.id })), P = W.cfg.priorities;
  // Plazos en horas por categoría (key) y prioridad; 'g' = generales. own = la categoría tiene plazos propios.
  const S = { g: {} };
  m.sla.forEach(s => { const k = s.category_id ? 'c' + s.category_id : 'g'; S[k] = S[k] || {}; S[k][s.priority] = [s.response_min, s.resolution_min]; });
  cats.forEach(c => { c.own = !!S[c.key]; S[c.key] = S[c.key] || {}; });
  const h = min => min ? +(min / 60).toFixed(2) : '';
  const tb = { ...m.team_boards };
  const slaTable = k => `<div class="wk-wrap"><table class="wk-t" style="cursor:default"><thead><tr><th>Prioridad</th><th>Primera respuesta (h)</th><th>Resolución (h)</th></tr></thead><tbody>
      ${P.map(p => { const v = S[k][p.id] || []; return `<tr style="cursor:default"><td><span style="color:${esc(p.color)}"><i class="fa-solid fa-flag"></i></span> ${esc(p.name)}</td>
        <td><input class="wk-in" style="width:110px" data-sla="${k}" data-p="${p.id}" data-k="r" inputmode="decimal" value="${h(v[0])}" placeholder="sin plazo" aria-label="Primera respuesta ${esc(p.name)}"></td>
        <td><input class="wk-in" style="width:110px" data-sla="${k}" data-p="${p.id}" data-k="z" inputmode="decimal" value="${h(v[1])}" placeholder="sin plazo" aria-label="Resolución ${esc(p.name)}"></td></tr>`; }).join('')}
      </tbody></table></div>`;
  $('wk-main').innerHTML = `<a class="wk-link" href="#tickets"><i class="fa-solid fa-arrow-left"></i> Tickets</a>
    <div class="wk-h" style="margin-top:8px"><div><h1>Configuración de tickets<span>.</span></h1><p>Cada categoría la atiende un departamento. Los plazos se cuentan en horas corridas desde que se abre el ticket y se frenan mientras se espera respuesta.</p></div>
      <div class="wk-h-a"><button class="wk-btn primary" type="button" id="tc-save"><i class="fa-solid fa-check"></i> Guardar</button></div></div>
    <div class="wk-card"><h3><i class="fa-solid fa-tags"></i>Categorías</h3><div id="tc-cats"></div><button class="wk-btn sm" type="button" id="tc-add"><i class="fa-solid fa-plus"></i> Agregar categoría</button>
      <div class="wk-help">"Tareas" es la categoría de los tickets que nacen como tarea: no lleva plazos (manda la fecha de la tarea).</div></div>
    <div class="wk-card" style="margin-top:14px"><h3><i class="fa-regular fa-clock"></i>Plazos generales</h3>${slaTable('g')}<div class="wk-help">Valen para todas las categorías salvo las que tengan plazos propios. Deja vacío para que esa prioridad no tenga plazo. La resolución nunca puede ser menor que la primera respuesta.</div></div>
    <div id="tc-own"></div>
    <div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-bolt"></i>Tickets desde el chat (tareas rápidas)</h3>
      <div class="wk-fr"><label class="wk-f"><span>Plazo para resolverlos (horas)</span><input class="wk-in" id="tc-chat" type="number" min="0" max="8000" step="0.5" value="${+m.chat_min ? +(m.chat_min / 60).toFixed(2) : 0}"></label></div>
      <div class="wk-help">Vale para los tickets que se abren desde el chat (/ticket o el menú de un mensaje), sin importar categoría ni prioridad. Pon 0 para que usen los plazos de su categoría.</div></div>
    <div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-truck-fast"></i>Mensaje de entrega (desde Promos)</h3>
      <label class="wk-f"><span>Texto que recibe quien hizo el pedido</span><textarea class="wk-in" id="tc-dtpl" rows="3" maxlength="2000">${esc(m.delivery_tpl || '')}</textarea></label>
      <div class="wk-help">Al cargar un contenido en Promos y elegir el pedido que entrega, el ticket recibe este mensaje y pasa a Resuelto. <b>{enlaces}</b> se reemplaza por los enlaces del contenido y <b>{nombre}</b> por su nombre.</div></div>
    <div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-table-columns"></i>Tablero de cada departamento</h3>
      <p class="wk-help" style="margin:0 0 10px">Ahí cae la tarea de cada ticket nuevo. Si no eliges, va al primer tablero del departamento o al General.</p>
      <div class="wk-tbmap">${[{ id: 0, name: 'Sin departamento (Administración)' }, ...m.teams].map(t => `<label class="wk-f"><span>${esc(t.name)}</span><select class="wk-in" data-tb="${t.id}"><option value="0">Automático</option>${m.boards.map(b => `<option value="${b.id}"${+tb[t.id] === b.id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>`).join('')}</div></div>
    <div class="wk-card" style="margin-top:14px"><h3><i class="fa-regular fa-bell"></i>Avisos</h3>
      <div class="wk-fr"><label class="wk-f"><span>Avisar antes de vencer (minutos)</span><input class="wk-in" id="tc-warn" type="number" min="5" max="10080" step="5" value="${+m.warn_min}"></label>
        <label class="wk-f"><span>Resumen diario</span><select class="wk-in" id="tc-dig"><option value="-1"${m.digest_hour < 0 ? ' selected' : ''}>No mandar</option>${Array.from({ length: 24 }, (_, i) => `<option value="${i}"${m.digest_hour === i ? ' selected' : ''}>A las ${String(i).padStart(2, '0')}:00</option>`).join('')}</select></label></div>
      <div class="wk-help">El aviso de "por vencer" le llega al responsable (o al departamento si no hay). El resumen les llega a los admins y a los jefes de cada departamento. Cada persona elige si los recibe en la campana o por email en Tareas › Mis avisos.</div></div>
    <div class="wk-card" style="margin-top:14px;--c:#22c55e" id="jr-card"><h3><i class="fa-solid fa-gamepad"></i>Reportes desde el juego</h3><div class="wk-help">Cargando…</div></div>`;
  const ownBox = () => {
    const list = cats.filter(c => c.own && c.id !== m.task_cat);
    $('tc-own').innerHTML = list.map(c => `<div class="wk-card" style="margin-top:14px"><h3><span class="wk-dot" style="--c:${esc(c.color)}"></span>Plazos de ${esc(c.name)}<span style="flex:1"></span><button class="wk-btn sm" type="button" data-own-off="${c.key}">Usar los generales</button></h3>${slaTable(c.key)}</div>`).join('');
  };
  const drawCats = () => {
    $('tc-cats').innerHTML = cats.map((c, i) => `<div class="wk-st" data-i="${i}"><input type="color" value="${esc(c.color)}" data-k="color" aria-label="Color">
      <input class="wk-in" value="${esc(c.name)}" data-k="name" maxlength="80" aria-label="Nombre">
      <select class="wk-in" data-k="team_id" style="max-width:200px" aria-label="Departamento"><option value="0">Administración</option>${m.teams.map(t => `<option value="${t.id}"${t.id === c.team_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
      ${c.id === m.task_cat ? '' : `<label class="wk-chkl"><input type="checkbox" data-k="own"${c.own ? ' checked' : ''}> Plazos propios</label>`}
      <label class="wk-chkl"><input type="checkbox" data-k="active"${c.active ? ' checked' : ''}> Activa</label>
      <button type="button" class="x" data-rm aria-label="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('') || '<div class="wk-help">Sin categorías.</div>';
  };
  const readSla = () => $('wk-main').querySelectorAll('[data-sla]').forEach(i => {
    const v = parseFloat((i.value || '').replace(',', '.')), k = i.dataset.sla, p = i.dataset.p, cur = S[k][p] || [0, 0];
    cur[i.dataset.k === 'r' ? 0 : 1] = v > 0 ? Math.round(v * 60) : 0; S[k][p] = cur;
  });
  const onCat = e => { const r = e.target.closest('[data-i]'); if (!r) return; const c = cats[+r.dataset.i], k = e.target.dataset.k; if (!k) return;
    if (k === 'own') { readSla(); c.own = e.target.checked; if (c.own && !Object.keys(S[c.key]).length) S[c.key] = JSON.parse(JSON.stringify(S.g)); ownBox(); return; }
    c[k] = k === 'active' ? e.target.checked : k === 'team_id' ? +e.target.value || null : e.target.value; if (k === 'name' || k === 'color') ownBox(); };
  $('tc-cats').addEventListener('change', onCat);
  $('tc-cats').addEventListener('input', e => { if (['name', 'color'].includes(e.target.dataset.k)) { const c = cats[+e.target.closest('[data-i]').dataset.i]; c[e.target.dataset.k] = e.target.value; } });
  $('tc-cats').addEventListener('click', e => { const r = e.target.closest('[data-i]'); if (r && e.target.closest('[data-rm]')) { readSla(); cats.splice(+r.dataset.i, 1); drawCats(); ownBox(); } });
  $('tc-own').addEventListener('click', e => { const b = e.target.closest('[data-own-off]'); if (!b) return; readSla(); const c = cats.find(x => x.key === b.dataset.ownOff); if (c) c.own = false; drawCats(); ownBox(); });
  $('tc-add').addEventListener('click', () => { readSla(); const key = 'n' + (++nk); S[key] = {}; cats.push({ id: 0, key, name: 'Nueva categoría', color: '#3b82f6', team_id: null, active: 1, own: false }); drawCats(); ownBox(); });
  $('wk-main').querySelectorAll('[data-tb]').forEach(s => s.addEventListener('change', () => { tb[s.dataset.tb] = +s.value; }));
  $('tc-save').addEventListener('click', async () => {
    readSla();
    const sla = [];
    const push = (k, cat) => P.forEach(p => { const v = S[k][p.id]; if (v && v[0] > 0 && v[1] > 0) sla.push({ ...cat, priority: p.id, response_min: v[0], resolution_min: v[1] }); });
    push('g', { category_id: 0 });
    cats.filter(c => c.own).forEach(c => push(c.key, c.id ? { category_id: c.id } : { cat_key: c.key }));
    try {
      await api('tk_cfg_save', { cats: cats.map(c => ({ id: c.id, key: c.key, name: c.name, color: c.color, team_id: c.team_id, active: c.active })), sla, team_boards: tb, warn_min: +$('tc-warn').value, digest_hour: +$('tc-dig').value, chat_min: Math.round((+$('tc-chat').value || 0) * 60), delivery_tpl: $('tc-dtpl').value });
      toast('Configuración guardada'); renderTicketCfg();
    } catch (e) { toast(e.message, true); }
  });
  drawCats(); ownBox();
  jrCard();
}
/** Reportes desde el juego (juegos/reportes.php): prender, y una clave por juego. La clave entera se ve una sola vez. */
async function jrCard(r) {
  const box = $('jr-card'); if (!box) return;
  try { r = r || await api('jr_admin'); } catch (e) { box.innerHTML = `<h3><i class="fa-solid fa-gamepad"></i>Reportes desde el juego</h3><div class="wk-help">${esc(e.message)}</div>`; return; }
  const gameSel = (sel, attr) => r.games.length ? `<select class="wk-in" ${attr} style="max-width:200px" aria-label="Juego"><option value="0">Sin juego de Juegos</option>${r.games.map(g => `<option value="${g.id}"${+sel === g.id ? ' selected' : ''}>${esc(g.name)}</option>`).join('')}</select>` : '';
  const when = d => d ? ago(d) : 'nunca';
  box.innerHTML = `<h3><i class="fa-solid fa-gamepad"></i>Reportes desde el juego<span style="flex:1"></span><label class="wk-chkl"><input type="checkbox" id="jr-on"${r.on ? ' checked' : ''}> Recibir reportes</label></h3>
    <p class="wk-help" style="margin:0 0 10px">Los jugadores mandan un bug, una sugerencia o una consulta desde el juego y entran aquí como tickets (origen <b>Juego</b>), con su tarea. Las respuestas públicas y el estado vuelven al juego; las notas internas no.
      Categorías: ${Object.values(r.cats).map(c => `<b>${esc(c.name)}</b>`).join(', ')}. Se renombran o se asignan a un departamento en Categorías, arriba.</p>
    <div class="wk-kv" style="margin-bottom:10px"><div class="k">Dirección</div><div class="v"><code>${esc(r.endpoint)}</code></div></div>
    <div id="jr-keys">${r.keys.length ? r.keys.map(k => `<div class="wk-st" data-k="${k.id}"><i class="fa-solid fa-key" style="opacity:.6;color:${k.active ? '#22c55e' : '#8b8b94'}"></i>
        <input class="wk-in" value="${esc(k.name)}" data-jr="name" maxlength="80" aria-label="Nombre de la clave">${gameSel(k.game_id, 'data-jr="game"')}
        <code title="Primeros caracteres de la clave">${esc(k.hint)}</code><small class="tkt-mute">${k.players} jugador(es) · ${k.reports} reporte(s) · usada ${esc(when(k.last_used_at))}</small>
        <label class="wk-chkl"><input type="checkbox" data-jr="active"${k.active ? ' checked' : ''}> Activa</label>
        <button class="wk-btn sm" type="button" data-jr="rotate" title="Genera una clave nueva: la de antes deja de funcionar"><i class="fa-solid fa-rotate"></i> Rotar</button>
        ${k.players ? '' : '<button type="button" class="x" data-jr="del" aria-label="Borrar la clave"><i class="fa-solid fa-xmark"></i></button>'}</div>`).join('') : '<div class="wk-help">Todavía no hay claves.</div>'}</div>
    <div class="wk-fr" style="margin-top:10px;align-items:flex-end"><label class="wk-f"><span>Clave nueva</span><input class="wk-in" id="jr-new-name" maxlength="80" placeholder="Nombre (ej.: NEIBOR)"></label>${gameSel(0, 'id="jr-new-game"')}
      <button class="wk-btn sm" type="button" id="jr-new"><i class="fa-solid fa-plus"></i> Crear clave</button></div>
    <div id="jr-show"></div>
    <div class="wk-help">Cada clave es para un juego y va en la cabecera <code>X-Game-Key</code>. Se guarda solo su huella (hash): la clave entera se muestra una sola vez, al crearla o rotarla. Los jugadores son de cada clave; rotarla no los pierde.</div>`;
  const show = (key, what) => { $('jr-show').innerHTML = `<div class="tk-banner" style="margin-top:10px"><span class="tk-src" style="--s:#22c55e"><i class="fa-solid fa-key"></i></span><span class="tx"><b>${esc(what)}</b> Cópiala ahora: no se vuelve a mostrar.<br><code style="user-select:all;word-break:break-all">${esc(key)}</code></span><button class="wk-btn" type="button" id="jr-copy"><i class="fa-regular fa-copy"></i> Copiar</button></div>`;
    $('jr-copy').addEventListener('click', async () => { try { await navigator.clipboard.writeText(key); toast('Clave copiada'); } catch (e) { toast('No se pudo copiar: selecciónala a mano', true); } }); };
  const run = async (action, data, after) => { try { const x = await api(action, data); await jrCard(x); if (after) after(x); } catch (e) { toast(e.message, true); } };
  $('jr-on').addEventListener('change', e => run('jr_on', { on: e.target.checked }, x => toast(x.on ? 'Reportes desde el juego prendidos' : 'Reportes desde el juego apagados')));
  $('jr-new').addEventListener('click', () => run('jr_key_new', { name: $('jr-new-name').value, game_id: +($('jr-new-game')?.value || 0) }, x => show(x.key, 'Clave creada.')));
  $('jr-keys').addEventListener('change', e => { const row = e.target.closest('[data-k]'), w = e.target.dataset.jr; if (!row || !w) return; const id = +row.dataset.k;
    if (w === 'name') run('jr_key_set', { id, name: e.target.value }, () => toast('Nombre guardado'));
    if (w === 'game') run('jr_key_set', { id, game_id: +e.target.value }, () => toast('Juego guardado'));
    if (w === 'active') run('jr_key_set', { id, active: e.target.checked }, () => toast(e.target.checked ? 'Clave activada' : 'Clave desactivada: el juego ya no puede mandar reportes con ella')); });
  $('jr-keys').addEventListener('click', e => { const b = e.target.closest('[data-jr]'), row = e.target.closest('[data-k]'); if (!b || !row) return; const id = +row.dataset.k;
    if (b.dataset.jr === 'rotate' && confirm('¿Rotar la clave? La de antes deja de funcionar y hay que poner la nueva en el juego.')) run('jr_key_rotate', { id }, x => show(x.key, 'Clave rotada.'));
    if (b.dataset.jr === 'del' && confirm('¿Borrar la clave?')) run('jr_key_del', { id }, () => toast('Clave borrada')); });
}

// ── PROYECTOS (fase 3) ───────────────────────────────────────
const HEALTH = { ok: ['En camino', '#22c55e', 'fa-circle-check'], risk: ['En riesgo', '#f59e0b', 'fa-triangle-exclamation'], late: ['Atrasado', '#ef4444', 'fa-circle-exclamation'], na: ['', '#8b8b94', 'fa-circle-pause'] };
const pStatus = k => { const s = (W.projectStatus || {})[k] || [k, '#8b8b94']; return { name: s[0], color: s[1] }; };
function progBar(p, big = false) {
  return `<div class="wk-prog${big ? ' big' : ''}" role="progressbar" aria-valuenow="${p}" aria-valuemin="0" aria-valuemax="100"><i style="width:${p}%"></i></div>`;
}
function projCard(p) {
  const s = p.stats, h = HEALTH[p.health.key] || HEALTH.na, st = pStatus(p.status);
  return `<a class="wk-proj" href="#proyectos/${p.id}" style="--c:${esc(p.color)}">
    <div class="top"><span class="wk-pill" style="--c:${st.color}">${esc(st.name)}</span>${p.health.key !== 'na' ? `<span class="wk-health" style="--c:${h[1]}" title="${esc(p.health.label)}"><i class="fa-solid ${h[2]}"></i>${h[0]}</span>` : ''}</div>
    <b class="nm">${esc(p.name)}</b>
    <span class="sub">${[p.client_name, p.team_name].filter(Boolean).map(esc).join(' · ') || 'Sin ' + W.cli.toLowerCase() + ' ni departamento'}</span>
    <div class="pr"><span>${s.progress}%</span>${progBar(s.progress)}</div>
    <div class="st"><span><b>${s.done}/${s.total}</b> tareas</span>${s.ms ? `<span><b>${s.ms_done}/${s.ms}</b> hitos</span>` : ''}${p.due_date ? `<span>${dueHtml(p.due_date, ['terminado', 'cancelado'].includes(p.status))}</span>` : ''}</div>
    ${p.owner_id ? `<span class="own">${av(userOf(p.owner_id), 22)}${esc(userOf(p.owner_id)?.name || '')}</span>` : ''}</a>`;
}
async function renderProjects(arg) {
  if (+arg) return renderProject(+arg);
  const arch = arg === 'archivados';
  const r = await api('projects', { archived: arch });
  W.clients = r.clients; W.projectStatus = r.status;
  const groups = arch ? [['Archivados', r.projects]] : [
    ['Activos', r.projects.filter(p => ['en_curso', 'planificado', 'en_pausa'].includes(p.status))],
    ['Cerrados', r.projects.filter(p => ['terminado', 'cancelado'].includes(p.status))],
  ];
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Proyectos<span>.</span></h1><p>Cada proyecto junta tareas de cualquier tablero, con hitos, responsable y fechas. El avance sale de las tareas terminadas (o de sus horas estimadas).</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="#proyectos${arch ? '' : '/archivados'}"><i class="fa-solid ${arch ? 'fa-arrow-left' : 'fa-box-archive'}"></i> ${arch ? 'Volver' : 'Archivados'}</a>
      ${!arch && r.can_create ? '<button class="wk-btn primary" type="button" id="wk-new-proj"><i class="fa-solid fa-plus"></i> Nuevo proyecto</button>' : ''}</div></div>
    ${r.projects.length ? groups.filter(g => g[1].length).map(([n, l]) => `<div class="wk-sec"><h2>${n}</h2><small>${l.length}</small></div><div class="wk-projs">${l.map(projCard).join('')}</div>`).join('')
      : emptyBox('fa-diagram-project', arch ? 'No hay proyectos archivados.' : 'Todavía no hay proyectos. Crea uno y sumale tareas de tus tableros.')}`;
  $('wk-new-proj')?.addEventListener('click', () => projectDialog());
}
async function renderProject(id) {
  const r = await api('project_get', { id });
  const p = r.project; W.projectStatus = r.status; W.clients = r.clients;
  const s = p.stats, h = HEALTH[p.health.key] || HEALTH.na, st = pStatus(p.status), can = p.can_manage;
  const ms = p.milestones, byMs = mid => p.tasks.filter(t => (t.milestone_id || 0) === mid);
  const taskRow = t => `<a class="wk-row${t.completed_at ? ' done' : ''}" href="${taskUrl(t)}"><span class="wk-dot" style="--c:${esc(prioOf(t.priority).color)}" title="${esc(prioOf(t.priority).name)}"></span>
      <span class="t"><b>${esc(t.title)}</b><span><span class="wk-bchip"><span class="wk-dot" style="--c:${esc(t.board_color || '#888')}"></span>${esc(t.board_name || '')}</span>${t.assignees.map(u => esc(userOf(u)?.name || '')).join(', ')}${t.estimate_min ? ` · ${fmtMin(t.minutes)} de ${fmtMin(t.estimate_min)}` : t.minutes ? ' · ' + fmtMin(t.minutes) : ''}</span></span>
      <span class="wk-pill" style="--c:${esc(statusOf(t.status, t.board_id).color)}">${esc(statusOf(t.status, t.board_id).name)}</span>${dueHtml(t.due_date, !!t.completed_at)}</a>`;
  const msBlock = m => {
    const list = byMs(m ? +m.id : 0), d = list.filter(t => t.completed_at).length;
    const pr = list.length ? Math.round(d * 100 / list.length) : 0;
    const late = m && !m.done_at && m.due_date && m.due_date < W.today;
    if (!m && !list.length) return '';
    return `<div class="wk-ms${m?.done_at ? ' done' : ''}${late ? ' late' : ''}">
      <div class="wk-ms-h">${m ? `<button class="wk-chk${m.done_at ? ' on' : ''}" type="button" data-msdone="${m.id}" ${can ? '' : 'disabled'} title="${m.done_at ? 'Volver a abrir el hito' : 'Marcar el hito como cumplido'}" aria-label="Hito cumplido"><i class="fa-solid fa-check"></i></button>` : '<i class="fa-regular fa-folder-open" style="width:20px;text-align:center;color:var(--g7-text-4)"></i>'}
        <span class="n"><b>${m ? esc(m.name) : 'Sin hito'}</b>${m?.description ? `<small>${esc(m.description)}</small>` : ''}</span>
        ${m?.due_date ? dueHtml(m.due_date, !!m.done_at) : ''}<span class="c">${d}/${list.length}</span><span class="wk-msp">${progBar(pr)}</span>
        ${m && can ? `<button class="wk-ic" type="button" data-msedit="${m.id}" aria-label="Editar hito"><i class="fa-solid fa-pen"></i></button>` : ''}</div>
      ${list.length ? `<div class="wk-rows">${list.map(taskRow).join('')}</div>` : '<div class="wk-help" style="padding:4px 2px 8px 30px">Sin tareas todavía.</div>'}</div>`;
  };
  const hrs = s.minutes || 0, bud = p.budget_min || 0;
  $('wk-main').innerHTML = `<a class="wk-link" href="#proyectos"><i class="fa-solid fa-arrow-left"></i> Proyectos</a>
    <div class="wk-h" style="margin-top:8px"><div><h1><span class="wk-pdot" style="background:${esc(p.color)}"></span>${esc(p.name)}<span>.</span></h1>
      <p><span class="wk-pill" style="--c:${st.color}">${esc(st.name)}</span> ${p.health.key !== 'na' ? `<span class="wk-health" style="--c:${h[1]}"><i class="fa-solid ${h[2]}"></i>${esc(p.health.label)}</span>` : ''}
      ${p.client_name ? ` · <i class="fa-solid fa-briefcase"></i> ${esc(p.client_name)}` : ''}${p.owner_id ? ` · Responsable: <b>${esc(userOf(p.owner_id)?.name || '')}</b>` : ''}</p></div>
      <div class="wk-h-a">${can ? `<button class="wk-btn" type="button" id="pj-edit"><i class="fa-solid fa-pen"></i> Editar</button>` : ''}</div></div>
    ${p.description ? `<p class="wk-pdesc">${esc(p.description)}</p>` : ''}
    <div class="wk-kpis">
      <div class="wk-kpi"><small><i class="fa-solid fa-gauge"></i>Avance</small><b>${s.progress}%</b>${progBar(s.progress, true)}<span>por ${s.by}</span></div>
      <div class="wk-kpi"><small><i class="fa-solid fa-list-check"></i>Tareas</small><b>${s.done}/${s.total}</b><span>${s.total - s.done} abiertas</span></div>
      <div class="wk-kpi ${s.late ? 'alert' : ''}"><small><i class="fa-solid fa-circle-exclamation ic-late"></i>Vencidas</small><b>${s.late}</b><span>tareas abiertas</span></div>
      <div class="wk-kpi"><small><i class="fa-solid fa-flag-checkered"></i>Hitos</small><b>${s.ms_done}/${s.ms}</b><span>${s.ms_late ? s.ms_late + ' vencido' + (s.ms_late > 1 ? 's' : '') : 'cumplidos'}</span></div>
      <div class="wk-kpi ${bud && hrs > bud ? 'alert' : ''}"><small><i class="fa-solid fa-clock"></i>Horas</small><b>${fmtMin(hrs)}</b><span>${bud ? `de ${fmtMin(bud)} presupuestadas (${pct(hrs, bud)}%)` : 'sin presupuesto'}</span></div>
      <div class="wk-kpi"><small><i class="fa-regular fa-calendar"></i>Entrega</small><b style="font-size:20px">${p.due_date ? dueHtml(p.due_date, ['terminado', 'cancelado'].includes(p.status)) : '—'}</b><span>${p.start_date ? 'desde el ' + p.start_date.split('-').reverse().join('/') : 'sin fecha de inicio'}</span></div>
    </div>
    <div class="wk-2" style="margin-top:14px;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)">
      <div class="wk-card"><h3><i class="fa-solid fa-flag-checkered"></i>Hitos y tareas<span class="sp"></span>${can ? '<button class="wk-btn sm" type="button" id="pj-ms-add"><i class="fa-solid fa-plus"></i> Hito</button>' : ''}</h3>
        ${p.boards.length ? `<form class="wk-padd" id="pj-add" autocomplete="off"><input class="wk-in" name="title" maxlength="250" placeholder="Nueva tarea del proyecto…" aria-label="Título de la tarea">
          <select class="wk-in" name="board" aria-label="Tablero">${p.boards.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select>
          <select class="wk-in" name="ms" aria-label="Hito"><option value="0">Sin hito</option>${ms.filter(m => !m.done_at).map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</select>
          <button class="wk-btn primary" type="submit"><i class="fa-solid fa-plus"></i> Agregar</button>
          <button class="wk-btn" type="button" id="pj-link" title="Sumar una tarea que ya existe"><i class="fa-solid fa-link"></i></button></form>` : ''}
        ${ms.map(msBlock).join('')}${msBlock(null)}
        ${!ms.length && !p.tasks.length ? emptyBox('fa-flag', 'Agrega hitos (entregas intermedias) y tareas al proyecto.') : ''}</div>
      <div>
        <div class="wk-card"><h3><i class="fa-solid fa-users"></i>Equipo del proyecto</h3>
          ${p.members.map(m => `<div class="wk-mem">${av(userOf(m.user_id))}<span class="n">${esc(userOf(m.user_id)?.name || '#' + m.user_id)}</span>${can ? `<select data-prole="${m.user_id}" aria-label="Rol"><option value="member"${m.role === 'member' ? ' selected' : ''}>Miembro</option><option value="manager"${m.role === 'manager' ? ' selected' : ''}>Encargado</option></select><button class="x" type="button" data-prm="${m.user_id}" aria-label="Sacar"><i class="fa-solid fa-xmark"></i></button>` : `<span class="wk-help">${m.role === 'manager' ? 'Encargado' : 'Miembro'}</span>`}</div>`).join('') || '<div class="wk-help">Sin miembros.</div>'}
          ${can ? `<select class="wk-in" id="pj-madd" style="margin-top:8px" aria-label="Sumar persona"><option value="">Sumar a alguien…</option>${W.users.filter(u => !p.members.some(m => m.user_id === u.id)).map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select>` : ''}</div>
        <div class="wk-card" style="margin-top:14px"><h3><i class="fa-solid fa-clock"></i>Horas por persona</h3>
          ${p.hours.length ? (() => { const mx = Math.max(...p.hours.map(x => x.minutes)); return p.hours.map(x => `<div class="wk-hb" data-tip="${esc(userOf(x.user_id)?.name || '')}: <b>${fmtMin(x.minutes)}</b>"><span class="t">${esc(userOf(x.user_id)?.name || '#' + x.user_id)}</span><span class="b"><i style="--c:var(--g7-accent);width:${x.minutes / mx * 100}%"></i></span><span class="n" style="width:auto">${fmtMin(x.minutes)}</span></div>`).join(''); })() : '<div class="wk-help">Todavía nadie cargó horas en las tareas del proyecto.</div>'}</div>
      </div></div>`;
  const reload = () => renderProject(id);
  $('pj-edit')?.addEventListener('click', () => projectDialog(p));
  $('pj-ms-add')?.addEventListener('click', () => milestoneDialog(p, null, reload));
  $('wk-main').querySelectorAll('[data-msedit]').forEach(b => b.addEventListener('click', () => milestoneDialog(p, ms.find(m => m.id === +b.dataset.msedit), reload)));
  $('wk-main').querySelectorAll('[data-msdone]').forEach(b => b.addEventListener('click', async () => {
    const m = ms.find(x => x.id === +b.dataset.msdone);
    try {
      let res = await api('milestone_done', { project_id: id, id: m.id, on: !m.done_at });
      if (res.confirm) { if (!confirm(res.confirm)) return; res = await api('milestone_done', { project_id: id, id: m.id, on: true, force: true }); }
      toast(m.done_at ? 'Hito abierto de nuevo' : 'Hito cumplido'); reload();
    } catch (e) { toast(e.message, true); }
  }));
  $('pj-add')?.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, title = f.title.value.trim(); if (!title) return;
    try {
      await api('save', { board: +f.board.value, title, project_id: id, milestone_id: +f.ms.value || 0 }, '/toolz/tareas_api.php');
      toast('Tarea agregada'); reload();
    } catch (err) { toast(err.message, true); }
  });
  $('pj-link')?.addEventListener('click', () => linkTaskDialog(p, reload));
  const member = async (user_id, op) => { try { await api('project_member', { project_id: id, user_id, op }); reload(); } catch (e) { toast(e.message, true); } };
  $('wk-main').querySelectorAll('[data-prole]').forEach(s => s.addEventListener('change', () => member(+s.dataset.prole, s.value)));
  $('wk-main').querySelectorAll('[data-prm]').forEach(b => b.addEventListener('click', () => member(+b.dataset.prm, 'remove')));
  $('pj-madd')?.addEventListener('change', e => { if (e.target.value) member(+e.target.value, 'add'); });
}
function projectDialog(p = null) {
  const v = p || { name: '', description: '', client_id: null, team_id: W.me.lead[0] || W.me.teams[0] || null, owner_id: W.me.id, status: 'planificado', start_date: W.today, due_date: '', budget_min: null, color: COLORS[0], visibility: 'public' };
  const teams = W.teams.filter(t => W.me.role === 'admin' || W.me.role === 'director' || W.me.teams.includes(t.id) || t.id === v.team_id);
  const d = openDlg(`<h3>${p ? 'Editar proyecto' : 'Nuevo proyecto'}</h3>
    <label class="wk-f"><span>Nombre</span><input class="wk-in" id="pd-name" maxlength="120" value="${esc(v.name)}" placeholder="Ej.: Lanzamiento de la web nueva"></label>
    <label class="wk-f"><span>Descripción</span><textarea class="wk-in" id="pd-desc" rows="2" maxlength="5000">${esc(v.description || '')}</textarea></label>
    <div class="wk-fr"><label class="wk-f"><span>${esc(W.cli)}</span><select class="wk-in" id="pd-client"><option value="0">Sin ${esc(W.cli.toLowerCase())}</option>${(W.clients || []).map(c => `<option value="${c.id}"${c.id === v.client_id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Responsable</span><select class="wk-in" id="pd-owner"><option value="0">Sin responsable</option>${W.users.map(u => `<option value="${u.id}"${u.id === v.owner_id ? ' selected' : ''}>${esc(u.name)}</option>`).join('')}</select></label></div>
    <div class="wk-fr"><label class="wk-f"><span>Departamento</span><select class="wk-in" id="pd-team"><option value="0">Sin departamento</option>${teams.map(t => `<option value="${t.id}"${t.id === v.team_id ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Quién lo ve</span><select class="wk-in" id="pd-vis">${Object.entries(W.vis).map(([k, n]) => `<option value="${k}"${k === v.visibility ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label></div>
    <div class="wk-fr"><label class="wk-f"><span>Inicio</span><input class="wk-in" type="date" id="pd-start" value="${esc(v.start_date || '')}"></label>
      <label class="wk-f"><span>Entrega</span><input class="wk-in" type="date" id="pd-due" value="${esc(v.due_date || '')}"></label></div>
    <div class="wk-fr"><label class="wk-f"><span>Estado</span><select class="wk-in" id="pd-status">${Object.entries(W.projectStatus || {}).map(([k, s]) => `<option value="${k}"${k === v.status ? ' selected' : ''}>${esc(s[0])}</option>`).join('')}</select></label>
      <label class="wk-f"><span>Horas presupuestadas</span><input class="wk-in" type="text" inputmode="decimal" id="pd-budget" value="${v.budget_min ? +(v.budget_min / 60).toFixed(2) : ''}" placeholder="Opcional"></label></div>
    <div class="wk-f"><span>Color</span><div class="wk-colors" id="pd-colors">${COLORS.map(c => `<button type="button" style="--c:${c}" data-c="${c}" class="${c === v.color ? 'on' : ''}" aria-label="Color ${c}"></button>`).join('')}</div></div>
    <div class="wk-err" id="pd-err"></div>
    <div class="acts">${p ? `<button class="wk-btn danger" type="button" id="pd-arch"><i class="fa-solid fa-box-archive"></i> ${p.archived ? 'Restaurar' : 'Archivar'}</button>${p.archived ? '<button class="wk-btn danger" type="button" id="pd-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : ''}` : ''}<span class="sp"></span>
      <button class="wk-btn" type="button" id="pd-cancel">Cancelar</button><button class="wk-btn primary" type="button" id="pd-ok">${p ? 'Guardar' : 'Crear proyecto'}</button></div>`);
  let color = v.color;
  d.querySelector('#pd-colors').addEventListener('click', e => { const x = e.target.closest('[data-c]'); if (!x) return; color = x.dataset.c; d.querySelectorAll('#pd-colors button').forEach(y => y.classList.toggle('on', y === x)); });
  d.querySelector('#pd-cancel').addEventListener('click', closeDlg);
  const g = s => d.querySelector(s).value;
  d.querySelector('#pd-ok').addEventListener('click', async () => {
    try {
      const r = await api('project_save', { id: p?.id || 0, name: g('#pd-name'), description: g('#pd-desc'), client_id: +g('#pd-client'), owner_id: +g('#pd-owner'), team_id: +g('#pd-team'),
        visibility: g('#pd-vis'), start_date: g('#pd-start'), due_date: g('#pd-due'), status: g('#pd-status'), budget_h: g('#pd-budget'), color });
      closeDlg(); toast(p ? 'Proyecto guardado' : 'Proyecto creado');
      if (location.hash === '#proyectos/' + r.id) route(); else location.hash = 'proyectos/' + r.id;
    } catch (e) { d.querySelector('#pd-err').textContent = e.message; }
  });
  d.querySelector('#pd-arch')?.addEventListener('click', async () => { try { await api('project_archive', { id: p.id, on: !p.archived }); closeDlg(); toast(p.archived ? 'Proyecto restaurado' : 'Proyecto archivado'); location.hash = 'proyectos'; } catch (e) { d.querySelector('#pd-err').textContent = e.message; } });
  d.querySelector('#pd-del')?.addEventListener('click', async () => {
    if (!confirm('¿Borrar el proyecto? Sus tareas no se borran: quedan en sus tableros, sin proyecto.')) return;
    try { await api('project_delete', { id: p.id }); closeDlg(); toast('Proyecto borrado'); location.hash = 'proyectos/archivados'; } catch (e) { d.querySelector('#pd-err').textContent = e.message; }
  });
  d.querySelector('#pd-name').focus();
}
function milestoneDialog(p, m, done) {
  const d = openDlg(`<h3>${m ? 'Editar hito' : 'Nuevo hito'}</h3>
    <label class="wk-f"><span>Nombre</span><input class="wk-in" id="md-name" maxlength="120" value="${esc(m?.name || '')}" placeholder="Ej.: Primera entrega al cliente"></label>
    <label class="wk-f"><span>Fecha</span><input class="wk-in" type="date" id="md-due" value="${esc(m?.due_date || '')}"></label>
    <label class="wk-f"><span>Detalle</span><textarea class="wk-in" id="md-desc" rows="2" maxlength="500">${esc(m?.description || '')}</textarea></label>
    <div class="wk-err" id="md-err"></div>
    <div class="acts">${m ? '<button class="wk-btn danger" type="button" id="md-del"><i class="fa-solid fa-trash"></i> Borrar</button>' : ''}<span class="sp"></span><button class="wk-btn" type="button" id="md-cancel">Cancelar</button><button class="wk-btn primary" type="button" id="md-ok">Guardar</button></div>`);
  d.querySelector('#md-cancel').addEventListener('click', closeDlg);
  d.querySelector('#md-ok').addEventListener('click', async () => {
    try { await api('milestone_save', { project_id: p.id, id: m?.id || 0, name: d.querySelector('#md-name').value, due_date: d.querySelector('#md-due').value, description: d.querySelector('#md-desc').value }); closeDlg(); toast('Hito guardado'); done(); }
    catch (e) { d.querySelector('#md-err').textContent = e.message; }
  });
  d.querySelector('#md-del')?.addEventListener('click', async () => {
    if (!confirm('¿Borrar el hito? Sus tareas quedan en el proyecto, sin hito.')) return;
    try { await api('milestone_del', { project_id: p.id, id: m.id }); closeDlg(); toast('Hito borrado'); done(); } catch (e) { d.querySelector('#md-err').textContent = e.message; }
  });
  d.querySelector('#md-name').focus();
}
function linkTaskDialog(p, done) {
  const d = openDlg(`<h3>Sumar una tarea al proyecto</h3>
    <input class="wk-in" id="lt-q" type="search" placeholder="Buscar por título…" aria-label="Buscar tarea" autocomplete="off">
    <label class="wk-f" style="margin-top:10px"><span>Hito</span><select class="wk-in" id="lt-ms"><option value="0">Sin hito</option>${p.milestones.filter(m => !m.done_at).map(m => `<option value="${m.id}">${esc(m.name)}</option>`).join('')}</select></label>
    <div id="lt-list" class="wk-rows" style="max-height:320px;overflow:auto;margin-top:6px"></div>
    <div class="acts"><button class="wk-btn" type="button" id="lt-close">Cerrar</button></div>`);
  d.querySelector('#lt-close').addEventListener('click', closeDlg);
  const load = async q => {
    try {
      const r = await api('project_task_search', { project_id: p.id, q });
      d.querySelector('#lt-list').innerHTML = r.items.map(t => `<button class="wk-row" type="button" data-lt="${t.id}" style="border:0;background:none;width:100%;text-align:left;cursor:pointer;color:inherit"><span class="wk-dot" style="--c:var(--g7-accent)"></span><span class="t"><b>${esc(t.title)}</b><span>${esc(t.board_name || '')}${t.completed_at ? ' · terminada' : ''}</span></span><span></span><span class="wk-link">Sumar</span></button>`).join('') || '<div class="wk-help">No hay tareas sin proyecto con ese título.</div>';
    } catch (e) { d.querySelector('#lt-list').innerHTML = `<div class="wk-err">${esc(e.message)}</div>`; }
  };
  let qt; d.querySelector('#lt-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => load(e.target.value), 200); });
  d.querySelector('#lt-list').addEventListener('click', async e => {
    const b = e.target.closest('[data-lt]'); if (!b) return;
    try { await api('save', { id: +b.dataset.lt, project_id: p.id, milestone_id: +d.querySelector('#lt-ms').value || 0 }, '/toolz/tareas_api.php'); b.remove(); toast('Tarea sumada al proyecto'); done(); }
    catch (err) { toast(err.message, true); }
  });
  load(''); d.querySelector('#lt-q').focus();
}

// ── CALENDARIO (todos los tableros) ──────────────────────────
W.cal = { mode: 'month', date: '', board: '', user: '', done: false };
const iso = d => d.toISOString().slice(0, 10);
const addDays = (s, n) => iso(new Date(dayMs(s) + n * 864e5));
const mondayOf = s => { const d = new Date(dayMs(s)); return addDays(s, -((d.getUTCDay() + 6) % 7)); };
async function renderCalendar() {
  const C = W.cal; if (!C.date) C.date = W.today;
  if (!W.boards) try { W.boards = (await api('boards')).boards; } catch (e) { W.boards = []; }
  let from, to, title;
  const d0 = new Date(dayMs(C.date));
  if (C.mode === 'month') {
    const first = iso(new Date(Date.UTC(d0.getUTCFullYear(), d0.getUTCMonth(), 1)));
    const last = iso(new Date(Date.UTC(d0.getUTCFullYear(), d0.getUTCMonth() + 1, 0)));
    from = mondayOf(first); to = addDays(mondayOf(last), 6);
    title = `${['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][d0.getUTCMonth()]} ${d0.getUTCFullYear()}`;
  } else if (C.mode === 'week') {
    from = mondayOf(C.date); to = addDays(from, 6);
    title = `Semana del ${+from.slice(8)} ${MON[+from.slice(5, 7) - 1]}`;
  } else if (C.mode === 'day') {
    from = to = C.date; title = `${['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][d0.getUTCDay()]} ${+C.date.slice(8)} ${MON[+C.date.slice(5, 7) - 1]}`;
  } else { from = C.date; to = addDays(C.date, 30); title = 'Próximos 30 días'; }
  const r = await api('calendar', { from, to, board: +C.board || 0, user: +C.user || 0, done: C.done });
  const chip = t => { const s = statusOf(t.status, t.board_id), b = (W.boards || []).find(x => x.id === t.board_id);
    return `<a class="wk-cchip${t.completed_at ? ' done' : ''}" href="${taskUrl(t)}" style="--c:${esc(b?.color || '#888')}" title="${esc(t.title)} · ${esc(s.name)}${b ? ' · ' + esc(b.name) : ''}"><span class="wk-dot" style="--c:${esc(s.color)}"></span>${esc(t.title)}</a>`; };
  const dueOn = day => r.tasks.filter(t => t.due_date === day || (!t.due_date && t.start_date === day));
  let body = '';
  if (C.mode === 'month' || C.mode === 'week') {
    const n = C.mode === 'month' ? Math.round((dayMs(to) - dayMs(from)) / 864e5) + 1 : 7;
    body = `<div class="wk-cal ${C.mode}"><div class="wk-cal-h">${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(x => `<div>${x}</div>`).join('')}</div><div class="wk-cal-g">`;
    for (let i = 0; i < n; i++) {
      const day = addDays(from, i), list = dueOn(day), out = C.mode === 'month' && +day.slice(5, 7) !== d0.getUTCMonth() + 1;
      const max = C.mode === 'month' ? 4 : 50;
      body += `<div class="wk-day${out ? ' out' : ''}${day === W.today ? ' today' : ''}"><button class="dn" type="button" data-day="${day}">${+day.slice(8)}</button>${list.slice(0, max).map(chip).join('')}${list.length > max ? `<button class="more" type="button" data-day="${day}">+${list.length - max} más</button>` : ''}</div>`;
    }
    body += '</div></div>';
  } else {
    const days = [...new Set(r.tasks.map(t => t.due_date || t.start_date))].sort();
    body = days.length ? days.map(day => `<div class="wk-group${day < W.today ? ' late' : ''}">${dueHtml(day)} <span class="n">${dueOn(day).length}</span></div><div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${dueOn(day).map(t => { const s = statusOf(t.status, t.board_id), b = (W.boards || []).find(x => x.id === t.board_id);
      return `<a class="wk-row${t.completed_at ? ' done' : ''}" href="${taskUrl(t)}"><span class="wk-dot" style="--c:${esc(prioOf(t.priority).color)}"></span><span class="t"><b>${esc(t.title)}</b><span><span class="wk-bchip"><span class="wk-dot" style="--c:${esc(b?.color || '#888')}"></span>${esc(b?.name || '')}</span>${t.assignees.map(id => esc(userOf(id)?.name || '')).join(', ')}</span></span><span class="wk-pill" style="--c:${esc(s.color)}">${esc(s.name)}</span><span></span></a>`; }).join('')}</div></div>`).join('')
      : emptyBox('fa-calendar-check', 'No hay tareas con fecha en este período.');
  }
  const opt = (v, l, cur) => `<option value="${v}"${String(v) === String(cur) ? ' selected' : ''}>${esc(l)}</option>`;
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Calendario<span>.</span></h1><p>Las fechas límite de todos los tableros que puedes ver.</p></div></div>
    <div class="wk-bar">
      <div class="wk-tabs" style="margin:0">${[['day', 'Día'], ['week', 'Semana'], ['month', 'Mes'], ['agenda', 'Agenda']].map(([k, n]) => `<button type="button" data-mode="${k}" class="${C.mode === k ? 'on' : ''}">${n}</button>`).join('')}</div>
      <button class="wk-btn sm" type="button" data-nav="-1" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
      <button class="wk-btn sm" type="button" data-nav="0">Hoy</button>
      <button class="wk-btn sm" type="button" data-nav="1" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
      <b style="font-size:15px;margin:0 6px">${esc(title)}</b><span style="flex:1"></span>
      <select class="wk-in" id="cal-board" aria-label="Tablero">${opt('', 'Todos los tableros', C.board)}${(W.boards || []).map(b => opt(b.id, b.name, C.board)).join('')}</select>
      <select class="wk-in" id="cal-user" aria-label="Persona">${opt('', 'Todas las personas', C.user)}${opt(W.me.id, 'Solo lo mío', C.user)}${W.users.filter(u => u.id !== W.me.id).map(u => opt(u.id, u.name, C.user)).join('')}</select>
      <label class="wk-chkl"><input type="checkbox" id="cal-done"${C.done ? ' checked' : ''}> Terminadas</label>
    </div>${C.mode === 'day' ? (dueOn(C.date).length ? `<div class="wk-card" style="padding:6px 10px"><div class="wk-rows">${dueOn(C.date).map(t => `<a class="wk-row${t.completed_at ? ' done' : ''}" href="${taskUrl(t)}"><span class="wk-dot" style="--c:${esc(prioOf(t.priority).color)}"></span><span class="t"><b>${esc(t.title)}</b><span>${esc((W.boards || []).find(x => x.id === t.board_id)?.name || '')}</span></span><span class="wk-pill" style="--c:${esc(statusOf(t.status, t.board_id).color)}">${esc(statusOf(t.status, t.board_id).name)}</span><span></span></a>`).join('')}</div></div>` : emptyBox('fa-mug-hot', 'Nada vence este día.')) : body}`;
  const m = $('wk-main');
  m.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { C.mode = b.dataset.mode; renderCalendar(); }));
  m.querySelectorAll('[data-nav]').forEach(b => b.addEventListener('click', () => {
    const k = +b.dataset.nav;
    if (!k) C.date = W.today;
    else if (C.mode === 'month') { const d = new Date(dayMs(C.date)); C.date = iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + k, 1))); }
    else C.date = addDays(C.date, k * (C.mode === 'week' ? 7 : C.mode === 'agenda' ? 30 : 1));
    renderCalendar();
  }));
  m.querySelectorAll('[data-day]').forEach(b => b.addEventListener('click', () => { C.mode = 'day'; C.date = b.dataset.day; renderCalendar(); }));
  $('cal-board').addEventListener('change', e => { C.board = e.target.value; renderCalendar(); });
  $('cal-user').addEventListener('change', e => { C.user = e.target.value; renderCalendar(); });
  $('cal-done').addEventListener('change', e => { C.done = e.target.checked; renderCalendar(); });
}


// ── CONFIGURACIÓN (admins): roles ────────────────────────────
async function renderConfig() {
  const r = await api('roles');
  const opts = p => p.role === 'admin' ? `<span class="wk-role admin">Administrador</span> <span class="wk-help" style="display:inline">(se cambia en Usuarios)</span>`
    : `<select class="wk-in" data-user="${p.id}" style="width:auto;height:32px" aria-label="Rol de ${esc(p.name)}">
        <option value=""${['empleado', 'jefe'].includes(p.role) ? ' selected' : ''}>${p.role === 'jefe' ? 'Jefe de departamento (por su equipo)' : 'Empleado'}</option>
        <option value="director"${p.role === 'director' ? ' selected' : ''}>Director</option><option value="solicitante"${p.role === 'solicitante' ? ' selected' : ''}>Solicitante</option></select>`;
  $('wk-main').innerHTML = `<div class="wk-h"><div><h1>Configuración<span>.</span></h1><p>Quién es quién en Gestión de trabajo. Los departamentos son los Equipos: el administrador de un equipo es su jefe de departamento.</p></div>
      <div class="wk-h-a"><a class="wk-btn" href="/toolz/teams.php"><i class="fa-solid fa-people-group"></i> Equipos</a><a class="wk-btn" href="/toolz/tareas.php?b=${W.general}"><i class="fa-solid fa-sliders"></i> Estados y prioridades</a></div></div>
    <div class="wk-card" style="margin-bottom:14px"><h3><i class="fa-solid fa-circle-info"></i>Qué puede hacer cada rol</h3>
      <div class="wk-wrap"><table class="wk-t" style="cursor:default"><tbody style="cursor:default">
        <tr><td><span class="wk-role admin">Administrador</span></td><td>Ve y administra todo, asigna roles.</td></tr>
        <tr><td><span class="wk-role director">Director</span></td><td>Ve todos los tableros y la carga de todo el equipo; crea tableros en cualquier departamento.</td></tr>
        <tr><td><span class="wk-role jefe">Jefe de departamento</span></td><td>Ve los tableros de su departamento (también los privados) y la carga de su gente; los administra.</td></tr>
        <tr><td><span class="wk-role">Empleado</span></td><td>Ve los tableros públicos, los de su departamento y aquellos de los que es miembro; crea tareas y tableros.</td></tr>
        <tr><td><span class="wk-role solicitante">Solicitante</span></td><td>Solo ve las tareas que tiene asignadas o que creó.</td></tr>
      </tbody></table></div><div class="wk-help">El <b>Responsable</b> de un tablero se elige dentro de cada tablero (Tableros › editar).</div></div>
    <div class="wk-card"><h3><i class="fa-solid fa-user-shield"></i>Roles de cada persona</h3>
      <div class="wk-bar"><input class="wk-in" id="rl-q" type="search" placeholder="Buscar persona…" aria-label="Buscar persona"></div>
      <div class="wk-wrap"><table class="wk-t"><thead><tr><th>Persona</th><th>Departamentos</th><th>Rol</th></tr></thead><tbody id="rl-body">
      ${r.people.map(p => `<tr data-name="${esc(p.name.toLowerCase())}" style="cursor:default"><td><span class="who">${av(p)}${esc(p.name)}</span></td>
        <td>${p.teams.length ? p.teams.map(t => esc(t.name) + (t.lead ? ' <span class="wk-role jefe">jefe</span>' : '')).join(', ') : '<span class="muted">Ninguno</span>'}</td><td>${opts(p)}</td></tr>`).join('')}
      </tbody></table></div></div>`;
  $('rl-q').addEventListener('input', e => { const q = e.target.value.toLowerCase().trim(); $('rl-body').querySelectorAll('tr').forEach(tr => { tr.hidden = q && !tr.dataset.name.includes(q); }); });
  $('rl-body').addEventListener('change', async e => {
    const s = e.target.closest('[data-user]'); if (!s) return;
    try { await api('role_set', { user_id: +s.dataset.user, role: s.value }); toast('Rol guardado'); } catch (err) { toast(err.message, true); renderConfig(); }
  });
}

// ── Arranque ─────────────────────────────────────────────────
(async function boot() {
  try {
    const r = await api('boot'); setTimeout(() => setReqBadge(r.my_req_waiting || 0), 0);
    Object.assign(W, { me: r.me, admin: r.admin, teamView: r.team_view, canBoards: r.can_boards, users: r.users, teams: r.teams, cfg: r.config, roles: r.roles, vis: r.vis, general: r.general, today: r.today, boardStatuses: r.board_statuses || {}, fieldTypes: r.field_types || {}, projectStatus: r.project_status || {}, reqStatus: r.req_status || {}, cli: r.cli_label || 'Cliente', tkStatus: r.tk_status || {} });
    $('wk-nav-cal').hidden = !W.canBoards;
    $('wk-nav-proc').hidden = !W.canBoards;
  } catch (e) { $('wk-main').innerHTML = emptyBox('fa-triangle-exclamation', `<b>No se pudo cargar</b><br>${esc(e.message)}`); return; }
  $('wk-nav-boards').hidden = !W.canBoards;
  $('wk-nav-cfg').hidden = !W.admin;
  const me = userOf(W.me.id);
  $('wk-me').innerHTML = `<b>${esc(me?.name || '')}</b><span class="wk-role ${W.me.role}">${esc(W.me.label)}</span>`;
  addEventListener('hashchange', route);
  route();
})();
