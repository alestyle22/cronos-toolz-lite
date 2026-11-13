/* materiales.js — Materiales de clientes (stock.php): carpetas por cliente, subida en partes, vista previa, mover y borrar.
   Habla con materiales_api.php. Estado en la URL: ?c=ID&p=carpeta/sub (se puede compartir el link a una carpeta). */
'use strict';

const MT_API = '/toolz/materiales_api.php';
const MT = { clients: [], chunk: 1536 << 10, max: 300 << 20, admin: false, cid: 0, path: '', data: null, sel: new Set(), q: '', queue: [], uploading: false };
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const size = b => b >= 1073741824 ? (b / 1073741824).toFixed(1).replace('.', ',') + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const AV_COLORS = ['#ff6600', '#a78bfa', '#4ade80', '#60a5fa', '#f59e0b', '#ec4899'];
const ST = { potencial: 'Potencial', activo: 'Activo', pausado: 'Pausado', terminado: 'Terminado' };
const ago = d => {
  if (!d) return '';
  const s = (Date.now() - new Date(d.replace(' ', 'T'))) / 1000;
  if (s < 60) return 'recién'; if (s < 3600) return 'hace ' + Math.floor(s / 60) + ' min'; if (s < 86400) return 'hace ' + Math.floor(s / 3600) + ' h';
  if (s < 86400 * 30) return 'hace ' + Math.floor(s / 86400) + ' d';
  return new Date(d.replace(' ', 'T')).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric' });
};

async function api(action, data = {}) {
  let d;
  try {
    const r = await fetch(MT_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ ...data, action }) });
    d = await r.json();
  } catch (e) { d = { error: 'No se pudo conectar con el servidor' }; }
  if (d.error) { toast(d.error, true); throw new Error(d.error); }
  return d;
}
function toast(msg, err) {
  const t = document.createElement('div'); t.className = 'mt-toast' + (err ? ' err' : ''); t.textContent = msg;
  document.body.appendChild(t); setTimeout(() => t.remove(), err ? 4200 : 2400);
}
function avatar(c, cls = '') {
  if (c.avatar) return `<span class="mt-av ${cls}"><img src="${esc(c.avatar)}" alt=""></span>`;
  const i = (c.name || '?').trim()[0]?.toUpperCase() || '?';
  return `<span class="mt-av ${cls}" style="background:${AV_COLORS[i.charCodeAt(0) % 6]}">${esc(i)}</span>`;
}
const ext = n => (n.match(/\.([a-z0-9]+)$/i) || [])[1]?.toLowerCase() || '';
const isImg = f => /^image\/(png|jpeg|webp|gif|avif|svg\+xml)$/.test(f.mime);
const isFont = f => /^(ttf|otf|woff2?)$/.test(ext(f.name));
const fileUrl = (id, dl) => MT_API + '?f=' + id + (dl ? '&dl=1' : '');
function icon(f) {
  const e = ext(f.name);
  if (/pdf/.test(f.mime)) return 'fa-file-pdf';
  if (/^(zip|rar|7z)$/.test(e)) return 'fa-file-zipper';
  if (/^(ai|eps|psd|indd|idml|cdr|xd|fig|sketch|afdesign)$/.test(e)) return 'fa-pen-ruler';
  if (/video/.test(f.mime)) return 'fa-file-video';
  if (/audio/.test(f.mime)) return 'fa-file-audio';
  if (/^(doc|docx|odt|rtf|pages|txt)$/.test(e)) return 'fa-file-word';
  if (/^(xls|xlsx|csv|ods|numbers)$/.test(e)) return 'fa-file-excel';
  if (/^(ppt|pptx|key)$/.test(e)) return 'fa-file-powerpoint';
  if (/image/.test(f.mime)) return 'fa-file-image';
  return 'fa-file';
}

