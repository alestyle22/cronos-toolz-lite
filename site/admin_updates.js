/* admin_updates.js — Sección "Actualizaciones" del panel Admin. Usa esc(), $id(), accApi() y admToast() del panel. */
'use strict';

const UPD = { data: null, plan: null, busy: false };
const updNewer = (a, b) => { const x = String(a || '0').split('.').map(Number), y = String(b || '0').split('.').map(Number); for (let i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); } return false; };
const updLatest = () => UPD.data?.releases?.[0]?.version || '';

async function updLoad() {
  if (UPD.busy) return;
  try { UPD.data = await accApi('upd_state'); updDraw(); }
  catch (e) { $id('upd-body').innerHTML = `<div class="adm-card"><div class="upd-log">No se pudo leer el estado: ${esc(e.message)}</div></div>`; }
}
function updSiteRow(s, i) {
  const latest = updLatest(), sites = UPD.data.sites;
  let tag = '', acts = '';
  if (s.unreachable) tag = '<span class="upd-tag bad"><i class="fa-solid fa-plug-circle-xmark"></i> Sin conexión</span>';
  else if (s.interrupted) tag = `<span class="upd-tag bad"><i class="fa-solid fa-triangle-exclamation"></i> Quedó a medias (${esc(s.interrupted)})</span>`;
  else if (!s.version) tag = '<span class="upd-tag new">Sin versión instalada</span>';
  else if (latest && updNewer(latest, s.version)) tag = `<span class="upd-tag new"><i class="fa-solid fa-arrow-up"></i> Hay ${esc(latest)}</span>`;
  else tag = '<span class="upd-tag ok"><i class="fa-solid fa-check"></i> Al día</span>';
  if (!s.unreachable) {
    const blocked = sites.slice(0, i).some(x => !x.version || updNewer(latest, x.version));
    if (latest && !s.interrupted && (!s.version || updNewer(latest, s.version))) {
      acts += blocked ? `<span class="acc-muted" title="Cada sitio espera al anterior">Espera a ${esc(sites.slice(0, i).find(x => !x.version || updNewer(latest, x.version)).name)}</span>`
        : `<button class="btn btn-primary btn-sm" data-on-click="updPreview" data-click-1="s:${esc(s.id)}" data-click-2="s:${esc(latest)}">Revisar e instalar</button>`;
    }
    if (s.can_rollback || s.interrupted) acts += `<button class="btn btn-ghost btn-sm" data-on-click="updRollback" data-click-1="s:${esc(s.id)}" title="Deja los archivos como estaban. La base de datos no vuelve atrás."><i class="fa-solid fa-rotate-left"></i> Volver${s.rollback_to ? ' a ' + esc(s.rollback_to) : ' atrás'}</button>`;
  }
  return `<div class="upd-row"><div><b>${esc(s.name)}${s.self ? ' <small style="display:inline">(este sitio)</small>' : ''}</b><small>${i === 0 && sites.length > 1 ? 'Se actualiza primero · ' : ''}${s.php ? 'PHP ' + esc(s.php) : esc(s.unreachable || '')}${s.propios ? ' · ' + s.propios + ' archivos propios' : ''}</small></div>
    <div class="upd-ver">${esc(s.version || '—')}</div><div>${tag}</div><div class="upd-act">${acts}</div></div>`;
}
function updPlanHtml() {
  const u = UPD.plan; if (!u) return '';
  const p = u.plan, list = (t, a, warn) => a.length ? `<details${warn ? ' open' : ''}><summary class="${warn ? 'upd-warn' : ''}">${a.length} ${t}</summary><ul>${a.map(x => `<li>${esc(x)}</li>`).join('')}</ul></details>` : '';
  const none = !p.new.length && !p.change.length && !p.remove.length;
  return `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-list-check"></i> Versión ${esc(u.version)} en ${esc(u.name)}</div></div>
    <div class="upd-plan"><div>${esc(u.notes)}</div>
      <div style="margin-top:8px">${esc(p.same)} archivos ya están iguales.${none ? ' No hay archivos para cambiar.' : ''}</div>
      ${list('cambiados a mano en este sitio: se pisan, y queda un respaldo', p.edited, true)}
      ${list('archivos para reemplazar', p.change)}${list('archivos nuevos', p.new)}${list('archivos para borrar', p.remove, p.remove.length > 0)}${list('archivos propios de este sitio, no se tocan', p.kept)}
      <div style="margin-top:12px;display:flex;gap:8px"><button class="btn btn-primary" id="upd-go" data-on-click="updApply"><i class="fa-solid fa-download"></i> Instalar ${esc(u.version)}</button><button class="btn btn-ghost" data-on-click="updBack">Cancelar</button></div>
    </div></div>`;
}
function updDraw() {
  const d = UPD.data; if (!d) return;
  const logs = d.sites.flatMap(s => (s.history || []).map(h => ({ ...h, site: s.name }))).sort((a, b) => String(b.at).localeCompare(String(a.at))).slice(0, 12);
  $id('upd-body').innerHTML = `${d.configured ? '' : '<div class="adm-card"><div class="upd-log upd-warn">Este sitio todavía no tiene su configuración (_private/site.php). Mira toolz/ACTUALIZACIONES.md.</div></div>'}
    ${d.catalog_error ? `<div class="adm-card"><div class="upd-log upd-warn">${esc(d.catalog_error)}</div></div>` : ''}
    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-server"></i> Sitios</div><button class="btn btn-ghost btn-sm" data-on-click="updLoad"><i class="fa-solid fa-rotate"></i> Actualizar estado</button></div>${d.sites.map(updSiteRow).join('')}</div>
    ${updPlanHtml()}
    ${d.linked && !d.central ? `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-link"></i> Conexión con el sitio base</div></div><div class="upd-plan">Este sitio ya está conectado a <b>${esc(d.central_url)}</b>. Por seguridad, el código de conexión ya no se muestra.</div></div>` : ''}
    ${d.connect_code ? `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-link"></i> Conexión con el sitio base</div></div><div class="upd-plan">Las versiones llegan de <b>${esc(d.central_url)}</b>. Si allá todavía no figura este sitio, pega este código en su panel › Agregar sitio. Es secreto: no lo compartas. Cuando el sitio base se conecte, el código deja de mostrarse acá.
      <details style="margin-top:8px"><summary>Ver el código</summary><textarea readonly data-on-click="@select" style="width:100%;min-height:80px;margin-top:6px;font:11px var(--g7-font-mono,monospace);background:var(--g7-bg-4);color:var(--g7-text);border:1px solid var(--g7-border-3);border-radius:8px;padding:8px">${esc(d.connect_code)}</textarea></details></div></div>` : ''}
    <div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-tag"></i> Versiones publicadas</div></div>${d.releases.map(r => `<div class="upd-rel"><b>${esc(r.version)}</b><small>${esc(r.date)} · ${esc(r.files)} archivos</small><div>${esc(r.notes)}</div></div>`).join('') || '<div class="upd-log">Todavía no se publicó ninguna versión.</div>'}</div>
    ${logs.length ? `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-clock-rotate-left"></i> Historial</div></div>${logs.map(h => `<div class="upd-log">${esc(h.at)} · <b>${esc(h.site)}</b> ${esc(h.action)} ${esc(h.version)}${h.detail ? ' · ' + esc(h.detail) : ''}</div>`).join('')}</div>` : ''}`;
}
async function updPreview(site, version) {
  // Revisar baja el paquete (~14 MB) y lo compara archivo por archivo: puede tardar. Que se note que está trabajando.
  if (UPD.busy) return;
  const btn = this instanceof Element ? this : null, html = btn ? btn.innerHTML : '';
  UPD.busy = true;
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Revisando… baja el paquete'; }
  const slow = setTimeout(() => { if (btn && btn.isConnected) btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sigue revisando… puede tardar hasta 2 min'; }, 12000);
  try {
    const r = await accApi('upd_run', { op: 'preview', site, version });
    UPD.plan = { ...r, site, name: UPD.data.sites.find(s => s.id === site)?.name || site };
    UPD.busy = false;
    updDraw();
    const go = $id('upd-go');
    if (go) { go.closest('.adm-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); go.focus({ preventScroll: true }); }
  } catch (e) {
    UPD.busy = false;
    if (btn && btn.isConnected) { btn.disabled = false; btn.innerHTML = html; }
    const row = btn && btn.closest('.upd-row');
    if (row) { row.querySelector('.upd-err')?.remove(); row.insertAdjacentHTML('beforeend', `<div class="upd-err"><i class="fa-solid fa-triangle-exclamation"></i> No se pudo revisar: ${esc(e.message || 'error desconocido')}</div>`); }
  } finally { clearTimeout(slow); }
}
async function updApply() {
  const u = UPD.plan; if (!u || UPD.busy) return;
  UPD.busy = true;
  const b = $id('upd-go'); if (b) { b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Instalando… no cierres esta página'; }
  try { await accApi('upd_run', { op: 'apply', site: u.site, version: u.version }); admToast(`${u.name} quedó en ${u.version}`); UPD.plan = null; } catch (e) {}
  UPD.busy = false;
  updLoad();
}
async function updRollback(site) {
  const s = UPD.data.sites.find(x => x.id === site); if (UPD.busy || !s) return;
  if (!confirm(`¿Volver ${s.name} a ${s.rollback_to || 'como estaba antes'}? Los archivos quedan como antes de la última actualización. La base de datos no vuelve atrás.`)) return;
  UPD.busy = true;
  try { const r = await accApi('upd_run', { op: 'rollback', site }); admToast(`${s.name} volvió a ${r.version || 'la versión anterior'}`); UPD.plan = null; } catch (e) {}
  UPD.busy = false;
  updLoad();
}
SECTION_LOADERS.updates = updLoad;

// ── Eventos que antes eran código en línea (CSP estricta) ──
function updBack() { UPD.plan = null; updDraw(); }
