/* promos/promos.js — Script principal de Promos (antes en línea en index.php; CSP estricta, SEC-24 fase 2).
   Los datos de PHP llegan en <script type="application/json" data-global="PR_DATA"> (assets/json-globals.js). */
window.PF_PROJECTS = PR_DATA.pf.projects;   // proyectos del rubro (null: listas fijas)
window.PF_CONFIG = PR_DATA.pf.config;
window.PF_JIRA = PR_DATA.pf.jira;           // campo "Jira: vincular con ticket" (forms.js)
window.PF_ADMIN = PR_DATA.pf.admin;
const items = PR_DATA.items;
const PR_TOTAL = PR_DATA.total;
const PR_PER_PAGE = 15;
let   prPage    = 1;
let   prLoading = false;
let   prHasMore = false; // se cargan todos los contenidos de una vez
let   prShown = 60;      // la grilla se dibuja de a 60
const IS_ADMIN = PR_DATA.isAdmin;
const PR_URL   = window.location.pathname;
const ME = PR_DATA.me;
const CURRENT_USER = PR_DATA.currentUser;
const IS_ADMIN_PROMOS = PR_DATA.isAdmin;
const ALL_USERS_CMS = PR_DATA.users;
const AVATAR_MAP = Object.fromEntries(ALL_USERS_CMS.map(u=>[u.username, u.avatar||'']));
const ME_ID_CMS = PR_DATA.meId;
const IS_ADMIN_CMS = PR_DATA.isAdmin;
const TYPE_LABELS = {promo:'Promo/Torneo',launch:'Lanzamiento',paid:'Paid',social:'Redes',otro:'Otro',custom:'Custom',tarea:'Tarea rápida',mailings:'Mailings'};
const TYPE_CSS = {promo:'type-promo',launch:'type-launch',paid:'type-paid',social:'type-social',otro:'type-otro',custom:'type-otro',tarea:'type-tarea',mailings:'type-mailings'};

let statusFilter='all', typeFilter='all', projectFilter='all', viewMode='grid';
let formType='promo', mailingMode='link', mediaType='imagen';
let currentDetailId=null, formCollabs=[], collabModalContext='new', mondayModalContext='new';
let mondayCachedTasks=null;
const today=new Date().toISOString().split('T')[0];
const isInactive=p=>p.end&&p.end<today;
const $=id=>document.getElementById(id);

function itemMatchesProject(p,proj){
  if(proj==='all')return true;
  if(p.project===proj)return true;
  if(Array.isArray(p.projects)&&p.projects.includes(proj))return true;
  return false;
}
function updateCounters(){
  // Igual que la grilla: tareas rápidas y mailings tienen su propio filtro
  const _main=items.filter(p=>p.type!=='tarea'&&p.type!=='mailings');
  $('cnt-all').textContent=_main.length;
  $('cnt-active').textContent=_main.filter(p=>!isInactive(p)).length;
  $('cnt-inactive').textContent=_main.filter(p=>isInactive(p)).length;
  $('cnt-t-promo').textContent=items.filter(p=>p.type==='promo').length;
  $('cnt-t-launch').textContent=items.filter(p=>p.type==='launch').length;
  $('cnt-t-paid').textContent=items.filter(p=>p.type==='paid').length;
  $('cnt-t-social').textContent=items.filter(p=>p.type==='social').length;
  $('cnt-t-otro').textContent=items.filter(p=>p.type==='otro').length;
  if (window.PF_PROJECTS) { PF_PROJECTS.forEach((n, i) => { const e = $('cnt-pp-' + i); if (e) e.textContent = items.filter(p => itemMatchesProject(p, n)).length; }); return; }
  if($('cnt-p-otros'))$('cnt-p-otros').textContent=items.filter(p=>itemMatchesProject(p,'Otros')).length;
}
function getFiltered(){
  const s=$('searchInput').value.toLowerCase();
  const ftype=$('filt-type').value,cat=$('filt-cat').value,author=$('filt-author').value,media=$('filt-media').value;
  const d1=$('filt-date-from').value,d2=$('filt-date-to').value;
  return items.filter(p=>{
    // Tareas rápidas solo aparecen cuando se filtra explícitamente por ese tipo
    if(p.type==='tarea' && typeFilter!=='tarea') return false;
    if(p.type==='mailings' && typeFilter!=='mailings') return false;
    if(statusFilter==='active'&&isInactive(p))return false;
    if(statusFilter==='inactive'&&!isInactive(p))return false;
    if(typeFilter!=='all'&&p.type!==typeFilter)return false;
    if(!itemMatchesProject(p,projectFilter))return false;
    if(ftype&&p.type!==ftype)return false;
    if(cat&&p.category!==cat)return false;
    if(author&&p.author!==author)return false;
    if(media&&p.media_type!==media)return false;
    if(d1&&p.start&&p.start<d1)return false;
    if(d2&&p.end&&p.end>d2)return false;
    if(s){ const hay=[p.name,p.project,(p.projects||[]).join(' '),p.author,p.category,TYPE_LABELS[p.type]||p.type,p.media_type].join(' ').toLowerCase(); if(!hay.includes(s)) return false; }
    return true;
  });
}

