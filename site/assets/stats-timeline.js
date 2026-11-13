/* assets/stats-timeline.js — Timeline de stats.php (detalle de cada pieza), sin código en línea (CSP estricta, SEC-24). Los datos llegan en #tl-data. */
const TL_DATA = JSON.parse(document.getElementById('tl-data').textContent);   // datos de PHP (stats.php)
const TL_URL = '/toolz/stats.php';
const TL_ITEMS = TL_DATA.items;
const TL_PROJ_COLORS = TL_DATA.colors;

function tlOpenDetail(id){
  const item = TL_ITEMS.find(i=>i.id===id);
  if(!item) return;
  const color = TL_PROJ_COLORS[item.project] || 'var(--g7-accent)';
  const typeLabel = {promo:'Promo',launch:'Launch',paid:'Paid',social:'Social',otro:'Otro',custom:'Custom',tarea:'Tarea rápida'}[item.type]||item.type;
  const dt = new Date(item.created_at);
  const dateStr = dt.toLocaleDateString('es-AR',{weekday:'long',day:'numeric',month:'long'});
  const timeStr = dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'});

  document.getElementById('tl-drawer-title').textContent = item.name;
  document.getElementById('tl-drawer-body').innerHTML = `
    <!-- Header -->
    <div style="border-left:3px solid ${color};padding:10px 12px;background:${color}10;border-radius:0 8px 8px 0;margin-bottom:16px">
      <div style="font-size:15px;font-weight:800;margin-bottom:3px">${esc(item.name)}</div>
      <div style="font-size:11px;color:var(--g7-text-4)">${esc(item.project||'Sin proyecto')}${item.category?' · '+esc(item.category):''}</div>
    </div>

    <!-- Meta -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
      <div style="background:var(--g7-bg-4);border-radius:8px;padding:10px 12px">
        <div style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px">Tipo</div>
        <div style="font-size:13px;font-weight:700">${typeLabel}</div>
      </div>
      <div style="background:var(--g7-bg-4);border-radius:8px;padding:10px 12px">
        <div style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px">Fecha</div>
        <div style="font-size:12px;font-weight:600">${dateStr}</div>
        <div style="font-size:10px;color:var(--g7-text-5)">${timeStr}</div>
      </div>
    </div>

    <!-- Cantidades -->
    ${(item.qty_graficas||item.qty_videos||item.qty_tareas)?`
    <div style="margin-bottom:14px">
      <div style="font-size:9px;font-weight:700;color:var(--g7-text-5);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Producción</div>
      <div style="display:flex;gap:8px">
        ${item.qty_graficas?`<div style="flex:1;background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.2);border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#60a5fa">${item.qty_graficas}</div><div style="font-size:9px;color:var(--g7-text-5);margin-top:2px">Gráficas</div></div>`:''}
        ${item.qty_videos?`<div style="flex:1;background:rgba(167,139,250,.08);border:1px solid rgba(167,139,250,.2);border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#a78bfa">${item.qty_videos}</div><div style="font-size:9px;color:var(--g7-text-5);margin-top:2px">Videos</div></div>`:''}
        ${item.qty_tareas?`<div style="flex:1;background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.2);border-radius:8px;padding:10px;text-align:center"><div style="font-size:20px;font-weight:800;color:#4ade80">${item.qty_tareas}</div><div style="font-size:9px;color:var(--g7-text-5);margin-top:2px">Tareas</div></div>`:''}
      </div>
    </div>`:''}

    ${item.edit_type?`
    <div style="margin-bottom:14px;background:${item.edit_type==='rechazo'?'rgba(239,68,68,.08)':'rgba(245,158,11,.08)'};border:1px solid ${item.edit_type==='rechazo'?'rgba(239,68,68,.2)':'rgba(245,158,11,.2)'};border-radius:8px;padding:10px 12px;display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">${item.edit_type==='rechazo'?'✗':'↩'}</span>
      <span style="font-size:12px;font-weight:700;color:${item.edit_type==='rechazo'?'#ef4444':'#f59e0b'}">Registrado como ${item.edit_type==='rechazo'?'Rechazo':'Revisión'}</span>
    </div>`:''}

    <!-- Links -->
    ${[['fa-brands fa-google-drive','#4ade80','Gráficas',item.graphics],...(()=>{const l=String(item.landing||'').split(/[\r\n]+/).map(s=>s.trim()).filter(Boolean);return l.map((u,i)=>['fa-solid fa-globe','#60a5fa','Landing'+(l.length>1?' '+(i+1):''),u]);})(),['fa-solid fa-envelope','#f59e0b','Mailing',item.mailing]].filter(([,,, v])=>v).map(([icon,col,lbl,url])=>`
      <a href="${url}" target="_blank" rel="noopener" class="tl-link" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;text-decoration:none;color:var(--g7-text);margin-bottom:6px;transition:border-color .15s;--hov:${col}">
        <i class="${icon}" style="color:${col};font-size:16px;flex-shrink:0;width:20px;text-align:center"></i>
        <span style="font-size:12px;font-weight:600;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${lbl}</span>
        <i class="fa-solid fa-arrow-up-right-from-square" style="color:var(--g7-text-5);font-size:10px;flex-shrink:0"></i>
      </a>`).join('')}

    <a href="/toolz/promos/?search=${encodeURIComponent(item.name)}" target="_blank" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:9px;background:var(--g7-bg-4);border:1px solid var(--g7-border-3);border-radius:8px;text-decoration:none;color:var(--g7-text-3);font-size:11px;font-weight:700;margin-top:8px">
      <i class="fa-solid fa-arrow-up-right-from-square"></i> Ver en Promos
    </a>`;

  document.getElementById('tl-drawer').style.right = '0';
  document.getElementById('tl-overlay').style.display = 'block';
}

function tlCloseDrawer(){
  document.getElementById('tl-drawer').style.right = '-420px';
  document.getElementById('tl-overlay').style.display = 'none';
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('keydown', e=>{ if(e.key==='Escape') tlCloseDrawer(); });