// ── Navegación ──────────────────────────────────────────────────
function go(cid, path = '', push = true) {
  MT.cid = +cid || 0; MT.path = path || ''; MT.sel.clear(); MT.q = ''; $('mt-q').value = '';
  if (push) {
    const u = new URL(location.href);
    u.searchParams.delete('c'); u.searchParams.delete('p');
    if (MT.cid) u.searchParams.set('c', MT.cid);
    if (MT.path) u.searchParams.set('p', MT.path);
    history.pushState({ c: MT.cid, p: MT.path }, '', u);
  }
  render();
}
window.addEventListener('popstate', () => { const u = new URL(location.href); go(u.searchParams.get('c') || 0, u.searchParams.get('p') || '', false); });

async function render() {
  updSel();
  if (MT.q.length >= 2) return renderSearch();
  if (!MT.cid) return renderClients();
  const view = $('mt-view');
  let d;
  try { d = await api('open', { client_id: MT.cid, path: MT.path }); }
  catch (e) { view.innerHTML = `<div class="mt-empty"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudo abrir esta carpeta</b>${esc(e.message)}<div style="margin-top:14px"><button class="mt-btn" data-on-click="go" data-click-1="n:0">Volver a Materiales</button></div></div>`; return; }
  MT.data = d;
  const segs = d.path ? d.path.split('/') : [];
  const crumbs = [`<button data-on-click="go" data-click-1="n:0"><i class="fa-solid fa-folder-tree"></i> Materiales</button>`,
    `<button ${segs.length ? `data-on-click="go" data-click-1="j:${MT.cid}"` : 'class="cur"'}>${avatar(d.client, 'sm')}${esc(d.client.name)}</button>`,
    ...segs.map((s, i) => { const p = segs.slice(0, i + 1).join('/'); return i === segs.length - 1 ? `<button class="cur">${esc(s)}</button>` : `<button data-on-click="go" data-click-1="j:${MT.cid}" data-click-2="s:${esc(p)}">${esc(s)}</button>`; })];
  const F = d.folders, X = d.files;
  view.innerHTML = `
    <div class="mt-bar">
      <nav class="mt-crumbs" aria-label="Ubicación">${crumbs.join('<i class="fa-solid fa-chevron-right sep"></i>')}</nav>
      <div class="mt-actions">
        <button class="mt-btn" data-on-click="mtNewFolder"><i class="fa-solid fa-folder-plus"></i> <span>Nueva carpeta</span></button>
        <button class="mt-btn" data-on-click="mtPickDir"><i class="fa-solid fa-folder-open"></i> <span>Subir carpeta</span></button>
        ${(F.length || X.length) ? `<a class="mt-btn" href="${MT_API}?zip=${MT.cid}&path=${encodeURIComponent(MT.path)}"><i class="fa-solid fa-file-zipper"></i> <span>Descargar todo</span></a>` : ''}
        <button class="mt-btn primary" data-on-click="mtPickFiles"><i class="fa-solid fa-cloud-arrow-up"></i> Subir archivos</button>
      </div>
    </div>
    ${F.length ? `<div class="mt-label">Carpetas</div><div class="mt-folders">${F.map(f => `
      <div class="mt-fold" tabindex="0" role="button" data-path="${esc(f.path)}">
        <i class="fa-solid fa-folder"></i>
        <div class="mt-fold-b"><div class="mt-fold-n" title="${esc(f.name)}">${esc(f.name)}</div><div class="mt-fold-m">${f.files ? `${f.files} archivo${f.files === 1 ? '' : 's'} · ${size(f.bytes)}` : 'Vacía'}</div></div>
        <button class="mt-ic" data-fmenu title="Opciones de la carpeta" aria-label="Opciones de ${esc(f.name)}"><i class="fa-solid fa-ellipsis-vertical"></i></button>
      </div>`).join('')}</div>` : ''}
    ${X.length ? `<div class="mt-label">Archivos</div><div class="mt-files${MT.sel.size ? ' mt-selmode' : ''}" id="mt-files">${X.map(fileCard).join('')}</div>`
      : `<div class="mt-empty" style="margin-top:${F.length ? 22 : 0}px"><i class="fa-solid fa-cloud-arrow-up"></i><b>${F.length ? 'No hay archivos sueltos en esta carpeta' : 'Esta carpeta está vacía'}</b>Arrastra archivos o una carpeta entera acá, o usa <b style="display:inline;font-size:inherit">Subir archivos</b>.</div>`}`;
  view.querySelectorAll('.mt-fold').forEach(el => {
    const open = () => go(MT.cid, el.dataset.path);
    el.addEventListener('click', e => { if (!e.target.closest('[data-fmenu]')) open(); });
    el.addEventListener('keydown', e => { if (e.key === 'Enter') open(); });
    el.querySelector('[data-fmenu]').onclick = e => { e.stopPropagation(); folderMenu(el.dataset.path); };
    // Soltar archivos seleccionados sobre una carpeta los mueve ahí
    el.addEventListener('dragover', e => { if (e.dataTransfer.types.includes('text/mt-ids')) { e.preventDefault(); el.classList.add('drop'); } });
    el.addEventListener('dragleave', () => el.classList.remove('drop'));
    el.addEventListener('drop', async e => {
      const ids = e.dataTransfer.getData('text/mt-ids'); el.classList.remove('drop'); if (!ids) return;
      e.preventDefault(); e.stopPropagation();
      await api('move', { client_id: MT.cid, ids: ids.split(',').map(Number), folder: el.dataset.path }); toast('Movido a ' + el.dataset.path.split('/').pop()); MT.sel.clear(); render();
    });
  });
  view.querySelectorAll('.mt-file').forEach(bindFile);
  loadFonts(X);
}

