/* assets/teams-page.js — Script de teams.php (Equipos), sin código en línea (CSP estricta, SEC-24). Los datos de PHP llegan en #tm-cfg. */
const TM_URL = '/toolz/teams.php';
const TM_CFG = JSON.parse(document.getElementById('tm-cfg').textContent);   // datos de PHP (teams.php)
const IS_ADMIN = !!TM_CFG.isAdmin;
const MY_ID = TM_CFG.myId;

let allTeams    = [];
let allUsers    = [];
let currentTeam = null;
let currentPerms= {};

// ── UTILS ─────────────────────────────────────────────
function $(id){ return document.getElementById(id); }
function tmToast(msg, err=false){
  const t=$('tm-toast'); t.textContent=msg;
  t.style.background=err?'#ef4444':'#22c55e'; t.style.color=err?'#fff':'#000';
  t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>t.style.transform='translateX(-50%) translateY(60px)',2200);
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function teamInitial(name){ return (name||'?')[0].toUpperCase(); }

// ── LOAD TEAMS ────────────────────────────────────────
async function tmLoadTeams(){
  const r = await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_teams'})});
  const d = await r.json();
  allTeams = d.teams || [];
  tmRenderTeamList(allTeams);
}

function tmRenderTeamList(teams){
  const list = $('tm-team-list');
  if(!teams.length){
    list.innerHTML='<div style="padding:16px;text-align:center;font-size:12px;color:var(--g7-text-5)">Sin equipos</div>';
    return;
  }
  list.innerHTML = teams.map(t=>`
    <div class="tm-team-item ${currentTeam?.id==t.id?'active':''}" id="tli-${t.id}" data-on-click="tmSelectTeam" data-click-1="j:${t.id}">
      <div class="tm-team-avatar" style="background:${esc(t.color)}">${teamInitial(t.name)}</div>
      <div>
        <div class="tm-team-name">${esc(t.name)}</div>
        <div class="tm-team-count">${t.member_count||0} miembro${t.member_count!=1?'s':''}</div>
      </div>
    </div>`).join('');
}

function tmFilterTeams(q){
  const f = allTeams.filter(t=>t.name.toLowerCase().includes(q.toLowerCase()));
  tmRenderTeamList(f);
}

// ── SELECT TEAM ───────────────────────────────────────
async function tmSelectTeam(id){
  document.querySelectorAll('.tm-team-item').forEach(el=>el.classList.remove('active'));
  const el = $('tli-'+id); if(el) el.classList.add('active');
  $('tm-content').innerHTML='<div style="padding:40px;text-align:center;color:var(--g7-text-5)"><i class="fa-solid fa-spinner fa-spin fa-lg"></i></div>';

  const r = await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_team',team_id:id})});
  const d = await r.json();
  if(!d.ok){ tmToast(d.error||'Error',true); return; }
  currentTeam = d.team;

  // Build permissions map
  currentPerms = {};
  (d.permissions||[]).forEach(p=>{ if(!currentPerms[p.module]) currentPerms[p.module]={}; currentPerms[p.module][p.action]=true; });

  tmRenderTeamDetail(d);
}

