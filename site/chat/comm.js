/* chat/comm.js — El chat unido a la Comunidad y a los perfiles (2026-11-14). No toca la lógica de chat.js: observa.
   - Tarjeta de persona y panel "Perfil" de un mensaje directo: rango, medallas, reconocimientos y botones Ver perfil /
     Reconocer (cm-kudos.js, sin salir del chat).
   - Enlaces en los mensajes a /toolz/perfil.php?u=N y /toolz/comunidad.php?post=N se ven como tarjetas.
   Datos: comunidad.php › mini_profile / mini_post. Estilos en chat2.css (.ccm-*). Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cache = { u: {}, p: {} };
  const post = body => fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(r => r.json());
  const mini = id => cache.u[id] || (cache.u[id] = post({ action: 'mini_profile', user_id: id }).catch(() => null));
  const miniPost = id => cache.p[id] || (cache.p[id] = post({ action: 'mini_post', post_id: id }).catch(() => null));
  const av = (u, s) => u.avatar ? `<img src="${esc(u.avatar)}" alt="" style="width:${s}px;height:${s}px">` : `<span style="width:${s}px;height:${s}px">${esc((u.name || '?').charAt(0).toUpperCase())}</span>`;
  const ago = d => { const s = (Date.now() - new Date(String(d).replace(' ', 'T'))) / 1000; return s < 3600 ? Math.max(1, Math.floor(s / 60)) + ' min' : s < 86400 ? Math.floor(s / 3600) + ' h' : Math.floor(s / 86400) + ' d'; };

  // ── Bloque "Comunidad" en la tarjeta de persona y en el perfil del mensaje directo ──
  async function fillComm(box){
    if (box.dataset.ccmDone) return; box.dataset.ccmDone = '1';
    const uid = +box.dataset.wk; if (!uid) return;
    const host = document.createElement('div'); host.className = 'ccm'; host.innerHTML = '<div class="ccm-sk"></div>';
    box.after(host);
    const d = await mini(uid); if (!host.isConnected) return;
    if (!d || d.error) { host.remove(); return; }
    const self = +d.id === +d.me;
    host.innerHTML = `<h4>Comunidad</h4>
      <div class="ccm-row">
        ${d.level ? `<span class="ccm-rank" style="--lc:${esc(d.level.color || '#f5c542')}">${esc(d.level.emoji)} ${esc(d.level.label)}</span>` : ''}
        <span class="ccm-pill"><i class="fa-solid fa-medal"></i> ${+d.medals}</span>
        <span class="ccm-pill k"><i class="fa-solid fa-award"></i> ${+d.kudos_n}</span>
        <span class="ccm-pill"><i class="fa-solid fa-rss"></i> ${+d.posts}</span>
      </div>
      ${d.kudos && d.kudos.length ? `<div class="ccm-kudos">${d.kudos.map(k => `<span title="${esc(k.name)}">${esc(k.emoji)} ${esc(k.name)} <b>×${+k.n}</b></span>`).join('')}</div>` : ''}
      <div class="ccm-btns">
        <a class="cc-btn" href="/toolz/perfil.php?u=${+d.id}" target="_blank" rel="noopener"><i class="fa-solid fa-id-card"></i> ${self ? 'Mi perfil' : 'Ver perfil'}</a>
        ${self ? '<a class="cc-btn" href="/toolz/comunidad.php" target="_blank" rel="noopener"><i class="fa-solid fa-people-group"></i> Comunidad</a>' : `${d.kudos_list && Object.keys(d.kudos_list).length ? `<button type="button" class="cc-btn ccm-kbtn" data-ccm-kudos="${+d.id}"><i class="fa-solid fa-award"></i> Reconocer</button>` : ''}`}
      </div>`;
    const card = host.closest('.cc-card'); if (card) { const r = card.getBoundingClientRect(); if (r.bottom > innerHeight - 8) card.style.top = Math.max(8, innerHeight - r.height - 8) + 'px'; }
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-ccm-kudos]'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    const uid = +b.dataset.ccmKudos, d = await mini(uid);
    if (!window.CMKudos || !d) { location.href = '/toolz/perfil.php?u=' + uid + '&reconocer=1'; return; }
    const card = b.closest('.cc-card'); if (card) card.remove();
    CMKudos.open({ to: uid, me: d.me, kudos: d.kudos_list, team: [{ id: d.id, name: d.name, avatar: d.avatar }], onDone: () => { delete cache.u[uid]; } });
  }, true);

  // ── Enlaces a perfiles y publicaciones dentro de los mensajes ──
  const RX_U = /\/toolz\/perfil\.php\?(?:[^#]*&)?u=(\d+)/, RX_P = /\/toolz\/comunidad\.php\?(?:[^#]*&)?post=(\d+)/;
  async function chip(a){
    if (a.dataset.ccmDone) return; a.dataset.ccmDone = '1';
    const href = a.getAttribute('href') || '', mu = href.match(RX_U), mp = href.match(RX_P);
    if (!mu && !mp) return;
    const card = document.createElement('a'); card.href = href; card.target = '_blank'; card.rel = 'noopener'; card.className = 'ccm-link';
    card.innerHTML = '<span class="ccm-sk"></span>';
    a.replaceWith(card);
    if (mu) {
      const d = await mini(+mu[1]); if (!d || d.error) { card.innerHTML = '<i class="fa-solid fa-id-card"></i> Perfil'; return; }
      card.innerHTML = `<span class="ccm-av">${av(d, 38)}</span><span class="ccm-t"><small><i class="fa-solid fa-id-card"></i> Perfil</small><b>${esc(d.name)}</b><em>${esc(d.position || '@' + d.username)}${d.level ? ' · ' + esc(d.level.emoji + ' ' + d.level.label) : ''}</em></span><i class="fa-solid fa-arrow-up-right-from-square ccm-go"></i>`;
    } else {
      const d = await miniPost(+mp[1]); if (!d || d.error) { card.innerHTML = '<i class="fa-solid fa-people-group"></i> Publicación de Comunidad'; return; }
      const k = d.kind === 'kudos' && d.badge;
      card.classList.add('post'); if (k) card.classList.add('k');
      card.innerHTML = `<span class="ccm-av">${av(d.author, 38)}</span><span class="ccm-t"><small><i class="fa-solid ${k ? 'fa-award' : 'fa-people-group'}"></i> ${k ? 'Reconocimiento' : 'Comunidad'} · ${ago(d.created_at)}</small>
        <b>${esc(d.author.name)}${k ? ` → ${esc(d.target || '')} · ${esc(d.badge[0])} ${esc(d.badge[1])}` : ''}</b><em>${esc(d.body || (d.img ? 'Imagen' : ''))}</em><span class="ccm-meta"><i class="fa-regular fa-heart"></i> ${+d.reactions} <i class="fa-regular fa-comment"></i> ${+d.replies}</span></span>${d.img ? `<img class="ccm-img" src="${esc(d.img)}" alt="">` : ''}`;
    }
  }
  function scan(root){
    (root.querySelectorAll ? root : document).querySelectorAll('.cc-wkbox[data-wk]').forEach(fillComm);
    (root.querySelectorAll ? root : document).querySelectorAll('a[href*="/toolz/perfil.php?"],a[href*="/toolz/comunidad.php?"]').forEach(a => { if (a.closest('.cc-msgs, .cc-thread, #ccw-msgs') && !a.closest('.ccm, .ccm-link')) chip(a); });
  }
  new MutationObserver(ms => { for (const m of ms) for (const n of m.addedNodes) if (n.nodeType === 1) scan(n); }).observe(document.body, { childList: true, subtree: true });
  scan(document);
})();
