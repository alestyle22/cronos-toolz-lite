/* assets/g7-people.js — Tarjeta de persona en todo Cronos (2026-11-14). La carga topbar.php.
   Cualquier elemento con data-g7-user="ID" (avatares de Trabajo, Tareas, Comunidad, Logros…) muestra, al dejar el mouse
   encima un momento (o con un toque largo), una tarjeta con rango, medallas y reconocimientos y los botones Ver perfil,
   Mensaje y Reconocer. No cambia lo que hace el clic en cada página. Datos: comunidad.php › mini_profile.
   Estilos: g7-people.css. Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  if (window.G7People) return;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cache = {};
  const mini = id => cache[id] || (cache[id] = fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'mini_profile', user_id: id }) }).then(r => r.ok ? r.json() : null).catch(() => null));
  let card = null, timer = 0, hideT = 0, curId = 0;
  const kill = () => { clearTimeout(timer); clearTimeout(hideT); if (card) { card.remove(); card = null; curId = 0; } };
  function loadKudos(cb){
    if (window.CMKudos) return cb();
    const v = Date.now() >> 22;
    if (!document.querySelector('link[href*="cm-kudos.css"]')) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = '/toolz/assets/cm-kudos.css?v=' + v; document.head.appendChild(l); }
    const s = document.createElement('script'); s.src = '/toolz/assets/cm-kudos.js?v=' + v; s.onload = cb; document.head.appendChild(s);
  }
  async function show(el){
    const id = +el.dataset.g7User; if (!id || id === curId) return;
    kill(); curId = id;
    card = document.createElement('div'); card.className = 'g7p'; card.innerHTML = '<div class="g7p-sk"></div>';
    document.body.appendChild(card); place(el);
    const d = await mini(id); if (!card || curId !== id) return;
    if (!d || d.error) return kill();
    const self = +d.id === +d.me;
    card.innerHTML = `<div class="g7p-top"></div>
      <div class="g7p-h"><span class="g7p-av">${d.avatar ? `<img src="${esc(d.avatar)}" alt="">` : `<span>${esc(d.name.charAt(0).toUpperCase())}</span>`}</span>
        <div><b>${esc(d.name)}</b><small>${esc(d.position || '@' + d.username)}</small></div></div>
      <div class="g7p-row">${d.level ? `<span class="g7p-rank" style="--lc:${esc(d.level.color || '#f5c542')}">${esc(d.level.emoji)} ${esc(d.level.label)}</span>` : ''}<span title="Medallas"><i class="fa-solid fa-medal"></i> ${+d.medals}</span><span title="Reconocimientos" class="k"><i class="fa-solid fa-award"></i> ${+d.kudos_n}</span></div>
      ${d.kudos && d.kudos.length ? `<div class="g7p-k">${d.kudos.map(k => `<span>${esc(k.emoji)} ${esc(k.name)}</span>`).join('')}</div>` : ''}
      <div class="g7p-btns"><a href="/toolz/perfil.php?u=${+d.id}"><i class="fa-solid fa-id-card"></i> ${self ? 'Mi perfil' : 'Perfil'}</a>${self ? '' : `<a href="/toolz/chat/?u=${+d.id}"><i class="fa-solid fa-comment-dots"></i> Mensaje</a><button type="button" class="k" data-g7p-k><i class="fa-solid fa-award"></i> Reconocer</button>`}</div>`;
    card.querySelector('[data-g7p-k]')?.addEventListener('click', () => { const dd = d; kill(); loadKudos(() => CMKudos.open({ to: dd.id, me: dd.me, kudos: dd.kudos_list, team: [{ id: dd.id, name: dd.name, avatar: dd.avatar }], onDone: () => { delete cache[dd.id]; } })); });
    place(el);
    if (document.documentElement.classList.contains('g7-move')) card.animate([{ opacity: 0, transform: 'translateY(6px) scale(.97)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'cubic-bezier(.2,.8,.2,1)' });
  }
  function place(el){
    if (!card) return;
    const r = el.getBoundingClientRect(), w = card.offsetWidth || 280, h = card.offsetHeight || 160;
    let x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
    if (y + h > innerHeight - 8) y = r.top - h - 8;
    x = Math.max(8, Math.min(innerWidth - w - 8, x)); y = Math.max(8, y);
    card.style.left = x + 'px'; card.style.top = y + 'px';
  }
  document.addEventListener('pointerover', e => {
    if (e.pointerType === 'touch') return;
    const el = e.target.closest && e.target.closest('[data-g7-user]');
    if (card && (e.target.closest && e.target.closest('.g7p'))) { clearTimeout(hideT); return; }
    if (!el) { if (card) { clearTimeout(hideT); hideT = setTimeout(kill, 260); } clearTimeout(timer); return; }
    clearTimeout(hideT); clearTimeout(timer);
    timer = setTimeout(() => show(el), card ? 120 : 550);
  });
  // toque largo en pantallas táctiles
  let lp = 0;
  document.addEventListener('touchstart', e => { const el = e.target.closest && e.target.closest('[data-g7-user]'); if (!el) return; lp = setTimeout(() => show(el), 520); }, { passive: true });
  document.addEventListener('touchend', () => clearTimeout(lp), { passive: true });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') kill(); });
  addEventListener('scroll', () => { if (card) kill(); }, true);
  window.G7People = { show: el => show(el), hide: kill };
})();
