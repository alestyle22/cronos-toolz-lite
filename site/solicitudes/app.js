/* solicitudes/app.js — Arranque del Portal de Solicitudes como módulo propio (solicitudes/index.php).
   Trae las piezas chicas de trabajo.js que usan portal.js y portal_admin.js ($, esc, api, toast, av, …) sin cargar Gestión de trabajo.
   Todo pedido va a solicitudes/portal_api.php, que es el mismo portal_api.php de trabajo/ con el permiso de este módulo. */
'use strict';

const W = { me: null, users: [], teams: [] };
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MON = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

async function api(action, data = {}, url = 'portal_api.php') {
  const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) });
  let j;
  try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió algo inesperado. Prueba de nuevo.'); }
  if (r.status === 401) { location.href = '/toolz/login.php'; throw new Error(j.error); }
  if (!r.ok || j.error) { const e = new Error(j.error || 'No se pudo completar la acción'); e.data = j; e.status = r.status; throw e; }
  return j;
}
let toastT;
function toast(msg, err = false) {
  const t = $('wk-toast'); t.textContent = msg; t.classList.toggle('err', err); t.classList.add('show');
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), err ? 4200 : 2400);
}
// Avatar que no carga: pasa a la inicial (sin onerror en línea por la CSP estricta)
document.addEventListener('error', e => {
  const t = e.target;
  if (!t || t.tagName !== 'IMG' || !t.hasAttribute('data-wk-ini')) return;
  t.replaceWith(Object.assign(document.createElement('span'), { className: 'wk-av', textContent: t.getAttribute('data-wk-ini'), style: `width:${t.style.width};height:${t.style.height};background:#ff8a3d` }));
}, true);
function av(u, size = 26) {
  if (!u) return `<span class="wk-av" style="width:${size}px;height:${size}px;background:var(--g7-bg-5)"></span>`;
  if (u.avatar) return `<img class="wk-av" style="width:${size}px;height:${size}px" src="${esc(u.avatar)}" alt="" data-wk-ini="${esc((u.name || '?')[0].toUpperCase())}">`;
  const hue = (u.id * 47) % 360;
  return `<span class="wk-av" style="width:${size}px;height:${size}px;background:hsl(${hue} 70% 65%)">${esc((u.name || '?')[0].toUpperCase())}</span>`;
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
const emptyBox = (icon, txt) => `<div class="wk-empty"><i class="fa-solid ${icon}"></i>${txt}</div>`;

// Secciones: portal.js y portal_admin.js completan SECS (solicitudes, gestion, portal-config, portal-metricas).
// El historial de solicitudes de antes del portal sigue en Gestión de trabajo.
const SECS = {
  solicitudes: (a, b) => { location.href = '/toolz/trabajo/#solicitudes/' + [a, b].filter(Boolean).join('/'); return new Promise(() => {}); },
};
function route() {
  const [sec, arg, arg2] = (location.hash.slice(1) || 'solicitudes').split('/');
  const link = document.querySelector(`#wk-nav [data-sec="${sec}"]`);
  const fn = SECS[sec] && link && !link.hidden ? SECS[sec] : SECS.solicitudes;
  const cur = SECS[sec] === fn ? sec : 'solicitudes';
  const key = cur === 'solicitudes' && arg === 'mias' ? 'solicitudes/mias' : cur;
  document.querySelectorAll('#wk-nav a[data-sec]').forEach(a => { const on = (a.dataset.key || a.dataset.sec) === key; a.classList.toggle('on', on); on ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'); });
  $('wk-main').dataset.sec = cur;
  $('wk-main').innerHTML = '<div class="wk-sk"></div><div class="wk-sk"></div><div class="wk-sk"></div>';
  (cur === sec ? fn(arg, arg2) : fn()).catch(e => { $('wk-main').innerHTML = emptyBox('fa-triangle-exclamation', `<b>No se pudo cargar</b><br>${esc(e.message)}`); });
}

// Arranque: cuando ya cargaron portal.js y portal_admin.js (van después en la página)
document.addEventListener('DOMContentLoaded', async () => {
  let b;
  try { b = await ptLoadBoot(); } catch (e) { $('wk-main').innerHTML = emptyBox('fa-triangle-exclamation', `<b>No se pudo cargar</b><br>${esc(e.message)}`); return; }
  W.me = b.me; W.users = b.people || []; W.teams = b.teams || [];
  const me = W.users.find(u => u.id === b.me.id);
  if (me) $('wk-me').innerHTML = `<b>${esc(me.name)}</b>`;
  addEventListener('hashchange', route);
  // portal.js ya mostró las secciones de su rol y, si la dirección era una de ellas, la abrió
  if (!['gestion', 'portal-config', 'portal-metricas'].includes(location.hash.slice(1).split('/')[0])) route();
});
