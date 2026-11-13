/* builder.js — Constructor visual de formularios (builder.php). Arrastrar y soltar con eventos de puntero (mouse, lápiz y dedo),
   secciones y grupos, propiedades, condiciones Y/O, reglas de tiempo, tema, vista previa desktop/mobile, deshacer, borrador y versiones.
   Lo que se guarda lo limpia y valida el servidor (portal_api.php form_save / form_publish). Doc: toolz/SOLICITUDES.md */
'use strict';

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let toastT;
function toast(msg, err = false) { const t = $('wk-toast'); t.textContent = msg; t.classList.toggle('err', err); t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), err ? 4200 : 2400); }
async function api(action, data = {}) {
  const r = await fetch('portal_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) });
  let j; try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió algo inesperado'); }
  if (r.status === 401) { location.href = '/toolz/login.php'; throw new Error(j.error); }
  if (!r.ok || j.error) { const e = new Error(j.error || 'No se pudo completar'); e.data = j; e.status = r.status; throw e; }
  return j;
}

const PAL = [
  ['Texto', [['text', 'fa-font', 'Texto corto'], ['textarea', 'fa-align-left', 'Texto largo'], ['number', 'fa-hashtag', 'Número'], ['email', 'fa-at', 'Email'], ['phone', 'fa-phone', 'Teléfono'], ['url', 'fa-link', 'Link']]],
  ['Selección', [['select', 'fa-square-caret-down', 'Desplegable'], ['multiselect', 'fa-list-check', 'Selección múltiple'], ['radio', 'fa-circle-dot', 'Opción única'], ['checkbox', 'fa-square-check', 'Casilla'], ['priority', 'fa-flag', 'Prioridad'], ['catpick', 'fa-shapes', 'Categorías visuales']]],
  ['Fecha y archivos', [['date', 'fa-calendar', 'Fecha'], ['time', 'fa-clock', 'Hora'], ['color', 'fa-palette', 'Color'], ['file', 'fa-paperclip', 'Archivo'], ['image', 'fa-image', 'Imagen']]],
  ['Diseño', [['heading', 'fa-heading', 'Encabezado'], ['paragraph', 'fa-paragraph', 'Descripción'], ['info', 'fa-circle-info', 'Bloque informativo'], ['separator', 'fa-minus', 'Separador'], ['group', 'fa-object-group', 'Grupo']]],
  ['Especiales', [['approval', 'fa-stamp', 'Aprobación'], ['hidden', 'fa-eye-slash', 'Oculto interno']]],
];
const ICON = Object.fromEntries(PAL.flatMap(g => g[1]).map(([t, i]) => [t, i]));
const NAME = Object.fromEntries(PAL.flatMap(g => g[1]).map(([t, , l]) => [t, l]));
const VALUED = new Set(['text', 'textarea', 'number', 'email', 'phone', 'select', 'multiselect', 'radio', 'checkbox', 'date', 'time', 'color', 'file', 'image', 'url', 'priority', 'catpick', 'approval', 'hidden', 'person']);
const SEC_ICONS = ['fa-circle-dot', 'fa-shapes', 'fa-pen', 'fa-user', 'fa-calendar', 'fa-paperclip', 'fa-list-check', 'fa-palette', 'fa-bullhorn', 'fa-circle-info', 'fa-star', 'fa-bolt'];
const B = { sel: { kind: 'form' }, hist: [], hi: -1, dirty: false, device: 'desktop' };
const uid = p => p + Math.random().toString(36).slice(2, 7);
const slug = s => (String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 30)) || 'campo';

function newItem(type) {
  const it = { id: '', type, label: NAME[type] || 'Campo', width: '1/1' };
  if (['select', 'multiselect', 'radio'].includes(type)) it.options = [{ v: 'opcion_1', l: 'Opción 1' }, { v: 'opcion_2', l: 'Opción 2' }];
  if (type === 'info') { it.tone = 'info'; it.label = 'Importante'; it.desc = 'Un texto para orientar a quien pide.'; }
  if (type === 'paragraph') { it.label = ''; it.desc = 'Contá acá algo que ayude a completar el formulario.'; }
  if (type === 'heading') it.label = 'Título';
  if (type === 'separator') it.label = '';
  if (type === 'group') { it.label = 'Grupo'; it.items = []; }
  if (type === 'catpick') { it.label = '¿Qué necesitás?'; it.required = true; }
  if (type === 'hidden') { it.label = 'Dato interno'; it.default = ''; }
  if (type === 'approval') { it.label = 'Aprobación'; it.pick = false; }
  if (type === 'file' || type === 'image') it.valid = { maxfiles: 5, maxmb: 25 };
  it.id = uniqueId(slug(it.label || type));
  return it;
}
function allItems(schema = B.schema) { const out = []; for (const s of schema.sections) for (const it of s.items) { out.push(it); if (it.type === 'group') for (const x of it.items || []) out.push(x); } return out; }
function uniqueId(base, except = null) { const ids = new Set(allItems().filter(x => x !== except).map(x => x.id)); let id = base, n = 2; while (ids.has(id) || /^__/.test(id)) id = base + '_' + n++; return id; }
/** Dónde está un elemento: { list, idx, sec, group }. */
function locate(id) {
  for (const sec of B.schema.sections) {
    const i = sec.items.findIndex(x => x.id === id); if (i >= 0) return { list: sec.items, idx: i, sec, group: null };
    for (const g of sec.items) if (g.type === 'group') { const j = (g.items || []).findIndex(x => x.id === id); if (j >= 0) return { list: g.items, idx: j, sec, group: g }; }
  }
  return null;
}
const secById = id => B.schema.sections.find(s => s.id === id);

// ── Historial y guardado ─────────────────────────────────────
const snap = () => JSON.stringify({ s: B.schema, t: B.theme, m: B.meta });
function commit(redrawCanvas = true) {
  const s = snap(); if (B.hist[B.hi] === s) return;
  B.hist = B.hist.slice(0, B.hi + 1); B.hist.push(s); if (B.hist.length > 80) B.hist.shift(); B.hi = B.hist.length - 1;
  B.dirty = true; status(); if (redrawCanvas) drawCanvas(); saveSoon();
}
function restore(s) { const x = JSON.parse(s); B.schema = x.s; B.theme = x.t; B.meta = x.m; B.dirty = true; drawAll(); saveSoon(); }
function undo() { if (B.hi > 0) { B.hi--; restore(B.hist[B.hi]); } }
function redo() { if (B.hi < B.hist.length - 1) { B.hi++; restore(B.hist[B.hi]); } }
let saveT;
function saveSoon() { clearTimeout(saveT); saveT = setTimeout(() => save(), 1500); }
async function save(force = false) {
  clearTimeout(saveT); if (B.saving) { saveSoon(); return; }
  if (!B.dirty && !force) return true;
  B.saving = true; status('Guardando…');
  try {
    const r = await api('form_save', { id: B.id, schema: B.schema, theme: B.theme, meta: B.meta, updated_at: B.updated_at });
    B.updated_at = r.updated_at; B.dirty = false; B.savedAt = Date.now(); B.saving = false; status(); return true;
  } catch (e) {
    B.saving = false; status();
    if (e.data && e.data.conflict) {
      const dlg = $('wk-dlg'); dlg.innerHTML = `<div class="wk-dlg-in"><h3>Otra persona cambió este formulario</h3><p>Mientras lo editabas, alguien guardó otra versión del borrador. Podés recargar para ver sus cambios (perdés los tuyos) o guardar los tuyos encima.</p><div class="acts"><button type="button" class="wk-btn" id="cf-r">Recargar</button><button type="button" class="pt-btn primary" id="cf-f">Guardar los míos</button></div></div>`;
      dlg.showModal(); $('cf-r').onclick = () => location.reload();
      $('cf-f').onclick = async () => { dlg.close(); try { const r = await api('form_save', { id: B.id, schema: B.schema, theme: B.theme, meta: B.meta, force: 1 }); B.updated_at = r.updated_at; B.dirty = false; status(); toast('Guardado'); } catch (er) { toast(er.message, true); } };
    } else toast(e.message, true);
    return false;
  }
}
function status(txt) {
  const el = $('bd-status'); if (!el) return;
  if (txt) { el.textContent = txt; return; }
  const pub = B.form.version ? `Versión ${B.form.version} publicada` : 'Sin publicar';
  el.innerHTML = `${B.dirty ? '<i class="fa-solid fa-circle" style="color:#f59e0b;font-size:7px"></i> Cambios sin guardar' : '<i class="fa-solid fa-check" style="color:#22c55e"></i> Borrador guardado'} · ${pub}${B.form.is_template ? ' · Plantilla' : ''}`;
  $('bd-undo').disabled = B.hi <= 0; $('bd-redo').disabled = B.hi >= B.hist.length - 1;
}

