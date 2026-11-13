/* assets/imghost-page.js — Script de imghost.php (ImageHost), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #ih-cfg, #ih-images e #ih-albums. */
const IH_URL = window.location.pathname;
const IH_CFG = JSON.parse(document.getElementById('ih-cfg').textContent);   // datos de PHP (imghost.php)
const IS_ADMIN = !!IH_CFG.isAdmin;
const ME_ID = IH_CFG.me;
let images = JSON.parse(document.getElementById('ih-images').textContent);
let view = 'grid';
let urlBarTimer = null;

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function fmtBytes(b){if(!b)return'0B';if(b<1024)return b+'B';if(b<1048576)return(b/1024).toFixed(1)+'KB';return(b/1048576).toFixed(1)+'MB';}
function fmtDate(d){if(!d)return'';const dt=new Date(d);return dt.toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'2-digit'})+' '+dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});}
function timeAgo(d){const s=Math.floor((Date.now()-new Date(d))/1000);if(s<60)return'ahora';if(s<3600)return Math.floor(s/60)+'m';if(s<86400)return Math.floor(s/3600)+'h';return Math.floor(s/86400)+'d';}

// ── DRAG & DROP ────────────────────────────────────────────
function ev(e, type) {
  e.preventDefault();
  const drop = document.getElementById('drop');
  if (type === 'over')  drop.classList.add('over');
  if (type === 'leave') drop.classList.remove('over');
  if (type === 'drop')  { drop.classList.remove('over'); uploadFiles([...e.dataTransfer.files]); }
}
document.getElementById('file-in').addEventListener('change', e => uploadFiles([...e.target.files]));

// ── UPLOAD ─────────────────────────────────────────────────
async function uploadFiles(files) {
  const imgFiles = files.filter(f => f.type.startsWith('image/'));
  document.getElementById('file-in').value = '';
  if (!imgFiles.length) return;

  const queue = document.getElementById('upload-queue');

  // Lote: con la opción prendida y más de una imagen, primero se crea el link y cada subida se suma a él
  let album = null;
  const done = [];
  if (document.getElementById('batch-on').checked && imgFiles.length > 1) {
    try {
      const r = await fetch(IH_URL, {method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'album_create', title:document.getElementById('batch-title').value})});
      const d = await r.json();
      if (d.ok) album = d;
      else showToast('⚠ No se pudo crear el lote: se suben sueltas');
    } catch(e) { showToast('⚠ No se pudo crear el lote: se suben sueltas'); }
  }
  if (album) renderBatchRes(album, done, imgFiles.length, false);

  for (const file of imgFiles) {
    const qid = 'q-' + Date.now() + Math.random().toString(36).slice(2);
    const objUrl = URL.createObjectURL(file);

    // Add queue item
    const item = document.createElement('div');
    item.className = 'ih-q-item'; item.id = qid;
    item.innerHTML = `
      <img class="ih-q-thumb" src="${objUrl}" data-on-error="ihHide" data-error-1="this">
      <div style="flex:1;min-width:0">
        <div class="ih-q-name">${esc(file.name)}</div>
        <div class="ih-q-bar"><div class="ih-q-bar-inner" id="bar-${qid}"></div></div>
      </div>
      <span class="ih-q-status uploading" id="st-${qid}">Subiendo...</span>`;
    queue.insertBefore(item, queue.firstChild);

    try {
      // Simulate progress
      const barEl = document.getElementById(`bar-${qid}`);
      let prog = 0;
      const progTimer = setInterval(() => { prog = Math.min(prog+15,85); if(barEl) barEl.style.width=prog+'%'; }, 100);

      const fd = new FormData();
      fd.append('action', 'upload');
      fd.append('file', file);
      if (album) fd.append('album', album.code);

      const r = await fetch(IH_URL, {method:'POST', body:fd});
      const d = await r.json();

      clearInterval(progTimer);
      if (barEl) barEl.style.width = '100%';

      if (d.ok) {
        const stEl = document.getElementById(`st-${qid}`);
        if (stEl) { stEl.textContent = '✓ Listo'; stEl.className = 'ih-q-status ok'; }

        // Add to images array and re-render
        images.unshift({id:d.id,filename:d.filename,original_name:d.original,mime_type:d.mime,file_size:d.size,width:d.width,height:d.height,url:d.url,created_at:new Date().toISOString(),display_name:'Tú',user_id:ME_ID});
        renderGallery();
        updateStats();

        // Suelta: barra con su URL. En lote: se suma al panel del lote
        if (album) { done.push(d.url); renderBatchRes(album, done, imgFiles.length, false); }
        else showUrlBar(d.url);

        // Auto-remove queue item
        setTimeout(() => { item.style.opacity='0'; item.style.transition='opacity .4s'; setTimeout(()=>item.remove(), 400); }, 3000);
      } else {
        const stEl = document.getElementById(`st-${qid}`);
        if (stEl) { stEl.textContent = '⚠ ' + (d.error||'Error'); stEl.className = 'ih-q-status err'; }
      }
    } catch(e) {
      const stEl = document.getElementById(`st-${qid}`);
      if (stEl) { stEl.textContent = '⚠ Error de red'; stEl.className = 'ih-q-status err'; }
    }

    URL.revokeObjectURL(objUrl);
  }

  if (album) {
    renderBatchRes(album, done, imgFiles.length, true);
    if (done.length) {
      albums.unshift({code:album.code,title:album.title,user_id:ME_ID,created_at:new Date().toISOString(),display_name:'Tú',n:done.length,cover:done[0]});
      renderAlbums();
    }
    document.getElementById('batch-title').value = '';
  }
}