function thumbUrl(img,w){ return /^imgs\//.test(img||'') ? 'thumb.php?w='+w+'&src='+encodeURIComponent(img) : img; }
function imgBlock(p,w,h){
  return p.img?`<img src="${thumbUrl(p.img, w==='100%'?640:160)}" alt="" loading="lazy" decoding="async" style="width:${w};height:${h};object-fit:cover" data-on-error="prHide" data-error-1="this">`:`<div class="pr-noimg" style="width:${w};height:${h}"><i class="fa-regular fa-image"></i></div>`;
}
function projectTags(p){
  if(Array.isArray(p.projects)&&(p.projects.length>1||(p.type==='launch'&&p.projects.length))){
    const ps=p.projects; const shown=ps.slice(0,3).map(pr=>`<span class="proj-tag">${escH(pr)}</span>`).join(' ');
    return shown+(ps.length>3?` <span class="proj-tag" title="${escH(ps.slice(3).join(', '))}">+${ps.length-3}</span>`:'')+(ps.length>1&&p.type!=='launch'?' <span class="proj-tag" title="Tarea de múltiples plataformas"><i class="fa-solid fa-layer-group" style="font-size:8px"></i></span>':'');
  }
  return p.project?`<span class="proj-tag">${p.project}</span>`:'';
}
function renderItems(keepPage){
  const c=$('item-container');
  if(!keepPage) prShown=60;
  c.className=viewMode==='grid'?'grid-view':'list-view';
  const all=getFiltered();
  const rc=$('pr-results'); if(rc) rc.textContent = all.length + (all.length===1?' contenido':' contenidos');
  if(!all.length){c.innerHTML='<div class="empty"><i class="fa-solid fa-magnifying-glass" style="font-size:22px;display:block;margin-bottom:10px;opacity:.25"></i>No hay contenidos con estos filtros.<br><button type="button" data-on-click="clearFilters" class="pr-more" style="margin-top:14px">Limpiar filtros</button></div>';return;}
  const fl=all.slice(0,prShown);
  const more = all.length>prShown ? `<div class="pr-more-wrap"><button type="button" class="pr-more" data-on-click="prShowMore">Mostrar más <span>${Math.min(60,all.length-prShown)} de ${all.length-prShown} restantes</span></button></div>` : '';
  c.innerHTML=fl.map(p=>{
    const x=isInactive(p);
    const isTarea=p.type==='tarea';
  const ttag=`<span class="type-tag ${TYPE_CSS[p.type]}">${TYPE_LABELS[p.type]||p.type}</span>${p.edit_type?`<span style="font-size:8px;font-weight:700;padding:1px 6px;border-radius:20px;margin-left:4px;background:${p.edit_type==='rechazo'?'rgba(239,68,68,.15)':'rgba(255,165,0,.15)'};color:${p.edit_type==='rechazo'?'#ef4444':'#f59e0b'};border:1px solid ${p.edit_type==='rechazo'?'rgba(239,68,68,.3)':'rgba(255,165,0,.3)'}">${p.edit_type==='rechazo'?'Rechazo':'Revisión'}</span>`:''}`;
    const ptags=projectTags(p);
    const stat=`<span class="status-pill ${x?'off':'on'}"><span class="sdot"></span>${x?'Finalizado':'Activo'}</span>`;
    const av=AVATAR_MAP[p.author];const avHtml=av?`<img src="${av}" style="width:14px;height:14px;border-radius:50%;object-fit:cover;flex-shrink:0">`:`<span style="width:14px;height:14px;border-radius:50%;background:var(--g7-bg-5);display:inline-flex;align-items:center;justify-content:center;font-size:7px;font-weight:700;flex-shrink:0;color:var(--g7-text-2)">${(p.author||'?')[0].toUpperCase()}</span>`;
    const chips=[p.category&&`<span class="chip chip-cat">${p.category}</span>`,p.media_type&&`<span class="chip chip-media">${p.media_type}</span>`,`<span class="chip chip-author" style="display:inline-flex;align-items:center;gap:4px">${avHtml}${p.author}</span>`].filter(Boolean).join('');
    const links=[p.mailing?`<a href="${p.mailing}" target="_blank" class="link-chip" data-on-click="@stop"><i class="fa-solid fa-envelope" style="font-size:8px"></i> Mailing</a>`:'',p.graphics?`<a href="${p.graphics}" target="_blank" class="link-chip" data-on-click="@stop"><i class="fa-solid fa-images" style="font-size:8px"></i> Gr&aacute;ficas</a>`:'',...landingList(p.landing).map((u,i,a)=>`<a href="${cxEsc(u)}" target="_blank" class="link-chip" data-on-click="@stop"><i class="fa-solid fa-globe" style="font-size:8px"></i> Landing${a.length>1?' '+(i+1):''}</a>`)].join('');
    const dates=(p.start||p.end)?`<div class="card-dates"><i class="fa-regular fa-calendar"></i>${p.start||'--'} → ${p.end||'--'}</div>`:'';
    const likeBtns=`<div style="display:flex;align-items:center;gap:12px;margin-bottom:6px" data-on-click="prCardBar" data-click-1="event"><button class="like-btn" data-id="${p.id}" style="background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:4px;padding:0"><i class="${p.liked?'fa-solid':'fa-regular'} fa-heart" style="font-size:13px;color:${p.liked?'#ef4444':'#52525b'}"></i><span id="like-cnt-${p.id}" style="font-size:10px;color:var(--g7-text-3)">${p.like_count||0}</span></button><button class="cmt-open-btn" data-id="${p.id}" style="background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:4px;padding:0"><i class="fa-regular fa-comment" style="font-size:13px;color:var(--g7-text-4)"></i><span id="cmt-cnt-${p.id}" style="font-size:10px;color:var(--g7-text-3)">${p.comment_count||0}</span></button></div>`;
    if(viewMode==='grid')return`<div class="item-card${x?' inactive':''}" data-type="${p.type}" data-on-click="openDetail" data-click-1="j:${p.id}"><div class="card-img-g">${imgBlock(p,'100%','120px')}</div><div class="card-body-g"><div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:6px">${ttag}${ptags}</div><div style="font-size:13px;font-weight:700;margin-bottom:8px;line-height:1.3;color:var(--txt)">${p.name}</div><div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;margin-bottom:8px">${stat}${chips}</div>${dates}${likeBtns}<div class="links-row">${links}</div></div></div>`;
    return`<div class="item-card card-list${x?' inactive':''}" data-type="${p.type}" data-on-click="openDetail" data-click-1="j:${p.id}"><div class="card-img-l">${imgBlock(p,'70px','52px')}</div><div class="card-body-l"><div style="min-width:0;flex:1"><div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:2px">${ttag}${ptags}</div><div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${p.name}</div></div><div style="display:flex;gap:4px">${chips}</div>${dates?`<div style="font-size:10px;color:var(--g7-text-4);white-space:nowrap">${p.start||'--'} &rarr; ${p.end||'--'}</div>`:''}<span>${stat}</span></div></div>`;
  }).join('')+more;
}

function setStatus(s,btn){statusFilter=s;document.querySelectorAll('[id^="s-"]').forEach(b=>b.classList.remove('active'));btn.classList.add('active');renderItems();}
function setType(t,btn){typeFilter=t;document.querySelectorAll('[id^="t-"]').forEach(b=>b.classList.remove('active'));btn.classList.add('active');renderItems();}
function setProject(p,btn){projectFilter=p;document.querySelectorAll('[id^="p-"]').forEach(b=>b.classList.remove('active'));btn.classList.add('active');renderItems();}
function setViewMode(m){viewMode=m;$('vt-grid').classList.toggle('active',m==='grid');$('vt-list').classList.toggle('active',m==='list');renderItems();}
function showView(v){
  const fc=$('form-container');
  if(v==='create'){
    fc.classList.remove('hidden','closing');
    $('view-title').innerHTML='Nuevo <span>contenido</span>';
    // click backdrop to close
    fc._backdropClose=fc._backdropClose||(e=>{if(e.target===fc)showView('list');});
    fc.addEventListener('click',fc._backdropClose);
    // hookear inputs para progreso
    if(!fc._progHooked){
      fc.addEventListener('input',updateProgress);
      fc.addEventListener('change',updateProgress);
      fc._progHooked=true;
    }
    setTimeout(updateProgress,80);
  } else {
    fc.classList.add('closing');
    setTimeout(()=>{fc.classList.add('hidden');fc.classList.remove('closing');},200);
    $('item-container').classList.remove('hidden');
    $('filter-bar').classList.remove('hidden');
    renderItems();
    $('view-title').innerHTML='Todo el <span>contenido</span>';
    return;
  }
  $('item-container').classList.toggle('hidden',v!=='list');
  $('filter-bar').classList.toggle('hidden',v!=='list');
}
function adjQty(id,delta){const el=$(id);el.value=Math.max(0,(parseInt(el.value)||0)+delta);updateProgress();}
const _typeColors={promo:'#ff6600',launch:'#a855f7',paid:'#22c55e',social:'#3b82f6',otro:'#f59e0b',custom:'#06b6d4',tarea:'#ec4899',mailings:'#14b8a6'};
function updateProgress(){
  const fc=$('form-container');
  const fields=[...(fc||document).querySelectorAll('.form-group input:not([type=hidden]):not([type=file]),.form-group select,.form-group textarea')];
  let vis=0,filled=0;
  fields.forEach(el=>{
    if(el.closest('.hidden'))return;
    vis++;
    const v=(el.value||'').trim();
    if(v!==''&&v!=='0')filled++;
  });
  const pct=vis?Math.round(filled/vis*100):0;
  const color=_typeColors[formType]||'#ff6600';
  const fill=$('fc-prog-fill'),lbl=$('fc-prog-pct');
  if(fill){fill.style.width=pct+'%';fill.style.background=color;}
  if(lbl){lbl.textContent=pct+'%';lbl.style.color=color;}
}
function clearFilters(){['searchInput','filt-type','filt-cat','filt-author','filt-media','filt-date-from','filt-date-to'].forEach(id=>$(id).value='');renderItems();}
function toggleSidebar(){const sb=$('sidebar');sb.classList.toggle('collapsed');$('col-icon').style.transform=sb.classList.contains('collapsed')?'rotate(180deg)':'';}
// ── Tarea de múltiples plataformas ──
function multiSelected(){ return [...document.querySelectorAll('input[name="mprojects[]"]:checked')].map(c=>c.value); }
function toggleMulti(){
  const on = $('f-multi').checked;
  $('multi-wrap').classList.toggle('on', on);
  $('multi-projects').style.display = on ? '' : 'none';
  $('multi-hint').style.display = on ? '' : 'none';
  // El proyecto único queda deshabilitado: lo definen las plataformas elegidas
  ['f-project','f-paidso-project'].forEach(id=>{ const g=$(id)?.closest('.form-group'); if(g) g.classList.toggle('single-off', on); });
  if (!on) document.querySelectorAll('input[name="mprojects[]"]').forEach(c=>c.checked=false);
  updateMulti();
}
function updateMulti(){
  const h = $('multi-hint'); if (!h || !$('f-multi').checked) return;
  const sel = multiSelected(), n = sel.length;
  const g = parseInt($('f-qty-graficas')?.value)||0, v = parseInt($('f-qty-videos')?.value)||0;
  const fmt = x => Number.isInteger(x) ? x : x.toFixed(1).replace('.', ',');
  if (n < 2) { h.innerHTML = 'Elige al menos <b>dos plataformas</b>.'; return; }
  const parts = [];
  if (g) parts.push(`<b>${fmt(g/n)}</b> gráfica${g/n===1?'':'s'}`);
  if (v) parts.push(`<b>${fmt(v/n)}</b> video${v/n===1?'':'s'}`);
  h.innerHTML = `${n} plataformas elegidas.` + (parts.length ? ` En Producción cada una suma ${parts.join(' y ')}.` : ' Las cantidades que cargues en Producción se reparten en partes iguales.');
}
document.addEventListener('input', e => { if (e.target && (e.target.id==='f-qty-graficas' || e.target.id==='f-qty-videos')) updateMulti(); });
document.addEventListener('click', e => { if (e.target.closest && e.target.closest('.qty-btn,[data-on-click="adjQty"]')) setTimeout(updateMulti, 0); });

function selectFormType(type,btn){
  formType=type;
  document.querySelectorAll('.type-tab').forEach(b=>b.classList.remove('active'));btn.classList.add('active');
  setTimeout(updateProgress,50);
  document.querySelectorAll('.f-promo,.f-launch,.f-paidso,.f-otro,.f-custom,.f-tarea,.f-mailings').forEach(el=>el.classList.add('hidden'));
  if(type==='promo') document.querySelectorAll('.f-promo').forEach(el=>el.classList.remove('hidden'));
  else if(type==='launch') document.querySelectorAll('.f-launch').forEach(el=>el.classList.remove('hidden'));
  else if(type==='otro'){document.querySelectorAll('.f-paidso,.f-otro').forEach(el=>el.classList.remove('hidden'));}
  else if(type==='custom'){
    document.querySelectorAll('.f-custom').forEach(el=>el.classList.remove('hidden'));
    if(!document.querySelector('#f-resources-list .resource-pair')) addResourceField();
  }
  else if(type==='tarea'){
    document.querySelectorAll('.f-tarea').forEach(el=>el.classList.remove('hidden'));
  }
  else if(type==='mailings'){
    document.querySelectorAll('.f-mailings').forEach(el=>el.classList.remove('hidden'));
  }
  else if(type==='tarea'){
    document.querySelectorAll('.f-tarea').forEach(el=>el.classList.remove('hidden'));
  }
  else if(type==='mailings'){
    document.querySelectorAll('.f-mailings').forEach(el=>el.classList.remove('hidden'));
  }
  else document.querySelectorAll('.f-paidso').forEach(el=>el.classList.remove('hidden'));
  const vcw=document.getElementById('video-count-wrap');
  if(vcw) vcw.style.display=(mediaType==='video'&&(type==='paid'||type==='social'||type==='otro'))?'':'none';
  // La casilla multiplataforma va debajo del selector de proyecto que corresponda
  const mw=$('multi-wrap');
  if(mw){
    const anchor = type==='promo' ? $('f-category')?.closest('.form-group') : $('f-paidso-project')?.closest('.form-group');
    const multiOk = !!anchor && (type==='promo'||type==='paid'||type==='social'||type==='otro');
    if(multiOk){ anchor.after(mw); mw.classList.remove('hidden'); }
    else { mw.classList.add('hidden'); if($('f-multi').checked){ $('f-multi').checked=false; toggleMulti(); } }
  }
}

async function uploadMailingFile(input){
  const file = input.files[0]; if(!file) return;
  const fd = new FormData();
  fd.append('action','upload_mailing'); fd.append('file', file);
  try{
    const r = await fetch(PR_URL, {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){
      document.getElementById('f-mailings-link').value = d.url;
      document.getElementById('f-mailings-fname').textContent = file.name;
      const prev = document.getElementById('f-mailings-preview');
      prev.style.display = 'flex';
    }
  }catch(e){}
  input.value='';
}
function clearMailingFile(){
  document.getElementById('f-mailings-link').value='';
  document.getElementById('f-mailings-preview').style.display='none';
}

function addResourceField(){
  const list = document.getElementById('f-resources-list');
  const div  = document.createElement('div');
  div.className = 'resource-pair';
  div.style.cssText = 'display:flex;gap:8px;align-items:center';
  div.innerHTML = `
    <input type="text" placeholder="Nombre del recurso" class="res-name"
      style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none">
    <input type="text" placeholder="URL / Enlace" class="res-url"
      style="flex:2;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:7px 10px;font-size:12px;outline:none">
    <button type="button" data-on-click="prRemoveClosest" data-click-1="this" data-click-2="s:.resource-pair"
      style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:14px;padding:4px">
      <i class="fa-solid fa-xmark"></i>
    </button>`;
  list.appendChild(div);
}

function getResources(){
  return Array.from(document.querySelectorAll('#f-resources-list .resource-pair'))
    .map(p=>({name:p.querySelector('.res-name').value.trim(), url:p.querySelector('.res-url').value.trim()}))
    .filter(r=>r.name||r.url);
}

function updateNameWithVideos(){
  const nameEl=$('f-name');
  if(!nameEl)return;
  const count=parseInt($('f-video-count')?.value)||0;
  // Remove any existing " - X videos" suffix
  let name=nameEl.value.replace(/ - \d+ videos?$/i,'').trim();
  if(mediaType==='video'&&count>0){
    name=name+' - '+count+' video'+(count>1?'s':'');
  }
  nameEl.value=name;
}

function setMailingMode(mode,btn){mailingMode=mode;$('mailing-toggle').querySelectorAll('button').forEach(b=>b.classList.remove('active'));btn.classList.add('active');$('mailing-link-wrap').style.display=mode==='link'?'':'none';$('mailing-file-wrap').style.display=mode==='file'?'':'none';}
function setMediaType(val,btn){mediaType=val;$('media-toggle').querySelectorAll('button').forEach(b=>b.classList.remove('active'));btn.classList.add('active');$('f-media-type').value=val;
  const vcw=$('video-count-wrap');
  if(vcw){vcw.style.display=val==='video'?'':'none';if(val!=='video')$('f-video-count').value='';}
  updateNameWithVideos();
}

async function saveItem(){
  const name=$('f-name').value.trim();if(!name){alert('El nombre es obligatorio');return;}
  const cx=cxGet('f-cx');if(cx.yes&&!cx.list.length){alert('Marcaste que hubo complicaciones: elige al menos una.');return;}
  const fd=new FormData();
  fd.append('complications',JSON.stringify(cx.list));fd.append('complications_note',cx.note);
  fd.append('action','save');fd.append('type',formType);fd.append('name',name);
  if(IS_ADMIN&&$('f-author'))fd.append('author',$('f-author').value);
  fd.append('graphics',$('f-graphics').value);
  const cover=$('f-img').files[0];if(cover)fd.append('cover_img',cover);
  if(formType==='promo'){
    fd.append('project',$('f-project').value);fd.append('category',$('f-category').value);
    fd.append('landing',lmGet('f-landing').join('\n'));fd.append('mailing_link',$('f-mailing-link').value);
    const mf=$('f-mailing-file').files[0];if(mf)fd.append('mailing_file',mf);
  }else if(formType==='launch'){
    document.querySelectorAll('input[name="projects[]"]:checked').forEach(cb=>fd.append('projects[]',cb.value));
  }else if(formType==='otro'){
    fd.append('project',$('f-paidso-project').value);fd.append('media_type',$('f-media-type').value);
    const cl=$('f-custom-label').value.trim(),cv=$('f-custom-value').value.trim();
    if(cl&&cv)fd.append('custom_field',JSON.stringify({label:cl,value:cv}));
  }else{
    fd.append('project',$('f-paidso-project').value);fd.append('media_type',$('f-media-type').value);
  }
  // Multiplataforma: proyecto principal = primera plataforma; todas van en projects[]
  if($('f-multi')?.checked && !$('multi-wrap').classList.contains('hidden')){
    const sel = multiSelected();
    if(sel.length < 2){ alert('Elige al menos dos plataformas o desmarca "Tarea de múltiples plataformas".'); return; }
    fd.set('project', sel[0]);
    fd.delete('projects[]');
    sel.forEach(v=>fd.append('projects[]', v));
  }
  fd.append('collaborators',JSON.stringify(formCollabs.map(u=>u.id)));
  fd.append('monday_task_id',$('f-monday-task-id').value);
  fd.append('monday_task_name',$('f-monday-task-name').value);
  // Custom type fields
  if(formType==='custom'){
    fd.append('content_type', $('f-content-type')?.value||'');
    fd.append('custom_date', $('f-custom-date')?.value||'');
    fd.append('resources', JSON.stringify(getResources()));
  }
  // Tarea rápida fields
  if(formType==='tarea'){
    fd.append('tarea_qty',  $('f-tarea-qty')?.value||1);
    fd.append('tarea_desc', $('f-tarea-desc')?.value||'');
  }
  // Mailings fields
  if(formType==='mailings'){
    fd.append('mailings_qty',  $('f-mailings-qty')?.value||1);
    fd.append('mailings_link', $('f-mailings-link')?.value||'');
  }
  // Qty production fields
  fd.append('qty_graficas',       $('f-qty-graficas')?.value||0);
  fd.append('qty_videos',         $('f-qty-videos')?.value||0);
  fd.append('qty_landings',       $('f-qty-landings')?.value||0);
  fd.append('qty_mailings',       $('f-qty-mailings')?.value||0);
  fd.append('qty_creativos_paid', $('f-qty-paid')?.value||0);
  fd.append('qty_assets_rrss',    $('f-qty-rrss')?.value||0);
  fd.append('qty_assets_mobile',  $('f-qty-mobile')?.value||0);
  fd.append('tiempo_entrega_dias',$('f-qty-dias')?.value||'');
  fd.append('monday_task_url',$('f-monday-task-url').value);
  if(window.pfAppend&&pfAppend(fd)===false)return;
  if(window.pdAppend)pdAppend(fd);   // entrega de un pedido (promos/entrega.js)
  const res=await fetch('/toolz/promos/',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){
    if(window.pdAfter)pdAfter(data.delivery);
    const p=data.item;p.start=p.start_date||'';p.end=p.end_date||'';p.collaborators=[];p.like_count=0;p.liked=false;p.comment_count=0;
    if(typeof p.projects==='string')p.projects=JSON.parse(p.projects||'[]');
    items.unshift(p);updateCounters();renderItems();
    formCollabs=[];
    ['f-name','f-mailing-link','f-graphics','f-landing','f-start','f-end','f-launch-start','f-launch-end','f-custom-label','f-custom-value','f-monday-task-id','f-monday-task-name','f-monday-task-url'].forEach(id=>{const el=$(id);if(el)el.value='';});cxSet('f-cx',[],'');lmRender('f-landing',[]);
    ['f-img','f-mailing-file'].forEach(id=>{const el=$(id);if(el)el.value='';});
    document.querySelectorAll('input[name="projects[]"]').forEach(cb=>cb.checked=false);
    if($('f-multi')?.checked){ $('f-multi').checked=false; toggleMulti(); }
    $('form-link-preview').style.display='none';$('form-link-preview').innerHTML='';
    showView('list');
    promoToast('✓ '+p.name+' guardada');
  }
}
function copyAndClose(){const a=$('msg-area');a.select();document.execCommand('copy');$('msg-modal').classList.add('hidden');showView('list');}

// ── DETAIL PANEL ──────────────────────────────────────────
function closePanelOverlay(e,el){if(e.target===(el||e.currentTarget))closeDetail();}   // el: el overlay (g7-on escucha en document)
function closeDetail(){$('detail-panel').classList.add('hidden');currentDetailId=null;}

function copyDetailMessage(){
  const p = items.find(x=>x.id==currentDetailId);
  if(!p) return;
  const projLine = (Array.isArray(p.projects)&&p.projects.length)
    ? 'Proyectos: '+p.projects.join(', ')+'\n'
    : (p.project ? 'Proyecto: '+p.project+'\n' : '');
  let msg = 'Hola, ya estan listos los elementos:\n\nNombre: '+p.name+'\nTipo: '+(TYPE_LABELS[p.type]||p.type)+'\n'+projLine;
  if(p.mailing)  msg += '\nMailing:\n'+p.mailing+'\n';
  if(p.graphics) msg += '\nGraficas:\n'+p.graphics+'\n';
  const lds = landingList(p.landing);
  if(lds.length) msg += '\n'+(lds.length>1?'Landings':'Landing')+':\n'+lds.join('\n')+'\n';
  msg += '\nMuchas gracias';
  // Copy to clipboard
  navigator.clipboard.writeText(msg).then(()=>{
    const btn = $('d-copy-msg-btn');
    if(btn){ const orig=btn.innerHTML; btn.innerHTML='<i class="fa-solid fa-check" style="font-size:10px"></i> ¡Copiado!'; btn.style.background='rgba(34,197,94,.2)'; setTimeout(()=>{ btn.innerHTML=orig; btn.style.background='rgba(34,197,94,.1)'; },2000); }
  }).catch(()=>{
    // Fallback
    const ta=document.createElement('textarea'); ta.value=msg; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    showVerde('Mensaje copiado');
  });
}

function openDetail(id){
  const p=items.find(x=>x.id==id);if(!p)return;
  currentDetailId=id;
  const x=isInactive(p);
  $('d-type-tag').className='type-tag '+TYPE_CSS[p.type];
  $('d-type-tag').textContent=TYPE_LABELS[p.type]||p.type;
  const dpj=$('d-proj-tag');
  if(p.type==='launch'&&Array.isArray(p.projects)&&p.projects.length){dpj.innerHTML=p.projects.map(pr=>'<span class="proj-tag" style="margin-left:4px">'+pr+'</span>').join('');}
  else{dpj.innerHTML=p.project?'<span class="proj-tag" style="margin-left:4px">'+p.project+'</span>':'';}
  const di=$('d-img');
  if(p.img){di.src=p.img;di.style.display='block';}else di.style.display='none';
  $('d-name').textContent=p.name;
  $('d-author').textContent=p.author;
  $('d-created').textContent=p.created_at||'---';
  $('d-cat-wrap').style.display=p.category?'':'none';$('d-cat').textContent=p.category||'';
  $('d-media-wrap').style.display=p.media_type?'':'none';$('d-media').textContent=p.media_type||'';
  const hasDates=p.start||p.end;
  $('d-start-wrap').style.display=hasDates?'':'none';$('d-start').textContent=p.start||'---';
  $('d-end-wrap').style.display=hasDates?'':'none';$('d-end').textContent=p.end||'---';
  $('d-status-wrap').style.display=hasDates?'':'none';
  $('d-status').innerHTML='<span class="status-dot '+( x?'off':'on')+'"></span>'+(x?'Finalizado':'Activo');
  const sl=(wid,eid,uid,val)=>{$(wid).style.display=val?'':'none';if(val){$(eid).href=val;$(uid).textContent=val;}};
  sl('d-mailing-wrap','d-mailing','d-mailing-u',p.mailing);
  // Show edit button for any local HTML mailing — only author or admin
  const editBtn = $('d-mailing-edit-btn');
  if(editBtn){
    const mailingUrl = p.mailing || '';
    const isLocalHtml = mailingUrl && /\.(html?|htm)$/i.test(mailingUrl) &&
      (mailingUrl.startsWith('/toolz/') || mailingUrl.includes(location.host + '/toolz/'));
    const canEditMailing = IS_ADMIN || (p.created_by && p.created_by == ME_ID_CMS);
    editBtn.style.display = (isLocalHtml && canEditMailing) ? '' : 'none';
  }
  sl('d-graphics-wrap','d-graphics','d-graphics-u',p.graphics);
  showLandings(p.landing);
  if(p.monday_task_name){$('d-monday-name').textContent=p.monday_task_name;$('d-monday-link').href=p.monday_task_url||'#';$('d-monday-wrap').style.display='';}
  else $('d-monday-wrap').style.display='none';
  if(p.custom_field&&p.type==='otro'){try{const cf=JSON.parse(p.custom_field);$('d-custom-label').textContent=cf.label||'Campo';$('d-custom-value').textContent=cf.value||'';$('d-custom-wrap').style.display='';}catch(e){$('d-custom-wrap').style.display='none';}}
  else $('d-custom-wrap').style.display='none';
  const canEdit=IS_ADMIN_PROMOS||p.created_by==CURRENT_USER.id||p.author===CURRENT_USER.username;
  const eb=$('d-edit-btn');if(eb)eb.style.display=canEdit?'flex':'none';
  $('d-del-btn').style.display=canEdit?'flex':'none';
  $('d-del-btn').onclick=()=>deleteItem(id);
  cxShowDetail(p);
  emShowDetail(p);
  renderDetailCollabs(p.collaborators||[]);
  $('detail-panel').classList.remove('hidden');
  $('d-new-comment').value='';
  $('d-comments-list').innerHTML='<div class="dc-no-comments"><i class="fa-solid fa-spinner fa-spin" style="display:block;font-size:18px;margin-bottom:6px;opacity:.5"></i></div>';
  fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_content',content_id:id})})
    .then(r=>r.json()).then(data=>{
      if(data.item){
        $('d-like-icon').className=data.liked?'fa-solid fa-heart':'fa-regular fa-heart';
        $('d-like-icon').style.color=data.liked?'#ef4444':'#52525b';
        $('d-like-count').textContent=data.likes;
        $('d-cmt-count').textContent=(data.comments||[]).length;
        renderDComments(data.comments||[]);
        if(data.collaborators)renderDetailCollabs(data.collaborators);
        const pi=items.find(x=>x.id==id);if(pi){pi.liked=data.liked;pi.like_count=data.likes;pi.comment_count=(data.comments||[]).length;if(data.collaborators)pi.collaborators=data.collaborators;}
      }
    });
  // Load resources for custom type
  const resWrap=$('d-resources-wrap');
  if(resWrap){
    if(p.type==='custom'){
      resWrap.style.display='block';
      loadDetailResources(id);
    } else {
      resWrap.style.display='none';
    }
  }
}

async function deleteItem(id){
  if(!confirm('¿Eliminar este contenido?'))return;
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',id})});
  if((await res.json()).status==='success'){const idx=items.findIndex(p=>p.id==id);if(idx>=0)items.splice(idx,1);closeDetail();updateCounters();renderItems();}
}

// ── LIKES ─────────────────────────────────────────────────
async function toggleLike(id,btn){
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_like',content_id:id})});
  const data=await res.json();
  if(data.status==='success'){
    const icon=btn.querySelector('i');icon.className=data.liked?'fa-solid fa-heart':'fa-regular fa-heart';icon.style.color=data.liked?'#ef4444':'#52525b';
    const cnt=$('like-cnt-'+id);if(cnt)cnt.textContent=data.likes;
    if(currentDetailId==id){$('d-like-icon').className=data.liked?'fa-solid fa-heart':'fa-regular fa-heart';$('d-like-icon').style.color=data.liked?'#ef4444':'#52525b';$('d-like-count').textContent=data.likes;}
    const pi=items.find(x=>x.id==id);if(pi){pi.liked=data.liked;pi.like_count=data.likes;}
  }
}
async function toggleLikeDetail(){
  if(!currentDetailId)return;
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_like',content_id:currentDetailId})});
  const data=await res.json();
  if(data.status==='success'){
    $('d-like-icon').className=data.liked?'fa-solid fa-heart':'fa-regular fa-heart';$('d-like-icon').style.color=data.liked?'#ef4444':'#52525b';$('d-like-count').textContent=data.likes;
    const pi=items.find(x=>x.id==currentDetailId);if(pi){pi.liked=data.liked;pi.like_count=data.likes;}
    const cnt=$('like-cnt-'+currentDetailId);if(cnt)cnt.textContent=data.likes;
  }
}

// ── COMMENTS ──────────────────────────────────────────────
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escA(s){return escH(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');}   // para atributos data-… (CSP: los argumentos ya no van en código)
function hlMentions(t){return t.replace(/@(\w+)/g,'<span class="mention">@$1</span>');}
function dcAv(u,size){
  if(u&&u.avatar)return '<img src="'+u.avatar+'" style="width:'+size+'px;height:'+size+'px;border-radius:50%;object-fit:cover;flex-shrink:0">';
  const name=(u&&(u.display_name||u.username))||'?',i=(name[0]||'?').toUpperCase();
  const c=['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][i.charCodeAt(0)%6];
  return '<div style="width:'+size+'px;height:'+size+'px;border-radius:50%;background:'+c+';display:flex;align-items:center;justify-content:center;font-size:'+Math.floor(size*.4)+'px;font-weight:700;color:#000;flex-shrink:0">'+i+'</div>';
}
function avHtmlJS(u,size){return dcAv(u,size);}

function renderDComment(c,kids){
  const name=c.display_name||c.username;
  const canDel=c.author_id==ME_ID_CMS||IS_ADMIN_CMS;
  const replies=kids[c.id]||[];
  const t=new Date(c.created_at).toLocaleString('es-AR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
  return '<div class="dc-comment" id="dc-'+c.id+'"><div class="dc-bubble"><div class="dc-header">'+dcAv(c,20)+'<span class="dc-author">'+escH(name)+'</span><span class="role-badge '+(c.role==='admin'?'rb-admin':'rb-user')+'">'+c.role+'</span><span class="dc-time">'+t+'</span></div><div class="dc-body">'+hlMentions(escH(c.body))+'</div><div class="dc-actions"><button class="dc-btn" data-on-click="toggleDReply" data-click-1="j:'+c.id+'" data-click-2="s:'+escA(name)+'"><i class="fa-solid fa-reply"></i> Responder</button>'+(canDel?'<button class="dc-btn del" data-on-click="deleteDComment" data-click-1="j:'+c.id+'"><i class="fa-solid fa-trash-can"></i> Borrar</button>':'')+'</div></div><div class="dc-reply-form" id="dc-rf-'+c.id+'"><textarea class="dc-reply-ta" id="dc-rta-'+c.id+'" rows="2"></textarea><button class="dc-send" data-on-click="submitDReply" data-click-1="j:'+c.id+'"><i class="fa-solid fa-paper-plane"></i></button></div><div class="dc-replies" id="dc-rep-'+c.id+'">'+replies.map(r=>renderDComment(r,kids)).join('')+'</div></div>';
}
function renderDComments(comments){
  const list=$('d-comments-list');
  if(!comments.length){list.innerHTML='<div class="dc-no-comments"><i class="fa-regular fa-comments" style="display:block;font-size:20px;margin-bottom:6px;opacity:.3"></i>Sin comentarios</div>';return;}
  const roots=comments.filter(c=>!c.parent_id);
  const kids={};comments.filter(c=>c.parent_id).forEach(c=>{(kids[c.parent_id]||(kids[c.parent_id]=[])).push(c);});
  list.innerHTML=roots.map(c=>renderDComment(c,kids)).join('');
}
function toggleDReply(id,name){const f=$('dc-rf-'+id);f.classList.toggle('open');if(f.classList.contains('open')){const ta=$('dc-rta-'+id);ta.value='@'+name+' ';ta.focus();}}
async function submitContentComment(){
  const body=$('d-new-comment').value.trim();if(!body||!currentDetailId)return;
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_content_comment',content_id:currentDetailId,parent_id:null,body})});
  const data=await res.json();
  if(data.status==='success'){$('d-new-comment').value='';$('d-mention-dd').style.display='none';appendDComment(data.comment,null);updateCommentCount(currentDetailId,1);}
}
async function submitDReply(parentId){
  const ta=$('dc-rta-'+parentId),body=ta.value.trim();if(!body||!currentDetailId)return;
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_content_comment',content_id:currentDetailId,parent_id:parentId,body})});
  const data=await res.json();
  if(data.status==='success'){ta.value='';$('dc-rf-'+parentId).classList.remove('open');appendDComment(data.comment,parentId);updateCommentCount(currentDetailId,1);}
}
function appendDComment(c,parentId){
  const html=renderDComment(c,{});
  if(parentId){let cont=$('dc-rep-'+parentId);if(!cont){cont=document.createElement('div');cont.className='dc-replies';cont.id='dc-rep-'+parentId;$('dc-'+parentId)?.appendChild(cont);}cont.insertAdjacentHTML('beforeend',html);}
  else{const list=$('d-comments-list');list.querySelector('.dc-no-comments')?.remove();list.insertAdjacentHTML('beforeend',html);list.scrollTop=list.scrollHeight;}
}
async function deleteDComment(id){
  if(!confirm('Eliminar comentario?'))return;
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_content_comment',comment_id:id})});
  if((await res.json()).status==='success')$('dc-'+id)?.remove();
}
function updateCommentCount(id,delta){
  const p=items.find(x=>x.id==id);if(p)p.comment_count=(p.comment_count||0)+delta;
  const cnt=$('cmt-cnt-'+id);if(cnt)cnt.textContent=(parseInt(cnt.textContent)||0)+delta;
  const dc=$('d-cmt-count');if(dc)dc.textContent=(parseInt(dc.textContent)||0)+delta;
}
function handleDMention(ta){
  const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos),match=before.match(/@(\w*)$/);
  const dd=$('d-mention-dd');if(!dd)return;
  if(match){
    const q=match[1].toLowerCase();
    const res=ALL_USERS_CMS.filter(u=>(u.username.toLowerCase().startsWith(q)||(u.display_name||'').toLowerCase().startsWith(q))&&u.id!=ME_ID_CMS).slice(0,5);
    if(res.length){dd.style.display='block';dd.innerHTML=res.map(u=>'<div class="dc-mention-item" data-on-click="insertDMention" data-click-1="s:'+escA(u.username)+'" data-click-2="s:d-new-comment" data-click-3="s:d-mention-dd">'+dcAv(u,20)+'<span style="font-size:12px;font-weight:600">'+escH(u.display_name||u.username)+'</span></div>').join('');return;}
  }
  dd.style.display='none';
}
function insertDMention(username,taId,ddId){
  const ta=$(taId),dd=$(ddId);if(!ta)return;
  const val=ta.value,pos=ta.selectionStart,before=val.slice(0,pos),atPos=before.lastIndexOf('@');
  ta.value=val.slice(0,atPos)+'@'+username+' '+val.slice(pos);ta.focus();const np=atPos+username.length+2;ta.setSelectionRange(np,np);
  if(dd)dd.style.display='none';
}

// ── COLLABORATORS ─────────────────────────────────────────
function openCollabModal(ctx){collabModalContext=ctx;$('collab-modal').style.display='flex';renderCollabList();}
function closeCollabModal(){$('collab-modal').style.display='none';}
function getActiveCollabs(){
  if(collabModalContext==='detail')return(items.find(x=>x.id==currentDetailId)?.collaborators||[]).map(u=>u.id);
  return formCollabs.map(u=>u.id);
}
function renderCollabList(){
  const active=getActiveCollabs();
  $('collab-user-list').innerHTML=ALL_USERS_CMS.map(u=>{
    const sel=active.includes(u.id);
    return '<div data-on-click="toggleCollab" data-click-1="j:'+escA(JSON.stringify(u.id))+'" style="display:flex;align-items:center;gap:10px;padding:11px 16px;cursor:pointer;border-bottom:1px solid var(--g7-border-2);background:'+(sel?'rgba(var(--g7-accent-rgb),.05)':'transparent')+';transition:background .1s">'+dcAv(u,34)+'<div style="flex:1"><div style="font-size:12px;font-weight:600">'+(u.display_name||u.username)+'</div><div style="font-size:10px;color:var(--g7-text-4)">@'+u.username+'</div></div><div style="width:20px;height:20px;border-radius:50%;border:2px solid '+(sel?'var(--g7-accent)':'var(--g7-border-3)')+';background:'+(sel?'var(--g7-accent)':'transparent')+';display:flex;align-items:center;justify-content:center">'+(sel?'<i class="fa-solid fa-check" style="font-size:9px;color:#000"></i>':'')+'</div></div>';
  }).join('');
}
async function toggleCollab(userId){
  const u=ALL_USERS_CMS.find(x=>x.id===userId);if(!u)return;
  const active=getActiveCollabs(),isActive=active.includes(userId);
  if(collabModalContext==='detail'&&currentDetailId){
    const action=isActive?'remove_collaborator':'add_collaborator';
    const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,content_id:currentDetailId,user_id:userId})});
    if((await res.json()).status==='success'){const p=items.find(x=>x.id==currentDetailId);if(p){if(isActive)p.collaborators=(p.collaborators||[]).filter(c=>c.id!==userId);else p.collaborators=[...(p.collaborators||[]),u];renderDetailCollabs(p.collaborators);}}
  }else{if(isActive)formCollabs=formCollabs.filter(c=>c.id!==userId);else formCollabs=[...formCollabs,u];updateFormPreviews();}
  renderCollabList();
}
function renderDetailCollabs(collabs){
  const inline=$('d-collab-inline');
  if(inline)inline.innerHTML=collabs.map(u=>'<span title="'+(u.display_name||u.username)+'" data-on-click="toggleCollab" data-click-1="j:'+escA(JSON.stringify(u.id))+'" style="display:inline-flex;align-items:center;gap:4px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:20px;padding:2px 7px 2px 3px;font-size:10px;font-weight:600;color:var(--g7-text-2);cursor:pointer">'+dcAv(u,16)+'<span>'+(u.display_name||u.username)+'</span></span>').join('');
  const list=$('d-collab-list'),empty=$('d-collab-empty');if(!list)return;
  list.querySelectorAll('.collab-chip').forEach(e=>e.remove());
  if(!collabs.length){if(empty)empty.style.display='block';return;}
  if(empty)empty.style.display='none';
  collabs.forEach(u=>{const chip=document.createElement('div');chip.className='collab-chip';chip.style.cssText='display:flex;align-items:center;gap:5px;background:#18181b;border:1px solid #27272a;border-radius:20px;padding:3px 8px 3px 4px;cursor:pointer';chip.title='Click para quitar';chip.innerHTML=dcAv(u,20)+'<span style="font-size:11px;font-weight:600">'+(u.display_name||u.username)+'</span>';chip.onclick=()=>toggleCollab(u.id);list.appendChild(chip);});
}
function updateFormPreviews(){
  const wrap=$('form-link-preview'),mname=$('f-monday-task-name').value,chips=[];
  formCollabs.forEach(u=>chips.push('<span style="display:inline-flex;align-items:center;gap:5px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:20px;padding:3px 8px 3px 4px;font-size:11px;font-weight:600">'+dcAv(u,18)+(u.display_name||u.username)+'<span data-on-click="removeFormCollab" data-click-1="j:'+u.id+'" style="cursor:pointer;color:var(--g7-text-4);margin-left:2px">&#10005;</span></span>'));
  if(mname)chips.push('<span style="background:rgba(var(--g7-accent-rgb),.08);border:1px solid rgba(var(--g7-accent-rgb),.2);color:var(--g7-accent);border-radius:6px;padding:4px 10px;font-size:11px;display:inline-flex;align-items:center;gap:5px"><i class="fa-brands fa-square-square" style="font-size:9px"></i>'+mname+'<span data-on-click="clearFormMondayTask" style="cursor:pointer;color:var(--g7-text-4);margin-left:4px">&#10005;</span></span>');
  wrap.innerHTML=chips.join('');wrap.style.display=chips.length?'flex':'none';
}

// ── MONDAY TASK ───────────────────────────────────────────
async function openMondayModal(ctx){
  mondayModalContext=ctx;$('monday-modal-search').value='';
  renderMondayList('',[]);$('monday-modal').style.display='flex';
  const tasks=await loadMondayTasks();renderMondayList('',tasks);
}
function closeMondayModal(){$('monday-modal').style.display='none';}
async function loadMondayTasks(){
  if(mondayCachedTasks)return mondayCachedTasks;
  try{const res=await fetch('/toolz/monday_widget.php?get_tasks=1');const data=await res.json();mondayCachedTasks=data.items||[];}catch(e){mondayCachedTasks=[];}
  return mondayCachedTasks;
}
function renderMondayList(q,tasks){
  const list=$('monday-modal-list');
  if(!tasks.length&&!q){list.innerHTML='<div style="padding:20px;text-align:center;color:var(--g7-text-4);font-size:12px"><i class="fa-solid fa-spinner fa-spin" style="display:block;font-size:20px;margin-bottom:8px"></i>Cargando...</div>';return;}
  const res=q?tasks.filter(t=>t.name.toLowerCase().includes(q.toLowerCase())).slice(0,20):tasks.slice(0,20);
  if(!res.length){list.innerHTML='<div style="padding:20px;text-align:center;color:var(--g7-text-4);font-size:12px">Sin resultados</div>';return;}
  list.innerHTML=res.map(t=>'<div data-on-click="selectMondayTask" data-click-1="s:'+escA(t.id)+'" data-click-2="s:'+escA(t.name)+'" data-click-3="s:'+escA(t.url||'')+'" data-click-4="s:'+escA(t.group||'')+'" style="display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;border-bottom:1px solid var(--g7-border-2);transition:background .1s" data-hov="row"><div style="width:32px;height:32px;border-radius:7px;background:rgba(var(--g7-accent-rgb),.1);display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-brands fa-square-square" style="color:var(--g7-accent);font-size:13px"></i></div><div style="flex:1;min-width:0"><div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+escH(t.name)+'</div>'+(t.group?'<div style="font-size:9px;color:var(--g7-text-4)">'+escH(t.group)+'</div>':'')+'</div><i class="fa-solid fa-chevron-right" style="font-size:10px;color:var(--g7-text-5);flex-shrink:0"></i></div>').join('');
}
async function selectMondayTask(id,name,url,group){
  if(mondayModalContext==='detail'&&currentDetailId){
    const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_monday_task',content_id:currentDetailId,monday_task_id:id,monday_task_name:name,monday_task_url:url})});
    if((await res.json()).status==='success'){const p=items.find(x=>x.id==currentDetailId);if(p){p.monday_task_id=id;p.monday_task_name=name;p.monday_task_url=url;}$('d-monday-name').textContent=name;$('d-monday-link').href=url;$('d-monday-wrap').style.display='';}
  }else{$('f-monday-task-id').value=id;$('f-monday-task-name').value=name;$('f-monday-task-url').value=url;updateFormPreviews();const nf=$('f-name');if(nf&&!nf.value.trim())nf.value=name;}
  closeMondayModal();
}

// ── EDIT MODAL ────────────────────────────────────────────
// ── COMPLICACIONES DEL PEDIDO ─────────────────────────────
const CX_OPTS = PR_DATA.cx;
const cxEsc = t => String(t ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const cxLabel = k => (CX_OPTS.find(o => o.k === k) || {}).l || k;
function cxParse(v){ if (Array.isArray(v)) return v; try { const a = JSON.parse(v || '[]'); return Array.isArray(a) ? a : []; } catch(e) { return []; } }
function cxMount(id){
  const el = $(id); if (!el || el.dataset.ready) return; el.dataset.ready = '1';
  el.innerHTML = `<div class="cx-row"><span class="cx-q">¿Tuviste complicaciones con este pedido?</span><input type="checkbox" class="cx-yes-in" hidden><div class="cx-yn" role="group" aria-label="¿Tuviste complicaciones?"><button type="button" data-v="0" data-on-click="cxYes" data-click-1="s:${id}" data-click-2="false">No</button><button type="button" data-v="1" data-on-click="cxYes" data-click-1="s:${id}" data-click-2="true">Sí</button></div></div>
    <div class="cx-opts cx-off">${CX_OPTS.map(o => `<label class="cx-opt" data-k="${o.k}"><input type="checkbox" value="${o.k}" data-on-change="cxRefresh" data-change-1="s:${id}"><i class="fa-solid fa-check"></i>${cxEsc(o.l)}</label>`).join('')}</div>
    <input type="text" class="cx-note cx-off" maxlength="300" placeholder="Cuenta brevemente qué pasó">`;
}
function cxYes(id, v){ const el = $(id); el.querySelector('.cx-yes-in').checked = v; cxRefresh(id); }
function cxRefresh(id){
  const el = $(id); const yes = el.querySelector('.cx-yes-in').checked;
  el.querySelectorAll('.cx-yn button').forEach(b => b.classList.toggle('on', (b.dataset.v === '1') === yes));
  el.querySelector('.cx-opts').classList.toggle('cx-off', !yes);
  
  el.querySelectorAll('.cx-opt').forEach(l => l.classList.toggle('on', l.querySelector('input').checked));
  const other = el.querySelector('.cx-opt[data-k="otro"] input').checked;
  el.querySelector('.cx-note').classList.toggle('cx-off', !(yes && other));
}
function cxGet(id){
  const el = $(id); if (!el || !el.querySelector('.cx-yes-in').checked) return {yes:false, list:[], note:''};
  const list = [...el.querySelectorAll('.cx-opt input:checked')].map(i => i.value);
  return {yes:true, list, note: list.includes('otro') ? el.querySelector('.cx-note').value.trim() : ''};
}
function cxSet(id, list, note){
  cxMount(id); const el = $(id); if (!el) return; list = cxParse(list);
  el.querySelector('.cx-yes-in').checked = list.length > 0;
  el.querySelectorAll('.cx-opt input').forEach(i => i.checked = list.includes(i.value));
  el.querySelector('.cx-note').value = note || '';
  cxRefresh(id);
}
function cxShowDetail(p){
  const list = cxParse(p.complications);
  $('d-cx-wrap').style.display = list.length ? '' : 'none';
  $('d-cx-list').innerHTML = list.map(k => `<span class="cx-chip">${cxEsc(k === 'otro' && p.complications_note ? 'Otro: ' + p.complications_note : cxLabel(k))}</span>`).join('');
}
cxMount('f-cx'); cxMount('e-cx'); cxRefresh('f-cx'); cxRefresh('e-cx');

function openEditModal(){
  const p=items.find(x=>x.id==currentDetailId);if(!p)return;
  $('e-name').value=p.name||'';$('e-category').value=p.category||'';$('e-media').value=p.media_type||'imagen';
  $('e-start').value=p.start||'';$('e-end').value=p.end||'';$('e-graphics').value=p.graphics||'';lmRender('e-landing',landingList(p.landing));$('e-mailing').value=p.mailing||'';
  if($('e-project'))$('e-project').value=p.project||'';
  if($('e-type'))$('e-type').value=p.type||'promo';
  if($('e-content-type'))$('e-content-type').value=p.content_type||'';
  const isCustom=(p.type==='custom');
  if($('e-resources-wrap'))$('e-resources-wrap').style.display=isCustom?'block':'none';
  if(isCustom)eLoadResources(p.id);
  cxSet('e-cx',p.complications,p.complications_note);
  $('edit-modal').style.display='flex';
}

function eAddResource(name='',url=''){
  const list=$('e-resources-list');
  const div=document.createElement('div');
  div.className='e-res-pair';div.style.cssText='display:flex;gap:6px;align-items:center';
  div.innerHTML=`<input type="text" placeholder="Nombre" class="eres-name" value="${(name||'').replace(/"/g,'&quot;')}" style="flex:1;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:6px 9px;font-size:12px;outline:none"><input type="text" placeholder="URL" class="eres-url" value="${(url||'').replace(/"/g,'&quot;')}" style="flex:2;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:8px;padding:6px 9px;font-size:12px;outline:none"><button type="button" data-on-click="prRemoveClosest" data-click-1="this" data-click-2="s:.e-res-pair" style="background:none;border:none;color:var(--g7-text-4);cursor:pointer;font-size:13px;padding:3px"><i class="fa-solid fa-xmark"></i></button>`;
  list.appendChild(div);
}

async function eLoadResources(cid){
  $('e-resources-list').innerHTML='';
  try{const r=await fetch(PR_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_resources',content_id:cid})});const d=await r.json();(d.resources||[]).forEach(res=>eAddResource(res.name,res.url));if(!d.resources?.length)eAddResource();}catch(e){eAddResource();}
}

function eGetResources(){
  return Array.from(document.querySelectorAll('#e-resources-list .e-res-pair')).map(p=>({name:p.querySelector('.eres-name').value.trim(),url:p.querySelector('.eres-url').value.trim()})).filter(r=>r.name||r.url);
}

function closeEditModal(){$('edit-modal').style.display='none';}
async function saveEdit(){
  const btn=$('e-save-btn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
  const payload={action:'update_content',content_id:currentDetailId,name:$('e-name').value.trim(),category:$('e-category').value.trim(),media_type:$('e-media').value,start_date:$('e-start').value,end_date:$('e-end').value,graphics:$('e-graphics').value.trim(),landing:lmGet('e-landing').join('\n'),mailing:$('e-mailing').value.trim(),project:$('e-project')?$('e-project').value:'',type:$('e-type')?$('e-type').value:undefined,content_type:$('e-content-type')?$('e-content-type').value:'',resources:$('e-resources-wrap')&&$('e-resources-wrap').style.display!=='none'?eGetResources():undefined};
  const ecx=cxGet('e-cx');if(ecx.yes&&!ecx.list.length){alert('Marcaste que hubo complicaciones: elige al menos una.');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Guardar cambios';return;}
  payload.complications=ecx.list;payload.complications_note=ecx.note;
  if(window.pfEditPayload)pfEditPayload(payload);
  const res=await fetch('/toolz/promos/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const data=await res.json();
  if(data.status==='success'){
    const p=items.find(x=>x.id==currentDetailId);
    if(p){p.complications=JSON.stringify(payload.complications);p.complications_note=payload.complications.includes('otro')?payload.complications_note:'';cxShowDetail(p);}
    if(p){p.name=payload.name;p.category=payload.category;p.media_type=payload.media_type;p.start=payload.start_date;p.end=payload.end_date;p.graphics=payload.graphics;p.landing=payload.landing;p.mailing=payload.mailing;}
    $('d-name').textContent=payload.name;$('d-cat').textContent=payload.category;$('d-media').textContent=payload.media_type;
    const sl=(wid,eid,uid,val)=>{$(wid).style.display=val?'':'none';if(val){$(eid).href=val;$(uid).textContent=val;}};
    sl('d-mailing-wrap','d-mailing','d-mailing-u',payload.mailing);sl('d-graphics-wrap','d-graphics','d-graphics-u',payload.graphics);showLandings(payload.landing);
    if(window.pfEditSaved)pfEditSaved(payload);
    renderItems();closeEditModal();
  }
  btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Guardar cambios';btn.disabled=false;
}

// ── EXPORT ────────────────────────────────────────────────
function openExportModal(){$('export-modal').style.display='flex';generateExport(5);document.querySelectorAll('.exp-period-btn')[0].classList.add('active');}
function closeExportModal(){$('export-modal').style.display='none';}
function generateExport(days){
  document.querySelectorAll('.exp-period-btn').forEach(b=>b.classList.toggle('active',b.dataset.days==days));
  $('exp-from').value='';$('exp-to').value='';
  const cutoff=Date.now()-(days*86400000);
  const fl=items.filter(p=>new Date(p.created_at||0).getTime()>=cutoff&&(p.author===CURRENT_USER.username||p.created_by==CURRENT_USER.id));
  const TYPE_ES={promo:'Promo/Torneo',launch:'Lanzamiento',paid:'Paid',social:'Redes Sociales',otro:'Otro'};
  const lines=fl.map(p=>'- ['+( TYPE_ES[p.type]||p.type)+'] '+p.name);
  $('export-text').value=fl.length?('Mi contenido - ultimos '+days+' dias ('+fl.length+'):\n\n'+lines.join('\n')):'Sin contenido tuyo en este periodo.';
  $('export-count').textContent=fl.length+' elementos';
}
function generateExportCustom(){
  const from=$('exp-from').value,to=$('exp-to').value;if(!from&&!to)return;
  document.querySelectorAll('.exp-period-btn').forEach(b=>b.classList.remove('active'));
  const fromTs=from?new Date(from).getTime():0,toTs=to?new Date(to).getTime()+86399999:Date.now();
  const fl=items.filter(p=>{const d=new Date(p.created_at||0).getTime();return d>=fromTs&&d<=toTs&&(p.author===CURRENT_USER.username||p.created_by==CURRENT_USER.id);});
  const TYPE_ES={promo:'Promo/Torneo',launch:'Lanzamiento',paid:'Paid',social:'Redes Sociales',otro:'Otro'};
  const lines=fl.map(p=>'- ['+(TYPE_ES[p.type]||p.type)+'] '+p.name);
  const label=from&&to?from+' a '+to:from?'desde '+from:'hasta '+to;
  $('export-text').value=fl.length?('Mi contenido - '+label+' ('+fl.length+'):\n\n'+lines.join('\n')):'Sin contenido tuyo en este periodo.';
  $('export-count').textContent=fl.length+' elementos';
}
async function copyExport(){
  const ta=$('export-text');try{await navigator.clipboard.writeText(ta.value);}catch(e){ta.select();document.execCommand('copy');}
  const btn=$('copy-export-btn'),orig=btn.innerHTML;btn.innerHTML='<i class="fa-solid fa-check"></i> Copiado!';btn.style.background='rgba(74,222,128,.2)';btn.style.color='#4ade80';
  setTimeout(()=>{btn.innerHTML=orig;btn.style.background='';btn.style.color='';},2000);
}

// ── EVENT DELEGATION ──────────────────────────────────────
document.getElementById('item-container').addEventListener('click',function(e){
  const lb=e.target.closest('.like-btn'),cb=e.target.closest('.cmt-open-btn');
  if(lb){e.stopPropagation();toggleLike(parseInt(lb.dataset.id),lb);return;}
  if(cb){e.stopPropagation();openDetail(parseInt(cb.dataset.id));return;}
});
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){closeDetail();closeEditModal();closeCollabModal();closeMondayModal();closeExportModal();}
});

// ── DETAIL RESOURCES ──────────────────────────────────────
async function loadDetailResources(cid){
  const list=$('d-resources-list');
  list.innerHTML='<div style="font-size:11px;color:var(--g7-text-4)">Cargando...</div>';
  try{
    const r=await fetch(PR_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_resources',content_id:cid})});
    const d=await r.json();
    const resources=d.resources||[];
    if(!resources.length){list.innerHTML='<div style="font-size:11px;color:var(--g7-text-4)">Sin recursos</div>';return;}
    list.innerHTML=resources.map(res=>`
      <a href="${res.url}" target="_blank" rel="noopener" class="panel-link" style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <i class="fa-solid fa-link" style="color:var(--brand);font-size:11px;flex-shrink:0"></i>
        <span style="font-size:12px;font-weight:600;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${res.name||res.url}</span>
        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:10px;color:var(--g7-text-4);flex-shrink:0"></i>
      </a>`).join('');
  }catch(e){list.innerHTML='<div style="font-size:11px;color:#ef4444">Error al cargar</div>';}
}

// Show/hide resources in edit modal when type changes
document.addEventListener('change',e=>{
  if(e.target.id==='e-type'){
    const isCustom=e.target.value==='custom';
    if($('e-resources-wrap'))$('e-resources-wrap').style.display=isCustom?'block':'none';
    if(isCustom&&$('e-resources-list')&&!$('e-resources-list').children.length)eAddResource();
  }
});

// ── LOAD MORE ─────────────────────────────────────────────
async function prLoadMore(){
  if(prLoading || !prHasMore) return;
  prLoading = true;
  const btn = document.getElementById('pr-load-more-btn');
  const spin= document.getElementById('pr-load-spinner');
  if(btn) btn.style.display='none';
  if(spin) spin.style.display='block';

  try {
    const r = await fetch(PR_URL, {method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'paginate', page:prPage})});
    const d = await r.json();
    if(d.ok && d.items?.length){
      // Merge into items array
      d.items.forEach(item => {
        item.projects = Array.isArray(item.projects) ? item.projects : [];
        if(!items.find(x=>x.id==item.id)) items.push(item);
      });
      prPage++;
      prHasMore = d.has_more;
      renderItems(); // re-render with new items
    }
    // Update button
    if(prHasMore){
      const remaining = d.total - (prPage * PR_PER_PAGE);
      const cnt = document.getElementById('pr-load-more-count');
      if(cnt) cnt.textContent = '('+ Math.max(0,remaining) +' restantes)';
      if(btn) btn.style.display='';
    } else {
      const wrap = document.getElementById('pr-load-more-wrap');
      if(wrap) wrap.style.display='none';
    }
  } catch(e){ if(btn) btn.style.display=''; }

  prLoading=false;
  if(spin) spin.style.display='none';
}

// Infinite scroll
const prSentinel = document.getElementById('pr-sentinel');
if(prSentinel){
  new IntersectionObserver(entries=>{
    if(entries[0].isIntersecting && prHasMore && !prLoading) prLoadMore();
  },{rootMargin:'300px'}).observe(prSentinel);
}

// ── CONTENIDO EDITADO ─────────────────────────────────────
let emSelectedId = null;

function openEditedModal(){
  emSelectedId = null;
  document.querySelector('#em-selected-wrap').style.display = 'none';
  document.getElementById('em-search').value = '';
  document.querySelectorAll('input[name="em-type"]').forEach(r=>r.checked=false);
  document.querySelectorAll('#em-type-revision,#em-type-rechazo').forEach(el=>el.style.borderColor='#27272a');
  ['em-qty-graficas','em-qty-videos'].forEach(id=>{const el=document.getElementById(id);if(el) el.value='0';});
  ['em-graphics','em-landing','em-mailing'].forEach(id=>{const el=document.getElementById(id);if(el) el.value='';});
  emClearMailing();
  document.getElementById('em-notes').value='';
  emReasonsType=null; emRenderReasons(null); emShowLinks(null);
  document.getElementById('em-error').style.display='none';
  emRenderItems(items.filter(i=>i.estado!=='reemplazado'));
  document.getElementById('edited-modal').style.display='flex';
  setTimeout(()=>document.getElementById('em-search').focus(),50);
}

function closeEditedModal(){
  document.getElementById('edited-modal').style.display='none';
}

function emRenderItems(list){
  const el = document.getElementById('em-items-list');
  if(!list.length){ el.innerHTML='<div style="padding:12px;font-size:12px;color:var(--g7-text-4);text-align:center">Sin resultados</div>'; return; }
  el.innerHTML = list.slice(0,40).map(p=>`
    <div data-on-click="emSelectItem" data-click-1="j:${p.id}" style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid var(--g7-border-2);cursor:pointer;transition:background .1s" data-hov="em">
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">${esc(p.project||'')}${p.category?' · '+esc(p.category):''}</div>
      </div>
      <span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;background:rgba(var(--g7-accent-rgb),.1);color:var(--g7-accent);flex-shrink:0">${TYPE_LABELS[p.type]||p.type}</span>
    </div>`).join('');
}

function emFilterItems(q){
  const f = items.filter(i=>i.estado!=='reemplazado'&&(!q||(i.name||'').toLowerCase().includes(q.toLowerCase())||(i.project||'').toLowerCase().includes(q.toLowerCase())));
  emRenderItems(f);
}

function emSelectItem(id){
  const p = items.find(x=>x.id==id);
  if(!p) return;
  emSelectedId = id;
  document.getElementById('em-selected-wrap').style.display='flex';
  document.getElementById('em-selected-name').textContent = p.name;
  document.getElementById('em-selected-meta').textContent = (p.project||'Sin proyecto') + (p.category?' · '+p.category:'');
  // Pre-fill quantities from original
  document.getElementById('em-qty-graficas').value = p.qty_graficas||0;
  document.getElementById('em-qty-videos').value   = p.qty_videos||0;
  // Los links del nuevo entregable son los de la tarea original
  emShowLinks(p);
  document.getElementById('em-items-list').innerHTML='';
}

function emClearSelection(){
  emSelectedId=null;
  emShowLinks(null);
  document.getElementById('em-selected-wrap').style.display='none';
  emRenderItems(items.filter(i=>i.estado!=='reemplazado'));
}

function emSelectType(type){
  document.querySelectorAll('input[name="em-type"]').forEach(r=>{r.checked=r.value===type;});
  document.getElementById('em-type-revision').style.borderColor = type==='revision'?'#ff6600':'#27272a';
  document.getElementById('em-type-rechazo').style.borderColor  = type==='rechazo' ?'#ef4444':'#27272a';
  emRenderReasons(type);
}

// ── Motivos de la revisión / del rechazo ──
const EM_REASONS = PR_DATA.emReasons;
let emReasonsType = null;
function emRenderReasons(type){
  const wrap = $('em-reasons');
  if (!type || !EM_REASONS[type]) { wrap.style.display = 'none'; emReasonsType = null; return; }
  if (emReasonsType === type) return;          // mismo tipo: se mantiene lo elegido
  emReasonsType = type;
  $('em-reasons-title').textContent = type === 'revision' ? '¿Qué cambios pidieron? Puedes elegir varios.' : '¿Por qué se rechazó? Puedes elegir varios.';
  $('em-reasons-list').innerHTML = Object.entries(EM_REASONS[type]).map(([k, l]) =>
    `<label class="cx-opt${type === 'rechazo' ? ' rej' : ''}" data-k="${k}"><input type="checkbox" value="${k}" data-on-change="@parentOn"><i class="fa-solid fa-check"></i>${cxEsc(l)}</label>`).join('');
  wrap.style.display = '';
}
function emGetReasons(){ return [...document.querySelectorAll('#em-reasons-list input:checked')].map(i => i.value); }
// ── Varias landings por pieza: se guardan como una URL por línea ──
function landingList(v){ return String(v || '').split(/[\r\n]+/).map(s => s.trim()).filter(Boolean); }
function lmRow(wrap, val){
  const row = document.createElement('div'); row.className = 'lm-row';
  const inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = wrap.dataset.ph || 'URL';
  if (wrap.dataset.style) inp.style.cssText = wrap.dataset.style;
  inp.value = val || '';
  // Pegar varias URLs juntas (una por línea o separadas por espacios) las reparte en filas
  inp.addEventListener('paste', e => {
    const urls = (e.clipboardData.getData('text') || '').split(/\s+/).filter(Boolean);
    if (urls.length < 2) return;
    e.preventDefault(); inp.value = urls.shift();
    let after = row; urls.forEach(u => { const r = lmRow(wrap, u); after.after(r); after = r; });
  });
  const del = document.createElement('button'); del.type = 'button'; del.className = 'lm-del'; del.title = 'Quitar esta landing';
  del.innerHTML = '<i class="fa-solid fa-xmark"></i>';
  del.onclick = () => { if (wrap.querySelectorAll('.lm-row').length > 1) row.remove(); else inp.value = ''; };
  row.append(inp, del);
  return row;
}
function lmRender(id, list){
  const wrap = $(id); if (!wrap) return;
  wrap.innerHTML = '';
  (list && list.length ? list : ['']).forEach(u => wrap.append(lmRow(wrap, u)));
  const add = document.createElement('button'); add.type = 'button'; add.className = 'lm-add';
  add.innerHTML = '<i class="fa-solid fa-plus"></i> Agregar otra landing';
  add.onclick = () => { const r = lmRow(wrap, ''); add.before(r); r.querySelector('input').focus(); };
  wrap.append(add);
}
function lmGet(id){ const wrap = $(id); return wrap ? [...wrap.querySelectorAll('.lm-row input')].map(i => i.value.trim()).filter(Boolean) : []; }
function showLandings(v){
  const wrap = $('d-landing-wrap'), lds = landingList(v);
  wrap.style.display = lds.length ? '' : 'none';
  wrap.innerHTML = lds.map((u, i) => `<a class="panel-link" target="_blank" rel="noopener" href="${cxEsc(u)}"><span class="ll">Landing${lds.length > 1 ? ' ' + (i + 1) : ''}</span>${cxEsc(u)}</a>`).join('');
}
document.addEventListener('DOMContentLoaded', () => { lmRender('f-landing', []); lmRender('e-landing', []); });

function emShowLinks(p){
  const box = $('em-links-info'); if (!box) return;
  const lds = landingList(p && p.landing);
  const rows = [['fa-brands fa-google-drive', 'Drive / Gráficas', p && p.graphics], ...lds.map((u, i) => ['fa-solid fa-globe', 'Landing' + (lds.length > 1 ? ' ' + (i + 1) : ''), u]), ['fa-solid fa-envelope', 'Mailing', p && p.mailing]].filter(r => r[2]);
  if (!p) { box.innerHTML = ''; box.style.display = 'none'; return; }
  box.style.display = '';
  box.innerHTML = '<div class="em-li-head"><i class="fa-solid fa-link"></i> Links de la tarea original</div>' +
    (rows.length ? rows.map(r => `<a class="em-li-row" href="${cxEsc(r[2])}" target="_blank" rel="noopener"><i class="${r[0]}"></i><span class="em-li-k">${r[1]}</span><span class="em-li-v">${cxEsc(r[2])}</span></a>`).join('')
                 : '<div class="em-li-none">La tarea original no tiene links cargados.</div>');
}
function emShowDetail(p){
  const wrap = $('d-em-wrap'); if (!wrap) return;
  const type = p.edit_type; const list = cxParse(p.edit_reasons);
  if (!type || (!list.length && !p.edit_notes)) { wrap.style.display = 'none'; return; }
  wrap.style.display = '';
  $('d-em-title').textContent = type === 'rechazo' ? 'Motivos del rechazo' : 'Cambios pedidos';
  const labels = EM_REASONS[type] || {};
  $('d-em-list').innerHTML = list.map(k => `<span class="cx-chip${type === 'rechazo' ? ' rej' : ''}">${cxEsc(labels[k] || k)}</span>`).join('');
  const n = $('d-em-notes'); n.textContent = p.edit_notes || ''; n.style.display = p.edit_notes ? '' : 'none';
}

let emMailingFile = null;

async function emUploadMailing(input){
  const file = input.files[0]; if(!file) return;
  emMailingFile = file;
  document.getElementById('em-mailing').value = '';
  document.getElementById('em-mailing').placeholder = '(se usará el archivo subido)';
  document.getElementById('em-mailing-fname').textContent = file.name + ' (' + (file.size>1048576?(file.size/1048576).toFixed(1)+' MB':(file.size/1024).toFixed(0)+' KB') + ')';
  const prev = document.getElementById('em-mailing-preview');
  prev.style.display = 'flex';
  input.value = '';
}

function emClearMailing(){
  emMailingFile = null;
  const input = document.getElementById('em-mailing-file');
  if(input) input.value = '';
  const prev = document.getElementById('em-mailing-preview');
  if(prev) prev.style.display = 'none';
  const field = document.getElementById('em-mailing');
  if(field){ field.value = ''; field.placeholder = 'URL o link del mailing...'; }
}

async function saveEditedContent(){
  const err = document.getElementById('em-error');
  err.style.display='none';
  if(!emSelectedId){ err.textContent='Selecciona el contenido original primero'; err.style.display='block'; return; }
  const editType = document.querySelector('input[name="em-type"]:checked')?.value;
  if(!editType){ err.textContent='Elige el tipo de edición (Revisión o Rechazo)'; err.style.display='block'; return; }
  const editReasons = emGetReasons();
  if(!editReasons.length){ err.textContent = editType==='revision' ? 'Elige al menos un cambio pedido' : 'Elige al menos un motivo del rechazo'; err.style.display='block'; return; }

  const btn = document.getElementById('em-save-btn');
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';

  try{
    // If mailing file, upload it first via FormData
    let mailingUrl = document.getElementById('em-mailing')?.value.trim()||'';
    if(emMailingFile){
      const fd = new FormData();
      fd.append('action','upload_mailing'); fd.append('file', emMailingFile);
      try{
        const ur = await fetch(PR_URL, {method:'POST', body:fd});
        const ud = await ur.json();
        if(ud.ok) mailingUrl = ud.url;
      }catch(e){}
    }

    const r = await fetch(PR_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      action: 'save_edited',
      parent_id: emSelectedId,
      edit_type: editType,
      edit_reasons: editReasons,
      qty_graficas:       parseInt(document.getElementById('em-qty-graficas').value)||0,
      qty_videos:         parseInt(document.getElementById('em-qty-videos').value)||0,
      qty_landings:       parseInt(document.getElementById('em-qty-landings').value)||0,
      qty_mailings:       parseInt(document.getElementById('em-qty-mailings').value)||0,
      qty_creativos_paid: parseInt(document.getElementById('em-qty-paid').value)||0,
      qty_assets_rrss:    parseInt(document.getElementById('em-qty-rrss').value)||0,
      qty_assets_mobile:  parseInt(document.getElementById('em-qty-mobile').value)||0,
      tiempo_entrega_dias:parseInt(document.getElementById('em-qty-dias').value)||null,
      graphics: document.getElementById('em-graphics')?.value.trim()||'',
      landing:  document.getElementById('em-landing')?.value.trim()||'',
      mailing:  mailingUrl,
      notes: document.getElementById('em-notes').value.trim(),
    })});
    const d = await r.json();
    if(d.ok){
      // Update original in local items array
      const orig = items.find(x=>x.id==emSelectedId);
      if(orig) orig.estado='reemplazado';
      // Add new item
      if(d.item) items.unshift(d.item);
      renderItems();
      closeEditedModal();
      promoToast('Edición registrada');
    } else {
      err.textContent = d.error||'Error al guardar';
      err.style.display='block';
    }
  }catch(e){ err.textContent='Error de conexión'; err.style.display='block'; }
  btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate"></i> Registrar edición';
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── INIT ──────────────────────────────────────────────────
updateCounters();
renderItems();
document.querySelectorAll('.f-launch,.f-paidso,.f-otro,.f-custom,.f-tarea,.f-mailings').forEach(el=>el.classList.add('hidden'));
const urlSearch=new URLSearchParams(window.location.search).get('search');
if(urlSearch){$('searchInput').value=urlSearch;renderItems();setTimeout(()=>{document.querySelectorAll('.item-card').forEach(c=>{c.style.transition='box-shadow .3s';c.style.boxShadow='0 0 0 2px #ff6600';setTimeout(()=>c.style.boxShadow='',2500);});},300);}

// ── MAILING HTML EDITOR ──────────────────────────────
let meMailingUrl = null;
let mePreviewVisible = true;
let meUpdateTimer = null;

async function openMailingEditor(){
  const p = items.find(x=>x.id==currentDetailId);
  if(!p||!p.mailing) return;
  // Extract path — handle both /toolz/... and https://domain/toolz/...
  const url = new URL(p.mailing, window.location.origin);
  meMailingUrl = url.pathname; // always /toolz/promos/mailings/file.html

  const modal = document.getElementById('mailing-editor-modal');
  modal.style.display = 'flex';
  document.getElementById('me-filename').textContent = p.mailing.split('/').pop();
  document.getElementById('me-status').textContent = 'Cargando...';
  document.getElementById('me-code').value = '';
  document.getElementById('me-preview').srcdoc = '';

  try {
    const r = await fetch(PR_URL, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'load_mailing_html', url: meMailingUrl})});
    const d = await r.json();
    if(d.ok){
      document.getElementById('me-code').value = d.html;
      document.getElementById('me-status').textContent = '';
      meUpdatePreview();
    } else {
      document.getElementById('me-status').textContent = '[!] ' + (d.error||'Error al cargar');
    }
  } catch(e){
    document.getElementById('me-status').textContent = '[!] Error de conexion';
  }
}

