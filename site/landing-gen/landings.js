/* landing-gen/landings.js — Lista y creador de landings (antes en línea en index.php; CSP estricta, SEC-24 fase 2). Datos de PHP en #lg-data. */
const API = location.pathname;
let curPlat = null, curLandingId = null, generatedHTML = '';
let allLandings = [], curView = 'list', curStatusFilter = 'all';
const IS_ADMIN = JSON.parse(document.getElementById('lg-data').textContent).isAdmin;

document.querySelectorAll('.plat-card').forEach(el => {
  el.addEventListener('click', () => {
    curPlat = JSON.parse(el.dataset.plat);
    goStep(2);
  });
});

function goStep(n, mode) {
  [1,2,3].forEach(i => {
    document.getElementById('step-'+i).style.display = i===n ? '' : 'none';
    const si = document.getElementById('si-'+i);
    si.className = 'step-item' + (i===n?' active':i<n?' done':'');
  });
  updateBreadcrumb(n);
  if(n===2) loadLandings();
  if(n===3) initCreator(mode);
}
function s1click(){ if(document.getElementById('si-1').classList.contains('done')) goStep(1); }

function updateBreadcrumb(n){
  const bc = document.getElementById('lg-breadcrumb');
  if(!bc) return;
  if(n===1||!curPlat){ bc.innerHTML=''; return; }
  let h = `<span class="sep">&#8250;</span><span>${esc(curPlat.name)}</span>`;
  if(n===3) h += `<span class="sep">&#8250;</span><span>Crear</span>`;
  bc.innerHTML = h;
}

async function loadLandings(){
  if(!curPlat) return;
  // restore view preference
  curView = localStorage.getItem('lg-view') || 'list';
  document.getElementById('fv-list')?.classList.toggle('active', curView==='list');
  document.getElementById('fv-grid')?.classList.toggle('active', curView==='grid');

  document.getElementById('list-plat-name').textContent = curPlat.name;
  document.getElementById('list-meta').textContent = 'Cargando...';
  document.getElementById('filter-bar').style.display = 'none';
  document.getElementById('land-list-wrap').innerHTML = '<div class="empty-st"><div class="empty-st-icon"><i class="fa-solid fa-spinner fa-spin"></i></div><h3>Cargando...</h3></div>';

  const r = await post({action:'list', platform:curPlat.id});
  allLandings = r.landings || [];

  // reset filters
  const si = document.getElementById('filter-search');
  if(si) si.value = '';
  curStatusFilter = 'all';
  document.querySelectorAll('.fst-btn').forEach(b => b.classList.toggle('active', b.textContent.trim()==='Todos'));
  if(document.getElementById('filter-sort')) document.getElementById('filter-sort').value = 'newest';
  if(document.getElementById('filter-month')) document.getElementById('filter-month').value = '';
  if(document.getElementById('filter-autor')) document.getElementById('filter-autor').value = '';

  if(allLandings.length > 0){
    document.getElementById('filter-bar').style.display = 'flex';
    populateFilters();
  }
  filterLandings();
}

function populateFilters(){
  // Month filter — unique YYYY-MM values, newest first
  const months = [...new Set(allLandings.map(l => {
    const d = new Date(l.created_at);
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
  }))].sort((a,b) => b.localeCompare(a));
  const mSel = document.getElementById('filter-month');
  mSel.innerHTML = '<option value="">Todos los meses</option>';
  months.forEach(m => {
    const [y,mo] = m.split('-');
    const label = new Date(y, mo-1, 1).toLocaleDateString('es-AR',{month:'long',year:'numeric'});
    const opt = document.createElement('option');
    opt.value = m;
    opt.textContent = label.charAt(0).toUpperCase()+label.slice(1);
    mSel.appendChild(opt);
  });
  mSel.style.display = months.length > 1 ? '' : 'none';

  // Author filter — unique created_by values
  const authors = [...new Set(allLandings.map(l => l.created_by).filter(Boolean))].sort();
  const aSel = document.getElementById('filter-autor');
  aSel.innerHTML = '<option value="">Todos los autores</option>';
  authors.forEach(a => {
    const opt = document.createElement('option');
    opt.value = a;
    opt.textContent = a;
    aSel.appendChild(opt);
  });
  aSel.style.display = authors.length > 1 ? '' : 'none';
}