// ── LOTES ─────────────────────────────────────────────────
const ALBUM_BASE = IH_CFG.albumBase;
let albums = JSON.parse(document.getElementById('ih-albums').textContent);
const albumLink = code => ALBUM_BASE + code;
let batchUrls = [];

function renderBatchRes(album, urls, total, finished) {
  const box = document.getElementById('batch-res'), link = albumLink(album.code);
  const fails = finished ? total - urls.length : 0;
  batchUrls = urls;
  box.hidden = false;
  box.innerHTML = `
    <h3><i class="fa-solid fa-layer-group"></i> ${esc(album.title)}</h3>
    <p>${finished ? `${urls.length} de ${total} imágenes subidas${fails ? ` · ${fails} con error (no están en el link)` : ''}` : `Subiendo… ${urls.length} de ${total}`}. Este link muestra todas las del lote.</p>
    <div class="ih-batch-link">
      <code data-on-click="copyUrl" data-click-1="s:${esc(link)}" title="Click para copiar">${esc(link)}</code>
      <button class="ih-btn pri" data-on-click="copyUrl" data-click-1="s:${esc(link)}"><i class="fa-solid fa-link"></i> Copiar link</button>
      <a class="ih-btn" href="${esc(link)}" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir</a>
      <button class="ih-btn" data-on-click="ihCopyBatch" ${urls.length ? '' : 'disabled'}><i class="fa-solid fa-copy"></i> Copiar las ${urls.length} URLs</button>
      <button class="ih-btn" data-on-click="ihBatchClose" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>`;
}

