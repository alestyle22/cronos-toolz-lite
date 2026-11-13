/* assets/status-page.js — Script de status.php (Estado de incidencias), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #st-cfg, #st-issues, #st-users y #st-chart. */
const ST_URL  = window.location.pathname;
const ST_CFG = JSON.parse(document.getElementById('st-cfg').textContent);   // datos de PHP (status.php)
const ME_ID   = ST_CFG.me;
const IS_ADMIN = !!ST_CFG.isAdmin;
let issues = JSON.parse(document.getElementById('st-issues').textContent);
let users  = JSON.parse(document.getElementById('st-users').textContent);
let chartData = JSON.parse(document.getElementById('st-chart').textContent);
let curIdx = 0;
let listOpen = false;

const PRIO_LABELS  = {baja:'BAJA',media:'MEDIA',alta:'ALTA',critica:'CRÍTICA'};
const STATUS_LABELS = {abierto:'ABIERTO',en_progreso:'EN PROGRESO',resuelto:'RESUELTO',cancelado:'CANCELADO'};

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function fmtDate(d){if(!d)return'—';const dt=new Date(d);return dt.toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'2-digit'})+' '+dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});}
function timeAgo(d){const s=Math.floor((Date.now()-new Date(d))/1000);if(s<60)return'ahora';if(s<3600)return Math.floor(s/60)+'m';if(s<86400)return Math.floor(s/3600)+'h';return Math.floor(s/86400)+'d';}
function avHtml(av,name,size,extra=''){
  if(av)return`<img src="${av}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;${extra}">`;
  const i=(name?.[0]||'?').toUpperCase(),c=['#ff3366','#9966ff','#4488ff','#00ffaa','#ffcc00','#ff6600'][i.charCodeAt(0)%6],fs=Math.floor(size*.42);
  return`<div style="width:${size}px;height:${size}px;border-radius:50%;background:${c}22;border:1px solid ${c}44;display:flex;align-items:center;justify-content:center;font-size:${fs}px;font-weight:700;color:${c};flex-shrink:0;${extra}">${i}</div>`;
}

// ── CHART ──────────────────────────────────────────────────
function renderChart(){
  const bar = document.getElementById('st-chart');
  if(!bar) return;
  const days = [];
  for(let i=13;i>=0;i--){
    const d=new Date(); d.setDate(d.getDate()-i);
    const key=d.toISOString().slice(0,10);
    days.push({key,cnt:chartData[key]||0,day:d.getDate()});
  }
  const max = Math.max(...days.map(d=>d.cnt),1);
  bar.innerHTML = days.map(d=>{
    const h = Math.round((d.cnt/max)*40);
    const isToday = d.key===new Date().toISOString().slice(0,10);
    return `<div class="st-chart-col" title="${d.key}: ${d.cnt} updates">
      <div class="st-chart-col-bar" style="height:${Math.max(h,2)}px;background:${isToday?'var(--accent)':d.cnt>0?'rgba(68,136,255,.6)':'rgba(68,136,255,.1)'};${d.cnt>0&&isToday?'box-shadow:0 0 10px rgba(255,102,0,.5)':''}"></div>
      <div class="st-chart-col-lbl">${d.day}</div>
    </div>`;
  }).join('');
}

// ── NAVIGATION ─────────────────────────────────────────────
function updateNavUI(){
  document.getElementById('btn-prev').disabled = curIdx <= 0;
  document.getElementById('btn-next').disabled = curIdx >= issues.length-1;
  document.getElementById('nav-counter').textContent = issues.length ? `${curIdx+1} / ${issues.length}` : '—';
  // Highlight list row
  document.querySelectorAll('.st-issue-row').forEach(r=>r.classList.remove('current'));
  const cur = issues[curIdx];
  if(cur) document.getElementById('row-'+cur.id)?.classList.add('current');
}
function navigate(dir){
  curIdx = Math.max(0, Math.min(issues.length-1, curIdx+dir));
  updateNavUI();
  loadIssue(issues[curIdx].id);
}
function goToIssue(idx){
  curIdx=idx; updateNavUI(); loadIssue(issues[idx].id); toggleList();
}

