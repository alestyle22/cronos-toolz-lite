/* assets/stock-page.js — Script de stock.php (Stock), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #sk-cfg y #sk-items. */
const SK_URL = '/toolz/stock.php';
const SK_CFG = JSON.parse(document.getElementById('sk-cfg').textContent);   // datos de PHP (stock.php)
const ITEMS = JSON.parse(document.getElementById('sk-items').textContent);
const SK_TOTAL    = SK_CFG.total;
const SK_PER_PAGE = 15;
let   skPage      = 1; // next page to load (0 was server-side)
let   skLoading   = false;
let   skHasMore   = SK_CFG.hasMore;
const ME = SK_CFG.me;
let currentLbItem = null;
let editCurrentId = null;
let uploadTags = [], editTags = [];
let uploadQueue = [];

function $(id){return document.getElementById(id);}
function fmtSz(b){if(b>=1073741824)return(b/1073741824).toFixed(1)+' GB';if(b>=1048576)return(b/1048576).toFixed(1)+' MB';if(b>=1024)return Math.round(b/1024)+' KB';return b+' B';}

// ── SEARCH ────────────────────────────────────────────────
let searchTimer;
$('sk-search-input').addEventListener('input', function(){
  clearTimeout(searchTimer);
  searchTimer = setTimeout(()=>{
    const q=this.value.trim();
    const url=new URL(location.href);
    if(q)url.searchParams.set('q',q);else url.searchParams.delete('q');
    location.href=url.toString();
  },600);
});

function applySort(){
  const url=new URL(location.href);
  url.searchParams.set('sort',$('sk-sort').value);
  location.href=url.toString();
}

// ── UPLOAD ────────────────────────────────────────────────
function openUpload(){
  // Auto-select current category and folder
  if (SK_CAT_ID) {
    const catSel = $('up-cat');
    if (catSel) catSel.value = SK_CAT_ID;
  }
  if (SK_FOLDER_ID) {
    const fi = $('up-folder-id');
    if (fi) fi.value = SK_FOLDER_ID;
    const fd = $('up-folder-display');
    if (fd) fd.textContent = '📁 ' + (SK_CFG.folderName);
    const fg = $('up-folder-group');
    if (fg) fg.style.display = '';
  }
  $('sk-upload-modal').classList.add('open');
}
function closeUpload(){$('sk-upload-modal').classList.remove('open');uploadQueue=[];renderQueue();}
function dropFiles(e){e.preventDefault();$('sk-drop').classList.remove('drag');filesChosen(e.dataTransfer.files);}
function filesChosen(files){
  const allowed=['image/','video/','image/gif'];
  Array.from(files).forEach(f=>{
    if(!allowed.some(t=>f.type.startsWith(t)))return;
    uploadQueue.push({file:f,status:'pending',id:Date.now()+Math.random()});
  });
  renderQueue();
  $('sk-upload-go-btn').disabled=uploadQueue.length===0;
}
function renderQueue(){
  const q=$('sk-queue');q.innerHTML='';
  uploadQueue.forEach(item=>{
    const type=item.file.type.startsWith('video')?'fa-film':item.file.type==='image/gif'?'fa-circle-play':'fa-image';
    const statusColor=item.status==='done'?'#22c55e':item.status==='error'?'#ef4444':'#52525b';
    const statusText=item.status==='done'?'✓':item.status==='error'?'✗':item.status==='uploading'?'…':'';
    const div=document.createElement('div');div.className='sk-qitem';
    div.innerHTML=`<i class="fa-solid ${type}"></i><span class="sk-qitem-name">${item.file.name}</span><span class="sk-qitem-size">${fmtSz(item.file.size)}</span><span class="sk-qitem-status" style="color:${statusColor}">${statusText}</span>`;
    q.appendChild(div);
  });
}
async function uploadAll(){
  const btn=$('sk-upload-go-btn');btn.disabled=true;
  const catId=$('up-cat').value;
  const tags=uploadTags.join(',');
  const pb=$('sk-progress-bar');pb.style.display='block';
  let done=0;
  for(let i=0;i<uploadQueue.length;i++){
    const item=uploadQueue[i];
    if(item.status==='done')continue;
    item.status='uploading';renderQueue();
    const fd=new FormData();fd.append('action','upload');fd.append('file',item.file);
    if(catId)fd.append('category_id',catId);fd.append('tags',tags);const foldId=$('up-folder-id')?.value;if(foldId)fd.append('folder_id',foldId);
    const title=item.file.name.replace(/\.[^.]+$/,'');fd.append('title',title);
    try{
      const r=await fetch(SK_URL,{method:'POST',body:fd});const d=await r.json();
      item.status=d.status==='success'?'done':'error';
    }catch(e){item.status='error';}
    done++;$('sk-progress-fill').style.width=Math.round(done/uploadQueue.length*100)+'%';
    renderQueue();
  }
  btn.disabled=false;
  if(uploadQueue.every(i=>i.status==='done')){setTimeout(()=>{closeUpload();location.reload();},600);}
}