function renderAlbums() {
  const box = document.getElementById('albums-box');
  box.hidden = !albums.length;
  document.getElementById('albums-count').textContent = albums.length;
  document.getElementById('albums-list').innerHTML = albums.map(a => {
    const link = albumLink(a.code), canDel = a.user_id == ME_ID || IS_ADMIN;
    return `<div class="ih-alb">
      ${a.cover ? `<img src="${esc(a.cover)}" alt="" loading="lazy">` : '<img alt="">'}
      <div class="ih-alb-info">
        <div class="ih-alb-t">${esc(a.title)}</div>
        <div class="ih-alb-m">${a.n} ${a.n == 1 ? 'imagen' : 'imágenes'} · ${esc(a.display_name || a.username || '')} · ${timeAgo(a.created_at)}</div>
      </div>
      <button class="ih-row-btn" data-on-click="copyUrl" data-click-1="s:${esc(link)}" title="Copiar link del lote"><i class="fa-solid fa-link"></i></button>
      <a class="ih-row-btn" href="${esc(link)}" target="_blank" title="Abrir" style="text-decoration:none"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
      ${canDel ? `<button class="ih-row-btn del" data-on-click="deleteAlbum" data-click-1="s:${esc(a.code)}" title="Borrar el link (las imágenes quedan)"><i class="fa-solid fa-trash"></i></button>` : ''}
    </div>`;
  }).join('');
}

async function deleteAlbum(code) {
  if (!confirm('¿Borrar este lote? El link deja de funcionar; las imágenes siguen en el ImageHost.')) return;
  const r = await fetch(IH_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'album_delete',code})});
  const d = await r.json();
  if (d.ok) { albums = albums.filter(a => a.code !== code); renderAlbums(); showToast('Lote borrado'); }
  else showToast('⚠ ' + (d.error || 'No se pudo borrar'));
}

// ── RENDER ─────────────────────────────────────────────────
function renderGallery(filter='') {
  const gallery = document.getElementById('gallery');
  const filtered = filter ? images.filter(i => i.original_name?.toLowerCase().includes(filter.toLowerCase()) || i.filename?.toLowerCase().includes(filter.toLowerCase())) : images;

  if (!filtered.length) {
    gallery.innerHTML = '<div class="ih-empty"><i class="fa-regular fa-image"></i><p style="font-size:13px">Sin imágenes</p></div>';
    return;
  }

  if (view === 'grid') {
    gallery.innerHTML = '<div class="ih-grid">' + filtered.map(img => buildCard(img)).join('') + '</div>';
  } else {
    gallery.innerHTML = '<div class="ih-list">' + filtered.map(img => buildRow(img)).join('') + '</div>';
  }
}

