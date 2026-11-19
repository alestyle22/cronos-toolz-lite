/* bienestar/app.js — Pantalla Bienestar (index.php). Habla con api.php; nada de código en línea (CSP): todo con addEventListener
   y delegación por data-act. Vistas por #hash: inicio, animo, habitos, retos[/id], encuestas[/id], reconocimientos, carga,
   ausencias, gestion[/sub]. Gráficos en SVG propio (sin librerías). Las animaciones solo con html.g7-move (g7-juice.js). */
'use strict';
const BOOT = window.BN_BOOT || {};
const S = { meta: null, home: null, view: '', arg: '', people: null, feed: { filter: 'all', cat: '', items: [], more: false, last: 0 }, poll: 0, mg: null, mgTab: 'resumen', moodDays: 30, calMonth: '', insights: { days: 30, team: 0 } };
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const view = () => $('#bn-view');
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DOW = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
const moving = () => document.documentElement.classList.contains('g7-move');

// ── API ──────────────────────────────────────────────────
async function api(action, data = {}, quiet = false) {
  let r, d;
  try {
    r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-BN': '1' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar. Revisa tu conexión y prueba de nuevo.' }; }
  if (d.error) { if (!quiet) toast(d.error, true); const e = new Error(d.error); e.api = true; throw e; }
  return d;
}
async function upload(file) {
  const fd = new FormData(); fd.append('action', 'ch_upload'); fd.append('image', file);
  const r = await fetch('api.php', { method: 'POST', headers: { 'X-BN': '1' }, credentials: 'same-origin', body: fd });
  const d = await r.json().catch(() => ({ error: 'No se pudo subir la imagen.' }));
  if (d.error) { toast(d.error, true); throw new Error(d.error); }
  return d;
}

// ── Utilidades de interfaz ───────────────────────────────
let toastT = 0;
function toast(msg, bad = false) {
  const t = $('#bn-toast'); t.textContent = msg; t.className = 'bn-toast show' + (bad ? ' bad' : '');
  clearTimeout(toastT); toastT = setTimeout(() => { t.className = 'bn-toast'; }, 3600);
}
function celebrate(el) {
  if (!el || !moving()) return;
  el.classList.remove('bn-pop'); void el.offsetWidth; el.classList.add('bn-pop');
  const box = el.getBoundingClientRect();
  for (let i = 0; i < 14; i++) {
    const p = document.createElement('i'); p.className = 'bn-spark';
    p.style.left = (box.left + box.width / 2) + 'px'; p.style.top = (box.top + box.height / 2) + 'px';
    p.style.setProperty('--dx', (Math.random() * 160 - 80) + 'px'); p.style.setProperty('--dy', (Math.random() * -120 - 20) + 'px');
    p.style.background = ['#22c55e', '#f59e0b', '#ec4899', '#3b82f6', '#a855f7'][i % 5];
    document.body.appendChild(p); setTimeout(() => p.remove(), 900);
  }
}
function badgesToast(list) { if (list && list.length) toast('¡Nueva insignia! ' + list.map(b => b.emoji + ' ' + b.label).join(' · ')); }
const fmtD = d => { if (!d) return ''; const [y, m, dd] = d.slice(0, 10).split('-'); return `${+dd}/${+m}`; };
const fmtLong = d => { const x = new Date(d.slice(0, 10) + 'T12:00:00'); return `${x.getDate()} de ${MONTHS[x.getMonth()]}`; };
function ago(dt) {
  const t = (window.G7Time && G7Time.parse(dt)) || new Date(String(dt).replace(' ', 'T'));
  const s = Math.floor((Date.now() - t) / 1000);
  if (s < 60) return 'recién'; if (s < 3600) return `hace ${Math.floor(s / 60)} min`; if (s < 86400) return `hace ${Math.floor(s / 3600)} h`;
  const d = Math.floor(s / 86400); return d === 1 ? 'ayer' : d < 30 ? `hace ${d} días` : fmtLong(String(dt));
}
function avatar(p, size = 34) {
  const name = p && p.name || '?';
  if (p && p.avatar && /^\/toolz\/avatars\/[A-Za-z0-9_.-]+$/.test(p.avatar)) return `<img class="bn-av" src="${esc(p.avatar)}" alt="" width="${size}" height="${size}" style="width:${size}px;height:${size}px" loading="lazy">`;
  const hue = [...name].reduce((a, c) => a + c.charCodeAt(0), 0) % 360;
  return `<span class="bn-av bn-av-l" style="width:${size}px;height:${size}px;font-size:${Math.round(size * .4)}px;background:hsl(${hue} 55% 42%)" aria-hidden="true">${esc(name.trim()[0] || '?').toUpperCase()}</span>`;
}
const moodLvl = v => v == null ? null : Math.max(1, Math.min(5, Math.round(v)));
function moodFace(v, size = 28) {
  const L = S.meta && S.meta.mood_levels; const k = moodLvl(v);
  if (!k || !L) return `<span class="bn-face empty" style="font-size:${size}px"><i class="fa-regular fa-face-meh-blank"></i></span>`;
  return `<span class="bn-face" style="font-size:${size}px;color:${L[k].color}" title="${esc(L[k].label)}"><i class="fa-solid ${L[k].icon}"></i></span>`;
}
const num = v => v == null ? '—' : String(v).replace('.', ',');
const unit = (n, u) => n === 1 ? u.replace(/s$/, '') : u;   // "1 día", "2 días"
function empty(icon, title, text, btn = '') { return `<div class="bn-empty"><div class="bn-empty-ico"><i class="fa-solid ${icon}"></i></div><h3>${esc(title)}</h3><p>${esc(text)}</p>${btn}</div>`; }
function card(title, body, opts = {}) {
  return `<article class="bn-card ${opts.cls || ''}" ${opts.style ? `style="${opts.style}"` : ''}>${title ? `<header class="bn-card-h"><h2>${opts.icon ? `<i class="fa-solid ${opts.icon}"></i>` : ''}${esc(title)}</h2>${opts.link ? `<a class="bn-more" href="${opts.link}">${esc(opts.linkText || 'Ver todo')} <i class="fa-solid fa-arrow-right"></i></a>` : ''}${opts.right || ''}</header>` : ''}${body}</article>`;
}

// ── Gráficos SVG ─────────────────────────────────────────
/** Línea con área. pts: [{v, label}] con v null = sin dato (corta la línea). */
function lineChart(pts, { min = 1, max = 5, h = 140, color = 'var(--g7-accent)', ticks = [1, 3, 5], fmt = v => num(v) } = {}) {
  const W = 600, H = h, pl = 26, pr = 8, pt = 10, pb = 22, n = pts.length;
  const x = i => pl + (n <= 1 ? 0 : i * (W - pl - pr) / (n - 1)), y = v => pt + (1 - (v - min) / (max - min)) * (H - pt - pb);
  let seg = [], segs = [];
  pts.forEach((p, i) => { if (p.v == null) { if (seg.length) segs.push(seg); seg = []; } else seg.push([x(i), y(p.v), p]); });
  if (seg.length) segs.push(seg);
  const id = 'g' + Math.random().toString(36).slice(2, 8);
  const lines = segs.map(s => s.length === 1 ? `<circle cx="${s[0][0]}" cy="${s[0][1]}" r="3.5" fill="${color}"/>` :
    `<path d="M${s.map(q => q[0].toFixed(1) + ',' + q[1].toFixed(1)).join('L')}L${s[s.length - 1][0].toFixed(1)},${H - pb}L${s[0][0].toFixed(1)},${H - pb}Z" fill="url(#${id})"/>` +
    `<path class="bn-line" d="M${s.map(q => q[0].toFixed(1) + ',' + q[1].toFixed(1)).join('L')}" fill="none" stroke="${color}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>`).join('');
  const dots = segs.flat().map(q => `<circle cx="${q[0]}" cy="${q[1]}" r="3" fill="var(--g7-bg-3)" stroke="${color}" stroke-width="2"><title>${esc(q[2].label || '')}: ${esc(fmt(q[2].v))}</title></circle>`).join('');
  const grid = ticks.map(t => `<line x1="${pl}" x2="${W - pr}" y1="${y(t)}" y2="${y(t)}" class="bn-grid"/><text x="${pl - 6}" y="${y(t) + 4}" text-anchor="end" class="bn-ax">${t}</text>`).join('');
  const step = Math.max(1, Math.ceil(n / 7));
  const labels = pts.map((p, i) => i % step === 0 || i === n - 1 ? `<text x="${x(i)}" y="${H - 5}" text-anchor="middle" class="bn-ax">${esc(p.short || '')}</text>` : '').join('');
  return `<svg class="bn-chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Gráfico de evolución"><defs><linearGradient id="${id}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${color}" stop-opacity=".28"/><stop offset="1" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>${grid}${lines}${dots}${labels}</svg>`;
}
/** Anillo de progreso (0-100). */
function ring(pct, { size = 64, stroke = 7, color = 'var(--g7-accent)', label = '' } = {}) {
  const r = (size - stroke) / 2, c = 2 * Math.PI * r, p = Math.max(0, Math.min(100, pct || 0));
  return `<svg class="bn-ring" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" role="img" aria-label="${p}%"><circle cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="var(--g7-bg-5)" stroke-width="${stroke}"/><circle class="bn-ring-v" cx="${size / 2}" cy="${size / 2}" r="${r}" fill="none" stroke="${color}" stroke-width="${stroke}" stroke-linecap="round" stroke-dasharray="${c}" stroke-dashoffset="${c * (1 - p / 100)}" transform="rotate(-90 ${size / 2} ${size / 2})" style="--c:${c}"/><text x="50%" y="50%" dy=".35em" text-anchor="middle" class="bn-ring-t">${esc(label || p + '%')}</text></svg>`;
}
/** Barra apilada de distribución (porcentajes por nivel 1-5). */
function distBar(dist) {
  const L = S.meta.mood_levels;
  return `<div class="bn-dist" role="img" aria-label="Distribución">${[1, 2, 3, 4, 5].map(k => dist[k] ? `<span style="width:${dist[k]}%;background:${L[k].color}" title="${esc(L[k].label)}: ${dist[k]}%"></span>` : '').join('')}</div>
    <div class="bn-dist-l">${[5, 4, 3, 2, 1].map(k => `<span><i style="background:${L[k].color}"></i>${esc(L[k].label)} <b>${dist[k] || 0}%</b></span>`).join('')}</div>`;
}
/** Barras verticales simples. items: [{v, label, color}] */
function bars(items, { max = 5, h = 110, fmt = v => num(v) } = {}) {
  return `<div class="bn-bars" style="height:${h}px">${items.map(it => `<div class="bn-bar" title="${esc(it.label)}: ${it.v == null ? 'sin datos' : esc(fmt(it.v))}"><span style="height:${it.v == null ? 0 : Math.max(4, it.v / max * 100)}%;background:${it.color || 'var(--g7-accent)'}" class="${it.v == null ? 'none' : ''}"></span><small>${esc(it.short || '')}</small></div>`).join('')}</div>`;
}
/** Calendario de actividad: 12 semanas (columnas) x 7 días. done: Set de fechas. */
function heatmap(done, color, mask = 127, today = S.meta.today) {
  const t = new Date(today + 'T12:00:00'); const dow = (t.getDay() + 6) % 7;
  const start = new Date(t); start.setDate(t.getDate() - dow - 7 * 11);
  let cells = '';
  for (let w = 0; w < 12; w++) for (let d = 0; d < 7; d++) {
    const x = new Date(start); x.setDate(start.getDate() + w * 7 + d);
    const k = x.toISOString().slice(0, 10), fut = k > today, on = done.has(k), sched = !!(mask & (1 << d));
    cells += `<i class="${fut ? 'f' : on ? 'on' : sched ? '' : 'off'}" style="grid-column:${w + 1};grid-row:${d + 1};${on ? `background:${color}` : ''}" title="${fmtLong(k)}${on ? ' · hecho' : ''}"></i>`;
  }
  return `<div class="bn-heat" role="img" aria-label="Últimas 12 semanas">${cells}</div>`;
}

// ── Modal ────────────────────────────────────────────────
let modalDone = null, lastFocus = null;
function modal(title, body, { submit = 'Guardar', wide = false, onSubmit = null, foot = null, noForm = false } = {}) {
  lastFocus = document.activeElement;
  const m = $('#bn-modal'), d = $('#bn-dialog');
  d.className = 'bn-dialog' + (wide ? ' wide' : '');
  const tag = noForm ? 'div' : 'form';   // noForm: el cuerpo ya trae su propio formulario (no se pueden anidar)
  d.innerHTML = `<${tag} class="bn-form" ${noForm ? '' : 'novalidate'}><header class="bn-dialog-h"><h2 id="bn-dialog-h">${esc(title)}</h2><button type="button" class="bn-x" data-act="modal-close" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button></header>
    <div class="bn-dialog-b">${body}</div>${foot !== null ? foot : `<footer class="bn-dialog-f"><button type="button" class="bn-btn bn-btn-ghost" data-act="modal-close">Cancelar</button>${onSubmit ? `<button type="submit" class="bn-btn">${esc(submit)}</button>` : ''}</footer>`}</${tag}>`;
  m.hidden = false; requestAnimationFrame(() => m.classList.add('open'));
  modalDone = onSubmit;
  const f = d.querySelector('input:not([type=hidden]),select,textarea,button.bn-chip'); if (f) setTimeout(() => f.focus(), 40);
  return d.querySelector('form');
}
function closeModal() { const m = $('#bn-modal'); m.classList.remove('open'); m.hidden = true; modalDone = null; if (lastFocus && lastFocus.focus) lastFocus.focus(); }
document.addEventListener('submit', async ev => {
  if (!ev.target.closest('#bn-dialog')) return;
  ev.preventDefault(); if (!modalDone) return;
  const btn = ev.target.querySelector('[type=submit]'); if (btn) btn.disabled = true;
  try { const keep = await modalDone(ev.target); if (keep !== true) closeModal(); } catch (e) { if (!e.api) { console.error(e); toast('Algo salió mal.', true); } }
  if (btn) btn.disabled = false;
});
document.addEventListener('keydown', ev => { if (ev.key === 'Escape' && !$('#bn-modal').hidden) closeModal(); });
$('#bn-modal') && $('#bn-modal').addEventListener('mousedown', ev => { if (ev.target.id === 'bn-modal') closeModal(); });

