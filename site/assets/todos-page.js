/* assets/todos-page.js — Script de todos.php (Tareas personales), sin código en línea (CSP estricta, SEC-24). Las listas llegan en #td-pending y #td-done. */
const TD_URL = '/toolz/todos.php';
let pendingTodos = JSON.parse(document.getElementById('td-pending').textContent);   // datos de PHP (todos.php)
let doneTodos    = JSON.parse(document.getElementById('td-done').textContent);
let completingId = null;

const tdEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
async function tdApi(action, data = {}) {
  try {
    const r = await fetch(TD_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...data }) });
    const d = await r.json();
    if (!d.ok) throw new Error(d.error || 'No se pudo guardar');
    return d;
  } catch (e) { showToast(e.message === 'Failed to fetch' ? 'No hay conexión con el servidor' : e.message, '#f87171'); return null; }
}

// ── LISTAS ────────────────────────────────────────────────
function renderStats() {
  document.getElementById('stat-pending').textContent = pendingTodos.length + ' pendientes';
  document.getElementById('stat-done').textContent = doneTodos.length + ' completadas';
}
function renderPending() {
  const box = document.getElementById('td-list');
  box.innerHTML = pendingTodos.length ? pendingTodos.map(t => `
    <div class="td-card" data-id="${+t.id}" style="position:relative">
      <div class="td-drag" draggable="true" title="Arrastra para ordenar"><i class="fa-solid fa-grip-vertical"></i></div>
      <div class="td-check-wrap"><button class="td-check" data-on-click="completeTodo" data-click-1="j:${+t.id}" title="Completar" aria-label="Completar"></button></div>
      <div class="td-body">
        <div class="td-card-title">${tdEsc(t.title)}</div>
        ${t.description ? `<div class="td-card-desc">${tdEsc(t.description)}</div>` : ''}
        ${t.project ? `<span class="td-card-proj">${tdEsc(t.project)}</span>` : ''}
      </div>
      <div class="td-actions">
        <button class="td-act-btn" data-on-click="openEdit" data-click-1="j:${+t.id}" title="Editar" aria-label="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="td-act-btn danger" data-on-click="deleteTodo" data-click-1="j:${+t.id}" title="Borrar" aria-label="Borrar"><i class="fa-solid fa-trash"></i></button>
      </div>
      <div class="td-edit-overlay" id="edit-${+t.id}">
        <input class="td-inp" id="edit-title-${+t.id}" value="${tdEsc(t.title)}" aria-label="Título" data-on-keydown="tdEditKey" data-keydown-1="event" data-keydown-2="j:${+t.id}">
        <div class="td-edit-row">
          <input class="td-inp-sm" id="edit-desc-${+t.id}" value="${tdEsc(t.description)}" placeholder="Descripción (opcional)" aria-label="Descripción">
          <input class="td-inp-sm" id="edit-proj-${+t.id}" value="${tdEsc(t.project)}" placeholder="Proyecto" list="proj-list" aria-label="Proyecto">
        </div>
        <div class="td-edit-row">
          <button class="td-edit-save" data-on-click="saveEdit" data-click-1="j:${+t.id}">Guardar</button>
          <button class="td-edit-cancel" data-on-click="closeEdit" data-click-1="j:${+t.id}">Cancelar</button>
        </div>
      </div>
    </div>`).join('')
    : '<div class="td-empty" id="td-empty"><i class="fa-solid fa-check-double"></i>¡Todo al día! Agrega una tarea arriba.</div>';
  renderStats();
}
function renderDone() {
  const box = document.getElementById('td-done-list'), sec = document.getElementById('td-done-sec');
  sec.style.display = doneTodos.length ? '' : 'none';
  box.innerHTML = doneTodos.map(t => `
    <div class="td-done-card">
      <div class="td-done-check"><i class="fa-solid fa-check"></i></div>
      <div class="td-done-title">${tdEsc(t.title)}</div>
      <div class="td-done-time">${tdEsc(String(t.completed_at || '').slice(0, 16))}</div>
      <div class="td-done-actions">
        <button class="td-done-btn promos" data-on-click="tdToPromos" data-click-1="j:${+t.id}">A Promos</button>
        <button class="td-done-btn" data-on-click="reopenTodo" data-click-1="j:${+t.id}">Reabrir</button>
        <button class="td-done-btn" data-on-click="deleteTodo" data-click-1="j:${+t.id}">Borrar</button>
      </div>
    </div>`).join('');
  renderStats();
}
const doneTitle = id => doneTodos.find(t => +t.id === id)?.title || '';
const doneProject = id => doneTodos.find(t => +t.id === id)?.project || '';

