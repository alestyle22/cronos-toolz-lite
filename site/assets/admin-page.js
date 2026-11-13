/* assets/admin-page.js — Script principal de admin.php (antes en línea). CSP estricta, SEC-24 fase 2. */
const ADM_URL = '/toolz/admin.php';
function $(id){ return document.getElementById(id); }

// ── MONDAY ────────────────────────────────────────────────
function admToggleMonToken(){ const inp=$('mon-token'); const eye=$('mon-token-eye'); inp.type=inp.type==='password'?'text':'password'; eye.className=inp.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash'; }
async function admSaveMonday(){
  const r=await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_monday',token:$('mon-token').value,board_id:$('mon-board').value,user_id:$('mon-user').value})});
  const d=await r.json();
  if(d.ok) admToast('Configuración Monday guardada ✓'); else admToast('Error al guardar',true);
}
async function admTestMonday(){
  const btn=document.querySelector('[onclick="admTestMonday()"]'); btn.disabled=true;
  btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Probando...';
  const res=$('mon-test-result'); res.style.display='block'; res.textContent='Conectando con Monday API...';
  try{
    const r=await fetch('/toolz/monday.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'test'})});
    const d=await r.json();
    if(d.items||d.status==='success'){ res.style.background='rgba(34,197,94,.1)'; res.style.color='#22c55e'; res.textContent='✓ Conexión exitosa — API respondió correctamente'; }
    else { res.style.background='rgba(239,68,68,.1)'; res.style.color='#ef4444'; res.textContent='✗ Error: '+(d.error||JSON.stringify(d)); }
  }catch(e){ res.style.background='rgba(239,68,68,.1)'; res.style.color='#ef4444'; res.textContent='✗ Error de conexión: '+e.message; }
  btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-vial"></i> Probar conexión';
}

// ── INTELIGENCIA ARTIFICIAL ───────────────────────────────
function admIaEye(s){ const i=$('ia-'+s+'-key'), e=$('ia-'+s+'-eye'); i.type=i.type==='password'?'text':'password'; e.className=i.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash'; }
function admIaShow(s,ok,msg){ const r=$('ia-'+s+'-result'); r.style.display='block'; r.style.background=ok?'rgba(34,197,94,.1)':'rgba(239,68,68,.1)'; r.style.color=ok?'#22c55e':'#ef4444'; r.textContent=(ok?'✓ ':'✗ ')+msg; }
function admIaState(s,tail){ const e=$('ia-'+s+'-state'); e.textContent=tail?'Configurada':'Sin configurar'; e.style.background=tail?'rgba(34,197,94,.12)':'var(--g7-bg-4)'; e.style.color=tail?'#22c55e':'var(--g7-text-4)'; $('ia-'+s+'-key').placeholder=tail?'Guardada (termina en …'+tail+'). Vacío = no cambiarla':'Pega la clave acá'; $('ia-'+s+'-key').value=''; }
async function admIaPost(body){ const r=await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); try{ return await r.json(); }catch(e){ return {error:'El servidor respondió con un error ('+r.status+').'}; } }
async function admIaSave(s,clear){
  const body={action:'save_ia'};
  body[s]={key:$('ia-'+s+'-key').value.replace(/\s+/g,''), model:$('ia-'+s+'-model').value, clear:!!clear};
  let d; try{ d=await admIaPost(body); }catch(e){ d={error:'No hubo respuesta del servidor ('+e.message+').'}; }
  if(d.ok){ admIaState(s,d[s]); admIaShow(s,true,clear?'Clave quitada.':'Guardada.'); admToast(clear?'Clave quitada ✓':'Configuración de IA guardada ✓'); }
  else { admIaShow(s,false,d.error||'Error al guardar'); admToast(d.error||'Error al guardar',true); }
  return !!d.ok;
}
async function admIaClear(s){ if(!confirm('¿Quitar la clave de '+(s==='claude'?'Claude':'Leonardo')+'? Las funciones que la usan dejan de andar hasta cargar otra.')) return; admIaSave(s,true); }
async function admIaTest(s,btn){
  // Si hay una clave nueva escrita, se guarda antes de probar
  if($('ia-'+s+'-key').value.trim() && !(await admIaSave(s))) return;
  btn.disabled=true; const h=btn.innerHTML; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Probando...';
  const d=await admIaPost({action:s==='claude'?'test_claude':'test_leonardo'});
  admIaShow(s,!!d.ok,d.ok?d.msg:(d.error||'Error'));
  btn.disabled=false; btn.innerHTML=h;
}

// ── JIRA ──────────────────────────────────────────────────
function admJiraEye(){ const i=$('jira-token'), e=$('jira-eye'); i.type=i.type==='password'?'text':'password'; e.className=i.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash'; }
// Los textos vienen de Jira: se pintan con textContent, nunca como HTML
function admJiraResult(lines){
  const r=$('jira-result'), col={ok:'#22c55e',warn:'#f59e0b',fail:'#ef4444'}, ico={ok:'✓',warn:'!',fail:'✗'};
  r.style.display='block'; r.replaceChildren(...lines.map(l=>{ const d=document.createElement('div'); d.style.color=col[l.state]; d.textContent=ico[l.state]+' '+(l.name?l.name+': ':'')+l.msg; return d; }));
}
async function admJiraSave(clear){
  const d=await admIaPost({action:'save_jira', site:$('jira-site').value, email:$('jira-email').value, token:$('jira-token').value.replace(/\s+/g,''), clear:!!clear});
  if(!d.ok){ admJiraResult([{state:'fail',msg:d.error||'Error al guardar'}]); admToast(d.error||'Error al guardar',true); return false; }
  const e=$('jira-state'), t=$('jira-token');
  e.textContent=d.tail?'Configurada':'Sin configurar'; e.style.background=d.tail?'rgba(34,197,94,.12)':'var(--g7-bg-4)'; e.style.color=d.tail?'#22c55e':'var(--g7-text-4)';
  t.value=''; t.placeholder=d.tail?'Guardado (termina en …'+d.tail+'). Vacío = no cambiarlo':'Pega el token acá'; $('jira-site').value=d.site;
  admJiraResult([{state:'ok',msg:clear?'Token quitado.':'Guardado.'}]); admToast(clear?'Token quitado ✓':'Conexión con Jira guardada ✓');
  return true;
}
async function admJiraClear(){ if(confirm('¿Quitar el token de Jira? Lo que use Jira deja de andar hasta cargar otro.')) admJiraSave(true); }
async function admJiraTest(btn){
  // Lo que esté escrito se guarda antes de probar
  if(!(await admJiraSave())) return;
  btn.disabled=true; const h=btn.innerHTML; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Probando...';
  const d=await admIaPost({action:'test_jira'});
  admJiraResult(d.steps||[{state:'fail',msg:d.error||'Error'}]);
  btn.disabled=false; btn.innerHTML=h;
}

// Usar Jira en Cronos: Promos, Gestión de trabajo y los proyectos (jira_use_lib.php)
function admJiraFeatMsg(ok, msg){ const r=$('jira-f-result'); r.style.display='block'; r.style.color=ok?'#22c55e':'#ef4444'; r.textContent=msg; }
async function admJiraProjects(btn){
  btn.disabled=true; const h=btn.innerHTML; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Consultando Jira...';
  const d=await admIaPost({action:'jira_projects'}); btn.disabled=false; btn.innerHTML=h;
  if(d.error){ admJiraFeatMsg(false, d.error); return; }
  const box=$('jira-f-projects'), sel=new Set([...box.querySelectorAll('input:checked')].map(i=>i.value).concat((box.dataset.keys||'').split(',').filter(Boolean)));
  box.textContent='';
  if(!d.projects.length){ box.textContent='La cuenta del token no ve ningún proyecto (revisa sus permisos en Jira).'; return; }
  d.projects.forEach(p=>{ const l=document.createElement('label'); l.style.cssText='display:inline-flex;gap:6px;align-items:center;padding:5px 10px;border:1px solid var(--g7-border-3);border-radius:8px;cursor:pointer';
    const i=document.createElement('input'); i.type='checkbox'; i.value=p.key; i.checked=sel.has(p.key); l.append(i, ' '+p.key+' · '+p.name); box.append(l); });
  admJiraFeatMsg(true, d.projects.length+' proyectos. Marca los que quieras usar y guarda.');
}
async function admJiraFeatSave(){
  const keys=[...$('jira-f-projects').querySelectorAll('input:checked')].map(i=>i.value), promos=$('jira-f-promos').checked, trabajo=$('jira-f-trabajo').checked, chat=$('jira-f-chat').checked, stats=$('jira-f-stats')?.checked||false;
  if((promos||trabajo||chat||stats)&&!keys.length){ admJiraFeatMsg(false,'Elige al menos un proyecto de Jira (botón "Traer los proyectos de Jira").'); return; }
  const d=await admIaPost({action:'save_jira_features', promos, trabajo, chat, stats, projects:keys});
  if(!d.ok){ admJiraFeatMsg(false, d.error||'No se pudo guardar'); return; }
  $('jira-f-projects').dataset.keys=d.features.projects.join(',');
  admJiraFeatMsg(true, 'Guardado.'+(d.ready?'':' Ojo: la conexión con Jira todavía no está completa (falta el email o el token), así que no se va a ver hasta cargarla.'));
  admToast('Uso de Jira guardado ✓');
}

async function admStock(s, a) {
  const res = $('st-result'), show = (ok, m) => { res.style.display = 'block'; res.style.background = ok ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)'; res.style.color = ok ? '#22c55e' : '#ef4444'; res.textContent = (ok ? '✓ ' : '✗ ') + m; };
  if (a === 'test') { const d = await admIaPost({ action: 'test_stock', svc: s }); return show(!!d.ok, d.ok ? d.msg : d.error || 'Error'); }
  if (a === 'clear' && !confirm('¿Quitar la clave de ' + s + '?')) return;
  const body = { action: 'save_stock' }; body[s] = { key: $('st-' + s + '-key').value.trim(), clear: a === 'clear' };
  const d = await admIaPost(body);
  if (!d.ok) return show(false, d.error || 'Error al guardar');
  const t = d.tails[s], st = $('st-' + s + '-state'); st.textContent = t ? 'Configurada (…' + t + ')' : 'Sin configurar'; st.style.color = t ? '#22c55e' : 'var(--g7-text-4)'; $('st-' + s + '-key').value = '';
  show(true, a === 'clear' ? 'Clave quitada.' : 'Guardada. Pruébala con "Probar".');
}

// ── CONTENT STATS ─────────────────────────────────────────
async function admLoadContentStats(){
  try{
    const r=await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_content_stats'})});
    const d=await r.json(); const s=d.stats||{};
    const items=[
      {label:'Promos/Contenido',val:s.promos||0,icon:'fa-layer-group'},
      {label:'Links biblioteca',val:s.links||0,icon:'fa-link'},
      {label:'Categorías',val:s.categories||0,icon:'fa-tags'},
      {label:'Landing pages',val:s.pages||0,icon:'fa-file-code'},
      {label:'Mensajes chat',val:s.chat_msgs||0,icon:'fa-comment'},
      {label:'Grupos chat',val:s.chat_groups||0,icon:'fa-people-group'},
    ];
    const html=items.map(i=>`<div class="adm-stat"><div style="font-size:18px;color:var(--g7-accent);margin-bottom:4px"><i class="fa-solid ${i.icon}"></i></div><div class="adm-stat-val" style="font-size:22px">${i.val.toLocaleString()}</div><div class="adm-stat-label">${i.label}</div></div>`).join('');
    const el=$('adm-content-stats'); if(el) el.innerHTML=html;
    const el2=$('content-stats-grid'); if(el2) el2.innerHTML=html;
  }catch(e){}
}

// ── LOGS ──────────────────────────────────────────────────
async function admLoadLogs(){
  const type=$('log-type-filter')?.value||'';
  const cont=$('logs-container');
  cont.innerHTML='<div style="padding:20px;text-align:center;color:var(--g7-text-5)">Cargando...</div>';
  try{
    const r=await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_logs',limit:100,type})});
    const d=await r.json(); const logs=d.logs||[];
    if(!logs.length){ cont.innerHTML='<div style="padding:20px;text-align:center;color:var(--g7-text-5)">Sin registros</div>'; return; }
    cont.innerHTML=`<div style="padding:10px 18px;display:flex;flex-direction:column">
      ${logs.map(l=>`<div class="log-item">
        <span class="log-type">${esc(l.type||'—')}</span>
        <span class="log-user">${esc(l.display_name||l.username||'Sistema')}</span>
        <span class="log-msg">${esc(l.target_name||l.description||'—')}</span>
        <span class="log-time">${new Date(l.created_at).toLocaleString('es-AR',{dateStyle:'short',timeStyle:'short'})}</span>
      </div>`).join('')}
    </div>`;
  }catch(e){ cont.innerHTML='<div style="padding:20px;text-align:center;color:#ef4444">Error cargando logs</div>'; }
}

// ── TOAST ─────────────────────────────────────────────────
function admToast(msg,isErr=false){
  const t=$('adm-toast'); t.textContent=msg;
  t.style.background=isErr?'#ef4444':'#22c55e'; t.style.color=isErr?'#fff':'#000';
  t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>t.style.transform='translateX(-50%) translateY(60px)',2200);
}

// ── TOPBAR MANAGEMENT ─────────────────────────────────────
let navItems = [];
let navAlign = 'center';
const FA_ICONS = {
  'Navegación': ['fa-house','fa-arrow-left','fa-arrow-right','fa-bars','fa-grid','fa-table-columns','fa-sitemap'],
  'Comunicación': ['fa-comment-dots','fa-comments','fa-message','fa-envelope','fa-phone','fa-bell','fa-bullhorn','fa-paper-plane'],
  'Contenido': ['fa-layer-group','fa-photo-film','fa-image','fa-images','fa-video','fa-music','fa-file','fa-file-code','fa-newspaper'],
  'Datos': ['fa-chart-line','fa-chart-bar','fa-chart-pie','fa-table','fa-database','fa-server','fa-code'],
  'Personas': ['fa-users','fa-user','fa-user-group','fa-people-group','fa-address-card','fa-id-badge'],
  'Herramientas': ['fa-gear','fa-sliders','fa-wrench','fa-screwdriver-wrench','fa-toolbox','fa-wand-magic-sparkles','fa-magic'],
  'Negocio': ['fa-briefcase','fa-building','fa-shop','fa-store','fa-handshake','fa-money-bill','fa-credit-card','fa-receipt'],
  'Favoritos': ['fa-star','fa-heart','fa-bookmark','fa-flag','fa-trophy','fa-medal','fa-crown','fa-gem'],
  'Organización': ['fa-folder','fa-folder-open','fa-tag','fa-tags','fa-list','fa-check-square','fa-clipboard','fa-calendar'],
  'Internet': ['fa-globe','fa-link','fa-wifi','fa-cloud','fa-shield-halved','fa-lock','fa-unlock','fa-key'],
  'Movimiento': ['fa-rocket','fa-plane','fa-car','fa-truck','fa-bicycle','fa-location-dot','fa-map'],
  'Varios': ['fa-bolt','fa-fire','fa-leaf','fa-sun','fa-moon','fa-snowflake','fa-rainbow','fa-infinity','fa-atom'],
};
const FA_ICONS_FLAT = Object.values(FA_ICONS).flat();

async function admLoadTopbar(){
  try {
    const r = await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_config'})});
    const d = await r.json(); const c = d.config||{};
    // Load logo
    if(c.logo_url){ $('adm-logo-url').value=c.logo_url; admPreviewLogo(c.logo_url); }
    // Load alignment
    if(c.nav_align){ admSetAlign(c.nav_align); }
    // Load nav items
    if(c.nav_links){
      navItems = JSON.parse(c.nav_links);
    } else {
      navItems = [
        {href:'/toolz/',             icon:'fa-house',         label:'Home',       enabled:true},
        {href:'/toolz/links/',       icon:'fa-book-bookmark', label:'Biblioteca', enabled:true},
        {href:'/toolz/comunidad.php',icon:'fa-people-group',  label:'Comunidad',  enabled:true},
        {href:'/toolz/stock.php',    icon:'fa-photo-film',    label:'Stock',      enabled:true},
        {href:'/toolz/chat_page.php',icon:'fa-comment-dots',  label:'Chat',       enabled:true},
        {href:'/toolz/stats.php',    icon:'fa-chart-line',    label:'Stats',      enabled:true},
        {href:'/toolz/promos/',      icon:'fa-layer-group',   label:'Promos',     enabled:true},
        {href:'/toolz/webp.php',     icon:'fa-image',         label:'WebP',       enabled:true},
        {href:'/toolz/monday.php',   icon:'fa-check-square',  label:'Monday',     enabled:true},
      ];
    }
    admRenderNavItems();
  } catch(e) {}
}

function admSetAlign(val){
  navAlign = val;
  document.querySelectorAll('.adm-align-btn').forEach(b=>{
    b.classList.toggle('active', b.dataset.val === val);
  });
}

function admPreviewLogo(url){
  const img = $('adm-logo-preview');
  if(url){ img.src = url; img.style.display=''; }
}

async function admUploadLogo(input){
  const file = input.files[0]; if(!file) return;
  const status = $('adm-logo-upload-status');
  status.textContent = 'Subiendo...'; status.style.color='var(--g7-text-3)';
  const fd = new FormData(); fd.append('f',file); fd.append('t','logo');
  try{
    const r = await fetch('/toolz/pages.php',{method:'POST',body:fd});
    const d = await r.json();
    if(d.ok||d.url){
      const url = d.url||d.ok;
      $('adm-logo-url').value = url;
      admPreviewLogo(url);
      status.textContent = '✓ Subido'; status.style.color='#22c55e';
    } else { status.textContent = 'Error al subir'; status.style.color='#ef4444'; }
  }catch(e){ status.textContent='Error: '+e.message; status.style.color='#ef4444'; }
  input.value='';
}

function admRenderNavItems(){
  const list = $('nav-items-list');
  list.innerHTML = navItems.map((item,i)=>`
    <div class="nav-item-row" data-idx="${i}">
      <span class="nav-drag-handle" draggable="true"
        data-on-dragstart="admDragStart" data-dragstart-1="event" data-dragstart-2="j:${i}"
        data-on-dragover="admPrevent" data-dragover-1="event"
        data-on-drop="admDrop" data-drop-1="event" data-drop-2="j:${i}">
        <i class="fa-solid fa-grip-vertical"></i>
      </span>
      <label class="adm-toggle" style="flex-shrink:0">
        <input type="checkbox" ${item.enabled?'checked':''} data-on-change="admNavSet" data-change-1="j:${i}" data-change-2="s:enabled" data-change-3="checked">
        <div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div>
      </label>
      <button class="btn-icon" data-on-click="admOpenIconPicker" data-click-1="j:${i}" title="Elegir ícono" style="width:36px;height:34px;border-radius:7px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);display:flex;align-items:center;justify-content:center;cursor:pointer">
        <i class="fa-solid ${item.icon}" style="color:var(--g7-accent);font-size:14px" id="nav-icon-preview-${i}"></i>
      </button>
      <input class="adm-input" value="${esc(item.label)}" placeholder="Etiqueta" style="flex:1;min-width:80px;padding:5px 8px;font-size:12px" data-on-input="admNavSet" data-input-1="j:${i}" data-input-2="s:label" data-input-3="value">
      <input class="adm-input" value="${esc(item.href)}" placeholder="/toolz/pagina.php" style="flex:2;min-width:120px;padding:5px 8px;font-size:12px" data-on-input="admNavSet" data-input-1="j:${i}" data-input-2="s:href" data-input-3="value">
      <button class="btn-icon" data-on-click="admRemoveNavItem" data-click-1="j:${i}" style="color:var(--g7-danger)"><i class="fa-solid fa-trash"></i></button>
    </div>`).join('');
}

// Drag and drop reorder
let dragSrc = null;
function admDragStart(e,i){ dragSrc=i; e.dataTransfer.effectAllowed='move'; }
function admDrop(e,i){
  e.preventDefault();
  if(dragSrc===null||dragSrc===i) return;
  const moved = navItems.splice(dragSrc,1)[0];
  navItems.splice(i,0,moved);
  dragSrc=null;
  admRenderNavItems();
}

function admAddNavItem(){
  navItems.push({href:'/toolz/nueva.php',icon:'fa-star',label:'Nueva',enabled:true});
  admRenderNavItems();
  // Scroll to bottom
  $('nav-items-list').lastElementChild?.scrollIntoView({behavior:'smooth'});
}
function admRemoveNavItem(i){
  navItems.splice(i,1); admRenderNavItems();
}

async function admSaveTopbar(){
  const logoUrl = $('adm-logo-url').value.trim();
  const config = {
    nav_links: JSON.stringify(navItems),
    nav_align: navAlign,
    logo_url: logoUrl,
  };
  const r = await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_config',config})});
  const d = await r.json();
  if(d.ok){ admToast('Topbar guardado ✓ — Recarga para ver los cambios'); }
  else admToast('Error al guardar',true);
}

// ── ICON PICKER ───────────────────────────────────────────
let iconPickerIdx = null;

function admOpenIconPicker(idx){
  iconPickerIdx = idx;
  $('icon-search').value = '';
  admFilterIcons('');
  $('icon-picker-modal').classList.add('open');
  setTimeout(()=>$('icon-search').focus(), 50);
}
function admCloseIconPicker(){
  $('icon-picker-modal').classList.remove('open');
  iconPickerIdx = null;
}
function admSelectIcon(icon){
  if(iconPickerIdx === null) return;
  navItems[iconPickerIdx].icon = icon;
  // Update the preview button in the list
  const preview = $('nav-icon-preview-'+iconPickerIdx);
  if(preview){ preview.className = 'fa-solid '+icon; }
  admCloseIconPicker();
}
function admFilterIcons(q){
  const body = $('icon-picker-body');
  const search = q.toLowerCase().replace('fa-','');
  let html = '';
  for(const [cat, icons] of Object.entries(FA_ICONS)){
    const filtered = icons.filter(ic=>!search||ic.replace('fa-','').includes(search));
    if(!filtered.length) continue;
    html += `<div style="margin-bottom:16px">
      <div style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">${cat}</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:6px">
        ${filtered.map(ic=>`
          <button data-on-click="admSelectIcon" data-click-1="s:${ic}" title="${ic.replace('fa-','')}"
            style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:10px 6px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;cursor:pointer;color:var(--g7-text-3);font-size:10px;transition:all .15s"
            data-hov2>
            <i class="fa-solid ${ic}" style="font-size:18px"></i>
            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50px">${ic.replace('fa-','')}</span>
          </button>`).join('')}
      </div>
    </div>`;
  }
  if(!html) html = '<div style="text-align:center;color:var(--g7-text-5);padding:30px">Sin resultados para "'+q+'"</div>';
  body.innerHTML = html;
}

// Close on outside click
$('icon-picker-modal').addEventListener('click', e=>{
  if(e.target === $('icon-picker-modal')) admCloseIconPicker();
});

// ── NOTIFICATIONS CONFIG ─────────────────────────────────
let currentSoundType = 'beep';

async function admLoadNotif(){
  try{
    const r = await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_config'})});
    const d = await r.json(); const c = d.config||{};
    if(c.buzz_enabled !== undefined) $('n-buzz-enabled').checked = c.buzz_enabled==='1';
    if(c.notif_sound_enabled !== undefined) $('n-sound-enabled').checked = c.notif_sound_enabled==='1';
    if(c.notif_beeps_default){ $('n-beeps').value=c.notif_beeps_default; $('n-beeps-val').textContent=c.notif_beeps_default; }
    if(c.notif_sound_type) admSetSoundType(c.notif_sound_type, document.querySelector(`.adm-sound-btn[data-type="${c.notif_sound_type}"]`));
  }catch(e){}
}

function admSetSoundType(type, btn){
  currentSoundType = type;
  document.querySelectorAll('.adm-sound-btn').forEach(b=>b.classList.remove('active'));
  if(btn) btn.classList.add('active');
}

function admPreviewSound(){
  const type = currentSoundType;
  try {
    const ctx = new (window.AudioContext||window.webkitAudioContext)();
    if(type==='beep'){
      const o=ctx.createOscillator(); const g=ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.frequency.value=880; o.type='sine';
      g.gain.setValueAtTime(0.3,ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.3);
      o.start(ctx.currentTime); o.stop(ctx.currentTime+0.3);
    } else if(type==='soft'){
      const o=ctx.createOscillator(); const g=ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.frequency.setValueAtTime(523,ctx.currentTime);
      o.frequency.exponentialRampToValueAtTime(659,ctx.currentTime+0.1);
      o.type='sine'; g.gain.setValueAtTime(0.2,ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.5);
      o.start(ctx.currentTime); o.stop(ctx.currentTime+0.5);
    } else if(type==='pop'){
      const o=ctx.createOscillator(); const g=ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.frequency.setValueAtTime(300,ctx.currentTime);
      o.frequency.exponentialRampToValueAtTime(100,ctx.currentTime+0.08);
      o.type='sine'; g.gain.setValueAtTime(0.4,ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001,ctx.currentTime+0.1);
      o.start(ctx.currentTime); o.stop(ctx.currentTime+0.1);
    }
  }catch(e){}
}

async function admSaveNotif(){
  const config = {
    buzz_enabled:         $('n-buzz-enabled')?.checked ? '1' : '0',
    notif_sound_enabled:  $('n-sound-enabled')?.checked ? '1' : '0',
    notif_sound_type:     currentSoundType,
    notif_beeps_default:  $('n-beeps')?.value || '3',
  };
  const r = await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_config',config})});
  const d = await r.json();
  if(d.ok) admToast('Notificaciones guardadas ✓');
  else admToast('Error al guardar',true);
}

// ── PLUGINS ──────────────────────────────────────────────
let PLUGINS_REGISTRY = []; // loaded from server

let pluginStates = {}; // {pluginId: {enabled, config}}

async function admLoadPlugins(){
  // 1. Load plugin registry from server (auto-discovered)
  try{
    const rr = await fetch('/toolz/plugins.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_registry'})});
    const dr = await rr.json();
    if(dr.plugins) PLUGINS_REGISTRY = dr.plugins;
  }catch(e){}

  // 2. Load plugin states from config
  try{
    const r = await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_config'})});
    const d = await r.json(); const c = d.config||{};
    pluginStates = c.plugins_state ? JSON.parse(c.plugins_state) : {};
  }catch(e){ pluginStates={}; }
  admRenderPlugins();
  // Load backup list if plugin is active
  if(pluginStates['backup']?.enabled){
    admLoadBackupList();
  }
}