function buildCard(img) {
  const canDel = img.user_id == ME_ID || IS_ADMIN;
  const dims   = img.width && img.height ? `${img.width}×${img.height}` : '';
  return `<div class="ih-card" id="img-${img.id}">
    <div class="ih-card-img-wrap" data-on-click="openLb" data-click-1="j:${img.id}">
      <img src="${esc(img.url)}?v=${img.file_size||0}" alt="${esc(img.original_name)}" loading="lazy" data-on-error="ihImgBroken" data-error-1="this">
      <div class="ih-card-overlay">
        <button class="ih-ov-btn" data-on-click="openLb" data-click-1="j:${img.id}" title="Ver"><i class="fa-solid fa-expand"></i></button>
        <button class="ih-ov-btn copy" data-on-click="copyUrl" data-click-1="s:${esc(img.url)}" title="Copiar URL"><i class="fa-solid fa-copy"></i></button>
        ${canDel?`<button class="ih-ov-btn" data-on-click="triggerReplace" data-click-1="j:${img.id}" title="Reemplazar" style="background:rgba(249,115,22,.35);border-color:rgba(249,115,22,.5)"><i class="fa-solid fa-arrow-rotate-right"></i></button>`:''}
        ${canDel?`<button class="ih-ov-btn del" data-on-click="deleteImg" data-click-1="j:${img.id}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>`:''}
      </div>
    </div>
    <div class="ih-card-foot">
      <div class="ih-card-name">${esc(img.original_name||img.filename)}</div>
      <div class="ih-card-meta">
        <span class="ih-card-size">${fmtBytes(img.file_size)}</span>
        <span class="ih-card-dims">${dims}</span>
      </div>
    </div>
  </div>`;
}

function buildRow(img) {
  const canDel = img.user_id == ME_ID || IS_ADMIN;
  const dims   = img.width && img.height ? `${img.width}×${img.height} · ` : '';
  return `<div class="ih-row" id="img-${img.id}">
    <img class="ih-row-thumb" src="${esc(img.url)}?v=${img.file_size||0}" loading="lazy" data-on-error="ihNoSrc" data-error-1="this">
    <div class="ih-row-info">
      <div class="ih-row-name">${esc(img.original_name||img.filename)}</div>
      <div class="ih-row-meta">${dims}${fmtBytes(img.file_size)} · ${timeAgo(img.created_at)}</div>
    </div>
    <span class="ih-row-url" data-on-click="copyUrl" data-click-1="s:${esc(img.url)}" title="Click para copiar">${esc(img.url)}</span>
    <div class="ih-row-btns">
      <button class="ih-row-btn" data-on-click="openLb" data-click-1="j:${img.id}" title="Ver"><i class="fa-solid fa-expand"></i></button>
      <button class="ih-row-btn" data-on-click="copyUrl" data-click-1="s:${esc(img.url)}" title="Copiar URL"><i class="fa-solid fa-copy"></i></button>
      ${canDel?`<button class="ih-row-btn" data-on-click="triggerReplace" data-click-1="j:${img.id}" title="Reemplazar" style="color:var(--text2)"><i class="fa-solid fa-arrow-rotate-right"></i></button>`:''}
      <a href="${esc(img.url)}" download target="_blank" class="ih-row-btn" title="Descargar" style="display:flex;align-items:center;justify-content:center;text-decoration:none;color:var(--text2)"><i class="fa-solid fa-download"></i></a>
      ${canDel?`<button class="ih-row-btn del" data-on-click="deleteImg" data-click-1="j:${img.id}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>`:''}
    </div>
  </div>`;
}

// ── LIGHTBOX ───────────────────────────────────────────────
let currentLbId = null;
function openLb(id) {
  const img = images.find(i=>i.id==id);
  if (!img) return;
  currentLbId = id;
  document.getElementById('lb-img').src = img.url + '?t=' + Date.now();
  document.getElementById('lb-url').textContent = img.url;
  document.getElementById('lb-open').href = img.url;
  const canDel = img.user_id == ME_ID || IS_ADMIN;
  document.getElementById('lb-replace-btn').style.display = canDel ? '' : 'none';
  document.getElementById('lb').classList.add('open');
}
// ── Antes código en línea (CSP estricta, SEC-24). Los botones de la tarjeta no abren el visor: g7-on solo llama al más cercano. ──
function ihCopyFrom(id) { copyUrl(document.getElementById(id).textContent); }
function ihCopySelf(el) { copyUrl(el.textContent); }
function ihLbBg(el, e) { if (e.target === el) closeLb(); }
function ihReplaceCurrent() { triggerReplace(currentLbId); }
function ihCopyBatch() { copyUrl(batchUrls.join('\n')); }
function ihBatchClose() { document.getElementById('batch-res').hidden = true; }
function ihHide(el) { el.style.display = 'none'; }
function ihNoSrc(el) { el.src = ''; }
/** Miniatura rota: en su lugar va un ícono. */
function ihImgBroken(el) { el.parentElement.innerHTML = '<i class=\'fa-solid fa-image-slash\' style=\'font-size:24px;color:var(--border2)\'></i>'; }
function closeLb() { document.getElementById('lb').classList.remove('open'); currentLbId=null; }

// ── REPLACE ───────────────────────────────────────────────
let replaceTargetId = null;

function triggerReplace(id) {
  replaceTargetId = id;
  // Create hidden file input
  let inp = document.getElementById('replace-input');
  if (!inp) {
    inp = document.createElement('input');
    inp.type = 'file'; inp.id = 'replace-input';
    inp.accept = 'image/*'; inp.style.display = 'none';
    inp.addEventListener('change', handleReplace);
    document.body.appendChild(inp);
  }
  inp.value = '';
  inp.click();
}

async function handleReplace(e) {
  const file = e.target.files[0];
  if (!file || !replaceTargetId) return;

  showToast('Reemplazando imagen...');

  const fd = new FormData();
  fd.append('action', 'replace');
  fd.append('id', replaceTargetId);
  fd.append('file', file);

  try {
    const r = await fetch(IH_URL, {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      // Update local data
      const img = images.find(i=>i.id==d.id);
      if (img) {
        img.original_name = d.original;
        img.file_size     = d.size;
        img.width         = d.width;
        img.height        = d.height;
        img.mime_type     = d.mime;
      }
      renderGallery(document.getElementById('search')?.value||'');
      updateStats();
      // Update lightbox if open
      if (currentLbId == d.id) {
        document.getElementById('lb-img').src = d.url + '?t=' + Date.now();
      }
      showToast('✓ Imagen reemplazada — misma URL');
    } else {
      showToast('⚠ ' + (d.error||'Error al reemplazar'));
    }
  } catch(e) {
    showToast('⚠ Error de red');
  }
  replaceTargetId = null;
}

// ── URL BAR ────────────────────────────────────────────────
function showUrlBar(url) {
  clearTimeout(urlBarTimer);
  document.getElementById('url-text').textContent = url;
  document.getElementById('url-bar').classList.add('show');
  urlBarTimer = setTimeout(hideUrlBar, 8000);
}
function hideUrlBar() { document.getElementById('url-bar').classList.remove('show'); }

// ── COPY ──────────────────────────────────────────────────
async function copyUrl(url) {
  try {
    await navigator.clipboard.writeText(url);
    showToast('✓ URL copiada al portapapeles');
  } catch(e) {
    // Fallback
    const ta = document.createElement('textarea');
    ta.value = url; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove();
    showToast('✓ URL copiada');
  }
}

// ── DELETE ────────────────────────────────────────────────
async function deleteImg(id) {
  if (!confirm('¿Eliminar esta imagen? No se puede deshacer.')) return;
  const r = await fetch(IH_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})});
  const d = await r.json();
  if (d.ok) {
    images = images.filter(i=>i.id!==id);
    document.getElementById('img-'+id)?.remove();
    updateStats();
    showToast('Imagen eliminada');
  }
}

// ── FILTER & VIEW ─────────────────────────────────────────
function filterImages(q) { renderGallery(q); }
function setView(v) {
  view = v;
  document.getElementById('vb-grid').classList.toggle('on', v==='grid');
  document.getElementById('vb-list').classList.toggle('on', v==='list');
  renderGallery(document.getElementById('search')?.value||'');
}
function updateStats() {
  const cnt = document.getElementById('stat-count');
  const sz  = document.getElementById('stat-size');
  if (cnt) cnt.textContent = images.length + ' imágenes';
  const total = images.reduce((s,i)=>s+(+i.file_size||0),0);   // la base los manda como texto
  if (sz) sz.textContent = total > 1048576 ? (total/1048576).toFixed(1)+'MB' : (total/1024).toFixed(1)+'KB';
}

// ── TOAST ─────────────────────────────────────────────────
function showToast(msg) {
  let t = document.getElementById('ih-toast');
  if (!t) { t=document.createElement('div'); t.id='ih-toast'; t.style.cssText='position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:var(--bg2);border:1px solid var(--border2);color:var(--text);font-size:12px;font-weight:700;padding:9px 18px;border-radius:20px;z-index:9999;opacity:0;transition:all .3s;pointer-events:none;font-family:"DM Mono",monospace;border-left:3px solid var(--accent)'; document.body.appendChild(t); }
  t.textContent=msg; t.style.opacity='1';
  setTimeout(()=>t.style.opacity='0', 2500);
}

document.addEventListener('keydown', e => {
  if (e.key==='Escape') { closeLb(); hideUrlBar(); }
});

// ── INIT ──────────────────────────────────────────────────
renderGallery();
renderAlbums();
// dragleave no está entre los eventos de g7-on: se engancha directo en la zona de soltar
document.getElementById('drop').addEventListener('dragleave', e => ev(e, 'leave'));