// ── AGREGAR, EDITAR, BORRAR ───────────────────────────────
async function quickAdd() {
  const ti = document.getElementById('new-title'), de = document.getElementById('new-desc'), pr = document.getElementById('new-proj');
  const title = ti.value.trim();
  if (!title) { ti.focus(); return; }
  const d = await tdApi('save_todo', { title, description: de.value.trim(), project: pr.value.trim() });
  if (!d) return;
  pendingTodos.push(d.todo);
  ti.value = ''; de.value = ''; pr.value = '';
  renderPending(); ti.focus();
}
function openEdit(id) { document.getElementById('edit-' + id).classList.add('open'); document.getElementById('edit-title-' + id).focus(); }
/** Enter guarda la edición; Escape la cierra sin cerrar otros diálogos. */
function tdEditKey(e, id) {
  if (e.key === 'Enter') saveEdit(id);
  if (e.key === 'Escape') { e.stopPropagation(); closeEdit(id); }
}
/** Abre el diálogo "A Promos" con el título y el proyecto de la tarea. */
function tdToPromos(id) { openPromosModal(id, doneTitle(id), doneProject(id)); }
/** Clic en el fondo del diálogo de Promos: lo saltea. */
function tdModalBg(e) { if (e.target.id === 'promos-modal') skipPromos(); }
function closeEdit(id) { document.getElementById('edit-' + id)?.classList.remove('open'); }
async function saveEdit(id) {
  const title = document.getElementById('edit-title-' + id).value.trim();
  if (!title) return showToast('El título no puede quedar vacío', '#f87171');
  const d = await tdApi('save_todo', { id, title, description: document.getElementById('edit-desc-' + id).value.trim(), project: document.getElementById('edit-proj-' + id).value.trim() });
  if (!d) return;
  pendingTodos = pendingTodos.map(t => +t.id === id ? d.todo : t);
  renderPending();
}
async function deleteTodo(id) {
  if (!confirm('¿Borrar esta tarea?')) return;
  if (!await tdApi('delete_todo', { id })) return;
  pendingTodos = pendingTodos.filter(t => +t.id !== id);
  doneTodos = doneTodos.filter(t => +t.id !== id);
  renderPending(); renderDone();
}

// ── COMPLETAR Y REABRIR ───────────────────────────────────
function completeTodo(id) {
  const t = pendingTodos.find(x => +x.id === id); if (!t) return;
  completingId = id;
  openPromosModal(id, t.title, t.project || '');
}
async function finishComplete(id) {
  const d = await tdApi('complete_todo', { id });
  if (!d) return;
  pendingTodos = pendingTodos.filter(t => +t.id !== id);
  doneTodos = [d.todo, ...doneTodos.filter(t => +t.id !== id)];
  renderPending(); renderDone();
}
async function reopenTodo(id) {
  if (!await tdApi('reopen_todo', { id })) return;
  const t = doneTodos.find(x => +x.id === id);
  doneTodos = doneTodos.filter(x => +x.id !== id);
  if (t) pendingTodos.push({ ...t, status: 'pending', completed_at: null });
  renderPending(); renderDone();
}

// ── ORDENAR ARRASTRANDO ───────────────────────────────────
let dragId = null;
const tdList = document.getElementById('td-list');
tdList.addEventListener('dragstart', e => {
  const c = e.target.closest('.td-card'); if (!c) return;
  dragId = +c.dataset.id; c.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setDragImage(c, 10, 10);
});
tdList.addEventListener('dragover', e => {
  if (dragId === null) return;
  e.preventDefault();
  tdList.querySelectorAll('.drag-over').forEach(x => x.classList.remove('drag-over'));
  const c = e.target.closest('.td-card');
  if (c && +c.dataset.id !== dragId) c.classList.add('drag-over');
});
tdList.addEventListener('drop', async e => {
  if (dragId === null) return;
  e.preventDefault();
  const c = e.target.closest('.td-card'), from = dragId; dragId = null;
  if (!c || +c.dataset.id === from) return renderPending();
  const moved = pendingTodos.find(t => +t.id === from);
  const before = pendingTodos.slice();
  pendingTodos = pendingTodos.filter(t => +t.id !== from);
  pendingTodos.splice(pendingTodos.findIndex(t => +t.id === +c.dataset.id), 0, moved);
  renderPending();
  if (!await tdApi('reorder', { ids: pendingTodos.map(t => +t.id) })) { pendingTodos = before; renderPending(); }
});
tdList.addEventListener('dragend', () => { dragId = null; tdList.querySelectorAll('.dragging,.drag-over').forEach(x => x.classList.remove('dragging', 'drag-over')); });