// ── RENDER TEAM DETAIL ────────────────────────────────
function tmRenderTeamDetail(d){
  const {team, members, config} = d;
  const canEdit = IS_ADMIN || members.some(m=>m.id==MY_ID && m.team_role==='admin');

  $('tm-content').innerHTML = `
    <!-- HEADER -->
    <div class="tm-head">
      <div class="tm-head-avatar" style="background:${esc(team.color)}">${teamInitial(team.name)}</div>
      <div style="flex:1;min-width:0">
        <div class="tm-head-name">${esc(team.name)}</div>
        <div class="tm-head-meta">${esc(team.description||'Sin descripción')} · ${members.length} miembro${members.length!=1?'s':''}</div>
      </div>
      ${IS_ADMIN ? `<button class="btn btn-danger btn-sm" data-on-click="tmDeleteTeam" data-click-1="j:${team.id}"><i class="fa-solid fa-trash"></i> Eliminar</button>` : ''}
    </div>

    <!-- TABS -->
    <div class="tm-tabs">
      <button class="tm-tab active" data-on-click="tmShowTab" data-click-1="s:members" data-click-2="this"><i class="fa-solid fa-users"></i> Miembros</button>
      ${canEdit ? `<button class="tm-tab" data-on-click="tmShowTab" data-click-1="s:perms" data-click-2="this"><i class="fa-solid fa-lock"></i> Permisos</button>` : ''}
      ${canEdit ? `<button class="tm-tab" data-on-click="tmShowTab" data-click-1="s:settings" data-click-2="this"><i class="fa-solid fa-gear"></i> Configuración</button>` : ''}
    </div>

    <!-- MEMBERS TAB -->
    <div class="tm-tab-content active" id="tab-members">
      <div class="tm-card">
        <div class="tm-card-head">
          <div class="tm-card-title"><i class="fa-solid fa-users"></i> Miembros del equipo</div>
          ${canEdit ? `<button class="btn btn-primary btn-sm" data-on-click="tmOpenAddMember" data-click-1="j:${team.id}"><i class="fa-solid fa-plus"></i> Agregar</button>` : ''}
        </div>
        <div class="tm-card-body" style="padding:0">
          <div style="padding:0 18px">
            ${members.map(m=>`
            <div class="tm-member">
              ${m.avatar ? `<img src="${esc(m.avatar)}" class="tm-member-av">` : `<div class="tm-member-av-placeholder">${(m.display_name||m.username||'?')[0].toUpperCase()}</div>`}
              <div style="flex:1;min-width:0">
                <div class="tm-member-name">${esc(m.display_name||m.username)}</div>
                <div class="tm-member-meta">@${esc(m.username)} · ${esc(m.email||'')}</div>
              </div>
              <div style="display:flex;gap:6px;align-items:center">
                ${m.is_primary ? '<span class="badge badge-primary">Principal</span>' : ''}
                <span class="badge ${m.team_role==='admin'?'badge-admin':'badge-member'}">${m.team_role==='admin'?'Admin':'Miembro'}</span>
                ${canEdit && m.id!=MY_ID ? `
                <select data-on-change="tmUpdateRole" data-change-1="j:${team.id}" data-change-2="j:${m.id}" data-change-3="value" style="background:var(--g7-bg-4);border:1px solid var(--g7-border-3);color:var(--g7-text);border-radius:6px;padding:3px 6px;font-size:10px;cursor:pointer">
                  <option value="member" ${m.team_role==='member'?'selected':''}>Miembro</option>
                  <option value="admin"  ${m.team_role==='admin'?'selected':''}>Admin</option>
                </select>
                <button class="btn btn-danger btn-sm" data-on-click="tmRemoveMember" data-click-1="j:${team.id}" data-click-2="j:${m.id}" data-click-3="s:${esc(m.display_name||m.username)}"><i class="fa-solid fa-xmark"></i></button>
                ` : ''}
              </div>
            </div>`).join('')}
          </div>
        </div>
      </div>
    </div>

    <!-- PERMISSIONS TAB -->
    <div class="tm-tab-content" id="tab-perms">
      ${tmRenderPermissions(IS_ADMIN)}
      ${IS_ADMIN ? `<div style="margin-top:16px"><button class="btn btn-primary" data-on-click="tmSavePermissions" data-click-1="j:${team.id}"><i class="fa-solid fa-floppy-disk"></i> Guardar permisos</button></div>` : ''}
    </div>

    <!-- SETTINGS TAB -->
    <div class="tm-tab-content" id="tab-settings">
      <div class="tm-card">
        <div class="tm-card-head"><div class="tm-card-title"><i class="fa-solid fa-pen"></i> Información del equipo</div></div>
        <div class="tm-card-body">
          <div class="tm-row2" style="margin-bottom:12px">
            <div>
              <label class="tm-label">Nombre</label>
              <input class="tm-input" id="ts-name" value="${esc(team.name)}">
            </div>
            <div>
              <label class="tm-label">Color</label>
              <div style="display:flex;gap:8px;align-items:center">
                <input type="color" id="ts-color" value="${esc(team.color)}" style="width:36px;height:34px;border:none;border-radius:6px;cursor:pointer;padding:2px" data-on-input="tsColorPreview" data-input-1="this">
                <div style="width:36px;height:34px;border-radius:8px;background:${esc(team.color)}"></div>
              </div>
            </div>
          </div>
          <div style="margin-bottom:16px">
            <label class="tm-label">Descripción</label>
            <textarea class="tm-input" id="ts-desc" rows="2">${esc(team.description||'')}</textarea>
          </div>
          <button class="btn btn-primary" data-on-click="tmSaveSettings" data-click-1="j:${team.id}"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
        </div>
      </div>
    </div>
  `;
}

