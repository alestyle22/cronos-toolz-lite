/* admin_rubro.js — Admin › Rubro, Campos de la ficha y Estados (admin_rubro.php). Usa esc(), $id() y admToast() del panel.
   Acciones en rubro_api.php. Textos en español neutro. Doc: toolz/RUBROS.md */
'use strict';

const RB = { d: null, pick: null, diff: null, parts: null, samples: false, editField: null }; window.RB = RB;   // g7-on lo busca en window
const RB_PART_HELP = { vocab: 'Cómo se llaman clientes, contenidos, proyectos y lo demás.', types: 'Por ejemplo, propietario e interesado.', statuses: 'Clientes, servicios y proyectos. Los estados en uso se conservan.',
  fields: 'Agrega los campos del rubro y oculta los que no usa. No borra ninguno.', contents: 'Muestra los tipos del rubro y oculta los demás (no se borran).', home: 'Los accesos del Inicio.',
  templates: 'Suma plantillas de tareas y define las carpetas de archivos de cada ficha nueva.', modules: 'Apaga las herramientas que el rubro no usa. Se vuelven a prender si cambias de rubro.', look: 'El color principal de la plataforma.', billing: 'Sugerencias para las líneas de las facturas.' };

async function rbApi(action, data = {}) {
  let d;
  try { const r = await fetch('/toolz/rubro_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) }); d = await r.json(); }
  catch (e) { d = { error: 'No se pudo conectar con el servidor.' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
async function rbLoad(force) { if (!RB.d || force) RB.d = await rbApi('state'); rbDrawAll(); }
function rbSet(d) { if (d && d.rubros) RB.d = d; rbDrawAll(); }
function rbDrawAll() { rbDrawRubro(); rbDrawFields(); rbDrawStates(); }
const rbCard = (icon, title, body, extra = '') => `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid ${icon}"></i> ${title}</div>${extra}</div><div class="adm-card-body">${body}</div></div>`;
const rbTerm = (k, f = 's') => RB.d?.terms?.[k]?.[f] || k;

// ── Rubro ───────────────────────────────────────────────────
function rbDrawRubro() {
  const d = RB.d, box = $id('rb-body'); if (!d || !box) return;
  const cur = d.rubro, rubros = d.rubros;
  const lic = d.license_rubro && d.license_rubro !== cur ? `<div class="rb-warn"><i class="fa-solid fa-circle-info"></i> Tu licencia indica el rubro <b>${esc(rubros.find(r => r.id === d.license_rubro)?.name || d.license_rubro)}</b>. Puedes aplicarlo eligiéndolo abajo.</div>` : '';
  const cards = rubros.map(r => `<button type="button" class="rb-card${(RB.pick || cur) === r.id ? ' on' : ''}" style="--rb:${esc(r.accent)}" data-on-click="rbPick" data-click-1="s:${esc(r.id)}">
    <span style="display:flex;justify-content:space-between;align-items:center"><span class="ic"><i class="fa-solid ${esc(r.icon)}"></i></span>${cur === r.id ? '<span class="rb-tag">Actual</span>' : ''}</span>
    <b>${esc(r.name)}</b><small>${esc(r.desc)}</small>${r.types.length ? `<small><b style="font-size:11px;color:var(--g7-text-3)">${esc(r.client)}:</b> ${esc(r.types.join(', '))}</small>` : ''}</button>`).join('');
  let change = '';
  if (RB.pick && RB.diff) {
    const p = rubros.find(r => r.id === RB.pick);
    change = rbCard('fa-shuffle', `Cambiar a ${esc(p.name)}`, `<p class="rb-msg" style="margin-bottom:10px">Elige qué partes aplicar. Lo que no marques queda como está. Nada se borra: los datos, los estados en uso y los campos propios se conservan.</p>
      <div class="rb-parts">${Object.entries(d.parts).map(([k, label]) => { const lines = RB.diff[k] || [], same = !lines.length; return `<label class="rb-part${same ? ' same' : ''}"><input type="checkbox" data-part="${k}"${RB.parts.includes(k) ? ' checked' : ''}${same ? ' disabled' : ''} data-on-change="rbParts">
        <span><b>${esc(label)}</b><small>${same ? 'Sin cambios' : lines.map(esc).join('<br>')}</small><small style="opacity:.8">${esc(RB_PART_HELP[k] || '')}</small></span></label>`; }).join('')}</div>
      ${d.clients && !d.samples ? `<label class="rb-part" style="margin-top:10px;max-width:520px"><input type="checkbox" id="rb-samples"${RB.samples ? ' checked' : ''} data-on-change="@set" data-change-1="s:RB.samples" data-change-2="checked"><span><b>Cargar datos de ejemplo</b><small>Algunas fichas y tareas de muestra para recorrer la plataforma. Se borran con un botón.</small></span></label>` : ''}
      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap"><button class="btn btn-primary" id="rb-apply" data-on-click="rbApply"><i class="fa-solid fa-check"></i> Aplicar ${esc(p.name)}</button><button class="btn btn-ghost" data-on-click="rbBack">Cancelar</button></div>`);
  }
  const terms = Object.entries(d.term_labels).map(([k, label]) => `<div class="rb-row"><span>${esc(label)}</span>
      <input class="adm-input" data-v="${k}" data-f="s" value="${esc(d.terms[k].s)}" maxlength="40" placeholder="Singular">
      <input class="adm-input" data-v="${k}" data-f="p" value="${esc(d.terms[k].p)}" maxlength="40" placeholder="Plural">
      <select class="adm-select" data-v="${k}" data-f="g"><option value="m">Masculino</option><option value="f"${d.terms[k].g === 'f' ? ' selected' : ''}>Femenino</option></select></div>`).join('');
  box.innerHTML = `${lic}
    ${rbCard('fa-briefcase', 'Rubro de esta plataforma', `<p class="rb-msg" style="margin-bottom:12px">Ahora: <b>${esc(d.rubro_name)}</b>. Elige otro para ver qué cambiaría antes de aplicarlo.</p><div class="rb-grid">${cards}</div>`)}
    ${change}
    ${rbCard('fa-spell-check', 'Nombres', `<p class="rb-msg" style="margin-bottom:8px">Cómo se llaman las cosas en toda la plataforma: menú, fichas, facturas, Inicio y panel del cliente.</p>${terms}
      <div style="display:flex;gap:8px;margin-top:12px"><button class="btn btn-primary" data-on-click="rbVocab"><i class="fa-solid fa-floppy-disk"></i> Guardar nombres</button></div>`)}
    ${d.clients ? rbCard('fa-flask', 'Datos de ejemplo', d.samples
      ? `<p class="rb-msg" style="margin-bottom:12px">Hay fichas y tareas de ejemplo cargadas. Al borrarlas se quitan solo esas: lo que cargaste tú queda.</p><button class="btn btn-danger" data-on-click="rbSamplesClear" data-click-1="this"><i class="fa-solid fa-trash"></i> Borrar los datos de ejemplo</button>`
      : `<p class="rb-msg" style="margin-bottom:12px">No hay datos de ejemplo. Puedes cargar algunos del rubro actual para probar.</p><button class="btn btn-ghost" data-on-click="rbSamplesSeed" data-click-1="this"><i class="fa-solid fa-plus"></i> Cargar datos de ejemplo</button>`) : ''}`;
}
async function rbPick(id) {
  if (id === RB.pick) return;
  RB.pick = id; RB.diff = null; rbDrawRubro();
  try { const r = await rbApi('diff', { id }); RB.diff = r.diff; RB.parts = Object.keys(r.diff).filter(k => r.diff[k].length); RB.samples = false; } catch (e) { RB.pick = null; }
  rbDrawRubro(); $id('rb-apply')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function rbParts() { RB.parts = [...document.querySelectorAll('[data-part]:checked')].map(i => i.dataset.part); }
async function rbApply() {
  rbParts(); const p = RB.d.rubros.find(r => r.id === RB.pick);
  if (!RB.parts.length && !RB.samples) return admToast('Marca al menos una parte', true);
  if (!confirm(`¿Aplicar ${p.name}? Las partes marcadas cambian para todo el equipo. No se borran datos.`)) return;
  const b = $id('rb-apply'); b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Aplicando…';
  try { const r = await rbApi('apply', { id: RB.pick, parts: RB.parts, samples: RB.samples }); RB.pick = null; RB.diff = null; rbSet(r); admToast(`${p.name} aplicado. Recarga las otras pestañas para ver los cambios.`); }
  catch (e) { b.disabled = false; b.innerHTML = `<i class="fa-solid fa-check"></i> Aplicar ${esc(p.name)}`; }
}
async function rbVocab() {
  const v = {}; document.querySelectorAll('[data-v]').forEach(el => { (v[el.dataset.v] ||= {})[el.dataset.f] = el.value.trim(); });
  try { rbSet(await rbApi('vocab', { vocab: v })); admToast('Nombres guardados. Recarga las otras pestañas para verlos.'); } catch (e) {}
}
async function rbSamplesClear(b) { if (!confirm('¿Borrar los datos de ejemplo? Solo se quitan las fichas, propiedades y tareas de muestra.')) return; b.disabled = true; try { const r = await rbApi('samples_clear'); rbSet(r); admToast(`Se borraron ${r.cleared.clients} fichas${r.cleared.props ? `, ${r.cleared.props} propiedades` : ''}${r.cleared.due ? `, ${r.cleared.due} obligaciones` : ''}${r.cleared.appts ? `, ${r.cleared.appts} turnos` : ''} y ${r.cleared.tasks} tareas de ejemplo`); } catch (e) { b.disabled = false; } }
async function rbSamplesSeed(b) { b.disabled = true; try { const r = await rbApi('samples_seed'); rbSet(r); admToast(`Se cargaron ${r.seeded.clients} fichas${r.seeded.props ? `, ${r.seeded.props} propiedades` : ''}${r.seeded.due ? `, ${r.seeded.due} obligaciones asignadas` : ''}${r.seeded.appts ? `, ${r.seeded.appts} turnos` : ''} y ${r.seeded.tasks} tareas de ejemplo`); } catch (e) { b.disabled = false; } }

// ── Campos de la ficha ─────────────────────────────────────
function rbDrawFields() {
  const d = RB.d, box = $id('rb-fields'); if (!d || !box) return;
  if (!d.clients) { box.innerHTML = rbCard('fa-list-check', 'Campos', '<p class="rb-msg">Esta plataforma no usa fichas de clientes.</p>'); return; }
  const cl = rbTerm('client', 'p'), lim = d.types_limit;
  const types = `<p class="rb-msg" style="margin-bottom:10px">Sirven para separar ${esc(cl.toLowerCase())} en pestañas (por ejemplo, propietarios e interesados). Sin tipos, todos van juntos.${lim ? ` Tu plan permite hasta <b>${lim}</b>.` : ''}</p>
    <div id="rb-types">${d.types.map(t => rbTypeRow(t)).join('')}</div>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap"><button class="btn btn-ghost btn-sm" data-on-click="rbTypeAdd"${lim && d.types.length >= lim ? ' disabled' : ''}><i class="fa-solid fa-plus"></i> Agregar tipo</button><button class="btn btn-primary btn-sm" data-on-click="rbTypesSave"><i class="fa-solid fa-floppy-disk"></i> Guardar tipos</button></div>`;
  const ft = d.field_types, ef = RB.editField;
  const form = ef ? `<div class="adm-card" style="border-color:var(--g7-accent-border)"><div class="adm-card-body"><div class="adm-form-grid">
      <div><label class="adm-label">Nombre</label><input class="adm-input" id="rbf-label" value="${esc(ef.label || '')}" maxlength="80" placeholder="Ej.: Presupuesto"></div>
      <div><label class="adm-label">Tipo</label><select class="adm-select" id="rbf-type" data-on-change="rbFieldType" data-change-1="this">${Object.entries(ft).map(([k, v]) => `<option value="${k}"${(ef.type || 'text') === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></div>
      ${(ef.type || 'text') === 'select' ? `<div style="grid-column:1/-1"><label class="adm-label">Opciones (una por línea)</label><textarea class="adm-input" id="rbf-opts" rows="4" style="resize:vertical">${esc((ef.options || []).join('\n'))}</textarea></div>` : ''}
      <div><label class="adm-label">Grupo (opcional)</label><input class="adm-input" id="rbf-group" value="${esc(ef.group || '')}" maxlength="40" placeholder="Ej.: Búsqueda"></div>
      <div style="display:flex;gap:16px;align-items:end;padding-bottom:6px"><label class="rb-chip${ef.required ? ' on' : ''}"><input type="checkbox" id="rbf-req"${ef.required ? ' checked' : ''}> Obligatorio</label><label class="rb-chip${ef.hidden ? ' on' : ''}"><input type="checkbox" id="rbf-hid"${ef.hidden ? ' checked' : ''}> Oculto</label></div></div>
      <div style="display:flex;gap:8px"><button class="btn btn-primary" data-on-click="rbFieldSave"><i class="fa-solid fa-floppy-disk"></i> ${ef.id ? 'Guardar campo' : 'Crear campo'}</button><button class="btn btn-ghost" data-on-click="rbFieldCancel">Cancelar</button></div></div></div>` : '';
  const list = d.fields.map(f => `<div class="rb-fl" draggable="true" data-id="${f.id}"><i class="fa-solid fa-grip-vertical h" title="Arrastra para ordenar"></i>
      <span><b>${esc(f.label)}</b>${f.required ? ' <small>· obligatorio</small>' : ''}${f.hidden ? ' <small>· oculto</small>' : ''}</span><small>${esc(ft[f.type] || f.type)}</small><small>${esc(f.group || '')}${f.type === 'select' ? (f.group ? ' · ' : '') + f.options.length + ' opciones' : ''}</small>
      <span style="display:flex;gap:6px"><button class="btn-icon" title="Editar" data-on-click="rbFieldEdit" data-click-1="j:${f.id}"><i class="fa-solid fa-pen"></i></button><button class="btn-icon" title="Borrar" data-on-click="rbFieldDel" data-click-1="j:${f.id}"><i class="fa-solid fa-trash"></i></button></span></div>`).join('');
  const hidden = Object.entries(d.builtin).map(([k, v]) => { const on = !d.hidden.includes(k); return `<label class="rb-chip${on ? ' on' : ''}"><input type="checkbox" data-hid="${k}"${on ? ' checked' : ''} data-on-change="@parentOn"> ${esc(v)}</label>`; }).join('');
  box.innerHTML = `${rbCard('fa-tags', `Tipos de ${esc(rbTerm('client').toLowerCase())}`, types)}
    ${form}
    ${rbCard('fa-list-check', 'Campos propios', `${list || `<p class="rb-msg">Todavía no hay campos propios. Crea los que necesites: aparecen en cada ficha, en "Más datos".</p>`}`, ef ? '' : '<button class="btn btn-primary btn-sm" data-on-click="rbFieldNew"><i class="fa-solid fa-plus"></i> Nuevo campo</button>')}
    ${rbCard('fa-eye', 'Campos de siempre', `<p class="rb-msg" style="margin-bottom:10px">Desmarca los que no se usan: dejan de verse en la ficha (lo que ya tienen cargado se conserva).</p><div class="rb-chips">${hidden}</div>
      <div style="margin-top:12px"><button class="btn btn-primary btn-sm" data-on-click="rbHiddenSave"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>`)}`;
  rbDragBind();
}
const rbTypeRow = t => `<div style="display:flex;gap:8px;margin-bottom:6px" data-type="${esc(t.key || '')}"><input class="adm-input" value="${esc(t.label || '')}" maxlength="40" placeholder="Nombre del tipo"><button class="btn-icon" title="Quitar" data-on-click="@removeParent"><i class="fa-solid fa-xmark"></i></button></div>`;
function rbTypeAdd() { $id('rb-types').insertAdjacentHTML('beforeend', rbTypeRow({})); $id('rb-types').lastElementChild.querySelector('input').focus(); }
async function rbTypesSave() {
  const types = [...document.querySelectorAll('#rb-types [data-type]')].map(r => ({ key: r.dataset.type, label: r.querySelector('input').value.trim() })).filter(t => t.label);
  try { rbSet(await rbApi('types', { types })); admToast('Tipos guardados'); } catch (e) {}
}
function rbFieldEdit(id) { const f = RB.d.fields.find(x => x.id === id); RB.editField = JSON.parse(JSON.stringify(f)); rbDrawFields(); $id('rb-fields').scrollIntoView({ behavior: 'smooth' }); }
async function rbFieldSave() {
  const ef = RB.editField, field = { label: $id('rbf-label').value, type: $id('rbf-type').value, group: $id('rbf-group').value, required: $id('rbf-req').checked, hidden: $id('rbf-hid').checked,
    options: $id('rbf-opts') ? $id('rbf-opts').value.split('\n').map(s => s.trim()).filter(Boolean) : [] };
  if (!field.label.trim()) return admToast('El campo necesita un nombre', true);
  try { const r = await rbApi('field_save', { id: ef.id || 0, field }); RB.editField = null; rbSet(r); admToast(ef.id ? 'Campo guardado' : 'Campo creado'); } catch (e) {}
}
async function rbFieldDel(id) {
  const f = RB.d.fields.find(x => x.id === id);
  if (!confirm(`¿Borrar el campo "${f.label}" y lo que tiene cargado en cada ficha? Si solo quieres dejar de verlo, edítalo y márcalo como oculto.`)) return;
  try { rbSet(await rbApi('field_delete', { id })); admToast('Campo borrado'); } catch (e) {}
}
async function rbHiddenSave() {
  const hidden = [...document.querySelectorAll('[data-hid]')].filter(i => !i.checked).map(i => i.dataset.hid);
  try { rbSet(await rbApi('hidden', { hidden })); admToast('Listo'); } catch (e) {}
}
function rbDragBind() {
  let drag = null;
  document.querySelectorAll('.rb-fl').forEach(el => {
    el.ondragstart = () => { drag = el; el.classList.add('drag'); };
    el.ondragend = async () => {
      el.classList.remove('drag'); if (!drag) return; drag = null;
      const ids = [...document.querySelectorAll('.rb-fl')].map(x => +x.dataset.id);
      if (ids.join() !== RB.d.fields.map(f => f.id).join()) try { rbSet(await rbApi('field_order', { ids })); admToast('Orden guardado'); } catch (e) {}
    };
    el.ondragover = e => { e.preventDefault(); if (!drag || drag === el) return; const r = el.getBoundingClientRect(); el.parentNode.insertBefore(drag, e.clientY < r.top + r.height / 2 ? el : el.nextSibling); };
  });
}

// ── Estados ─────────────────────────────────────────────────
function rbDrawStates() {
  const d = RB.d, box = $id('rb-states'); if (!d || !box) return;
  box.innerHTML = Object.entries(d.statuses).filter(([k]) => d.clients || !['clients', 'services', 'invoices'].includes(k)).map(([k, L]) => rbCard('fa-circle-half-stroke', esc(L.label), `
    <div data-list="${k}">${L.items.map(s => rbStRow(s, L.fixed)).join('')}</div>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">${L.fixed ? '<span class="rb-lock">Estas se calculan solas: solo cambian el nombre y el color.</span>' : `<button class="btn btn-ghost btn-sm" data-on-click="rbStAdd" data-click-1="s:${k}"><i class="fa-solid fa-plus"></i> Agregar estado</button>`}
      <button class="btn btn-primary btn-sm" data-on-click="rbStSave" data-click-1="s:${k}"><i class="fa-solid fa-floppy-disk"></i> Guardar</button><button class="btn btn-ghost btn-sm" data-on-click="rbStReset" data-click-1="s:${k}">Volver a los de siempre</button></div>`)).join('');
}
const rbStRow = (s, fixed) => `<div class="rb-st" data-key="${esc(s.key || '')}">
  <span style="display:flex;flex-direction:column;gap:2px">${fixed ? '' : `<button class="btn-icon" style="padding:0 4px;font-size:9px" title="Subir" data-on-click="rbStMove" data-click-1="this" data-click-2="n:-1"><i class="fa-solid fa-chevron-up"></i></button><button class="btn-icon" style="padding:0 4px;font-size:9px" title="Bajar" data-on-click="rbStMove" data-click-1="this" data-click-2="n:1"><i class="fa-solid fa-chevron-down"></i></button>`}</span>
  <input type="color" value="${esc(s.color || '#8b8b94')}"><input class="adm-input" value="${esc(s.label || '')}" maxlength="40" placeholder="Nombre del estado">
  ${s.locked ? '<span class="rb-lock" title="Se usa para calcular otras cosas: no se puede borrar"><i class="fa-solid fa-lock"></i></span>' : `<button class="btn-icon" title="Quitar" data-on-click="@removeParent"><i class="fa-solid fa-xmark"></i></button>`}</div>`;
function rbStAdd(list) { const box = document.querySelector(`[data-list="${list}"]`); box.insertAdjacentHTML('beforeend', rbStRow({ color: '#60a5fa' })); box.lastElementChild.querySelector('.adm-input').focus(); }
function rbStMove(btn, dir) { const row = btn.closest('.rb-st'), sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling; if (sib) row.parentNode.insertBefore(row, dir < 0 ? sib : sib.nextSibling); }
async function rbStSave(list) {
  const items = [...document.querySelectorAll(`[data-list="${list}"] .rb-st`)].map(r => ({ key: r.dataset.key, color: r.querySelector('input[type=color]').value, label: r.querySelector('.adm-input').value.trim() })).filter(x => x.label);
  try { rbSet(await rbApi('statuses', { list, items })); admToast('Estados guardados'); } catch (e) {}
}
async function rbStReset(list) { if (!confirm('¿Volver a los estados de siempre en esta lista?')) return; try { rbSet(await rbApi('statuses', { list, reset: 1 })); admToast('Listo'); } catch (e) {} }

SECTION_LOADERS.rubro = () => rbLoad(true);
SECTION_LOADERS.campos = () => rbLoad(true);
SECTION_LOADERS.estados = () => rbLoad(true);

// ── Eventos que antes eran código en línea (CSP estricta) ──
function rbBack() { RB.pick = null; RB.diff = null; rbDrawRubro(); }
function rbFieldType(el) { RB.editField.type = el.value; RB.editField.label = $id('rbf-label').value; rbDrawFields(); }
function rbFieldCancel() { RB.editField = null; rbDrawFields(); }
function rbFieldNew() { RB.editField = { type: 'text' }; rbDrawFields(); $id('rbf-label').focus(); }
