/* links/links.js — Biblioteca de enlaces (antes en línea en index.php; CSP estricta, SEC-24 fase 2). Los datos de PHP llegan en #links-data. */
const LINKS_BOOT=JSON.parse(document.getElementById('links-data').textContent);
const DATA=LINKS_BOOT.DATA;
const IS_ADMIN=LINKS_BOOT.IS_ADMIN;
let categories=DATA.categories;
let links=DATA.links;
let notes=DATA.notes||{};
let favorites=new Set((DATA.favorites||[]).map(Number));
let showFavOnly=false;
let activeTagFilter=null;
const ALL_TAGS=LINKS_BOOT.ALL_TAGS;
const $=id=>document.getElementById(id);

// ── SEARCH + FILTER ───────────────────────────────────────
function highlight(text, query) {
  if (!query) return escHtml(text);
  const escaped = escHtml(text);
  const lc = escaped.toLowerCase();
  const lq = query.toLowerCase();
  const idx = lc.indexOf(lq);
  if (idx === -1) return escaped;
  return escaped.slice(0,idx) + '<mark class="search-hl">' + escaped.slice(idx,idx+query.length) + '</mark>' + escaped.slice(idx+query.length);
}
function escHtml(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

function applyFilters() {
  const search  = $('searchInput').value.trim().toLowerCase();
  const catId   = $('filt-cat').value;
  let anyVisible = false, totalShown = 0;
  document.querySelectorAll('.category-section').forEach(sec => {
    if (catId && sec.dataset.catId !== catId) { sec.classList.add('hidden'); return; }
    let secVisible = false;
    sec.querySelectorAll('.link-card-wrap').forEach(wrap => {
      const tags   = JSON.parse(wrap.dataset.tags || '[]');
      const linkId = parseInt(wrap.dataset.linkId);
      const show = (!search || (wrap.dataset.search || '').includes(search)) && (!activeTagFilter || tags.includes(activeTagFilter)) && (!showFavOnly || favorites.has(linkId));
      wrap.classList.toggle('hidden', !show);
      if (show) { secVisible = true; anyVisible = true; totalShown++; }
      const nameEl = wrap.querySelector('.card-name');
      if (nameEl) { if (search) nameEl.innerHTML = highlight(wrap.dataset.nameRaw || '', search); else nameEl.textContent = wrap.dataset.nameRaw || ''; }
    });
    const filtering = !!(catId || search || activeTagFilter || showFavOnly);
    sec.classList.toggle('hidden', filtering ? !secVisible : (sec.dataset.empty === '1' && !IS_ADMIN));
  });
  const filtering = !!(search || activeTagFilter || catId || showFavOnly);
  $('no-results').classList.toggle('hidden', anyVisible || !filtering);
  $('results-count').textContent = filtering ? totalShown + ' resultado' + (totalShown !== 1 ? 's' : '') : '';
  $('lb-clear').style.display = filtering ? '' : 'none';
}

function toggleTagFilter(tag, btn) {
  if (activeTagFilter===tag) { activeTagFilter=null; document.querySelectorAll('.tag-pill').forEach(b=>b.classList.remove('active')); }
  else { activeTagFilter=tag; document.querySelectorAll('.tag-pill').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
  applyFilters();
}

function clearFilters() {
  $('searchInput').value='';
  $('filt-cat').value='';
  activeTagFilter=null; showFavOnly=false;
  $('fav-filter-btn').classList.remove('on');
  document.querySelectorAll('.tag-pill').forEach(b=>b.classList.remove('active'));
  applyFilters();
}

function toggleFavFilter() {
  showFavOnly=!showFavOnly;
  $('fav-filter-btn').classList.toggle('on', showFavOnly);
  applyFilters();
}

// ── FAVORITES ─────────────────────────────────────────────
function trackClick(id){
  if(!id) return;
  // Track the click in g7_link_clicks
  fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'click',link_id:id})});
  // Also log to accesos rápidos for activity feed
  const linkData = links.find(l=>l.id==id);
  const name = linkData?.name || 'Recurso';
  const url  = linkData?.url  || '';
  fetch('/toolz/index.php',{method:'POST',keepalive:true,
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=track_acceso&tool='+encodeURIComponent(url)+'&tool_name='+encodeURIComponent(name)+'&meta=biblioteca'
  });
}
fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'session_start'})});
setInterval(()=>fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat'})}),60000);

async function toggleFavorite(id,btn){
  const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_favorite',link_id:id})});
  const data=await res.json();
  if(data.status==='success'){
    const icon=btn.querySelector('i');
    if(data.favorited){favorites.add(id);icon.className='fa-solid fa-heart';btn.classList.add('on');btn.title='Quitar de favoritos';}
    else{favorites.delete(id);icon.className='fa-regular fa-heart';btn.classList.remove('on');btn.title='Agregar a favoritos';}
    updateFavCount();
    if(showFavOnly) applyFilters();
  }
}

// ── NOTES ──────────────────────────────────────────────────
function togglePubNote(id,header){const body=document.getElementById('pub-note-body-'+id);const icon=header.querySelector('.note-toggle-icon');const isOpen=body.classList.toggle('open');icon.style.transform=isOpen?'rotate(180deg)':'';}
async function savePubNote(id){const ta=document.getElementById('pub-note-ta-'+id);const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_public_note',link_id:id,note:ta.value})});const data=await res.json();if(data.status==='success'){const l=links.find(x=>x.id==id);if(l)l.public_note=ta.value;const saved=document.getElementById('pub-note-saved-'+id);saved.style.display='inline';setTimeout(()=>saved.style.display='none',2000);}}
function toggleNote(id,header){const body=document.getElementById('note-body-'+id);const icon=header.querySelector('.note-toggle-icon');const isOpen=body.classList.toggle('open');icon.style.transform=isOpen?'rotate(180deg)':'';}
function showNoteContent(id){document.getElementById('note-mask-'+id).style.display='none';document.getElementById('note-ta-'+id).style.display='block';}
function hideNoteContent(id){const ta=document.getElementById('note-ta-'+id);if(!ta.value.trim())return;ta.style.display='none';document.getElementById('note-mask-'+id).style.display='block';}
async function saveNote(id){const ta=document.getElementById('note-ta-'+id);const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_note',link_id:id,note:ta.value})});const data=await res.json();if(data.status==='success'){notes[id]=ta.value;const indicator=ta.closest('.note-area').querySelector('.note-indicator');indicator.classList.toggle('has-note',ta.value.trim()!=='');const saved=document.getElementById('note-saved-'+id);saved.style.display='inline';setTimeout(()=>saved.style.display='none',2000);}}

// ── RENDER ─────────────────────────────────────────────────
function domainOf(u){ try { return new URL(u).hostname.replace(/^www\./,''); } catch(e) { return ''; } }
function coverSrc(img){ if(!img) return ''; if(/^https?:\/\//.test(img) || img.startsWith('/toolz/')) return img; return img.startsWith('link_covers/') ? '/toolz/'+img : '/toolz/link_covers/'+img.split('/').pop(); }
function updateFavCount(){ const n=links.filter(l=>favorites.has(Number(l.id))).length; $('fav-count').textContent = n ? n : ''; }
function cardHtml(l){
  const id = Number(l.id);
  const tags = Array.isArray(l.tags) ? l.tags.filter(Boolean) : [];
  const dom = l.html_file ? 'Página HTML' : domainOf(l.url);
  const img = coverSrc(l.img);
  const coverCls = img ? '' : ' noimg';
  const isFav = favorites.has(id);
  const hasNote = (notes[id] || '').trim() !== '';
  const pub = (l.public_note || '').trim() || (l.pub_locked ? '🔒' : '');
  const search = [l.name, l.description, dom, tags.join(' ')].join(' ').toLowerCase();
  const tagHtml = tags.slice(0,2).map(t => `<span class="lb-tag">${escHtml(t.charAt(0)+t.slice(1).toLowerCase())}</span>`).join('') + (tags.length>2 ? `<span class="lb-tag">+${tags.length-2}</span>` : '');
  const open = `href="${escHtml(l.url)}" target="_blank" rel="noopener" data-on-click="trackClick" data-click-1="j:${id}"`;
  return `<div class="link-card-wrap" data-name-raw="${escHtml(l.name)}" data-search="${escHtml(search)}" data-tags='${escHtml(JSON.stringify(tags))}' data-link-id="${id}">
    <div class="lb-card">
      <a class="lb-cover${coverCls}" ${open} tabindex="-1" aria-hidden="true">${img ? `<img src="${escHtml(img)}" alt="" loading="lazy" data-on-load="lbImgLoad" data-load-1="this" data-on-error="lbImgError" data-error-1="this">` : ''}<span class="ph"><i class="fa-solid fa-link"></i></span></a>
      <button class="lb-fav-btn ${isFav?'on':''}" type="button" data-on-click="toggleFavorite" data-click-1="j:${id}" data-click-2="this" title="${isFav?'Quitar de favoritos':'Agregar a favoritos'}"><i class="${isFav?'fa-solid':'fa-regular'} fa-heart"></i></button>
      <a class="lb-body" ${open}>
        ${dom ? `<div class="lb-domain"><i class="fa-solid fa-arrow-up-right-from-square"></i>${escHtml(dom)}</div>` : ''}
        <div class="card-name">${escHtml(l.name)}</div>
        ${l.description ? `<div class="lb-desc">${escHtml(l.description)}</div>` : ''}
      </a>
      <div class="lb-foot">${tagHtml}<span class="sp"></span>
        <button class="lb-ic${pub?' has-pub':''}" type="button" data-on-click="openNotes" data-click-1="j:${id}" title="${pub?'Ver la nota del equipo':(hasNote?'Ver mi nota':'Agregar una nota')}"><i class="${pub||hasNote?'fa-solid':'fa-regular'} fa-note-sticky"></i>${pub||hasNote?'<span class="dot"></span>':''}</button>
        ${IS_ADMIN ? `<button class="lb-ic" type="button" data-on-click="openEditLink" data-click-1="j:${id}" title="Editar"><i class="fa-solid fa-pen"></i></button><button class="lb-ic del" type="button" data-on-click="deleteLink" data-click-1="j:${id}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>` : ''}
      </div>
    </div>
  </div>`;
}
function renderAll(){
  const container=$('categories-container'); container.innerHTML='';
  const total = links.length;
  $('lb-summary').textContent = total + ' recurso' + (total!==1?'s':'') + ' en ' + categories.filter(c=>links.some(l=>l.category_id==c.id)).length + ' categorías';
  updateFavCount();
  if(!categories.length){ container.innerHTML='<p class="no-results">Todavía no hay categorías.</p>'; return; }
  categories.forEach(cat=>{
    const catLinks = links.filter(l=>l.category_id==cat.id);
    const sec = document.createElement('section');
    sec.className = 'section category-section'; sec.dataset.catId = cat.id; sec.dataset.empty = catLinks.length ? '0' : '1';
    const adminBtns = IS_ADMIN ? `<div class="admin-section-actions"><button class="add-item-btn" data-on-click="openAddLink" data-click-1="j:${cat.id}"><i class="fa-solid fa-plus" style="margin-right:5px;font-size:9px"></i>Agregar</button><button class="del-cat-btn" title="Eliminar categoría" data-n="${escHtml(cat.name)}" data-on-click="lbDelCat" data-click-1="j:${cat.id}" data-click-2="this"><i class="fa-solid fa-trash"></i></button></div>` : '';
    const body = catLinks.length ? `<div class="cards-grid">${catLinks.map(cardHtml).join('')}</div>`
      : `<div class="lb-empty-cat">Esta categoría está vacía.${IS_ADMIN ? ` <button class="add-item-btn" data-on-click="openAddLink" data-click-1="j:${cat.id}">Agregar el primero</button>` : ''}</div>`;
    sec.innerHTML = `<div class="section-header"><h2 class="section-title">${escHtml(cat.name)}</h2><span class="section-count">${catLinks.length}</span>${adminBtns}<button class="toggle-section" data-on-click="toggleSec" data-click-1="this" title="Contraer"><i class="fa-solid fa-chevron-up"></i></button></div><div class="sec-body">${body}</div>`;
    container.appendChild(sec);
  });
  applyFilters();
}

// ── NOTAS (una sola ventana para la pública y la privada) ──
function openNotes(id){
  const l = links.find(x=>x.id==id); if(!l) return;
  $('nm-id').value = id; $('nm-title').textContent = l.name;
  $('nm-pub').value = l.public_note || ''; $('nm-priv').value = notes[id] || '';
  const locked = !!l.pub_locked;
  $('nm-pub').style.display = locked ? 'none' : '';
  $('nm-pub-locked').style.display = locked ? '' : 'none';
  $('nm-pub-group').style.display = (IS_ADMIN || locked || (l.public_note||'').trim()) ? '' : 'none';
  $('nm-pub').readOnly = !IS_ADMIN;
  $('notes-modal').classList.remove('hidden'); setTimeout(()=>$('nm-priv').focus(), 30);
}
function closeNotes(){ $('notes-modal').classList.add('hidden'); }
async function saveNotes(){
  const id = Number($('nm-id').value); const l = links.find(x=>x.id==id); if(!l) return;
  const btn = $('nm-save'); btn.disabled = true; btn.textContent = 'Guardando…';
  try {
    const post = body => fetch('', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}).then(r=>r.json());
    const priv = $('nm-priv').value;
    if (priv !== (notes[id]||'')) { await post({action:'save_note', link_id:id, note:priv}); notes[id] = priv; }
    if (IS_ADMIN && $('nm-pub').value !== (l.public_note||'')) { await post({action:'save_public_note', link_id:id, note:$('nm-pub').value}); l.public_note = $('nm-pub').value; }
    closeNotes(); renderAll();
  } catch(e) { alert('No se pudo guardar. Prueba de nuevo.'); }
  btn.disabled = false; btn.textContent = 'Guardar';
}

function toggleSec(btn){const body=btn.closest('.section').querySelector('.sec-body');body.classList.toggle('hidden');btn.querySelector('i').style.transform=body.classList.contains('hidden')?'rotate(180deg)':'';btn.title=body.classList.contains('hidden')?'Expandir':'Contraer';}

// ── EDIT LINK ──────────────────────────────────────────────
let editPreviewData={};
function openEditLink(id){clearHtml('el');const l=links.find(x=>x.id==id);if(!l)return;$('el-id').value=l.id;$('el-name').value=l.name;$('el-desc').value=l.description||'';$('el-url').value=l.url;$('el-cat').value=l.category_id;$('el-img-url').value='';$('el-img').value='';const thumb=$('el-img-thumb');if(l.img){thumb.src=l.img;thumb.style.display='block';$('el-img-none').style.display='none';}else{thumb.style.display='none';$('el-img-none').style.display='block';}const tags=Array.isArray(l.tags)?l.tags:[];document.querySelectorAll('.edit-tag-opt').forEach(b=>b.classList.toggle('selected',tags.includes(b.dataset.tag)));clearEditPreview();$('edit-link-modal').classList.remove('hidden');}
function closeEditLink(){$('edit-link-modal').classList.add('hidden');}
async function fetchEditPreview(){const url=$('el-url').value.trim();if(!url)return;$('edit-preview-loading').style.display='block';$('edit-preview-result').style.display='none';try{const res=await fetch('/toolz/fetch_preview.php?url='+encodeURIComponent(url));const data=await res.json();$('edit-preview-loading').style.display='none';if(data.error)return;editPreviewData=data;const imgSrc=data.og_image||data.favicon||'';if(imgSrc){$('edit-preview-thumb').src=imgSrc;$('edit-preview-url').textContent=imgSrc;$('edit-preview-result').style.display='flex';}$('edit-fav-btn').style.display=data.favicon?'':'none';}catch(e){$('edit-preview-loading').style.display='none';}}
function useEditOgImage(){if(!editPreviewData.og_image)return;$('el-img-url').value=editPreviewData.og_image;$('el-img-thumb').src=editPreviewData.og_image;$('el-img-thumb').style.display='block';$('el-img-none').style.display='none';$('el-img').value='';}
function useEditFavicon(){if(!editPreviewData.favicon)return;$('el-img-url').value=editPreviewData.favicon;$('el-img-thumb').src=editPreviewData.favicon;$('el-img-thumb').style.display='block';$('el-img-none').style.display='none';$('el-img').value='';}
function clearEditPreview(){editPreviewData={};$('edit-preview-result').style.display='none';$('edit-preview-loading').style.display='none';}
function previewEditFile(){const file=$('el-img').files[0];if(!file)return;const reader=new FileReader();reader.onload=e=>{$('el-img-thumb').src=e.target.result;$('el-img-thumb').style.display='block';$('el-img-none').style.display='none';$('el-img-url').value='';};reader.readAsDataURL(file);}
async function submitEditLink(){const name=$('el-name').value.trim();const html=$('el-html').files[0];const url=$('el-url').value.trim();if(!name||(!url&&!html)){alert('Pon un nombre y un enlace (o sube un HTML)');return;}const selectedTags=[...document.querySelectorAll('.edit-tag-opt.selected')].map(b=>b.dataset.tag).join(',');const fd=new FormData();fd.append('action','edit_link');fd.append('id',$('el-id').value);fd.append('category_id',$('el-cat').value);fd.append('name',name);fd.append('description',$('el-desc').value);fd.append('url',url);fd.append('tags',selectedTags);if(html)fd.append('html',html);const img=$('el-img').files[0];const imgUrl=$('el-img-url').value.trim();if(img)fd.append('img',img);else if(imgUrl)fd.append('img_url',imgUrl);const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>({error:'No se pudo guardar'}));if(data.error){alert(data.error);return;}if(data.status==='success'){const idx=links.findIndex(l=>l.id==data.link.id);if(idx>-1)links[idx]=data.link;closeEditLink();renderAll();}}

// ── ADD LINK ───────────────────────────────────────────────
let previewData={};
async function fetchPreview(){const url=$('ml-url').value.trim();if(!url){alert('Primero ingresa una URL');return;}$('preview-loading').style.display='block';$('preview-error').style.display='none';$('preview-result').style.display='none';try{const res=await fetch('/toolz/fetch_preview.php?url='+encodeURIComponent(url));const data=await res.json();$('preview-loading').style.display='none';if(data.error){$('preview-error').textContent=data.error;$('preview-error').style.display='block';return;}previewData=data;const imgSrc=data.og_image||data.favicon||'';if(imgSrc){$('preview-img-thumb').src=imgSrc;$('preview-img-url').textContent=imgSrc;$('preview-result').style.display='flex';}if(data.title&&!$('ml-name').value)$('ml-name').value=data.title;$('use-fav-btn').style.display=data.favicon?'':'none';}catch(e){$('preview-loading').style.display='none';$('preview-error').textContent='Error al obtener preview';$('preview-error').style.display='block';}}
function useOgImage(){if(!previewData.og_image)return;$('ml-img-url').value=previewData.og_image;$('img-selected-thumb').src=previewData.og_image;$('img-selected-preview').style.display='block';$('ml-img').value='';}
function useFavicon(){if(!previewData.favicon)return;$('ml-img-url').value=previewData.favicon;$('img-selected-thumb').src=previewData.favicon;$('img-selected-preview').style.display='block';$('ml-img').value='';}
function clearPreview(){previewData={};$('preview-result').style.display='none';$('preview-error').style.display='none';$('preview-loading').style.display='none';}
function previewSelectedFile(){const file=$('ml-img').files[0];if(!file)return;const reader=new FileReader();reader.onload=e=>{$('img-selected-thumb').src=e.target.result;$('img-selected-preview').style.display='block';$('ml-img-url').value='';};reader.readAsDataURL(file);}
function clearImgSelection(){$('ml-img-url').value='';$('ml-img').value='';$('img-selected-preview').style.display='none';}
function openAddLink(catId){clearHtml('ml');$('ml-cat-id').value=catId;['ml-name','ml-desc','ml-url'].forEach(id=>$(id).value='');$('ml-img').value='';$('ml-img-url').value='';$('img-selected-preview').style.display='none';clearPreview();document.querySelectorAll('.tag-opt').forEach(b=>b.classList.remove('selected'));$('add-link-modal').classList.remove('hidden');}
function closeAddLink(){$('add-link-modal').classList.add('hidden');}
function toggleTag(btn){btn.classList.toggle('selected');}
// HTML propio en lugar de un enlace
function pickHtml(p){const f=$(p+'-html').files[0];$(p+'-html-name').textContent=f?f.name:'o sube un archivo HTML';$(p+'-html-x').hidden=!f;$(p+'-html-box').classList.toggle('on',!!f);const u=$(p+'-url');u.disabled=!!f;u.placeholder=f?'Se completa solo con la página subida':'https://...';if(f&&!$(p+'-name').value.trim())$(p+'-name').value=f.name.replace(/\.html?$/i,'').replace(/[-_]+/g,' ');}
function clearHtml(p){$(p+'-html').value='';pickHtml(p);}
async function submitLink(){const name=$('ml-name').value.trim();const html=$('ml-html').files[0];const url=$('ml-url').value.trim();if(!name||(!url&&!html)){alert('Pon un nombre y un enlace (o sube un HTML)');return;}const selectedTags=[...document.querySelectorAll('.tag-opt.selected')].map(b=>b.dataset.tag).join(',');const fd=new FormData();fd.append('action','add_link');fd.append('category_id',$('ml-cat-id').value);fd.append('name',name);fd.append('description',$('ml-desc').value);fd.append('url',url);fd.append('tags',selectedTags);if(html)fd.append('html',html);const img=$('ml-img').files[0];const imgUrl=$('ml-img-url').value.trim();if(img)fd.append('img',img);else if(imgUrl)fd.append('img_url',imgUrl);const res=await fetch('',{method:'POST',body:fd});const data=await res.json().catch(()=>({error:'No se pudo guardar'}));if(data.error){alert(data.error);return;}if(data.status==='success'){links.unshift(data.link);closeAddLink();renderAll();}}

// ── ADMIN CATEGORIES ───────────────────────────────────────
async function addCategory(){const name=$('new-cat-input').value.trim();if(!name)return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_category',name})});const data=await res.json();if(data.status==='success'){categories.push({id:data.id,name:data.name,sort_order:categories.length});$('new-cat-input').value='';const opt=document.createElement('option');opt.value=data.id;opt.textContent=data.name;$('filt-cat').appendChild(opt);renderAll();}}
async function deleteCategory(id,name){if(!confirm(`¿Eliminar la categoría "${name}" y todos sus enlaces?`))return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_category',id})});if((await res.json()).status==='success'){categories=categories.filter(c=>c.id!=id);links=links.filter(l=>l.category_id!=id);$('filt-cat').querySelector(`option[value="${id}"]`)?.remove();renderAll();}}
async function deleteLink(id){if(!confirm('¿Eliminar este enlace?'))return;const res=await fetch('',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_link',id})});if((await res.json()).status==='success'){links=links.filter(l=>l.id!=id);renderAll();}}

document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeAddLink();closeEditLink();closeNotes();}});
$('notes-modal').addEventListener('click',e=>{if(e.target===$('notes-modal'))closeNotes();});
$('add-link-modal').addEventListener('click',e=>{if(e.target===$('add-link-modal'))closeAddLink();});
$('edit-link-modal').addEventListener('click',e=>{if(e.target===$('edit-link-modal'))closeEditLink();});

// Keyboard shortcut: focus search on /
document.addEventListener('keydown',e=>{if(e.key==='/'&&document.activeElement.tagName!=='INPUT'&&document.activeElement.tagName!=='TEXTAREA'){e.preventDefault();$('searchInput').focus();}});

renderAll();

// ── Eventos que antes estaban en línea ─────────────────────
function lbToggleNewCat(){document.getElementById('lb-newcat').classList.toggle('open');document.getElementById('new-cat-input').focus();}
function lbNewCatKey(e){if(e.key==='Enter')addCategory();if(e.key==='Escape')document.getElementById('lb-newcat').classList.remove('open');}
function lbSearchKey(e,el){if(e.key==='Escape'){el.value='';applyFilters();el.blur();}}
function lbToggleSel(el){el.classList.toggle('selected');}
function lbImgLoad(img){if(img.naturalWidth<200)img.classList.add('small');}
function lbImgError(img){img.parentNode.classList.add('noimg');img.remove();}
function lbDelCat(id,el){return deleteCategory(id,el.dataset.n);}
