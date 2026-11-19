/* admin_base.js — Admin › Red Cronos: el sitio base con la interfaz de Toolz (admin_base.php). Usa esc(), $id() y admToast() del panel.
   Todo pasa por base_api.php, que firma cada pedido en el servidor. Doc: toolz/BASE.md */
'use strict';

const BZ = { d: null, loading: null, plan: null, busy: false, focus: null, editPlan: null, bn: null, tk: null, tkOpen: null, shots: {},
  tkf: { area: '', type: '', status: 'abiertos', site: '', q: '' } };
const BZ_SECS = { 'base-sitios': bzDrawSitios, 'base-licencias': bzDrawLics, 'base-planes': bzDrawPlans, 'base-banners': bzDrawBanners, 'base-herramientas': bzDrawTools, 'base-tickets': bzDrawTickets, 'base-red': bzDrawRed };
const BZ_LIC_TAG = { ok: ['Licencia activa', 'ok'], aviso: ['Por vencer', 'warn'], gracia: ['Vencida (gracia)', 'warn'], solo_lectura: ['Solo lectura', 'bad'], suspendida: ['Suspendida', 'bad'], alterada: ['Licencia alterada', 'bad'] };
const BZ_PLACE = { dashboard: 'Inicio', admin: 'Admin' }, BZ_STYLE = { novedad: 'Novedad', aviso: 'Aviso', promo: 'Promo' };
const BZ_AREAS = { comercial: ['Comercial', ['demo', 'plan']], soporte: ['Soporte', ['mejora', 'problema']] }, BZ_OPEN = ['nuevo', 'en_curso', 'esperando'];

// ── Utilidades ──
const bzNewer = (a, b) => { const x = String(a || '0').split('.').map(Number), y = String(b || '0').split('.').map(Number); for (let i = 0; i < 3; i++) if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) > (y[i] || 0); return false; };
const bzGb = b => ((+b || 0) / 1073741824).toLocaleString('es-AR', { maximumFractionDigits: b < 1073741824 ? 2 : 1 });
const bzNum = n => (+n || 0).toLocaleString('es-AR');
const bzDate = d => d ? d.split('-').reverse().join('/') : 'sin vencimiento';
const bzDays = d => d ? Math.floor((new Date(d + 'T23:59:59') - new Date()) / 864e5) : null;
const bzSite = id => BZ.d?.sites.find(s => s.id === id);
const bzCard = (title, icon, body, extra = '') => `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid ${icon}"></i> ${title}</div>${extra}</div>${body}</div>`;
function bzAgo(dt) {
  if (!dt) return '—'; const t = new Date(String(dt).replace(' ', 'T')), s = (Date.now() - t) / 1000;
  if (s < 3600) return 'hace ' + Math.max(1, Math.round(s / 60)) + ' min'; if (s < 86400) return 'hace ' + Math.round(s / 3600) + ' h';
  if (s < 86400 * 45) return 'hace ' + Math.round(s / 86400) + ' d'; return t.toLocaleDateString('es-AR');
}
async function bzApi(body) {
  let r, d;
  try { r = await fetch('/toolz/base_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': window.BZ_CSRF }, body: JSON.stringify(body) }); }
  catch (e) { d = { error: 'Sin conexión con Toolz.' }; }
  if (r) d = await r.json().catch(() => ({ error: `Respuesta inválida (${r.status}).` }));
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
function bzSec() { const id = document.querySelector('.adm-section.active')?.id.replace('sec-', ''); return BZ_SECS[id] ? id : null; }
function bzDraw() {
  const nb = BZ.d?.tickets_unread || 0, b = $id('bz-tk-badge'); if (b) { b.textContent = nb; b.style.display = nb ? '' : 'none'; }
  const sec = bzSec(); if (!sec) return;
  const box = $id('bz-' + sec.replace('base-', ''));
  if (!BZ.d) { box.innerHTML = bzCard('Sitio base', 'fa-server', `<div class="bz-msg">${BZ.err ? `<span class="bz-bad">${esc(BZ.err)}</span> Prueba con <b>Actualizar</b> o abre el panel del base.` : '<i class="fa-solid fa-spinner fa-spin"></i> Consultando a cada sitio de la red…'}</div>`); return; }
  box.innerHTML = BZ_SECS[sec]();
  if (sec === 'base-tickets' && !BZ.tk) bzTkLoad();
  if (sec === 'base-herramientas' && !BZ.tl) bzTlLoad();
}
async function bzLoad(force) {
  if (BZ.loading) return BZ.loading;
  if (BZ.d && !force) { bzDraw(); return; }
  if (force && BZ.d) admToast('Consultando a los sitios…');
  if (force) { BZ.tk = null; BZ.tl = null; }
  BZ.err = ''; if (!BZ.d) bzDraw();
  BZ.loading = bzApi({ act: 'state' }).then(d => { BZ.d = d; }).catch(e => { BZ.err = e.message; }).finally(() => { BZ.loading = null; bzDraw(); });
  return BZ.loading;
}
function bzGo(sec) { admShow(sec); }
Object.keys(BZ_SECS).forEach(k => { SECTION_LOADERS[k] = () => bzLoad(false); });