function closeMailingEditor(){
  document.getElementById('mailing-editor-modal').style.display = 'none';
  meMailingUrl = null;
}

function meUpdatePreview(){
  clearTimeout(meUpdateTimer);
  meUpdateTimer = setTimeout(()=>{
    const html = document.getElementById('me-code').value;
    const iframe = document.getElementById('me-preview');
    iframe.srcdoc = html;
  }, 300);
}

function meTogglePreview(){
  const pp = document.getElementById('me-preview-pane');
  mePreviewVisible = !mePreviewVisible;
  pp.style.display = mePreviewVisible ? 'flex' : 'none';
}

function meSetWidth(w){
  const iframe = document.getElementById('me-preview');
  iframe.style.width = typeof w === 'number' ? w+'px' : w;
  document.querySelectorAll('.me-wb').forEach(b=>{
    const isActive = b.dataset.w == String(w).replace('%','100');
    b.style.background = isActive ? 'rgba(255,102,0,.1)' : 'none';
    b.style.borderColor = isActive ? 'rgba(255,102,0,.3)' : '#27272a';
    b.style.color = isActive ? '#ff6600' : '#52525b';
  });
}

function meHandleKey(e){
  // Tab = insert 2 spaces
  if(e.key === 'Tab'){
    e.preventDefault();
    const ta = e.target;
    const start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.slice(0,start) + '  ' + ta.value.slice(end);
    ta.selectionStart = ta.selectionEnd = start + 2;
    meUpdatePreview();
  }
  // Ctrl+S = save
  if((e.ctrlKey||e.metaKey) && e.key==='s'){
    e.preventDefault();
    meSave();
  }
}