async function admLoadBackupList(){
  const config = pluginStates['backup']?.config || {};
  try{
    const r = await fetch('/toolz/plugins.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'plugin_action',plugin_id:'backup',sub_action:'list',config})});
    const d = await r.json();
    if(d.ok) admRenderBackupList(d.backups||[]);
  }catch(e){}
}

function admRenderBackupList(backups){
  const el = $('backup-history');
  if(!el) return;
  if(!backups.length){
    el.innerHTML='<div style="font-size:11px;color:var(--g7-text-5);padding:10px 0">Sin backups guardados todavía</div>';
    return;
  }
  el.innerHTML = backups.map(b=>`
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--g7-border)">
      <i class="fa-solid fa-file-zipper" style="color:var(--g7-accent);font-size:13px;flex-shrink:0"></i>
      <div style="flex:1;min-width:0">
        <div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(b.name)}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">${b.date} · ${b.size}</div>
      </div>
      <a href="${esc(b.url||'#')}" download class="btn-icon" title="Descargar" style="text-decoration:none"><i class="fa-solid fa-download"></i></a>
      <button class="btn-icon" data-on-click="admDeleteBackup" data-click-1="s:${esc(b.name)}" title="Eliminar" style="color:var(--g7-danger)"><i class="fa-solid fa-trash"></i></button>
    </div>`).join('');
}

async function admDeleteBackup(name){
  if(!confirm('¿Eliminar el backup "'+name+'"?')) return;
  const config = pluginStates['backup']?.config||{};
  const r = await fetch('/toolz/plugins.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'plugin_action',plugin_id:'backup',sub_action:'delete',file:name,config})});
  const d = await r.json();
  if(d.ok){ admLoadBackupList(); admToast('Backup eliminado ✓'); }
  else admToast('Error al eliminar',true);
}

function admRenderPlugins(){
  const grid = $('plugins-grid');
  if(!PLUGINS_REGISTRY.length){
    grid.innerHTML='<div style="background:var(--g7-bg-3);border:1px solid var(--g7-border-2);border-radius:12px;padding:32px;text-align:center;color:var(--g7-text-5)"><i class="fa-solid fa-puzzle-piece" style="font-size:28px;margin-bottom:10px;display:block"></i>No se encontraron plugins.<br><span style="font-size:11px">Asegurate de que /toolz/plugins/backup/plugin.php existe en el servidor.</span></div>';
    return;
  }
  grid.innerHTML = PLUGINS_REGISTRY.map(plugin => {
    const state = pluginStates[plugin.id] || {enabled:false, config:{}};
    const enabled = state.enabled || false;
    return `
    <div class="adm-card" id="plugin-card-${plugin.id}">
      <div class="adm-card-head" style="cursor:default">
        <div style="display:flex;align-items:center;gap:12px;flex:1">
          <div style="width:42px;height:42px;border-radius:10px;background:${enabled?'var(--g7-accent-muted)':'var(--g7-bg-5)'};border:1px solid ${enabled?'var(--g7-accent-border)':'var(--g7-border-3)'};display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .3s">
            <i class="fa-solid ${plugin.icon}" style="font-size:18px;color:${enabled?'var(--g7-accent)':'var(--g7-text-4)'}"></i>
          </div>
          <div style="flex:1">
            <div style="font-size:14px;font-weight:700;margin-bottom:2px">${esc(plugin.name)}</div>
            <div style="font-size:11px;color:var(--g7-text-4)">${esc(plugin.description)}</div>
            <div style="display:flex;gap:10px;margin-top:5px">
              <span style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase">v${plugin.version}</span>
              <span style="font-size:9px;color:var(--g7-text-5)">por ${plugin.author}</span>
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
          <span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;${enabled?'background:rgba(34,197,94,.1);color:#22c55e;border:1px solid rgba(34,197,94,.2)':'background:var(--g7-bg-5);color:var(--g7-text-5);border:1px solid var(--g7-border-3)'}">${enabled?'Activo':'Inactivo'}</span>
          <label class="adm-toggle">
            <input type="checkbox" ${enabled?'checked':''} data-on-change="admTogglePlugin" data-change-1="s:${plugin.id}" data-change-2="checked">
            <div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div>
          </label>
        </div>
      </div>
      ${enabled ? `
      <div class="plugin-config" id="plugin-cfg-${plugin.id}" style="border-top:1px solid var(--g7-border);padding:16px 18px">
        <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px">Configuración</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          ${admRenderPluginFields(plugin, state.config||{})}
        </div>
        <div style="display:flex;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--g7-border)">
          ${plugin.id==='backup' ? `
          <button class="btn btn-ghost btn-sm" data-on-click="admRunBackup" data-click-1="s:manual"><i class="fa-solid fa-download"></i> Backup manual</button>
          <button class="btn btn-ghost btn-sm" data-on-click="admRunBackup" data-click-1="s:auto"><i class="fa-solid fa-server"></i> Guardar en servidor</button>
          <div id="backup-status" style="font-size:11px;color:var(--g7-text-4);display:flex;align-items:center;gap:6px;margin-left:auto"></div>
        </div>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--g7-border)">
          <div style="font-size:10px;font-weight:700;color:var(--g7-text-4);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px">Backups guardados en servidor</div>
          <div id="backup-history"><div style="font-size:11px;color:var(--g7-text-5)">Cargando...</div></div>
          ` : ''}
          <button class="btn btn-primary btn-sm" style="margin-left:${plugin.id==='backup'?'0':'auto'}" data-on-click="admSavePluginConfig" data-click-1="s:${plugin.id}"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
        </div>
      </div>` : ''}
    </div>`;
  }).join('');
}

function admRenderPluginFields(plugin, config){
  return (plugin.configFields||plugin.config_fields||[]).map(f => {
    const val = config[f.key] !== undefined ? config[f.key] : f.default;
    const span = (f.type==='toggle'||f.type==='time') ? 'grid-column:span 1' : '';
    if(f.type==='toggle') return `
      <div style="display:flex;align-items:center;justify-content:space-between;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;padding:10px 14px">
        <span style="font-size:12px;font-weight:600">${esc(f.label)}</span>
        <label class="adm-toggle"><input type="checkbox" id="pcfg-${f.key}" ${val?'checked':''}><div class="adm-toggle-track"></div><div class="adm-toggle-thumb"></div></label>
      </div>`;
    if(f.type==='select') return `
      <div>
        <label class="adm-label">${esc(f.label)}</label>
        <select class="adm-select" id="pcfg-${f.key}">${f.options.map(o=>`<option value="${o}" ${val===o?'selected':''}>${o.charAt(0).toUpperCase()+o.slice(1)}</option>`).join('')}</select>
      </div>`;
    if(f.type==='time') return `
      <div>
        <label class="adm-label">${esc(f.label)}</label>
        <input class="adm-input" type="time" id="pcfg-${f.key}" value="${esc(val)}">
      </div>`;
    if(f.type==='number') return `
      <div>
        <label class="adm-label">${esc(f.label)}</label>
        <input class="adm-input" type="number" id="pcfg-${f.key}" value="${val}" min="${f.min||0}" max="${f.max||999}">
      </div>`;
    return `
      <div style="grid-column:span 2">
        <label class="adm-label">${esc(f.label)}</label>
        <input class="adm-input" id="pcfg-${f.key}" value="${esc(val)}" placeholder="${f.default||''}">
      </div>`;
  }).join('');
}

async function admTogglePlugin(id, enabled){
  if(!pluginStates[id]) pluginStates[id]={config:{}};
  pluginStates[id].enabled = enabled;
  await admSavePluginStates();
  admRenderPlugins();
  // Load backup list if plugin is active
  if(pluginStates['backup']?.enabled){
    admLoadBackupList();
  }
}

async function admLoadBackupList(){
  const config = pluginStates['backup']?.config || {};
  try{
    const r = await fetch('/toolz/plugins.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'plugin_action',plugin_id:'backup',sub_action:'list',config})});
    const d = await r.json();
    if(d.ok) admRenderBackupList(d.backups||[]);
  }catch(e){}
}

function admRenderBackupList(backups){
  const el = $('backup-history');
  if(!el) return;
  if(!backups.length){
    el.innerHTML='<div style="font-size:11px;color:var(--g7-text-5);padding:10px 0">Sin backups guardados todavía</div>';
    return;
  }
  el.innerHTML = backups.map(b=>`
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--g7-border)">
      <i class="fa-solid fa-file-zipper" style="color:var(--g7-accent);font-size:13px;flex-shrink:0"></i>
      <div style="flex:1;min-width:0">
        <div style="font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(b.name)}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">${b.date} · ${b.size}</div>
      </div>
      <a href="${esc(b.url||'#')}" download class="btn-icon" title="Descargar" style="text-decoration:none"><i class="fa-solid fa-download"></i></a>
      <button class="btn-icon" data-on-click="admDeleteBackup" data-click-1="s:${esc(b.name)}" title="Eliminar" style="color:var(--g7-danger)"><i class="fa-solid fa-trash"></i></button>
    </div>`).join('');
}

async function admDeleteBackup(name){
  if(!confirm('¿Eliminar el backup "'+name+'"?')) return;
  const config = pluginStates['backup']?.config||{};
  const r = await fetch('/toolz/plugins.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'plugin_action',plugin_id:'backup',sub_action:'delete',file:name,config})});
  const d = await r.json();
  if(d.ok){ admLoadBackupList(); admToast('Backup eliminado ✓'); }
  else admToast('Error al eliminar',true);
}

async function admSavePluginConfig(id){
  const plugin = PLUGINS_REGISTRY.find(p=>p.id===id); if(!plugin) return;
  const config = {};
  (plugin.configFields||plugin.config_fields||[]).forEach(f=>{
    const el=$(('pcfg-'+f.key));
    if(!el) return;
    config[f.key] = f.type==='toggle' ? el.checked : (f.type==='number' ? parseInt(el.value)||f.default : el.value);
  });
  if(!pluginStates[id]) pluginStates[id]={enabled:false};
  pluginStates[id].config = config;
  await admSavePluginStates();
  admToast('Configuración guardada ✓');
}

async function admSavePluginStates(){
  await fetch(ADM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    action:'save_config', config:{plugins_state: JSON.stringify(pluginStates)}
  })});
}

async function admRunBackup(mode){
  const statusEl = $('backup-status');
  if(statusEl){ statusEl.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generando backup...'; }
  try{
    const r = await fetch('/toolz/plugins.php',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'plugin_action', plugin_id:'backup', sub_action: mode==='manual'?'manual':'save', config: pluginStates['backup']?.config||{}})
    });
    if(mode==='manual'){
      // Trigger download
      const blob = await r.blob();
      const cd = r.headers.get('Content-Disposition')||'';
      const match = cd.match(/filename="([^"]+)"/);
      const filename = match?match[1]:'backup.zip';
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href=url; a.download=filename; a.click();
      URL.revokeObjectURL(url);
      if(statusEl) statusEl.innerHTML='<span style="color:#22c55e"><i class="fa-solid fa-check"></i> Descargado</span>';
    } else {
      const d = await r.json();
      if(d.ok){
        if(statusEl) statusEl.innerHTML=`<span style="color:#22c55e"><i class="fa-solid fa-check"></i> Guardado: ${d.filename}</span>`;
        if(d.backups) admRenderBackupList(d.backups);
      } else {
        if(statusEl) statusEl.innerHTML=`<span style="color:#ef4444"><i class="fa-solid fa-xmark"></i> ${d.error||'Error'}</span>`;
      }
    }
  }catch(e){
    if(statusEl) statusEl.innerHTML=`<span style="color:#ef4444">Error: ${e.message}</span>`;
  }
}