// ── Router ───────────────────────────────────────────────
const VIEWS = {};
async function route() {
  const [v, ...rest] = (location.hash.slice(1) || 'inicio').split('/');
  const name = VIEWS[v] ? v : 'inicio';
  S.view = name; S.arg = rest.join('/');
  document.querySelectorAll('.bn-tab').forEach(t => { const on = t.dataset.tab === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); if (on) t.scrollIntoView({ block: 'nearest', inline: 'center' }); });
  clearInterval(S.poll);
  view().setAttribute('aria-busy', 'true');
  try { await VIEWS[name](S.arg); } catch (e) { if (!e.api) console.error(e); if (!view().innerHTML.trim() || view().querySelector('.bn-loading')) view().innerHTML = empty('fa-plug-circle-exclamation', 'No se pudo cargar', 'Prueba de nuevo en un momento.', '<button class="bn-btn" data-act="reload">Reintentar</button>'); }
  view().setAttribute('aria-busy', 'false');
  if (moving()) { view().classList.remove('bn-enter'); void view().offsetWidth; view().classList.add('bn-enter'); }
}
const loading = () => { view().innerHTML = '<div class="bn-loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</div>'; };
async function ensureMeta() { if (!S.meta) { const h = await api('home'); S.meta = h.meta; S.home = h; } }

// ═════════════════════════════ INICIO ═════════════════════════════
VIEWS.inicio = async () => {
  if (!S.home || S.view === 'inicio') { const h = await api('home'); S.meta = h.meta; S.home = h; }
  const h = S.home, M = S.meta;
  const dot = $('#bn-dot-gestion'); if (dot) dot.hidden = !(h.manage && h.manage.approvals);
  // Ánimo de hoy
  const moodBody = h.mood.can
    ? `<p class="bn-q">¿Cómo estás hoy?</p><div class="bn-faces">${[1, 2, 3, 4, 5].map(k => `<button type="button" class="bn-face-btn" data-act="mood-quick" data-v="${k}" style="--c:${M.mood_levels[k].color}" aria-label="${esc(M.mood_levels[k].label)}"><i class="fa-solid ${M.mood_levels[k].icon}"></i><small>${esc(M.mood_levels[k].label)}</small></button>`).join('')}</div><p class="bn-fine">Solo tú ves tu respuesta. Al equipo le llega un promedio anónimo.</p>`
    : `<div class="bn-today">${moodFace(h.mood.current && h.mood.current.mood, 46)}<div><b>Hoy: ${esc(h.mood.current ? M.mood_levels[h.mood.current.mood].label : '—')}</b><p>${h.mood.streak > 1 ? `🔥 ${h.mood.streak} días seguidos contándolo` : 'Puedes volver a responder en ' + h.mood.wait_h + ' h'}</p></div></div>
       <div class="bn-week">${h.mood.series.map(d => `<span title="${fmtLong(d.day)}">${moodFace(d.mood, 18)}<small>${DOW[(new Date(d.day + 'T12:00:00').getDay() + 6) % 7]}</small></span>`).join('')}</div>`;
  // Ánimo de la organización
  const o = h.org_mood;
  const orgBody = !o ? '' : o.ok
    ? `<div class="bn-org"><div class="bn-org-v">${moodFace(o.avg, 40)}<div><b class="bn-big">${num(o.avg)}</b><small>de 5 · últimos 30 días</small></div>${o.trend ? `<span class="bn-trend ${o.trend}"><i class="fa-solid ${o.trend === 'up' ? 'fa-arrow-trend-up' : o.trend === 'down' ? 'fa-arrow-trend-down' : 'fa-minus'}"></i>${o.delta > 0 ? '+' : ''}${num(o.delta)}</span>` : ''}</div>${distBar(o.dist)}</div><p class="bn-fine"><i class="fa-solid fa-user-shield"></i> Anónimo: solo cuenta lo que respondieron ${o.min} personas o más.</p>`
    : empty('fa-users', 'Todavía pocas respuestas', `El ánimo del equipo aparece cuando responden al menos ${o.min} personas. Así nadie puede deducir lo de otro.`);
  // Hábitos
  const hb = h.habits;
  const habBody = hb.total ? `<div class="bn-hab-sum">${ring(hb.due_today ? Math.round(hb.done_today / hb.due_today * 100) : 100, { size: 74, label: `${hb.done_today}/${hb.due_today}`, color: '#22c55e' })}<div><b>${hb.done_today === hb.due_today ? '¡Día completo!' : 'Hábitos de hoy'}</b><p>${hb.due_today - hb.done_today > 0 ? `Te ${hb.due_today - hb.done_today === 1 ? 'queda 1' : 'quedan ' + (hb.due_today - hb.done_today)}` : 'Hiciste todo lo de hoy'}</p></div></div>
    <ul class="bn-mini">${hb.top.map(x => `<li><span class="bn-hico" style="--c:${x.color}"><i class="fa-solid ${esc(x.icon)}"></i></span><span class="t">${esc(x.title)}</span>${x.streak ? `<span class="bn-streak">🔥 ${x.streak}</span>` : ''}${x.due_today ? `<button type="button" class="bn-check ${x.done_today ? 'on' : ''}" data-act="habit-check" data-id="${x.id}" data-done="${x.done_today ? 0 : 1}" aria-label="${x.done_today ? 'Desmarcar' : 'Marcar como hecho'}"><i class="fa-solid fa-check"></i></button>` : ''}</li>`).join('')}</ul>`
    : empty('fa-seedling', 'Suma tu primer hábito', 'Pequeñas rutinas que te hacen bien: tomar agua, pausas, caminar.', '<a class="bn-btn bn-btn-sm" href="#habitos">Elegir un hábito</a>');
  // Retos
  const chBody = h.challenges.length ? h.challenges.map(chMini).join('') : empty('fa-person-running', 'No hay retos activos', 'Cuando haya uno nuevo te avisamos.');
  // Reconocimientos
  const kBody = h.kudos.length ? `<ul class="bn-klist">${h.kudos.map(k => `<li>${avatar(k.from, 32)}<div><p><b>${esc(k.from.name)}</b> te reconoció · <span class="bn-cat">${esc(k.cat.emoji)} ${esc(k.cat.label)}</span>${k.private ? ' <span class="bn-tag"><i class="fa-solid fa-lock"></i> privado</span>' : ''}</p><q>${esc(k.body)}</q><small>${ago(k.at)}</small></div></li>`).join('')}</ul>`
    : empty('fa-award', 'Todavía sin reconocimientos', 'Empieza tú: agradecer también hace bien.', '<button type="button" class="bn-btn bn-btn-sm" data-act="kudos-new">Reconocer a alguien</button>');
  // Encuestas
  const svBody = h.surveys.length ? `<ul class="bn-mini">${h.surveys.map(s => `<li><span class="bn-hico" style="--c:#a855f7"><i class="fa-solid fa-square-poll-vertical"></i></span><span class="t">${esc(s.title)}<small>${s.n_questions} preguntas · vence el ${fmtD(s.end_date)}${s.anonymous ? ' · anónima' : ''}</small></span><a class="bn-btn bn-btn-sm" href="#encuestas/${s.id}">Responder</a></li>`).join('')}</ul>`
    : empty('fa-circle-check', 'Nada pendiente', 'No tienes encuestas por responder.');
  // Carga
  const L = M.load_levels, ld = h.load;
  const loadBody = `<p class="bn-q">¿Cómo viene tu carga esta semana?</p><div class="bn-levels">${[1, 2, 3, 4, 5].map(k => `<button type="button" class="bn-level ${ld.current === k ? 'on' : ''}" data-act="load-set" data-v="${k}" style="--c:${L[k].color}">${k}<small>${esc(L[k].label)}</small></button>`).join('')}</div>
    <div class="bn-facts"><span><b>${ld.tasks.open}</b> tareas abiertas</span><span class="${ld.tasks.overdue ? 'warn' : ''}"><b>${ld.tasks.overdue}</b> vencidas</span><span><b>${ld.tasks.due7}</b> vencen en 7 días</span></div>${ld.hint ? `<p class="bn-hint"><i class="fa-solid fa-lightbulb"></i> ${esc(ld.hint)}</p>` : ''}`;
  // Vacaciones
  const lv = h.leave, b = lv.balance;
  const leaveBody = `<div class="bn-hab-sum">${ring(b.total ? Math.round(b.available / b.total * 100) : 0, { size: 74, label: String(b.available), color: '#06b6d4' })}<div><b>${b.available === 1 ? '1 día disponible' : b.available + ' días disponibles'}</b><p>De ${b.total} en ${b.year}${b.pending ? ` · ${b.pending} en espera` : ''}</p></div></div>
    ${lv.next ? `<p class="bn-next"><i class="fa-solid ${esc(lv.next.icon)}" style="color:${lv.next.color}"></i> Próxima: <b>${esc(lv.next.type_label)}</b> del ${fmtD(lv.next.date_from)} al ${fmtD(lv.next.date_to)}</p>` : ''}
    <div class="bn-row"><button type="button" class="bn-btn bn-btn-sm" data-act="leave-new">Pedir días</button>${lv.pending ? `<a class="bn-more" href="#ausencias">${lv.pending} pedido${lv.pending > 1 ? 's' : ''} en espera</a>` : ''}</div>`;
  const badges = h.badges.length ? `<div class="bn-badges">${h.badges.map(x => `<span class="bn-badge" title="${esc(x.desc)}"><em>${esc(x.emoji)}</em>${esc(x.label)}${x.n > 1 ? ` <b>×${x.n}</b>` : ''}</span>`).join('')}</div>` : '';
  const progs = h.programs.length ? `<div class="bn-progs">${h.programs.map(p => `<div class="bn-prog" style="--c:${p.color}"><i class="fa-solid ${esc(p.icon)}"></i><div><b>${esc(p.title)}</b><p>${esc(p.description)}</p><small>${p.n_challenges ? p.n_challenges + ' reto' + (p.n_challenges > 1 ? 's' : '') : ''}${p.n_challenges && p.n_surveys ? ' · ' : ''}${p.n_surveys ? p.n_surveys + ' encuesta' + (p.n_surveys > 1 ? 's' : '') : ''}${p.end_date ? ' · hasta el ' + fmtD(p.end_date) : ''}</small></div></div>`).join('')}</div>` : '';
  const mg = h.manage && h.manage.approvals ? `<a class="bn-alert" href="#ausencias"><i class="fa-solid fa-inbox"></i> Tienes ${h.manage.approvals} pedido${h.manage.approvals > 1 ? 's' : ''} de vacaciones o licencia para revisar <i class="fa-solid fa-arrow-right"></i></a>` : '';
  view().innerHTML = `${mg}${progs}<div class="bn-bento">
    ${card('Tu ánimo', moodBody, { icon: 'fa-face-smile', link: '#animo', linkText: 'Tu historial', cls: 'c-mood' })}
    ${o ? card('Ánimo del equipo', orgBody, { icon: 'fa-users', link: '#animo', linkText: 'Tendencias', cls: 'c-org' }) : ''}
    ${card('Hábitos', habBody, { icon: 'fa-seedling', link: '#habitos', cls: 'c-hab' })}
    ${card('Retos activos', chBody, { icon: 'fa-person-running', link: '#retos', cls: 'c-ch' })}
    ${card('Reconocimientos recibidos', kBody, { icon: 'fa-award', link: '#reconocimientos', linkText: 'Muro', cls: 'c-k' })}
    ${card('Encuestas pendientes', svBody, { icon: 'fa-square-poll-vertical', link: '#encuestas', cls: 'c-sv' })}
    ${card('Carga laboral', loadBody, { icon: 'fa-scale-balanced', link: '#carga', linkText: 'Historial', cls: 'c-load' })}
    ${card('Vacaciones y licencias', leaveBody, { icon: 'fa-umbrella-beach', link: '#ausencias', cls: 'c-leave' })}
    ${badges ? card('Tus insignias', badges, { icon: 'fa-medal', cls: 'c-badges' }) : ''}
  </div>`;
};
function chMini(c) {
  const pct = c.kind === 'colectivo' ? c.pct_total : c.pct_mine;
  return `<a class="bn-chmini" href="#retos/${c.id}" style="--c:${c.color}"><span class="bn-hico"><i class="fa-solid ${esc(c.icon)}"></i></span><div><b>${esc(c.title)}</b><div class="bn-prog-bar"><span style="width:${pct || 0}%"></span></div><small>${c.kind === 'colectivo' ? `Entre todos: ${c.total} de ${c.goal_total} ${esc(c.unit)}` : c.joined ? `Tu avance: ${c.my_progress} de ${c.goal_person} ${esc(c.unit)}` : 'Individual · súmate'} · ${c.days_left} día${c.days_left === 1 ? '' : 's'}</small></div></a>`;
}