// ── TAG INPUT ─────────────────────────────────────────────
function tagKeydown(e){
  if(e.key===','||e.key==='Enter'){e.preventDefault();addUploadTag($('up-tag-input').value);}
}
function addUploadTag(val){
  const t=val.trim().toLowerCase().replace(/,/g,'');if(!t||uploadTags.includes(t))return;
  uploadTags.push(t);$('up-tag-input').value='';renderUploadTags();
}
function renderUploadTags(){
  const wrap=$('up-tag-wrap');
  wrap.querySelectorAll('.sk-tag-pill').forEach(el=>el.remove());
  uploadTags.forEach((t,i)=>{
    const pill=document.createElement('div');pill.className='sk-tag-pill';
    pill.innerHTML=`${t}<button data-on-click="skUpTagDel" data-click-1="j:${i}">✕</button>`;
    wrap.insertBefore(pill,$('up-tag-input'));
  });
}
function editTagKeydown(e){
  if(e.key===','||e.key==='Enter'){e.preventDefault();addEditTag($('edit-tag-input').value);}
}
function addEditTag(val){
  const t=val.trim().toLowerCase().replace(/,/g,'');if(!t||editTags.includes(t))return;
  editTags.push(t);$('edit-tag-input').value='';renderEditTags();
}
function renderEditTags(){
  const wrap=$('edit-tag-wrap');
  wrap.querySelectorAll('.sk-tag-pill').forEach(el=>el.remove());
  editTags.forEach((t,i)=>{
    const pill=document.createElement('div');pill.className='sk-tag-pill';
    pill.innerHTML=`${t}<button data-on-click="skEditTagDel" data-click-1="j:${i}">✕</button>`;
    wrap.insertBefore(pill,$('edit-tag-input'));
  });
}

// ── CATEGORY ─────────────────────────────────────────────
function openNewCat(){$('new-cat-name').value='';$('sk-cat-modal').classList.add('open');setTimeout(()=>$('new-cat-name').focus(),50);}
async function createCategory(){
  const name=$('new-cat-name').value.trim();if(!name)return;
  const r=await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'create_category',name})});
  const d=await r.json();
  if(d.status==='success'){$('sk-cat-modal').classList.remove('open');location.reload();}
  else alert(d.error||'Error');
}

// ── LIGHTBOX ─────────────────────────────────────────────
function openLightbox(id){
  currentLbItem=ITEMS.find(i=>i.id==id);
  if(!currentLbItem)return;
  const i=currentLbItem;
  // Increment view
  fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'download',id})});
  // Render media
  const media=$('lb-media');media.innerHTML='';
  if(i.file_type==='video'){const v=document.createElement('video');v.src=i.file_url;v.controls=true;v.autoplay=true;v.style.cssText='max-width:92vw;max-height:80vh;border-radius:10px';media.appendChild(v);}
  else{const img=document.createElement('img');img.src=i.file_url;img.alt=i.title;media.appendChild(img);}
  $('lb-title').textContent=i.title;
  $('lb-meta-size').innerHTML=`<i class="fa-solid fa-weight-hanging"></i> ${fmtSz(i.file_size)}`;
  $('lb-meta-dims').innerHTML=i.width?`<i class="fa-solid fa-expand"></i> ${i.width}×${i.height}`:'';
  $('lb-meta-by').innerHTML=`<i class="fa-solid fa-user"></i> ${i.display_name||i.username}`;
  $('lb-meta-cat').innerHTML=i.cat_name?`<i class="fa-solid fa-folder"></i> ${i.cat_name}`:'';
  const tagsEl=$('lb-tags');tagsEl.innerHTML='';
  if(i.tags)i.tags.split(',').forEach(t=>{const a=document.createElement('a');a.className='sk-lb-tag';a.href=`stock.php?tag=${encodeURIComponent(t)}`;a.textContent='#'+t;tagsEl.appendChild(a);});
  // Fav button
  const favBtn=$('lb-fav-btn');
  favBtn.innerHTML=i.is_fav?'<i class="fa-solid fa-heart" style="color:#ef4444"></i> Favorito':'<i class="fa-regular fa-heart"></i> Favorito';
  $('sk-lightbox').classList.add('open');
}
function closeLightbox(){$('sk-lightbox').classList.remove('open');$('lb-media').innerHTML='';}
function lbDownload(){if(!currentLbItem)return;downloadItem(currentLbItem.id,currentLbItem.file_url,currentLbItem.title);}
function lbCopyUrl(){if(!currentLbItem)return;copyUrl(currentLbItem.file_url);}
async function lbToggleFav(){if(!currentLbItem)return;await toggleFav(currentLbItem.id,null);const btn=$('lb-fav-btn');currentLbItem.is_fav=!currentLbItem.is_fav;btn.innerHTML=currentLbItem.is_fav?'<i class="fa-solid fa-heart" style="color:#ef4444"></i> Favorito':'<i class="fa-regular fa-heart"></i> Favorito';}