// ── THUMBNAIL GENERATOR ──────────────────────────────────
async function admOpenThumbGenerator(){
  const wrap = $('thumb-gen-wrap');
  const bar  = $('thumb-gen-bar');
  const stat = $('thumb-gen-status');
  const cnt  = $('thumb-gen-count');
  wrap.style.display = 'block';
  bar.style.width = '0%';
  stat.textContent = 'Buscando videos sin thumbnail...';
  cnt.textContent  = '';

  // 1. Get videos without thumbnails
  const r = await fetch('/toolz/stock.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'get_videos_without_thumb'})
  });
  const d = await r.json();
  const videos = d.videos || [];

  if(!videos.length){
    stat.textContent = '✓ Todos los videos ya tienen thumbnail';
    bar.style.width = '100%';
    return;
  }

  stat.textContent = `Procesando ${videos.length} videos...`;
  let done = 0; let errors = 0;

  for(const vid of videos){
    cnt.textContent  = `${done}/${videos.length} · ${errors} errores`;
    bar.style.width  = Math.round((done/videos.length)*100) + '%';
    stat.textContent = `Capturando: ${vid.title.substring(0,40)}...`;

    try {
      const thumbBase64 = await admCaptureVideoFrame(vid.file_url);
      if(thumbBase64){
        // Upload thumbnail
        const res = await fetch('/toolz/stock.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({action:'save_video_thumb', id: vid.id, thumb: thumbBase64})
        });
        const rd = await res.json();
        if(rd.ok) done++;
        else errors++;
      } else { errors++; }
    } catch(e){ errors++; }
  }

  bar.style.width  = '100%';
  stat.textContent = `✓ Completado — ${done} thumbnails generados, ${errors} errores`;
  cnt.textContent  = '';
}