// ═════════════════════════════ ÁNIMO ═════════════════════════════
VIEWS.animo = async () => {
  await ensureMeta();
  const d = await api('mood', { days: S.moodDays }), M = S.meta, m = d.mine;
  const pts = m.series.map(x => ({ v: x.mood, label: fmtLong(x.day), short: fmtD(x.day) }));
  const L = M.mood_levels;
  const mineCard = card('Tu evolución', `<div class="bn-seg" role="tablist">${[7, 30, 90].map(n => `<button type="button" data-act="mood-days" data-v="${n}" class="${S.moodDays === n ? 'on' : ''}">${n} días</button>`).join('')}</div>
    <div class="bn-kpis"><div><small>Últimos 7 días</small><b>${moodFace(m.avg7, 20)} ${num(m.avg7)}</b></div><div><small>Últimos 30 días</small><b>${moodFace(m.avg30, 20)} ${num(m.avg30)}</b></div><div><small>30 días anteriores</small><b>${num(m.prev30)}</b>${m.avg30 != null && m.prev30 != null ? `<span class="bn-trend ${m.avg30 - m.prev30 >= .2 ? 'up' : m.avg30 - m.prev30 <= -.2 ? 'down' : 'flat'}">${m.avg30 - m.prev30 > 0 ? '+' : ''}${num(Math.round((m.avg30 - m.prev30) * 10) / 10)}</span>` : ''}</div><div><small>Racha</small><b>🔥 ${m.streak} día${m.streak === 1 ? '' : 's'}</b></div></div>
    ${m.answered30 ? lineChart(pts, { color: 'var(--g7-accent)' }) : empty('fa-chart-line', 'Todavía no hay registros', 'Cuenta cómo estás y acá verás tu evolución.')}`, { icon: 'fa-chart-line', cls: 'span2' });
  const total = Object.values(m.dist).reduce((a, b) => a + b, 0);
  const distCard = card('Cómo te sentiste (30 días)', total ? `<div class="bn-donut-w">${donut([5, 4, 3, 2, 1].map(k => ({ v: m.dist[k], color: L[k].color, label: L[k].label })), total + ' ' + unit(total, 'días'))}<ul class="bn-legend">${[5, 4, 3, 2, 1].map(k => `<li><i style="background:${L[k].color}"></i>${esc(L[k].label)}<b>${m.dist[k]}</b></li>`).join('')}</ul></div>` : empty('fa-chart-pie', 'Sin datos', 'Aparece cuando registres tu ánimo.'), { icon: 'fa-chart-pie' });
  const reg = m.can ? card('¿Cómo estás hoy?', moodForm(), { icon: 'fa-heart', cls: 'c-reg' })
    : card('Hoy', `<div class="bn-today">${moodFace(m.current && m.current.mood, 52)}<div><b>${esc(m.current ? L[m.current.mood].label : '')}</b><p>${m.current && m.current.reasons.length ? m.current.reasons.map(r => esc(M.mood_reasons[r] ? M.mood_reasons[r].label : r)).join(' · ') : 'Sin motivos marcados'}</p><small>Puedes volver a responder en ${m.wait_h} h</small></div></div>`, { icon: 'fa-heart' });
  let org = '';
  if (d.org) {
    const o = d.org;
    org = o.ok ? card('Ánimo de la organización', `<div class="bn-kpis"><div><small>Promedio</small><b>${moodFace(o.avg, 20)} ${num(o.avg)}</b></div><div><small>Período anterior</small><b>${num(o.prev_avg)}</b></div><div><small>Tendencia</small><b>${o.trend ? `<span class="bn-trend ${o.trend}">${o.trend === 'up' ? 'Mejora' : o.trend === 'down' ? 'Baja' : 'Estable'} ${o.delta != null ? (o.delta > 0 ? '+' : '') + num(o.delta) : ''}</span>` : '—'}</b></div></div>
      ${lineChart(o.days.map(x => ({ v: x.avg, label: fmtLong(x.day), short: fmtD(x.day) })), { color: '#22c55e' })}
      <h3 class="bn-h3">Distribución</h3>${distBar(o.dist)}
      <h3 class="bn-h3">Por semana</h3>${bars(o.weeks.map(w => ({ v: w.avg, label: 'Semana ' + w.week.slice(6), short: 'S' + w.week.slice(6), color: w.avg ? L[moodLvl(w.avg)].color : '' })))}
      <p class="bn-fine"><i class="fa-solid fa-user-shield"></i> Todo es anónimo y agregado. Un día o una semana se muestra solo si respondieron ${o.min} personas o más; ninguna persona, ni siquiera quien administra, ve respuestas individuales.</p>`, { icon: 'fa-users', cls: 'span2' })
      : card('Ánimo de la organización', empty('fa-user-shield', 'Todavía pocas respuestas', `Se muestra cuando responden al menos ${o.min} personas distintas.`), { icon: 'fa-users', cls: 'span2' });
  }
  const teams = d.teams && d.teams.length ? card('Por equipo', `<ul class="bn-tlist">${d.teams.map(t => `<li><i class="bn-tdot" style="background:${t.color}"></i><span class="t">${esc(t.name)}</span>${t.ok ? `${moodFace(t.avg, 18)}<b>${num(t.avg)}</b>${t.trend ? `<span class="bn-trend ${t.trend}">${t.delta > 0 ? '+' : ''}${num(t.delta)}</span>` : ''}` : '<small class="bn-muted">pocas respuestas</small>'}</li>`).join('')}</ul>`, { icon: 'fa-people-group' }) : '';
  view().innerHTML = `<div class="bn-grid">${reg}${distCard}${mineCard}${org}${teams}</div>`;
};
function donut(items, center) {
  const total = items.reduce((a, b) => a + b.v, 0) || 1, r = 42, c = 2 * Math.PI * r; let off = 0;
  return `<svg class="bn-donut" viewBox="0 0 110 110" role="img" aria-label="Distribución">${items.filter(i => i.v).map(i => { const len = i.v / total * c; const s = `<circle cx="55" cy="55" r="${r}" fill="none" stroke="${i.color}" stroke-width="16" stroke-dasharray="${len} ${c - len}" stroke-dashoffset="${-off}" transform="rotate(-90 55 55)"><title>${esc(i.label)}: ${i.v}</title></circle>`; off += len; return s; }).join('')}<text x="55" y="59" text-anchor="middle" class="bn-ring-t">${esc(center)}</text></svg>`;
}
function moodForm(preset = 0, id = 'bn-mood-form') {
  const L = S.meta.mood_levels, R = S.meta.mood_reasons;
  return `<form id="${id}"><div class="bn-faces">${[1, 2, 3, 4, 5].map(k => `<button type="button" class="bn-face-btn ${preset === k ? 'on' : ''}" data-act="mood-pick" data-v="${k}" style="--c:${L[k].color}" aria-pressed="${preset === k}"><i class="fa-solid ${L[k].icon}"></i><small>${esc(L[k].label)}</small></button>`).join('')}</div>
    <p class="bn-q2">¿Algo que influya? <small>(hasta 3, opcional)</small></p><div class="bn-chips">${Object.entries(R).map(([k, r]) => `<button type="button" class="bn-chip" data-act="mood-reason" data-v="${k}" aria-pressed="false"><i class="fa-solid ${r.icon}"></i> ${esc(r.label)}</button>`).join('')}</div>
    <input type="hidden" name="mood" value="${preset || ''}"><button type="submit" class="bn-btn bn-btn-block" ${preset ? '' : 'disabled'}>Guardar</button><p class="bn-fine">Una respuesta cada 24 horas. Solo tú la ves.</p></form>`;
}

// ═════════════════════════════ HÁBITOS ═════════════════════════════
VIEWS.habitos = async () => {
  await ensureMeta();
  const d = await api('habits'); S.habits = d;
  const list = d.habits.length ? `<div class="bn-habits">${d.habits.map(habitCard).join('')}</div>` : empty('fa-seedling', 'Todavía no tienes hábitos', 'Elige uno sugerido o crea el tuyo. Son privados: solo tú los ves.');
  const sug = d.suggested.filter(s => !d.habits.some(h => h.title === s.title));
  view().innerHTML = `<div class="bn-bar-top"><div><h2 class="bn-h2">Tus hábitos</h2><p class="bn-muted">Privados por defecto. Marca lo que hiciste hoy; puedes corregir hasta una semana atrás.</p></div><button type="button" class="bn-btn" data-act="habit-new"><i class="fa-solid fa-plus"></i> Nuevo hábito</button></div>
    ${list}
    ${sug.length ? `<h3 class="bn-h3">Sugeridos para ti</h3><div class="bn-sug">${sug.map(s => `<button type="button" class="bn-sug-i" data-act="habit-tpl" data-id="${s.id}" style="--c:${s.color}"><span class="bn-hico"><i class="fa-solid ${esc(s.icon)}"></i></span><b>${esc(s.title)}</b><small>${esc(s.hint)}</small><em>${s.freq === 'weekly' ? s.target + ' veces por semana' : 'Todos los días'}</em><i class="fa-solid fa-plus bn-sug-add"></i></button>`).join('')}</div>` : ''}
    ${d.archived.length ? `<details class="bn-arch"><summary>Archivados (${d.archived.length})</summary><ul class="bn-mini">${d.archived.map(h => `<li><span class="bn-hico" style="--c:${h.color}"><i class="fa-solid ${esc(h.icon)}"></i></span><span class="t">${esc(h.title)}<small>Mejor racha: ${h.best} ${unit(h.best, h.streak_unit)}</small></span><button type="button" class="bn-btn bn-btn-sm bn-btn-ghost" data-act="habit-unarchive" data-id="${h.id}">Reactivar</button><button type="button" class="bn-ico" data-act="habit-delete" data-id="${h.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></li>`).join('')}</ul></details>` : ''}`;
};
function habitCard(h) {
  const today = S.meta.today, set = new Set(h.log);
  const days = [...Array(7)].map((_, i) => { const x = new Date(today + 'T12:00:00'); x.setDate(x.getDate() - 6 + i); return x.toISOString().slice(0, 10); });
  const sched = k => h.freq === 'weekly' || !!(h.days_mask & (1 << ((new Date(k + 'T12:00:00').getDay() + 6) % 7)));
  return `<article class="bn-habit" style="--c:${h.color}" data-id="${h.id}">
    <header><span class="bn-hico"><i class="fa-solid ${esc(h.icon)}"></i></span><div class="t"><b>${esc(h.title)}</b><small>${h.freq === 'weekly' ? `${h.week_count} de ${h.target} esta semana` : h.days_mask === 127 ? 'Todos los días' : DOW.filter((_, i) => h.days_mask & (1 << i)).join(' ')}${h.goal ? ' · ' + esc(h.goal) : ''}${h.remind ? ` · <i class="fa-regular fa-bell"></i> ${esc(h.remind_time)}` : ''}</small></div>
      <button type="button" class="bn-check big ${h.done_today ? 'on' : ''}" data-act="habit-check" data-id="${h.id}" data-done="${h.done_today ? 0 : 1}" ${h.due_today || h.done_today ? '' : 'disabled title="Hoy no toca"'} aria-label="${h.done_today ? 'Desmarcar hoy' : 'Marcar hoy como hecho'}"><i class="fa-solid fa-check"></i></button></header>
    <div class="bn-week7">${days.map(k => `<button type="button" class="${set.has(k) ? 'on' : ''} ${sched(k) ? '' : 'off'}" data-act="habit-day" data-id="${h.id}" data-day="${k}" data-done="${set.has(k) ? 0 : 1}" aria-label="${fmtLong(k)}${set.has(k) ? ', hecho' : ''}"><small>${DOW[(new Date(k + 'T12:00:00').getDay() + 6) % 7]}</small>${+k.slice(8)}</button>`).join('')}</div>
    <div class="bn-hstats"><span><b>🔥 ${h.streak}</b> ${unit(h.streak, h.streak_unit)} de racha</span><span><b>${h.best}</b> mejor racha</span><span><b>${h.rate30 == null ? '—' : h.rate30 + '%'}</b> ${h.freq === 'weekly' ? 'de las últimas 4 semanas' : 'cumplido en 30 días'}</span><span><b>${h.total}</b> en total</span></div>
    ${heatmap(set, h.color, h.freq === 'weekly' ? 127 : h.days_mask)}
    <footer><button type="button" class="bn-link" data-act="habit-edit" data-id="${h.id}"><i class="fa-regular fa-pen-to-square"></i> Editar</button><button type="button" class="bn-link" data-act="habit-archive" data-id="${h.id}"><i class="fa-solid fa-box-archive"></i> Archivar</button></footer></article>`;
}
function habitForm(h = {}) {
  const icons = (S.habits && S.habits.icons) || [], colors = (S.habits && S.habits.colors) || [];
  const freq = h.freq || 'daily', mask = h.days_mask ?? 127;
  return `<input type="hidden" name="id" value="${h.id || ''}"><input type="hidden" name="tpl_id" value="${h.tpl_id || ''}">
    <label class="bn-f"><span>Nombre</span><input name="title" maxlength="80" required value="${esc(h.title || '')}" placeholder="Ej.: Tomar agua"></label>
    <label class="bn-f"><span>Objetivo (opcional)</span><input name="goal" maxlength="120" value="${esc(h.goal || '')}" placeholder="Ej.: ocho vasos al día"></label>
    <div class="bn-f"><span>Frecuencia</span><div class="bn-seg"><button type="button" data-act="hf-freq" data-v="daily" class="${freq === 'daily' ? 'on' : ''}">Días de la semana</button><button type="button" data-act="hf-freq" data-v="weekly" class="${freq === 'weekly' ? 'on' : ''}">Veces por semana</button></div><input type="hidden" name="freq" value="${freq}"></div>
    <div class="bn-f hf-daily" ${freq === 'daily' ? '' : 'hidden'}><span>¿Qué días?</span><div class="bn-days">${DOW.map((l, i) => `<label><input type="checkbox" name="dm" value="${1 << i}" ${mask & (1 << i) ? 'checked' : ''}><span>${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][i]}</span></label>`).join('')}</div></div>
    <label class="bn-f hf-weekly" ${freq === 'weekly' ? '' : 'hidden'}><span>Veces por semana</span><input type="number" name="target" min="1" max="7" value="${h.target || 3}"></label>
    <div class="bn-f"><span>Ícono</span><div class="bn-icons">${icons.map(i => `<label><input type="radio" name="icon" value="${i}" ${(h.icon || 'fa-leaf') === i ? 'checked' : ''}><i class="fa-solid ${i}"></i></label>`).join('')}</div></div>
    <div class="bn-f"><span>Color</span><div class="bn-colors">${colors.map(c => `<label><input type="radio" name="color" value="${c}" ${(h.color || '#22c55e') === c ? 'checked' : ''}><i style="background:${c}"></i></label>`).join('')}</div></div>
    <div class="bn-f bn-inline"><label class="bn-switch"><input type="checkbox" name="remind" ${h.remind ? 'checked' : ''}><i></i> Recordarme si no lo marqué</label><input type="time" name="remind_time" value="${esc(h.remind_time || '18:00')}" aria-label="Hora del recordatorio"></div>`;
}
function habitPayload(f) {
  const freq = f.freq.value, mask = [...f.querySelectorAll('[name=dm]:checked')].reduce((a, i) => a | +i.value, 0);
  return { id: +f.id.value || 0, tpl_id: +f.tpl_id.value || 0, title: f.title.value, goal: f.goal.value, freq, days_mask: mask, target: +f.target.value,
    icon: (f.querySelector('[name=icon]:checked') || {}).value, color: (f.querySelector('[name=color]:checked') || {}).value, remind: f.remind.checked, remind_time: f.remind_time.value };
}
function openHabit(h) {
  modal(h && h.id ? 'Editar hábito' : 'Nuevo hábito', habitForm(h || {}), { onSubmit: async f => { await api('habit_save', habitPayload(f)); toast(h && h.id ? 'Hábito actualizado' : '¡Hábito creado! Arranca hoy 🌱'); S.home = null; route(); } });
}