// ── LOAD ISSUE ─────────────────────────────────────────────
async function loadIssue(id){
  const content = document.getElementById('st-content');
  if(!content) return;
  content.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;padding:80px;color:var(--text3)"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px"></i></div>`;
  const r = await fetch(ST_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_detail',id})});
  const d = await r.json();
  if(d.ok) renderIssuePage(d.issue, d.updates);
}

function renderIssuePage(iss, updates){
  const content = document.getElementById('st-content');
  if(!content) return;
  const canEdit = iss.created_by==ME_ID || IS_ADMIN;
  const tlHtml = updates.map((u,i)=>{
    const isFirst=i===0, isLatest=i===updates.length-1;
    const name=u.display_name||u.username||'?';
    const avExtra = isFirst ? 'border:2px solid var(--accent);box-shadow:0 0 12px rgba(255,102,0,.4)' : isLatest ? 'border:2px solid var(--neon);box-shadow:0 0 10px rgba(0,255,170,.3)' : 'border:2px solid var(--border2)';
    return `<div class="st-tl-item">
      <div class="st-tl-node">${avHtml(u.avatar,name,32,avExtra)}</div>
      <div class="st-tl-content">
        <div class="st-tl-meta">
          <span class="st-tl-author">${esc(name)}</span>
          <span class="st-tl-time">${fmtDate(u.created_at)} · ${timeAgo(u.created_at)}</span>
        </div>
        <div class="st-tl-bubble ${isFirst?'first':isLatest?'latest':''}">${esc(u.body).replace(/\n/g,'<br>')}</div>
      </div>
    </div>`;
  }).join('');

  content.innerHTML = `
  <div class="st-issue-page">
    <div class="st-prio-strip ${iss.priority}"></div>
    <div class="st-issue-header">
      <div class="st-issue-id">#${iss.id}</div>
      <div class="st-issue-title">${esc(iss.title)}</div>
      ${canEdit?`<button class="st-edit-btn" data-on-click="openModal" data-click-1="j:${iss.id}"><i class="fa-solid fa-pen-to-square"></i> Editar</button>`:''}
    </div>
    <div class="st-badges">
      <span class="st-badge ${iss.priority}">${PRIO_LABELS[iss.priority]||iss.priority}</span>
      <span class="st-badge ${iss.status}">${STATUS_LABELS[iss.status]||iss.status}</span>
      ${iss.category?`<span class="st-badge" style="background:var(--bg4);color:var(--text3);border-color:var(--border2)">${esc(iss.category)}</span>`:''}
    </div>
    ${iss.description?`<div class="st-desc">${esc(iss.description).replace(/\n/g,'<br>')}</div>`:''}
    <div class="st-meta-grid">
      <div class="st-meta-item"><span class="st-meta-lbl">Creado por</span><span class="st-meta-val">${avHtml(iss.creator_avatar,iss.creator_name||iss.creator_username,18)} ${esc(iss.creator_name||iss.creator_username||'?')}</span></div>
      <div class="st-meta-item"><span class="st-meta-lbl">Fecha</span><span class="st-meta-val">${fmtDate(iss.created_at)}</span></div>
      ${iss.team?`<div class="st-meta-item"><span class="st-meta-lbl">Equipo</span><span class="st-meta-val"><i class="fa-solid fa-users" style="font-size:10px;color:var(--text3)"></i>${esc(iss.team)}</span></div>`:''}
      ${iss.assigned_name||iss.assigned_username?`<div class="st-meta-item"><span class="st-meta-lbl">Responsable</span><span class="st-meta-val">${avHtml(iss.assigned_avatar,iss.assigned_name||iss.assigned_username,18)} ${esc(iss.assigned_name||iss.assigned_username)}</span></div>`:''}
      ${iss.resolved_at?`<div class="st-meta-item"><span class="st-meta-lbl">Resuelto</span><span class="st-meta-val" style="color:var(--neon)">${fmtDate(iss.resolved_at)}</span></div>`:''}
      <div class="st-meta-item"><span class="st-meta-lbl">Updates</span><span class="st-meta-val">${iss.update_count}</span></div>
    </div>

    <div class="st-tl-header"><i class="fa-solid fa-timeline" style="color:var(--accent)"></i> HISTORIAL</div>
    <div class="st-tl">${tlHtml}</div>

    <div class="st-form">
      <div class="st-form-header"><i class="fa-solid fa-terminal"></i> AGREGAR UPDATE</div>
      <div class="st-form-body">
        <textarea class="st-ta" id="st-ta" placeholder="Describe el estado actual, avance o resolución..." data-on-keydown="stTaKey" data-keydown-1="event" data-keydown-2="j:${iss.id}"></textarea>
        <div class="st-form-actions">
          <select class="st-sel" id="st-sel-status">
            <option value="">Sin cambio de estado</option>
            <option value="abierto">● Abierto</option>
            <option value="en_progreso">● En progreso</option>
            <option value="resuelto">● Resuelto</option>
            <option value="cancelado">● Cancelado</option>
          </select>
          <button class="st-send-btn" id="st-send-btn" data-on-click="submitUpdate" data-click-1="j:${iss.id}"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
        </div>
      </div>
      <div class="st-hint">Ctrl+Enter para enviar</div>
    </div>
  </div>`;

  // Scroll to bottom of timeline
  setTimeout(()=>{
    const tl=content.querySelector('.st-tl');
    if(tl) tl.scrollIntoView({block:'end',behavior:'smooth'});
  }, 100);
}

// ── SUBMIT UPDATE ─────────────────────────────────────────
async function submitUpdate(issueId){
  const body = document.getElementById('st-ta')?.value.trim();
  if(!body) return;
  const btn = document.getElementById('st-send-btn');
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';}
  const payload={action:'add_update',issue_id:issueId,body,status:document.getElementById('st-sel-status')?.value||''};
  const r=await fetch(ST_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok){
    const idx=issues.findIndex(x=>x.id==issueId);
    if(idx>=0) issues[idx]={...issues[idx],...d.issue};
    // Update sidebar row
    const row=document.getElementById('row-'+issueId);
    if(row){ const badges=row.querySelectorAll('.st-ir-badge'); if(badges[1]){badges[1].className=`st-ir-badge ${d.issue.status}`;badges[1].textContent=STATUS_LABELS[d.issue.status]?.toLowerCase()||d.issue.status;} }
    renderIssuePage(d.issue, d.updates);
  }
  if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Enviar';}
}

// ── LIST PANEL ────────────────────────────────────────────
function toggleList(){ listOpen=!listOpen; document.getElementById('st-list-panel').classList.toggle('open',listOpen); }

// ── MODAL ─────────────────────────────────────────────────
function openModal(id=null){
  document.getElementById('st-modal').classList.add('open');
  if(id){
    const iss=issues.find(x=>x.id==id); if(!iss) return;
    document.getElementById('st-modal-title').textContent='Editar situación';
    document.getElementById('m-id').value=iss.id;
    document.getElementById('m-title').value=iss.title||'';
    document.getElementById('m-desc').value=iss.description||'';
    document.getElementById('m-status').value=iss.status||'abierto';
    document.getElementById('m-category').value=iss.category||'';
    document.getElementById('m-team').value=iss.team||'';
    document.getElementById('m-assigned').value=iss.assigned_to||'';
    selPrio(iss.priority||'media');
    document.getElementById('m-del-btn').style.display=IS_ADMIN?'':'none';
  } else {
    document.getElementById('st-modal-title').textContent='Nueva situación';
    document.getElementById('m-id').value='';
    document.getElementById('m-title').value='';
    document.getElementById('m-desc').value='';
    document.getElementById('m-status').value='abierto';
    document.getElementById('m-category').value='';
    document.getElementById('m-team').value='';
    document.getElementById('m-assigned').value='';
    selPrio('media');
    document.getElementById('m-del-btn').style.display='none';
  }
  setTimeout(()=>document.getElementById('m-title').focus(),50);
}
// ── Antes código en línea (CSP estricta, SEC-24) ──
function stGo(url){ location.href = url; }
function stModalBg(e){ if (e.target.id === 'st-modal') closeModal(); }
/** Ctrl/Cmd + Enter en el comentario: lo manda. */
function stTaKey(e, id){ if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); submitUpdate(id); } }
function closeModal(){document.getElementById('st-modal').classList.remove('open');}
function selPrio(v){document.getElementById('m-priority').value=v;document.querySelectorAll('.st-prio-btn').forEach(b=>b.classList.toggle('sel',b.classList.contains(v)));}

async function saveIssue(){
  const btn=document.getElementById('m-save-btn');
  btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  const payload={action:'save_issue',id:parseInt(document.getElementById('m-id').value)||0,title:document.getElementById('m-title').value.trim(),description:document.getElementById('m-desc').value,priority:document.getElementById('m-priority').value,status:document.getElementById('m-status').value,category:document.getElementById('m-category').value,team:document.getElementById('m-team').value,assigned_to:parseInt(document.getElementById('m-assigned').value)||0};
  if(!payload.title){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Guardar';document.getElementById('m-title').focus();return;}
  const r=await fetch(ST_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const d=await r.json();
  if(d.ok){
    const idx=issues.findIndex(x=>x.id==d.issue.id);
    if(idx>=0) issues[idx]=d.issue; else { issues.unshift(d.issue); }
    closeModal();
    const newIdx=issues.findIndex(x=>x.id==d.issue.id);
    curIdx=newIdx>=0?newIdx:0;
    updateNavUI();
    loadIssue(d.issue.id);
  }
  btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-check"></i> Guardar';
}

async function deleteIssue(){
  if(!confirm('¿Eliminar esta situación y todo su historial?')) return;
  const id=parseInt(document.getElementById('m-id').value);
  await fetch(ST_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_issue',id})});
  issues=issues.filter(x=>x.id!==id);
  document.getElementById('row-'+id)?.remove();
  closeModal();
  curIdx=Math.min(curIdx,issues.length-1);
  updateNavUI();
  if(issues.length) loadIssue(issues[curIdx].id);
  else document.getElementById('st-content').innerHTML='<div class="st-empty"><div class="st-empty-icon"><i class="fa-solid fa-shield-halved"></i></div><h2>TODO EN VERDE</h2></div>';
}

document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){closeModal();if(listOpen)toggleList();}
  if(!e.target.matches('input,textarea,select')){
    if(e.key==='ArrowLeft'&&curIdx>0) navigate(-1);
    if(e.key==='ArrowRight'&&curIdx<issues.length-1) navigate(1);
  }
});

// ── INIT ──────────────────────────────────────────────────
renderChart();
if(issues.length){updateNavUI();loadIssue(issues[0].id);}