// ── Arranque ─────────────────────────────────────────────────
(async function boot() {
  B.id = +$('bd').dataset.id;
  if (!B.id) { $('bd-paper').innerHTML = '<div class="pt-empty">Falta el formulario.</div>'; return; }
  let r;
  try { r = await api('form_edit', { id: B.id }); } catch (e) { $('bd-paper').innerHTML = `<div class="pt-empty"><i class="fa-solid fa-lock"></i><b>${esc(e.message)}</b></div>`; return; }
  Object.assign(B, { form: r.form, schema: r.schema, theme: r.theme, cats: r.cats, members: r.members, boards: r.boards, priorities: r.priorities, dept: r.dept, versions: r.versions, published: r.published, updated_at: r.form.updated_at });
  B.meta = { name: r.form.name, description: r.form.description, icon: r.form.icon, color: r.form.color, board_id: r.form.board_id, assignee_id: r.form.assignee_id, category_id: r.form.category_id, priority: r.form.priority, settings: r.form.settings || {} };
  $('bd-back').href = `index.php#portal-config/${r.dept.id}/formularios`;
  $('bd-name').value = B.meta.name;
  $('bd-name').addEventListener('input', () => { B.meta.name = $('bd-name').value; commit(false); });
  B.hist = [snap()]; B.hi = 0;
  drawPalette(); drawAll(); status();
  $('bd-mode').querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => { B.schema.mode = b.dataset.m; commit(); drawMode(); }));
  $('bd-undo').addEventListener('click', undo); $('bd-redo').addEventListener('click', redo);
  $('bd-save').addEventListener('click', async () => { if (await save(true)) toast('Borrador guardado'); });
  $('bd-publish').addEventListener('click', publish);
  $('bd-preview').addEventListener('click', openPreview);
  $('bd-prev-x').addEventListener('click', () => { $('bd-prev').hidden = true; });
  $('bd-dev').querySelectorAll('[data-d]').forEach(b => b.addEventListener('click', () => { B.device = b.dataset.d; $('bd-dev').querySelectorAll('[data-d]').forEach(x => x.classList.toggle('on', x === b)); $('bd-device').dataset.d = B.device; }));
  addEventListener('keydown', e => {
    const typing = /input|textarea|select/i.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !typing) { e.preventDefault(); e.shiftKey ? redo() : undo(); }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y' && !typing) { e.preventDefault(); redo(); }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(true).then(ok => ok && toast('Borrador guardado')); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && !typing && B.sel.kind === 'item') { e.preventDefault(); removeItem(B.sel.id); }
    else if (e.key === 'Escape' && !$('bd-prev').hidden) $('bd-prev').hidden = true;
  });
  window.onbeforeunload = () => B.dirty ? true : undefined;
})();
function drawAll() { drawMode(); drawCanvas(); drawProps(); status(); $('bd-name').value = B.meta.name; }
function drawMode() { $('bd-mode').querySelectorAll('[data-m]').forEach(b => b.classList.toggle('on', b.dataset.m === B.schema.mode)); }

// ── Paleta ───────────────────────────────────────────────────
function drawPalette() {
  $('bd-pal').innerHTML = `<div class="bd-pal-h">Elementos <small>Arrastrá o tocá para sumar</small></div>${PAL.map(([g, list]) => `<div class="bd-pal-g"><span>${g}</span><div>${list.map(([t, i, l]) => `<button type="button" class="bd-pal-i" data-type="${t}" title="${esc(l)}"><i class="fa-solid ${i}"></i><b>${esc(l)}</b></button>`).join('')}</div></div>`).join('')}
    <div class="bd-pal-g"><span>Estructura</span><div><button type="button" class="bd-pal-i" id="bd-add-sec"><i class="fa-solid fa-layer-group"></i><b>${B.schema.mode === 'steps' ? 'Paso nuevo' : 'Sección nueva'}</b></button></div></div>`;
  $('bd-pal').querySelectorAll('[data-type]').forEach(b => {
    b.addEventListener('click', () => { if (b.dataset.dragged) { delete b.dataset.dragged; return; } addItem(b.dataset.type); });
    startDragOn(b, () => ({ fresh: b.dataset.type }));
  });
  $('bd-add-sec').addEventListener('click', addSection);
}
function addItem(type, target = null) {
  const it = newItem(type);
  if (target) target.list.splice(target.idx, 0, it);
  else {
    // al final de la sección elegida (o del grupo elegido, o de la última sección)
    let sec = B.sel.kind === 'sec' ? secById(B.sel.id) : null, list = null;
    if (B.sel.kind === 'item') { const l = locate(B.sel.id); if (l) { list = l.list; list.splice(l.idx + 1, 0, it); } }
    if (!list) { sec = sec || B.schema.sections[B.schema.sections.length - 1]; sec.items.push(it); }
  }
  B.sel = { kind: 'item', id: it.id }; commit(); drawProps();
  requestAnimationFrame(() => { const el = document.querySelector(`.bd-it[data-id="${CSS.escape(it.id)}"]`); el?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); el?.classList.add('bd-new'); });
}
function addSection() {
  const n = B.schema.sections.length + 1;
  const s = { id: uniqueSec('s' + n), title: B.schema.mode === 'steps' ? 'Paso ' + n : 'Sección ' + n, desc: '', icon: 'fa-circle-dot', items: [] };
  B.schema.sections.push(s); B.sel = { kind: 'sec', id: s.id }; commit(); drawProps();
}
function uniqueSec(id) { const ids = new Set(B.schema.sections.map(s => s.id)); let x = id, k = 2; while (ids.has(x)) x = id + '_' + k++; return x; }
function removeItem(id) { const l = locate(id); if (!l) return; l.list.splice(l.idx, 1); B.sel = { kind: 'sec', id: l.sec.id }; commit(); drawProps(); toast('Elemento quitado · Ctrl+Z para deshacer'); }
function dupItem(id) {
  const l = locate(id); if (!l) return;
  const c = JSON.parse(JSON.stringify(l.list[l.idx])); c.id = uniqueId(c.id + '_copia');
  if (c.items) c.items.forEach(x => { x.id = uniqueId(x.id + '_copia'); });
  l.list.splice(l.idx + 1, 0, c); B.sel = { kind: 'item', id: c.id }; commit(); drawProps();
}
function moveItem(id, d) { const l = locate(id); if (!l) return; const j = l.idx + d; if (j < 0 || j >= l.list.length) return; [l.list[l.idx], l.list[j]] = [l.list[j], l.list[l.idx]]; commit(); }

