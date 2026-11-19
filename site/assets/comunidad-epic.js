/* assets/comunidad-epic.js — El frente de la Comunidad como una red social (2026-11-14). Va después de comunidad-plus.js.
   Portada con el pulso del equipo, "historias" del equipo (quién está, ánimo y foco), tendencias, ranking de
   reconocimientos, doble clic = ❤️, emojis que saltan al reaccionar, compositor que crece y pestañas con indicador.
   Estilos: comunidad-epic.css. Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const P = CM_CFG.pulse || {};
  const moving = () => document.documentElement.classList.contains('g7-move');
  const F = () => (window.G7Juice && window.G7Juice._fx) || null;
  const feedCol = document.querySelector('.feed-col'), right = document.querySelector('.right-col'), left = document.querySelector('.left-col');
  document.body.classList.add('cme');
  const online = u => u.last_seen && (Date.now() - new Date(String(u.last_seen).replace(' ', 'T'))) < 5 * 60 * 1000;
  const AV = { available: '#22c55e', busy: '#f59e0b', focus: '#60a5fa', away: '#71717a' };
  const first = s => String(s || '').split(' ')[0];

  // ── Portada: título y pulso ──
  const me = ALL_USERS.find(u => +u.id === +ME_ID) || {};
  const h = new Date().getHours(), hello = h < 6 ? 'Buenas noches' : h < 13 ? 'Buen día' : h < 20 ? 'Buenas tardes' : 'Buenas noches';
  const hero = document.createElement('section'); hero.className = 'cme-hero';
  hero.innerHTML = `<div class="cme-hero-bg" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="cme-hero-t"><small>${hello}, ${escH(first(me.display_name || me.username))} 👋</small><h1>Comunidad</h1><p>Lo que pasa en el equipo, en un solo lugar.</p></div>
    <div class="cme-hero-s">
      <div><b data-n="${+P.online || 0}">${+P.online || 0}</b><span><i class="cme-live"></i> conectados</span></div>
      <div><b data-n="${+P.posts_today || 0}">${+P.posts_today || 0}</b><span>publicaciones hoy</span></div>
      <div><b data-n="${+P.kudos_week || 0}">${+P.kudos_week || 0}</b><span>reconocimientos esta semana</span></div>
    </div>`;
  feedCol.prepend(hero);

  // ── Historias del equipo: conectados primero, con anillo de color por estado ──
  const team = ALL_USERS.filter(u => +u.id !== +ME_ID).sort((a, b) => (online(b) - online(a)) || ((b.focus_text ? 1 : 0) - (a.focus_text ? 1 : 0)) || String(a.display_name || a.username).localeCompare(String(b.display_name || b.username)));
  const st = document.createElement('div'); st.className = 'cme-stories';
  const story = (u, mine) => {
    const on = online(u), col = on ? (AV[u.availability] || '#22c55e') : 'var(--g7-border-3)';
    return `<a class="cme-story${on ? ' on' : ''}${mine ? ' me' : ''}" href="${mine ? '#cme-status' : '/toolz/perfil.php?u=' + +u.id}" data-g7-user="${mine ? '' : +u.id}" style="--rc:${col}" title="${escH((u.display_name || u.username) + (u.focus_text ? ' · 🎯 ' + u.focus_text : ''))}">
      <span class="cme-ring">${av(u, 58)}${u.mood ? `<em>${escH(u.mood)}</em>` : ''}${mine ? '<i class="cme-plus">+</i>' : ''}</span>
      <b>${mine ? 'Tu estado' : escH(first(u.display_name || u.username))}</b>${u.focus_text && !mine ? `<small>🎯 ${escH(u.focus_text)}</small>` : ''}</a>`;
  };
  st.innerHTML = `<div class="cme-stories-in">${story(me, true)}${team.map(u => story(u, false)).join('')}</div>`;
  hero.after(st);
  st.addEventListener('click', e => { const m = e.target.closest('.cme-story.me'); if (!m) return; e.preventDefault(); const f = document.getElementById('my-focus'); if (f) { f.scrollIntoView({ behavior: 'smooth', block: 'center' }); f.focus(); f.closest('.cme-me-status')?.classList.add('cme-glow'); setTimeout(() => f.closest('.cme-me-status')?.classList.remove('cme-glow'), 1600); } });
  // arrastrar para desplazar
  const sin = st.querySelector('.cme-stories-in'); let dx = null, sx = 0, moved = false;
  sin.addEventListener('pointerdown', e => { if (e.pointerType !== 'mouse') return; dx = e.clientX; sx = sin.scrollLeft; moved = false; });
  addEventListener('pointermove', e => { if (dx === null) return; const d = e.clientX - dx; if (Math.abs(d) > 4) moved = true; sin.scrollLeft = sx - d; });
  addEventListener('pointerup', () => { dx = null; });
  sin.addEventListener('click', e => { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);

  // ── Columna izquierda: estado propio agrupado ──
  const myFocus = document.getElementById('my-focus');
  if (myFocus) { const box = myFocus.closest('div').parentNode; box.classList.add('cme-me-status'); box.id = 'cme-status'; }
  if (left) {
    const links = document.createElement('div'); links.className = 'cme-quick';
    links.innerHTML = `<div class="nav-section">Atajos</div>
      <a class="nav-item" href="/toolz/perfil.php"><i class="fa-solid fa-id-card"></i> Mi perfil</a>
      <a class="nav-item" href="/toolz/logros.php"><i class="fa-solid fa-trophy"></i> Mis logros</a>
      <a class="nav-item" href="/toolz/chat/"><i class="fa-solid fa-comments"></i> Chat</a>
      <button type="button" class="nav-item" id="cme-kudos"><i class="fa-solid fa-award"></i> Reconocer a alguien</button>`;
    left.appendChild(links);
    links.querySelector('#cme-kudos').addEventListener('click', () => document.getElementById('cm-kudos-open')?.click());
  }

  // ── Columna derecha: tendencias y ranking ──
  if (right) {
    const after = right.querySelector('.cm-today') || null;
    const mk = html => { const d = document.createElement('div'); d.className = 'cme-card'; d.innerHTML = html; return d; };
    const cards = [];
    if ((P.trending || []).length) cards.push(mk(`<div class="cme-card-h"><i class="fa-solid fa-fire"></i> Tendencias</div>${P.trending.map((t, i) => `<a class="cme-trend" href="/toolz/comunidad.php?post=${+t.id}" data-post="${+t.id}"><span class="cme-trend-n">${i + 1}</span><div><b>${escH(t.name)}</b><small>${escH(t.kind === 'kudos' ? '🏆 Reconocimiento' : t.kind === 'poll' ? '📊 ' + t.body : t.body || (t.img ? '📷 Imagen' : ''))}</small></div><em>🔥 ${+t.score}</em></a>`).join('')}`));
    if ((P.leaders || []).length) cards.push(mk(`<div class="cme-card-h"><i class="fa-solid fa-award"></i> Los más reconocidos <small>30 días</small></div>${P.leaders.map((u, i) => `<a class="cme-lead" href="/toolz/perfil.php?u=${+u.id}" data-g7-user="${+u.id}"><span class="cme-medal">${['🥇', '🥈', '🥉'][i] || i + 1}</span>${av(u, 30)}<b>${escH(u.name)}</b><em>${+u.n}</em></a>`).join('')}`));
    else cards.push(mk(`<div class="cme-card-h"><i class="fa-solid fa-award"></i> Reconocimientos</div><p class="cme-muted">Todavía nadie recibió uno este mes. ¡Sé quien arranca!</p><button type="button" class="cme-cta" data-cme-k>Reconocer a alguien</button>`));
    cards.reverse().forEach(c => after ? after.after(c) : right.prepend(c));
    right.addEventListener('click', e => {
      if (e.target.closest('[data-cme-k]')) document.getElementById('cm-kudos-open')?.click();
      const t = e.target.closest('.cme-trend'); if (t && document.getElementById('post-' + t.dataset.post)) { e.preventDefault(); const el = document.getElementById('post-' + t.dataset.post); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.classList.remove('cm-flash'); void el.offsetWidth; el.classList.add('cm-flash'); }
    });
  }

  // ── Doble clic en una publicación = ❤️ ──
  const feed = document.getElementById('feed-posts');
  feed.addEventListener('dblclick', e => {
    const card = e.target.closest('.post-card'); if (!card || e.target.closest('button,a,textarea,input,.cm-poll')) return;
    const id = +card.dataset.id, mineHeart = card.querySelector('.rxn-btn.active') && card.querySelector('.rxn-btn.active').textContent.includes('❤️');
    if (!mineHeart && typeof reactToPost === 'function') reactToPost(id, 'heart');
    burst(e.clientX, e.clientY, '❤️', true);
    window.getSelection()?.removeAllRanges();
  });
  // ── Emojis que saltan al reaccionar ──
  feed.addEventListener('click', e => { const b = e.target.closest('.rxn-btn'); if (!b) return; const r = b.getBoundingClientRect(); burst(r.left + r.width / 2, r.top, b.textContent.trim().replace(/\d+/g, '').trim() || '👍', false); }, true);
  function burst(x, y, emo, big){
    if (!moving()) return;
    const n = big ? 1 : 5;
    for (let i = 0; i < n; i++) {
      const s = document.createElement('span'); s.className = 'cme-burst' + (big ? ' big' : ''); s.textContent = emo; s.style.left = x + 'px'; s.style.top = y + 'px'; document.body.appendChild(s);
      const ang = big ? -Math.PI / 2 : -Math.PI / 2 + (Math.random() - .5) * 1.6, dist = big ? 60 : 40 + Math.random() * 40;
      s.animate(big ? [{ transform: 'translate(-50%,-50%) scale(.2)', opacity: 0 }, { transform: 'translate(-50%,-50%) scale(1.4)', opacity: 1, offset: .3 }, { transform: 'translate(-50%,-140%) scale(1)', opacity: 0 }]
        : [{ transform: 'translate(-50%,-50%) scale(.4)', opacity: 1 }, { transform: `translate(calc(-50% + ${Math.cos(ang) * dist}px), calc(-50% + ${Math.sin(ang) * dist}px)) scale(1.1) rotate(${(Math.random() - .5) * 60}deg)`, opacity: 0 }],
        { duration: big ? 900 : 650 + Math.random() * 250, easing: 'cubic-bezier(.2,.8,.2,1)' }).onfinish = () => s.remove();
    }
  }

  // ── Compositor que crece y con contador circular ──
  const ta = document.getElementById('post-body');
  if (ta) {
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(260, Math.max(52, ta.scrollHeight)) + 'px'; };
    ta.addEventListener('input', grow); grow();
    ta.addEventListener('focus', () => ta.closest('.composer').classList.add('cme-focus'));
    ta.addEventListener('blur', () => { if (!ta.value) ta.closest('.composer').classList.remove('cme-focus'); });
  }

  // ── Pestañas con indicador que se desliza ──
  const tabs = document.querySelector('.feed-tabs');
  if (tabs) {
    const ind = document.createElement('span'); ind.className = 'cme-tab-ind'; tabs.appendChild(ind);
    const place = () => { const a = tabs.querySelector('.feed-tab.active'); if (!a) return; ind.style.width = a.offsetWidth + 'px'; ind.style.transform = `translateX(${a.offsetLeft - 3}px)`; };
    new MutationObserver(place).observe(tabs, { subtree: true, attributes: true, attributeFilter: ['class'] });
    addEventListener('resize', place); setTimeout(place, 50);
  }

  // ── Números del pulso que cuentan ──
  if (moving()) hero.querySelectorAll('[data-n]').forEach((b, k) => { const n = +b.dataset.n; if (n < 2) return; const t0 = performance.now() + 200 + k * 90; const step = t => { const p = Math.min(1, Math.max(0, (t - t0) / 900)); b.textContent = Math.round(n * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); }; b.textContent = '0'; requestAnimationFrame(step); });
  // ── Las publicaciones entran al hacer scroll ──
  if (moving() && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) { en.target.classList.add('cme-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -30px 0px' });
    const watch = () => feed.querySelectorAll('.post-card:not(.cme-w)').forEach(c => { c.classList.add('cme-w'); if (c.getBoundingClientRect().top > innerHeight) { c.classList.add('cme-out'); io.observe(c); } });
    new MutationObserver(watch).observe(feed, { childList: true }); watch();
  }
})();