function fileCard(f) {
  const th = isImg(f) ? `<img src="${f.mime === 'image/svg+xml' ? fileUrl(f.id) : MT_API + '?th=' + f.id}" alt="" loading="lazy">`
    : isFont(f) ? `<span class="font" data-font="${f.id}">Aa Bb Cc<small>${esc(f.name.replace(/\.[^.]+$/, ''))}</small></span>`
    : `<i class="fa-solid ${icon(f)}"></i>`;
  const where = f.client_name ? `${esc(f.client_name)}${f.folder ? ' › ' + esc(f.folder) : ''}` : `${size(f.size)} · ${esc(ago(f.created_at))}${f.by_name ? ' · ' + esc(f.by_name) : ''}`;
  return `<div class="mt-file${MT.sel.has(f.id) ? ' sel' : ''}" data-id="${f.id}" draggable="${f.client_name ? 'false' : 'true'}">
    <button class="mt-th" data-prev title="Ver ${esc(f.name)}">${th}</button>
    <span class="mt-ext">${esc(ext(f.name) || 'archivo')}</span>
    ${f.client_name ? '' : `<button class="mt-chk" data-chk title="Seleccionar" aria-label="Seleccionar ${esc(f.name)}">${MT.sel.has(f.id) ? '<i class="fa-solid fa-check"></i>' : ''}</button>`}
    <div class="mt-fb">
      <div class="mt-fn" title="${esc(f.name)}">${esc(f.name)}</div>
      <div class="mt-fm" title="${f.client_name ? '' : esc(f.by_name || '')}">${where}</div>
      <div class="mt-fa">
        <a class="mt-ic" href="${fileUrl(f.id, 1)}" title="Descargar" aria-label="Descargar"><i class="fa-solid fa-download"></i></a>
        ${f.client_name ? `<button class="mt-ic" data-on-click="go" data-click-1="j:${f.client_id}" data-click-2="s:${esc(f.folder)}" title="Abrir la carpeta"><i class="fa-solid fa-folder-open"></i></button>` : `
        <button class="mt-ic" data-ren title="Renombrar" aria-label="Renombrar"><i class="fa-solid fa-pen"></i></button>
        <button class="mt-ic" data-mv title="Mover" aria-label="Mover"><i class="fa-solid fa-folder-tree"></i></button>
        ${f.can_delete ? '<button class="mt-ic danger" data-del title="Borrar" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button>' : ''}`}
      </div>
    </div></div>`;
}
function bindFile(el) {
  const id = +el.dataset.id, list = MT.q ? MT.found : MT.data.files, f = list.find(x => +x.id === id);
  el.querySelector('[data-prev]').onclick = () => preview(f, list);
  el.querySelector('[data-chk]')?.addEventListener('click', () => { MT.sel.has(id) ? MT.sel.delete(id) : MT.sel.add(id); el.classList.toggle('sel', MT.sel.has(id)); el.querySelector('[data-chk]').innerHTML = MT.sel.has(id) ? '<i class="fa-solid fa-check"></i>' : ''; $('mt-files')?.classList.toggle('mt-selmode', MT.sel.size > 0); updSel(); });
  el.querySelector('[data-ren]')?.addEventListener('click', () => renameFile(f));
  el.querySelector('[data-mv]')?.addEventListener('click', () => moveDialog([id]));
  el.querySelector('[data-del]')?.addEventListener('click', async () => { if (!confirm(`¿Borrar "${f.name}"? No se puede deshacer.`)) return; await api('delete_file', { ids: [id] }); toast('Archivo borrado'); render(); });
  el.addEventListener('dragstart', e => {
    const ids = MT.sel.has(id) ? [...MT.sel] : [id];
    e.dataTransfer.setData('text/mt-ids', ids.join(',')); e.dataTransfer.effectAllowed = 'move';
  });
}
// Las tipografías se muestran escritas con su propia letra
function loadFonts(files) {
  files.filter(isFont).forEach(f => {
    const fam = 'mtf' + f.id;
    new FontFace(fam, `url(${fileUrl(f.id)})`).load().then(ff => { document.fonts.add(ff); document.querySelectorAll(`[data-font="${f.id}"]`).forEach(el => el.style.fontFamily = `'${fam}', sans-serif`); }).catch(() => {});
  });
}

