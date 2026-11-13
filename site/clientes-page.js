/* clientes-page.js — Lo que la página Clientes (clientes.php) toma del panel Admin. Antes era un <script> en línea (CSP estricta, SEC-24). */
// Lo que admin_clientes.js toma del panel Admin
function esc(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
const $id = (id) => document.getElementById(id);
function ago(dt) {
  if (!dt) return '—';
  const s = (Date.now() - (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T'))).getTime()) / 1000;
  if (s < 60) return 'recién'; if (s < 3600) return Math.floor(s / 60) + ' min'; if (s < 86400) return Math.floor(s / 3600) + ' h';
  if (s < 86400 * 30) return Math.floor(s / 86400) + ' d';
  return (window.G7Time && G7Time.parse(dt) || new Date(dt.replace(' ', 'T'))).toLocaleDateString('es-AR', window.G7Time ? { timeZone: G7Time.cfg.tz } : {});
}
// Sin onchange en línea (CSP estricta): quien lo necesite engancha el evento al elemento con ese id.
function toggle(id, checked) {
  return `<label class="adm-toggle"><input type="checkbox" id="${id}" ${checked ? 'checked' : ''}><div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div></label>`;
}
function admToast(msg, isErr = false) {
  const t = $id('adm-toast'); t.textContent = msg;
  t.style.background = isErr ? '#ef4444' : '#22c55e'; t.style.color = isErr ? '#fff' : '#000';
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(t._h); t._h = setTimeout(() => t.style.transform = 'translateX(-50%) translateY(60px)', 2200);
}
const SECTION_LOADERS = {};
document.addEventListener('DOMContentLoaded', () => {
  const id = +new URLSearchParams(location.search).get('id');
  if (id) cliOpen(id).catch(() => cliLoad()); else cliLoad();
});
