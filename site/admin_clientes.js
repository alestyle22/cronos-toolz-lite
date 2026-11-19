/* admin_clientes.js — Sección Clientes del panel Admin (cronos): lista, ficha, servicios, archivos y notas.
   Usa esc(), $id(), ago() y admToast() del panel; los datos van a clientes_api.php. */
'use strict';

const CLI_API = '/toolz/clientes_api.php';
// Rubro (admin_clientes.php → window.CLI_META): estados editables, tipos de cliente, campos propios y campos fijos ocultos. Sin rubro, lo de siempre.
const CLI_META = window.CLI_META || { st: [], svc: [], types: [], fields: [], hidden: [], concepts: [] };
const cliMap = (list, def) => list.length ? Object.fromEntries(list.map(x => [x.key, x.label])) : def;
const CLI_ST = cliMap(CLI_META.st, { potencial: 'Potencial', activo: 'Activo', pausado: 'Pausado', terminado: 'Terminado' });
const CLI_SVC_ST = cliMap(CLI_META.svc, { pendiente: 'Pendiente', en_curso: 'En curso', pausado: 'Pausado', terminado: 'Terminado' });
const CLI_COLORS = Object.fromEntries([...CLI_META.st, ...CLI_META.svc].map(x => [x.key, x.color]));
const CLI_TYPES = Object.fromEntries((CLI_META.types || []).map(x => [x.key, x.label]));
const CLI_HIDE = new Set(CLI_META.hidden || []);
// Cómo se llama "cliente" en este sitio (vocab_lib.php): cT() → "cliente", cT('p') → "clientes", cT('s', 1) → "Cliente"
const cDef = () => !window.g7Term || g7Term('client') === 'Cliente';   // sin rubro: los textos de siempre, tal cual
const cT = (f = 's', cap = 0) => { const t = window.g7Term ? g7Term('client', f) : (f === 'p' ? 'Clientes' : 'Cliente'); return cap ? t : t.toLowerCase(); };
const CLI_SVC_TYPES = ['Branding', 'Identidad visual', 'Redes sociales', 'Diseño web', 'Packaging', 'Fotografía', 'Video', 'Campaña', 'Consultoría', 'Otro'];
const CLI_IVA = ['Responsable inscripto', 'Monotributo', 'Exento', 'Consumidor final', 'Otro'];
const CLI_KINDS = { logo: 'Logo', manual: 'Manual de marca', otro: 'Otro' };
const CLI_FIELDS = {
  contacto: [['name', 'Marca o empresa', 'text', true], ['contact_name', 'Persona de contacto'], ['email', 'Email', 'email'], ['phone', 'Teléfono', 'tel'], ['whatsapp', 'WhatsApp', 'tel']],
  fiscal: [['legal_name', 'Razón social'], ['cuit', 'CUIT', 'text', false, '20-12345678-9'], ['iva_cond', 'Condición frente al IVA', 'iva'], ['address', 'Domicilio'], ['city', 'Localidad'], ['province', 'Provincia']],
  redes: [['website', 'Sitio web', 'url', false, 'https://'], ['instagram', 'Instagram', 'text', false, '@usuario o link'], ['facebook', 'Facebook'], ['tiktok', 'TikTok'], ['linkedin', 'LinkedIn'], ['youtube', 'YouTube']],
};
const cliVis = g => CLI_FIELDS[g].filter(f => f[0] === 'name' || !CLI_HIDE.has(f[0])).map(f => f[0] === 'name' && window.g7Term ? [f[0], g7Term('client_name'), ...f.slice(2)] : f);
const CLI = { type: '', list: [], filter: 'todos', cur: null, dirty: false, tab: 'datos', inv: null, invDirty: false, limits: { file: 25 << 20, post: 64 << 20, count: 20 } };