// ── Lista de clientes ───────────────────────────────────────────
async function loadClients() {
  const d = await api('clients');
  MT.clients = d.clients; MT.chunk = d.chunk; MT.max = d.max; MT.admin = d.admin;
}
function renderClients() {
  const C = MT.clients, view = $('mt-view');
  if (!C.length) { view.innerHTML = `<div class="mt-empty"><i class="fa-solid fa-address-book"></i><b>Todavía no hay clientes</b>Cuando cargues un cliente en <a href="/toolz/clientes.php">Clientes</a>, acá aparece su carpeta lista para subir materiales.</div>`; return; }
  view.innerHTML = `<div class="mt-clients">${C.map(c => `
    <button class="mt-card" data-on-click="go" data-click-1="j:${c.id}">
      <div class="mt-card-top">${avatar(c)}<div style="min-width:0"><div class="mt-card-name">${esc(c.name)}</div><div class="mt-card-meta">${c.files ? `${c.files} archivo${c.files === 1 ? '' : 's'} · ${size(c.bytes)}` : 'Sin archivos todavía'}</div></div></div>
      <div class="mt-card-foot"><span><i class="fa-solid fa-folder"></i> ${c.last_up ? 'Último: ' + esc(ago(c.last_up)) : 'Carpeta lista'}</span><span class="mt-st ${esc(c.status)}">${esc(ST[c.status] || c.status)}</span></div>
    </button>`).join('')}</div>`;
}