// ── ACTIONS ──────────────────────────────────────────────
async function toggleFav(id,btn){
  const r=await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_fav',id})});
  const d=await r.json();
  if(btn){btn.classList.toggle('active',d.fav);btn.innerHTML=d.fav?'<i class="fa-solid fa-heart"></i>':'<i class="fa-regular fa-heart"></i>';}
  const item=ITEMS.find(i=>i.id==id);if(item)item.is_fav=d.fav?1:0;
}
async function downloadItem(id,url,title){
  await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'download',id})});
  const a=document.createElement('a');a.href=url;a.download=title||'archivo';a.click();
}
function copyUrl(url){
  navigator.clipboard.writeText(location.origin+url).then(()=>{showToast('URL copiada');}).catch(()=>{prompt('URL:',location.origin+url);});
}
function showToast(msg){const t=$('sk-toast');t.textContent=msg;t.style.transform='translateX(-50%) translateY(0)';setTimeout(()=>t.style.transform='translateX(-50%) translateY(60px)',2000);}

// ── EDIT ──────────────────────────────────────────────────
function openEdit(item){
  if(typeof item==='string')item=JSON.parse(item);
  editCurrentId=item.id;
  $('edit-title').value=item.title||'';
  $('edit-desc').value=item.description||'';
  $('edit-cat').value=item.category_id||'';
  editTags=item.tags?item.tags.split(',').map(t=>t.trim()).filter(Boolean):[];
  renderEditTags();
  $('sk-edit-modal').classList.add('open');
}
function closeEdit(){$('sk-edit-modal').classList.remove('open');editCurrentId=null;}
async function submitEdit(){
  const r=await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_item',id:editCurrentId,title:$('edit-title').value.trim(),description:$('edit-desc').value.trim(),category_id:$('edit-cat').value,tags:editTags.join(',')})});
  const d=await r.json();
  if(d.status==='success'){closeEdit();location.reload();}else alert(d.error||'Error');
}
async function confirmDelete(){
  if(!confirm('¿Eliminar este archivo permanentemente?'))return;
  const r=await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_item',id:editCurrentId})});
  const d=await r.json();
  if(d.status==='success'){closeEdit();location.reload();}else alert(d.error||'Error');
}

// ── FOLDERS ──────────────────────────────────────────────
const SK_CAT_SLUG  = SK_CFG.catSlug;
const SK_CAT_ID    = SK_CFG.catId;
const SK_FOLDER_ID = SK_CFG.folderId;
const SK_IS_ADMIN  = SK_CFG.isAdmin;
const SK_ME        = SK_CFG.me;