function meWrap(tag){
  const ta = document.getElementById('me-code');
  const start = ta.selectionStart, end = ta.selectionEnd;
  const sel = ta.value.slice(start, end) || 'texto';
  const tagName = tag.split(' ')[0];
  const wrapped = `<${tag}>${sel}</${tagName}>`;
  ta.value = ta.value.slice(0,start) + wrapped + ta.value.slice(end);
  ta.selectionStart = start + tag.length + 2;
  ta.selectionEnd   = ta.selectionStart + sel.length;
  ta.focus();
  meUpdatePreview();
}

function meFormat(){
  const ta = document.getElementById('me-code');
  let html = ta.value;
  html = html
    .replace(/>\s*</g, '>\n<')
    .replace(/(<\/?(html|head|body|div|table|tr|td|th|p|h[1-6]|ul|ol|li|section|header|footer|main|nav|aside)[^>]*>)/gi, '\n$1\n')
    .split('\n').map(l=>l.trim()).filter(Boolean).join('\n');
  ta.value = html;
  meUpdatePreview();
}

async function meSave(){
  if(!meMailingUrl) return;
  const btn = document.getElementById('me-save-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
  document.getElementById('me-status').textContent = '';

  try {
    const r = await fetch(PR_URL, {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'save_mailing_html', url:meMailingUrl, html:document.getElementById('me-code').value})});
    const d = await r.json();
    if(d.ok){
      document.getElementById('me-status').innerHTML = '<span style="color:#22c55e"><i class="fa-solid fa-check"></i> Guardado ✓</span>';
      setTimeout(()=>{ document.getElementById('me-status').textContent=''; }, 3000);
    } else {
      document.getElementById('me-status').innerHTML = '<span style="color:#ef4444">[!] '+(d.error||'Error')+'</span>';
    }
  } catch(e){
    document.getElementById('me-status').innerHTML = '<span style="color:#ef4444">[!] Error de conexión</span>';
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar';
}