// ── Buscar ──────────────────────────────────────────────────────
let qTimer;
$('mt-q').addEventListener('input', e => { clearTimeout(qTimer); qTimer = setTimeout(() => { MT.q = e.target.value.trim(); MT.sel.clear(); render(); }, 250); });
async function renderSearch() {
  const d = await api('search', { q: MT.q }); MT.found = d.files;
  const view = $('mt-view');
  view.innerHTML = `<div class="mt-bar"><div class="mt-crumbs"><button data-on-click="mtClearSearch"><i class="fa-solid fa-arrow-left"></i> Volver</button><span style="color:var(--g7-text-3)">${d.files.length ? `${d.files.length} resultado${d.files.length === 1 ? '' : 's'} para “${esc(MT.q)}”` : ''}</span></div></div>
    ${d.files.length ? `<div class="mt-files">${d.files.map(fileCard).join('')}</div>` : `<div class="mt-empty"><i class="fa-solid fa-magnifying-glass"></i><b>No hay archivos con “${esc(MT.q)}”</b>Prueba con otra parte del nombre.</div>`}`;
  view.querySelectorAll('.mt-file').forEach(bindFile);
  loadFonts(d.files);
}

// ── Vista previa ────────────────────────────────────────────────
function preview(f, list) {
  const i = list.indexOf(f), m = $('mt-modal');
  const step = k => { const n = list[(i + k + list.length) % list.length]; if (n && n !== f) preview(n, list); };
  let body;
  if (isImg(f)) body = `<img src="${fileUrl(f.id)}" alt="${esc(f.name)}">`;
  else if (/pdf/.test(f.mime)) body = `<iframe src="${fileUrl(f.id)}" title="${esc(f.name)}"></iframe>`;
  else if (isFont(f)) body = `<div class="font-big" style="font-family:'mtf${f.id}',sans-serif"><div>ABCDEFGHIJKLMNÑOPQRSTUVWXYZ<br>abcdefghijklmnñopqrstuvwxyz<br>0123456789 ¿?¡!&@</div><p>El veloz murciélago hindú comía feliz cardillo y kiwi.</p></div>`;
  else body = `<div class="mt-empty" style="background:var(--g7-bg-3);min-width:min(420px,90vw)"><i class="fa-solid ${icon(f)}"></i><b>${esc(f.name)}</b>Este tipo de archivo no se puede ver acá: descárgalo para abrirlo.</div>`;
  m.innerHTML = `<div class="mt-prev">${body}<div class="mt-prev-bar">
      ${list.length > 1 ? '<button class="mt-btn" data-k="-1" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>' : ''}
      <span>${esc(f.name)} · ${size(f.size)}</span>
      <a class="mt-btn primary" href="${fileUrl(f.id, 1)}"><i class="fa-solid fa-download"></i> Descargar</a>
      ${list.length > 1 ? '<button class="mt-btn" data-k="1" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>' : ''}
      <button class="mt-btn" data-on-click="mtModalClose"><i class="fa-solid fa-xmark"></i> Cerrar</button></div></div>`;
  m.querySelectorAll('[data-k]').forEach(b => b.onclick = () => step(+b.dataset.k));
  m.onkeydown = null; document.onkeydown = e => { if (!m.classList.contains('on')) return; if (e.key === 'Escape') mtModalClose(); if (e.key === 'ArrowRight') step(1); if (e.key === 'ArrowLeft') step(-1); };
  m.classList.add('on');
}
/** Botones de subida y "Volver" de la búsqueda (antes código en línea; CSP estricta, SEC-24). */
function mtPickDir() { $('mt-in-dir').click(); }
function mtPickFiles() { $('mt-in-files').click(); }
function mtClearSearch() { $('mt-q').value = ''; MT.q = ''; render(); }
/** Cierra el modal al hacer clic en el fondo. */
function mtModalBg(el, e) { if (e.target === el) mtModalClose(); }
function mtModalClose() { $('mt-modal').classList.remove('on'); $('mt-modal').innerHTML = ''; }