// ── Antes código en línea (CSP estricta, SEC-24) ──
/** Escapa un texto para ponerlo en un atributo HTML. */
function skAttr(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function skNoSubmit(){ return false; }
function skLbBg(el, e){ if (e.target === el) closeLightbox(); }
function skDragOver(el, e){ e.preventDefault(); el.classList.add('drag'); }
function skFilesChosen(el){ filesChosen(el.files); }
function skFocus(id){ document.getElementById(id).focus(); }
function skCatClose(){ document.getElementById('sk-cat-modal').classList.remove('open'); }
function skUpTagDel(i){ uploadTags.splice(i, 1); renderUploadTags(); }
function skEditTagDel(i){ editTags.splice(i, 1); renderEditTags(); }
// dragleave no está entre los eventos de g7-on: se engancha directo
{ const d = document.getElementById('sk-drop'); if (d) d.addEventListener('dragleave', () => d.classList.remove('drag')); }

async function loadFolders() {
  const nav = $('sk-folders-nav');
  const grid = $('sk-folder-grid');
  if (!SK_CAT_ID && !nav) return;
  const payload = {action:'get_folders', category_id: SK_CAT_ID};
  if (SK_FOLDER_ID) payload.parent_id = SK_FOLDER_ID;
  try {
    const r = await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d = await r.json();
    const folders = d.folders || [];
    // Sidebar nav
    if (nav) {
      nav.innerHTML = '';
      folders.forEach(f => {
        const a = document.createElement('a');
        a.className = 'sk-nav-link';
        a.href = `stock.php?cat=${SK_CAT_SLUG}&folder_id=${f.id}`;
        a.innerHTML = `<span><i class="fa-solid fa-folder-open"></i> ${f.name}</span><span class="sk-cnt">${f.item_cnt}</span>`;
        nav.appendChild(a);
      });
    }
    // Main grid folders
    if (grid) {
      grid.innerHTML = '';
      // Back button if inside a folder
      if (SK_FOLDER_ID) {
        const back = document.createElement('div');
        back.className = 'sk-folder-card';
        const parentUrl = SK_CFG.parentUrl;
        back.onclick = () => location.href = parentUrl;
        back.innerHTML = `<div class="sk-folder-icon">⬅️</div><div><div class="sk-folder-name">Volver</div><div class="sk-folder-cnt">carpeta anterior</div></div>`;
        grid.appendChild(back);
      }
      folders.forEach(f => {
        const card = document.createElement('div');
        card.className = 'sk-folder-card';
        card.onclick = () => location.href = `stock.php?cat=${SK_CAT_SLUG}&folder_id=${f.id}`;
        card.innerHTML = `
          <div class="sk-folder-icon">📁</div>
          <div style="flex:1;min-width:0">
            <div class="sk-folder-name">${f.name}</div>
            <div class="sk-folder-cnt">${f.item_cnt} archivo${f.item_cnt!==1?'s':''} · ${f.sub_cnt} carpeta${f.sub_cnt!==1?'s':''}</div>
          </div>
          <div class="sk-folder-menu">
            <button class="sk-folder-menu-btn" data-on-click="renameFolder|@stop" data-click-1="j:${f.id}" data-click-2="s:${skAttr(f.name)}"><i class="fa-solid fa-pen"></i></button>
            <button class="sk-folder-menu-btn" data-on-click="deleteFolder|@stop" data-click-1="j:${f.id}" data-click-2="s:${skAttr(f.name)}"><i class="fa-solid fa-trash"></i></button>
          </div>`;
        grid.appendChild(card);
      });
    }
  } catch(e) {}
}

function openNewFolder() {
  const name = prompt('Nombre de la carpeta:');
  if (!name || !name.trim()) return;
  const payload = {action:'create_folder', name: name.trim(), category_id: SK_CAT_ID || ''};
  if (SK_FOLDER_ID) payload.parent_id = SK_FOLDER_ID;
  fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
    .then(r=>r.json()).then(d=>{ if(d.status==='success') loadFolders(); else alert(d.error||'Error'); });
}
async function renameFolder(id, current) {
  const name = prompt('Nuevo nombre:', current);
  if (!name || !name.trim() || name.trim() === current) return;
  const r = await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'rename_folder',id,name:name.trim()})});
  const d = await r.json();
  if (d.status==='success') loadFolders(); else alert(d.error||'Error');
}
async function deleteFolder(id, name) {
  if (!confirm(`¿Eliminar la carpeta "${name}"? Los archivos dentro quedarán sin carpeta.`)) return;
  const r = await fetch(SK_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_folder',id})});
  const d = await r.json();
  if (d.status==='success') loadFolders(); else alert(d.error||'Error');
}

// Add folder_id to upload form
function openNewCatPatched() {
  // Patch upload modal to show folder selector
  if (SK_FOLDER_ID) {
    const fd = document.getElementById('up-folder-id');
    if (fd) fd.value = SK_FOLDER_ID;
  }
  openUpload();
}

// Init folders on page load
if (SK_CAT_ID) loadFolders();

// ── LOAD MORE / INFINITE SCROLL ───────────────────────────
function skGetCurrentParams(){
  return {
    page:    skPage,
    q:       new URLSearchParams(location.search).get('q')||'',
    cat:     new URLSearchParams(location.search).get('cat')||'',
    tag:     new URLSearchParams(location.search).get('tag')||'',
    typ:     new URLSearchParams(location.search).get('typ')||'',
    fav:     new URLSearchParams(location.search).get('fav')||'',
    sort:    document.getElementById('sk-sort')?.value || '',
  };
}

async function skLoadMore(){
  if(skLoading || !skHasMore) return;
  skLoading = true;
  const btn     = document.getElementById('sk-load-more-btn');
  const spinner = document.getElementById('sk-load-more-spinner');
  if(btn) btn.style.display = 'none';
  if(spinner) spinner.style.display = 'block';

  try {
    const r = await fetch(SK_URL, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'paginate', ...skGetCurrentParams()})
    });
    const d = await r.json();
    if(!d.ok) throw new Error(d.error||'Error');

    // Append items to grid
    const grid = document.getElementById('sk-items-grid');
    if(grid && d.items.length){
      d.items.forEach(item => {
        const div = document.createElement('div');
        div.innerHTML = skRenderCard(item);
        const card = div.firstElementChild;
        grid.appendChild(card);
      });
      // Re-init video thumbs for new cards
      initVideoThumbs();
    }

    skPage++;
    skHasMore = d.has_more;

    // Update counter
    const shown = document.getElementById('sk-shown-count');
    if(shown) shown.textContent = parseInt(shown.textContent) + d.items.length;

    // Update button
    if(skHasMore){
      const remaining = d.total - (skPage * SK_PER_PAGE);
      const info = document.getElementById('sk-load-more-info');
      if(btn) btn.style.display = '';
      if(info) info.textContent = '('+Math.max(0,remaining)+' restantes)';
    } else {
      const wrap = document.getElementById('sk-load-more-wrap');
      if(wrap) wrap.style.display = 'none';
    }

  } catch(e){ console.error('Load more error:', e); }

  skLoading = false;
  if(spinner) spinner.style.display = 'none';
}