// ═════════════════════════════ RETOS ═════════════════════════════
VIEWS.retos = async arg => {
  await ensureMeta();
  const d = await api('challenges'); S.chs = d.items;
  const sel = arg ? d.items.find(c => c.id === +arg) : null;
  if (arg && !sel) toast('Ese reto ya no está disponible.', true);
  const groups = [['active', 'Activos'], ['upcoming', 'Próximos'], ['ended', 'Terminados']];
  view().innerHTML = `<div class="bn-bar-top"><div><h2 class="bn-h2">Retos</h2><p class="bn-muted">Participar es voluntario. En los colectivos, lo que cada uno suma cuenta para todos.</p></div></div>
    ${d.items.length ? groups.map(([k, l]) => { const xs = d.items.filter(c => c.state === k); return xs.length ? `<h3 class="bn-h3">${l}</h3><div class="bn-chs">${xs.map(chCard).join('')}</div>` : ''; }).join('') : empty('fa-person-running', 'No hay retos por ahora', 'Quien gestiona Bienestar los publica; te avisamos cuando haya uno.')}`;
  if (sel) { const el = view().querySelector(`.bn-chc[data-id="${sel.id}"]`); if (el) { el.scrollIntoView({ block: 'center' }); el.classList.add('hl'); } }
};
function chCard(c) {
  const coll = c.kind === 'colectivo';
  return `<article class="bn-chc ${c.state}" data-id="${c.id}" style="--c:${c.color}">
    <div class="bn-chc-img">${c.image ? `<img src="${esc(c.image)}" alt="" loading="lazy">` : `<i class="fa-solid ${esc(c.icon)}"></i>`}<span class="bn-pill">${coll ? '<i class="fa-solid fa-users"></i> Colectivo' : '<i class="fa-solid fa-user"></i> Individual'}</span>${c.goal_hit ? '<span class="bn-pill ok">🎉 ¡Meta lograda!</span>' : ''}</div>
    <div class="bn-chc-b"><h3>${esc(c.title)}</h3>${c.description ? `<p>${esc(c.description)}</p>` : ''}
      <div class="bn-chc-meta"><span><i class="fa-regular fa-calendar"></i> ${fmtD(c.start_date)} al ${fmtD(c.end_date)}</span>${c.state === 'active' ? `<span><i class="fa-regular fa-clock"></i> ${c.days_left} día${c.days_left === 1 ? '' : 's'}</span>` : ''}<span><i class="fa-solid fa-users"></i> ${c.participants}</span>${c.reward ? `<span><i class="fa-solid fa-gift"></i> ${esc(c.reward)}</span>` : ''}</div>
      ${coll ? `<div class="bn-goal"><div class="bn-goal-h"><span>Entre todos</span><b>${c.total} / ${c.goal_total} ${esc(c.unit)}</b></div><div class="bn-prog-bar big"><span style="width:${c.pct_total || 0}%"></span></div></div>` : ''}
      ${c.joined && c.goal_person ? `<div class="bn-goal"><div class="bn-goal-h"><span>Tu avance</span><b>${c.my_progress} / ${c.goal_person} ${esc(c.unit)}${c.my_done ? ' ✓' : ''}</b></div><div class="bn-prog-bar mine"><span style="width:${c.pct_mine || 0}%"></span></div></div>` : c.joined ? `<p class="bn-muted">Llevas <b>${c.my_progress}</b> ${esc(c.unit)}.</p>` : ''}
      ${c.ranking && c.top.length ? `<ol class="bn-rank">${c.top.map(r => `<li class="${r.me ? 'me' : ''}"><span>${r.pos}</span>${avatar(r, 22)}<em>${esc(r.name)}</em><b>${r.progress}</b></li>`).join('')}</ol>` : ''}
      <div class="bn-row">${c.state === 'ended' ? '' : c.joined ? `${c.state === 'active' ? `<button type="button" class="bn-btn bn-btn-sm" data-act="ch-log" data-id="${c.id}"><i class="fa-solid fa-plus"></i> Sumar avance</button>` : '<span class="bn-muted">Empieza el ' + fmtD(c.start_date) + '</span>'}<button type="button" class="bn-link" data-act="ch-mylog" data-id="${c.id}">Mis avances</button><button type="button" class="bn-link danger" data-act="ch-leave" data-id="${c.id}">Salir</button>` : `<button type="button" class="bn-btn bn-btn-sm" data-act="ch-join" data-id="${c.id}">Sumarme</button>`}</div>
    </div></article>`;
}

// ═════════════════════════════ ENCUESTAS ═════════════════════════════
VIEWS.encuestas = async arg => {
  await ensureMeta();
  if (arg) return surveyAnswer(+arg);
  const d = await api('surveys');
  const pend = d.items.filter(s => !s.done && s.state === 'open'), done = d.items.filter(s => s.done);
  const row = s => `<li><span class="bn-hico" style="--c:${s.done ? '#22c55e' : '#a855f7'}"><i class="fa-solid ${s.done ? 'fa-check' : 'fa-square-poll-vertical'}"></i></span><span class="t">${esc(s.title)}<small>${esc(s.category_label)} · ${s.n_questions} preguntas · ${s.done ? 'respondida' : 'vence el ' + fmtD(s.end_date)}${s.anonymous ? ' · <i class="fa-solid fa-user-secret"></i> anónima' : ''}</small></span>${s.done ? '<span class="bn-tag ok">¡Gracias!</span>' : `<a class="bn-btn bn-btn-sm" href="#encuestas/${s.id}">Responder</a>`}</li>`;
  view().innerHTML = `<div class="bn-bar-top"><div><h2 class="bn-h2">Clima laboral</h2><p class="bn-muted">Tu opinión ayuda a mejorar el día a día. En las anónimas no se guarda quién respondió qué.</p></div></div>
    <div class="bn-grid">${card('Pendientes', pend.length ? `<ul class="bn-mini">${pend.map(row).join('')}</ul>` : empty('fa-circle-check', 'Estás al día', 'No hay encuestas por responder.'), { icon: 'fa-hourglass-half' })}
    ${card('Respondidas', done.length ? `<ul class="bn-mini">${done.map(row).join('')}</ul>` : empty('fa-inbox', 'Todavía ninguna', 'Acá quedan las que vayas respondiendo.'), { icon: 'fa-check-double' })}</div>`;
};
async function surveyAnswer(id) {
  const { survey: s } = await api('survey', { id });
  if (s.done || s.state !== 'open') { view().innerHTML = card(s.title, empty(s.done ? 'fa-heart' : 'fa-lock', s.done ? '¡Gracias por responder!' : 'Esta encuesta no está abierta', s.done ? 'Tu respuesta ya está guardada.' : (s.state === 'scheduled' ? 'Abre el ' + fmtD(s.start_date) + '.' : 'Ya cerró.'), '<a class="bn-btn" href="#encuestas">Volver</a>')); return; }
  view().innerHTML = `<form class="bn-survey" id="bn-survey" data-id="${s.id}"><header><a class="bn-link" href="#encuestas"><i class="fa-solid fa-arrow-left"></i> Encuestas</a><h2>${esc(s.title)}</h2>${s.intro ? `<p>${esc(s.intro)}</p>` : ''}<p class="bn-fine">${s.anonymous ? '<i class="fa-solid fa-user-secret"></i> Anónima: no se guarda quién respondió. Los resultados se ven solo agregados.' : '<i class="fa-solid fa-user"></i> Esta encuesta no es anónima.'} · Vence el ${fmtD(s.end_date)}</p><div class="bn-prog-bar"><span id="bn-sv-prog" style="width:0%"></span></div></header>
    ${s.questions.map((q, i) => `<fieldset class="bn-qq" data-q="${q.id}" data-type="${q.type}" data-req="${q.required ? 1 : 0}"><legend><span>${i + 1}</span>${esc(q.text)}${q.required ? '' : ' <small>(opcional)</small>'}</legend>${qInput(q)}</fieldset>`).join('')}
    <footer><button type="submit" class="bn-btn">Enviar respuestas</button></footer></form>`;
}
function qInput(q) {
  if (q.type === 'scale') return `<div class="bn-scale" role="radiogroup">${[...Array(q.scale_max)].map((_, i) => `<label><input type="radio" name="q${q.id}" value="${i + 1}"><span>${i + 1}</span></label>`).join('')}</div><div class="bn-scale-l"><small>Nada</small><small>Mucho</small></div>`;
  if (q.type === 'single') return `<div class="bn-opts">${q.options.map((o, i) => `<label><input type="radio" name="q${q.id}" value="${i}"><span>${esc(o)}</span></label>`).join('')}</div>`;
  if (q.type === 'multi') return `<div class="bn-opts">${q.options.map((o, i) => `<label><input type="checkbox" name="q${q.id}" value="${i}"><span>${esc(o)}</span></label>`).join('')}</div>`;
  return `<textarea name="q${q.id}" rows="3" maxlength="1000" placeholder="Escribe con confianza…"></textarea>`;
}

// ═════════════════════════════ RECONOCIMIENTOS ═════════════════════════════
VIEWS.reconocimientos = async () => {
  await ensureMeta();
  const C = S.meta.kudos_cats;
  view().innerHTML = `<div class="bn-bar-top"><div><h2 class="bn-h2">Muro de agradecimientos</h2><p class="bn-muted">Los públicos también aparecen en Comunidad. Los privados solo los ven quien lo da y quien lo recibe.</p></div><button type="button" class="bn-btn" data-act="kudos-new"><i class="fa-solid fa-award"></i> Reconocer</button></div>
    <div class="bn-filters"><div class="bn-seg">${[['all', 'Todos'], ['received', 'Recibidos'], ['given', 'Dados'], ['private', 'Privados']].map(([k, l]) => `<button type="button" data-act="feed-filter" data-v="${k}" class="${S.feed.filter === k ? 'on' : ''}">${l}</button>`).join('')}</div>
    <select id="bn-feed-cat" aria-label="Categoría" ${S.feed.filter === 'private' ? 'hidden' : ''}><option value="">Todas las categorías</option>${Object.entries(C).map(([k, c]) => `<option value="${k}" ${S.feed.cat === k ? 'selected' : ''}>${esc(c.emoji + ' ' + c.label)}</option>`).join('')}</select></div>
    <div id="bn-feed" class="bn-feed"></div><div id="bn-feed-more"></div>`;
  await loadFeed(true);
  // actualización dinámica: cada 30 s trae solo lo nuevo (sin recargar lo que ya está)
  S.poll = setInterval(async () => {
    if (document.hidden || S.view !== 'reconocimientos' || S.feed.filter === 'private') return;
    try {
      const d = await api('kudos_feed', { filter: S.feed.filter, cat: S.feed.cat, after: S.feed.last }, true);
      if (d.items.length) { S.feed.items = d.items.concat(S.feed.items); S.feed.last = d.items[0].id; $('#bn-feed').insertAdjacentHTML('afterbegin', d.items.map(k => kudoCard(k, true)).join('')); }
    } catch (e) {}
  }, 30000);
};
async function loadFeed(reset) {
  const f = S.feed;
  if (reset) { f.items = []; f.more = false; }
  const before = reset || !f.items.length ? 0 : f.items[f.items.length - 1].id;
  const d = await api('kudos_feed', { filter: f.filter, cat: f.cat, before });
  f.items = f.items.concat(d.items); f.more = d.more;
  if (reset) f.last = d.items.length && !d.items[0].private ? d.items[0].id : 0;
  const box = $('#bn-feed'); if (!box) return;
  if (reset) box.innerHTML = '';
  box.insertAdjacentHTML('beforeend', d.items.map(k => kudoCard(k)).join(''));
  if (!f.items.length) box.innerHTML = empty('fa-hand-holding-heart', f.filter === 'received' ? 'Todavía no te reconocieron' : 'Todavía no hay reconocimientos', 'Un gracias a tiempo cambia el día de alguien.', '<button type="button" class="bn-btn" data-act="kudos-new">Reconocer a alguien</button>');
  const more = $('#bn-feed-more');
  more.innerHTML = f.more ? '<button type="button" class="bn-btn bn-btn-ghost bn-btn-block" data-act="feed-more">Ver más</button>' : '';
  // carga más al llegar al final (scroll), sin depender del botón
  if (f.more && 'IntersectionObserver' in window) { const io = new IntersectionObserver(es => { if (es[0].isIntersecting) { io.disconnect(); loadFeed(false).catch(() => {}); } }, { rootMargin: '300px' }); io.observe(more); }
}
const REACTS = ['❤️', '👏', '🎉', '🙌'];
function kudoCard(k, fresh = false) {
  const rx = {}; (k.reactions || []).forEach(r => { rx[r.emoji] = +r.cnt; });
  return `<article class="bn-kudo ${fresh ? 'fresh' : ''} ${k.private ? 'priv' : ''}" data-id="${k.id}">
    <header>${avatar(k.from, 40)}<div><p><b>${esc(k.from.name)}</b> <i class="fa-solid fa-arrow-right bn-muted"></i> ${k.to ? `${avatar(k.to, 20)} <b>${esc(k.to.name)}</b>` : ''}</p><small>${ago(k.at)}${k.private ? ' · <i class="fa-solid fa-lock"></i> privado' : ''}</small></div><span class="bn-cat big">${esc(k.cat.emoji)} ${esc(k.cat.label)}</span></header>
    <blockquote>${esc(k.body)}</blockquote>
    ${k.private ? (k.to && k.to.id === S.meta.uid ? `<footer>${k.thanked ? '<span class="bn-tag ok">Le diste las gracias 💛</span>' : `<button type="button" class="bn-btn bn-btn-sm bn-btn-ghost" data-act="kudos-thank" data-id="${k.id}">💛 Dar las gracias</button>`}</footer>` : k.thanked ? '<footer><span class="bn-tag ok">Te dio las gracias 💛</span></footer>' : '')
    : `<footer><div class="bn-reacts">${REACTS.map(e => `<button type="button" class="${k.my_reaction === e ? 'on' : ''}" data-act="kudos-react" data-id="${k.id}" data-e="${e}" aria-pressed="${k.my_reaction === e}">${e}<b>${rx[e] || ''}</b></button>`).join('')}</div>
      <button type="button" class="bn-link" data-act="kudos-replies" data-id="${k.id}"><i class="fa-regular fa-comment"></i> ${k.replies.length || ''} Comentar</button>${k.mine || S.meta.admin ? `<button type="button" class="bn-link danger" data-act="kudos-delete" data-id="${k.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button>` : ''}</footer>
    <div class="bn-replies" hidden>${k.replies.map(replyHtml).join('')}<form class="bn-reply-f" data-id="${k.id}"><input name="body" maxlength="280" placeholder="Escribe un comentario…" aria-label="Comentario"><button type="submit" class="bn-ico" aria-label="Enviar"><i class="fa-solid fa-paper-plane"></i></button></form></div>`}
  </article>`;
}
const replyHtml = r => `<div class="bn-reply">${avatar(r, 24)}<div><b>${esc(r.name)}</b> ${esc(r.body)}<small>${ago(r.at)}</small></div></div>`;
async function kudosNew(toId = 0) {
  if (!S.meta) await ensureMeta();
  if (!S.people) S.people = (await api('people')).items;
  const C = S.meta.kudos_cats;
  const f = modal('Reconocer a alguien', `<div class="bn-f"><span>¿A quién?</span><input type="search" id="bn-pp-q" placeholder="Busca por nombre" autocomplete="off" aria-label="Buscar persona"><div class="bn-people" id="bn-pp">${peopleList('', toId)}</div><input type="hidden" name="to" value="${toId || ''}"></div>
    <div class="bn-f"><span>Categoría</span><div class="bn-chips">${Object.entries(C).map(([k, c]) => `<label class="bn-chip-r"><input type="radio" name="badge" value="${k}"><span>${esc(c.emoji)} ${esc(c.label)}</span></label>`).join('')}</div></div>
    <label class="bn-f"><span>Mensaje</span><textarea name="message" rows="3" maxlength="280" required placeholder="Cuenta en pocas palabras por qué"></textarea><small class="bn-count">0 / 280</small></label>
    <label class="bn-switch"><input type="checkbox" name="private"><i></i> Privado: solo lo ve esa persona (no sale en el muro ni en Comunidad)</label>`, {
    submit: 'Enviar reconocimiento', onSubmit: async fm => {
      if (!+fm.to.value) { toast('Elige a quién reconocer.', true); return true; }
      const b = fm.querySelector('[name=badge]:checked'); if (!b) { toast('Elige una categoría.', true); return true; }
      const r = await api('kudos_give', { to: +fm.to.value, badge: b.value, message: fm.message.value, private: fm.private.checked });
      toast(r.private ? 'Reconocimiento privado enviado 💌' : '¡Reconocimiento publicado! 🎉'); celebrate($('#bn-thank'));
      S.home = null; if (S.view === 'reconocimientos' || S.view === 'inicio') route();
    } });
  f.querySelector('#bn-pp-q').addEventListener('input', e => { $('#bn-pp').innerHTML = peopleList(e.target.value, +f.to.value); });
  f.message.addEventListener('input', e => { f.querySelector('.bn-count').textContent = e.target.value.length + ' / 280'; });
}
function peopleList(q, sel) {
  const n = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const xs = S.people.filter(p => !q || n(p.name + ' ' + p.username).includes(n(q))).slice(0, 40);
  return xs.length ? xs.map(p => `<button type="button" class="bn-person ${p.id === sel ? 'on' : ''}" data-act="pick-person" data-id="${p.id}">${avatar(p, 28)}<span>${esc(p.name)}</span></button>`).join('') : '<p class="bn-muted">No hay coincidencias.</p>';
}