// ── Diálogos ────────────────────────────────────────────────────
function dialog(title, fieldHtml, okText, onOk) {
  const m = $('mt-modal');
  m.innerHTML = `<form class="mt-dlg"><h3>${title}</h3>${fieldHtml}<div class="err"></div><div class="row"><button type="button" class="mt-btn" data-on-click="mtModalClose">Cancelar</button><button class="mt-btn primary">${okText}</button></div></form>`;
  const form = m.querySelector('form'), inp = form.querySelector('input,select');
  form.onsubmit = async e => {
    e.preventDefault(); const btn = form.querySelector('.primary'); btn.disabled = true;
    try { await onOk(inp.value.trim()); mtModalClose(); } catch (err) { form.querySelector('.err').textContent = err.message; btn.disabled = false; }
  };
  m.classList.add('on'); setTimeout(() => { inp.focus(); inp.select?.(); }, 30);
}
function mtNewFolder() {
  dialog('Nueva carpeta', '<input placeholder="Ej.: Logos 2027" maxlength="80">', 'Crear', async name => {
    if (!name) throw new Error('Pon un nombre');
    await api('mkdir', { client_id: MT.cid, path: MT.path, name }); toast('Carpeta creada'); render();
  });
}
function folderMenu(path) {
  const name = path.split('/').pop(), m = $('mt-modal');
  m.innerHTML = `<div class="mt-dlg"><h3><i class="fa-solid fa-folder" style="color:#fbbf24"></i> ${esc(name)}</h3>
    <button class="mt-btn" data-a="open"><i class="fa-solid fa-folder-open"></i> Abrir</button>
    <a class="mt-btn" href="${MT_API}?zip=${MT.cid}&path=${encodeURIComponent(path)}"><i class="fa-solid fa-file-zipper"></i> Descargar como .zip</a>
    <button class="mt-btn" data-a="ren"><i class="fa-solid fa-pen"></i> Cambiar nombre</button>
    <button class="mt-btn danger" data-a="del"><i class="fa-solid fa-trash"></i> Borrar carpeta y su contenido</button>
    <div class="row"><button class="mt-btn" data-on-click="mtModalClose">Cerrar</button></div></div>`;
  m.classList.add('on');
  m.querySelector('[data-a=open]').onclick = () => { mtModalClose(); go(MT.cid, path); };
  m.querySelector('[data-a=ren]').onclick = () => dialog('Cambiar nombre de la carpeta', `<input value="${esc(name)}" maxlength="80">`, 'Guardar', async v => {
    if (!v) throw new Error('Pon un nombre');
    await api('rename_folder', { client_id: MT.cid, path, name: v }); toast('Nombre cambiado'); render();
  });
  m.querySelector('[data-a=del]').onclick = async () => {
    const f = MT.data.folders.find(x => x.path === path);
    if (!confirm(f && f.files ? `"${name}" tiene ${f.files} archivo${f.files === 1 ? '' : 's'}. ¿Borrar la carpeta con todo lo que tiene? No se puede deshacer.` : `¿Borrar la carpeta "${name}"?`)) return;
    mtModalClose(); await api('delete_folder', { client_id: MT.cid, path }); toast('Carpeta borrada'); render();
  };
}
function renameFile(f) {
  dialog('Cambiar nombre', `<input value="${esc(f.name)}" maxlength="200">`, 'Guardar', async v => {
    if (!v) throw new Error('Pon un nombre');
    await api('rename_file', { id: f.id, name: v }); toast('Nombre cambiado'); render();
  });
}
function moveDialog(ids) {
  const all = [...new Set(MT.data.all_folders)].sort((a, b) => a.localeCompare(b, 'es'));
  const opts = ['', ...all].map(p => `<option value="${esc(p)}"${p === MT.path ? ' disabled' : ''}>${p ? esc(p.replace(/\//g, ' › ')) : '(carpeta principal de ' + esc(MT.data.client.name) + ')'}</option>`).join('');
  dialog(`Mover ${ids.length === 1 ? 'archivo' : ids.length + ' archivos'} a…`, `<select>${opts}</select>`, 'Mover', async to => {
    await api('move', { client_id: MT.cid, ids, folder: to }); MT.sel.clear(); toast('Movido'); render();
  });
  const s = $('mt-modal').querySelector('select'); const first = [...s.options].find(o => !o.disabled); if (first) s.value = first.value;
}
function updSel() {
  const n = MT.sel.size; $('mt-selbar').classList.toggle('on', n > 0);
  $('mt-seln').textContent = n === 1 ? '1 archivo' : n + ' archivos';
}
function mtClearSel() { MT.sel.clear(); render(); }
function mtMoveSel() { moveDialog([...MT.sel]); }
async function mtDeleteSel() {
  if (!confirm(`¿Borrar ${MT.sel.size === 1 ? 'el archivo seleccionado' : 'los ' + MT.sel.size + ' archivos seleccionados'}? No se puede deshacer.`)) return;
  const d = await api('delete_file', { ids: [...MT.sel] });
  toast(d.denied ? `Se borraron ${d.deleted}. ${d.denied} no, porque los subió otra persona.` : 'Borrado', !!d.denied);
  MT.sel.clear(); render();
}