function skRenderCard(item){
  const isVideo = item.file_type==='video' || (item.mime_type||'').startsWith('video/');
  const isGif   = item.file_type==='gif'   || item.mime_type==='image/gif';
  const canEdit = IS_ADMIN || item.owner_id == SK_ME;
  const isFav   = item.is_fav ? 'active' : '';

  let media = '';
  if(isVideo){
    if(item.thumb_url){
      media = `<div class="sk-video-thumb" data-src="${item.file_url}">
        <img class="sk-vthumb-img" src="${item.thumb_url}" alt="${item.title}" loading="lazy">
        <div class="sk-play-overlay"><div class="sk-play-btn"><i class="fa-solid fa-play"></i></div></div>
      </div>`;
    } else {
      media = `<div class="sk-video-thumb" data-src="${item.file_url}">
        <video class="sk-vthumb-vid" preload="none" data-src="${item.file_url}"></video>
        <div class="sk-vthumb-placeholder"><i class="fa-solid fa-film" style="font-size:28px;color:rgba(var(--g7-ov),.2);margin-bottom:8px"></i><span style="font-size:10px;color:rgba(var(--g7-ov),.4);text-align:center;padding:0 8px">${item.title.substring(0,40)}</span></div>
        <div class="sk-play-overlay"><div class="sk-play-btn"><i class="fa-solid fa-play"></i></div></div>
      </div>`;
    }
  } else if(isGif){
    media = `<img src="${item.file_url}" alt="${item.title}" loading="lazy" style="max-height:220px">`;
  } else if(item.thumb_url){
    media = `<img src="${item.thumb_url}" alt="${item.title}" loading="lazy">`;
  } else {
    media = `<img src="${item.file_url}" alt="${item.title}" loading="lazy">`;
  }

  const typeBadge = isVideo ? 'video' : (isGif ? 'gif' : 'image');
  const editBtn = canEdit ? `<button class="sk-card-btn edit" data-on-click="openEdit|@stop" data-click-1="s:${skAttr(JSON.stringify(item))}"><i class="fa-solid fa-pen"></i></button>` : '';

  return `<div class="sk-card" data-on-click="openLightbox" data-click-1="j:${item.id}">
    ${media}
    <div class="sk-type-badge">${typeBadge}</div>
    <div class="sk-card-actions" data-on-click="@stop">
      <button class="sk-card-btn fav ${isFav}" data-on-click="toggleFav" data-click-1="j:${item.id}" data-click-2="this" title="Favorito"><i class="fa-solid fa-heart"></i></button>
      <button class="sk-card-btn dl" data-on-click="downloadItem" data-click-1="j:${item.id}" data-click-2="s:${item.file_url}" data-click-3="s:${item.title}" title="Descargar"><i class="fa-solid fa-download"></i></button>
    </div>
    <div class="sk-card-overlay">
      <div class="sk-card-title">${item.title}</div>
      <div class="sk-card-meta">
        ${item.cat_name ? `<span><i class="fa-solid fa-folder"></i> ${item.cat_name}</span>` : ''}
        <span><i class="fa-solid fa-download"></i> ${item.downloads}</span>
        <span>${fmtSz(item.file_size)}</span>
      </div>
    </div>
  </div>`;
}