// ═════════════════════════════ CARGA ═════════════════════════════
VIEWS.carga = async () => {
  await ensureMeta();
  const d = await api('load'), m = d.mine, L = m.levels;
  const mine = card('Tu carga esta semana', `<div class="bn-levels big">${[1, 2, 3, 4, 5].map(k => `<button type="button" class="bn-level ${m.current === k ? 'on' : ''}" data-act="load-set" data-v="${k}" style="--c:${L[k].color}">${k}<small>${esc(L[k].label)}</small></button>`).join('')}</div>
    ${m.hint ? `<p class="bn-hint"><i class="fa-solid fa-lightbulb"></i> ${esc(m.hint)}</p>` : ''}<p class="bn-fine"><i class="fa-solid fa-lock"></i> Lo que marcas es tuyo. Al equipo solo le llegan promedios con ${S.meta.min} personas o más, y nunca se usa para evaluar el rendimiento de nadie.</p>`, { icon: 'fa-scale-balanced', cls: 'span2' });
  const hist = card('Últimas 12 semanas', bars(m.history.map(h => ({ v: h.level, label: 'Semana ' + h.week.slice(6), short: h.week.slice(6), color: h.level ? L[h.level].color : '' }))), { icon: 'fa-chart-column' });
  const t = m.tasks;
  const tasks = card('Tus tareas', `<div class="bn-kpis"><div><small>Abiertas</small><b>${t.open}</b></div><div><small>Vencidas</small><b class="${t.overdue ? 'warn' : ''}">${t.overdue}</b></div><div><small>Vencen en 7 días</small><b>${t.due7}</b></div>${t.planned_h != null ? `<div><small>Horas planificadas (7 días)</small><b>${num(t.planned_h)}</b></div>` : ''}</div><p class="bn-fine">Sale de Trabajo y de Solicitudes. <a href="/toolz/trabajo/">Ver mis tareas</a></p>`, { icon: 'fa-list-check' });
  let teams = '';
  if (d.teams) teams = card('Carga por equipo', `<p class="bn-fine">Promedio semanal de carga percibida. Con menos de ${d.teams.min} respuestas, la semana queda vacía.</p><div class="bn-ltable">${d.teams.groups.map(g => `<div class="bn-lrow ${g.alert ? 'alert' : ''}"><span class="t"><i class="bn-tdot" style="background:${g.color}"></i>${esc(g.name)}${g.alert ? ' <span class="bn-tag warn"><i class="fa-solid fa-triangle-exclamation"></i> posible sobrecarga</span>' : ''}</span>
      <span class="spark">${bars(g.series.map(s => ({ v: s.avg, label: 'Semana ' + s.week.slice(6) + (s.high != null ? ` · ${s.high}% alta` : ''), color: s.avg ? L[Math.round(s.avg)].color : '' })), { h: 36 })}</span><b>${num(g.avg)}</b>
      <small>${g.tasks ? `${num(g.tasks.open_pp)} tareas por persona · ${g.tasks.overdue} vencidas` : 'equipo chico: sin datos de tareas'}</small></div>`).join('')}</div>`, { icon: 'fa-people-group', cls: 'span3' });
  view().innerHTML = `<div class="bn-grid">${mine}${tasks}${hist}${teams}</div>`;
};

// ═════════════════════════════ AUSENCIAS ═════════════════════════════
VIEWS.ausencias = async () => {
  await ensureMeta();
  const d = await api('leave'); S.leave = d;
  if (!S.calMonth) S.calMonth = S.meta.today.slice(0, 7);
  const b = d.balance;
  const bal = card('Tus días', `<div class="bn-hab-sum">${ring(b.total ? Math.round(b.available / b.total * 100) : 0, { size: 96, stroke: 9, label: String(b.available), color: '#06b6d4' })}<div><b>${b.available} día${b.available === 1 ? '' : 's'} de vacaciones disponibles</b><p>${b.total} en ${b.year} · ${b.used} usados${b.pending ? ` · ${b.pending} en espera` : ''}</p><button type="button" class="bn-btn" data-act="leave-new"><i class="fa-solid fa-plus"></i> Pedir vacaciones o licencia</button></div></div>`, { icon: 'fa-umbrella-beach', cls: 'span2' });
  const dec = d.to_decide.length ? card('Para revisar', `<ul class="bn-leaves">${d.to_decide.map(l => leaveRow(l, true)).join('')}</ul>`, { icon: 'fa-inbox', cls: 'span3 c-dec' }) : '';
  const mine = card('Tus pedidos', d.items.length ? `<ul class="bn-leaves">${d.items.map(l => leaveRow(l)).join('')}</ul>` : empty('fa-calendar-plus', 'Todavía no pediste días', 'Cuando lo hagas, acá vas a ver su estado.'), { icon: 'fa-clock-rotate-left' });
  view().innerHTML = `<div class="bn-grid">${dec}${bal}${mine}${card('Calendario de ausencias', '<div id="bn-cal"></div>', { icon: 'fa-calendar-days', cls: 'span3' })}</div>`;
  await drawCal();
};
function leaveRow(l, decide = false) {
  const st = { pending: 'warn', approved: 'ok', rejected: 'bad', cancelled: '' }[l.status];
  const canCancel = !decide && (l.status === 'pending' || (l.status === 'approved' && l.date_from > S.meta.today));
  return `<li style="--c:${l.color}"><span class="bn-hico"><i class="fa-solid ${esc(l.icon)}"></i></span><div class="t">${decide ? `<b>${esc(l.user.name)}</b> · ` : ''}<b>${esc(l.type_label)}</b> · ${fmtD(l.date_from)}${l.date_to !== l.date_from ? ' al ' + fmtD(l.date_to) : ''} · ${l.days} día${l.days === 1 ? '' : 's'} hábil${l.days === 1 ? '' : 'es'}
    ${l.note ? `<small>“${esc(l.note)}”</small>` : ''}${l.decision_note ? `<small>Respuesta: ${esc(l.decision_note)}</small>` : ''}${decide && l.balance ? `<small>Le quedan ${l.balance.available + l.days} días antes de este pedido</small>` : ''}</div>
    ${decide ? `<div class="bn-row"><button type="button" class="bn-btn bn-btn-sm" data-act="leave-decide" data-id="${l.id}" data-v="1"><i class="fa-solid fa-check"></i> Aprobar</button><button type="button" class="bn-btn bn-btn-sm bn-btn-ghost" data-act="leave-decide" data-id="${l.id}" data-v="0">Rechazar</button></div>`
      : `<span class="bn-tag ${st}">${esc(l.status_label)}</span>${canCancel ? `<button type="button" class="bn-link danger" data-act="leave-cancel" data-id="${l.id}">Cancelar</button>` : ''}`}</li>`;
}
async function drawCal() {
  const d = await api('leave_calendar', { month: S.calMonth });
  const [y, m] = S.calMonth.split('-').map(Number), first = new Date(y, m - 1, 1), off = (first.getDay() + 6) % 7, nd = new Date(y, m, 0).getDate();
  let cells = '';
  for (let i = 0; i < off; i++) cells += '<div class="bn-cd out"></div>';
  for (let i = 1; i <= nd; i++) {
    const k = `${S.calMonth}-${String(i).padStart(2, '0')}`, wd = (off + i - 1) % 7;
    const evs = d.items.filter(l => l.date_from <= k && l.date_to >= k);
    cells += `<div class="bn-cd ${wd >= 5 ? 'we' : ''} ${k === S.meta.today ? 'today' : ''}"><span class="n">${i}</span>${d.holidays[k] ? `<em class="hol">${esc(d.holidays[k])}</em>` : ''}${evs.slice(0, 3).map(l => `<span class="ev ${l.status === 'pending' ? 'pend' : ''}" style="--c:${l.color}" title="${esc(l.user.name)} · ${esc(l.type_label)}${l.status === 'pending' ? ' (en espera)' : ''}">${esc(l.self ? 'Tú' : l.user.name.split(' ')[0])}</span>`).join('')}${evs.length > 3 ? `<span class="ev more">+${evs.length - 3}</span>` : ''}</div>`;
  }
  $('#bn-cal').innerHTML = `<div class="bn-cal-h"><button type="button" class="bn-ico" data-act="cal-move" data-v="-1" aria-label="Mes anterior"><i class="fa-solid fa-chevron-left"></i></button><b>${MONTHS[m - 1]} ${y}</b><button type="button" class="bn-ico" data-act="cal-move" data-v="1" aria-label="Mes siguiente"><i class="fa-solid fa-chevron-right"></i></button></div>
    <div class="bn-cal"><div class="bn-cdw">${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map(x => `<span>${x}</span>`).join('')}</div><div class="bn-cg">${cells}</div></div><p class="bn-fine">Ves tus ausencias y las de tu equipo. A tus compañeros solo les aparece "Vacaciones" o "Ausente", nunca el motivo.</p>`;
}
function leaveNew() {
  const T = S.meta.leave_types, today = S.meta.today;
  const f = modal('Pedir vacaciones o licencia', `<div class="bn-f"><span>Tipo</span><div class="bn-chips">${Object.entries(T).map(([k, t], i) => `<label class="bn-chip-r"><input type="radio" name="type" value="${k}" ${i === 0 ? 'checked' : ''}><span><i class="fa-solid ${t.icon}" style="color:${t.color}"></i> ${esc(t.label)}</span></label>`).join('')}</div></div>
    <div class="bn-two"><label class="bn-f"><span>Desde</span><input type="date" name="date_from" required value="${today}"></label><label class="bn-f"><span>Hasta</span><input type="date" name="date_to" required value="${today}"></label></div>
    <p class="bn-days-n" id="bn-ld">…</p>
    <label class="bn-f"><span>Comentario (opcional)</span><input name="note" maxlength="200" placeholder="Ej.: viaje familiar"></label>
    <p class="bn-fine"><i class="fa-solid fa-shield-heart"></i> No escribas información médica ni de salud: eso se gestiona directamente con RR. HH.</p>`, {
    submit: 'Enviar pedido', onSubmit: async fm => { await api('leave_request', { type: fm.querySelector('[name=type]:checked').value, date_from: fm.date_from.value, date_to: fm.date_to.value, note: fm.note.value }); toast('Pedido enviado. Te avisamos cuando lo revisen.'); S.home = null; if (S.view === 'ausencias' || S.view === 'inicio') route(); } });
  const upd = async () => { if (f.date_to.value < f.date_from.value) f.date_to.value = f.date_from.value; const r = await api('leave_days', { from: f.date_from.value, to: f.date_to.value }, true).catch(() => ({ days: 0 })); $('#bn-ld').textContent = r.days ? `${r.days} día${r.days === 1 ? '' : 's'} hábil${r.days === 1 ? '' : 'es'}` : 'Sin días hábiles en esas fechas'; };
  f.date_from.addEventListener('change', upd); f.date_to.addEventListener('change', upd); upd();
}