// ── PERMISSIONS RENDER ────────────────────────────────
const MODULES = TM_CFG.modules;
const ACTION_LABELS = {view:'Ver',create:'Crear',edit:'Editar',delete:'Eliminar',upload:'Subir',manage:'Gestionar',read:'Leer',write:'Escribir',config:'Configurar',design:'Diseñar',publish:'Publicar'};

function tmRenderPermissions(canEdit){
  return `<div class="tm-perm-grid">` +
    Object.entries(MODULES).map(([mod,cfg])=>`
      <div class="tm-perm-module">
        <div class="tm-perm-module-name"><i class="fa-solid ${cfg.icon}"></i> ${cfg.label}</div>
        <div class="tm-perm-actions">
          ${cfg.actions.map(act=>`
            <label class="tm-perm-action">
              <input type="checkbox" data-mod="${mod}" data-act="${act}"
                ${currentPerms[mod]?.[act] ? 'checked' : ''}
                ${canEdit ? '' : 'disabled'}>
              <span>${ACTION_LABELS[act]||act}</span>
            </label>`).join('')}
        </div>
      </div>`).join('') + `</div>`;
}

async function tmSavePermissions(teamId){
  const perms = [];
  document.querySelectorAll('[data-mod][data-act]:checked').forEach(cb=>{
    perms.push({module:cb.dataset.mod, action:cb.dataset.act});
  });
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_permissions',team_id:teamId,permissions:perms})});
  const d=await r.json();
  if(d.ok) tmToast('Permisos guardados ✓');
  else tmToast(d.error||'Error',true);
}

// ── TABS ──────────────────────────────────────────────
function tmShowTab(name, btn){
  document.querySelectorAll('.tm-tab').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tm-tab-content').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  $('tab-'+name)?.classList.add('active');
}

// ── SETTINGS ──────────────────────────────────────────
async function tmSaveSettings(teamId){
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    action:'update_team', team_id:teamId,
    name:$('ts-name').value, color:$('ts-color').value, description:$('ts-desc').value
  })});
  const d=await r.json();
  if(d.ok){ tmToast('Guardado ✓'); tmLoadTeams(); tmSelectTeam(teamId); }
  else tmToast(d.error||'Error',true);
}

// ── CREATE TEAM ───────────────────────────────────────
function tmOpenCreate(){ $('tc-name').value=''; $('tc-desc').value=''; $('tc-error').style.display='none'; $('tm-create-modal').classList.add('open'); setTimeout(()=>$('tc-name').focus(),50); }
function tmCloseCreate(){ $('tm-create-modal').classList.remove('open'); }

document.getElementById('tc-color')?.addEventListener('input', e=>{
  $('tc-color-preview').style.background = e.target.value;
});