// ── Subida (en partes, uno por vez) ─────────────────────────────
function addUploads(list) {
  // list: [{file, rel}] — rel = subcarpeta relativa (cuando se sube una carpeta entera)
  if (!MT.cid) { toast('Primero entra a la carpeta de un cliente', true); return; }
  const base = MT.path;
  list = list.filter(x => !/^\./.test(x.file.name) && !/(^|\/)(__MACOSX|\.DS_Store)(\/|$)/.test(x.rel));   // basura del sistema
  if (!list.length) return;
  list.forEach(x => MT.queue.push({ file: x.file, cid: MT.cid, folder: [base, x.rel].filter(Boolean).join('/'), state: 'wait', pct: 0, msg: '' }));
  $('mt-up').classList.add('on'); drawUploads(); runUploads();
}
function drawUploads() {
  const Q = MT.queue, done = Q.filter(x => x.state === 'ok').length, bad = Q.filter(x => x.state === 'err').length;
  $('mt-up-t').textContent = MT.uploading ? `Subiendo ${done + bad + 1} de ${Q.length}` : bad ? `${done} subido${done === 1 ? '' : 's'} · ${bad} con error` : `${done} archivo${done === 1 ? '' : 's'} subido${done === 1 ? '' : 's'}`;
  $('mt-up-l').innerHTML = Q.map(x => `<div class="mt-up-i"><span class="n" title="${esc(x.folder ? x.folder + '/' : '')}${esc(x.file.name)}">${esc(x.file.name)}</span>
    <span class="s ${x.state}">${x.state === 'ok' ? '<i class="fa-solid fa-check"></i>' : x.state === 'err' ? esc(x.msg) : x.state === 'up' ? x.pct + '%' : size(x.file.size)}</span>
    ${x.state === 'up' || x.state === 'wait' ? `<span class="bar"><span style="width:${x.pct}%"></span></span>` : ''}</div>`).join('');
}
function mtUpClose() { if (MT.uploading && !confirm('Hay archivos subiéndose. ¿Cancelar lo que falta?')) return; MT.queue.forEach(x => { if (x.state === 'wait') { x.state = 'err'; x.msg = 'Cancelado'; } }); MT.queue = MT.queue.filter(x => x.state === 'up'); $('mt-up').classList.remove('on'); }
const token = () => [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
async function sendChunk(fd, tries = 3) {
  for (let t = 1; ; t++) {
    try {
      const r = await fetch(MT_API, { method: 'POST', body: fd, credentials: 'same-origin' });
      const d = await r.json().catch(() => ({ error: 'El servidor rechazó la parte (código ' + r.status + ')' }));
      if (d.error) throw new Error(d.error);
      return;
    } catch (e) { if (t >= tries) throw e; await new Promise(r => setTimeout(r, 800 * t)); }
  }
}
async function runUploads() {
  if (MT.uploading) return; MT.uploading = true;
  let changed = false;
  for (const x of MT.queue) {
    if (x.state !== 'wait') continue;
    x.state = 'up'; drawUploads();
    const f = x.file, tok = token(), n = Math.max(1, Math.ceil(f.size / MT.chunk));
    try {
      if (f.size > MT.max) throw new Error('Pesa más de ' + size(MT.max));
      for (let i = 0; i < n; i++) {
        const fd = new FormData(); fd.append('action', 'up_chunk'); fd.append('token', tok); fd.append('index', i);
        fd.append('chunk', f.slice(i * MT.chunk, (i + 1) * MT.chunk), 'parte');
        await sendChunk(fd);
        x.pct = Math.round((i + 1) / n * 100); drawUploads();
      }
      await api('up_done', { token: tok, parts: n, size: f.size, name: f.name, client_id: x.cid, folder: x.folder });
      x.state = 'ok'; changed = true;
    } catch (e) {
      x.state = 'err'; x.msg = e.message || 'No se pudo subir';
      fetch(MT_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'up_abort', token: tok }) }).catch(() => {});
    }
    drawUploads();
  }
  MT.uploading = false; drawUploads();
  if (changed) { await loadClients().catch(() => {}); if (MT.cid && !MT.q) render(); }
  if (MT.queue.every(x => x.state === 'ok')) setTimeout(() => { if (!MT.uploading) { $('mt-up').classList.remove('on'); MT.queue = []; } }, 3500);
}
window.addEventListener('beforeunload', e => { if (MT.uploading) { e.preventDefault(); e.returnValue = ''; } });