// ── Lienzo (WYSIWYG) ─────────────────────────────────────────
function renderer() {
  const R = new PTForm($('bd-paper'), { schema: B.schema, theme: B.theme, cats: B.cats, members: B.members, people: B.members, priorities: B.priorities, showEstimate: true, agentView: true, readonly: false });
  R.applyTheme(); return R;
}
function drawCanvas() {
  const P = $('bd-paper'), R = renderer(), steps = B.schema.mode === 'steps';
  const allVis = Object.fromEntries(allItems().map(x => [x.id, true]));
  const w = it => ({ '1/1': 12, '3/4': 9, '2/3': 8, '1/2': 6, '1/3': 4, '1/4': 3 }[it.width] || 12);
  const tools = it => `<div class="bd-tools"><span class="bd-grip" title="Arrastrar para mover"><i class="fa-solid fa-grip-vertical"></i></span><span class="bd-type"><i class="fa-solid ${ICON[it.type] || 'fa-square'}"></i>${esc(NAME[it.type] || it.type)}</span>${it.cond ? '<span class="bd-badge cond" title="Tiene condición"><i class="fa-solid fa-code-branch"></i></span>' : ''}${it.required ? '<span class="bd-badge req">Obligatorio</span>' : ''}<span class="sp"></span><button type="button" data-up title="Subir"><i class="fa-solid fa-arrow-up"></i></button><button type="button" data-down title="Bajar"><i class="fa-solid fa-arrow-down"></i></button><button type="button" data-dup title="Duplicar"><i class="fa-regular fa-copy"></i></button><button type="button" data-del title="Quitar"><i class="fa-solid fa-trash"></i></button></div>`;
  const itemHtml = it => {
    const sel = B.sel.kind === 'item' && B.sel.id === it.id ? ' sel' : '';
    if (it.type === 'group') return `<div class="bd-it group${sel}" data-id="${esc(it.id)}" style="grid-column:span ${w(it)}">${tools(it)}<fieldset class="ptf-group">${it.label ? `<legend>${esc(it.label)}</legend>` : ''}${it.desc ? `<p class="ptf-d">${esc(it.desc)}</p>` : ''}<div class="bd-grid ptf-grid" data-list="g:${esc(it.id)}">${(it.items || []).map(itemHtml).join('') || '<div class="bd-empty">Arrastrá elementos al grupo</div>'}</div></fieldset></div>`;
    let inner = R.item(it, allVis);
    if (it.type === 'hidden') inner = `<div class="ptf-f w12 t-hidden"><label class="ptf-l">${esc(it.label)}</label><div class="ptf-hidden"><i class="fa-solid fa-eye-slash"></i> ${esc(it.default || '(sin valor)')} <small>Quien pide no lo ve</small></div></div>`;
    if (!inner) inner = `<div class="ptf-f w12"><em class="pt-muted">${esc(NAME[it.type])}</em></div>`;
    return `<div class="bd-it${sel}" data-id="${esc(it.id)}" style="grid-column:span ${w(it)}">${tools(it)}<div class="bd-it-in">${inner}</div></div>`;
  };
  P.innerHTML = `<div class="bd-ftitle"><span class="pt-fhead-ic" style="--c:${esc(B.theme.accent || B.meta.color)}"><i class="fa-solid ${esc(B.meta.icon)}"></i></span><div><h1>${esc(B.meta.name)}</h1>${B.meta.description ? `<p>${esc(B.meta.description)}</p>` : ''}</div></div>
    <div class="bd-titlef" data-pick="form" title="Pregunta fija del título (se cambia en Formulario)"><label class="ptf-l">${esc(B.schema.title_label || 'Título del pedido')} <b class="req">*</b></label><input class="ptf-in ptf-title" placeholder="${esc(B.schema.title_ph || 'Ej.: Placas para la promo de verano')}" tabindex="-1" readonly><span class="bd-lock"><i class="fa-solid fa-lock"></i> Siempre está</span></div>
    ${B.schema.sections.map((s, i) => `<section class="bd-sec${B.sel.kind === 'sec' && B.sel.id === s.id ? ' sel' : ''}" data-sec="${esc(s.id)}">
      <header class="bd-sec-h">${steps ? `<span class="bd-step">${i + 1}</span>` : `<span class="ptf-sec-ic"><i class="fa-solid ${esc(s.icon || 'fa-circle-dot')}"></i></span>`}<div class="bd-sec-t"><h3>${esc(s.title || (steps ? 'Paso ' + (i + 1) : 'Sección ' + (i + 1)))}</h3>${s.desc ? `<p>${esc(s.desc)}</p>` : ''}</div>${s.cond ? '<span class="bd-badge cond"><i class="fa-solid fa-code-branch"></i> Con condición</span>' : ''}
        <div class="bd-sec-a"><button type="button" data-sup title="Subir"${i ? '' : ' disabled'}><i class="fa-solid fa-arrow-up"></i></button><button type="button" data-sdown title="Bajar"${i < B.schema.sections.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-arrow-down"></i></button><button type="button" data-sdel title="Quitar"${B.schema.sections.length > 1 ? '' : ' disabled'}><i class="fa-solid fa-trash"></i></button></div></header>
      <div class="bd-grid ptf-grid" data-list="s:${esc(s.id)}">${s.items.map(itemHtml).join('') || '<div class="bd-empty"><i class="fa-solid fa-hand-pointer"></i> Arrastrá elementos acá o tocá uno de la izquierda</div>'}</div></section>`).join('')}
    <button type="button" class="bd-addsec" id="bd-addsec2"><i class="fa-solid fa-plus"></i> ${steps ? 'Agregar un paso' : 'Agregar una sección'}</button>
    ${steps ? '<div class="bd-auto-steps"><span><i class="fa-solid fa-calendar-days"></i> Fecha</span><span><i class="fa-solid fa-list-check"></i> Resumen</span><small>Estos dos pasos los agrega el portal solos.</small></div>' : '<div class="bd-auto-steps"><span><i class="fa-solid fa-calendar-days"></i> Fecha con calendario de carga</span><small>La agrega el portal al final.</small></div>'}`;
  // las entradas del lienzo no se usan: es una vista
  P.querySelectorAll('.bd-it-in input,.bd-it-in textarea,.bd-it-in select,.bd-it-in button').forEach(x => { x.tabIndex = -1; });
  P.querySelectorAll('.bd-it').forEach(el => {
    el.addEventListener('click', e => { e.stopPropagation(); if (e.target.closest('.bd-tools button')) return; if (el.dataset.dragged) { delete el.dataset.dragged; return; } select({ kind: 'item', id: el.dataset.id }); });
    const id = el.dataset.id;
    el.querySelector(':scope > .bd-tools [data-del]').addEventListener('click', e => { e.stopPropagation(); removeItem(id); });
    el.querySelector(':scope > .bd-tools [data-dup]').addEventListener('click', e => { e.stopPropagation(); dupItem(id); });
    el.querySelector(':scope > .bd-tools [data-up]').addEventListener('click', e => { e.stopPropagation(); moveItem(id, -1); });
    el.querySelector(':scope > .bd-tools [data-down]').addEventListener('click', e => { e.stopPropagation(); moveItem(id, 1); });
    startDragOn(el.querySelector(':scope > .bd-tools .bd-grip'), () => ({ move: id }), el);
    el.addEventListener('dblclick', e => { e.stopPropagation(); select({ kind: 'item', id }); setTimeout(() => $('p-label')?.select(), 30); });
  });
  P.querySelectorAll('.bd-sec').forEach(el => {
    const id = el.dataset.sec;
    el.querySelector('.bd-sec-h').addEventListener('click', e => { if (e.target.closest('button')) return; select({ kind: 'sec', id }); });
    const i = B.schema.sections.findIndex(s => s.id === id);
    el.querySelector('[data-sup]').addEventListener('click', () => { const a = B.schema.sections; [a[i - 1], a[i]] = [a[i], a[i - 1]]; commit(); });
    el.querySelector('[data-sdown]').addEventListener('click', () => { const a = B.schema.sections; [a[i + 1], a[i]] = [a[i], a[i + 1]]; commit(); });
    el.querySelector('[data-sdel]').addEventListener('click', () => { const s = B.schema.sections[i]; if (s.items.length && !confirm('¿Quitar la sección con sus ' + s.items.length + ' elementos?')) return; B.schema.sections.splice(i, 1); B.sel = { kind: 'form' }; commit(); drawProps(); });
  });
  P.querySelector('.bd-titlef').addEventListener('click', () => select({ kind: 'form' }));
  $('bd-addsec2').addEventListener('click', addSection);
}
function select(s) { B.sel = s; document.querySelectorAll('.bd-it.sel,.bd-sec.sel').forEach(x => x.classList.remove('sel')); if (s.kind === 'item') document.querySelector(`.bd-it[data-id="${CSS.escape(s.id)}"]`)?.classList.add('sel'); if (s.kind === 'sec') document.querySelector(`.bd-sec[data-sec="${CSS.escape(s.id)}"]`)?.classList.add('sel'); drawProps(); }

// ── Arrastrar y soltar (eventos de puntero) ──────────────────
let DR = null;
function startDragOn(handle, payload, srcEl = null) {
  handle.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    e.preventDefault();
    const start = { x: e.clientX, y: e.clientY }, pl = payload();
    const move = ev => {
      if (!DR) {
        if (Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < 5) return;
        const label = pl.fresh ? NAME[pl.fresh] : (locate(pl.move)?.list[locate(pl.move).idx].label || NAME[locate(pl.move)?.list[locate(pl.move).idx].type]);
        const g = document.createElement('div'); g.className = 'bd-ghost'; g.innerHTML = `<i class="fa-solid ${pl.fresh ? ICON[pl.fresh] : 'fa-grip-vertical'}"></i>${esc(label || 'Elemento')}`;
        document.body.appendChild(g);
        const line = document.createElement('div'); line.className = 'bd-line'; document.body.appendChild(line);
        DR = { pl, g, line, src: srcEl, target: null };
        if (srcEl) srcEl.classList.add('bd-dragging');
        document.body.classList.add('bd-drag');
      }
      DR.g.style.transform = `translate(${ev.clientX + 12}px,${ev.clientY + 12}px)`;
      DR.target = dropTarget(ev.clientX, ev.clientY);
      paintLine();
      // desplazamiento automático cerca de los bordes del lienzo
      const C = $('bd-canvas'), r = C.getBoundingClientRect();
      if (ev.clientY < r.top + 50) C.scrollTop -= 12; else if (ev.clientY > r.bottom - 50) C.scrollTop += 12;
    };
    let done = false;
    const up = () => {
      if (done) return; done = true;
      removeEventListener('pointermove', move); removeEventListener('pointerup', up); removeEventListener('pointercancel', up); removeEventListener('mouseup', up);
      if (!DR) return;
      const d = DR; DR = null;
      d.g.remove(); d.line.remove(); d.src?.classList.remove('bd-dragging'); document.body.classList.remove('bd-drag');
      handle.dataset.dragged = '1'; if (srcEl) srcEl.dataset.dragged = '1';
      setTimeout(() => { delete handle.dataset.dragged; if (srcEl) delete srcEl.dataset.dragged; }, 50);
      if (!d.target) return;
      drop(d.pl, d.target);
    };
    addEventListener('pointermove', move); addEventListener('pointerup', up); addEventListener('pointercancel', up); addEventListener('mouseup', up);   // mouseup: respaldo si el navegador no manda pointerup
  });
}
/** Lista y posición donde caería lo que se arrastra. */
function dropTarget(x, y) {
  const el = document.elementFromPoint(x, y); if (!el || !el.closest('#bd-paper')) return null;
  const movingGroup = DR.pl.fresh === 'group' || (DR.pl.move && locate(DR.pl.move)?.list[locate(DR.pl.move).idx].type === 'group');
  let it = el.closest('.bd-it');
  while (it && DR.src && (it === DR.src || DR.src.contains(it))) it = it.parentElement?.closest('.bd-it');
  let grid;
  if (it) {
    grid = it.parentElement.closest('.bd-grid');
    // un grupo no va dentro de otro: se usa la sección
    if (movingGroup && grid.dataset.list.startsWith('g:')) { it = grid.closest('.bd-it'); grid = it.parentElement.closest('.bd-grid'); }
    // soltar sobre el contenido vacío de un grupo
    if (!movingGroup && it.classList.contains('group')) { const inner = it.querySelector('.bd-grid'); const r = inner.getBoundingClientRect(); if (x > r.left && x < r.right && y > r.top && y < r.bottom) { const kids = [...inner.children].filter(c => c.classList.contains('bd-it')); if (!kids.length) return { list: listOf(inner), idx: 0, el: inner, mode: 'in' }; } }
    const r = it.getBoundingClientRect(), wide = r.width > grid.getBoundingClientRect().width * .7;
    const after = wide ? y > r.top + r.height / 2 : x > r.left + r.width / 2;
    const items = [...grid.children].filter(c => c.classList.contains('bd-it') && c !== DR.src);
    const idxInDom = items.indexOf(it);
    return { list: listOf(grid), idx: idxInDom + (after ? 1 : 0), el: it, mode: after ? (wide ? 'below' : 'right') : (wide ? 'above' : 'left') };
  }
  grid = el.closest('.bd-grid') || el.closest('.bd-sec')?.querySelector('.bd-grid');
  if (!grid) return null;
  if (movingGroup && grid.dataset.list.startsWith('g:')) grid = grid.closest('.bd-sec').querySelector(':scope > .bd-grid');
  const items = [...grid.children].filter(c => c.classList.contains('bd-it') && c !== DR.src);
  return { list: listOf(grid), idx: items.length, el: items[items.length - 1] || grid, mode: items.length ? 'below' : 'in' };
}
function listOf(grid) { const [k, id] = grid.dataset.list.split(/:(.+)/); return k === 's' ? secById(id).items : (locate(id)?.list[locate(id).idx].items); }
function paintLine() {
  const t = DR.target, L = DR.line;
  document.querySelectorAll('.bd-grid.bd-over').forEach(g => g.classList.remove('bd-over'));
  if (!t) { L.style.display = 'none'; return; }
  const r = t.el.getBoundingClientRect(); L.style.display = 'block';
  if (t.mode === 'in') { t.el.classList.add('bd-over'); L.style.display = 'none'; return; }
  if (t.mode === 'above' || t.mode === 'below') Object.assign(L.style, { left: r.left + 'px', top: (t.mode === 'above' ? r.top - 5 : r.bottom + 3) + 'px', width: r.width + 'px', height: '3px' });
  else Object.assign(L.style, { left: (t.mode === 'left' ? r.left - 5 : r.right + 3) + 'px', top: r.top + 'px', width: '3px', height: r.height + 'px' });
}
function drop(pl, t) {
  if (pl.fresh) { addItem(pl.fresh, { list: t.list, idx: t.idx }); return; }
  const l = locate(pl.move); if (!l) return;
  const [it] = l.list.splice(l.idx, 1);
  // el índice del destino se calculó sin el que se mueve: no hace falta corregirlo
  t.list.splice(Math.min(t.idx, t.list.length), 0, it);
  B.sel = { kind: 'item', id: it.id }; commit(); drawProps();
}