function admCaptureVideoFrame(url){
  return new Promise((resolve) => {
    const video  = document.createElement('video');
    video.muted  = true;
    video.preload = 'metadata';
    video.crossOrigin = 'anonymous';
    video.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px';
    document.body.appendChild(video);

    const cleanup = () => { try{ document.body.removeChild(video); }catch(e){} };
    const timeout = setTimeout(() => { cleanup(); resolve(null); }, 15000);

    const capture = () => {
      try {
        const canvas = document.createElement('canvas');
        // Medium resolution thumbnail
        const maxW = 480; const maxH = 270;
        const ar = video.videoWidth / video.videoHeight;
        canvas.width  = ar >= 1 ? maxW : Math.round(maxH * ar);
        canvas.height = ar >= 1 ? Math.round(maxW / ar) : maxH;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        const base64 = canvas.toDataURL('image/jpeg', 0.75);
        clearTimeout(timeout);
        cleanup();
        resolve(base64);
      } catch(e){ clearTimeout(timeout); cleanup(); resolve(null); }
    };

    video.addEventListener('seeked', capture, {once:true});
    video.addEventListener('loadedmetadata', () => {
      video.currentTime = 0.5;
    }, {once:true});
    video.addEventListener('loadeddata', () => {
      if(video.currentTime < 0.1) video.currentTime = 0.5;
    }, {once:true});
    video.addEventListener('error', () => { clearTimeout(timeout); cleanup(); resolve(null); }, {once:true});

    video.src = url;
    video.load();
  });
}

// ── Eventos que antes eran código en línea (CSP estricta, SEC-24 fase 2) ──
function admBeepsInput(el) { document.getElementById('n-beeps-val').textContent = el.value; admSaveNotif(); }
function admImgBlank() { this.src = ''; }
function admPrevent(e) { e.preventDefault(); }
function admNavSet(i, key, val) { navItems[i][key] = val; }
