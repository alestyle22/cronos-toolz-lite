/* assets/planify-page.js — Script de planify.php (Planify), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #pf-cfg, #pf-posts y #pf-projects. */
const PF_URL   = window.location.pathname;
const PF_CFG   = JSON.parse(document.getElementById('pf-cfg').textContent);   // datos de PHP (planify.php)
const PF_PID   = PF_CFG.pid;
const PF_COLOR = PF_CFG.color;
const PF_TODAY = PF_CFG.today;

let posts  = JSON.parse(document.getElementById('pf-posts').textContent);
let projects = JSON.parse(document.getElementById('pf-projects').textContent);
let calView  = 'month';
let calMes   = PF_CFG.mes;
let weekOffset = 0;
let modalRefs  = [];
let dragPostId = null;
let activeEP   = null; // active estado picker post id
let quickOpen  = null; // active quick-add date

const RED_LABELS = {facebook:'Facebook',ig_post:'IG Post',ig_historia:'IG Historia',whatsapp:'WhatsApp',x:'X'};
const RED_ICONS  = {facebook:'fa-brands fa-facebook',ig_post:'fa-brands fa-instagram',ig_historia:'fa-brands fa-instagram',whatsapp:'fa-brands fa-whatsapp',x:'fa-brands fa-x-twitter'};
const ESTADO_COLORS= {urgente:'#ef4444',pendiente_assets:'#f97316',en_diseno:'#eab308',publicado:'#22c55e'};
const ESTADO_LABELS= {urgente:'🔴 Urgente',pendiente_assets:'🟠 Pendiente',en_diseno:'🟡 En Diseño',publicado:'🟢 Publicado'};

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

// ── DETAIL PANEL ──────────────────────────────────────
let detPostId = null;

function isImageUrl(url){
  if(!url) return false;
  return /\.(jpe?g|png|gif|webp|svg|bmp)(\?.*)?$/i.test(url) ||
         /drive\.google\.com\/.*uc\?/i.test(url) ||
         /imgur\.com/i.test(url);
}

function getRefIcon(url){
  if(/pinterest/i.test(url)) return '<i class="fa-brands fa-pinterest" style="color:#e60023"></i>';
  if(/drive\.google/i.test(url)) return '<i class="fa-brands fa-google-drive" style="color:#4ade80"></i>';
  if(/dropbox/i.test(url)) return '<i class="fa-brands fa-dropbox" style="color:#60a5fa"></i>';
  if(/instagram/i.test(url)) return '<i class="fa-brands fa-instagram" style="color:#e1306c"></i>';
  if(/behance/i.test(url)) return '<i class="fa-brands fa-behance" style="color:#60a5fa"></i>';
  if(/figma/i.test(url)) return '<i class="fa-brands fa-figma" style="color:#a78bfa"></i>';
  if(/dreamstime/i.test(url)) return '<i class="fa-solid fa-image" style="color:#f59e0b"></i>';
  return '<i class="fa-solid fa-link" style="color:#60a5fa"></i>';
}