// ── Sitios y versiones ──
function bzUse(s) {
  const lim = BZ.d.limits[s.id], u = s.license?.usage;
  if (!lim || !lim.plan) return s.license ? '<div class="bz-use">Sin licencia: sin límites</div>' : '';
  const part = (n, m, label) => `<span class="${m && n > m ? 'over' : ''}"><b>${n == null ? '—' : esc(n)}</b>${m ? '/' + esc(m) : ''} ${label}</span>`;
  const st = s.license?.state && BZ_LIC_TAG[s.license.state];
  return `<div class="bz-use"><span><b>${esc(lim.plan)}</b> · ${esc(bzDate(lim.expires))}</span>${st ? `<span class="bz-tag ${st[1]}">${st[0]}</span>` : ''}
    ${u ? part(u.users, lim.users, 'usuarios') + part(u.clients, lim.clients, 'clientes') + `<span class="${lim.storage_gb && u.bytes > lim.storage_gb * 1073741824 ? 'over' : ''}"><b>${bzGb(u.bytes)}</b>${lim.storage_gb ? '/' + lim.storage_gb : ''} GB</span>` : '<span>uso: falta la versión con licencias</span>'}
    ${BZ.d.licenses[s.id]?.push_error ? '<span class="bz-warn">Licencia sin entregar: se reintenta</span>' : ''}${s.lic_alert ? `<span class="bz-bad">${esc(s.lic_alert)}</span>` : ''}</div>`;
}
function bzDrawSitios() {
  const d = BZ.d, latest = d.releases?.[0]?.version || '';
  const rows = d.sites.map((s, i) => {
    let tag, acts = '';
    if (s.unreachable) tag = '<span class="bz-tag bad"><i class="fa-solid fa-plug-circle-xmark"></i> Sin conexión</span>';
    else if (s.interrupted) tag = `<span class="bz-tag bad"><i class="fa-solid fa-triangle-exclamation"></i> Quedó a medias (${esc(s.interrupted)})</span>`;
    else if (!s.version) tag = '<span class="bz-tag new">Sin versión</span>';
    else if (latest && bzNewer(latest, s.version)) tag = `<span class="bz-tag new"><i class="fa-solid fa-arrow-up"></i> Hay ${esc(latest)}</span>`;
    else tag = '<span class="bz-tag ok"><i class="fa-solid fa-check"></i> Al día</span>';
    const online = s.unreachable ? '' : s.stats ? (s.stats.db_ok ? '<span class="bz-tag ok">En línea</span>' : '<span class="bz-tag bad">La base no responde</span>') : '<span class="bz-tag">En línea</span>';
    if (!s.unreachable && latest && !s.interrupted && (!s.version || bzNewer(latest, s.version))) {
      const prev = d.sites.slice(0, i).find(x => !x.version || bzNewer(latest, x.version));
      acts += prev ? `<span class="bz-muted" title="Cada sitio espera al anterior de la lista">Espera a ${esc(prev.name)}</span>` : `<button class="btn btn-primary btn-sm" ${BZ.busy ? 'disabled' : ''} data-on-click="bzPreview" data-click-1="s:${esc(s.id)}" data-click-2="s:${esc(latest)}" data-click-3="this"><i class="fa-solid fa-magnifying-glass"></i> Revisar e instalar</button>`;
    }
    if (s.can_rollback || s.interrupted) acts += `<button class="btn btn-ghost btn-sm" ${BZ.busy ? 'disabled' : ''} data-on-click="bzRollback" data-click-1="s:${esc(s.id)}" title="Los archivos vuelven a como estaban; la base de datos no"><i class="fa-solid fa-rotate-left"></i> Volver${s.rollback_to ? ' a ' + esc(s.rollback_to) : ' atrás'}</button>`;
    acts += `<button class="btn-icon" title="Licencia" data-on-click="bzGoLic" data-click-1="s:${esc(s.id)}"><i class="fa-solid fa-id-card"></i></button>
      <button class="btn-icon" title="Subir en la lista" data-on-click="bzMove" data-click-1="s:${esc(s.id)}" data-click-2="n:-1"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button>
      <button class="btn-icon" title="Bajar en la lista" data-on-click="bzMove" data-click-1="s:${esc(s.id)}" data-click-2="n:1"${i < d.sites.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-arrow-down"></i></button>
      <button class="btn-icon" title="Quitar de la lista" data-on-click="bzRemove" data-click-1="s:${esc(s.id)}"><i class="fa-solid fa-xmark"></i></button>`;
    return `<div class="bz-row"><div><b>${esc(s.name)}</b><small>${i === 0 && d.sites.length > 1 ? 'Se actualiza primero · ' : ''}<a href="${esc(s.url)}" target="_blank" rel="noopener" style="color:inherit">${esc(String(s.url).replace(/^https?:\/\//, ''))}</a>${s.unreachable ? '<br><span class="bz-bad">' + esc(s.unreachable) + '</span>' : ''}${s.php ? ' · PHP ' + esc(s.php) : ''}</small>${bzUse(s)}</div>
      <div class="bz-ver">${esc(s.version || '—')}</div><div style="display:flex;gap:6px;flex-wrap:wrap">${tag}${online}</div><div class="bz-act">${acts}</div></div>`;
  }).join('') || '<div class="bz-msg">Todavía no hay sitios. Agrega uno con su código de conexión.</div>';
  return `${d.catalog_error ? `<div class="adm-card"><div class="bz-msg bz-warn">${esc(d.catalog_error)}</div></div>` : ''}
    ${bzCard('Sitios', 'fa-server', rows, `<span class="bz-muted">${d.sites.length} en la red · el primero hace de prueba</span>`)}
    ${bzAllHtml(latest)}
    ${bzPlanHtml()}
    ${bzCard('Agregar sitio', 'fa-plus', `<div class="adm-card-body"><div class="bz-muted" style="margin-bottom:8px">Pega el código de conexión que da el asistente al instalar (o Admin › Actualizaciones del sitio). Es secreto.</div>
      <textarea class="adm-input" id="bz-code" rows="3" placeholder="Código de conexión" style="font-family:var(--g7-font-mono,monospace);font-size:11px"></textarea>
      <div class="bz-foot"><button class="btn btn-primary" data-on-click="bzAdd" data-click-1="this"><i class="fa-solid fa-plus"></i> Agregar</button></div></div>`)}
    ${bzCard('Versiones publicadas', 'fa-tag', (d.releases || []).map(r => `<div class="bz-row" style="grid-template-columns:90px 1fr"><b class="bz-ver">${esc(r.version)}</b><div>${esc(r.notes)}<small>${esc(r.date)} · ${r.files} archivos</small></div></div>`).join('') || '<div class="bz-msg">Ninguna todavía.</div>')}`;
}
function bzPlanHtml() {
  const p = BZ.plan; if (!p) return '';
  if (p.loading) return bzCard(`Revisando ${esc(p.version)} en ${esc(p.name)}`, 'fa-list-check', '<div class="bz-plan"><i class="fa-solid fa-spinner fa-spin"></i> El sitio baja el paquete firmado y compara archivo por archivo…</div>');
  const x = p.plan, list = (t, a, w) => a.length ? `<details${w ? ' open' : ''}><summary class="${w ? 'bz-warn' : ''}">${a.length} ${t}</summary><ul>${a.map(f => `<li>${esc(f)}</li>`).join('')}</ul></details>` : '';
  const steps = ['Paquete firmado', 'Respaldo', 'Copiar archivos', 'Comprobar el sitio'];
  return bzCard(`Versión ${esc(p.version)} en ${esc(p.name)}`, 'fa-list-check', `<div class="bz-plan"><div>${esc(p.notes)}</div><div class="bz-muted" style="margin:8px 0">${esc(x.same)} archivos ya están iguales.</div>
    ${list('cambiados a mano en ese sitio: se pisan y queda respaldo', x.edited, true)}${list('para reemplazar', x.change)}${list('nuevos', x.new)}${list('para borrar', x.remove, x.remove.length > 0)}${list('propios del sitio, no se tocan', x.kept)}
    ${p.running ? `<div class="bz-prog" id="bz-prog"><div class="bz-prog-track"><i id="bz-prog-bar"></i></div><div class="bz-prog-txt"><span id="bz-prog-step">Instalando…</span><span id="bz-prog-time">0 s</span></div>
      <div class="bz-steps">${steps.map((s, i) => `<span data-i="${i}">${s}</span>`).join('')}</div><div class="bz-muted" style="margin-top:8px">No cierres esta página. Si el sitio no responde bien al final, vuelve solo a la versión anterior.</div></div>`
      : `<div class="bz-foot"><button class="btn btn-primary" data-on-click="bzApply"><i class="fa-solid fa-download"></i> Instalar ${esc(p.version)}</button><button class="btn btn-ghost" data-on-click="bzReset" data-click-1="s:plan" data-click-2="null">Cancelar</button></div>`}</div>`);
}
// ── Actualizar todos y actualización automática ──
// Va sitio por sitio en el orden de la lista con el mismo "apply" de a uno (el base comprueba que el anterior ya esté al día)
// y se frena en el primer problema: así un error no se reparte por toda la red.
function bzAllHtml(latest) {
  const d = BZ.d, pend = latest ? d.sites.filter(s => !s.version || bzNewer(latest, s.version)) : [], a = d.auto || {}, last = a.last, R = BZ.all;
  const icon = { updated: 'fa-circle-check" style="color:#22c55e', ok: 'fa-check" style="color:var(--g7-text-4)', stop: 'fa-circle-xmark" style="color:#f87171', run: 'fa-spinner fa-spin" style="color:var(--g7-accent)', wait: 'fa-clock" style="color:var(--g7-text-5)' };
  const steps = R ? R.steps : (last?.steps || []);
  const body = `<div class="adm-card-body">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <button class="btn btn-primary" data-on-click="bzApplyAll" ${BZ.busy || !pend.length ? 'disabled' : ''}><i class="fa-solid fa-forward"></i> ${pend.length ? `Actualizar todos a ${esc(latest)} (${pend.length})` : 'Todos al día'}</button>
      <label class="bz-muted" style="display:flex;gap:8px;align-items:center;cursor:pointer"><input type="checkbox" ${a.on ? 'checked' : ''} data-on-change="bzAutoSave" data-change-1="this"> Actualización automática</label>
    </div>
    <div class="bz-muted" style="margin-top:8px;line-height:1.5">Siempre en el orden de la lista y sitio por sitio: si uno no responde o su instalación falla (vuelve atrás solo), se frena y los siguientes quedan como estaban.
      ${a.on ? 'Con la automática prendida, el cron del base (<code>cron_update.php</code>) instala cada versión nueva sin esperar a nadie.' : ''}</div>
    ${steps.length ? `<div style="margin-top:12px;display:flex;flex-direction:column;gap:6px">${steps.map(x => `<div style="display:flex;gap:10px;align-items:baseline"><i class="fa-solid ${icon[x.st] || icon.ok}"></i><b>${esc(x.site)}</b><span class="bz-muted">${esc(x.msg)}</span></div>`).join('')}</div>` : ''}
    ${!R && last ? `<div class="bz-muted" style="margin-top:8px">Última corrida: ${esc(last.at)} (${esc(last.by)}) · ${({ ok: 'terminó bien', stopped: 'se frenó', nothing: 'no había nada para instalar', busy: 'había otra en curso' })[last.result] || esc(last.result)}</div>` : ''}
  </div>`;
  return bzCard('Actualizar toda la red', 'fa-forward', body);
}
async function bzApplyAll() {
  const d = BZ.d, latest = d.releases?.[0]?.version; if (!latest || BZ.busy) return;
  const pend = d.sites.filter(s => !s.version || bzNewer(latest, s.version));
  if (!pend.length || !confirm(`¿Instalar ${latest} en ${pend.length} sitio${pend.length > 1 ? 's' : ''}, uno por uno y en orden?\n\n${pend.map(s => '· ' + s.name + ' (' + (s.version || 'sin versión') + ')').join('\n')}\n\nSi uno falla, se frena ahí.`)) return;
  BZ.busy = true; BZ.all = { steps: d.sites.map(s => ({ site: s.name, id: s.id, st: pend.includes(s) ? 'wait' : 'ok', msg: pend.includes(s) ? 'En espera' : 'Ya estaba en ' + s.version })) }; bzDraw();
  let stopped = false, done = 0;
  for (const s of d.sites) {
    const step = BZ.all.steps.find(x => x.id === s.id);
    if (!pend.includes(s)) continue;
    if (stopped) { step.msg = 'No se tocó (se frenó antes)'; continue; }
    if (s.unreachable) { step.st = 'stop'; step.msg = 'No responde: se frena acá'; stopped = true; bzDraw(); continue; }
    if (s.interrupted) { step.st = 'stop'; step.msg = 'Quedó a medias: hay que volverlo atrás primero'; stopped = true; bzDraw(); continue; }
    step.st = 'run'; step.msg = 'Instalando ' + latest + '…'; bzDraw();
    const t0 = Date.now();
    try { await bzApi({ op: 'apply', site: s.id, version: latest }); step.st = 'updated'; step.msg = (s.version || 'sin versión') + ' → ' + latest + ' en ' + Math.round((Date.now() - t0) / 1000) + ' s'; done++; }
    catch (e) { step.st = 'stop'; step.msg = 'Falló: ' + (e.message || 'error') + '. Los siguientes quedan como estaban.'; stopped = true; }
    bzDraw();
  }
  admToast(stopped ? `Se frenó: ${done} actualizado${done === 1 ? '' : 's'}` : `Listo: ${done} sitio${done === 1 ? '' : 's'} en ${latest}`, stopped);
  BZ.busy = false; const keep = BZ.all; await bzLoad(true); BZ.all = keep; bzDraw();
}
async function bzAutoSave(cb) {
  if (cb.checked && !confirm('Con la actualización automática, cada versión nueva se instala sola en toda la red (en orden y frenando en el primer problema).\n\nHace falta un cron en el hosting del base que corra cron_update.php (por ejemplo, cada hora). ¿Prenderla?')) { cb.checked = false; return; }
  try { const r = await bzApi({ act: 'auto_save', on: cb.checked ? 1 : 0 }); BZ.d.auto = r.auto; admToast(cb.checked ? 'Actualización automática prendida' : 'Actualización automática apagada'); }
  catch (e) { cb.checked = !cb.checked; }
}
async function bzPreview(site, version, btn) {
  if (BZ.busy) return;
  BZ.plan = { site, version, name: bzSite(site)?.name || site, loading: true }; bzDraw();
  try { const r = await bzApi({ op: 'preview', site, version }); BZ.plan = { ...r, site, name: BZ.plan.name }; } catch (e) { BZ.plan = null; }
  bzDraw();
}
function bzProgress(t0) {
  const bar = $id('bz-prog-bar'); if (!bar) return;
  const s = (Date.now() - t0) / 1000, i = s < 6 ? 0 : s < 15 ? 1 : s < 40 ? 2 : 3;
  bar.style.width = Math.min(94, 100 * (1 - Math.exp(-s / 35))) + '%';
  $id('bz-prog-time').textContent = Math.round(s) + ' s';
  $id('bz-prog-step').textContent = ['Bajando y verificando el paquete firmado…', 'Respaldando lo que cambia…', 'Copiando los archivos nuevos…', 'Comprobando que el sitio responda…'][i];
  document.querySelectorAll('#bz-prog .bz-steps span').forEach(x => { const k = +x.dataset.i; x.className = k < i ? 'done' : k === i ? 'on' : ''; });
}
async function bzApply() {
  const p = BZ.plan; if (!p || BZ.busy) return;
  BZ.busy = true; p.running = true; bzDraw();
  const t0 = Date.now(), h = setInterval(() => bzProgress(t0), 500); bzProgress(t0);
  let ok = false;
  try { await bzApi({ op: 'apply', site: p.site, version: p.version }); ok = true; } catch (e) {}
  clearInterval(h);
  if (ok) { const bar = $id('bz-prog-bar'); if (bar) bar.style.width = '100%'; admToast(`${p.name} quedó en ${p.version}`); }
  BZ.busy = false; BZ.plan = null; bzLoad(true);
}
async function bzRollback(site) {
  const s = bzSite(site); if (BZ.busy || !confirm(`¿Volver ${s.name} a ${s.rollback_to || 'como estaba antes'}? Los archivos vuelven a como estaban; la base de datos no.`)) return;
  BZ.busy = true; bzDraw();
  try { const r = await bzApi({ op: 'rollback', site }); admToast(`${s.name} volvió a ${r.version || 'la versión anterior'}`); } catch (e) {}
  BZ.busy = false; bzLoad(true);
}
async function bzMove(site, dir) { try { await bzApi({ act: 'move', site, dir }); const a = BZ.d.sites, i = a.findIndex(x => x.id === site), j = i + dir; if (j >= 0 && j < a.length) [a[i], a[j]] = [a[j], a[i]]; bzDraw(); } catch (e) {} }
async function bzRemove(site) {
  const s = bzSite(site); if (!confirm(`¿Quitar ${s.name} de la lista? Deja de recibir versiones desde el base; el sitio no se toca.`)) return;
  try { await bzApi({ act: 'remove', site }); admToast(`${s.name} quitado de la lista`); bzLoad(true); } catch (e) {}
}
async function bzLicResignAll(btn) {
  if (!confirm('¿Firmar y volver a mandar la licencia de todas las agencias? Desde ahí cada agencia solo acepta licencias firmadas.')) return;
  btn.disabled = true;
  try { const r = await bzApi({ act: 'lic_resign_all' }); admToast(`Firmadas y enviadas: ${r.sent.length}` + (r.errors.length ? `. Con error: ${r.errors.join(' · ')}` : ''), r.errors.length > 0); bzLoad(true); }
  catch (e) {} finally { btn.disabled = false; }
}
async function bzAdd(btn) {
  const c = $id('bz-code'); if (!c.value.trim()) return admToast('Pega el código de conexión', true);
  btn.disabled = true;
  try { const r = await bzApi({ act: 'add', code: c.value.trim() }); c.value = ''; admToast(`${r.name} agregado (versión ${r.version || 'sin versión'})`); bzLoad(true); } catch (e) { btn.disabled = false; }
}
function bzGoLic(id) { BZ.focus = id; bzGo('base-licencias'); setTimeout(() => $id('bz-lic-' + id)?.scrollIntoView({ block: 'start', behavior: 'smooth' }), 60); }

