/* assets/comunidad-plus.js — Comunidad 2 (2026-11-14), se carga después de comunidad-page.js y reemplaza partes:
   feed dibujado desde datos (filtros, búsqueda, "Cargar más"), publicaciones de hasta 500 caracteres, edición de las
   propias, reconocimientos (cm-kudos.js), avisos solo de la Comunidad, buscador del equipo y los perfiles en su
   propia página (perfil.php). Doc: toolz/COMUNIDAD.md */
(function () {
  'use strict';
  const MAX = CM_CFG.maxPost || 500;
  const KUDOS = CM_CFG.kudos || {};
  const feed = document.getElementById('feed-posts');
  const bar = document.getElementById('cm-bar');
  const moreBtn = document.getElementById('cm-more');
  const moving = () => document.documentElement.classList.contains('g7-move');
  const F = () => (window.G7Juice && window.G7Juice._fx) || null;
  const tap = () => { const f = F(); if (f) f.play('tap', 0); };
  const api = body => fetch('', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(r => r.json());
  const team = ALL_USERS.map(u => ({ id: +u.id, name: u.display_name || u.username, username: u.username, avatar: u.avatar || '' }));
  let filter = 'all', query = '', oldest = 0, loading = false;

  // ── Perfiles: ahora en su página ──
  window.openProfile = function (uid) { if (uid) location.href = '/toolz/perfil.php?u=' + encodeURIComponent(uid); };
  window.previewMyProfile = function () { location.href = '/toolz/perfil.php'; };

  // ── Publicación ──
  const EMO = { like: '👍', fire: '🔥', heart: '❤️', wow: '😮', clap: '👏' };
  window.renderPost = function (post) {
    const name = post.display_name || post.username, mine = +post.user_id === +ME_ID, canDel = mine || IS_ADMIN, isK = post.kind === 'kudos';
    const rm = {}; (post.reactions || []).forEach(r => { rm[r.emoji] = r.cnt; });
    const rx = Object.entries(EMO).map(([k, em]) => { const n = rm[k] || 0; return `<button class="rxn-btn ${post.my_reaction === k ? 'active' : ''}" data-on-click="reactToPost" data-click-1="j:${post.id}" data-click-2="s:${k}">${em}${n > 0 ? `<span class="rxn-cnt">${n}</span>` : ''}</button>`; }).join('');
    const reps = (post.replies || []).map(r => renderReply(r, post.id)).join(''), rc = (post.replies || []).length;
    const tools = [
      `<button class="post-del cm-share" data-id="${post.id}" title="Copiar el enlace (pegado en el chat se ve como tarjeta)"><i class="fa-solid fa-link"></i></button>`,
      !mine ? `<a class="post-del" href="/toolz/chat/?u=${+post.user_id}" title="Escribirle por chat"><i class="fa-solid fa-comment-dots"></i></a>` : '',
      mine && !isK ? `<button class="post-del cm-edit" data-id="${post.id}" title="Editar"><i class="fa-solid fa-pen"></i></button>` : '',
      IS_ADMIN ? `<button class="post-del${+post.pinned ? ' cm-pinned' : ''}" data-on-click="pinPost" data-click-1="j:${post.id}" data-click-2="this" title="${+post.pinned ? 'Desfijar' : 'Fijar'}"><i class="fa-solid fa-thumbtack"></i></button>` : `<button class="post-del" data-on-click="suggestPin" data-click-1="j:${post.id}" data-click-2="this" title="Sugerir fijar"><i class="fa-solid fa-thumbtack"></i></button>`,
      canDel ? `<button class="post-del" data-on-click="deletePost" data-click-1="j:${post.id}" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>` : ''
    ].join('');
    const kb = isK ? (KUDOS[post.badge] || ['🏆', 'Reconocimiento']) : null, tg = post.target || null;
    const meta = post.meta || {};
    // Pregunta del día: tarjeta destacada, sin autor, con la respuesta a mano
    if (post.kind === 'qotd') {
      return `<div class="post-card cm-qotd" id="post-${post.id}" data-id="${post.id}">
        <div class="cm-q-head"><span class="cm-q-ic">💬</span><div><b>Pregunta del día</b><small>${escH(meta.date ? new Date(meta.date + 'T12:00').toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }) : tagoAgo(post.created_at))} · ${rc} ${rc === 1 ? 'respuesta' : 'respuestas'}</small></div><span style="margin-left:auto;display:flex">${tools}</span></div>
        <div class="cm-q-text">${escH(post.body)}</div>
        <div class="post-reactions" id="reactions-${post.id}">${rx}</div>
        <div class="post-replies">
          <div id="post-replies-${post.id}" style="display:flex;flex-direction:column;gap:7px;${rc ? 'margin-bottom:8px' : ''}">${reps}</div>
          <div class="reply-form open" id="reply-box-${post.id}">
            <textarea class="reply-input" id="reply-input-${post.id}" placeholder="Tu respuesta…" data-on-keydown="cmReplyKey" data-keydown-1="event" data-keydown-2="j:${post.id}"></textarea>
            <button class="reply-send" data-on-click="submitReplyToPost" data-click-1="j:${post.id}"><i class="fa-solid fa-paper-plane"></i></button>
          </div>
        </div></div>`;
    }
    const AUTO = { content: ['fa-layer-group', '#ff6600', 'Nuevo contenido'], ach: ['fa-medal', '#f5c542', 'Medalla'], rank: ['fa-ranking-star', '#a78bfa', 'Nuevo rango'], landing: ['fa-rocket', '#22c55e', 'Landing publicada'], member: ['fa-hand-sparkles', '#60a5fa', 'Se sumó al equipo'], birthday: ['fa-cake-candles', '#ec4899', 'Cumpleaños'], anniv: ['fa-champagne-glasses', '#f59e0b', 'Aniversario'] };
    const au = post.kind === 'auto' ? (AUTO[post.badge] || ['fa-bolt', 'var(--g7-accent)', 'Novedad']) : null;
    const poll = post.kind === 'poll' && post.poll ? post.poll : null;
    const pollHtml = poll ? (() => {
      const total = poll.counts.reduce((a, b) => a + b, 0), voted = poll.mine.length > 0;
      return `<div class="cm-poll${voted ? ' voted' : ''}" data-post="${post.id}">${poll.options.map((o, i) => { const n = poll.counts[i] || 0, pct = total ? Math.round(100 * n / total) : 0, on = poll.mine.includes(i);
        return `<button type="button" class="cm-opt${on ? ' on' : ''}" data-opt="${i}" style="--p:${voted ? pct : 0}%"><span class="cm-opt-bar"></span><span class="cm-opt-t">${on ? '<i class="fa-solid fa-circle-check"></i>' : `<i class="fa-regular ${poll.multi ? 'fa-square' : 'fa-circle'}"></i>`} ${escH(o)}</span>${voted ? `<b>${pct}%</b>` : ''}</button>`; }).join('')}
        <small class="cm-poll-f">${poll.voters} ${poll.voters === 1 ? 'voto' : 'votos'}${poll.multi ? ' · podés elegir varias' : ''}${voted ? ' · tocá otra vez para cambiar' : ' · votá para ver los resultados'}</small></div>`;
    })() : '';
    if (au) {
      const isB = post.badge === 'birthday' || post.badge === 'anniv';
      return `<div class="post-card cm-auto${isB ? ' cm-bday' : ''}" id="post-${post.id}" data-id="${post.id}" style="--ac:${meta.color || au[1]}">
        <div class="post-header"><span data-g7-user="${+post.user_id}" class="cm-auto-av">${av({ avatar: post.avatar, display_name: post.display_name, username: post.username }, 38)}<i class="fa-solid ${meta.icon || au[0]}"></i></span>
          <div style="flex:1;min-width:0"><a class="post-author-name" href="/toolz/perfil.php?u=${+post.user_id}">${escH(name)}</a><div class="post-handle"><span class="cm-auto-tag">${au[2]}</span> · ${tagoAgo(post.created_at)}</div></div>${tools}</div>
        <div class="post-body">${escH(post.body || '')}</div>
        ${post.img ? `<img src="${escH(post.img)}" class="post-img" alt="" loading="lazy" data-on-click="openLightbox" data-click-1="j:${jsA(post.img)}" data-on-error="cmHide" data-error-1="this">` : ''}
        ${meta.href ? `<a class="cm-auto-cta" href="${escH(meta.href)}"${/^https?:/.test(meta.href) ? ' target="_blank" rel="noopener"' : ''}>${escH(meta.cta || 'Ver')} <i class="fa-solid fa-arrow-right"></i></a>` : ''}
        <div class="post-reactions" id="reactions-${post.id}">${rx}</div>
        <div class="post-replies">
          <div id="post-replies-${post.id}" style="display:flex;flex-direction:column;gap:7px;${rc ? 'margin-bottom:8px' : ''}">${reps}</div>
          <button class="reply-toggle" data-on-click="toggleReplyBox" data-click-1="j:${post.id}"><i class="fa-solid ${isB ? 'fa-gift' : 'fa-hands-clapping'}" style="font-size:10px"></i>${rc ? `${rc} ${rc > 1 ? 'mensajes' : 'mensaje'} · ` : ''}${isB ? 'Saludar' : 'Felicitar'}</button>
          <div class="reply-form" id="reply-box-${post.id}">
            <textarea class="reply-input" id="reply-input-${post.id}" placeholder="${isB ? '¡Feliz día! 🎉' : '¡Bien ahí! 👏'}" data-on-keydown="cmReplyKey" data-keydown-1="event" data-keydown-2="j:${post.id}"></textarea>
            <button class="reply-send" data-on-click="submitReplyToPost" data-click-1="j:${post.id}"><i class="fa-solid fa-paper-plane"></i></button>
          </div>
        </div></div>`;
    }
    const head = isK && tg
      ? `<div class="cm-k-head"><a class="cm-k-av" href="/toolz/perfil.php?u=${+post.user_id}">${av({ avatar: post.avatar, display_name: post.display_name, username: post.username }, 34)}</a><span class="cm-k-arrow"><i class="fa-solid fa-arrow-right"></i></span><a class="cm-k-av" href="/toolz/perfil.php?u=${+tg.id}">${av(tg, 34)}</a>
         <div class="cm-k-t"><div><a href="/toolz/perfil.php?u=${+post.user_id}">${escH(name)}</a> reconoció a <a href="/toolz/perfil.php?u=${+tg.id}">${escH(tg.display_name || tg.username)}</a></div><div class="post-handle">${tagoAgo(post.created_at)}</div></div>${tools}</div>
         <div class="cm-k-badge"><span>${escH(kb[0])}</span>${escH(kb[1])}</div>`
      : `<div class="post-header"><span data-g7-user="${+post.user_id}">${av({ avatar: post.avatar, display_name: post.display_name, username: post.username }, 38)}</span><div style="flex:1;min-width:0"><a class="post-author-name" href="/toolz/perfil.php?u=${+post.user_id}">${escH(name)}</a><div class="post-handle">@${escH(post.username)} · ${tagoAgo(post.created_at)}${post.edited_at ? ' · <span class="cm-edited">editado</span>' : ''}</div></div>${+post.pinned ? '<span class="cm-pin" title="Fijada">📌 Fijada</span>' : ''}${tools}</div>`;
    return `<div class="post-card${isK ? ' cm-k' : ''}${+post.pinned ? ' cm-is-pinned' : ''}" id="post-${post.id}" data-id="${post.id}">
      ${head}
      ${poll ? '<div class="cm-poll-tag"><i class="fa-solid fa-square-poll-vertical"></i> Encuesta</div>' : ''}
      <div class="post-body" data-raw="${escH(post.body || '')}">${linkifyPost(post.body || '')}</div>
      ${pollHtml}
      ${post.img ? `<img src="${escH(post.img)}" class="post-img" alt="" loading="lazy" data-on-click="openLightbox" data-click-1="j:${jsA(post.img)}" data-on-error="cmHide" data-error-1="this">` : ''}
      <div class="post-reactions" id="reactions-${post.id}">${rx}</div>
      <div class="post-replies">
        <div id="post-replies-${post.id}" style="display:flex;flex-direction:column;gap:7px;${rc ? 'margin-bottom:8px' : ''}">${reps}</div>
        <button class="reply-toggle" data-on-click="toggleReplyBox" data-click-1="j:${post.id}"><i class="fa-solid fa-reply" style="font-size:10px"></i>${rc ? `${rc} respuesta${rc > 1 ? 's' : ''} · ` : ''}Responder</button>
        <div class="reply-form" id="reply-box-${post.id}">
          <textarea class="reply-input" id="reply-input-${post.id}" placeholder="Escribí una respuesta… (@ para mencionar)" data-on-keydown="cmReplyKey" data-keydown-1="event" data-keydown-2="j:${post.id}"></textarea>
          <button class="reply-send" data-on-click="submitReplyToPost" data-click-1="j:${post.id}"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
      </div>
    </div>`;
  };
  // Nunca dos veces la misma; la hora de referencia es la más nueva (antes quedaba la más vieja del lote y se repetían)
  window.prependPost = function (post) {
    if (document.getElementById('post-' + post.id)) return;
    if (post.created_at > lastPostTime) lastPostTime = post.created_at;
    if (filter !== 'all' && !(filter === 'kudos' && post.kind === 'kudos') && !(filter === 'mine' && +post.user_id === +ME_ID)) return;
    const empty = feed.querySelector('.cm-empty'); if (empty) empty.remove();
    const pin = feed.querySelector('.cm-is-pinned');
    (pin && pin === feed.firstElementChild ? pin : null) ? pin.insertAdjacentHTML('afterend', renderPost(post)) : feed.insertAdjacentHTML('afterbegin', renderPost(post));
    const el = document.getElementById('post-' + post.id);
    if (el && moving()) el.animate([{ opacity: 0, transform: 'translateY(-14px) scale(.98)' }, { opacity: 1, transform: 'none' }], { duration: 480, easing: 'cubic-bezier(.34,1.56,.64,1)' });
  };

  // ── Compositor: 500 caracteres ──
  window.updateCharCount = function (ta) {
    const left = MAX - ta.value.length, cc = document.getElementById('char-count');
    cc.textContent = left; cc.className = 'char-count' + (left < 50 ? ' warn' : '') + (left < 0 ? ' over' : '');
    document.getElementById('pub-btn').disabled = (ta.value.trim().length === 0 && !document.getElementById('post-img-input').files[0]) || ta.value.length > MAX;
  };
  window.submitPost = async function () {
    const ta = document.getElementById('post-body'), body = ta.value.trim(), rawImg = document.getElementById('post-img-input').files[0];
    if ((!body && !rawImg) || body.length > MAX) return;
    const btn = document.getElementById('pub-btn'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    try {
      const imgFile = rawImg ? await g7PrepImage(rawImg) : null;
      const fd = new FormData(); fd.append('action', 'add_post'); fd.append('body', body); if (imgFile) fd.append('post_img', imgFile);
      const data = await fetch('', { method: 'POST', body: fd }).then(r => r.json()).catch(() => null);
      if (data && data.status === 'success') {
        ta.value = ''; clearPostImg(); if (typeof clearOgPreview === 'function') clearOgPreview(); updateCharCount(ta);
        const keep = filter; filter = 'all'; prependPost(data.post); filter = keep;
        const f = F(); if (f) { f.play('pop', 0); const r = btn.getBoundingClientRect(); if (moving()) f.confetti(18, { x: r.left + r.width / 2, y: r.top }, null, { power: .5, size: .7, spread: 1.6 }); }
      } else showToast(g7UploadError(data, 'No se pudo publicar'));
    } catch (e) { showToast('No se pudo publicar: ' + e.message); }
    btn.innerHTML = 'Publicar'; btn.disabled = false;
  };
  const pb = document.getElementById('post-body'); if (pb) { pb.maxLength = MAX; updateCharCount(pb); }

  // ── Barra del feed: filtros y búsqueda ──
  const FILTERS = [['all', 'fa-border-all', 'Todo'], ['kudos', 'fa-award', 'Reconocimientos'], ['mentions', 'fa-at', 'Para mí'], ['img', 'fa-image', 'Con imagen'], ['mine', 'fa-user', 'Mías']];
  bar.innerHTML = `<div class="cm-chips">${FILTERS.map(([k, i, l]) => `<button type="button" data-f="${k}" class="${k === 'all' ? 'on' : ''}"><i class="fa-solid ${i}"></i> ${l}</button>`).join('')}</div>
    <label class="cm-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="cm-q" placeholder="Buscar en el feed…" autocomplete="off"></label>`;
  bar.addEventListener('click', e => { const b = e.target.closest('[data-f]'); if (!b || b.dataset.f === filter) return; filter = b.dataset.f; bar.querySelectorAll('[data-f]').forEach(x => x.classList.toggle('on', x === b)); tap(); load(false); });
  let qt = 0; document.getElementById('cm-q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { query = e.target.value.trim(); load(false); }, 280); });

  function draw(posts, append){
    const html = posts.map(renderPost).join('');
    if (!append) feed.innerHTML = html || `<div class="cm-empty"><i class="fa-solid ${filter === 'kudos' ? 'fa-award' : query ? 'fa-magnifying-glass' : 'fa-pen-nib'}"></i>${query ? 'No hay publicaciones con «' + escH(query) + '».' : filter === 'kudos' ? 'Todavía no hay reconocimientos. <button type="button" class="cm-link" id="cm-kudos-empty">Reconocé a alguien</button>' : filter === 'mentions' ? 'Nadie te mencionó todavía.' : filter === 'mine' ? 'Todavía no publicaste.' : 'Sin publicaciones aún. ¡Sé la primera persona!'}</div>`;
    else feed.insertAdjacentHTML('beforeend', html);
    if (posts.length) oldest = Math.min(...posts.map(p => +p.id), oldest || Infinity);
    if (moving()) { const kids = [...feed.querySelectorAll('.post-card')].slice(append ? -posts.length : 0).slice(0, 12); kids.forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(14px)' }, { opacity: 1, transform: 'none' }], { duration: 400, delay: i * 40, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' })); }
  }
  async function load(append){
    if (loading) return; loading = true;
    if (!append) { oldest = 0; feed.classList.add('cm-loading'); }
    moreBtn.disabled = true; moreBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cargando…';
    try { const d = await api({ action: 'feed_page', filter, q: query, before: append ? oldest : 0 }); draw(d.posts || [], append); moreBtn.hidden = !d.more; }
    catch (e) { showToast('No se pudo cargar el feed'); }
    feed.classList.remove('cm-loading'); moreBtn.disabled = false; moreBtn.textContent = 'Cargar más'; loading = false;
  }
  moreBtn.addEventListener('click', () => load(true));
  // primera página (viene en la página)
  draw((CM_CFG.feed && CM_CFG.feed.posts) || [], false); moreBtn.hidden = !(CM_CFG.feed && CM_CFG.feed.more);
  if (window.IntersectionObserver) new IntersectionObserver(es => { if (es[0].isIntersecting && !moreBtn.hidden && !loading) load(true); }, { rootMargin: '300px' }).observe(moreBtn);

  // Pestañas Publicaciones / Actividad / Trabajando en: la barra y "Cargar más" son solo del feed
  const _switch = window.switchFeed;
  window.switchFeed = function (type, nav, tab) { _switch(type, nav, tab); bar.style.display = type === 'feed' ? '' : 'none'; moreBtn.parentNode.style.display = type === 'feed' ? '' : 'none'; };

  // ── Editar una publicación propia ──
  feed.addEventListener('click', async e => {
    const ed = e.target.closest('.cm-edit'); if (!ed) return;
    const card = ed.closest('.post-card'), bodyEl = card.querySelector('.post-body'); if (card.querySelector('.cm-editbox')) return;
    const raw = bodyEl.dataset.raw ? new DOMParser().parseFromString(bodyEl.dataset.raw, 'text/html').documentElement.textContent : bodyEl.textContent;
    const box = document.createElement('div'); box.className = 'cm-editbox';
    box.innerHTML = `<textarea maxlength="${MAX}"></textarea><div><small></small><button type="button" class="cm-ed-cancel">Cancelar</button><button type="button" class="cm-ed-save">Guardar</button></div>`;
    const ta = box.querySelector('textarea'); ta.value = raw; bodyEl.hidden = true; bodyEl.after(box); ta.focus();
    const cnt = () => { box.querySelector('small').textContent = (MAX - ta.value.length) + ' restantes'; }; cnt(); ta.addEventListener('input', cnt);
    box.querySelector('.cm-ed-cancel').onclick = () => { box.remove(); bodyEl.hidden = false; };
    box.querySelector('.cm-ed-save').onclick = async () => {
      const d = await api({ action: 'edit_post', post_id: +card.dataset.id, body: ta.value.trim() });
      if (d.status !== 'success') return showToast(d.error || 'No se pudo guardar');
      bodyEl.dataset.raw = escH(d.body); bodyEl.innerHTML = linkifyPost(d.body); box.remove(); bodyEl.hidden = false;
      const h = card.querySelector('.post-handle'); if (h && !h.querySelector('.cm-edited')) h.insertAdjacentHTML('beforeend', ' · <span class="cm-edited">editado</span>');
      const f = F(); if (f) f.play('tap', 0);
    };
  });

  // ── Reconocer ──
  const openKudos = to => window.CMKudos && CMKudos.open({ to, team, kudos: KUDOS, me: ME_ID, onDone: p => { if (p) { const keep = filter; if (filter !== 'kudos') filter = 'all'; prependPost(p); filter = keep; } } });
  document.addEventListener('click', e => { if (e.target.closest('#cm-kudos-open, #cm-kudos-empty')) openKudos(null); });

  // ── Avisos: solo los de la Comunidad, cada uno lleva a su lugar ──
  const NT = { comment: ['fa-comment', '#60a5fa', 'comentó en tu muro'], reply: ['fa-reply', '#a78bfa', 'respondió tu comentario'], mention: ['fa-at', 'var(--g7-accent)', 'te mencionó en un comentario'],
    mention_post: ['fa-at', 'var(--g7-accent)', 'te mencionó en una publicación'], post_reply: ['fa-reply', '#a78bfa', 'respondió tu publicación'], kudos: ['fa-award', '#ec4899', 'te dio un reconocimiento'] };
  window.renderNotifItem = function (n) {
    const t = NT[n.type] || ['fa-bell', 'var(--g7-text-3)', 'te avisó'], name = escH(n.display_name || n.username || 'Alguien');
    const go = ['mention_post', 'post_reply', 'kudos'].includes(n.type) ? '/toolz/comunidad.php?post=' + (+n.target_id) : '/toolz/perfil.php?u=' + (+n.profile_id || +n.from_id) + (n.profile_id ? '#muro' : '');
    return `<a class="cm-nt${n.read_at ? '' : ' new'}" href="${go}" data-post="${['mention_post', 'post_reply', 'kudos'].includes(n.type) ? +n.target_id : ''}">
      <span class="cm-nt-av">${av({ avatar: n.avatar, display_name: n.display_name, username: n.username }, 30)}<i class="fa-solid ${t[0]}" style="--c:${t[1]}"></i></span>
      <span class="cm-nt-t"><span><b>${name}</b> ${t[2]}</span>${n.body ? `<small>${escH(String(n.body).replace(/<[^>]*>/g, '').slice(0, 80))}</small>` : ''}<em>${tagoAgo(n.created_at)}</em></span></a>`;
  };
  document.getElementById('notif-list').addEventListener('click', e => {
    const a = e.target.closest('.cm-nt[data-post]'); if (!a || !a.dataset.post) return;
    if (focusPost(+a.dataset.post)) e.preventDefault();
  });
  if (typeof pollNotifications === 'function') pollNotifications();

  // ── Ir a una publicación ──
  function focusPost(id){
    const el = document.getElementById('post-' + id); if (!el) return false;
    if (window.switchFeed) switchFeed('feed', null, null);
    el.scrollIntoView({ behavior: moving() ? 'smooth' : 'auto', block: 'center' });
    el.classList.remove('cm-flash'); void el.offsetWidth; el.classList.add('cm-flash'); return true;
  }

  // ── Equipo: buscador ──
  const tq = document.getElementById('cm-team-q');
  if (tq) tq.addEventListener('input', () => {
    const v = tq.value.trim().toLowerCase();
    if (v && typeof teamExpanded !== 'undefined' && teamExpanded) toggleFullTeam();
    document.querySelectorAll('#team-compact > [data-on-click="openProfile"]').forEach(el => { el.hidden = v && !el.textContent.toLowerCase().includes(v); });
  });

  // ── Enlaces que llegan de otras páginas ──
  const qs = new URLSearchParams(location.search);
  if (qs.get('u')) location.replace('/toolz/perfil.php?u=' + encodeURIComponent(qs.get('u')));
  // ?editar=1: espera a que carguen todos los scripts (profile-edit.js reemplaza al formulario viejo)
  if (qs.get('editar')) { const go = () => { if (typeof openEditModal === 'function') openEditModal(qs.get('campo') || ''); }; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go, { once: true }); else setTimeout(go, 0); }
  if (qs.get('mencionar') && pb) { pb.value = '@' + qs.get('mencionar') + ' '; updateCharCount(pb); pb.focus(); pb.setSelectionRange(pb.value.length, pb.value.length); }
  if (qs.get('post')) {
    const id = +qs.get('post');
    (async () => { for (let i = 0; i < 6 && !focusPost(id) && !moreBtn.hidden; i++) await load(true); if (!document.getElementById('post-' + id)) showToast('Esa publicación ya no está'); })();
  }
  const m = location.hash.match(/^#profile-(\d+)$/); if (m) location.replace('/toolz/perfil.php?u=' + m[1]);

  // ── Chat: copiar enlace de una publicación y escribirle a alguien del equipo ──
  feed.addEventListener('click', async e => {
    const b = e.target.closest('.cm-share'); if (!b) return;
    const url = location.origin + '/toolz/comunidad.php?post=' + b.dataset.id;
    try { await navigator.clipboard.writeText(url); const f = F(), r = b.getBoundingClientRect(); if (f) f.chip('Enlace copiado: pegalo en el chat', 'fa-link', { x: r.left + r.width / 2, y: r.top }, '#22c55e'); else showToast('Enlace copiado'); }
    catch (er) { prompt('Copiá el enlace:', url); }
  });
  document.querySelectorAll('#team-compact > [data-on-click="openProfile"]').forEach(el => {
    const id = +el.getAttribute('data-click-1').replace(/^\D+/, ''); if (!id) return;
    el.style.position = 'relative'; el.setAttribute('data-g7-user', id);
    const a = document.createElement('a'); a.className = 'cm-team-chat'; a.href = '/toolz/chat/?u=' + id; a.title = 'Mensaje'; a.innerHTML = '<i class="fa-solid fa-comment-dots"></i>';
    a.addEventListener('click', ev => ev.stopPropagation(), true);
    el.appendChild(a);
  });

  // ══ Comunidad 3 ══
  // ── Encuestas: votar ──
  feed.addEventListener('click', async e => {
    const b = e.target.closest('.cm-opt'); if (!b) return;
    const box = b.closest('.cm-poll'), pid = +box.dataset.post;
    b.classList.add('busy');
    const d = await api({ action: 'vote_poll', post_id: pid, opt: +b.dataset.opt });
    if (d.status !== 'success') { b.classList.remove('busy'); return showToast(d.error || 'No se pudo votar'); }
    const card = document.getElementById('post-' + pid), tmp = document.createElement('div');
    tmp.innerHTML = renderPost({ id: pid, kind: 'poll', poll: d.poll, body: '', replies: [], reactions: [] });
    const nb = tmp.querySelector('.cm-poll'); box.replaceWith(nb);
    if (moving()) nb.querySelectorAll('.cm-opt-bar').forEach((x, i) => x.animate([{ width: '0%' }, { width: getComputedStyle(x.parentNode).getPropertyValue('--p') }], { duration: 700, delay: i * 60, easing: 'cubic-bezier(.2,.8,.2,1)' }));
    const f = F(); if (f) f.play('pop', 0);
  });

  // ── Encuestas: armar desde el compositor ──
  const compActs = document.querySelector('.composer .comp-actions');
  if (compActs) {
    const pbtn = document.createElement('button'); pbtn.type = 'button'; pbtn.className = 'comp-action-btn cm-poll-btn'; pbtn.title = 'Hacer una encuesta'; pbtn.innerHTML = '<i class="fa-solid fa-square-poll-vertical"></i> Encuesta';
    const kb = document.getElementById('cm-kudos-open'); (kb || compActs.lastChild).before(pbtn);
    const builder = document.createElement('div'); builder.className = 'cm-pbuild'; builder.hidden = true;
    builder.innerHTML = `<div class="cm-pb-opts"></div><div class="cm-pb-f"><button type="button" class="cm-pb-add"><i class="fa-solid fa-plus"></i> Opción</button><label><input type="checkbox" class="cm-pb-multi"> Varias respuestas</label></div>`;
    document.getElementById('img-preview-wrap').before(builder);
    const opts = builder.querySelector('.cm-pb-opts');
    const addOpt = (v = '') => { if (opts.children.length >= 6) return; const r = document.createElement('div'); r.className = 'cm-pb-o'; r.innerHTML = `<input maxlength="80" placeholder="Opción ${opts.children.length + 1}"><button type="button" title="Quitar">✕</button>`; r.querySelector('input').value = v; opts.appendChild(r); };
    builder.addEventListener('click', e => { if (e.target.closest('.cm-pb-add')) { addOpt(); opts.lastChild.querySelector('input').focus(); } const x = e.target.closest('.cm-pb-o button'); if (x && opts.children.length > 2) x.parentNode.remove(); });
    builder.addEventListener('input', () => updateCharCount(pb));
    pbtn.addEventListener('click', () => {
      builder.hidden = !builder.hidden; pbtn.classList.toggle('on', !builder.hidden);
      if (!builder.hidden && !opts.children.length) { addOpt(); addOpt(); }
      pb.placeholder = builder.hidden ? '¿Qué quieres compartir? usa @ para mencionar usuarios' : 'Escribí la pregunta de la encuesta…';
      pb.focus(); updateCharCount(pb); tap();
    });
    const _submit = window.submitPost;
    window.submitPost = async function () {
      if (builder.hidden) return _submit();
      const body = pb.value.trim(), list = [...opts.querySelectorAll('input')].map(i => i.value.trim()).filter(Boolean);
      if (body.length < 3) return showToast('Escribí la pregunta');
      if (list.length < 2) return showToast('Poné al menos 2 opciones');
      const btn = document.getElementById('pub-btn'); btn.disabled = true;
      const d = await api({ action: 'add_poll', body, options: list, multi: builder.querySelector('.cm-pb-multi').checked });
      btn.disabled = false;
      if (d.status !== 'success') return showToast(d.error || 'No se pudo publicar');
      pb.value = ''; opts.innerHTML = ''; builder.hidden = true; pbtn.classList.remove('on'); updateCharCount(pb);
      const keep = filter; filter = 'all'; prependPost(d.post); filter = keep;
      const f = F(); if (f) f.play('pop', 0);
    };
    const _ucc = window.updateCharCount;
    window.updateCharCount = function (ta) { _ucc(ta); if (!builder.hidden) document.getElementById('pub-btn').disabled = ta.value.trim().length < 3; };
  }

  // ── Filtros nuevos: Encuestas y Novedades ──
  const chips = bar.querySelector('.cm-chips');
  [['polls', 'fa-square-poll-vertical', 'Encuestas'], ['auto', 'fa-bolt', 'Novedades']].forEach(([k, i, l]) => chips.insertAdjacentHTML('beforeend', `<button type="button" data-f="${k}"><i class="fa-solid ${i}"></i> ${l}</button>`));

  // ── Hoy en el equipo: pregunta del día, cumpleaños y café ──
  const T = CM_CFG.today || {};
  const rc = document.querySelector('.right-col');
  if (rc && (T.qotd || (T.bdays || []).length || (T.soon || []).length || T.coffee)) {
    const box = document.createElement('div'); box.className = 'cm-today';
    const c = T.coffee, w = c && c.with;
    box.innerHTML = `<div class="cm-today-h"><i class="fa-solid fa-sun"></i> Hoy en el equipo</div>
      ${T.qotd ? `<a class="cm-t-q" href="#post-${+T.qotd.id}" data-qotd="${+T.qotd.id}"><span>💬</span><div><small>Pregunta del día</small><b>${escH(T.qotd.body)}</b><em>${+T.qotd.n} ${+T.qotd.n === 1 ? 'respuesta' : 'respuestas'}${+T.qotd.mine ? ' · ya respondiste ✓' : ' · ¡respondé!'}</em></div></a>` : ''}
      ${(T.bdays || []).map(b => `<a class="cm-t-b" href="/toolz/perfil.php?u=${+b.id}#muro"><span class="cm-t-av">${av(b, 30)}</span><div><small>🎂 Cumpleaños hoy</small><b>${escH(b.name)}</b></div><span class="cm-t-go">Saludar</span></a>`).join('')}
      ${(T.soon || []).length ? `<div class="cm-t-soon"><small>Próximos cumpleaños</small>${T.soon.slice(0, 4).map(b => `<a href="/toolz/perfil.php?u=${+b.id}" data-g7-user="${+b.id}">${av(b, 22)} ${escH(b.name.split(' ')[0])} <em>${escH(b.when)}</em></a>`).join('')}</div>` : ''}
      ${w ? `<div class="cm-t-c${c.done ? ' done' : ''}"><div class="cm-t-c-h"><span>☕</span><div><small>Tu café de la semana</small><b>${escH(w.display_name || w.username)}</b></div><span class="cm-t-av" data-g7-user="${+w.id}">${av(w, 34)}</span></div>
        <p>Para romper el hielo: <i>${escH(c.question)}</i></p>
        <div class="cm-t-c-b"><a href="/toolz/chat/?u=${+w.id}"><i class="fa-solid fa-comment-dots"></i> Escribirle</a><button type="button" data-coffee ${c.done ? 'disabled' : ''}>${c.done ? '✓ Ya charlaron' : '¡Ya charlamos!'}</button></div>
        ${c.their_done && !c.done ? '<small class="cm-t-c-n">Dice que ya charlaron 😉</small>' : ''}</div>` : ''}`;
    rc.prepend(box);
    box.addEventListener('click', async e => {
      const q = e.target.closest('[data-qotd]');
      if (q) { e.preventDefault(); const id = +q.dataset.qotd; if (!focusPost(id)) { filter = 'polls'; bar.querySelectorAll('[data-f]').forEach(x => x.classList.toggle('on', x.dataset.f === 'polls')); await load(false); focusPost(id); } const ta = document.getElementById('reply-input-' + id); if (ta) setTimeout(() => ta.focus(), 400); return; }
      const cf = e.target.closest('[data-coffee]'); if (!cf) return;
      cf.disabled = true; const d = await api({ action: 'coffee_done' });
      if (d.status !== 'success') { cf.disabled = false; return showToast(d.error || 'No se pudo guardar'); }
      cf.textContent = '✓ Ya charlaron'; cf.closest('.cm-t-c').classList.add('done');
      const f = F(), r = cf.getBoundingClientRect(); if (f) { if (moving()) f.confetti(40, { x: r.left + r.width / 2, y: r.top }, ['#a16207', '#f5c542', '#fde68a', '#ffffff'], { power: .8 }); f.chip('+15 XP · ¡Buen café!', 'fa-mug-hot', { x: r.left + r.width / 2, y: r.top }, '#f59e0b'); }
    });
  }

  // ── Avisos nuevos ──
  Object.assign(NT, { birthday: ['fa-cake-candles', '#ec4899', 'cumple años hoy 🎂'], coffee: ['fa-mug-hot', '#f59e0b', 'es tu café de la semana ☕'], poll_vote: ['fa-square-poll-vertical', '#60a5fa', 'votó en tu encuesta'] });
  const _rn = window.renderNotifItem;
  window.renderNotifItem = function (n) {
    let h = _rn(n);
    if (n.type === 'birthday' || n.type === 'poll_vote') h = h.replace(/href="[^"]*"/, `href="/toolz/comunidad.php?post=${+n.target_id}"`).replace('data-post=""', `data-post="${+n.target_id}"`);
    if (n.type === 'coffee') h = h.replace(/href="[^"]*"/, `href="/toolz/chat/?u=${+n.from_id}"`);
    return h;
  };
  if (typeof pollNotifications === 'function') pollNotifications();
})();
