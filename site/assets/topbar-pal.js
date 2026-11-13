/* assets/topbar-pal.js — Buscador de herramientas (Ctrl + K) y menú de usuario de la barra superior. Datos: #g7-pal-data. */
(function(){
  var ITEMS = []; try { ITEMS = JSON.parse(document.getElementById('g7-pal-data').textContent) || []; } catch (e) {}
  var bg = document.getElementById('accesos-modal-bg'), q = document.getElementById('g7-pal-q'), list = document.getElementById('g7-pal-list');
  var sel = 0, shown = [];
  function norm(s){ return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
  function esc(s){ return String(s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function render(){
    var t = norm(q.value.trim());
    shown = ITEMS.filter(function(it){ return !t || norm(it.n+' '+it.d+' '+it.g).indexOf(t) >= 0; });
    if (!t) shown.sort(function(a,b){ return a.r - b.r; });
    if (sel >= shown.length) sel = 0;
    if (!shown.length) { list.innerHTML = '<div class="g7-pal-empty">No hay herramientas que coincidan con "' + esc(q.value) + '"</div>'; return; }
    var html = '', last = null;
    shown.forEach(function(it, i){
      var g = t ? 'Resultados' : it.g;
      if (g !== last) { html += '<div class="g7-pal-group">' + esc(g) + '</div>'; last = g; }
      html += '<a class="g7-pal-item' + (i === sel ? ' sel' : '') + '" href="' + esc(it.u) + '" data-i="' + i + '"><span class="ic"><i class="fa-solid ' + esc(it.i) + '"></i></span><span><b>' + esc(it.n) + '</b><small>' + esc(it.d) + '</small></span><span class="go">Abrir <i class="fa-solid fa-arrow-right"></i></span></a>';
    });
    list.innerHTML = html;
    var cur = list.querySelector('.sel'); if (cur) cur.scrollIntoView({block:'nearest'});
  }
  list.addEventListener('mousemove', function(e){ var a = e.target.closest('.g7-pal-item'); if (a && +a.dataset.i !== sel) { sel = +a.dataset.i; list.querySelectorAll('.g7-pal-item').forEach(function(x){ x.classList.toggle('sel', +x.dataset.i === sel); }); } });
  q.addEventListener('input', function(){ sel = 0; render(); });
  q.addEventListener('keydown', function(e){
    if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(sel + 1, shown.length - 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(sel - 1, 0); render(); }
    else if (e.key === 'Enter' && shown[sel]) { e.preventDefault(); location.href = shown[sel].u; }
  });
  window.toggleAccesosModal = function(){ bg.classList.contains('open') ? closeAccesosModal() : openPal(); };
  window.closeAccesosModal = function(){ bg.classList.remove('open'); bg.setAttribute('aria-hidden','true'); };
  function openPal(){ bg.classList.add('open'); bg.setAttribute('aria-hidden','false'); q.value = ''; sel = 0; render(); setTimeout(function(){ q.focus(); }, 20); }
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); toggleAccesosModal(); }
    else if (e.key === 'Escape') { closeAccesosModal(); var um = document.getElementById('g7-um'); if (um) um.classList.remove('open'); }
  });
  // Menú de usuario
  window.g7UserMenu = function(e){ e.stopPropagation(); var um = document.getElementById('g7-um'); var o = um.classList.toggle('open'); um.querySelector('.g7-um-btn').setAttribute('aria-expanded', o ? 'true' : 'false'); };
  document.addEventListener('click', function(e){ var um = document.getElementById('g7-um'); if (um && !um.contains(e.target)) um.classList.remove('open'); });
})();