async function openDet(id){
  detPostId = id;
  const p = posts.find(x=>x.id==id);
  if(!p) return;
  document.getElementById('det-title').textContent = p.title || 'Sin título';
  document.getElementById('det-modal').classList.add('open');

  // Meta bar
  const meta = document.getElementById('det-meta');
  const redIcons = {facebook:'fa-brands fa-facebook',ig_post:'fa-brands fa-instagram',ig_historia:'fa-brands fa-instagram',whatsapp:'fa-brands fa-whatsapp',x:'fa-brands fa-x-twitter'};
  meta.innerHTML = `
    <span class="pf-det-estado ${p.estado}">${ESTADO_LABELS[p.estado]||p.estado}</span>
    <span class="pf-det-badge ${p.red_social}"><i class="${redIcons[p.red_social]||'fa-share-nodes'}" style="margin-right:4px"></i>${RED_LABELS[p.red_social]||p.red_social}</span>
    <span style="font-size:10px;color:var(--text3);font-family:'DM Mono',monospace">📅 ${p.fecha_publicacion||'—'}</span>
    <span style="font-size:10px;color:var(--text3);margin-left:auto">Por ${esc(p.display_name||p.username||'')}</span>`;

  // Body
  const body = document.getElementById('det-body');
  let html = '';

  if(p.copy_placa){
    html += `<div class="pf-ds">
      <div class="pf-ds-label"><i class="fa-solid fa-align-left" style="margin-right:5px"></i>Copy de la placa</div>
      <div class="pf-ds-value" style="background:var(--bg3);border-radius:8px;padding:12px 14px;font-style:italic;border-left:3px solid var(--accent)">${esc(p.copy_placa)}</div>
    </div>`;
  }

  if(p.descripcion){
    html += `<div class="pf-ds">
      <div class="pf-ds-label"><i class="fa-solid fa-note-sticky" style="margin-right:5px"></i>Descripción / Indicaciones</div>
      <div class="pf-ds-value">${esc(p.descripcion)}</div>
    </div>`;
  }

  // Refs - load from server
  html += `<div class="pf-ds" id="det-refs-section">
    <div class="pf-ds-label"><i class="fa-solid fa-link" style="margin-right:5px"></i>Referencias</div>
    <div id="det-refs-body"><div style="font-size:11px;color:var(--text3)">Cargando...</div></div>
  </div>`;

  // Asset final
  if(p.asset_final){
    const isImg = isImageUrl(p.asset_final);
    html += `<div class="pf-ds">
      <div class="pf-ds-label"><i class="fa-solid fa-check-circle" style="color:#22c55e;margin-right:5px"></i>Asset Final</div>
      ${isImg ? `
        <div class="pf-det-img-wrap" data-on-click="openLB" data-click-1="s:${p.asset_final}">
          <img src="${p.asset_final}" alt="Asset final" data-on-error="pfHideParent" data-error-1="this">
          <div class="pf-det-img-overlay"><span><i class="fa-solid fa-expand"></i> Ver completo</span></div>
        </div>` : ''}
      <div class="pf-det-asset">
        <i class="fa-solid fa-file-circle-check" style="font-size:22px;color:#22c55e;flex-shrink:0"></i>
        <a href="${p.asset_final}" target="_blank" rel="noopener">${p.asset_final.split('/').pop()||p.asset_final}</a>
        <a href="${p.asset_final}" target="_blank" rel="noopener" style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#22c55e;border-radius:6px;padding:4px 10px;font-size:10px;font-weight:700;flex-shrink:0;display:flex;align-items:center;gap:4px"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir</a>
      </div>
    </div>`;
  } else {
    html += `<div class="pf-ds">
      <div class="pf-ds-label"><i class="fa-solid fa-check-circle" style="color:var(--text3);margin-right:5px"></i>Asset Final</div>
      <div style="font-size:11px;color:var(--text3);padding:8px;background:var(--bg3);border-radius:7px;border:1px dashed var(--border2)">Sin asset cargado aún</div>
    </div>`;
  }

  body.innerHTML = html;

  // Load refs async
  try {
    const r = await fetch(PF_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_post',id})});
    const d = await r.json();
    const refs = d.post?.refs || [];
    const refsBody = document.getElementById('det-refs-body');
    if(!refsBody) return;
    if(!refs.length){ refsBody.innerHTML='<div style="font-size:11px;color:var(--text3)">Sin referencias</div>'; return; }
    refsBody.innerHTML = refs.map(ref => {
      const isImg = isImageUrl(ref.url);
      return `
        ${isImg ? `<div class="pf-det-img-wrap" data-on-click="openLB" data-click-1="s:${ref.url}" style="margin-bottom:8px">
          <img src="${ref.url}" alt="${esc(ref.name||'')}" data-on-error="pfHideParent" data-error-1="this">
          <div class="pf-det-img-overlay"><span><i class="fa-solid fa-expand"></i> Ver completo</span></div>
        </div>` : ''}
        <a href="${ref.url}" target="_blank" rel="noopener" class="pf-det-ref">
          <div class="pf-det-ref-icon">${getRefIcon(ref.url)}</div>
          <div style="flex:1;min-width:0">
            <div class="pf-det-ref-name">${esc(ref.name||ref.url)}</div>
            <div class="pf-det-ref-url">${esc(ref.url)}</div>
          </div>
          <i class="fa-solid fa-arrow-up-right-from-square" style="color:var(--text3);font-size:10px;flex-shrink:0"></i>
        </a>`;
    }).join('');
  } catch(e) {
    const refsBody = document.getElementById('det-refs-body');
    if(refsBody) refsBody.innerHTML = '<div style="font-size:11px;color:var(--text3)">Sin referencias</div>';
  }
}

function closeDet(){
  document.getElementById('det-modal').classList.remove('open');
  detPostId = null;
}

function openLB(src){
  document.getElementById('pf-lb-img').src=src;
  document.getElementById('pf-lightbox').classList.add('open');
}
function closeLB(){
  document.getElementById('pf-lightbox').classList.remove('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLB();}});
document.getElementById('det-modal').addEventListener('click',e=>{if(e.target.id==='det-modal')closeDet();});
document.getElementById('pf-lightbox').addEventListener('click',e=>{if(e.target.id==='pf-lightbox')closeLB();});

// ── CALENDAR NAV ──────────────────────────────────────
function setCalView(v){
  calView=v;
  document.getElementById('view-month').style.display=v==='month'?'':'none';
  document.getElementById('view-week').style.display=v==='week'?'':'none';
  document.getElementById('vt-month').classList.toggle('active',v==='month');
  document.getElementById('vt-week').classList.toggle('active',v==='week');
  updateNavLinks();
  if(v==='month') renderMonth(); else renderWeek();
}

function updateNavLinks(){
  const prevBtn=document.getElementById('nav-prev');
  const nextBtn=document.getElementById('nav-next');
  const label=document.getElementById('period-label');
  if(calView==='month'){
    const dt=new Date(calMes+'-01');
    const prev=new Date(dt.getFullYear(),dt.getMonth()-1,1).toISOString().slice(0,7);
    const next=new Date(dt.getFullYear(),dt.getMonth()+1,1).toISOString().slice(0,7);
    prevBtn.onclick=()=>{calMes=prev;fetchAndRender();};
    nextBtn.onclick=()=>{calMes=next;fetchAndRender();};
    label.textContent=dt.toLocaleDateString('es-AR',{month:'long',year:'numeric'});
  } else {
    prevBtn.onclick=()=>{weekOffset--;renderWeek();};
    nextBtn.onclick=()=>{weekOffset++;renderWeek();};
    const {start,end}=getWeekRange(weekOffset);
    const s=start.toLocaleDateString('es-AR',{day:'numeric',month:'short'});
    const e=end.toLocaleDateString('es-AR',{day:'numeric',month:'short',year:'numeric'});
    label.textContent=s+' – '+e;
    nextBtn.setAttribute('href','#');nextBtn.onclick=e=>{e.preventDefault();weekOffset++;renderWeek();};
  }
}

async function fetchAndRender(){
  const mesDt=new Date(calMes+'-01');
  const mesEnd=new Date(mesDt.getFullYear(),mesDt.getMonth()+1,0).toISOString().slice(0,10);
  const mesStart=calMes+'-01';
  // Simple reload approach
  window.location.href=`?project=${PF_PID}&mes=${calMes}&view=${calView}`;
}

function applyFilters(){
  if(calView==='month') renderMonth(); else renderWeek();
}

function getFilteredPosts(){
  const red=document.getElementById('filter-red')?.value||'';
  const est=document.getElementById('filter-estado')?.value||'';
  return posts.filter(p=>{
    if(red && p.red_social!==red) return false;
    if(est && p.estado!==est) return false;
    return true;
  });
}

function getWeekRange(offset){
  const now=new Date();
  const dow=now.getDay()===0?6:now.getDay()-1; // Mon=0
  const mon=new Date(now); mon.setDate(now.getDate()-dow+offset*7); mon.setHours(0,0,0,0);
  const sun=new Date(mon); sun.setDate(mon.getDate()+6);
  return {start:mon,end:sun};
}

// ── MONTH RENDER ──────────────────────────────────────
function renderMonth(){
  updateNavLinks();
  const grid=document.getElementById('month-grid');
  grid.innerHTML='';
  // Day headers
  ['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'].forEach((d,i)=>{
    const h=document.createElement('div');
    h.className='pf-day-header'+(i>=5?' weekend':'');
    h.textContent=d; grid.appendChild(h);
  });
  const [yr,mo]=calMes.split('-').map(Number);
  const first=new Date(yr,mo-1,1);
  const last=new Date(yr,mo,0);
  const startDow=first.getDay()===0?6:first.getDay()-1;
  const totalCells=startDow+last.getDate();
  const rows=Math.ceil(totalCells/7);
  const filtered=getFilteredPosts();

  for(let cell=0;cell<rows*7;cell++){
    const dayNum=cell-startDow+1;
    const isValid=dayNum>=1&&dayNum<=last.getDate();
    const date=isValid?`${yr}-${String(mo).padStart(2,'0')}-${String(dayNum).padStart(2,'0')}`:null;
    const isToday=date===PF_TODAY;
    const isWeekend=cell%7>=5;
    const dayPosts=date?filtered.filter(p=>p.fecha_publicacion===date):[];
    const div=document.createElement('div');
    div.className='pf-month-day'+(isToday?' today':'')+(isWeekend?' weekend':'')+(isValid?'':' other-month');
    if(date){
      div.onclick=e=>{if(e.target===div||e.target.classList.contains('pf-day-num')||e.target.classList.contains('pf-day-more'))openQuick(date,div);}
      setupDrop(div,date);
    }
    let html=`<span class="pf-day-num${isWeekend?' weekend-num':''}">${isValid?dayNum:''}</span>`;
    const maxShow=3;
    dayPosts.slice(0,maxShow).forEach(p=>{
      const icon=RED_ICONS[p.red_social]||'fa-share-nodes';
      html+=`<div class="pf-mini-card ${p.estado}" draggable="true" data-id="${p.id}" data-on-click="openDet|@stop" data-click-1="j:${p.id}" data-on-dragstart="startDrag" data-dragstart-1="event" data-dragstart-2="j:${p.id}">
        <i class="${icon}" style="font-size:8px;flex-shrink:0"></i>${esc(p.title||'Sin título')}
      </div>`;
    });
    if(dayPosts.length>maxShow) html+=`<div class="pf-day-more" data-on-click="@stop">+${dayPosts.length-maxShow} más</div>`;
    // Quick add form for this day
    if(date) html+=`<div class="pf-quick" id="qa-${date}" data-on-click="@stop">
      <input class="pf-qi" id="qi-title-${date}" placeholder="Título..." data-on-keydown="pfQiKey" data-keydown-1="event" data-keydown-2="s:${date}">
      <div class="pf-qi-row">
        <select class="pf-qi-sel" id="qi-red-${date}">
          <option value="ig_post">📸 IG Post</option><option value="ig_historia">📱 Historia</option>
          <option value="facebook">📘 Facebook</option><option value="whatsapp">💬 WhatsApp</option><option value="x">🐦 X</option>
        </select>
        <div class="pf-qi-estados">
          ${Object.entries({urgente:'🔴',pendiente_assets:'🟠',en_diseno:'🟡',publicado:'🟢'}).map(([k,e])=>
            `<button type="button" class="pf-qi-e ${k}" data-d="${date}" data-e="${k}" data-on-click="selQI" data-click-1="s:${date}" data-click-2="s:${k}">${e}</button>`
          ).join('')}
        </div>
      </div>
      <div class="pf-qi-btns">
        <button class="pf-qi-cancel" data-on-click="closeQuick" data-click-1="s:${date}">✕</button>
        <button class="pf-qi-save" data-on-click="quickSave" data-click-1="s:${date}"><i class="fa-solid fa-plus"></i> Crear</button>
      </div>
    </div>`;
    div.innerHTML=html;
    grid.appendChild(div);
  }
}

// ── WEEK RENDER ───────────────────────────────────────
function renderWeek(){
  updateNavLinks();
  const grid=document.getElementById('week-grid');
  grid.innerHTML='';
  const {start}=getWeekRange(weekOffset);
  const filtered=getFilteredPosts();
  const DAYS=['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'];
  for(let d=0;d<7;d++){
    const dt=new Date(start);dt.setDate(start.getDate()+d);
    const dateStr=dt.toISOString().slice(0,10);
    const isToday=dateStr===PF_TODAY;
    const isWeekend=d>=5;
    const dayPosts=filtered.filter(p=>p.fecha_publicacion===dateStr);
    const row=document.createElement('div');
    row.className='pf-week-row';
    if(isWeekend) row.style.background='rgba(255,255,255,.01)';
    const label=document.createElement('div');
    label.className='pf-week-day-label';
    label.style.cssText=isToday?'background:rgba(255,102,0,.06)':'';
    label.innerHTML=`<div class="pf-week-day-name" style="${isWeekend?'opacity:.5':''}">${DAYS[d]}</div><div class="pf-week-day-num${isToday?' today-num':''}">${dt.getDate()}</div>`;
    const cards=document.createElement('div');
    cards.className='pf-week-cards';
    cards.onclick=e=>{if(e.target===cards)openQuickWeek(dateStr,cards);};
    setupDrop(cards,dateStr);
    // Cards
    dayPosts.forEach(p=>cards.appendChild(buildWeekCard(p)));
    // Quick add
    const qa=document.createElement('div');
    qa.className='pf-quick';qa.id=`qa-w-${dateStr}`;
    qa.onclick=e=>e.stopPropagation();
    qa.innerHTML=`
      <input class="pf-qi" id="qi-wt-${dateStr}" placeholder="Título del post..." data-on-keydown="pfQiwKey" data-keydown-1="event" data-keydown-2="s:${dateStr}">
      <div class="pf-qi-row">
        <select class="pf-qi-sel" id="qi-wr-${dateStr}">
          <option value="ig_post">📸 IG Post</option><option value="ig_historia">📱 Historia</option>
          <option value="facebook">📘 Facebook</option><option value="whatsapp">💬 WhatsApp</option><option value="x">🐦 X</option>
        </select>
        <div class="pf-qi-estados">
          ${Object.entries({urgente:'🔴',pendiente_assets:'🟠',en_diseno:'🟡',publicado:'🟢'}).map(([k,e])=>
            `<button type="button" class="pf-qi-e ${k}" data-wd="${dateStr}" data-we="${k}" data-on-click="selQIW" data-click-1="s:${dateStr}" data-click-2="s:${k}">${e}</button>`
          ).join('')}
        </div>
      </div>
      <div class="pf-qi-btns">
        <button class="pf-qi-cancel" data-on-click="closeQuickWeek" data-click-1="s:${dateStr}">✕</button>
        <button class="pf-qi-save" data-on-click="quickSaveWeek" data-click-1="s:${dateStr}"><i class="fa-solid fa-plus"></i> Crear</button>
      </div>`;
    cards.appendChild(qa);
    row.appendChild(label);row.appendChild(cards);
    grid.appendChild(row);
  }
}

function buildWeekCard(p){
  const hasAsset=!!p.asset_final;
  const hasRefs=parseInt(p.refs_count||0)>0;
  const div=document.createElement('div');
  div.className=`pf-week-card ${p.estado}`;
  div.draggable=true;
  div.dataset.id=p.id;
  div.ondragstart=e=>startDrag(e,p.id);
  div.innerHTML=`
    <div data-on-click="handleWCClick" data-click-1="event" data-click-2="j:${p.id}">
      <div class="pf-week-card-top">
        <span class="pf-wc-red ${p.red_social}"><i class="${RED_ICONS[p.red_social]||'fa-share-nodes'}" style="margin-right:3px;font-size:9px"></i>${RED_LABELS[p.red_social]}</span>
      </div>
      <div class="pf-wc-title">${esc(p.title||'Sin título')}</div>
      ${p.copy_placa?`<div class="pf-wc-copy">${esc(p.copy_placa.slice(0,60))}</div>`:''}
      <div class="pf-wc-foot">
        <span class="pf-wc-estado ${p.estado}" data-on-click="toggleEP" data-click-1="event" data-click-2="j:${p.id}" style="position:relative">
          ${ESTADO_LABELS[p.estado]||p.estado}
          <div class="pf-ep" id="ep-${p.id}">
            ${Object.entries(ESTADO_LABELS).map(([k,v])=>`
              <button class="pf-ep-opt" data-on-click="changeEstado" data-click-1="event" data-click-2="j:${p.id}" data-click-3="s:${k}">
                <span class="ep-dot" style="background:${ESTADO_COLORS[k]}"></span>${v}
              </button>`).join('')}
          </div>
        </span>
        <div class="pf-wc-chips" style="margin-left:auto">
          ${hasAsset?`<span class="pf-wc-chip ok" title="Asset listo">✓</span>`:''}
          ${hasRefs?`<span class="pf-wc-chip" title="Tiene referencias"><i class="fa-solid fa-link" style="font-size:7px"></i></span>`:''}
        </div>
      </div>
    </div>`;
  return div;
}

function handleWCClick(e,id){
  if(e.target.closest('.pf-wc-estado')||e.target.closest('.pf-ep')) return;
  openDet(id);
}

// ── ESTADO PICKER ─────────────────────────────────────
function toggleEP(e,id){
  e.stopPropagation();
  const ep=document.getElementById(`ep-${id}`);
  if(!ep) return;
  const isOpen=ep.classList.contains('open');
  closeAllEP();
  if(!isOpen) ep.classList.add('open');
}

function closeAllEP(){
  document.querySelectorAll('.pf-ep.open').forEach(el=>el.classList.remove('open'));
}

async function changeEstado(e,id,estado){
  e.stopPropagation();
  closeAllEP();
  const p=posts.find(x=>x.id==id);
  if(p) p.estado=estado;
  if(calView==='month') renderMonth(); else renderWeek();
  await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_estado',id,estado})});
}

document.addEventListener('click',e=>{
  if(!e.target.closest('.pf-wc-estado')&&!e.target.closest('.pf-ep')) closeAllEP();
});

// ── Antes código en línea (CSP estricta, SEC-24) ──
function pfGo(url){ window.location = url; }
function pfEditDet(){ openModal(detPostId); }
function pfHideParent(el){ el.parentElement.style.display = 'none'; }
/** Alta rápida: Enter guarda, Escape cierra (vista mes y vista semana). */
function pfQiKey(e, date){ if (e.key === 'Enter') quickSave(date); if (e.key === 'Escape') closeQuick(date); }
function pfQiwKey(e, date){ if (e.key === 'Enter') quickSaveWeek(date); if (e.key === 'Escape') closeQuickWeek(date); }

// ── QUICK ADD ─────────────────────────────────────────
const qiEstados={}, qiWEstados={};

function openQuick(date,dayEl){
  if(quickOpen===date){closeQuick(date);return;}
  if(quickOpen) closeQuick(quickOpen);
  quickOpen=date;
  const qa=document.getElementById(`qa-${date}`);
  if(!qa) return;
  qa.classList.add('open');
  selQI(date,'pendiente_assets');
  setTimeout(()=>document.getElementById(`qi-title-${date}`)?.focus(),50);
}
function closeQuick(date){
  document.getElementById(`qa-${date}`)?.classList.remove('open');
  if(quickOpen===date) quickOpen=null;
}
function selQI(date,estado){
  qiEstados[date]=estado;
  document.querySelectorAll(`.pf-qi-e[data-d="${date}"]`).forEach(b=>b.classList.toggle('sel',b.dataset.e===estado));
}
async function quickSave(date){
  const title=document.getElementById(`qi-title-${date}`)?.value.trim();
  if(!title) return;
  const payload={action:'save_post',id:0,project_id:PF_PID,title,red_social:document.getElementById(`qi-red-${date}`)?.value||'ig_post',copy_placa:'',descripcion:'',asset_final:'',estado:qiEstados[date]||'pendiente_assets',fecha:date,refs:[]};
  const r=await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok){posts.unshift(d.post);closeQuick(date);renderMonth();}
}

function openQuickWeek(date,container){
  if(quickOpen===`w-${date}`){closeQuickWeek(date);return;}
  if(quickOpen) { const old=quickOpen; if(old.startsWith('w-'))closeQuickWeek(old.slice(2));else closeQuick(old); }
  quickOpen=`w-${date}`;
  const qa=document.getElementById(`qa-w-${date}`);
  if(!qa) return;
  qa.classList.add('open');
  selQIW(date,'pendiente_assets');
  setTimeout(()=>document.getElementById(`qi-wt-${date}`)?.focus(),50);
}
function closeQuickWeek(date){
  document.getElementById(`qa-w-${date}`)?.classList.remove('open');
  if(quickOpen===`w-${date}`) quickOpen=null;
}
function selQIW(date,estado){
  qiWEstados[date]=estado;
  document.querySelectorAll(`.pf-qi-e[data-wd="${date}"]`).forEach(b=>b.classList.toggle('sel',b.dataset.we===estado));
}
async function quickSaveWeek(date){
  const title=document.getElementById(`qi-wt-${date}`)?.value.trim();
  if(!title) return;
  const payload={action:'save_post',id:0,project_id:PF_PID,title,red_social:document.getElementById(`qi-wr-${date}`)?.value||'ig_post',copy_placa:'',descripcion:'',asset_final:'',estado:qiWEstados[date]||'pendiente_assets',fecha:date,refs:[]};
  const r=await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok){posts.unshift(d.post);closeQuickWeek(date);renderWeek();}
}

// ── DRAG & DROP ───────────────────────────────────────
function startDrag(e,id){
  dragPostId=id;
  e.dataTransfer.effectAllowed='move';
  e.dataTransfer.setData('text/plain',id);
  setTimeout(()=>e.target.style.opacity='.4',0);
}

function setupDrop(el,date){
  el.addEventListener('dragover',e=>{e.preventDefault();e.dataTransfer.dropEffect='move';el.classList.add('drag-over');});
  el.addEventListener('dragleave',()=>el.classList.remove('drag-over'));
  el.addEventListener('drop',e=>{
    e.preventDefault();el.classList.remove('drag-over');
    const id=parseInt(e.dataTransfer.getData('text/plain')||dragPostId);
    if(!id) return;
    const p=posts.find(x=>x.id==id);
    if(p&&p.fecha_publicacion!==date){
      p.fecha_publicacion=date;
      if(calView==='month') renderMonth(); else renderWeek();
      fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'move_post',id,fecha:date})});
    }
    dragPostId=null;
    document.querySelectorAll('[style*="opacity: 0.4"],[style*="opacity:.4"]').forEach(el=>el.style.opacity='');
  });
}