// ═════════════════════════════ GESTIÓN ═════════════════════════════
VIEWS.gestion = async arg => {
  await ensureMeta();
  if (!S.meta.manage) { view().innerHTML = empty('fa-lock', 'Sin acceso', 'Esta parte es para quienes gestionan Bienestar.'); return; }
  const [sub, sid] = (arg || 'resumen').split('/');
  S.mgTab = ['resumen', 'retos', 'encuestas', 'programas'].includes(sub) ? sub : 'resumen';
  S.mg = await api('manage');
  const tabs = [['resumen', 'Resumen', 'fa-chart-simple'], ['retos', 'Retos', 'fa-person-running'], ['encuestas', 'Encuestas', 'fa-square-poll-vertical'], ['programas', 'Programas', 'fa-seedling']];
  view().innerHTML = `<div class="bn-bar-top"><div><h2 class="bn-h2">Gestión de Bienestar</h2><p class="bn-muted">${S.mg.org ? 'Ves toda la organización.' : 'Ves y gestionas solo tus equipos.'} Todo lo emocional se muestra agregado y anónimo.${S.meta.admin ? ' Ajustes generales en <a href="/toolz/admin.php#bienestar">Admin › Bienestar</a>.' : ''}</p></div>${S.mg.to_decide ? `<a class="bn-btn bn-btn-ghost" href="#ausencias"><i class="fa-solid fa-inbox"></i> ${S.mg.to_decide} pedido${S.mg.to_decide > 1 ? 's' : ''} por revisar</a>` : ''}</div>
    <div class="bn-seg bn-mgtabs">${tabs.map(([k, l, i]) => `<a href="#gestion/${k}" class="${S.mgTab === k ? 'on' : ''}"><i class="fa-solid ${i}"></i> ${l}</a>`).join('')}</div><div id="bn-mg"></div>`;
  await MG[S.mgTab](sid);
};
const MG = {};
const teamNames = ids => ids && ids.length ? ids.map(i => (S.mg.teams.find(t => t.id === i) || { name: '#' + i }).name).join(', ') : 'Toda la organización';
MG.resumen = async () => {
  const I = S.insights, d = await api('insights', { days: I.days, team: I.team }), L = S.meta.mood_levels, P = d.participation, mo = d.mood;
  const pc = (k, l, icon) => `<div class="bn-pc"><i class="fa-solid ${icon}"></i><b>${P[k] == null ? '—' : P[k] + '%'}</b><small>${l}</small></div>`;
  $('#bn-mg').innerHTML = `<div class="bn-filters"><div class="bn-seg">${[7, 30, 90].map(n => `<button type="button" data-act="ins-days" data-v="${n}" class="${I.days === n ? 'on' : ''}">${n} días</button>`).join('')}</div>
    <select id="bn-ins-team" aria-label="Equipo">${S.mg.org ? '<option value="0">Toda la organización</option>' : '<option value="0">Todos mis equipos</option>'}${S.mg.teams.map(t => `<option value="${t.id}" ${I.team === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
    <div class="bn-grid">${card('Participación (últimos 7 días)', P.size == null ? empty('fa-user-shield', 'Grupo chico', `Se muestra con ${P.min} personas o más.`) : `<div class="bn-pcs">${pc('mood', 'contó su ánimo', 'fa-face-smile')}${pc('habits', 'cumplió un hábito', 'fa-seedling')}${pc('challenges', 'sumó a un reto', 'fa-person-running')}${pc('load', 'marcó su carga', 'fa-scale-balanced')}${pc('kudos', 'reconoció a alguien', 'fa-award')}</div><p class="bn-fine">Porcentajes sobre ${P.size} personas. "—" = menos de ${P.min} personas (no se muestra para que nadie quede expuesto).</p>`, { icon: 'fa-users', cls: 'span3' })}
    ${card('Ánimo', mo.ok ? `<div class="bn-kpis"><div><small>Promedio</small><b>${moodFace(mo.avg, 20)} ${num(mo.avg)}</b></div><div><small>Período anterior</small><b>${num(mo.prev_avg)}</b></div><div><small>Tendencia</small><b>${mo.trend ? `<span class="bn-trend ${mo.trend}">${mo.delta > 0 ? '+' : ''}${num(mo.delta)}</span>` : '—'}</b></div></div>${lineChart(mo.days.map(x => ({ v: x.avg, label: fmtLong(x.day), short: fmtD(x.day) })), { color: '#22c55e' })}${distBar(mo.dist)}` : empty('fa-user-shield', 'Pocas respuestas', `Se muestra con al menos ${mo.min} personas distintas.`), { icon: 'fa-face-smile', cls: 'span2' })}
    ${card('Ánimo por equipo', d.teams.length ? `<ul class="bn-tlist">${d.teams.map(t => `<li><i class="bn-tdot" style="background:${t.color}"></i><span class="t">${esc(t.name)}</span>${t.ok ? `${moodFace(t.avg, 18)}<b>${num(t.avg)}</b>${t.trend ? `<span class="bn-trend ${t.trend}">${t.delta > 0 ? '+' : ''}${num(t.delta)}</span>` : ''}` : '<small class="bn-muted">pocas respuestas</small>'}</li>`).join('')}</ul>` : empty('fa-people-group', 'Sin equipos', 'Arma equipos en Admin › Equipos.'), { icon: 'fa-people-group' })}
    ${card('Alertas de carga', d.load.groups.filter(g => g.alert).length ? `<ul class="bn-tlist">${d.load.groups.filter(g => g.alert).map(g => `<li><i class="fa-solid fa-triangle-exclamation" style="color:#f97316"></i><span class="t">${esc(g.name)}</span><b>${num(g.avg)}</b></li>`).join('')}</ul><p class="bn-fine">Dos semanas seguidas con carga promedio alta. Buen momento para revisar prioridades con el equipo.</p><a class="bn-more" href="#carga">Ver detalle <i class="fa-solid fa-arrow-right"></i></a>` : empty('fa-circle-check', 'Sin alertas', 'Ningún equipo marca sobrecarga sostenida.'), { icon: 'fa-scale-balanced', cls: 'span2' })}</div>`;
  $('#bn-ins-team').addEventListener('change', e => { S.insights.team = +e.target.value; MG.resumen(); });
};
MG.retos = async () => {
  const xs = S.mg.challenges, st = { draft: ['Borrador', ''], published: ['Publicado', 'ok'], archived: ['Archivado', ''] };
  $('#bn-mg').innerHTML = `<div class="bn-row end"><button type="button" class="bn-btn" data-act="mg-ch-edit" data-id="0"><i class="fa-solid fa-plus"></i> Nuevo reto</button></div>
    ${xs.length ? `<div class="bn-mlist">${xs.map(c => `<article class="bn-mitem" style="--c:${c.color}"><span class="bn-hico"><i class="fa-solid ${esc(c.icon)}"></i></span><div class="t"><b>${esc(c.title)}</b> <span class="bn-tag ${st[c.status][1]}">${st[c.status][0]}</span><small>${c.kind === 'colectivo' ? 'Colectivo · meta ' + c.goal_total : 'Individual · ' + c.goal_person} ${esc(c.unit)} · ${fmtD(c.start_date)} al ${fmtD(c.end_date)} · ${esc(teamNames(c.audience))}</small>
      <div class="bn-mstats"><span><b>${c.stats.participants}</b> de ${c.stats.audience} (${c.stats.participation}%)</span>${c.stats.pct_total != null ? `<span class="bn-prog-bar"><span style="width:${c.stats.pct_total}%"></span></span><span><b>${c.stats.pct_total}%</b> de la meta</span>` : ''}<span><b>${c.stats.completed}</b> cumplieron</span><span><b>${c.stats.active7}</b> activos en 7 días</span></div></div>
      <div class="bn-row">${c.status !== 'published' ? `<button type="button" class="bn-btn bn-btn-sm" data-act="mg-ch-st" data-id="${c.id}" data-v="published">Publicar</button>` : `<button type="button" class="bn-btn bn-btn-sm bn-btn-ghost" data-act="mg-ch-st" data-id="${c.id}" data-v="archived">Archivar</button>`}<button type="button" class="bn-ico" data-act="mg-ch-edit" data-id="${c.id}" aria-label="Editar"><i class="fa-regular fa-pen-to-square"></i></button>${c.stats.participants ? '' : `<button type="button" class="bn-ico" data-act="mg-ch-st" data-id="${c.id}" data-v="delete" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button>`}</div></article>`).join('')}</div>` : empty('fa-person-running', 'Todavía no hay retos', 'Crea el primero: los colectivos suman a todo el equipo.')}`;
};
function audField(sel = []) {
  return `<div class="bn-f"><span>Para quién</span><div class="bn-chips">${S.mg.org ? `<label class="bn-chip-r"><input type="checkbox" name="aud_all" ${sel.length ? '' : 'checked'}><span>Toda la organización</span></label>` : ''}${S.mg.teams.map(t => `<label class="bn-chip-r"><input type="checkbox" name="aud" value="${t.id}" ${sel.includes(t.id) ? 'checked' : ''}><span><i class="bn-tdot" style="background:${t.color}"></i> ${esc(t.name)}</span></label>`).join('')}</div>${S.mg.org ? '' : '<small class="bn-muted">Solo puedes elegir equipos que gestionas.</small>'}</div>`;
}
const audVal = f => (f.aud_all && f.aud_all.checked) ? [] : [...f.querySelectorAll('[name=aud]:checked')].map(i => +i.value);
const progOpts = sel => `<label class="bn-f"><span>Programa (opcional)</span><select name="program_id"><option value="">Ninguno</option>${S.mg.programs.map(p => `<option value="${p.id}" ${sel === p.id ? 'selected' : ''}>${esc(p.title)}</option>`).join('')}</select></label>`;
function chEdit(c) {
  c = c || { kind: 'colectivo', unit: 'veces', icon: 'fa-person-running', color: '#3b82f6', start_date: S.meta.today, end_date: new Date(Date.now() + 6 * 864e5).toISOString().slice(0, 10), audience: [], image: '', goal_total: 100, goal_person: 0 };
  const f = modal(c.id ? 'Editar reto' : 'Nuevo reto', `<input type="hidden" name="id" value="${c.id || ''}"><input type="hidden" name="image" value="${esc(c.image || '')}">
    <label class="bn-f"><span>Título</span><input name="title" maxlength="120" required value="${esc(c.title || '')}" placeholder="Ej.: 10.000 pasos entre todos"></label>
    <label class="bn-f"><span>Descripción</span><textarea name="description" rows="3" maxlength="1500">${esc(c.description || '')}</textarea></label>
    <div class="bn-f"><span>Tipo</span><div class="bn-chips"><label class="bn-chip-r"><input type="radio" name="kind" value="colectivo" ${c.kind === 'colectivo' ? 'checked' : ''}><span><i class="fa-solid fa-users"></i> Colectivo (recomendado)</span></label><label class="bn-chip-r"><input type="radio" name="kind" value="individual" ${c.kind === 'individual' ? 'checked' : ''}><span><i class="fa-solid fa-user"></i> Individual</span></label></div></div>
    <div class="bn-three"><label class="bn-f"><span>Unidad</span><input name="unit" maxlength="30" value="${esc(c.unit)}" placeholder="pasos, minutos, veces"></label><label class="bn-f"><span>Meta del grupo</span><input type="number" name="goal_total" min="0" value="${c.goal_total || 0}"></label><label class="bn-f"><span>Objetivo por persona</span><input type="number" name="goal_person" min="0" value="${c.goal_person || 0}"></label></div>
    <div class="bn-two"><label class="bn-f"><span>Empieza</span><input type="date" name="start_date" required value="${c.start_date}"></label><label class="bn-f"><span>Termina</span><input type="date" name="end_date" required value="${c.end_date}"></label></div>
    ${audField(c.audience)}${progOpts(c.program_id)}
    <label class="bn-f"><span>Recompensa (opcional)</span><input name="reward" maxlength="200" value="${esc(c.reward || '')}" placeholder="Ej.: desayuno para todo el equipo"></label>
    <div class="bn-f"><span>Imagen (opcional)</span><div class="bn-upl"><div class="bn-upl-prev" id="bn-upl-prev">${c.image_url ? `<img src="${esc(c.image_url)}" alt="">` : '<i class="fa-regular fa-image"></i>'}</div><input type="file" name="file" accept="image/jpeg,image/png,image/webp" aria-label="Imagen"><button type="button" class="bn-link" data-act="ch-img-clear">Quitar</button></div></div>
    <div class="bn-two"><div class="bn-f"><span>Ícono</span><div class="bn-icons sm">${S.mg.icons.map(i => `<label><input type="radio" name="icon" value="${i}" ${c.icon === i ? 'checked' : ''}><i class="fa-solid ${i}"></i></label>`).join('')}</div></div><div class="bn-f"><span>Color</span><div class="bn-colors">${S.mg.colors.map(x => `<label><input type="radio" name="color" value="${x}" ${c.color === x ? 'checked' : ''}><i style="background:${x}"></i></label>`).join('')}</div></div></div>
    ${S.mg.rankings ? `<label class="bn-switch"><input type="checkbox" name="ranking" ${c.ranking ? 'checked' : ''}><i></i> Mostrar ranking (cada persona elige si aparece)</label>` : ''}`, {
    wide: true, onSubmit: async fm => {
      const r = await api('ch_save', { id: +fm.id.value || 0, title: fm.title.value, description: fm.description.value, kind: fm.querySelector('[name=kind]:checked').value, unit: fm.unit.value, goal_total: +fm.goal_total.value, goal_person: +fm.goal_person.value,
        start_date: fm.start_date.value, end_date: fm.end_date.value, audience: audVal(fm), program_id: +fm.program_id.value || 0, reward: fm.reward.value, image: fm.image.value,
        icon: (fm.querySelector('[name=icon]:checked') || {}).value, color: (fm.querySelector('[name=color]:checked') || {}).value, ranking: fm.ranking ? fm.ranking.checked : false });
      S.mg.challenges = r.challenges; toast(c.id ? 'Reto guardado' : 'Reto creado como borrador. Publícalo cuando esté listo.'); MG.retos();
    } });
  f.file.addEventListener('change', async e => { const file = e.target.files[0]; if (!file) return; const r = await upload(file); f.image.value = r.path; $('#bn-upl-prev').innerHTML = `<img src="${esc(r.url)}" alt="">`; });
  audToggle(f);
}
function audToggle(f) { if (!f.aud_all) return; const sync = () => { if (f.querySelector('[name=aud]:checked')) f.aud_all.checked = false; }; f.addEventListener('change', e => { if (e.target.name === 'aud') sync(); if (e.target.name === 'aud_all' && e.target.checked) f.querySelectorAll('[name=aud]').forEach(i => { i.checked = false; }); }); }
MG.encuestas = async sid => {
  if (sid) return svResults(+sid);
  const xs = S.mg.surveys, stl = { draft: ['Borrador', ''], open: ['Abierta', 'ok'], scheduled: ['Programada', 'warn'], closed: ['Cerrada', ''] };
  $('#bn-mg').innerHTML = `<div class="bn-row end"><button type="button" class="bn-btn" data-act="mg-sv-edit" data-id="0"><i class="fa-solid fa-plus"></i> Nueva encuesta</button></div>
    ${xs.length ? `<div class="bn-mlist">${xs.map(s => `<article class="bn-mitem" style="--c:#a855f7"><span class="bn-hico"><i class="fa-solid fa-square-poll-vertical"></i></span><div class="t"><b>${esc(s.title)}</b> <span class="bn-tag ${stl[s.state][1]}">${stl[s.state][0]}</span>${s.anonymous ? ' <span class="bn-tag"><i class="fa-solid fa-user-secret"></i> anónima</span>' : ''}${s.sensitive ? ' <span class="bn-tag warn">sensible</span>' : ''}
      <small>${esc(s.category_label)} · ${s.n_questions} preguntas · ${fmtD(s.start_date)} al ${fmtD(s.end_date)} · ${esc(teamNames(s.audience))}${s.recurrence !== 'none' ? ' · ' + esc(S.mg.recur[s.recurrence]) : ''}</small>
      <div class="bn-mstats"><span><b>${s.responses}</b> de ${s.audience_size} respondieron (${s.participation}%)</span></div></div>
      <div class="bn-row">${s.status === 'draft' ? `<button type="button" class="bn-btn bn-btn-sm" data-act="mg-sv-st" data-id="${s.id}" data-v="published">Publicar</button>` : `<a class="bn-btn bn-btn-sm bn-btn-ghost" href="#gestion/encuestas/${s.id}"><i class="fa-solid fa-chart-column"></i> Resultados</a>`}${s.state === 'open' || s.state === 'scheduled' ? `<button type="button" class="bn-link" data-act="mg-sv-st" data-id="${s.id}" data-v="closed">Cerrar</button>` : ''}<button type="button" class="bn-ico" data-act="mg-sv-edit" data-id="${s.id}" aria-label="Editar"><i class="fa-regular fa-pen-to-square"></i></button>${s.responses ? '' : `<button type="button" class="bn-ico" data-act="mg-sv-st" data-id="${s.id}" data-v="delete" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button>`}</div></article>`).join('')}</div>` : empty('fa-square-poll-vertical', 'Todavía no hay encuestas', 'Empieza con una plantilla: Pulso de clima, Saturación o Equilibrio.')}`;
};
function qRow(q = { type: 'scale', text: '', options: [], required: true, scale_max: 5 }) {
  return `<div class="bn-qe" data-type="${q.type}"><div class="bn-qe-h"><select class="qe-type" aria-label="Tipo">${Object.entries(S.mg.types).map(([k, l]) => `<option value="${k}" ${q.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select><input class="qe-text" maxlength="300" value="${esc(q.text)}" placeholder="Escribe la pregunta" aria-label="Pregunta">
    <button type="button" class="bn-ico" data-act="qe-up" aria-label="Subir"><i class="fa-solid fa-arrow-up"></i></button><button type="button" class="bn-ico" data-act="qe-del" aria-label="Quitar"><i class="fa-regular fa-trash-can"></i></button></div>
    <div class="bn-qe-b"><label class="qe-scale"><span>Escala</span><select class="qe-max"><option value="5" ${q.scale_max !== 10 ? 'selected' : ''}>1 a 5</option><option value="10" ${q.scale_max === 10 ? 'selected' : ''}>1 a 10</option></select></label>
    <label class="qe-opts"><span>Opciones (una por línea)</span><textarea class="qe-o" rows="3">${esc((q.options || []).join('\n'))}</textarea></label><label class="bn-switch sm"><input type="checkbox" class="qe-req" ${q.required !== false ? 'checked' : ''}><i></i> Obligatoria</label></div></div>`;
}
function svEdit(s) {
  const fresh = !s;
  s = s || { title: '', intro: '', category: 'ambiente', anonymous: true, sensitive: false, start_date: S.meta.today, end_date: new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 10), recurrence: 'none', audience: [], questions: [] };
  const lock = !!s.locked;
  const f = modal(s.id ? 'Editar encuesta' : 'Nueva encuesta', `${fresh ? `<div class="bn-f"><span>Empezar desde una plantilla</span><div class="bn-chips">${Object.entries(S.mg.templates).map(([k, t]) => `<button type="button" class="bn-chip" data-act="sv-tpl" data-v="${k}">${esc(t.title)}</button>`).join('')}</div></div>` : ''}
    ${lock ? '<p class="bn-hint"><i class="fa-solid fa-lock"></i> Ya tiene respuestas: solo puedes cambiar el título, la presentación y extender el vencimiento.</p>' : ''}
    <input type="hidden" name="id" value="${s.id || ''}">
    <label class="bn-f"><span>Título</span><input name="title" maxlength="140" required value="${esc(s.title)}"></label>
    <label class="bn-f"><span>Presentación</span><textarea name="intro" rows="2" maxlength="800">${esc(s.intro)}</textarea></label>
    <div class="bn-three"><label class="bn-f"><span>Tema</span><select name="category" ${lock ? 'disabled' : ''}>${Object.entries(S.mg.cats).map(([k, l]) => `<option value="${k}" ${s.category === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label><label class="bn-f"><span>Publicación</span><input type="date" name="start_date" value="${s.start_date}" ${lock ? 'disabled' : ''}></label><label class="bn-f"><span>Vencimiento</span><input type="date" name="end_date" value="${s.end_date}"></label></div>
    <div class="bn-two"><label class="bn-f"><span>Periodicidad</span><select name="recurrence" ${lock ? 'disabled' : ''}>${Object.entries(S.mg.recur).map(([k, l]) => `<option value="${k}" ${s.recurrence === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>${progOpts(s.program_id)}</div>
    <div class="bn-row"><label class="bn-switch"><input type="checkbox" name="anonymous" ${s.anonymous ? 'checked' : ''} ${lock ? 'disabled' : ''}><i></i> Anónima</label><label class="bn-switch"><input type="checkbox" name="sensitive" ${s.sensitive ? 'checked' : ''} ${lock ? 'disabled' : ''}><i></i> Tema sensible (siempre anónima)</label></div>
    ${lock ? '' : audField(s.audience)}
    <div class="bn-f"><span>Preguntas</span><div id="bn-qes" ${lock ? 'class="locked"' : ''}>${(s.questions.length ? s.questions : [undefined]).map(q => qRow(q)).join('')}</div>${lock ? '' : '<button type="button" class="bn-btn bn-btn-ghost bn-btn-sm" data-act="qe-add"><i class="fa-solid fa-plus"></i> Agregar pregunta</button>'}</div>`, {
    wide: true, onSubmit: async fm => {
      const qs = [...fm.querySelectorAll('.bn-qe')].map(e => ({ type: e.querySelector('.qe-type').value, text: e.querySelector('.qe-text').value, scale_max: +e.querySelector('.qe-max').value, options: e.querySelector('.qe-o').value.split('\n').map(x => x.trim()).filter(Boolean), required: e.querySelector('.qe-req').checked }));
      const r = await api('sv_save', { id: +fm.id.value || 0, title: fm.title.value, intro: fm.intro.value, category: fm.category.value, start_date: fm.start_date.value, end_date: fm.end_date.value, recurrence: fm.recurrence.value,
        program_id: +fm.program_id.value || 0, anonymous: fm.anonymous.checked, sensitive: fm.sensitive.checked, audience: fm.aud_all || fm.aud ? audVal(fm) : s.audience, questions: qs });
      S.mg.surveys = r.surveys; toast(s.id ? 'Encuesta guardada' : 'Encuesta creada como borrador. Publícala cuando esté lista.'); MG.encuestas();
    } });
  if (lock) f.querySelectorAll('#bn-qes input, #bn-qes select, #bn-qes textarea, #bn-qes button').forEach(e => { e.disabled = true; });
  f.sensitive.addEventListener('change', () => { if (f.sensitive.checked) f.anonymous.checked = true; });
  f.addEventListener('change', e => { if (e.target.classList.contains('qe-type')) e.target.closest('.bn-qe').dataset.type = e.target.value; });
  audToggle(f);
}
async function svResults(id) {
  const team = S.svTeam || 0, d = await api('sv_results', { id, team }), s = d.survey;
  const qHtml = q => {
    if (q.type === 'scale') return `<div class="bn-rq"><h4>${esc(q.text)}</h4><div class="bn-kpis"><div><small>Promedio</small><b>${num(q.avg)} <small>/ ${q.scale_max}</small></b></div><div><small>Favorable</small><b>${q.favorable == null ? '—' : q.favorable + '%'}</b></div><div><small>Respuestas</small><b>${q.answered}</b></div></div>${bars(q.dist.map((p, i) => ({ v: p, label: `${i + 1}: ${p}%`, short: String(i + 1), color: `hsl(${i / (q.scale_max - 1) * 120} 65% 48%)` })), { max: Math.max(1, ...q.dist), h: 90, fmt: v => v + '%' })}</div>`;
    if (q.type === 'text') return `<div class="bn-rq"><h4>${esc(q.text)}</h4>${q.texts.length ? `<ul class="bn-texts">${q.texts.map(t => `<li>${esc(t.text)}${t.by ? ` <small>— ${esc(t.by)}</small>` : ''}</li>`).join('')}</ul>` : '<p class="bn-muted">Sin respuestas.</p>'}</div>`;
    return `<div class="bn-rq"><h4>${esc(q.text)}</h4><ul class="bn-optres">${q.options.map(o => `<li><span>${esc(o.label)}</span><span class="bn-prog-bar"><span style="width:${o.pct}%"></span></span><b>${o.pct}%</b></li>`).join('')}</ul></div>`;
  };
  $('#bn-mg').innerHTML = `<a class="bn-link" href="#gestion/encuestas"><i class="fa-solid fa-arrow-left"></i> Encuestas</a><div class="bn-bar-top"><div><h2 class="bn-h2">${esc(s.title)}</h2><p class="bn-muted">${esc(s.category_label)} · ${fmtD(s.start_date)} al ${fmtD(s.end_date)}${s.anonymous ? ' · anónima: se muestra con ' + d.min + ' respuestas o más' : ''}</p></div>
    <select id="bn-sv-team" aria-label="Equipo"><option value="0">Todos</option>${d.teams.map(t => `<option value="${t.id}" ${team === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
    <div class="bn-grid">${card('Resumen', `<div class="bn-kpis"><div><small>Respuestas</small><b>${d.responses == null ? '—' : d.responses}</b></div><div><small>Participación</small><b>${d.participation == null ? '—' : d.participation + '%'}</b></div><div><small>Índice de clima</small><b>${d.score == null ? '—' : num(d.score) + '/100'}</b></div></div>`, { icon: 'fa-chart-simple' })}
    ${d.history.length > 1 ? card('Evolución', lineChart(d.history.map(h => ({ v: h.score, label: fmtLong(h.start_date), short: fmtD(h.start_date) })), { min: 0, max: 100, ticks: [0, 50, 100], color: '#a855f7' }), { icon: 'fa-chart-line', cls: 'span2' }) : ''}
    ${d.by_team.length ? card('Por equipo', `<ul class="bn-tlist">${d.by_team.map(t => `<li><span class="t">${esc(t.name)}</span>${t.ok ? `<b>${num(t.score)}</b><small>/100</small>` : '<small class="bn-muted">pocas respuestas</small>'}</li>`).join('')}</ul>`, { icon: 'fa-people-group' }) : ''}</div>
    ${d.locked ? empty('fa-user-shield', 'Todavía pocas respuestas', `Para cuidar el anonimato, los resultados aparecen con al menos ${d.min} respuestas${team ? ' de este equipo' : ''}.`) : card('Respuestas por pregunta', d.questions.map(qHtml).join(''), { icon: 'fa-list-ol' })}`;
  $('#bn-sv-team').addEventListener('change', e => { S.svTeam = +e.target.value; svResults(id); });
}
MG.programas = async () => {
  const xs = S.mg.programs;
  $('#bn-mg').innerHTML = `<div class="bn-row end"><button type="button" class="bn-btn" data-act="mg-pg-edit" data-id="0"><i class="fa-solid fa-plus"></i> Nuevo programa</button></div><p class="bn-fine">Un programa agrupa retos y encuestas con un objetivo (ej.: "Mes del descanso"). Aparece arriba de la portada mientras está vigente.</p>
    ${xs.length ? `<div class="bn-mlist">${xs.map(p => `<article class="bn-mitem" style="--c:${p.color}"><span class="bn-hico"><i class="fa-solid ${esc(p.icon)}"></i></span><div class="t"><b>${esc(p.title)}</b> ${p.active ? '<span class="bn-tag ok">Activo</span>' : '<span class="bn-tag">Pausado</span>'}<small>${p.start_date ? fmtD(p.start_date) : 'Sin inicio'} al ${p.end_date ? fmtD(p.end_date) : 'sin fin'} · ${esc(teamNames(p.audience))} · ${p.n_challenges} retos · ${p.n_surveys} encuestas</small></div><div class="bn-row"><button type="button" class="bn-ico" data-act="mg-pg-edit" data-id="${p.id}" aria-label="Editar"><i class="fa-regular fa-pen-to-square"></i></button><button type="button" class="bn-ico" data-act="mg-pg-del" data-id="${p.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></div></article>`).join('')}</div>` : empty('fa-seedling', 'Sin programas', 'Opcional: sirven para darle un hilo a varios retos y encuestas.')}`;
};
function pgEdit(p) {
  p = p || { title: '', description: '', icon: 'fa-seedling', color: '#22c55e', start_date: '', end_date: '', audience: [], active: true };
  const f = modal(p.id ? 'Editar programa' : 'Nuevo programa', `<input type="hidden" name="id" value="${p.id || ''}"><label class="bn-f"><span>Nombre</span><input name="title" maxlength="120" required value="${esc(p.title)}"></label>
    <label class="bn-f"><span>Descripción</span><textarea name="description" rows="2" maxlength="600">${esc(p.description)}</textarea></label>
    <div class="bn-two"><label class="bn-f"><span>Desde (opcional)</span><input type="date" name="start_date" value="${p.start_date || ''}"></label><label class="bn-f"><span>Hasta (opcional)</span><input type="date" name="end_date" value="${p.end_date || ''}"></label></div>
    ${audField(p.audience)}<div class="bn-two"><div class="bn-f"><span>Ícono</span><div class="bn-icons sm">${S.mg.icons.map(i => `<label><input type="radio" name="icon" value="${i}" ${p.icon === i ? 'checked' : ''}><i class="fa-solid ${i}"></i></label>`).join('')}</div></div><div class="bn-f"><span>Color</span><div class="bn-colors">${S.mg.colors.map(x => `<label><input type="radio" name="color" value="${x}" ${p.color === x ? 'checked' : ''}><i style="background:${x}"></i></label>`).join('')}</div></div></div>
    <label class="bn-switch"><input type="checkbox" name="active" ${p.active ? 'checked' : ''}><i></i> Activo</label>`, {
    onSubmit: async fm => { const r = await api('program_save', { id: +fm.id.value || 0, title: fm.title.value, description: fm.description.value, start_date: fm.start_date.value, end_date: fm.end_date.value, audience: audVal(fm), icon: (fm.querySelector('[name=icon]:checked') || {}).value, color: (fm.querySelector('[name=color]:checked') || {}).value, active: fm.active.checked }); S.mg.programs = r.programs; toast('Programa guardado'); MG.programas(); } });
  audToggle(f);
}

// ── Preferencias de avisos ───────────────────────────────
async function prefsOpen() {
  const d = await api('prefs_get');
  modal('Tus avisos de Bienestar', `<p class="bn-muted">Elige qué avisos de Bienestar quieres recibir en la campana.</p>${Object.entries(d.labels).map(([k, l]) => `<label class="bn-switch row"><input type="checkbox" name="${k}" ${d.prefs[k] ? 'checked' : ''}><i></i> ${esc(l)}</label>`).join('')}`, {
    onSubmit: async f => { const p = {}; Object.keys(d.labels).forEach(k => { p[k] = f[k].checked; }); await api('prefs_save', { prefs: p }); toast('Avisos guardados'); } });
}

// ═════════════════════════════ ACCIONES (delegación) ═════════════════════════════
const ACT = {
  'reload': () => route(),
  'modal-close': () => closeModal(),
  'mood-quick': async b => {
    modal('¿Cómo estás hoy?', moodForm(+b.dataset.v, 'bn-mood-form-m'), { foot: '', noForm: true });
  },
  'mood-pick': b => { const f = b.closest('form'); f.querySelectorAll('.bn-face-btn').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); }); f.mood.value = b.dataset.v; f.querySelector('[type=submit]').disabled = false; },
  'mood-reason': b => { const f = b.closest('form'), on = b.getAttribute('aria-pressed') !== 'true'; if (on && f.querySelectorAll('.bn-chip[aria-pressed=true]').length >= 3) { toast('Hasta 3 motivos.', true); return; } b.setAttribute('aria-pressed', on); b.classList.toggle('on', on); },
  'mood-days': b => { S.moodDays = +b.dataset.v; route(); },
  'habit-check': async b => {
    const r = await api('habit_check', { id: +b.dataset.id, done: b.dataset.done === '1' });
    if (b.dataset.done === '1') { celebrate(b); badgesToast(r.badges); if (r.habit && r.habit.streak > 1 && !r.badges.length) toast(`🔥 ¡${r.habit.streak} ${r.habit.streak_unit} de racha!`); }
    S.home = null; route();
  },
  'habit-day': async b => { const r = await api('habit_check', { id: +b.dataset.id, day: b.dataset.day, done: b.dataset.done === '1' }); badgesToast(r.badges); route(); },
  'habit-new': () => openHabit(null),
  'habit-tpl': b => { const s = S.habits.suggested.find(x => x.id === +b.dataset.id); openHabit({ tpl_id: s.id, title: s.title, icon: s.icon, color: s.color, freq: s.freq, target: s.target, goal: s.hint }); },
  'habit-edit': b => openHabit(S.habits.habits.find(h => h.id === +b.dataset.id)),
  'habit-archive': async b => { await api('habit_archive', { id: +b.dataset.id, archive: true }); toast('Hábito archivado. Lo puedes reactivar cuando quieras.'); route(); },
  'habit-unarchive': async b => { await api('habit_archive', { id: +b.dataset.id, archive: false }); route(); },
  'habit-delete': async b => { if (!confirm('¿Borrar este hábito y todo su historial? No se puede deshacer.')) return; await api('habit_delete', { id: +b.dataset.id }); route(); },
  'hf-freq': b => { const f = b.closest('form'); f.freq.value = b.dataset.v; b.parentNode.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); f.querySelector('.hf-daily').hidden = b.dataset.v !== 'daily'; f.querySelector('.hf-weekly').hidden = b.dataset.v !== 'weekly'; },
  'ch-join': async b => {
    const c = S.chs.find(x => x.id === +b.dataset.id);
    if (c && c.ranking) {
      modal('Sumarme a "' + c.title + '"', '<p>Este reto tiene ranking. ¿Quieres aparecer?</p><label class="bn-switch"><input type="checkbox" name="rk" checked><i></i> Aparecer en el ranking</label>', { submit: 'Sumarme', onSubmit: async f => { await api('ch_join', { id: c.id, in_ranking: f.rk.checked }); toast('¡Te sumaste! 💪'); route(); } });
      return;
    }
    await api('ch_join', { id: +b.dataset.id }); celebrate(b); toast('¡Te sumaste! 💪'); route();
  },
  'ch-leave': async b => { if (!confirm('¿Salir del reto? Se borra lo que cargaste.')) return; await api('ch_leave', { id: +b.dataset.id }); toast('Saliste del reto.'); route(); },
  'ch-log': b => {
    const c = S.chs.find(x => x.id === +b.dataset.id);
    modal('Sumar avance', `<p class="bn-muted">${esc(c.title)}</p><label class="bn-f"><span>¿Cuánto? (${esc(c.unit)})</span><input type="number" name="amount" min="1" max="100000" required value="1"></label><label class="bn-f"><span>Nota (opcional)</span><input name="note" maxlength="140"></label>`, {
      submit: 'Sumar', onSubmit: async f => { const r = await api('ch_log', { id: c.id, amount: +f.amount.value, note: f.note.value }); badgesToast(r.badges); if (!r.badges.length) toast('¡Sumado! 🎉'); route().then(() => celebrate(view().querySelector(`.bn-chc[data-id="${c.id}"] .bn-goal`))); } });
  },
  'ch-mylog': async b => {
    const id = +b.dataset.id, d = await api('ch_mylog', { id });
    modal('Mis avances', d.items.length ? `<ul class="bn-mini">${d.items.map(l => `<li><span class="t"><b>+${l.amount}</b> · ${fmtLong(l.day)}${l.note ? `<small>${esc(l.note)}</small>` : ''}</span><button type="button" class="bn-ico" data-act="ch-log-del" data-id="${l.id}" aria-label="Borrar"><i class="fa-regular fa-trash-can"></i></button></li>`).join('')}</ul>` : '<p class="bn-muted">Todavía no cargaste avances.</p>');
  },
  'ch-log-del': async b => { await api('ch_log_delete', { id: +b.dataset.id }); closeModal(); toast('Avance borrado'); route(); },
  'kudos-new': () => kudosNew(),
  'pick-person': b => { const f = b.closest('form'); f.to.value = b.dataset.id; f.querySelectorAll('.bn-person').forEach(x => x.classList.toggle('on', x === b)); },
  'feed-filter': b => { S.feed.filter = b.dataset.v; route(); },
  'feed-more': async b => { b.disabled = true; await loadFeed(false); },
  'kudos-react': async b => {
    const id = +b.dataset.id, r = await api('kudos_react', { id, emoji: b.dataset.e });
    const box = b.closest('.bn-reacts'), rx = {}; r.reactions.forEach(x => { rx[x.emoji] = +x.cnt; });
    box.querySelectorAll('button').forEach(x => { const on = x.dataset.e === r.my_reaction; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on); x.querySelector('b').textContent = rx[x.dataset.e] || ''; });
    if (r.my_reaction) celebrate(b);
  },
  'kudos-replies': b => { const r = b.closest('.bn-kudo').querySelector('.bn-replies'); r.hidden = !r.hidden; if (!r.hidden) r.querySelector('input').focus(); },
  'kudos-thank': async b => { await api('kudos_thank', { id: +b.dataset.id }); b.outerHTML = '<span class="bn-tag ok">Le diste las gracias 💛</span>'; },
  'kudos-delete': async b => { if (!confirm('¿Borrar este reconocimiento? También se borra de Comunidad.')) return; await api('kudos_delete', { id: +b.dataset.id }); b.closest('.bn-kudo').remove(); toast('Reconocimiento borrado'); },
  'load-set': async b => { await api('load_set', { level: +b.dataset.v }); b.parentNode.querySelectorAll('.bn-level').forEach(x => x.classList.toggle('on', x === b)); celebrate(b); toast('¡Gracias! Lo anotamos para esta semana.'); S.home = null; if (S.view === 'carga') route(); },
  'leave-new': async () => { await ensureMeta(); leaveNew(); },
  'leave-cancel': async b => { if (!confirm('¿Cancelar este pedido?')) return; await api('leave_cancel', { id: +b.dataset.id }); toast('Pedido cancelado'); S.home = null; route(); },
  'leave-decide': b => {
    const ok = b.dataset.v === '1';
    modal(ok ? 'Aprobar pedido' : 'Rechazar pedido', `<label class="bn-f"><span>Comentario para la persona ${ok ? '(opcional)' : ''}</span><input name="note" maxlength="200" ${ok ? '' : 'placeholder="Ej.: esa semana cerramos el proyecto, ¿podemos moverlo?"'}></label>${ok ? '<p class="bn-fine">Al aprobar, la ausencia se anota en el calendario de Trabajo y se recalcula la capacidad del equipo.</p>' : ''}`, {
      submit: ok ? 'Aprobar' : 'Rechazar', onSubmit: async f => { await api('leave_decide', { id: +b.dataset.id, approve: ok, note: f.note.value }); toast(ok ? 'Pedido aprobado ✓' : 'Pedido rechazado'); S.home = null; route(); } });
  },
  'cal-move': async b => { const [y, m] = S.calMonth.split('-').map(Number), d = new Date(y, m - 1 + +b.dataset.v, 1); S.calMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; await drawCal(); },
  'ins-days': b => { S.insights.days = +b.dataset.v; MG.resumen(); },
  'mg-ch-edit': b => chEdit(S.mg.challenges.find(c => c.id === +b.dataset.id)),
  'mg-ch-st': async b => { const v = b.dataset.v; if (v === 'delete' && !confirm('¿Borrar este reto?')) return; if (v === 'archived' && !confirm('¿Archivar? Deja de verse para el equipo.')) return; const r = await api('ch_status', { id: +b.dataset.id, to: v }); S.mg.challenges = r.challenges; toast({ published: 'Reto publicado: le avisamos al equipo 📣', archived: 'Reto archivado', delete: 'Reto borrado' }[v] || 'Listo'); MG.retos(); },
  'ch-img-clear': b => { const f = b.closest('form'); f.image.value = ''; f.file.value = ''; $('#bn-upl-prev').innerHTML = '<i class="fa-regular fa-image"></i>'; },
  'mg-sv-edit': b => svEdit(S.mg.surveys.find(s => s.id === +b.dataset.id)),
  'mg-sv-st': async b => { const v = b.dataset.v; if (v === 'delete' && !confirm('¿Borrar esta encuesta?')) return; if (v === 'closed' && !confirm('¿Cerrar la encuesta? Si es periódica, no se crea la siguiente.')) return; const r = await api('sv_status', { id: +b.dataset.id, to: v }); S.mg.surveys = r.surveys; toast({ published: 'Encuesta publicada: le avisamos al equipo 📣', closed: 'Encuesta cerrada', delete: 'Encuesta borrada' }[v]); MG.encuestas(); },
  'sv-tpl': b => {
    const t = S.mg.templates[b.dataset.v], f = b.closest('form');
    f.title.value = t.title; f.intro.value = t.intro; f.category.value = t.category;
    $('#bn-qes').innerHTML = t.questions.map(q => qRow({ ...q, options: q.options || [], required: q.required !== false, scale_max: q.scale_max || 5 })).join('');
    f.querySelectorAll('[data-act=sv-tpl]').forEach(x => x.classList.toggle('on', x === b));
  },
  'qe-add': () => { $('#bn-qes').insertAdjacentHTML('beforeend', qRow()); const all = $('#bn-qes').querySelectorAll('.qe-text'); all[all.length - 1].focus(); },
  'qe-del': b => { const qs = $('#bn-qes'); if (qs.querySelectorAll('.bn-qe').length > 1) b.closest('.bn-qe').remove(); else toast('La encuesta necesita al menos una pregunta.', true); },
  'qe-up': b => { const q = b.closest('.bn-qe'); if (q.previousElementSibling) q.parentNode.insertBefore(q, q.previousElementSibling); },
  'mg-pg-edit': b => pgEdit(S.mg.programs.find(p => p.id === +b.dataset.id)),
  'mg-pg-del': async b => { if (!confirm('¿Borrar el programa? Sus retos y encuestas siguen, sin programa.')) return; const r = await api('program_delete', { id: +b.dataset.id }); S.mg.programs = r.programs; MG.programas(); },
};
document.addEventListener('click', async ev => {
  const b = ev.target.closest('[data-act]'); if (!b || !ACT[b.dataset.act]) return;
  if (b.tagName === 'A') return;
  ev.preventDefault();
  if (b.dataset.busy) return; b.dataset.busy = '1';
  try { await ACT[b.dataset.act](b); } catch (e) { if (!e.api) { console.error(e); toast('Algo salió mal.', true); } }
  delete b.dataset.busy;
});
document.addEventListener('change', ev => {
  if (ev.target.id === 'bn-feed-cat') { S.feed.cat = ev.target.value; loadFeed(true); }
});
document.addEventListener('submit', async ev => {
  const f = ev.target;
  if (f.id === 'bn-mood-form' || f.id === 'bn-mood-form-m') {
    ev.preventDefault();
    const reasons = [...f.querySelectorAll('.bn-chip[aria-pressed=true]')].map(x => x.dataset.v);
    const btn = f.querySelector('[type=submit]'); btn.disabled = true;
    try { await api('mood_set', { mood: +f.mood.value, reasons }); if (f.id === 'bn-mood-form-m') closeModal(); toast('¡Gracias por contarlo! 💛'); S.home = null; route(); } catch (e) { btn.disabled = false; }
  } else if (f.id === 'bn-survey') {
    ev.preventDefault();
    const ans = {}; let miss = null;
    f.querySelectorAll('.bn-qq').forEach(q => {
      const id = q.dataset.q, t = q.dataset.type;
      let v = t === 'multi' ? [...q.querySelectorAll('input:checked')].map(i => +i.value) : t === 'text' ? q.querySelector('textarea').value.trim() : ((q.querySelector('input:checked') || {}).value ?? '');
      if (t === 'scale' || t === 'single') v = v === '' ? '' : +v;
      const emp = v === '' || (Array.isArray(v) && !v.length);
      if (emp && q.dataset.req === '1' && !miss) miss = q;
      q.classList.toggle('miss', emp && q.dataset.req === '1');
      if (!emp) ans[id] = v;
    });
    if (miss) { miss.scrollIntoView({ block: 'center', behavior: 'smooth' }); toast('Faltan preguntas obligatorias.', true); return; }
    const btn = f.querySelector('[type=submit]'); btn.disabled = true;
    try { await api('survey_submit', { id: +f.dataset.id, answers: ans }); toast('¡Gracias! Tu respuesta quedó guardada 💜'); S.home = null; location.hash = '#encuestas'; } catch (e) { btn.disabled = false; }
  } else if (f.classList.contains('bn-reply-f')) {
    ev.preventDefault();
    const body = f.body.value.trim(); if (!body) return;
    const r = await api('kudos_reply', { id: +f.dataset.id, body }).catch(() => null);
    if (r) { f.insertAdjacentHTML('beforebegin', replyHtml(r.reply)); f.body.value = ''; }
  }
});
document.addEventListener('input', ev => {
  const q = ev.target.closest('#bn-survey'); if (!q) return;
  const all = q.querySelectorAll('.bn-qq'), done = [...all].filter(x => x.querySelector('input:checked') || (x.querySelector('textarea') && x.querySelector('textarea').value.trim())).length;
  $('#bn-sv-prog').style.width = Math.round(done / all.length * 100) + '%';
  const qq = ev.target.closest('.bn-qq'); if (qq) qq.classList.remove('miss');
});

// ── Arranque ─────────────────────────────────────────────
(function boot() {
  const d = new Date(); $('#bn-date').textContent = d.toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'long' });
  $('#bn-prefs').addEventListener('click', () => prefsOpen().catch(() => {}));
  $('#bn-thank').addEventListener('click', () => kudosNew().catch(() => {}));
  window.addEventListener('hashchange', route);
  route();
})();