function filterLandings(){
  const q = (document.getElementById('filter-search')?.value || '').toLowerCase().trim();
  const sort = document.getElementById('filter-sort')?.value || 'newest';
  const monthFilter = document.getElementById('filter-month')?.value || '';
  const autorFilter = document.getElementById('filter-autor')?.value || '';
  let filtered = allLandings.filter(l => {
    const matchName = !q || l.name.toLowerCase().includes(q);
    const matchSt = curStatusFilter === 'all'
      || (curStatusFilter === 'published' && l.public_url)
      || (curStatusFilter === 'draft' && !l.public_url);
    const matchMonth = !monthFilter || (()=>{
      const d = new Date(l.created_at);
      return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0') === monthFilter;
    })();
    const matchAutor = !autorFilter || l.created_by === autorFilter;
    return matchName && matchSt && matchMonth && matchAutor;
  });
  if(sort === 'oldest') filtered.sort((a,b) => new Date(a.created_at)-new Date(b.created_at));
  else if(sort === 'az') filtered.sort((a,b) => a.name.localeCompare(b.name,'es'));
  else filtered.sort((a,b) => new Date(b.created_at)-new Date(a.created_at));

  const total = allLandings.length, shown = filtered.length;
  document.getElementById('list-meta').textContent = shown < total
    ? `${shown} de ${total} landing${total!==1?'s':''}`
    : `${total} landing${total!==1?'s':''}`;

  renderLandings(filtered);
}