// ── Herramientas (módulos) ──
function bzMods(sel, all) {
  return `<div class="bz-mods">${Object.entries(BZ.d.modules).map(([k, v]) => { const on = all || sel.includes(k); return `<label class="${on ? 'on' : ''}"><input type="checkbox" value="${k}"${on ? ' checked' : ''} data-on-change="@parentOn">${esc(v)}</label>`; }).join('')}</div>`;
}
const bzReadMods = box => [...box.querySelectorAll('input:checked')].map(i => i.value);

// ── Limits inside the modules (base B_LIMITS / site LIC_LIMITS) ──
/** Fields of the limits. vals: what is saved; lic: in a license an empty field = "the plan's" (pl = the plan's values, for the hint). */
function bzLimits(vals, lic, pl) {
  const cat = BZ.d.limits_catalog || {}; vals = vals || {}; pl = pl || {};
  if (!Object.keys(cat).length) return '';
  const mods = BZ.d.modules || {}, groups = {};
  Object.entries(cat).forEach(([k, def]) => { (groups[def[0]] = groups[def[0]] || []).push([k, def]); });
  const show = (k, def) => { const v = pl[k]; if (v === undefined || v === null) return 'sin límite'; return def[2] === 'num' ? String(v) : def[2] === 'bool' ? (v ? 'incluido' : 'no incluido') : (v.length ? v.map(x => def[3][x] || x).join(', ') : 'ninguna'); };
  const empty = lic ? 'Del plan' : 'Sin límite';
  const field = (k, def) => {
    const v = vals[k], has = v !== undefined && v !== null, hint = lic ? `<small class="bz-muted">plan: ${esc(show(k, def))}</small>` : '';
    if (def[2] === 'num') return `<label class="bz-f"><span class="adm-label">${esc(def[1])} ${hint}</span><input class="adm-input" type="number" min="0" data-lim="${esc(k)}" value="${has ? v : ''}" placeholder="${empty}"></label>`;
    if (def[2] === 'bool') return `<label class="bz-f"><span class="adm-label">${esc(def[1])} ${hint}</span><select class="adm-select" data-lim="${esc(k)}"><option value="">${empty}</option><option value="1"${has && v ? ' selected' : ''}>Incluido</option><option value="0"${has && !v ? ' selected' : ''}>No incluido</option></select></label>`;
    return `<div class="bz-f span2"><span class="adm-label">${esc(def[1])} ${hint}</span><select class="adm-select" data-lim="${esc(k)}" data-lim-list="1"><option value="">${empty}</option><option value="some"${has ? ' selected' : ''}>Solo las marcadas</option></select>
      <div class="bz-mods" data-lim-opts="${esc(k)}">${Object.entries(def[3]).map(([o, l]) => { const on = has && v.includes(o); return `<label class="${on ? 'on' : ''}"><input type="checkbox" value="${esc(o)}"${on ? ' checked' : ''} data-on-change="@parentOn">${esc(l)}</label>`; }).join('')}</div></div>`;
  };
  return `<details class="bz-lims" style="margin-top:12px"${Object.keys(vals).some(k => vals[k] !== null && vals[k] !== undefined) ? ' open' : ''}><summary class="adm-label" style="cursor:pointer">Límites dentro de las herramientas</summary>
    ${Object.entries(groups).map(([m, list]) => `<div style="margin-top:10px"><b style="font-size:13px">${esc(mods[m] || m)}</b><div class="bz-grid" style="margin-top:6px">${list.map(([k, def]) => field(k, def)).join('')}</div></div>`).join('')}
    <div class="bz-muted" style="margin-top:6px">${lic ? 'Vacío = lo que diga el plan.' : 'Vacío = sin límite. Viajan dentro de la licencia firmada.'}</div></details>`;
}
/** Reads the limits of a form. lic: an empty field is sent as null (= the plan's); in a plan it is left out (= no limit). */
function bzReadLimits(f, lic) {
  const o = {}, cat = BZ.d.limits_catalog || {};
  f.querySelectorAll('[data-lim]').forEach(el => {
    const k = el.dataset.lim, def = cat[k]; if (!def) return;
    if (el.value === '') { if (lic) o[k] = null; return; }
    if (def[2] === 'num') o[k] = Number(el.value);
    else if (def[2] === 'bool') o[k] = el.value === '1';
    else o[k] = [...f.querySelectorAll(`[data-lim-opts="${k}"] input:checked`)].map(x => x.value);
  });
  return o;
}