// Infinite scroll with IntersectionObserver
const skSentinelObs = new IntersectionObserver((entries) => {
  if(entries[0].isIntersecting && skHasMore && !skLoading) skLoadMore();
}, {rootMargin: '300px'});
const skSentinel = document.getElementById('sk-sentinel');
if(skSentinel) skSentinelObs.observe(skSentinel);

// ── VIDEO THUMBNAILS ──────────────────────────────────────
function initVideoThumbs(){
  // For each video without a thumb_url, capture a frame automatically
  document.querySelectorAll('.sk-vthumb-vid:not([data-loaded])').forEach(vidEl => {
    vidEl.dataset.loaded = '1';
    vidEl.muted = true;
    vidEl.preload = 'metadata';
    vidEl.src = vidEl.dataset.src || vidEl.closest('.sk-video-thumb')?.dataset.src || '';
    if(!vidEl.src) return;

    const onFrame = () => {
      // Draw to canvas to get static thumbnail
      try {
        const canvas = document.createElement('canvas');
        canvas.width  = vidEl.videoWidth  || 320;
        canvas.height = vidEl.videoHeight || 180;
        canvas.getContext('2d').drawImage(vidEl, 0, 0, canvas.width, canvas.height);
        canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover';
        const thumb = vidEl.closest('.sk-video-thumb');
        if(thumb){
          // Insert canvas as static thumbnail
          thumb.insertBefore(canvas, thumb.firstChild);
          // Hide placeholder and video
          const ph = thumb.querySelector('.sk-vthumb-placeholder');
          if(ph) ph.style.display = 'none';
          vidEl.style.display = 'none';
        }
      } catch(e) {
        // Fallback: just show the video frame directly
        vidEl.style.display = 'block';
        const ph = vidEl.closest('.sk-video-thumb')?.querySelector('.sk-vthumb-placeholder');
        if(ph) ph.style.display = 'none';
      }
    };

    vidEl.addEventListener('seeked', onFrame, {once:true});
    vidEl.addEventListener('loadeddata', () => {
      vidEl.currentTime = 0.5;
    }, {once:true});
    // Fallback: if seeked doesn't fire, try after a delay
    vidEl.addEventListener('loadedmetadata', () => {
      vidEl.currentTime = 0.5;
      setTimeout(() => {
        if(vidEl.style.display !== 'none') return; // already handled
        onFrame();
      }, 800);
    }, {once:true});

    vidEl.load();
  });

  // Hover: play preview
  document.querySelectorAll('.sk-video-thumb:not([data-hover-init])').forEach(container => {
    container.dataset.hoverInit = '1';
    const src = container.dataset.src;
    if(!src) return;
    let hoverVid = null;

    container.addEventListener('mouseenter', () => {
      if(!hoverVid){
        hoverVid = document.createElement('video');
        hoverVid.src = src;
        hoverVid.muted = true;
        hoverVid.loop = true;
        hoverVid.playsInline = true;
        hoverVid.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:3;display:block';
        container.insertBefore(hoverVid, container.querySelector('.sk-play-overlay'));
      }
      hoverVid.play().catch(()=>{});
    });

    container.addEventListener('mouseleave', () => {
      if(hoverVid){ hoverVid.pause(); hoverVid.currentTime = 0; }
    });
  });
}

window.addEventListener('load', initVideoThumbs);

// ── KEYBOARD SHORTCUTS ────────────────────────────────────
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){closeLightbox();closeEdit();closeUpload();$('sk-cat-modal').classList.remove('open');}
});
document.addEventListener('click',e=>{
  if(e.target.classList.contains('sk-modal'))e.target.classList.remove('open');
});