// ── Propiedades ──────────────────────────────────────────────
function condFields(exceptId) {
  return allItems().filter(x => VALUED.has(x.type) && x.id !== exceptId && x.type !== 'hidden' && x.type !== 'approval').map(x => ({
    id: x.id, label: x.label || NAME[x.type], type: x.type,
    options: x.options ? x.options.map(o => ({ v: o.v, l: o.l })) : x.type === 'catpick' ? B.cats.filter(c => (c.parent_id || 0) === (x.root || 0)).map(c => ({ v: String(c.id), l: c.name })) : x.type === 'priority' ? B.priorities.map(p => ({ v: p.id, l: p.name })) : null,
  }));
}
function drawProps() {
  const P = $('bd-props'), s = B.sel;
  const tabs = [['item', 'Elemento'], ['sec', B.schema.mode === 'steps' ? 'Paso' : 'Sección'], ['form', 'Formulario'], ['design', 'Diseño'], ['versions', 'Versiones']];
  const cur = s.kind;
  P.innerHTML = `<div class="bd-tabs">${tabs.map(([k, l]) => `<button type="button" data-k="${k}" class="${cur === k ? 'on' : ''}"${(k === 'item' && cur !== 'item') || (k === 'sec' && !['sec', 'item'].includes(cur)) ? ' disabled' : ''}>${l}</button>`).join('')}</div><div class="bd-pb" id="bd-pb"></div>`;
  P.querySelectorAll('.bd-tabs [data-k]').forEach(b => b.addEventListener('click', () => {
    const k = b.dataset.k;
    if (k === 'sec' && s.kind === 'item') { const l = locate(s.id); if (l) select({ kind: 'sec', id: l.sec.id }); return; }
    if (k !== 'item' && k !== 'sec') select({ kind: k });
  }));
  const pb = $('bd-pb');
  if (cur === 'item') propsItem(pb, s.id);
  else if (cur === 'sec') propsSec(pb, s.id);
  else if (cur === 'design') propsDesign(pb);
  else if (cur === 'versions') propsVersions(pb);
  else propsForm(pb);
}
const fieldRow = (label, html, help = '') => `<label class="bd-f"><span>${label}</span>${html}${help ? `<small>${help}</small>` : ''}</label>`;
function bindInputs(pb, obj, after) {
  pb.querySelectorAll('[data-p]').forEach(x => {
    const ev = x.type === 'checkbox' || x.tagName === 'SELECT' ? 'change' : 'input';
    x.addEventListener(ev, () => {
      const k = x.dataset.p; let v = x.type === 'checkbox' ? x.checked : x.value;
      if (x.dataset.num !== undefined) v = x.value === '' ? undefined : +x.value;
      const path = k.split('.'); let o = obj; for (let i = 0; i < path.length - 1; i++) o = o[path[i]] = o[path[i]] || {};
      if (v === undefined || v === '' && x.dataset.opt !== undefined) delete o[path[path.length - 1]]; else o[path[path.length - 1]] = v;
      after && after(k, v);
      commit();
    });
  });
}
function propsItem(pb, id) {
  const l = locate(id); if (!l) { select({ kind: 'form' }); return; }
  const it = l.list[l.idx], t = it.type, va = it.valid = it.valid || {};
  const hasPh = ['text', 'textarea', 'number', 'email', 'phone', 'url', 'select', 'person'].includes(t);
  const hasReq = VALUED.has(t) && !['hidden'].includes(t);
  const W = ['1/4', '1/3', '1/2', '2/3', '3/4', '1/1'];
  let h = `<div class="bd-ph"><span class="bd-ph-ic"><i class="fa-solid ${ICON[t]}"></i></span><div><b>${esc(NAME[t])}</b><small>${l.group ? 'Dentro del grupo "' + esc(l.group.label) + '"' : 'En ' + esc(l.sec.title || 'la sección')}</small></div></div>
    ${fieldRow('Tipo', `<select class="wk-in sm" id="p-type">${PAL.flatMap(g => g[1]).filter(([x]) => x !== 'group' || !l.group).map(([x, , n]) => `<option value="${x}"${x === t ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>`)}
    ${t !== 'separator' ? fieldRow(t === 'paragraph' ? 'Título (opcional)' : 'Etiqueta', `<input class="wk-in sm" id="p-label" data-p="label" value="${esc(it.label || '')}" maxlength="200">`) : ''}
    ${t !== 'separator' ? fieldRow(t === 'paragraph' || t === 'info' ? 'Texto' : 'Descripción o ayuda', `<textarea class="wk-in sm pt-ta" rows="${t === 'paragraph' || t === 'info' ? 4 : 2}" data-p="desc" maxlength="1000">${esc(it.desc || '')}</textarea>`) : ''}
    ${hasPh ? fieldRow('Texto de ejemplo', `<input class="wk-in sm" data-p="placeholder" value="${esc(it.placeholder || '')}" maxlength="120">`) : ''}
    ${hasReq ? `<label class="pt-sw sm"><input type="checkbox" data-p="required"${it.required ? ' checked' : ''}><span></span>Obligatorio</label>` : ''}
    ${t === 'info' ? fieldRow('Tono', `<div class="bd-seg sm">${[['info', 'Info'], ['ok', 'Bien'], ['warn', 'Atención'], ['danger', 'Alerta']].map(([k, n]) => `<button type="button" data-tone="${k}" class="${(it.tone || 'info') === k ? 'on' : ''}">${n}</button>`).join('')}</div>`) : ''}
    ${fieldRow('Ancho', `<div class="bd-seg sm" id="p-w">${W.map(x => `<button type="button" data-w="${x}" class="${(it.width || '1/1') === x ? 'on' : ''}">${x === '1/1' ? 'Todo' : x}</button>`).join('')}</div>`, 'En celular siempre ocupa todo el ancho.')}`;
  // Valor por defecto
  if (['text', 'textarea', 'number', 'email', 'phone', 'url', 'date', 'time', 'color', 'hidden'].includes(t)) h += fieldRow(t === 'hidden' ? 'Valor que se guarda' : 'Valor por defecto', `<input class="wk-in sm" data-p="default" type="${{ number: 'number', date: 'date', time: 'time' }[t] || 'text'}" value="${esc(it.default ?? '')}">`, t === 'hidden' ? 'Lo ve solo quien atiende. Sirve para centros de costo, códigos, etc.' : '');
  if (t === 'checkbox') h += `<label class="pt-sw sm"><input type="checkbox" id="p-def-cb"${it.default === '1' ? ' checked' : ''}><span></span>Tildada por defecto</label>`;
  if (['select', 'radio'].includes(t)) h += fieldRow('Valor por defecto', `<select class="wk-in sm" data-p="default"><option value="">Ninguno</option>${(it.options || []).map(o => `<option value="${esc(o.v)}"${it.default === o.v ? ' selected' : ''}>${esc(o.l)}</option>`).join('')}</select>`);
  if (t === 'priority') h += fieldRow('Valor por defecto', `<select class="wk-in sm" data-p="default"><option value="">Ninguno</option>${B.priorities.map(p => `<option value="${esc(p.id)}"${it.default === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>`, 'Este campo define la prioridad del ticket.');
  // Opciones
  if (['select', 'multiselect', 'radio'].includes(t)) h += `<div class="bd-block"><div class="bd-block-h"><b>Opciones</b><button type="button" class="wk-btn sm" id="p-opt-add"><i class="fa-solid fa-plus"></i></button></div><div id="p-opts"></div><button type="button" class="wk-btn sm" id="p-opt-bulk"><i class="fa-solid fa-paste"></i> Pegar varias</button></div>`;
  if (t === 'catpick') h += fieldRow('Opciones a mostrar', `<select class="wk-in sm" data-p="root" data-num><option value="">Las categorías principales</option>${B.cats.filter(c => !c.parent_id || B.cats.some(x => x.parent_id === c.id)).filter(c => B.cats.some(x => x.parent_id === c.id)).map(c => `<option value="${c.id}"${+it.root === c.id ? ' selected' : ''}>Variantes de ${esc(c.name)}</option>`).join('')}</select>`, `${B.cats.length ? 'Lo que elija define la categoría y su tiempo base.' : 'Este departamento todavía no tiene categorías: cargalas en Configurar › Categorías.'}`);
  if (t === 'approval') h += `<label class="pt-sw sm"><input type="checkbox" data-p="pick"${it.pick ? ' checked' : ''}><span></span>Que quien pide elija quién aprueba</label>${!it.pick ? fieldRow('Aprueba', `<select class="wk-in sm" data-p="approver" data-num><option value="">Un responsable del departamento</option>${B.members.map(m => `<option value="${m.id}"${+it.approver === m.id ? ' selected' : ''}>${esc(m.name)}</option>`).join('')}</select>`) : ''}<p class="pt-muted">Si este elemento se muestra, la solicitud entra como "Pendiente de revisión".</p>`;
  // Validaciones
  const vrow = (k, l, type = 'number', ph = '') => fieldRow(l, `<input class="wk-in sm" data-p="valid.${k}" ${type === 'number' ? 'data-num type="number"' : `type="${type}"`} value="${esc(va[k] ?? '')}" placeholder="${ph}" data-opt>`);
  let vh = '';
  if (['text', 'textarea'].includes(t)) vh = `<div class="bd-2">${vrow('minlen', 'Mín. caracteres')}${vrow('maxlen', 'Máx. caracteres')}</div>${t === 'text' ? vrow('pattern', 'Formato (expresión regular)', 'text', 'Ej.: ^[A-Z]{3}-\\d+$') + vrow('pattern_msg', 'Mensaje si no cumple', 'text') : ''}`;
  if (t === 'number') vh = `<div class="bd-2">${vrow('min', 'Mínimo')}${vrow('max', 'Máximo')}</div><label class="pt-sw sm"><input type="checkbox" id="p-int"${va.step === 1 ? ' checked' : ''}><span></span>Solo números enteros</label>`;
  if (t === 'date') vh = `<div class="bd-2">${fieldRow('Desde', `<input class="wk-in sm" data-p="valid.mindate" value="${esc(va.mindate || '')}" placeholder="today, +3 o 2026-12-01" data-opt>`)}${fieldRow('Hasta', `<input class="wk-in sm" data-p="valid.maxdate" value="${esc(va.maxdate || '')}" placeholder="+90" data-opt>`)}</div>`;
  if (t === 'multiselect') vh = `<div class="bd-2">${vrow('mincount', 'Elegir al menos')}${vrow('maxcount', 'Elegir como mucho')}</div>`;
  if (t === 'file' || t === 'image') vh = `<div class="bd-2">${vrow('maxfiles', 'Máx. archivos')}${vrow('maxmb', 'Máx. MB c/u')}</div>${fieldRow('Tipos permitidos', `<input class="wk-in sm" id="p-ext" value="${esc((va.ext || []).join(', '))}" placeholder="${t === 'image' ? 'png, jpg, webp' : 'pdf, png, zip…'} (vacío = los de siempre)">`)}`;
  if (vh) h += `<div class="bd-block"><div class="bd-block-h"><b>Validación</b></div>${vh}</div>`;
  // Estimación
  if (t === 'checkbox') h += `<div class="bd-block"><div class="bd-block-h"><b>Tiempo</b></div>${fieldRow('Si la tildan, suma', `<input class="wk-in sm" type="number" min="0" id="p-est-cb" value="${it.est?.v ?? ''}" placeholder="0"> <small>minutos</small>`)}</div>`;
  if (t === 'number') h += `<div class="bd-block"><div class="bd-block-h"><b>Tiempo</b></div>${fieldRow('Por cada unidad suma', `<input class="wk-in sm" type="number" min="0" id="p-est-num" value="${it.est?.op === 'per' || it.est?.op === 'add' ? it.est.v : ''}" placeholder="0"> <small>minutos</small>`, 'Para rangos (de 2 a 4 → 30 min) usá las reglas del formulario.')}</div>`;
  if (t !== 'hidden') h += `<div class="bd-block"><div class="bd-block-h"><b>Mostrar</b></div><div class="pt-condbox" id="p-cond"></div></div>`;
  h += `<div class="bd-block"><div class="bd-block-h"><b>ID interno</b></div><input class="wk-in sm mono" id="p-id" value="${esc(it.id)}" maxlength="40"><small class="pt-muted">Lo usan las condiciones y las reglas. Letras, números y _.</small></div>
    <div class="bd-pacts"><button type="button" class="wk-btn sm" id="p-dup"><i class="fa-regular fa-copy"></i> Duplicar</button><button type="button" class="wk-btn sm danger" id="p-del"><i class="fa-solid fa-trash"></i> Quitar</button></div>`;
  pb.innerHTML = h;
  bindInputs(pb, it, k => { if (k === 'label') { const el = document.querySelector(`.bd-it[data-id="${CSS.escape(it.id)}"]`); } });
  // el lienzo se vuelve a dibujar en commit(); el panel queda como está (no pierde el foco)
  // Si el id todavía es el automático, sigue a la etiqueta (salvo que alguna condición o regla ya lo use)
  $('p-label')?.addEventListener('change', () => {
    const used = JSON.stringify([B.schema.rules, allItems().map(x => x.cond), B.schema.sections.map(x => x.cond)]).includes('"' + it.id + '"');
    const auto = it.id === slug(NAME[t]) || /^(campo|f)_?/.test(it.id) || it.id.startsWith(slug(NAME[t]) + '_');
    if (!used && auto && it.label) { const nid = uniqueId(slug(it.label), it); if (nid !== it.id) { it.id = nid; B.sel.id = nid; $('p-id').value = nid; commit(); } }
  });
  $('p-type').addEventListener('change', e => {
    const nt = e.target.value, fresh = newItem(nt);
    Object.assign(it, { type: nt }); if (fresh.options && !it.options) it.options = fresh.options; if (nt === 'group' && !it.items) it.items = [];
    if (!['select', 'multiselect', 'radio'].includes(nt)) delete it.options; if (nt !== 'group') delete it.items;
    delete it.est; delete it.valid; it.valid = fresh.valid || {}; commit(); drawProps();
  });
  pb.querySelectorAll('[data-w]').forEach(b => b.addEventListener('click', () => { it.width = b.dataset.w; pb.querySelectorAll('[data-w]').forEach(x => x.classList.toggle('on', x === b)); commit(); }));
  pb.querySelectorAll('[data-tone]').forEach(b => b.addEventListener('click', () => { it.tone = b.dataset.tone; pb.querySelectorAll('[data-tone]').forEach(x => x.classList.toggle('on', x === b)); commit(); }));
  $('p-def-cb')?.addEventListener('change', e => { if (e.target.checked) it.default = '1'; else delete it.default; commit(); });
  $('p-int')?.addEventListener('change', e => { if (e.target.checked) va.step = 1; else delete va.step; commit(); });
  $('p-ext')?.addEventListener('change', e => { const l2 = e.target.value.split(/[\s,;]+/).map(x => x.replace(/^\./, '').toLowerCase()).filter(Boolean); if (l2.length) va.ext = l2; else delete va.ext; commit(); });
  $('p-est-cb')?.addEventListener('input', e => { const v = +e.target.value; if (v > 0) it.est = { op: 'add', v }; else delete it.est; commit(); });
  $('p-est-num')?.addEventListener('input', e => { const v = +e.target.value; if (v > 0) it.est = { op: 'per', v }; else delete it.est; commit(); });
  if ($('p-cond')) ptCondEditor($('p-cond'), it.cond, condFields(it.id), c => { if (c) it.cond = c; else delete it.cond; commit(); }, 'siempre');
  $('p-id').addEventListener('change', e => {
    const nid = slug(e.target.value).replace(/^_+/, '') || it.id;
    const fin = uniqueId(nid, it);
    if (fin !== it.id) { renameRefs(it.id, fin); it.id = fin; B.sel.id = fin; }
    e.target.value = fin; commit();
  });
  $('p-dup').addEventListener('click', () => dupItem(it.id));
  $('p-del').addEventListener('click', () => removeItem(it.id));
  if ($('p-opts')) drawOptions(it);
}
/** Cambia el id de un campo también en las condiciones y reglas que lo usan. */
function renameRefs(from, to) {
  const walk = c => { if (!c) return; for (const r of c.rules || []) { if (r.rules) walk(r); else if (r.f === from) r.f = to; } };
  for (const s of B.schema.sections) { walk(s.cond); for (const it of allItems({ sections: [s] })) walk(it.cond); }
  for (const r of B.schema.rules || []) { walk(r.when); if (r.field === from) r.field = to; for (const a of r.alts || []) walk(a.when); }
}
function drawOptions(it) {
  const box = $('p-opts');
  box.innerHTML = it.options.map((o, i) => `<div class="bd-opt" data-i="${i}"><span class="bd-opt-mv"><button type="button" data-ou${i ? '' : ' disabled'}><i class="fa-solid fa-chevron-up"></i></button><button type="button" data-od${i < it.options.length - 1 ? '' : ' disabled'}><i class="fa-solid fa-chevron-down"></i></button></span>
    <input class="wk-in sm" data-ok="l" value="${esc(o.l)}" placeholder="Texto"><input class="wk-in sm mono" data-ok="v" value="${esc(o.v)}" placeholder="valor" title="Valor que se guarda">
    <details class="bd-opt-more"><summary title="Ícono, color y tiempo"><i class="fa-solid fa-sliders"></i></summary><div>
      <label>Ícono<input class="wk-in sm" data-ok="icon" value="${esc(o.icon || '')}" placeholder="fa-star"></label><label>Color<input type="color" data-ok="color" value="${esc(o.color || '#ff6600')}"></label>
      <label>Tiempo<select class="wk-in sm" data-ok="estop"><option value="">No cambia</option><option value="add"${o.est?.op === 'add' ? ' selected' : ''}>Suma</option><option value="replace"${o.est?.op === 'replace' ? ' selected' : ''}>Reemplaza la base</option><option value="mult"${o.est?.op === 'mult' ? ' selected' : ''}>Multiplica</option></select><input class="wk-in sm" type="number" step="any" min="0" data-ok="estv" value="${o.est?.v ?? ''}" placeholder="${o.est?.op === 'mult' ? '1.5' : 'min'}"></label></div></details>
    <button type="button" class="pt-ic" data-ox title="Quitar"${it.options.length > 1 ? '' : ' disabled'}><i class="fa-solid fa-xmark"></i></button></div>`).join('');
  box.querySelectorAll('.bd-opt').forEach(row => {
    const i = +row.dataset.i, o = it.options[i];
    row.querySelectorAll('[data-ok]').forEach(x => x.addEventListener(x.tagName === 'SELECT' || x.type === 'color' ? 'change' : 'input', () => {
      const k = x.dataset.ok;
      if (k === 'l') { const auto = !o.v || o.v === slug(o.l); o.l = x.value; if (auto) { o.v = uniqueOpt(it, slug(x.value), o); row.querySelector('[data-ok=v]').value = o.v; } }
      else if (k === 'v') o.v = x.value.trim() || o.l;
      else if (k === 'icon') { if (/^fa-[a-z0-9-]+$/.test(x.value)) o.icon = x.value; else delete o.icon; }
      else if (k === 'color') o.color = x.value;
      else { const op = row.querySelector('[data-ok=estop]').value, v = +row.querySelector('[data-ok=estv]').value; if (op && v) o.est = { op, v }; else delete o.est; }
      commit();
    }));
    row.querySelector('[data-ox]').addEventListener('click', () => { it.options.splice(i, 1); commit(); drawOptions(it); });
    row.querySelector('[data-ou]').addEventListener('click', () => { [it.options[i - 1], it.options[i]] = [it.options[i], it.options[i - 1]]; commit(); drawOptions(it); });
    row.querySelector('[data-od]').addEventListener('click', () => { [it.options[i + 1], it.options[i]] = [it.options[i], it.options[i + 1]]; commit(); drawOptions(it); });
  });
  $('p-opt-add').onclick = () => { const n = it.options.length + 1; it.options.push({ v: uniqueOpt(it, 'opcion_' + n), l: 'Opción ' + n }); commit(); drawOptions(it); box.querySelector('.bd-opt:last-child [data-ok=l]')?.select(); };
  $('p-opt-bulk').onclick = () => {
    const dlg = $('wk-dlg'); dlg.innerHTML = `<div class="wk-dlg-in"><h3>Pegar opciones</h3><p class="pt-muted">Una por renglón. Se suman a las que ya hay.</p><textarea class="wk-in pt-ta" rows="8" id="bulk"></textarea><div class="acts"><button type="button" class="wk-btn" data-x>Cancelar</button><button type="button" class="pt-btn primary" id="bulk-ok">Sumar</button></div></div>`;
    dlg.showModal(); dlg.querySelector('[data-x]').onclick = () => dlg.close();
    $('bulk-ok').onclick = () => { $('bulk').value.split('\n').map(x => x.trim()).filter(Boolean).slice(0, 100).forEach(l2 => it.options.push({ v: uniqueOpt(it, slug(l2)), l: l2 })); dlg.close(); commit(); drawOptions(it); };
  };
}
function uniqueOpt(it, v, except) { const s = new Set(it.options.filter(o => o !== except).map(o => o.v)); let x = v || 'opcion', k = 2; while (s.has(x)) x = v + '_' + k++; return x; }
function propsSec(pb, id) {
  const s = secById(id); if (!s) { select({ kind: 'form' }); return; }
  pb.innerHTML = `<div class="bd-ph"><span class="bd-ph-ic"><i class="fa-solid ${esc(s.icon)}"></i></span><div><b>${B.schema.mode === 'steps' ? 'Paso' : 'Sección'}</b><small>${s.items.length} elemento${s.items.length === 1 ? '' : 's'}</small></div></div>
    ${fieldRow('Título', `<input class="wk-in sm" data-p="title" value="${esc(s.title)}" maxlength="120">`)}${fieldRow('Descripción', `<textarea class="wk-in sm pt-ta" rows="2" data-p="desc" maxlength="500">${esc(s.desc || '')}</textarea>`)}
    ${fieldRow('Ícono', `<div class="pt-icons sm">${SEC_ICONS.map(i => `<button type="button" data-si="${i}" class="${s.icon === i ? 'on' : ''}"><i class="fa-solid ${i}"></i></button>`).join('')}</div>`)}
    <div class="bd-block"><div class="bd-block-h"><b>Mostrar ${B.schema.mode === 'steps' ? 'el paso' : 'la sección'}</b></div><div class="pt-condbox" id="p-scond"></div>${B.schema.mode === 'steps' ? '<small class="pt-muted">Si no se cumple, el paso se saltea.</small>' : ''}</div>`;
  bindInputs(pb, s);
  pb.querySelectorAll('[data-si]').forEach(b => b.addEventListener('click', () => { s.icon = b.dataset.si; pb.querySelectorAll('[data-si]').forEach(x => x.classList.toggle('on', x === b)); commit(); }));
  ptCondEditor($('p-scond'), s.cond, condFields(null).filter(f => !s.items.some(x => x.id === f.id)), c => { if (c) s.cond = c; else delete s.cond; commit(); }, 'siempre');
}
function propsForm(pb) {
  const m = B.meta, S = B.schema;
  pb.innerHTML = `${fieldRow('Descripción', `<textarea class="wk-in sm pt-ta" rows="2" data-p="description" maxlength="500">${esc(m.description || '')}</textarea>`)}
    ${fieldRow('Ícono', `<div class="pt-icons sm">${['fa-inbox', 'fa-image', 'fa-shapes', 'fa-video', 'fa-code', 'fa-bullhorn', 'fa-pen-nib', 'fa-file-lines', 'fa-bolt', 'fa-headset', 'fa-calculator', 'fa-users'].map(i => `<button type="button" data-fi="${i}" class="${m.icon === i ? 'on' : ''}"><i class="fa-solid ${i}"></i></button>`).join('')}</div>`)}
    ${fieldRow('Color', `<input type="color" data-p="color" value="${esc(m.color)}">`)}
    <div class="bd-block"><div class="bd-block-h"><b>Pregunta del título</b></div>${fieldRow('Texto', `<input class="wk-in sm" id="p-tl" value="${esc(S.title_label || '')}" placeholder="Título del pedido" maxlength="120">`)}${fieldRow('Ejemplo', `<input class="wk-in sm" id="p-tp" value="${esc(S.title_ph || '')}" maxlength="120">`)}</div>
    <div class="bd-block"><div class="bd-block-h"><b>Al recibir la solicitud</b></div>
      ${fieldRow('Categoría por defecto', `<select class="wk-in sm" data-p="category_id" data-num><option value="">La que elija quien pide (o la primera)</option>${B.cats.map(c => `<option value="${c.id}"${+m.category_id === c.id ? ' selected' : ''}>${c.parent_id ? '— ' : ''}${esc(c.name)}</option>`).join('')}</select>`, 'Su tiempo base se usa si el formulario no tiene "Categorías visuales".')}
      ${fieldRow('Prioridad', `<select class="wk-in sm" data-p="priority"><option value="">La que elija quien pide (o la normal)</option>${B.priorities.map(p => `<option value="${esc(p.id)}"${m.priority === p.id ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>`)}
      ${fieldRow('Responsable de entrada', `<select class="wk-in sm" data-p="assignee_id" data-num><option value="">Nadie (o automático si el departamento lo usa)</option>${B.members.map(x => `<option value="${x.id}"${+m.assignee_id === x.id ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}</select>`)}
      ${fieldRow('Tablero de la tarea', `<select class="wk-in sm" data-p="board_id" data-num><option value="">El del departamento</option>${B.boards.map(b => `<option value="${b.id}"${+m.board_id === b.id ? ' selected' : ''}>${esc(b.name)}</option>`).join('')}</select>`)}
      <label class="pt-sw sm"><input type="checkbox" id="p-appr"${m.settings?.approval ? ' checked' : ''}><span></span>Necesita aprobación</label></div>
    <div class="bd-block"><div class="bd-block-h"><b>Reglas de tiempo del formulario</b><button type="button" class="wk-btn sm" id="p-rule-add"><i class="fa-solid fa-plus"></i></button></div><div id="p-rules"></div>
      <small class="pt-muted">Se suman a las de la categoría elegida. También podés poner tiempo en cada opción y casilla.</small></div>
    <div class="bd-pacts"><button type="button" class="wk-btn sm" id="p-tpl"><i class="fa-regular fa-bookmark"></i> Guardar como plantilla</button></div>`;
  bindInputs(pb, m);
  pb.querySelectorAll('[data-fi]').forEach(b => b.addEventListener('click', () => { m.icon = b.dataset.fi; pb.querySelectorAll('[data-fi]').forEach(x => x.classList.toggle('on', x === b)); commit(); }));
  $('p-tl').addEventListener('input', e => { S.title_label = e.target.value; commit(); });
  $('p-tp').addEventListener('input', e => { S.title_ph = e.target.value; commit(); });
  $('p-appr').addEventListener('change', e => { m.settings = m.settings || {}; m.settings.approval = e.target.checked; commit(); });
  S.rules = S.rules || [];
  const rr = () => ptRulesEditor($('p-rules'), S.rules, condFields(null), () => { commit(); rr(); });
  rr();
  $('p-rules').addEventListener('change', () => commit(false), true);
  $('p-rule-add').addEventListener('click', () => { S.rules.push({ name: '', op: 'add', v: 30, when: null }); commit(); rr(); });
  $('p-tpl').addEventListener('click', async () => { await save(true); try { await api('form_new', { team_id: B.dept.id, from: B.id, template: 1 }); toast('Se guardó una copia como plantilla del departamento'); } catch (e) { toast(e.message, true); } });
}
function propsDesign(pb) {
  const t = B.theme;
  const col = (k, l, def) => `<label class="bd-col"><input type="color" data-tk="${k}" value="${esc(t[k] || def)}"><span>${l}</span>${t[k] ? `<button type="button" data-tx="${k}" title="Usar el del tema"><i class="fa-solid fa-rotate-left"></i></button>` : '<small>tema</small>'}</label>`;
  pb.innerHTML = `<div class="bd-block"><div class="bd-block-h"><b>Colores</b></div><div class="bd-cols">${col('accent', 'Principal', '#ff6600')}${col('bg', 'Fondo', '#1a1a1a')}${col('card', 'Campos', '#111111')}${col('text', 'Texto', '#ffffff')}</div></div>
    <div class="bd-block"><div class="bd-block-h"><b>Tipografía</b></div><select class="wk-in sm" data-tp="font">${[['', 'La de Toolz'], ['Inter', 'Inter'], ['Montserrat', 'Montserrat'], ['Poppins', 'Poppins'], ['Roboto', 'Roboto'], ['Lora', 'Lora (serif)'], ['system', 'Del sistema']].map(([k, l]) => `<option value="${k}"${(t.font || '') === k ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
    <div class="bd-block"><div class="bd-block-h"><b>Bordes y espacios</b></div>${fieldRow(`Redondeo <em id="v-r">${t.radius ?? 12}px</em>`, `<input type="range" min="0" max="28" data-tp="radius" value="${t.radius ?? 12}">`)}${fieldRow(`Espacio entre campos <em id="v-g">${t.gap ?? 16}px</em>`, `<input type="range" min="8" max="32" data-tp="gap" value="${t.gap ?? 16}">`)}</div>
    <div class="bd-block"><div class="bd-block-h"><b>Botones</b></div><div class="bd-seg sm">${[['solid', 'Lleno'], ['soft', 'Suave'], ['outline', 'Borde'], ['pill', 'Píldora']].map(([k, l]) => `<button type="button" data-btn="${k}" class="${(t.btn || 'solid') === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
    <div class="bd-block"><div class="bd-block-h"><b>Fondo</b></div><div class="bd-seg sm">${[['plain', 'Liso'], ['soft', 'Suave'], ['grad', 'Degradé'], ['dots', 'Puntos']].map(([k, l]) => `<button type="button" data-bgs="${k}" class="${(t.bg_style || 'plain') === k ? 'on' : ''}">${l}</button>`).join('')}</div></div>
    <div class="bd-block"><div class="bd-block-h"><b>Portada</b></div><div class="pt-banner sm"><span id="v-cover"${t.cover ? ` style="background-image:url('${esc(t.cover)}')"` : ''}></span><label class="wk-btn sm"><i class="fa-solid fa-upload"></i> Subir<input type="file" accept="image/png,image/jpeg,image/webp" id="v-cf" hidden></label>${t.cover ? '<button type="button" class="wk-btn sm" id="v-cx">Quitar</button>' : ''}</div>${t.cover ? fieldRow(`Alto <em id="v-ch">${t.cover_h || 140}px</em>`, `<input type="range" min="60" max="320" data-tp="cover_h" value="${t.cover_h || 140}">`) : ''}</div>`;
  pb.querySelectorAll('[data-tk]').forEach(x => x.addEventListener('input', () => { t[x.dataset.tk] = x.value; commit(); }));
  pb.querySelectorAll('[data-tx]').forEach(x => x.addEventListener('click', () => { delete t[x.dataset.tx]; commit(); propsDesign(pb); }));
  pb.querySelectorAll('[data-tp]').forEach(x => x.addEventListener(x.type === 'range' ? 'input' : 'change', () => { t[x.dataset.tp] = x.type === 'range' ? +x.value : x.value; const lab = { radius: 'v-r', gap: 'v-g', cover_h: 'v-ch' }[x.dataset.tp]; if (lab) $(lab).textContent = x.value + 'px'; commit(); }));
  pb.querySelectorAll('[data-btn]').forEach(x => x.addEventListener('click', () => { t.btn = x.dataset.btn; pb.querySelectorAll('[data-btn]').forEach(y => y.classList.toggle('on', y === x)); commit(); }));
  pb.querySelectorAll('[data-bgs]').forEach(x => x.addEventListener('click', () => { t.bg_style = x.dataset.bgs; pb.querySelectorAll('[data-bgs]').forEach(y => y.classList.toggle('on', y === x)); commit(); }));
  $('v-cf').addEventListener('change', async e => {
    const f = e.target.files[0]; if (!f) return;
    if (f.size > 3 * 1048576) { toast('La imagen tiene que pesar menos de 3 MB', true); return; }
    const buf = new Uint8Array(await f.arrayBuffer()); let bin = ''; for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
    try { const r = await api('image_up', { team_id: B.dept.id, data: btoa(bin) }); t.cover = 'portal_file.php?img=' + r.name; commit(); propsDesign(pb); } catch (er) { toast(er.message, true); }
  });
  $('v-cx')?.addEventListener('click', () => { delete t.cover; commit(); propsDesign(pb); });
}
function propsVersions(pb) {
  pb.innerHTML = `<div class="bd-block"><div class="bd-block-h"><b>Estado</b></div><p>${B.form.version ? `Publicada la <b>versión ${B.form.version}</b>.` : 'Todavía <b>no se publicó</b>: quien pide no lo ve.'} ${B.form.version && !B.form.active ? 'Está desactivado.' : ''}</p>
      <p class="pt-muted">Cada publicación crea una versión nueva. Las solicitudes guardan la versión con la que se hicieron, así que cambiar el formulario no altera las anteriores.</p>
      ${B.form.version ? `<label class="pt-sw sm"><input type="checkbox" id="v-act"${B.form.active ? ' checked' : ''}><span></span>Activo en el portal</label>` : ''}</div>
    <div class="bd-block"><div class="bd-block-h"><b>Versiones publicadas</b></div><ul class="pt-list">${B.versions.map(v => `<li><i class="fa-solid fa-code-commit"></i><b>v${v.version}</b><span>${esc(v.published_at.slice(0, 16).replace('T', ' '))}</span><button type="button" class="wk-btn sm" data-rv="${v.version}" title="Volver el borrador a esta versión">Restaurar</button></li>`).join('') || '<li class="pt-muted">Ninguna todavía</li>'}</ul></div>`;
  $('v-act')?.addEventListener('change', async e => { try { await api('form_state', { id: B.id, op: 'active', on: e.target.checked ? 1 : 0 }); B.form.active = e.target.checked ? 1 : 0; toast(e.target.checked ? 'Activo en el portal' : 'Desactivado'); } catch (er) { toast(er.message, true); } });
  pb.querySelectorAll('[data-rv]').forEach(b => b.addEventListener('click', async () => { if (!confirm('El borrador vuelve a la versión ' + b.dataset.rv + '. ¿Seguir?')) return; try { await save(true); await api('form_state', { id: B.id, op: 'restore', version: +b.dataset.rv }); location.reload(); } catch (e) { toast(e.message, true); } }));
}

// ── Publicar ─────────────────────────────────────────────────
async function publish() {
  const btn = $('bd-publish'); btn.disabled = true;
  try {
    if (!(await save(true))) return;
    const r = await api('form_publish', { id: B.id });
    B.form.version = r.version; B.form.active = 1;
    if (!r.same) B.versions.unshift({ version: r.version, published_at: new Date().toISOString().slice(0, 16).replace('T', ' ') });
    status(); toast(r.same ? 'No había cambios: sigue la versión ' + r.version : '¡Publicado! Versión ' + r.version);
    if (B.sel.kind === 'versions') drawProps();
  } catch (e) { toast(e.message, true); }
  finally { btn.disabled = false; }
}

// ── Vista previa ─────────────────────────────────────────────
function openPreview() {
  $('bd-prev').hidden = false; $('bd-device').dataset.d = B.device;
  const body = $('bd-prev-body'), steps = B.schema.mode === 'steps';
  body.innerHTML = `<div class="pt-fcard ptf" id="pv-card">${B.theme.cover ? `<div class="pt-cover" style="background-image:url('${esc(B.theme.cover)}');height:${B.theme.cover_h || 140}px"></div>` : `<div class="pt-cover plain" style="--c:${esc(B.theme.accent || B.meta.color)}"></div>`}
    <div class="pt-fhead"><span class="pt-fhead-ic" style="--c:${esc(B.theme.accent || B.meta.color)}"><i class="fa-solid ${esc(B.meta.icon)}"></i></span><div><h1>${esc(B.meta.name)}</h1>${B.meta.description ? `<p>${esc(B.meta.description)}</p>` : ''}</div></div>
    ${steps ? '<div class="pt-steps" id="pv-steps"></div>' : ''}<div id="pv-body" style="padding:18px 28px"></div><div class="pt-fnav" id="pv-nav"></div></div>`;
  const F = new PTForm($('pv-body'), { schema: B.schema, theme: B.theme, cats: B.cats, members: B.members, people: B.members, priorities: B.priorities, showEstimate: true, onChange: () => estSoon() });
  const card = $('pv-card'); ['--ptf-accent', '--ptf-bg', '--ptf-card', '--ptf-text', '--ptf-radius', '--ptf-gap', '--ptf-font'].forEach(k => { const v = F.el.style.getPropertyValue(k); if (v) card.style.setProperty(k, v); }); card.dataset.btn = F.el.dataset.btn;
  let step = 0;
  const draw = () => {
    if (!steps) { F.render(null); $('pv-nav').innerHTML = '<span class="sp"></span><button type="button" class="pt-btn primary" id="pv-send"><i class="fa-solid fa-paper-plane"></i> Probar el envío</button>'; }
    else {
      const list = F.activeSections(); step = Math.min(step, list.length);
      const names = [...list.map(i => B.schema.sections[i].title || 'Paso'), 'Fecha y resumen'];
      $('pv-steps').innerHTML = `<div class="pt-prog"><i style="--p:${Math.round(step * 100 / Math.max(1, names.length - 1))}%"></i></div><ol>${names.map((n, i) => `<li class="${i < step ? 'done' : i === step ? 'on' : ''}"><button type="button" disabled><span>${i < step ? '<i class="fa-solid fa-check"></i>' : i + 1}</span><em>${esc(n)}</em></button></li>`).join('')}</ol>`;
      if (step < list.length) { const s = B.schema.sections[list[step]]; F.render(list[step], `<header class="ptf-step-h"><span class="ptf-sec-ic"><i class="fa-solid ${esc(s.icon || 'fa-circle-dot')}"></i></span><div><h3>${esc(s.title || '')}</h3>${s.desc ? `<p>${esc(s.desc)}</p>` : ''}</div></header>`); }
      else { const sm = F.summary(); $('pv-body').innerHTML = `<div class="pt-rev"><div class="pt-rev-t"><span>Título</span><b>${esc(F.title || '—')}</b></div>${sm.map(g => `<div class="pt-rev-g"><h4>${esc(g.title || '')}</h4><dl>${g.rows.map(([l, v]) => `<div><dt>${esc(l)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl></div>`).join('')}<p class="pt-muted"><i class="fa-solid fa-calendar-days"></i> En el portal, acá aparece también el calendario de carga para elegir la fecha.</p></div>`; }
      $('pv-nav').innerHTML = `${step ? '<button type="button" class="wk-btn" id="pv-back"><i class="fa-solid fa-arrow-left"></i> Volver</button>' : ''}<span class="sp"></span>${step < list.length ? '<button type="button" class="pt-btn primary" id="pv-next">Continuar <i class="fa-solid fa-arrow-right"></i></button>' : '<button type="button" class="pt-btn primary" id="pv-send"><i class="fa-solid fa-paper-plane"></i> Probar el envío</button>'}`;
      $('pv-back')?.addEventListener('click', () => { step--; draw(); });
      $('pv-next')?.addEventListener('click', () => { const e = F.validate(list[step]); if (Object.keys(e).length) { F.firstError()?.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; } step++; draw(); });
    }
    $('pv-send')?.addEventListener('click', () => { const e = F.validate(null); if (Object.keys(e).length) { toast('Hay ' + Object.keys(e).length + ' campo(s) para revisar (así lo vería quien pide)', true); F.firstError()?.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; } toast('Todo bien: en el portal esto se enviaría (en la vista previa no se guarda nada)'); });
  };
  let tq; const estSoon = () => { clearTimeout(tq); tq = setTimeout(async () => { try { const r = await api('est_preview', { team_id: B.dept.id, schema: B.schema, answers: F.values(), category_id: B.meta.category_id }); $('bd-prev-est').innerHTML = `<i class="fa-solid fa-wand-magic-sparkles"></i> Tiempo calculado: <b>${PTFmt.fmtMin(r.work_min)}</b>${r.lines.length ? ' · ' + r.lines.map(l => esc(l[0])).join(' · ') : ''}`; } catch (e) { $('bd-prev-est').textContent = ''; } }, 350); };
  draw(); estSoon();
}