async function cliApi(action, data = {}) {
  let d;
  try {
    const r = await fetch(CLI_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar con el servidor' }; }
  if (d.error) { admToast(d.error, true); throw new Error(d.error); }
  return d;
}
const cliFileUrl = (id, dl) => CLI_API + '?file=' + id + (dl ? '&dl=1' : '');
const cliSize = b => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const CLI_AV = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'];
function cliLogo(c, big) {
  const i = (c.name || '?').trim()[0]?.toUpperCase() || '?';
  if (c.avatar) return `<div class="cli-logo${big ? ' lg' : ''}"><img src="${esc(c.avatar)}" alt=""></div>`;
  if (c.logo_id) return `<div class="cli-logo${big ? ' lg' : ''}"><img src="${cliFileUrl(c.logo_id)}" alt=""></div>`;
  return `<div class="cli-logo${big ? ' lg' : ''}" style="background:${CLI_AV[i.charCodeAt(0) % 6]}">${esc(i)}</div>`;
}
const cliSt = (s, map = CLI_ST) => { const c = CLI_COLORS[s]; return `<span class="cli-st ${esc(s)}"${c && !['potencial', 'activo', 'pausado', 'terminado', 'en_curso', 'pendiente'].includes(s) ? ` style="color:${esc(c)};background:${esc(c)}1f"` : ''}>${esc(map[s] || s)}</span>`; };

// ── Lista ─────────────────────────────────────────────────────
async function cliLoad() {
  if (CLI.cur) return;               // si hay una ficha abierta, no se pisa
  const d = await cliApi('list'); CLI.list = d.clients; if (d.limits) CLI.limits = d.limits;
  cliRenderList();
}
function cliRenderList() {
  const L = CLI.list, q = ($id('cli-q').value || '').toLowerCase().trim();
  const n = s => L.filter(c => c.status === s).length;
  const act = (CLI_ST.activo || 'Activo') === 'Activo' ? `activo${n('activo') === 1 ? '' : 's'}` : CLI_ST.activo.toLowerCase();
  $id('cli-count').textContent = L.length ? `${L.length} ${L.length === 1 ? cT() : cT('p')} · ${n('activo')} ${act}` : `Todavía no cargaste ${cT('p')}`;
  const tc = $id('cli-typechips'), nt = k => L.filter(c => (c.type || '') === k).length;
  if (tc) tc.innerHTML = Object.keys(CLI_TYPES).length ? [['', 'Todos los tipos', L.length], ...Object.entries(CLI_TYPES).map(([k, v]) => [k, v, nt(k)]), ...(nt('') && L.length !== nt('') ? [['_sin', 'Sin tipo', nt('')]] : [])]
    .map(([k, v, c]) => `<button class="cli-chip${(CLI.type || '') === k ? ' on' : ''}" data-on-click="cliSetType" data-click-1="s:${k}">${esc(v)} <b>${c}</b></button>`).join('') : '';
  $id('cli-chips').innerHTML = [['todos', 'Todos', L.length], ...Object.entries(CLI_ST).map(([k, v]) => [k, v, n(k)])]
    .map(([k, v, c]) => `<button class="cli-chip${CLI.filter === k ? ' on' : ''}" data-on-click="cliSetFilter" data-click-1="s:${k}">${esc(v)} <b>${c}</b></button>`).join('');
  const ty = CLI.type || '';
  const rows = L.filter(c => (CLI.filter === 'todos' || c.status === CLI.filter) && (!ty || (ty === '_sin' ? !c.type : c.type === ty)) && (!q || [c.name, c.contact_name, c.email, c.phone].some(v => (v || '').toLowerCase().includes(q))));
  const g = $id('cli-grid');
  if (!L.length) { g.innerHTML = `<div class="cli-empty"><i class="fa-solid fa-address-book"></i><b>Todavía no hay ${cT('p')}</b><p>Carga el primero con sus datos de contacto${cDef() ? ', fiscales y de marca' : ''}.</p><button class="btn btn-primary" data-on-click="cliOpen" data-click-1="n:0"><i class="fa-solid fa-plus"></i> Nuevo ${cT()}</button></div>`; return; }
  if (!rows.length) { g.innerHTML = `<div class="cli-hint">Ningún ${cT()} coincide con la búsqueda.</div>`; return; }
  g.className = 'cli-grid';
  g.innerHTML = rows.map(c => `<div class="cli-card" data-on-click="cliOpen" data-click-1="j:${c.id}">
      <div class="cli-card-top">${cliLogo(c)}<div style="min-width:0"><div class="cli-name">${esc(c.name)}</div><div class="cli-meta">${CLI_TYPES[c.type] ? `<b style="color:var(--g7-text-3)">${esc(CLI_TYPES[c.type])}</b> · ` : ''}${esc(c.contact_name || c.email || c.phone || 'Sin contacto cargado')}</div></div></div>
      <div class="cli-card-foot">${cliSt(c.status)}<span>${+c.svc_active ? `${c.svc_active} en curso · ` : ''}${+c.svc_total} servicio${+c.svc_total === 1 ? '' : 's'}</span></div>
    </div>`).join('');
}

// ── Ficha ─────────────────────────────────────────────────────
async function cliOpen(id) {
  if (CLI.cur && CLI.dirty && !confirm(`Hay cambios sin guardar en este ${cT()}. ¿Descartarlos?`)) return;
  if (id) {
    const d = await cliApi('get', { id });
    CLI.cur = d; if (d.has) CLI.has = d.has;
  } else CLI.cur = { client: { id: 0, name: '', status: Object.keys(CLI_ST)[0] || 'potencial', type: CLI.type && CLI.type !== '_sin' ? CLI.type : '', brand_colors: [], custom: {} }, services: [], notes: [], files: [], invoices: [], contents: [], portal: {} };
  CLI.dirty = false; CLI.tab = 'datos'; CLI.inv = null;
  $id('cli-list-view').hidden = true; $id('cli-detail-view').hidden = false;
  cliRenderDetail(); window.scrollTo(0, 0);
}
function cliClose() {
  if (CLI.dirty && !confirm(`Hay cambios sin guardar en este ${cT()}. ¿Descartarlos?`)) return;
  CLI.cur = null; CLI.dirty = false;
  $id('cli-detail-view').hidden = true; $id('cli-list-view').hidden = false;
  cliLoad();
}
function cliField([k, label, type = 'text', req, ph], c) {
  const v = c[k] ?? '';
  const lab = `<label class="adm-label" for="cli-${k}">${esc(label)}${req ? ' <span class="cli-req">*</span>' : ''}</label>`;
  if (type === 'iva') return `<div class="cli-f">${lab}<select class="adm-select" id="cli-${k}" data-k="${k}"><option value="">—</option>${CLI_IVA.map(o => `<option${o === v ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
  return `<div class="cli-f">${lab}<input class="adm-input" id="cli-${k}" data-k="${k}" type="${type}" value="${esc(v)}"${ph ? ` placeholder="${esc(ph)}"` : ''}${req ? ' required' : ''}></div>`;
}
// Campos propios (custom_fields_lib.php): se guardan en client.custom
function cliCustomField(f, c) {
  const v = (c.custom || {})[f.key] ?? '', id = 'cli-cf-' + f.key, req = f.required ? ' <span class="cli-req">*</span>' : '';
  const lab = `<label class="adm-label" for="${id}">${esc(f.label)}${req}</label>`, a = `id="${id}" data-cf="${esc(f.key)}"`;
  if (f.type === 'select') return `<div class="cli-f">${lab}<select class="adm-select" ${a}><option value="">—</option>${f.options.map(o => `<option${o === v ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`;
  if (f.type === 'bool') return `<div class="cli-f">${lab}<select class="adm-select" ${a}><option value="">—</option><option value="1"${v === '1' ? ' selected' : ''}>Sí</option><option value="0">No</option></select></div>`;
  const type = { number: 'number', date: 'date', url: 'url', money: 'text' }[f.type] || 'text';
  return `<div class="cli-f">${lab}<input class="adm-input" ${a} type="${type}" value="${esc(v)}"${f.type === 'url' ? ' placeholder="https://"' : f.type === 'money' ? ' inputmode="decimal" placeholder="0,00"' : ''}${type === 'number' ? ' step="any"' : ''}></div>`;
}
const cliCard = (icon, title, body) => `<div class="adm-card"><div class="adm-card-head"><div class="adm-card-title"><i class="fa-solid ${icon}"></i> ${title}</div></div><div class="adm-card-body">${body}</div></div>`;

function cliRenderDetail() {
  const { client: c, services: S, files: F, notes: N } = CLI.cur, I = CLI.cur.invoices || [], K = CLI.cur.contents || [], isNew = !c.id;
  const logoFile = F.find(f => f.kind === 'logo' && /^image\/(png|jpeg|webp|gif|avif)$/.test(f.mime));
  const tabs = [['datos', 'Datos'], ['marca', 'Marca y redes'], ['servicios', 'Servicios', S.length], ['contenidos', 'Contenidos', K.length], ['facturas', 'Facturas', I.length],
    ['archivos', 'Archivos', F.length], ['notas', 'Notas', N.length], ['dashboard', 'Dashboard']]
    .filter(([k]) => !((k === 'facturas' && CLI.has?.facturas === false) || (k === 'dashboard' && CLI.has?.portal === false)));   // edición Lite: sin facturas ni portal
  $id('cli-detail-view').innerHTML = `
    <button class="cli-back" data-on-click="cliClose"><i class="fa-solid fa-arrow-left"></i> ${esc(cT('p', 1))}</button>
    <div class="cli-head">
      <div class="cli-av-wrap">${cliLogo({ name: c.name || '?', avatar: c.avatar, logo_id: logoFile?.id }, true)}
        ${isNew ? '' : `<button class="cli-av-btn" id="cli-av-btn" title="Cambiar la foto de perfil" aria-label="Cambiar la foto de perfil"><i class="fa-solid fa-camera"></i></button>
        <input type="file" id="cli-av-in" accept="image/png,image/jpeg,image/webp,image/gif" hidden>`}</div>
      <div class="cli-head-main"><div class="adm-title" id="cli-title">${esc(c.name || 'Nuevo ' + cT())}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><select class="adm-select" id="cli-status" data-k="status" style="width:auto">${Object.entries(CLI_ST).map(([k, v]) => `<option value="${esc(k)}"${c.status === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}${CLI_ST[c.status] || !c.status ? '' : `<option value="${esc(c.status)}" selected>${esc(c.status)}</option>`}</select>
        ${Object.keys(CLI_TYPES).length ? `<select class="adm-select" id="cli-type" data-k="type" style="width:auto" title="Tipo"><option value="">Sin tipo</option>${Object.entries(CLI_TYPES).map(([k, v]) => `<option value="${esc(k)}"${c.type === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>` : ''}</div></div>
      <div class="cli-head-act">
        ${isNew ? '' : '<button class="btn btn-danger btn-sm" data-on-click="cliDelete"><i class="fa-solid fa-trash"></i> Eliminar</button>'}
        <button class="btn btn-primary" id="cli-save" data-on-click="cliSave"${isNew ? '' : ' disabled'}><i class="fa-solid fa-floppy-disk"></i> ${isNew ? 'Crear ' + cT() : 'Guardar cambios'}</button>
      </div>
    </div>
    <div class="cli-tabs">${tabs.map(([k, v, n]) => `<button class="cli-tab${CLI.tab === k ? ' on' : ''}" data-tab="${k}"${isNew && !['datos', 'marca'].includes(k) ? ` disabled title="Primero crea el ${cT()}"` : ''}>${v}${n ? `<b>${n}</b>` : ''}</button>`).join('')}</div>

    <div class="cli-pane" data-pane="datos">
      ${cliCard('fa-address-card', 'Contacto', `<div class="adm-form-grid">${cliVis('contacto').map(f => cliField(f, c)).join('')}</div>`)}
      ${CLI_META.fields.length ? cliCard('fa-list-check', 'Más datos', `<div class="adm-form-grid">${CLI_META.fields.map(f => cliCustomField(f, c)).join('')}</div>`) : ''}
      ${cliVis('fiscal').length ? cliCard('fa-file-invoice', 'Datos fiscales', `<div class="adm-form-grid">${cliVis('fiscal').map(f => cliField(f, c)).join('')}</div>`) : ''}
      ${cliCard('fa-note-sticky', 'Observaciones', `<div class="cli-f"><textarea class="adm-input" data-k="notes" placeholder="Lo que conviene tener siempre a mano de este ${cT()}">${esc(c.notes || '')}</textarea></div>`)}
    </div>
    <div class="cli-pane" data-pane="marca">
      ${CLI_HIDE.has('brand_colors') && CLI_HIDE.has('brand_fonts') ? '' : cliCard('fa-palette', 'Identidad de marca', `
        <div${CLI_HIDE.has('brand_colors') ? ' hidden' : ''}><label class="adm-label">Colores de marca</label>
        <div class="cli-colors" id="cli-colors"></div></div>
        ${CLI_HIDE.has('brand_fonts') ? '' : `<div class="adm-form-grid" style="margin-top:14px;margin-bottom:0"><div class="cli-f wide"><label class="adm-label" for="cli-brand_fonts">Tipografías</label><input class="adm-input" id="cli-brand_fonts" data-k="brand_fonts" value="${esc(c.brand_fonts || '')}" placeholder="Ej.: Montserrat (títulos), Inter (textos)"></div></div>`}`)}
      ${cliCard('fa-share-nodes', 'Web y redes', `<div class="adm-form-grid">${cliVis('redes').map(f => cliField(f, c)).join('')}
        <div class="cli-f wide"><label class="adm-label">Otros links</label><textarea class="adm-input" data-k="other_links" placeholder="Uno por línea: Behance, Pinterest, Drive, etc.">${esc(c.other_links || '')}</textarea></div></div>`)}
    </div>
    <div class="cli-pane" data-pane="servicios"><div id="cli-svc"></div></div>
    <div class="cli-pane" data-pane="archivos"><div id="cli-files"></div>${isNew ? '' : `<div id="cli-oc" data-client="${+c.id}"></div>`}</div>
    <div class="cli-pane" data-pane="notas"><div id="cli-notes"></div></div>
    <div class="cli-pane" data-pane="contenidos"><div id="cli-contents"></div></div>
    <div class="cli-pane" data-pane="facturas"><div id="cli-inv"></div></div>
    <div class="cli-pane" data-pane="dashboard"><div id="cli-portal"></div></div>`;

  const box = $id('cli-detail-view');
  box.querySelectorAll('.cli-tab').forEach(b => b.onclick = () => { CLI.tab = b.dataset.tab; cliShowTab(); });
  box.querySelectorAll('[data-k]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => {
    CLI.cur.client[el.dataset.k] = el.value; cliTouch();
    if (el.dataset.k === 'name') $id('cli-title').textContent = el.value || 'Nuevo ' + cT();
  }));
  box.querySelectorAll('[data-cf]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => {
    (CLI.cur.client.custom ||= {})[el.dataset.cf] = el.value; cliTouch();
  }));
  cliRenderColors(); cliRenderServices(); cliRenderFiles(); cliRenderNotes();
  if (!isNew) cliBindAvatar();
  if (!isNew) { cliRenderContents(); cliRenderInvoices(); cliRenderPortal(); }
  cliShowTab();
  if (isNew) setTimeout(() => $id('cli-name')?.focus(), 50);
}
// ── Foto de perfil ────────────────────────────────────────────
function cliBindAvatar() {
  const btn = $id('cli-av-btn'), inp = $id('cli-av-in'), c = CLI.cur.client;
  btn.onclick = () => {
    if (!c.avatar) return inp.click();
    // Con foto: cambiarla o quitarla
    const m = document.createElement('div'); m.className = 'cli-av-menu';
    m.innerHTML = '<button data-a="new"><i class="fa-solid fa-image"></i> Cambiar foto</button><button data-a="del"><i class="fa-solid fa-trash"></i> Quitar foto</button>';
    btn.parentNode.appendChild(m);
    const close = e => { if (!m.contains(e.target)) { m.remove(); document.removeEventListener('click', close, true); } };
    setTimeout(() => document.addEventListener('click', close, true));
    m.querySelector('[data-a=new]').onclick = () => { m.remove(); inp.click(); };
    m.querySelector('[data-a=del]').onclick = async () => { m.remove(); await cliAvatarSend(null); };
  };
  inp.onchange = async () => { const f = inp.files[0]; inp.value = ''; if (f) await cliAvatarSend(f); };
}
async function cliAvatarSend(file) {
  const fd = new FormData(); fd.append('action', 'avatar'); fd.append('client_id', CLI.cur.client.id);
  if (file) fd.append('image', await g7PrepImage(file, { max: 600, maxBytes: 400 << 10 })); else fd.append('remove', '1');
  const btn = $id('cli-av-btn'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
  let d;
  try { d = await (await fetch(CLI_API, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { d = { error: 'No se pudo subir la foto' }; }
  if (d.error) { admToast(d.error, true); btn.innerHTML = '<i class="fa-solid fa-camera"></i>'; return; }
  CLI.cur.client.avatar = d.avatar; const L = CLI.list.find(x => +x.id === +CLI.cur.client.id); if (L) L.avatar = d.avatar;
  admToast(file ? 'Foto actualizada' : 'Foto quitada');
  const dirty = CLI.dirty; cliRenderDetail(); if (dirty) cliTouch();
}

function cliShowTab() {
  const box = $id('cli-detail-view');
  box.querySelectorAll('.cli-tab').forEach(b => b.classList.toggle('on', b.dataset.tab === CLI.tab));
  box.querySelectorAll('.cli-pane').forEach(p => p.classList.toggle('on', p.dataset.pane === CLI.tab));
}
function cliTouch() { CLI.dirty = true; $id('cli-save').disabled = false; }

function cliRenderColors() {
  if (!$id('cli-colors')) return;   // el rubro ocultó los colores de marca
  const cols = CLI.cur.client.brand_colors;
  $id('cli-colors').innerHTML = cols.map((c, i) => `<div class="cli-color"><input type="color" value="${esc(c)}" data-i="${i}"><input type="text" value="${esc(c)}" data-i="${i}" maxlength="7"><button data-del="${i}" title="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('')
    + (cols.length < 12 ? '<button class="btn btn-ghost btn-sm" id="cli-addcolor"><i class="fa-solid fa-plus"></i> Agregar color</button>' : '');
  const box = $id('cli-colors');
  box.querySelectorAll('input').forEach(inp => inp.oninput = () => {
    let v = inp.value.trim(); if (v && v[0] !== '#') v = '#' + v;
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
    cols[+inp.dataset.i] = v.toLowerCase(); cliTouch();
    const sib = box.querySelector(`input[data-i="${inp.dataset.i}"][type="${inp.type === 'color' ? 'text' : 'color'}"]`); if (sib) sib.value = v;
  });
  box.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { cols.splice(+b.dataset.del, 1); cliTouch(); cliRenderColors(); });
  const add = $id('cli-addcolor'); if (add) add.onclick = () => { cols.push('#ff6600'); cliTouch(); cliRenderColors(); };
}

async function cliSave() {
  const c = CLI.cur.client;
  if (!(c.name || '').trim()) { admToast(cDef() ? 'Falta el nombre del cliente o la marca' : 'Falta el nombre', true); CLI.tab = 'datos'; cliShowTab(); $id('cli-name')?.focus(); return; }
  const btn = $id('cli-save'); btn.disabled = true;
  try {
    const r = await cliApi('save', { client: c });
    const wasNew = !c.id; CLI.dirty = false;
    admToast(wasNew ? cT('s', 1) + ' creado' : 'Cambios guardados');
    const d = await cliApi('get', { id: r.id }); CLI.cur = d;
    if (!wasNew) { btn.disabled = true; return; }
    cliRenderDetail();
  } catch (e) { btn.disabled = false; }
}
async function cliDelete() {
  const c = CLI.cur.client;
  if (!confirm(`¿Eliminar a "${c.name}"? Se borran también sus servicios, notas y archivos. No se puede deshacer.`)) return;
  await cliApi('delete', { id: c.id });
  admToast(cT('s', 1) + ' eliminado'); CLI.dirty = false; cliClose();
}
async function cliReload(tab) {
  const d = await cliApi('get', { id: CLI.cur.client.id });
  const keep = CLI.cur.client, dirty = CLI.dirty;
  CLI.cur = d; if (dirty) CLI.cur.client = keep;       // lo que se estaba escribiendo en la ficha no se pierde
  CLI.tab = tab; cliRenderDetail(); if (dirty) cliTouch();
}

// ── Servicios / proyectos ─────────────────────────────────────
function cliSvcForm(s) {
  const o = (map, v) => Object.entries(map).map(([k, t]) => `<option value="${k}"${v === k ? ' selected' : ''}>${t}</option>`).join('');
  return `<div class="cli-row" data-id="${s.id || 0}">
    <div class="cli-row-grid">
      <div class="cli-f"><label class="adm-label">Servicio o proyecto</label><input class="adm-input" data-s="name" value="${esc(s.name || '')}" placeholder="Ej.: Rediseño de marca"></div>
      <div class="cli-f"><label class="adm-label">Tipo</label><input class="adm-input" data-s="type" list="cli-types" value="${esc(s.type || '')}" placeholder="Elige o escribe"></div>
      <div class="cli-f"><label class="adm-label">Estado</label><select class="adm-select" data-s="status">${o(CLI_SVC_ST, s.status || 'pendiente')}</select></div>
      <div class="cli-f"><label class="adm-label">Inicio</label><input class="adm-input" type="date" data-s="start_date" value="${esc(s.start_date || '')}"></div>
      <div class="cli-f"><label class="adm-label">Fin</label><input class="adm-input" type="date" data-s="end_date" value="${esc(s.end_date || '')}"></div>
    </div>
    <div class="cli-row-foot"><input class="adm-input" data-s="notes" value="${esc(s.notes || '')}" placeholder="Detalle o próximos pasos">
      <div style="display:flex;gap:6px">${s.id ? '<button class="btn btn-danger btn-sm" data-act="del" title="Eliminar"><i class="fa-solid fa-trash"></i></button>' : ''}
      <button class="btn ${s.id ? 'btn-ghost' : 'btn-primary'} btn-sm" data-act="save"><i class="fa-solid ${s.id ? 'fa-check' : 'fa-plus'}"></i> ${s.id ? 'Guardar' : 'Agregar'}</button></div></div>
  </div>`;
}
function cliRenderServices() {
  const S = CLI.cur.services;
  $id('cli-svc').innerHTML = `<datalist id="cli-types">${CLI_SVC_TYPES.map(t => `<option value="${esc(t)}">`).join('')}</datalist>
    ${cliCard('fa-plus', 'Nuevo servicio o proyecto', cliSvcForm({}).replace('class="cli-row"', 'class="cli-row" style="margin:0;border:0;padding:0;background:none"'))}
    ${S.length ? S.map(s => `<div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 6px"><b style="font-size:13px">${esc(s.name)}</b>${cliSt(s.status, CLI_SVC_ST)}</div>${cliSvcForm(s)}`).join('') : '<div class="cli-hint">Todavía no hay servicios ni proyectos cargados.</div>'}`;
  $id('cli-svc').querySelectorAll('.cli-row').forEach(row => {
    row.querySelector('[data-act="save"]').onclick = async () => {
      const s = { id: +row.dataset.id, client_id: CLI.cur.client.id };
      row.querySelectorAll('[data-s]').forEach(el => s[el.dataset.s] = el.value);
      if (!s.name.trim()) { admToast('Falta el nombre del servicio o proyecto', true); return; }
      await cliApi('svc_save', { service: s }); admToast(s.id ? 'Servicio guardado' : 'Servicio agregado'); cliReload('servicios');
    };
    const del = row.querySelector('[data-act="del"]');
    if (del) del.onclick = async () => { if (!confirm('¿Eliminar este servicio?')) return; await cliApi('svc_delete', { id: +row.dataset.id }); cliReload('servicios'); };
  });
}

// ── Archivos ──────────────────────────────────────────────────
function cliRenderFiles() {
  const F = CLI.cur.files;
  const icon = f => /pdf/.test(f.mime) ? 'fa-file-pdf' : /zip|rar/.test(f.mime + f.name) ? 'fa-file-zipper' : /font|\.(ttf|otf|woff2?)$/i.test(f.mime + f.name) ? 'fa-font' : /video/.test(f.mime) ? 'fa-file-video' : /image/.test(f.mime) ? 'fa-file-image' : 'fa-file';
  const thumb = f => /^image\/(png|jpeg|webp|gif|avif)$/.test(f.mime) ? `<img src="${cliFileUrl(f.id)}" alt="" loading="lazy">` : `<i class="fa-solid ${icon(f)}"></i>`;
  $id('cli-files').innerHTML = `
    <a class="cli-mat-link" href="/toolz/stock.php?c=${CLI.cur.client.id}"><i class="fa-solid fa-folder-open"></i><span><b>Abrir la carpeta en Materiales</b><small>Ahí se organizan en carpetas (Logos, Tipografías…), se suben carpetas enteras y archivos pesados, y se descarga todo junto.</small></span><i class="fa-solid fa-arrow-right"></i></a>
    <div class="cli-drop" id="cli-drop"><i class="fa-solid fa-cloud-arrow-up"></i>Arrastra archivos acá o elegilos desde tu computadora<br><small style="color:var(--g7-text-4)">Logos, manual de marca, tipografías, PDF, ZIP… hasta ${cliSize(CLI.limits.file)} cada uno</small>
      <div class="cli-drop-act"><select class="adm-select" id="cli-upkind">${Object.entries(CLI_KINDS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
      <button class="btn btn-primary btn-sm" id="cli-pick"><i class="fa-solid fa-folder-open"></i> Elegir archivos</button><input type="file" id="cli-file" multiple hidden></div></div>
    ${F.length ? `<div class="cli-files">${F.map(f => `<div class="cli-file" data-id="${f.id}">
        <a class="cli-file-th" href="${cliFileUrl(f.id)}" target="_blank" rel="noopener">${thumb(f)}</a>
        <div class="cli-file-b"><div class="cli-file-n" title="${esc(f.name)}">${esc(f.name)}</div><div class="cli-file-m">${cliSize(+f.size)} · ${esc(ago(f.created_at))}</div>
          <div class="cli-file-a"><select class="adm-select" data-kind>${Object.entries(CLI_KINDS).map(([k, v]) => `<option value="${k}"${f.kind === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
          <a class="btn btn-ghost btn-sm" href="${cliFileUrl(f.id, 1)}" title="Descargar"><i class="fa-solid fa-download"></i></a>
          <button class="btn btn-danger btn-sm" data-del title="Borrar"><i class="fa-solid fa-trash"></i></button></div></div></div>`).join('')}</div>` : '<div class="cli-hint">Todavía no hay archivos de este cliente.</div>'}`;
  const drop = $id('cli-drop'), inp = $id('cli-file');
  $id('cli-pick').onclick = () => inp.click();
  inp.onchange = () => inp.files.length && cliUpload(inp.files);
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => e.dataTransfer.files.length && cliUpload(e.dataTransfer.files));
  $id('cli-files').querySelectorAll('.cli-file').forEach(el => {
    el.querySelector('[data-kind]').onchange = async e => { await cliApi('file_kind', { id: +el.dataset.id, kind: e.target.value }); cliReload('archivos'); };
    el.querySelector('[data-del]').onclick = async () => { if (!confirm('¿Borrar este archivo?')) return; await cliApi('file_delete', { id: +el.dataset.id }); cliReload('archivos'); };
  });
}
async function cliUpload(files) {
  // Lo que el servidor rechazaría se avisa antes de mandarlo
  const L = CLI.limits, big = [...files].filter(f => f.size > L.file);
  files = [...files].filter(f => f.size <= L.file);
  if (big.length) alert(`Estos archivos pesan más de ${cliSize(L.file)}, el máximo que acepta el servidor:\n` + big.map(f => `${f.name} (${cliSize(f.size)})`).join('\n'));
  if (!files.length) return;
  if (files.length > L.count || files.reduce((a, f) => a + f.size, 0) > L.post * .95) { admToast(`Sube de a menos archivos: el servidor acepta hasta ${cliSize(L.post)} por vez`, true); return; }
  const fd = new FormData();
  fd.append('action', 'upload'); fd.append('client_id', CLI.cur.client.id); fd.append('kind', $id('cli-upkind').value);
  for (const f of files) fd.append('files[]', f);
  $id('cli-drop').classList.add('over'); $id('cli-pick').disabled = true; $id('cli-pick').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo…';
  let d;
  try { d = await (await fetch(CLI_API, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { d = { error: 'No se pudo subir: revisa la conexión o el tamaño' }; }
  if (d.error) admToast(d.error, true);
  else {
    if (d.rechazados?.length) alert('No se subieron:\n' + d.rechazados.join('\n'));
    if (d.subidos?.length) admToast(d.subidos.length === 1 ? 'Archivo subido' : d.subidos.length + ' archivos subidos');
  }
  cliReload('archivos');
}

// ── Notas ─────────────────────────────────────────────────────
function cliRenderNotes() {
  const N = CLI.cur.notes;
  $id('cli-notes').innerHTML = `${cliCard('fa-pen', 'Nueva nota', `<div class="cli-f"><textarea class="adm-input" id="cli-note" placeholder="Llamadas, acuerdos, pedidos, recordatorios…"></textarea></div>
      <div style="display:flex;justify-content:flex-end;margin-top:10px"><button class="btn btn-primary btn-sm" id="cli-note-add"><i class="fa-solid fa-plus"></i> Agregar nota</button></div>`)}
    ${N.length ? N.map(n => `<div class="cli-note"><div class="cli-note-h"><span><b style="color:var(--g7-text-2)">${esc(n.author || '—')}</b> · ${esc(new Date(n.created_at.replace(' ', 'T')).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' }))}</span>
      <button class="btn btn-ghost btn-sm" data-del="${n.id}" title="Borrar nota"><i class="fa-solid fa-trash"></i></button></div><div class="cli-note-b">${esc(n.body)}</div></div>`).join('') : '<div class="cli-hint">Todavía no hay notas de este cliente.</div>'}`;
  $id('cli-note-add').onclick = async () => {
    const body = $id('cli-note').value.trim(); if (!body) return;
    await cliApi('note_add', { client_id: CLI.cur.client.id, body }); cliReload('notas');
  };
  $id('cli-notes').querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (!confirm('¿Borrar esta nota?')) return; await cliApi('note_delete', { id: +b.dataset.del }); cliReload('notas'); });
}

// ── Contenidos (los de Promos con este cliente como proyecto) ─
const CLI_CT = { promo: 'Promo', launch: 'Lanzamiento', paid: 'Pauta', social: 'Redes', otro: 'Otro', tarea: 'Tarea', mailings: 'Mailing', custom: 'Contenido' };
const cliDate = d => d ? new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString('es-AR') : '';
function cliRenderContents() {
  const K = CLI.cur.contents || [];
  $id('cli-contents').innerHTML = `<div class="cli-hint" style="text-align:left;margin-bottom:14px">Son los contenidos de <b>Promos</b> que tienen a <b>${esc(CLI.cur.client.name)}</b> como proyecto. Es lo que el cliente ve en su dashboard, en "Ver contenidos".
      <div style="margin-top:10px"><a class="btn btn-primary btn-sm" href="/toolz/promos/" target="_blank"><i class="fa-solid fa-plus"></i> Cargar contenidos en Promos</a></div></div>
    ${K.length ? `<div class="adm-table-wrap"><table class="adm-table"><thead><tr><th>Contenido</th><th>Tipo</th><th>Fechas</th></tr></thead><tbody>${K.map(k => `<tr><td><b>${esc(k.name)}</b></td><td>${esc(CLI_CT[k.type] || k.type)}${k.category ? ' · ' + esc(k.category) : ''}</td><td>${esc([cliDate(k.start_date), cliDate(k.end_date)].filter(Boolean).join(' al '))}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="cli-hint">Todavía no hay contenidos de este cliente en Promos.</div>'}`;
}

// ── Facturas y pagos (formato de la plantilla de Cronos) ──────
// Historial en pestañas, panel de edición a la izquierda y la factura tal como la ve el cliente a la derecha.
const CLI_INV_ST = { pagada: 'Pagada', parcial: 'Pago parcial', vencida: 'Vencida', pendiente: 'Pendiente' };
const CLI_PAY = ['Transferencia', 'Efectivo', 'Mercado Pago', 'Tarjeta', 'Otro'];
const cliMoney = (n, cur) => (cur === 'USD' ? 'US$ ' : cur === 'EUR' ? '€ ' : '$ ') + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cliToday = () => { const d = new Date(); return new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };   // fecha local, no UTC
// "1.500,50" o "1500,50" (coma decimal) y "1500.50" (punto decimal) → "1500.50"
const cliNum = v => { v = String(v || '').trim().replace(/\s|\$/g, ''); return v.includes(',') ? v.replace(/\./g, '').replace(',', '.') : v; };
// FAC-007 → FAC-008, igual que el servidor
const cliNextRef = (last, prefix) => { const m = String(last || '').trim().match(/^(.*?)(\d+)$/); return m ? m[1] + String(+m[2] + 1).padStart(m[2].length, '0') : (prefix || '') + '001'; };
const CLI_INV_FIELDS = ['number', 'issue_date', 'due_date', 'period', 'concept', 'description', 'currency', 'subtotal_expr', 'discount'];
let cliPrevT = 0;

function cliInvDraft() {
  const I = CLI.cur.invoices || [], B = CLI.cur.billing || {}, last = I[0];
  const svc = (CLI.cur.services || []).find(s => s.status === 'en_curso') || (CLI.cur.services || [])[0];
  return { id: 0, number: cliNextRef(last?.number, B.prefix), issue_date: cliToday(), due_date: '', period: B.period || '1 Mes',
    concept: last?.concept || svc?.name || '', description: last?.description ?? B.description ?? '', currency: last?.currency || B.currency || 'USD',
    subtotal_expr: last ? (last.subtotal_expr || String(+last.subtotal)) : '', discount: '' };
}
function cliRenderInvoices() {
  const c = CLI.cur.client, I = CLI.cur.invoices || [], tabs = [...I].reverse(), closed = c.status === 'terminado', tot = {};
  I.forEach(i => { const t = (tot[i.currency] ||= { f: 0, p: 0 }); t.f += +i.amount; t.p += +i.paid; });
  if (CLI.inv == null || (CLI.inv && !I.some(i => +i.id === CLI.inv))) CLI.inv = I.length ? +I[0].id : 0;
  const cur = I.find(i => +i.id === CLI.inv), d = cur ? { ...cur, subtotal_expr: cur.subtotal_expr || String(+cur.subtotal) } : cliInvDraft();
  CLI.invDirty = false;
  const fld = (k, label, attrs = '') => `<div class="cli-f"><label class="adm-label" for="iv-${k}">${label}</label><input class="adm-input" id="iv-${k}" data-v="${k}" value="${esc(d[k] ?? '')}" ${attrs}></div>`;
  // Las facturas hechas en Facturación tienen varias líneas e IVA: acá se ven y se cobran, pero se editan allá
  const isFact = cur?.source === 'fact', FACT = '/toolz/facturacion/', factOk = !!CLI.cur.fact_ok;
  $id('cli-inv').innerHTML = `
    ${Object.keys(tot).length ? `<div class="adm-stats" style="margin-bottom:16px">${Object.entries(tot).map(([cur, t]) => `
      <div class="adm-stat"><div class="adm-stat-val" style="font-size:18px">${cliMoney(t.f, cur)}</div><div class="adm-stat-label">Facturado ${esc(cur)}</div></div>
      <div class="adm-stat"><div class="adm-stat-val" style="font-size:18px">${cliMoney(t.p, cur)}</div><div class="adm-stat-label">Pagado ${esc(cur)}</div></div>
      <div class="adm-stat"><div class="adm-stat-val" style="font-size:18px;color:var(--g7-accent)">${cliMoney(Math.max(0, t.f - t.p), cur)}</div><div class="adm-stat-label">Saldo ${esc(cur)}</div></div>`).join('')}</div>` : ''}
    <div class="cx-bar">
      <div class="cx-bar-t">${esc(String(CLI.cur.sys || '').toUpperCase())} <span>${esc(c.name)}</span>${closed ? `<span class="cx-closed"><i class="fa-solid fa-lock"></i> Contrato finalizado${c.closed_date ? ' el ' + esc(cliDate(c.closed_date)) : ''}</span>` : ''}</div>
      <div class="cx-bar-a">
        ${factOk ? `<a class="btn btn-ghost btn-sm" href="${FACT}#facturas/cliente/${c.id}"><i class="fa-solid fa-file-invoice-dollar"></i> Ver en Facturación</a>
        <a class="btn btn-ghost btn-sm" href="${FACT}#nueva/cliente/${c.id}"><i class="fa-solid fa-plus"></i> Factura con líneas</a>` : ''}
        <button class="btn btn-ghost btn-sm" id="iv-new"><i class="fa-solid fa-plus"></i> Nueva factura</button>
        <button class="btn btn-primary btn-sm" id="iv-next"${I.length ? '' : ' disabled title="Primero carga la primera factura"'}><i class="fa-solid fa-rotate-right"></i> Generar siguiente mes</button>
        <a class="btn btn-ghost btn-sm" href="${CLI_API}?report=${c.id}" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Historial PDF</a>
        <button class="btn btn-ghost btn-sm" id="iv-bill"><i class="fa-solid fa-sliders"></i> Datos de facturación</button>
        <button class="btn ${closed ? 'btn-ghost' : 'btn-danger'} btn-sm" id="iv-close"><i class="fa-solid ${closed ? 'fa-lock-open' : 'fa-lock'}"></i> ${closed ? 'Reabrir contrato' : 'Finalizar contrato'}</button>
      </div>
    </div>
    ${(CLI.cur.fact_drafts || []).length ? `<div class="cx-hist cx-drafts"><span class="cx-hl"><i class="fa-solid fa-pen-ruler"></i> Borradores sin emitir:</span>
      ${CLI.cur.fact_drafts.map(b => factOk ? `<a class="cx-tab" href="${FACT}#f/${b.id}" title="Abrir en Facturación para emitirla">${esc(b.concept || 'Sin concepto')} · ${cliMoney(+b.amount, b.currency)}</a>` : `<span class="cx-tab">${esc(b.concept || 'Sin concepto')} · ${cliMoney(+b.amount, b.currency)}</span>`).join('')}
      <span class="cx-small">El cliente no los ve hasta que se emiten.</span></div>` : ''}
    <div class="cx-hist"><span class="cx-hl">Historial:</span>
      ${tabs.map(i => `<button class="cx-tab${+i.id === CLI.inv ? ' on' : ''} ${esc(i.status)}" data-tab-inv="${i.id}">${esc(i.number || 'Sin número')}${i.period ? ` (${esc(i.period)})` : ''}${i.status === 'pagada' ? ' <b>✓</b>' : ''}</button>`).join('')}
      ${!cur ? '<button class="cx-tab on">Nueva factura</button>' : ''}
    </div>
    <div class="cx-ws">
      <aside class="cx-side">
        <h3>${cur ? 'Panel de edición' : 'Nueva factura'}</h3>
        ${cur ? `<div class="cx-state">${cliInvSt(cur.status)}${cur.status !== 'pagada' && +cur.paid > 0 ? `<span>Falta ${cliMoney(cur.balance, cur.currency)}</span>` : ''}</div>` : ''}
        ${isFact ? `<div class="cx-small" style="margin:10px 0 14px;line-height:1.55">Esta factura se hizo en <b>Facturación</b> (${(cur.items || []).length} ${(cur.items || []).length === 1 ? 'línea' : 'líneas'}). Acá puedes registrar pagos; para corregirla, duplicarla o cancelarla, abrila allá.</div>
          ${factOk ? `<a class="btn btn-primary" href="${FACT}#f/${cur.id}" style="width:100%;justify-content:center"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir en Facturación</a>` : ''}` : ''}
        <div class="cx-legacy"${isFact ? ' hidden' : ''}>
        <div class="cx-grid">
          ${fld('number', 'Referencia de factura', 'placeholder="FAC-001"')}
          ${fld('period', 'Período', 'placeholder="1 Mes, 2 Meses…"')}
          ${fld('issue_date', 'Fecha de emisión', 'type="date"')}
          ${fld('due_date', 'Vence (opcional)', 'type="date"')}
        </div>
        ${fld('concept', 'Título del servicio', 'placeholder="Ej.: Gestión de Marca"')}
        <div class="cli-f"><label class="adm-label" for="iv-description">Descripción del servicio</label><textarea class="adm-input" id="iv-description" data-v="description" rows="5" placeholder="Una línea por ítem">${esc(d.description || '')}</textarea></div>
        <div class="cx-grid">
          <div class="cli-f"><label class="adm-label" for="iv-subtotal_expr">Monto base <span class="cli-req">*</span></label>
            <div style="display:flex;gap:6px"><select class="adm-select" data-v="currency" style="width:78px;flex:none">${['USD', 'ARS', 'EUR'].map(x => `<option${d.currency === x ? ' selected' : ''}>${x}</option>`).join('')}</select>
            <input class="adm-input" id="iv-subtotal_expr" data-v="subtotal_expr" value="${esc(d.subtotal_expr || '')}" placeholder="160 o 160*2" inputmode="decimal"></div></div>
          ${fld('discount', 'Descuento', 'placeholder="10% o 20"')}
        </div>
        <div class="cx-calc" id="iv-calc"></div>
        <div class="cli-f"><label class="adm-label">Factura original (PDF o imagen, opcional)</label><input class="adm-input" type="file" id="iv-file" accept=".pdf,.png,.jpg,.jpeg,.webp">
          ${cur?.has_file ? `<a href="${CLI_API}?inv=${cur.id}" target="_blank" class="cx-small"><i class="fa-solid fa-paperclip"></i> ${esc(cur.file_name)}</a>` : ''}</div>
        <div class="cx-act">
          <button class="btn btn-primary" id="iv-save"><i class="fa-solid fa-floppy-disk"></i> ${cur ? 'Guardar cambios' : 'Crear factura'}</button>
          ${cur ? `<a class="btn btn-ghost" href="${CLI_API}?print=${cur.id}" target="_blank" rel="noopener" title="Imprimir o guardar como PDF"><i class="fa-solid fa-download"></i> PDF</a>
          <button class="btn btn-danger btn-sm" id="iv-del" title="Borrar factura"><i class="fa-solid fa-trash"></i></button>` : ''}
        </div>
        </div>
        ${cur ? `<div class="cx-paybox"><h3>Pagos</h3>
          ${cur.payments.map(p => `<div class="cx-payrow"><i class="fa-solid fa-circle-check"></i><b>${cliMoney(p.amount, cur.currency)}</b><span>${esc(cliDate(p.pay_date))} · ${esc(p.method)}${p.note ? ' · ' + esc(p.note) : ''}</span>
            <button class="btn btn-ghost btn-sm" data-paydel="${p.id}" title="Borrar pago"><i class="fa-solid fa-trash"></i></button></div>`).join('') || '<div class="cx-small" style="margin-bottom:8px">Todavía no hay pagos registrados.</div>'}
          ${cur.status !== 'pagada' ? `<div class="cx-grid" style="margin-top:8px">
              <div class="cli-f"><label class="adm-label">Fecha</label><input class="adm-input" type="date" id="pv-date" value="${cliToday()}"></div>
              <div class="cli-f"><label class="adm-label">Medio</label><select class="adm-select" id="pv-method">${CLI_PAY.map(m => `<option>${m}</option>`).join('')}</select></div></div>
            <button class="btn btn-primary btn-sm" id="pv-full" style="width:100%;justify-content:center;margin-top:8px"><i class="fa-solid fa-check"></i> Marcar como pagada (${cliMoney(cur.balance, cur.currency)})</button>
            <details class="cx-partial"><summary>Registrar un pago parcial</summary>
              <div style="display:flex;gap:6px;margin-top:8px"><input class="adm-input" id="pv-amount" inputmode="decimal" placeholder="Monto"><input class="adm-input" id="pv-note" placeholder="Nota (opcional)">
              <button class="btn btn-ghost btn-sm" id="pv-part">Registrar</button></div></details>` : ''}
        </div>` : ''}
      </aside>
      <div class="cx-prev"><div class="cx-prev-h"><span><i class="fa-regular fa-eye"></i> Así la ve el cliente</span><span id="iv-prev-st"></span></div><iframe id="iv-prev" sandbox="allow-same-origin" title="Vista previa de la factura"></iframe></div>
    </div>`;

  const box = $id('cli-inv'), val = () => { const v = { id: cur ? +cur.id : 0 }; box.querySelectorAll('[data-v]').forEach(el => v[el.dataset.v] = el.value); return v; };
  const leave = () => !CLI.invDirty || confirm('Hay cambios sin guardar en esta factura. ¿Descartarlos?');
  box.querySelectorAll('[data-v]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => { CLI.invDirty = true; cliInvPreview(val()); }));
  $id('iv-file').onchange = () => { CLI.invDirty = true; };
  box.querySelectorAll('[data-tab-inv]').forEach(b => b.onclick = () => { if (+b.dataset.tabInv === CLI.inv || !leave()) return; CLI.inv = +b.dataset.tabInv; cliRenderInvoices(); });
  $id('iv-new').onclick = () => { if (!leave()) return; CLI.inv = 0; cliRenderInvoices(); $id('iv-subtotal_expr').focus(); };
  $id('iv-next').onclick = async () => {
    if (!leave()) return;
    const r = await cliApi('inv_next', { client_id: c.id });
    admToast(`Ciclo renovado: se generó la factura ${r.number} con fecha de hoy`); CLI.inv = r.id; cliReload('facturas');
  };
  $id('iv-bill').onclick = () => cliOpenBilling();
  $id('iv-close').onclick = async () => {
    if (!confirm(closed ? `¿Reabrir el contrato de ${c.name}? Vuelve a quedar activo.` : `¿Finalizar el contrato de ${c.name}? En su dashboard va a ver el mensaje de cierre y su historial.`)) return;
    await cliApi('contract', { client_id: c.id, close: !closed });
    CLI.cur.client.status = closed ? 'activo' : 'terminado'; CLI.cur.client.closed_date = closed ? null : cliToday();
    admToast(closed ? 'Contrato reabierto' : 'Contrato finalizado'); cliReload('facturas');
  };
  $id('iv-save').onclick = async () => {
    const v = val(), fd = new FormData(); fd.append('action', 'inv_save'); fd.append('client_id', c.id);
    Object.entries(v).forEach(([k, x]) => fd.append(k, x));
    const f = $id('iv-file').files[0]; if (f) fd.append('file', f);
    if (!v.subtotal_expr.trim()) { admToast('Pon el monto base de la factura', true); $id('iv-subtotal_expr').focus(); return; }
    $id('iv-save').disabled = true;
    let r; try { r = await (await fetch(CLI_API, { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (e) { r = { error: 'No se pudo guardar' }; }
    $id('iv-save').disabled = false;
    if (r.error) { admToast(r.error, true); if (!r.id) return; }
    else admToast(cur ? 'Factura guardada' : 'Factura creada');
    CLI.invDirty = false; if (r.id) CLI.inv = +r.id; cliReload('facturas');
  };
  if (cur) {
    $id('iv-del').onclick = async () => { if (!confirm(`¿Borrar la factura ${cur.number || ''} y sus pagos? El cliente deja de verla.`)) return; await cliApi('inv_delete', { id: cur.id }); CLI.inv = null; cliReload('facturas'); };
    box.querySelectorAll('[data-paydel]').forEach(b => b.onclick = async () => { if (!confirm('¿Borrar este pago?')) return; await cliApi('pay_delete', { id: +b.dataset.paydel }); cliReload('facturas'); });
    const full = $id('pv-full');
    if (full) {
      full.onclick = async () => { await cliApi('inv_paid', { id: cur.id, pay_date: $id('pv-date').value, method: $id('pv-method').value }); admToast('Factura marcada como pagada'); cliReload('facturas'); };
      $id('pv-part').onclick = async () => {
        const amount = cliNum($id('pv-amount').value); if (!(parseFloat(amount) > 0)) { admToast('Pon el monto del pago', true); return; }
        await cliApi('pay_save', { invoice_id: cur.id, amount, pay_date: $id('pv-date').value, method: $id('pv-method').value, note: $id('pv-note').value });
        admToast('Pago registrado'); cliReload('facturas');
      };
    }
  }
  const fr = $id('iv-prev');
  fr.onload = () => { try { fr.style.height = (fr.contentDocument.documentElement.scrollHeight + 4) + 'px'; } catch (e) {} };
  cliInvPreview(val(), true);
}
// La vista previa la arma el servidor con el mismo código que ve el cliente (así no hay dos versiones de la factura)
function cliInvPreview(v, now) {
  clearTimeout(cliPrevT);
  cliPrevT = setTimeout(async () => {
    let r; try { r = await cliApi('inv_preview', { client_id: CLI.cur.client.id, inv: v }); } catch (e) { return; }
    const fr = $id('iv-prev'); if (!fr) return;
    fr.srcdoc = r.html;
    const calc = $id('iv-calc');
    if (calc) calc.innerHTML = r.valid ? `Subtotal <b>${cliMoney(r.subtotal, v.currency)}</b>${r.total < r.subtotal ? ` · total con descuento <b>${cliMoney(r.total, v.currency)}</b>` : ''}`
      : (v.subtotal_expr.trim() ? '<span style="color:#f87171">El monto base no es una cuenta válida</span>' : '');
  }, now ? 0 : 280);
}
const cliInvSt = s => `<span class="cli-st ${s === 'pagada' ? 'activo' : s === 'vencida' ? 'vencida' : s === 'parcial' ? 'pausado' : 'terminado'}">${esc(CLI_INV_ST[s] || s)}</span>`;

// ── Datos de facturación (emisor, medios de pago, textos) ─────
async function cliOpenBilling() {
  const B = JSON.parse(JSON.stringify((await cliApi('billing_get')).billing));
  let dlg = $id('cli-bill-dlg');
  if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'cli-bill-dlg'; dlg.className = 'cx-dlg'; document.body.append(dlg); dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); }); }
  const f = (k, label, ph = '', type = 'text') => `<div class="cli-f"><label class="adm-label">${label}</label><input class="adm-input" data-b="${k}" type="${type}" value="${esc(B[k] ?? '')}" placeholder="${esc(ph)}"></div>`;
  const t = (k, label, rows = 4) => `<div class="cli-f wide"><label class="adm-label">${label}</label><textarea class="adm-input" data-b="${k}" rows="${rows}">${esc(B[k] ?? '')}</textarea></div>`;
  const methods = () => B.methods.map((m, mi) => `<div class="cx-meth" data-mi="${mi}">
      <div style="display:flex;gap:6px"><input class="adm-input" data-mt value="${esc(m.title)}" placeholder="Ej.: Pago vía PayPal"><button class="btn btn-danger btn-sm" data-mdel title="Quitar este medio"><i class="fa-solid fa-trash"></i></button></div>
      ${m.items.map((it, ii) => `<div class="cx-mitem" data-ii="${ii}"><input class="adm-input" data-il value="${esc(it.label)}" placeholder="Etiqueta (ej.: Alias)"><input class="adm-input" data-iv value="${esc(it.value)}" placeholder="Dato que se copia"><button class="btn btn-ghost btn-sm" data-idel title="Quitar"><i class="fa-solid fa-xmark"></i></button></div>`).join('')}
      <button class="btn btn-ghost btn-sm" data-iadd><i class="fa-solid fa-plus"></i> Agregar dato</button></div>`).join('');
  const draw = () => {
    dlg.innerHTML = `<div class="cx-dlg-in">
      <div class="cx-dlg-h"><div><div class="adm-title" style="margin:0">Datos de facturación</div><div class="adm-sub" style="margin:0">Se usan en todas las facturas y en el dashboard de los clientes.</div></div><button type="button" class="btn btn-ghost btn-sm" data-close><i class="fa-solid fa-xmark"></i></button></div>
      <div class="cx-dlg-b">
        <h4>Quién factura</h4>
        <div class="adm-form-grid">${f('issuer', 'Nombre', 'Nombre de tu agencia')}${f('tagline', 'Bajada', 'Qué hace tu agencia, en una línea')}
          <div class="cli-f wide"><label class="adm-label">Logo (dirección de la imagen)</label><div style="display:flex;gap:10px;align-items:center"><input class="adm-input" data-b="logo" value="${esc(B.logo)}" placeholder="https://…">${B.logo ? `<img src="${esc(B.logo)}" alt="" style="height:34px;max-width:90px;object-fit:contain" referrerpolicy="no-referrer">` : ''}</div></div></div>
        <h4>Valores para facturas nuevas</h4>
        <div class="adm-form-grid">${f('prefix', 'Prefijo de la referencia', 'FAC-')}
          <div class="cli-f"><label class="adm-label">Moneda</label><select class="adm-select" data-b="currency">${['USD', 'ARS', 'EUR'].map(x => `<option${B.currency === x ? ' selected' : ''}>${x}</option>`).join('')}</select></div>
          ${f('period', 'Período', '1 Mes')}${t('description', 'Descripción del servicio por defecto')}</div>
        <h4>Medios de pago <small>(el cliente los ve con un botón para copiar)</small></h4>
        <div id="cx-meths">${methods()}</div>
        <button class="btn btn-ghost btn-sm" id="cx-madd"${B.methods.length >= 6 ? ' disabled' : ''}><i class="fa-solid fa-plus"></i> Agregar medio de pago</button>
        <h4>Pie de la factura</h4>
        <div class="adm-form-grid">${f('footer_text', 'Texto', 'Para mayor información visita:')}${f('footer_url', 'Link', 'https://')}</div>
        <h4>Mensaje de contrato finalizado</h4>
        <div class="adm-form-grid">${f('closing_title', 'Título')}${f('signature', 'Firma')}${t('closing_msg', 'Mensaje', 5)}</div>
      </div>
      <div class="cx-dlg-f"><button type="button" class="btn btn-ghost" data-close>Cancelar</button><button class="btn btn-primary" type="button" id="cx-bsave"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
    </div>`;
    dlg.querySelectorAll('[data-close]').forEach(b => b.onclick = () => dlg.close());
    dlg.querySelectorAll('button:not([type])').forEach(b => b.type = 'button');
    dlg.querySelectorAll('[data-b]').forEach(el => el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', () => B[el.dataset.b] = el.value));
    dlg.querySelectorAll('.cx-meth').forEach(mEl => {
      const m = B.methods[+mEl.dataset.mi];
      mEl.querySelector('[data-mt]').oninput = e => m.title = e.target.value;
      mEl.querySelector('[data-mdel]').onclick = e => { e.preventDefault(); B.methods.splice(+mEl.dataset.mi, 1); draw(); };
      mEl.querySelector('[data-iadd]').onclick = e => { e.preventDefault(); if (m.items.length < 5) { m.items.push({ label: '', value: '' }); draw(); } };
      mEl.querySelectorAll('.cx-mitem').forEach(iEl => {
        const it = m.items[+iEl.dataset.ii];
        iEl.querySelector('[data-il]').oninput = e => it.label = e.target.value;
        iEl.querySelector('[data-iv]').oninput = e => it.value = e.target.value;
        iEl.querySelector('[data-idel]').onclick = e => { e.preventDefault(); m.items.splice(+iEl.dataset.ii, 1); draw(); };
      });
    });
    $id('cx-madd').onclick = e => { e.preventDefault(); B.methods.push({ title: '', items: [{ label: '', value: '' }] }); draw(); };
    $id('cx-bsave').onclick = async () => {
      const r = await cliApi('billing_save', { billing: B });
      admToast('Datos de facturación guardados'); dlg.close();
      if (CLI.cur) { CLI.cur.billing = r.billing; if (CLI.tab === 'facturas' && $id('iv-prev')) cliInvPreview({ id: CLI.inv, ...Object.fromEntries([...$id('cli-inv').querySelectorAll('[data-v]')].map(el => [el.dataset.v, el.value])) }, true); }
    };
  };
  draw(); dlg.showModal();
}

// ── Acceso al dashboard del cliente ───────────────────────────
function cliPass() { const a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; const r = crypto.getRandomValues(new Uint32Array(12)); return [...r].map(n => a[n % a.length]).join(''); }
function cliRenderPortal() {
  const P = CLI.cur.portal || {}, c = CLI.cur.client, url = location.origin + (CLI.cur.portal_path || '/');
  $id('cli-portal').innerHTML = cliCard('fa-user-lock', 'Acceso al dashboard', `
    <p style="font-size:12.5px;color:var(--g7-text-3);margin-bottom:14px;line-height:1.55">El cliente entra en <a href="${esc(url)}" target="_blank" style="color:var(--g7-accent)">${esc(url.replace(/^https?:\/\//, ''))}</a> con este email y contraseña, y ve solo sus <b>contenidos</b> y sus <b>facturas</b>.
      ${P.last ? `Último ingreso: ${esc(new Date(P.last.replace(' ', 'T')).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' }))}.` : P.has_pass ? 'Todavía no entró nunca.' : ''}</p>
    <label class="cli-chk" style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;cursor:pointer"><input type="checkbox" id="pt-on"${P.on ? ' checked' : ''} style="accent-color:var(--g7-accent)"> Acceso activo</label>
    <div class="adm-form-grid">
      <div class="cli-f"><label class="adm-label">Email de acceso</label><input class="adm-input" id="pt-email" type="email" value="${esc(P.email || c.email || '')}"></div>
      <div class="cli-f"><label class="adm-label">${P.has_pass ? 'Nueva contraseña (vacío = no cambiar)' : 'Contraseña'}</label><div style="display:flex;gap:6px"><input class="adm-input" id="pt-pass" type="text" autocomplete="new-password" placeholder="${P.has_pass ? '••••••••' : 'Mínimo 8 caracteres'}"><button class="btn btn-ghost btn-sm" id="pt-gen" type="button" title="Generar una"><i class="fa-solid fa-wand-magic-sparkles"></i></button></div></div>
    </div>
    <div style="display:flex;justify-content:flex-end"><button class="btn btn-primary" id="pt-save"><i class="fa-solid fa-floppy-disk"></i> Guardar acceso</button></div>
    <div id="pt-share" style="display:none;margin-top:14px"></div>`) + '<div id="cli-pdesign"></div>';
  cliRenderDesign(c.id);
  $id('pt-gen').onclick = () => { $id('pt-pass').value = cliPass(); };
  $id('pt-save').onclick = async () => {
    const email = $id('pt-email').value.trim(), password = $id('pt-pass').value, on = $id('pt-on').checked;
    await cliApi('access_save', { client_id: c.id, email, password, on });
    admToast('Acceso guardado');
    const d = await cliApi('get', { id: c.id }); CLI.cur.portal = d.portal; cliRenderPortal();
    if (password && on) {   // los datos para mandarle (la contraseña no se puede volver a ver)
      const txt = `Hola! Ya puedes ver tus contenidos y facturas en ${url}\nEmail: ${email}\nContraseña: ${password}`;
      const box = $id('pt-share'); box.style.display = '';
      box.innerHTML = `<div class="cli-hint" style="text-align:left"><b>Datos para mandarle</b> (la contraseña no se vuelve a mostrar):<pre style="white-space:pre-wrap;margin:8px 0;font:12.5px var(--g7-font);color:var(--g7-text)">${esc(txt)}</pre><button class="btn btn-ghost btn-sm" id="pt-copy"><i class="fa-solid fa-copy"></i> Copiar</button></div>`;
      $id('pt-copy').onclick = () => navigator.clipboard.writeText(txt).then(() => admToast('Copiado'));
    }
  };
}

// ── Diseño del portal (constructor: portal/editor/, toolz/PORTAL_BUILDER.md) ──
async function cliRenderDesign(cid) {
  cid = +cid;
  const box = $id('cli-pdesign'); if (!box) return;
  let s;
  try {
    const r = await fetch('/toolz/portal/editor/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-PB': '1' }, credentials: 'same-origin', body: JSON.stringify({ action: 'client_state', client_id: cid }) });
    s = await r.json(); if (!r.ok || s.error) throw new Error(s.error || 'Error');
  } catch (e) { box.innerHTML = ''; return; }   // sin constructor (versión vieja o Lite): no se muestra nada
  if (+(CLI.cur.client || {}).id !== cid) return;
  const def = s.designs.find(d => d.id === s.default);
  const opt = d => `<option value="${d.id}"${d.id === s.assigned ? ' selected' : ''}>${esc(d.kind === 'client' ? 'Propio: ' + d.name : 'Plantilla: ' + d.name)}${d.live ? '' : ' (sin publicar)'}</option>`;
  const own = s.designs.find(d => d.kind === 'client' && d.client_id === cid);
  const eff = s.effective;
  box.innerHTML = cliCard('fa-wand-magic-sparkles', 'Diseño del portal', `
    <p style="font-size:12.5px;color:var(--g7-text-3);margin-bottom:12px;line-height:1.55">Hoy ve: <b style="color:var(--g7-text)">${eff ? esc(eff.name) : 'el portal de siempre'}</b>. Puedes darle una plantilla o armarle un diseño propio con bloques.</p>
    <div class="cli-f"><label class="adm-label" for="pd-sel">Diseño que ve</label>
      <select class="adm-input" id="pd-sel"><option value="0"${!s.assigned ? ' selected' : ''}>${def ? 'El predeterminado (' + esc(def.name) + ')' : 'El de siempre (sin diseño)'}</option>${s.designs.map(opt).join('')}</select></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:12px">
      ${eff ? `<a class="btn btn-ghost" href="/toolz/portal/editor/preview.php?d=${eff.id}&c=${cid}&live=1" target="_blank" rel="noopener"><i class="fa-regular fa-eye"></i> Ver como el cliente</a>` : ''}
      ${own ? `<a class="btn btn-primary" href="/toolz/portal/editor/?d=${own.id}"><i class="fa-solid fa-pen-ruler"></i> Editar su diseño</a>` : `<a class="btn btn-primary" href="/toolz/portal/editor/?cliente=${cid}&nuevo=1"><i class="fa-solid fa-plus"></i> Crear diseño propio</a>`}
    </div>`);
  $id('pd-sel').onchange = async e => {
    try {
      const r = await fetch('/toolz/portal/editor/api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-PB': '1' }, credentials: 'same-origin', body: JSON.stringify({ action: 'assign', client_id: cid, design_id: +e.target.value }) });
      const d = await r.json(); if (!r.ok || d.error) throw new Error(d.error || 'Error');
      admToast('Listo: el cliente ya ve ese diseño' + (s.designs.find(x => x.id === +e.target.value && !x.live) ? ' (cuando lo publiques)' : ''));
      cliRenderDesign(cid);
    } catch (x) { admToast(x.message, true); }
  };
}

SECTION_LOADERS.clientes = cliLoad;
window.addEventListener('beforeunload', e => { if (CLI.dirty || CLI.invDirty) { e.preventDefault(); e.returnValue = ''; } });

// ── Eventos que antes eran código en línea (CSP estricta) ──
function cliSetType(k) { CLI.type = k; cliRenderList(); }
function cliSetFilter(k) { CLI.filter = k; cliRenderList(); }
