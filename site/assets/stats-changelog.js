/* assets/stats-changelog.js — Changelog de stats.php, sin código en línea (CSP estricta, SEC-24). */
const CL_URL = '/toolz/stats.php';
async function clAdd(){
  const inp  = document.getElementById('cl-input');
  const text = inp.value.trim();
  const err  = document.getElementById('cl-error');
  err.style.display='none';
  if(!text){ err.textContent='Escribe algo primero'; err.style.display='block'; return; }
  inp.disabled = true;
  try{
    const r = await fetch(CL_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add_changelog',text})});
    const d = await r.json();
    if(d.ok){
      inp.value='';
      // Prepend new entry to list
      const wrap = document.getElementById('cl-list-wrap');
      const entry = d.entry;
      const div = document.createElement('div');
      div.className='cl-entry';
      div.id='cle-'+entry.id;
      div.style.cssText='display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--g7-border)';
      const dt = new Date(entry.created_at);
      const timeStr = dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});
      const authorName = entry.display_name || entry.username;
      div.innerHTML=`
        <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;padding-top:2px">
          <span style="font-size:11px;color:var(--g7-text-4);min-width:36px">${timeStr}</span>
          <div style="width:22px;height:22px;border-radius:50%;background:var(--g7-accent-muted);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--g7-accent)">${authorName[0].toUpperCase()}</div>
        </div>
        <div style="flex:1;min-width:0">
          <span style="font-size:13px;color:var(--g7-text)">${entry.text}</span>
          <span style="font-size:10px;color:var(--g7-text-5);margin-left:8px">— ${authorName}</span>
        </div>
        <button data-on-click="clDelete" data-click-1="j:${entry.id}" style="background:none;border:none;color:var(--g7-text-5);cursor:pointer;font-size:11px;padding:2px 5px;opacity:.5;transition:opacity .15s" class="cl-del" title="Eliminar"><i class="fa-solid fa-xmark"></i></button>`;
      // Find or create today's date header
      const today = new Date().toLocaleDateString('es-AR');
      const firstEntry = wrap.querySelector('.cl-entry');
      if(firstEntry){
        wrap.insertBefore(div, firstEntry);
      } else {
        wrap.innerHTML='';
        wrap.appendChild(div);
      }
    } else {
      err.textContent = d.error || 'Error al guardar';
      err.style.display='block';
    }
  }catch(e){ err.textContent='Error de conexión'; err.style.display='block'; }
  inp.disabled=false; inp.focus();
}

async function clDelete(id){
  if(!confirm('¿Eliminar esta entrada del changelog?')) return;
  const r = await fetch(CL_URL,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete_changelog',id})});
  const d = await r.json();
  if(d.ok){ const el=document.getElementById('cle-'+id); if(el) el.remove(); }
}