// Ctrl+S anywhere in editor
document.getElementById('mailing-editor-modal')?.addEventListener('keydown', e=>{
  if((e.ctrlKey||e.metaKey)&&e.key==='s'){ e.preventDefault(); meSave(); }
  if(e.key==='Escape') closeMailingEditor();
});


// ── Aviso flotante ──
let _ptT;
function promoToast(msg){
  const el=document.getElementById('promo-toast');
  el.textContent=msg;
  el.style.transform='translateX(-50%) translateY(0)';
  clearTimeout(_ptT);_ptT=setTimeout(()=>{el.style.transform='translateX(-50%) translateY(80px)';},3000);
}

// ── Ayudantes para los eventos declarativos (assets/g7-on.js): antes eran código en línea ──
/** Cierra un modal solo si el clic fue en el fondo (antes un manejador en línea con if(event.target===this)closeX()). */
function prIfSelf(e, el, fn){ if (e.target === el && typeof window[fn] === 'function') window[fn](); }
/** Enter sin Shift en el comentario del detalle: publica. */
function dCommentKey(e){ if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitContentComment(); } }
function mondaySearch(v){ renderMondayList(v, mondayCachedTasks || []); }
function prHide(el){ el.style.display = 'none'; }
function prShowMore(){ prShown += 60; renderItems(true); }
function prRemoveClosest(el, sel){ const x = el.closest(sel); if (x) x.remove(); }
function removeFormCollab(id){ formCollabs = formCollabs.filter(c => c.id != id); updateFormPreviews(); renderCollabList(); }
function clearFormMondayTask(){ ['f-monday-task-id','f-monday-task-name','f-monday-task-url'].forEach(i => { const e = $(i); if (e) e.value = ''; }); updateFormPreviews(); }
/** Barra de "me gusta" y comentarios de la tarjeta: no abre el detalle. Antes cortaba la propagación (event.stopPropagation() en línea), y además
    cortaba la delegación de #item-container y dejaba sin efecto los dos botones; ahora se atienden acá. */
function prCardBar(e){
  e.stopPropagation();
  const lb = e.target.closest('.like-btn'), cb = e.target.closest('.cmt-open-btn');
  if (lb) { toggleLike(parseInt(lb.dataset.id), lb); return; }
  if (cb) openDetail(parseInt(cb.dataset.id));
}