function setStatusFilter(btn, status){
  curStatusFilter = status;
  document.querySelectorAll('.fst-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterLandings();
}

function setView(mode){
  curView = mode;
  localStorage.setItem('lg-view', mode);
  document.getElementById('fv-list').classList.toggle('active', mode==='list');
  document.getElementById('fv-grid').classList.toggle('active', mode==='grid');
  filterLandings();
}

function renderLandings(lands){
  const wrap = document.getElementById('land-list-wrap');
  if(!lands.length){
    const noResults = allLandings.length > 0;
    wrap.innerHTML = noResults
      ? `<div class="empty-st"><div class="empty-st-icon"><i class="fa-solid fa-filter"></i></div><h3>Sin resultados</h3><p>Prueba con otros filtros o busca otro nombre</p></div>`
      : `<div class="empty-st"><div class="empty-st-icon"><i class="fa-regular fa-file-code"></i></div><h3>Todavia no hay landings</h3><p>Crea la primera landing para ${esc(curPlat.name)}</p><button class="btn-primary" style="margin-top:8px" data-on-click="goStep" data-click-1="n:3" data-click-2="s:new"><i class="fa-solid fa-plus"></i> Crear primera landing</button></div>`;
    return;
  }
  if(curView === 'grid') renderGridView(lands, wrap);
  else renderListView(lands, wrap);
}

function editingBadge(l){
  if(!l.editing_by||!l.editing_since) return '';
  const age=(Date.now()-new Date(l.editing_since+'Z').getTime())/1000;
  if(age>120) return '';
  return `<span class="land-editing-badge"><i class="fa-solid fa-pen"></i> ${esc(l.editing_by)}</span>`;
}

function renderListView(lands, wrap){
  const list = document.createElement('div');
  list.className = 'land-list';
  lands.forEach(l => {
    const d = new Date(l.created_at).toLocaleDateString('es-AR',{day:'2-digit',month:'short',year:'numeric'});
    const isPub = !!l.public_url;
    const el = document.createElement('div');
    el.className = 'land-card';
    el.style.setProperty('--pc', curPlat.color);
    el.innerHTML = `
      <div class="land-stripe"></div>
      <div class="land-body">
        <div class="land-info">
          <div class="land-nm">${esc(l.name)}${kindTag(l)} ${editingBadge(l)}</div>
          <div class="land-meta">
            ${isPub?'<span class="land-st-dot"><i class="fa-solid fa-circle"></i>Publicado</span>':''}
            Por ${esc(l.created_by)} &middot; ${d}
          </div>
        </div>
        <div class="land-actions">
          ${isPub&&parseInt(l.view_count||0)>0?`<span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.08);border:1px solid rgba(var(--g7-accent-rgb),.2);border-radius:6px;padding:4px 8px" title="Vistas totales"><i class="fa-solid fa-eye" style="font-size:9px"></i> ${parseInt(l.view_count).toLocaleString()}</span>`:''}
          <button class="land-btn" data-on-click="previewLanding" data-click-1="j:${l.id}"><i class="fa-solid fa-eye"></i> Ver</button>
          ${editBtn(l)}
          ${isPub?`<button class="land-btn" style="border-color:rgba(167,139,250,.3);color:#a78bfa" data-on-click="lgGo" data-click-1="s:stats.php?id=${l.id}" title="Ver estadísticas"><i class="fa-solid fa-chart-mixed"></i></button>`:''}
          ${isPub?`<button class="land-btn" style="border-color:rgba(74,222,128,.3);color:#4ade80" data-puburl="${esc(l.public_url)}" data-on-click="lgCopyPubUrl" data-click-1="this" title="Copiar URL publicada"><i class="fa-solid fa-link"></i></button>`:''}
          ${l.kind==='bundle'?`<a class="land-btn" href="${esc(l.public_url)}" target="_blank" rel="noopener" title="Abrir en una pestaña nueva"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`:`<button class="land-btn" data-on-click="copyLanding" data-click-1="j:${l.id}" title="Copiar HTML"><i class="fa-solid fa-copy"></i></button>`}
          ${l.kind==='bundle'?'':`<button class="land-btn" data-on-click="dlLanding" data-click-1="j:${l.id}" data-click-2="s:${esc(l.name).replace(/"/g,'&quot;')}" title="Descargar"><i class="fa-solid fa-download"></i></button>`}
          ${IS_ADMIN?`<button class="land-btn del" data-on-click="delLanding" data-click-1="j:${l.id}"><i class="fa-solid fa-trash"></i></button>`:''}
        </div>
      </div>`;
    list.appendChild(el);
  });
  wrap.innerHTML = '';
  wrap.appendChild(list);
}

function renderGridView(lands, wrap){
  const grid = document.createElement('div');
  grid.className = 'land-grid';
  lands.forEach(l => {
    const d = new Date(l.created_at).toLocaleDateString('es-AR',{day:'2-digit',month:'short',year:'numeric'});
    const isPub = !!l.public_url;
    const heroImg = l.hero_img && l.hero_img !== 'null' ? l.hero_img : '';
    const el = document.createElement('div');
    el.className = 'land-gc';
    el.style.setProperty('--pc', curPlat.color);
    el.innerHTML = `
      <div class="land-gc-head${heroImg?' has-hero':''}">
        ${heroImg
          ? `<img class="land-gc-hero" src="${esc(heroImg)}" alt="" loading="lazy">
             <div class="land-gc-head-ov"></div>`
          : `<div class="land-gc-icon"><i class="fa-solid ${curPlat.icon}"></i></div>`
        }
        ${editingBadge(l)}
      </div>
      <div class="land-gc-body">
        <div class="land-gc-nm">${esc(l.name)}${kindTag(l)}</div>
        <div class="land-gc-meta">Por ${esc(l.created_by)} &middot; ${d}</div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px">
          <span class="land-gc-status ${isPub?'pub':'draft'}">
            <i class="fa-solid fa-circle" style="font-size:4px"></i>
            ${isPub?'Publicado':'Borrador'}
          </span>
          ${isPub&&parseInt(l.view_count||0)>0?`<span style="display:inline-flex;align-items:center;gap:3px;font-size:8px;font-weight:700;color:var(--g7-accent);background:rgba(var(--g7-accent-rgb),.08);border:1px solid rgba(var(--g7-accent-rgb),.2);border-radius:999px;padding:2px 7px"><i class="fa-solid fa-eye" style="font-size:7px"></i> ${parseInt(l.view_count).toLocaleString()}</span>`:''}
        </div>
      </div>
      <div class="land-gc-foot">
        <button class="land-btn" data-on-click="previewLanding" data-click-1="j:${l.id}"><i class="fa-solid fa-eye"></i> Ver</button>
        ${editBtn(l)}
        ${isPub?`<button class="land-btn" style="border-color:rgba(167,139,250,.3);color:#a78bfa" data-on-click="lgGo" data-click-1="s:stats.php?id=${l.id}" title="Estadísticas"><i class="fa-solid fa-chart-mixed"></i></button>`:''}
        ${isPub?`<button class="land-btn" style="border-color:rgba(74,222,128,.3);color:#4ade80" data-puburl="${esc(l.public_url)}" data-on-click="lgCopyPubUrl" data-click-1="this" title="Copiar URL publicada"><i class="fa-solid fa-link"></i></button>`:''}
        ${l.kind==='bundle'?`<a class="land-btn" href="${esc(l.public_url)}" target="_blank" rel="noopener" title="Abrir en una pestaña nueva"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>`:`<button class="land-btn" data-on-click="copyLanding" data-click-1="j:${l.id}" title="Copiar HTML"><i class="fa-solid fa-copy"></i></button>`}
        ${l.kind==='bundle'?'':`<button class="land-btn" data-on-click="dlLanding" data-click-1="j:${l.id}" data-click-2="s:${esc(l.name).replace(/"/g,'&quot;')}" title="Descargar"><i class="fa-solid fa-download"></i></button>`}
        ${IS_ADMIN?`<button class="land-btn del" data-on-click="delLanding" data-click-1="j:${l.id}"><i class="fa-solid fa-trash"></i></button>`:''}
      </div>`;
    grid.appendChild(el);
  });
  wrap.innerHTML = '';
  wrap.appendChild(grid);
}

async function previewLanding(id){
  // Las subidas se ven desde su URL: sus imágenes son relativas a la carpeta publicada
  const l = allLandings.find(x => +x.id === +id);
  if(l && l.kind==='bundle' && l.public_url){ openModal('', l.name, l.public_url, l.public_url); return; }
  const r = await post({action:'get', id});
  if(r.landing) openModal(r.landing.html_content, r.landing.name, r.landing.public_url||'');
}
function kindTag(l){ return l.kind==='bundle' ? '<span class="land-kind" title="Landing subida tal cual (carpeta o ZIP)"><i class="fa-solid fa-box-open"></i> Subida</span>' : ''; }
function editBtn(l){
  return l.kind==='bundle'
    ? `<button class="land-btn edit" data-on-click="openBundle" data-click-1="j:${l.id}" title="Subir una versión nueva"><i class="fa-solid fa-arrows-rotate"></i> Reemplazar</button>`
    : `<button class="land-btn edit" data-on-click="lgGo" data-click-1="s:editor.php?id=${l.id}"><i class="fa-solid fa-pen-ruler"></i> Editar</button>`;
}
async function copyLanding(id){
  const r = await post({action:'get', id});
  if(r.landing?.html_content){ await navigator.clipboard.writeText(r.landing.html_content); toast('HTML copiado','ok'); }
}
async function dlLanding(id, name){
  const r = await post({action:'get', id});
  if(r.landing?.html_content) triggerDl(r.landing.html_content, name+'.html');
}
async function delLanding(id){
  if(!confirm('Eliminar esta landing?')) return;
  const r = await post({action:'delete', id});
  if(r.ok){ toast('Landing eliminada','ok'); loadLandings(); }
  else toast(r.error||'Error','err');
}

function openModal(html, name, pubUrl='', src=''){
  const ov = document.createElement('div');
  ov.className = 'pv-modal';
  const urlBtn = pubUrl
    ? `<button class="pv-btn" style="border-color:rgba(74,222,128,.3);color:#4ade80" data-puburl="${esc(pubUrl)}" data-on-click="lgCopyPubUrl" data-click-1="this"><i class="fa-solid fa-link"></i> Copiar URL</button>`
    : '';
  ov.innerHTML = `
    <div class="pv-modal-bar">
      <span class="pv-modal-nm">${esc(name)}</span>
      <div style="display:flex;gap:8px">
        ${urlBtn}
        ${src?'':`<button class="pv-btn" data-on-click="lgCopyModalHtml" data-click-1="this"><i class="fa-solid fa-copy"></i> Copiar HTML</button>`}
        <button class="pv-modal-close" data-on-click="lgCloseModal" data-click-1="this">✕ Cerrar</button>
      </div>
    </div>
    <iframe class="pv-modal-iframe" id="pv-mf"></iframe>`;
  ov.dataset.html = html;
  document.body.appendChild(ov);
  const fr = ov.querySelector('#pv-mf');
  if(src) fr.src = src; else lgFrameShow(fr, html);   // aislada en frame.php: con la CSP estricta un blob: heredaría la política y no correrían sus scripts
  ov.addEventListener('click', e=>{ if(e.target===ov) ov.remove(); });
}

function initCreator(mode){
  curLandingId = null; generatedHTML = '';
  document.getElementById('creator-title').textContent = 'Nueva landing';
  document.getElementById('creator-sub').textContent = curPlat?.name || '';
  // El ejemplo de lo que conviene pegar sale de la plataforma (paquete de marcas); si no trae, el neutro del textarea
  const ta = document.getElementById('raw-content');
  ta.dataset.ph0 = ta.dataset.ph0 || ta.placeholder;
  ta.placeholder = curPlat?.ejemplo || ta.dataset.ph0;
  if(mode==='new'){
    document.getElementById('raw-content').value = '';
    document.getElementById('landing-name').value = '';
    updateCharCount();
  }
  setPrevState(false);
}

function setPrevState(on){
  document.getElementById('prev-empty').style.display = on ? 'none' : 'flex';
  document.getElementById('preview-iframe').style.display = on ? 'block' : 'none';
  document.getElementById('prev-actions').style.display = on ? 'flex' : 'none';
  const dot = document.getElementById('prev-dot');
  if(dot){ dot.className = 'fa-solid fa-circle prev-dot '+(on?'live':'idle'); }
}

function updateCharCount(){
  const v = document.getElementById('raw-content').value;
  const el = document.getElementById('char-count');
  if(el) el.textContent = v.length ? v.length+' car.' : '';
}

let aiTimer;
const AI_MSGS = ['Analizando contenido...','Identificando beneficios...','Armando la estructura...','Generando HTML...','Aplicando estilos...','Revisando T&C...','Finalizando la landing...'];
function startLoading(){
  let i=0;
  const el=document.getElementById('ld-msg');
  el.textContent=AI_MSGS[0];
  aiTimer=setInterval(()=>{
    i=(i+1)%AI_MSGS.length;
    el.classList.add('fade');
    setTimeout(()=>{el.textContent=AI_MSGS[i];el.classList.remove('fade');},180);
  },1400);
  document.getElementById('gen-loading').classList.add('on');
}
function stopLoading(){
  clearInterval(aiTimer);
  document.getElementById('gen-loading').classList.remove('on');
}

async function generateAI(){
  const raw = document.getElementById('raw-content').value.trim();
  if(!raw){ toast('Escribe el contenido de la promo primero','err'); return; }
  const btn = document.getElementById('gen-btn');
  btn.disabled = true;
  startLoading();
  // Si el pedido se corta (hosting, conexión, sesión vencida) la carga termina igual y se avisa: antes quedaba girando para siempre
  let r;
  try { r = await post({action:'generate_ai', content:raw, platform:curPlat?.id||''}); }
  catch(e){ r = {error: e.status===401||e.login ? 'Tu sesión venció. Vuelve a entrar y prueba de nuevo.' : 'La generación se cortó antes de terminar (tardó demasiado o se perdió la conexión). Prueba de nuevo; si se repite, acorta el texto de la promo.'}; }
  finally { btn.disabled = false; stopLoading(); }
  if(r.error){ toast(r.error,'err'); return; }
  if(!r.html){ toast('La IA no devolvió la landing. Prueba de nuevo.','err'); return; }
  generatedHTML = r.html;
  renderPreview(generatedHTML);
  toast('Landing generada!','ok');
}

function renderPreview(html){
  const iframe = document.getElementById('preview-iframe');
  lgFrameShow(iframe, html);   // aislada en frame.php (ver frame-host.js)
  iframe.style.height = '600px';
  setPrevState(true);
}

function setDevice(d){
  const fr = document.getElementById('preview-iframe');
  document.getElementById('dev-desk').className='dev-btn'+(d==='desktop'?' on':'');
  document.getElementById('dev-mob').className='dev-btn'+(d==='mobile'?' on':'');
  if(d==='mobile'){ fr.style.width='375px'; fr.style.margin='0 auto'; fr.style.display='block'; }
  else{ fr.style.width='100%'; fr.style.margin=''; }
}

function copyHTML(){ if(generatedHTML){ navigator.clipboard.writeText(generatedHTML); toast('HTML copiado','ok'); }}
function dlHTML(){
  if(!generatedHTML) return;
  const nm=(document.getElementById('landing-name').value||'landing').replace(/[^a-z0-9\s-]/gi,'')+'.html';
  triggerDl(generatedHTML,nm);
}

async function saveLanding(){
  const name=document.getElementById('landing-name').value.trim();
  if(!name){ toast('Pone un nombre a la landing primero','err'); document.getElementById('landing-name').focus(); return null; }
  if(!generatedHTML){ toast('Primero genera el HTML con IA','err'); return null; }
  const raw=document.getElementById('raw-content').value;
  const r=await post({action:'save',platform:curPlat.id,name,content_raw:raw,html_content:generatedHTML,id:curLandingId||0});
  if(r.ok){ curLandingId=r.id; toast('Landing guardada','ok'); }
  else toast(r.error||'Error al guardar','err');
  return r;
}

async function openInEditor(){
  const name=document.getElementById('landing-name').value.trim();
  if(!name){ toast('Pone un nombre antes de abrir el editor','err'); document.getElementById('landing-name').focus(); return; }
  if(!generatedHTML){ toast('Primero genera el HTML con IA','err'); return; }
  const btn=document.getElementById('open-editor-btn');
  btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
  const r=await saveLanding();
  btn.disabled=false;
  btn.innerHTML='<i class="fa-solid fa-pen-ruler"></i> Editor';
  if(r?.ok&&r.id) window.location.href='editor.php?id='+r.id;
  else toast('No se pudo guardar','err');
}

async function post(data){
  const r=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  const t=await r.text();   // generate_ai manda espacios de latido antes del JSON
  try { return JSON.parse(t); }
  catch(e){ const err=new Error('respuesta inválida'); err.status=r.status; err.login=r.redirected&&/login\.php/.test(r.url); throw err; }
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function triggerDl(html, filename){
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([html],{type:'text/html'}));
  a.download=filename; a.click();
}
let toastT;
function toast(msg, type){
  const el=document.getElementById('lg-toast');
  el.textContent=msg;
  el.className='lg-toast show '+(type||'');
  clearTimeout(toastT);
  toastT=setTimeout(()=>el.classList.remove('show'),3000);
}

// Mostrar aviso si redirigido desde editor por lock
(function(){
  const p = new URLSearchParams(location.search);
  const by = p.get('locked_by');
  if(by){ toast('Esta landing la está editando ' + by + ' ahora mismo', 'err'); history.replaceState(null,'',location.pathname+location.search.replace(/[?&]locked_by=[^&]*/,'')); }
})();

// ── PEGAR HTML DIRECTO ──────────────────────────────────────
function openPasteModal(){
  document.getElementById('paste-modal').style.display='flex';
  setTimeout(()=>document.getElementById('paste-html-input').focus(),50);
}
function closePasteModal(){
  document.getElementById('paste-modal').style.display='none';
  document.getElementById('paste-html-input').value='';
}
function applyPastedHTML(){
  const html = document.getElementById('paste-html-input').value.trim();
  if(!html){ toast('Pega el HTML primero','err'); return; }
  generatedHTML = html;
  renderPreview(html);
  closePasteModal();
  toast('HTML cargado en preview','ok');
}
document.getElementById('paste-modal').addEventListener('click', function(e){
  if(e.target===this) closePasteModal();
});

// ── SUBIR LANDING ARMADA (carpeta o ZIP) ────────────────────
// Se arma la lista en el navegador (un ZIP se abre con JSZip), se deja afuera lo que la página no usa
// y se sube en tandas que respetan los límites del servidor; al final bundle_commit la publica.
const LB_CODE = /\.(html?|css|js|mjs|json)$/i;
const LB_SEG = /^[\p{L}\p{N}_\-. ()@+,]+$/u;
let LB = { id: 0, files: [], entry: '', skipped: [], busy: false, limits: null };

function lbFmt(b){ return b >= 1048576 ? (b/1048576).toFixed(1).replace('.',',')+' MB' : Math.max(1, Math.round(b/1024))+' KB'; }
function lbExt(p){ return (p.split('.').pop() || '').toLowerCase(); }
function lbErr(msg){ const e = document.getElementById('lb-err'); e.textContent = msg || ''; e.hidden = !msg; }

function openBundle(id = 0){
  if(!curPlat) return;
  const row = id ? allLandings.find(x => +x.id === +id) : null;
  LB = { id: row ? +row.id : 0, files: [], entry: '', skipped: [], busy: false, limits: LB.limits };
  document.getElementById('lb-title-t').textContent = row ? 'Reemplazar archivos' : 'Subir landing';
  document.getElementById('lb-sub').textContent = row
    ? 'Sube la versión nueva. Se publica en la misma dirección y conserva las visitas.'
    : 'Una landing ya armada para ' + curPlat.name + ': su index.html con las imágenes, estilos y fuentes que use.';
  document.getElementById('lb-name').value = row ? row.name : '';
  document.getElementById('lb-sum').hidden = true;
  document.getElementById('lb-progw').hidden = true;
  document.getElementById('lb-dir').value = ''; document.getElementById('lb-zip').value = '';
  lbErr(''); lbSync();
  document.getElementById('lb-dlg').showModal();
  setTimeout(() => document.getElementById('lb-name').focus(), 30);
  if(!LB.limits) post({action:'bundle_limits'}).then(r => { if(r.ok) LB.limits = r; }).catch(()=>{});
}
function closeBundle(){ if(LB.busy) return; document.getElementById('lb-dlg').close(); }
document.getElementById('lb-dlg').addEventListener('cancel', e => { if(LB.busy) e.preventDefault(); });
function lbSync(){ document.getElementById('lb-go').disabled = LB.busy || !LB.entry || !document.getElementById('lb-name').value.trim(); }
document.getElementById('lb-name').addEventListener('input', lbSync);

document.getElementById('lb-dir').addEventListener('change', e => {
  lbTake([...e.target.files].map(f => ({ path: f.webkitRelativePath || f.name, blob: f })));
});
document.getElementById('lb-zip').addEventListener('change', e => { if(e.target.files[0]) lbFromZip(e.target.files[0]); });

// Arrastrar: un ZIP, una carpeta o varios archivos sueltos
const lbDrop = document.getElementById('lb-drop');
['dragenter','dragover'].forEach(t => lbDrop.addEventListener(t, e => { e.preventDefault(); lbDrop.classList.add('over'); }));
['dragleave','drop'].forEach(t => lbDrop.addEventListener(t, e => { e.preventDefault(); lbDrop.classList.remove('over'); }));
lbDrop.addEventListener('drop', async e => {
  const items = [...(e.dataTransfer.items || [])].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
  const files = [...e.dataTransfer.files];
  if(files.length === 1 && /\.zip$/i.test(files[0].name) && !(items[0] && items[0].isDirectory)) return lbFromZip(files[0]);
  if(!items.length) return lbTake(files.map(f => ({ path: f.name, blob: f })));
  const out = [];
  const walk = (ent, pre) => new Promise(res => {
    if(ent.isFile) { ent.file(f => { out.push({ path: pre + f.name, blob: f }); res(); }, () => res()); return; }
    const rd = ent.createReader(), all = [];
    const more = () => rd.readEntries(async es => {
      if(es.length){ all.push(...es); more(); return; }
      for(const x of all) await walk(x, pre + ent.name + '/');
      res();
    }, () => res());
    more();
  });
  for(const it of items) await walk(it, '');
  lbTake(out);
});

async function lbFromZip(file){
  lbErr('');
  try {
    if(!window.JSZip) await new Promise((ok, ko) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';
      s.onload = ok; s.onerror = () => ko(new Error('No se pudo cargar el lector de ZIP'));
      document.head.appendChild(s);
    });
    const zip = await JSZip.loadAsync(file), list = [];
    for(const z of Object.values(zip.files)) if(!z.dir) list.push({ path: z.name, blob: await z.async('blob') });
    if(!document.getElementById('lb-name').value.trim()) document.getElementById('lb-name').value = file.name.replace(/\.zip$/i, '').replace(/[-_]+/g, ' ');
    lbTake(list);
  } catch(err){ lbErr('No se pudo abrir el ZIP: ' + err.message); }
}

// Arma la lista final: página principal, raíz en su carpeta y solo lo que se usa
async function lbTake(list){
  lbErr('');
  list = list.map(f => ({ ...f, path: f.path.replace(/\\/g, '/').replace(/^\/+/, '') }))
             .filter(f => !f.path.split('/').some(s => s.startsWith('.') || s === '__MACOSX') && !/(^|\/)(thumbs\.db|desktop\.ini)$/i.test(f.path));
  const htmls = list.filter(f => /\.html?$/i.test(f.path));
  if(!htmls.length){
    LB.entry = ''; lbSync(); document.getElementById('lb-sum').hidden = true;
    return lbErr('No encontré ningún archivo .html. La landing tiene que tener su index.html.');
  }
  const depth = p => p.split('/').length, isIdx = p => /(^|\/)index\.html?$/i.test(p) ? 1 : 0;
  htmls.sort((a, b) => depth(a.path) - depth(b.path) || isIdx(b.path) - isIdx(a.path));
  const entryFull = htmls[0].path, root = entryFull.includes('/') ? entryFull.slice(0, entryFull.lastIndexOf('/') + 1) : '';
  const inRoot = list.filter(f => f.path.startsWith(root)).map(f => ({ ...f, path: f.path.slice(root.length) }));
  const entry = entryFull.slice(root.length);
  if(!document.getElementById('lb-name').value.trim() && root) document.getElementById('lb-name').value = root.replace(/\/$/, '').split('/').pop().replace(/[-_]+/g, ' ');

  const allowed = (LB.limits && LB.limits.ext) || ['html','htm','css','js','mjs','json','txt','map','jpg','jpeg','png','gif','webp','avif','svg','ico','woff','woff2','ttf','otf','eot','mp4','webm','mp3','ogg','wav'];
  const skipped = [], cand = [];
  for(const f of inRoot){
    if(allowed.includes(lbExt(f.path))) cand.push(f);
    else skipped.push({ path: f.path, size: f.blob.size, why: 'tipo no permitido' });
  }
  // Se usan: la página principal y todo lo que se nombre desde un archivo de código que se use
  const used = new Set([entry]), texts = new Map();
  const textOf = async f => { if(!texts.has(f.path)) texts.set(f.path, await f.blob.text()); return texts.get(f.path); };
  let changed = true;
  while(changed){
    changed = false;
    const hay = (await Promise.all(cand.filter(f => used.has(f.path) && LB_CODE.test(f.path)).map(textOf))).join('\n');
    for(const f of cand){
      if(used.has(f.path)) continue;
      const base = f.path.split('/').pop();
      if(hay.includes(base) || hay.includes(encodeURIComponent(base)) || hay.includes(base.replace(/ /g, '%20'))){ used.add(f.path); changed = true; }
    }
  }
  const files = cand.filter(f => used.has(f.path));
  cand.filter(f => !used.has(f.path)).forEach(f => skipped.push({ path: f.path, size: f.blob.size, why: 'no se usa' }));
  const bad = files.filter(f => !f.path.split('/').every(s => LB_SEG.test(s)));
  LB.files = files; LB.entry = entry; LB.skipped = skipped;
  lbSummary(entryFull);
  if(bad.length){ LB.entry = ''; lbErr('Estos nombres tienen caracteres que no se pueden publicar (usa letras, números, guiones o espacios): ' + bad.map(f => f.path).join(', ')); }
  lbSync();
}

function lbSummary(entryFull){
  const tot = LB.files.reduce((a, f) => a + f.blob.size, 0), sk = LB.skipped.reduce((a, f) => a + f.size, 0);
  const li = arr => arr.map(f => `<li><span>${esc(f.path)}</span><span>${f.why ? esc(f.why) + ' · ' : ''}${lbFmt(f.size != null ? f.size : f.blob.size)}</span></li>`).join('');
  const n = LB.files.length, m = LB.skipped.length;
  const box = document.getElementById('lb-sum');
  box.innerHTML = `
    <div class="lb-sum-row ok"><i class="fa-solid fa-circle-check"></i><span>Página principal: <b>${esc(entryFull)}</b></span></div>
    <div class="lb-sum-row"><i class="fa-regular fa-file"></i><span><b>${n}</b> archivo${n !== 1 ? 's' : ''} a publicar · <b>${lbFmt(tot)}</b></span></div>
    <details><summary>Ver archivos</summary><ul>${li(LB.files)}</ul></details>
    ${m ? `<div class="lb-sum-row"><i class="fa-solid fa-filter"></i><span>${m} archivo${m !== 1 ? 's' : ''} quedan afuera (${lbFmt(sk)})</span></div><details><summary>Ver cuáles</summary><ul>${li(LB.skipped)}</ul></details>` : ''}`;
  box.hidden = false;
}

async function uploadBundle(){
  const name = document.getElementById('lb-name').value.trim();
  if(!name){ document.getElementById('lb-name').focus(); return lbErr('Pon un nombre.'); }
  if(!LB.entry || LB.busy) return;
  lbErr('');
  if(!LB.limits){ const r = await post({action:'bundle_limits'}).catch(() => ({})); if(r.ok) LB.limits = r; }
  const lim = LB.limits || { post: 8 << 20, file: 2 << 20, count: 20, maxFiles: 300, maxBytes: 80 << 20 };
  const total = LB.files.reduce((a, f) => a + f.blob.size, 0);
  const big = LB.files.find(f => f.blob.size > lim.file);
  if(big) return lbErr(`${big.path} pesa ${lbFmt(big.blob.size)} y el servidor acepta hasta ${lbFmt(lim.file)} por archivo. Achicala o pasala a .jpg/.webp.`);
  if(LB.files.length > lim.maxFiles || total > lim.maxBytes) return lbErr(`La landing supera el máximo de ${lim.maxFiles} archivos u ${lbFmt(lim.maxBytes)}.`);

  // Tandas: pocos archivos por pedido y lejos del tope del servidor
  const room = Math.max(256 << 10, Math.floor(lim.post * 0.8)), per = Math.max(1, Math.min(lim.count - 1, 15));
  const batches = []; let cur = [], sz = 0;
  for(const f of LB.files){
    if(cur.length && (cur.length >= per || sz + f.blob.size > room)){ batches.push(cur); cur = []; sz = 0; }
    cur.push(f); sz += f.blob.size;
  }
  if(cur.length) batches.push(cur);

  const token = [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');
  LB.busy = true; lbSync();
  const bar = document.getElementById('lb-prog'), msg = document.getElementById('lb-pmsg');
  document.getElementById('lb-progw').hidden = false;
  let done = 0;
  try {
    for(const b of batches){
      const fd = new FormData();
      fd.append('action', 'bundle_put'); fd.append('token', token);
      b.forEach(f => { fd.append('paths[]', f.path); fd.append('files[]', f.blob, f.path.split('/').pop()); });
      msg.textContent = `Subiendo ${done + b.length} de ${LB.files.length} archivos…`;
      const res = await fetch(API, { method: 'POST', body: fd });
      const r = await res.json().catch(() => ({ error: 'El servidor rechazó la tanda (' + res.status + '). Puede que sea demasiado grande.' }));
      if(!r.ok) throw new Error(r.error || 'Error al subir');
      done += b.length;
      bar.style.width = Math.round(done / LB.files.length * 90) + '%';
    }
    msg.textContent = 'Publicando…';
    const r = await post({ action: 'bundle_commit', token, name, platform: curPlat.id, id: LB.id || 0, entry: LB.entry });
    if(!r.ok) throw new Error(r.error || 'No se pudo publicar');
    bar.style.width = '100%';
    LB.busy = false;
    document.getElementById('lb-dlg').close();
    toast(LB.id ? 'Versión nueva publicada' : 'Landing publicada — URL copiada', 'ok');
    if(r.url && navigator.clipboard) navigator.clipboard.writeText(r.url).catch(() => {});
    loadLandings();
  } catch(err){
    LB.busy = false; lbSync();
    lbErr(err.message);
    msg.textContent = '';
    bar.style.width = '0';
    post({ action: 'bundle_abort', token }).catch(() => {});
  }
}

// ── Eventos que antes estaban en línea (assets/g7-on.js los llama por nombre) ──
function lgGo(url){ window.location = url; }
function lgCopyPubUrl(el){ navigator.clipboard.writeText(el.dataset.puburl).then(()=>toast('URL copiada','ok')).catch(()=>toast('Error al copiar','err')); }
function lgCopyModalHtml(el){ navigator.clipboard.writeText(el.closest('.pv-modal').dataset.html||''); toast('Copiado','ok'); }
function lgCloseModal(el){ el.closest('.pv-modal').remove(); }