async function tmCreateTeam(){
  const name=$('tc-name').value.trim();
  const err=$('tc-error');
  err.style.display='none';
  if(!name){ err.textContent='El nombre es requerido'; err.style.display='block'; return; }
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
    action:'create_team', name, color:$('tc-color').value, description:$('tc-desc').value
  })});
  const d=await r.json();
  if(d.ok){ tmCloseCreate(); tmToast('Equipo creado ✓'); tmLoadTeams(); }
  else { err.textContent=d.error||'Error al crear'; err.style.display='block'; }
}

// ── DELETE TEAM ───────────────────────────────────────
async function tmDeleteTeam(id){
  if(!confirm('¿Eliminar este equipo? Se eliminarán todos sus miembros y permisos.')) return;
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_team',team_id:id})});
  const d=await r.json();
  if(d.ok){ tmToast('Equipo eliminado'); tmLoadTeams(); currentTeam=null; $('tm-content').innerHTML='<div class="tm-empty"><i class="fa-solid fa-people-group"></i><div>Selecciona un equipo</div></div>'; }
  else tmToast(d.error||'Error',true);
}

// ── MEMBERS ───────────────────────────────────────────
async function tmOpenAddMember(teamId){
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_users',team_id:teamId})});
  const d=await r.json();
  allUsers=d.users||[];
  tmRenderUserList(allUsers, teamId);
  $('am-search').value='';
  $('tm-add-member-modal').classList.add('open');
}
function tmCloseAddMember(){ $('tm-add-member-modal').classList.remove('open'); }

function tmRenderUserList(users, teamId){
  const list=$('am-list');
  if(!users.length){ list.innerHTML='<div style="font-size:12px;color:var(--g7-text-5);padding:10px">Sin usuarios disponibles</div>'; return; }
  list.innerHTML=users.map(u=>`
    <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px">
      ${u.avatar?`<img src="${esc(u.avatar)}" style="width:32px;height:32px;border-radius:50%;object-fit:cover">`:`<div style="width:32px;height:32px;border-radius:50%;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--g7-accent)">${(u.display_name||u.username||'?')[0].toUpperCase()}</div>`}
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:600">${esc(u.display_name||u.username)}</div>
        <div style="font-size:10px;color:var(--g7-text-4)">@${esc(u.username)}</div>
      </div>
      <button class="btn btn-primary btn-sm" data-on-click="tmAddMember" data-click-1="j:${teamId}" data-click-2="j:${u.id}"><i class="fa-solid fa-plus"></i></button>
    </div>`).join('');
}

function tmFilterUsers(q){
  const tid=currentTeam?.id;
  const f=allUsers.filter(u=>(u.display_name||'').toLowerCase().includes(q.toLowerCase())||(u.username||'').toLowerCase().includes(q.toLowerCase()));
  tmRenderUserList(f,tid);
}

async function tmAddMember(teamId, userId){
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_member',team_id:teamId,user_id:userId,role:'member'})});
  const d=await r.json();
  if(d.ok){ tmToast('Miembro agregado ✓'); tmCloseAddMember(); tmSelectTeam(teamId); }
  else tmToast(d.error||'Error',true);
}

async function tmRemoveMember(teamId, userId, name){
  if(!confirm(`¿Quitar a ${name} del equipo?`)) return;
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'remove_member',team_id:teamId,user_id:userId})});
  const d=await r.json();
  if(d.ok){ tmToast(`${name} removido ✓`); tmSelectTeam(teamId); }
  else tmToast(d.error||'Error',true);
}

async function tmUpdateRole(teamId, userId, role){
  const r=await fetch(TM_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update_member_role',team_id:teamId,user_id:userId,role})});
  const d=await r.json();
  if(d.ok) tmToast('Rol actualizado ✓');
  else tmToast(d.error||'Error',true);
}

/** Muestra el color elegido en el cuadrito de al lado. */
function tsColorPreview(el){ el.nextElementSibling.style.background = el.value; }

// ── KEYBOARD ──────────────────────────────────────────
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ tmCloseCreate(); tmCloseAddMember(); } });

// ── INIT ──────────────────────────────────────────────
tmLoadTeams();
