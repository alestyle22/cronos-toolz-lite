/* assets/home-3.js — antes era un <script> en línea de index.php (CSP estricta, SEC-24 fase 2). */
// Seguimiento de uso (igual que antes)
function trackClick(id){if(!id)return;fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'click',link_id:id})});}
fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'session_start'})});
setInterval(()=>fetch('/toolz/track.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'heartbeat'})}),60000);
function trackAcceso(e,href,name){try{fetch('/toolz/index.php',{method:'POST',keepalive:true,headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=track_acceso&tool='+encodeURIComponent(href)+'&tool_name='+encodeURIComponent(name||href)});}catch(ex){}}

// Filtro de herramientas
(function(){
  const q=document.getElementById('d3q'), empty=document.getElementById('d3-empty'), recent=document.getElementById('d3-recent-sec');
  if(!q)return;
  const norm=s=>String(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  q.addEventListener('input',()=>{
    const t=norm(q.value.trim()); let any=false;
    document.querySelectorAll('[data-group]').forEach(g=>{let n=0;g.querySelectorAll('[data-q]').forEach(a=>{const ok=!t||norm(a.dataset.q).includes(t);a.style.display=ok?'':'none';if(ok)n++;});g.style.display=n?'':'none';if(n)any=true;});
    empty.style.display=any?'none':'block'; if(recent) recent.style.display=t?'none':'';
  });
  q.addEventListener('keydown',e=>{if(e.key==='Enter'){const f=[...document.querySelectorAll('[data-group] [data-q]')].find(a=>a.style.display!=='none');if(f)f.click();}if(e.key==='Escape'){q.value='';q.dispatchEvent(new Event('input'));q.blur();}});
  document.addEventListener('keydown',e=>{if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)&&!document.activeElement.isContentEditable){e.preventDefault();q.focus();}});
  // Brillo que sigue al cursor en las tarjetas
  document.querySelectorAll('.d3-tool').forEach(c=>c.addEventListener('pointermove',e=>{const r=c.getBoundingClientRect();c.style.setProperty('--mx',(e.clientX-r.left)+'px');c.style.setProperty('--my',(e.clientY-r.top)+'px');}));
})();