// ── MODAL ─────────────────────────────────────────────
function openModal(id=null,defaultDate=null){
  closeDet();
  document.getElementById('post-modal').classList.add('open');
  if(id){
    const p=posts.find(x=>x.id==id);
    if(!p) return;
    document.getElementById('post-modal-title').textContent='Editar post';
    document.getElementById('m-id').value=p.id;
    document.getElementById('m-title').value=p.title||'';
    document.getElementById('m-red').value=p.red_social||'ig_post';
    document.getElementById('m-copy').value=p.copy_placa||'';
    document.getElementById('m-desc').value=p.descripcion||'';
    document.getElementById('m-fecha').value=p.fecha_publicacion||'';
    selEstado(p.estado||'pendiente_assets');
    if(p.asset_final) setAsset(p.asset_final); else clearAsset();
    modalRefs=[];
    fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_post',id})})
    .then(r=>r.json()).then(d=>{if(d.ok){modalRefs=(d.post.refs||[]).map(r=>({url:r.url,name:r.name,type:r.type}));renderRefs();}});
    document.getElementById('m-del-btn').style.display='';
  } else {
    document.getElementById('post-modal-title').textContent='Nuevo post';
    document.getElementById('m-id').value='';
    document.getElementById('m-title').value='';
    document.getElementById('m-red').value='ig_post';
    document.getElementById('m-copy').value='';
    document.getElementById('m-desc').value='';
    document.getElementById('m-fecha').value=defaultDate||PF_TODAY;
    selEstado('pendiente_assets');
    clearAsset();modalRefs=[];renderRefs();
    document.getElementById('m-del-btn').style.display='none';
  }
}
function closeModal(){document.getElementById('post-modal').classList.remove('open');}
function selEstado(v){
  document.getElementById('m-estado').value=v;
  document.querySelectorAll('.pf-eg-btn').forEach(b=>b.classList.toggle('sel',b.classList.contains(v)));
}
function renderRefs(){
  const list=document.getElementById('m-refs');list.innerHTML='';
  modalRefs.forEach((r,i)=>{
    const d=document.createElement('div');d.className='pf-ref-row';
    d.innerHTML=`<i class="fa-solid ${r.type==='file'?'fa-file':'fa-link'}" style="color:#60a5fa;font-size:10px;flex-shrink:0"></i><a href="${r.url}" target="_blank">${esc(r.name||r.url)}</a><button class="pf-ref-rm" data-on-click="removeRef" data-click-1="j:${i}"><i class="fa-solid fa-xmark"></i></button>`;
    list.appendChild(d);
  });
}
function addRef(){
  const url=document.getElementById('m-ref-url').value.trim();
  const name=document.getElementById('m-ref-name').value.trim();
  if(!url) return;
  modalRefs.push({url,name:name||url,type:'link'});
  renderRefs();
  document.getElementById('m-ref-url').value='';
  document.getElementById('m-ref-name').value='';
}
function removeRef(i){modalRefs.splice(i,1);renderRefs();}
async function uploadRef(input){
  const file=input.files[0];if(!file)return;
  const fd=new FormData();fd.append('upload_type','ref');fd.append('upload_file',file);
  const r=await fetch(PF_URL,{method:'POST',body:fd});const d=await r.json();
  if(d.ok){modalRefs.push({url:d.url,name:d.name||file.name,type:'file'});renderRefs();}
  input.value='';
}
function setAsset(url){
  document.getElementById('m-asset-val').value=url;
  document.getElementById('m-asset-link').href=url;
  document.getElementById('m-asset-link').textContent=url.split('/').pop()||url;
  document.getElementById('m-asset-preview').style.display='flex';
  document.getElementById('m-asset-empty').style.display='none';
}
function clearAsset(){
  document.getElementById('m-asset-val').value='';
  document.getElementById('m-asset-url').value='';
  document.getElementById('m-asset-preview').style.display='none';
  document.getElementById('m-asset-empty').style.display='flex';
}
async function uploadAsset(input){
  const file=input.files[0];if(!file)return;
  const fd=new FormData();fd.append('upload_type','asset');fd.append('upload_file',file);
  const r=await fetch(PF_URL,{method:'POST',body:fd});const d=await r.json();
  if(d.ok) setAsset(d.url);
  input.value='';
}
async function savePost(){
  const btn=document.getElementById('m-save-btn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  const assetUrl=document.getElementById('m-asset-url').value.trim();
  if(assetUrl&&!document.getElementById('m-asset-val').value) setAsset(assetUrl);
  const payload={
    action:'save_post',
    id:parseInt(document.getElementById('m-id').value)||0,
    project_id:PF_PID,
    title:document.getElementById('m-title').value.trim(),
    red_social:document.getElementById('m-red').value,
    copy_placa:document.getElementById('m-copy').value,
    descripcion:document.getElementById('m-desc').value,
    asset_final:document.getElementById('m-asset-val').value,
    estado:document.getElementById('m-estado').value,
    fecha:document.getElementById('m-fecha').value,
    refs:modalRefs,
  };
  if(!payload.title){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Guardar';document.getElementById('m-title').focus();return;}
  const r=await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok){
    const idx=posts.findIndex(x=>x.id==d.post.id);
    if(idx>=0) posts[idx]=d.post; else posts.unshift(d.post);
    if(calView==='month') renderMonth(); else renderWeek();
    closeModal();
  }
  btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Guardar';
}
async function deletePost(){
  const id=parseInt(document.getElementById('m-id').value);
  if(!id||!confirm('¿Eliminar este post?')) return;
  await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_post',id})});
  posts=posts.filter(p=>p.id!==id);
  if(calView==='month') renderMonth(); else renderWeek();
  closeModal();
}

// ── PROJECT MODAL ─────────────────────────────────────
function openProjectModal(p=null){
  document.getElementById('proj-id').value=p?.id||'';
  document.getElementById('proj-modal-title').textContent=p?'Editar proyecto':'Nuevo proyecto';
  document.getElementById('proj-name').value=p?.name||'';
  document.getElementById('proj-desc').value=p?.description||'';
  selectProjColor(p?.color||'#ff6600');
  document.getElementById('proj-delete-btn').style.display=p?'':'none';
  document.getElementById('proj-modal').classList.add('open');
  setTimeout(()=>document.getElementById('proj-name').focus(),50);
}
function closeProjectModal(){document.getElementById('proj-modal').classList.remove('open');}
function selectProjColor(c){
  document.getElementById('proj-color').value=c;
  document.querySelectorAll('.pf-color-swatch').forEach(s=>s.classList.toggle('sel',s.dataset.color===c));
}
async function saveProject(){
  const name=document.getElementById('proj-name').value.trim();
  if(!name) return;
  const payload={action:'save_project',id:parseInt(document.getElementById('proj-id').value)||0,name,description:document.getElementById('proj-desc').value.trim(),color:document.getElementById('proj-color').value};
  const r=await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok) window.location.reload();
}
async function deleteProject(){
  const id=parseInt(document.getElementById('proj-id').value);
  if(!id||!confirm('¿Eliminar este proyecto y todos sus posts?')) return;
  await fetch(PF_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_project',id})});
  window.location.href='?';
}

// Close modals on background click / ESC
['post-modal','proj-modal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',e=>{if(e.target.id===id) e.target.classList.remove('open');});
});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeModal();closeProjectModal();closeAllEP();}});

// ── INIT ─────────────────────────────────────────────
if (PF_CFG.hasProject) setCalView('week');