// ── TYPE SELECTOR ─────────────────────────────────────────
function selType(type){
  document.getElementById('pm-type').value = type;
  document.querySelectorAll('.pm-type-btn').forEach(b=>b.classList.toggle('sel', b.dataset.type===type));
  document.getElementById('pm-fields-tarea').style.display    = type==='tarea' ? '' : 'none';
  document.getElementById('pm-fields-promo').style.display    = ['promo','social','launch','paid','otro'].includes(type) ? '' : 'none';
  document.getElementById('pm-fields-mailings').style.display = type==='mailings' ? '' : 'none';
}


// ── PROMOS MODAL ──────────────────────────────────────────
function openPromosModal(todoId, title, project){
  document.getElementById('pm-todo-id').value = todoId;
  document.getElementById('pm-title').value   = title;
  document.getElementById('pm-proj').value    = project;
  document.getElementById('pm-qty-tarea').value = 1;
  document.getElementById('pm-graficas').value  = 0;
  document.getElementById('pm-videos').value    = 0;
  document.getElementById('pm-qty-mailings').value = 1;
  document.getElementById('pm-mailing-link').value = '';
  selType('tarea');
  document.getElementById('promos-modal').classList.add('open');
  setTimeout(()=>document.getElementById('pm-title').focus(),100);
}

async function saveToPromos(){
  const btn = document.getElementById('pm-save-btn');
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  const todoId  = parseInt(document.getElementById('pm-todo-id').value)||0;
  const title   = document.getElementById('pm-title').value.trim();
  const type    = document.getElementById('pm-type').value || 'tarea';
  const project = document.getElementById('pm-proj').value.trim();
  let qty=1, graficas=0, videos=0, mailingLink='';
  if(type==='tarea')    qty      = parseInt(document.getElementById('pm-qty-tarea')?.value)||1;
  if(type==='mailings'){ qty     = parseInt(document.getElementById('pm-qty-mailings')?.value)||1; mailingLink=document.getElementById('pm-mailing-link')?.value.trim()||''; }
  if(['promo','social','launch','paid','otro'].includes(type)){ graficas=parseInt(document.getElementById('pm-graficas')?.value)||0; videos=parseInt(document.getElementById('pm-videos')?.value)||0; }
  const r = await fetch(TD_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_to_promos',todo_id:todoId,title,type,qty,graficas,videos,mailing_link:mailingLink,project})});
  const d = await r.json();
  if(d.ok){
    closePromosModal();
    showToast('✓ Cargado en Promos');
    await finishComplete(todoId);
  } else {
    alert('Error: '+(d.error||'No se pudo guardar'));
  }
  btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-arrow-right-to-bracket"></i> Cargar en Promos';
}

async function skipPromos(){
  const todoId = parseInt(document.getElementById('pm-todo-id').value)||0;
  closePromosModal();
  if(todoId) await finishComplete(todoId);
}
function closePromosModal(){ document.getElementById('promos-modal').classList.remove('open'); completingId=null; }

function showToast(msg, color='var(--green)'){
  const t=document.createElement('div');
  t.textContent=msg;
  t.style.cssText=`position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:${color};color:#000;font-size:12px;font-weight:800;padding:9px 20px;border-radius:20px;z-index:9999;pointer-events:none;animation:fadeIn .2s ease;box-shadow:0 4px 20px rgba(0,0,0,.4)`;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),2500);
}

document.addEventListener('keydown',e=>{ if(e.key==='Escape') closePromosModal(); });

// ── INIT ──────────────────────────────────────────────────
renderPending();
renderDone();
