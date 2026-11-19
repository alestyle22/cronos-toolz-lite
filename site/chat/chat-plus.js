/* chat/chat-plus.js — El chat más vivo (2026-11-14). Va después de chat.js y comm.js; escucha el evento 'cc:render' que
   emite chat.js y usa window.CCX (estado y funciones del chat). No cambia cómo se mandan ni se guardan los mensajes.
   - Inicio: saludo, quién está en línea, conversaciones sin leer, "Hoy en el equipo" (cumpleaños, café de la semana,
     pregunta del día; comunidad.php › today) y seguir conversando.
   - Mensajes nuevos que entran con animación, y efectos según el texto: 🎉 confeti, 🎂 globos, gracias ❤️ corazones, 👋 saludo.
   - Doble clic en un mensaje = ❤️ (el estallido de las reacciones y de los emojis al mandar ya lo hace g7-juice.js).
   - Rompehielos en conversaciones vacías; encabezado con color propio y botón "Ver perfil" en los mensajes directos.
   Estilos: chat-plus.css. Doc: toolz/MOVIMIENTO.md */
(function () {
  'use strict';
  const moving = () => document.documentElement.classList.contains('g7-move');
  const FX = () => (window.G7Juice && window.G7Juice._fx) || null;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const hash = s => { let h = 2166136261; for (const ch of String(s)) { h ^= ch.charCodeAt(0); h = Math.imul(h, 16777619); } return h >>> 0; };
  const $ = id => document.getElementById(id);
  let X = null, today = null, todayAt = 0;

  document.addEventListener('cc:render', e => {
    X = window.CCX || X; if (!X) return;
    const w = e.detail;
    if ((w === 'boot' || w === 'welcome') && !X.S.open) home();
    else if (w === 'msgs') onMsgs();
    else if (w === 'head') head();
  });

  // ── Partículas de emoji (efectos de mensaje y reacciones) ──
  function burst(emojis, x, y, n = 14, up = true, spread = 1) {
    if (!moving()) return;
    const host = document.createElement('div'); host.className = 'ccp-fx'; document.body.appendChild(host);
    for (let i = 0; i < n; i++) {
      const s = document.createElement('span'); s.textContent = emojis[i % emojis.length];
      s.style.left = x + 'px'; s.style.top = y + 'px'; s.style.fontSize = (18 + Math.random() * 18) + 'px';
      host.appendChild(s);
      const dx = (Math.random() - .5) * 260 * spread, dy = (up ? -1 : 1) * (120 + Math.random() * 260), r = (Math.random() - .5) * 90;
      s.animate([{ transform: 'translate(-50%,-50%) scale(.3)', opacity: 0 }, { transform: `translate(calc(-50% + ${dx * .35}px), calc(-50% + ${dy * .35}px)) scale(1.1)`, opacity: 1, offset: .25 },
        { transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) rotate(${r}deg) scale(.9)`, opacity: 0 }], { duration: 1300 + Math.random() * 900, delay: Math.random() * 260, easing: 'cubic-bezier(.2,.7,.3,1)', fill: 'both' });
    }
    setTimeout(() => host.remove(), 2700);
  }
  function rise(emojis, n = 16) {   // globos que suben desde abajo de la conversación
    if (!moving()) return;
    const box = $('cc-msgs'); if (!box) return;
    const r = box.getBoundingClientRect(), host = document.createElement('div'); host.className = 'ccp-fx'; document.body.appendChild(host);
    for (let i = 0; i < n; i++) {
      const s = document.createElement('span'); s.textContent = emojis[i % emojis.length];
      s.style.left = (r.left + 30 + Math.random() * (r.width - 60)) + 'px'; s.style.top = (r.bottom + 20) + 'px'; s.style.fontSize = (26 + Math.random() * 22) + 'px';
      host.appendChild(s);
      const sway = (Math.random() - .5) * 120;
      s.animate([{ transform: 'translate(-50%,0)', opacity: .95 }, { transform: `translate(calc(-50% + ${sway}px), -${r.height * .55}px) rotate(${sway / 8}deg)`, opacity: 1, offset: .6 }, { transform: `translate(calc(-50% + ${sway * .4}px), -${r.height + 60}px)`, opacity: 0 }],
        { duration: 2600 + Math.random() * 1400, delay: Math.random() * 900, easing: 'cubic-bezier(.3,.1,.4,1)', fill: 'both' });
    }
    setTimeout(() => host.remove(), 5200);
  }
  const EFFECTS = [
    { rx: /feliz\s+cumple|🎂|🎈/i, run: el => { rise(['🎈', '🎈', '🎂', '🎉', '🎈']); const f = FX(), r = el.getBoundingClientRect(); if (f) f.confetti(60, { x: r.left + 120, y: r.top + 20 }); } },
    { rx: /🎉|🥳|🎊|felicitaciones|felicidades|lo logramos|bien ah[ií]|excelente trabajo/i, run: el => { const f = FX(), r = el.getBoundingClientRect(); if (f) f.confetti(70, { x: r.left + 140, y: r.top + 20 }); else burst(['🎉', '🎊', '✨'], r.left + 140, r.top + 20, 18); } },
    { rx: /\bgracias\b|❤️|💖|💕|te quiero|sos (un|una) genio/i, run: el => { const r = el.getBoundingClientRect(); burst(['❤️', '💖', '💕', '💗'], r.left + 120, r.top + 24, 14); } },
    { rx: /🔥|fuego|está que arde/i, run: el => { const r = el.getBoundingClientRect(); burst(['🔥', '🔥', '✨'], r.left + 120, r.top + 24, 12); } },
    { rx: /^\s*(👋|(hola+|holi+s?|buen(os)? d[ií]as?|buenas)(?![\p{L}]))/iu, run: el => { el.classList.add('ccp-wave'); setTimeout(() => el.classList.remove('ccp-wave'), 1600); } },
  ];

  // ── Mensajes nuevos: entrada animada y efectos ──
  const seen = {}, maxId = {};
  function onMsgs() {
    const c = X.curConv(); if (!c) return;
    const C = X.S.conv[c.id]; if (!C) return;
    const first = !seen[c.id], set = seen[c.id] || (seen[c.id] = new Set());
    const fresh = [];
    let top = maxId[c.id] || 0;
    C.list.forEach(m => {
      const known = set.has(m.id) || (m.client_id && set.has(m.client_id));
      set.add(m.id); if (m.client_id) set.add(m.client_id);
      const num = typeof m.id === 'number';
      if (!first && !known && (m.pending || (num && m.id > top))) fresh.push(m);
      if (num && m.id > top) top = m.id;
    });
    maxId[c.id] = top;
    fresh.forEach(m => {
      const el = $('m-' + m.id); if (!el) return;
      if (moving()) el.animate([{ opacity: 0, transform: `translateY(14px) scale(.98)` }, { opacity: 1, transform: 'none' }], { duration: 380, easing: 'cubic-bezier(.2,.8,.2,1)' });
      if (m.deleted || m.kind === 'system') return;
      const fx = EFFECTS.find(f => f.rx.test(m.body || ''));
      if (fx) fx.run(el);   // al instante: el mensaje "enviando…" se reemplaza enseguida por el confirmado
    });
    icebreakers(c, C);
  }

  // ── Rompehielos en conversaciones vacías ──
  function icebreakers(c, C) {
    const intro = document.querySelector('#cc-msgs .cc-intro'); if (!intro || intro.querySelector('.ccp-ice')) return;
    if (!$('cc-ta') || C.moreBefore || C.list.some(m => m.kind !== 'system')) return;
    const S = X.S, dm = c.kind === 'dm', self = dm && c.peer === S.me;
    if (self) return;
    const first = dm ? X.uname(c.peer).split(' ')[0] : '';
    const ideas = dm ? [['👋', `¡Hola ${first}! 👋`], ['☕', '☕ ¿Hacemos un café virtual esta semana?'], ['🙌', `🙌 ¡Gracias por la ayuda, ${first}!`], ['💡', '💡 ¿Tenés un minuto? Quería preguntarte algo:']]
      : [['👋', '👋 ¡Hola a todos!'], ['📌', `📌 Les cuento de qué se trata ${c.kind === 'channel' ? 'este canal' : 'este grupo'}: `], ['🎉', '🎉 ¡Arrancamos!']];
    const box = document.createElement('div'); box.className = 'ccp-ice';
    const label = t => { const s = t.replace(/^\p{Extended_Pictographic}️?\s*/u, '').replace(/\s*\p{Extended_Pictographic}️?$/u, ''); return s.length > 34 ? s.slice(0, 32) + '…' : s; };
    box.innerHTML = `<small>Para romper el hielo</small><div>${ideas.map(([e, t]) => `<button type="button" data-ccp-say="${esc(t)}"><span>${e}</span>${esc(label(t))}</button>`).join('')}${dm ? `<button type="button" class="k" data-ccm-kudos="${+c.peer}"><span>🏆</span>Reconocer a ${esc(first)}</button>` : ''}</div>`;
    intro.appendChild(box);
  }
  function say(text) {
    const ta = $('cc-ta'); if (!ta) return;
    ta.value = text; ta.dispatchEvent(new Event('input', { bubbles: true })); ta.focus(); ta.setSelectionRange(text.length, text.length);
    const b = $('cc-box'); if (b && moving()) b.animate([{ boxShadow: '0 0 0 0 var(--g7-accent)' }, { boxShadow: '0 0 0 6px transparent' }], { duration: 700 });
  }
  /** Abre el mensaje directo con alguien y deja un texto listo para mandar. */
  function dmWith(uid, text) {
    X.openDM(uid);
    if (!text) return;
    const t0 = Date.now();
    (function wait() {
      const c = X.curConv(), C = c && X.S.conv[c.id];
      if (c && c.kind === 'dm' && c.peer === uid && C && $('cc-ta')) { if (!$('cc-ta').value) say(text); return; }
      if (Date.now() - t0 < 5000) setTimeout(wait, 120);
    })();
  }

  // ── Encabezado: color propio y "Ver perfil" ──
  function head() {
    const h = $('cc-mh'), c = X.curConv(); if (!h || !c) return;
    h.style.setProperty('--ccp-h', String(hash(c.kind === 'dm' ? 'u' + c.peer : c.name || c.id) % 360));
    h.classList.add('ccp-head');
    if (c.kind === 'dm' && c.peer !== X.S.me && !h.querySelector('.ccp-prof')) {
      const a = h.querySelector('.cc-mh-a');
      if (a) a.insertAdjacentHTML('afterbegin', `<a class="cc-ib ccp-prof" href="/toolz/perfil.php?u=${+c.peer}" target="_blank" rel="noopener" title="Ver perfil" aria-label="Ver perfil"><i class="fa-solid fa-id-card"></i></a>`);
    }
  }

  // ── Inicio del chat ──
  const greet = () => { const h = new Date().getHours(); return h < 6 ? 'Buenas noches' : h < 13 ? 'Buen día' : h < 20 ? 'Buenas tardes' : 'Buenas noches'; };
  function preview(c) {
    const l = c.last; if (!l) return '';
    const who = l.user_id === X.S.me ? 'Tú: ' : c.kind !== 'dm' ? X.uname(l.user_id).split(' ')[0] + ': ' : '';
    return l.kind === 'system' ? X.uname(l.user_id).split(' ')[0] + ' ' + (l.body || '') : who + (String(l.body || '').replace(/\[\[(\w+):[^\]]+\]\]/g, '🔗 $1') || '📎 Archivo');
  }
  const convLabel = c => c.kind === 'dm' ? X.uname(c.peer) : (c.kind === 'channel' ? '#' : '') + (c.name || '');
  const convIco = (c, s) => c.kind === 'dm' ? X.avatar(c.peer, s) : `<span class="ccp-hash" style="--s:${s}px;--h:${hash(c.name || c.id) % 360}">${c.kind === 'group' ? '<i class="fa-solid fa-user-group"></i>' : '#'}</span>`;
  const when = d => { const t = new Date(String(d).replace(' ', 'T')), s = (Date.now() - t) / 1000; return s < 60 ? 'ahora' : s < 3600 ? Math.floor(s / 60) + ' min' : s < 86400 ? Math.floor(s / 3600) + ' h' : Math.floor(s / 86400) + ' d'; };

  function home() {
    const w = document.querySelector('#cc-main .cc-welcome'); if (!w) return;
    const S = X.S, me = S.users[S.me] || {}, convs = Object.values(S.convs).filter(c => !c.archived);
    const unread = convs.filter(c => c.unread > 0 && !c.muted).sort((a, b) => String(b.last_msg_at || '').localeCompare(String(a.last_msg_at || '')));
    const nUnread = unread.reduce((a, c) => a + c.unread, 0);
    const recent = convs.filter(c => c.last_msg_at && !(c.unread > 0 && !c.muted)).sort((a, b) => String(b.last_msg_at).localeCompare(String(a.last_msg_at))).slice(0, 6);
    const online = Object.values(S.users).filter(u => u.active && u.online && u.id !== S.me).sort((a, b) => String(a.name).localeCompare(String(b.name), 'es'));
    const team = Object.values(S.users).filter(u => u.active).length;
    w.classList.add('ccp-home');
    w.innerHTML = `<div class="ccp-wrap">
      <div class="ccp-hero">
        <div class="ccp-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
        <div class="ccp-hi">
          <span class="ccp-me">${X.avatar(S.me, 58)}</span>
          <div><h2>${greet()}, ${esc(String(me.name || '').split(' ')[0])} <span class="ccp-hand">👋</span></h2>
          <p>${nUnread ? `Tenés <b>${nUnread} ${nUnread === 1 ? 'mensaje' : 'mensajes'} sin leer</b> en ${unread.length} ${unread.length === 1 ? 'conversación' : 'conversaciones'}.` : 'Estás al día ✨ No tenés mensajes sin leer.'}</p></div>
        </div>
        <div class="ccp-stats">
          <span><b>${online.length}</b> en línea</span><span><b>${team}</b> en el equipo</span><span><b>${convs.length}</b> conversaciones</span>
        </div>
        <div class="ccp-acts">
          <button class="cc-btn pri" data-act="new-dm"><i class="fa-solid fa-pen-to-square"></i> Nuevo mensaje</button>
          <button class="cc-btn" data-act="new-group"><i class="fa-solid fa-user-group"></i> Armar grupo</button>
          <button class="cc-btn" data-act="browse"><i class="fa-solid fa-hashtag"></i> Canales</button>
          <a class="cc-btn" href="/toolz/comunidad.php"><i class="fa-solid fa-people-group"></i> Comunidad</a>
        </div>
      </div>
      <section class="ccp-card ccp-online"><h4><i class="ccp-live"></i> En línea ahora <small>${online.length}</small></h4>
        ${online.length ? `<div class="ccp-ppl">${online.slice(0, 18).map(u => `<button type="button" class="ccp-p" data-dm="${u.id}" title="Escribile a ${esc(u.name)}">${X.avatar(u.id, 46).replace(/ data-user="\d+"/, '')}<span>${esc(String(u.name).split(' ')[0])}</span>${u.status_text ? `<em>${esc(u.status_text)}</em>` : ''}</button>`).join('')}</div>` : '<p class="ccp-none">Nadie más en línea ahora. Dejales un mensaje: lo ven al volver.</p>'}
      </section>
      <div class="ccp-cols">
        <section class="ccp-card"><h4><i class="fa-solid fa-envelope-open-text"></i> Sin leer <small>${nUnread || ''}</small></h4>
          ${unread.length ? unread.slice(0, 6).map(c => `<button type="button" class="ccp-row" data-conv="${c.id}">${convIco(c, 38)}<span class="t"><b>${esc(convLabel(c))}</b><small>${esc(preview(c))}</small></span><span class="m"><time>${c.last_msg_at ? when(c.last_msg_at) : ''}</time><span class="cc-badge">${c.unread > 99 ? '99+' : c.unread}</span></span></button>`).join('') : '<div class="ccp-zero"><span>🎉</span>Todo leído. ¡Buen trabajo!</div>'}
        </section>
        <section class="ccp-card" id="ccp-today"><h4><i class="fa-solid fa-sun"></i> Hoy en el equipo</h4><div class="ccp-sk"></div><div class="ccp-sk"></div></section>
      </div>
      ${recent.length ? `<section class="ccp-card"><h4><i class="fa-solid fa-clock-rotate-left"></i> Seguir conversando</h4><div class="ccp-recent">${recent.map(c => `<button type="button" class="ccp-rc" data-conv="${c.id}">${convIco(c, 34)}<span class="t"><b>${esc(convLabel(c))}</b><small>${esc(preview(c))}</small></span><time>${when(c.last_msg_at)}</time></button>`).join('')}</div></section>` : ''}
      <p class="ccp-tip"><i class="fa-regular fa-lightbulb"></i> ${esc(TIPS[Math.floor(Date.now() / 3.6e6) % TIPS.length])}</p>
    </div>`;
    if (moving()) w.querySelectorAll('.ccp-hero,.ccp-card,.ccp-tip').forEach((el, i) => el.animate([{ opacity: 0, transform: 'translateY(18px)' }, { opacity: 1, transform: 'none' }], { duration: 520, delay: 60 + i * 70, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }));
    loadToday();
  }
  const TIPS = ['Doble clic en un mensaje para darle ❤️.', 'Escribí «feliz cumple» o 🎉 y mirá lo que pasa.', 'Con Ctrl+K buscás cualquier conversación.', 'Escribí / en el mensaje para crear una tarea, un ticket o una nota.', 'Flecha arriba con el mensaje vacío edita lo último que mandaste.', 'Tocá la foto de alguien para ver su tarjeta y reconocerlo.', 'Arrastrá archivos a la conversación para compartirlos.'];

  async function loadToday() {
    if (!today || Date.now() - todayAt > 120000) {
      try { const r = await fetch('/toolz/comunidad.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'today' }) }); today = await r.json(); todayAt = Date.now(); } catch (e) { today = null; }
    }
    const box = $('ccp-today'); if (!box) return;
    const T = today || {}, items = [];
    const av = (u, s) => u.avatar ? `<img src="${esc(u.avatar)}" alt="" style="width:${s}px;height:${s}px">` : `<span style="width:${s}px;height:${s}px">${esc(String(u.name || '?').charAt(0).toUpperCase())}</span>`;
    (T.bdays || []).forEach(b => items.push(`<div class="ccp-td bday"><span class="ccp-tav">${av(b, 38)}<i>🎂</i></span><span class="t"><b>¡Hoy cumple ${esc(String(b.name).split(' ')[0])}!</b><small>Mandale un saludo</small></span>${+b.id !== X.S.me ? `<button type="button" class="cc-btn sm pri" data-ccp-dm="${+b.id}" data-ccp-text="¡Feliz cumple, ${esc(String(b.name).split(' ')[0])}! 🎂🎉">Saludar</button>` : '<span class="ccp-you">¡Feliz cumple! 🎉</span>'}</div>`));
    const cf = T.coffee;
    if (cf && cf.with) { const nm = cf.with.display_name || cf.with.username || ''; items.push(`<div class="ccp-td coffee"><span class="ccp-tav">${av({ name: nm, avatar: cf.with.avatar }, 38)}<i>☕</i></span><span class="t"><b>Café de la semana con ${esc(nm.split(' ')[0])}</b><small>${cf.done ? 'Ya lo marcaste como hecho ✔' : esc(cf.question || '')}</small></span>${cf.done ? '' : `<button type="button" class="cc-btn sm" data-ccp-dm="${+cf.with.id}" data-ccp-text="☕ ¡Hola! Nos tocó café virtual esta semana. ${esc(cf.question || '')}">Escribirle</button>`}</div>`); }
    if (T.qotd) items.push(`<a class="ccp-td qotd" href="/toolz/comunidad.php?post=${+T.qotd.id}"><span class="ccp-qi">💬</span><span class="t"><b>Pregunta del día</b><small>${esc(String(T.qotd.body || '').replace(/^[^\wÀ-ÿ¿¡]+/u, '').slice(0, 110))}</small></span><span class="ccp-go">${+T.qotd.mine ? 'Respondiste ✔' : 'Responder'} <i class="fa-solid fa-arrow-right"></i></span></a>`);
    (T.soon || []).slice(0, 3).forEach(b => items.push(`<div class="ccp-td soon"><span class="ccp-tav">${av(b, 30)}</span><span class="t"><b>${esc(b.name)}</b><small>🎈 Cumple ${esc(String(b.when).toLowerCase())}</small></span></div>`));
    box.innerHTML = `<h4><i class="fa-solid fa-sun"></i> Hoy en el equipo</h4>` + (items.join('') || '<div class="ccp-zero"><span>☀️</span>Un día tranquilo. Pasá por la Comunidad a ver qué hay.</div>');
  }
  // refrescar la portada si cambian los no leídos o la gente en línea mientras está a la vista
  let homeKey = '';
  setInterval(() => {
    if (!X || X.S.open || !document.querySelector('#cc-main .ccp-home') || document.hidden) return;
    const k = Object.values(X.S.convs).map(c => c.id + ':' + (c.unread || 0)).join(',') + '|' + Object.values(X.S.users).filter(u => u.online).map(u => u.id).join(',');
    if (k !== homeKey) { if (homeKey) home(); homeKey = k; }
  }, 4000);

  // ── Clics propios ──
  document.addEventListener('click', e => {
    const s = e.target.closest('[data-ccp-say]'); if (s) { e.preventDefault(); say(s.dataset.ccpSay); return; }
    const d = e.target.closest('[data-ccp-dm]'); if (d) { e.preventDefault(); dmWith(+d.dataset.ccpDm, d.dataset.ccpText || ''); return; }
    // (el estallido al reaccionar ya lo hace g7-juice.js)
  }, true);
  // Doble clic en un mensaje: ❤️
  document.addEventListener('dblclick', e => {
    if (!X) return;
    const el = e.target.closest('#cc-msgs .cc-m:not(.sys), #cc-aside .cc-m:not(.sys)'); if (!el || e.target.closest('a,button,textarea,input,video,audio,.cc-editbox,pre,code')) return;
    const id = +el.dataset.id; if (!id) return;
    const C = X.S.conv[X.S.open], m = (C && C.list.find(x => x.id === id)) || null;
    if (m && (m.deleted || m.pending)) return;
    try { getSelection().removeAllRanges(); } catch (er) {}
    const mine = m && (m.reactions || []).some(r => r.emoji === '❤️' && r.mine);
    if (!mine) X.react(id, '❤️');
    heart(e.clientX, e.clientY);
  });
  function heart(x, y) {
    const h = document.createElement('div'); h.className = 'ccp-heart'; h.textContent = '❤️'; h.style.left = x + 'px'; h.style.top = y + 'px'; document.body.appendChild(h);
    h.animate([{ transform: 'translate(-50%,-50%) scale(.2)', opacity: 0 }, { transform: 'translate(-50%,-50%) scale(1.25)', opacity: 1, offset: .35 }, { transform: 'translate(-50%,-50%) scale(1)', opacity: 1, offset: .6 }, { transform: 'translate(-50%,-110%) scale(.8)', opacity: 0 }], { duration: moving() ? 900 : 500, easing: 'cubic-bezier(.2,.8,.2,1)' });
    burst(['❤️', '💖', '✨'], x, y, 8, true, .6);
    setTimeout(() => h.remove(), 950);
  }
  // Enviar: el avioncito sale volando
  function fly() { const b = $('cc-send'); if (!b || !moving()) return; b.classList.remove('ccp-fly'); void b.offsetWidth; b.classList.add('ccp-fly'); setTimeout(() => b.classList.remove('ccp-fly'), 650); }
  document.addEventListener('keydown', e => { if (e.target && e.target.id === 'cc-ta' && e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.target.value.trim()) fly(); }, true);
  document.addEventListener('click', e => { const b = e.target.closest('#cc-send'); if (b && !b.disabled) fly(); }, true);

  document.body.classList.add('ccp');
})();