// ── Licencias ──
function bzDrawLics() {
  const d = BZ.d, plans = d.plans;
  if (!d.sites.length) return '<div class="adm-card"><div class="bz-msg">Agrega un sitio primero (Red Cronos › Sitios).</div></div>';
  if (!Object.keys(plans).length) return '<div class="adm-card"><div class="bz-msg">Crea un plan primero (Red Cronos › Planes).</div></div>';
  const sign = d.lic_signing
    ? `<div class="adm-card"><div class="bz-msg" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap"><span>Las licencias salen firmadas: cada agencia rechaza una alterada o la de otra agencia.</span><button class="btn btn-ghost" data-on-click="bzLicResignAll" data-click-1="this"><i class="fa-solid fa-signature"></i> Firmar y reenviar todas</button></div></div>`
    : '<div class="adm-card"><div class="bz-msg bz-warn">Las licencias salen sin firma: falta la clave _private/license_sign.pem en el sitio base (ver LICENCIAS.md).</div></div>';
  return sign + d.sites.map(s => {
    const l = d.licenses[s.id] || {}, pl = plans[l.plan_id] || null, custom = l.modules !== null && l.modules !== undefined, dl = bzDays(l.expires);
    const ph = k => pl && pl[k] != null ? `del plan: ${pl[k]}` : 'del plan';
    const tag = l.plan_id ? (l.push_error ? '<span class="bz-tag warn">Pendiente de entrega</span>' : `<span class="bz-tag ${l.status === 'suspendida' ? 'bad' : dl !== null && dl < 0 ? 'bad' : dl !== null && dl <= 7 ? 'warn' : 'ok'}">${l.status === 'suspendida' ? 'Suspendida' : dl === null ? 'Sin vencimiento' : dl < 0 ? 'Vencida' : 'Vence en ' + dl + ' días'}</span>`) : '<span class="bz-tag">Sin licencia</span>';
    return `<div class="adm-card" id="bz-lic-${esc(s.id)}"${BZ.focus === s.id ? ' style="border-color:var(--g7-accent-border)"' : ''}><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-id-card"></i> ${esc(s.name)} <span class="bz-muted" style="font-weight:500">${esc(String(s.url).replace(/^https?:\/\//, ''))}</span></div>${tag}</div>
      <div class="adm-card-body" data-site="${esc(s.id)}">
        <div class="bz-grid">
          <label class="bz-f"><span class="adm-label">Plan</span><select class="adm-select" data-k="plan_id"><option value="">Elige un plan</option>${Object.entries(plans).map(([k, p]) => `<option value="${esc(k)}"${k === l.plan_id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
          <label class="bz-f"><span class="adm-label">Vence</span><input class="adm-input" type="date" data-k="expires" value="${esc(l.expires || '')}"></label>
          <label class="bz-f"><span class="adm-label">Estado</span><select class="adm-select" data-k="status"><option value="activa">Activa</option><option value="suspendida"${l.status === 'suspendida' ? ' selected' : ''}>Suspendida</option></select></label>
          <div class="bz-f"><span class="adm-label">Extender</span><span style="display:flex;gap:6px"><button type="button" class="btn btn-ghost btn-sm" data-on-click="bzAddTime" data-click-1="this" data-click-2="n:1">+1 mes</button><button type="button" class="btn btn-ghost btn-sm" data-on-click="bzAddTime" data-click-1="this" data-click-2="n:12">+1 año</button></span></div>
          <label class="bz-f"><span class="adm-label">Usuarios</span><input class="adm-input" type="number" min="0" data-k="users" value="${l.users ?? ''}" placeholder="${esc(ph('users'))}"></label>
          <label class="bz-f"><span class="adm-label">Clientes</span><input class="adm-input" type="number" min="0" data-k="clients" value="${l.clients ?? ''}" placeholder="${esc(ph('clients'))}"></label>
          <label class="bz-f"><span class="adm-label">Espacio (GB)</span><input class="adm-input" type="number" min="0" step="0.5" data-k="storage_gb" value="${l.storage_gb ?? ''}" placeholder="${esc(ph('storage_gb'))}"></label>
          <label class="bz-f"><span class="adm-label">Tipos de cliente</span><input class="adm-input" type="number" min="0" data-k="client_types" value="${l.client_types ?? ''}" placeholder="${esc(ph('client_types'))}"></label>
          <label class="bz-f"><span class="adm-label">Rubro</span><select class="adm-select" data-k="rubro"><option value="">Sin asignar</option>${Object.entries(d.rubros || {}).map(([k, v]) => `<option value="${esc(k)}"${l.rubro === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
          <label class="bz-f span2"><span class="adm-label">Herramientas</span><select class="adm-select" data-k="modmode" data-on-change="bzModWrap" data-change-1="this" data-change-2="s:[data-site]" data-change-3="s:custom"><option value="plan">Las del plan</option><option value="custom"${custom ? ' selected' : ''}>Elegir para esta agencia</option></select></label>
        </div>
        <div class="bz-modwrap" style="${custom ? '' : 'display:none'}">${bzMods(custom ? l.modules : (pl?.modules || []), custom ? false : !(pl?.modules || []).length)}</div>
        ${Object.keys(d.extras || {}).length ? `<div style="margin-top:12px"><span class="adm-label">Módulos extra (se cobran aparte del plan)</span><div class="bz-mods" data-extras>${Object.entries(d.extras).map(([k, v]) => { const on = (l.extras || []).includes(k); return `<label class="${on ? 'on' : ''}"><input type="checkbox" value="${esc(k)}"${on ? ' checked' : ''} data-on-change="@parentOn">${esc(v)}</label>`; }).join('')}</div></div>` : ''}
        ${bzLimits(l.limits, true, pl?.limits)}
        <label class="bz-f" style="margin-top:12px"><span class="adm-label">Notas internas</span><textarea class="adm-input" data-k="notes" rows="2" placeholder="Ej.: pagó por transferencia, contacto, condiciones especiales">${esc(l.notes || '')}</textarea></label>
        <div class="bz-foot"><button class="btn btn-primary" data-on-click="bzSaveLic" data-click-1="this"><i class="fa-solid fa-paper-plane"></i> Guardar y enviar</button>${l.plan_id ? `<button class="btn btn-danger" data-on-click="bzRemoveLic" data-click-1="s:${esc(s.id)}">Quitar licencia</button>` : ''}
          <span class="bz-muted">${l.push_error ? '<span class="bz-warn">No se pudo entregar: ' + esc(l.push_error) + '</span>' : l.pushed_at ? 'Entregada ' + new Date(l.pushed_at * 1000).toLocaleString('es-AR') : 'Los campos vacíos toman el valor del plan.'}</span></div>
      </div></div>`;
  }).join('');
}
function bzAddTime(btn, months) {
  const inp = btn.closest('[data-site]').querySelector('[data-k=expires]');
  const base = inp.value && new Date(inp.value + 'T12:00:00') > new Date() ? new Date(inp.value + 'T12:00:00') : new Date();
  base.setMonth(base.getMonth() + months); inp.value = base.toISOString().slice(0, 10);
}
async function bzSaveLic(btn) {
  const f = btn.closest('[data-site]'), v = k => f.querySelector(`[data-k=${k}]`).value;
  const ex = f.querySelector('[data-extras]');
  const lic = { plan_id: v('plan_id'), expires: v('expires'), status: v('status'), users: v('users'), clients: v('clients'), storage_gb: v('storage_gb'), notes: v('notes'),
    client_types: v('client_types'), rubro: v('rubro'), extras: ex ? bzReadMods(ex) : [],
    modules: v('modmode') === 'custom' ? bzReadMods(f.querySelector('.bz-modwrap')) : null, limits: bzReadLimits(f, true) };
  if (!lic.plan_id) return admToast('Elige un plan', true);
  if (lic.modules && !lic.modules.length) return admToast('Elige al menos una herramienta', true);
  btn.disabled = true; BZ.focus = f.dataset.site;
  try { const r = await bzApi({ act: 'lic_save', site: f.dataset.site, lic }); admToast(r.pending ? 'Guardada. El sitio no respondió: se reintenta solo.' : 'Licencia guardada y entregada', !!r.pending); await bzLoad(true); } catch (e) { btn.disabled = false; }
}
async function bzRemoveLic(id) {
  const s = bzSite(id); if (!confirm(`¿Quitar la licencia de ${s.name}? Queda sin límites.`)) return;
  try { await bzApi({ act: 'lic_remove', site: id }); admToast('Licencia quitada'); bzLoad(true); } catch (e) {}
}

// ── Planes ──
function bzDrawPlans() {
  const d = BZ.d, used = {}; Object.values(d.licenses).forEach(l => { used[l.plan_id] = (used[l.plan_id] || 0) + 1; });
  const card = (id, p) => { const all = !(p.modules || []).length; return `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid fa-layer-group"></i> ${id ? esc(p.name) : 'Nuevo plan'}</div><span class="bz-muted">${id ? (used[id] ? used[id] + (used[id] > 1 ? ' agencias' : ' agencia') : 'sin agencias') : ''}${p.price ? ' · ' + esc(p.price) : ''}</span></div>
    <div class="adm-card-body" data-plan="${esc(id)}">
      <div class="bz-grid">
        <label class="bz-f"><span class="adm-label">Nombre</span><input class="adm-input" data-k="name" value="${esc(p.name || '')}" maxlength="40"></label>
        <label class="bz-f"><span class="adm-label">Usuarios</span><input class="adm-input" type="number" min="0" data-k="users" value="${p.users ?? ''}" placeholder="sin tope"></label>
        <label class="bz-f"><span class="adm-label">Clientes</span><input class="adm-input" type="number" min="0" data-k="clients" value="${p.clients ?? ''}" placeholder="sin tope"></label>
        <label class="bz-f"><span class="adm-label">Espacio (GB)</span><input class="adm-input" type="number" min="0" step="0.5" data-k="storage_gb" value="${p.storage_gb ?? ''}" placeholder="sin tope"></label>
        <label class="bz-f"><span class="adm-label">Tipos de cliente</span><input class="adm-input" type="number" min="0" data-k="client_types" value="${p.client_types ?? ''}" placeholder="sin tope"></label>
        <label class="bz-f"><span class="adm-label">Precio de referencia</span><input class="adm-input" data-k="price" value="${esc(p.price || '')}" placeholder="Ej.: USD 79 por mes"></label>
        <label class="bz-f span2"><span class="adm-label">Herramientas</span><select class="adm-select" data-k="modmode" data-on-change="bzModWrap" data-change-1="this" data-change-2="s:[data-plan]" data-change-3="s:some"><option value="all">Todas</option><option value="some"${all ? '' : ' selected'}>Solo algunas</option></select></label>
      </div>
      <div class="bz-modwrap" style="${all ? 'display:none' : ''}">${bzMods(p.modules || [], false)}</div>
      ${bzLimits(p.limits, false)}
      <div class="bz-foot"><button class="btn btn-primary" data-on-click="bzSavePlan" data-click-1="this"><i class="fa-solid fa-floppy-disk"></i> Guardar plan</button>${id ? `<button class="btn btn-danger" data-on-click="bzDelPlan" data-click-1="s:${esc(id)}"${used[id] ? ' disabled title="Lo usa alguna agencia"' : ''}>Borrar</button>` : '<button class="btn btn-ghost" data-on-click="bzReset" data-click-1="s:editPlan" data-click-2="null">Cancelar</button>'}
        ${id && used[id] ? '<span class="bz-muted">Al guardar, las agencias de este plan reciben los topes nuevos.</span>' : ''}</div>
    </div></div>`; };
  return (BZ.editPlan === 'new' ? card('', { name: '', modules: [] }) : `<div class="adm-card"><div class="bz-msg" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><span>${Object.keys(d.plans).length} planes. Los campos vacíos no tienen tope.</span><button class="btn btn-primary btn-sm" data-on-click="bzReset" data-click-1="s:editPlan" data-click-2="s:new"><i class="fa-solid fa-plus"></i> Nuevo plan</button></div></div>`)
    + Object.entries(d.plans).map(([k, p]) => card(k, p)).join('');
}
async function bzSavePlan(btn) {
  const f = btn.closest('[data-plan]'), v = k => f.querySelector(`[data-k=${k}]`).value;
  const plan = { id: f.dataset.plan, name: v('name'), users: v('users'), clients: v('clients'), storage_gb: v('storage_gb'), client_types: v('client_types'), price: v('price'), modules: v('modmode') === 'some' ? bzReadMods(f.querySelector('.bz-modwrap')) : [], limits: bzReadLimits(f, false) };
  if (!plan.name.trim()) return admToast('El plan necesita un nombre', true);
  if (v('modmode') === 'some' && !plan.modules.length) return admToast('Elige al menos una herramienta', true);
  btn.disabled = true;
  try { const r = await bzApi({ act: 'plan_save', plan }); BZ.editPlan = null; admToast(r.pending?.length ? 'Plan guardado. Falta entregarlo a: ' + r.pending.join(', ') : 'Plan guardado', !!r.pending?.length); await bzLoad(true); } catch (e) { btn.disabled = false; }
}
async function bzDelPlan(id) { if (!confirm('¿Borrar este plan?')) return; try { await bzApi({ act: 'plan_delete', id }); admToast('Plan borrado'); bzLoad(true); } catch (e) {} }

// ── Banners ──
function bzBnStatus(b) { const t = new Date().toISOString().slice(0, 10); if (!b.active) return ['Pausado', '']; if (b.start && b.start > t) return ['Programado', 'warn']; if (b.end && b.end < t) return ['Terminó', '']; return ['Publicado', 'ok']; }
function bzBnAud(b) { const a = b.audience || {}; if (a.mode === 'sites') return (a.sites || []).map(id => bzSite(id)?.name || id).join(', '); if (a.mode === 'plans') return 'Plan ' + (a.plans || []).map(p => p === '_sin_plan' ? 'sin licencia' : (BZ.d.plans[p]?.name || p)).join(', '); return 'Todas las agencias'; }
function bzBnPrev(b) { return `<div class="bz-prev ${esc(b.style)}">${b.image || b._img ? `<img src="${esc(b._img || b.image)}" alt="">` : ''}<div style="min-width:0">${b.title ? `<b>${esc(b.title)}</b>` : ''}${b.text ? `<span>${esc(b.text)}</span>` : ''}</div>${b.btn_url ? `<span class="bb">${esc(b.btn_text || 'Ver más')}</span>` : ''}</div>`; }
function bzDrawBanners() {
  const d = BZ.d, bp = d.banner_push || {}, pend = Object.entries(bp).filter(([, v]) => v.error);
  const list = (d.banners || []).map(b => { const [st, cl] = bzBnStatus(b); return `<div class="bz-bn"><div>${bzBnPrev(b)}<div class="bz-use" style="margin-top:8px"><span class="bz-tag ${cl}">${st}</span><span><b>${BZ_PLACE[b.place]}</b> · ${BZ_STYLE[b.style]}</span><span>${esc(bzBnAud(b))}</span><span>${b.start || b.end ? `${esc(b.start ? bzDate(b.start) : 'ya')} → ${esc(b.end ? bzDate(b.end) : 'sin fin')}` : 'Sin fechas'}</span>${b.dismissible ? '<span>Se puede cerrar</span>' : ''}</div></div>
    <div class="bz-act"><button class="btn btn-ghost btn-sm" data-on-click="bzBnEdit" data-click-1="s:${esc(b.id)}"><i class="fa-solid fa-pen"></i> Editar</button><button class="btn btn-danger btn-sm" data-on-click="bzBnDel" data-click-1="s:${esc(b.id)}">Borrar</button></div></div>`; }).join('');
  return `${pend.length ? `<div class="adm-card"><div class="bz-msg bz-warn">No les llegaron los banners a: ${pend.map(([k, v]) => `<b>${esc(bzSite(k)?.name || k)}</b> (${esc(v.error)})`).join(' · ')}. Se reintenta solo cuando respondan con la versión nueva. <button class="btn btn-ghost btn-sm" data-on-click="bzBnPush" data-click-1="this">Reintentar ahora</button></div></div>` : ''}
    ${BZ.bn ? bzBnForm() : `<div class="adm-card"><div class="bz-msg" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><span>Aparecen arriba del Inicio o de Admin en los sitios de la red.</span><button class="btn btn-primary btn-sm" data-on-click="bzBnEdit" data-click-1="s:"><i class="fa-solid fa-plus"></i> Nuevo banner</button></div></div>`}
    ${bzCard('Banners', 'fa-bullhorn', list || '<div class="bz-msg">Todavía no hay banners.</div>')}`;
}
function bzBnEdit(id) {
  const b = (BZ.d.banners || []).find(x => x.id === id);
  BZ.bn = b ? JSON.parse(JSON.stringify(b)) : { id: '', place: 'dashboard', style: 'novedad', title: '', text: '', btn_text: '', btn_url: '', image: '', start: '', end: '', audience: { mode: 'all', sites: [], plans: [] }, dismissible: true, active: true };
  bzDraw(); $id('sec-base-banners')?.scrollIntoView({ behavior: 'smooth' });
}
function bzBnForm() {
  const b = BZ.bn, a = b.audience || { mode: 'all' }, sel = (k, o) => Object.entries(o).map(([v, t]) => `<option value="${v}"${b[k] === v ? ' selected' : ''}>${t}</option>`).join('');
  const chk = (kind, val, label, on) => `<label class="${on ? 'on' : ''}"><input type="checkbox" data-aud="${kind}" value="${esc(val)}"${on ? ' checked' : ''} data-on-change="@parentOn">${esc(label)}</label>`;
  const f = (label, html, cls = '') => `<label class="bz-f ${cls}"><span class="adm-label">${label}</span>${html}</label>`;
  return bzCard(b.id ? 'Editar banner' : 'Nuevo banner', 'fa-bullhorn', `<div class="adm-card-body" data-on-input="bzBnRead" data-on-change="bzBnRead"><div class="bz-grid">
    ${f('Dónde aparece', `<select class="adm-select" id="bzb-place">${sel('place', BZ_PLACE)}</select>`)}${f('Estilo', `<select class="adm-select" id="bzb-style">${sel('style', BZ_STYLE)}</select>`)}
    ${f('Desde (opcional)', `<input class="adm-input" type="date" id="bzb-start" value="${esc(b.start)}">`)}${f('Hasta (opcional)', `<input class="adm-input" type="date" id="bzb-end" value="${esc(b.end)}">`)}
    ${f('Título', `<input class="adm-input" id="bzb-title" maxlength="120" value="${esc(b.title)}" placeholder="Llegó el calendario personal">`, 'span4')}
    ${f('Texto', `<textarea class="adm-input" id="bzb-text" rows="2" maxlength="400" placeholder="Una o dos líneas.">${esc(b.text)}</textarea>`, 'span4')}
    ${f('Texto del botón', `<input class="adm-input" id="bzb-btn" maxlength="40" value="${esc(b.btn_text)}" placeholder="Ver más">`, 'span2')}${f('Link del botón (opcional)', `<input class="adm-input" id="bzb-url" value="${esc(b.btn_url)}" placeholder="https://">`, 'span2')}
    ${f('Imagen (opcional, hasta 2 MB)', `<input class="adm-input" type="file" id="bzb-img" accept="image/png,image/jpeg,image/webp,image/gif" data-on-change="bzBnImg" data-change-1="this">`, 'span2')}
    <div class="bz-f span2"><span class="adm-label">&nbsp;</span>${b.image || b._img ? '<label class="bz-chk"><input type="checkbox" id="bzb-noimg"> Quitar la imagen</label>' : '<span class="bz-muted">Sin imagen</span>'}</div>
    ${f('A quién le aparece', `<select class="adm-select" id="bzb-mode"><option value="all"${a.mode === 'all' ? ' selected' : ''}>Todas las agencias</option><option value="sites"${a.mode === 'sites' ? ' selected' : ''}>Algunas agencias</option><option value="plans"${a.mode === 'plans' ? ' selected' : ''}>Por plan</option></select>`, 'span2')}
    <div class="bz-f span2" style="align-content:end;display:flex;gap:18px;flex-wrap:wrap"><label class="bz-chk"><input type="checkbox" id="bzb-dis"${b.dismissible ? ' checked' : ''}> Cada persona lo puede cerrar</label><label class="bz-chk"><input type="checkbox" id="bzb-act"${b.active ? ' checked' : ''}> Publicado</label></div></div>
    ${a.mode === 'sites' ? `<div class="bz-mods">${BZ.d.sites.map(s => chk('sites', s.id, s.name, (a.sites || []).includes(s.id))).join('')}</div>` : ''}
    ${a.mode === 'plans' ? `<div class="bz-mods">${Object.entries(BZ.d.plans).map(([k, p]) => chk('plans', k, p.name, (a.plans || []).includes(k))).join('')}${chk('plans', '_sin_plan', 'Sin licencia', (a.plans || []).includes('_sin_plan'))}</div>` : ''}
    <div style="margin-top:16px"><div class="adm-label" style="margin-bottom:6px">Vista previa</div><div id="bzb-prev">${bzBnPrev(b)}</div></div>
    <div class="bz-foot"><button class="btn btn-primary" id="bzb-save" data-on-click="bzBnSave"><i class="fa-solid fa-paper-plane"></i> Guardar y enviar</button><button class="btn btn-ghost" data-on-click="bzReset" data-click-1="s:bn" data-click-2="null">Cancelar</button></div></div>`);
}
function bzBnRead() {
  const b = BZ.bn, v = id => $id('bzb-' + id); if (!b || !v('place')) return;
  const mode = v('mode').value, prevMode = (b.audience || {}).mode;
  Object.assign(b, { place: v('place').value, style: v('style').value, title: v('title').value, text: v('text').value, btn_text: v('btn').value, btn_url: v('url').value.trim(),
    start: v('start').value, end: v('end').value, dismissible: v('dis').checked, active: v('act').checked, remove_image: !!v('noimg')?.checked });
  b.audience = { mode, sites: [...document.querySelectorAll('[data-aud=sites]:checked')].map(x => x.value), plans: [...document.querySelectorAll('[data-aud=plans]:checked')].map(x => x.value) };
  if (mode !== prevMode) { bzDraw(); return; }
  $id('bzb-prev').innerHTML = bzBnPrev(b.remove_image ? { ...b, image: '', _img: '' } : b);
}
function bzBnImg(inp) {
  const f = inp.files[0]; if (!f) return; if (f.size > 2 * 1048576) { admToast('La imagen pesa más de 2 MB', true); inp.value = ''; return; }
  const r = new FileReader(); r.onload = () => { BZ.bn.image_data = r.result; BZ.bn._img = r.result; bzBnRead(); }; r.readAsDataURL(f);
}
async function bzBnSave() {
  bzBnRead(); const b = BZ.bn, btn = $id('bzb-save');
  if (!b.title.trim() && !b.text.trim()) return admToast('El banner necesita un título o un texto', true);
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando a los sitios…';
  const { _img, ...send } = b;
  try { const r = await bzApi({ act: 'banner_save', banner: send }); BZ.bn = null; admToast(r.pending?.length ? `Guardado. Falta que llegue a: ${r.pending.join(', ')}` : 'Banner guardado y enviado a todos los sitios', !!r.pending?.length); bzLoad(true); }
  catch (e) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Guardar y enviar'; }
}
async function bzBnDel(id) { if (!confirm('¿Borrar este banner? Se saca de todos los sitios.')) return; try { await bzApi({ act: 'banner_delete', id }); admToast('Banner borrado'); bzLoad(true); } catch (e) {} }
async function bzBnPush(btn) { btn.disabled = true; try { await bzApi({ act: 'banner_push' }); admToast('Banners reenviados'); bzLoad(true); } catch (e) { btn.disabled = false; } }

// ── Catálogo de herramientas (Biblioteca › Catálogo Cronos de las agencias). Doc: toolz/CATALOGO.md ──
const BZ_TL_ST = { publicada: ['Publicada', 'ok'], borrador: ['Borrador', 'warn'] };
async function bzTlLoad() { if (BZ.tlLoading) return; BZ.tlLoading = true; try { BZ.tl = await bzApi({ act: 'tool_list' }); } catch (e) { BZ.tl = { tools: [], types: {}, plans: {}, error: e.message }; } BZ.tlLoading = false; bzDraw(); }
const bzTlUrl = (id, what, v) => `/toolz/admin_base_tool.php?id=${encodeURIComponent(id)}&what=${what}&v=${encodeURIComponent(v || '')}`;
function bzDrawTools() {
  const L = BZ.tl; if (!L) return bzCard('Herramientas', 'fa-toolbox', '<div class="bz-msg"><i class="fa-solid fa-spinner fa-spin"></i> Trayendo el catálogo…</div>');
  const n = L.tools.length;
  const rows = L.tools.map((t, i) => { const [st, cl] = BZ_TL_ST[t.status] || ['', '']; return `<div class="bz-tl">${t.cover ? `<img src="${bzTlUrl(t.id, 'cover', t.cover)}" alt="" loading="lazy">` : '<span class="ph"><i class="fa-solid fa-toolbox"></i></span>'}
    <div><b>${esc(t.name)}</b> <span class="bz-tag ${cl}">${st}</span> <span class="bz-tag">${esc(L.types[t.type] || t.type)}</span>${t.plans.length ? ` <span class="bz-tag new"><i class="fa-solid fa-lock"></i> ${esc(t.plans.map(p => L.plans[p] || p).join(', '))}</span>` : ''}
      <small>${esc(t.short)}</small><small>v${t.ver} · ${esc(t.id)} · ${Math.round(t.size / 1024)} KB${t.tags.length ? ' · #' + t.tags.map(esc).join(' #') : ''}</small></div>
    <div class="bz-act"><button class="btn btn-ghost btn-sm" title="Subir" data-on-click="bzTlMove" data-click-1="s:${esc(t.id)}" data-click-2="-1"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button><button class="btn btn-ghost btn-sm" title="Bajar" data-on-click="bzTlMove" data-click-1="s:${esc(t.id)}" data-click-2="1"${i < n - 1 ? '' : ' disabled'}><i class="fa-solid fa-arrow-down"></i></button>
      <button class="btn btn-ghost btn-sm" data-on-click="bzTlPrev" data-click-1="s:${esc(t.id)}"><i class="fa-solid fa-eye"></i> Probar</button><button class="btn btn-ghost btn-sm" data-on-click="bzTlEdit" data-click-1="s:${esc(t.id)}"><i class="fa-solid fa-pen"></i> Editar</button><button class="btn btn-danger btn-sm" data-on-click="bzTlDel" data-click-1="s:${esc(t.id)}">Borrar</button></div></div>
    ${BZ.tlPrev === t.id ? `<div style="padding:0 18px 14px"><div class="bz-tframe"><iframe src="${bzTlUrl(t.id, 'html', t.sha256)}" title="Vista previa" sandbox="allow-scripts allow-forms allow-modals allow-downloads allow-popups"></iframe></div></div>` : ''}`; }).join('');
  return `${L.error ? `<div class="adm-card"><div class="bz-msg bz-bad">${esc(L.error)}</div></div>` : ''}
    ${BZ.tle ? bzTlForm() : `<div class="adm-card"><div class="bz-msg" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><span>Cada herramienta es <b>un solo archivo HTML</b> que trae todo adentro (estilos y scripts), sin servidor. Las agencias se la copian y corre aislada: no ve su sesión ni sus datos. Al subir un HTML nuevo, la versión sube y a las agencias que la tienen les aparece <b>Actualizar</b>.</span><button class="btn btn-primary btn-sm" data-on-click="bzTlEdit" data-click-1="s:"><i class="fa-solid fa-plus"></i> Nueva herramienta</button></div></div>`}
    ${bzCard(`Herramientas (${n})`, 'fa-toolbox', rows || '<div class="bz-msg">Todavía no hay herramientas.</div>')}`;
}
function bzTlEdit(id) {
  const t = BZ.tl.tools.find(x => x.id === id);
  BZ.tle = t ? { ...JSON.parse(JSON.stringify(t)), tagsTxt: t.tags.join(', ') } : { id: '', name: '', slug: '', short: '', long: '', type: 'calculadora', tags: [], tagsTxt: '', plans: [], status: 'borrador', cover: '', ver: 0 };
  BZ.tlPrev = null; bzDraw(); $id('sec-base-herramientas')?.scrollIntoView({ behavior: 'smooth' });
}
function bzTlForm() {
  const t = BZ.tle, L = BZ.tl, f = (label, html, cls = '') => `<label class="bz-f ${cls}"><span class="adm-label">${label}</span>${html}</label>`;
  const opt = (o, v) => Object.entries(o).map(([k, x]) => `<option value="${esc(k)}"${k === v ? ' selected' : ''}>${esc(x)}</option>`).join('');
  return bzCard(t.id ? 'Editar herramienta' : 'Nueva herramienta', 'fa-toolbox', `<div class="adm-card-body" data-on-input="bzTlRead" data-on-change="bzTlRead"><div class="bz-grid">
    ${f('Nombre', `<input class="adm-input" id="bzt-name" maxlength="80" value="${esc(t.name)}" placeholder="Calculadora de tarifa por hora">`, 'span2')}
    ${t.id ? f('Identificador', `<input class="adm-input" value="${esc(t.id)}" disabled>`) : f('Identificador (opcional)', `<input class="adm-input" id="bzt-slug" maxlength="50" value="${esc(t.slug)}" placeholder="sale del nombre">`)}
    ${f('Tipo', `<select class="adm-select" id="bzt-type">${opt(L.types, t.type)}</select>`)}
    ${f('Descripción corta (la tarjeta)', `<input class="adm-input" id="bzt-short" maxlength="220" value="${esc(t.short)}" placeholder="Una o dos frases: qué resuelve.">`, 'span4')}
    ${f('Descripción larga (la ficha: para qué sirve y cómo se usa)', `<textarea class="adm-input" id="bzt-long" rows="5" maxlength="6000">${esc(t.long)}</textarea>`, 'span4')}
    ${f('Etiquetas (separadas por coma)', `<input class="adm-input" id="bzt-tags" value="${esc(t.tagsTxt)}" placeholder="precios, presupuesto">`, 'span2')}
    ${f('Estado', `<select class="adm-select" id="bzt-status"><option value="borrador"${t.status === 'borrador' ? ' selected' : ''}>Borrador (no la ven las agencias)</option><option value="publicada"${t.status === 'publicada' ? ' selected' : ''}>Publicada</option></select>`, 'span2')}
    ${f(t.id ? `Archivo HTML (versión actual: ${t.ver}; subí uno solo si cambió)` : 'Archivo HTML (hasta 2 MB)', `<input class="adm-input" type="file" id="bzt-html" accept=".html,.htm,text/html" data-on-change="bzTlFile" data-change-1="this">`, 'span2')}
    ${f('Portada (PNG, JPG o WebP de hasta 1 MB, 16:9)', `<input class="adm-input" type="file" id="bzt-cover" accept="image/png,image/jpeg,image/webp" data-on-change="bzTlCover" data-change-1="this">`, 'span2')}
    <div class="bz-f span4">${t.html ? `<span class="bz-tag ok"><i class="fa-solid fa-file-code"></i> ${esc(t.htmlName)} · ${Math.round(t.html.length / 1024)} KB listo para subir</span>` : ''} ${t.cover_data ? '<span class="bz-tag ok">Portada nueva lista</span>' : t.cover ? '<label class="bz-chk"><input type="checkbox" id="bzt-nocover"> Quitar la portada</label>' : '<span class="bz-muted">Sin portada: las agencias ven un ícono del tipo.</span>'}</div></div>
    <div class="adm-label" style="margin-top:14px">Planes que la incluyen <span class="bz-muted">(ninguno marcado = todas las agencias)</span></div>
    <div class="bz-mods">${Object.entries(L.plans).map(([k, n]) => `<label class="${t.plans.includes(k) ? 'on' : ''}"><input type="checkbox" data-tlplan value="${esc(k)}"${t.plans.includes(k) ? ' checked' : ''} data-on-change="@parentOn">${esc(n)}</label>`).join('')}</div>
    <div class="bz-foot"><button class="btn btn-primary" id="bzt-save" data-on-click="bzTlSave"><i class="fa-solid fa-floppy-disk"></i> Guardar</button><button class="btn btn-ghost" data-on-click="bzReset" data-click-1="s:tle" data-click-2="null">Cancelar</button></div></div>`);
}
function bzTlRead() {
  const t = BZ.tle, v = id => $id('bzt-' + id); if (!t || !v('name')) return;
  Object.assign(t, { name: v('name').value, short: v('short').value, long: v('long').value, type: v('type').value, status: v('status').value, tagsTxt: v('tags').value,
    remove_cover: !!v('nocover')?.checked, plans: [...document.querySelectorAll('[data-tlplan]:checked')].map(x => x.value) });
  if (v('slug')) t.slug = v('slug').value;
}
function bzTlFile(inp) {
  const f = inp.files[0]; if (!f) return; if (f.size > 2 * 1048576) { admToast('El HTML pesa más de 2 MB', true); inp.value = ''; return; }
  const r = new FileReader(); r.onload = () => { bzTlRead(); BZ.tle.html = r.result; BZ.tle.htmlName = f.name; bzDraw(); }; r.readAsText(f, 'UTF-8');
}
function bzTlCover(inp) {
  const f = inp.files[0]; if (!f) return; if (f.size > 1048576) { admToast('La portada pesa más de 1 MB', true); inp.value = ''; return; }
  const r = new FileReader(); r.onload = () => { bzTlRead(); BZ.tle.cover_data = r.result; bzDraw(); }; r.readAsDataURL(f);
}
async function bzTlSave() {
  bzTlRead(); const t = BZ.tle, btn = $id('bzt-save');
  if (!t.name.trim() || !t.short.trim()) return admToast('Faltan el nombre y la descripción corta', true);
  if (!t.id && !t.html) return admToast('Falta el archivo HTML', true);
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando…';
  const tool = { id: t.id, slug: t.slug, name: t.name, short: t.short, long: t.long, type: t.type, status: t.status, plans: t.plans, tags: t.tagsTxt.split(',').map(x => x.trim()).filter(Boolean), remove_cover: t.remove_cover };
  if (t.html) tool.html = t.html; if (t.cover_data) tool.cover_data = t.cover_data;
  try { const r = await bzApi({ act: 'tool_save', tool }); BZ.tle = null; admToast(`Guardada (versión ${r.tool.ver}${r.tool.status === 'publicada' ? ', publicada' : ', en borrador'})`); BZ.tl = null; bzTlLoad(); }
  catch (e) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar'; }
}
async function bzTlDel(id) { if (!confirm('¿Borrar esta herramienta del catálogo? Las agencias que ya la agregaron la conservan, pero no van a recibir más versiones.')) return; try { await bzApi({ act: 'tool_delete', id }); admToast('Herramienta borrada'); BZ.tl = null; bzTlLoad(); } catch (e) {} }
async function bzTlMove(id, dir) { try { await bzApi({ act: 'tool_move', id, dir: +dir }); BZ.tl = null; bzTlLoad(); } catch (e) {} }
function bzTlPrev(id) { BZ.tlPrev = BZ.tlPrev === id ? null : id; bzDraw(); }

// ── Tickets ──
async function bzTkLoad() { if (BZ.tkLoading) return; BZ.tkLoading = true; try { BZ.tk = await bzApi({ act: 'tickets' }); } catch (e) { BZ.tk = { tickets: [], types: {}, statuses: {}, error: e.message }; } BZ.tkLoading = false; bzDraw(); }
function bzTkFiltered() {
  const f = BZ.tkf, q = f.q.trim().toLowerCase();
  return (BZ.tk?.tickets || []).filter(t => (!f.area || t.area === f.area) && (!f.type || t.type === f.type) && (!f.site || (f.site === '_web' ? t.source === 'web' : t.site_id === f.site))
    && (f.status === 'todos' || (f.status === 'abiertos' ? BZ_OPEN.includes(t.status) : t.status === f.status))
    && (!q || [t.title, t.body, t.contact?.name, t.contact?.email, t.contact?.company, t.site_name, '#' + t.id].join(' ').toLowerCase().includes(q)));
}
function bzTkTag(t) { const ty = BZ.tk.types[t.type] || []; return `<span class="bz-tag ${esc(t.area)}">${esc(BZ_AREAS[t.area]?.[0] || t.area)} › ${esc(ty[1] || t.type)}</span>`; }
function bzDrawTickets() {
  if (!BZ.tk) return bzCard('Tickets', 'fa-life-ring', '<div class="bz-msg"><i class="fa-solid fa-spinner fa-spin"></i> Cargando tickets…</div>');
  if (BZ.tk.error) return bzCard('Tickets', 'fa-life-ring', `<div class="bz-msg bz-bad">${esc(BZ.tk.error)}</div>`);
  const f = BZ.tkf, all = BZ.tk.tickets, cnt = (a, ty) => all.filter(t => BZ_OPEN.includes(t.status) && (!a || t.area === a) && (!ty || t.type === ty)).length;
  const chip = (a, ty, label) => `<button class="bz-chip${f.area === a && f.type === ty ? ' on' : ''}" data-on-click="bzTkFilter" data-click-1="s:${a}" data-click-2="s:${ty}">${label}<small>${cnt(a, ty)}</small></button>`;
  const chips = chip('', '', 'Todos') + '<span class="sep"></span>' + Object.entries(BZ_AREAS).map(([a, [lab, types]]) => chip(a, '', lab) + types.map(ty => chip(a, ty, '› ' + esc(BZ.tk.types[ty]?.[1] || ty))).join('') + '<span class="sep"></span>').join('');
  const sites = [...new Map(all.filter(t => t.source === 'sitio').map(t => [t.site_id, t.site_name])).entries()];
  const unread = all.filter(t => t.unread).length;
  const rows = bzTkFiltered().map(t => `<div class="bz-tk${t.unread ? ' unread' : ''}${BZ.tkOpen === t.id ? ' on' : ''}" data-on-click="bzTkOpen" data-click-1="j:${+t.id}"><span class="id">#${+t.id}</span>
    <div style="min-width:0"><b class="tt">${esc(t.title)}</b><small>${bzTkTag(t)} ${esc(t.source === 'web' ? 'cronostoolz.com · ' + (t.contact?.company || t.contact?.name || '') : (t.site_name || '') + ' · ' + (t.contact?.name || ''))}</small></div>
    <div style="text-align:right"><span class="bz-tag ${t.status === 'nuevo' ? 'new' : BZ_OPEN.includes(t.status) ? 'warn' : 'ok'}">${esc(BZ.tk.statuses[t.status] || t.status)}</span><small>${esc(bzAgo(t.updated_at))}${t.replies?.length ? ' · ' + t.replies.length + ' resp.' : ''}</small></div></div>
    ${BZ.tkOpen === t.id ? bzTkDetail(t) : ''}`).join('');
  return bzCard('Tickets', 'fa-life-ring', `<div class="bz-chips">${chips}</div>
    <div class="bz-filters"><select class="adm-select" data-on-change="bzTkSet" data-change-1="s:status" data-change-2="value"><option value="abiertos"${f.status === 'abiertos' ? ' selected' : ''}>Abiertos</option>${Object.entries(BZ.tk.statuses).map(([k, v]) => `<option value="${k}"${f.status === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}<option value="todos"${f.status === 'todos' ? ' selected' : ''}>Todos</option></select>
      <select class="adm-select" data-on-change="bzTkSet" data-change-1="s:site" data-change-2="value"><option value="">Todas las agencias</option><option value="_web"${f.site === '_web' ? ' selected' : ''}>Web (cronostoolz.com)</option>${sites.map(([k, v]) => `<option value="${esc(k)}"${f.site === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>
      <input class="adm-input" type="search" id="bz-tkq" placeholder="Buscar por texto, persona, empresa o #número" value="${esc(f.q)}" data-on-input="bzTkSearch" data-input-1="this">
      <button class="btn btn-ghost btn-sm" data-on-click="bzReset" data-click-1="s:tk" data-click-2="null"><i class="fa-solid fa-rotate"></i></button></div>
    ${rows || '<div class="bz-msg">No hay tickets con estos filtros.</div>'}`, unread ? `<span class="bz-tag bad">${unread} sin leer</span>` : '');
}
function bzTkDetail(t) {
  const c = t.contact || {}, m = t.meta || {}, web = t.source === 'web';
  const kv = [['Origen', web ? 'Formulario de cronostoolz.com' : esc(t.site_name)], ['Persona', esc(c.name || '—') + (c.email ? ` · <a href="mailto:${esc(c.email)}">${esc(c.email)}</a>` : '')],
    c.company ? ['Empresa', esc(c.company) + (c.team ? ' · equipo de ' + esc(c.team) : '')] : null, c.phone ? ['Teléfono', esc(c.phone)] : null, m.url ? ['Página', !/^https?:\/\//i.test(String(m.url).trim()) ? esc(m.url) : `<a href="${esc(m.url)}" target="_blank" rel="noopener">${esc(m.url)}</a>`] : null,
    m.version ? ['Versión', esc(m.version)] : null, m.ua ? ['Navegador', esc(m.ua)] : null, ['Creado', esc(t.created_at)]].filter(Boolean);
  const msgs = (t.replies || []).map(r => `<div class="bz-msgx ${r.by === 'cronos' ? 'cronos' : ''}"><small>${r.by === 'cronos' ? 'Cronos' + (r.name && r.name !== 'Cronos' ? ' · ' + esc(r.name) : '') : esc(r.name || 'Agencia')} · ${esc(r.at)}</small><div>${esc(r.text)}</div></div>`).join('');
  const shots = (t.files || []).map(f => `<img data-shot="${esc(f)}" alt="Captura adjunta" ${BZ.shots[f] ? `src="${BZ.shots[f]}"` : ''} data-on-click="@stop|bzShotOpen" data-click2-1="this">`).join('');
  if ((t.files || []).length) setTimeout(bzShotsLoad, 0);
  const sel = (id, o, cur) => `<select class="adm-select" id="${id}">${Object.entries(o).map(([k, v]) => `<option value="${k}"${cur === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>`;
  return `<div class="bz-tkd" data-on-click="@stop">${t.wk ? `<div class="bz-m" style="margin-bottom:10px"><i class="fa-solid fa-briefcase"></i> Se gestiona en <a href="/toolz/trabajo/#tickets/${+t.wk.id}">Trabajo › Tickets (T-${String(+t.wk.id).padStart(4, '0')})</a>. Lo que respondas acá también aparece allá.</div>` : ''}<div class="bz-body">${esc(t.body || '(sin descripción)')}</div>${shots ? `<div class="bz-shots">${shots}</div>` : ''}
    <div class="bz-kv">${kv.map(([k, v]) => `<span>${k}</span><span>${v}</span>`).join('')}</div>${msgs ? `<div style="margin-top:12px">${msgs}</div>` : ''}
    <label class="bz-f" style="margin-top:14px"><span class="adm-label">${web ? 'Respuesta (se guarda acá; al cliente escribile por email)' : 'Responder (le llega a la persona en Soporte de su sitio)'}</span><textarea class="adm-input" id="bz-tk-reply" rows="3" placeholder="Escribe la respuesta…"></textarea></label>
    <div class="bz-foot"><button class="btn btn-primary" data-on-click="bzTkReply" data-click-1="j:${+t.id}" data-click-2="s:esperando" data-click-3="this"><i class="fa-solid fa-reply"></i> Responder y esperar respuesta</button><button class="btn btn-ghost" data-on-click="bzTkReply" data-click-1="j:${+t.id}" data-click-2="s:resuelto" data-click-3="this"><i class="fa-solid fa-check"></i> Responder y marcar resuelto</button>${web && c.email ? `<a class="btn btn-ghost" style="text-decoration:none" href="mailto:${esc(c.email)}?subject=${encodeURIComponent('Tu consulta en Cronos')}"><i class="fa-solid fa-envelope"></i> Escribir por email</a>` : ''}</div>
    <div class="bz-grid" style="margin-top:16px"><label class="bz-f"><span class="adm-label">Estado</span>${sel('bz-tk-status', BZ.tk.statuses, t.status)}</label>
      <label class="bz-f span2"><span class="adm-label">Tipo</span>${sel('bz-tk-type', Object.fromEntries(Object.entries(BZ.tk.types).map(([k, v]) => [k, (BZ_AREAS[v[0]]?.[0] || v[0]) + ' › ' + v[1]])), t.type)}</label>
      <label class="bz-f"><span class="adm-label">Prioridad</span>${sel('bz-tk-prio', { baja: 'Baja', media: 'Media', alta: 'Alta' }, t.priority)}</label>
      <label class="bz-f span4"><span class="adm-label">Notas internas (no las ve la agencia)</span><textarea class="adm-input" id="bz-tk-notes" rows="2">${esc(t.notes || '')}</textarea></label></div>
    <div class="bz-foot"><button class="btn btn-ghost" data-on-click="bzTkSave" data-click-1="j:${+t.id}" data-click-2="this"><i class="fa-solid fa-floppy-disk"></i> Guardar cambios</button><button class="btn btn-danger" data-on-click="bzTkDel" data-click-1="j:${+t.id}">Borrar ticket</button></div></div>`;
}
async function bzShotsLoad() {
  for (const img of document.querySelectorAll('img[data-shot]:not([src])')) {
    const f = img.dataset.shot; if (BZ.shots[f] === '…') continue; BZ.shots[f] = '…';
    try { const r = await bzApi({ act: 'tk_file', file: f }); BZ.shots[f] = `data:${r.mime};base64,${r.data}`; document.querySelectorAll(`img[data-shot="${CSS.escape(f)}"]`).forEach(i => { i.src = BZ.shots[f]; }); } catch (e) { delete BZ.shots[f]; }
  }
}
function bzShotOpen(img) { if (!img.src) return; const w = window.open(); if (w) w.document.write(`<title>Captura</title><body style="margin:0;background:#111;display:grid;place-items:center;min-height:100vh"><img src="${img.src}" style="max-width:100%">`); }
async function bzTkOpen(id) {
  BZ.tkOpen = BZ.tkOpen === id ? null : id; const t = BZ.tk.tickets.find(x => x.id === id);
  if (BZ.tkOpen && t?.unread) { try { await bzApi({ act: 'tk_read', id }); t.unread = false; BZ.d.tickets_unread = Math.max(0, (BZ.d.tickets_unread || 1) - 1); } catch (e) {} }
  bzDraw();
}
function bzTkPut(t) { const i = BZ.tk.tickets.findIndex(x => x.id === t.id); if (i >= 0) BZ.tk.tickets[i] = t; }
async function bzTkReply(id, status, btn) {
  const text = $id('bz-tk-reply').value.trim(); if (!text) return admToast('Escribe la respuesta', true);
  btn.disabled = true; try { const r = await bzApi({ act: 'tk_reply', id, text, status }); bzTkPut(r.ticket); admToast('Respuesta guardada'); bzDraw(); } catch (e) { btn.disabled = false; }
}
async function bzTkSave(id, btn) {
  btn.disabled = true;
  try { const r = await bzApi({ act: 'tk_save', id, status: $id('bz-tk-status').value, type: $id('bz-tk-type').value, priority: $id('bz-tk-prio').value, notes: $id('bz-tk-notes').value }); bzTkPut(r.ticket); admToast('Ticket guardado'); bzDraw(); } catch (e) { btn.disabled = false; }
}
async function bzTkDel(id) { if (!confirm('¿Borrar este ticket? No se puede deshacer.')) return; try { await bzApi({ act: 'tk_delete', id }); BZ.tk.tickets = BZ.tk.tickets.filter(x => x.id !== id); BZ.tkOpen = null; admToast('Ticket borrado'); bzDraw(); } catch (e) {} }

// ── Estadísticas de la red ──
function bzBars(rows, key, fmt) {
  if (!rows.length) return '<div class="bz-muted">Todavía no hay datos.</div>';
  const W = 300, H = 120, max = Math.max(1, ...rows.map(r => +r[key] || 0)), bw = W / Math.max(rows.length, 6);
  return `<svg viewBox="0 0 ${W} ${H + 18}" role="img" aria-label="Evolución mensual">${rows.map((r, i) => { const v = +r[key] || 0, h = Math.max(2, v / max * (H - 16)), x = i * bw + bw * .18, w = bw * .64;
    return `<g><rect x="${x}" y="${H - h}" width="${w}" height="${h}" rx="3" style="fill:var(--g7-accent)" opacity="${i === rows.length - 1 ? 1 : .55}"><title>${esc(r.month)}: ${esc(fmt(v))}</title></rect>
      <text x="${x + w / 2}" y="${H - h - 4}" text-anchor="middle" font-size="9" style="fill:var(--g7-text-2)">${esc(fmt(v))}</text>
      <text x="${x + w / 2}" y="${H + 13}" text-anchor="middle" font-size="9" style="fill:var(--g7-text-4)">${esc(new Date(r.month + '-15').toLocaleDateString('es-AR', { month: 'short', year: '2-digit' }))}</text></g>`; }).join('')}</svg>`;
}
function bzDrawRed() {
  const d = BZ.d, rows = d.sites, st = rows.map(s => s.stats || {}), sum = k => st.reduce((a, x) => a + (+x[k] || 0), 0), on = rows.filter(s => !s.unreachable).length;
  const kpi = [['Agencias', bzNum(rows.length)], ['En línea', `${on}/${rows.length}`], ['Usuarios activos', bzNum(sum('users'))], ['Activos en 7 días', bzNum(sum('active_7d'))], ['Clientes', bzNum(sum('clients'))], ['Espacio', bzGb(sum('bytes')) + ' GB']];
  const lic = id => { const l = d.limits[id]; return l && l.plan ? `${esc(l.plan)}<small>${esc(bzDate(l.expires))}</small>` : '<span class="bz-muted">Sin licencia</span>'; };
  const health = s => s.unreachable ? '<span class="bz-tag bad">Sin conexión</span>' : !s.stats ? '<span class="bz-tag warn">Falta actualizar</span>' : !s.stats.db_ok ? '<span class="bz-tag bad">La base no responde</span>' : '<span class="bz-tag ok">En línea</span>';
  const tr = rows.map(s => { const x = s.stats || {}; return `<tr><td><b>${esc(s.name)}</b><small>${esc(String(s.url).replace(/^https?:\/\//, ''))}</small></td><td>${health(s)}<small>${esc(s.version || '—')}${x.php ? ' · PHP ' + esc(x.php) : ''}</small></td>
    <td class="bz-n">${s.stats ? bzNum(x.users) : '—'}<small>${s.stats ? bzNum(x.admins) + ' admin' : ''}</small></td><td class="bz-n">${s.stats ? bzNum(x.active_7d) : '—'}<small>${s.stats ? bzNum(x.active_30d) + ' en 30 d' : ''}</small></td>
    <td class="bz-n">${x.clients == null ? '—' : bzNum(x.clients)}</td><td class="bz-n">${x.bytes != null ? bzGb(x.bytes) + ' GB' : '—'}</td><td>${lic(s.id)}</td><td>${s.stats ? esc(bzAgo(x.last_login)) : '—'}</td></tr>`; }).join('');
  const m = d.monthly || [];
  const mt = m.slice().reverse().map(r => `<tr><td>${esc(new Date(r.month + '-15').toLocaleDateString('es-AR', { month: 'long', year: 'numeric' }))}</td><td class="bz-n">${bzNum(r.sites)}</td><td class="bz-n">${bzNum(r.online)}</td><td class="bz-n">${bzNum(r.users)}</td><td class="bz-n">${bzNum(r.active_7d)}</td><td class="bz-n">${bzNum(r.clients)}</td><td class="bz-n">${bzGb(r.bytes)} GB</td></tr>`).join('');
  return `<div class="bz-kpis">${kpi.map(([k, v]) => `<div class="adm-stat"><div class="adm-stat-val">${v}</div><div class="adm-stat-label">${k}</div></div>`).join('')}</div>
    ${bzCard('Agencias', 'fa-building', `<div class="bz-tblw"><table class="adm-table"><thead><tr><th>Agencia</th><th>Estado</th><th class="bz-n">Usuarios</th><th class="bz-n">Activos 7 d</th><th class="bz-n">Clientes</th><th class="bz-n">Espacio</th><th>Plan</th><th>Último ingreso</th></tr></thead><tbody>${tr || '<tr><td colspan="8">Todavía no hay agencias.</td></tr>'}</tbody></table></div>`)}
    ${bzCard('Evolución de la red', 'fa-chart-column', `<div class="bz-charts"><div><h4>Usuarios activos</h4>${bzBars(m, 'users', bzNum)}</div><div><h4>Clientes</h4>${bzBars(m, 'clients', bzNum)}</div><div><h4>Espacio (GB)</h4>${bzBars(m, 'bytes', bzGb)}</div></div>`,
      `<span class="bz-muted">${m.length < 2 ? 'Se arma con una foto por día cada vez que se consulta el estado' : 'Último dato de cada mes'}</span>`)}
    ${m.length ? bzCard('Historial mensual', 'fa-table', `<div class="bz-tblw"><table class="adm-table"><thead><tr><th>Mes</th><th class="bz-n">Agencias</th><th class="bz-n">En línea</th><th class="bz-n">Usuarios</th><th class="bz-n">Activos 7 d</th><th class="bz-n">Clientes</th><th class="bz-n">Espacio</th></tr></thead><tbody>${mt}</tbody></table></div>`) : ''}`;
}

// Al abrir el Admin: si la sección de la red ya está elegida (por #), se carga; si no, solo se cuentan los tickets sin leer para el menú (pedido liviano)
function bzBoot() {
  if (bzSec()) { bzLoad(false); return; }
  fetch('/toolz/base_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': window.BZ_CSRF }, body: JSON.stringify({ act: 'tickets' }) })
    .then(r => r.json()).then(d => { if (d.tickets && !BZ.d) { const b = $id('bz-tk-badge'), n = d.tickets.filter(t => t.unread).length; if (b) { b.textContent = n; b.style.display = n ? '' : 'none'; } } }).catch(() => {});
}
if (document.readyState !== 'loading') setTimeout(bzBoot, 300); else document.addEventListener('DOMContentLoaded', () => setTimeout(bzBoot, 300));

// ── Eventos que antes eran código en línea (CSP estricta, SEC-24 fase 2) ──
function bzReset(key, val) { BZ[key] = val; bzDraw(); }
function bzModWrap(el, sel, val) { el.closest(sel).querySelector('.bz-modwrap').style.display = el.value === val ? '' : 'none'; }
function bzTkFilter(area, type) { BZ.tkf.area = area; BZ.tkf.type = type; BZ.tkOpen = null; bzDraw(); }
function bzTkSet(k, v) { BZ.tkf[k] = v; bzDraw(); }
function bzTkSearch(el) { BZ.tkf.q = el.value; clearTimeout(BZ._q); BZ._q = setTimeout(() => { bzDraw(); const i = $id('bz-tkq'); i.focus(); i.setSelectionRange(i.value.length, i.value.length); }, 250); }
