/* assets/monday-page.js — Script de monday.php (tablero de Monday), sin código en línea (CSP estricta, SEC-24). Usuarios y asignaciones llegan en #mo-users y #mo-assign. */
const ALL_USERS = JSON.parse(document.getElementById('mo-users').textContent);   // datos de PHP (monday.php)
const ASSIGNMENTS = JSON.parse(document.getElementById('mo-assign').textContent);
let currentAssignItemId = null;
let localAssignments = JSON.parse(JSON.stringify(ASSIGNMENTS));

function avJS(u, size) {
  if (u.avatar) return `<img src="${u.avatar}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover">`;
  const i = (u.display_name||u.username||'?')[0].toUpperCase();
  const c = ['#ff6600','#a78bfa','#4ade80','#60a5fa','#f59e0b','#ec4899'][i.charCodeAt(0)%6];
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${c};display:flex;align-items:center;justify-content:center;font-size:${Math.floor(size*.45)}px;font-weight:700;color:#000">${i}</div>`;
}

function openAssignFromPrio(itemId, taskName) {
  // close prio modal, open assign modal, then reopen prio on close
  document.getElementById('prio-modal').classList.remove('open');
  currentAssignFromPrio = true;
  openAssign(itemId, taskName);
}
let currentAssignFromPrio = false;

function openAssign(itemId, taskName) {
  currentAssignItemId = itemId;
  document.getElementById('assign-task-name').textContent = taskName;
  renderAssignList();
  document.getElementById('assign-modal').classList.add('open');
}
function closeAssign() {
  document.getElementById('assign-modal').classList.remove('open');
  if (currentAssignFromPrio) {
    currentAssignFromPrio = false;
    document.getElementById('prio-modal').classList.add('open');
    // refresh assignee icons in prio modal
    updatePrioAssignees(currentAssignItemId);
  }
  currentAssignItemId = null;
}

function renderAssignList() {
  const assigned = (localAssignments[currentAssignItemId]||[]).map(a=>a.user_id);
  document.getElementById('assign-user-list').innerHTML = ALL_USERS.map(u => {
    const isAssigned = assigned.includes(u.id);
    return `<div class="assign-user-item ${isAssigned?'assigned':''}" data-on-click="toggleAssign" data-click-1="j:${u.id}">
      ${avJS(u, 32)}
      <div style="flex:1">
        <div style="font-size:12px;font-weight:600">${u.display_name||u.username}</div>
        <div style="font-size:10px;color:#52525b">@${u.username}</div>
      </div>
      <div style="width:18px;height:18px;border-radius:50%;border:2px solid ${isAssigned?'#ff6600':'#27272a'};background:${isAssigned?'#ff6600':'transparent'};display:flex;align-items:center;justify-content:center;font-size:10px;color:#000;transition:all .15s">
        ${isAssigned?'<i class="fa-solid fa-check" style="font-size:9px"></i>':''}
      </div>
    </div>`;
  }).join('');
}

async function toggleAssign(userId) {
  const assigned = (localAssignments[currentAssignItemId]||[]).map(a=>a.user_id);
  const isAssigned = assigned.includes(userId);
  const action = isAssigned ? 'unassign' : 'assign';
  const u = ALL_USERS.find(x=>x.id===userId);

  const res  = await fetch('/toolz/monday.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,item_id:currentAssignItemId,user_id:userId})});
  const data = await res.json();
  if (data.status==='success') {
    if (!localAssignments[currentAssignItemId]) localAssignments[currentAssignItemId]=[];
    if (isAssigned) {
      localAssignments[currentAssignItemId] = localAssignments[currentAssignItemId].filter(a=>a.user_id!==userId);
    } else {
      localAssignments[currentAssignItemId].push({user_id:userId,username:u.username,display_name:u.display_name,avatar:u.avatar});
    }
    renderAssignList();
    updateCardAssignees(currentAssignItemId);
  }
}

async function unassign(itemId, userId, el) {
  const res  = await fetch('/toolz/monday.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'unassign',item_id:itemId,user_id:userId})});
  const data = await res.json();
  if (data.status==='success') {
    if (localAssignments[itemId]) localAssignments[itemId] = localAssignments[itemId].filter(a=>a.user_id!==userId);
    el.remove();
  }
}

function updateCardAssignees(itemId) {
  const card = document.getElementById('tc-'+itemId);
  if (!card) return;
  const wrap = card.querySelector('.tc-assignees');
  if (!wrap) return;
  const assignees = localAssignments[itemId]||[];
  const addBtn = `<div class="add-assign-btn" data-on-click="openAssign" data-click-1="s:${itemId}" data-click-2="s:" title="Asignar"><i class="fa-solid fa-plus" style="font-size:9px"></i></div>`;
  wrap.innerHTML = assignees.map(au => `
    <div class="assign-avatar" title="${au.display_name||au.username}" data-on-click="unassign" data-click-1="s:${itemId}" data-click-2="j:${au.user_id}" data-click-3="this">
      ${avJS(au,22)}
      <div class="remove-x">✕</div>
    </div>`).join('') + addBtn;
}

function toggleKbDesc(nameEl) {
  const desc = nameEl.parentElement.querySelector('.kb-desc');
  const icon = nameEl.querySelector('i.fa-chevron-down');
  if (!desc) return;
  const isOpen = desc.style.display !== 'none';
  desc.style.display = isOpen ? 'none' : 'block';
  if (icon) icon.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function togglePrioDesc(nameEl) {
  const desc = nameEl.parentElement.querySelector('.prio-desc');
  const icon = nameEl.querySelector('i.fa-chevron-down');
  if (!desc) return;
  const isOpen = desc.style.display !== 'none';
  desc.style.display = isOpen ? 'none' : 'block';
  if (icon) icon.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function updatePrioAssignees(itemId) {
  // find the assign row inside prio modal for this item
  const rows = document.querySelectorAll('#prio-modal [data-item-id="'+itemId+'"]');
  rows.forEach(row => {
    const assignees = localAssignments[itemId]||[];
    const addBtn = row.querySelector('.prio-add-btn');
    // remove old avatars
    row.querySelectorAll('.prio-av').forEach(el=>el.remove());
    assignees.forEach(au => {
      const d = document.createElement('div');
      d.className='prio-av';
      d.title=au.display_name||au.username;
      d.innerHTML=avJS(au,20);
      row.insertBefore(d, addBtn);
    });
  });
}

// ── Antes código en línea (CSP estricta, SEC-24) ──
function moPrioOpen() { document.getElementById('prio-modal').classList.add('open'); }
function moPrioClose() { document.getElementById('prio-modal').classList.remove('open'); }
function moPrioBg(el, e) { if (e.target === el) el.classList.remove('open'); }
function moAssignBg(el, e) { if (e.target === el) closeAssign(); }
/** Nombre de la tarjeta: despliega la descripción sin abrir el link. */
function moKbDesc(el, e) { e.preventDefault(); toggleKbDesc(el); }
function moPrioDesc(el, e) { e.preventDefault(); e.stopPropagation(); togglePrioDesc(el); }
function moPrioAssign(e, itemId, taskName) { e.preventDefault(); e.stopPropagation(); openAssignFromPrio(itemId, taskName); }
// Los asignados de cada tarjeta no abren el link de la tarea (los botones de adentro siguen funcionando)
document.querySelectorAll('.tc-assignees').forEach(w => w.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); }));

document.addEventListener('keydown', e => {
  if (e.key==='Escape') {
    document.getElementById('prio-modal').classList.remove('open');
    closeAssign();
  }
});