$('mt-in-files').onchange = e => { addUploads([...e.target.files].map(file => ({ file, rel: '' }))); e.target.value = ''; };
$('mt-in-dir').onchange = e => {
  addUploads([...e.target.files].map(file => ({ file, rel: (file.webkitRelativePath || '').split('/').slice(0, -1).join('/') })));
  e.target.value = '';
};

// Arrastrar archivos o carpetas desde la computadora
async function walk(entry, rel, out) {
  if (entry.isFile) { await new Promise(ok => entry.file(file => { out.push({ file, rel }); ok(); }, ok)); return; }
  if (!entry.isDirectory) return;
  const sub = [rel, entry.name].filter(Boolean).join('/'), reader = entry.createReader();
  for (;;) {
    const batch = await new Promise(ok => reader.readEntries(ok, () => ok([])));
    if (!batch.length) break;
    for (const e of batch) await walk(e, sub, out);
  }
}
let dragDepth = 0;
const isFileDrag = e => [...(e.dataTransfer?.types || [])].includes('Files');
window.addEventListener('dragenter', e => {
  if (!isFileDrag(e)) return; e.preventDefault(); dragDepth++;
  if (!MT.cid) return;
  $('mt-drop-to').textContent = MT.data ? (MT.path ? MT.path.split('/').pop() : MT.data.client.name) : 'esta carpeta';
  $('mt-drop').classList.add('on');
});
window.addEventListener('dragover', e => { if (isFileDrag(e)) e.preventDefault(); });
window.addEventListener('dragleave', e => { if (!isFileDrag(e)) return; if (--dragDepth <= 0) { dragDepth = 0; $('mt-drop').classList.remove('on'); } });
window.addEventListener('drop', async e => {
  if (!isFileDrag(e)) return;
  e.preventDefault(); dragDepth = 0; $('mt-drop').classList.remove('on');
  if (!MT.cid) { toast('Primero entra a la carpeta de un cliente', true); return; }
  const items = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry?.()).filter(Boolean), out = [];
  if (items.length) { for (const en of items) await walk(en, '', out); }
  else [...e.dataTransfer.files].forEach(file => out.push({ file, rel: '' }));
  addUploads(out);
});

// ── Arranque ────────────────────────────────────────────────────
(async () => {
  try { await loadClients(); } catch (e) { $('mt-view').innerHTML = `<div class="mt-empty"><i class="fa-solid fa-triangle-exclamation"></i><b>No se pudieron cargar los clientes</b>${esc(e.message)}</div>`; return; }
  const u = new URL(location.href);
  go(u.searchParams.get('c') || 0, u.searchParams.get('p') || '', false);
})();
